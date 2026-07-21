<?php
/**
 * Durable confirmation boundary for HelcimPay.js hosted purchases.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\HelcimPay;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsPurchaseResponseAdapter;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsPurchaseRuntime;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationState;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimPurchaseOperation;
use YangSheep\Helcim\FluentCart\Support\YSHelcimTransactionId;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates the browser callback without trusting browser-owned order fields.
 */
final class YSHelcimPayConfirmationService {

	private const GATEWAY_SLUG = 'ys_helcim';

	public function __construct(
		private YSHelcimOperationRepository $operations,
		private YSHelcimJsPurchaseRuntime $runtime
	) {
	}

	/**
	 * Validate one HelcimPay.js result and reconcile its exact durable attempt.
	 *
	 * @param array<string, mixed> $event_data Decoded HelcimPay.js event data.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function confirm(
		string $transaction_uuid,
		string $operation_uuid,
		string $confirm_token,
		array $event_data,
		string $received_hash
	) {
		$transaction = $this->loadTransaction( $transaction_uuid );
		if ( is_wp_error( $transaction ) ) {
			return $transaction;
		}

		$purchase = $this->purchaseIdentity( $transaction );
		if ( is_wp_error( $purchase ) ) {
			return $purchase;
		}

		$operation_uuid = strtolower( trim( $operation_uuid ) );
		$row            = $this->operations->findByUuidStrict( $operation_uuid );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( ! is_array( $row ) || ! $purchase->matchesIdentityRow( $row ) ) {
			return self::error( 'ys_helcim_confirm_operation_invalid', 'The matching payment operation could not be verified.' );
		}
		if ( ! self::hasExactCorrelation( $row, $operation_uuid ) ) {
			return self::error( 'ys_helcim_confirm_correlation_invalid', 'The payment operation correlation could not be verified.' );
		}

		$persisted_replay = $this->resumePersistedSuccess( $transaction, $row, $purchase );
		if ( null !== $persisted_replay ) {
			return $persisted_replay;
		}
		if ( ! self::hasExactTransactionCorrelation( $transaction, $operation_uuid ) ) {
			return self::error( 'ys_helcim_confirm_transaction_correlation_invalid', 'The payment attempt does not match the stored checkout session.' );
		}

		if ( array() === $event_data || 1 !== preg_match( '/\A[a-f0-9]{64}\z/i', trim( $received_hash ) ) ) {
			return self::error( 'ys_helcim_confirm_hash_invalid', 'The payment verification code is invalid.' );
		}

		$secret_token = self::secretToken( $transaction );
		if ( is_wp_error( $secret_token ) ) {
			return $secret_token;
		}

		$encoded = json_encode( $event_data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Helcim's signature contract uses PHP's default JSON encoding.
		$valid_hash = is_string( $encoded )
			&& hash_equals( hash( 'sha256', $encoded . $secret_token ), strtolower( trim( $received_hash ) ) );
		$secret_token = '';
		if ( ! $valid_hash ) {
			return self::error( 'ys_helcim_confirm_hash_invalid', 'The payment verification code is invalid.' );
		}

		$event_correlation = strtolower( trim( (string) ( $event_data['invoiceNumber'] ?? '' ) ) );
		if ( '' === $event_correlation || ! hash_equals( $operation_uuid, $event_correlation ) ) {
			return self::error( 'ys_helcim_confirm_correlation_invalid', 'The provider payment correlation could not be verified.' );
		}

		$identity = $purchase->identity();
		$outcome  = YSHelcimJsPurchaseResponseAdapter::toCoordinatorOutcome( $event_data, $identity );
		if (
			is_wp_error( $outcome ) ||
			! in_array( $outcome['outcome'] ?? null, array( 'succeeded', 'declined' ), true )
		) {
			return self::error( 'ys_helcim_confirm_proof_invalid', 'The provider payment proof is incomplete or invalid.' );
		}

		$consumed = $this->operations->consumeConfirmToken( $operation_uuid, $confirm_token );
		if ( is_wp_error( $consumed ) ) {
			return $consumed;
		}
		if ( true !== $consumed ) {
			$current = $this->operations->findByUuidStrict( $operation_uuid );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			if ( is_array( $current ) ) {
				$replay = $this->resumePersistedSuccess( $transaction, $current, $purchase );
				if ( null !== $replay ) {
					return $replay;
				}
			}

			return self::error( 'ys_helcim_confirm_token_invalid', 'The one-time payment confirmation has expired or was already used.' );
		}

		$outcome['operation_correlation'] = $operation_uuid;
		return $this->runtime->reconcileProviderProof( $transaction, $operation_uuid, $outcome );
	}

	/** @return OrderTransaction|\WP_Error */
	private function loadTransaction( string $transaction_uuid ) {
		$transaction_uuid = trim( $transaction_uuid );
		if ( '' === $transaction_uuid || strlen( $transaction_uuid ) > 191 ) {
			return self::error( 'ys_helcim_confirm_transaction_invalid', 'The FluentCart transaction identifier is invalid.' );
		}

		$transaction = OrderTransaction::query()
			->where( 'uuid', $transaction_uuid )
			->where( 'payment_method', self::GATEWAY_SLUG )
			->where( 'transaction_type', Status::TRANSACTION_TYPE_CHARGE )
			->first();

		return $transaction instanceof OrderTransaction
			? $transaction
			: self::error( 'ys_helcim_confirm_transaction_missing', 'The exact FluentCart transaction could not be loaded.' );
	}

