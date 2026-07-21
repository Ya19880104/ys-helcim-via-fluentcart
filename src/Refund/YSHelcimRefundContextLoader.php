<?php
/**
 * Consistent read-only FluentCart context for remote-first refunds.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and cross-checks source, order, refund and item rows in one snapshot.
 */
final class YSHelcimRefundContextLoader {

	/** @var object wpdb-compatible database object. */
	private $database;

	private string $transactions_table;

	private string $orders_table;

	private string $items_table;

	public function __construct( ?object $database = null ) {
		if ( null === $database ) {
			global $wpdb;
			$database = $wpdb;
		}

		$this->database           = $database;
		$this->transactions_table = $database->prefix . 'fct_order_transactions';
		$this->orders_table       = $database->prefix . 'fct_orders';
		$this->items_table        = $database->prefix . 'fct_order_items';
	}

	/** @return array<string,mixed>|\WP_Error */
	public function __invoke( int $transaction_id ) {
		return $this->load( $transaction_id );
	}

	/**
	 * Load the exact server-owned context accepted by YSHelcimRefundRequest.
	 *
	 * This deliberately does not call OrderTransaction::getMaxRefundableAmount(),
	 * because the native Helcim refund veto makes that public accessor zero.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function load( int $transaction_id ) {
		if ( $transaction_id <= 0 ) {
			return self::accountingDrift();
		}

		if ( ! $this->beginSnapshot() ) {
			$this->rollback();
			return self::storageUnavailable();
		}

		try {
			$source       = $this->sourceRow( $transaction_id );
			$source_data  = $this->validateSource( $source, $transaction_id );
			$order        = $this->orderRow( $source_data['order_id'] );
			$refund_rows  = $this->refundRows( $source_data['order_id'] );
			$item_rows    = $this->itemRows( $source_data['order_id'] );
			$context      = $this->buildContext( $source_data, $order, $refund_rows, $item_rows );
		} catch ( YSHelcimRefundContextDrift $exception ) {
			unset( $exception );
			return $this->rollback() ? self::accountingDrift() : self::storageUnavailable();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$this->rollback();
			return self::storageUnavailable();
		}

		try {
			if ( false === $this->database->query( 'COMMIT' ) ) {
				$this->rollback();
				return self::storageUnavailable();
			}
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$this->rollback();
			return self::storageUnavailable();
		}

		return $context;
	}

	private function beginSnapshot(): bool {
		try {
			if ( false === $this->database->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) ) {
				return false;
			}

			return false !== $this->database->query( 'START TRANSACTION READ ONLY' );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return false;
		}
	}

	private function rollback(): bool {
		try {
			return false !== $this->database->query( 'ROLLBACK' );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return false;
		}
	}

	/** @return array<string,mixed> */
	private function sourceRow( int $transaction_id ): array {
		$row = $this->database->get_row(
			$this->database->prepare(
				"SELECT id, order_id, uuid, vendor_charge_id, payment_method, payment_mode,
				currency, status, transaction_type, order_type, total, meta
				FROM `{$this->transactions_table}` WHERE id = %d LIMIT 1",
				$transaction_id
			),
			ARRAY_A
		);
		$this->assertReadSucceeded();
		if ( ! is_array( $row ) ) {
			throw new YSHelcimRefundContextDrift();
		}

		return $row;
	}

	/** @return array<string,mixed> */
	private function orderRow( int $order_id ): array {
		$row = $this->database->get_row(
			$this->database->prepare(
				"SELECT id, type, uuid, customer_id, currency, total_paid, total_refund, payment_status
				FROM `{$this->orders_table}` WHERE id = %d LIMIT 1",
				$order_id
			),
			ARRAY_A
		);
		$this->assertReadSucceeded();
		if ( ! is_array( $row ) ) {
			throw new YSHelcimRefundContextDrift();
		}

		return $row;
	}

