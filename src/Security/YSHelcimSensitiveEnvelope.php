<?php
/**
 * Authenticated encryption for short-lived reconciliation material.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AES-256-GCM envelope derived from the site's WordPress authentication salt.
 */
final class YSHelcimSensitiveEnvelope {

	private const PREFIX = 'ysenc:v1:';
	private const CIPHER = 'aes-256-gcm';
	private const AAD    = 'ys-helcim-operation-material-v1';
	private const NONCE_LENGTH = 12;
	private const TAG_LENGTH   = 16;

	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			throw new \InvalidArgumentException( 'Sensitive operation material cannot be empty.' );
		}

		$nonce = random_bytes( self::NONCE_LENGTH );
		$tag   = '';
		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			self::key(),
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			self::AAD,
			self::TAG_LENGTH
		);

		if ( false === $ciphertext || self::TAG_LENGTH !== strlen( $tag ) ) {
			throw new \RuntimeException( 'Sensitive operation material could not be encrypted.' );
		}

		return self::PREFIX . self::base64UrlEncode( $nonce . $tag . $ciphertext );
	}

	public static function decrypt( string $envelope ): ?string {
		if ( ! str_starts_with( $envelope, self::PREFIX ) ) {
			return null;
		}

		$payload = self::base64UrlDecode( substr( $envelope, strlen( self::PREFIX ) ) );
		if ( null === $payload || strlen( $payload ) <= self::NONCE_LENGTH + self::TAG_LENGTH ) {
			return null;
		}

		$nonce      = substr( $payload, 0, self::NONCE_LENGTH );
		$tag        = substr( $payload, self::NONCE_LENGTH, self::TAG_LENGTH );
		$ciphertext = substr( $payload, self::NONCE_LENGTH + self::TAG_LENGTH );
		$plaintext  = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			self::key(),
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			self::AAD
		);

		return is_string( $plaintext ) && '' !== $plaintext ? $plaintext : null;
	}

	public static function isValid( string $envelope ): bool {
		return null !== self::decrypt( $envelope );
	}

	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|ys-helcim-operation-material-v1', true );
	}

	private static function base64UrlEncode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private static function base64UrlDecode( string $value ): ?string {
		if ( '' === $value || 1 !== preg_match( '/\A[A-Za-z0-9_-]+\z/', $value ) ) {
			return null;
		}

		$padding = ( 4 - strlen( $value ) % 4 ) % 4;
		$decoded = base64_decode( strtr( $value, '-_', '+/' ) . str_repeat( '=', $padding ), true );

		return is_string( $decoded ) ? $decoded : null;
	}
}
