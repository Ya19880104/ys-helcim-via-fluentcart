<?php
/**
 * Deterministic Helcim idempotency-key generation and validation.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Operations;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates provider-safe keys from a persisted operation type and scope.
 */
final class YSHelcimIdempotency {
	/** @var string[] */
	private const OPERATION_TYPES = array( 'purchase', 'refund', 'reverse' );

	/** @var string[] */
	private const PAYMENT_MODES = array( 'test', 'live' );

	/**
	 * Generate a deterministic 36-character key accepted by Helcim.
	 *
	 * The caller must persist every input with the operation row. The business
	 * scope is deliberately not an input: sequential operations may share a
	 * parent-payment lock while retaining distinct operation UUIDs and keys.
	 *
	 * @param string $operation_type   Operation type: purchase, refund, or reverse.
	 * @param string $transaction_uuid Stable FluentCart transaction identifier.
	 * @param int    $amount_cents     Positive amount in integer cents.
	 * @param string $payment_mode     FluentCart payment mode: test or live.
	 * @param string $operation_uuid   Persistent operation UUID.
	 * @return string
	 * @throws \InvalidArgumentException When an input cannot safely identify an operation.
	 */
	public static function generate(
		string $operation_type,
		string $transaction_uuid,
		int $amount_cents,
		string $payment_mode,
		string $operation_uuid
	): string {
		$operation_type   = strtolower( trim( $operation_type ) );
		$transaction_uuid = trim( $transaction_uuid );
		$payment_mode     = strtolower( trim( $payment_mode ) );
		$operation_uuid   = strtolower( trim( $operation_uuid ) );

		if ( ! in_array( $operation_type, self::OPERATION_TYPES, true ) ) {
			throw new \InvalidArgumentException( 'Unsupported Helcim operation type.' );
		}

		if ( '' === $transaction_uuid ) {
			throw new \InvalidArgumentException( 'A FluentCart transaction UUID is required.' );
		}

		if ( $amount_cents <= 0 ) {
			throw new \InvalidArgumentException( 'The operation amount must be positive.' );
		}

		if ( ! in_array( $payment_mode, self::PAYMENT_MODES, true ) ) {
			throw new \InvalidArgumentException( 'Unsupported FluentCart payment mode.' );
		}

		if ( 1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $operation_uuid ) ) {
			throw new \InvalidArgumentException( 'A valid persistent operation UUID is required.' );
		}

		$material = wp_json_encode(
			array(
				'version'          => 1,
				'operation_type'   => $operation_type,
				'transaction_uuid' => $transaction_uuid,
				'amount_cents'     => $amount_cents,
				'payment_mode'     => $payment_mode,
				'operation_uuid'   => $operation_uuid,
			),
			JSON_UNESCAPED_SLASHES
		);

		if ( ! is_string( $material ) ) {
			throw new \InvalidArgumentException( 'The Helcim operation identity could not be encoded.' );
		}

		return 'ysh-' . substr( hash( 'sha256', $material ), 0, 32 );
	}

	/**
	 * Validate Helcim's 25-36 character contract using header-safe characters.
	 *
	 * @param string $key Candidate idempotency key.
	 * @return bool
	 */
	public static function isValid( string $key ): bool {
		return 1 === preg_match( '/\A[A-Za-z0-9_-]{25,36}\z/', $key );
	}
}
