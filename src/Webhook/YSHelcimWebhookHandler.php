<?php
/**
 * Transport-independent Helcim webhook boundary.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Webhook;

use YangSheep\Helcim\FluentCart\Support\YSHelcimTransactionId;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies the signed event, fetches provider-owned proof, and delegates only
 * an exact transaction match to a channel-specific reconciler.
 */
final class YSHelcimWebhookHandler {

	private const MAX_BODY_BYTES = 1048576;

	/** @var callable */
	private $credential_resolver;

	/** @var callable */
	private $signature_verifier;

	/** @var callable */
	private $transaction_lookup;

	/** @var callable */
	private $reconciler;

	/** @var callable */
	private $correlation_binding_resolver;

	/** @var callable|null */
	private $completed_receipt_reader;

	/** @var callable|null */
	private $completed_receipt_writer;

	public function __construct(
		callable $credential_resolver,
		callable $signature_verifier,
		callable $transaction_lookup,
		callable $reconciler,
		callable $correlation_binding_resolver,
		?callable $completed_receipt_reader = null,
		?callable $completed_receipt_writer = null
	) {
		$this->credential_resolver = $credential_resolver;
		$this->signature_verifier  = $signature_verifier;
		$this->transaction_lookup  = $transaction_lookup;
		$this->reconciler           = $reconciler;
		$this->correlation_binding_resolver = $correlation_binding_resolver;
		$this->completed_receipt_reader = $completed_receipt_reader;
		$this->completed_receipt_writer = $completed_receipt_writer;
	}