	/** @return YSHelcimPurchaseOperation|\WP_Error */
	private function purchaseIdentity( OrderTransaction $transaction ) {
		return YSHelcimPurchaseOperation::fromTransaction(
			array(
				'gateway'          => self::GATEWAY_SLUG,
				'order_id'         => (int) ( $transaction->order_id ?? 0 ),
				'transaction_id'   => (int) ( $transaction->id ?? 0 ),
				'transaction_uuid' => (string) ( $transaction->uuid ?? '' ),
				'amount'           => (int) ( $transaction->total ?? 0 ),
				'currency'         => (string) ( $transaction->currency ?? '' ),
				'payment_mode'     => (string) ( $transaction->payment_mode ?? '' ),
			)
		);
	}

	/** @param array<string, mixed> $row */
	private static function hasExactCorrelation( array $row, string $operation_uuid ): bool {
		$correlation = strtolower( trim( (string) ( $row['provider_correlation_id'] ?? '' ) ) );
		return '' !== $correlation && hash_equals( $operation_uuid, $correlation );
	}

	private static function hasExactTransactionCorrelation( OrderTransaction $transaction, string $operation_uuid ): bool {
		$meta   = is_array( $transaction->meta ?? null ) ? $transaction->meta : array();
		$stored = $meta['ys_helcim_operation_uuid'] ?? null;

		return is_string( $stored ) && hash_equals( $operation_uuid, $stored );
	}

	/** @return string|\WP_Error */
	private static function secretToken( OrderTransaction $transaction ) {
		$meta       = is_array( $transaction->meta ?? null ) ? $transaction->meta : array();
		$ciphertext = (string) ( $meta['ys_helcim_secret_token_enc'] ?? '' );
		if ( '' === $ciphertext ) {
			return self::error( 'ys_helcim_confirm_secret_missing', 'The one-time provider verification material is unavailable.' );
		}
		if (
			! is_callable( array( Helper::class, 'isValueEncrypted' ) ) ||
			! Helper::isValueEncrypted( $ciphertext )
		) {
			return self::error( 'ys_helcim_confirm_secret_invalid', 'The one-time provider verification material is invalid.' );
		}

		try {
			$decrypted = Helper::decryptKey( $ciphertext );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$decrypted = false;
		}

		return is_string( $decrypted ) && '' !== $decrypted
			? $decrypted
			: self::error( 'ys_helcim_confirm_secret_invalid', 'The one-time provider verification material is invalid.' );
	}

	/**
	 * Resume only a provider success already durably bound to this exact attempt.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>|\WP_Error|null
	 */
	private function resumePersistedSuccess(
		OrderTransaction $transaction,
		array $row,
		YSHelcimPurchaseOperation $purchase
	) {
		if (
			! $purchase->matchesIdentityRow( $row ) ||
			YSHelcimOperationState::REMOTE_SUCCEEDED !== (string) ( $row['remote_status'] ?? '' )
		) {
			return null;
		}

		$provider_id = YSHelcimTransactionId::normalize( $row['vendor_transaction_id'] ?? null );
		if ( null === $provider_id ) {
			return self::error( 'ys_helcim_confirm_proof_invalid', 'The persisted provider payment proof is invalid.' );
		}

		$identity = $purchase->identity();
		return $this->runtime->reconcileProviderProof(
			$transaction,
			(string) $row['operation_uuid'],
			array(
				'operation_correlation' => (string) $row['operation_uuid'],
				'outcome'               => 'succeeded',
				'transaction'           => array(
					'status'        => 'APPROVED',
					'type'          => 'purchase',
					'transactionId' => $provider_id,
					'amount'        => number_format( (int) $identity['amount'] / 100, 2, '.', '' ),
					'currency'      => strtoupper( (string) $identity['currency'] ),
				),
			)
		);
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'ys-helcim-via-fluentcart' ) );
	}
}
