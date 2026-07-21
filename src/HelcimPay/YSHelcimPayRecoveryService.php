<?php
/**
 * Provider-query recovery for abandoned hosted HelcimPay.js purchases.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\HelcimPay;

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
 * Reconciles a lost hosted callback without ever initiating another charge.
 */
final class YSHelcimPayRecoveryService {
	/** Helcim documents hosted checkout tokens as valid for sixty minutes. */
	private const CHECKOUT_SESSION_TTL_SECONDS = 3600;

	/** Allow provider indexing to settle before the first positive-only lookup. */
	private const PROVIDER_INDEX_GRACE_SECONDS = 600;

	/** Begin searching for exact positive proof without waiting for token expiry. */
	public const LOOKUP_ELIGIBILITY_SECONDS = 300;

	/** Empty/decline observations cannot expire public checkout material earlier. */
	public const EXPIRED_MATERIAL_CLEANUP_SECONDS = self::CHECKOUT_SESSION_TTL_SECONDS + self::PROVIDER_INDEX_GRACE_SECONDS;

	/** A bounded retry budget prevents abandoned checkouts from polling forever. */
	public const MAX_AUTOMATIC_RECOVERY_ATTEMPTS = 7;

	/** Crash lease for one claimed recovery worker. */
	public const RECOVERY_LEASE_SECONDS = 120;

	/** Delays after attempts 1..6; attempt 7 pauses automatic recovery. */
	private const RETRY_DELAYS = array( 300, 900, 3600, 10800, 21600, 43200 );

	/** @var callable */
	private $transaction_loader;

	/** @var callable */
	private $credential_resolver;

	/** @var callable */
	private $provider_lookup;

	/** @var callable */
	private $clock;

	/** @var string[] */
	private array $terminal_meta_keys = array(
		'ys_helcim_checkout_token',
		'ys_helcim_secret_token_enc',
		'ys_helcim_card_token',
		'ys_helcim_operation_uuid',
		'ys_helcim_initialized_at',
	);

	public function __construct(
		private YSHelcimOperationRepository $operations,
		private YSHelcimJsPurchaseRuntime $runtime,
		callable $transaction_loader,
		callable $credential_resolver,
		callable $provider_lookup,
		?callable $clock = null
	) {
		$this->transaction_loader   = $transaction_loader;
		$this->credential_resolver  = $credential_resolver;
		$this->provider_lookup      = $provider_lookup;
		$this->clock                = $clock ?? static fn (): int => time();
	}

