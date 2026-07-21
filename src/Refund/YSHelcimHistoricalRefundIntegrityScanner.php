<?php
/**
 * Read-only deployment gate for historical FluentCart Helcim refunds.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans every FluentCart order containing a Helcim transaction without
 * contacting Helcim or mutating local state.
 */
final class YSHelcimHistoricalRefundIntegrityScanner {

	private const GATEWAYS = array( 'ys_helcim', 'ys_helcim_js' );

	private const MODES = array( 'test', 'live' );

	/** @var object wpdb-compatible database object. */
	private $database;

	private string $transactions_table;

	private string $orders_table;

	private int $issue_limit;

	private int $page_size;

	/**
	 * @param object|null $database    wpdb-compatible database object.
	 * @param int         $issue_limit Maximum issue details retained in the report.
	 * @param int         $page_size   Maximum orders or duplicate groups held per query.
	 */
	public function __construct( ?object $database = null, int $issue_limit = 100, int $page_size = 200 ) {
		if ( null === $database ) {
			global $wpdb;
			$database = $wpdb;
		}

		$this->database           = $database;
		$this->transactions_table = $database->prefix . 'fct_order_transactions';
		$this->orders_table       = $database->prefix . 'fct_orders';
		$this->issue_limit        = max( 1, min( 200, $issue_limit ) );
		$this->page_size          = max( 1, min( 500, $page_size ) );
	}

	public function __invoke(): YSHelcimHistoricalRefundIntegrityReport {
		return $this->scan();
	}

