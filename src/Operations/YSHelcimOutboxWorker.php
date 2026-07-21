<?php
/**
 * Executes one claimed Helcim outbox effect.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Operations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YSHelcimOutboxWorker {
	/** @var callable|null */
	private $finalizer;

	/** @var callable|null */
	private $integrity_verifier;

	/** @param array<string,callable> $handlers */
	public function __construct(
		private YSHelcimOutboxRepository $outbox,
		private array $handlers,
		?callable $finalizer = null,
		?callable $integrity_verifier = null
	) {
		$this->finalizer          = $finalizer;
		$this->integrity_verifier = $integrity_verifier;
	}

	/** @return array|\WP_Error|null */
	public function runOnce( string $operation_uuid ) {
		$effect = $this->outbox->claimNext( $operation_uuid );
		if ( is_wp_error( $effect ) ) {
			return $effect;
		}
		if ( null === $effect ) {
			return $this->finalize( $operation_uuid );
		}

		$payload = $this->decodePayload( $effect );
		if ( is_wp_error( $payload ) ) {
			return $this->rejectBeforeHandler( $effect, $payload );
		}

		if ( null !== $this->integrity_verifier ) {
			try {
				$verified = ( $this->integrity_verifier )( $effect, $payload );
			} catch ( \Throwable $exception ) {
				unset( $exception );
				$verified = self::integrityUnavailable();
			}
			if ( is_wp_error( $verified ) ) {
				return $this->rejectBeforeHandler( $effect, $verified );
			}
			if ( true !== $verified && ! is_array( $verified ) ) {
				return $this->rejectBeforeHandler( $effect, self::integrityUnavailable() );
			}
		}

		$handler = $this->handlers[ (string) $effect['effect_type'] ] ?? null;
		if ( ! is_callable( $handler ) ) {
			$failed = $this->outbox->fail(
				(int) $effect['id'],
				(string) $effect['claim_token'],
				'missing_effect_handler',
				'No handler is registered for this refund effect.'
			);
			return $this->settledResult( $operation_uuid, $effect, $failed );
		}

		try {
			$result = $handler( $payload, $effect );
			if ( is_wp_error( $result ) ) {
				$data = $result->get_error_data();
				$failed = $this->outbox->fail(
					(int) $effect['id'],
					(string) $effect['claim_token'],
					$result->get_error_code(),
					$result->get_error_message(),
					is_array( $data ) && true === ( $data['retryable'] ?? false )
				);
				return $this->settledResult( $operation_uuid, $effect, $failed );
			}
			$completed = $this->outbox->complete( (int) $effect['id'], (string) $effect['claim_token'], $result );
			return $this->settledResult( $operation_uuid, $effect, $completed );
		} catch ( \Throwable $exception ) {
			$failed = $this->outbox->fail(
				(int) $effect['id'],
				(string) $effect['claim_token'],
				'effect_exception',
				$exception->getMessage()
			);
			return $this->settledResult( $operation_uuid, $effect, $failed );
		}
	}

	/** @param array<string,mixed> $effect @return array<string,mixed>|\WP_Error */
	private function settledResult( string $operation_uuid, array $effect, mixed $settled ) {
		if ( is_wp_error( $settled ) ) {
			return $settled;
		}
		if ( true !== $settled ) {
			return new \WP_Error(
				'ys_helcim_effect_claim_lost',
				__( 'The refund effect claim changed before it could be saved.', 'ys-helcim-via-fluentcart' )
			);
		}

		$finalization = $this->finalize( $operation_uuid );
		if ( is_wp_error( $finalization ) ) {
			return $finalization;
		}
		if ( null !== $this->finalizer ) {
			$effect['finalization'] = $finalization;
		}

		return $effect;
	}

	/** @return mixed */
	private function finalize( string $operation_uuid ) {
		return null === $this->finalizer ? null : ( $this->finalizer )( $operation_uuid );
	}

	/** @param array<string,mixed> $effect @return array<string,mixed>|\WP_Error */
	private function decodePayload( array $effect ) {
		$payload      = $effect['payload'] ?? null;
		$payload_hash = $effect['payload_hash'] ?? null;
		if (
			! is_string( $payload ) ||
			! is_string( $payload_hash ) ||
			1 !== preg_match( '/\A[a-f0-9]{64}\z/', $payload_hash ) ||
			! hash_equals( $payload_hash, hash( 'sha256', $payload ) )
		) {
			return self::invalidPayload();
		}

		try {
			$decoded = json_decode( $payload, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			unset( $exception );
			return self::invalidPayload();
		}

		return is_array( $decoded ) ? $decoded : self::invalidPayload();
	}

	private static function invalidPayload(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_effect_payload_invalid',
			__( 'The saved refund effect failed integrity validation.', 'ys-helcim-via-fluentcart' )
		);
	}

	/** @param array<string,mixed> $effect */
	private function rejectBeforeHandler( array $effect, \WP_Error $error ): \WP_Error {
		$failed = $this->outbox->fail(
			(int) $effect['id'],
			(string) $effect['claim_token'],
			$error->get_error_code(),
			$error->get_error_message()
		);
		if ( is_wp_error( $failed ) ) {
			return $failed;
		}
		if ( true !== $failed ) {
			return new \WP_Error(
				'ys_helcim_effect_claim_lost',
				__( 'The refund effect claim changed before it could be saved.', 'ys-helcim-via-fluentcart' )
			);
		}

		return $error;
	}

	private static function integrityUnavailable(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_effect_integrity_unavailable',
			__( 'The refund effect could not be matched to its local receipt.', 'ys-helcim-via-fluentcart' )
		);
	}
}
