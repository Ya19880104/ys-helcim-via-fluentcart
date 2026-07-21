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
		$screen = match ( $page ) {
			self::PAGE_SLUG => 'canonical',
			'fluent-cart'   => 'spa',
			default         => '',
		};
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
					)
				)
			)
			: array();
		$browser_config['screen'] = $screen;
		if ( array_key_exists( 'canResolve', $browser_config ) ) {
			$browser_config['canResolve'] = true === $browser_config['canResolve'];
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
		$render_messages = self::renderMessages();
		$escape          = static fn ( string $key ): string => htmlspecialchars(
			$render_messages[ $key ] ?? '',
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8'
		);

		echo '<div class="wrap ys-helcim-refund-admin" id="ys-helcim-refund-admin">';
		echo '<h1>' . $escape( 'Helcim Refunds' ) . '</h1>';
		echo '<p class="description">' . $escape( 'Refund Helcim transactions remotely before FluentCart records the local refund.' ) . '</p>';
		echo '<form id="ys-helcim-refund-order-lookup" class="ys-helcim-refund-lookup" method="get" action="admin.php">';
		echo '<input type="hidden" name="page" value="' . self::PAGE_SLUG . '">';
		echo '<label for="ys-helcim-refund-order-id">' . $escape( 'Order ID' ) . '</label>';
		echo '<input id="ys-helcim-refund-order-id" name="order_id" type="number" min="1" step="1" required value="' . $initial_order_id . '">';
		echo '<button type="submit" class="button button-secondary">' . $escape( 'Load order' ) . '</button>';
		echo '</form>';
		echo '<div id="ys-helcim-refund-status" class="notice inline" role="status" aria-live="polite" hidden></div>';
		echo '<section id="ys-helcim-refund-context" class="ys-helcim-refund-context" hidden>';
		echo '<div id="ys-helcim-refund-summary" class="ys-helcim-refund-summary"></div>';
		echo '<form id="ys-helcim-refund-form">';
		echo '<label for="ys-helcim-refund-transaction">' . $escape( 'Helcim transaction' ) . '</label>';
		echo '<select id="ys-helcim-refund-transaction" name="transaction_id" required></select>';
		echo '<label for="ys-helcim-refund-amount">' . $escape( 'Refund amount' ) . '</label>';
		echo '<input id="ys-helcim-refund-amount" name="amount" type="number" min="0.01" step="0.01" inputmode="decimal" required>';
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
		echo '</div>';
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
			'refundCompleted'                    => __( 'The Helcim refund and local reconciliation completed.', 'ys-helcim-via-fluentcart' ),
			'refundNotCompleted'                 => __( 'The refund was not completed. Review the result before trying again.', 'ys-helcim-via-fluentcart' ),
			'operationStatusUnreadable'          => __( 'Operation status could not be read.', 'ys-helcim-via-fluentcart' ),
			'refundStillReconciling'             => __( 'The refund is still reconciling. Do not submit it again; reconcile this operation.', 'ys-helcim-via-fluentcart' ),
			'noOperationToReconcile'             => __( 'There is no valid operation to reconcile.', 'ys-helcim-via-fluentcart' ),
			'readingDurableOperation'            => __( 'Reading the durable refund operation…', 'ys-helcim-via-fluentcart' ),
			'invalidRefundIntent'                => __( 'Refund intent is invalid.', 'ys-helcim-via-fluentcart' ),
			'submittingRefund'                   => __( 'Submitting the Helcim refund…', 'ys-helcim-via-fluentcart' ),
			'refundStatusUnknownNoRetry'         => __( 'Refund status is unknown. Do not submit it again.', 'ys-helcim-via-fluentcart' ),
			'refundStatusUnknown'                => __( 'Refund status is unknown.', 'ys-helcim-via-fluentcart' ),
			'refundOptionsLoadFailed'            => __( 'Refund options could not be loaded.', 'ys-helcim-via-fluentcart' ),
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
			'Helcim transaction' => __( 'Helcim transaction', 'ys-helcim-via-fluentcart' ),
			'Refund amount' => __( 'Refund amount', 'ys-helcim-via-fluentcart' ),
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
			'Action' => __( 'Action', 'ys-helcim-via-fluentcart' ),
			'I attest that the candidate belongs to the source transaction shown above.' => __( 'I attest that the candidate belongs to the source transaction shown above.', 'ys-helcim-via-fluentcart' ),
			'Type this exact confirmation phrase:' => __( 'Type this exact confirmation phrase:', 'ys-helcim-via-fluentcart' ),
			'Confirmation phrase' => __( 'Confirmation phrase', 'ys-helcim-via-fluentcart' ),
			'Commit positive resolution' => __( 'Commit positive resolution', 'ys-helcim-via-fluentcart' ),
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
