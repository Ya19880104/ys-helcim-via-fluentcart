<?php
/**
 * Durable two-phase initialization for the hosted HelcimPay checkout.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\HelcimPay;

use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationScope;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationState;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimPurchaseOperation;
use YangSheep\Helcim\FluentCart\Support\YSHelcimApiClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists and claims one hosted purchase before exposing a provider session.
 */
final class YSHelcimPayInitializationCoordinator {
	private const CONFIRM_TOKEN_TTL_SECONDS = 900;

	/** @var callable */
	private $initialize;

	/** @var callable */
	private $uuid_factory;

	/** @var callable */
	private $confirm_token_factory;

	/** @var callable */
	private $clock;

	public function __construct(
		private YSHelcimOperationRepository $operations,
		callable $initialize,
		?callable $uuid_factory = null,
		?callable $confirm_token_factory = null,
		?callable $clock = null
	) {
		$this->initialize            = $initialize;
		$this->uuid_factory          = $uuid_factory ?? static fn (): string => wp_generate_uuid4();
		$this->confirm_token_factory = $confirm_token_factory ?? static function (): string {
			return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		};
		$this->clock                 = $clock ?? static fn (): int => time();
	}

	/**
	 * @param array<string, mixed> $transaction Server-loaded FluentCart identity.
	 * @return array{operation_uuid:string,confirm_token:string,checkout_token:string,secret_token:string}|\WP_Error
	 */
	public function begin( array $transaction ) {
		$operation = YSHelcimPurchaseOperation::fromTransaction( $transaction );
		if ( is_wp_error( $operation ) ) {
			return $operation;
		}
		if ( 'ys_helcim' !== (string) $operation->identity()['gateway'] ) {
			return self::invalidOperation();
		}

		try {
			$operation_uuid = strtolower( trim( (string) ( $this->uuid_factory )() ) );
			$confirm_token  = (string) ( $this->confirm_token_factory )();
			$now            = ( $this->clock )();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::invalidOperation();
		}

		if (
			1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $operation_uuid ) ||
			1 !== preg_match( '/\A[A-Za-z0-9_-]{32,128}\z/', $confirm_token ) ||
			! is_int( $now ) ||
			$now <= 0 ||
			$now > PHP_INT_MAX - self::CONFIRM_TOKEN_TTL_SECONDS
		) {
			return self::invalidOperation();
		}

		try {
			$expected_active_scope = YSHelcimOperationScope::fromBusinessKey( $operation->scopeKey() );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::invalidOperation();
		}
		$confirm_token_hash       = hash( 'sha256', $confirm_token );
		$confirm_token_expires_at = gmdate( 'Y-m-d H:i:s', $now + self::CONFIRM_TOKEN_TTL_SECONDS );
		$now_sql                  = gmdate( 'Y-m-d H:i:s', $now );
		$record                   = $operation->repositoryRecord( $operation_uuid, $confirm_token_hash );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		$record['provider_correlation_id']  = $operation_uuid;
		$record['confirm_token_hash']       = $confirm_token_hash;
		$record['confirm_token_expires_at'] = $confirm_token_expires_at;

		try {
			$created = $this->operations->create( $record );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::journalUnavailable();
		}
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		try {
			$claimed = $this->operations->claimRemoteProcessing( $operation_uuid );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::journalUnavailable();
		}
		if ( is_wp_error( $claimed ) ) {
			return $claimed;
		}
		if ( true !== $claimed ) {
			return self::operationConflict();
		}

		try {
			$processing = $this->operations->findByUuidStrict( $operation_uuid );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::journalUnavailable();
		}
		if ( is_wp_error( $processing ) ) {
			return $processing;
		}
		if (
			! is_array( $processing ) ||
			! $operation->matchesIdentityRow( $processing ) ||
			YSHelcimOperationState::REMOTE_PROCESSING !== (string) ( $processing['remote_status'] ?? '' ) ||
			! hash_equals( $operation_uuid, (string) ( $processing['provider_correlation_id'] ?? '' ) ) ||
			! hash_equals( $confirm_token_hash, (string) ( $processing['confirm_token_hash'] ?? '' ) ) ||
			! hash_equals( $expected_active_scope, (string) ( $processing['active_scope_key'] ?? '' ) ) ||
			'' === (string) ( $processing['confirm_token_expires_at'] ?? '' ) ||
			! hash_equals( $confirm_token_expires_at, (string) $processing['confirm_token_expires_at'] ) ||
			(string) $processing['confirm_token_expires_at'] <= $now_sql
		) {
			return self::operationConflict();
		}

