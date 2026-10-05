<?php
/**
 * Records refunds and voids that were made directly in Helcim.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Refund;

use YangSheep\Helcim\FluentCart\Operations\YSHelcimIdempotency;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationState;
use YangSheep\Helcim\FluentCart\Support\YSHelcimLogger;
use YangSheep\Helcim\FluentCart\Support\YSHelcimOrderNote;
use YangSheep\Helcim\FluentCart\Support\YSHelcimSanitizer;
use YangSheep\Helcim\FluentCart\Support\YSHelcimTransactionId;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 店家在 Helcim 後台作廢／退款時，外掛的作業日誌沒有這筆紀錄，FluentCart 會一直顯示已付款。
 *
 * 這個服務只接受 Helcim 自己的交易紀錄（webhook 驗簽後 GET 回來的，或手動同步時列出來的），
 * 全部條件成立才新增一筆作業列，再交給既有的本地退款記錄器寫進 FluentCart；
 * 任何一項不成立都只回報，不動帳（fail-closed）。
 */
final class YSHelcimProviderRefundSync {

	public const STATUS_RECORDED         = 'recorded';
	public const STATUS_ALREADY_RECORDED = 'already_recorded';
	public const STATUS_SKIPPED          = 'skipped';
	public const STATUS_UNRELATED        = 'unrelated';
	public const STATUS_RETRY            = 'retry';
	public const STATUS_REVIEW           = 'review';

	private const MAX_PROVIDER_RECORDS = 100;

	/** @var callable */
	private $accounting_reader;

	/** @var callable */
	private $local_recorder;

	/** @var callable|null */
	private $local_failure_recorder;

	/** @var callable|null */
	private $stale_scope_recoverer;

	/** @var callable|null */
	private $order_note_writer;

	/** @var callable|null */
	private $credential_resolver;

	/** @var callable|null */
	private $provider_lister;

	/** @var callable */
	private $review_logger;

	/** @var callable|null fn(int $order_id): int|\WP_Error 作業日誌上線前的 Helcim 付款筆數 */
	private $legacy_charge_counter;

	/**
	 * @param callable      $accounting_reader      fn(int $order_id, int $transaction_id): array{transaction:array,order:array}|null|\WP_Error
	 * @param callable      $local_recorder         fn(string $operation_uuid): array|\WP_Error（既有本地記錄＋outbox 流程）
	 * @param callable|null $local_failure_recorder fn(string $uuid, string $code, string $message): bool|\WP_Error
	 * @param callable|null $stale_scope_recoverer  fn(string $scope_key): int|\WP_Error
	 * @param callable|null $order_note_writer      fn(int $order_id, string $title, string $message): void
	 * @param callable|null $credential_resolver    fn(string $gateway, string $mode): string|\WP_Error（手動同步用）
	 * @param callable|null $provider_lister        fn(string $invoice_number, string $api_token): array|\WP_Error（手動同步用）
	 * @param callable|null $review_logger          fn(string $message, array $context): void
	 * @param callable|null $legacy_charge_counter  fn(int $order_id): int|\WP_Error（沒有 purchase 作業的 Helcim 付款筆數）
	 */
	public function __construct(
		private YSHelcimOperationRepository $operations,
		callable $accounting_reader,
		callable $local_recorder,
		?callable $local_failure_recorder = null,
		?callable $stale_scope_recoverer = null,
		?callable $order_note_writer = null,
		?callable $credential_resolver = null,
		?callable $provider_lister = null,
		?callable $review_logger = null,
		?callable $legacy_charge_counter = null
	) {
		$this->legacy_charge_counter  = $legacy_charge_counter;
		$this->accounting_reader      = $accounting_reader;
		$this->local_recorder         = $local_recorder;
		$this->local_failure_recorder = $local_failure_recorder;
		$this->stale_scope_recoverer  = $stale_scope_recoverer;
		$this->order_note_writer      = $order_note_writer;
		$this->credential_resolver    = $credential_resolver;
		$this->provider_lister        = $provider_lister;
		$this->review_logger          = $review_logger ?? static function ( string $message, array $context ): void {
			YSHelcimLogger::error( $message, $context );
		};
	}

	/**
	 * Webhook 第二個 reconciler：只處理已驗簽、已向 Helcim 取回的 refund／reverse 紀錄。
	 *
	 * @param array<string,mixed>                          $proof    Provider-owned GET proof.
	 * @param array<int,array{gateway:string,mode:string}> $bindings Verified account bindings.
	 * @return array{code:int,message:string}
	 */
	public function reconcileWebhook( array $proof, string $transaction_id, array $bindings ): array {
		$proved_id = YSHelcimTransactionId::normalize( $proof['transactionId'] ?? null );
		$event_id  = YSHelcimTransactionId::normalize( $transaction_id );
		if ( null === $proved_id || null === $event_id || ! hash_equals( $event_id, $proved_id ) ) {
			return array( 'code' => 400, 'message' => 'transaction proof mismatch' );
		}

		$result = $this->recordProof( $proof, $bindings );
		return match ( $result['status'] ) {
			self::STATUS_RECORDED         => array( 'code' => 200, 'message' => 'provider refund recorded' ),
			self::STATUS_ALREADY_RECORDED => array( 'code' => 200, 'message' => 'provider refund already recorded' ),
			self::STATUS_RETRY            => array( 'code' => 503, 'message' => 'provider refund retry later: ' . $result['reason'] ),
			self::STATUS_REVIEW           => array( 'code' => 409, 'message' => 'provider refund requires review: ' . $result['reason'] ),
			default                       => array( 'code' => 200, 'message' => 'ignored' ),
		};
	}

