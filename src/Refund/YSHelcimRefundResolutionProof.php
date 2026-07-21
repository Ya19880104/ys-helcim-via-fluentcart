<?php
/**
 * Read-only provider proof for resolving an indeterminate refund operation.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

use YangSheep\Helcim\FluentCart\Support\YSHelcimTransactionId;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts two exact provider reads into one canonical, re-checkable proof.
 */
final class YSHelcimRefundResolutionProof {

	public const ACTION = 'resolve_positive';

	/** Provider fields which, when present, explicitly bind a child to its source. */
	private const PARENT_FIELDS = array(
		'originalTransactionId',
		'parentTransactionId',
		'originalCardTransactionId',
	);

	/**
	 * @param array<string,mixed> $operation Stored operation plus resolution_candidate_id.
	 * @param array<string,mixed> $candidate Exact candidate GET response.
	 * @param array<string,mixed> $source    Exact source GET response.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function verify( array $operation, array $candidate, array $source ) {
		$type         = strtolower( trim( (string) ( $operation['operation_type'] ?? '' ) ) );
		$currency     = strtoupper( trim( (string) ( $operation['currency'] ?? '' ) ) );
		$amount       = $operation['amount'] ?? null;
		$candidate_id = YSHelcimTransactionId::normalize( $operation['resolution_candidate_id'] ?? null );
		$source_id    = YSHelcimTransactionId::normalize( $operation['source_vendor_transaction_id'] ?? null );

		if (
			! in_array( $type, array( 'refund', 'reverse' ), true ) ||
			! is_int( $amount ) ||
			$amount <= 0 ||
			! in_array( $currency, array( 'USD', 'CAD' ), true ) ||
			null === $candidate_id ||
			null === $source_id ||
			$candidate_id === $source_id
		) {
			return self::mismatch();
		}

		$candidate_amount = YSHelcimProviderProof::amountToCents( $candidate['amount'] ?? null );
		$source_amount    = YSHelcimProviderProof::amountToCents( $source['amount'] ?? null );
		if (
			$candidate_id !== YSHelcimTransactionId::normalize( $candidate['transactionId'] ?? null ) ||
			'APPROVED' !== strtoupper( trim( (string) ( $candidate['status'] ?? '' ) ) ) ||
			$type !== strtolower( trim( (string) ( $candidate['type'] ?? '' ) ) ) ||
			$amount !== $candidate_amount ||
			$currency !== strtoupper( trim( (string) ( $candidate['currency'] ?? '' ) ) ) ||
			$source_id !== YSHelcimTransactionId::normalize( $source['transactionId'] ?? null ) ||
			'APPROVED' !== strtoupper( trim( (string) ( $source['status'] ?? '' ) ) ) ||
			! in_array( strtolower( trim( (string) ( $source['type'] ?? '' ) ) ), array( 'purchase', 'capture' ), true ) ||
			null === $source_amount ||
			( 'reverse' === $type ? $amount !== $source_amount : $amount > $source_amount ) ||
			$currency !== strtoupper( trim( (string) ( $source['currency'] ?? '' ) ) )
		) {
			return self::mismatch();
		}

		$parent_present = false;
		foreach ( self::PARENT_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $candidate ) ) {
				continue;
			}
			$parent_present = true;
			if ( $source_id !== YSHelcimTransactionId::normalize( $candidate[ $field ] ) ) {
				return self::mismatch();
			}
		}

		$canonical = array(
			'version'                     => 1,
			'action'                      => self::ACTION,
			'operation_uuid'              => strtolower( (string) ( $operation['operation_uuid'] ?? '' ) ),
			'operation_type'              => $type,
			'gateway'                     => (string) ( $operation['gateway'] ?? '' ),
			'payment_mode'                => (string) ( $operation['payment_mode'] ?? '' ),
			'amount_cents'                => $amount,
			'currency'                    => $currency,
			'candidate_transaction_id'    => $candidate_id,
			'candidate_status'            => 'APPROVED',
			'candidate_type'              => $type,
			'candidate_amount_cents'      => $candidate_amount,
			'candidate_currency'          => $currency,
			'source_transaction_id'       => $source_id,
			'source_status'               => 'APPROVED',
			'source_type'                 => strtolower( trim( (string) $source['type'] ) ),
			'source_amount_cents'         => $source_amount,
			'source_currency'             => $currency,
			'provider_parent_field_found' => $parent_present,
		);
		$json      = wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES );
		$digest    = is_string( $json ) ? hash( 'sha256', $json ) : '';
		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $digest ) ) {
			return self::mismatch();
		}

		return array(
			'action'                      => self::ACTION,
			'candidate_transaction_id'    => $candidate_id,
			'source_transaction_id'       => $source_id,
			'parent_attestation_required' => ! $parent_present,
			'proof_digest'                => $digest,
			'candidate'                   => array(
				'transaction_id' => $candidate_id,
				'status'         => 'APPROVED',
				'type'           => $type,
				'amount_cents'   => $candidate_amount,
				'currency'       => $currency,
			),
			'source'                      => array(
				'transaction_id' => $source_id,
				'status'         => 'APPROVED',
				'type'           => $canonical['source_type'],
				'amount_cents'   => $source_amount,
				'currency'       => $currency,
			),
		);
	}

	private static function mismatch(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_resolution_proof_mismatch',
			__( 'The Helcim transactions do not provide exact proof for this refund resolution.', 'ys-helcim-via-fluentcart' )
		);
	}
}
