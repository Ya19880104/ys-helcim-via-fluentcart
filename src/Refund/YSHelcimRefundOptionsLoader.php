<?php
/**
 * Server-owned refund option classification for the Helcim admin UI.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

use YangSheep\Helcim\FluentCart\Support\YSHelcimTransactionId;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the safe, read-only options envelope consumed by the refund UI.
 */
final class YSHelcimRefundOptionsLoader {

	/** @var callable */
	private $order_query;

	/** @var callable */
	private $context_loader;

	/**
	 * @param callable $order_query   Loads one server-owned order snapshot.
	 * @param callable $context_loader Revalidates one Helcim transaction.
	 */
	public function __construct( callable $order_query, callable $context_loader ) {
		$this->order_query    = $order_query;
		$this->context_loader = $context_loader;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function __invoke( int $order_id ) {
		return $this->load( $order_id );
	}

	/**
	 * Load and classify safe refund display data for one order.
	 *
	 * Expected query snapshot keys are order, transactions, items and
	 * operations. Only the explicit public projection below is returned.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function load( int $order_id ) {
		if ( $order_id <= 0 ) {
			return self::invalidOrder();
		}

		try {
			$snapshot = ( $this->order_query )( $order_id );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::unavailable();
		}

		if ( null === $snapshot ) {
			return self::orderNotFound();
		}
		if ( is_wp_error( $snapshot ) || ! is_array( $snapshot ) ) {
			return self::unavailable();
		}

		$order = self::validatedOrder( $snapshot['order'] ?? null, $order_id );
		if (
			null === $order ||
			! is_array( $snapshot['transactions'] ?? null ) ||
			! is_array( $snapshot['items'] ?? null ) ||
			! is_array( $snapshot['operations'] ?? null )
		) {
			return self::unavailable();
		}

		$eligible  = array();
		$has_native = false;
		$seen_ids = array();
		foreach ( $snapshot['transactions'] as $transaction ) {
			if ( ! is_array( $transaction ) ) {
				continue;
			}
			$transaction_id = self::positiveInteger( $transaction['id'] ?? null );
			$transaction_order_id = self::positiveInteger( $transaction['order_id'] ?? null );
			$gateway = is_string( $transaction['payment_method'] ?? null )
				? $transaction['payment_method']
				: '';
			$status = is_string( $transaction['status'] ?? null ) ? $transaction['status'] : '';
			$type   = is_string( $transaction['transaction_type'] ?? null )
				? $transaction['transaction_type']
				: '';

			if (
				null === $transaction_id ||
				$order_id !== $transaction_order_id ||
				isset( $seen_ids[ $transaction_id ] )
			) {
				continue;
			}
			$seen_ids[ $transaction_id ] = true;

			if ( ! self::isHelcimGateway( $gateway ) ) {
				$remaining = self::nonnegativeInteger( $transaction['remaining_refundable'] ?? null );
				if ( 'succeeded' === $status && 'charge' === $type && ( null === $remaining || $remaining > 0 ) ) {
					$has_native = true;
				}
				continue;
			}

			if ( 'succeeded' !== $status || 'charge' !== $type ) {
				continue;
			}

			try {
				$context = ( $this->context_loader )( $transaction_id );
			} catch ( \Throwable $exception ) {
				unset( $exception );
				return self::unavailable();
			}
			if ( is_wp_error( $context ) || ! is_array( $context ) ) {
				return self::unavailable();
			}

			$option = self::eligibleTransaction(
				$context,
				$order_id,
				$transaction_id,
				$gateway,
				$order['currency'],
				$order['remaining']
			);
			if ( null !== $option ) {
				$eligible[] = $option;
			}
		}

		usort(
			$eligible,
			static fn ( array $left, array $right ): int => $left['id'] <=> $right['id']
		);
		$items = self::displayItems( $snapshot['items'] );
		$resolution_operation = self::resolutionOperation( $snapshot['operations'], $order_id );

		$classification = 'none';
		if ( array() !== $eligible ) {
			if ( self::hasOperationBlocker( $snapshot['operations'], $order_id ) ) {
				$classification = 'blocked';
			} elseif ( $has_native ) {
				$classification = 'mixed';
			} else {
				$classification = 'helcim_only';
			}
		}

		return array(
			'order_id'        => $order_id,
			'classification' => $classification,
			'currency'        => $order['currency'],
			'order_remaining' => $order['remaining'],
			'transactions'    => $eligible,
			'items'           => $items,
			'resolution_operation' => $resolution_operation,
		);
	}

	/** @return array{currency:string,remaining:int}|null */
	private static function validatedOrder( mixed $order, int $expected_order_id ): ?array {
		if ( ! is_array( $order ) ) {
			return null;
		}

		$order_id     = self::positiveInteger( $order['id'] ?? null );
		$currency     = is_string( $order['currency'] ?? null ) ? $order['currency'] : '';
		$total_paid   = self::nonnegativeInteger( $order['total_paid'] ?? null );
		$total_refund = self::nonnegativeInteger( $order['total_refund'] ?? null );
		if (
			$expected_order_id !== $order_id ||
			1 !== preg_match( '/\A[A-Z]{3}\z/', $currency ) ||
			null === $total_paid ||
			null === $total_refund ||
			$total_refund > $total_paid
		) {
			return null;
		}

		return array(
			'currency'  => $currency,
			'remaining' => $total_paid - $total_refund,
		);
	}

	/** @return array{id:int,gateway:string,payment_mode:string,remaining_refundable:int}|null */
	private static function eligibleTransaction(
		array $context,
		int $order_id,
		int $transaction_id,
		string $raw_gateway,
		string $order_currency,
		int $order_remaining
	): ?array {
		$context_order_id       = self::positiveInteger( $context['order_id'] ?? null );
		$context_transaction_id = self::positiveInteger( $context['transaction_id'] ?? null );
		$gateway = is_string( $context['gateway'] ?? null ) ? $context['gateway'] : '';
		$currency = is_string( $context['currency'] ?? null ) ? $context['currency'] : '';
		$payment_mode = is_string( $context['payment_mode'] ?? null ) ? $context['payment_mode'] : '';
		$remaining = self::positiveInteger( $context['remaining_refundable'] ?? null );
		$provider_id = YSHelcimTransactionId::normalize( $context['vendor_transaction_id'] ?? null );

		if (
			$order_id !== $context_order_id ||
			$transaction_id !== $context_transaction_id ||
			$raw_gateway !== $gateway ||
			! self::isHelcimGateway( $gateway ) ||
			'succeeded' !== ( $context['status'] ?? null ) ||
			'charge' !== ( $context['transaction_type'] ?? null ) ||
			null === $provider_id ||
			! in_array( $currency, array( 'USD', 'CAD' ), true ) ||
			$order_currency !== $currency ||
			! in_array( $payment_mode, array( 'test', 'live' ), true ) ||
			null === $remaining ||
			$order_remaining <= 0
		) {
			return null;
		}

		return array(
			'id'                   => $transaction_id,
			'gateway'              => $gateway,
			'payment_mode'         => $payment_mode,
			'remaining_refundable' => min( $remaining, $order_remaining ),
		);
	}

	/** @param array<int,mixed> $items @return array<int,array{id:int,title:string,quantity:int,refundable_quantity:int}> */
	private static function displayItems( array $items ): array {
		$display = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id                  = self::positiveInteger( $item['id'] ?? null );
			$title               = is_string( $item['title'] ?? null ) ? $item['title'] : '';
			$quantity            = self::positiveInteger( $item['quantity'] ?? null );
			$refundable_quantity = self::positiveInteger( $item['refundable_quantity'] ?? null );
			if (
				null === $id ||
				null === $quantity ||
				null === $refundable_quantity ||
				$refundable_quantity > $quantity ||
				isset( $display[ $id ] )
			) {
				continue;
			}
			$display[ $id ] = array(
				'id'                  => $id,
				'title'               => $title,
				'quantity'            => $quantity,
				'refundable_quantity' => $refundable_quantity,
			);
		}
		ksort( $display, SORT_NUMERIC );

		return array_values( $display );
	}

