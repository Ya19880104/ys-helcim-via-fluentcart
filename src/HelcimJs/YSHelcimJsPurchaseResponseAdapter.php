<?php
/**
 * Fail-closed adapter from Helcim Payment API results to purchase coordinator proof.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\HelcimJs;

use YangSheep\Helcim\FluentCart\Support\YSHelcimPurchaseProof;
use YangSheep\Helcim\FluentCart\Support\YSHelcimApiClient;
use YangSheep\Helcim\FluentCart\Support\YSHelcimTransactionId;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits only the exact success/decline envelopes accepted by the coordinator.
 */
final class YSHelcimJsPurchaseResponseAdapter {

	/**
	 * @param array<string, mixed>|\WP_Error $provider_result Raw API client result.
	 * @param array<string, int|string>       $identity        Server-owned purchase identity.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function toCoordinatorOutcome( $provider_result, array $identity ) {
		if ( is_wp_error( $provider_result ) ) {
			if ( self::isAllowlistedApiDecline( $provider_result ) ) {
				return self::declineEnvelope( $identity );
			}

			$no_charge_disposition = self::definitiveNoChargeDisposition( $provider_result );
			return null === $no_charge_disposition
				? $provider_result
				: self::failureEnvelope( $no_charge_disposition );
		}

		if ( ! is_array( $provider_result ) ) {
			return self::unprovenOutcome();
		}

		if ( self::isExactApprovedProof( $provider_result, $identity ) ) {
			return array(
				'outcome'     => 'succeeded',
				'transaction' => array(
					'status'        => 'APPROVED',
					'type'          => 'purchase',
					'transactionId' => YSHelcimTransactionId::normalize( $provider_result['transactionId'] ),
					'amount'        => self::decimalAmount( (int) $identity['amount'] ),
					'currency'      => strtoupper( (string) $identity['currency'] ),
				),
			);
		}

		if ( self::isExactDeclineProof( $provider_result, $identity ) ) {
			return self::declineEnvelope( $identity );
		}

		return self::unprovenOutcome();
	}

	/** @param array<string, mixed> $response @param array<string, int|string> $identity */
	private static function isExactApprovedProof( array $response, array $identity ): bool {
		return null === YSHelcimPurchaseProof::failureReason(
			$response,
			(int) ( $identity['amount'] ?? 0 ),
			(string) ( $identity['currency'] ?? '' )
		);
	}

	/** @param array<string, mixed> $response @param array<string, int|string> $identity */
	private static function isExactDeclineProof( array $response, array $identity ): bool {
		if (
			'DECLINED' !== strtoupper( trim( (string) ( $response['status'] ?? '' ) ) ) ||
			'purchase' !== strtolower( trim( (string) ( $response['type'] ?? '' ) ) )
		) {
			return false;
		}

		$synthetic_proof                  = $response;
		$synthetic_proof['status']        = 'APPROVED';
		$synthetic_proof['transactionId'] = '1';

		return null === YSHelcimPurchaseProof::failureReason(
			$synthetic_proof,
			(int) ( $identity['amount'] ?? 0 ),
			(string) ( $identity['currency'] ?? '' )
		);
	}

	private static function isAllowlistedApiDecline( \WP_Error $error ): bool {
		$data   = $error->get_error_data();
		$detail = is_array( $data ) ? ( $data['provider_errors'] ?? null ) : null;

		return 'ys_helcim_api_error' === $error->get_error_code()
			&& is_array( $data )
			&& true === ( $data['definitive_decline'] ?? null )
			&& false === ( $data['indeterminate'] ?? null )
			&& 'provider' === ( $data['kind'] ?? null )
			&& 500 === ( $data['http_code'] ?? null )
			&& is_string( $detail )
			&& strlen( $detail ) <= 500
			&& 1 === preg_match( '/\ATransaction Declined:[^\r\n]{1,478}\z/', $detail );
	}

	private static function definitiveNoChargeDisposition( \WP_Error $error ): ?string {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) || false !== ( $data['indeterminate'] ?? null ) ) {
			return null;
		}

		$disposition = $data['mutation_disposition'] ?? null;
		if (
			YSHelcimApiClient::MUTATION_NEVER_SENT === $disposition
			&& 'local' === ( $data['kind'] ?? null )
			&& ! array_key_exists( 'http_code', $data )
			&& in_array(
				$error->get_error_code(),
				array( 'ys_helcim_api_error', 'ys_helcim_invalid_idempotency_key' ),
				true
			)
		) {
			return $disposition;
		}

		if (
			YSHelcimApiClient::MUTATION_AUTHENTICATION_REJECTED === $disposition
			&& 'ys_helcim_api_error' === $error->get_error_code()
			&& 'provider' === ( $data['kind'] ?? null )
			&& 401 === ( $data['http_code'] ?? null )
		) {
			return $disposition;
		}

		return null;
	}

	/** @param array<string, int|string> $identity @return array<string, mixed> */
	private static function declineEnvelope( array $identity ): array {
		return array(
			'outcome'    => 'declined',
			'definitive' => true,
			'transaction' => array(
				'status'   => 'DECLINED',
				'type'     => 'purchase',
				'amount'   => self::decimalAmount( (int) ( $identity['amount'] ?? 0 ) ),
				'currency' => strtoupper( (string) ( $identity['currency'] ?? '' ) ),
			),
		);
	}

	/** @return array{outcome:string,definitive:bool,mutation_disposition:string} */
	private static function failureEnvelope( string $mutation_disposition ): array {
		return array(
			'outcome'              => 'failed',
			'definitive'           => true,
			'mutation_disposition' => $mutation_disposition,
		);
	}

	private static function decimalAmount( int $amount_cents ): string {
		return number_format( $amount_cents / 100, 2, '.', '' );
	}

	private static function unprovenOutcome(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_purchase_outcome_unproven',
			__( 'Helcim did not return exact proof of the payment outcome.', 'ys-helcim-via-fluentcart' ),
			array(
				'kind'          => 'provider',
				'indeterminate' => true,
			)
		);
	}
}
