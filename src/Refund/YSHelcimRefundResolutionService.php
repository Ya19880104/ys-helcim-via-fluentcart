<?php
/**
 * Two-phase positive resolution for an indeterminate Helcim refund/reverse.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationScope;
use YangSheep\Helcim\FluentCart\Support\YSHelcimTransactionId;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider reads happen in both phases, but this class has no provider-mutation
 * dependency. Only exact, fresh evidence may reach the atomic store commit.
 */
final class YSHelcimRefundResolutionService {

	private const CHALLENGE_TTL_SECONDS = 300;

	/** @var callable */
	private $credential_resolver;

	/** @var callable */
	private $provider_reader;

	/** @var callable */
	private $local_recorder;

	/** @var callable */
	private $random_factory;

	/** @var callable */
	private $clock;

	public function __construct(
		private YSHelcimRefundResolutionStore $store,
		callable $credential_resolver,
		callable $provider_reader,
		callable $local_recorder,
		?callable $random_factory = null,
		?callable $clock = null
	) {
		$this->credential_resolver = $credential_resolver;
		$this->provider_reader      = $provider_reader;
		$this->local_recorder       = $local_recorder;
		$this->random_factory       = $random_factory ?? static fn (): string => random_bytes( 32 );
		$this->clock                = $clock ?? static fn (): int => time();
	}