	/** @return array<int,array<string,mixed>> */
	private function refundRows( int $order_id ): array {
		$rows = $this->database->get_results(
			$this->database->prepare(
				"SELECT id, order_id, transaction_type, status, total, meta
				FROM `{$this->transactions_table}`
				WHERE order_id = %d AND transaction_type = 'refund'
				ORDER BY id ASC",
				$order_id
			),
			ARRAY_A
		);
		$this->assertReadSucceeded();
		if ( ! is_array( $rows ) ) {
			throw new \RuntimeException( 'Refund rows could not be read.' );
		}

		return $rows;
	}

	/** @return array<int,array<string,mixed>> */
	private function itemRows( int $order_id ): array {
		$rows = $this->database->get_results(
			$this->database->prepare(
				"SELECT id, order_id, post_id, object_id, quantity, unit_price, subtotal,
				tax_amount, shipping_charge, discount_total, line_total, refund_total
				FROM `{$this->items_table}` WHERE order_id = %d ORDER BY id ASC",
				$order_id
			),
			ARRAY_A
		);
		$this->assertReadSucceeded();
		if ( ! is_array( $rows ) ) {
			throw new \RuntimeException( 'Order item rows could not be read.' );
		}

		return $rows;
	}

	private function assertReadSucceeded(): void {
		if ( '' !== trim( (string) ( $this->database->last_error ?? '' ) ) ) {
			throw new \RuntimeException( 'The FluentCart refund context query failed.' );
		}
	}

	/** @param array<string,mixed> $source @return array<string,mixed> */
	private function validateSource( array $source, int $transaction_id ): array {
		$id             = self::positiveInteger( $source['id'] ?? null );
		$order_id       = self::positiveInteger( $source['order_id'] ?? null );
		$total          = self::positiveInteger( $source['total'] ?? null );
		$vendor_id      = self::positiveIntegerString( $source['vendor_charge_id'] ?? null );
		$uuid           = is_string( $source['uuid'] ?? null ) ? $source['uuid'] : '';
		$gateway        = is_string( $source['payment_method'] ?? null ) ? $source['payment_method'] : '';
		$currency       = is_string( $source['currency'] ?? null ) ? $source['currency'] : '';
		$payment_mode   = is_string( $source['payment_mode'] ?? null ) ? $source['payment_mode'] : '';
		$order_type     = is_string( $source['order_type'] ?? null ) ? $source['order_type'] : '';
		$source_meta    = self::jsonObject( $source['meta'] ?? null );
		$meta_refunded  = null === $source_meta
			? null
			: self::nonnegativeInteger( $source_meta['refunded_total'] ?? 0 );

		if (
			$transaction_id !== $id ||
			null === $order_id ||
			null === $total ||
			null === $vendor_id ||
			'' === $uuid ||
			trim( $uuid ) !== $uuid ||
			strlen( $uuid ) > 191 ||
			! in_array( $gateway, array( 'ys_helcim', 'ys_helcim_js' ), true ) ||
			! in_array( $currency, array( 'USD', 'CAD' ), true ) ||
			! in_array( $payment_mode, array( 'test', 'live' ), true ) ||
			'' === $order_type ||
			trim( $order_type ) !== $order_type ||
			'succeeded' !== ( $source['status'] ?? null ) ||
			'charge' !== ( $source['transaction_type'] ?? null ) ||
			null === $source_meta ||
			null === $meta_refunded ||
			$meta_refunded > $total
		) {
			throw new YSHelcimRefundContextDrift();
		}

		return array(
			'order_id'              => $order_id,
			'transaction_id'        => $id,
			'transaction_uuid'      => $uuid,
			'vendor_transaction_id' => $vendor_id,
			'gateway'               => $gateway,
			'status'                => 'succeeded',
			'transaction_type'      => 'charge',
			'transaction_total'     => $total,
			'meta_refunded_total'   => $meta_refunded,
			'currency'              => $currency,
			'payment_mode'          => $payment_mode,
			'order_type'            => $order_type,
		);
	}

