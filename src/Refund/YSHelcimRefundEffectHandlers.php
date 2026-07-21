<?php
/**
 * Durable post-refund effect handlers.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes the non-financial half of a locally recorded refund.
 *
 * Each method verifies the claimed outbox row and its immutable payload before
 * loading a FluentCart model or invoking an application hook.
 */
final class YSHelcimRefundEffectHandlers {

	private const PLAN = array(
		'stock_restore'    => array( 'at_most_once', 10 ),
		'customer_recount' => array( 'idempotent', 20 ),
		'refund_hooks'     => array( 'at_most_once', 30 ),
	);

	private const SNAPSHOT_KEYS = array(
		'id',
		'order_id',
		'post_id',
		'object_id',
		'fulfillment_type',
		'payment_type',
		'post_title',
		'title',
		'quantity',
		'unit_price',
		'subtotal',
		'tax_amount',
		'shipping_charge',
		'discount_total',
		'line_total',
		'refund_total',
		'rate',
		'fulfilled_quantity',
	);

	/** @var callable */
	private $order_loader;

	/** @var callable */
	private $transaction_loader;

	/** @var callable */
	private $customer_loader;

	/** @var callable */
	private $stock_restorer;

	/** @var callable */
	private $customer_recounter;

	/** @var callable */
	private $action_dispatcher;

	/** @var callable */
	private $activity_logger;

	/** @var callable */
	private $actor_loader;

	public function __construct(
		?callable $order_loader = null,
		?callable $transaction_loader = null,
		?callable $customer_loader = null,
		?callable $stock_restorer = null,
		?callable $customer_recounter = null,
		?callable $action_dispatcher = null,
		?callable $activity_logger = null,
		?callable $actor_loader = null
	) {
		$this->order_loader       = $order_loader ?? array( self::class, 'loadOrder' );
		$this->transaction_loader = $transaction_loader ?? array( self::class, 'loadTransaction' );
		$this->customer_loader    = $customer_loader ?? array( self::class, 'loadCustomer' );
		$this->stock_restorer     = $stock_restorer ?? array( self::class, 'restoreStock' );
		$this->customer_recounter = $customer_recounter ?? array( self::class, 'recountCustomer' );
		$this->action_dispatcher  = $action_dispatcher ?? array( self::class, 'dispatchAction' );
		$this->activity_logger    = $activity_logger ?? array( self::class, 'logActivity' );
		$this->actor_loader       = $actor_loader ?? array( self::class, 'loadActor' );
	}

