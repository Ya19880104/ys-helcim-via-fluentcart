<?php
/**
 * Strict proof validation for a successful Helcim purchase.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps modal, inline, and webhook payment confirmation on one fail-closed contract.
 */
final class YSHelcimPurchaseProof {

	/**
	 * Return null only when the response is complete and exactly matches the charge.
	 *
	 * @param array<string, mixed> $response                Helcim transaction response.
	 * @param int                  $expected_amount_cents   Expected amount in cents.
	 * @param string               $expected_currency       Expected ISO currency.
	 * @param string|null          $expected_transaction_id Optional ID used for an API lookup.
	 */
	public static function failureReason(
		array $response,
		int $expected_amount_cents,
		string $expected_currency,
		?string $expected_transaction_id = null
	): ?string {
		if ( 'APPROVED' !== strtoupper( trim( (string) ( $response['status'] ?? '' ) ) ) ) {
			return 'status_not_approved';
		}

		if ( 'purchase' !== strtolower( trim( (string) ( $response['type'] ?? '' ) ) ) ) {
			return 'type_not_purchase';
		}

		$transaction_id = YSHelcimTransactionId::normalize( $response['transactionId'] ?? null );
		if ( null === $transaction_id ) {
			return 'invalid_transaction_id';
		}

		if ( null !== $expected_transaction_id && $transaction_id !== YSHelcimTransactionId::normalize( $expected_transaction_id ) ) {
			return 'transaction_id_mismatch';
		}

		if ( self::amountToCents( $response['amount'] ?? null ) !== $expected_amount_cents ) {
			return 'amount_mismatch';
		}

		$currency = strtoupper( trim( (string) ( $response['currency'] ?? '' ) ) );
		if ( '' === $currency || $currency !== strtoupper( trim( $expected_currency ) ) ) {
			return 'currency_mismatch';
		}

		return null;
	}

	/** Convert an ordinary non-negative decimal with at most two places to cents. */
	private static function amountToCents( mixed $value ): ?int {
		if ( is_bool( $value ) || ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) ) ) {
			return null;
		}

		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) {
				return null;
			}
			$value = (string) json_encode( $value, JSON_PRESERVE_ZERO_FRACTION );
		} else {
			$value = trim( (string) $value );
		}

		if ( 1 !== preg_match( '/\A([0-9]+)(?:\.([0-9]{1,2}))?\z/', $value, $matches ) ) {
			return null;
		}

		$dollars  = (int) $matches[1];
		$fraction = (int) str_pad( $matches[2] ?? '', 2, '0' );
		if ( $dollars > intdiv( PHP_INT_MAX - $fraction, 100 ) ) {
			return null;
		}

		return ( $dollars * 100 ) + $fraction;
	}
}
