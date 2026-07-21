<?php
/**
 * YS Helcim FluentCart Bootstrap — plugin bootstrapper.
 *
 * Responsibilities: check the FluentCart dependency and register both payment
 * methods with FluentCart (ys_helcim = HelcimPay.js modal, ys_helcim_js =
 * helcim.js inline form).
 *
 * @package YangSheep\Helcim\FluentCart
 */

namespace YangSheep\Helcim\FluentCart;

use FluentCart\App\Models\OrderTransaction;
use YangSheep\Helcim\FluentCart\Admin\YSHelcimRefundAdminPage;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimInlineCheckoutCartLock;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsPurchaseRuntime;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPaySettings;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPayRecoveryService;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsSettings;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationSchema;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationState;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOutboxRepository;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOutboxWorker;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimStoragePreflight;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimLocalRefundRecorder;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimNativeRefundVeto;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundContextLoader;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundEffectHandlers;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundEffectIntegrityVerifier;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundFinalizer;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundLocalCoordinator;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundOptionsLoader;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundOptionsQuery;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundRequest;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundResolutionRepository;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundResolutionRestController;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundResolutionSchema;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundResolutionService;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundRestController;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundService;
use YangSheep\Helcim\FluentCart\Settings\YSHelcimWebhookVerifierModeMigration;
use YangSheep\Helcim\FluentCart\Support\YSHelcimApiClient;
use YangSheep\Helcim\FluentCart\Support\YSHelcimLogger;
use YangSheep\Helcim\FluentCart\Webhook\YSHelcimWebhookHandler;
use YangSheep\Helcim\FluentCart\Webhook\YSHelcimWebhookOperationBindingResolver;
use YangSheep\Helcim\FluentCart\Webhook\YSHelcimWebhookPurchaseReconciler;
use YangSheep\Helcim\FluentCart\Webhook\YSHelcimWebhookReceiptRepository;
use YangSheep\Helcim\FluentCart\Webhook\YSHelcimWebhookRestController;
use YangSheep\Helcim\FluentCart\Webhook\YSHelcimWebhookVerifier;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrapper (singleton).
 *
 * Timeline: on plugins_loaded we check for FluentCart, then on
 * fluent_cart/register_payment_methods (fired by FluentCart during init) we
 * register the gateways.
 */
final class YSHelcimFctBootstrap {

	private const OUTBOX_CRON_HOOK = 'ys_helcim_process_refund_outbox';

	private const OUTBOX_SWEEP_HOOK = 'ys_helcim_sweep_refund_outbox';

	private const HOSTED_PURCHASE_RECONCILE_HOOK = 'ys_helcim_reconcile_hosted_purchases';

	private const OUTBOX_SWEEP_INTERVAL = 'ys_helcim_minute';

	private const OUTBOX_STALE_SECONDS = 300;

	private const OUTBOX_SWEEP_LIMIT = 50;

	private const OUTBOX_SWEEP_MAX_FUTURE_SECONDS = 120;

	private const HOSTED_RECOVERY_BATCH_LIMIT = 2;

	private const HOSTED_LOCAL_CLAIM_STALE_SECONDS = 300;

	private const WEBHOOK_RECEIPT_RETENTION_SECONDS = 604800;

	/**
	 * Singleton instance.
	 *
	 * @var YSHelcimFctBootstrap|null
	 */
	private static $instance = null;

	/** @var array<string,object>|\WP_Error|null */
	private $refund_runtime = null;

	/** @var YSHelcimRefundAdminPage|\WP_Error|null */
	private $refund_admin = null;

	/** @var array<string,object>|\WP_Error|null */
	private $refund_resolution_runtime = null;

	/** @var array<string,object>|\WP_Error|null */
	private $webhook_runtime = null;

	/** @var array<string,object>|\WP_Error|null */
	private $hosted_recovery_runtime = null;

	/** @var YSHelcimInlineCheckoutCartLock|null */
	private $inline_checkout_lock = null;