	/**
	 * Reconcile one exact operation from an authenticated provider collection.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function recover( string $operation_uuid ) {
		$operation_uuid = strtolower( trim( $operation_uuid ) );
		if ( ! self::isUuid( $operation_uuid ) ) {
			return self::error( 'ys_helcim_hosted_recovery_invalid', 'The hosted payment operation identifier is invalid.' );
		}

		try {
			$row = $this->operations->findByUuidStrict( $operation_uuid );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::journalUnavailable();
		}
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( ! is_array( $row ) ) {
			return self::error( 'ys_helcim_hosted_recovery_missing', 'The hosted payment operation could not be found.' );
		}

		$loaded = $this->loadExactTransaction( $row );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		$transaction = $loaded['transaction'];
		$purchase    = $loaded['purchase'];
		$identity    = $purchase->identity();

		if ( ! $this->isRecoverableRow( $row, $purchase, $operation_uuid ) ) {
			return self::error( 'ys_helcim_hosted_recovery_conflict', 'The hosted payment operation is not safely recoverable.' );
		}

		$remote_status = (string) ( $row['remote_status'] ?? '' );
		if ( YSHelcimOperationState::REMOTE_SUCCEEDED === $remote_status ) {
			return $this->resumePersistedSuccess( $row, $transaction, $operation_uuid, $identity );
		}
		if ( YSHelcimOperationState::REMOTE_DECLINED === $remote_status ) {
			return self::result( $operation_uuid, 'declined', 'provider_declined' );
		}

		try {
			$api_token = ( $this->credential_resolver )( (string) $identity['payment_mode'] );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::credentialsUnavailable();
		}
		if ( is_wp_error( $api_token ) ) {
			return $api_token;
		}
		if ( ! is_string( $api_token ) || '' === trim( $api_token ) ) {
			return self::credentialsUnavailable();
		}

		try {
			$response = ( $this->provider_lookup )( $operation_uuid, $api_token );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::error( 'ys_helcim_hosted_recovery_lookup_failed', 'The hosted payment provider lookup failed.' );
		} finally {
			$api_token = '';
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$transactions = self::transactionCollection( $response );
		if ( null === $transactions ) {
			return self::ambiguous();
		}
		if ( array() === $transactions ) {
			return $this->handleEmptyLookup( $row, $transaction, $operation_uuid );
		}

		$candidates = array();
		foreach ( $transactions as $provider_transaction ) {
			if ( ! is_array( $provider_transaction ) ) {
				return self::ambiguous();
			}
			$invoice_number = strtolower( trim( (string) ( $provider_transaction['invoiceNumber'] ?? '' ) ) );
			if ( '' === $invoice_number || ! hash_equals( $operation_uuid, $invoice_number ) ) {
				return self::ambiguous();
			}
			if ( 'purchase' === strtolower( trim( (string) ( $provider_transaction['type'] ?? '' ) ) ) ) {
				$candidates[] = $provider_transaction;
			}
		}
		if ( 1 !== count( $candidates ) ) {
			return self::ambiguous();
		}

		$outcome = YSHelcimJsPurchaseResponseAdapter::toCoordinatorOutcome( $candidates[0], $identity );
		if (
			is_wp_error( $outcome ) ||
			! in_array( $outcome['outcome'] ?? null, array( 'succeeded', 'declined' ), true )
		) {
			return is_wp_error( $outcome ) ? $outcome : self::ambiguous();
		}
		if ( 'declined' === $outcome['outcome'] ) {
			if ( null === YSHelcimTransactionId::normalize( $candidates[0]['transactionId'] ?? null ) ) {
				return self::ambiguous();
			}
			$expired = $this->checkoutMaterialExpired( $row );
			if ( is_wp_error( $expired ) ) {
				return $expired;
			}
			if ( ! $expired ) {
				return self::result( $operation_uuid, 'pending', 'checkout_session_still_valid' );
			}
		}

		$outcome['operation_correlation'] = $operation_uuid;
		try {
			return $this->runtime->reconcileProviderProof( $transaction, $operation_uuid, $outcome );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::error( 'ys_helcim_hosted_recovery_apply_failed', 'The hosted payment proof could not be applied safely.' );
		}
	}

	/** @return array{transaction:OrderTransaction,purchase:YSHelcimPurchaseOperation}|\WP_Error */
	private function loadExactTransaction( array $row ) {
		try {
			$transaction = ( $this->transaction_loader )( (int) ( $row['transaction_id'] ?? 0 ) );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::error( 'ys_helcim_hosted_recovery_transaction_unavailable', 'The hosted payment transaction could not be loaded.' );
		}
		if ( ! $transaction instanceof OrderTransaction ) {
			return self::error( 'ys_helcim_hosted_recovery_transaction_unavailable', 'The hosted payment transaction could not be loaded.' );
		}
		if (
			(int) ( $transaction->id ?? 0 ) !== (int) ( $row['transaction_id'] ?? 0 ) ||
			'ys_helcim' !== (string) ( $transaction->payment_method ?? '' ) ||
			Status::TRANSACTION_TYPE_CHARGE !== (string) ( $transaction->transaction_type ?? '' )
		) {
			return self::error( 'ys_helcim_hosted_recovery_transaction_mismatch', 'The hosted payment transaction identity does not match.' );
		}

		$purchase = YSHelcimPurchaseOperation::fromTransaction(
			array(
				'gateway'          => 'ys_helcim',
				'order_id'         => (int) ( $transaction->order_id ?? 0 ),
				'transaction_id'   => (int) ( $transaction->id ?? 0 ),
				'transaction_uuid' => (string) ( $transaction->uuid ?? '' ),
				'amount'           => (int) ( $transaction->total ?? 0 ),
				'currency'         => (string) ( $transaction->currency ?? '' ),
				'payment_mode'     => (string) ( $transaction->payment_mode ?? '' ),
			)
		);
		return $purchase instanceof YSHelcimPurchaseOperation
			? array( 'transaction' => $transaction, 'purchase' => $purchase )
			: $purchase;
	}

