<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Operations;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsSettings;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPaySettings;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationSchema;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundResolutionSchema;
use YangSheep\Helcim\FluentCart\Tests\Doubles\FakeWpdb;
use YangSheep\Helcim\FluentCart\YSHelcimFctBootstrap;

final class BootstrapSchemaGateTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMainPluginRegistersThePreciseDeactivationCleanupCallback(): void
    {
        \YSHelcimWpDouble::reset();
        $pluginFile = dirname(__DIR__, 4) . '/ys-helcim-via-fluentcart.php';

        require $pluginFile;

        self::assertCount(1, \YSHelcimWpDouble::$deactivationHooks);
        self::assertSame(realpath($pluginFile), \YSHelcimWpDouble::$deactivationHooks[0]['file']);
        self::assertSame(
            [YSHelcimFctBootstrap::class, 'deactivate'],
            \YSHelcimWpDouble::$deactivationHooks[0]['callback']
        );
    }

    public function testPluginsLoadedDefersTranslatedRuntimeBootUntilInit(): void
    {
        if (!defined('FLUENTCART_VERSION')) {
            define('FLUENTCART_VERSION', '1.5.2');
        }
        if (!defined('YS_HELCIM_FCT_FILE')) {
            define('YS_HELCIM_FCT_FILE', dirname(__DIR__, 4) . '/ys-helcim-via-fluentcart.php');
        }

        global $wpdb;
        $wpdb = new FakeWpdb();
        \YSHelcimWpDouble::reset();

        $bootstrap = YSHelcimFctBootstrap::init();
        $bootstrap->onPluginsLoaded();

        self::assertSame([], \YSHelcimWpDouble::$loadedTextdomains);
        self::assertSame([], \YSHelcimWpDouble::$dbDeltaSql);
        self::assertSame([], \YSHelcimWpDouble::$scheduledEvents);

        $initHooks = array_values(array_filter(
            \YSHelcimWpDouble::$actions,
            static fn (array $action): bool => $action['hook'] === 'init'
        ));
        self::assertCount(1, $initHooks);
        self::assertSame([$bootstrap, 'onInit'], $initHooks[0]['callback']);
        self::assertSame(0, $initHooks[0]['priority']);

        $bootstrap->onInit();

        self::assertCount(1, \YSHelcimWpDouble::$loadedTextdomains);
        self::assertNotSame([], \YSHelcimWpDouble::$dbDeltaSql);
        self::assertCount(1, array_filter(
            \YSHelcimWpDouble::$scheduledEvents,
            static fn (array $event): bool => $event['hook'] === 'ys_helcim_sweep_refund_outbox'
        ));
        self::assertCount(1, array_filter(
            \YSHelcimWpDouble::$scheduledEvents,
            static fn (array $event): bool => $event['hook'] === 'ys_helcim_reconcile_hosted_purchases'
        ));
    }

    public function testGatewayRegistrationStopsWhenJournalSchemaIsUnavailable(): void
    {
        if (!defined('FLUENTCART_VERSION')) {
            define('FLUENTCART_VERSION', '1.5.2');
        }
        if (!defined('YS_HELCIM_FCT_FILE')) {
            define('YS_HELCIM_FCT_FILE', dirname(__DIR__, 4) . '/ys-helcim-via-fluentcart.php');
        }

        global $wpdb;
        $wpdb = new FakeWpdb();
        $wpdb->failNextSchemaInstall = true;
        \YSHelcimWpDouble::reset();
        $wpdb->failNextSchemaInstall = true;

        $bootstrap = YSHelcimFctBootstrap::init();
        \YSHelcimWpDouble::$actions = [];
        $bootstrap->onInit();

        $hooks = array_column(\YSHelcimWpDouble::$actions, 'hook');
        self::assertContains('admin_notices', $hooks);
        self::assertNotContains('fluent_cart/register_payment_methods', $hooks);

        $vetoFilters = array_values(array_filter(
            \YSHelcimWpDouble::$filters,
            static fn (array $filter): bool => $filter['hook'] === 'fluent_cart/transaction/max_refundable_amount'
        ));
        self::assertCount(1, $vetoFilters);
        self::assertSame(2, $vetoFilters[0]['accepted_args']);
        self::assertSame(PHP_INT_MAX, $vetoFilters[0]['priority']);
    }

    public function testNormalRequestUsesOnlyStoredSchemaVersionsAndRunsNoMetadataQueries(): void
    {
        if (!defined('FLUENTCART_VERSION')) {
            define('FLUENTCART_VERSION', '1.5.2');
        }
        if (!defined('YS_HELCIM_FCT_FILE')) {
            define('YS_HELCIM_FCT_FILE', dirname(__DIR__, 4) . '/ys-helcim-via-fluentcart.php');
        }

        global $wpdb;
        $wpdb = new FakeWpdb();
        \YSHelcimWpDouble::reset();
        \YSHelcimWpDouble::$options[YSHelcimOperationSchema::OPTION_NAME] = YSHelcimOperationSchema::VERSION;
        \YSHelcimWpDouble::$options[YSHelcimRefundResolutionSchema::OPTION_NAME] = YSHelcimRefundResolutionSchema::VERSION;

        YSHelcimFctBootstrap::init()->onInit();

        self::assertSame(0, $wpdb->schemaMetadataQueryCount);
        self::assertSame([], \YSHelcimWpDouble::$dbDeltaSql);
    }

    public function testMigrationFailureStillRegistersNativeRefundVetoBeforeStoppingRuntime(): void
    {
        if (!defined('FLUENTCART_VERSION')) {
            define('FLUENTCART_VERSION', '1.5.2');
        }
        if (!defined('YS_HELCIM_FCT_FILE')) {
            define('YS_HELCIM_FCT_FILE', dirname(__DIR__, 4) . '/ys-helcim-via-fluentcart.php');
        }

        global $wpdb;
        $wpdb = new FakeWpdb();
        \YSHelcimWpDouble::reset();
        \YSHelcimWpDouble::$fluentCartOptions['fluent_cart_payment_settings_ys_helcim'] = 'corrupt-settings-row';

        YSHelcimFctBootstrap::init()->onInit();

        $vetoFilters = array_values(array_filter(
            \YSHelcimWpDouble::$filters,
            static fn (array $filter): bool => $filter['hook'] === 'fluent_cart/transaction/max_refundable_amount'
        ));
        self::assertCount(1, $vetoFilters);
        self::assertSame(PHP_INT_MAX, $vetoFilters[0]['priority']);
        self::assertSame(2, $vetoFilters[0]['accepted_args']);

        $hooks = array_column(\YSHelcimWpDouble::$actions, 'hook');
        self::assertNotContains('fluent_cart/register_payment_methods', $hooks);
        self::assertSame([], \YSHelcimWpDouble::$dbDeltaSql);
    }

    public function testReadySchemaRegistersRefundRestAndDurableOutboxRuntimeHooks(): void
    {
        global $wpdb;
        $wpdb = new FakeWpdb();
        \YSHelcimWpDouble::reset();

        $bootstrap = YSHelcimFctBootstrap::init();
        $bootstrap->onInit();

        $hooks = array_column(\YSHelcimWpDouble::$actions, 'hook');
        self::assertContains('rest_api_init', $hooks);
        $restCallbacks = array_values(array_map(
            static fn (array $action): string => is_array($action['callback']) ? (string) $action['callback'][1] : '',
            array_filter(
                \YSHelcimWpDouble::$actions,
                static fn (array $action): bool => $action['hook'] === 'rest_api_init'
            )
        ));
        self::assertContains('registerRefundRoutes', $restCallbacks);
        self::assertContains('registerRefundResolutionRoutes', $restCallbacks);
        self::assertContains('registerWebhookRoutes', $restCallbacks);
        self::assertContains('ys_helcim_process_refund_outbox', $hooks);
        self::assertContains('ys_helcim_sweep_refund_outbox', $hooks);
        self::assertContains('ys_helcim_reconcile_hosted_purchases', $hooks);
        self::assertContains('fluent_cart/register_payment_methods', $hooks);
        self::assertContains('admin_menu', $hooks);
        self::assertContains('admin_enqueue_scripts', $hooks);

        $refundAdminMenuHooks = array_values(array_filter(
            \YSHelcimWpDouble::$actions,
            static fn (array $action): bool => $action['hook'] === 'admin_menu'
                && is_array($action['callback'])
                && ($action['callback'][1] ?? '') === 'registerMenu'
        ));
        self::assertCount(1, $refundAdminMenuHooks);
        self::assertSame(99, $refundAdminMenuHooks[0]['priority']);

        $filters = array_column(\YSHelcimWpDouble::$filters, 'hook');
        self::assertContains('cron_schedules', $filters);
        $cartLocks = array_values(array_filter(
            \YSHelcimWpDouble::$filters,
            static fn (array $filter): bool => $filter['hook'] === 'fluent_cart/checkout/validate_before_process'
        ));
        self::assertCount(1, $cartLocks);
        self::assertSame(PHP_INT_MAX, $cartLocks[0]['priority']);
        self::assertSame(2, $cartLocks[0]['accepted_args']);
        self::assertInstanceOf(
            \YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimInlineCheckoutCartLock::class,
            $cartLocks[0]['callback'][0]
        );
        self::assertCount(1, array_filter(
            \YSHelcimWpDouble::$scheduledEvents,
            static fn (array $event): bool => $event['hook'] === 'ys_helcim_sweep_refund_outbox'
                && $event['recurrence'] === 'ys_helcim_minute'
        ));
        self::assertCount(1, array_filter(
            \YSHelcimWpDouble::$scheduledEvents,
            static fn (array $event): bool => $event['hook'] === 'ys_helcim_reconcile_hosted_purchases'
                && $event['recurrence'] === 'ys_helcim_minute'
        ));
        self::assertSame(
            YSHelcimRefundResolutionSchema::VERSION,
            \YSHelcimWpDouble::$options[YSHelcimRefundResolutionSchema::OPTION_NAME] ?? null
        );
        self::assertTrue($wpdb->resolutionChallengeSchemaInstalled);
        self::assertTrue($wpdb->resolutionAuditSchemaInstalled);
    }

    public function testBothDurableServerJournaledGatewaysAreRegistered(): void
    {
        \YSHelcimWpDouble::reset();
        $bootstrap = YSHelcimFctBootstrap::init();

        $bootstrap->registerGateways();

        self::assertSame(['ys_helcim', 'ys_helcim_js'], array_keys(\YSHelcimFluentCartApiDouble::$registered));
        self::assertInstanceOf(
            \YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPayGateway::class,
            \YSHelcimFluentCartApiDouble::$registered['ys_helcim']
        );
        self::assertInstanceOf(
            \YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsGateway::class,
            \YSHelcimFluentCartApiDouble::$registered['ys_helcim_js']
        );
    }

    public function testInlineGatewayStillRegistersWhenHostedGatewayRegistrationThrows(): void
    {
        \YSHelcimWpDouble::reset();
        \YSHelcimFluentCartApiDouble::$failRegistrationSlugs = ['ys_helcim'];

        YSHelcimFctBootstrap::init()->registerGateways();

        self::assertSame(
            ['ys_helcim', 'ys_helcim_js'],
            \YSHelcimFluentCartApiDouble::$registrationAttempts
        );
        self::assertArrayNotHasKey('ys_helcim', \YSHelcimFluentCartApiDouble::$registered);
        self::assertInstanceOf(
            \YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsGateway::class,
            \YSHelcimFluentCartApiDouble::$registered['ys_helcim_js'] ?? null
        );
    }

    public function testHostedGatewayRemainsRegisteredWhenInlineGatewayRegistrationThrows(): void
    {
        \YSHelcimWpDouble::reset();
        \YSHelcimFluentCartApiDouble::$failRegistrationSlugs = ['ys_helcim_js'];

        YSHelcimFctBootstrap::init()->registerGateways();

        self::assertSame(
            ['ys_helcim', 'ys_helcim_js'],
            \YSHelcimFluentCartApiDouble::$registrationAttempts
        );
        self::assertInstanceOf(
            \YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPayGateway::class,
            \YSHelcimFluentCartApiDouble::$registered['ys_helcim'] ?? null
        );
        self::assertArrayNotHasKey('ys_helcim_js', \YSHelcimFluentCartApiDouble::$registered);
    }

    public function testWebhookCredentialsRequireCompleteModeIsolatedPairsForBothGateways(): void
    {
        BaseGatewaySettings::$settingsByClass[YSHelcimPaySettings::class] = [
            'test_api_token' => 'enc:hosted-test-api',
            'test_webhook_verifier_token' => 'enc:hosted-test-verifier',
            'live_api_token' => '',
            'live_webhook_verifier_token' => '',
        ];
        BaseGatewaySettings::$settingsByClass[YSHelcimJsSettings::class] = [
            'test_api_token' => 'enc:test-api',
            'test_webhook_verifier_token' => 'enc:test-verifier',
            'live_api_token' => 'enc:live-api-without-verifier',
            'live_webhook_verifier_token' => '',
        ];

        $credentials = YSHelcimFctBootstrap::init()->resolveWebhookCredentials();

        self::assertSame([
            [
                'gateway' => 'ys_helcim',
                'mode' => 'test',
                'verifier_token' => 'hosted-test-verifier',
                'api_token' => 'hosted-test-api',
            ],
            [
                'gateway' => 'ys_helcim_js',
                'mode' => 'test',
                'verifier_token' => 'test-verifier',
                'api_token' => 'test-api',
            ],
        ], $credentials);
    }

    public function testWebhookRuntimeUsesTheCleanControllerAndStrictJournalReader(): void
    {
        global $wpdb;
        $wpdb = new FakeWpdb();
        $bootstrap = YSHelcimFctBootstrap::init();

        $method = new \ReflectionMethod($bootstrap, 'webhookRuntime');
        $runtime = $method->invoke($bootstrap);

        self::assertIsArray($runtime);
        self::assertInstanceOf(
            \YangSheep\Helcim\FluentCart\Webhook\YSHelcimWebhookRestController::class,
            $runtime['controller']
        );
        $resolverReader = new \ReflectionProperty($runtime['binding_resolver'], 'operation_reader');
        $reader = $resolverReader->getValue($runtime['binding_resolver']);
        self::assertIsArray($reader);
        self::assertSame('findByUuidStrict', $reader[1]);

        $runtimeResolver = new \ReflectionProperty($runtime['reconciler'], 'runtime_resolver');
        $resolver = $runtimeResolver->getValue($runtime['reconciler']);
        $hostedRuntime = $resolver('ys_helcim', 'test');
        $inlineRuntime = $resolver('ys_helcim_js', 'test');
        self::assertInstanceOf(\YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsPurchaseRuntime::class, $hostedRuntime);
        self::assertInstanceOf(\YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsPurchaseRuntime::class, $inlineRuntime);
        self::assertNotSame($hostedRuntime, $inlineRuntime);
    }

    public function testRefundRuntimeWiresTheServerOwnedOptionsLoaderIntoTheRestController(): void
    {
        global $wpdb;
        $wpdb = new FakeWpdb();
        \YSHelcimWpDouble::reset();
        $bootstrap = YSHelcimFctBootstrap::init();

        $method = new \ReflectionMethod($bootstrap, 'refundRuntime');
        $runtime = $method->invoke($bootstrap);

        self::assertIsArray($runtime);
        $property = new \ReflectionProperty($runtime['controller'], 'options_loader');
        self::assertIsCallable($property->getValue($runtime['controller']));
        $reader = new \ReflectionProperty($runtime['controller'], 'operation_reader');
        self::assertIsCallable($reader->getValue($runtime['controller']));
    }

    public function testRefundResolutionRuntimeWiresThePositiveOnlyControllerAndLocalCoordinator(): void
    {
        global $wpdb;
        $wpdb = new FakeWpdb();
        \YSHelcimWpDouble::reset();
        $bootstrap = YSHelcimFctBootstrap::init();

        $method = new \ReflectionMethod($bootstrap, 'refundResolutionRuntime');
        $runtime = $method->invoke($bootstrap);

        self::assertIsArray($runtime);
        self::assertInstanceOf(
            \YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundResolutionRestController::class,
            $runtime['controller']
        );
        self::assertInstanceOf(
            \YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundResolutionService::class,
            $runtime['service']
        );
        self::assertInstanceOf(
            \YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundResolutionRepository::class,
            $runtime['store']
        );
    }

    public function testAdminAdapterUsesTheExactFluentCartNativeRefundButtonLabel(): void
    {
        $config = YSHelcimFctBootstrap::init()->refundAdminConfig('assets');

        self::assertSame('Refund', $config['browser_config']['labels']['nativeRefund']);
    }

    public function testRefundMenuKeepsFluentCartDashboardFirstAndRegistersAWorkingCanonicalPage(): void
    {
        global $wpdb, $submenu;
        $wpdb = new FakeWpdb();
        $dashboard = ['Dashboard', 'manage_options', 'admin.php?page=fluent-cart#/'];
        $submenu = ['fluent-cart' => ['dashboard' => $dashboard]];
        \YSHelcimWpDouble::reset();

        $bootstrap = YSHelcimFctBootstrap::init();
        $bootstrap->onInit();

        $menuHook = array_values(array_filter(
            \YSHelcimWpDouble::$actions,
            static fn (array $action): bool => $action['hook'] === 'admin_menu'
                && is_array($action['callback'])
                && ($action['callback'][1] ?? '') === 'registerMenu'
        ))[0];
        ($menuHook['callback'])();

        self::assertCount(1, \YSHelcimWpDouble::$submenuPages);
        self::assertSame('admin.php', \YSHelcimWpDouble::$submenuPages[0]['parent_slug']);
        self::assertSame('ys-helcim-refunds', \YSHelcimWpDouble::$submenuPages[0]['menu_slug']);
        self::assertSame($dashboard, $submenu['fluent-cart']['dashboard']);
        self::assertSame('dashboard', array_key_first($submenu['fluent-cart']));
        self::assertSame(
            ['Helcim Refunds', 'read', 'admin.php?page=ys-helcim-refunds'],
            $submenu['fluent-cart']['ys-helcim-refunds']
        );
    }

    public function testRefundMenuDoesNotPublishAVisibleLinkWhenCanonicalRegistrationFails(): void
    {
        global $wpdb, $submenu;
        $wpdb = new FakeWpdb();
        $submenu = ['fluent-cart' => ['dashboard' => ['Dashboard', 'manage_options', 'admin.php?page=fluent-cart#/']]];
        \YSHelcimWpDouble::reset();
        \YSHelcimWpDouble::$failSubmenuPageRegistration = true;

        $bootstrap = YSHelcimFctBootstrap::init();
        $bootstrap->onInit();
        $menuHook = array_values(array_filter(
            \YSHelcimWpDouble::$actions,
            static fn (array $action): bool => $action['hook'] === 'admin_menu'
                && is_array($action['callback'])
                && ($action['callback'][1] ?? '') === 'registerMenu'
        ))[0];
        ($menuHook['callback'])();

        self::assertCount(1, \YSHelcimWpDouble::$submenuPages);
        self::assertArrayNotHasKey('ys-helcim-refunds', $submenu['fluent-cart']);
    }

    public function testRefundMenuDoesNotCreateAnOrphanFluentCartParent(): void
    {
        global $wpdb, $submenu;
        $wpdb = new FakeWpdb();
        $submenu = [];
        \YSHelcimWpDouble::reset();

        $bootstrap = YSHelcimFctBootstrap::init();
        $bootstrap->onInit();
        $menuHook = array_values(array_filter(
            \YSHelcimWpDouble::$actions,
            static fn (array $action): bool => $action['hook'] === 'admin_menu'
                && is_array($action['callback'])
                && ($action['callback'][1] ?? '') === 'registerMenu'
        ))[0];
        ($menuHook['callback'])();

        self::assertCount(1, \YSHelcimWpDouble::$submenuPages);
        self::assertArrayNotHasKey('fluent-cart', $submenu);
    }

    public function testAdminResolutionCapabilityIsServerOwnedAndRequiresManageOptions(): void
    {
        \YSHelcimWpDouble::reset();
        $bootstrap = YSHelcimFctBootstrap::init();

        self::assertTrue($bootstrap->refundAdminConfig('assets')['browser_config']['canResolve']);

        \YSHelcimWpDouble::$currentUserCapabilities['manage_options'] = false;
        self::assertFalse($bootstrap->refundAdminConfig('assets')['browser_config']['canResolve']);
    }

    public function testRefundOutboxSchedulingIsDeduplicatedAndRejectsInvalidIdentifiers(): void
    {
        \YSHelcimWpDouble::reset();
        $bootstrap = YSHelcimFctBootstrap::init();
        $uuid = '00000000-0000-4000-8000-000000000001';

        self::assertFalse($bootstrap->scheduleRefundOutbox('../invalid'));
        self::assertTrue($bootstrap->scheduleRefundOutbox($uuid));
        self::assertTrue($bootstrap->scheduleRefundOutbox($uuid));
        self::assertCount(1, \YSHelcimWpDouble::$scheduledEvents);
        self::assertSame('ys_helcim_process_refund_outbox', \YSHelcimWpDouble::$scheduledEvents[0]['hook']);
        self::assertSame([$uuid], \YSHelcimWpDouble::$scheduledEvents[0]['args']);
    }

    public function testDeactivationRemovesOnlyOwnedRecurringSweepAndUuidSingleEvents(): void
    {
        \YSHelcimWpDouble::reset();
        $uuid = '00000000-0000-4000-8000-000000000001';
        \YSHelcimWpDouble::$scheduledEvents = [
            [
                'timestamp' => 101,
                'hook' => 'ys_helcim_sweep_refund_outbox',
                'args' => [],
                'recurrence' => 'ys_helcim_minute',
            ],
            [
                'timestamp' => 102,
                'hook' => 'ys_helcim_process_refund_outbox',
                'args' => [$uuid],
                'recurrence' => null,
            ],
            [
                'timestamp' => 103,
                'hook' => 'another_plugin_event',
                'args' => [$uuid],
                'recurrence' => null,
            ],
            [
                'timestamp' => 104,
                'hook' => 'ys_helcim_process_refund_outbox',
                'args' => ['not-a-uuid'],
                'recurrence' => null,
            ],
            [
                'timestamp' => 105,
                'hook' => 'ys_helcim_process_refund_outbox',
                'args' => [$uuid],
                'recurrence' => 'hourly',
            ],
            [
                'timestamp' => 106,
                'hook' => 'ys_helcim_sweep_refund_outbox',
                'args' => [],
                'recurrence' => null,
            ],
            [
                'timestamp' => 107,
                'hook' => 'ys_helcim_sweep_refund_outbox',
                'args' => ['foreign'],
                'recurrence' => 'ys_helcim_minute',
            ],
            [
                'timestamp' => 108,
                'hook' => 'ys_helcim_sweep_refund_outbox',
                'args' => [],
                'recurrence' => 'legacy_ys_helcim_interval',
            ],
            [
                'timestamp' => 109,
                'hook' => 'ys_helcim_reconcile_hosted_purchases',
                'args' => [],
                'recurrence' => 'ys_helcim_every_minute',
            ],
            [
                'timestamp' => 110,
                'hook' => 'ys_helcim_reconcile_hosted_purchases',
                'args' => ['foreign'],
                'recurrence' => 'ys_helcim_every_minute',
            ],
            [
                'timestamp' => 111,
                'hook' => 'ys_helcim_reconcile_hosted_purchases',
                'args' => [],
                'recurrence' => null,
            ],
        ];

        YSHelcimFctBootstrap::deactivate(true);

        self::assertSame(
            [103, 104, 105, 106, 107, 110, 111],
            array_column(\YSHelcimWpDouble::$scheduledEvents, 'timestamp')
        );
    }

    public function testRecurringSweepScheduleAndIntervalAreDeduplicated(): void
    {
        \YSHelcimWpDouble::reset();
        $bootstrap = YSHelcimFctBootstrap::init();

        $schedules = $bootstrap->registerCronIntervals([]);
        self::assertSame(60, $schedules['ys_helcim_minute']['interval']);
        self::assertNotSame('', $schedules['ys_helcim_minute']['display']);

        self::assertTrue($bootstrap->ensureRefundOutboxSweep());
        self::assertTrue($bootstrap->ensureRefundOutboxSweep());
        self::assertCount(1, \YSHelcimWpDouble::$scheduledEvents);
        self::assertSame('ys_helcim_sweep_refund_outbox', \YSHelcimWpDouble::$scheduledEvents[0]['hook']);
        self::assertSame('ys_helcim_minute', \YSHelcimWpDouble::$scheduledEvents[0]['recurrence']);
        self::assertSame([], \YSHelcimWpDouble::$scheduledEvents[0]['args']);

        self::assertTrue($bootstrap->ensureHostedPurchaseReconciliation());
        self::assertTrue($bootstrap->ensureHostedPurchaseReconciliation());
        self::assertCount(2, \YSHelcimWpDouble::$scheduledEvents);
        self::assertSame('ys_helcim_reconcile_hosted_purchases', \YSHelcimWpDouble::$scheduledEvents[1]['hook']);
        self::assertSame('ys_helcim_minute', \YSHelcimWpDouble::$scheduledEvents[1]['recurrence']);
        self::assertSame([], \YSHelcimWpDouble::$scheduledEvents[1]['args']);
    }

    public function testHostedRecoverySweepQueriesExpiredRowsAndDoesNotLetOneFailureStarveTheNext(): void
    {
        $events = [];
        $operations = new class($events) {
            public function __construct(private array &$events) {}
			public function findHostedPurchasesNeedingRecovery(
				string $cutoff,
				string $dueBefore,
				string $localClaimedBefore,
				int $maxAttempts,
				int $limit
			): array
            {
				$this->events[] = ['scan', $cutoff, $dueBefore, $localClaimedBefore, $maxAttempts, $limit];
                return [
                    ['operation_uuid' => '00000000-0000-4000-8000-000000000001'],
                    ['operation_uuid' => '00000000-0000-4000-8000-000000000002'],
                ];
            }
			public function claimHostedRecovery(
				string $uuid,
				string $dueBefore,
				string $localClaimedBefore,
				string $leaseUntil,
				int $maxAttempts
			): bool {
				$this->events[] = ['claim', $uuid, $dueBefore, $localClaimedBefore, $leaseUntil, $maxAttempts];
				return true;
			}
			public function findByUuidStrict(string $uuid): array
			{
				return str_ends_with($uuid, '1')
					? [
						'operation_uuid' => $uuid,
						'remote_status' => 'processing',
						'local_status' => 'pending',
						'active_scope_key' => 'purchase:test',
						'recovery_attempt_count' => 1,
					]
					: [
						'operation_uuid' => $uuid,
						'remote_status' => 'succeeded',
						'local_status' => 'applied',
						'active_scope_key' => null,
						'recovery_attempt_count' => 0,
					];
			}
			public function deferHostedRecovery(
				string $uuid,
				int $attempt,
				string $expectedLease,
				?string $nextDue,
				string $code,
				string $message
			): bool {
				$this->events[] = ['defer', $uuid, $attempt, $expectedLease, $nextDue, $code, $message];
				return true;
			}
        };
        $service = new class($events) {
            public function __construct(private array &$events) {}
            public function recover(string $uuid): array|\WP_Error
            {
                $this->events[] = ['recover', $uuid];
                return str_ends_with($uuid, '1')
                    ? new \WP_Error('first_failed', 'First failed.')
                    : ['status' => 'succeeded'];
            }
        };
        $bootstrap = YSHelcimFctBootstrap::init();
        $property = new \ReflectionProperty($bootstrap, 'hosted_recovery_runtime');
        $property->setValue($bootstrap, ['operations' => $operations, 'service' => $service]);

        $bootstrap->reconcileHostedPurchases();

        self::assertSame('scan', $events[0][0]);
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $events[0][1]);
		self::assertSame(7, $events[0][4]);
		self::assertSame(2, $events[0][5]);
		self::assertSame('claim', $events[1][0]);
		self::assertSame(['recover', '00000000-0000-4000-8000-000000000001'], $events[2]);
		self::assertSame('defer', $events[3][0]);
		self::assertSame('first_failed', $events[3][5]);
		self::assertNotNull($events[3][4]);
		self::assertSame('claim', $events[4][0]);
		self::assertSame(['recover', '00000000-0000-4000-8000-000000000002'], $events[5]);
    }

	public function testHostedAttentionNoticeShowsOnlyOperationalIdentifiersAndManualOneShotAction(): void
	{
		$operationUuid = '00000000-0000-4000-8000-000000000901';
		$operations = new class($operationUuid) {
			public function __construct(private string $uuid) {}
			public function findHostedPurchasesNeedingAttention(int $limit, int $maxAttempts): array
			{
				TestCase::assertSame(10, $limit);
				TestCase::assertSame(7, $maxAttempts);
				return [[
					'operation_uuid' => $this->uuid,
					'order_id' => 91,
					'transaction_id' => 92,
					'remote_status' => 'indeterminate',
					'local_status' => 'pending',
					'recovery_attempt_count' => 7,
					'next_recovery_at' => null,
					'updated_at' => '2026-07-21 00:00:00',
				], [
					'operation_uuid' => '00000000-0000-4000-8000-000000000903',
					'order_id' => 93,
					'transaction_id' => 94,
					'remote_status' => 'indeterminate',
					'local_status' => 'pending',
					'recovery_attempt_count' => 3,
					'next_recovery_at' => '2026-07-21 00:05:00',
					'updated_at' => '2026-07-21 00:01:00',
				]];
			}
		};
		$bootstrap = YSHelcimFctBootstrap::init();
		$property = new \ReflectionProperty($bootstrap, 'hosted_recovery_runtime');
		$property->setValue($bootstrap, ['operations' => $operations, 'service' => new \stdClass()]);

		$_GET['ys_helcim_recovery'] = 'checked';
		try {
			ob_start();
			$bootstrap->renderHostedPurchaseAttentionNotice();
			$html = (string) ob_get_clean();
		} finally {
			unset($_GET['ys_helcim_recovery']);
		}

		self::assertStringContainsString($operationUuid, $html);
		self::assertStringContainsString('Order 91 / transaction 92', $html);
		self::assertStringContainsString('Automatic checks paused', $html);
		self::assertStringContainsString('attempt 7 of 7', $html);
		self::assertStringContainsString('Automatic recovery attempt 3 of 7; next check 2026-07-21 00:05:00 UTC.', $html);
		self::assertStringContainsString('Manual Helcim check completed', $html);
		self::assertStringContainsString('ys_helcim_retry_hosted_recovery', $html);
		self::assertStringContainsString('Check Helcim once', $html);
		self::assertStringNotContainsString('api-token', $html);
		self::assertStringNotContainsString('secret', strtolower($html));
	}

	public function testManualHostedRecoveryUsesPausedLeaseAndReturnsToPausedWhenStillUnresolved(): void
	{
		$events = [];
		$operationUuid = '00000000-0000-4000-8000-000000000902';
		$operations = new class($events, $operationUuid) {
			public function __construct(private array &$events, private string $uuid) {}
			public function claimPausedHostedRecovery(string $uuid, string $due, string $stale, string $lease, int $max): bool
			{
				$this->events[] = ['claim', $uuid, $due, $stale, $lease, $max];
				return true;
			}
			public function findByUuidStrict(string $uuid): array
			{
				return [
					'operation_uuid' => $uuid,
					'active_scope_key' => 'purchase:paused',
					'recovery_attempt_count' => 7,
				];
			}
			public function deferHostedRecovery(string $uuid, int $attempt, string $lease, ?string $next, string $code, string $message): bool
			{
				$this->events[] = ['defer', $uuid, $attempt, $lease, $next, $code, $message];
				return true;
			}
		};
		$service = new class($events) {
			public function __construct(private array &$events) {}
			public function recover(string $uuid): array
			{
				$this->events[] = ['recover', $uuid];
				return ['status' => 'pending', 'reason' => 'empty_observation_recorded'];
			}
		};
		$bootstrap = YSHelcimFctBootstrap::init();
		$property = new \ReflectionProperty($bootstrap, 'hosted_recovery_runtime');
		$property->setValue($bootstrap, ['operations' => $operations, 'service' => $service]);

		$result = $bootstrap->retryHostedPurchaseManually($operationUuid);

		self::assertIsArray($result);
		self::assertSame('pending', $result['status']);
		self::assertSame('claim', $events[0][0]);
		self::assertSame(7, $events[0][5]);
		self::assertSame(['recover', $operationUuid], $events[1]);
		self::assertSame('defer', $events[2][0]);
		self::assertNull($events[2][4]);
		self::assertSame('ys_helcim_hosted_recovery_attention_required', $events[2][5]);
	}

    public function testSingleOrFarFutureEventCannotMakeRefundRecoveryPreflightPass(): void
    {
        \YSHelcimWpDouble::reset();
        $bootstrap = YSHelcimFctBootstrap::init();
        \YSHelcimWpDouble::$scheduledEvents[] = [
            'timestamp' => time() + 3600,
            'hook' => 'ys_helcim_sweep_refund_outbox',
            'args' => [],
            'recurrence' => null,
        ];

        self::assertTrue($bootstrap->ensureRefundOutboxSweep());
        self::assertCount(1, \YSHelcimWpDouble::$scheduledEvents);
        self::assertSame('ys_helcim_minute', \YSHelcimWpDouble::$scheduledEvents[0]['recurrence']);
        self::assertLessThanOrEqual(time() + 61, \YSHelcimWpDouble::$scheduledEvents[0]['timestamp']);

        \YSHelcimWpDouble::$scheduledEvents[0]['timestamp'] = time() + 3600;
        self::assertTrue($bootstrap->ensureRefundOutboxSweep());
        self::assertCount(1, \YSHelcimWpDouble::$scheduledEvents);
        self::assertSame('ys_helcim_minute', \YSHelcimWpDouble::$scheduledEvents[0]['recurrence']);
        self::assertLessThanOrEqual(time() + 61, \YSHelcimWpDouble::$scheduledEvents[0]['timestamp']);
    }

    public function testInvalidExistingSweepFailsClosedWhenItCannotBeReplaced(): void
    {
        \YSHelcimWpDouble::reset();
        $bootstrap = YSHelcimFctBootstrap::init();
        \YSHelcimWpDouble::$scheduledEvents[] = [
            'timestamp' => time() + 3600,
            'hook' => 'ys_helcim_sweep_refund_outbox',
            'args' => [],
            'recurrence' => 'daily',
        ];
        \YSHelcimWpDouble::$failUnschedule = true;

        self::assertFalse($bootstrap->ensureRefundOutboxSweep());
        $result = $bootstrap->verifyRefundSafety(static fn (): bool => true);
        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_refund_recovery_unavailable', $result->get_error_code());
    }

    public function testRefundSafetyPreflightFailsClosedWhenRecurringRecoveryCannotBeScheduled(): void
    {
        \YSHelcimWpDouble::reset();
        \YSHelcimWpDouble::$failRecurringSchedule = true;
        $bootstrap = YSHelcimFctBootstrap::init();

        $result = $bootstrap->verifyRefundSafety(static fn (): bool => true);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_refund_recovery_unavailable', $result->get_error_code());
        self::assertSame(['status' => 503], $result->get_error_data());
        self::assertSame([], \YSHelcimWpDouble::$scheduledEvents);
    }

    public function testRefundSafetyPreflightPreservesStorageFailureAndAcceptsAnExistingSweep(): void
    {
        \YSHelcimWpDouble::reset();
        $bootstrap = YSHelcimFctBootstrap::init();
        $storageError = new \WP_Error('unsafe_storage', 'Unsafe.');
        self::assertSame($storageError, $bootstrap->verifyRefundSafety(static fn (): \WP_Error => $storageError));

        self::assertTrue($bootstrap->ensureRefundOutboxSweep());
        \YSHelcimWpDouble::$failRecurringSchedule = true;
        self::assertTrue($bootstrap->verifyRefundSafety(static fn (): bool => true));
    }

    public function testSweepRecoversStaleClaimsBeforeDrivingReadyOperations(): void
    {
        $events = [];
        $outbox = new class($events) {
            public function __construct(private array &$events) {}
            public function recoverStaleOperationUuids(string $cutoff, int $limit): array
            {
                $this->events[] = ['recover', $cutoff, $limit];
                return ['00000000-0000-4000-8000-000000000002'];
            }
            public function actionableOperationUuids(int $limit): array
            {
                $this->events[] = ['ready', $limit];
                return [
                    '00000000-0000-4000-8000-000000000001',
                    '00000000-0000-4000-8000-000000000002',
                ];
            }
        };
        $coordinator = new class($events) {
            public function __construct(private array &$events) {}
            public function record(string $uuid): array
            {
                $this->events[] = ['record', $uuid];
                return ['local_status' => 'applied'];
            }
        };
        $bootstrap = YSHelcimFctBootstrap::init();
        $this->setRefundRuntime($bootstrap, ['outbox' => $outbox, 'coordinator' => $coordinator]);

        $bootstrap->sweepRefundOutbox();

        self::assertSame('recover', $events[0][0]);
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $events[0][1]);
        self::assertSame(['ready', 50], $events[1]);
        self::assertSame(['record', '00000000-0000-4000-8000-000000000002'], $events[2]);
        self::assertSame(['record', '00000000-0000-4000-8000-000000000001'], $events[3]);
    }

    public function testRecoveryReadFailureCannotStarveAnIndependentActionableOperation(): void
    {
        $events = [];
        $outbox = new class($events) {
            public function __construct(private array &$events) {}
            public function recoverStaleOperationUuids(string $cutoff, int $limit): \WP_Error
            {
                $this->events[] = ['recover', $cutoff, $limit];
                return new \WP_Error('ys_helcim_outbox_unavailable', 'Unavailable.');
            }
            public function actionableOperationUuids(int $limit): array
            {
                $this->events[] = ['ready', $limit];
                return ['00000000-0000-4000-8000-000000000003'];
            }
        };
        $coordinator = new class($events) {
            public function __construct(private array &$events) {}
            public function record(string $uuid): array
            {
                $this->events[] = ['record', $uuid];
                return ['local_status' => 'applied'];
            }
        };
        $bootstrap = YSHelcimFctBootstrap::init();
        $this->setRefundRuntime($bootstrap, ['outbox' => $outbox, 'coordinator' => $coordinator]);

        $bootstrap->sweepRefundOutbox();

        self::assertSame('recover', $events[0][0]);
        self::assertSame(['ready', 50], $events[1]);
        self::assertSame(['record', '00000000-0000-4000-8000-000000000003'], $events[2]);
    }

    public function testSingleOperationCronStillOffersTargetToCoordinatorWhenRecoveryReadFails(): void
    {
        $events = [];
        $outbox = new class($events) {
            public function __construct(private array &$events) {}
            public function recoverStaleOperationUuids(string $cutoff, int $limit): \WP_Error
            {
                $this->events[] = ['recover', $cutoff, $limit];
                return new \WP_Error('ys_helcim_outbox_unavailable', 'Unavailable.');
            }
        };
        $coordinator = new class($events) {
            public function __construct(private array &$events) {}
            public function record(string $uuid): array
            {
                $this->events[] = ['record', $uuid];
                return ['local_status' => 'applied'];
            }
        };
        $bootstrap = YSHelcimFctBootstrap::init();
        $this->setRefundRuntime($bootstrap, ['outbox' => $outbox, 'coordinator' => $coordinator]);

        $bootstrap->processRefundOutbox('00000000-0000-4000-8000-000000000001');

        self::assertSame('recover', $events[0][0]);
        self::assertSame(['record', '00000000-0000-4000-8000-000000000001'], $events[1]);
    }

    public function testSingleOperationCronRecoversStaleClaimBeforeReentry(): void
    {
        $events = [];
        $outbox = new class($events) {
            public function __construct(private array &$events) {}
            public function recoverStaleOperationUuids(string $cutoff, int $limit): array
            {
                $this->events[] = ['recover', $cutoff, $limit];
                return ['00000000-0000-4000-8000-000000000001'];
            }
        };
        $coordinator = new class($events) {
            public function __construct(private array &$events) {}
            public function record(string $uuid): array
            {
                $this->events[] = ['record', $uuid];
                return ['local_status' => 'applied'];
            }
        };
        $bootstrap = YSHelcimFctBootstrap::init();
        $this->setRefundRuntime($bootstrap, ['outbox' => $outbox, 'coordinator' => $coordinator]);

        $bootstrap->processRefundOutbox('00000000-0000-4000-8000-000000000001');

        self::assertSame('recover', $events[0][0]);
        self::assertSame(['record', '00000000-0000-4000-8000-000000000001'], $events[1]);
    }

    /** @param array<string,object> $runtime */
    private function setRefundRuntime(YSHelcimFctBootstrap $bootstrap, array $runtime): void
    {
        $property = new \ReflectionProperty($bootstrap, 'refund_runtime');
        $property->setValue($bootstrap, $runtime);
    }
}
