<?php
/**
 * Canonical, transaction-safe FluentCart refund recorder.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationScope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies the local half of a remote-first refund exactly once.
 *
 * The operation journal is the idempotency authority. All FluentCart rows and
 * the journal receipt are locked and changed in one InnoDB transaction.
 */
final class YSHelcimLocalRefundRecorder {

	/** @var object wpdb-compatible database object. */
	private $database;

	/** @var callable UTC SQL timestamp provider. */
	private $clock;

	/** @var callable|null Injectable crash-test checkpoint. */
	private $checkpoint;

	private string $operations_table;

	private string $transactions_table;

	private string $orders_table;

	private string $items_table;

	private string $outbox_table;

	/**
	 * @param object|null   $database   wpdb-compatible database object.
	 * @param callable|null $clock      UTC SQL timestamp provider.
	 * @param callable|null $checkpoint Optional test-only fault checkpoint.
	 */
	public function __construct( ?object $database = null, ?callable $clock = null, ?callable $checkpoint = null ) {
		if ( null === $database ) {
			global $wpdb;
			$database = $wpdb;
		}

		$this->database           = $database;
		$this->clock              = $clock ?? static fn (): string => gmdate( 'Y-m-d H:i:s' );
		$this->checkpoint         = $checkpoint;
		$this->operations_table   = $database->prefix . 'ys_helcim_operations';
		$this->transactions_table = $database->prefix . 'fct_order_transactions';
		$this->orders_table       = $database->prefix . 'fct_orders';
		$this->items_table        = $database->prefix . 'fct_order_items';
		$this->outbox_table       = $database->prefix . 'ys_helcim_outbox';
	}

	/**
	 * Record one provider-confirmed refund/reversal into FluentCart.
	 *
	 * @return array{operation_uuid:string,local_transaction_id:int,local_status:string,replayed:bool}|\WP_Error
	 */
	public function record( string $operation_uuid ) {
		$operation_uuid = strtolower( trim( $operation_uuid ) );
		if ( ! self::isUuid( $operation_uuid ) ) {
			return self::error(
				'ys_helcim_local_invalid_operation',
				__( 'The refund operation identifier is invalid.', 'ys-helcim-via-fluentcart' )
			);
		}

		if ( false === $this->database->query( 'START TRANSACTION' ) ) {
			return self::storageError();
		}

		try {
			$operation = $this->lockOperation( $operation_uuid );
			if ( null === $operation ) {
				$this->abort(
					'ys_helcim_local_invalid_operation',
					__( 'The refund operation could not be found.', 'ys-helcim-via-fluentcart' )
				);
			}
			$this->validateOperation( $operation, $operation_uuid );

			if ( 'succeeded' !== (string) $operation['remote_status'] ) {
				$this->abort(
					'ys_helcim_remote_not_succeeded',
					__( 'The provider has not definitively confirmed this refund.', 'ys-helcim-via-fluentcart' )
				);
			}

			$payload = $this->validatedPayload( $operation );
			$source  = $this->lockSourceTransaction( (int) $operation['transaction_id'] );
			if ( null === $source ) {
				$this->accountingDrift();
			}
			$order = $this->lockOrder( (int) $operation['order_id'] );
			if ( null === $order ) {
				$this->accountingDrift();
			}

			$refund_rows  = $this->lockRefundRows( (int) $operation['order_id'] );
			$item_rows    = $this->lockOrderItems( (int) $operation['order_id'] );
			$outbox_rows  = $this->lockOutboxRows( $operation_uuid );
			$local_status = (string) $operation['local_status'];
			$has_receipt  = null !== self::positiveInteger( $operation['local_transaction_id'] ?? null );
			$is_replay    = in_array( $local_status, array( 'applied', 'recorded' ), true )
				|| ( 'failed' === $local_status && $has_receipt );
			$accounting   = $this->validateAccounting( $operation, $source, $order, $refund_rows, $item_rows, $payload, $is_replay );

			if ( $is_replay ) {
				$result = $this->replayResult(
					$operation,
					$source,
					$refund_rows,
					$outbox_rows,
					$payload
				);
				$this->commit();
				return $result;
			}
			if ( ! in_array( $local_status, array( 'pending', 'failed' ), true ) ) {
				$this->abort(
					'ys_helcim_local_operation_conflict',
					__( 'This refund is already being applied locally.', 'ys-helcim-via-fluentcart' )
				);
			}

			$now = ( $this->clock )();
			$this->mustUpdate(
				$this->operations_table,
				array(
					'local_status' => 'applying',
					'updated_at'   => $now,
				),
				array(
					'id'            => (int) $operation['id'],
					'remote_status' => 'succeeded',
					'local_status'  => $local_status,
				)
			);
			$this->checkpoint( 'after_local_claim' );

			$this->assertNoDuplicateReceipt( $operation, $refund_rows );
			$refund_meta      = $this->refundMeta(
				$operation,
				$source,
				$payload,
				$accounting['stock_snapshot'],
				$accounting['hook_item_snapshot'],
				$accounting['item_allocated_amount'],
				$accounting['unallocated_amount']
			);
			$refund_row       = $this->refundRow( $operation, $source, $refund_meta, $now );
			$inserted         = $this->database->insert( $this->transactions_table, $refund_row );
			$refund_row_id    = (int) ( $this->database->insert_id ?? 0 );
			if ( 1 !== $inserted || $refund_row_id <= 0 ) {
				throw new \RuntimeException( 'Refund insert failed.' );
			}
			$this->checkpoint( 'after_refund_insert' );

			$source_meta                     = $accounting['source_meta'];
			$source_meta['refunded_total']   = $accounting['next_source_refund'];
			$encoded_source_meta             = wp_json_encode( $source_meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $encoded_source_meta ) ) {
				throw new \RuntimeException( 'Parent metadata could not be encoded.' );
			}
			$this->mustUpdate(
				$this->transactions_table,
				array(
					'meta'       => $encoded_source_meta,
					'updated_at' => $now,
				),
				array( 'id' => (int) $source['id'] )
			);
			$this->checkpoint( 'after_parent_update' );

			$next_order_status = $accounting['next_order_refund'] >= (int) $order['total_paid']
				? 'refunded'
				: 'partially_refunded';
			$this->mustUpdate(
				$this->orders_table,
				array(
					'total_refund'   => $accounting['next_order_refund'],
					'payment_status' => $next_order_status,
					'refunded_at'    => $now,
					'updated_at'     => $now,
				),
				array( 'id' => (int) $order['id'] )
			);
			$this->checkpoint( 'after_order_update' );

			foreach ( $accounting['item_targets'] as $item_id => $refund_total ) {
				$this->mustUpdate(
					$this->items_table,
					array(
						'refund_total' => $refund_total,
						'updated_at'   => $now,
					),
					array(
						'id'       => $item_id,
						'order_id' => (int) $operation['order_id'],
					)
				);
			}
			$this->checkpoint( 'after_items_update' );
			$effect_plan = $this->insertOutboxEffects(
				$operation,
				$source,
				$order,
				$payload,
				$accounting['stock_snapshot'],
				$accounting['hook_item_snapshot'],
				$refund_row_id,
				$accounting['next_order_refund'] >= (int) $order['total_paid'] ? 'full' : 'partial',
				$now
			);
			$receipt_meta = self::jsonObject( $refund_meta );
			if ( null === $receipt_meta ) {
				throw new \RuntimeException( 'Refund receipt metadata could not be restored.' );
			}
			$receipt_meta['ys_helcim_effect_plan_hash'] = self::outboxPlanHash( $effect_plan );
			$receipt_meta['ys_helcim_effect_plan']      = $effect_plan;
			$encoded_receipt_meta = wp_json_encode( $receipt_meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $encoded_receipt_meta ) ) {
				throw new \RuntimeException( 'Refund receipt metadata could not be finalized.' );
			}
			$this->mustUpdate(
				$this->transactions_table,
				array(
					'meta'       => $encoded_receipt_meta,
					'updated_at' => $now,
				),
				array(
					'id'   => $refund_row_id,
					'uuid' => $operation_uuid,
				)
			);
			$this->checkpoint( 'after_outbox_insert' );

