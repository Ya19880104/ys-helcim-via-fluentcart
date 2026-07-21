<?php
/**
 * Canonical server-owned identity for one FluentCart purchase operation.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Operations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a durable purchase identity without accepting or retaining card data.
 */
final class YSHelcimPurchaseOperation {

	/** @var string[] */
	private const IDENTITY_FIELDS = array(
		'gateway',
		'order_id',
		'transaction_id',
		'transaction_uuid',
		'amount',
		'currency',
		'payment_mode',
	);

	/** @param array<string, int|string> $identity */
	private function __construct(
		private array $identity,
		private string $scope_key
	) {
	}

	/**
	 * @param array<string, mixed> $transaction Server-loaded FluentCart transaction identity.
	 * @return self|\WP_Error
	 */
	public static function fromTransaction( array $transaction ) {
		if (
			array() !== array_diff( self::IDENTITY_FIELDS, array_keys( $transaction ) ) ||
			array() !== array_diff( array_keys( $transaction ), self::IDENTITY_FIELDS ) ||
			! is_string( $transaction['gateway'] ?? null ) ||
			! is_int( $transaction['order_id'] ?? null ) ||
			! is_int( $transaction['transaction_id'] ?? null ) ||
			! is_string( $transaction['transaction_uuid'] ?? null ) ||
			! is_int( $transaction['amount'] ?? null ) ||
			! is_string( $transaction['currency'] ?? null ) ||
			! is_string( $transaction['payment_mode'] ?? null )
		) {
			return self::invalidPurchase();
		}

		$identity = array(
			'gateway'          => trim( $transaction['gateway'] ),
			'order_id'         => $transaction['order_id'],
			'transaction_id'   => $transaction['transaction_id'],
			'transaction_uuid' => trim( $transaction['transaction_uuid'] ),
			'amount'           => $transaction['amount'],
			'currency'         => strtoupper( trim( $transaction['currency'] ) ),
			'payment_mode'     => strtolower( trim( $transaction['payment_mode'] ) ),
		);

		if (
			! in_array( $identity['gateway'], array( 'ys_helcim', 'ys_helcim_js' ), true ) ||
			$identity['order_id'] <= 0 ||
			$identity['transaction_id'] <= 0 ||
			'' === $identity['transaction_uuid'] ||
			strlen( $identity['transaction_uuid'] ) > 191 ||
			1 === preg_match( '/[\x00-\x1F\x7F]/', $identity['transaction_uuid'] ) ||
			$identity['amount'] <= 0 ||
			! in_array( $identity['currency'], array( 'USD', 'CAD' ), true ) ||
			! in_array( $identity['payment_mode'], array( 'test', 'live' ), true )
		) {
			return self::invalidPurchase();
		}

		$scope_material = self::encode(
			array(
				'version'        => 1,
				'transaction_id' => $identity['transaction_id'],
			)
		);
		if ( null === $scope_material ) {
			return self::invalidPurchase();
		}

		return new self(
			$identity,
			'purchase-transaction-v1:' . hash( 'sha256', $scope_material )
		);
	}

	/** @return array<string, int|string> */
	public function identity(): array {
		return $this->identity;
	}

	public function scopeKey(): string {
		return $this->scope_key;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function repositoryRecord( string $operation_uuid, string $attempt_digest ) {
		$operation_uuid = strtolower( trim( $operation_uuid ) );
		$fingerprint    = $this->requestFingerprint( $attempt_digest );
		if ( null === $fingerprint ) {
			return self::invalidPurchase();
		}

		try {
			$idempotency_key = YSHelcimIdempotency::generate(
				'purchase',
				(string) $this->identity['transaction_uuid'],
				(int) $this->identity['amount'],
				(string) $this->identity['payment_mode'],
				$operation_uuid
			);
		} catch ( \InvalidArgumentException $exception ) {
			unset( $exception );
			return self::invalidPurchase();
		}

		return array_merge(
			array(
				'operation_uuid'     => $operation_uuid,
				'idempotency_key'    => $idempotency_key,
				'scope_key'           => $this->scope_key,
				'operation_type'      => 'purchase',
				'request_fingerprint' => $fingerprint,
			),
			$this->identity
		);
	}

	/** @param array<string, mixed> $row */
	public function matchesIdentityRow( array $row ): bool {
		if (
			'purchase' !== (string) ( $row['operation_type'] ?? '' ) ||
			(string) $this->identity['gateway'] !== (string) ( $row['gateway'] ?? '' ) ||
			(int) $this->identity['order_id'] !== (int) ( $row['order_id'] ?? 0 ) ||
			(int) $this->identity['transaction_id'] !== (int) ( $row['transaction_id'] ?? 0 ) ||
			(string) $this->identity['transaction_uuid'] !== (string) ( $row['transaction_uuid'] ?? '' ) ||
			(int) $this->identity['amount'] !== (int) ( $row['amount'] ?? 0 ) ||
			(string) $this->identity['currency'] !== (string) ( $row['currency'] ?? '' ) ||
			(string) $this->identity['payment_mode'] !== (string) ( $row['payment_mode'] ?? '' ) ||
			null !== ( $row['encrypted_material'] ?? null ) ||
			null !== ( $row['local_payload'] ?? null ) ||
			null !== ( $row['source_vendor_transaction_id'] ?? null ) ||
			1 !== preg_match( '/\A[a-f0-9]{64}\z/', (string) ( $row['request_fingerprint'] ?? '' ) )
		) {
			return false;
		}

		try {
			$stored_scope = YSHelcimOperationScope::fromBusinessKey( (string) ( $row['scope_key'] ?? '' ) );
			$expected_key = YSHelcimIdempotency::generate(
				'purchase',
				(string) $this->identity['transaction_uuid'],
				(int) $this->identity['amount'],
				(string) $this->identity['payment_mode'],
				(string) ( $row['operation_uuid'] ?? '' )
			);
		} catch ( \InvalidArgumentException $exception ) {
			unset( $exception );
			return false;
		}

		return hash_equals( YSHelcimOperationScope::fromBusinessKey( $this->scope_key ), $stored_scope )
			&& hash_equals( $expected_key, (string) ( $row['idempotency_key'] ?? '' ) );
	}

	/** @param array<string, mixed> $row */
	public function matchesRow( array $row, string $attempt_digest ): bool {
		$fingerprint = $this->requestFingerprint( $attempt_digest );
		return null !== $fingerprint
			&& $this->matchesIdentityRow( $row )
			&& hash_equals( $fingerprint, (string) ( $row['request_fingerprint'] ?? '' ) );
	}

	private function requestFingerprint( string $attempt_digest ): ?string {
		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $attempt_digest ) ) {
			return null;
		}

		$material = self::encode(
			array_merge(
				array(
					'version'        => 2,
					'operation_type' => 'purchase',
				),
				$this->identity,
				array( 'attempt_digest' => $attempt_digest )
			)
		);

		return null === $material ? null : hash( 'sha256', $material );
	}

	/** @param array<string, mixed> $value */
	private static function encode( array $value ): ?string {
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) ? $encoded : null;
	}

	private static function invalidPurchase(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_invalid_purchase',
			__( 'The Helcim purchase contains invalid or client-controlled transaction identity.', 'ys-helcim-via-fluentcart' )
		);
	}
}