	/**
	 * Inspect exact provider evidence and issue a five-minute confirmation challenge.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function inspect( string $operation_uuid, string $candidate_transaction_id, int $actor_user_id ) {
		$input = $this->normalizeInput( $operation_uuid, $candidate_transaction_id, $actor_user_id );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		$operation = $this->loadIndeterminateOperation( $input['operation_uuid'] );
		if ( is_wp_error( $operation ) ) {
			return $operation;
		}

		$proof = $this->readProof( $operation, $input['candidate_transaction_id'] );
		if ( is_wp_error( $proof ) ) {
			return $proof;
		}

		try {
			$random = ( $this->random_factory )();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::challengeUnavailable();
		}
		if ( ! is_string( $random ) || strlen( $random ) < 16 || strlen( $random ) > 64 ) {
			return self::challengeUnavailable();
		}

		$challenge = bin2hex( $random );
		$phrase    = self::confirmationPhrase(
			$input['operation_uuid'],
			$input['candidate_transaction_id'],
			(bool) $proof['parent_attestation_required']
		);
		$now       = $this->now();
		if ( is_wp_error( $now ) ) {
			return $now;
		}
		$expires = $now->modify( '+' . self::CHALLENGE_TTL_SECONDS . ' seconds' );

		$stored = array(
			'challenge_hash'              => hash( 'sha256', $challenge ),
			'operation_uuid'              => $input['operation_uuid'],
			'gateway'                     => $operation['gateway'],
			'payment_mode'                => $operation['payment_mode'],
			'candidate_transaction_id'    => $proof['candidate_transaction_id'],
			'source_transaction_id'       => $proof['source_transaction_id'],
			'action'                      => $proof['action'],
			'proof_digest'                => $proof['proof_digest'],
			'state_updated_at'            => $operation['updated_at'],
			'actor_user_id'               => $input['actor_user_id'],
			'phrase_hash'                 => hash( 'sha256', $phrase ),
			'parent_attestation_required' => (bool) $proof['parent_attestation_required'],
			'created_at'                  => $now->format( 'Y-m-d H:i:s' ),
			'expires_at'                  => $expires->format( 'Y-m-d H:i:s' ),
		);

		try {
			$created = $this->store->createChallenge( $stored );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::storeUnavailable();
		}
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		if ( true !== $created ) {
			return self::storeUnavailable();
		}

		return array(
			'status'                      => 'confirmation_required',
			'operation_uuid'              => $input['operation_uuid'],
			'candidate_transaction_id'    => $proof['candidate_transaction_id'],
			'source_transaction_id'       => $proof['source_transaction_id'],
			'action'                      => $proof['action'],
			'proof_digest'                => $proof['proof_digest'],
			'parent_attestation_required' => (bool) $proof['parent_attestation_required'],
			'challenge'                   => $challenge,
			'challenge_expires_at'        => $expires->format( 'Y-m-d H:i:s' ),
			'confirmation_phrase'         => $phrase,
		);
	}

	/**
	 * Re-read provider proof, atomically resolve, then resume local recording.
	 *
	 * @param array<string,mixed> $request
	 * @return array<string,mixed>|\WP_Error
	 */
	public function commit( array $request, int $actor_user_id ) {
		$input = $this->normalizeCommitRequest( $request, $actor_user_id );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		$binding = array(
			'challenge_hash'           => hash( 'sha256', $input['challenge'] ),
			'operation_uuid'           => $input['operation_uuid'],
			'candidate_transaction_id' => $input['candidate_transaction_id'],
			'actor_user_id'            => $input['actor_user_id'],
			'phrase_hash'              => hash( 'sha256', $input['confirmation_phrase'] ),
			'parent_attested'          => $input['parent_attestation'],
		);

		try {
			$replay = $this->store->findResolutionReplay( $binding );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::storeUnavailable();
		}
		if ( is_wp_error( $replay ) ) {
			return $replay;
		}
		if ( is_array( $replay ) ) {
			return $this->recordLocally( $input['operation_uuid'], $replay, true );
		}

		$operation = $this->loadIndeterminateOperation( $input['operation_uuid'] );
		if ( is_wp_error( $operation ) ) {
			return $operation;
		}

		$proof = $this->readProof( $operation, $input['candidate_transaction_id'] );
		if ( is_wp_error( $proof ) ) {
			return $proof;
		}

		$attestation_required = (bool) $proof['parent_attestation_required'];
		if ( $attestation_required && true !== $input['parent_attestation'] ) {
			return new \WP_Error(
				'ys_helcim_resolution_attestation_required',
				__( 'The provider omitted a parent transaction field; explicit operator attestation is required.', 'ys-helcim-via-fluentcart' )
			);
		}
		if ( ! $attestation_required && true === $input['parent_attestation'] ) {
			return new \WP_Error(
				'ys_helcim_resolution_attestation_mismatch',
				__( 'Operator attestation does not match the inspected provider proof.', 'ys-helcim-via-fluentcart' )
			);
		}

		$expected_phrase = self::confirmationPhrase(
			$input['operation_uuid'],
			$input['candidate_transaction_id'],
			$attestation_required
		);
		if ( ! hash_equals( $expected_phrase, $input['confirmation_phrase'] ) ) {
			return new \WP_Error(
				'ys_helcim_resolution_confirmation_mismatch',
				__( 'The typed refund-resolution confirmation phrase does not match exactly.', 'ys-helcim-via-fluentcart' )
			);
		}

		$now = $this->now();
		if ( is_wp_error( $now ) ) {
			return $now;
		}
		$resolution = array_merge(
			$binding,
			array(
				'gateway'                     => $operation['gateway'],
				'payment_mode'                => $operation['payment_mode'],
				'operation_type'              => $operation['operation_type'],
				'local_status'                => $operation['local_status'],
				'active_scope_key'            => $operation['active_scope_key'],
				'source_transaction_id'       => $proof['source_transaction_id'],
				'action'                      => $proof['action'],
				'proof_digest'                => $proof['proof_digest'],
				'state_updated_at'            => $operation['updated_at'],
				'parent_attestation_required' => $attestation_required,
				'now'                         => $now->format( 'Y-m-d H:i:s' ),
			)
		);

		try {
			$committed = $this->store->commitResolution( $resolution );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::storeUnavailable();
		}
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}
		if ( ! is_array( $committed ) ) {
			return self::storeUnavailable();
		}

