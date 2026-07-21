<?php
/**
 * Consistent FluentCart snapshot for the Helcim refund options UI.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationSchema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Reads only the server-owned rows required by YSHelcimRefundOptionsLoader. */
final class YSHelcimRefundOptionsQuery {

	/** @var object */
	private $database;

	private string $orders_table;

	private string $transactions_table;

	private string $items_table;

	private string $operations_table;

	private string $outbox_table;

	public function __construct( ?object $database = null ) {
		if ( null === $database ) {
			global $wpdb;
			$database = $wpdb;
		}
		$this->database           = $database;
		$this->orders_table       = $database->prefix . 'fct_orders';
		$this->transactions_table = $database->prefix . 'fct_order_transactions';
		$this->items_table        = $database->prefix . 'fct_order_items';
		$this->operations_table   = YSHelcimOperationSchema::tableName( $database );
		$this->outbox_table       = YSHelcimOperationSchema::outboxTableName( $database );
	}

	/** @return array<string,mixed>|\WP_Error|null */
	public function __invoke( int $order_id ) {
		return $this->load( $order_id );
	}

	/** @return array<string,mixed>|\WP_Error|null */
	public function load( int $order_id ) {
		if ( $order_id <= 0 ) {
			return self::invalidOrder();
		}
		if ( ! $this->beginSnapshot() ) {
			$this->rollback();
			return self::unavailable();
		}

		try {
			$order = $this->database->get_row(
				$this->database->prepare(
					"/* ys_helcim_refund_options_order */
					SELECT id, currency, total_paid, total_refund
					FROM `{$this->orders_table}` WHERE id = %d LIMIT 1",
					$order_id
				),
				ARRAY_A
			);
			$this->assertReadSucceeded();
			if ( null === $order ) {
				if ( false === $this->database->query( 'COMMIT' ) ) {
					$this->rollback();
					return self::unavailable();
				}
				return null;
			}
			if ( ! is_array( $order ) ) {
				throw new \RuntimeException( 'Invalid order row.' );
			}

			$transactions = $this->database->get_results(
				$this->database->prepare(
					"/* ys_helcim_refund_options_transactions */
					SELECT id, order_id, payment_method, status, transaction_type, total, meta
					FROM `{$this->transactions_table}`
					WHERE order_id = %d AND transaction_type = 'charge'
					ORDER BY id ASC",
					$order_id
				),
				ARRAY_A
			);
			$this->assertReadSucceeded();

			$items = $this->database->get_results(
				$this->database->prepare(
					"/* ys_helcim_refund_options_items */
					SELECT id, order_id, title, quantity, line_total, refund_total
					FROM `{$this->items_table}` WHERE order_id = %d ORDER BY id ASC",
					$order_id
				),
				ARRAY_A
			);
			$this->assertReadSucceeded();

			$operations = $this->database->get_results(
				$this->database->prepare(
					"/* ys_helcim_refund_options_operations */
					SELECT operations.operation_uuid, operations.order_id, operations.operation_type,
						operations.active_scope_key, operations.remote_status, operations.local_status,
						MAX(CASE WHEN effects.status IN ('failed', 'indeterminate') THEN 1 ELSE 0 END) AS manual_reconciliation_required,
						MAX(CASE WHEN effects.effect_type = 'stock_restore' AND effects.status IN ('failed', 'indeterminate')
							THEN 'stock_reconciliation_required' ELSE '' END) AS effect_status
					FROM `{$this->operations_table}` AS operations
					LEFT JOIN `{$this->outbox_table}` AS effects ON effects.operation_uuid = operations.operation_uuid
					WHERE operations.order_id = %d AND operations.operation_type IN ('refund', 'reverse')
					GROUP BY operations.id, operations.operation_uuid, operations.order_id, operations.operation_type,
						operations.active_scope_key, operations.remote_status, operations.local_status
					ORDER BY operations.id ASC",
					$order_id
				),
				ARRAY_A
			);
			$this->assertReadSucceeded();

			if ( ! is_array( $transactions ) || ! is_array( $items ) || ! is_array( $operations ) ) {
				throw new \RuntimeException( 'Invalid refund options rows.' );
			}
			$result = array(
				'order'        => $order,
				'transactions' => self::projectTransactions( $transactions ),
				'items'        => self::projectItems( $items ),
				'operations'   => array_values( $operations ),
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$this->rollback();
			return self::unavailable();
		}

		try {
			if ( false === $this->database->query( 'COMMIT' ) ) {
				$this->rollback();
				return self::unavailable();
			}
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$this->rollback();
			return self::unavailable();
		}

		return $result;
	}

	private function beginSnapshot(): bool {
		try {
			return false !== $this->database->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' )
				&& false !== $this->database->query( 'START TRANSACTION READ ONLY' );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return false;
		}
	}

	private function rollback(): void {
		try {
			$this->database->query( 'ROLLBACK' );
		} catch ( \Throwable $exception ) {
			unset( $exception );
		}
	}

	private function assertReadSucceeded(): void {
		if ( '' !== trim( (string) ( $this->database->last_error ?? '' ) ) ) {
			throw new \RuntimeException( 'Refund options storage read failed.' );
		}
	}

	/** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
	private static function projectTransactions( array $rows ): array {
		$result = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				$result[] = array();
				continue;
			}
			$total      = self::nonnegativeInteger( $row['total'] ?? null );
			$meta       = self::jsonObject( $row['meta'] ?? null );
			$refunded   = null === $meta ? null : self::nonnegativeInteger( $meta['refunded_total'] ?? 0 );
			$remaining  = null !== $total && null !== $refunded && $refunded <= $total
				? $total - $refunded
				: null;
			$result[] = array(
				'id'                   => $row['id'] ?? null,
				'order_id'             => $row['order_id'] ?? null,
				'payment_method'       => $row['payment_method'] ?? null,
				'status'               => $row['status'] ?? null,
				'transaction_type'     => $row['transaction_type'] ?? null,
				'remaining_refundable' => $remaining,
			);
		}
		return $result;
	}

	/** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
	private static function projectItems( array $rows ): array {
		$result = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				$result[] = array();
				continue;
			}
			$quantity     = self::positiveInteger( $row['quantity'] ?? null );
			$line_total   = self::nonnegativeInteger( $row['line_total'] ?? null );
			$refund_total = self::nonnegativeInteger( $row['refund_total'] ?? null );
			$refundable   = null !== $quantity && null !== $line_total && null !== $refund_total && $refund_total < $line_total
				? $quantity
				: 0;
			$result[] = array(
				'id'                  => $row['id'] ?? null,
				'title'               => is_string( $row['title'] ?? null ) ? $row['title'] : '',
				'quantity'            => $quantity ?? 0,
				'refundable_quantity' => $refundable,
			);
		}
		return $result;
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

	/** @return array<string,mixed>|null */
	private static function jsonObject( mixed $value ): ?array {
		if ( null === $value || '' === $value ) {
			return array();
		}
		if ( is_array( $value ) && ! array_is_list( $value ) ) {
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
		return is_array( $decoded ) && ! array_is_list( $decoded ) ? $decoded : null;
	}

	private static function invalidOrder(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_invalid_order',
			__( 'The requested order is invalid.', 'ys-helcim-via-fluentcart' ),
			array( 'status' => 422 )
		);
	}

	private static function unavailable(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_refund_options_unavailable',
			__( 'Refund options could not be loaded safely.', 'ys-helcim-via-fluentcart' ),
			array( 'status' => 503 )
		);
	}
}