	/**
	 * @param array<string,mixed>            $source
	 * @param array<string,mixed>            $order
	 * @param array<int,array<string,mixed>> $refund_rows
	 * @param array<int,array<string,mixed>> $item_rows
	 * @return array<string,mixed>
	 */
	private function buildContext( array $source, array $order, array $refund_rows, array $item_rows ): array {
		$order_id     = self::positiveInteger( $order['id'] ?? null );
		$order_paid   = self::positiveInteger( $order['total_paid'] ?? null );
		$order_refund = self::nonnegativeInteger( $order['total_refund'] ?? null );
		$order_type   = is_string( $order['type'] ?? null ) ? $order['type'] : '';
		$order_uuid   = is_string( $order['uuid'] ?? null ) ? $order['uuid'] : '';
		$customer_id  = self::nonnegativeInteger( $order['customer_id'] ?? null );
		if (
			$source['order_id'] !== $order_id ||
			null === $order_paid ||
			null === $order_refund ||
			$source['order_type'] !== $order_type ||
			'' === $order_uuid ||
			trim( $order_uuid ) !== $order_uuid ||
			strlen( $order_uuid ) > 100 ||
			null === $customer_id ||
			$order_refund > $order_paid ||
			$source['currency'] !== ( $order['currency'] ?? null )
		) {
			throw new YSHelcimRefundContextDrift();
		}

		$order_refund_sum  = 0;
		$source_refund_sum = 0;
		foreach ( $refund_rows as $refund ) {
			if (
				! is_array( $refund ) ||
				null === self::positiveInteger( $refund['id'] ?? null ) ||
				$source['order_id'] !== self::positiveInteger( $refund['order_id'] ?? null ) ||
				'refund' !== ( $refund['transaction_type'] ?? null )
			) {
				throw new YSHelcimRefundContextDrift();
			}
			if ( 'refunded' !== ( $refund['status'] ?? null ) ) {
				continue;
			}

			$total     = self::positiveInteger( $refund['total'] ?? null );
			$meta      = self::jsonObject( $refund['meta'] ?? null );
			$parent_id = null === $meta ? null : self::positiveInteger( $meta['parent_id'] ?? null );
			if ( null === $total || null === $meta || null === $parent_id || $total > PHP_INT_MAX - $order_refund_sum ) {
				throw new YSHelcimRefundContextDrift();
			}

			$order_refund_sum += $total;
			if ( $source['transaction_id'] === $parent_id ) {
				if ( $total > PHP_INT_MAX - $source_refund_sum ) {
					throw new YSHelcimRefundContextDrift();
				}
				$source_refund_sum += $total;
			}
		}

		if (
			$order_refund !== $order_refund_sum ||
			$source['meta_refunded_total'] !== $source_refund_sum ||
			$source_refund_sum > $source['transaction_total']
		) {
			throw new YSHelcimRefundContextDrift();
		}
		if ( $order_refund > 0 ) {
			$expected_payment_status = $order_refund >= $order_paid ? 'refunded' : 'partially_refunded';
			if ( $expected_payment_status !== ( $order['payment_status'] ?? null ) ) {
				throw new YSHelcimRefundContextDrift();
			}
		}

		$item_quantities      = array();
		$item_remaining_total = 0;
		foreach ( $item_rows as $item ) {
			$item_id      = is_array( $item ) ? self::positiveInteger( $item['id'] ?? null ) : null;
			$item_order   = is_array( $item ) ? self::positiveInteger( $item['order_id'] ?? null ) : null;
			$post_id      = is_array( $item ) ? self::nonnegativeInteger( $item['post_id'] ?? null ) : null;
			$object_value = is_array( $item ) && array_key_exists( 'object_id', $item ) ? $item['object_id'] : null;
			$object_id    = null === $object_value ? 0 : self::nonnegativeInteger( $object_value );
			$quantity     = is_array( $item ) ? self::positiveInteger( $item['quantity'] ?? null ) : null;
			$money        = array();
			foreach ( array( 'unit_price', 'subtotal', 'tax_amount', 'shipping_charge', 'discount_total', 'line_total', 'refund_total' ) as $field ) {
				$money[ $field ] = is_array( $item ) ? self::nonnegativeInteger( $item[ $field ] ?? null ) : null;
			}
			$line_total   = $money['line_total'];
			$refund_total = $money['refund_total'];
			if (
				null === $item_id ||
				$source['order_id'] !== $item_order ||
				null === $post_id ||
				null === $object_id ||
				null === $quantity ||
				in_array( null, $money, true ) ||
				$refund_total > $line_total ||
				isset( $item_quantities[ $item_id ] )
			) {
				throw new YSHelcimRefundContextDrift();
			}
			$item_remaining = $line_total - $refund_total;
			if ( $item_remaining > PHP_INT_MAX - $item_remaining_total ) {
				throw new YSHelcimRefundContextDrift();
			}
			$item_remaining_total += $item_remaining;
			$item_quantities[ $item_id ] = $quantity;
		}
		if ( empty( $item_quantities ) ) {
			throw new YSHelcimRefundContextDrift();
		}
		ksort( $item_quantities, SORT_NUMERIC );

		$source_remaining = $source['transaction_total'] - $source_refund_sum;
		$order_remaining  = $order_paid - $order_refund_sum;

		return array(
			'order_id'                 => $source['order_id'],
			'transaction_id'           => $source['transaction_id'],
			'transaction_uuid'         => $source['transaction_uuid'],
			'vendor_transaction_id'    => $source['vendor_transaction_id'],
			'gateway'                  => $source['gateway'],
			'status'                   => $source['status'],
			'transaction_type'         => $source['transaction_type'],
			'transaction_total'        => $source['transaction_total'],
			'refunded_total'           => $source_refund_sum,
			'remaining_refundable'     => min( $source_remaining, $order_remaining ),
			'currency'                 => $source['currency'],
			'payment_mode'             => $source['payment_mode'],
			'order_item_quantities'    => $item_quantities,
		);
	}