	/**
	 * 手動同步：列出這張訂單每筆已套用 purchase 在 Helcim 的全部交易，逐筆記錄外部退款／作廢。
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function syncOrder( int $order_id ) {
		if ( $order_id <= 0 ) {
			return new \WP_Error(
				'ys_helcim_invalid_order',
				__( 'The requested order is invalid.', 'ys-helcim-via-fluentcart' ),
				array( 'status' => 422 )
			);
		}
		if ( null === $this->credential_resolver || null === $this->provider_lister ) {
			return self::syncUnavailable();
		}

		$purchases = $this->operations->findAppliedPurchasesForOrder( $order_id );
		if ( is_wp_error( $purchases ) ) {
			return self::syncUnavailable();
		}

		$summary = array(
			'order_id'                 => $order_id,
			'status'                   => 'nothing_new',
			'helcim_payments'          => count( $purchases ),
			self::STATUS_RECORDED         => array(),
			self::STATUS_ALREADY_RECORDED => array(),
			self::STATUS_SKIPPED          => array(),
			self::STATUS_RETRY            => array(),
			self::STATUS_REVIEW           => array(),
		);
		$handled = array();

		// 起頭先重跑這張訂單「作業列已建立、本地還沒寫完」的外部紀錄（例如上次同步在寫入前中斷）。
		$awaiting = $this->operations->findRefundsAwaitingLocalRecordingForOrder( $order_id );
		if ( is_wp_error( $awaiting ) ) {
			return self::syncUnavailable();
		}
		foreach ( $awaiting as $row ) {
			if ( ! is_array( $row ) || ! self::isProviderRecordedOperation( $row ) ) {
				continue;
			}
			$result = $this->resumeProviderRecord( $row );
			$handled[ $result['transaction_id'] ] = true;
			$summary[ $result['status'] ][] = self::summaryEntry( $result['transaction_id'], $result['provider_action'], $result['reason'] );
		}

		foreach ( $purchases as $purchase ) {
			$purchase_uuid = is_array( $purchase ) ? self::uuid( $purchase['operation_uuid'] ?? null ) : null;
			$gateway       = is_array( $purchase ) ? (string) ( $purchase['gateway'] ?? '' ) : '';
			$mode          = is_array( $purchase ) ? (string) ( $purchase['payment_mode'] ?? '' ) : '';
			if (
				null === $purchase_uuid ||
				! in_array( $gateway, array( 'ys_helcim', 'ys_helcim_js' ), true ) ||
				! in_array( $mode, array( 'test', 'live' ), true )
			) {
				$summary[ self::STATUS_REVIEW ][] = self::summaryEntry( '', '', 'invalid_purchase_operation' );
				continue;
			}

			try {
				$api_token = ( $this->credential_resolver )( $gateway, $mode );
			} catch ( \Throwable $exception ) {
				unset( $exception );
				$api_token = null;
			}
			if ( ! is_string( $api_token ) || '' === trim( $api_token ) ) {
				$summary[ self::STATUS_RETRY ][] = self::summaryEntry( '', '', 'credential_unavailable' );
				continue;
			}

			try {
				$listed = ( $this->provider_lister )( $purchase_uuid, trim( $api_token ) );
			} catch ( \Throwable $exception ) {
				unset( $exception );
				$listed = null;
			}
			$records = self::providerRecords( $listed );
			if ( null === $records ) {
				$summary[ self::STATUS_RETRY ][] = self::summaryEntry( '', '', 'provider_lookup_failed' );
				continue;
			}

			foreach ( $records as $record ) {
				$type = strtolower( trim( (string) ( $record['type'] ?? '' ) ) );
				if ( ! in_array( $type, array( 'refund', 'reverse' ), true ) ) {
					continue;
				}
				if ( isset( $handled[ (string) YSHelcimTransactionId::normalize( $record['transactionId'] ?? null ) ] ) ) {
					continue;
				}
				$result = $this->recordProof(
					$record,
					array( array( 'gateway' => $gateway, 'mode' => $mode ) ),
					$purchase_uuid,
					$records
				);
				$bucket = self::STATUS_UNRELATED === $result['status'] ? self::STATUS_SKIPPED : $result['status'];
				$summary[ $bucket ][] = self::summaryEntry( $result['transaction_id'], $result['provider_action'], $result['reason'] );
			}
		}

		// 作業日誌上線前付的款沒有 purchase 作業，無法自動比對；明確告訴店家要手動處理。
		if ( array() === $purchases && null !== $this->legacy_charge_counter ) {
			try {
				$legacy = ( $this->legacy_charge_counter )( $order_id );
			} catch ( \Throwable $exception ) {
				unset( $exception );
				$legacy = new \WP_Error( 'ys_helcim_refund_context_unavailable', 'Refund context unavailable.' );
			}
			if ( is_wp_error( $legacy ) ) {
				return self::syncUnavailable();
			}
			if ( is_int( $legacy ) && $legacy > 0 ) {
				$summary['status']          = 'legacy_payment';
				$summary['legacy_payments'] = $legacy;
				return $summary;
			}
		}

		if ( array() !== $summary[ self::STATUS_REVIEW ] ) {
			$summary['status'] = 'needs_review';
		} elseif ( array() !== $summary[ self::STATUS_RETRY ] ) {
			$summary['status'] = 'retry_later';
		} elseif ( array() !== $summary[ self::STATUS_RECORDED ] ) {
			$summary['status'] = 'recorded';
		}

		return $summary;
	}

	/**
	 * 單筆 Helcim 交易紀錄 → 條件全部成立才記錄。
	 *
	 * @param array<string,mixed>                          $proof            Provider-owned record.
	 * @param array<int,array{gateway:string,mode:string}> $bindings         Credential bindings that read the record.
	 * @param string|null                                  $expected_invoice Manual sync: the purchase UUID that was listed.
	 * @param array<int,mixed>|null                        $family           Manual sync: every Helcim record of the same invoice.
	 * @return array{status:string,reason:string,transaction_id:string,provider_action:string,order_id:int,operation_uuid:string}
	 */
	public function recordProof( array $proof, array $bindings, ?string $expected_invoice = null, ?array $family = null ): array {
		$provider_id = YSHelcimTransactionId::normalize( $proof['transactionId'] ?? null );
		$type        = strtolower( trim( (string) ( $proof['type'] ?? '' ) ) );
		$action      = in_array( $type, array( 'refund', 'reverse' ), true ) ? $type : '';
		$result      = self::resultFactory( $provider_id, $action );

		if ( null === $provider_id ) {
			return $result( self::STATUS_SKIPPED, 'invalid_provider_record' );
		}
		if ( '' === $action ) {
			return $result( self::STATUS_SKIPPED, 'not_a_refund' );
		}
		if ( 'APPROVED' !== strtoupper( trim( (string) ( $proof['status'] ?? '' ) ) ) ) {
			return $result( self::STATUS_SKIPPED, 'not_approved' );
		}

		// Helcim 後台作廢／退款的紀錄會繼承原 purchase 的 invoiceNumber（＝作業 UUID）。
		$purchase_uuid = self::uuid( $proof['invoiceNumber'] ?? null );
		if ( null === $purchase_uuid ) {
			return $result( self::STATUS_UNRELATED, 'no_operation_correlation' );
		}
		if ( null !== $expected_invoice && ! hash_equals( strtolower( $expected_invoice ), $purchase_uuid ) ) {
			return $result( self::STATUS_SKIPPED, 'invoice_mismatch' );
		}

		// 先解析 invoice 對應的 purchase：之後連「已在日誌」的列也要跟它比對訂單與帳號。
		$purchase = $this->operations->findByUuidStrict( $purchase_uuid );
		if ( is_wp_error( $purchase ) ) {
			return $result( self::STATUS_RETRY, 'journal_unavailable' );
		}
		if ( null === $purchase ) {
			return $result( self::STATUS_UNRELATED, 'no_operation_correlation' );
		}
		$order_id = is_array( $purchase ) ? self::positiveInteger( $purchase['order_id'] ?? null ) : null;
		if (
			! is_array( $purchase ) ||
			$purchase_uuid !== strtolower( (string) ( $purchase['operation_uuid'] ?? '' ) ) ||
			'purchase' !== (string) ( $purchase['operation_type'] ?? '' ) ||
			null === $order_id
		) {
			return $this->review( $result, 'operation_correlation_conflict', $provider_id );
		}
		$purchase_remote = (string) ( $purchase['remote_status'] ?? '' );
		$purchase_local  = (string) ( $purchase['local_status'] ?? '' );
		if ( YSHelcimOperationState::REMOTE_SUCCEEDED !== $purchase_remote || YSHelcimOperationState::LOCAL_APPLIED !== $purchase_local ) {
			// 付款還在收斂（建立中／處理中／結果不明／已成功但本地尚未套用）→ 稍後重送；
			// 付款確定沒有成立（拒絕／失敗／取消／過期）卻出現退款 → 需要人工檢查。
			return in_array(
				$purchase_remote,
				array(
					YSHelcimOperationState::REMOTE_CREATED,
					YSHelcimOperationState::REMOTE_PROCESSING,
					YSHelcimOperationState::REMOTE_INDETERMINATE,
					YSHelcimOperationState::REMOTE_SUCCEEDED,
				),
				true
			)
				? $result( self::STATUS_RETRY, 'purchase_not_final', $order_id )
				: $this->review( $result, 'purchase_not_applied', $provider_id, $order_id );
		}

		$gateway        = (string) ( $purchase['gateway'] ?? '' );
		$mode           = (string) ( $purchase['payment_mode'] ?? '' );
		$currency       = strtoupper( (string) ( $purchase['currency'] ?? '' ) );
		$transaction_id = self::positiveInteger( $purchase['transaction_id'] ?? null );
		$source_vendor  = YSHelcimTransactionId::normalize( $purchase['vendor_transaction_id'] ?? null );
		if ( ! self::hasBinding( $bindings, $gateway, $mode ) ) {
			return $this->review( $result, 'credential_binding_mismatch', $provider_id, $order_id );
		}

		// 這筆 Helcim 交易是否已在作業日誌（外掛自己發起的退款也會在這裡被認出）。
		$existing = $this->operations->findByVendorTransactionIdStrict( $provider_id );
		if ( is_wp_error( $existing ) ) {
			return $result( self::STATUS_RETRY, 'journal_unavailable', $order_id );
		}
		if ( is_array( $existing ) ) {
			return $this->existingRecord( $existing, $order_id, $gateway, $mode, $proof, $provider_id, $action, $result );
		}
		if ( null === $transaction_id || null === $source_vendor || $source_vendor === $provider_id ) {
			return $this->review( $result, 'purchase_identity_invalid', $provider_id, $order_id );
		}
		if ( $currency !== strtoupper( trim( (string) ( $proof['currency'] ?? '' ) ) ) ) {
			return $this->review( $result, 'currency_mismatch', $provider_id, $order_id );
		}
		$amount = YSHelcimProviderProof::amountToCents( $proof['amount'] ?? null );
		if ( null === $amount || $amount <= 0 ) {
			return $this->review( $result, 'invalid_amount', $provider_id, $order_id );
		}

		try {
			$accounting = ( $this->accounting_reader )( $order_id, $transaction_id );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$accounting = new \WP_Error( 'ys_helcim_refund_context_unavailable', 'Refund context unavailable.' );
		}
		if ( is_wp_error( $accounting ) ) {
			return $result( self::STATUS_RETRY, 'accounting_unavailable', $order_id );
		}
		$source = is_array( $accounting ) && is_array( $accounting['transaction'] ?? null ) ? $accounting['transaction'] : null;
		$order  = is_array( $accounting ) && is_array( $accounting['order'] ?? null ) ? $accounting['order'] : null;
		$source_meta     = null === $source ? null : self::jsonObject( $source['meta'] ?? null );
		$total           = null === $source ? null : self::nonnegativeInteger( $source['total'] ?? null );
		$refunded        = null === $source_meta ? null : self::nonnegativeInteger( $source_meta['refunded_total'] ?? 0 );
		$order_paid      = null === $order ? null : self::nonnegativeInteger( $order['total_paid'] ?? null );
		$order_refund    = null === $order ? null : self::nonnegativeInteger( $order['total_refund'] ?? null );
		if (
			null === $source ||
			null === $order ||
			null === $total ||
			null === $refunded ||
			null === $order_paid ||
			null === $order_refund ||
			$refunded > $total ||
			$order_refund > $order_paid ||
			$transaction_id !== self::positiveInteger( $source['id'] ?? null ) ||
			$order_id !== self::positiveInteger( $source['order_id'] ?? null ) ||
			$order_id !== self::positiveInteger( $order['id'] ?? null ) ||
			(string) ( $purchase['transaction_uuid'] ?? '' ) !== (string) ( $source['uuid'] ?? '' ) ||
			$gateway !== (string) ( $source['payment_method'] ?? '' ) ||
			$mode !== (string) ( $source['payment_mode'] ?? '' ) ||
			$currency !== strtoupper( (string) ( $source['currency'] ?? '' ) ) ||
			$currency !== strtoupper( (string) ( $order['currency'] ?? '' ) ) ||
			'succeeded' !== (string) ( $source['status'] ?? '' ) ||
			'refund' === (string) ( $source['transaction_type'] ?? '' ) ||
			$source_vendor !== YSHelcimTransactionId::normalize( $source['vendor_charge_id'] ?? null )
		) {
			return $this->review( $result, 'local_transaction_mismatch', $provider_id, $order_id );
		}

		// FluentCart 已經有這筆 Helcim 交易的退款紀錄（例如舊版或其他途徑記過）→ 不再建作業列。
		// 同時先做與本地記錄器相同的帳務一致性檢查，避免建出一筆「寫不進去、卡住退款鎖」的外部列。
		$refund_rows = is_array( $accounting ) && is_array( $accounting['refunds'] ?? null ) ? $accounting['refunds'] : null;
		if ( null === $refund_rows ) {
			return $result( self::STATUS_RETRY, 'accounting_unavailable', $order_id );
		}
		$order_refund_sum  = 0;
		$source_refund_sum = 0;
		$unfinished_twin   = false;
		foreach ( $refund_rows as $refund ) {
			if ( ! is_array( $refund ) ) {
				return $this->review( $result, 'accounting_drift', $provider_id, $order_id );
			}
			$refund_status = (string) ( $refund['status'] ?? '' );
			if ( $provider_id === YSHelcimTransactionId::normalize( $refund['vendor_charge_id'] ?? null ) ) {
				// 只有已完成（refunded）的同編號退款列才算「FluentCart 已記錄」。
				if ( 'refunded' === $refund_status ) {
					return $result( self::STATUS_ALREADY_RECORDED, 'already_in_fluentcart', $order_id );
				}
				// 同編號但還沒完成（pending／failed 等）：帳面其實沒有這筆退款，不可當成已記錄而靜默漏記，
				// 也不可再建一筆；掃完其餘列（有已完成的同編號列仍以它為準）後交給人工檢查。
				$unfinished_twin = true;
			}
			if ( 'refunded' !== $refund_status ) {
				continue;
			}
			$refund_total = self::nonnegativeInteger( $refund['total'] ?? null );
			$refund_meta  = self::jsonObject( $refund['meta'] ?? null );
			if ( null === $refund_total || null === $refund_meta || $refund_total > PHP_INT_MAX - $order_refund_sum ) {
				return $this->review( $result, 'accounting_drift', $provider_id, $order_id );
			}
			$order_refund_sum += $refund_total;
			if ( $transaction_id === (int) ( $refund_meta['parent_id'] ?? 0 ) ) {
				$source_refund_sum += $refund_total;
			}
		}
		if ( $unfinished_twin ) {
			return $this->review( $result, 'fluentcart_refund_row_not_final', $provider_id, $order_id );
		}
		if ( $order_refund_sum !== $order_refund || $source_refund_sum !== $refunded ) {
			return $this->review( $result, 'accounting_drift', $provider_id, $order_id );
		}

		if ( $amount > $total - $refunded || $amount > $order_paid - $order_refund ) {
			return $this->review( $result, 'amount_exceeds_remaining', $provider_id, $order_id );
		}
		// 作廢只能是整筆且這筆交易還沒有任何退款。
		if ( 'reverse' === $action && ( $amount !== $total || 0 !== $refunded ) ) {
			return $this->review( $result, 'reverse_not_full', $provider_id, $order_id );
		}

		// 同一 invoice 家族整體判斷：Helcim 也能作廢「未結批的退款」，紀錄同樣是 reverse、同 invoice。
		// webhook 路徑沒有清單，就用同一組帳號再列一次；列不到寧可稍後重送。
		if ( null === $family ) {
			$family = $this->providerFamily( $purchase_uuid, $gateway, $mode );
			if ( null === $family ) {
				return $result( self::STATUS_RETRY, 'provider_family_unavailable', $order_id );
			}
		}
		$family_conflict = self::familyConflict( $family, $purchase_uuid, $action, $provider_id, $amount );
		if ( null !== $family_conflict ) {
			return $this->review( $result, $family_conflict, $provider_id, $order_id );
		}

		$scope = 'refund-order:' . $order_id;
		if ( null !== $this->stale_scope_recoverer ) {
			try {
				$recovered = ( $this->stale_scope_recoverer )( $scope );
			} catch ( \Throwable $exception ) {
				unset( $exception );
				$recovered = new \WP_Error( 'ys_helcim_journal_unavailable', 'Refund recovery unavailable.' );
			}
			if ( is_wp_error( $recovered ) ) {
				return $result( self::STATUS_RETRY, 'journal_unavailable', $order_id );
			}
		}
		// 外掛自己的退款還在進行中：不得並發記錄，等它結束再來。
		if ( null !== $this->operations->findActiveByScope( $scope ) ) {
			return $result( self::STATUS_RETRY, 'refund_in_progress', $order_id );
		}

		$operation_uuid = self::operationUuid( $gateway, $mode, $provider_id );
		try {
			$payload      = YSHelcimRefundPayload::normalize(
				array(
					'version'       => 1,
					'reason'        => self::refundReason( $action, $provider_id ),
					'manage_stock'  => false,
					'actor_user_id' => 0,
				)
			);
			$payload_json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$payload_hash = YSHelcimRefundPayload::hash( $payload );
			$idempotency  = YSHelcimIdempotency::generate(
				$action,
				(string) $purchase['transaction_uuid'],
				$amount,
				$mode,
				$operation_uuid
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return $this->review( $result, 'invalid_local_payload', $provider_id, $order_id );
		}
		if ( ! is_string( $payload_json ) ) {
			return $this->review( $result, 'invalid_local_payload', $provider_id, $order_id );
		}

		$parent_uuid = 'reverse' === $action ? $purchase_uuid : null;
		$created     = $this->operations->createProviderRecordedRefund(
			array(
				'operation_uuid'               => $operation_uuid,
				'idempotency_key'              => $idempotency,
				'scope_key'                    => $scope,
				'operation_type'               => $action,
				'gateway'                      => $gateway,
				'order_id'                     => $order_id,
				'transaction_id'               => $transaction_id,
				'transaction_uuid'             => (string) $purchase['transaction_uuid'],
				'parent_operation_uuid'        => $parent_uuid,
				'amount'                       => $amount,
				'currency'                     => $currency,
				'payment_mode'                 => $mode,
				'vendor_transaction_id'        => $provider_id,
				'source_vendor_transaction_id' => $source_vendor,
				'request_fingerprint'          => self::fingerprint(
					$action,
					$parent_uuid,
					$gateway,
					$order_id,
					$transaction_id,
					(string) $purchase['transaction_uuid'],
					$source_vendor,
					$amount,
					$total,
					$currency,
					$mode,
					$payload_hash
				),
				'local_payload'                => $payload_json,
				'local_payload_hash'           => $payload_hash,
			)
		);
		if ( is_wp_error( $created ) ) {
			return match ( $created->get_error_code() ) {
				'ys_helcim_provider_refund_already_recorded' => $result( self::STATUS_ALREADY_RECORDED, 'already_recorded', $order_id ),
				'ys_helcim_scope_busy'                       => $result( self::STATUS_RETRY, 'refund_in_progress', $order_id ),
				'ys_helcim_journal_unavailable'              => $result( self::STATUS_RETRY, 'journal_unavailable', $order_id ),
				default                                      => $this->review( $result, 'operation_conflict', $provider_id, $order_id ),
			};
		}

		return $this->recordLocally( $operation_uuid, $proof, $action, $provider_id, $order_id, $amount, $currency, $result );
	}

	/**
	 * 這筆 Helcim 交易已在作業日誌：外掛自己的退款一律視為已記錄；只有本服務建立、
	 * 本地尚未寫入（pending／failed）的外部紀錄才重跑本地記錄（自我修復）。
	 * 不論是誰建立的，都必須屬於 invoice 解析出的同一張訂單與同一個帳號；
	 * 數字交易編號跨帳號（test／live、兩個 gateway）碰撞時不可靜默吞掉。
	 *
	 * @param array<string,mixed> $existing
	 * @param array<string,mixed> $proof
	 */
	private function existingRecord(
		array $existing,
		int $purchase_order_id,
		string $purchase_gateway,
		string $purchase_mode,
		array $proof,
		string $provider_id,
		string $action,
		callable $result
	): array {
		$type     = (string) ( $existing['operation_type'] ?? '' );
		$order_id = self::positiveInteger( $existing['order_id'] ?? null ) ?? 0;
		$uuid     = strtolower( (string) ( $existing['operation_uuid'] ?? '' ) );
		$gateway  = (string) ( $existing['gateway'] ?? '' );
		$mode     = (string) ( $existing['payment_mode'] ?? '' );
		if (
			! in_array( $type, array( 'refund', 'reverse' ), true ) ||
			YSHelcimOperationState::REMOTE_SUCCEEDED !== (string) ( $existing['remote_status'] ?? '' ) ||
			$purchase_order_id !== $order_id ||
			$purchase_gateway !== $gateway ||
			$purchase_mode !== $mode
		) {
			return $this->review( $result, 'provider_id_conflict', $provider_id, $purchase_order_id );
		}

		$local   = (string) ( $existing['local_status'] ?? '' );
		$is_ours = $type === $action && hash_equals( self::operationUuid( $gateway, $mode, $provider_id ), $uuid );
		if (
			! $is_ours ||
			! in_array( $local, array( YSHelcimOperationState::LOCAL_PENDING, YSHelcimOperationState::LOCAL_FAILED ), true )
		) {
			return $result( self::STATUS_ALREADY_RECORDED, 'already_recorded', $order_id, $uuid );
		}

		$amount   = self::positiveInteger( $existing['amount'] ?? null ) ?? 0;
		$currency = (string) ( $existing['currency'] ?? '' );
		return $this->recordLocally( $uuid, $proof, $action, $provider_id, $order_id, $amount, $currency, $result );
	}

	/**
	 * webhook 路徑：用 purchase 的帳號再列一次同 invoice 的全部 Helcim 交易。
	 *
	 * @return array<int,array<string,mixed>>|null
	 */
	private function providerFamily( string $purchase_uuid, string $gateway, string $mode ): ?array {
		if ( null === $this->credential_resolver || null === $this->provider_lister ) {
			return null;
		}
		try {
			$api_token = ( $this->credential_resolver )( $gateway, $mode );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$api_token = null;
		}
		if ( ! is_string( $api_token ) || '' === trim( $api_token ) ) {
			return null;
		}
		try {
			$listed = ( $this->provider_lister )( $purchase_uuid, trim( $api_token ) );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$listed = null;
		}

		return self::providerRecords( $listed );
	}

	/**
	 * 同 invoice 家族裡有沒有讓這筆紀錄不能入帳的其他交易。
	 *
	 * - 要記作廢（reverse）：家族裡只要有任何退款紀錄（不論狀態），這筆 reverse 就可能是「作廢了某筆退款」，
	 *   無法確定作廢的是原付款 → 不入帳、交給人工。不只看 APPROVED：本機無法證明 Helcim
	 *   作廢一筆退款之後，原退款紀錄的狀態是否仍是 APPROVED。
	 * - 要記退款（refund）：家族裡有編號較大、同金額、已核准的作廢＝這筆退款已被作廢 → 不入帳。
	 *
	 * @param array<int,mixed> $family
	 */
	private static function familyConflict( array $family, string $purchase_uuid, string $action, string $provider_id, int $amount ): ?string {
		foreach ( $family as $record ) {
			if ( ! is_array( $record ) || $purchase_uuid !== self::uuid( $record['invoiceNumber'] ?? null ) ) {
				continue;
			}
			$record_type = strtolower( trim( (string) ( $record['type'] ?? '' ) ) );
			if ( 'reverse' === $action && 'refund' === $record_type ) {
				return 'reverse_of_refund';
			}
			$record_id = YSHelcimTransactionId::normalize( $record['transactionId'] ?? null );
			if (
				null === $record_id ||
				$record_id === $provider_id ||
				'APPROVED' !== strtoupper( trim( (string) ( $record['status'] ?? '' ) ) )
			) {
				continue;
			}
			if (
				'refund' === $action &&
				'reverse' === $record_type &&
				self::transactionIdGreater( $record_id, $provider_id ) &&
				$amount === YSHelcimProviderProof::amountToCents( $record['amount'] ?? null )
			) {
				return 'refund_was_voided';
			}
		}

		return null;
	}

	private static function transactionIdGreater( string $left, string $right ): bool {
		return strlen( $left ) === strlen( $right ) ? strcmp( $left, $right ) > 0 : strlen( $left ) > strlen( $right );
	}

	/** @return callable(string,string,int=,string=):array{status:string,reason:string,transaction_id:string,provider_action:string,order_id:int,operation_uuid:string} */
	private static function resultFactory( ?string $provider_id, string $action ): callable {
		return static fn ( string $status, string $reason, int $order_id = 0, string $operation_uuid = '' ): array => array(
			'status'          => $status,
			'reason'          => $reason,
			'transaction_id'  => (string) $provider_id,
			'provider_action' => $action,
			'order_id'        => $order_id,
			'operation_uuid'  => $operation_uuid,
		);
	}

	/**
	 * 手動同步起頭：把這張訂單「作業列已建立、本地還沒寫完」的外部紀錄直接重跑（不需要再連 Helcim；
	 * 建列時已通過全部證明）。
	 *
	 * @param array<string,mixed> $row
	 * @return array{status:string,reason:string,transaction_id:string,provider_action:string,order_id:int,operation_uuid:string}
	 */
	private function resumeProviderRecord( array $row ): array {
		$provider_id = (string) YSHelcimTransactionId::normalize( $row['vendor_transaction_id'] ?? null );
		$action      = (string) ( $row['operation_type'] ?? '' );
		$order_id    = self::positiveInteger( $row['order_id'] ?? null ) ?? 0;
		$amount      = self::positiveInteger( $row['amount'] ?? null ) ?? 0;
		$currency    = (string) ( $row['currency'] ?? '' );
		$proof       = array(
			'transactionId' => $provider_id,
			'type'          => $action,
			'status'        => 'APPROVED',
		);

		return $this->recordLocally(
			strtolower( (string) ( $row['operation_uuid'] ?? '' ) ),
			$proof,
			$action,
			$provider_id,
			$order_id,
			$amount,
			$currency,
			self::resultFactory( $provider_id, $action )
		);
	}

	/** @param array<string,mixed> $proof */
	private function recordLocally(
		string $operation_uuid,
		array $proof,
		string $action,
		string $provider_id,
		int $order_id,
		int $amount,
		string $currency,
		callable $result
	): array {
		try {
			$recorded = ( $this->local_recorder )( $operation_uuid );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$recorded = new \WP_Error( 'ys_helcim_local_recording_failed', 'Local recording failed.' );
		}

		if ( is_wp_error( $recorded ) ) {
			$code = self::safeErrorCode( $recorded->get_error_code() );
			if ( null !== $this->local_failure_recorder ) {
				try {
					( $this->local_failure_recorder )( $operation_uuid, $code, YSHelcimSanitizer::errorText( $recorded->get_error_message() ) );
				} catch ( \Throwable $exception ) {
					unset( $exception );
				}
			}
			if (
				str_contains( $code, 'accounting_drift' ) ||
				str_contains( $code, 'conflict' ) ||
				str_contains( $code, 'invalid' )
			) {
				return $this->review( $result, 'local_recording_failed', $provider_id, $order_id, $operation_uuid );
			}
			return $result( self::STATUS_RETRY, 'local_recording_unavailable', $order_id, $operation_uuid );
		}

		if (
			! is_array( $recorded ) ||
			$operation_uuid !== (string) ( $recorded['operation_uuid'] ?? '' ) ||
			! in_array(
				(string) ( $recorded['local_status'] ?? '' ),
				array( YSHelcimOperationState::LOCAL_RECORDED, YSHelcimOperationState::LOCAL_APPLIED ),
				true
			)
		) {
			return $result( self::STATUS_RETRY, 'local_recording_unverified', $order_id, $operation_uuid );
		}

		if ( true !== ( $recorded['replayed'] ?? false ) ) {
			YSHelcimOrderNote::write(
				$this->order_note_writer,
				$order_id,
				'reverse' === $action
					? __( 'Helcim reverse recorded from provider records', 'ys-helcim-via-fluentcart' )
					: __( 'Helcim refund recorded from provider records', 'ys-helcim-via-fluentcart' ),
				self::orderNoteMessage( $action, $provider_id, $amount, $currency, $proof )
			);
		}

		return $result( self::STATUS_RECORDED, 'recorded', $order_id, $operation_uuid );
	}

	/** 409 類結果都要留 ERROR 級紀錄，方便店家對帳。 */
	private function review( callable $result, string $reason, string $provider_id, int $order_id = 0, string $operation_uuid = '' ): array {
		try {
			( $this->review_logger )(
				'A Helcim refund made outside this plugin needs review before it can be recorded',
				array(
					'order_id'                => $order_id,
					'provider_transaction_id' => $provider_id,
					'reason'                  => $reason,
				)
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
		}

		return $result( self::STATUS_REVIEW, $reason, $order_id, $operation_uuid );
	}

	/** 顧客看得到退款原因，所以只寫動作與 Helcim 交易編號，不寫 Helcim 操作者。 */
	private static function refundReason( string $action, string $provider_id ): string {
		return 'reverse' === $action
			/* translators: %s: Helcim transaction ID. */
			? sprintf( __( 'Voided in Helcim (Helcim transaction %s).', 'ys-helcim-via-fluentcart' ), $provider_id )
			/* translators: %s: Helcim transaction ID. */
			: sprintf( __( 'Refunded in Helcim (Helcim transaction %s).', 'ys-helcim-via-fluentcart' ), $provider_id );
	}

	/** @param array<string,mixed> $proof */
	private static function orderNoteMessage( string $action, string $provider_id, int $amount, string $currency, array $proof ): string {
		$user = is_scalar( $proof['user'] ?? null ) ? trim( (string) $proof['user'] ) : '';
		$user = 1 === preg_match( '/\A[A-Za-z0-9 ._@+-]{1,64}\z/', $user ) ? $user : __( 'unknown', 'ys-helcim-via-fluentcart' );
		$date = is_scalar( $proof['dateCreated'] ?? null ) ? trim( (string) $proof['dateCreated'] ) : '';
		$date = 1 === preg_match( '/\A[0-9T :.\/+-]{8,32}\z/', $date ) ? $date : '-';
		$money = number_format( $amount / 100, 2, '.', '' );

		return 'reverse' === $action
			/* translators: 1: amount, 2: currency code, 3: Helcim transaction ID, 4: Helcim user who made the change, 5: Helcim transaction time. */
			? sprintf( __( 'The %1$s %2$s payment was voided directly in Helcim (Helcim transaction %3$s, Helcim user: %4$s, %5$s) and is now recorded in FluentCart.', 'ys-helcim-via-fluentcart' ), $money, $currency, $provider_id, $user, $date )
			/* translators: 1: amount, 2: currency code, 3: Helcim transaction ID, 4: Helcim user who made the change, 5: Helcim transaction time. */
			: sprintf( __( 'A %1$s %2$s refund was made directly in Helcim (Helcim transaction %3$s, Helcim user: %4$s, %5$s) and is now recorded in FluentCart.', 'ys-helcim-via-fluentcart' ), $money, $currency, $provider_id, $user, $date );
	}

	/** 與 YSHelcimRefundService／本地記錄器相同的 version-2 請求指紋。 */
	private static function fingerprint(
		string $action,
		?string $parent_uuid,
		string $gateway,
		int $order_id,
		int $transaction_id,
		string $transaction_uuid,
		string $source_vendor,
		int $amount,
		int $transaction_total,
		string $currency,
		string $mode,
		string $payload_hash
	): string {
		$material = wp_json_encode(
			array(
				'version'                      => 2,
				'operation_type'               => $action,
				'parent_operation_uuid'        => $parent_uuid,
				'gateway'                      => $gateway,
				'order_id'                     => $order_id,
				'transaction_id'               => $transaction_id,
				'transaction_uuid'             => $transaction_uuid,
				'source_vendor_transaction_id' => $source_vendor,
				'amount'                       => $amount,
				'transaction_total'            => $transaction_total,
				'currency'                     => $currency,
				'payment_mode'                 => $mode,
				'local_payload_hash'           => $payload_hash,
			),
			JSON_UNESCAPED_SLASHES
		);

		return hash( 'sha256', is_string( $material ) ? $material : '' );
	}

	/**
	 * 這筆作業列是不是本服務依 Helcim 交易紀錄建立的（外部退款／作廢）。
	 * 以作業 UUID 是否等於 (gateway, mode, Helcim 交易編號) 的推導值判斷，外掛自己發起的退款不會符合。
	 *
	 * @param array<string,mixed> $row
	 */
	public static function isProviderRecordedOperation( array $row ): bool {
		$type        = (string) ( $row['operation_type'] ?? '' );
		$gateway     = (string) ( $row['gateway'] ?? '' );
		$mode        = (string) ( $row['payment_mode'] ?? '' );
		$provider_id = YSHelcimTransactionId::normalize( $row['vendor_transaction_id'] ?? null );
		$uuid        = strtolower( (string) ( $row['operation_uuid'] ?? '' ) );

		return in_array( $type, array( 'refund', 'reverse' ), true )
			&& in_array( $gateway, array( 'ys_helcim', 'ys_helcim_js' ), true )
			&& in_array( $mode, array( 'test', 'live' ), true )
			&& null !== $provider_id
			&& hash_equals( self::operationUuid( $gateway, $mode, $provider_id ), $uuid );
	}

	/** 外部退款作業 UUID 的推導值（測試與診斷用）。 */
	public static function operationUuidFor( string $gateway, string $mode, string $provider_id ): string {
		return self::operationUuid( $gateway, $mode, $provider_id );
	}

	/** 同一筆 Helcim 交易永遠得到同一個作業 UUID：webhook 與手動同步並發時由 UNIQUE 收斂。 */
	private static function operationUuid( string $gateway, string $mode, string $provider_id ): string {
		$hash    = hash( 'sha256', 'ys-helcim-provider-refund-v1|' . $gateway . '|' . $mode . '|' . $provider_id );
		$variant = dechex( 8 + ( hexdec( $hash[16] ) % 4 ) );

		return substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-5' . substr( $hash, 13, 3 ) . '-'
			. $variant . substr( $hash, 17, 3 ) . '-' . substr( $hash, 20, 12 );
	}

	/** @return array<int,array<string,mixed>>|null */
	private static function providerRecords( mixed $listed ): ?array {
		if ( is_wp_error( $listed ) || ! is_array( $listed ) ) {
			return null;
		}
		if ( array_key_exists( 'data', $listed ) ) {
			$listed = $listed['data'];
		}
		if ( ! is_array( $listed ) || ( array() !== $listed && ! array_is_list( $listed ) ) ) {
			return null;
		}
		// 家族判斷要看到同一 invoice 的全部紀錄：超過上限就不判斷（webhook 稍後重送、手動同步列為重試），
		// 不截斷——截斷可能剛好丟掉決定結果的那一筆（例如已核准的退款）。
		if ( count( $listed ) > self::MAX_PROVIDER_RECORDS ) {
			return null;
		}

		$records = array_values( array_filter( $listed, 'is_array' ) );
		usort(
			$records,
			static fn ( array $left, array $right ): int => strcmp(
				str_pad( (string) YSHelcimTransactionId::normalize( $left['transactionId'] ?? null ), 20, '0', STR_PAD_LEFT ),
				str_pad( (string) YSHelcimTransactionId::normalize( $right['transactionId'] ?? null ), 20, '0', STR_PAD_LEFT )
			)
		);

		return $records;
	}

	/** @return array{transaction_id:string,provider_action:string,reason:string} */
	private static function summaryEntry( string $transaction_id, string $provider_action, string $reason ): array {
		return array(
			'transaction_id'  => $transaction_id,
			'provider_action' => $provider_action,
			'reason'          => $reason,
		);
	}

	/** @param array<int,mixed> $bindings */
	private static function hasBinding( array $bindings, string $gateway, string $mode ): bool {
		$matches = 0;
		foreach ( $bindings as $binding ) {
			if (
				is_array( $binding ) &&
				array( 'gateway', 'mode' ) === array_keys( $binding ) &&
				$gateway === $binding['gateway'] &&
				$mode === $binding['mode']
			) {
				++$matches;
			}
		}

		return 1 === $matches;
	}

	private static function uuid( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = strtolower( trim( $value ) );
		return 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value )
			? $value
			: null;
	}

	private static function positiveInteger( mixed $value ): ?int {
		$normalized = YSHelcimTransactionId::normalize( $value );
		return null === $normalized ? null : (int) $normalized;
	}

	private static function nonnegativeInteger( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) ) {
			return null;
		}
		$max = (string) PHP_INT_MAX;
		return strlen( $value ) < strlen( $max ) || ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) <= 0 )
			? (int) $value
			: null;
	}

	/** 與 YSHelcimLocalRefundRecorder 相同的 meta 解讀，避免兩邊對同一筆交易判斷不一致。 @return array<string,mixed>|null */
	private static function jsonObject( mixed $value ): ?array {
		if ( null === $value || '' === $value ) {
			return array();
		}
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		try {
			$decoded = json_decode( $value, true, 64, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			unset( $exception );
			return null;
		}
		return is_array( $decoded ) ? $decoded : null;
	}

	private static function safeErrorCode( mixed $code ): string {
		$code = is_string( $code ) ? strtolower( $code ) : '';
		$code = preg_replace( '/[^a-z0-9_\-]/', '', $code ) ?? '';
		return '' === $code ? 'ys_helcim_local_recording_failed' : substr( $code, 0, 100 );
	}

	private static function syncUnavailable(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_provider_refund_sync_unavailable',
			__( 'Refunds could not be synced from Helcim.', 'ys-helcim-via-fluentcart' ),
			array( 'status' => 503 )
		);
	}
}
