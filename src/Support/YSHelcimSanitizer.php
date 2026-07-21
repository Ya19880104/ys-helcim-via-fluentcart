<?php
/**
 * Bounded redaction for provider-controlled diagnostic text.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps provider errors useful without persisting markup, PANs, or credentials.
 */
final class YSHelcimSanitizer {

	/** @var string[] */
	private const SENSITIVE_KEYS = array(
		'cardnumber', 'cardcvv', 'cardtoken', 'cardexpiry', 'apitoken', 'api-token',
		'secrettoken', 'secret', 'checkouttoken', 'xmlhash', 'hash', 'token',
		'js_secret_key', 'cardholdername', 'approvalcode', 'authorization',
		'password', 'transaction_uuid', 'transactionuuid', 'trxhash',
		'billingaddress', 'shippingaddress', 'customer', 'customerdata', 'contact',
		'address', 'name', 'firstname', 'lastname', 'email', 'emailaddress', 'phone',
		'phonenumber', 'street1', 'street2', 'city', 'province', 'state', 'postalcode',
		'zipcode', 'country',
	);

	public static function errorText( string $message, int $limit = 1000 ): string {
		$limit   = max( 1, min( 5000, $limit ) );
		$message = sanitize_textarea_field( $message );
		$message = (string) preg_replace( '/\b(?:\d[ -]?){12,19}\b/', '[redacted-number]', $message );
		$message = (string) preg_replace(
			'/(api[-_ ]?token|card[-_ ]?token|secret(?:token)?|authorization|password)\s*[:=]?\s*(?:bearer\s+)?[^\s,;]+/i',
			'$1=[redacted]',
			$message
		);

		return substr( $message, 0, $limit );
	}

	/** @return array|string|null */
	public static function providerErrors( mixed $errors, int $depth = 0 ) {
		if ( $depth > 5 ) {
			return null;
		}
		if ( is_string( $errors ) ) {
			return self::errorText( $errors, 500 );
		}
		if ( ! is_array( $errors ) ) {
			return null;
		}

		$sanitized = array();
		foreach ( array_slice( $errors, 0, 20, true ) as $key => $value ) {
			$safe_key = substr( sanitize_key( (string) $key ), 0, 100 );
			if ( in_array( $safe_key, self::SENSITIVE_KEYS, true ) ) {
				$sanitized[ $safe_key ] = '[redacted]';
				continue;
			}
			if ( is_scalar( $value ) && null !== $value ) {
				$sanitized[ $safe_key ] = self::errorText( (string) $value, 500 );
			} elseif ( is_array( $value ) ) {
				$sanitized[ $safe_key ] = self::providerErrors( $value, $depth + 1 );
			}
		}

		return $sanitized;
	}

	/** Recursively sanitize provider responses before diagnostic logging. */
	public static function logContext( mixed $value, int $depth = 0 ): mixed {
		if ( $depth > 5 ) {
			return '[truncated]';
		}
		if ( is_string( $value ) ) {
			return self::errorText( $value, 500 );
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return self::errorText( (string) $value, 500 );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$result = array();
		foreach ( array_slice( $value, 0, 50, true ) as $key => $item ) {
			$normalized_key = sanitize_key( (string) $key );
			$result[ $key ] = in_array( $normalized_key, self::SENSITIVE_KEYS, true )
				? '[redacted]'
				: self::logContext( $item, $depth + 1 );
		}
		return $result;
	}
}