	/**
	 * Get (or create) the single instance and hook up the bootstrap actions.
	 *
	 * @return YSHelcimFctBootstrap
	 */
	public static function init(): YSHelcimFctBootstrap {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Remove only this plugin's durable-refund cron events on deactivation. */
	public static function deactivate( bool $network_wide = false ): void {
		unset( $network_wide );

		if ( ! function_exists( '_get_cron_array' ) || ! function_exists( 'wp_unschedule_event' ) ) {
			return;
		}

		$crons = _get_cron_array();
		foreach ( is_array( $crons ) ? $crons : array() as $timestamp => $hooks ) {
			if ( ! is_numeric( $timestamp ) || ! is_array( $hooks ) ) {
				continue;
			}

			$recurring_hooks = array( self::OUTBOX_SWEEP_HOOK, self::HOSTED_PURCHASE_RECONCILE_HOOK );
			foreach ( array_merge( $recurring_hooks, array( self::OUTBOX_CRON_HOOK ) ) as $hook ) {
				$events = $hooks[ $hook ] ?? array();
				foreach ( is_array( $events ) ? $events : array() as $event ) {
					if ( ! is_array( $event ) || ! isset( $event['args'] ) || ! is_array( $event['args'] ) ) {
						continue;
					}

					$is_owned_recurring = in_array( $hook, $recurring_hooks, true )
						&& array() === $event['args']
						&& is_string( $event['schedule'] ?? null )
						&& '' !== ( $event['schedule'] ?? '' );
					$is_owned_single = self::OUTBOX_CRON_HOOK === $hook
						&& false === ( $event['schedule'] ?? null )
						&& array( 0 ) === array_keys( $event['args'] )
						&& self::isUuid( $event['args'][0] );

					if ( $is_owned_recurring || $is_owned_single ) {
						wp_unschedule_event( (int) $timestamp, $hook, $event['args'], true );
					}
				}
			}
		}
	}

	/**
	 * Constructor: hook into plugins_loaded (private — only reachable via init()).
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'onPluginsLoaded' ) );
	}

	/**
	 * plugins_loaded callback: check whether FluentCart is active.
	 *
	 * If it is not active, show an admin notice and register no gateways.
	 * If it is active, hook into FluentCart's gateway registration action.
	 *
	 * @return void
	 */
	public function onPluginsLoaded(): void {
		add_action( 'init', array( $this, 'onInit' ), 0 );
	}

	/**
	 * Initialize translated runtime services at the earliest safe WordPress hook.
	 *
	 * WordPress 6.7+ warns when a translation is resolved before `init`. Keeping
	 * the plugins_loaded callback hook-only also prevents cron schedule labels and
	 * schema failure messages from triggering just-in-time translation too early.
	 * Priority zero still registers the FluentCart payment-method callback before
	 * FluentCart's normal init callback runs.
	 */
	public function onInit(): void {
		load_plugin_textdomain(
			'ys-helcim-via-fluentcart',
			false,
			dirname( plugin_basename( YS_HELCIM_FCT_FILE ) ) . '/languages'
		);

		if ( $this->isFluentCartActive() ) {
			add_filter(
				'fluent_cart/transaction/max_refundable_amount',
				array( YSHelcimNativeRefundVeto::class, 'filter' ),
				PHP_INT_MAX,
				2
			);
		}

		if (
			$this->isFluentCartActive() &&
			! YSHelcimWebhookVerifierModeMigration::maybeMigrate()
		) {
			YSHelcimLogger::error( 'Webhook verifier mode migration could not be completed' );
			return;
		}

		if (
			! YSHelcimOperationSchema::maybeUpgrade() ||
			! YSHelcimRefundResolutionSchema::maybeUpgrade()
		) {
			add_action( 'admin_notices', array( $this, 'renderOperationSchemaNotice' ) );
			return;
		}

		if ( ! $this->isFluentCartActive() ) {
			add_action( 'admin_notices', array( $this, 'renderMissingFluentCartNotice' ) );
			return;
		}

		// FluentCart fires this action inside its init hook (GlobalPaymentHandler::init).
		add_action( 'fluent_cart/register_payment_methods', array( $this, 'registerGateways' ) );
		$this->inline_checkout_lock ??= new YSHelcimInlineCheckoutCartLock();
		add_filter(
			'fluent_cart/checkout/validate_before_process',
			array( $this->inline_checkout_lock, 'validate' ),
			PHP_INT_MAX,
			2
		);
		add_action( 'rest_api_init', array( $this, 'registerRefundRoutes' ) );
		add_action( 'rest_api_init', array( $this, 'registerRefundResolutionRoutes' ) );
		add_action( 'rest_api_init', array( $this, 'registerWebhookRoutes' ) );
		$refund_admin = $this->refundAdminPage();
		if ( is_wp_error( $refund_admin ) ) {
			YSHelcimLogger::error( 'Refund admin page initialization failed' );
		} else {
			// FluentCart 1.5.x builds its submenu manually at the default priority.
			// Register afterwards so its dashboard remains the canonical first entry.
			add_action( 'admin_menu', array( $refund_admin, 'registerMenu' ), 99 );
			add_action( 'admin_enqueue_scripts', array( $refund_admin, 'enqueueAssets' ), 10, 1 );
		}
		add_action( 'admin_notices', array( $this, 'renderHostedPurchaseAttentionNotice' ) );
		add_action( 'admin_post_ys_helcim_retry_hosted_recovery', array( $this, 'handleHostedPurchaseManualRetry' ) );
		add_action( self::OUTBOX_CRON_HOOK, array( $this, 'processRefundOutbox' ), 10, 1 );
		add_filter( 'cron_schedules', array( $this, 'registerCronIntervals' ) );
		add_action( self::OUTBOX_SWEEP_HOOK, array( $this, 'sweepRefundOutbox' ) );
		if ( ! $this->ensureRefundOutboxSweep() ) {
			YSHelcimLogger::error( 'Refund outbox recurring sweep could not be scheduled' );
		}
		add_action( self::HOSTED_PURCHASE_RECONCILE_HOOK, array( $this, 'reconcileHostedPurchases' ) );
		if ( ! $this->ensureHostedPurchaseReconciliation() ) {
			YSHelcimLogger::error( 'Hosted purchase reconciliation sweep could not be scheduled' );
		}
	}

	/** Register the remote-first refund REST routes after WordPress REST init. */
	public function registerRefundRoutes(): void {
		$runtime = $this->refundRuntime();
		if ( is_wp_error( $runtime ) ) {
			YSHelcimLogger::error( 'Refund runtime registration failed' );
			return;
		}

		$runtime['controller']->registerRoutes();
	}

	/** Register the positive-only indeterminate-refund resolution routes. */
	public function registerRefundResolutionRoutes(): void {
		$runtime = $this->refundResolutionRuntime();
		if ( is_wp_error( $runtime ) ) {
			YSHelcimLogger::error( 'Refund resolution runtime registration failed' );
			return;
		}

		$runtime['controller']->registerRoutes();
	}

	/** Register the clean account-level card event route. */
	public function registerWebhookRoutes(): void {
		$runtime = $this->webhookRuntime();
		if ( is_wp_error( $runtime ) ) {
			YSHelcimLogger::error( 'Webhook runtime registration failed' );
			return;
		}

		$runtime['controller']->registerRoutes();
	}

	/** Resume one recorded refund from its durable WordPress cron event. */
	public function processRefundOutbox( mixed $operation_uuid ): void {
		if ( ! self::isUuid( $operation_uuid ) ) {
			return;
		}

		$runtime = $this->refundRuntime();
		if ( is_wp_error( $runtime ) ) {
			YSHelcimLogger::error( 'Refund outbox runtime unavailable' );
			return;
		}

		$cutoff    = self::outboxStaleCutoff();
		$recovered = $runtime['outbox']->recoverStaleOperationUuids( $cutoff, self::OUTBOX_SWEEP_LIMIT );
		if ( is_wp_error( $recovered ) ) {
			YSHelcimLogger::error(
				'Refund outbox stale-claim recovery failed',
				array( 'operation_uuid' => $operation_uuid, 'error_code' => $recovered->get_error_code() )
			);
			$recovered = array();
		}

		foreach ( array_values( array_unique( array_merge( $recovered, array( $operation_uuid ) ) ) ) as $candidate_uuid ) {
			$result = $runtime['coordinator']->record( $candidate_uuid );
			if ( is_wp_error( $result ) ) {
				YSHelcimLogger::error(
					'Refund outbox processing failed',
					array( 'operation_uuid' => $candidate_uuid, 'error_code' => $result->get_error_code() )
				);
			}
		}
	}

	/** Add the bounded one-minute recovery cadence used by the durable outbox. */
	public function registerCronIntervals( array $schedules ): array {
		$schedules[ self::OUTBOX_SWEEP_INTERVAL ] = array(
			'interval' => 60,
			'display'  => __( 'Every minute (YS Helcim refund recovery)', 'ys-helcim-via-fluentcart' ),
		);
		return $schedules;
	}

	/** Ensure a persistent global sweep exists before any refund is accepted. */
	public function ensureRefundOutboxSweep(): bool {
		return $this->ensureRecurringSweep( self::OUTBOX_SWEEP_HOOK );
	}

	/** Ensure the hosted lost-callback recovery sweep remains scheduled. */
	public function ensureHostedPurchaseReconciliation(): bool {
		return $this->ensureRecurringSweep( self::HOSTED_PURCHASE_RECONCILE_HOOK );
	}

	/** Ensure one exact no-argument recurring recovery event. */
	private function ensureRecurringSweep( string $hook ): bool {
		if ( ! function_exists( 'wp_get_scheduled_event' ) || ! function_exists( 'wp_unschedule_event' ) ) {
			return false;
		}

		$now = time();
		for ( $attempt = 0; $attempt < 10; ++$attempt ) {
			$event = wp_get_scheduled_event( $hook, array() );
			if ( false === $event ) {
				$scheduled = wp_schedule_event(
					$now + 60,
					self::OUTBOX_SWEEP_INTERVAL,
					$hook,
					array(),
					true
				);
				if ( is_wp_error( $scheduled ) || false === $scheduled ) {
					return false;
				}
				continue;
			}

			$timestamp = isset( $event->timestamp ) && is_int( $event->timestamp ) ? $event->timestamp : 0;
			$schedule  = isset( $event->schedule ) && is_string( $event->schedule ) ? $event->schedule : '';
			if (
				self::OUTBOX_SWEEP_INTERVAL === $schedule &&
				$timestamp >= $now - self::OUTBOX_STALE_SECONDS &&
				$timestamp <= $now + self::OUTBOX_SWEEP_MAX_FUTURE_SECONDS
			) {
				return true;
			}

			$unscheduled = wp_unschedule_event(
				$timestamp,
				$hook,
				array(),
				true
			);
			if ( is_wp_error( $unscheduled ) || false === $unscheduled ) {
				return false;
			}
		}

		return false;
	}

	/** Reconcile expired hosted checkout attempts from authenticated provider evidence. */
	public function reconcileHostedPurchases(): void {
		$runtime = $this->hostedPurchaseRecoveryRuntime();
		if ( is_wp_error( $runtime ) ) {
			YSHelcimLogger::error( 'Hosted purchase recovery runtime unavailable' );
			return;
		}

		$now                  = time();
		$due_before           = gmdate( 'Y-m-d H:i:s', $now );
		$created_before       = gmdate( 'Y-m-d H:i:s', $now - YSHelcimPayRecoveryService::LOOKUP_ELIGIBILITY_SECONDS );
		$local_claimed_before = gmdate( 'Y-m-d H:i:s', $now - self::HOSTED_LOCAL_CLAIM_STALE_SECONDS );
		$lease_until          = gmdate( 'Y-m-d H:i:s', $now + YSHelcimPayRecoveryService::RECOVERY_LEASE_SECONDS );
		$rows = $runtime['operations']->findHostedPurchasesNeedingRecovery(
			$created_before,
			$due_before,
			$local_claimed_before,
			YSHelcimPayRecoveryService::MAX_AUTOMATIC_RECOVERY_ATTEMPTS,
			self::HOSTED_RECOVERY_BATCH_LIMIT
		);
		if ( is_wp_error( $rows ) ) {
			YSHelcimLogger::error( 'Hosted purchase recovery scan failed', array( 'error_code' => $rows->get_error_code() ) );
			return;
		}

		foreach ( $rows as $row ) {
			$operation_uuid = is_array( $row ) ? (string) ( $row['operation_uuid'] ?? '' ) : '';
			if ( ! self::isUuid( $operation_uuid ) ) {
				continue;
			}
			$claimed = $runtime['operations']->claimHostedRecovery(
				$operation_uuid,
				$due_before,
				$local_claimed_before,
				$lease_until,
				YSHelcimPayRecoveryService::MAX_AUTOMATIC_RECOVERY_ATTEMPTS
			);
			if ( is_wp_error( $claimed ) ) {
				YSHelcimLogger::error(
					'Hosted purchase recovery claim failed',
					array( 'operation_uuid' => $operation_uuid, 'error_code' => $claimed->get_error_code() )
				);
				continue;
			}
			if ( true !== $claimed ) {
				continue;
			}
			$result = $runtime['service']->recover( $operation_uuid );
			if ( is_wp_error( $result ) ) {
				YSHelcimLogger::error(
					'Hosted purchase recovery operation failed',
					array( 'operation_uuid' => $operation_uuid, 'error_code' => $result->get_error_code() )
				);
			}

			$current = $runtime['operations']->findByUuidStrict( $operation_uuid );
			if ( is_wp_error( $current ) || ! is_array( $current ) ) {
				YSHelcimLogger::error( 'Hosted purchase recovery state could not be verified', array( 'operation_uuid' => $operation_uuid ) );
				continue;
			}
			if ( '' === (string) ( $current['active_scope_key'] ?? '' ) ) {
				continue;
			}

			$attempt_count = (int) ( $current['recovery_attempt_count'] ?? 0 );
			if ( $attempt_count < 1 ) {
				// An exact approval resets the provider-query budget. If its first
				// local bind failed, leave it immediately due for local-only replay.
				continue;
			}
			$delay = YSHelcimPayRecoveryService::retryDelayAfterAttempt( $attempt_count );
			if ( null === $delay ) {
				$error_code = 'ys_helcim_hosted_recovery_attention_required';
				$error_message = __( 'Automatic hosted payment recovery paused without exact proof. Check Helcim using the operation ID; the payment remains locked.', 'ys-helcim-via-fluentcart' );
				$next_recovery_at = null;
			} else {
				if ( is_wp_error( $result ) ) {
					$error_code    = (string) $result->get_error_code();
					$error_message = (string) $result->get_error_message();
				} else {
					$is_local_recovery = YSHelcimOperationState::REMOTE_SUCCEEDED === (string) ( $current['remote_status'] ?? '' );
					$persisted_code = (string) ( $current[ $is_local_recovery ? 'local_error_code' : 'remote_error_code' ] ?? '' );
					$persisted_message = (string) ( $current[ $is_local_recovery ? 'local_error_message' : 'remote_error_message' ] ?? '' );
					$error_code = '' !== $persisted_code
						? $persisted_code
						: (string) ( $result['reason'] ?? 'ys_helcim_hosted_recovery_unresolved' );
					$error_message = '' !== $persisted_message
						? $persisted_message
						: __( 'Hosted payment recovery remains unresolved and will be retried with bounded backoff.', 'ys-helcim-via-fluentcart' );
				}
				$next_recovery_at = gmdate( 'Y-m-d H:i:s', $now + $delay );
			}

			$deferred = $runtime['operations']->deferHostedRecovery(
				$operation_uuid,
				$attempt_count,
				$lease_until,
				$next_recovery_at,
				$error_code,
				$error_message
			);
			if ( is_wp_error( $deferred ) || true !== $deferred ) {
				YSHelcimLogger::error( 'Hosted purchase recovery backoff could not be persisted', array( 'operation_uuid' => $operation_uuid ) );
			}
		}
	}

	/** Execute one administrator-requested lookup without reopening auto retries. */
	public function retryHostedPurchaseManually( string $operation_uuid ) {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'ys_helcim_hosted_recovery_forbidden',
				__( 'You are not allowed to retry hosted payment recovery.', 'ys-helcim-via-fluentcart' ),
				array( 'status' => 403 )
			);
		}
		$operation_uuid = strtolower( trim( $operation_uuid ) );
		if ( ! self::isUuid( $operation_uuid ) ) {
			return new \WP_Error( 'ys_helcim_hosted_recovery_invalid', __( 'The hosted payment operation identifier is invalid.', 'ys-helcim-via-fluentcart' ) );
		}
		$runtime = $this->hostedPurchaseRecoveryRuntime();
		if ( is_wp_error( $runtime ) ) {
			return $runtime;
		}

