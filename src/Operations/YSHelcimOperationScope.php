<?php
/**
 * Canonical operation business-scope keys.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Operations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts variable business identifiers into fixed binary-safe lock keys.
 */
final class YSHelcimOperationScope {

	public static function fromBusinessKey( string $business_key ): string {
		$business_key = trim( $business_key );
		if ( '' === $business_key ) {
			throw new \InvalidArgumentException( 'A Helcim business scope is required.' );
		}

		if ( 1 === preg_match( '/\Ayshs-[a-f0-9]{64}\z/', $business_key ) ) {
			return $business_key;
		}

		return 'yshs-' . hash( 'sha256', $business_key );
	}
}
