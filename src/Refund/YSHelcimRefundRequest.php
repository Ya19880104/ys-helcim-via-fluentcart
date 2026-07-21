<?php
/**
 * Strict REST request boundary for remote-first Helcim refunds.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts the small client-authored refund intent into server-owned context.
 */
final class YSHelcimRefundRequest {

	/** @var callable */
	private $transaction_loader;

	/** @var callable */
	private $credential_resolver;

	/** @var callable */
	private $ip_resolver;

	/** @var callable */
	private $storage_preflight;

	public function __construct(
		callable $transaction_loader,
		callable $credential_resolver,
		callable $ip_resolver,
		?callable $storage_preflight = null
	) {
		$this->transaction_loader = $transaction_loader;
		$this->credential_resolver = $credential_resolver;
		$this->ip_resolver          = $ip_resolver;
		$this->storage_preflight    = $storage_preflight ?? static fn (): \WP_Error => self::contextUnavailable();
	}

	/**
	 * Build the exact request accepted by YSHelcimRefundService.
	 *
	 * The browser supplies only intent. Payment identity, totals, mode,
	 * credentials, order ownership, actor and network context are resolved on
	 * the server for every new operation.
	 *
	 * @param mixed $request       WP_REST_Request-compatible object.
	 * @param int   $actor_user_id Authenticated administrator user ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function build( mixed $request, int $actor_user_id ) {
		if (
			! is_object( $request ) ||
			! method_exists( $request, 'get_json_params' ) ||
			! method_exists( $request, 'get_url_params' )
		) {
			return self::invalidRequest();
		}

		$body        = $request->get_json_params();
		$route       = $request->get_url_params();
		$allowed     = array(
			'operation_uuid',
			'transaction_id',
			'amount',
			'reason',
			'item_ids',
			'manage_stock',
			'refunded_items',
			'cancel_subscription',
		);
		$required    = array( 'operation_uuid', 'transaction_id', 'amount' );
		$route_order = is_array( $route ) ? self::positiveInteger( $route['order_id'] ?? null ) : null;

		if (
			! is_array( $body ) ||
			array_diff( array_keys( $body ), $allowed ) ||
			array_diff( $required, array_keys( $body ) ) ||
			null === $route_order ||
			! is_int( $body['transaction_id'] ) ||
			$body['transaction_id'] <= 0 ||
			1 !== preg_match(
				'/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
				is_string( $body['operation_uuid'] ) ? $body['operation_uuid'] : ''
			)
		) {
			return self::invalidRequest();
		}

		$amount = self::decimalToCents( $body['amount'] );
		if ( null === $amount || $amount <= 0 ) {
			return self::invalidRequest();
		}

		try {
			$storage_ready = ( $this->storage_preflight )();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::contextUnavailable();
		}
		if ( is_wp_error( $storage_ready ) ) {
			return $storage_ready;
		}
		if ( true !== $storage_ready ) {
			return self::contextUnavailable();
		}

		try {
			$transaction = ( $this->transaction_loader )( $body['transaction_id'] );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::contextUnavailable();
		}
		if ( is_wp_error( $transaction ) ) {
			return $transaction;
		}
		if ( ! is_array( $transaction ) || ! self::validTransaction( $transaction, $route_order, $body['transaction_id'], $amount ) ) {
			return self::invalidRequest();
		}

		$provider_id = self::positiveIntegerString( $transaction['vendor_transaction_id'] );
		if ( null === $provider_id ) {
			return self::invalidRequest();
		}

		$local_payload = array(
			'reason'              => $body['reason'] ?? '',
			'item_ids'            => $body['item_ids'] ?? array(),
			'manage_stock'        => $body['manage_stock'] ?? false,
			'refunded_items'      => $body['refunded_items'] ?? array(),
			'cancel_subscription' => $body['cancel_subscription'] ?? false,
			'actor_user_id'       => $actor_user_id,
		);
		try {
			$local_payload = YSHelcimRefundPayload::normalize( $local_payload );
		} catch ( \InvalidArgumentException $exception ) {
			unset( $exception );
			return self::invalidRequest();
		}

		if ( ! self::itemsBelongToOrder( $local_payload, $transaction['order_item_quantities'] ) ) {
			return self::invalidRequest();
		}

		try {
			$credentials = ( $this->credential_resolver )( $transaction['gateway'], $transaction['payment_mode'] );
			$ip_address  = ( $this->ip_resolver )( $request );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::contextUnavailable();
		}
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}
		if (
			$actor_user_id <= 0 ||
			! is_array( $credentials ) ||
			! isset( $credentials['current_mode'], $credentials['api_token'] ) ||
			! is_string( $credentials['current_mode'] ) ||
			! in_array( $credentials['current_mode'], array( 'test', 'live' ), true ) ||
			! is_string( $credentials['api_token'] ) ||
			'' === trim( $credentials['api_token'] ) ||
			! is_string( $ip_address ) ||
			false === filter_var( $ip_address, FILTER_VALIDATE_IP )
		) {
			return self::contextUnavailable();
		}
		if ( $transaction['payment_mode'] !== $credentials['current_mode'] ) {
			return self::accountDrift();
		}

		return array(
			'operation_uuid'       => $body['operation_uuid'],
			'gateway'              => $transaction['gateway'],
			'order_id'             => $transaction['order_id'],
			'transaction_id'       => $transaction['transaction_id'],
			'transaction_uuid'     => $transaction['transaction_uuid'],
			'vendor_transaction_id' => $provider_id,
			'amount'               => $amount,
			'transaction_total'    => $transaction['transaction_total'],
			'refunded_total'       => $transaction['refunded_total'],
			'remaining_refundable' => $transaction['remaining_refundable'],
			'currency'             => $transaction['currency'],
			'payment_mode'         => $transaction['payment_mode'],
			'current_mode'         => $credentials['current_mode'],
			'api_token'            => trim( $credentials['api_token'] ),
			'ip_address'           => $ip_address,
			'local_payload'        => $local_payload,
		);
	}

	/** @param array<string,mixed> $transaction */
	private static function validTransaction( array $transaction, int $route_order, int $transaction_id, int $amount ): bool {
		$required = array(
			'order_id', 'transaction_id', 'transaction_uuid', 'vendor_transaction_id',
			'gateway', 'status', 'transaction_type', 'transaction_total', 'refunded_total',
			'remaining_refundable', 'currency', 'payment_mode', 'order_item_quantities',
		);
		if ( array_diff( $required, array_keys( $transaction ) ) ) {
			return false;
		}

		foreach ( array( 'order_id', 'transaction_id', 'transaction_total', 'refunded_total', 'remaining_refundable' ) as $field ) {
			if ( ! is_int( $transaction[ $field ] ) ) {
				return false;
			}
		}

		return $transaction['order_id'] === $route_order
			&& $transaction['transaction_id'] === $transaction_id
			&& is_string( $transaction['transaction_uuid'] )
			&& '' !== trim( $transaction['transaction_uuid'] )
			&& in_array( $transaction['gateway'], array( 'ys_helcim', 'ys_helcim_js' ), true )
			&& 'succeeded' === $transaction['status']
			&& 'charge' === $transaction['transaction_type']
			&& $transaction['transaction_total'] > 0
			&& $transaction['refunded_total'] >= 0
			&& $transaction['remaining_refundable'] >= 0
			&& $transaction['refunded_total'] <= $transaction['transaction_total']
			&& $transaction['remaining_refundable'] <= $transaction['transaction_total'] - $transaction['refunded_total']
			&& $amount <= $transaction['remaining_refundable']
			&& in_array( $transaction['currency'], array( 'USD', 'CAD' ), true )
			&& in_array( $transaction['payment_mode'], array( 'test', 'live' ), true )
			&& is_array( $transaction['order_item_quantities'] );
	}

