<?php
/**
 * Normalized result of a remote Helcim refund or reversal attempt.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

use YangSheep\Helcim\FluentCart\Support\YSHelcimSanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries only evidence that is safe for the refund state machine to consume.
 */
final class YSHelcimRefundResult {

	public const SUCCEEDED        = 'succeeded';
	public const DECLINED         = 'declined';
	public const FAILED           = 'failed';
	public const INDETERMINATE    = 'indeterminate';
	public const REQUIRES_REVERSE = 'requires_reverse';

	/**
	 * @param string      $status                Normalized result status.
	 * @param string|null $vendor_transaction_id Positive Helcim transaction ID after proven success.
	 * @param string|null $error_code            Bounded internal error code.
	 * @param string|null $message               Bounded, sanitized operator-facing message.
	 */
	public function __construct(
		private string $status,
		private ?string $vendor_transaction_id = null,
		private ?string $error_code = null,
		private ?string $message = null,
		private ?string $refund_operation_uuid = null,
		private ?string $effective_operation_uuid = null,
		private ?string $provider_action = null
	) {
		$allowed = array(
			self::SUCCEEDED,
			self::DECLINED,
			self::FAILED,
			self::INDETERMINATE,
			self::REQUIRES_REVERSE,
		);

		if ( ! in_array( $status, $allowed, true ) ) {
			throw new \InvalidArgumentException( 'Unsupported Helcim refund result status.' );
		}

		$is_positive_provider_id = null !== $vendor_transaction_id
			&& 1 === preg_match( '/\A[1-9][0-9]*\z/', $vendor_transaction_id );
		if ( ( self::SUCCEEDED === $status ) !== $is_positive_provider_id ) {
			throw new \InvalidArgumentException( 'Only a proven success may carry a positive provider transaction ID.' );
		}

		if ( null !== $provider_action && ! in_array( $provider_action, array( 'refund', 'reverse' ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported Helcim refund provider action.' );
		}

		if ( null !== $this->message ) {
			$this->message = YSHelcimSanitizer::errorText( $this->message );
		}
	}

	public function status(): string {
		return $this->status;
	}

	public function vendorTransactionId(): ?string {
		return $this->vendor_transaction_id;
	}

	public function errorCode(): ?string {
		return $this->error_code;
	}

	public function message(): ?string {
		return $this->message;
	}

	public function refundOperationUuid(): ?string {
		return $this->refund_operation_uuid;
	}

	public function effectiveOperationUuid(): ?string {
		return $this->effective_operation_uuid;
	}

	public function providerAction(): ?string {
		return $this->provider_action;
	}

	public function withOperationContext( string $refund_operation_uuid, string $effective_operation_uuid, string $provider_action ): self {
		return new self(
			$this->status,
			$this->vendor_transaction_id,
			$this->error_code,
			$this->message,
			$refund_operation_uuid,
			$effective_operation_uuid,
			$provider_action
		);
	}
}
