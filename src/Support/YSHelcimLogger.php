<?php
/**
 * YS Helcim Logger — debug logging and sensitive-data masking.
 *
 * Every Helcim API request/response and internal error is emitted through this
 * class. Sensitive fields (card numbers, tokens, hashes, etc.) are always
 * masked before anything is written to the log.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Support;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logger (static utility class).
 *
 * Output format: error_log( '[ys-helcim-fct] [LEVEL] message | {json context}' ).
 *
 * Level rules:
 * - When debug is disabled: error-level messages are STILL logged (payment
 *   errors must never be silenced); all other levels are skipped.
 * - When debug is enabled: every level is logged.
 */
class YSHelcimLogger {

	/**
	 * Log prefix (identifier for grepping).
	 *
	 * @var string
	 */
	private const PREFIX = '[ys-helcim-fct]';

	/**
	 * Whether debug logging is enabled.
	 *
	 * @var bool
	 */
	private static $enabled = false;

	/**
	 * List of sensitive key names to mask (integration contract with other lanes — do not change).
	 *
	 * @var string[]
	 */
	private const SENSITIVE_KEYS = array(
		'cardnumber',
		'cardcvv',
		'cardtoken',
		'cardexpiry',
		'apitoken',
		'apikey',
		'secrettoken',
		'checkouttoken',
		'xmlhash',
		'hash',
		'token',
		'jssecretkey',
		'cardholdername',
		'approvalcode',
		'authorization',
		'password',
		'transactionuuid',
		'trxhash',
		'billingaddress',
		'shippingaddress',
		'customer',
		'customerdata',
		'contact',
		'address',
		'name',
		'firstname',
		'lastname',
		'email',
		'emailaddress',
		'phone',
		'phonenumber',
		'street1',
		'street2',
		'city',
		'province',
		'state',
		'postalcode',
		'zipcode',
		'country',
	);

	/**
	 * Set the debug logging switch.
	 *
	 * @param bool $enabled Whether to enable it.
	 * @return void
	 */
	public static function set_enabled( bool $enabled ): void {
		self::$enabled = $enabled;
	}

	/**
	 * Write a single log entry (the context is masked first).
	 *
	 * @param string $level   Level (info / error / debug / warning).
	 * @param string $message Message.
	 * @param array  $context Additional data (appended to the message as JSON).
	 * @return void
	 */
	public static function log( string $level, string $message, array $context = array() ): void {
		$level = strtolower( $level );

		// When debug is disabled: error level is still logged, everything else is skipped.
		if ( ! self::$enabled && 'error' !== $level ) {
			return;
		}

		$line = sprintf( '%s [%s] %s', self::PREFIX, strtoupper( $level ), $message );

		if ( ! empty( $context ) ) {
			$masked = self::mask_sensitive( $context );
			$line  .= ' | ' . wp_json_encode( $masked, JSON_UNESCAPED_UNICODE );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- error_log is this plugin's log channel.
		error_log( $line );
	}

	/**
	 * Log an info-level message.
	 *
	 * @param string $message Message.
	 * @param array  $context Additional data.
	 * @return void
	 */
	public static function info( string $message, array $context = array() ): void {
		self::log( 'info', $message, $context );
	}

	/**
	 * Log an error-level message (always logged, regardless of the debug switch).
	 *
	 * @param string $message Message.
	 * @param array  $context Additional data.
	 * @return void
	 */
	public static function error( string $message, array $context = array() ): void {
		self::log( 'error', $message, $context );
	}

	/**
	 * Log a debug-level message.
	 *
	 * @param string $message Message.
	 * @param array  $context Additional data.
	 * @return void
	 */
	public static function debug( string $message, array $context = array() ): void {
		self::log( 'debug', $message, $context );
	}

	/**
	 * Recursively mask sensitive data.
	 *
	 * Sensitive fields are fully redacted. Keeping prefixes or suffixes would
	 * still disclose reusable credential material. Free-form diagnostic strings
	 * also pass through the bounded provider sanitizer before logging.
	 *
	 * @param array $data The original data.
	 * @return array The masked data.
	 */
	public static function mask_sensitive( array $data ): array {
		$masked = array();
		foreach ( $data as $key => $value ) {
			$normalized_key = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '', (string) $key ) );
			if ( in_array( $normalized_key, self::SENSITIVE_KEYS, true ) ) {
				$masked[ $key ] = '[redacted]';
				continue;
			}
			if ( is_array( $value ) ) {
				$masked[ $key ] = self::mask_sensitive( $value );
				continue;
			}
			$masked[ $key ] = is_string( $value )
				? YSHelcimSanitizer::errorText( $value, 5000 )
				: $value;
		}

		return $masked;
	}
}