	/** @return array<string,callable> */
	public function handlers(): array {
		return array(
			'stock_restore'    => array( $this, 'stockRestore' ),
			'customer_recount' => array( $this, 'customerRecount' ),
			'refund_hooks'     => array( $this, 'refundHooks' ),
		);
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $effect @return array<string,string>|\WP_Error */
	public function stockRestore( array $payload, array $effect ) {
		$payload = $this->verifiedPayload( 'stock_restore', $payload, $effect );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		if ( ! $this->validStockPayload( $payload ) ) {
			return self::invalidPayload();
		}
		if ( false === $payload['manage_stock'] ) {
			return self::receipt( 'stock_restore', 'skipped' );
		}

		$order = $this->dependencyCall( $this->order_loader, array( $payload['order_id'] ) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		if ( ! self::recordMatches( $order, array( 'id' => $payload['order_id'] ) ) ) {
			return self::recordMismatch();
		}

		$new_refunded_items = array();
		foreach ( $payload['items'] as $item ) {
			$new_refunded_items[] = array(
				'id'               => $item['item_id'],
				'variation_id'     => $item['object_id'],
				'restore_quantity' => $item['restore_quantity'],
			);
		}
		$result = $this->dependencyCall(
			$this->stock_restorer,
			array(
				array(
					'order'               => $order,
					'manage_stock'        => true,
					'refunded_items'      => array(),
					'new_refunded_items'  => $new_refunded_items,
				)
			)
		);

		return is_wp_error( $result ) ? $result : self::receipt( 'stock_restore' );
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $effect @return array<string,string>|\WP_Error */
	public function customerRecount( array $payload, array $effect ) {
		$payload = $this->verifiedPayload( 'customer_recount', $payload, $effect );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		if ( ! self::sameKeys( $payload, array( 'version', 'operation_uuid', 'order_id', 'local_transaction_id', 'customer_id' ) ) || ! self::validCommon( $payload ) || ! is_int( $payload['customer_id'] ) || $payload['customer_id'] < 0 ) {
			return self::invalidPayload();
		}
		if ( 0 === $payload['customer_id'] ) {
			return self::receipt( 'customer_recount', 'skipped' );
		}

		$customer = $this->dependencyCall( $this->customer_loader, array( $payload['customer_id'] ) );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}
		if ( ! self::recordMatches( $customer, array( 'id' => $payload['customer_id'] ) ) ) {
			return self::recordMismatch();
		}

		$result = $this->dependencyCall( $this->customer_recounter, array( $customer ) );
		return is_wp_error( $result ) ? $result : self::receipt( 'customer_recount' );
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $effect @return array<string,string>|\WP_Error */
	public function refundHooks( array $payload, array $effect ) {
		$payload = $this->verifiedPayload( 'refund_hooks', $payload, $effect );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		if ( ! $this->validHooksPayload( $payload ) ) {
			return self::invalidPayload();
		}

		$order = $this->dependencyCall( $this->order_loader, array( $payload['order_id'] ) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		if (
			! self::recordMatches(
				$order,
				array(
					'id'             => $payload['order_id'],
					'uuid'           => $payload['order_uuid'],
					'payment_status' => 'full' === $payload['refund_type'] ? 'refunded' : 'partially_refunded',
				)
			)
		) {
			return self::recordMismatch();
		}

		$transaction = $this->dependencyCall( $this->transaction_loader, array( $payload['local_transaction_id'] ) );
		if ( is_wp_error( $transaction ) ) {
			return $transaction;
		}
		if (
			! self::recordMatches(
				$transaction,
				array(
					'id'               => $payload['local_transaction_id'],
					'order_id'         => $payload['order_id'],
					'transaction_type' => 'refund',
					'status'           => 'refunded',
					'total'            => $payload['refund_amount'],
					'currency'         => $payload['currency'],
					'uuid'             => $payload['operation_uuid'],
					'vendor_charge_id' => $payload['provider_transaction_id'],
				)
			)
		) {
			return self::recordMismatch();
		}

		$customer = null;
		if ( $payload['customer_id'] > 0 ) {
			$customer = $this->dependencyCall( $this->customer_loader, array( $payload['customer_id'] ) );
			if ( is_wp_error( $customer ) ) {
				return $customer;
			}
			if ( ! self::recordMatches( $customer, array( 'id' => $payload['customer_id'] ) ) ) {
				return self::recordMismatch();
			}
		}

		$data = array(
			'order'                => $order,
			'refunded_items'       => $payload['refunded_item_snapshots'],
			'new_refunded_items'   => $payload['refunded_items'],
			'refunded_amount'      => $payload['refund_amount'],
			'manage_stock'         => false,
			'transaction'          => $transaction,
			'customer'             => $customer,
			'type'                 => $payload['refund_type'],
		);
		$actor = $this->actorContext( $payload['actor_user_id'] );
		if ( is_wp_error( $actor ) ) {
			return $actor;
		}
		$result = $this->dependencyCall(
			$this->activity_logger,
			array(
				__( 'Order Refund', 'fluent-cart' ),
				__( 'Order Refund successfully!', 'fluent-cart' ),
				'success',
				array(
					'module_type' => get_class( $order ),
					'module_id'   => $payload['order_id'],
					'module_name' => 'Order',
					'user_id'     => $actor['user_id'],
					'created_by'  => $actor['created_by'],
				)
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result = $this->dependencyCall( $this->action_dispatcher, array( 'fluent_cart/order_refunded', $data ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$typed_hook = 'full' === $payload['refund_type']
			? 'fluent_cart/order_fully_refunded'
			: 'fluent_cart/order_partially_refunded';
		$result = $this->dependencyCall( $this->action_dispatcher, array( $typed_hook, $data ) );

		return is_wp_error( $result ) ? $result : self::receipt( 'refund_hooks' );
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $effect @return array<string,mixed>|\WP_Error */
	private function verifiedPayload( string $type, array $payload, array $effect ) {
		list( $expected_class, $expected_sequence ) = self::PLAN[ $type ];
		$raw             = $effect['payload'] ?? null;
		$hash            = $effect['payload_hash'] ?? null;
		$actual_sequence = self::hydratedPositiveInt( $effect['sequence'] ?? null );
		if (
			! is_string( $raw ) ||
			! is_string( $hash ) ||
			1 !== preg_match( '/\A[a-f0-9]{64}\z/', $hash ) ||
			! hash_equals( $hash, hash( 'sha256', $raw ) ) ||
			$type !== ( $effect['effect_type'] ?? null ) ||
			$expected_class !== ( $effect['effect_class'] ?? null ) ||
			$expected_sequence !== $actual_sequence ||
			'processing' !== ( $effect['status'] ?? null ) ||
			! self::isUuid( $effect['operation_uuid'] ?? null ) ||
			! self::isUuid( $effect['claim_token'] ?? null )
		) {
			return self::invalidPayload();
		}

		try {
			$decoded = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			unset( $exception );
			return self::invalidPayload();
		}

		if ( ! is_array( $decoded ) || array_is_list( $decoded ) || $decoded !== $payload || ( $decoded['operation_uuid'] ?? null ) !== $effect['operation_uuid'] ) {
			return self::invalidPayload();
		}

		return $decoded;
	}

	/** @param array<string,mixed> $payload */
	private function validStockPayload( array $payload ): bool {
		if ( ! self::sameKeys( $payload, array( 'version', 'operation_uuid', 'order_id', 'local_transaction_id', 'manage_stock', 'items' ) ) || ! self::validCommon( $payload ) || ! is_bool( $payload['manage_stock'] ) || ! is_array( $payload['items'] ) || count( $payload['items'] ) > 100 || ( false === $payload['manage_stock'] && array() !== $payload['items'] ) || ( true === $payload['manage_stock'] && array() === $payload['items'] ) ) {
			return false;
		}

		$seen = array();
		foreach ( $payload['items'] as $item ) {
			if (
				! is_array( $item ) ||
				! self::sameKeys( $item, array( 'item_id', 'object_id', 'post_id', 'quantity', 'restore_quantity' ) ) ||
				! self::positiveInt( $item['item_id'] ) ||
				! self::positiveInt( $item['object_id'] ) ||
				! self::positiveInt( $item['post_id'] ) ||
				! self::positiveInt( $item['quantity'] ) ||
				! self::positiveInt( $item['restore_quantity'] ) ||
				$item['restore_quantity'] > $item['quantity'] ||
				isset( $seen[ $item['item_id'] ] )
			) {
				return false;
			}
			$seen[ $item['item_id'] ] = true;
		}

		$ids = array_keys( $seen );
		$sorted = $ids;
		sort( $sorted, SORT_NUMERIC );
		return $ids === $sorted;
	}

	/** @param array<string,mixed> $payload */
	private function validHooksPayload( array $payload ): bool {
		$keys = array(
			'version', 'operation_uuid', 'order_id', 'local_transaction_id', 'root_refund_uuid',
			'order_uuid', 'customer_id', 'source_transaction_id', 'provider_transaction_id',
			'provider_action', 'refund_amount', 'currency', 'refund_type', 'reason', 'item_ids',
			'manage_stock', 'stock_restore_requested', 'actor_user_id', 'refunded_items', 'refunded_item_snapshots',
		);
		if (
			! self::sameKeys( $payload, $keys ) ||
			! self::validCommon( $payload ) ||
			! self::isUuid( $payload['root_refund_uuid'] ) ||
			! self::safeText( $payload['order_uuid'], 100, false ) ||
			! is_int( $payload['customer_id'] ) || $payload['customer_id'] < 0 ||
			! self::positiveInt( $payload['source_transaction_id'] ) ||
			! self::providerId( $payload['provider_transaction_id'] ) ||
			! in_array( $payload['provider_action'], array( 'refund', 'reverse' ), true ) ||
			! self::positiveInt( $payload['refund_amount'] ) ||
			! in_array( $payload['currency'], array( 'USD', 'CAD' ), true ) ||
			! in_array( $payload['refund_type'], array( 'full', 'partial' ), true ) ||
			! self::safeText( $payload['reason'], 500, true ) ||
			false !== $payload['manage_stock'] ||
			! is_bool( $payload['stock_restore_requested'] ) ||
			! is_int( $payload['actor_user_id'] ) || $payload['actor_user_id'] < 0 ||
			! is_array( $payload['item_ids'] ) ||
			! is_array( $payload['refunded_items'] ) ||
			! is_array( $payload['refunded_item_snapshots'] ) ||
			count( $payload['item_ids'] ) > 100 ||
			count( $payload['refunded_items'] ) > 100 ||
			count( $payload['refunded_item_snapshots'] ) > 100
		) {
			return false;
		}

		$item_ids = array();
		foreach ( $payload['item_ids'] as $item_id ) {
			if ( ! self::positiveInt( $item_id ) || isset( $item_ids[ $item_id ] ) ) {
				return false;
			}
			$item_ids[ $item_id ] = true;
		}
		if ( array_keys( $item_ids ) !== self::sortedIds( array_keys( $item_ids ) ) ) {
			return false;
		}

		$snapshots = array();
		foreach ( $payload['refunded_item_snapshots'] as $snapshot ) {
			if ( ! $this->validItemSnapshot( $snapshot, $payload['order_id'] ) || isset( $snapshots[ $snapshot['id'] ] ) ) {
				return false;
			}
			$snapshots[ $snapshot['id'] ] = $snapshot;
		}
		if ( array_keys( $snapshots ) !== array_keys( $item_ids ) ) {
			return false;
		}

		$restores = array();
		foreach ( $payload['refunded_items'] as $row ) {
			if (
				! is_array( $row ) ||
				! self::sameKeys( $row, array( 'id', 'restore_quantity' ) ) ||
				! self::positiveInt( $row['id'] ) ||
				! self::positiveInt( $row['restore_quantity'] ) ||
				! isset( $snapshots[ $row['id'] ] ) ||
				$row['restore_quantity'] > $snapshots[ $row['id'] ]['quantity'] ||
				isset( $restores[ $row['id'] ] )
			) {
				return false;
			}
			$restores[ $row['id'] ] = true;
		}
		if ( array_keys( $restores ) !== self::sortedIds( array_keys( $restores ) ) ) {
			return false;
		}

		return $payload['stock_restore_requested'] ? array() !== $restores : array() === $restores;
	}

	private function validItemSnapshot( mixed $snapshot, int $order_id ): bool {
		if ( ! is_array( $snapshot ) || ! self::sameKeys( $snapshot, self::SNAPSHOT_KEYS ) ) {
			return false;
		}
		if (
			! self::positiveInt( $snapshot['id'] ) ||
			! is_int( $snapshot['order_id'] ) || $snapshot['order_id'] !== $order_id ||
			! self::nonNegativeInt( $snapshot['post_id'] ) ||
			! ( null === $snapshot['object_id'] || self::nonNegativeInt( $snapshot['object_id'] ) ) ||
			! self::safeText( $snapshot['fulfillment_type'], 20, true ) ||
			! self::safeText( $snapshot['payment_type'], 20, true ) ||
			! self::safeText( $snapshot['post_title'], 65535, true ) ||
			! self::safeText( $snapshot['title'], 65535, true ) ||
			! self::positiveInt( $snapshot['quantity'] ) ||
			! self::nonNegativeInt( $snapshot['unit_price'] ) ||
			! self::nonNegativeInt( $snapshot['subtotal'] ) ||
			! self::nonNegativeInt( $snapshot['tax_amount'] ) ||
			! self::nonNegativeInt( $snapshot['shipping_charge'] ) ||
			! self::nonNegativeInt( $snapshot['discount_total'] ) ||
			! self::nonNegativeInt( $snapshot['line_total'] ) ||
			! self::nonNegativeInt( $snapshot['refund_total'] ) ||
			! self::nonNegativeInt( $snapshot['fulfilled_quantity'] ) ||
			! is_string( $snapshot['rate'] ) ||
			1 !== preg_match( '/\A\d+(?:\.\d{1,8})?\z/', $snapshot['rate'] )
		) {
			return false;
		}
		return true;
	}

	/** @param array<string,mixed> $payload */
	private static function validCommon( array $payload ): bool {
		return 1 === ( $payload['version'] ?? null )
			&& self::isUuid( $payload['operation_uuid'] ?? null )
			&& self::positiveInt( $payload['order_id'] ?? null )
			&& self::positiveInt( $payload['local_transaction_id'] ?? null );
	}

	/** @param callable $callable @param array<int,mixed> $arguments @return mixed|\WP_Error */
	private function dependencyCall( callable $callable, array $arguments ) {
		try {
			$result = $callable( ...$arguments );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::dependencyFailed();
		}
		if ( is_wp_error( $result ) ) {
			if ( in_array( $result->get_error_code(), array( 'ys_helcim_effect_dependency_missing', 'ys_helcim_effect_dependency_failed' ), true ) ) {
				return $result;
			}
			$data = $result->get_error_data();
			return self::dependencyFailed( is_array( $data ) && false === ( $data['retryable'] ?? true ) );
		}
		return $result;
	}

	/** @param array<string,mixed> $expected */
	private static function recordMatches( mixed $record, array $expected ): bool {
		if ( ! is_array( $record ) && ! is_object( $record ) ) {
			return false;
		}
		foreach ( $expected as $key => $value ) {
			$actual = is_array( $record ) ? ( $record[ $key ] ?? null ) : ( $record->{$key} ?? null );
			if ( is_int( $value ) ) {
				if ( ! is_numeric( $actual ) || (int) $actual !== $value ) {
					return false;
				}
			} elseif ( (string) $actual !== $value ) {
				return false;
			}
		}
		return true;
	}

	/** @param array<mixed> $value @param string[] $keys */
	private static function sameKeys( array $value, array $keys ): bool {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $keys, SORT_STRING );
		return $actual === $keys;
	}

	/** @param int[] $ids @return int[] */
	private static function sortedIds( array $ids ): array {
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	private static function positiveInt( mixed $value ): bool {
		return is_int( $value ) && $value > 0;
	}

	private static function hydratedPositiveInt( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $value ) ) {
			return null;
		}
		$maximum = (string) PHP_INT_MAX;
		if ( strlen( $value ) > strlen( $maximum ) || ( strlen( $value ) === strlen( $maximum ) && strcmp( $value, $maximum ) > 0 ) ) {
			return null;
		}

		return (int) $value;
	}

	private static function nonNegativeInt( mixed $value ): bool {
		return is_int( $value ) && $value >= 0;
	}

	private static function isUuid( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value );
	}

	private static function providerId( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[1-9][0-9]{0,63}\z/', $value );
	}

	private static function safeText( mixed $value, int $maximum_length, bool $allow_empty ): bool {
		return is_string( $value )
			&& ( $allow_empty || '' !== $value )
			&& strlen( $value ) <= $maximum_length
			&& 1 !== preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value );
	}

	/** @return array{user_id:int,created_by:string}|\WP_Error */
	private function actorContext( int $actor_user_id ) {
		if ( 0 === $actor_user_id ) {
			return array( 'user_id' => 0, 'created_by' => 'FCT-BOT' );
		}

		$actor = $this->dependencyCall( $this->actor_loader, array( $actor_user_id ) );
		if ( is_wp_error( $actor ) ) {
			return $actor;
		}
		if ( null === $actor || false === $actor ) {
			return array( 'user_id' => $actor_user_id, 'created_by' => 'FCT-BOT' );
		}
		if ( ! self::recordMatches( $actor, array( 'ID' => $actor_user_id ) ) ) {
			return self::recordMismatch();
		}

		$name = is_array( $actor ) ? ( $actor['display_name'] ?? '' ) : ( $actor->display_name ?? '' );
		$name = is_string( $name ) && self::safeText( $name, 250, true ) ? trim( $name ) : '';
		return array(
			'user_id'    => $actor_user_id,
			'created_by' => '' !== $name ? $name : 'FCT-BOT',
		);
	}

	/** @return array{status:string,effect:string} */
	private static function receipt( string $effect, string $status = 'completed' ): array {
		return array( 'status' => $status, 'effect' => $effect );
	}

	private static function invalidPayload(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_effect_payload_invalid',
			__( 'The saved refund effect payload is invalid.', 'ys-helcim-via-fluentcart' ),
			array( 'retryable' => false )
		);
	}

	private static function recordMismatch(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_effect_record_mismatch',
			__( 'The saved refund effect no longer matches its local records.', 'ys-helcim-via-fluentcart' ),
			array( 'retryable' => false )
		);
	}

	private static function dependencyMissing(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_effect_dependency_missing',
			__( 'A required FluentCart refund dependency is unavailable.', 'ys-helcim-via-fluentcart' ),
			array( 'retryable' => false )
		);
	}

	private static function dependencyFailed( bool $non_retryable = false ): \WP_Error {
		return new \WP_Error(
			'ys_helcim_effect_dependency_failed',
			__( 'A FluentCart refund effect could not be completed.', 'ys-helcim-via-fluentcart' ),
			array( 'retryable' => ! $non_retryable )
		);
	}

	/** @return mixed|\WP_Error */
	private static function loadOrder( int $order_id ) {
		if ( ! class_exists( '\\FluentCart\\App\\Models\\Order' ) ) {
			return self::dependencyMissing();
		}
		return \FluentCart\App\Models\Order::query()
			->with( array( 'customer', 'shipping_address', 'billing_address' ) )
			->find( $order_id );
	}

	/** @return mixed|\WP_Error */
	private static function loadTransaction( int $transaction_id ) {
		if ( ! class_exists( '\\FluentCart\\App\\Models\\OrderTransaction' ) ) {
			return self::dependencyMissing();
		}
		return \FluentCart\App\Models\OrderTransaction::query()->find( $transaction_id );
	}

	/** @return mixed|\WP_Error */
	private static function loadCustomer( int $customer_id ) {
		if ( ! class_exists( '\\FluentCart\\App\\Models\\Customer' ) ) {
			return self::dependencyMissing();
		}
		return \FluentCart\App\Models\Customer::query()->find( $customer_id );
	}

	/** @return mixed|\WP_Error */
	private static function loadActor( int $actor_user_id ) {
		if ( ! function_exists( 'get_userdata' ) ) {
			return self::dependencyMissing();
		}
		return get_userdata( $actor_user_id );
	}

	/** @param array<string,mixed> $data @return mixed|\WP_Error */
	private static function restoreStock( array $data ) {
		if ( ! class_exists( '\\FluentCart\\App\\Modules\\StockManagement\\StockManagement' ) ) {
			return self::dependencyMissing();
		}
		return ( new \FluentCart\App\Modules\StockManagement\StockManagement() )->manageStockOnOrderRefunded( $data );
	}

	/** @return mixed|\WP_Error */
	private static function recountCustomer( mixed $customer ) {
		if ( ! is_object( $customer ) || ! is_callable( array( $customer, 'recountStat' ) ) ) {
			return self::dependencyMissing();
		}
		return $customer->recountStat();
	}

	/** @param array<string,mixed> $data @return mixed|\WP_Error */
	private static function dispatchAction( string $hook, array $data ) {
		if ( ! function_exists( 'do_action' ) ) {
			return self::dependencyMissing();
		}
		do_action( $hook, $data );
		return true;
	}

	/** @param array<string,mixed> $context @return mixed|\WP_Error */
	private static function logActivity( string $title, string $content, string $status, array $context ) {
		if ( ! function_exists( 'fluent_cart_add_log' ) ) {
			return self::dependencyMissing();
		}
		return fluent_cart_add_log( $title, $content, $status, $context );
	}
}