		try {
			$response = ( $this->initialize )( $operation->identity(), $operation_uuid );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return $this->failInitialization( $processing, $expected_active_scope );
		}
		if ( is_wp_error( $response ) ) {
			return self::isDefinitiveNeverSent( $response )
				? $this->failNeverSentInitialization( $processing, $response )
				: $this->failInitialization( $processing, $expected_active_scope );
		}
		if (
			! is_array( $response ) ||
			! self::hasExactKeys( $response, array( 'checkoutToken', 'secretToken' ) ) ||
			! self::isProviderToken( $response['checkoutToken'] ?? null ) ||
			! self::isProviderToken( $response['secretToken'] ?? null )
		) {
			return $this->failInitialization( $processing, $expected_active_scope );
		}

		return array(
			'operation_uuid' => $operation_uuid,
			'confirm_token'  => $confirm_token,
			'checkout_token' => (string) $response['checkoutToken'],
			'secret_token'   => (string) $response['secretToken'],
		);
	}

	private function failNeverSentInitialization( array $row, \WP_Error $provider_error ): \WP_Error {
		try {
			$transitioned = $this->operations->transitionRemote(
				(string) $row['operation_uuid'],
				YSHelcimOperationState::REMOTE_PROCESSING,
				YSHelcimOperationState::REMOTE_FAILED,
				array(
					'error_code'    => 'helcim_pay_initialize_never_sent',
					'error_message' => 'The hosted checkout request was rejected locally before it was sent.',
				)
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::journalOutcomeUnpersisted();
		}
		if ( is_wp_error( $transitioned ) || true !== $transitioned ) {
			return self::journalOutcomeUnpersisted();
		}

		return $provider_error;
	}

	private function failInitialization( array $row, string $expected_active_scope ): \WP_Error {
		try {
			$transitioned = $this->operations->transitionRemote(
				(string) $row['operation_uuid'],
				YSHelcimOperationState::REMOTE_PROCESSING,
				YSHelcimOperationState::REMOTE_INDETERMINATE,
				array(
					'error_code'    => 'helcim_pay_initialize_unresolved',
					'error_message' => 'The checkout session was not exposed, but the failed initialization remains locked until its journal outcome is reconciled.',
				)
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::journalOutcomeUnpersisted();
		}
		if ( is_wp_error( $transitioned ) || true !== $transitioned ) {
			return self::journalOutcomeUnpersisted();
		}

		try {
			$current = $this->operations->findByUuidStrict( (string) $row['operation_uuid'] );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::initializeIndeterminate();
		}
		if ( is_wp_error( $current ) ) {
			return self::initializeIndeterminate();
		}
		if (
			! is_array( $current ) ||
			YSHelcimOperationState::REMOTE_INDETERMINATE !== (string) ( $current['remote_status'] ?? '' ) ||
			! hash_equals( $expected_active_scope, (string) ( $current['active_scope_key'] ?? '' ) ) ||
			null !== ( $current['vendor_transaction_id'] ?? null )
		) {
			return self::journalOutcomeUnpersisted();
		}

		return self::initializeIndeterminate();
	}

	/** @param array<string, mixed> $value @param string[] $keys */
	private static function hasExactKeys( array $value, array $keys ): bool {
		return array() === array_diff( $keys, array_keys( $value ) )
			&& array() === array_diff( array_keys( $value ), $keys );
	}

	private static function isProviderToken( mixed $value ): bool {
		return is_string( $value )
			&& '' !== trim( $value )
			&& strlen( $value ) <= 4096
			&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $value );
	}

	private static function isDefinitiveNeverSent( \WP_Error $error ): bool {
		$data = $error->get_error_data();

		return is_array( $data )
			&& self::hasExactKeys( $data, array( 'kind', 'indeterminate', 'mutation_disposition' ) )
			&& 'local' === $data['kind']
			&& false === $data['indeterminate']
			&& YSHelcimApiClient::MUTATION_NEVER_SENT === $data['mutation_disposition'];
	}

	private static function invalidOperation(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_invalid_operation',
			__( 'A durable hosted payment operation could not be created.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function operationConflict(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_operation_conflict',
			__( 'The hosted payment operation changed while it was being claimed.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function journalUnavailable(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_journal_unavailable',
			__( 'The payment safety journal is unavailable. No checkout session was created.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function initializeIndeterminate(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_initialize_indeterminate',
			__( 'The hosted payment session could not be initialized and remains locked for safe reconciliation.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function journalOutcomeUnpersisted(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_journal_outcome_unpersisted',
			__( 'The hosted payment session failed before it was exposed, but the safety journal could not record that result.', 'ys-helcim-via-fluentcart' )
		);
	}
}