	/** Run the complete site-wide gate inside one read-only snapshot. */
	public function scan(): YSHelcimHistoricalRefundIntegrityReport {
		if ( ! $this->beginSnapshot() ) {
			$this->rollback();
			return YSHelcimHistoricalRefundIntegrityReport::unavailable( $this->issue_limit );
		}

		$state = array(
			'blocker_count' => 0,
			'issues'        => array(),
			'scanned'       => array(
				'orders'       => 0,
				'transactions' => 0,
				'charges'      => 0,
				'refunds'      => 0,
			),
		);

		try {
			$this->scanOrders( $state );
			$this->scanDuplicateReceipts( $state );
			if ( false === $this->database->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Historical integrity snapshot could not be committed.' );
			}
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$this->rollback();
			return YSHelcimHistoricalRefundIntegrityReport::unavailable( $this->issue_limit );
		}

		return YSHelcimHistoricalRefundIntegrityReport::complete(
			$state['blocker_count'],
			$state['issues'],
			$state['scanned'],
			$this->issue_limit
		);
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

	/** @param array<string,mixed> $state */
	private function scanOrders( array &$state ): void {
		$cursor     = 0;
		$first_page = true;

		do {
			$comparison = $first_page ? '>=' : '>';
			$candidates = $this->readRows(
				$this->database->prepare(
					"SELECT DISTINCT order_id
					FROM (
						SELECT id AS order_id
						FROM `{$this->orders_table}`
						WHERE payment_method IN ('ys_helcim', 'ys_helcim_js')
						UNION ALL
						SELECT order_id
						FROM `{$this->transactions_table}`
						WHERE payment_method IN ('ys_helcim', 'ys_helcim_js')
					) AS ys_helcim_candidate_orders
					WHERE order_id {$comparison} %d
					ORDER BY order_id ASC
					LIMIT %d",
					$cursor,
					$this->page_size
				)
			);
			if ( array() === $candidates ) {
				break;
			}

			$order_ids = array();
			$allow_equal = $first_page;
			foreach ( $candidates as $candidate ) {
				$order_id = self::nonnegativeInteger( $candidate['order_id'] ?? null );
				if (
					null === $order_id ||
					$order_id < $cursor ||
					( $order_id === $cursor && ! $allow_equal )
				) {
					throw new \RuntimeException( 'Historical integrity pagination became ambiguous.' );
				}
				$order_ids[] = $order_id;
				$cursor      = $order_id;
				$allow_equal = false;
			}
			if ( count( array_unique( $order_ids, SORT_NUMERIC ) ) !== count( $order_ids ) ) {
				throw new \RuntimeException( 'Historical integrity order page contained duplicates.' );
			}

			$orders       = $this->ordersById( $order_ids );
			$transactions = $this->transactionsByOrder( $order_ids );
			foreach ( $order_ids as $order_id ) {
				++$state['scanned']['orders'];
				$this->scanOrder(
					$order_id,
					$orders[ $order_id ] ?? null,
					$transactions[ $order_id ] ?? array(),
					$state
				);
			}
			$first_page = false;
		} while ( count( $candidates ) === $this->page_size );
	}

	/**
	 * @param int[] $order_ids
	 * @return array<int,array<string,mixed>>
	 */
	private function ordersById( array $order_ids ): array {
		$placeholders = implode( ', ', array_fill( 0, count( $order_ids ), '%d' ) );
		$rows         = $this->readRows(
			$this->database->prepare(
				"SELECT id, type, mode, payment_method, payment_status, currency,
					total_amount, total_paid, total_refund
				FROM `{$this->orders_table}`
				WHERE id IN ({$placeholders})
				ORDER BY id ASC",
				...$order_ids
			)
		);

		$indexed = array();
		foreach ( $rows as $row ) {
			$id = self::positiveInteger( $row['id'] ?? null );
			if ( null === $id || ! in_array( $id, $order_ids, true ) || isset( $indexed[ $id ] ) ) {
				throw new \RuntimeException( 'Historical integrity order read returned an invalid identity.' );
			}
			$indexed[ $id ] = $row;
		}

		return $indexed;
	}

	/**
	 * @param int[] $order_ids
	 * @return array<int,array<int,array<string,mixed>>>
	 */
	private function transactionsByOrder( array $order_ids ): array {
		$placeholders = implode( ', ', array_fill( 0, count( $order_ids ), '%d' ) );
		$rows         = $this->readRows(
			$this->database->prepare(
				"SELECT id, order_id, order_type, transaction_type, vendor_charge_id,
					payment_method, payment_mode, status, currency, total, meta
				FROM `{$this->transactions_table}`
				WHERE order_id IN ({$placeholders})
				ORDER BY order_id ASC, id ASC",
				...$order_ids
			)
		);

		$indexed  = array();
		$seen_ids = array();
		foreach ( $rows as $row ) {
			$id       = self::positiveInteger( $row['id'] ?? null );
			$order_id = self::nonnegativeInteger( $row['order_id'] ?? null );
			if (
				null === $id ||
				null === $order_id ||
				! in_array( $order_id, $order_ids, true ) ||
				isset( $seen_ids[ $id ] )
			) {
				throw new \RuntimeException( 'Historical integrity transaction read returned an invalid identity.' );
			}
			$seen_ids[ $id ]     = true;
			$indexed[ $order_id ][] = $row;
		}

		return $indexed;
	}

	/**
	 * @param array<string,mixed>|null       $order
	 * @param array<int,array<string,mixed>> $transactions
	 * @param array<string,mixed>            $state
	 */
	private function scanOrder( int $order_id, ?array $order, array $transactions, array &$state ): void {
		$state['scanned']['transactions'] += count( $transactions );
		if ( null === $order ) {
			$this->addIssue( $state, 'unknown_order', array( 'order_id' => $order_id ) );
		}

		$by_id           = array();
		$order_is_helcim = is_array( $order ) && self::isHelcimGateway( $order['payment_method'] ?? null );
		foreach ( $transactions as $row ) {
			$id = self::positiveInteger( $row['id'] ?? null );
			if ( null === $id ) {
				throw new \RuntimeException( 'Historical integrity transaction identity changed.' );
			}
			$by_id[ $id ] = $row;
			if (
				'charge' === ( $row['transaction_type'] ?? null ) &&
				self::isHelcimGateway( $row['payment_method'] ?? null )
			) {
				++$state['scanned']['charges'];
				$this->validateChargeIdentity( $row, $state );
			}
		}

		$parent_refund_sums = array();
		$parent_has_refunds = array();
		foreach ( $transactions as $refund ) {
			if ( 'refund' !== ( $refund['transaction_type'] ?? null ) ) {
				continue;
			}

			$refund_id = self::positiveInteger( $refund['id'] ?? null );
			$meta      = self::jsonObject( $refund['meta'] ?? null );
			$parent_id = null === $meta ? null : self::positiveInteger( $meta['parent_id'] ?? null );
			$parent    = null === $parent_id ? null : ( $by_id[ $parent_id ] ?? null );
			$is_related = self::isHelcimGateway( $refund['payment_method'] ?? null )
				|| ( is_array( $parent ) && self::isHelcimGateway( $parent['payment_method'] ?? null ) )
				|| $order_is_helcim;
			if ( ! $is_related ) {
				continue;
			}

			++$state['scanned']['refunds'];
			$this->validateRefundIdentity( $order_id, $refund, $parent_id, $parent, $state );

			if ( null === $parent_id || ! is_array( $parent ) || 'charge' !== ( $parent['transaction_type'] ?? null ) ) {
				continue;
			}
			$parent_has_refunds[ $parent_id ] = true;
			if ( 'refunded' !== ( $refund['status'] ?? null ) ) {
				continue;
			}
			$total = self::positiveInteger( $refund['total'] ?? null );
			if ( null === $total ) {
				continue;
			}
			$current = $parent_refund_sums[ $parent_id ] ?? 0;
			$sum     = self::safeAdd( $current, $total );
			if ( null === $sum ) {
				$this->addIssue(
					$state,
					'charge_refund_total_overflow',
					array( 'order_id' => $order_id, 'transaction_id' => $parent_id )
				);
			} else {
				$parent_refund_sums[ $parent_id ] = $sum;
			}
		}

		foreach ( $parent_has_refunds as $parent_id => $_present ) {
			unset( $_present );
			$parent = $by_id[ $parent_id ];
			$this->validateParentAccounting(
				$order_id,
				$parent_id,
				$parent,
				$parent_refund_sums[ $parent_id ] ?? 0,
				$state
			);
		}

		if ( is_array( $order ) ) {
			$this->validateOrderAccounting( $order_id, $order, $transactions, $state );
		}
	}

	/** @param array<string,mixed> $charge @param array<string,mixed> $state */
	private function validateChargeIdentity( array $charge, array &$state ): void {
		$id       = (int) $charge['id'];
		$order_id = (int) $charge['order_id'];
		$mode     = $charge['payment_mode'] ?? null;
		if ( ! in_array( $mode, self::MODES, true ) ) {
			$this->addIssue(
				$state,
				'unknown_charge_mode',
				array( 'order_id' => $order_id, 'transaction_id' => $id )
			);
		}

		$total = self::positiveInteger( $charge['total'] ?? null );
		if ( null === $total ) {
			$this->addIssue(
				$state,
				'invalid_charge_total',
				array( 'order_id' => $order_id, 'transaction_id' => $id )
			);
		}

		if ( 'succeeded' === ( $charge['status'] ?? null ) ) {
			$receipt = $charge['vendor_charge_id'] ?? null;
			if ( null === self::positiveIntegerString( $receipt ) ) {
				$this->addIssue(
					$state,
					empty( $receipt ) ? 'missing_charge_vendor_id' : 'invalid_charge_vendor_id',
					array( 'order_id' => $order_id, 'transaction_id' => $id )
				);
			}
		}
	}

	/**
	 * @param array<string,mixed>      $refund
	 * @param array<string,mixed>|null $parent
	 * @param array<string,mixed>      $state
	 */
	private function validateRefundIdentity(
		int $order_id,
		array $refund,
		?int $parent_id,
		?array $parent,
		array &$state
	): void {
		$refund_id = (int) $refund['id'];
		$context   = array( 'order_id' => $order_id, 'transaction_id' => $refund_id );
		if ( null !== $parent_id ) {
			$context['parent_transaction_id'] = $parent_id;
		}

		if ( 'refunded' !== ( $refund['status'] ?? null ) ) {
			$this->addIssue( $state, 'unexpected_refund_status', $context );
		}
		if ( null === self::positiveInteger( $refund['total'] ?? null ) ) {
			$this->addIssue( $state, 'invalid_refund_total', $context );
		}

		$receipt = $refund['vendor_charge_id'] ?? null;
		if ( null === $receipt || '' === $receipt ) {
			$this->addIssue( $state, 'missing_refund_vendor_id', $context );
		} elseif ( null === self::positiveIntegerString( $receipt ) ) {
			$this->addIssue( $state, 'invalid_refund_vendor_id', $context );
		}

		$refund_gateway = $refund['payment_method'] ?? null;
		if ( ! self::isHelcimGateway( $refund_gateway ) ) {
			$this->addIssue( $state, 'unknown_refund_gateway', $context );
		}
		$refund_mode = $refund['payment_mode'] ?? null;
		if ( ! in_array( $refund_mode, self::MODES, true ) ) {
			$this->addIssue( $state, 'unknown_refund_mode', $context );
		}

		if ( null === $parent_id || ! is_array( $parent ) || 'charge' !== ( $parent['transaction_type'] ?? null ) ) {
			$this->addIssue( $state, 'unknown_refund_parent', $context );
			return;
		}
		if ( 'succeeded' !== ( $parent['status'] ?? null ) ) {
			$this->addIssue( $state, 'invalid_refund_parent_status', $context );
		}

		$parent_gateway = $parent['payment_method'] ?? null;
		if ( ! self::isHelcimGateway( $parent_gateway ) ) {
			$this->addIssue( $state, 'unknown_parent_gateway', $context );
		}
		if ( $refund_gateway !== $parent_gateway ) {
			$this->addIssue( $state, 'refund_gateway_mismatch', $context );
		}

		$parent_mode = $parent['payment_mode'] ?? null;
		if ( ! in_array( $parent_mode, self::MODES, true ) ) {
			$this->addIssue( $state, 'unknown_parent_mode', $context );
		}
		if ( $refund_mode !== $parent_mode ) {
			$this->addIssue( $state, 'refund_mode_mismatch', $context );
		}

		if (
			( $refund['currency'] ?? null ) !== ( $parent['currency'] ?? null ) ||
			( $refund['order_type'] ?? null ) !== ( $parent['order_type'] ?? null )
		) {
			$this->addIssue( $state, 'refund_parent_identity_mismatch', $context );
		}
	}

	/** @param array<string,mixed> $parent @param array<string,mixed> $state */
	private function validateParentAccounting(
		int $order_id,
		int $parent_id,
		array $parent,
		int $refund_sum,
		array &$state
	): void {
		$context      = array( 'order_id' => $order_id, 'transaction_id' => $parent_id );
		$parent_total = self::positiveInteger( $parent['total'] ?? null );
		if ( null !== $parent_total && $refund_sum > $parent_total ) {
			$this->addIssue( $state, 'charge_refund_total_exceeded', $context );
		}

		$meta          = self::jsonObject( $parent['meta'] ?? null );
		$meta_refunded = null === $meta
			? null
			: self::nonnegativeInteger( $meta['refunded_total'] ?? 0 );
		if ( null === $meta_refunded ) {
			$this->addIssue( $state, 'invalid_parent_refunded_total', $context );
			return;
		}
		if ( $meta_refunded !== $refund_sum ) {
			$this->addIssue( $state, 'parent_refunded_total_mismatch', $context );
		}
	}

	/**
	 * @param array<string,mixed>            $order
	 * @param array<int,array<string,mixed>> $transactions
	 * @param array<string,mixed>            $state
	 */
	private function validateOrderAccounting( int $order_id, array $order, array $transactions, array &$state ): void {
		$order_paid   = self::nonnegativeInteger( $order['total_paid'] ?? null );
		$order_refund = self::nonnegativeInteger( $order['total_refund'] ?? null );
		if ( null === $order_paid || null === $order_refund ) {
			$this->addIssue( $state, 'invalid_order_totals', array( 'order_id' => $order_id ) );
		}

		$order_mode = $order['mode'] ?? null;
		if ( ! in_array( $order_mode, self::MODES, true ) ) {
			$this->addIssue( $state, 'unknown_order_mode', array( 'order_id' => $order_id ) );
		}
		if ( ! self::isHelcimGateway( $order['payment_method'] ?? null ) ) {
			$this->addIssue( $state, 'unknown_order_gateway', array( 'order_id' => $order_id ) );
		}

		$charge_sum       = 0;
		$refund_sum       = 0;
		$charge_sum_valid = true;
		$refund_sum_valid = true;
		foreach ( $transactions as $row ) {
			$type   = $row['transaction_type'] ?? null;
			$status = $row['status'] ?? null;
			if ( 'charge' === $type && 'succeeded' === $status ) {
				$total = self::positiveInteger( $row['total'] ?? null );
				$next  = null === $total ? null : self::safeAdd( $charge_sum, $total );
				if ( null === $next ) {
					$charge_sum_valid = false;
				} else {
					$charge_sum = $next;
				}
			}
			if ( 'refund' === $type && 'refunded' === $status ) {
				$total = self::positiveInteger( $row['total'] ?? null );
				$next  = null === $total ? null : self::safeAdd( $refund_sum, $total );
				if ( null === $next ) {
					$refund_sum_valid = false;
				} else {
					$refund_sum = $next;
				}
			}
		}

		if ( null !== $order_paid && $charge_sum_valid && $order_paid !== $charge_sum ) {
			$this->addIssue( $state, 'order_paid_total_mismatch', array( 'order_id' => $order_id ) );
		}
		if ( null !== $order_refund && $refund_sum_valid && $order_refund !== $refund_sum ) {
			$this->addIssue( $state, 'order_refunded_total_mismatch', array( 'order_id' => $order_id ) );
		}
		if ( null !== $order_paid && null !== $order_refund && $order_refund > $order_paid ) {
			$this->addIssue( $state, 'order_refund_total_exceeded', array( 'order_id' => $order_id ) );
		}

		foreach ( $transactions as $row ) {
			if ( ! self::isHelcimGateway( $row['payment_method'] ?? null ) ) {
				continue;
			}
			$context = array( 'order_id' => $order_id, 'transaction_id' => (int) $row['id'] );
			if ( $order_mode !== ( $row['payment_mode'] ?? null ) ) {
				$this->addIssue( $state, 'order_mode_mismatch', $context );
			}
			if (
				( $order['currency'] ?? null ) !== ( $row['currency'] ?? null ) ||
				( $order['type'] ?? null ) !== ( $row['order_type'] ?? null )
			) {
				$this->addIssue( $state, 'order_transaction_identity_mismatch', $context );
			}
		}
	}

	/** @param array<string,mixed> $state */
	private function scanDuplicateReceipts( array &$state ): void {
		$cursor = 0;
		do {
			$rows = $this->readRows(
				$this->database->prepare(
					"SELECT MIN(id) AS first_transaction_id,
						MAX(id) AS last_transaction_id,
						COUNT(*) AS duplicate_count
					FROM `{$this->transactions_table}`
					WHERE transaction_type = 'refund'
						AND status = 'refunded'
						AND payment_method IN ('ys_helcim', 'ys_helcim_js')
						AND vendor_charge_id REGEXP '^[1-9][0-9]*$'
					GROUP BY payment_mode, vendor_charge_id
					HAVING COUNT(*) > 1 AND MIN(id) > %d
					ORDER BY first_transaction_id ASC
					LIMIT %d",
					$cursor,
					$this->page_size
				)
			);
			foreach ( $rows as $row ) {
				$first = self::positiveInteger( $row['first_transaction_id'] ?? null );
				$last  = self::positiveInteger( $row['last_transaction_id'] ?? null );
				$count = self::positiveInteger( $row['duplicate_count'] ?? null );
				if ( null === $first || null === $last || null === $count || $count <= 1 || $first <= $cursor ) {
					throw new \RuntimeException( 'Historical duplicate receipt read returned invalid aggregate data.' );
				}
				$this->addIssue(
					$state,
					'duplicate_refund_vendor_id',
					array(
						'transaction_id'         => $first,
						'related_transaction_id' => $last,
						'occurrence_count'       => $count,
					)
				);
				$cursor = $first;
			}
		} while ( count( $rows ) === $this->page_size );
	}

	/** @return array<int,array<string,mixed>> */
	private function readRows( mixed $query ): array {
		$rows = $this->database->get_results( $query, ARRAY_A );
		if ( '' !== trim( (string) ( $this->database->last_error ?? '' ) ) || ! is_array( $rows ) ) {
			throw new \RuntimeException( 'Historical integrity data could not be read.' );
		}

		return $rows;
	}

	/**
	 * @param array<string,mixed> $state
	 * @param array<string,int>   $context
	 */
	private function addIssue( array &$state, string $code, array $context = array() ): void {
		if ( $state['blocker_count'] < PHP_INT_MAX ) {
			++$state['blocker_count'];
		}
		if ( count( $state['issues'] ) >= $this->issue_limit ) {
			return;
		}

		$issue = array( 'code' => $code );
		foreach ( array( 'order_id', 'transaction_id', 'parent_transaction_id', 'related_transaction_id', 'occurrence_count' ) as $key ) {
			if ( isset( $context[ $key ] ) && is_int( $context[ $key ] ) && $context[ $key ] >= 0 ) {
				$issue[ $key ] = $context[ $key ];
			}
		}
		$state['issues'][] = $issue;
	}

	private static function isHelcimGateway( mixed $gateway ): bool {
		return is_string( $gateway ) && in_array( $gateway, self::GATEWAYS, true );
	}

	private static function safeAdd( int $left, int $right ): ?int {
		return $right <= PHP_INT_MAX - $left ? $left + $right : null;
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
}