		return $this->recordLocally( $input['operation_uuid'], $committed, false );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function readProof( array $operation, string $candidate_transaction_id ) {
		try {
			$credential = ( $this->credential_resolver )( $operation['gateway'], $operation['payment_mode'] );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::credentialUnavailable();
		}
		if ( is_wp_error( $credential ) || ! is_string( $credential ) || '' === trim( $credential ) ) {
			return self::credentialUnavailable();
		}

		$candidate = $this->providerRead( $candidate_transaction_id, $credential, $operation );
		if ( is_wp_error( $candidate ) ) {
			return $candidate;
		}
		$source = $this->providerRead( $operation['source_vendor_transaction_id'], $credential, $operation );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$operation['resolution_candidate_id'] = $candidate_transaction_id;
		return YSHelcimRefundResolutionProof::verify( $operation, $candidate, $source );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function providerRead( string $transaction_id, string $credential, array $operation ) {
		try {
			$response = ( $this->provider_reader )(
				$transaction_id,
				$credential,
				$operation['gateway'],
				$operation['payment_mode']
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::providerUnavailable();
		}

		return is_array( $response ) ? $response : self::providerUnavailable();
	}

	/** @return array<string,mixed>|\WP_Error */
	private function loadIndeterminateOperation( string $operation_uuid ) {
		try {
			$promoted = $this->store->promoteStaleProcessing( $operation_uuid );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::storeUnavailable();
		}
		if ( is_wp_error( $promoted ) ) {
			return $promoted;
		}
		if ( ! in_array( $promoted, array( 0, 1 ), true ) ) {
			return self::storeUnavailable();
		}

		try {
			$row = $this->store->findOperation( $operation_uuid );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::storeUnavailable();
		}
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( ! is_array( $row ) ) {
			return self::operationConflict();
		}

		$order_id       = self::positiveInteger( $row['order_id'] ?? null );
		$transaction_id = self::positiveInteger( $row['transaction_id'] ?? null );
		$amount         = self::positiveInteger( $row['amount'] ?? null );
		$source_id      = YSHelcimTransactionId::normalize( $row['source_vendor_transaction_id'] ?? null );
		$uuid           = strtolower( (string) ( $row['operation_uuid'] ?? '' ) );
		$type           = strtolower( (string) ( $row['operation_type'] ?? '' ) );
		$gateway        = (string) ( $row['gateway'] ?? '' );
		$mode           = strtolower( (string) ( $row['payment_mode'] ?? '' ) );
		$currency       = strtoupper( (string) ( $row['currency'] ?? '' ) );
		$updated_at     = self::sqlDate( $row['updated_at'] ?? null );
		$scope          = null === $order_id
			? ''
			: YSHelcimOperationScope::fromBusinessKey( 'refund-order:' . $order_id );
		$vendor_id      = $row['vendor_transaction_id'] ?? null;

		if (
			$operation_uuid !== $uuid ||
			! in_array( $type, array( 'refund', 'reverse' ), true ) ||
			! in_array( $gateway, array( 'ys_helcim', 'ys_helcim_js' ), true ) ||
			! in_array( $mode, array( 'test', 'live' ), true ) ||
			! in_array( $currency, array( 'USD', 'CAD' ), true ) ||
			null === $order_id ||
			null === $transaction_id ||
			null === $amount ||
			null === $source_id ||
			null === $updated_at ||
			'indeterminate' !== (string) ( $row['remote_status'] ?? '' ) ||
			! in_array( (string) ( $row['local_status'] ?? '' ), array( 'pending', 'failed' ), true ) ||
			$scope !== (string) ( $row['scope_key'] ?? '' ) ||
			$scope !== (string) ( $row['active_scope_key'] ?? '' ) ||
			! ( null === $vendor_id || '' === $vendor_id )
		) {
			return self::operationConflict();
		}

		$row['operation_uuid']               = $uuid;
		$row['operation_type']               = $type;
		$row['gateway']                      = $gateway;
		$row['payment_mode']                 = $mode;
		$row['order_id']                     = $order_id;
		$row['transaction_id']               = $transaction_id;
		$row['amount']                       = $amount;
		$row['currency']                     = $currency;
		$row['source_vendor_transaction_id'] = $source_id;
		$row['updated_at']                   = $updated_at;
		$row['scope_key']                    = $scope;
		$row['active_scope_key']             = $scope;
		return $row;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function normalizeInput( string $operation_uuid, string $candidate_transaction_id, int $actor_user_id ) {
		$uuid      = strtolower( $operation_uuid );
		$candidate = YSHelcimTransactionId::normalize( $candidate_transaction_id );
		if (
			$uuid !== $operation_uuid ||
			1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid ) ||
			null === $candidate ||
			$actor_user_id <= 0
		) {
			return self::invalidRequest();
		}

		return array(
			'operation_uuid'           => $uuid,
			'candidate_transaction_id' => $candidate,
			'actor_user_id'             => $actor_user_id,
		);
	}

	/** @param array<string,mixed> $request @return array<string,mixed>|\WP_Error */
	private function normalizeCommitRequest( array $request, int $actor_user_id ) {
		$required = array(
			'operation_uuid',
			'candidate_transaction_id',
			'challenge',
			'confirmation_phrase',
			'parent_attestation',
		);
		if ( array_diff( $required, array_keys( $request ) ) || array_diff( array_keys( $request ), $required ) ) {
			return self::invalidRequest();
		}
		if (
			! is_string( $request['operation_uuid'] ) ||
			! is_string( $request['candidate_transaction_id'] ) ||
			! is_string( $request['challenge'] ) ||
			! is_string( $request['confirmation_phrase'] ) ||
			! is_bool( $request['parent_attestation'] ) ||
			1 !== preg_match( '/\A[a-f0-9]{64,128}\z/', $request['challenge'] ) ||
			0 !== strlen( $request['challenge'] ) % 2
		) {
			return self::invalidRequest();
		}

		$identity = $this->normalizeInput(
			$request['operation_uuid'],
			$request['candidate_transaction_id'],
			$actor_user_id
		);
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		return array_merge(
			$identity,
			array(
				'challenge'           => $request['challenge'],
				'confirmation_phrase' => $request['confirmation_phrase'],
				'parent_attestation'  => $request['parent_attestation'],
			)
		);
	}

	/** @return array<string,mixed> */
	private function recordLocally( string $operation_uuid, array $resolution, bool $replayed ): array {
		try {
			$local = ( $this->local_recorder )( $operation_uuid );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$local = new \WP_Error( 'ys_helcim_local_recording_failed', 'Local recording failed.' );
		}

		$result = array(
			'status'         => 'resolved',
			'operation_uuid' => $operation_uuid,
			'remote_status'  => 'succeeded',
			'replayed'       => $replayed,
		);
		if ( is_wp_error( $local ) ) {
			$result['local_recording_status'] = 'attention_required';
			$result['local_error_code']       = self::safeErrorCode( $local->get_error_code() );
			return $result;
		}
		if ( ! is_array( $local ) ) {
			$result['local_recording_status'] = 'attention_required';
			$result['local_error_code']       = 'ys_helcim_local_recording_failed';
			return $result;
		}

		$result['local_recording_status'] = 'continued';
		$result['local']                  = $local;
		return $result;
	}

	private function now(): \DateTimeImmutable|\WP_Error {
		try {
			$value = ( $this->clock )();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::storeUnavailable();
		}
		if ( ! is_int( $value ) || $value < 0 ) {
			return self::storeUnavailable();
		}

		return ( new \DateTimeImmutable( '@' . $value ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
	}

	private static function confirmationPhrase( string $operation_uuid, string $candidate_id, bool $attestation ): string {
		return ( $attestation ? 'ATTEST AND RESOLVE ' : 'RESOLVE ' )
			. $operation_uuid
			. ' WITH HELCIM '
			. $candidate_id;
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

	private static function sqlDate( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return null;
		}
		return $date->format( 'Y-m-d H:i:s' ) === $value ? $value : null;
	}

	private static function safeErrorCode( mixed $code ): string {
		$code = is_string( $code ) ? strtolower( $code ) : '';
		$code = preg_replace( '/[^a-z0-9_-]/', '', $code ) ?? '';
		return '' === $code ? 'ys_helcim_local_recording_failed' : substr( $code, 0, 100 );
	}

	private static function invalidRequest(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_invalid_resolution_request',
			__( 'The refund-resolution request is invalid.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function operationConflict(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_resolution_operation_conflict',
			__( 'The refund operation is not an active indeterminate operation.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function credentialUnavailable(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_resolution_credential_unavailable',
			__( 'The stored gateway and payment mode do not have an available Helcim credential.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function providerUnavailable(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_resolution_provider_unavailable',
			__( 'The exact Helcim transactions could not be read safely.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function challengeUnavailable(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_resolution_challenge_unavailable',
			__( 'A secure refund-resolution challenge could not be issued.', 'ys-helcim-via-fluentcart' )
		);
	}

	private static function storeUnavailable(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_resolution_store_unavailable',
			__( 'The refund-resolution journal is unavailable.', 'ys-helcim-via-fluentcart' )
		);
	}
}