	/** @return array{status:int,body:array{message:string}} */
	public function handle( string $raw_body, array $headers ): array {
		if ( strlen( $raw_body ) > self::MAX_BODY_BYTES ) {
			return self::response( 413, 'payload too large' );
		}

		try {
			$candidates = ( $this->credential_resolver )();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$candidates = null;
		}
		$credentials = self::credentialGroups( $candidates );
		if ( array() === $credentials ) {
			return self::response( 503, 'webhook credentials unavailable' );
		}

		$verified_credentials = array();
		foreach ( $credentials as $credential ) {
			try {
				$verified = ( $this->signature_verifier )( $headers, $raw_body, $credential['verifier_token'] );
			} catch ( \Throwable $exception ) {
				unset( $exception );
				$verified = false;
			}
			if ( true === $verified ) {
				$verified_credentials[] = $credential;
			}
		}
		if ( array() === $verified_credentials ) {
			return self::response( 401, 'signature verification failed' );
		}

		try {
			$event = json_decode( $raw_body, true, 32, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			unset( $exception );
			return self::response( 400, 'malformed payload' );
		}
		if ( ! is_array( $event ) || array_is_list( $event ) ) {
			return self::response( 400, 'malformed payload' );
		}

		$receipt_key = null;
		if ( null !== $this->completed_receipt_reader || null !== $this->completed_receipt_writer ) {
			if ( ! is_callable( $this->completed_receipt_reader ) || ! is_callable( $this->completed_receipt_writer ) ) {
				return self::response( 503, 'webhook receipt storage unavailable' );
			}
			$receipt_key = self::receiptKey( $headers, $raw_body );
			if ( null === $receipt_key ) {
				return self::response( 400, 'invalid webhook id' );
			}
			try {
				$completed = ( $this->completed_receipt_reader )( $receipt_key );
			} catch ( \Throwable $exception ) {
				unset( $exception );
				$completed = null;
			}
			if ( true === $completed ) {
				return self::response( 200, 'duplicate' );
			}
			if ( false !== $completed ) {
				return self::response( 503, 'webhook receipt storage unavailable' );
			}
		}

		if ( 'cardTransaction' !== ( $event['type'] ?? null ) ) {
			return $this->completeReceipt( $receipt_key, 'ignored' );
		}

		$transaction_id = YSHelcimTransactionId::normalize( $event['id'] ?? null );
		if ( null === $transaction_id ) {
			return self::response( 400, 'invalid transaction id' );
		}

		$matches       = array();
		$lookup_failed = false;
		foreach ( $verified_credentials as $credential ) {
			try {
				$proof = ( $this->transaction_lookup )( $transaction_id, $credential['api_token'] );
			} catch ( \Throwable $exception ) {
				unset( $exception );
				$proof = new \WP_Error( 'ys_helcim_webhook_lookup_failed', 'Provider lookup failed.' );
			}
			if ( is_wp_error( $proof ) || ! is_array( $proof ) ) {
				$lookup_failed = true;
				continue;
			}
			if ( array_key_exists( 'data', $proof ) ) {
				if ( ! is_array( $proof['data'] ) || array_is_list( $proof['data'] ) ) {
					continue;
				}
				$proof = $proof['data'];
			}
			$proved_id = YSHelcimTransactionId::normalize( $proof['transactionId'] ?? null );
			if ( null !== $proved_id && hash_equals( $transaction_id, $proved_id ) ) {
				$matches[] = array( 'proof' => $proof, 'bindings' => $credential['bindings'] );
			}
		}

		if ( array() === $matches ) {
			return self::response( $lookup_failed ? 502 : 400, $lookup_failed ? 'transaction lookup failed' : 'transaction proof mismatch' );
		}

		$purchase_matches = array_values(
			array_filter(
				$matches,
				static function ( array $match ): bool {
					$type = $match['proof']['type'] ?? null;
					return null === $type || 'purchase' === strtolower( trim( (string) $type ) );
				}
			)
		);
		if ( array() === $purchase_matches ) {
			if ( $lookup_failed ) {
				return self::response( 502, 'transaction lookup incomplete' );
			}
			return $this->completeReceipt( $receipt_key, 'ignored' );
		}
		$matches = $purchase_matches;

		$eligible_matches = array();
		$binding_unavailable = false;
		$binding_conflict    = false;
		foreach ( $matches as $match ) {
			try {
				$resolution = ( $this->correlation_binding_resolver )( $match['proof'] );
			} catch ( \Throwable $exception ) {
				unset( $exception );
				$resolution = array( 'status' => 'unavailable' );
			}
			$status  = is_array( $resolution ) ? ( $resolution['status'] ?? null ) : null;
			$binding = is_array( $resolution ) ? ( $resolution['binding'] ?? null ) : null;
			if ( 'matched' === $status && self::isBinding( $binding ) && self::containsBinding( $match['bindings'], $binding ) ) {
				$eligible_matches[] = $match;
			} elseif ( 'unavailable' === $status ) {
				$binding_unavailable = true;
			} elseif ( 'conflict' === $status || 'matched' === $status || ! in_array( $status, array( 'unrelated' ), true ) ) {
				$binding_conflict = true;
			}
		}
		if ( array() === $eligible_matches ) {
			if ( $binding_unavailable ) {
				return self::response( 503, 'transaction operation binding unavailable' );
			}
			if ( $binding_conflict ) {
				return self::response( 409, 'transaction operation binding conflict' );
			}
			if ( $lookup_failed ) {
				return self::response( 502, 'transaction lookup incomplete' );
			}
			return $this->completeReceipt( $receipt_key, 'ignored' );
		}
		if ( count( $eligible_matches ) > 1 ) {
			return self::response( 409, 'transaction account is ambiguous' );
		}
		$match = $eligible_matches[0];

		try {
			$result = ( $this->reconciler )( $match['proof'], $transaction_id, $match['bindings'] );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$result = null;
		}
		if (
			! is_array( $result ) ||
			! is_int( $result['code'] ?? null ) ||
			! in_array( $result['code'], array( 200, 400, 409, 422, 500, 502, 503 ), true ) ||
			! is_string( $result['message'] ?? null ) ||
			'' === trim( $result['message'] ) ||
			strlen( $result['message'] ) > 200 ||
			1 === preg_match( '/[\x00-\x1F\x7F]/', $result['message'] )
		) {
			return self::response( 500, 'local reconciliation failed' );
		}

		return 200 === $result['code']
			? $this->completeReceipt( $receipt_key, trim( $result['message'] ) )
			: self::response( $result['code'], trim( $result['message'] ) );
	}

	/** @return array{status:int,body:array{message:string}} */
	private function completeReceipt( ?string $receipt_key, string $message ): array {
		if ( null !== $receipt_key ) {
			try {
				$stored = ( $this->completed_receipt_writer )( $receipt_key );
			} catch ( \Throwable $exception ) {
				unset( $exception );
				$stored = false;
			}
			if ( true !== $stored ) {
				return self::response( 503, 'webhook receipt storage unavailable' );
			}
		}

		return self::response( 200, $message );
	}

	private static function receiptKey( array $headers, string $raw_body ): ?string {
		$webhook_id = null;
		foreach ( $headers as $name => $value ) {
			if ( is_string( $name ) && 'webhook-id' === str_replace( '_', '-', strtolower( $name ) ) ) {
				if ( is_array( $value ) ) {
					$value = reset( $value );
				}
				$candidate = is_scalar( $value ) ? trim( (string) $value ) : null;
				if ( null !== $webhook_id && ( ! is_string( $candidate ) || ! hash_equals( $webhook_id, $candidate ) ) ) {
					return null;
				}
				$webhook_id = $candidate;
			}
		}
		if (
			! is_string( $webhook_id ) ||
			'' === $webhook_id ||
			strlen( $webhook_id ) > 255 ||
			1 === preg_match( '/[\x00-\x1F\x7F]/', $webhook_id )
		) {
			return null;
		}

		return hash( 'sha256', "ys-helcim-webhook-receipt-v1\0" . $webhook_id . "\0" . hash( 'sha256', $raw_body ) );
	}

	/** @return array<int,array{verifier_token:string,api_token:string,bindings:array<int,array{gateway:string,mode:string}>}> */
	private static function credentialGroups( mixed $candidates ): array {
		if ( ! is_array( $candidates ) || ! array_is_list( $candidates ) || count( $candidates ) > 8 ) {
			return array();
		}

		$groups = array();
		foreach ( $candidates as $candidate ) {
			if (
				! is_array( $candidate ) ||
				! in_array( $candidate['gateway'] ?? null, array( 'ys_helcim', 'ys_helcim_js' ), true ) ||
				! in_array( $candidate['mode'] ?? null, array( 'test', 'live' ), true ) ||
				! is_string( $candidate['verifier_token'] ?? null ) ||
				! is_string( $candidate['api_token'] ?? null )
			) {
				continue;
			}
			$verifier_token = trim( $candidate['verifier_token'] );
			$api_token      = trim( $candidate['api_token'] );
			if ( '' === $verifier_token || '' === $api_token ) {
				continue;
			}
			$key = hash( 'sha256', $verifier_token . "\0" . $api_token );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'verifier_token' => $verifier_token,
					'api_token'      => $api_token,
					'bindings'       => array(),
				);
			}
			$binding = array( 'gateway' => $candidate['gateway'], 'mode' => $candidate['mode'] );
			if ( ! in_array( $binding, $groups[ $key ]['bindings'], true ) ) {
				$groups[ $key ]['bindings'][] = $binding;
			}
		}

		return array_values( $groups );
	}

	private static function isBinding( mixed $binding ): bool {
		return is_array( $binding ) &&
			array( 'gateway', 'mode' ) === array_keys( $binding ) &&
			in_array( $binding['gateway'], array( 'ys_helcim', 'ys_helcim_js' ), true ) &&
			in_array( $binding['mode'], array( 'test', 'live' ), true );
	}

	/** @param array<int,array{gateway:string,mode:string}> $bindings */
	private static function containsBinding( array $bindings, array $expected ): bool {
		$matches = array_values(
			array_filter(
				$bindings,
				static fn ( mixed $binding ): bool => self::isBinding( $binding ) && $binding === $expected
			)
		);

		return 1 === count( $matches );
	}

	/** @return array{status:int,body:array{message:string}} */
	private static function response( int $status, string $message ): array {
		return array(
			'status' => $status,
			'body'   => array( 'message' => $message ),
		);
	}
}