			$next_local_status = 'recorded';
			$journal_changes   = array(
				'local_status'         => $next_local_status,
				'local_transaction_id' => $refund_row_id,
				'local_recorded_at'    => $now,
				'local_applied_at'     => null,
				'local_error_code'     => null,
				'local_error_message'  => null,
				'updated_at'           => $now,
			);
			$this->mustUpdate(
				$this->operations_table,
				$journal_changes,
				array(
					'id'            => (int) $operation['id'],
					'remote_status' => 'succeeded',
					'local_status'  => 'applying',
				)
			);
			$this->checkpoint( 'after_journal_update' );
			$this->checkpoint( 'before_commit' );
			$this->commit();

			return array(
				'operation_uuid'      => $operation_uuid,
				'local_transaction_id' => $refund_row_id,
				'local_status'         => $next_local_status,
				'replayed'             => false,
			);
		} catch ( YSHelcimLocalRefundRecorderAbort $exception ) {
			$this->database->query( 'ROLLBACK' );
			return self::error( $exception->errorCode(), $exception->getMessage() );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$this->database->query( 'ROLLBACK' );
			return self::storageError();
		}
	}

	/** @return array<string,mixed>|null */
	private function lockOperation( string $operation_uuid ): ?array {
		$this->clearDatabaseError();
		$row = $this->database->get_row(
			$this->database->prepare(
				"SELECT * FROM `{$this->operations_table}` WHERE operation_uuid = %s LIMIT 1 FOR UPDATE",
				$operation_uuid
			),
			ARRAY_A
		);
		$this->assertDatabaseReadSucceeded();

		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	private function lockSourceTransaction( int $transaction_id ): ?array {
		$this->clearDatabaseError();
		$row = $this->database->get_row(
			$this->database->prepare(
				"SELECT * FROM `{$this->transactions_table}` WHERE id = %d LIMIT 1 FOR UPDATE",
				$transaction_id
			),
			ARRAY_A
		);
		$this->assertDatabaseReadSucceeded();

		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	private function lockOrder( int $order_id ): ?array {
		$this->clearDatabaseError();
		$row = $this->database->get_row(
			$this->database->prepare(
				"SELECT * FROM `{$this->orders_table}` WHERE id = %d LIMIT 1 FOR UPDATE",
				$order_id
			),
			ARRAY_A
		);
		$this->assertDatabaseReadSucceeded();

		return is_array( $row ) ? $row : null;
	}

	/** @return array<int,array<string,mixed>> */
	private function lockRefundRows( int $order_id ): array {
		$this->clearDatabaseError();
		$rows = $this->database->get_results(
			$this->database->prepare(
				"SELECT * FROM `{$this->transactions_table}` WHERE order_id = %d AND transaction_type = 'refund' ORDER BY id ASC FOR UPDATE",
				$order_id
			),
			ARRAY_A
		);
		$this->assertDatabaseReadSucceeded();

		if ( ! is_array( $rows ) ) {
			throw new \RuntimeException( 'Refund rows could not be locked.' );
		}

		return $rows;
	}

	/** @return array<int,array<string,mixed>> */
	private function lockOrderItems( int $order_id ): array {
		$this->clearDatabaseError();
		$rows = $this->database->get_results(
			$this->database->prepare(
				"SELECT * FROM `{$this->items_table}` WHERE order_id = %d ORDER BY id ASC FOR UPDATE",
				$order_id
			),
			ARRAY_A
		);
		$this->assertDatabaseReadSucceeded();

		if ( ! is_array( $rows ) ) {
			throw new \RuntimeException( 'Order items could not be locked.' );
		}

		return $rows;
	}

	/** @return array<int,array<string,mixed>> */
	private function lockOutboxRows( string $operation_uuid ): array {
		$this->clearDatabaseError();
		$rows = $this->database->get_results(
			$this->database->prepare(
				"SELECT * FROM `{$this->outbox_table}` WHERE operation_uuid = %s ORDER BY sequence ASC, id ASC FOR UPDATE",
				$operation_uuid
			),
			ARRAY_A
		);
		$this->assertDatabaseReadSucceeded();

		if ( ! is_array( $rows ) ) {
			throw new \RuntimeException( 'Refund outbox rows could not be locked.' );
		}

		return $rows;
	}

	private function clearDatabaseError(): void {
		if ( property_exists( $this->database, 'last_error' ) ) {
			$this->database->last_error = '';
		}
	}

	private function assertDatabaseReadSucceeded(): void {
		if ( '' !== (string) ( $this->database->last_error ?? '' ) ) {
			throw new \RuntimeException( 'Locked database read failed.' );
		}
	}

	/** @param array<string,mixed> $operation */
	private function validateOperation( array $operation, string $expected_uuid ): void {
		$operation_type = strtolower( (string) ( $operation['operation_type'] ?? '' ) );
		$gateway        = (string) ( $operation['gateway'] ?? '' );
		$amount         = self::positiveInteger( $operation['amount'] ?? null );
		$order_id       = self::positiveInteger( $operation['order_id'] ?? null );
		$transaction_id = self::positiveInteger( $operation['transaction_id'] ?? null );
		$vendor_id      = self::positiveIntegerString( $operation['vendor_transaction_id'] ?? null );
		$source_vendor  = self::positiveIntegerString( $operation['source_vendor_transaction_id'] ?? null );
		$currency       = (string) ( $operation['currency'] ?? '' );

		if (
			$expected_uuid !== strtolower( (string) ( $operation['operation_uuid'] ?? '' ) ) ||
			! in_array( $operation_type, array( 'refund', 'reverse' ), true ) ||
			! in_array( $gateway, array( 'ys_helcim', 'ys_helcim_js' ), true ) ||
			null === $amount ||
			null === $order_id ||
			null === $transaction_id ||
			null === $vendor_id ||
			null === $source_vendor ||
			1 !== preg_match( '/\A[a-f0-9]{64}\z/', (string) ( $operation['request_fingerprint'] ?? '' ) ) ||
			1 !== preg_match( '/\A[A-Z]{3}\z/', $currency ) ||
			! in_array( (string) ( $operation['payment_mode'] ?? '' ), array( 'live', 'test' ), true ) ||
			'' === (string) ( $operation['transaction_uuid'] ?? '' )
		) {
			$this->abort(
				'ys_helcim_local_invalid_operation',
				__( 'The refund operation contains invalid local identity data.', 'ys-helcim-via-fluentcart' )
			);
		}

		$parent_uuid = $operation['parent_operation_uuid'] ?? null;
		if (
			( 'reverse' === $operation_type && ( ! is_string( $parent_uuid ) || ! self::isUuid( $parent_uuid ) ) ) ||
			( 'refund' === $operation_type && null !== $parent_uuid && '' !== $parent_uuid )
		) {
			$this->abort(
				'ys_helcim_local_invalid_operation',
				__( 'The refund operation has an invalid parent operation.', 'ys-helcim-via-fluentcart' )
			);
		}

		$local_status = (string) ( $operation['local_status'] ?? '' );
		$scope_key    = YSHelcimOperationScope::fromBusinessKey( 'refund-order:' . $order_id );
		$active_scope = $operation['active_scope_key'] ?? null;
		$resolved_at  = $operation['resolved_at'] ?? null;
		$local_id     = self::positiveInteger( $operation['local_transaction_id'] ?? null );
		$recorded_at  = $operation['local_recorded_at'] ?? null;
		$applied_at   = $operation['local_applied_at'] ?? null;
		if (
			$scope_key !== (string) ( $operation['scope_key'] ?? '' ) ||
			! in_array( $local_status, array( 'pending', 'applying', 'recorded', 'applied', 'failed' ), true ) ||
			( 'applied' !== $local_status && ( $scope_key !== $active_scope || null !== $resolved_at ) ) ||
			( 'applied' === $local_status && ( null !== $active_scope || ! is_string( $resolved_at ) || '' === $resolved_at ) ) ||
			( 'pending' === $local_status && ( null !== $local_id || null !== $recorded_at || null !== $applied_at ) ) ||
			( 'recorded' === $local_status && ( null === $local_id || ! is_string( $recorded_at ) || '' === $recorded_at || null !== $applied_at ) ) ||
			( 'applied' === $local_status && ( null === $local_id || ! is_string( $recorded_at ) || '' === $recorded_at || ! is_string( $applied_at ) || '' === $applied_at ) ) ||
			( 'failed' === $local_status && null !== $local_id && ( ! is_string( $recorded_at ) || '' === $recorded_at ) )
		) {
			$this->accountingDrift();
		}
	}

	/** @param array<string,mixed> $operation @return array<string,mixed> */
	private function validatedPayload( array $operation ): array {
		$json        = $operation['local_payload'] ?? null;
		$stored_hash = (string) ( $operation['local_payload_hash'] ?? '' );
		if ( ! is_string( $json ) || '' === $json || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $stored_hash ) ) {
			$this->invalidPayload();
		}

		try {
			$decoded = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
			if ( ! is_array( $decoded ) || 1 !== ( $decoded['version'] ?? null ) ) {
				$this->invalidPayload();
			}
			$input = $decoded;
			unset( $input['version'] );
			$normalized = YSHelcimRefundPayload::normalize( $input );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$this->invalidPayload();
		}

		if (
			$normalized !== $decoded ||
			! hash_equals( $stored_hash, hash( 'sha256', $json ) ) ||
			! hash_equals( $stored_hash, YSHelcimRefundPayload::hash( $normalized ) )
		) {
			$this->invalidPayload();
		}

		return $normalized;
	}

	/**
	 * @param array<string,mixed>              $operation
	 * @param array<string,mixed>              $source
	 * @param array<string,mixed>              $order
	 * @param array<int,array<string,mixed>>   $refund_rows
	 * @param array<int,array<string,mixed>>   $item_rows
	 * @param array<string,mixed>              $payload
	 * @param bool                             $is_replay
	 * @return array{source_meta:array<string,mixed>,next_source_refund:int,next_order_refund:int,item_targets:array<int,int>,item_allocated_amount:int,unallocated_amount:int,stock_snapshot:array<int,array<string,int>>,hook_item_snapshot:array<int,array<string,mixed>>}
	 */
	private function validateAccounting(
		array $operation,
		array $source,
		array $order,
		array $refund_rows,
		array $item_rows,
		array $payload,
		bool $is_replay
	): array {
		$source_total  = self::nonnegativeInteger( $source['total'] ?? null );
		$order_paid    = self::nonnegativeInteger( $order['total_paid'] ?? null );
		$order_refund  = self::nonnegativeInteger( $order['total_refund'] ?? null );
		$amount        = (int) $operation['amount'];
		$source_meta   = self::jsonObject( $source['meta'] ?? null );
		$meta_refunded = self::nonnegativeInteger( $source_meta['refunded_total'] ?? 0 );

		if (
			null === $source_total ||
			null === $order_paid ||
			null === $order_refund ||
			null === $source_meta ||
			null === $meta_refunded ||
			(int) ( $source['id'] ?? 0 ) !== (int) $operation['transaction_id'] ||
			(int) ( $source['order_id'] ?? 0 ) !== (int) $operation['order_id'] ||
			(string) ( $source['uuid'] ?? '' ) !== (string) $operation['transaction_uuid'] ||
			(string) ( $source['payment_method'] ?? '' ) !== (string) $operation['gateway'] ||
			(string) ( $source['payment_mode'] ?? '' ) !== (string) $operation['payment_mode'] ||
			(string) ( $source['currency'] ?? '' ) !== (string) $operation['currency'] ||
			'succeeded' !== (string) ( $source['status'] ?? '' ) ||
			'refund' === (string) ( $source['transaction_type'] ?? '' ) ||
			null === self::positiveIntegerString( $source['vendor_charge_id'] ?? null ) ||
			(int) ( $order['id'] ?? 0 ) !== (int) $operation['order_id'] ||
			(string) ( $order['currency'] ?? '' ) !== (string) $operation['currency'] ||
			(string) ( $order['type'] ?? '' ) !== (string) ( $source['order_type'] ?? '' )
		) {
			$this->accountingDrift();
		}

		$source_vendor_id = self::positiveIntegerString( $source['vendor_charge_id'] ?? null );
		$stored_source_id = self::positiveIntegerString( $operation['source_vendor_transaction_id'] ?? null );
		$fingerprint      = self::refundFingerprint( $operation, $source_total );
		if (
			null === $source_vendor_id ||
			null === $stored_source_id ||
			! hash_equals( $stored_source_id, $source_vendor_id ) ||
			null === $fingerprint ||
			! hash_equals( (string) $operation['request_fingerprint'], $fingerprint )
		) {
			$this->accountingDrift();
		}

		$order_refund_sum  = 0;
		$source_refund_sum = 0;
		foreach ( $refund_rows as $refund ) {
			if ( 'refunded' !== (string) ( $refund['status'] ?? '' ) ) {
				continue;
			}
			$total = self::nonnegativeInteger( $refund['total'] ?? null );
			$meta  = self::jsonObject( $refund['meta'] ?? null );
			if ( null === $total || null === $meta || $total > PHP_INT_MAX - $order_refund_sum ) {
				$this->accountingDrift();
			}
			$order_refund_sum += $total;
			if ( (int) ( $meta['parent_id'] ?? 0 ) === (int) $source['id'] ) {
				if ( $total > PHP_INT_MAX - $source_refund_sum ) {
					$this->accountingDrift();
				}
				$source_refund_sum += $total;
			}
		}

		if (
			$order_refund !== $order_refund_sum ||
			$meta_refunded !== $source_refund_sum ||
			$order_refund > $order_paid ||
			$source_refund_sum > $source_total ||
			( ! $is_replay && $amount > $source_total - $source_refund_sum ) ||
			( ! $is_replay && $amount > $order_paid - $order_refund )
		) {
			$this->accountingDrift();
		}

		if ( $order_refund > 0 ) {
			$expected_status = $order_refund >= $order_paid ? 'refunded' : 'partially_refunded';
			if ( $expected_status !== (string) ( $order['payment_status'] ?? '' ) ) {
				$this->accountingDrift();
			}
		}

		$item_targets  = $is_replay ? array() : $this->allocateItemTargets( $item_rows, $payload['item_ids'], $amount );
		$item_allocated = $is_replay ? 0 : $this->itemAllocatedAmount( $item_rows, $item_targets );

		return array(
			'source_meta'       => $source_meta,
			'next_source_refund' => $is_replay ? $source_refund_sum : $source_refund_sum + $amount,
			'next_order_refund'  => $is_replay ? $order_refund_sum : $order_refund_sum + $amount,
			'item_targets'       => $item_targets,
			'item_allocated_amount' => $item_allocated,
			'unallocated_amount' => $is_replay ? 0 : $amount - $item_allocated,
			'stock_snapshot'     => $is_replay ? array() : $this->stockSnapshot( $item_rows, $payload ),
			'hook_item_snapshot' => $is_replay ? array() : $this->hookItemSnapshot( $item_rows, $payload['item_ids'], $item_targets ),
		);
	}

	/** @param array<int,array<string,mixed>> $item_rows @param array<int,int> $targets */
	private function itemAllocatedAmount( array $item_rows, array $targets ): int {
		$current = array();
		foreach ( $item_rows as $row ) {
			$current[ (int) $row['id'] ] = (int) $row['refund_total'];
		}

		$allocated = 0;
		foreach ( $targets as $item_id => $target ) {
			if ( ! isset( $current[ $item_id ] ) || $target < $current[ $item_id ] ) {
				$this->accountingDrift();
			}
			$delta = $target - $current[ $item_id ];
			if ( $delta > PHP_INT_MAX - $allocated ) {
				$this->accountingDrift();
			}
			$allocated += $delta;
		}

		return $allocated;
	}

	/**
	 * Freeze the stock identifiers and quantities that were validated under the
	 * same row locks as the refund accounting.
	 *
	 * @param array<int,array<string,mixed>> $item_rows
	 * @param array<string,mixed>            $payload
	 * @return array<int,array{item_id:int,object_id:int,post_id:int,quantity:int,restore_quantity:int}>
	 */
	private function stockSnapshot( array $item_rows, array $payload ): array {
		if ( ! $payload['manage_stock'] ) {
			return array();
		}

		$by_id = array();
		foreach ( $item_rows as $item ) {
			$item_id   = self::positiveInteger( $item['id'] ?? null );
			$object_id = self::positiveInteger( $item['object_id'] ?? null );
			$post_id   = self::positiveInteger( $item['post_id'] ?? null );
			$quantity  = self::positiveInteger( $item['quantity'] ?? null );
			if ( null === $item_id || null === $object_id || null === $post_id || null === $quantity ) {
				continue;
			}
			$by_id[ $item_id ] = array(
				'item_id'   => $item_id,
				'object_id' => $object_id,
				'post_id'   => $post_id,
				'quantity'  => $quantity,
			);
		}

		$snapshot = array();
		foreach ( $payload['refunded_items'] as $restore ) {
			$item_id          = (int) $restore['id'];
			$restore_quantity = (int) $restore['restore_quantity'];
			if ( ! isset( $by_id[ $item_id ] ) || $restore_quantity > $by_id[ $item_id ]['quantity'] ) {
				$this->accountingDrift();
			}
			$snapshot[] = array_merge(
				$by_id[ $item_id ],
				array( 'restore_quantity' => $restore_quantity )
			);
		}

		return $snapshot;
	}

	/**
	 * Snapshot only the stable, non-arbitrary fields needed to reproduce the
	 * selected-item portion of FluentCart's later refund hook.
	 *
	 * @param array<int,array<string,mixed>> $item_rows
	 * @param int[]                          $selected_ids
	 * @param array<int,int>                 $item_targets Absolute post-refund totals by changed item ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function hookItemSnapshot( array $item_rows, array $selected_ids, array $item_targets ): array {
		if ( empty( $selected_ids ) ) {
			return array();
		}

		$rows_by_id = array();
		foreach ( $item_rows as $row ) {
			$rows_by_id[ (int) ( $row['id'] ?? 0 ) ] = $row;
		}

		$snapshot = array();
		foreach ( $selected_ids as $item_id ) {
			$row       = $rows_by_id[ $item_id ] ?? null;
			$order_id  = is_array( $row ) ? self::positiveInteger( $row['order_id'] ?? null ) : null;
			$post_id   = is_array( $row ) ? self::nonnegativeInteger( $row['post_id'] ?? null ) : null;
			$object_value = is_array( $row ) && array_key_exists( 'object_id', $row ) ? $row['object_id'] : null;
			$object_id = null === $object_value ? null : self::nonnegativeInteger( $object_value );
			$quantity  = is_array( $row ) ? self::positiveInteger( $row['quantity'] ?? null ) : null;
			if (
				! is_array( $row ) ||
				null === $order_id ||
				null === $post_id ||
				( null !== $object_value && null === $object_id ) ||
				null === $quantity
			) {
				$this->accountingDrift();
			}

			$money = array();
			foreach ( array( 'unit_price', 'subtotal', 'tax_amount', 'shipping_charge', 'discount_total', 'line_total', 'refund_total' ) as $field ) {
				$value = self::nonnegativeInteger( $row[ $field ] ?? 0 );
				if ( null === $value ) {
					$this->accountingDrift();
				}
				$money[ $field ] = $value;
			}

			$snapshot[] = array(
				'id'                 => $item_id,
				'order_id'           => $order_id,
				'post_id'            => $post_id,
				'object_id'          => $object_id,
				'fulfillment_type'   => (string) ( $row['fulfillment_type'] ?? '' ),
				'payment_type'       => (string) ( $row['payment_type'] ?? '' ),
				'post_title'         => (string) ( $row['post_title'] ?? '' ),
				'title'              => (string) ( $row['title'] ?? '' ),
				'quantity'           => $quantity,
				'unit_price'         => $money['unit_price'],
				'subtotal'           => $money['subtotal'],
				'tax_amount'         => $money['tax_amount'],
				'shipping_charge'    => $money['shipping_charge'],
				'discount_total'     => $money['discount_total'],
				'line_total'         => $money['line_total'],
				'refund_total'       => $item_targets[ $item_id ] ?? $money['refund_total'],
				'rate'               => (string) ( $row['rate'] ?? '1' ),
				'fulfilled_quantity' => max( 0, (int) ( $row['fulfilled_quantity'] ?? 0 ) ),
			);
		}

		return $snapshot;
	}

	/**
	 * Mirrors FluentCart's selected/all-item allocation using integer cents.
	 *
	 * @param array<int,array<string,mixed>> $item_rows
	 * @param int[]                          $selected_ids
	 * @return array<int,int> Changed item IDs mapped to absolute refund totals.
	 */
	private function allocateItemTargets( array $item_rows, array $selected_ids, int $amount ): array {
		$items = array();
		foreach ( $item_rows as $row ) {
			$id             = self::positiveInteger( $row['id'] ?? null );
			$line_total     = self::nonnegativeInteger( $row['line_total'] ?? null );
			$refunded_total = self::nonnegativeInteger( $row['refund_total'] ?? null );
			if ( null === $id || null === $line_total || null === $refunded_total || $refunded_total > $line_total || isset( $items[ $id ] ) ) {
				$this->accountingDrift();
			}
			$items[ $id ] = array(
				'current' => $refunded_total,
				'remain'  => $line_total - $refunded_total,
			);
		}

		$selected_ids = empty( $selected_ids ) ? array_keys( $items ) : $selected_ids;
		$selected     = array();
		$total_remain = 0;
		foreach ( $selected_ids as $id ) {
			if ( ! isset( $items[ $id ] ) ) {
				$this->accountingDrift();
			}
			$selected[ $id ] = $items[ $id ];
			if ( $items[ $id ]['remain'] > PHP_INT_MAX - $total_remain ) {
				$this->accountingDrift();
			}
			$total_remain += $items[ $id ]['remain'];
		}

		if ( 0 === $total_remain ) {
			return array();
		}

		$allocation = min( $amount, $total_remain );
		$targets    = array();
		$distributed = 0;
		$last_id     = (int) array_key_last( $selected );
		foreach ( $selected as $id => $item ) {
			$share = $id === $last_id
				? $allocation - $distributed
				: self::multiplyDivideFloor( $allocation, $item['remain'], $total_remain );
			$distributed += $share;
			if ( $share > 0 ) {
				$targets[ $id ] = $item['current'] + $share;
			}
		}

		return $targets;
	}

	/**
	 * Overflow-safe floor(($left * $right) / $divisor), with operands <= divisor.
	 */
	private static function multiplyDivideFloor( int $left, int $right, int $divisor ): int {
		if ( $left < 0 || $right < 0 || $divisor <= 0 || $left > $divisor || $right > $divisor ) {
			throw new \InvalidArgumentException( 'Invalid proportional allocation.' );
		}

		$quotient  = 0;
		$remainder = 0;
		$part_q    = 0;
		$part_r    = $left;
		$multiplier = $right;

		while ( $multiplier > 0 ) {
			if ( 1 === $multiplier % 2 ) {
				$quotient += $part_q;
				if ( $remainder >= $divisor - $part_r ) {
					++$quotient;
					$remainder -= $divisor - $part_r;
				} else {
					$remainder += $part_r;
				}
			}

			$multiplier = intdiv( $multiplier, 2 );
			if ( 0 === $multiplier ) {
				break;
			}

			$part_q *= 2;
			if ( $part_r >= $divisor - $part_r ) {
				++$part_q;
				$part_r -= $divisor - $part_r;
			} else {
				$part_r *= 2;
			}
		}

		return $quotient;
	}

	/** Rebuild RefundService's immutable version-2 request fingerprint. */
	private static function refundFingerprint( array $operation, int $transaction_total ): ?string {
		$operation_type = (string) $operation['operation_type'];
		$material       = wp_json_encode(
			array(
				'version'                      => 2,
				'operation_type'               => $operation_type,
				'parent_operation_uuid'        => 'reverse' === $operation_type
					? (string) $operation['parent_operation_uuid']
					: null,
				'gateway'                      => (string) $operation['gateway'],
				'order_id'                     => (int) $operation['order_id'],
				'transaction_id'               => (int) $operation['transaction_id'],
				'transaction_uuid'             => (string) $operation['transaction_uuid'],
				'source_vendor_transaction_id' => (string) $operation['source_vendor_transaction_id'],
				'amount'                       => (int) $operation['amount'],
				'transaction_total'            => $transaction_total,
				'currency'                     => (string) $operation['currency'],
				'payment_mode'                 => (string) $operation['payment_mode'],
				'local_payload_hash'            => (string) $operation['local_payload_hash'],
			),
			JSON_UNESCAPED_SLASHES
		);

		return is_string( $material ) ? hash( 'sha256', $material ) : null;
	}

	/**
	 * @param array<string,mixed>          $operation
	 * @param array<string,mixed>          $source
	 * @param array<string,mixed>          $payload
	 * @param array<int,array<string,int>> $stock_snapshot
	 * @param array<int,array<string,mixed>> $hook_item_snapshot
	 */
	private function refundMeta(
		array $operation,
		array $source,
		array $payload,
		array $stock_snapshot,
		array $hook_item_snapshot,
		int $item_allocated_amount,
		int $unallocated_amount
	): string {
		$operation_uuid = (string) $operation['operation_uuid'];
		$root_uuid      = 'reverse' === (string) $operation['operation_type']
			? (string) $operation['parent_operation_uuid']
			: $operation_uuid;
		$meta           = array(
			'parent_id'                                     => (int) $source['id'],
			'reason'                                        => (string) $payload['reason'],
			'ys_helcim_operation_uuid'                      => $operation_uuid,
			'ys_helcim_root_refund_uuid'                    => $root_uuid,
			'ys_helcim_provider_action'                     => (string) $operation['operation_type'],
			'ys_helcim_original_vendor_transaction_id'      => (string) $source['vendor_charge_id'],
			'item_ids'                                      => $payload['item_ids'],
			'manageStock'                                   => (bool) $payload['manage_stock'],
			'refunded_items'                                => $payload['refunded_items'],
			'actor_user_id'                                 => (int) $payload['actor_user_id'],
			'ys_helcim_stock_snapshot'                      => $stock_snapshot,
			'ys_helcim_refunded_item_snapshots'             => $hook_item_snapshot,
			'ys_helcim_item_allocated_amount'               => $item_allocated_amount,
			'ys_helcim_unallocated_amount'                  => $unallocated_amount,
		);
		$encoded = wp_json_encode( $meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) ) {
			throw new \RuntimeException( 'Refund metadata could not be encoded.' );
		}

		return $encoded;
	}

	/**
	 * @param array<string,mixed> $operation
	 * @param array<string,mixed> $source
	 * @return array<string,mixed>
	 */
	private function refundRow( array $operation, array $source, string $meta, string $now ): array {
		return array(
			'order_id'            => (int) $operation['order_id'],
			'order_type'          => (string) $source['order_type'],
			'transaction_type'    => 'refund',
			'subscription_id'     => $source['subscription_id'] ?? null,
			'card_last_4'         => $source['card_last_4'] ?? null,
			'card_brand'          => $source['card_brand'] ?? null,
			'vendor_charge_id'    => (string) $operation['vendor_transaction_id'],
			'payment_method'      => (string) $operation['gateway'],
			'payment_mode'        => (string) $operation['payment_mode'],
			'payment_method_type' => (string) ( $source['payment_method_type'] ?? '' ),
			'status'              => 'refunded',
			'currency'            => (string) $operation['currency'],
			'total'               => (int) $operation['amount'],
			'rate'                => $source['rate'] ?? 1,
			'uuid'                => (string) $operation['operation_uuid'],
			'meta'                => $meta,
			'created_at'          => $now,
			'updated_at'          => $now,
		);
	}

	/**
	 * Insert the complete deterministic post-recording effect plan. These rows
	 * intentionally use the recorder's existing transaction instead of the
	 * repository helper, whose public method owns no surrounding transaction.
	 *
	 * @param array<string,mixed>            $operation
	 * @param array<string,mixed>            $source
	 * @param array<string,mixed>            $order
	 * @param array<string,mixed>            $payload
	 * @param array<int,array<string,int>>   $stock_snapshot
	 * @param array<int,array<string,mixed>> $hook_item_snapshot
	 * @return array<int,array{effect_type:string,effect_class:string,sequence:int,status:string,payload:string,payload_hash:string}>
	 */
	private function insertOutboxEffects(
		array $operation,
		array $source,
		array $order,
		array $payload,
		array $stock_snapshot,
		array $hook_item_snapshot,
		int $local_transaction_id,
		string $refund_type,
		string $now
	): array {
		$operation_uuid = (string) $operation['operation_uuid'];
		$effects        = $this->outboxPlan(
			$operation,
			$source,
			$order,
			$payload,
			$stock_snapshot,
			$hook_item_snapshot,
			$local_transaction_id,
			$refund_type
		);

		foreach ( $effects as $effect ) {
			$status   = (string) $effect['status'];
			$inserted = $this->database->insert(
				$this->outbox_table,
				array(
					'operation_uuid'     => $operation_uuid,
					'effect_type'        => (string) $effect['effect_type'],
					'effect_class'       => (string) $effect['effect_class'],
					'sequence'           => (int) $effect['sequence'],
					'payload'            => (string) $effect['payload'],
					'payload_hash'       => (string) $effect['payload_hash'],
					'status'             => $status,
					'attempt_count'      => 0,
					'claim_token'        => null,
					'available_at'       => $now,
					'claimed_at'         => null,
					'completed_at'       => 'skipped' === $status ? $now : null,
					'result_hash'        => null,
					'last_error_code'    => null,
					'last_error_message' => null,
					'created_at'         => $now,
					'updated_at'         => $now,
				)
			);
			if ( 1 !== $inserted ) {
				throw new \RuntimeException( 'Refund outbox insert failed.' );
			}
		}

		return $effects;
	}

	/**
	 * Build the canonical effect identities and payload hashes used by both the
	 * initial insert and replay integrity checks.
	 *
	 * @param array<string,mixed>          $operation
	 * @param array<string,mixed>          $source
	 * @param array<string,mixed>          $order
	 * @param array<string,mixed>          $payload
	 * @param array<int,array<string,int>> $stock_snapshot
	 * @param array<int,array<string,mixed>> $hook_item_snapshot
	 * @return array<int,array{effect_type:string,effect_class:string,sequence:int,status:string,payload:string,payload_hash:string}>
	 */
	private function outboxPlan(
		array $operation,
		array $source,
		array $order,
		array $payload,
		array $stock_snapshot,
		array $hook_item_snapshot,
		int $local_transaction_id,
		string $refund_type
	): array {
		$operation_uuid = (string) $operation['operation_uuid'];
		$base           = array(
			'version'              => 1,
			'operation_uuid'       => $operation_uuid,
			'order_id'             => (int) $operation['order_id'],
			'local_transaction_id' => $local_transaction_id,
		);
		$effects        = array(
			array(
				'effect_type'  => 'stock_restore',
				'effect_class' => 'at_most_once',
				'sequence'     => 10,
				'status'       => $payload['manage_stock'] ? 'pending' : 'skipped',
				'payload'      => array_merge(
					$base,
					array(
						'manage_stock' => (bool) $payload['manage_stock'],
						'items'        => $stock_snapshot,
					)
				),
			),
			array(
				'effect_type'  => 'customer_recount',
				'effect_class' => 'idempotent',
				'sequence'     => 20,
				'status'       => 'pending',
				'payload'      => array_merge(
					$base,
					array( 'customer_id' => max( 0, (int) ( $order['customer_id'] ?? 0 ) ) )
				),
			),
			array(
				'effect_type'  => 'refund_hooks',
				'effect_class' => 'at_most_once',
				'sequence'     => 30,
				'status'       => 'pending',
				'payload'      => array_merge(
					$base,
					array(
						'root_refund_uuid'       => 'reverse' === (string) $operation['operation_type']
							? (string) $operation['parent_operation_uuid']
							: $operation_uuid,
						'order_uuid'              => (string) ( $order['uuid'] ?? '' ),
						'customer_id'             => max( 0, (int) ( $order['customer_id'] ?? 0 ) ),
						'actor_user_id'           => (int) $payload['actor_user_id'],
						'source_transaction_id'   => (int) $source['id'],
						'provider_transaction_id' => (string) $operation['vendor_transaction_id'],
						'provider_action'         => (string) $operation['operation_type'],
						'refund_amount'            => (int) $operation['amount'],
						'currency'                 => (string) $operation['currency'],
						'refund_type'              => $refund_type,
						'reason'                   => (string) $payload['reason'],
						'item_ids'                 => $payload['item_ids'],
						'manage_stock'             => false,
						'stock_restore_requested'  => (bool) $payload['manage_stock'],
						'refunded_items'           => $payload['refunded_items'],
						'refunded_item_snapshots'  => $hook_item_snapshot,
					)
				),
			),
		);

		foreach ( $effects as &$effect ) {
			$encoded = wp_json_encode( $effect['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $encoded ) ) {
				throw new \RuntimeException( 'Refund outbox payload could not be encoded.' );
			}
			$effect['payload']      = $encoded;
			$effect['payload_hash'] = hash( 'sha256', $encoded );
		}
		unset( $effect );

		return $effects;
	}

	/** @param array<int,array<string,mixed>> $plan */
	private static function outboxPlanHash( array $plan ): string {
		$identity = array();
		foreach ( $plan as $effect ) {
			$identity[] = array(
				'effect_type'  => (string) $effect['effect_type'],
				'effect_class' => (string) $effect['effect_class'],
				'sequence'     => (int) $effect['sequence'],
				'status'       => (string) $effect['status'],
				'payload_hash' => (string) $effect['payload_hash'],
			);
		}
		$encoded = wp_json_encode( $identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) ) {
			throw new \RuntimeException( 'Refund effect plan could not be hashed.' );
		}

		return hash( 'sha256', $encoded );
	}

	/** @param array<string,mixed> $operation @param array<int,array<string,mixed>> $refund_rows */
	private function assertNoDuplicateReceipt( array $operation, array $refund_rows ): void {
		foreach ( $refund_rows as $refund ) {
			if (
				(string) ( $refund['uuid'] ?? '' ) === (string) $operation['operation_uuid'] ||
				(string) ( $refund['vendor_charge_id'] ?? '' ) === (string) $operation['vendor_transaction_id']
			) {
				$this->abort(
					'ys_helcim_local_operation_conflict',
					__( 'A different local refund already uses this provider receipt.', 'ys-helcim-via-fluentcart' )
				);
			}
		}
	}

	/**
	 * @param array<string,mixed>            $operation
	 * @param array<string,mixed>            $source
	 * @param array<int,array<string,mixed>> $refund_rows
	 * @param array<int,array<string,mixed>> $outbox_rows
	 * @param array<string,mixed>            $payload
	 * @return array{operation_uuid:string,local_transaction_id:int,local_status:string,replayed:bool}
	 */
	private function replayResult(
		array $operation,
		array $source,
		array $refund_rows,
		array $outbox_rows,
		array $payload
	): array {
		$local_id = self::positiveInteger( $operation['local_transaction_id'] ?? null );
		$receipt  = null;
		foreach ( $refund_rows as $refund ) {
			if ( (int) ( $refund['id'] ?? 0 ) === $local_id ) {
				$receipt = $refund;
				break;
			}
		}

		$meta = is_array( $receipt ) ? self::jsonObject( $receipt['meta'] ?? null ) : null;
		if (
			null === $local_id ||
			null === $receipt ||
			null === $meta ||
			(string) ( $receipt['uuid'] ?? '' ) !== (string) $operation['operation_uuid'] ||
			(string) ( $receipt['vendor_charge_id'] ?? '' ) !== (string) $operation['vendor_transaction_id'] ||
			(int) ( $receipt['order_id'] ?? 0 ) !== (int) $operation['order_id'] ||
			(int) ( $receipt['total'] ?? 0 ) !== (int) $operation['amount'] ||
			(int) ( $meta['parent_id'] ?? 0 ) !== (int) $source['id'] ||
			(string) ( $meta['ys_helcim_operation_uuid'] ?? '' ) !== (string) $operation['operation_uuid']
		) {
			$this->accountingDrift();
		}

		$this->receiptStockSnapshot( $meta, $payload );
		$this->receiptHookItemSnapshot( $meta, $payload, $operation );
		$item_allocated = self::nonnegativeInteger( $meta['ys_helcim_item_allocated_amount'] ?? null );
		$unallocated    = self::nonnegativeInteger( $meta['ys_helcim_unallocated_amount'] ?? null );
		if ( null === $item_allocated || null === $unallocated || $item_allocated > PHP_INT_MAX - $unallocated || $item_allocated + $unallocated !== (int) $operation['amount'] ) {
			$this->accountingDrift();
		}

		$expected_plan    = $this->receiptEffectPlan( $meta, $operation, $source, $payload, $local_id );
		$stored_plan_hash = (string) ( $meta['ys_helcim_effect_plan_hash'] ?? '' );
		if (
			1 !== preg_match( '/\A[a-f0-9]{64}\z/', $stored_plan_hash ) ||
			! hash_equals( $stored_plan_hash, self::outboxPlanHash( $expected_plan ) )
		) {
			$this->accountingDrift();
		}
		$this->assertOutboxPlan( $operation, $expected_plan, $outbox_rows );

		return array(
			'operation_uuid'      => (string) $operation['operation_uuid'],
			'local_transaction_id' => $local_id,
			'local_status'         => (string) $operation['local_status'],
			'replayed'             => true,
		);
	}

	/**
	 * @param array<string,mixed> $receipt_meta
	 * @param array<string,mixed> $payload
	 * @return array<int,array{item_id:int,object_id:int,post_id:int,quantity:int,restore_quantity:int}>
	 */
	private function receiptStockSnapshot( array $receipt_meta, array $payload ): array {
		$snapshot = $receipt_meta['ys_helcim_stock_snapshot'] ?? null;
		if ( ! is_array( $snapshot ) ) {
			$this->accountingDrift();
		}
		if ( ! $payload['manage_stock'] ) {
			if ( ! empty( $snapshot ) ) {
				$this->accountingDrift();
			}
			return array();
		}

		$validated = array();
		foreach ( $snapshot as $row ) {
			if (
				! is_array( $row ) ||
				! self::hasExactKeys( $row, array( 'item_id', 'object_id', 'post_id', 'quantity', 'restore_quantity' ) )
			) {
				$this->accountingDrift();
			}
			$item_id          = self::positiveInteger( $row['item_id'] );
			$object_id        = self::positiveInteger( $row['object_id'] );
			$post_id          = self::positiveInteger( $row['post_id'] );
			$quantity         = self::positiveInteger( $row['quantity'] );
			$restore_quantity = self::positiveInteger( $row['restore_quantity'] );
			if (
				null === $item_id ||
				null === $object_id ||
				null === $post_id ||
				null === $quantity ||
				null === $restore_quantity ||
				$restore_quantity > $quantity
			) {
				$this->accountingDrift();
			}
			$validated[] = array(
				'item_id'          => $item_id,
				'object_id'        => $object_id,
				'post_id'          => $post_id,
				'quantity'         => $quantity,
				'restore_quantity' => $restore_quantity,
			);
		}

		$requested = array_map(
			static fn ( array $row ): array => array(
				'item_id'          => (int) $row['id'],
				'restore_quantity' => (int) $row['restore_quantity'],
			),
			$payload['refunded_items']
		);
		$recorded = array_map(
			static fn ( array $row ): array => array(
				'item_id'          => $row['item_id'],
				'restore_quantity' => $row['restore_quantity'],
			),
			$validated
		);
		if ( $requested !== $recorded ) {
			$this->accountingDrift();
		}

		return $validated;
	}

	/**
	 * @param array<string,mixed> $receipt_meta
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $operation
	 * @return array<int,array<string,mixed>>
	 */
	private function receiptHookItemSnapshot( array $receipt_meta, array $payload, array $operation ): array {
		$snapshot = $receipt_meta['ys_helcim_refunded_item_snapshots'] ?? null;
		if ( ! is_array( $snapshot ) || count( $snapshot ) !== count( $payload['item_ids'] ) ) {
			$this->accountingDrift();
		}

		$keys = array(
			'id', 'order_id', 'post_id', 'object_id', 'fulfillment_type', 'payment_type',
			'post_title', 'title', 'quantity', 'unit_price', 'subtotal', 'tax_amount',
			'shipping_charge', 'discount_total', 'line_total', 'refund_total', 'rate',
			'fulfilled_quantity',
		);
		$validated = array();
		foreach ( $snapshot as $index => $row ) {
			if ( ! is_array( $row ) || ! self::hasExactKeys( $row, $keys ) ) {
				$this->accountingDrift();
			}
			$id        = self::positiveInteger( $row['id'] );
			$order_id  = self::positiveInteger( $row['order_id'] );
			$post_id   = self::nonnegativeInteger( $row['post_id'] );
			$object_id = null === $row['object_id'] ? null : self::nonnegativeInteger( $row['object_id'] );
			$quantity  = self::positiveInteger( $row['quantity'] );
			if (
				null === $id ||
				null === $order_id ||
				null === $post_id ||
				( null !== $row['object_id'] && null === $object_id ) ||
				null === $quantity ||
				$id !== ( $payload['item_ids'][ $index ] ?? null ) ||
				$order_id !== (int) $operation['order_id'] ||
				! is_string( $row['fulfillment_type'] ) ||
				! is_string( $row['payment_type'] ) ||
				! is_string( $row['post_title'] ) ||
				! is_string( $row['title'] ) ||
				! is_string( $row['rate'] ) ||
				'' === $row['rate']
			) {
				$this->accountingDrift();
			}
			foreach ( array( 'unit_price', 'subtotal', 'tax_amount', 'shipping_charge', 'discount_total', 'line_total', 'refund_total', 'fulfilled_quantity' ) as $field ) {
				if ( null === self::nonnegativeInteger( $row[ $field ] ) ) {
					$this->accountingDrift();
				}
			}

			$canonical = array();
			foreach ( $keys as $key ) {
				$canonical[ $key ] = $row[ $key ];
			}
			$validated[] = $canonical;
		}

		return $validated;
	}

	/**
	 * Recover the exact historical effect plan from the immutable refund receipt.
	 * Mutable order/customer state is deliberately absent from reconstruction.
	 *
	 * @param array<string,mixed> $receipt_meta
	 * @param array<string,mixed> $operation
	 * @param array<string,mixed> $source
	 * @param array<string,mixed> $payload
	 * @return array<int,array<string,mixed>>
	 */
	private function receiptEffectPlan(
		array $receipt_meta,
		array $operation,
		array $source,
		array $payload,
		int $local_id
	): array {
		$plan = $receipt_meta['ys_helcim_effect_plan'] ?? null;
		if ( ! is_array( $plan ) || 3 !== count( $plan ) ) {
			$this->accountingDrift();
		}

		$expected_identity = array(
			array( 'stock_restore', 'at_most_once', 10, $payload['manage_stock'] ? 'pending' : 'skipped' ),
			array( 'customer_recount', 'idempotent', 20, 'pending' ),
			array( 'refund_hooks', 'at_most_once', 30, 'pending' ),
		);
		$decoded_payloads = array();
		foreach ( $plan as $index => $effect ) {
			$identity = $expected_identity[ $index ];
			if (
				! is_array( $effect ) ||
				! self::hasExactKeys( $effect, array( 'effect_type', 'effect_class', 'sequence', 'status', 'payload', 'payload_hash' ) ) ||
				(string) $effect['effect_type'] !== $identity[0] ||
				(string) $effect['effect_class'] !== $identity[1] ||
				(int) $effect['sequence'] !== $identity[2] ||
				(string) $effect['status'] !== $identity[3] ||
				! is_string( $effect['payload'] ) ||
				1 !== preg_match( '/\A[a-f0-9]{64}\z/', (string) $effect['payload_hash'] ) ||
				! hash_equals( hash( 'sha256', $effect['payload'] ), (string) $effect['payload_hash'] )
			) {
				$this->accountingDrift();
			}
			$decoded = self::jsonObject( $effect['payload'] );
			if (
				null === $decoded ||
				1 !== ( $decoded['version'] ?? null ) ||
				(string) ( $decoded['operation_uuid'] ?? '' ) !== (string) $operation['operation_uuid'] ||
				(int) ( $decoded['order_id'] ?? 0 ) !== (int) $operation['order_id'] ||
				(int) ( $decoded['local_transaction_id'] ?? 0 ) !== $local_id
			) {
				$this->accountingDrift();
			}
			$decoded_payloads[] = $decoded;
		}

		$stock_snapshot = $this->receiptStockSnapshot( $receipt_meta, $payload );
		$hook_snapshot  = $this->receiptHookItemSnapshot( $receipt_meta, $payload, $operation );
		if (
			(bool) ( $decoded_payloads[0]['manage_stock'] ?? false ) !== (bool) $payload['manage_stock'] ||
			( $decoded_payloads[0]['items'] ?? null ) !== $stock_snapshot ||
			null === self::nonnegativeInteger( $decoded_payloads[1]['customer_id'] ?? null ) ||
			(string) ( $decoded_payloads[2]['root_refund_uuid'] ?? '' ) !== ( 'reverse' === (string) $operation['operation_type'] ? (string) $operation['parent_operation_uuid'] : (string) $operation['operation_uuid'] ) ||
			(int) ( $decoded_payloads[2]['source_transaction_id'] ?? 0 ) !== (int) $source['id'] ||
			(string) ( $decoded_payloads[2]['provider_transaction_id'] ?? '' ) !== (string) $operation['vendor_transaction_id'] ||
			(string) ( $decoded_payloads[2]['provider_action'] ?? '' ) !== (string) $operation['operation_type'] ||
			(int) ( $decoded_payloads[2]['refund_amount'] ?? 0 ) !== (int) $operation['amount'] ||
			(string) ( $decoded_payloads[2]['currency'] ?? '' ) !== (string) $operation['currency'] ||
			! in_array( (string) ( $decoded_payloads[2]['refund_type'] ?? '' ), array( 'full', 'partial' ), true ) ||
			(string) ( $decoded_payloads[2]['reason'] ?? '' ) !== (string) $payload['reason'] ||
			(int) ( $decoded_payloads[2]['actor_user_id'] ?? -1 ) !== (int) $payload['actor_user_id'] ||
			( $decoded_payloads[2]['item_ids'] ?? null ) !== $payload['item_ids'] ||
			false !== ( $decoded_payloads[2]['manage_stock'] ?? null ) ||
			(bool) ( $decoded_payloads[2]['stock_restore_requested'] ?? false ) !== (bool) $payload['manage_stock'] ||
			( $decoded_payloads[2]['refunded_items'] ?? null ) !== $payload['refunded_items'] ||
			( $decoded_payloads[2]['refunded_item_snapshots'] ?? null ) !== $hook_snapshot
		) {
			$this->accountingDrift();
		}

		return $plan;
	}

	/**
	 * @param array<string,mixed>            $operation
	 * @param array<int,array<string,mixed>> $expected_plan
	 * @param array<int,array<string,mixed>> $actual_rows
	 */
	private function assertOutboxPlan( array $operation, array $expected_plan, array $actual_rows ): void {
		if ( count( $expected_plan ) !== count( $actual_rows ) ) {
			$this->accountingDrift();
		}

		foreach ( $expected_plan as $index => $expected ) {
			$actual = $actual_rows[ $index ] ?? null;
			if (
				! is_array( $actual ) ||
				(string) ( $actual['operation_uuid'] ?? '' ) !== (string) $operation['operation_uuid'] ||
				(string) ( $actual['effect_type'] ?? '' ) !== (string) $expected['effect_type'] ||
				(string) ( $actual['effect_class'] ?? '' ) !== (string) $expected['effect_class'] ||
				(int) ( $actual['sequence'] ?? -1 ) !== (int) $expected['sequence'] ||
				(string) ( $actual['payload'] ?? '' ) !== (string) $expected['payload'] ||
				(string) ( $actual['payload_hash'] ?? '' ) !== (string) $expected['payload_hash'] ||
				! hash_equals( hash( 'sha256', (string) ( $actual['payload'] ?? '' ) ), (string) ( $actual['payload_hash'] ?? '' ) )
			) {
				$this->accountingDrift();
			}

			$status = (string) ( $actual['status'] ?? '' );
			if ( 'skipped' === (string) $expected['status'] ) {
				if ( 'skipped' !== $status ) {
					$this->accountingDrift();
				}
				continue;
			}

			if ( 'applied' === (string) $operation['local_status'] ) {
				$allowed = 'stock_restore' === (string) $expected['effect_type']
					? array( 'completed' )
					: array( 'completed', 'failed', 'indeterminate' );
			} else {
				$allowed = array( 'pending', 'processing', 'completed', 'failed', 'indeterminate' );
			}
			if ( ! in_array( $status, $allowed, true ) ) {
				$this->accountingDrift();
			}
		}
	}

	/** @param array<string,mixed> $data @param array<string,mixed> $where */
	private function mustUpdate( string $table, array $data, array $where ): void {
		if ( 1 !== $this->database->update( $table, $data, $where ) ) {
			throw new \RuntimeException( 'Locked row update failed.' );
		}
	}

	private function commit(): void {
		if ( false === $this->database->query( 'COMMIT' ) ) {
			throw new \RuntimeException( 'Commit failed.' );
		}
	}

	private function checkpoint( string $name ): void {
		if ( null !== $this->checkpoint ) {
			( $this->checkpoint )( $name );
		}
	}

	private function invalidPayload(): never {
		$this->abort(
			'ys_helcim_local_payload_invalid',
			__( 'The saved local refund instructions failed integrity validation.', 'ys-helcim-via-fluentcart' )
		);
	}

	private function accountingDrift(): never {
		$this->abort(
			'ys_helcim_accounting_drift',
			__( 'Local order accounting changed and requires reconciliation before this refund can be recorded.', 'ys-helcim-via-fluentcart' )
		);
	}

	private function abort( string $code, string $message ): never {
		throw new YSHelcimLocalRefundRecorderAbort( $code, $message );
	}

	private static function isUuid( string $value ): bool {
		return 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', strtolower( $value ) );
	}

	/** @param array<int|string,mixed> $value @param array<int,string> $expected */
	private static function hasExactKeys( array $value, array $expected ): bool {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );

		return $actual === $expected;
	}

	/** @return int|null */
	private static function positiveInteger( $value ): ?int {
		$integer = self::nonnegativeInteger( $value );
		return null !== $integer && $integer > 0 ? $integer : null;
	}

	/** @return int|null */
	private static function nonnegativeInteger( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) ) {
			return null;
		}
		$normalized = ltrim( $value, '0' );
		$normalized = '' === $normalized ? '0' : $normalized;
		$maximum    = (string) PHP_INT_MAX;
		if ( strlen( $normalized ) > strlen( $maximum ) || ( strlen( $normalized ) === strlen( $maximum ) && strcmp( $normalized, $maximum ) > 0 ) ) {
			return null;
		}

		return (int) $normalized;
	}

	/** @return string|null */
	private static function positiveIntegerString( $value ): ?string {
		if ( is_int( $value ) ) {
			return $value > 0 ? (string) $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[1-9][0-9]{0,63}\z/', $value ) ) {
			return null;
		}

		return $value;
	}

	/** @return array<string,mixed>|null */
	private static function jsonObject( $value ): ?array {
		if ( null === $value || '' === $value ) {
			return array();
		}
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}

		try {
			$decoded = json_decode( $value, true, 64, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			unset( $exception );
			return null;
		}

		return is_array( $decoded ) ? $decoded : null;
	}

	private static function storageError(): \WP_Error {
		return self::error(
			'ys_helcim_local_storage_unavailable',
			__( 'The refund was confirmed by the provider, but local recording is temporarily unavailable. Do not send it again.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message );
	}
}

/** Internal typed abort used to distinguish validation failures from storage faults. */
final class YSHelcimLocalRefundRecorderAbort extends \RuntimeException {

	private string $error_code;

	public function __construct( string $error_code, string $message ) {
		parent::__construct( $message );
		$this->error_code = $error_code;
	}

	public function errorCode(): string {
		return $this->error_code;
	}
}