	private function isRecoverableRow( array $row, YSHelcimPurchaseOperation $purchase, string $operation_uuid ): bool {
		$correlation = strtolower( trim( (string) ( $row['provider_correlation_id'] ?? '' ) ) );
		if (
			! $purchase->matchesIdentityRow( $row ) ||
			'ys_helcim' !== (string) ( $row['gateway'] ?? '' ) ||
			'purchase' !== (string) ( $row['operation_type'] ?? '' ) ||
			'' === $correlation ||
			! hash_equals( $operation_uuid, $correlation )
		) {
			return false;
		}

		$remote_status = (string) ( $row['remote_status'] ?? '' );
		$local_status  = (string) ( $row['local_status'] ?? '' );
		$active_scope  = (string) ( $row['active_scope_key'] ?? '' );
		if ( in_array( $remote_status, array( YSHelcimOperationState::REMOTE_PROCESSING, YSHelcimOperationState::REMOTE_INDETERMINATE ), true ) ) {
			return in_array( $local_status, array( YSHelcimOperationState::LOCAL_PENDING, YSHelcimOperationState::LOCAL_FAILED ), true )
				&& '' !== $active_scope;
		}
		if ( YSHelcimOperationState::REMOTE_SUCCEEDED === $remote_status ) {
			return null !== YSHelcimTransactionId::normalize( $row['vendor_transaction_id'] ?? null )
				&& in_array(
					$local_status,
					array(
						YSHelcimOperationState::LOCAL_PENDING,
						YSHelcimOperationState::LOCAL_FAILED,
						YSHelcimOperationState::LOCAL_APPLYING,
						YSHelcimOperationState::LOCAL_APPLIED,
					),
					true
				)
				&& ( YSHelcimOperationState::LOCAL_APPLIED === $local_status || '' !== $active_scope );
		}

		return YSHelcimOperationState::REMOTE_DECLINED === $remote_status
			&& YSHelcimOperationState::LOCAL_PENDING === $local_status
			&& '' === $active_scope;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function handleEmptyLookup( array $row, OrderTransaction $transaction, string $operation_uuid ) {
		$expired = $this->checkoutMaterialExpired( $row );
		if ( is_wp_error( $expired ) ) {
			return $expired;
		}
		if ( ! $expired ) {
			return self::result( $operation_uuid, 'pending', 'checkout_session_still_valid' );
		}

		$purged = $this->purgeTerminalMeta( $transaction );
		if ( is_wp_error( $purged ) ) {
			return $purged;
		}

		$current_status = (string) ( $row['remote_status'] ?? '' );
		$observed = $this->operations->recordHostedEmptyObservation( $operation_uuid, $current_status );
		if ( is_wp_error( $observed ) ) {
			return $observed;
		}
		if ( true !== $observed || ! $this->proveRemoteState( $operation_uuid, YSHelcimOperationState::REMOTE_INDETERMINATE, true ) ) {
			return self::journalUnavailable();
		}

		return self::result( $operation_uuid, 'pending', 'empty_observation_recorded' );
	}

	/** Resume a durable exact success without credentials or another provider request. */
	private function resumePersistedSuccess(
		array $row,
		OrderTransaction $transaction,
		string $operation_uuid,
		array $identity
	) {
		$provider_id = YSHelcimTransactionId::normalize( $row['vendor_transaction_id'] ?? null );
		if ( null === $provider_id ) {
			return self::error( 'ys_helcim_hosted_recovery_conflict', 'The hosted payment operation is not safely recoverable.' );
		}
		$proof = array(
			'outcome'               => 'succeeded',
			'transaction'           => array(
				'status'        => 'APPROVED',
				'type'          => 'purchase',
				'transactionId' => $provider_id,
				'amount'        => number_format( (int) $identity['amount'] / 100, 2, '.', '' ),
				'currency'      => strtoupper( (string) $identity['currency'] ),
			),
			'operation_correlation' => $operation_uuid,
		);

		try {
			return $this->runtime->reconcileProviderProof( $transaction, $operation_uuid, $proof );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::error( 'ys_helcim_hosted_recovery_apply_failed', 'The hosted payment proof could not be applied safely.' );
		}
	}

	/** @return bool|\WP_Error */
	private function checkoutMaterialExpired( array $row ) {
		$created_at = self::sqlTimestamp( $row['created_at'] ?? null );
		try {
			$now = ( $this->clock )();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::error( 'ys_helcim_hosted_recovery_clock_invalid', 'The hosted payment recovery clock is unavailable.' );
		}
		if ( ! is_int( $now ) || $now <= 0 || null === $created_at ) {
			return self::error( 'ys_helcim_hosted_recovery_clock_invalid', 'The hosted payment recovery clock is unavailable.' );
		}

		return $created_at <= $now - self::EXPIRED_MATERIAL_CLEANUP_SECONDS;
	}

	/** Return the next bounded retry delay; null means automatic recovery pauses. */
	public static function retryDelayAfterAttempt( int $completed_attempts ): ?int {
		return self::RETRY_DELAYS[ $completed_attempts - 1 ] ?? null;
	}

	/** @return true|\WP_Error */
	private function purgeTerminalMeta( OrderTransaction $transaction ) {
		$meta = is_array( $transaction->meta ?? null ) ? $transaction->meta : array();
		foreach ( $this->terminal_meta_keys as $key ) {
			unset( $meta[ $key ] );
		}
		try {
			$transaction->fill( array( 'meta' => $meta ) );
			$saved = $transaction->save();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$saved = false;
		}
		if ( false === $saved ) {
			return self::error( 'ys_helcim_hosted_recovery_cleanup_failed', 'The expired hosted payment metadata could not be removed safely.' );
		}

		try {
			$fresh = ( $this->transaction_loader )( (int) $transaction->id );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$fresh = null;
		}
		if ( ! $fresh instanceof OrderTransaction || (int) $fresh->id !== (int) $transaction->id ) {
			return self::error( 'ys_helcim_hosted_recovery_cleanup_failed', 'The expired hosted payment metadata could not be verified.' );
		}
		$fresh_meta = is_array( $fresh->meta ?? null ) ? $fresh->meta : array();
		foreach ( $this->terminal_meta_keys as $key ) {
			if ( array_key_exists( $key, $fresh_meta ) ) {
				return self::error( 'ys_helcim_hosted_recovery_cleanup_failed', 'The expired hosted payment metadata could not be verified.' );
			}
		}

		return true;
	}

	private function proveRemoteState( string $operation_uuid, string $remote_status, bool $scope_active ): bool {
		try {
			$current = $this->operations->findByUuidStrict( $operation_uuid );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return false;
		}
		if ( ! is_array( $current ) || $remote_status !== (string) ( $current['remote_status'] ?? '' ) ) {
			return false;
		}

		return $scope_active
			? '' !== (string) ( $current['active_scope_key'] ?? '' )
			: null === ( $current['active_scope_key'] ?? null );
	}

	/** @return array<int,array<string,mixed>>|null */
	private static function transactionCollection( mixed $response ): ?array {
		return is_array( $response ) && array_is_list( $response ) ? $response : null;
	}

	private static function sqlTimestamp( mixed $value ): ?int {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $value ) ) {
			return null;
		}
		$timestamp = strtotime( $value . ' UTC' );
		return false === $timestamp ? null : $timestamp;
	}

	private static function isUuid( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match(
			'/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
			$value
		);
	}

	/** @return array{operation_uuid:string,status:string,reason:string} */
	private static function result( string $operation_uuid, string $status, string $reason ): array {
		return compact( 'operation_uuid', 'status', 'reason' );
	}

	private static function ambiguous(): \WP_Error {
		return self::error( 'ys_helcim_hosted_recovery_ambiguous', 'Helcim did not return one exact hosted purchase result.' );
	}

	private static function credentialsUnavailable(): \WP_Error {
		return self::error( 'ys_helcim_hosted_recovery_credentials_unavailable', 'The credential for the original hosted payment mode is unavailable.' );
	}

	private static function journalUnavailable(): \WP_Error {
		return self::error( 'ys_helcim_journal_unavailable', 'The hosted payment recovery journal is unavailable.' );
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'ys-helcim-via-fluentcart' ) );
	}
}