	/** @param array<string,mixed> $payload @param array<mixed,mixed> $quantities */
	private static function itemsBelongToOrder( array $payload, array $quantities ): bool {
		$allowed = array();
		foreach ( $quantities as $item_id => $quantity ) {
			if ( ! is_int( $item_id ) || $item_id <= 0 || ! is_int( $quantity ) || $quantity <= 0 ) {
				return false;
			}
			$allowed[ $item_id ] = $quantity;
		}

		foreach ( $payload['item_ids'] as $item_id ) {
			if ( ! isset( $allowed[ $item_id ] ) ) {
				return false;
			}
		}
		foreach ( $payload['refunded_items'] as $row ) {
			if ( ! isset( $allowed[ $row['id'] ] ) || $row['restore_quantity'] > $allowed[ $row['id'] ] ) {
				return false;
			}
		}

		return true;
	}

	private static function decimalToCents( mixed $value ): ?int {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A(0|[1-9][0-9]*)\.([0-9]{2})\z/', $value, $parts ) ) {
			return null;
		}

		$fraction  = (int) $parts[2];
		$major     = $parts[1];
		$max_major = (string) intdiv( PHP_INT_MAX - $fraction, 100 );
		if ( strlen( $major ) > strlen( $max_major ) || ( strlen( $major ) === strlen( $max_major ) && strcmp( $major, $max_major ) > 0 ) ) {
			return null;
		}

		return (int) $major * 100 + $fraction;
	}

	private static function positiveInteger( mixed $value ): ?int {
		$normalized = self::positiveIntegerString( $value );
		return null === $normalized ? null : (int) $normalized;
	}

	private static function positiveIntegerString( mixed $value ): ?string {
		if ( is_int( $value ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $value ) ) {
			return null;
		}

		$max = (string) PHP_INT_MAX;
		return strlen( $value ) < strlen( $max ) || ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) <= 0 )
			? $value
			: null;
	}

	private static function invalidRequest(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_invalid_refund_request',
			__( 'The refund request is invalid or does not match the current order.', 'ys-helcim-via-fluentcart' ),
			array( 'status' => 422 )
		);
	}

	private static function contextUnavailable(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_refund_context_unavailable',
			__( 'The refund context could not be loaded safely. No provider request was sent.', 'ys-helcim-via-fluentcart' ),
			array( 'status' => 503 )
		);
	}

	private static function accountDrift(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_account_drift',
			__( 'The current Helcim account mode does not match the original payment.', 'ys-helcim-via-fluentcart' ),
			array( 'status' => 409 )
		);
	}
}