		$now                  = time();
		$due_before           = gmdate( 'Y-m-d H:i:s', $now );
		$local_claimed_before = gmdate( 'Y-m-d H:i:s', $now - self::HOSTED_LOCAL_CLAIM_STALE_SECONDS );
		$lease_until          = gmdate( 'Y-m-d H:i:s', $now + YSHelcimPayRecoveryService::RECOVERY_LEASE_SECONDS );
		$claimed = $runtime['operations']->claimPausedHostedRecovery(
			$operation_uuid,
			$due_before,
			$local_claimed_before,
			$lease_until,
			YSHelcimPayRecoveryService::MAX_AUTOMATIC_RECOVERY_ATTEMPTS
		);
		if ( is_wp_error( $claimed ) ) {
			return $claimed;
		}
		if ( true !== $claimed ) {
			return new \WP_Error(
				'ys_helcim_hosted_recovery_not_paused',
				__( 'This hosted payment is not paused for a manual recovery attempt, or another worker already holds its lease.', 'ys-helcim-via-fluentcart' ),
				array( 'status' => 409 )
			);
		}

		$result = $runtime['service']->recover( $operation_uuid );
		$current = $runtime['operations']->findByUuidStrict( $operation_uuid );
		if ( is_wp_error( $current ) || ! is_array( $current ) ) {
			return is_wp_error( $current ) ? $current : new \WP_Error( 'ys_helcim_journal_unavailable', __( 'The hosted payment recovery journal is unavailable.', 'ys-helcim-via-fluentcart' ) );
		}
		if (
			'' !== (string) ( $current['active_scope_key'] ?? '' ) &&
			(int) ( $current['recovery_attempt_count'] ?? 0 ) >= YSHelcimPayRecoveryService::MAX_AUTOMATIC_RECOVERY_ATTEMPTS
		) {
			$deferred = $runtime['operations']->deferHostedRecovery(
				$operation_uuid,
				(int) $current['recovery_attempt_count'],
				$lease_until,
				null,
				'ys_helcim_hosted_recovery_attention_required',
				__( 'The manual lookup did not return exact payment proof. The payment remains locked for administrator review.', 'ys-helcim-via-fluentcart' )
			);
			if ( is_wp_error( $deferred ) || true !== $deferred ) {
				return new \WP_Error( 'ys_helcim_journal_unavailable', __( 'The hosted payment recovery journal is unavailable.', 'ys-helcim-via-fluentcart' ) );
			}
		}

