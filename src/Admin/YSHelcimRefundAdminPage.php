<?php
/**
 * Canonical WordPress admin surface for remote-first Helcim refunds.
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a stable admin page and a progressive adapter for FluentCart orders.
 */
final class YSHelcimRefundAdminPage {
	public const PAGE_SLUG = 'ys-helcim-refunds';

	public const ASSET_HANDLE = 'ys-helcim-refund-admin';

	public const CONFIG_OBJECT = 'ysHelcimRefundAdminConfig';

	/** @var callable */
	private $permission_checker;

	/** @var callable */
	private $menu_registrar;

	/** @var callable */
	private $asset_enqueuer;

	/** @var callable */
	private $config_provider;

	public function __construct(
		callable $permission_checker,
		callable $menu_registrar,
		callable $asset_enqueuer,
		callable $config_provider
	) {
		$this->permission_checker = $permission_checker;
		$this->menu_registrar      = $menu_registrar;
		$this->asset_enqueuer      = $asset_enqueuer;
		$this->config_provider     = $config_provider;
	}

	/** Register the canonical page under FluentCart. */
	public function registerMenu(): void {
		if ( ! $this->canAccess() ) {
			return;
		}

		try {
			$config = ( $this->config_provider )( 'menu' );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return;
		}
		$capability = is_array( $config ) && is_string( $config['menu_capability'] ?? null )
			? $config['menu_capability']
			: '';
		if ( '' === $capability ) {
			return;
		}

		try {
			( $this->menu_registrar )(
				array(
					'parent_slug'      => 'admin.php',
					'menu_parent_slug' => 'fluent-cart',
					'menu_url'         => 'admin.php?page=' . self::PAGE_SLUG,
					'menu_key'         => self::PAGE_SLUG,
					'page_title'       => __( 'Helcim Refunds', 'ys-helcim-via-fluentcart' ),
					'menu_title'       => __( 'Helcim Refunds', 'ys-helcim-via-fluentcart' ),
					'capability'       => $capability,
					'menu_slug'        => self::PAGE_SLUG,
					'callback'         => array( $this, 'render' ),
				)
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
		}
	}

	/** Enqueue assets only on the canonical page or FluentCart SPA. */
	public function enqueueAssets( string $hook_suffix = '' ): void {
		unset( $hook_suffix );

		try {
			$config = ( $this->config_provider )( 'assets' );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return;
		}
		if ( ! is_array( $config ) ) {
			return;
		}

		$page   = is_string( $config['page'] ?? null ) ? $config['page'] : '';
		$screen = self::screenForPage( $page );
		if ( '' === $screen || ! $this->canAccess() ) {
			return;
		}

		$browser_config = is_array( $config['browser_config'] ?? null )
			? array_intersect_key(
				$config['browser_config'],
				array_flip(
					array(
						'restRoot',
						'restNonce',
						'adminPageUrl',
						'initialOrderId',
						'labels',
						'pollIntervalMs',
						'pollAttempts',
						'autoStart',
						'canResolve',
						'modalEnabled',
					)
				)
			)
			: array();
		$browser_config['screen'] = $screen;
		if ( array_key_exists( 'canResolve', $browser_config ) ) {
			$browser_config['canResolve'] = true === $browser_config['canResolve'];
		}
		if ( array_key_exists( 'modalEnabled', $browser_config ) ) {
			// 退款彈窗只存在於 FluentCart 訂單頁（spa）；獨立頁面一律關閉，避免同頁 id 重複。
			$browser_config['modalEnabled'] = 'spa' === $screen && true === $browser_config['modalEnabled'];
		}
		if ( is_array( $browser_config['labels'] ?? null ) ) {
			$browser_config['labels'] = array_filter(
				array_intersect_key(
					$browser_config['labels'],
					array_flip( array( 'nativeRefund', 'helcimRefund', 'blocked' ) )
				),
				'is_string'
			);
		}
		$browser_config['messages'] = self::browserMessages();

		$asset_config = array(
			'script_handle' => self::ASSET_HANDLE,
			'style_handle'  => self::ASSET_HANDLE,
			'config_object' => self::CONFIG_OBJECT,
			'script_url'    => is_string( $config['script_url'] ?? null ) ? $config['script_url'] : '',
			'style_url'     => is_string( $config['style_url'] ?? null ) ? $config['style_url'] : '',
			'version'       => is_string( $config['version'] ?? null ) ? $config['version'] : '',
			'browser_config' => $browser_config,
		);

		try {
			( $this->asset_enqueuer )( $screen, $asset_config );
		} catch ( \Throwable $exception ) {
			unset( $exception );
		}
	}

	/** Render the canonical refund application shell. */
	public function render(): void {
		if ( ! $this->canAccess() ) {
			return;
		}

		try {
			$config = ( $this->config_provider )( 'render' );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$config = array();
		}
		$browser_config  = is_array( $config ) && is_array( $config['browser_config'] ?? null )
			? $config['browser_config']
			: array();
		$initial_order_id = is_int( $browser_config['initialOrderId'] ?? null ) && $browser_config['initialOrderId'] > 0
			? (string) $browser_config['initialOrderId']
			: '';
		$escape = self::messageEscaper();

		echo '<div class="wrap ys-helcim-refund-admin" id="ys-helcim-refund-admin">';
		echo '<h1>' . $escape( 'Helcim Refunds' ) . '</h1>';
		$this->renderPanelBody( $initial_order_id );
		echo '</div>';
	}

	/**
	 * 在 FluentCart 訂單頁（spa）輸出隱藏的退款彈窗，掛在 admin_footer。
	 *
	 * 彈窗位於 #fluent_cart_plugin_app 之外，Vue 重繪不會清掉它；也不帶
	 * data-ys-helcim-refund-order／-enhancement 屬性，SPA 接管清理時不會被移除。
	 * 面板本體與獨立頁面共用 renderPanelBody()，控制項 id 只有一個來源。
	 */
	public function renderModal(): void {
		try {
			$config = ( $this->config_provider )( 'modal' );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return;
		}
		if ( ! is_array( $config ) ) {
			return;
		}

		$page = is_string( $config['page'] ?? null ) ? $config['page'] : '';
		// 與 enqueueAssets() 判定 spa 的條件相同；獨立頁面已有同一份面板，不可再輸出（id 會重複）。
		if ( 'spa' !== self::screenForPage( $page ) || ! $this->canAccess() ) {
			return;
		}

		$escape = self::messageEscaper();

		echo '<div id="ys-helcim-refund-modal" class="ys-helcim-refund-modal" hidden aria-hidden="true">';
		echo '<div class="ys-helcim-refund-modal__backdrop" data-ys-helcim-refund-modal-close></div>';
		echo '<div class="ys-helcim-refund-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="ys-helcim-refund-modal-title" tabindex="-1">';
		echo '<div class="ys-helcim-refund-modal__header">';
		echo '<h2 id="ys-helcim-refund-modal-title">' . $escape( 'Refund through Helcim' ) . '</h2>';
		echo '<button type="button" class="ys-helcim-refund-modal__close" data-ys-helcim-refund-modal-close aria-label="' . $escape( 'Close' ) . '">&times;</button>';
		echo '</div>';
		echo '<div class="ys-helcim-refund-admin ys-helcim-refund-modal__body" id="ys-helcim-refund-admin">';
		$this->renderPanelBody( '' );
		echo '</div>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * 退款面板本體（獨立頁面與訂單頁彈窗共用的唯一標記來源）。
	 *
	 * @param string $initial_order_id 已驗證的訂單編號字串；沒有時為空字串。
	 */
	private function renderPanelBody( string $initial_order_id ): void {
		$escape = self::messageEscaper();

		echo '<p class="description">' . $escape( 'Refund Helcim transactions remotely before FluentCart records the local refund.' ) . '</p>';
		echo '<form id="ys-helcim-refund-order-lookup" class="ys-helcim-refund-lookup" method="get" action="admin.php">';
		echo '<input type="hidden" name="page" value="' . self::PAGE_SLUG . '">';
		echo '<label for="ys-helcim-refund-order-id">' . $escape( 'Order ID' ) . '</label>';
		echo '<input id="ys-helcim-refund-order-id" name="order_id" type="number" min="1" step="1" required value="' . $initial_order_id . '">';
		echo '<button type="submit" class="button button-secondary">' . $escape( 'Load order' ) . '</button>';
		echo '</form>';
		// 狀態列在伺服器輸出時不可帶 notice／error class：FluentCart 後台根元件掛載時會執行
		// jQuery(".notice:not(.fluent-cart), .error:not(.fluent-cart)").remove()，帶了就整個被移出 DOM，
		// 訂單頁彈窗的所有狀態訊息都不會顯示。顯示訊息時才由 JS 加上 notice class 套用 WP 的通知樣式。
		echo '<div id="ys-helcim-refund-status" class="ys-helcim-refund-status inline" role="status" aria-live="polite" hidden></div>';
		echo '<section id="ys-helcim-refund-sync" class="ys-helcim-refund-sync" hidden>';
		echo '<p class="description">' . $escape( 'Refunded or voided this payment directly in Helcim? Sync it so FluentCart shows the same result.' ) . '</p>';
		echo '<button id="ys-helcim-refund-sync-button" type="button" class="button button-secondary">' . $escape( 'Sync refunds from Helcim' ) . '</button>';
		echo '</section>';
		echo '<section id="ys-helcim-refund-context" class="ys-helcim-refund-context" hidden>';
		echo '<div id="ys-helcim-refund-summary" class="ys-helcim-refund-summary"></div>';
		echo '<form id="ys-helcim-refund-form">';
		echo '<label for="ys-helcim-refund-transaction">' . $escape( 'Helcim transaction' ) . '</label>';
		echo '<select id="ys-helcim-refund-transaction" name="transaction_id" required></select>';
		echo '<label for="ys-helcim-refund-amount">' . $escape( 'Refund amount' ) . '</label>';
		echo '<input id="ys-helcim-refund-amount" name="amount" type="number" min="0.01" step="0.01" inputmode="decimal" aria-describedby="ys-helcim-refund-amount-note" required>';
		echo '<p id="ys-helcim-refund-amount-note" class="description">' . $escape( 'Payments that Helcim has not settled yet (usually the same day) can only be cancelled in full. A full refund is then sent as a void: no processing fee applies and the pending charge disappears from the card within 1 to 2 days. Partial refunds work once the payment has settled.' ) . '</p>';
		echo '<label for="ys-helcim-refund-reason">' . $escape( 'Reason' ) . '</label>';
		echo '<textarea id="ys-helcim-refund-reason" name="reason" rows="3" maxlength="500"></textarea>';
		echo '<fieldset><legend>' . $escape( 'Refunded items' ) . '</legend>';
		echo '<div id="ys-helcim-refund-items"></div>';
		echo '<label><input id="ys-helcim-refund-manage-stock" name="manage_stock" type="checkbox" aria-describedby="ys-helcim-refund-manage-stock-note" disabled> ' . $escape( 'Restore managed stock' ) . '</label>';
		echo '<p id="ys-helcim-refund-manage-stock-note" class="description">' . $escape( 'This version does not restore stock automatically. Adjust stock manually after the refund is reconciled.' ) . '</p>';
		echo '</fieldset>';
		echo '<label><input id="ys-helcim-refund-cancel-subscription" name="cancel_subscription" type="checkbox" aria-describedby="ys-helcim-refund-cancel-subscription-note" disabled> ' . $escape( 'Cancel the related subscription' ) . '</label>';
		echo '<p id="ys-helcim-refund-cancel-subscription-note" class="description">' . $escape( 'Subscription cancellation is not supported by this refund workflow. Cancel it separately after the refund is reconciled.' ) . '</p>';
		echo '<div class="ys-helcim-refund-actions">';
		echo '<button id="ys-helcim-refund-submit" type="submit" class="button button-primary">' . $escape( 'Submit Helcim refund' ) . '</button>';
		echo '<button id="ys-helcim-refund-reconcile" type="button" class="button button-secondary" hidden>' . $escape( 'Reconcile operation' ) . '</button>';
		echo '</div>';
		echo '</form>';
		echo '<dl id="ys-helcim-refund-operation" class="ys-helcim-refund-operation" aria-live="polite" hidden></dl>';
		echo '<section id="ys-helcim-refund-resolution" class="ys-helcim-refund-resolution" aria-labelledby="ys-helcim-refund-resolution-title" hidden>';
		echo '<h2 id="ys-helcim-refund-resolution-title">' . $escape( 'Resolve an indeterminate Helcim refund' ) . '</h2>';
		echo '<p class="description">' . $escape( 'Use only a verified positive Helcim transaction. This action cannot mark a refund as failed or unlock another submission.' ) . '</p>';
		echo '<label for="ys-helcim-refund-resolution-candidate">' . $escape( 'Candidate Helcim transaction ID' ) . '</label>';
		echo '<div class="ys-helcim-refund-resolution-inspect">';
		echo '<input id="ys-helcim-refund-resolution-candidate" type="text" inputmode="numeric" pattern="[1-9][0-9]*" autocomplete="off">';
		echo '<button id="ys-helcim-refund-resolution-inspect" type="button" class="button button-secondary">' . $escape( 'Inspect positive evidence' ) . '</button>';
		echo '</div>';
		echo '<dl id="ys-helcim-refund-resolution-evidence" class="ys-helcim-refund-resolution-evidence" hidden>';
		echo '<dt>' . $escape( 'Evidence' ) . '</dt><dd id="ys-helcim-refund-resolution-evidence-status"></dd>';
		echo '<dt>' . $escape( 'Source transaction' ) . '</dt><dd id="ys-helcim-refund-resolution-source"></dd>';
		// 候選交易在 Helcim 的類型、金額與 invoiceNumber（由 JS 以 textContent 填入），attestation 前可以核對。
		echo '<dt>' . $escape( 'Candidate transaction type' ) . '</dt><dd id="ys-helcim-refund-resolution-candidate-type"></dd>';
		echo '<dt>' . $escape( 'Candidate amount' ) . '</dt><dd id="ys-helcim-refund-resolution-candidate-amount"></dd>';
		echo '<dt>' . $escape( 'Helcim invoice number' ) . '</dt><dd id="ys-helcim-refund-resolution-invoice"></dd>';
		echo '<dt>' . $escape( 'Action' ) . '</dt><dd id="ys-helcim-refund-resolution-action"></dd>';
		echo '</dl>';
		echo '<div id="ys-helcim-refund-resolution-confirmation" class="ys-helcim-refund-resolution-confirmation" hidden>';
		echo '<label><input id="ys-helcim-refund-resolution-attestation" type="checkbox"> ' . $escape( 'I attest that the candidate belongs to the source transaction shown above.' ) . '</label>';
		echo '<p>' . $escape( 'Type this exact confirmation phrase:' ) . '</p>';
		echo '<code id="ys-helcim-refund-resolution-phrase" class="ys-helcim-refund-resolution-phrase"></code>';
		echo '<label for="ys-helcim-refund-resolution-typed-phrase">' . $escape( 'Confirmation phrase' ) . '</label>';
		echo '<input id="ys-helcim-refund-resolution-typed-phrase" type="text" autocomplete="off" spellcheck="false">';
		echo '<button id="ys-helcim-refund-resolution-commit" type="button" class="button button-primary" disabled>' . $escape( 'Commit positive resolution' ) . '</button>';
		echo '</div>';
		echo '</section>';
		echo '</section>';
	}

	/** 由頁面 slug 判定畫面：canonical（獨立頁面）、spa（FluentCart 後台）或空字串。 */
	private static function screenForPage( string $page ): string {
		return match ( $page ) {
			self::PAGE_SLUG => 'canonical',
			'fluent-cart'   => 'spa',
			default         => '',
		};
	}

	/**
	 * 伺服器端渲染字串的跳脫器：只接受 renderMessages() 內的鍵。
	 *
	 * @return \Closure(string):string
	 */
	private static function messageEscaper(): \Closure {
		$render_messages = self::renderMessages();

		return static fn ( string $key ): string => htmlspecialchars(
			$render_messages[ $key ] ?? '',
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8'
		);
	}

	/**
	 * Return server-owned browser copy so untrusted configuration cannot replace it.
	 *
	 * @return array<string, string>
	 */
	private static function browserMessages(): array {
		return array(
			'restSameOrigin'                    => __( 'The REST endpoint must use the same origin as WordPress.', 'ys-helcim-via-fluentcart' ),
			'requestFailed'                     => __( 'Request failed.', 'ys-helcim-via-fluentcart' ),
			'invalidRefundOptions'              => __( 'Invalid refund options.', 'ys-helcim-via-fluentcart' ),
			'invalidCandidateTransactionId'     => __( 'Enter a valid candidate Helcim transaction ID.', 'ys-helcim-via-fluentcart' ),
			'inspectingPositiveEvidence'        => __( 'Inspecting positive Helcim evidence…', 'ys-helcim-via-fluentcart' ),
			'invalidPositiveEvidenceResponse'   => __( 'The positive evidence response is invalid.', 'ys-helcim-via-fluentcart' ),
			'positiveEvidenceInspected'          => __( 'Positive evidence inspected. Complete the exact confirmation to continue.', 'ys-helcim-via-fluentcart' ),
			'positiveEvidenceInspectionFailed'  => __( 'Positive evidence could not be inspected.', 'ys-helcim-via-fluentcart' ),
			'committingPositiveResolution'       => __( 'Committing the positive refund resolution…', 'ys-helcim-via-fluentcart' ),
			'invalidPositiveResolutionResponse' => __( 'The positive resolution response is invalid.', 'ys-helcim-via-fluentcart' ),
			'positiveResolutionCommitted'        => __( 'Positive resolution committed. Reading the canonical refund operation…', 'ys-helcim-via-fluentcart' ),
			'positiveResolutionUnknown'          => __( 'Positive resolution status is unknown.', 'ys-helcim-via-fluentcart' ),
			'refundPageUnavailable'              => __( 'Refund page is unavailable.', 'ys-helcim-via-fluentcart' ),
			'noRefundableTransaction'            => __( 'No refundable Helcim transaction was found for this order.', 'ys-helcim-via-fluentcart' ),
			'refundBlocked'                      => __( 'This Helcim refund is blocked until its accounting state is reconciled.', 'ys-helcim-via-fluentcart' ),
			/* translators: 1: order ID, 2: currency code. */
			'orderSummary'                       => __( 'Order #%1$s · %2$s', 'ys-helcim-via-fluentcart' ),
			'refundOptionsLoaded'                => __( 'Refund options loaded.', 'ys-helcim-via-fluentcart' ),
			'invalidOrderId'                     => __( 'Invalid order ID.', 'ys-helcim-via-fluentcart' ),
			'refundOptionsRequired'              => __( 'Refund options must be loaded first.', 'ys-helcim-via-fluentcart' ),
			'refundFormUnavailable'              => __( 'Refund form is unavailable.', 'ys-helcim-via-fluentcart' ),
			'invalidRefundAmount'                => __( 'Enter a valid refund amount.', 'ys-helcim-via-fluentcart' ),
			'operationLabel'                     => __( 'Operation', 'ys-helcim-via-fluentcart' ),
			'effectiveOperationLabel'            => __( 'Effective operation', 'ys-helcim-via-fluentcart' ),
			'providerActionLabel'                => __( 'Provider action', 'ys-helcim-via-fluentcart' ),
			'remoteStatusLabel'                  => __( 'Remote status', 'ys-helcim-via-fluentcart' ),
			'localStatusLabel'                   => __( 'Local status', 'ys-helcim-via-fluentcart' ),
			'notificationLabel'                  => __( 'Notification', 'ys-helcim-via-fluentcart' ),
			'effectStatusLabel'                  => __( 'Effect status', 'ys-helcim-via-fluentcart' ),
			'warningsLabel'                      => __( 'Warnings', 'ys-helcim-via-fluentcart' ),
			'errorCodeLabel'                     => __( 'Error code', 'ys-helcim-via-fluentcart' ),
			'providerOutcomeIndeterminate'       => __( 'The provider outcome is indeterminate. Do not submit another refund; inspect positive evidence or reconcile this operation.', 'ys-helcim-via-fluentcart' ),
			'manualReconciliationRequired'      => __( 'The provider refund succeeded, but manual stock or local reconciliation is required. Do not submit another refund.', 'ys-helcim-via-fluentcart' ),
			'refundCompleted'                    => __( 'Helcim refunded the payment and FluentCart recorded the refund. The money usually reaches the card within 5 to 10 business days.', 'ys-helcim-via-fluentcart' ),
			'paymentVoided'                      => __( 'The payment had not settled yet, so Helcim cancelled (voided) it instead of refunding it, and FluentCart recorded the refund. No processing fee applies, and the pending charge disappears from the card within 1 to 2 days.', 'ys-helcim-via-fluentcart' ),
			'refundNotCompleted'                 => __( 'The refund was not completed. Review the result before trying again.', 'ys-helcim-via-fluentcart' ),
			'openBatchPartialRefund'             => __( 'This payment has not settled yet, so Helcim can only cancel the full amount. No money was moved. Enter the full amount to cancel the payment now, or make this partial refund after the payment settles (Helcim settles once a day).', 'ys-helcim-via-fluentcart' ),
			'openBatchUnproven'                  => __( 'Helcim did not accept the refund, and the plugin could not confirm that the payment is still unsettled, so nothing was sent. Wait a few minutes and try again.', 'ys-helcim-via-fluentcart' ),
			'operationStatusUnreadable'          => __( 'Operation status could not be read.', 'ys-helcim-via-fluentcart' ),
			'refundStillReconciling'             => __( 'The refund is still reconciling. Do not submit it again; reconcile this operation.', 'ys-helcim-via-fluentcart' ),
			'noOperationToReconcile'             => __( 'There is no valid operation to reconcile.', 'ys-helcim-via-fluentcart' ),
			'readingDurableOperation'            => __( 'Reading the durable refund operation…', 'ys-helcim-via-fluentcart' ),
			'invalidRefundIntent'                => __( 'Refund intent is invalid.', 'ys-helcim-via-fluentcart' ),
			'submittingRefund'                   => __( 'Submitting the Helcim refund…', 'ys-helcim-via-fluentcart' ),
			'refundStatusUnknownNoRetry'         => __( 'Refund status is unknown. Do not submit it again.', 'ys-helcim-via-fluentcart' ),
			'refundStatusUnknown'                => __( 'Refund status is unknown.', 'ys-helcim-via-fluentcart' ),
			'refundOptionsLoadFailed'            => __( 'Refund options could not be loaded.', 'ys-helcim-via-fluentcart' ),
			'classificationPending'              => __( 'Checking whether this payment was taken through Helcim. Please wait a moment and try again.', 'ys-helcim-via-fluentcart' ),
			'syncingProviderRefunds'             => __( 'Checking Helcim for refunds or voids made outside this plugin…', 'ys-helcim-via-fluentcart' ),
			/* translators: %1$s: number of Helcim refunds or voids that were recorded. */
			'providerRefundsRecorded'            => __( 'Recorded refunds or voids from Helcim: %1$s. FluentCart now matches Helcim.', 'ys-helcim-via-fluentcart' ),
			'providerRefundsNothingNew'          => __( 'Helcim has no refunds or voids for this order that are missing from FluentCart.', 'ys-helcim-via-fluentcart' ),
			'providerRefundsNoHelcimPayment'     => __( 'This order has no completed Helcim payment to check.', 'ys-helcim-via-fluentcart' ),
			/* translators: %1$s: comma-separated review reason codes. */
			'providerRefundsNeedReview'          => __( 'Some refunds in Helcim could not be recorded automatically (%1$s). Compare this order with Helcim before refunding again.', 'ys-helcim-via-fluentcart' ),
			'providerRefundsRetryLater'          => __( 'Another refund for this order is still in progress, or Helcim could not be reached. Try again in a few minutes.', 'ys-helcim-via-fluentcart' ),
			'providerRefundsSyncFailed'          => __( 'Refunds could not be synced from Helcim.', 'ys-helcim-via-fluentcart' ),
			'providerRefundsLegacyPayment'       => __( 'This order was paid before this plugin started keeping a payment journal, so a refund or void made in Helcim cannot be synced automatically. Compare this order with Helcim before refunding.', 'ys-helcim-via-fluentcart' ),
			'batchClosedRefundRejected'          => __( 'Helcim rejected this refund, and the payment has already settled, so nothing was sent. Check in Helcim whether this payment was already refunded or voided. If it was, use “Sync refunds from Helcim” to record it in FluentCart.', 'ys-helcim-via-fluentcart' ),
			'providerRefundPending'              => __( 'A refund or void made directly in Helcim for this order is not recorded in FluentCart yet. Use “Sync refunds from Helcim” to finish recording it before refunding again.', 'ys-helcim-via-fluentcart' ),
			'refundModalBusy'                    => __( 'The refund request is still being processed. Wait for the result before closing this window.', 'ys-helcim-via-fluentcart' ),
		);
	}

	/**
	 * Return canonical page copy with static gettext arguments for extraction.
	 *
	 * @return array<string, string>
	 */
	private static function renderMessages(): array {
		return array(
			'Helcim Refunds' => __( 'Helcim Refunds', 'ys-helcim-via-fluentcart' ),
			'Refund Helcim transactions remotely before FluentCart records the local refund.' => __( 'Refund Helcim transactions remotely before FluentCart records the local refund.', 'ys-helcim-via-fluentcart' ),
			'Order ID' => __( 'Order ID', 'ys-helcim-via-fluentcart' ),
			'Load order' => __( 'Load order', 'ys-helcim-via-fluentcart' ),
			'Refunded or voided this payment directly in Helcim? Sync it so FluentCart shows the same result.' => __( 'Refunded or voided this payment directly in Helcim? Sync it so FluentCart shows the same result.', 'ys-helcim-via-fluentcart' ),
			'Sync refunds from Helcim' => __( 'Sync refunds from Helcim', 'ys-helcim-via-fluentcart' ),
			'Helcim transaction' => __( 'Helcim transaction', 'ys-helcim-via-fluentcart' ),
			'Refund amount' => __( 'Refund amount', 'ys-helcim-via-fluentcart' ),
			'Payments that Helcim has not settled yet (usually the same day) can only be cancelled in full. A full refund is then sent as a void: no processing fee applies and the pending charge disappears from the card within 1 to 2 days. Partial refunds work once the payment has settled.' => __( 'Payments that Helcim has not settled yet (usually the same day) can only be cancelled in full. A full refund is then sent as a void: no processing fee applies and the pending charge disappears from the card within 1 to 2 days. Partial refunds work once the payment has settled.', 'ys-helcim-via-fluentcart' ),
			'Reason' => __( 'Reason', 'ys-helcim-via-fluentcart' ),
			'Refunded items' => __( 'Refunded items', 'ys-helcim-via-fluentcart' ),
			'Restore managed stock' => __( 'Restore managed stock', 'ys-helcim-via-fluentcart' ),
			'This version does not restore stock automatically. Adjust stock manually after the refund is reconciled.' => __( 'This version does not restore stock automatically. Adjust stock manually after the refund is reconciled.', 'ys-helcim-via-fluentcart' ),
			'Cancel the related subscription' => __( 'Cancel the related subscription', 'ys-helcim-via-fluentcart' ),
			'Subscription cancellation is not supported by this refund workflow. Cancel it separately after the refund is reconciled.' => __( 'Subscription cancellation is not supported by this refund workflow. Cancel it separately after the refund is reconciled.', 'ys-helcim-via-fluentcart' ),
			'Submit Helcim refund' => __( 'Submit Helcim refund', 'ys-helcim-via-fluentcart' ),
			'Reconcile operation' => __( 'Reconcile operation', 'ys-helcim-via-fluentcart' ),
			'Resolve an indeterminate Helcim refund' => __( 'Resolve an indeterminate Helcim refund', 'ys-helcim-via-fluentcart' ),
			'Use only a verified positive Helcim transaction. This action cannot mark a refund as failed or unlock another submission.' => __( 'Use only a verified positive Helcim transaction. This action cannot mark a refund as failed or unlock another submission.', 'ys-helcim-via-fluentcart' ),
			'Candidate Helcim transaction ID' => __( 'Candidate Helcim transaction ID', 'ys-helcim-via-fluentcart' ),
			'Inspect positive evidence' => __( 'Inspect positive evidence', 'ys-helcim-via-fluentcart' ),
			'Evidence' => __( 'Evidence', 'ys-helcim-via-fluentcart' ),
			'Source transaction' => __( 'Source transaction', 'ys-helcim-via-fluentcart' ),
			'Candidate transaction type' => __( 'Candidate transaction type', 'ys-helcim-via-fluentcart' ),
			'Candidate amount' => __( 'Candidate amount', 'ys-helcim-via-fluentcart' ),
			'Helcim invoice number' => __( 'Helcim invoice number', 'ys-helcim-via-fluentcart' ),
			'Action' => __( 'Action', 'ys-helcim-via-fluentcart' ),
			'I attest that the candidate belongs to the source transaction shown above.' => __( 'I attest that the candidate belongs to the source transaction shown above.', 'ys-helcim-via-fluentcart' ),
			'Type this exact confirmation phrase:' => __( 'Type this exact confirmation phrase:', 'ys-helcim-via-fluentcart' ),
			'Confirmation phrase' => __( 'Confirmation phrase', 'ys-helcim-via-fluentcart' ),
			'Commit positive resolution' => __( 'Commit positive resolution', 'ys-helcim-via-fluentcart' ),
			'Refund through Helcim' => __( 'Refund through Helcim', 'ys-helcim-via-fluentcart' ),
			'Close' => __( 'Close', 'ys-helcim-via-fluentcart' ),
		);
	}

	private function canAccess(): bool {
		try {
			$can_view   = true === ( $this->permission_checker )( 'orders/view' );
			$can_refund = true === ( $this->permission_checker )( 'orders/can_refund' );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return false;
		}

		return $can_view && $can_refund;
	}
}
