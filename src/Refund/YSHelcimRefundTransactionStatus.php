<?php
/**
 * Read-only, order-bound Helcim payment lookup for the refund dialog.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

use YangSheep\Helcim\FluentCart\Support\YSHelcimTransactionId;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YSHelcimRefundTransactionStatus {

	public function __construct(
		private \Closure $context_loader,
		private \Closure $credential_resolver,
		private \Closure $provider_reader
	) {}

	/**
	 * Never syncs refunds, creates journal rows, or sends a payment mutation.
	 * The source transaction does not prove the remaining balance at Helcim:
	 * the displayed limit is explicitly the locally recorded refund allowance.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function load( int $order_id, int $transaction_id ): array|\WP_Error {
		try {
			$context = ( $this->context_loader )( $transaction_id );
			if (
				! is_array( $context ) ||
				$order_id !== ( $context['order_id'] ?? null ) ||
				$transaction_id !== ( $context['transaction_id'] ?? null ) ||
				! in_array( $context['gateway'] ?? '', array( 'ys_helcim', 'ys_helcim_js' ), true ) ||
				! in_array( $context['payment_mode'] ?? '', array( 'live', 'test' ), true ) ||
				! in_array( $context['currency'] ?? '', array( 'USD', 'CAD' ), true ) ||
				'succeeded' !== ( $context['status'] ?? '' ) ||
				'charge' !== ( $context['transaction_type'] ?? '' ) ||
				! is_int( $context['transaction_total'] ?? null ) ||
				$context['transaction_total'] <= 0 ||
				! is_int( $context['remaining_refundable'] ?? null ) ||
				$context['remaining_refundable'] < 0 ||
				$context['remaining_refundable'] > $context['transaction_total']
			) {
				return self::error( 'context', 409 );
			}
			$source_id = YSHelcimTransactionId::normalize( $context['vendor_transaction_id'] ?? null );
			if ( null === $source_id ) {
				return self::error( 'context', 409 );
			}
			$credential = ( $this->credential_resolver )( $context['gateway'], $context['payment_mode'] );
			if (
				! is_array( $credential ) ||
				! is_string( $credential['api_token'] ?? null ) ||
				'' === trim( $credential['api_token'] ) ||
				$context['payment_mode'] !== ( $credential['current_mode'] ?? null )
			) {
				return self::error( 'credential' );
			}
			$source = $this->read( 'card-transactions/' . rawurlencode( $source_id ), $credential['api_token'] );
			if ( is_wp_error( $source ) ) {
				return $source;
			}
			if (
				$source_id !== YSHelcimTransactionId::normalize( $source['transactionId'] ?? null ) ||
				$context['transaction_total'] !== YSHelcimProviderProof::amountToCents( $source['amount'] ?? null ) ||
				$context['currency'] !== ( $source['currency'] ?? null ) ||
				! in_array( $source['type'] ?? '', array( 'purchase', 'capture' ), true )
			) {
				return self::error( 'mismatch', 409 );
			}
			$status = in_array( $source['status'] ?? '', array( 'APPROVED', 'DECLINED', 'VOIDED', 'REFUNDED', 'REVERSED' ), true )
				? $source['status'] : 'UNKNOWN';
			$batch_state = 'unknown';
			if ( 'APPROVED' === $status ) {
				$batch_id = YSHelcimTransactionId::normalize( $source['cardBatchId'] ?? null );
				if ( null === $batch_id ) {
					return self::error( 'response' );
				}
				$batch = $this->read( 'card-batches/' . rawurlencode( $batch_id ), $credential['api_token'] );
				if ( is_wp_error( $batch ) ) {
					return $batch;
				}
				if ( $batch_id !== YSHelcimTransactionId::normalize( $batch['id'] ?? null ) || ! is_bool( $batch['closed'] ?? null ) ) {
					return self::error( 'response' );
				}
				$batch_state = $batch['closed'] ? 'closed' : 'open';
			}
			$mode = 'unavailable';
			if ( 'APPROVED' === $status && $context['remaining_refundable'] > 0 ) {
				if ( 'closed' === $batch_state ) {
					$mode = 'refund';
				} elseif ( 'open' === $batch_state && $context['remaining_refundable'] === $context['transaction_total'] ) {
					$mode = 'full_void';
				}
			}
			// Explicit projection: no credentials, card data, customer data, invoice, or raw errors.
			return array(
				'order_id'         => $order_id,
				'transaction_id'   => $transaction_id,
				'provider_id'      => $source_id,
				'provider_status'  => $status,
				'amount'           => $context['transaction_total'],
				'currency'         => $context['currency'],
				'local_remaining'  => $context['remaining_refundable'],
				'batch_state'      => $batch_state,
				'refund_mode'      => $mode,
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::error( 'unavailable' );
		}
	}

	/** @return array<string,mixed>|\WP_Error */
	private function read( string $endpoint, string $token ): array|\WP_Error {
		$result = ( $this->provider_reader )( $endpoint, $token );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$http_code = is_array( $data ) ? ( $data['http_code'] ?? 0 ) : 0;
			return self::error( match ( $http_code ) {
				401, 403 => 'credential',
				404 => 'missing',
				429 => 'rate_limit',
				default => 'unavailable',
			} );
		}
		if ( is_array( $result ) && array_key_exists( 'data', $result ) ) {
			$result = $result['data'];
		}
		return is_array( $result ) && ! array_is_list( $result ) ? $result : self::error( 'response' );
	}

	public static function error( string $reason, int $status = 503 ): \WP_Error {
		$message = match ( $reason ) {
			'context' => __( 'This payment no longer matches the order records. Reload the order before refunding.', 'ys-helcim-via-fluentcart' ),
			'credential' => __( 'Helcim transaction lookup could not authenticate. Check the API token, permissions, and payment mode.', 'ys-helcim-via-fluentcart' ),
			'mismatch' => __( 'The Helcim transaction does not match this payment. No refund was sent.', 'ys-helcim-via-fluentcart' ),
			'missing' => __( 'Helcim could not find this transaction. Check the payment account and mode.', 'ys-helcim-via-fluentcart' ),
			'rate_limit' => __( 'Helcim is receiving too many requests. Wait a moment, then check the transaction again.', 'ys-helcim-via-fluentcart' ),
			'response' => __( 'Helcim returned incomplete transaction or batch details. Check again before refunding.', 'ys-helcim-via-fluentcart' ),
			default => __( 'Could not read the transaction from Helcim. No refund was sent. Check again in a moment.', 'ys-helcim-via-fluentcart' ),
		};
		return new \WP_Error( 'ys_helcim_transaction_' . $reason, $message, array( 'status' => $status ) );
	}
}
