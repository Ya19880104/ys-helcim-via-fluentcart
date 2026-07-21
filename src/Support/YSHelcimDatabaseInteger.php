<?php
/**
 * Strict conversion for unsigned integer columns returned by wpdb.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YSHelcimDatabaseInteger {

	/**
	 * WordPress returns MySQL integer columns as decimal strings. Accept only a
	 * canonical positive decimal that fits safely in the running PHP integer.
	 */
	public static function positive( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $value ) ) {
			return null;
		}

		$maximum = (string) PHP_INT_MAX;
		if ( strlen( $value ) > strlen( $maximum ) || ( strlen( $value ) === strlen( $maximum ) && strcmp( $value, $maximum ) > 0 ) ) {
			return null;
		}

		$integer = (int) $value;
		return $integer > 0 && (string) $integer === $value ? $integer : null;
	}
}
