<?php
/**
 * Prevents FluentCart's local-first refund path for Helcim charges.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FluentCart 1.5.2 creates a local refund before calling a gateway. Returning
 * zero here is the only pre-write veto exposed by that flow.
 */
final class YSHelcimNativeRefundVeto {

	/** @param mixed $transaction FluentCart order transaction model. */
	public static function filter( int|float|string $maximum, mixed $transaction ): int|float|string {
		if ( ! is_object( $transaction ) ) {
			return $maximum;
		}

		$gateway = (string) ( $transaction->payment_method ?? '' );
		$status  = (string) ( $transaction->status ?? '' );

		return 'succeeded' === $status && in_array( $gateway, array( 'ys_helcim', 'ys_helcim_js' ), true )
			? 0
			: $maximum;
	}
}