	private static function positiveInteger( mixed $value ): ?int {
		$integer = self::nonnegativeInteger( $value );
		return null !== $integer && $integer > 0 ? $integer : null;
	}

	private static function nonnegativeInteger( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) ) {
			return null;
		}

		$maximum = (string) PHP_INT_MAX;
		return strlen( $value ) < strlen( $maximum ) || ( strlen( $value ) === strlen( $maximum ) && strcmp( $value, $maximum ) <= 0 )
			? (int) $value
			: null;
	}

	private static function positiveIntegerString( mixed $value ): ?string {
		if ( is_int( $value ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $value ) ) {
			return null;
		}

		$maximum = (string) PHP_INT_MAX;
		return strlen( $value ) < strlen( $maximum ) || ( strlen( $value ) === strlen( $maximum ) && strcmp( $value, $maximum ) <= 0 )
			? $value
			: null;
	}

	/** @return array<string,mixed>|null */
	private static function jsonObject( mixed $value ): ?array {
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

	private static function accountingDrift(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_accounting_drift',
			__( 'The FluentCart refund totals or payment identity changed. No provider request was sent.', 'ys-helcim-via-fluentcart' ),
			array( 'status' => 409 )
		);
	}

	private static function storageUnavailable(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_refund_context_unavailable',
			__( 'The refund context could not be loaded safely. No provider request was sent.', 'ys-helcim-via-fluentcart' ),
			array( 'status' => 503 )
		);
	}
}

/** Internal marker for a complete but inconsistent FluentCart snapshot. */
final class YSHelcimRefundContextDrift extends \RuntimeException {
}
