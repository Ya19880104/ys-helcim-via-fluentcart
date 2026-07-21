<?php
/**
 * Cached proof that the current hosted credential can read card transactions.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\HelcimPay;

use YangSheep\Helcim\FluentCart\Support\YSHelcimApiClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Fail-closed preflight for the API permission required by lost-callback recovery. */
final class YSHelcimPayRecoveryCapability {
	public const OPTION_NAME = 'ys_helcim_hosted_recovery_capability_v1';

	private const CACHE_TTL_SECONDS = 900;

	/** @var callable */
	private $request;

	/** @var callable */
	private $clock;

	public function __construct( ?callable $request = null, ?callable $clock = null ) {
		$this->request = $request ?? static fn (
			string $endpoint,
			array $payload,
			string $api_token,
			?string $idempotency_key,
			string $method
		) => YSHelcimApiClient::request( $endpoint, $payload, $api_token, $idempotency_key, $method );
		$this->clock = $clock ?? static fn (): int => time();
	}

	/** @return true|\WP_Error */
	public function verify( YSHelcimPaySettings $settings ) {
		$mode = strtolower( trim( (string) $settings->getMode() ) );
		$api_token = trim( $settings->getApiToken() );
		if ( ! in_array( $mode, array( 'test', 'live' ), true ) || '' === $api_token ) {
			return self::unavailable();
		}
		try {
			$now = ( $this->clock )();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$now = null;
		}
		if ( ! is_int( $now ) || $now <= 0 ) {
			$api_token = '';
			return self::unavailable();
		}

		$fingerprint = hash_hmac(
			'sha256',
			$mode . '|' . $api_token,
			wp_salt( 'auth' ) . '|ys-helcim-hosted-recovery-capability-v1'
		);
		$cache = get_option( self::OPTION_NAME, array() );
		$entry = is_array( $cache ) && is_array( $cache[ $mode ] ?? null ) ? $cache[ $mode ] : array();
		$cached_fingerprint = is_string( $entry['fingerprint'] ?? null ) ? $entry['fingerprint'] : '';
		$verified_at = is_int( $entry['verified_at'] ?? null ) ? $entry['verified_at'] : 0;
		if (
			64 === strlen( $cached_fingerprint ) &&
			hash_equals( $fingerprint, $cached_fingerprint ) &&
			$verified_at <= $now &&
			$verified_at >= $now - self::CACHE_TTL_SECONDS
		) {
			$api_token = '';
			return true;
		}

		try {
			$response = ( $this->request )(
				'card-transactions',
				array(
					'invoiceNumber' => '00000000-0000-4000-8000-000000000000',
					'limit'         => 1,
					'page'          => 1,
				),
				$api_token,
				null,
				'GET'
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$response = null;
		} finally {
			$api_token = '';
		}
		if ( is_wp_error( $response ) || ! is_array( $response ) || ! array_is_list( $response ) ) {
			return self::unavailable();
		}

		$cache = is_array( $cache ) ? $cache : array();
		$cache[ $mode ] = array(
			'fingerprint' => $fingerprint,
			'verified_at' => $now,
		);
		update_option( self::OPTION_NAME, $cache, false );
		return true;
	}

	private static function unavailable(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_hosted_recovery_permission_unavailable',
			__( 'The Helcim API credential cannot prove card-transaction read access required for payment recovery.', 'ys-helcim-via-fluentcart' ),
			array( 'status' => 503 )
		);
	}
}
