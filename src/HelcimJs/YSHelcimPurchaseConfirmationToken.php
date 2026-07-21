<?php
/**
 * Short-lived server signature binding an inline confirm to one transaction.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\HelcimJs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YSHelcimPurchaseConfirmationToken {

	private const LIFETIME_SECONDS = 900;

	/** @var callable */
	private $clock;

	/** @var callable */
	private $secret_provider;

	public function __construct( ?callable $clock = null, ?callable $secret_provider = null ) {
		$this->clock           = $clock ?? static fn (): int => time();
		$this->secret_provider = $secret_provider ?? static fn (): string => hash(
			'sha256',
			wp_salt( 'auth' ) . '|ys-helcim-inline-confirm-v1',
			true
		);
	}

	/** @return string|\WP_Error */
	public function issue( string $transaction_uuid, int $transaction_id ) {
		$identity = self::identity( $transaction_uuid, $transaction_id );
		$now      = $this->now();
		$secret   = $this->secret();
		if ( null === $identity || null === $now || null === $secret ) {
			return self::invalid();
		}

		$expires = $now + self::LIFETIME_SECONDS;
		$mac     = hash_hmac( 'sha256', self::material( $identity, $expires ), $secret, true );

		return (string) $expires . '.' . self::base64Url( $mac );
	}

	public function verify( mixed $token, string $transaction_uuid, int $transaction_id ): bool {
		$identity = self::identity( $transaction_uuid, $transaction_id );
		$now      = $this->now();
		$secret   = $this->secret();
		if ( null === $identity || null === $now || null === $secret || ! is_string( $token ) ) {
			return false;
		}
		if ( 1 !== preg_match( '/\A([1-9][0-9]{9,11})\.([A-Za-z0-9_-]{43})\z/', $token, $matches ) ) {
			return false;
		}

		$expires = self::timestamp( $matches[1] );
		if ( null === $expires || $expires < $now || $expires > $now + self::LIFETIME_SECONDS ) {
			return false;
		}

		$expected = self::base64Url(
			hash_hmac( 'sha256', self::material( $identity, $expires ), $secret, true )
		);
		return hash_equals( $expected, $matches[2] );
	}

	/** @return array{uuid:string,id:int}|null */
	private static function identity( string $transaction_uuid, int $transaction_id ): ?array {
		$transaction_uuid = trim( $transaction_uuid );
		if (
			$transaction_id <= 0 ||
			'' === $transaction_uuid ||
			strlen( $transaction_uuid ) > 191 ||
			1 === preg_match( '/[\x00-\x1F\x7F]/', $transaction_uuid )
		) {
			return null;
		}

		return array( 'uuid' => $transaction_uuid, 'id' => $transaction_id );
	}

	/** @param array{uuid:string,id:int} $identity */
	private static function material( array $identity, int $expires ): string {
		return "ys-helcim-inline-confirm-v1\0" . $identity['uuid'] . "\0" . (string) $identity['id'] . "\0" . (string) $expires;
	}

	private function now(): ?int {
		try {
			$value = ( $this->clock )();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return null;
		}

		return is_int( $value ) && $value > 0 ? $value : null;
	}

	private function secret(): ?string {
		try {
			$value = ( $this->secret_provider )();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return null;
		}

		return is_string( $value ) && strlen( $value ) >= 32 ? $value : null;
	}

	private static function timestamp( string $value ): ?int {
		$maximum = (string) PHP_INT_MAX;
		if ( strlen( $value ) > strlen( $maximum ) || ( strlen( $value ) === strlen( $maximum ) && strcmp( $value, $maximum ) > 0 ) ) {
			return null;
		}
		$timestamp = (int) $value;
		return $timestamp > 0 && (string) $timestamp === $value ? $timestamp : null;
	}

	private static function base64Url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	private static function invalid(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_confirmation_token_unavailable',
			__( 'The payment confirmation could not be secured. Please refresh and try again.', 'ys-helcim-via-fluentcart' )
		);
	}
}
