<?php
/**
 * Fail-closed storage helpers for permanent gateway secrets.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Settings;

use FluentCart\App\Helpers\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Permanent secrets are usable only when FluentCart can prove valid ciphertext. */
final class YSHelcimSecretStorage {

	/**
	 * Prepare one submitted secret without ever returning new plaintext.
	 *
	 * @param mixed $submitted Submitted field value, or null when omitted.
	 * @param mixed $existing  Previously stored field value.
	 * @param bool  $failed    Set when a non-blank submission cannot be secured.
	 * @return string Verified ciphertext or an empty string.
	 */
	public static function prepareForStorage( $submitted, $existing, bool &$failed ): string {
		$existing_ciphertext = self::isEncrypted( $existing ) ? $existing : '';

		if ( null === $submitted || ( is_string( $submitted ) && '' === trim( $submitted ) ) ) {
			return $existing_ciphertext;
		}

		if ( ! is_string( $submitted ) ) {
			$failed = true;
			return $existing_ciphertext;
		}

		if ( self::isEncrypted( $submitted ) ) {
			return $submitted;
		}

		try {
			$encrypted = Helper::encryptKey( $submitted );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$failed = true;
			return $existing_ciphertext;
		}

		if ( ! self::isEncrypted( $encrypted ) ) {
			$failed = true;
			return $existing_ciphertext;
		}

		return $encrypted;
	}

	/**
	 * Determine whether a submitted-or-stored secret is safely usable.
	 *
	 * @param mixed  $submitted Submitted field value, or null when omitted.
	 * @param string $stored    Previously stored, fail-closed decrypted value.
	 */
	public static function isAvailableForValidation( $submitted, string $stored ): bool {
		if ( null === $submitted || ( is_string( $submitted ) && '' === trim( $submitted ) ) ) {
			return '' !== trim( $stored );
		}

		$failed     = false;
		$ciphertext = self::prepareForStorage( $submitted, '', $failed );

		return ! $failed && '' !== trim( self::decrypt( $ciphertext ) );
	}

	/** Decrypt only values FluentCart can first prove are valid ciphertext. */
	public static function decrypt( $ciphertext ): string {
		if ( ! self::isEncrypted( $ciphertext ) ) {
			return '';
		}

		try {
			$decrypted = Helper::decryptKey( $ciphertext );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return '';
		}

		return is_string( $decrypted ) ? $decrypted : '';
	}

	/** Prove ciphertext without allowing missing helpers or provider exceptions to escape. */
	private static function isEncrypted( $value ): bool {
		if ( ! is_string( $value ) || '' === $value || ! is_callable( array( Helper::class, 'isValueEncrypted' ) ) ) {
			return false;
		}

		try {
			return true === Helper::isValueEncrypted( $value );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return false;
		}
	}
}
