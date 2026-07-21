<?php
/**
 * Atomic persistence for positive indeterminate-refund resolution.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationSchema;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationScope;
use YangSheep\Helcim\FluentCart\Support\YSHelcimTransactionId;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YSHelcimRefundResolutionRepository implements YSHelcimRefundResolutionStore {

	/** @var object */
	private $database;

	/** @var callable */
	private $clock;

	private string $operations_table;
	private string $challenges_table;
	private string $audit_table;
	private string $transactions_table;

	/** @param object|null $database wpdb-compatible database object. */
	public function __construct( ?object $database = null, ?callable $clock = null ) {
		if ( null === $database ) {
			global $wpdb;
			$database = $wpdb;
		}
		$this->database         = $database;
		$this->clock            = $clock ?? static fn (): string => gmdate( 'Y-m-d H:i:s' );
		$this->operations_table = YSHelcimOperationSchema::tableName( $database );
		$this->challenges_table = YSHelcimRefundResolutionSchema::challengeTableName( $database );
		$this->audit_table      = YSHelcimRefundResolutionSchema::auditTableName( $database );
		$this->transactions_table = $database->prefix . 'fct_order_transactions';
	}

	/** @return array<string,mixed>|\WP_Error|null */
	public function findOperation( string $operation_uuid ) {
		if ( ! self::isUuid( $operation_uuid ) ) {
			return null;
		}
		$this->clearError();
		$row = $this->database->get_row(
			$this->database->prepare(
				"SELECT * FROM {$this->operations_table} WHERE operation_uuid = %s LIMIT 1",
				$operation_uuid
			),
			ARRAY_A
		);
		if ( '' !== $this->lastError() ) {
			return self::storeUnavailable();
		}
		return is_array( $row ) ? $row : null;
	}

	/** @return int|\WP_Error */
	public function promoteStaleProcessing( string $operation_uuid ) {
		$repository = new YSHelcimOperationRepository( $this->database, $this->clock );
		return $repository->promoteStaleRefundProcessing( $operation_uuid );
	}

	/** @param array<string,mixed> $challenge @return bool|\WP_Error */
	public function createChallenge( array $challenge ) {
		$challenge = $this->validatedChallenge( $challenge );
		if ( is_wp_error( $challenge ) ) {
			return $challenge;
		}

		$this->clearError();
		$used = $this->database->get_row(
			$this->database->prepare(
				"SELECT operation_uuid FROM {$this->audit_table} WHERE candidate_transaction_id = %s LIMIT 1",
				$challenge['candidate_transaction_id']
			),
			ARRAY_A
		);
		if ( '' !== $this->lastError() ) {
			return self::storeUnavailable();
		}
		if ( is_array( $used ) ) {
			return self::candidateUsed();
		}

		$inserted = $this->database->insert(
			$this->challenges_table,
			array_merge( $challenge, array( 'used_at' => null ) )
		);
		if ( false === $inserted || 1 !== (int) $inserted ) {
			return false !== stripos( $this->lastError(), 'duplicate' )
				? self::resolutionConflict()
				: self::storeUnavailable();
		}
		return true;
	}

	/** @param array<string,mixed> $binding @return array<string,mixed>|\WP_Error|null */
	public function findResolutionReplay( array $binding ) {
		$binding = $this->validatedReplayBinding( $binding );
		if ( is_wp_error( $binding ) ) {
			return null;
		}

		$challenge = $this->readChallenge( $binding['challenge_hash'], false );
		if ( is_wp_error( $challenge ) ) {
			return $challenge;
		}
		$audit = $this->readAudit( $binding['operation_uuid'], false );
		if ( is_wp_error( $audit ) ) {
			return $audit;
		}
		$operation = $this->findOperation( $binding['operation_uuid'] );
		if ( is_wp_error( $operation ) ) {
			return $operation;
		}

		return $this->validatedReplay( $binding, $challenge, $audit, $operation );
	}

	/** @param array<string,mixed> $resolution @return array<string,mixed>|\WP_Error */
	public function commitResolution( array $resolution ) {
		$resolution = $this->validatedResolution( $resolution );
		if ( is_wp_error( $resolution ) ) {
			return $resolution;
		}
		try {
			$now = ( $this->clock )();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::storeUnavailable();
		}
		if ( ! is_string( $now ) || null === self::sqlTimestamp( $now ) ) {
			return self::storeUnavailable();
		}
		$resolution['now'] = $now;

		try {
			$started = $this->database->query( 'START TRANSACTION' );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::storeUnavailable();
		}
		if ( false === $started ) {
			return self::storeUnavailable();
		}

		try {
			return $this->commitResolutionTransaction( $resolution );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$this->rollback();
			return self::storeUnavailable();
		}
	}

	/** @param array<string,mixed> $resolution @return array<string,mixed>|\WP_Error */
	private function commitResolutionTransaction( array $resolution ) {
		$challenge = $this->readChallenge( $resolution['challenge_hash'], true );
		$operation = $this->readOperation( $resolution['operation_uuid'], true );
		if ( is_wp_error( $challenge ) || is_wp_error( $operation ) ) {
			$this->rollback();
			return is_wp_error( $challenge ) ? $challenge : $operation;
		}
		if ( ! is_array( $challenge ) || ! is_array( $operation ) ) {
			$this->rollback();
			return self::resolutionConflict();
		}

		if ( null !== ( $challenge['used_at'] ?? null ) ) {
			$audit  = $this->readAudit( $resolution['operation_uuid'], true );
			$replay = is_wp_error( $audit )
				? $audit
				: $this->validatedReplay( $resolution, $challenge, $audit, $operation );
			$this->rollback();
			return is_array( $replay ) ? $replay : self::resolutionConflict();
		}

		if ( ! $this->challengeMatchesResolution( $challenge, $resolution ) || ! $this->operationMatchesResolution( $operation, $resolution ) ) {
			$this->rollback();
			return self::resolutionConflict();
		}

		$candidate_available = $this->candidateReceiptAvailable( $resolution );
		if ( is_wp_error( $candidate_available ) || true !== $candidate_available ) {
			$this->rollback();
			return is_wp_error( $candidate_available ) ? $candidate_available : self::candidateUsed();
		}

		$audit = array(
			'operation_uuid'           => $resolution['operation_uuid'],
			'challenge_hash'           => $resolution['challenge_hash'],
			'gateway'                  => $resolution['gateway'],
			'payment_mode'             => $resolution['payment_mode'],
			'operation_type'           => $resolution['operation_type'],
			'candidate_transaction_id' => $resolution['candidate_transaction_id'],
			'source_transaction_id'    => $resolution['source_transaction_id'],
			'action'                   => $resolution['action'],
			'proof_digest'             => $resolution['proof_digest'],
			'state_updated_at'         => $resolution['state_updated_at'],
			'actor_user_id'            => $resolution['actor_user_id'],
			'parent_attested'          => $resolution['parent_attested'],
			'resolved_at'              => $resolution['now'],
		);
		$inserted = $this->database->insert( $this->audit_table, $audit );
		if ( false === $inserted || 1 !== (int) $inserted ) {
			$error = $this->lastError();
			$this->rollback();
			return self::isResolutionCandidateDuplicate( $error )
				? self::candidateUsed()
				: ( false !== stripos( $error, 'duplicate' ) ? self::resolutionConflict() : self::storeUnavailable() );
		}

		$updated = $this->database->update(
			$this->operations_table,
			array(
				'remote_status'         => 'succeeded',
				'vendor_transaction_id' => $resolution['candidate_transaction_id'],
				'remote_error_code'      => null,
				'remote_error_message'   => null,
				'encrypted_material'     => null,
				'material_expires_at'    => null,
				'updated_at'             => $resolution['now'],
			),
			array(
				'operation_uuid'        => $resolution['operation_uuid'],
				'remote_status'         => 'indeterminate',
				'local_status'          => $resolution['local_status'],
				'active_scope_key'      => $resolution['active_scope_key'],
				'vendor_transaction_id' => null,
				'updated_at'            => $resolution['state_updated_at'],
			)
		);
		if ( 1 !== (int) $updated ) {
			$error = $this->lastError();
			$this->rollback();
			return false === $updated
				? ( self::isCandidateReceiptDuplicate( $error ) ? self::candidateUsed() : self::storeUnavailable() )
				: self::resolutionConflict();
		}

		$consumed = $this->database->update(
			$this->challenges_table,
			array( 'used_at' => $resolution['now'] ),
			array(
				'challenge_hash' => $resolution['challenge_hash'],
				'used_at'        => null,
			)
		);
		if ( 1 !== (int) $consumed ) {
			$this->rollback();
			return false === $consumed ? self::storeUnavailable() : self::resolutionConflict();
		}

		if ( false === $this->database->query( 'COMMIT' ) ) {
			$this->rollback();
			return self::storeUnavailable();
		}

		$operation['remote_status']         = 'succeeded';
		$operation['vendor_transaction_id'] = $resolution['candidate_transaction_id'];
		$operation['remote_error_code']      = null;
		$operation['remote_error_message']   = null;
		$operation['encrypted_material']     = null;
		$operation['material_expires_at']    = null;
		$operation['updated_at']             = $resolution['now'];
		return array_merge(
			$audit,
			array(
				'operation' => $operation,
				'replayed'  => false,
			)
		);
	}

	/** @return array<string,mixed>|\WP_Error|null */
	private function readChallenge( string $hash, bool $lock ) {
		$this->clearError();
		$row = $this->database->get_row(
			$this->database->prepare(
				"SELECT * FROM {$this->challenges_table} WHERE challenge_hash = %s LIMIT 1" . ( $lock ? ' FOR UPDATE' : '' ),
				$hash
			),
			ARRAY_A
		);
		if ( '' !== $this->lastError() ) {
			return self::storeUnavailable();
		}
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|\WP_Error|null */
	private function readAudit( string $operation_uuid, bool $lock ) {
		$this->clearError();
		$row = $this->database->get_row(
			$this->database->prepare(
				"SELECT * FROM {$this->audit_table} WHERE operation_uuid = %s LIMIT 1" . ( $lock ? ' FOR UPDATE' : '' ),
				$operation_uuid
			),
			ARRAY_A
		);
		if ( '' !== $this->lastError() ) {
			return self::storeUnavailable();
		}
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|\WP_Error|null */
	private function readOperation( string $operation_uuid, bool $lock ) {
		$this->clearError();
		$row = $this->database->get_row(
			$this->database->prepare(
				"SELECT * FROM {$this->operations_table} WHERE operation_uuid = %s LIMIT 1" . ( $lock ? ' FOR UPDATE' : '' ),
				$operation_uuid
			),
			ARRAY_A
		);
		if ( '' !== $this->lastError() ) {
			return self::storeUnavailable();
		}
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Reject existing receipt ownership conservatively across the whole site,
	 * including gateways, modes, and historical account changes. The operation
	 * table's unique index is the atomic
	 * reservation; the FluentCart query is a transactional historical-existence
	 * gate for rows that predate the journal.
	 *
	 * @param array<string,mixed> $resolution
	 * @return bool|\WP_Error
	 */
	private function candidateReceiptAvailable( array $resolution ) {
		try {
			$this->clearError();
			$journal_owner = $this->database->get_row(
				$this->database->prepare(
					"SELECT operation_uuid FROM {$this->operations_table} WHERE vendor_transaction_id = %s LIMIT 1 FOR UPDATE",
					$resolution['candidate_transaction_id']
				),
				ARRAY_A
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::storeUnavailable();
		}
		if ( '' !== $this->lastError() ) {
			return self::storeUnavailable();
		}
		if ( is_array( $journal_owner ) ) {
			return self::candidateUsed();
		}

		try {
			$this->clearError();
			$local_owner = $this->database->get_row(
				$this->database->prepare(
					"SELECT id, order_id, uuid, vendor_charge_id, payment_method, payment_mode FROM {$this->transactions_table} WHERE vendor_charge_id = %s AND transaction_type = 'refund' AND payment_method IN ('ys_helcim', 'ys_helcim_js') LIMIT 1 FOR UPDATE",
					$resolution['candidate_transaction_id']
				),
				ARRAY_A
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::storeUnavailable();
		}
		if ( '' !== $this->lastError() ) {
			return self::storeUnavailable();
		}

		return ! is_array( $local_owner );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function validatedChallenge( array $challenge ) {
		$fields = array(
			'challenge_hash', 'operation_uuid', 'gateway', 'payment_mode',
			'candidate_transaction_id', 'source_transaction_id', 'action', 'proof_digest',
			'state_updated_at', 'actor_user_id', 'phrase_hash',
			'parent_attestation_required', 'created_at', 'expires_at',
		);
		if ( array_diff( $fields, array_keys( $challenge ) ) || array_diff( array_keys( $challenge ), $fields ) ) {
			return self::invalidData();
		}

		$candidate = YSHelcimTransactionId::normalize( $challenge['candidate_transaction_id'] );
		$source    = YSHelcimTransactionId::normalize( $challenge['source_transaction_id'] );
		$created   = self::sqlTimestamp( $challenge['created_at'] );
		$expires   = self::sqlTimestamp( $challenge['expires_at'] );
		if (
			! self::isHash( $challenge['challenge_hash'] ) ||
			! self::isUuid( $challenge['operation_uuid'] ) ||
			! in_array( $challenge['gateway'], array( 'ys_helcim', 'ys_helcim_js' ), true ) ||
			! in_array( $challenge['payment_mode'], array( 'test', 'live' ), true ) ||
			null === $candidate ||
			null === $source ||
			$candidate === $source ||
			YSHelcimRefundResolutionProof::ACTION !== $challenge['action'] ||
			! self::isHash( $challenge['proof_digest'] ) ||
			null === self::sqlTimestamp( $challenge['state_updated_at'] ) ||
			null === self::positiveInteger( $challenge['actor_user_id'] ) ||
			! self::isHash( $challenge['phrase_hash'] ) ||
			! is_bool( $challenge['parent_attestation_required'] ) ||
			null === $created ||
			null === $expires ||
			300 !== $expires - $created
		) {
			return self::invalidData();
		}
		return $challenge;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function validatedResolution( array $resolution ) {
		$fields = array(
			'challenge_hash', 'operation_uuid', 'gateway', 'payment_mode', 'operation_type',
			'local_status', 'active_scope_key', 'candidate_transaction_id', 'source_transaction_id',
			'action', 'proof_digest', 'state_updated_at', 'actor_user_id', 'phrase_hash',
			'parent_attestation_required', 'parent_attested', 'now',
		);
		if ( array_diff( $fields, array_keys( $resolution ) ) || array_diff( array_keys( $resolution ), $fields ) ) {
			return self::invalidData();
		}

		$candidate = YSHelcimTransactionId::normalize( $resolution['candidate_transaction_id'] );
		$source    = YSHelcimTransactionId::normalize( $resolution['source_transaction_id'] );
		if (
			! self::isHash( $resolution['challenge_hash'] ) ||
			! self::isUuid( $resolution['operation_uuid'] ) ||
			! in_array( $resolution['gateway'], array( 'ys_helcim', 'ys_helcim_js' ), true ) ||
			! in_array( $resolution['payment_mode'], array( 'test', 'live' ), true ) ||
			! in_array( $resolution['operation_type'], array( 'refund', 'reverse' ), true ) ||
			! in_array( $resolution['local_status'], array( 'pending', 'failed' ), true ) ||
			! is_string( $resolution['active_scope_key'] ) ||
			1 !== preg_match( '/\Ayshs-[a-f0-9]{64}\z/', $resolution['active_scope_key'] ) ||
			null === $candidate ||
			null === $source ||
			$candidate === $source ||
			YSHelcimRefundResolutionProof::ACTION !== $resolution['action'] ||
			! self::isHash( $resolution['proof_digest'] ) ||
			null === self::sqlTimestamp( $resolution['state_updated_at'] ) ||
			null === self::positiveInteger( $resolution['actor_user_id'] ) ||
			! self::isHash( $resolution['phrase_hash'] ) ||
			! is_bool( $resolution['parent_attestation_required'] ) ||
			! is_bool( $resolution['parent_attested'] ) ||
			$resolution['parent_attestation_required'] !== $resolution['parent_attested'] ||
			null === self::sqlTimestamp( $resolution['now'] )
		) {
			return self::invalidData();
		}
		return $resolution;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function validatedReplayBinding( array $binding ) {
		$fields = array(
			'challenge_hash', 'operation_uuid', 'candidate_transaction_id',
			'actor_user_id', 'phrase_hash', 'parent_attested',
		);
		if ( array_diff( $fields, array_keys( $binding ) ) || array_diff( array_keys( $binding ), $fields ) ) {
			return self::invalidData();
		}
		if (
			! self::isHash( $binding['challenge_hash'] ) ||
			! self::isUuid( $binding['operation_uuid'] ) ||
			null === YSHelcimTransactionId::normalize( $binding['candidate_transaction_id'] ) ||
			null === self::positiveInteger( $binding['actor_user_id'] ) ||
			! self::isHash( $binding['phrase_hash'] ) ||
			! is_bool( $binding['parent_attested'] )
		) {
			return self::invalidData();
		}
		return $binding;
	}

	private function challengeMatchesResolution( array $challenge, array $resolution ): bool {
		foreach (
			array(
				'operation_uuid', 'gateway', 'payment_mode', 'candidate_transaction_id',
				'source_transaction_id', 'action', 'proof_digest', 'state_updated_at',
				'actor_user_id', 'phrase_hash',
			) as $field
		) {
			if ( (string) ( $challenge[ $field ] ?? '' ) !== (string) $resolution[ $field ] ) {
				return false;
			}
		}
		$required = self::databaseBoolean( $challenge['parent_attestation_required'] ?? null );
		return null !== $required
			&& $required === $resolution['parent_attestation_required']
			&& (string) ( $challenge['expires_at'] ?? '' ) > $resolution['now'];
	}

	private function operationMatchesResolution( array $operation, array $resolution ): bool {
		$expected_scope = self::refundScope( $operation['order_id'] ?? null );
		return $resolution['operation_uuid'] === (string) ( $operation['operation_uuid'] ?? '' )
			&& $resolution['operation_type'] === (string) ( $operation['operation_type'] ?? '' )
			&& $resolution['gateway'] === (string) ( $operation['gateway'] ?? '' )
			&& $resolution['payment_mode'] === (string) ( $operation['payment_mode'] ?? '' )
			&& $resolution['source_transaction_id'] === YSHelcimTransactionId::normalize( $operation['source_vendor_transaction_id'] ?? null )
			&& 'indeterminate' === (string) ( $operation['remote_status'] ?? '' )
			&& $resolution['local_status'] === (string) ( $operation['local_status'] ?? '' )
			&& $resolution['state_updated_at'] === (string) ( $operation['updated_at'] ?? '' )
			&& null !== $expected_scope
			&& $expected_scope === $resolution['active_scope_key']
			&& $expected_scope === (string) ( $operation['scope_key'] ?? '' )
			&& $expected_scope === (string) ( $operation['active_scope_key'] ?? '' )
			&& ( null === ( $operation['vendor_transaction_id'] ?? null ) || '' === $operation['vendor_transaction_id'] );
	}

	/** @return array<string,mixed>|null */
	private function validatedReplay( array $binding, mixed $challenge, mixed $audit, mixed $operation ): ?array {
		if ( ! is_array( $challenge ) || ! is_array( $audit ) || ! is_array( $operation ) || null === ( $challenge['used_at'] ?? null ) ) {
			return null;
		}

		foreach ( array( 'operation_uuid', 'candidate_transaction_id', 'actor_user_id', 'phrase_hash' ) as $field ) {
			if ( (string) ( $challenge[ $field ] ?? '' ) !== (string) ( $binding[ $field ] ?? '' ) ) {
				return null;
			}
		}
		$required = self::databaseBoolean( $challenge['parent_attestation_required'] ?? null );
		$attested = self::databaseBoolean( $audit['parent_attested'] ?? null );
		if ( null === $required || null === $attested || $required !== $attested || $attested !== ( $binding['parent_attested'] ?? null ) ) {
			return null;
		}

		foreach (
			array(
				'operation_uuid', 'challenge_hash', 'candidate_transaction_id', 'gateway',
				'payment_mode', 'source_transaction_id', 'action', 'proof_digest',
				'state_updated_at', 'actor_user_id',
			) as $field
		) {
			$expected = 'challenge_hash' === $field
				? $binding['challenge_hash']
				: ( $challenge[ $field ] ?? null );
			if ( (string) ( $audit[ $field ] ?? '' ) !== (string) $expected ) {
				return null;
			}
		}

		$operation_type = (string) ( $operation['operation_type'] ?? '' );
		$gateway        = (string) ( $operation['gateway'] ?? '' );
		$payment_mode   = (string) ( $operation['payment_mode'] ?? '' );
		$source_id      = YSHelcimTransactionId::normalize( $operation['source_vendor_transaction_id'] ?? null );
		$used_at        = (string) ( $challenge['used_at'] ?? '' );
		$resolved_at    = (string) ( $audit['resolved_at'] ?? '' );
		$canonical_scope = self::refundScope( $operation['order_id'] ?? null );
		$local_status = (string) ( $operation['local_status'] ?? '' );
		$scope        = (string) ( $operation['scope_key'] ?? '' );
		$scope_safe   = null !== $canonical_scope && 'applied' === $local_status
			? $canonical_scope === $scope && null === ( $operation['active_scope_key'] ?? null )
			: null !== $canonical_scope && $canonical_scope === $scope && $scope === (string) ( $operation['active_scope_key'] ?? '' );
		if (
			'succeeded' !== (string) ( $operation['remote_status'] ?? '' ) ||
			$binding['operation_uuid'] !== (string) ( $operation['operation_uuid'] ?? '' ) ||
			$binding['candidate_transaction_id'] !== YSHelcimTransactionId::normalize( $operation['vendor_transaction_id'] ?? null ) ||
			! in_array( $operation_type, array( 'refund', 'reverse' ), true ) ||
			$operation_type !== (string) ( $audit['operation_type'] ?? '' ) ||
			$gateway !== (string) ( $audit['gateway'] ?? '' ) ||
			$payment_mode !== (string) ( $audit['payment_mode'] ?? '' ) ||
			$source_id !== YSHelcimTransactionId::normalize( $audit['source_transaction_id'] ?? null ) ||
			! in_array( $local_status, array( 'pending', 'failed', 'applying', 'recorded', 'applied' ), true ) ||
			null === self::sqlTimestamp( $used_at ) ||
			$used_at !== $resolved_at ||
			! $scope_safe
		) {
			return null;
		}

		return array_merge( $audit, array( 'operation' => $operation, 'replayed' => true ) );
	}

	private function rollback(): void {
		try {
			$this->database->query( 'ROLLBACK' );
		} catch ( \Throwable $exception ) {
			unset( $exception );
		}
	}

	private static function isCandidateReceiptDuplicate( string $error ): bool {
		return false !== stripos( $error, 'duplicate' )
			&& false !== stripos( $error, 'vendor_transaction_id' );
	}

	private static function isResolutionCandidateDuplicate( string $error ): bool {
		return false !== stripos( $error, 'duplicate' )
			&& false !== stripos( $error, 'candidate_transaction_id' );
	}

	private static function refundScope( mixed $order_id ): ?string {
		$order_id = self::positiveInteger( $order_id );
		if ( null === $order_id ) {
			return null;
		}

		return YSHelcimOperationScope::fromBusinessKey( 'refund-order:' . $order_id );
	}

	private function clearError(): void {
		if ( property_exists( $this->database, 'last_error' ) ) {
			$this->database->last_error = '';
		}
	}

	private function lastError(): string {
		return (string) ( $this->database->last_error ?? '' );
	}

	private static function isHash( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/', $value );
	}

	private static function isUuid( mixed $value ): bool {
		return is_string( $value )
			&& 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value );
	}

	private static function positiveInteger( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $value ) ) {
			return null;
		}
		$max = (string) PHP_INT_MAX;
		if ( strlen( $value ) > strlen( $max ) || ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) > 0 ) ) {
			return null;
		}
		return (int) $value;
	}

	private static function sqlTimestamp( mixed $value ): ?int {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		if (
			false === $date ||
			( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ||
			$date->format( 'Y-m-d H:i:s' ) !== $value
		) {
			return null;
		}
		return $date->getTimestamp();
	}

	private static function databaseBoolean( mixed $value ): ?bool {
		return match ( $value ) {
			false, 0, '0' => false,
			true, 1, '1'  => true,
			default       => null,
		};
	}

	private static function invalidData(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_invalid_resolution_data',
			__( 'The refund-resolution journal data is invalid.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function resolutionConflict(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_resolution_conflict',
			__( 'The refund-resolution challenge or operation changed.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function candidateUsed(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_resolution_candidate_used',
			__( 'This Helcim transaction is already bound to another payment operation.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function storeUnavailable(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_resolution_store_unavailable',
			__( 'The refund-resolution journal is unavailable.', 'ys-helcim-via-fluentcart' )
		);
	}
}
