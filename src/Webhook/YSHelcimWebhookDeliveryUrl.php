<?php
/**
 * Validates the complete public webhook delivery URL.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YSHelcimWebhookDeliveryUrl {

	private const REQUIRED_PATH_SUFFIX = '/wp-json/ys-fc-pay/v1/events/card';

	/** @return string|\WP_Error */
	public static function validate( string $url ) {
		$url   = trim( $url );
		$parts = wp_parse_url( $url );
		$path  = is_array( $parts ) && is_string( $parts['path'] ?? null ) ? $parts['path'] : '';
		$host  = is_array( $parts ) && is_string( $parts['host'] ?? null ) ? $parts['host'] : '';
		if (
			'' === $url ||
			! is_array( $parts ) ||
			'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ||
			'' === $host ||
			isset( $parts['query'] ) ||
			isset( $parts['fragment'] ) ||
			isset( $parts['user'] ) ||
			isset( $parts['pass'] ) ||
			str_contains( strtolower( $host . $path ), 'helcim' ) ||
			! str_ends_with( $path, self::REQUIRED_PATH_SUFFIX ) ||
			strlen( $path ) < strlen( self::REQUIRED_PATH_SUFFIX ) ||
			str_ends_with( $path, '/' ) ||
			1 === preg_match( '/[\x00-\x20\x7F]/', $url )
		) {
			return self::blocked();
		}

		return $url;
	}

	private static function blocked(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_webhook_url_blocked',
			__( 'Webhook delivery is not ready: use a clean HTTPS REST URL whose host and path do not contain the provider name.', 'ys-helcim-via-fluentcart' )
		);
	}
}