	/** @param array<int,mixed> $operations */
	private static function hasOperationBlocker( array $operations, int $order_id ): bool {
		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				return true;
			}
			$operation_order_id = self::positiveInteger( $operation['order_id'] ?? null );
			if ( null === $operation_order_id ) {
				return true;
			}
			if ( $order_id !== $operation_order_id ) {
				continue;
			}
			$operation_type = $operation['operation_type'] ?? null;
			if ( 'purchase' === $operation_type ) {
				continue;
			}
			if ( ! in_array( $operation_type, array( 'refund', 'reverse' ), true ) ) {
				return true;
			}

			if ( ! array_key_exists( 'active_scope_key', $operation ) ) {
				return true;
			}
			$raw_active_scope = $operation['active_scope_key'];
			if ( null !== $raw_active_scope && ! is_string( $raw_active_scope ) ) {
				return true;
			}
			$active_scope = is_string( $raw_active_scope ) ? trim( $raw_active_scope ) : '';
			$remote_status = is_string( $operation['remote_status'] ?? null )
				? $operation['remote_status']
				: '';
			$local_status = is_string( $operation['local_status'] ?? null )
				? $operation['local_status']
				: '';
			$effect_status = is_string( $operation['effect_status'] ?? null )
				? $operation['effect_status']
				: '';
			$manual = self::booleanMarker( $operation['manual_reconciliation_required'] ?? false );
			if (
				null === $manual ||
				'' !== $active_scope ||
				$manual ||
				in_array( $remote_status, array( 'created', 'processing', 'indeterminate' ), true ) ||
				'stock_reconciliation_required' === $effect_status ||
				( 'succeeded' === $remote_status && 'applied' !== $local_status )
			) {
				return true;
			}

