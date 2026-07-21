<?php
/**
 * Strict Helcim transaction identifier normalization.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prevents malformed or lossy provider identifiers from becoming local proof.
 */
final class YSHelcimTransactionId {

	/**
	 * Normalize a positive base-10 integer that is safe on this PHP platform.
	 *
	 * @param mixed $value Candidate transaction identifier.
	 * @return string|null Canonical identifier, or null when it is not proof.
	 */
	public static function normalize( mixed $value ): ?string {
		if ( is_bool( $value ) || ( ! is_int( $value ) && ! is_string( $value ) ) ) {
			return null;
		}

		$value = (string) $value;
		if ( 1 !== preg_match( '/\A[1-9][0-9]*\z/', $value ) ) {
			return null;
		}

		$max = (string) PHP_INT_MAX;
		if ( strlen( $value ) > strlen( $max ) || ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) > 0 ) ) {
			return null;
		}

		return $value;
	}
}