		return $result;
	}

	/** POST adapter for the one-shot manual recovery action. */
	public function handleHostedPurchaseManualRetry(): void {
		$operation_uuid = isset( $_POST['operation_uuid'] )
			? strtolower( trim( sanitize_text_field( (string) wp_unslash( $_POST['operation_uuid'] ) ) ) )
			: '';
		$nonce = isset( $_POST['_ys_helcim_nonce'] )
			? sanitize_text_field( (string) wp_unslash( $_POST['_ys_helcim_nonce'] ) )
			: '';
		if (
			'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ||
			! self::isUuid( $operation_uuid ) ||
			! function_exists( 'current_user_can' ) ||
			! current_user_can( 'manage_options' ) ||
			! wp_verify_nonce( $nonce, 'ys_helcim_retry_hosted_' . $operation_uuid )
		) {
			wp_die(
				esc_html__( 'The hosted payment recovery request is not authorized.', 'ys-helcim-via-fluentcart' ),
				'',
				array( 'response' => 403 )
			);
		}

		$result = $this->retryHostedPurchaseManually( $operation_uuid );
		$referer = function_exists( 'wp_get_referer' ) ? wp_get_referer() : false;
		$redirect = is_string( $referer ) && '' !== $referer ? $referer : admin_url();
		wp_safe_redirect(
			add_query_arg(
				array( 'ys_helcim_recovery' => is_wp_error( $result ) ? 'failed' : 'checked' ),
				$redirect
			)
		);
		exit;
	}

	/** Show durable unresolved hosted payments without exposing credentials. */
	public function renderHostedPurchaseAttentionNotice(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$manual_result = isset( $_GET['ys_helcim_recovery'] )
			? sanitize_key( (string) wp_unslash( $_GET['ys_helcim_recovery'] ) )
			: '';
		if ( 'checked' === $manual_result ) {
			echo '<div class="notice notice-info is-dismissible"><p>'
				. esc_html__( 'Manual Helcim check completed. If the operation remains listed below, exact payment proof is still missing and the payment remains locked.', 'ys-helcim-via-fluentcart' )
				. '</p></div>';
		} elseif ( 'failed' === $manual_result ) {
			echo '<div class="notice notice-error is-dismissible"><p>'
				. esc_html__( 'Manual Helcim check could not complete. The payment remains locked; review the operation before taking any payment action.', 'ys-helcim-via-fluentcart' )
				. '</p></div>';
		}
		$runtime = $this->hostedPurchaseRecoveryRuntime();
		if ( is_wp_error( $runtime ) ) {
			return;
		}
		$rows = $runtime['operations']->findHostedPurchasesNeedingAttention(
			10,
			YSHelcimPayRecoveryService::MAX_AUTOMATIC_RECOVERY_ATTEMPTS
		);
		if ( is_wp_error( $rows ) || array() === $rows ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>'
			. esc_html__( 'YS Helcim: hosted payments need review', 'ys-helcim-via-fluentcart' )
			. '</strong></p><p>'
			. esc_html__( 'These payments remain locked because exact Helcim proof or local completion is still missing. Check Helcim using the operation ID before taking any payment action.', 'ys-helcim-via-fluentcart' )
			. '</p><ul>';
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$operation_uuid = (string) ( $row['operation_uuid'] ?? '' );
			if ( ! self::isUuid( $operation_uuid ) ) {
				continue;
			}
			$details = sprintf(
				/* translators: 1: FluentCart order ID, 2: transaction ID, 3: remote state, 4: local state, 5: UTC update time. */
				__( 'Order %1$d / transaction %2$d / %3$s:%4$s / updated %5$s', 'ys-helcim-via-fluentcart' ),
				(int) ( $row['order_id'] ?? 0 ),
				(int) ( $row['transaction_id'] ?? 0 ),
				(string) ( $row['remote_status'] ?? '' ),
				(string) ( $row['local_status'] ?? '' ),
				(string) ( $row['updated_at'] ?? '' )
			);
			$attempt_count = max( 0, (int) ( $row['recovery_attempt_count'] ?? 0 ) );
			$is_paused     = $attempt_count >= YSHelcimPayRecoveryService::MAX_AUTOMATIC_RECOVERY_ATTEMPTS;
			if ( $is_paused ) {
				$recovery_status = sprintf(
					/* translators: 1: completed recovery attempts, 2: maximum automatic recovery attempts. */
					__( 'Automatic checks paused; attempt %1$d of %2$d.', 'ys-helcim-via-fluentcart' ),
					$attempt_count,
					YSHelcimPayRecoveryService::MAX_AUTOMATIC_RECOVERY_ATTEMPTS
				);
			} elseif ( '' !== (string) ( $row['next_recovery_at'] ?? '' ) ) {
				$recovery_status = sprintf(
					/* translators: 1: completed recovery attempts, 2: maximum automatic recovery attempts, 3: next UTC check time. */
					__( 'Automatic recovery attempt %1$d of %2$d; next check %3$s UTC.', 'ys-helcim-via-fluentcart' ),
					$attempt_count,
					YSHelcimPayRecoveryService::MAX_AUTOMATIC_RECOVERY_ATTEMPTS,
					(string) $row['next_recovery_at']
				);
			} else {
				$recovery_status = sprintf(
					/* translators: 1: completed recovery attempts, 2: maximum automatic recovery attempts. */
					__( 'Automatic recovery attempt %1$d of %2$d; next check is pending scheduling.', 'ys-helcim-via-fluentcart' ),
					$attempt_count,
					YSHelcimPayRecoveryService::MAX_AUTOMATIC_RECOVERY_ATTEMPTS
				);
			}
			echo '<li><code>' . esc_html( $operation_uuid ) . '</code> — ' . esc_html( $details )
				. '<br><span>' . esc_html( $recovery_status ) . '</span>';
			if ( $is_paused ) {
				echo ' <form method="post" action="' . esc_html( admin_url( 'admin-post.php' ) ) . '" style="display:inline">'
					. '<input type="hidden" name="action" value="ys_helcim_retry_hosted_recovery">'
					. '<input type="hidden" name="operation_uuid" value="' . esc_html( $operation_uuid ) . '">'
					. '<input type="hidden" name="_ys_helcim_nonce" value="' . esc_html( wp_create_nonce( 'ys_helcim_retry_hosted_' . $operation_uuid ) ) . '">'
					. '<button type="submit" class="button button-secondary">' . esc_html__( 'Check Helcim once', 'ys-helcim-via-fluentcart' ) . '</button></form>';
			}
			echo '</li>';
		}
		echo '</ul></div>';
	}

	/** Refuse a provider refund unless storage and durable recovery are ready. */
	public function verifyRefundSafety( callable $storage_preflight ) {
		try {
			$storage_ready = $storage_preflight();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$storage_ready = null;
		}
		if ( is_wp_error( $storage_ready ) ) {
			return $storage_ready;
		}
		if ( true !== $storage_ready ) {
			return new \WP_Error(
				'ys_helcim_storage_not_transactional',
				__( 'Refund storage is not transaction-safe. No provider request was sent.', 'ys-helcim-via-fluentcart' ),
				array( 'status' => 503 )
			);
		}
		if ( ! $this->ensureRefundOutboxSweep() ) {
			return new \WP_Error(
				'ys_helcim_refund_recovery_unavailable',
				__( 'Durable refund recovery is unavailable. No provider request was sent.', 'ys-helcim-via-fluentcart' ),
				array( 'status' => 503 )
			);
		}

		return true;
	}

	/** Recover abandoned claims, then drive both recovered and newly due work. */
	public function sweepRefundOutbox(): void {
		$runtime = $this->refundRuntime();
		if ( is_wp_error( $runtime ) ) {
			YSHelcimLogger::error( 'Refund outbox sweep runtime unavailable' );
			return;
		}

		$cutoff    = self::outboxStaleCutoff();
		$recovered = $runtime['outbox']->recoverStaleOperationUuids( $cutoff, self::OUTBOX_SWEEP_LIMIT );
		if ( is_wp_error( $recovered ) ) {
			YSHelcimLogger::error( 'Refund outbox stale-claim recovery failed', array( 'error_code' => $recovered->get_error_code() ) );
			$recovered = array();
		}

		$ready = $runtime['outbox']->actionableOperationUuids( self::OUTBOX_SWEEP_LIMIT );
		if ( is_wp_error( $ready ) ) {
			YSHelcimLogger::error( 'Refund outbox ready-operation scan failed', array( 'error_code' => $ready->get_error_code() ) );
			return;
		}

		$operation_uuids = array_values( array_unique( array_merge( $recovered, $ready ) ) );
		foreach ( array_slice( $operation_uuids, 0, self::OUTBOX_SWEEP_LIMIT ) as $operation_uuid ) {
			$result = $runtime['coordinator']->record( $operation_uuid );
			if ( is_wp_error( $result ) ) {
				YSHelcimLogger::error(
					'Refund outbox sweep operation failed',
					array( 'operation_uuid' => $operation_uuid, 'error_code' => $result->get_error_code() )
				);
			}
		}
	}

	/**
	 * Check whether FluentCart is active.
	 *
	 * @return bool
	 */
	public function isFluentCartActive(): bool {
		return defined( 'FLUENTCART_VERSION' ) || function_exists( 'fluent_cart_api' );
	}

	/**
	 * Register the payment methods with FluentCart.
	 *
	 * Both gateways share the durable operation journal and strict local binder.
	 *
	 * @return void
	 */
	public function registerGateways(): void {
		if ( ! function_exists( 'fluent_cart_api' ) ) {
			return;
		}

		try {
			// The helcim.js inline gateway is built by another developer — skip it if the file is not present yet.
			try {
				$hosted_gateway_class = '\\YangSheep\\Helcim\\FluentCart\\HelcimPay\\YSHelcimPayGateway';
				if ( class_exists( $hosted_gateway_class ) ) {
					fluent_cart_api()->registerCustomPaymentMethod( 'ys_helcim', new $hosted_gateway_class() );
				}
			} catch ( \Throwable $e ) {
				YSHelcimLogger::error(
					'Gateway registration failed',
					array( 'gateway' => 'ys_helcim', 'error' => $e->getMessage() )
				);
			}

			try {
				$js_gateway_class = '\\YangSheep\\Helcim\\FluentCart\\HelcimJs\\YSHelcimJsGateway';
				if ( class_exists( $js_gateway_class ) ) {
					fluent_cart_api()->registerCustomPaymentMethod( 'ys_helcim_js', new $js_gateway_class() );
				}
			} catch ( \Throwable $e ) {
				YSHelcimLogger::error(
					'Gateway registration failed',
					array( 'gateway' => 'ys_helcim_js', 'error' => $e->getMessage() )
				);
			}
		} catch ( \Throwable $e ) {
			// A registration failure must not take down the whole site — log it and return quietly.
			YSHelcimLogger::error(
				'Gateway registration failed',
				array( 'error' => $e->getMessage() )
			);
		}
	}

	/** @return array<string,object>|\WP_Error */
	private function refundRuntime() {
		if ( null !== $this->refund_runtime ) {
			return $this->refund_runtime;
		}

		try {
			global $wpdb;
			$operations = new YSHelcimOperationRepository( $wpdb );
			$outbox     = new YSHelcimOutboxRepository( $wpdb );
			$recorder   = new YSHelcimLocalRefundRecorder( $wpdb );
			$finalizer  = new YSHelcimRefundFinalizer( $operations, $outbox );
			$handlers   = new YSHelcimRefundEffectHandlers();
			$integrity  = new YSHelcimRefundEffectIntegrityVerifier( array( $recorder, 'record' ) );
			$worker     = new YSHelcimOutboxWorker(
				$outbox,
				$handlers->handlers(),
				null,
				array( $integrity, 'verify' )
			);
			$coordinator = new YSHelcimRefundLocalCoordinator(
				array( $recorder, 'record' ),
				array( $worker, 'runOnce' ),
				array( $finalizer, 'finalize' ),
				array( $this, 'scheduleRefundOutbox' )
			);
			$context_loader = new YSHelcimRefundContextLoader( $wpdb );
			$options_query  = new YSHelcimRefundOptionsQuery( $wpdb );
			$options_loader = new YSHelcimRefundOptionsLoader(
				array( $options_query, 'load' ),
				array( $context_loader, 'load' )
			);
			$preflight      = new YSHelcimStoragePreflight( $wpdb );
			$request_builder = new YSHelcimRefundRequest(
				array( $context_loader, 'load' ),
				array( $this, 'resolveRefundCredential' ),
				array( $this, 'resolveRequestIp' ),
				fn () => $this->verifyRefundSafety( array( $preflight, 'verify' ) )
			);
			$service = new YSHelcimRefundService(
				$operations,
				array( YSHelcimApiClient::class, 'request' ),
				static fn (): string => wp_generate_uuid4()
			);
			$operation_reader = static function ( string $operation_uuid ) use ( $operations ) {
				$row = $operations->findByUuidStrict( $operation_uuid );
				if (
					is_wp_error( $row ) ||
					! is_array( $row ) ||
					'processing' !== ( $row['remote_status'] ?? null ) ||
					! in_array( $row['operation_type'] ?? null, array( 'refund', 'reverse' ), true )
				) {
					return $row;
				}

				$promoted = $operations->promoteStaleRefundProcessing( $operation_uuid );
				if ( is_wp_error( $promoted ) ) {
					return $promoted;
				}
				return 1 === $promoted ? $operations->findByUuidStrict( $operation_uuid ) : $row;
			};
			$stale_scope_recoverer = static function ( string $scope_key ) use ( $operations ) {
				$expired = $operations->expireStaleCreatedScope( $scope_key );
				if ( is_wp_error( $expired ) ) {
					return $expired;
				}
				if ( 1 !== preg_match( '/\Arefund-order:([1-9][0-9]*)\z/', $scope_key, $matches ) ) {
					return new \WP_Error(
						'ys_helcim_invalid_operation',
						__( 'The refund recovery scope is invalid.', 'ys-helcim-via-fluentcart' )
					);
				}
				$promoted = $operations->promoteStaleRefundProcessingForOrder( (int) $matches[1] );
				return is_wp_error( $promoted ) ? $promoted : (int) $expired + $promoted;
			};
			$controller = new YSHelcimRefundRestController(
				$request_builder,
				array( $service, 'execute' ),
				array( $coordinator, 'record' ),
				$operation_reader,
				static fn (): bool => is_user_logged_in(),
				static fn ( string $nonce, string $action ): bool => false !== wp_verify_nonce( $nonce, $action ),
				static fn ( string $permission ): bool => class_exists( '\\FluentCart\\App\\Services\\Permission\\PermissionManager' )
					&& \FluentCart\App\Services\Permission\PermissionManager::hasPermission( $permission ),
				static fn (): int => get_current_user_id(),
				null,
				null,
				array( $operations, 'recordLocalFailure' ),
				array( $finalizer, 'inspect' ),
				array( $options_loader, 'load' ),
				$stale_scope_recoverer
			);

			$this->refund_runtime = array(
				'controller'  => $controller,
				'coordinator' => $coordinator,
				'finalizer'   => $finalizer,
				'operations'  => $operations,
				'outbox'      => $outbox,
			);
		} catch ( \Throwable $exception ) {
			YSHelcimLogger::error( 'Refund runtime initialization failed', array( 'error' => $exception->getMessage() ) );
			$this->refund_runtime = new \WP_Error(
				'ys_helcim_refund_runtime_unavailable',
				__( 'The Helcim refund runtime is unavailable.', 'ys-helcim-via-fluentcart' )
			);
		}

		return $this->refund_runtime;
	}

	/** @return array<string,object>|\WP_Error */
	private function refundResolutionRuntime() {
		if ( null !== $this->refund_resolution_runtime ) {
			return $this->refund_resolution_runtime;
		}

		$refund_runtime = $this->refundRuntime();
		if ( is_wp_error( $refund_runtime ) ) {
			$this->refund_resolution_runtime = $refund_runtime;
			return $this->refund_resolution_runtime;
		}

		try {
			global $wpdb;
			$store   = new YSHelcimRefundResolutionRepository( $wpdb );
			$service = new YSHelcimRefundResolutionService(
				$store,
				array( $this, 'resolveRefundResolutionCredential' ),
				array( $this, 'readRefundResolutionProviderTransaction' ),
				array( $refund_runtime['coordinator'], 'record' )
			);
			$controller = new YSHelcimRefundResolutionRestController(
				array( $service, 'inspect' ),
				array( $service, 'commit' ),
				static fn (): bool => is_user_logged_in(),
				static fn ( string $nonce, string $action ): bool => false !== wp_verify_nonce( $nonce, $action ),
				static fn ( string $capability ): bool => current_user_can( $capability ),
				static fn ( string $permission ): bool => class_exists( '\\FluentCart\\App\\Services\\Permission\\PermissionManager' )
					&& \FluentCart\App\Services\Permission\PermissionManager::hasPermission( $permission ),
				static fn (): int => get_current_user_id()
			);

			$this->refund_resolution_runtime = array(
				'controller' => $controller,
				'service'    => $service,
				'store'      => $store,
			);
		} catch ( \Throwable $exception ) {
			YSHelcimLogger::error( 'Refund resolution runtime initialization failed', array( 'error' => $exception->getMessage() ) );
			$this->refund_resolution_runtime = new \WP_Error(
				'ys_helcim_refund_resolution_runtime_unavailable',
				__( 'The Helcim refund resolution runtime is unavailable.', 'ys-helcim-via-fluentcart' )
			);
		}

		return $this->refund_resolution_runtime;
	}

	/** @return array<string,object>|\WP_Error */
	private function webhookRuntime() {
		if ( null !== $this->webhook_runtime ) {
			return $this->webhook_runtime;
		}

		try {
			global $wpdb;
			$operations       = new YSHelcimOperationRepository( $wpdb );
			$inline_settings  = new YSHelcimJsSettings();
			$hosted_settings  = new YSHelcimPaySettings();
			$inline_runtime   = new YSHelcimJsPurchaseRuntime( $inline_settings, $operations );
			$hosted_runtime   = new YSHelcimJsPurchaseRuntime(
				settings: $hosted_settings,
				operations: $operations,
				method_slug: 'ys_helcim',
				terminal_meta_keys: array(
					'ys_helcim_checkout_token',
					'ys_helcim_secret_token_enc',
					'ys_helcim_card_token',
					'ys_helcim_operation_uuid',
					'ys_helcim_initialized_at',
				)
			);
			$binding_resolver = new YSHelcimWebhookOperationBindingResolver(
				array( $operations, 'findByUuidStrict' )
			);
			$reconciler = new YSHelcimWebhookPurchaseReconciler(
				array( $operations, 'findByUuidStrict' ),
				static fn ( int $transaction_id ) => OrderTransaction::query()
					->where( 'id', $transaction_id )
					->first(),
				static function ( string $gateway, string $mode ) use ( $hosted_runtime, $inline_runtime ) {
					if ( ! in_array( $mode, array( 'test', 'live' ), true ) ) {
						return null;
					}

					return match ( $gateway ) {
						'ys_helcim'    => $hosted_runtime,
						'ys_helcim_js' => $inline_runtime,
						default        => null,
					};
				}
			);
			$receipts = new YSHelcimWebhookReceiptRepository( $wpdb );
			$handler  = new YSHelcimWebhookHandler(
				array( $this, 'resolveWebhookCredentials' ),
				array( YSHelcimWebhookVerifier::class, 'verify' ),
				static fn ( string $transaction_id, string $api_token ) => YSHelcimApiClient::request(
					'card-transactions/' . rawurlencode( $transaction_id ),
					array(),
					$api_token,
					null,
					'GET'
				),
				array( $reconciler, 'reconcile' ),
				array( $binding_resolver, 'resolve' ),
				array( $receipts, 'hasCompleted' ),
				static function ( string $receipt_key ) use ( $receipts ) {
					$completed_at = time();
					return $receipts->complete(
						$receipt_key,
						gmdate( 'Y-m-d H:i:s', $completed_at ),
						gmdate( 'Y-m-d H:i:s', $completed_at + self::WEBHOOK_RECEIPT_RETENTION_SECONDS )
					);
				}
			);
			$this->webhook_runtime = array(
				'controller'       => new YSHelcimWebhookRestController( $handler ),
				'handler'          => $handler,
				'binding_resolver' => $binding_resolver,
				'reconciler'       => $reconciler,
				'receipts'         => $receipts,
			);
		} catch ( \Throwable $exception ) {
			YSHelcimLogger::error( 'Webhook runtime initialization failed', array( 'error' => $exception->getMessage() ) );
			$this->webhook_runtime = new \WP_Error(
				'ys_helcim_webhook_runtime_unavailable',
				__( 'The payment recovery webhook runtime is unavailable.', 'ys-helcim-via-fluentcart' )
			);
		}

		return $this->webhook_runtime;
	}

	/** @return array<string,object>|\WP_Error */
	private function hostedPurchaseRecoveryRuntime() {
		if ( null !== $this->hosted_recovery_runtime ) {
			return $this->hosted_recovery_runtime;
		}

		try {
			global $wpdb;
			$operations = new YSHelcimOperationRepository( $wpdb );
			$settings   = new YSHelcimPaySettings();
			$runtime    = new YSHelcimJsPurchaseRuntime(
				settings: $settings,
				operations: $operations,
				method_slug: 'ys_helcim',
				terminal_meta_keys: array(
					'ys_helcim_checkout_token',
					'ys_helcim_secret_token_enc',
					'ys_helcim_card_token',
					'ys_helcim_operation_uuid',
					'ys_helcim_initialized_at',
				)
			);
			$service = new YSHelcimPayRecoveryService(
				operations: $operations,
				runtime: $runtime,
				transaction_loader: static fn ( int $transaction_id ) => OrderTransaction::query()
					->where( 'id', $transaction_id )
					->first(),
				credential_resolver: static function ( string $mode ) use ( $settings ) {
					if ( ! in_array( $mode, array( 'test', 'live' ), true ) ) {
						return new \WP_Error(
							'ys_helcim_hosted_recovery_credentials_unavailable',
							__( 'The credential for the original hosted payment mode is unavailable.', 'ys-helcim-via-fluentcart' )
						);
					}

					$api_token = trim( $settings->getApiTokenForMode( $mode ) );
					return '' !== $api_token
						? $api_token
						: new \WP_Error(
							'ys_helcim_hosted_recovery_credentials_unavailable',
							__( 'The credential for the original hosted payment mode is unavailable.', 'ys-helcim-via-fluentcart' )
						);
				},
				provider_lookup: static fn ( string $invoice_number, string $api_token ) => YSHelcimApiClient::request(
					'card-transactions',
					array(
						'invoiceNumber' => $invoice_number,
						'limit'         => 1000,
						'page'          => 1,
					),
					$api_token,
					null,
					'GET'
				)
			);

			$this->hosted_recovery_runtime = array(
				'operations' => $operations,
				'service'    => $service,
			);
		} catch ( \Throwable $exception ) {
			YSHelcimLogger::error( 'Hosted purchase recovery runtime initialization failed', array( 'error' => $exception->getMessage() ) );
			$this->hosted_recovery_runtime = new \WP_Error(
				'ys_helcim_hosted_recovery_runtime_unavailable',
				__( 'The hosted payment recovery runtime is unavailable.', 'ys-helcim-via-fluentcart' )
			);
		}

		return $this->hosted_recovery_runtime;
	}

	/** @return YSHelcimRefundAdminPage|\WP_Error */
	private function refundAdminPage() {
		if ( null !== $this->refund_admin ) {
			return $this->refund_admin;
		}

		try {
			$this->refund_admin = new YSHelcimRefundAdminPage(
				static fn ( string $permission ): bool => class_exists( '\\FluentCart\\App\\Services\\Permission\\PermissionManager' )
					&& \FluentCart\App\Services\Permission\PermissionManager::hasPermission( $permission ),
				static function ( array $config ): mixed {
					$hook = add_submenu_page(
						(string) $config['parent_slug'],
						(string) $config['page_title'],
						(string) $config['menu_title'],
						(string) $config['capability'],
						(string) $config['menu_slug'],
						$config['callback']
					);
					if ( ! is_string( $hook ) || '' === $hook ) {
						return $hook;
					}

					global $submenu;
					$parent_slug = is_string( $config['menu_parent_slug'] ?? null )
						? $config['menu_parent_slug']
						: '';
					$menu_key = is_string( $config['menu_key'] ?? null )
						? $config['menu_key']
						: '';
					$menu_url = is_string( $config['menu_url'] ?? null )
						? $config['menu_url']
						: '';
					if (
						'' !== $parent_slug &&
						'' !== $menu_key &&
						'' !== $menu_url &&
						isset( $submenu[ $parent_slug ] ) &&
						is_array( $submenu[ $parent_slug ] )
					) {
						$submenu[ $parent_slug ][ $menu_key ] = array(
							(string) $config['menu_title'],
							(string) $config['capability'],
							$menu_url,
						);
					}

					return $hook;
				},
				static function ( string $screen, array $config ): void {
					unset( $screen );
					wp_enqueue_style(
						(string) $config['style_handle'],
						(string) $config['style_url'],
						array(),
						(string) $config['version']
					);
					wp_enqueue_script(
						(string) $config['script_handle'],
						(string) $config['script_url'],
						array(),
						(string) $config['version'],
						true
					);
					wp_localize_script(
						(string) $config['script_handle'],
						(string) $config['config_object'],
						(array) $config['browser_config']
					);
				},
				array( $this, 'refundAdminConfig' )
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$this->refund_admin = new \WP_Error(
				'ys_helcim_refund_admin_unavailable',
				__( 'The Helcim refund administration page is unavailable.', 'ys-helcim-via-fluentcart' )
			);
		}

		return $this->refund_admin;
	}

	/** @return array<string,mixed> */
	public function refundAdminConfig( string $context ): array {
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] )
			? sanitize_key( wp_unslash( $_GET['page'] ) )
			: '';
		$order_id = null;
		if ( isset( $_GET['order_id'] ) && is_scalar( $_GET['order_id'] ) ) {
			$raw_order_id = trim( (string) wp_unslash( $_GET['order_id'] ) );
			if ( 1 === preg_match( '/\A[1-9][0-9]*\z/', $raw_order_id ) && (int) $raw_order_id > 0 ) {
				$order_id = (int) $raw_order_id;
			}
		}

		$version = defined( 'YS_HELCIM_FCT_VERSION' ) ? (string) YS_HELCIM_FCT_VERSION : 'dev';
		$base_url = defined( 'YS_HELCIM_FCT_URL' ) ? (string) YS_HELCIM_FCT_URL : '';
		$browser_config = array(
			'restRoot'       => trailingslashit( rest_url( YSHelcimRefundRestController::REST_NAMESPACE ) ),
			'restNonce'      => wp_create_nonce( 'wp_rest' ),
			'adminPageUrl'   => admin_url( 'admin.php?page=' . YSHelcimRefundAdminPage::PAGE_SLUG ),
			'initialOrderId' => $order_id,
			'labels'         => array(
				'nativeRefund' => __( 'Refund', 'fluent-cart' ),
				'helcimRefund' => __( 'Helcim remote-first refund', 'ys-helcim-via-fluentcart' ),
				'blocked'      => __( 'This Helcim refund requires reconciliation before another refund.', 'ys-helcim-via-fluentcart' ),
			),
			'pollIntervalMs' => 1500,
			'pollAttempts'   => 120,
			'canResolve'     => function_exists( 'current_user_can' ) && true === current_user_can( 'manage_options' ),
			'autoStart'      => true,
		);

		return array(
			'menu_capability' => 'read',
			'page'            => $page,
			'script_url'      => $base_url . 'assets/js/ys-helcim-refund-admin.js',
			'style_url'       => $base_url . 'assets/css/ys-helcim-refund-admin.css',
			'version'         => $version,
			'browser_config'  => $browser_config,
			'context'         => $context,
		);
	}

	/** @return array{current_mode:string,api_token:string}|\WP_Error */
	public function resolveRefundCredential( string $gateway, string $payment_mode ) {
		$payment_mode = strtolower( trim( $payment_mode ) );
		if ( ! in_array( $payment_mode, array( 'test', 'live' ), true ) ) {
			return self::credentialUnavailable();
		}

		try {
			$settings = match ( $gateway ) {
				'ys_helcim'    => new YSHelcimPaySettings(),
				'ys_helcim_js' => new YSHelcimJsSettings(),
				default        => null,
			};
			$current_mode = null === $settings ? '' : strtolower( trim( (string) $settings->getMode() ) );
			$api_token    = null === $settings ? '' : trim( $settings->getApiTokenForMode( $payment_mode ) );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return self::credentialUnavailable();
		}

		return '' === $api_token || ! in_array( $current_mode, array( 'test', 'live' ), true )
			? self::credentialUnavailable()
			: array( 'current_mode' => $current_mode, 'api_token' => $api_token );
	}

	/** @return string|\WP_Error API credential bound to the stored payment mode. */
	public function resolveRefundResolutionCredential( string $gateway, string $payment_mode ) {
		$credential = $this->resolveRefundCredential( $gateway, $payment_mode );
		if ( is_wp_error( $credential ) ) {
			return $credential;
		}

		$api_token = is_array( $credential ) && is_string( $credential['api_token'] ?? null )
			? trim( $credential['api_token'] )
			: '';
		return '' === $api_token ? self::credentialUnavailable() : $api_token;
	}

	/** Read one exact Helcim transaction for positive refund resolution. */
	public function readRefundResolutionProviderTransaction(
		string $transaction_id,
		string $api_token,
		string $gateway,
		string $payment_mode
	) {
		unset( $gateway, $payment_mode );
		$response = YSHelcimApiClient::request(
			'card-transactions/' . rawurlencode( $transaction_id ),
			array(),
			$api_token,
			null,
			'GET'
		);
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return $response;
		}
		if ( ! array_key_exists( 'data', $response ) ) {
			return $response;
		}

		$data = $response['data'];
		return is_array( $data ) && ! array_is_list( $data )
			? $data
			: new \WP_Error(
				'ys_helcim_resolution_provider_unavailable',
				__( 'The Helcim transaction response is unavailable.', 'ys-helcim-via-fluentcart' )
			);
	}

	/**
	 * Enumerate only complete, mode-isolated credential pairs for both gateways.
	 * Historical and currently disabled mode rows remain reconcilable.
	 *
	 * @return array<int,array{gateway:string,mode:string,verifier_token:string,api_token:string}>
	 */
	public function resolveWebhookCredentials(): array {
		$credentials = array();
		foreach (
			array(
				'ys_helcim'    => YSHelcimPaySettings::class,
				'ys_helcim_js' => YSHelcimJsSettings::class,
			) as $gateway => $settings_class
		) {
			try {
				$settings = new $settings_class();
			} catch ( \Throwable $exception ) {
				unset( $exception );
				continue;
			}

			foreach ( array( 'test', 'live' ) as $mode ) {
				try {
					$api_token = trim( $settings->getApiTokenForMode( $mode ) );
					$verifier  = trim( $settings->getWebhookVerifierTokenForMode( $mode ) );
				} catch ( \Throwable $exception ) {
					unset( $exception );
					continue;
				}
				if ( '' === $api_token || '' === $verifier ) {
					continue;
				}
				$credentials[] = array(
					'gateway'       => (string) $gateway,
					'mode'          => $mode,
					'verifier_token' => $verifier,
					'api_token'      => $api_token,
				);
			}
		}

		return $credentials;
	}

	/** Resolve only the direct peer address; untrusted forwarding headers are ignored. */
	public function resolveRequestIp( mixed $request = null ): string {
		unset( $request );
		$address = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] )
			? trim( $_SERVER['REMOTE_ADDR'] )
			: '';
		return false !== filter_var( $address, FILTER_VALIDATE_IP ) ? $address : '';
	}

	/** Persist one bounded retry event; duplicate schedules collapse by UUID. */
	public function scheduleRefundOutbox( string $operation_uuid ): bool {
		if ( ! self::isUuid( $operation_uuid ) ) {
			return false;
		}
		$args = array( $operation_uuid );
		if ( false !== wp_next_scheduled( self::OUTBOX_CRON_HOOK, $args ) ) {
			return true;
		}
		$scheduled = wp_schedule_single_event( time() + 60, self::OUTBOX_CRON_HOOK, $args, true );
		return ! is_wp_error( $scheduled ) && false !== $scheduled;
	}

	private static function outboxStaleCutoff(): string {
		return gmdate( 'Y-m-d H:i:s', time() - self::OUTBOX_STALE_SECONDS );
	}

	private static function credentialUnavailable(): \WP_Error {
		return new \WP_Error(
			'ys_helcim_refund_credentials_unavailable',
			__( 'The credential for the original Helcim payment mode is unavailable.', 'ys-helcim-via-fluentcart' ),
			array( 'status' => 503 )
		);
	}

	private static function isUuid( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match(
			'/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
			strtolower( $value )
		);
	}

	/**
	 * Admin notice: FluentCart is not active.
	 *
	 * @return void
	 */
	public function renderMissingFluentCartNotice(): void {
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'YS Helcim via FluentCart requires the FluentCart plugin (1.5.2 or later) to be installed and activated.', 'ys-helcim-via-fluentcart' )
			. '</p></div>';
	}

	/** Operation journal failure notice; gateways remain unregistered. */
	public function renderOperationSchemaNotice(): void {
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'YS Helcim via FluentCart could not initialize its payment safety journal. Payment methods remain disabled until the database issue is resolved.', 'ys-helcim-via-fluentcart' )
			. '</p></div>';
	}

	/**
	 * Prevent cloning (singleton).
	 */
	private function __clone() {
	}

	/**
	 * Prevent unserialization (singleton).
	 *
	 * @throws \RuntimeException Always.
	 */
	public function __wakeup() {
		throw new \RuntimeException( 'Cannot unserialize singleton.' );
	}
}
