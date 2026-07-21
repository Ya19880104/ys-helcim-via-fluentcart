<?php
/**
 * Adapts a claimed outbox row to the recorder's receipt-plan verification.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YSHelcimRefundEffectIntegrityVerifier {

	/** @var callable */
	private $recorder;

	public function __construct( callable $recorder ) {
		$this->recorder = $recorder;
	}

	/** @param array<string,mixed> $effect @param array<string,mixed> $payload @return array<string,mixed>|\WP_Error */
	public function verify( array $effect, array $payload ) {
		unset( $payload );
		$operation_uuid = $effect['operation_uuid'] ?? null;
		if (
			! is_string( $operation_uuid ) ||
			1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $operation_uuid )
		) {
			return self::unavailable();
		}

		try {
			$result = ( $this->recorder )( $operation_uuid );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::unavailable();
		}

		return is_array( $result ) || is_wp_error( $result ) ? $result : self::unavailable();
	}

	private static function unavailable(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_effect_integrity_unavailable',
			__( 'The refund effect could not be matched to its local receipt.', 'ys-helcim-via-fluentcart' )
		);
	}
}
