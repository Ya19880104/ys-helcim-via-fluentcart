<?php
/**
 * Atomic persistence for Helcim operation journal rows.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Operations;

use YangSheep\Helcim\FluentCart\Security\YSHelcimSensitiveEnvelope;
use YangSheep\Helcim\FluentCart\Support\YSHelcimSanitizer;
use YangSheep\Helcim\FluentCart\Support\YSHelcimTransactionId;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wpdb-backed operation repository.
 */
final class YSHelcimOperationRepository {

	/** A provider claim must start well inside this lease. */
	private const CREATED_CLAIM_LEASE_SECONDS = 300;

	/** A refund provider call cannot legitimately outlive this safety lease. */
	private const REFUND_PROCESSING_LEASE_SECONDS = 300;

	/** @var object */
	private $database;

	/** @var callable */
	private $clock;

	private string $table;

	/**
	 * @param object|null   $database wpdb-compatible database object.
	 * @param callable|null $clock    UTC SQL timestamp provider.
	 */
	public function __construct( ?object $database = null, ?callable $clock = null ) {
		if ( null === $database ) {
			global $wpdb;
			$database = $wpdb;
		}

		$this->database = $database;
		$this->table    = YSHelcimOperationSchema::tableName( $database );
		$this->clock    = $clock ?? static fn (): string => gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Insert one claimed operation row.
	 *
	 * @param array $operation Immutable operation identity and business fields.
	 * @return array|\WP_Error
	 */
	public function create( array $operation ) {
		$expired = $this->expireStaleCreatedScope( (string) ( $operation['scope_key'] ?? '' ) );
		if ( is_wp_error( $expired ) ) {
			return $expired;
		}

		$purged = $this->purgeExpiredMaterial( 100 );
		if ( is_wp_error( $purged ) ) {
			return $purged;
		}

		return $this->createInternal( $operation, false );
	}

	/**
	 * @param array $operation    Immutable operation identity and business fields.
	 * @param bool  $allow_reverse Reverse rows may only be inserted by atomic handoff.
	 * @return array|\WP_Error
	 */
	private function createInternal( array $operation, bool $allow_reverse ) {
		$required = array(
			'operation_uuid',
			'idempotency_key',
			'scope_key',
			'operation_type',
			'gateway',
			'order_id',
			'transaction_id',
			'transaction_uuid',
			'amount',
			'currency',
			'payment_mode',
			'request_fingerprint',
		);

		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $operation ) || '' === (string) $operation[ $field ] ) {
				return new \WP_Error(
					'ys_helcim_invalid_operation',
					__( 'The payment operation is missing required identity data.', 'ys-helcim-via-fluentcart' )
				);
			}
		}

		$now = ( $this->clock )();
		try {
			$scope_key = YSHelcimOperationScope::fromBusinessKey( (string) $operation['scope_key'] );
			$expected_key = YSHelcimIdempotency::generate(
				(string) $operation['operation_type'],
				(string) $operation['transaction_uuid'],
				(int) $operation['amount'],
				(string) $operation['payment_mode'],
				(string) $operation['operation_uuid']
			);
		} catch ( \InvalidArgumentException $exception ) {
			unset( $exception );
			return self::invalidOperation();
		}

		if ( ! hash_equals( $expected_key, (string) $operation['idempotency_key'] ) ) {
			return self::invalidOperation();
		}

		$operation_type = strtolower( trim( (string) $operation['operation_type'] ) );
		if ( 'reverse' === $operation_type && ! $allow_reverse ) {
			return self::invalidOperation();
		}

		$local_payload      = self::nullableString( $operation['local_payload'] ?? null );
		$local_payload_hash = self::nullableString( $operation['local_payload_hash'] ?? null );
		$source_vendor_id   = YSHelcimTransactionId::normalize( $operation['source_vendor_transaction_id'] ?? null );
		if ( in_array( $operation_type, array( 'refund', 'reverse' ), true ) ) {
			$decoded_payload = null !== $local_payload ? json_decode( $local_payload, true ) : null;
			if (
				! is_array( $decoded_payload ) ||
				null === $source_vendor_id ||
				null === $local_payload_hash ||
				1 !== preg_match( '/\A[a-f0-9]{64}\z/', $local_payload_hash ) ||
				! hash_equals( $local_payload_hash, hash( 'sha256', $local_payload ) )
			) {
				return self::invalidOperation();
			}
		} elseif ( null !== $local_payload || null !== $local_payload_hash || null !== $source_vendor_id ) {
			return self::invalidOperation();
		}

		if (
			! in_array( (string) $operation['gateway'], array( 'ys_helcim', 'ys_helcim_js' ), true ) ||
			(int) $operation['order_id'] <= 0 ||
			(int) $operation['transaction_id'] <= 0 ||
			1 !== preg_match( '/\A[A-Z]{3}\z/', strtoupper( (string) $operation['currency'] ) ) ||
			1 !== preg_match( '/\A[a-f0-9]{64}\z/', (string) $operation['request_fingerprint'] )
		) {
			return self::invalidOperation();
		}

		$provider_correlation = self::nullableString( $operation['provider_correlation_id'] ?? null );
		if ( null !== $provider_correlation && 1 !== preg_match( '/\A[A-Za-z0-9._:-]{1,64}\z/', $provider_correlation ) ) {
			return self::invalidOperation();
		}

		$encrypted_material = self::nullableString( $operation['encrypted_material'] ?? null );
		$material_expiry    = self::nullableString( $operation['material_expires_at'] ?? null );
		if (
			( null === $encrypted_material ) !== ( null === $material_expiry ) ||
			( null !== $encrypted_material && ( ! YSHelcimSensitiveEnvelope::isValid( $encrypted_material ) || ! self::isFutureSqlDate( $material_expiry, $now ) ) )
		) {
			return self::invalidOperation();
		}

		$confirm_token_hash   = self::nullableString( $operation['confirm_token_hash'] ?? null );
		$confirm_token_expiry = self::nullableString( $operation['confirm_token_expires_at'] ?? null );
		if (
			( null === $confirm_token_hash ) !== ( null === $confirm_token_expiry ) ||
			( null !== $confirm_token_hash && ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $confirm_token_hash ) || ! self::isFutureSqlDate( $confirm_token_expiry, $now ) ) )
		) {
			return self::invalidOperation();
		}

		$row = array(
			'operation_uuid'           => strtolower( (string) $operation['operation_uuid'] ),
			'idempotency_key'          => (string) $operation['idempotency_key'],
			'scope_key'                => $scope_key,
			'active_scope_key'         => $scope_key,
			'operation_type'           => $operation_type,
			'gateway'                  => (string) $operation['gateway'],
			'order_id'                 => (int) $operation['order_id'],
			'transaction_id'           => (int) $operation['transaction_id'],
			'transaction_uuid'         => (string) $operation['transaction_uuid'],
			'parent_operation_uuid'    => self::nullableString( $operation['parent_operation_uuid'] ?? null ),
			'amount'                   => (int) $operation['amount'],
			'currency'                 => strtoupper( (string) $operation['currency'] ),
			'payment_mode'             => strtolower( (string) $operation['payment_mode'] ),
			'remote_status'            => YSHelcimOperationState::REMOTE_CREATED,
			'local_status'             => YSHelcimOperationState::LOCAL_PENDING,
			'source_vendor_transaction_id' => $source_vendor_id,
			'vendor_transaction_id'    => null,
			'provider_correlation_id'  => $provider_correlation,
			'request_fingerprint'      => (string) $operation['request_fingerprint'],
			'remote_error_code'        => null,
			'remote_error_message'     => null,
			'local_error_code'         => null,
			'local_error_message'      => null,
			'local_payload'            => $local_payload,
			'local_payload_hash'       => $local_payload_hash,
			'local_transaction_id'     => null,
			'local_claimed_at'         => null,
			'local_recorded_at'        => null,
			'local_applied_at'         => null,
			'encrypted_material'       => $encrypted_material,
			'material_expires_at'      => $material_expiry,
			'confirm_token_hash'       => $confirm_token_hash,
			'confirm_token_expires_at' => $confirm_token_expiry,
			'recovery_attempt_count'   => 0,
			'next_recovery_at'         => null,
			'created_at'               => $now,
			'updated_at'               => $now,
			'resolved_at'              => null,
		);

		$inserted = $this->database->insert( $this->table, $row );
		if ( false === $inserted ) {
			$database_error = (string) ( $this->database->last_error ?? '' );
			if ( false === stripos( $database_error, 'duplicate' ) ) {
				return new \WP_Error(
					'ys_helcim_journal_unavailable',
					__( 'The payment safety journal is unavailable. No provider request was sent.', 'ys-helcim-via-fluentcart' )
				);
			}

			if ( null !== $this->findActiveByScope( $scope_key ) ) {
				return new \WP_Error(
					'ys_helcim_scope_busy',
					__( 'Another payment operation is already being reconciled.', 'ys-helcim-via-fluentcart' )
				);
			}

			return new \WP_Error(
				'ys_helcim_operation_conflict',
				__( 'This payment operation already exists.', 'ys-helcim-via-fluentcart' )
			);
		}

		$stored = $this->findByUuid( $row['operation_uuid'] );
		if ( null === $stored ) {
			return new \WP_Error(
				'ys_helcim_journal_unavailable',
				__( 'The payment operation could not be read back safely. No provider request was sent.', 'ys-helcim-via-fluentcart' )
			);
		}

		return $stored;
	}

	public function findByUuid( string $operation_uuid ): ?array {
		$result = $this->findByUuidStrict( $operation_uuid );
		return is_array( $result ) ? $result : null;
	}

	/**
	 * Error-aware read for recovery boundaries that must retry on database loss.
	 *
	 * @return array|null|\WP_Error
	 */
	public function findByUuidStrict( string $operation_uuid ) {
		if ( property_exists( $this->database, 'last_error' ) ) {
			$this->database->last_error = '';
		}
		$query = $this->database->prepare(
			"SELECT * FROM {$this->table} WHERE operation_uuid = %s LIMIT 1",
			strtolower( $operation_uuid )
		);

		try {
			$row = $this->database->get_row( $query, ARRAY_A );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::journalUnavailable();
		}
		if ( '' !== (string) ( $this->database->last_error ?? '' ) ) {
			return self::journalUnavailable();
		}

		return null === $row || is_array( $row ) ? $row : self::journalUnavailable();
	}

	public function findActiveByScope( string $scope_key ): ?array {
		try {
			$scope_key = YSHelcimOperationScope::fromBusinessKey( $scope_key );
		} catch ( \InvalidArgumentException $exception ) {
			unset( $exception );
			return null;
		}

		$query = $this->database->prepare(
			"SELECT * FROM {$this->table} WHERE active_scope_key = %s LIMIT 1",
			$scope_key
		);

		$row = $this->database->get_row( $query, ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find every durable attempt for one server-owned FluentCart transaction.
	 *
	 * Unlike the active-scope lookup, this also finds declined and locally
	 * applied operations after their concurrency lock has been released. The
	 * coordinator uses their attempt fingerprints to distinguish a delayed
	 * request from a server-issued successor after a definitive decline.
	 *
	 * @return array|null|\WP_Error
	 */
	public function findPurchasesByIdentity( int $transaction_id ) {
		if ( $transaction_id <= 0 ) {
			return self::invalidOperation();
		}

		if ( property_exists( $this->database, 'last_error' ) ) {
			$this->database->last_error = '';
		}
		$query = $this->database->prepare(
			"SELECT * FROM {$this->table}
			WHERE operation_type = %s
			AND transaction_id = %d
			ORDER BY id ASC",
			'purchase',
			$transaction_id
		);
		$rows = $this->database->get_results( $query, ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) ( $this->database->last_error ?? '' ) ) {
			return self::journalUnavailable();
		}

		return array_values( $rows );
	}

	/**
	 * Find due active hosted purchases that require provider-query recovery or
	 * local completion from an already persisted success proof.
	 *
	 * The caller still revalidates every row and never resends a purchase. This
	 * The durable due time and attempt budget prevent abandoned checkouts from
	 * being queried forever. Persisted successes are prioritized because they do
	 * not require another provider request.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function findHostedPurchasesNeedingRecovery(
		string $created_before,
		string $due_before,
		string $local_claimed_before,
		int $max_attempts,
		int $limit = 20
	) {
		if (
			1 !== preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $created_before ) ||
			1 !== preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $due_before ) ||
			1 !== preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $local_claimed_before ) ||
			$max_attempts < 1 ||
			$max_attempts > 100 ||
			$limit < 1 ||
			$limit > 100
		) {
			return self::invalidOperation();
		}
		if ( property_exists( $this->database, 'last_error' ) ) {
			$this->database->last_error = '';
		}

		$query = $this->database->prepare(
			"/* ys_helcim_hosted_purchase_recovery_scan */
			SELECT * FROM {$this->table}
			WHERE operation_type = 'purchase'
			AND gateway = 'ys_helcim'
			AND remote_status IN ('processing', 'indeterminate', 'succeeded')
			AND (
				local_status IN ('pending', 'failed')
				OR (
					remote_status = 'succeeded'
					AND local_status = 'applying'
					AND local_claimed_at IS NOT NULL
					AND local_claimed_at <= %s
				)
			)
			AND active_scope_key IS NOT NULL
			AND (remote_status = 'succeeded' OR created_at <= %s)
			AND recovery_attempt_count < %d
			AND (next_recovery_at IS NULL OR next_recovery_at <= %s)
			ORDER BY CASE WHEN remote_status = 'succeeded' THEN 0 ELSE 1 END ASC,
				COALESCE(next_recovery_at, created_at) ASC,
				id ASC
			LIMIT %d",
			$local_claimed_before,
			$created_before,
			$max_attempts,
			$due_before,
			$limit
		);
		try {
			$rows = $this->database->get_results( $query, ARRAY_A );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::journalUnavailable();
		}
		if ( ! is_array( $rows ) || '' !== (string) ( $this->database->last_error ?? '' ) ) {
			return self::journalUnavailable();
		}

		return array_values( $rows );
	}

	/**
	 * Atomically lease one due hosted recovery attempt.
	 *
	 * The attempt counter is consumed when the lease is acquired so a crashed
	 * worker cannot create an unbounded retry loop. The lease timestamp doubles
	 * as the earliest crash-recovery time for another worker.
	 *
	 * @return bool|\WP_Error
	 */
	public function claimHostedRecovery(
		string $operation_uuid,
		string $due_before,
		string $local_claimed_before,
		string $lease_until,
		int $max_attempts
	) {
		$operation_uuid = strtolower( trim( $operation_uuid ) );
		if (
			1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $operation_uuid ) ||
			1 !== preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $due_before ) ||
			1 !== preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $local_claimed_before ) ||
			1 !== preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $lease_until ) ||
			$lease_until <= $due_before ||
			$max_attempts < 1 ||
			$max_attempts > 100
		) {
			return self::invalidOperation();
		}

		$current = $this->findByUuidStrict( $operation_uuid );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$remote_status = is_array( $current ) ? (string) ( $current['remote_status'] ?? '' ) : '';
		$local_status  = is_array( $current ) ? (string) ( $current['local_status'] ?? '' ) : '';
		$active_scope  = is_array( $current ) ? (string) ( $current['active_scope_key'] ?? '' ) : '';
		$attempt_count = is_array( $current ) ? (int) ( $current['recovery_attempt_count'] ?? 0 ) : 0;
		$next_due      = is_array( $current ) ? self::nullableString( $current['next_recovery_at'] ?? null ) : null;
		$local_claimed = is_array( $current ) ? self::nullableString( $current['local_claimed_at'] ?? null ) : null;
		if (
			! is_array( $current ) ||
			'purchase' !== (string) ( $current['operation_type'] ?? '' ) ||
			'ys_helcim' !== (string) ( $current['gateway'] ?? '' ) ||
			! in_array( $remote_status, array( 'processing', 'indeterminate', 'succeeded' ), true ) ||
			! in_array( $local_status, array( 'pending', 'failed', 'applying' ), true ) ||
			( 'applying' === $local_status && ( 'succeeded' !== $remote_status || null === $local_claimed || $local_claimed > $local_claimed_before ) ) ||
			'' === $active_scope ||
			$attempt_count >= $max_attempts ||
			( null !== $next_due && $next_due > $due_before )
		) {
			return false;
		}

		$claim_where = array(
			'operation_uuid'         => $operation_uuid,
			'remote_status'          => $remote_status,
			'local_status'           => $local_status,
			'active_scope_key'       => $active_scope,
			'recovery_attempt_count' => $attempt_count,
			'next_recovery_at'        => $next_due,
		);
		if ( 'applying' === $local_status ) {
			$claim_where['local_claimed_at'] = $local_claimed;
		}

		try {
			$updated = $this->database->update(
				$this->table,
				array(
					'recovery_attempt_count' => $attempt_count + 1,
					'next_recovery_at'       => $lease_until,
					'updated_at'             => ( $this->clock )(),
				),
				$claim_where
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::journalUnavailable();
		}
		if ( false === $updated ) {
			return self::journalUnavailable();
		}

		return 1 === $updated;
	}

	/**
	 * Lease one administrator-requested attempt without reopening auto retries.
	 *
	 * @return bool|\WP_Error
	 */
	public function claimPausedHostedRecovery(
		string $operation_uuid,
		string $due_before,
		string $local_claimed_before,
		string $lease_until,
		int $max_attempts
	) {
		$operation_uuid = strtolower( trim( $operation_uuid ) );
		if (
			1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $operation_uuid ) ||
			1 !== preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $due_before ) ||
			1 !== preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $local_claimed_before ) ||
			1 !== preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $lease_until ) ||
			$lease_until <= $due_before ||
			$max_attempts < 1 ||
			$max_attempts > 100
		) {
			return self::invalidOperation();
		}

		$current = $this->findByUuidStrict( $operation_uuid );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$remote_status = is_array( $current ) ? (string) ( $current['remote_status'] ?? '' ) : '';
		$local_status  = is_array( $current ) ? (string) ( $current['local_status'] ?? '' ) : '';
		$active_scope  = is_array( $current ) ? (string) ( $current['active_scope_key'] ?? '' ) : '';
		$attempt_count = is_array( $current ) ? (int) ( $current['recovery_attempt_count'] ?? 0 ) : 0;
		$next_due      = is_array( $current ) ? self::nullableString( $current['next_recovery_at'] ?? null ) : null;
		$local_claimed = is_array( $current ) ? self::nullableString( $current['local_claimed_at'] ?? null ) : null;
		if (
			! is_array( $current ) ||
			'purchase' !== (string) ( $current['operation_type'] ?? '' ) ||
			'ys_helcim' !== (string) ( $current['gateway'] ?? '' ) ||
			! in_array( $remote_status, array( 'processing', 'indeterminate', 'succeeded' ), true ) ||
			! in_array( $local_status, array( 'pending', 'failed', 'applying' ), true ) ||
			( 'applying' === $local_status && ( 'succeeded' !== $remote_status || null === $local_claimed || $local_claimed > $local_claimed_before ) ) ||
			'' === $active_scope ||
			$attempt_count < $max_attempts ||
			( null !== $next_due && $next_due > $due_before )
		) {
			return false;
		}

		$claim_where = array(
			'operation_uuid'         => $operation_uuid,
			'remote_status'          => $remote_status,
			'local_status'           => $local_status,
			'active_scope_key'       => $active_scope,
			'recovery_attempt_count' => $attempt_count,
			'next_recovery_at'        => $next_due,
		);
		if ( 'applying' === $local_status ) {
			$claim_where['local_claimed_at'] = $local_claimed;
		}

		try {
			$updated = $this->database->update(
				$this->table,
				array(
					'next_recovery_at' => $lease_until,
					'updated_at'       => ( $this->clock )(),
				),
				$claim_where
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::journalUnavailable();
		}
		if ( false === $updated ) {
			return self::journalUnavailable();
		}

		return 1 === $updated;
	}

	/** Persist the next due time and sanitized attention reason for one lease. */
	public function deferHostedRecovery(
		string $operation_uuid,
		int $expected_attempt_count,
		string $expected_lease_until,
		?string $next_recovery_at,
		string $error_code,
		string $error_message
	) {
		$operation_uuid = strtolower( trim( $operation_uuid ) );
		$error_code     = substr( sanitize_text_field( $error_code ), 0, 100 );
		if (
			1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $operation_uuid ) ||
			$expected_attempt_count < 1 ||
			1 !== preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $expected_lease_until ) ||
			'' === $error_code ||
			( null !== $next_recovery_at && 1 !== preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $next_recovery_at ) )
		) {
			return self::invalidOperation();
		}

		$current = $this->findByUuidStrict( $operation_uuid );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$remote_status = is_array( $current ) ? (string) ( $current['remote_status'] ?? '' ) : '';
		$local_status  = is_array( $current ) ? (string) ( $current['local_status'] ?? '' ) : '';
		$active_scope  = is_array( $current ) ? (string) ( $current['active_scope_key'] ?? '' ) : '';
		if (
			! is_array( $current ) ||
			'purchase' !== (string) ( $current['operation_type'] ?? '' ) ||
			'ys_helcim' !== (string) ( $current['gateway'] ?? '' ) ||
			! in_array( $remote_status, array( 'processing', 'indeterminate', 'succeeded' ), true ) ||
			! in_array( $local_status, array( 'pending', 'failed', 'applying' ), true ) ||
			'' === $active_scope ||
			$expected_attempt_count !== (int) ( $current['recovery_attempt_count'] ?? 0 )
			|| $expected_lease_until !== (string) ( $current['next_recovery_at'] ?? '' )
		) {
			return false;
		}

		$data = array(
			'next_recovery_at' => $next_recovery_at,
			'updated_at'       => ( $this->clock )(),
		);
		if ( YSHelcimOperationState::REMOTE_SUCCEEDED === $remote_status ) {
			$data['local_error_code']    = $error_code;
			$data['local_error_message'] = YSHelcimSanitizer::errorText( $error_message );
		} else {
			$data['remote_error_code']    = $error_code;
			$data['remote_error_message'] = YSHelcimSanitizer::errorText( $error_message );
		}

		try {
			$updated = $this->database->update(
				$this->table,
				$data,
				array(
					'operation_uuid'         => $operation_uuid,
					'remote_status'          => $remote_status,
					'local_status'           => $local_status,
					'active_scope_key'       => $active_scope,
					'recovery_attempt_count' => $expected_attempt_count,
					'next_recovery_at'        => $expected_lease_until,
				)
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::journalUnavailable();
		}
		if ( false === $updated ) {
			return self::journalUnavailable();
		}

		return 1 === $updated;
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function findHostedPurchasesNeedingAttention( int $limit = 10, int $max_attempts = 7 ) {
		if ( $limit < 1 || $limit > 100 || $max_attempts < 1 || $max_attempts > 100 ) {
			return self::invalidOperation();
		}
		if ( property_exists( $this->database, 'last_error' ) ) {
			$this->database->last_error = '';
		}
		$query = $this->database->prepare(
			"/* ys_helcim_hosted_purchase_attention_scan */
			SELECT * FROM {$this->table}
			WHERE operation_type = 'purchase'
			AND gateway = 'ys_helcim'
			AND active_scope_key IS NOT NULL
			AND local_status IN ('pending', 'failed', 'applying')
			AND (
				remote_status IN ('indeterminate', 'succeeded')
				OR recovery_attempt_count >= %d
			)
			ORDER BY updated_at ASC, id ASC
			LIMIT %d",
			$max_attempts,
			$limit
		);
		try {
			$rows = $this->database->get_results( $query, ARRAY_A );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::journalUnavailable();
		}
		if ( ! is_array( $rows ) || '' !== (string) ( $this->database->last_error ?? '' ) ) {
			return self::journalUnavailable();
		}

		return array_values( $rows );
	}

	/** Find the durable child used to resume a committed refund-to-reverse handoff. */
	public function findChildByParent( string $parent_operation_uuid, string $operation_type ): ?array {
		$operation_type = strtolower( trim( $operation_type ) );
		if ( ! in_array( $operation_type, array( 'reverse' ), true ) ) {
			return null;
		}

		$query = $this->database->prepare(
			"SELECT * FROM {$this->table} WHERE parent_operation_uuid = %s AND operation_type = %s LIMIT 1",
			strtolower( $parent_operation_uuid ),
			$operation_type
		);

		$row = $this->database->get_row( $query, ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** Atomically claim a created operation for its first provider call. */
	public function claimRemoteProcessing( string $operation_uuid ) {
		return $this->transitionRemote(
			$operation_uuid,
			YSHelcimOperationState::REMOTE_CREATED,
			YSHelcimOperationState::REMOTE_PROCESSING
		);
	}

	/**
	 * Record an empty provider lookup for a hosted purchase as unresolved.
	 *
	 * Absence is never proof that no transaction was created. This keeps the
	 * active scope lock and records durable administrator attention while later
	 * exact provider evidence may still resolve the operation.
	 *
	 * @return bool|\WP_Error
	 */
	public function recordHostedEmptyObservation( string $operation_uuid, string $expected_remote_status ) {
		$operation_uuid = strtolower( trim( $operation_uuid ) );
		if (
			1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $operation_uuid ) ||
			! in_array(
				$expected_remote_status,
				array( YSHelcimOperationState::REMOTE_PROCESSING, YSHelcimOperationState::REMOTE_INDETERMINATE ),
				true
			)
		) {
			return self::invalidOperation();
		}

		$current = $this->findByUuidStrict( $operation_uuid );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if (
			! is_array( $current ) ||
			'purchase' !== (string) ( $current['operation_type'] ?? '' ) ||
			'ys_helcim' !== (string) ( $current['gateway'] ?? '' ) ||
			$expected_remote_status !== (string) ( $current['remote_status'] ?? '' ) ||
			! in_array(
				(string) ( $current['local_status'] ?? '' ),
				array( YSHelcimOperationState::LOCAL_PENDING, YSHelcimOperationState::LOCAL_FAILED ),
				true
			) ||
			'' === (string) ( $current['active_scope_key'] ?? '' ) ||
			'' === (string) ( $current['updated_at'] ?? '' )
		) {
			return false;
		}

		try {
			$now = ( $this->clock )();
			$updated = $this->database->update(
				$this->table,
				array(
					'remote_status'            => YSHelcimOperationState::REMOTE_INDETERMINATE,
					'remote_error_code'        => 'ys_helcim_hosted_lookup_empty_unresolved',
					'remote_error_message'     => YSHelcimSanitizer::errorText( 'Helcim returned an empty collection. Absence is not payment proof; this operation remains locked until exact provider evidence is available.' ),
					'encrypted_material'       => null,
					'material_expires_at'      => null,
					'confirm_token_hash'       => null,
					'confirm_token_expires_at' => null,
					'updated_at'               => $now,
				),
				array(
					'operation_uuid' => $operation_uuid,
					'remote_status'  => $expected_remote_status,
					'updated_at'     => (string) $current['updated_at'],
				)
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::journalUnavailable();
		}
		if ( false === $updated ) {
			return self::journalUnavailable();
		}

		return 1 === $updated;
	}

	/**
	 * Atomically transfer a refund scope from a definitively failed parent to a
	 * reverse child. The InnoDB unique scope key remains owned throughout.
	 *
	 * @param array $child        Complete reverse operation identity.
	 * @param array $failure_data Sanitized parent failure fields.
	 * @return array|\WP_Error
	 */
	public function handoffRemoteFailureToChild(
		string $parent_operation_uuid,
		string $expected_parent_status,
		array $child,
		array $failure_data = array()
	) {
		if (
			YSHelcimOperationState::REMOTE_PROCESSING !== $expected_parent_status ||
			'reverse' !== strtolower( trim( (string) ( $child['operation_type'] ?? '' ) ) ) ||
			strtolower( $parent_operation_uuid ) !== strtolower( (string) ( $child['parent_operation_uuid'] ?? '' ) )
		) {
			return self::invalidOperation();
		}

		if ( false === $this->database->query( 'START TRANSACTION' ) ) {
			return self::journalUnavailable();
		}

		$locked_parent = $this->database->get_row(
			$this->database->prepare(
				"SELECT * FROM {$this->table} WHERE operation_uuid = %s LIMIT 1 FOR UPDATE",
				strtolower( $parent_operation_uuid )
			),
			ARRAY_A
		);

		if (
			! is_array( $locked_parent ) ||
			'refund' !== (string) $locked_parent['operation_type'] ||
			$expected_parent_status !== (string) $locked_parent['remote_status'] ||
			empty( $locked_parent['active_scope_key'] )
		) {
			$this->database->query( 'ROLLBACK' );
			return new \WP_Error(
				'ys_helcim_operation_conflict',
				__( 'The refund operation changed before reversal could be claimed.', 'ys-helcim-via-fluentcart' )
			);
		}

		try {
			$child_scope = YSHelcimOperationScope::fromBusinessKey( (string) ( $child['scope_key'] ?? '' ) );
		} catch ( \InvalidArgumentException $exception ) {
			unset( $exception );
			$this->database->query( 'ROLLBACK' );
			return self::invalidOperation();
		}

		$identity_fields = array( 'gateway', 'order_id', 'transaction_id', 'transaction_uuid', 'amount', 'currency', 'payment_mode' );
		foreach ( $identity_fields as $field ) {
			if ( (string) ( $child[ $field ] ?? '' ) !== (string) $locked_parent[ $field ] ) {
				$this->database->query( 'ROLLBACK' );
				return self::invalidOperation();
			}
		}

		if ( $child_scope !== (string) $locked_parent['scope_key'] ) {
			$this->database->query( 'ROLLBACK' );
			return self::invalidOperation();
		}

		$parent_transition = $this->transitionRemote( $parent_operation_uuid, $expected_parent_status, 'failed', $failure_data );
		if ( is_wp_error( $parent_transition ) ) {
			$this->database->query( 'ROLLBACK' );
			return $parent_transition;
		}
		if ( true !== $parent_transition ) {
			$this->database->query( 'ROLLBACK' );
			return new \WP_Error(
				'ys_helcim_operation_conflict',
				__( 'The refund operation changed before reversal could be claimed.', 'ys-helcim-via-fluentcart' )
			);
		}

		$created_child = $this->createInternal( $child, true );
		if ( is_wp_error( $created_child ) ) {
			$this->database->query( 'ROLLBACK' );
			return $created_child;
		}

		if ( false === $this->database->query( 'COMMIT' ) ) {
			$this->database->query( 'ROLLBACK' );
			return self::journalUnavailable();
		}

		return $created_child;
	}

	/**
	 * Compare-and-set one remote state transition.
	 *
	 * @param array $changes Sanitized outcome fields.
	 */
	public function transitionRemote( string $operation_uuid, string $expected, string $next, array $changes = array() ) {
		if ( ! YSHelcimOperationState::canTransitionRemote( $expected, $next ) ) {
			return false;
		}

		$current = $this->findByUuid( $operation_uuid );
		if ( null === $current || $expected !== $current['remote_status'] ) {
			return false;
		}

		$data = array(
			'remote_status' => $next,
			'updated_at'    => ( $this->clock )(),
		);

		foreach ( array( 'vendor_transaction_id' ) as $field ) {
			if ( array_key_exists( $field, $changes ) ) {
				$data[ $field ] = $changes[ $field ];
			}
		}

		if ( array_key_exists( 'error_code', $changes ) ) {
			$data['remote_error_code'] = substr( sanitize_text_field( (string) $changes['error_code'] ), 0, 100 );
		}
		if ( array_key_exists( 'error_message', $changes ) ) {
			$data['remote_error_message'] = YSHelcimSanitizer::errorText( (string) $changes['error_message'] );
		}

		if ( YSHelcimOperationState::REMOTE_SUCCEEDED === $next ) {
			$data['remote_error_code']    = null;
			$data['remote_error_message'] = null;
			$data['recovery_attempt_count'] = 0;
			$data['next_recovery_at']       = null;
		}

		if ( in_array( $next, array( 'succeeded', 'declined', 'failed', 'canceled', 'expired' ), true ) ) {
			$data['encrypted_material']  = null;
			$data['material_expires_at'] = null;
			$data['next_recovery_at']    = null;
		}

		if ( YSHelcimOperationState::shouldReleaseScope( $next, (string) $current['local_status'] ) ) {
			$data['active_scope_key'] = null;
			$data['resolved_at']      = ( $this->clock )();
		}

		$updated = $this->database->update(
			$this->table,
			$data,
			array(
				'operation_uuid' => strtolower( $operation_uuid ),
				'remote_status'  => $expected,
			)
		);

		if ( false === $updated ) {
			return self::journalUnavailable();
		}

		return 1 === $updated;
	}

	/** Compare-and-set local application status after provider success. */
	public function claimLocalApplying( string $operation_uuid, string $expected ) {
		if ( ! in_array( $expected, array( 'pending', 'failed' ), true ) ) {
			return false;
		}

		return $this->transitionLocal(
			$operation_uuid,
			$expected,
			YSHelcimOperationState::LOCAL_APPLYING
		);
	}

	/** Atomically refresh an abandoned local-applying lease after inspection. */
	public function reclaimStaleLocalApplying( string $operation_uuid, string $claimed_before ) {
		if ( 1 !== preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $claimed_before ) ) {
			return self::invalidOperation();
		}

		$current = $this->findByUuid( $operation_uuid );
		$claimed_at = is_array( $current ) ? self::nullableString( $current['local_claimed_at'] ?? null ) : null;
		if (
			null === $current ||
			YSHelcimOperationState::REMOTE_SUCCEEDED !== (string) $current['remote_status'] ||
			YSHelcimOperationState::LOCAL_APPLYING !== (string) $current['local_status'] ||
			null === $claimed_at ||
			$claimed_at > $claimed_before
		) {
			return false;
		}

		$now = ( $this->clock )();
		$updated = $this->database->update(
			$this->table,
			array(
				'local_claimed_at' => $now,
				'updated_at'       => $now,
			),
			array(
				'operation_uuid'   => strtolower( $operation_uuid ),
				'remote_status'    => YSHelcimOperationState::REMOTE_SUCCEEDED,
				'local_status'     => YSHelcimOperationState::LOCAL_APPLYING,
				'local_claimed_at' => $claimed_at,
			)
		);
		if ( false === $updated ) {
			return self::journalUnavailable();
		}

		return 1 === $updated;
	}

	/** Persist a recorder failure after its database transaction has rolled back. */
	public function recordLocalFailure( string $operation_uuid, string $error_code, string $error_message ) {
		return $this->transitionLocal(
			$operation_uuid,
			YSHelcimOperationState::LOCAL_PENDING,
			YSHelcimOperationState::LOCAL_FAILED,
			array(
				'error_code'    => $error_code,
				'error_message' => $error_message,
			)
		);
	}

	/** Compare-and-set local application status after provider success. */
	public function transitionLocal( string $operation_uuid, string $expected, string $next, array $changes = array() ) {
		if ( ! YSHelcimOperationState::canTransitionLocal( $expected, $next ) ) {
			return false;
		}

		$current = $this->findByUuid( $operation_uuid );
		if (
			null === $current ||
			YSHelcimOperationState::REMOTE_SUCCEEDED !== $current['remote_status'] ||
			$expected !== $current['local_status']
		) {
			return false;
		}

		$data = array(
			'local_status' => $next,
			'updated_at'   => ( $this->clock )(),
		);
		$data['local_claimed_at'] = YSHelcimOperationState::LOCAL_APPLYING === $next
			? $data['updated_at']
			: null;

		if ( array_key_exists( 'local_transaction_id', $changes ) ) {
			$local_transaction_id = $changes['local_transaction_id'];
			if (
				! is_int( $local_transaction_id ) ||
				$local_transaction_id <= 0 ||
				! in_array( $next, array( YSHelcimOperationState::LOCAL_RECORDED, YSHelcimOperationState::LOCAL_APPLIED ), true ) ||
				( ! empty( $current['local_transaction_id'] ) && (int) $current['local_transaction_id'] !== $local_transaction_id )
			) {
				return false;
			}
			$data['local_transaction_id'] = $local_transaction_id;
		}

		if ( YSHelcimOperationState::LOCAL_RECORDED === $next ) {
			if (
				in_array( (string) $current['operation_type'], array( 'refund', 'reverse' ), true ) &&
				empty( $data['local_transaction_id'] ) &&
				empty( $current['local_transaction_id'] )
			) {
				return false;
			}
			$data['local_recorded_at'] = ( $this->clock )();
		}

		if ( array_key_exists( 'error_code', $changes ) ) {
			$data['local_error_code'] = substr( sanitize_text_field( (string) $changes['error_code'] ), 0, 100 );
		}
		if ( array_key_exists( 'error_message', $changes ) ) {
			$data['local_error_message'] = YSHelcimSanitizer::errorText( (string) $changes['error_message'] );
		}
		if ( YSHelcimOperationState::LOCAL_APPLIED === $next ) {
			$data['local_error_code']    = null;
			$data['local_error_message'] = null;
			$data['local_applied_at']    = ( $this->clock )();
			$data['recovery_attempt_count'] = 0;
			$data['next_recovery_at']       = null;
		}

		if ( YSHelcimOperationState::shouldReleaseScope( (string) $current['remote_status'], $next ) ) {
			$data['active_scope_key'] = null;
			$data['resolved_at']      = ( $this->clock )();
		}

		$updated = $this->database->update(
			$this->table,
			$data,
			array(
				'operation_uuid' => strtolower( $operation_uuid ),
				'remote_status'  => YSHelcimOperationState::REMOTE_SUCCEEDED,
				'local_status'   => $expected,
			)
		);

		if ( false === $updated ) {
			return self::journalUnavailable();
		}

		return 1 === $updated;
	}

	/** Atomically consume the operation's one-time public confirmation token. */
	public function consumeConfirmToken( string $operation_uuid, string $presented_token ) {
		$current = $this->findByUuid( $operation_uuid );
		if (
			null === $current ||
			empty( $current['confirm_token_hash'] ) ||
			empty( $current['confirm_token_expires_at'] ) ||
			(string) $current['confirm_token_expires_at'] <= ( $this->clock )() ||
			! hash_equals( (string) $current['confirm_token_hash'], hash( 'sha256', $presented_token ) )
		) {
			return false;
		}

		$updated = $this->database->update(
			$this->table,
			array(
				'confirm_token_hash'       => null,
				'confirm_token_expires_at' => null,
				'updated_at'               => ( $this->clock )(),
			),
			array(
				'operation_uuid'    => strtolower( $operation_uuid ),
				'confirm_token_hash' => (string) $current['confirm_token_hash'],
			)
		);

		if ( false === $updated ) {
			return self::journalUnavailable();
		}

		return 1 === $updated;
	}

	/** Purge expired ciphertext while retaining unresolved operation scope locks. */
	public function purgeExpiredMaterial( int $limit = 100 ) {
		$limit = max( 1, min( 500, $limit ) );
		$now   = ( $this->clock )();
		$sql   = $this->database->prepare(
			"UPDATE {$this->table}
			SET encrypted_material = NULL, material_expires_at = NULL, updated_at = %s
			WHERE encrypted_material IS NOT NULL
			AND material_expires_at IS NOT NULL
			AND material_expires_at <= %s
			LIMIT %d",
			$now,
			$now,
			$limit
		);
		$updated = $this->database->query( $sql );

		return false === $updated ? self::journalUnavailable() : (int) $updated;
	}

	/**
	 * Expire an abandoned pre-provider row for the requested business scope.
	 *
	 * `created` is the only state proving that the atomic provider claim never
	 * happened. The conditional update also makes a late original request lose
	 * its claim before it can contact Helcim. Processing and indeterminate rows
	 * are deliberately never released by age.
	 *
	 * @return int|\WP_Error
	 */
	public function expireStaleCreatedScope( string $scope_key ) {
		try {
			$scope_key = YSHelcimOperationScope::fromBusinessKey( $scope_key );
		} catch ( \InvalidArgumentException $exception ) {
			unset( $exception );
			return self::invalidOperation();
		}

		$now_timestamp = strtotime( ( $this->clock )() . ' UTC' );
		if ( false === $now_timestamp ) {
			return self::journalUnavailable();
		}

		$now    = gmdate( 'Y-m-d H:i:s', $now_timestamp );
		$cutoff = gmdate( 'Y-m-d H:i:s', $now_timestamp - self::CREATED_CLAIM_LEASE_SECONDS );
		$code   = 'ys_helcim_operation_expired_before_claim';
		$message = __( 'The payment operation expired before any provider request was claimed.', 'ys-helcim-via-fluentcart' );
		$sql     = $this->database->prepare(
			"UPDATE {$this->table}
			SET remote_status = %s,
				local_status = %s,
				active_scope_key = NULL,
				remote_error_code = %s,
				remote_error_message = %s,
				local_error_code = %s,
				local_error_message = %s,
				encrypted_material = NULL,
				material_expires_at = NULL,
				confirm_token_hash = NULL,
				confirm_token_expires_at = NULL,
				resolved_at = %s,
				updated_at = %s
			WHERE active_scope_key = %s
			AND remote_status = %s
			AND local_status = %s
			AND created_at <= %s
			LIMIT 1",
			YSHelcimOperationState::REMOTE_EXPIRED,
			YSHelcimOperationState::LOCAL_FAILED,
			$code,
			$message,
			$code,
			$message,
			$now,
			$now,
			$scope_key,
			YSHelcimOperationState::REMOTE_CREATED,
			YSHelcimOperationState::LOCAL_PENDING,
			$cutoff
		);
		$updated = $this->database->query( $sql );

		return false === $updated ? self::journalUnavailable() : (int) $updated;
	}

	/**
	 * Conservatively expose an abandoned refund/reverse claim for read-only
	 * reconciliation. The scope remains owned and no provider retry is allowed.
	 *
	 * @return int|\WP_Error Number of rows promoted (zero or one).
	 */
	public function promoteStaleRefundProcessing( string $operation_uuid ) {
		$operation_uuid = strtolower( trim( $operation_uuid ) );
		if ( 1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $operation_uuid ) ) {
			return self::invalidOperation();
		}

		try {
			$now_value = ( $this->clock )();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::journalUnavailable();
		}
		$now_timestamp = is_string( $now_value ) ? strtotime( $now_value . ' UTC' ) : false;
		if ( false === $now_timestamp ) {
			return self::journalUnavailable();
		}

		$now     = gmdate( 'Y-m-d H:i:s', $now_timestamp );
		$cutoff  = gmdate( 'Y-m-d H:i:s', $now_timestamp - self::REFUND_PROCESSING_LEASE_SECONDS );
		$code    = 'ys_helcim_provider_result_unpersisted';
		$message = __( 'The provider request outlived its durable claim. Positive reconciliation is required; the request will not be resent.', 'ys-helcim-via-fluentcart' );
		$sql     = $this->database->prepare(
			"/* ys_helcim_promote_stale_refund_processing */
			UPDATE {$this->table}
			SET remote_status = %s,
				remote_error_code = %s,
				remote_error_message = %s,
				encrypted_material = NULL,
				material_expires_at = NULL,
				updated_at = %s
			WHERE operation_uuid = %s
			AND operation_type IN ('refund', 'reverse')
			AND remote_status = %s
			AND local_status IN ('pending', 'failed')
			AND active_scope_key IS NOT NULL
			AND updated_at <= %s
			LIMIT 1",
			YSHelcimOperationState::REMOTE_INDETERMINATE,
			$code,
			$message,
			$now,
			$operation_uuid,
			YSHelcimOperationState::REMOTE_PROCESSING,
			$cutoff
		);

		try {
			$updated = $this->database->query( $sql );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::journalUnavailable();
		}

		return false === $updated ? self::journalUnavailable() : (int) $updated;
	}

	/**
	 * Promote bounded stale refund claims for an order before building its admin
	 * options snapshot. Active scopes remain locked for positive resolution.
	 *
	 * @return int|\WP_Error
	 */
	public function promoteStaleRefundProcessingForOrder( int $order_id ) {
		if ( $order_id <= 0 ) {
			return self::invalidOperation();
		}

		try {
			$now_value = ( $this->clock )();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::journalUnavailable();
		}
		$now_timestamp = is_string( $now_value ) ? strtotime( $now_value . ' UTC' ) : false;
		if ( false === $now_timestamp ) {
			return self::journalUnavailable();
		}

		$now     = gmdate( 'Y-m-d H:i:s', $now_timestamp );
		$cutoff  = gmdate( 'Y-m-d H:i:s', $now_timestamp - self::REFUND_PROCESSING_LEASE_SECONDS );
		$code    = 'ys_helcim_provider_result_unpersisted';
		$message = __( 'The provider request outlived its durable claim. Positive reconciliation is required; the request will not be resent.', 'ys-helcim-via-fluentcart' );
		$sql     = $this->database->prepare(
			"/* ys_helcim_promote_stale_refund_processing_order */
			UPDATE {$this->table}
			SET remote_status = %s,
				remote_error_code = %s,
				remote_error_message = %s,
				encrypted_material = NULL,
				material_expires_at = NULL,
				updated_at = %s
			WHERE order_id = %d
			AND operation_type IN ('refund', 'reverse')
			AND remote_status = %s
			AND local_status IN ('pending', 'failed')
			AND active_scope_key IS NOT NULL
			AND updated_at <= %s
			LIMIT 20",
			YSHelcimOperationState::REMOTE_INDETERMINATE,
			$code,
			$message,
			$now,
			$order_id,
			YSHelcimOperationState::REMOTE_PROCESSING,
			$cutoff
		);

		try {
			$updated = $this->database->query( $sql );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::journalUnavailable();
		}

		return false === $updated ? self::journalUnavailable() : (int) $updated;
	}

	/** @return \WP_Error */
	private static function invalidOperation(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_invalid_operation',
			__( 'The payment operation contains invalid safety data.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function journalUnavailable(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_journal_unavailable',
			__( 'The payment safety journal is unavailable. No provider request was sent.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function nullableString( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$value = trim( (string) $value );
		return '' === $value ? null : $value;
	}

	private static function isFutureSqlDate( ?string $value, string $now ): bool {
		return null !== $value &&
			1 === preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $value ) &&
			$value > $now;
	}
}
