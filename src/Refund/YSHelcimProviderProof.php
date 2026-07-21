<?php
/**
 * Strict proof classifier for Helcim refund and reverse responses.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

use YangSheep\Helcim\FluentCart\Support\YSHelcimTransactionId;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prevents ambiguous provider responses from becoming local refunds.
 */
final class YSHelcimProviderProof {

	private const OPEN_BATCH_REFUND_ERROR = 'card transaction cannot be refunded';

	/**
	 * Classify a provider response using exact transaction evidence.
	 *
	 * @param array<string, mixed>|\WP_Error $response      API response or normalized API error.
	 * @param string                        $expected_type  refund or reverse.
	 * @param int                           $amount_cents   Expected amount in integer cents.
	 * @param string                        $currency       Expected ISO currency.
	 */
	public static function classify(
		array|\WP_Error $response,
		string $expected_type,
		int $amount_cents,
		string $currency
	): YSHelcimRefundResult {
		if ( is_wp_error( $response ) ) {
			return self::classifyError( $response, $expected_type );
		}

		$status = strtoupper( trim( (string) ( $response['status'] ?? '' ) ) );
		if ( ! in_array( $status, array( 'APPROVED', 'DECLINED' ), true ) ) {
			return new YSHelcimRefundResult(
				YSHelcimRefundResult::INDETERMINATE,
				null,
				'provider_status_unknown',
				'Helcim returned an unknown or missing operation status.'
			);
		}

		$vendor_transaction_id = self::positiveIntegerString( $response['transactionId'] ?? null );
		$type_matches          = strtolower( trim( (string) ( $response['type'] ?? '' ) ) ) === strtolower( trim( $expected_type ) );
		$currency_matches      = isset( $response['currency'] )
			&& strtoupper( trim( (string) $response['currency'] ) ) === strtoupper( trim( $currency ) );
		$response_amount       = self::amountToCents( $response['amount'] ?? null );
		$amount_matches        = null !== $response_amount && $response_amount === $amount_cents;

		if ( null === $vendor_transaction_id || ! $type_matches || ! $currency_matches || ! $amount_matches ) {
			return new YSHelcimRefundResult(
				YSHelcimRefundResult::INDETERMINATE,
				null,
				'provider_proof_mismatch',
				'Helcim reported approval without complete matching transaction proof.'
			);
		}

		if ( 'DECLINED' === $status ) {
			return new YSHelcimRefundResult(
				YSHelcimRefundResult::DECLINED,
				null,
				'provider_declined',
				'Helcim declined the operation.'
			);
		}

		return new YSHelcimRefundResult( YSHelcimRefundResult::SUCCEEDED, $vendor_transaction_id );
	}

	private static function classifyError( \WP_Error $error, string $expected_type ): YSHelcimRefundResult {
		$data          = $error->get_error_data();
		$data          = is_array( $data ) ? $data : array();
		$kind          = (string) ( $data['kind'] ?? '' );
		$indeterminate = true === ( $data['indeterminate'] ?? false )
			|| in_array( $kind, array( 'transport', 'http', 'invalid_response' ), true );

		if ( $indeterminate ) {
			return new YSHelcimRefundResult(
				YSHelcimRefundResult::INDETERMINATE,
				null,
				$error->get_error_code(),
				$error->get_error_message()
			);
		}

		if ( 'refund' === strtolower( trim( $expected_type ) )
			&& 'provider' === $kind
			&& in_array( (int) ( $data['http_code'] ?? 0 ), array( 400, 422 ), true )
			&& self::hasExactOpenBatchError( $data['provider_errors'] ?? null ) ) {
			return new YSHelcimRefundResult(
				YSHelcimRefundResult::REQUIRES_REVERSE,
				null,
				'open_batch_requires_reverse',
				'Helcim requires a reversal for this open-batch transaction.'
			);
		}

		return new YSHelcimRefundResult(
			YSHelcimRefundResult::FAILED,
			null,
			$error->get_error_code(),
			$error->get_error_message()
		);
	}

	private static function positiveIntegerString( mixed $value ): ?string {
		return YSHelcimTransactionId::normalize( $value );
	}

	/** Convert an ordinary non-negative decimal with at most two places to cents. */
	public static function amountToCents( mixed $value ): ?int {
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

	private static function hasExactOpenBatchError( mixed $errors ): bool {
		if ( is_string( $errors ) ) {
			$normalized = strtolower( rtrim( trim( $errors ), ". \t\n\r\0\x0B" ) );
			return self::OPEN_BATCH_REFUND_ERROR === $normalized;
		}
		if ( ! is_array( $errors ) ) {
			return false;
		}

		foreach ( $errors as $value ) {
			if ( self::hasExactOpenBatchError( $value ) ) {
				return true;
			}
		}

		return false;
	}
}
