<?php
/**
 * Read-only payment status for a checkout whose browser-side result is uncertain.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Checkout;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimPurchaseConfirmationToken;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationState;
use YangSheep\Helcim\FluentCart\Support\YSHelcimTransactionId;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers "what actually happened to this payment?" from durable local state only.
 *
 * The browser asks after a confirmation it could not trust: a Helcim webhook that
 * finished the same payment first, a dropped response, or a failure before any
 * charge. It never contacts Helcim and never changes state. The operation journal
 * row is written BEFORE any charge request, so a pending transaction without one
 * provably had no charge attempted and the shopper may safely try again.
 */
final class YSHelcimPaymentStatusService {

	public const AJAX_ACTION = 'ys_helcim_fct_payment_status';

	/** A shopper may keep the hosted payment window open for up to an hour. */
	public const STATUS_TOKEN_LIFETIME_SECONDS = 7200;

	private const GATEWAYS = array( 'ys_helcim', 'ys_helcim_js' );

	/** @var callable */
	private $transaction_loader;

	/** @var callable */
	private $order_loader;

	public function __construct(
		private YSHelcimOperationRepository $operations,
		private YSHelcimPurchaseConfirmationToken $tokens,
		?callable $transaction_loader = null,
		?callable $order_loader = null
	) {
		$this->transaction_loader = $transaction_loader ?? static fn ( string $transaction_uuid ) => OrderTransaction::query()
			->where( 'uuid', $transaction_uuid )
			->where( 'transaction_type', Status::TRANSACTION_TYPE_CHARGE )
			->first();
		$this->order_loader       = $order_loader ?? static fn ( int $order_id ) => Order::query()
			->where( 'id', $order_id )
			->first();
	}

	/** Transaction-bound status tokens, domain-separated from confirmation tokens. */
	public static function statusTokens(): YSHelcimPurchaseConfirmationToken {
		return new YSHelcimPurchaseConfirmationToken(
			null,
			static fn (): string => hash( 'sha256', wp_salt( 'auth' ) . '|ys-helcim-payment-status-v1', true ),
			self::STATUS_TOKEN_LIFETIME_SECONDS
		);
	}

	/** @return array{http:int,body:array<string,mixed>} */
	public function status( string $transaction_uuid, string $status_token ): array {
		$transaction_uuid = trim( $transaction_uuid );
		if ( '' === $transaction_uuid || strlen( $transaction_uuid ) > 191 ) {
			return self::sessionInvalid();
		}

		try {
			$transaction = ( $this->transaction_loader )( $transaction_uuid );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::pending( 'status_unavailable' );
		}
		if (
			! $transaction instanceof OrderTransaction ||
			! in_array( (string) ( $transaction->payment_method ?? '' ), self::GATEWAYS, true ) ||
			! $this->tokens->verify( $status_token, (string) $transaction->uuid, (int) $transaction->id )
		) {
			return self::sessionInvalid();
		}

		if ( Status::TRANSACTION_SUCCEEDED === (string) $transaction->status ) {
			return $this->paidResponse( $transaction ) ?? self::pending( 'finalizing' );
		}

		try {
			$attempts = $this->operations->findPurchasesByIdentity( (int) $transaction->id );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$attempts = null;
		}
		if ( ! is_array( $attempts ) ) {
			return self::pending( 'status_unavailable' );
		}

		$latest = null;
		foreach ( $attempts as $attempt ) {
			if ( is_array( $attempt ) && (string) ( $attempt['gateway'] ?? '' ) === (string) $transaction->payment_method ) {
				$latest = $attempt;
			}
		}
		if ( null === $latest ) {
			return Status::TRANSACTION_PENDING === (string) $transaction->status
				? self::retry( 'no_payment_attempt', __( 'No payment was taken. Please check your card details and try again.', 'ys-helcim-via-fluentcart' ) )
				: self::pending( 'transaction_state_unknown' );
		}

		return match ( (string) ( $latest['remote_status'] ?? '' ) ) {
			YSHelcimOperationState::REMOTE_SUCCEEDED => self::pending( 'finalizing' ),
			YSHelcimOperationState::REMOTE_DECLINED => self::retry(
				'payment_declined',
				__( 'The card was declined and no payment was taken. Please check the details or use a different card.', 'ys-helcim-via-fluentcart' )
			),
			YSHelcimOperationState::REMOTE_FAILED,
			YSHelcimOperationState::REMOTE_EXPIRED => self::retry(
				'payment_not_completed',
				__( 'The payment could not be completed and no payment was taken. Please try again.', 'ys-helcim-via-fluentcart' )
			),
			YSHelcimOperationState::REMOTE_CANCELED => self::retry(
				'payment_window_expired',
				__( 'The previous payment window expired without a payment. Please try again.', 'ys-helcim-via-fluentcart' )
			),
			default => self::pending( 'verifying' ),
		};
	}

	/** @return array{http:int,body:array<string,mixed>}|null */
	private function paidResponse( OrderTransaction $transaction ): ?array {
		if ( null === YSHelcimTransactionId::normalize( $transaction->vendor_charge_id ?? null ) ) {
			return null;
		}

		try {
			$order = ( $this->order_loader )( (int) $transaction->order_id );
			$receipt_url = $order instanceof Order ? (string) $transaction->getReceiptPageUrl( true ) : '';
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return null;
		}
		if (
			! $order instanceof Order ||
			(int) ( $order->id ?? 0 ) !== (int) $transaction->order_id ||
			'' === trim( (string) ( $order->uuid ?? '' ) ) ||
			'' === $receipt_url
		) {
			return null;
		}

		return array(
			'http' => 200,
			'body' => array(
				'status'       => 'success',
				'redirect_url' => $receipt_url,
				'message'      => __( 'Payment successful! Taking you to the order confirmation.', 'ys-helcim-via-fluentcart' ),
				'order'        => array( 'uuid' => (string) $order->uuid ),
			),
		);
	}

	/** @return array{http:int,body:array<string,mixed>} */
	private static function pending( string $code ): array {
		return array(
			'http' => 200,
			'body' => array(
				'status'  => 'pending',
				'code'    => $code,
				'message' => __( 'We are still confirming your payment. Please do not pay again. You will receive an email receipt once it is confirmed, or you can contact the store.', 'ys-helcim-via-fluentcart' ),
			),
		);
	}

	/** @return array{http:int,body:array<string,mixed>} */
	private static function retry( string $code, string $message ): array {
		return array(
			'http' => 200,
			'body' => array(
				'status'        => 'failed',
				'retry_allowed' => true,
				'code'          => $code,
				'message'       => $message,
			),
		);
	}

	/** @return array{http:int,body:array<string,mixed>} */
	private static function sessionInvalid(): array {
		return array(
			'http' => 403,
			'body' => array(
				'status'        => 'failed',
				'retry_allowed' => false,
				'code'          => 'status_session_invalid',
				'message'       => __( 'This payment page has expired. Please refresh the page to check your order before paying again.', 'ys-helcim-via-fluentcart' ),
			),
		);
	}
}
