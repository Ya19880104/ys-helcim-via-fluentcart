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
		'cardNumber',
		'cardCVV',
		'cardToken',
		'cardExpiry',
		'api-token',
		'apiToken',
		'secretToken',
		'checkoutToken',
		'xmlHash',
		'hash',
		'token',
		'js_secret_key',
		'cardHolderName',
		'approvalCode',
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
	 * Rule: strings longer than 8 characters keep the first and last 4 characters
	 * with the middle replaced by asterisks; strings of 8 characters or fewer are
	 * fully replaced with *** (keeping 4 characters on each end would expose the
	 * entire value, so they are masked completely).
	 *
	 * @param array $data The original data.
	 * @return array The masked data.
	 */
	public static function mask_sensitive( array $data ): array {
		array_walk_recursive(
			$data,
			static function ( &$value, $key ) {
				if ( ! in_array( (string) $key, self::SENSITIVE_KEYS, true ) ) {
					return;
				}

				if ( ! is_scalar( $value ) || null === $value ) {
					return;
				}

				$string_value = (string) $value;
				$length       = strlen( $string_value );

				if ( $length > 8 ) {
					$value = substr( $string_value, 0, 4 ) . str_repeat( '*', $length - 8 ) . substr( $string_value, -4 );
				} elseif ( $length > 0 ) {
					$value = '***';
				}
			}
		);

		return $data;
	}
}