			if ( 'succeeded' === $remote_status ) {
				if ( ! in_array( $effect_status, array( '', 'applied', 'completed' ), true ) ) {
					return true;
				}
				continue;
			}

			if (
				! in_array( $remote_status, array( 'declined', 'failed', 'canceled', 'expired' ), true ) ||
				! in_array( $local_status, array( 'pending', 'failed' ), true ) ||
				'' !== $effect_status
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Project the sole active operation that is safe for positive-only manual
	 * resolution. Any ambiguity or unrelated same-order blocker hides the
	 * resolution controls while the order remains blocked.
	 *
	 * @param array<int,mixed> $operations
	 * @return array{operation_uuid:string,provider_action:string}|null
	 */
	private static function resolutionOperation( array $operations, int $order_id ): ?array {
		$candidates = array();
		$remaining  = array();

		foreach ( $operations as $operation ) {
			if ( self::isResolutionCandidate( $operation, $order_id ) ) {
				$candidates[] = $operation;
				continue;
			}
			$remaining[] = $operation;
		}

		if ( 1 !== count( $candidates ) || self::hasOperationBlocker( $remaining, $order_id ) ) {
			return null;
		}

		$candidate = $candidates[0];
		return array(
			'operation_uuid' => (string) $candidate['operation_uuid'],
			'provider_action' => (string) $candidate['operation_type'],
		);
	}

	private static function isResolutionCandidate( mixed $operation, int $order_id ): bool {
		if ( ! is_array( $operation ) ) {
			return false;
		}

		$operation_uuid = $operation['operation_uuid'] ?? null;
		$active_scope   = $operation['active_scope_key'] ?? null;
		$operation_type = $operation['operation_type'] ?? null;
		$manual         = self::booleanMarker( $operation['manual_reconciliation_required'] ?? false );
		$effect_status  = $operation['effect_status'] ?? '';

		return $order_id === self::positiveInteger( $operation['order_id'] ?? null )
			&& is_string( $operation_uuid )
			&& 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $operation_uuid )
			&& in_array( $operation_type, array( 'refund', 'reverse' ), true )
			&& is_string( $active_scope )
			&& 1 === preg_match( '/\Ayshs-[a-f0-9]{64}\z/', $active_scope )
			&& 'indeterminate' === ( $operation['remote_status'] ?? null )
			&& in_array( $operation['local_status'] ?? null, array( 'pending', 'failed' ), true )
			&& false === $manual
			&& is_string( $effect_status )
			&& '' === $effect_status;
	}

	private static function booleanMarker( mixed $value ): ?bool {
		if ( true === $value || 1 === $value || '1' === $value ) {
			return true;
		}
		if ( false === $value || 0 === $value || '0' === $value || null === $value ) {
			return false;
		}
		return null;
	}

	private static function isHelcimGateway( string $gateway ): bool {
		return in_array( $gateway, array( 'ys_helcim', 'ys_helcim_js' ), true );
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
		return strlen( $value ) < strlen( $maximum ) ||
			( strlen( $value ) === strlen( $maximum ) && strcmp( $value, $maximum ) <= 0 )
			? (int) $value
			: null;
	}

	private static function invalidOrder(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_invalid_order',
			__( 'The requested order is invalid.', 'ys-helcim-via-fluentcart' ),
			array( 'status' => 422 )
		);
	}

	private static function orderNotFound(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_order_not_found',
			__( 'The requested order was not found.', 'ys-helcim-via-fluentcart' ),
			array( 'status' => 404 )
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
