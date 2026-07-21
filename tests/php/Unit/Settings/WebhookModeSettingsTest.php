<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Settings;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationSchema;
use YangSheep\Helcim\FluentCart\Settings\YSHelcimWebhookVerifierModeMigration;
use YangSheep\Helcim\FluentCart\Tests\Doubles\FakeWpdb;
use YangSheep\Helcim\FluentCart\YSHelcimFctBootstrap;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsGateway;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsSettings;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPayGateway;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPaySettings;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class WebhookModeSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/Doubles/InlineWordPress.php';
        if (!defined('YS_HELCIM_FCT_URL')) {
            define('YS_HELCIM_FCT_URL', 'https://shop.test/wp-content/plugins/ys-helcim-via-fluentcart/');
        }
        \YSHelcimWpDouble::reset();
        BaseGatewaySettings::$settingsByClass = [];
        StoreSettings::$orderMode = 'test';
        Helper::$encryptionAvailable = true;
        Helper::$encryptBehavior = 'normal';
        Helper::$verificationBehavior = 'normal';
        Helper::$decryptBehavior = 'normal';
    }

    #[DataProvider('settingsClasses')]
    public function testModeSpecificVerifierAlwaysWins(string $settingsClass): void
    {
        BaseGatewaySettings::$settingsByClass[$settingsClass] = [
            'test_webhook_verifier_token' => 'enc:test-verifier',
            'live_webhook_verifier_token' => 'enc:live-verifier',
            'webhook_verifier_token' => 'enc:legacy-verifier',
        ];
        $settings = new $settingsClass();

        self::assertSame('test-verifier', $settings->getWebhookVerifierTokenForMode('test'));
        self::assertSame('live-verifier', $settings->getWebhookVerifierTokenForMode('live'));
        self::assertSame('', $settings->getWebhookVerifierTokenForMode('invalid'));
        self::assertSame('test-verifier', $settings->getWebhookVerifierToken());
    }

    #[DataProvider('settingsClasses')]
    public function testHistoricalGlobalVerifierIsNeverUsedAsRuntimeFallback(string $settingsClass): void
    {
        BaseGatewaySettings::$settingsByClass[$settingsClass] = [
            'test_webhook_verifier_token' => '',
            'live_webhook_verifier_token' => '',
            'webhook_verifier_token' => 'enc:legacy-verifier',
        ];
        StoreSettings::$orderMode = 'test';
        $testSettings = new $settingsClass();
        self::assertSame('', $testSettings->getWebhookVerifierTokenForMode('test'));
        self::assertSame('', $testSettings->getWebhookVerifierTokenForMode('live'));

        StoreSettings::$orderMode = 'live';
        $liveSettings = new $settingsClass();
        self::assertSame('', $liveSettings->getWebhookVerifierTokenForMode('test'));
        self::assertSame('', $liveSettings->getWebhookVerifierTokenForMode('live'));
    }

    public function testInitMigrationBindsBothLegacyGatewayVerifiersToTheStoreModeExactlyOnce(): void
    {
        if (!defined('FLUENTCART_VERSION')) {
            define('FLUENTCART_VERSION', '1.5.2');
        }
        if (!defined('YS_HELCIM_FCT_FILE')) {
            define('YS_HELCIM_FCT_FILE', dirname(__DIR__, 4) . '/ys-helcim-via-fluentcart.php');
        }

        $modalKey = 'fluent_cart_payment_settings_ys_helcim';
        $inlineKey = 'fluent_cart_payment_settings_ys_helcim_js';
        \YSHelcimWpDouble::$fluentCartOptions = [
            $modalKey => [
                'test_webhook_verifier_token' => '',
                'live_webhook_verifier_token' => 'enc:modal-live-existing',
                'webhook_verifier_token' => 'enc:modal-legacy',
            ],
            $inlineKey => [
                'test_webhook_verifier_token' => 'enc:inline-test-existing',
                'live_webhook_verifier_token' => 'enc:inline-live-existing',
                'webhook_verifier_token' => 'enc:inline-legacy',
            ],
        ];
        StoreSettings::$orderMode = 'test';

        global $wpdb;
        $wpdb = new FakeWpdb();
        $bootstrap = YSHelcimFctBootstrap::init();
        $bootstrap->onInit();

        self::assertSame('enc:modal-legacy', \YSHelcimWpDouble::$fluentCartOptions[$modalKey]['test_webhook_verifier_token']);
        self::assertSame('enc:modal-live-existing', \YSHelcimWpDouble::$fluentCartOptions[$modalKey]['live_webhook_verifier_token']);
        self::assertSame('', \YSHelcimWpDouble::$fluentCartOptions[$modalKey]['webhook_verifier_token']);
        self::assertSame('enc:inline-test-existing', \YSHelcimWpDouble::$fluentCartOptions[$inlineKey]['test_webhook_verifier_token']);
        self::assertSame('enc:inline-live-existing', \YSHelcimWpDouble::$fluentCartOptions[$inlineKey]['live_webhook_verifier_token']);
        self::assertSame('', \YSHelcimWpDouble::$fluentCartOptions[$inlineKey]['webhook_verifier_token']);
        self::assertSame([
            'version' => YSHelcimWebhookVerifierModeMigration::VERSION,
            'mode' => 'test',
            'status' => 'complete',
        ], \YSHelcimWpDouble::$options[YSHelcimWebhookVerifierModeMigration::OPTION_NAME] ?? null);
        self::assertCount(2, \YSHelcimWpDouble::$fluentCartOptionWrites);

        StoreSettings::$orderMode = 'live';
        $bootstrap->onInit();

        self::assertCount(2, \YSHelcimWpDouble::$fluentCartOptionWrites);
        self::assertSame('enc:modal-live-existing', \YSHelcimWpDouble::$fluentCartOptions[$modalKey]['live_webhook_verifier_token']);
        self::assertSame('enc:inline-live-existing', \YSHelcimWpDouble::$fluentCartOptions[$inlineKey]['live_webhook_verifier_token']);
        self::assertSame(YSHelcimOperationSchema::VERSION, \YSHelcimWpDouble::$options[YSHelcimOperationSchema::OPTION_NAME] ?? null);
    }

    #[DataProvider('gatewayClasses')]
    public function testSavingNeverRebindsLegacyVerifierAndOnlyClearsGlobalField(
        string $gatewayClass,
        string $settingsClass
    ): void {
        StoreSettings::$orderMode = 'test';
        $result = $gatewayClass::beforeSettingsUpdate(
            ['test_api_token' => 'enc:api-token'],
            [
                'webhook_verifier_token' => 'enc:legacy-verifier',
                'live_webhook_verifier_token' => 'enc:existing-live-verifier',
            ]
        );

        self::assertSame('', $result['test_webhook_verifier_token']);
        self::assertSame('enc:existing-live-verifier', $result['live_webhook_verifier_token']);
        self::assertSame('', $result['webhook_verifier_token']);

        BaseGatewaySettings::$settingsByClass[$settingsClass] = $result;
        $settings = new $settingsClass();
        self::assertSame('', $settings->getWebhookVerifierTokenForMode('test'));
        self::assertSame('existing-live-verifier', $settings->getWebhookVerifierTokenForMode('live'));
    }

    #[DataProvider('gatewayClasses')]
    public function testNewModeVerifierSecretsAreEncryptedOnceAndNeverReturnedByFields(
        string $gatewayClass,
        string $settingsClass
    ): void {
        unset($settingsClass);
        $result = $gatewayClass::beforeSettingsUpdate(
            [
                'test_webhook_verifier_token' => 'new-test-secret',
                'live_webhook_verifier_token' => 'enc:unchanged-live-secret',
                'webhook_verifier_token' => 'must-not-survive',
            ],
            ['live_webhook_verifier_token' => 'enc:unchanged-live-secret']
        );

        self::assertSame('enc:new-test-secret', $result['test_webhook_verifier_token']);
        self::assertSame('enc:unchanged-live-secret', $result['live_webhook_verifier_token']);
        self::assertSame('', $result['webhook_verifier_token']);

        $fields = (new $gatewayClass())->fields();
        $tabs = $fields['payment_mode']['schema'];
        $schemas = [];
        foreach ($tabs as $tab) {
            $schemas[(string) $tab['value']] = $tab['schema'];
        }
        self::assertSame('password', $schemas['test']['test_webhook_verifier_token']['type']);
        self::assertSame('', $schemas['test']['test_webhook_verifier_token']['value']);
        self::assertSame('password', $schemas['live']['live_webhook_verifier_token']['type']);
        self::assertSame('', $schemas['live']['live_webhook_verifier_token']['value']);
        self::assertArrayNotHasKey('webhook_verifier_token', $fields);
        self::assertStringNotContainsString('new-test-secret', json_encode($fields, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('unchanged-live-secret', json_encode($fields, JSON_THROW_ON_ERROR));
    }

    public function testInlineEnableValidationRequiresACompleteCurrentModeRecoveryPair(): void
    {
        StoreSettings::$orderMode = 'test';
        BaseGatewaySettings::$settingsByClass[YSHelcimJsSettings::class] = [
            'test_api_token' => 'enc:stored-api',
            'test_js_token' => 'stored-js-token',
            'test_js_secret_key' => 'enc:stored-js-secret',
            'test_webhook_verifier_token' => '',
            'live_webhook_verifier_token' => 'enc:wrong-mode-verifier',
        ];

        $missing = YSHelcimJsGateway::validateSettings([]);
        self::assertSame('failed', $missing['status']);
        self::assertStringContainsString('Verifier', $missing['message']);

        $complete = YSHelcimJsGateway::validateSettings([
            'test_webhook_verifier_token' => 'new-current-mode-verifier',
        ]);
        self::assertSame('success', $complete['status']);
    }

    public function testInlineEnableValidationCanUseAnExistingCompleteCredentialSetWithoutSecretEcho(): void
    {
        StoreSettings::$orderMode = 'live';
        BaseGatewaySettings::$settingsByClass[YSHelcimJsSettings::class] = [
            'live_api_token' => 'enc:stored-api',
            'live_js_token' => 'stored-js-token',
            'live_js_secret_key' => 'enc:stored-js-secret',
            'live_webhook_verifier_token' => 'enc:stored-verifier',
        ];

        $result = YSHelcimJsGateway::validateSettings([]);

        self::assertSame('success', $result['status']);
        self::assertStringNotContainsString('stored-', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testHostedEnableValidationRequiresACompleteCurrentModeRecoveryPair(): void
    {
        StoreSettings::$orderMode = 'test';
        BaseGatewaySettings::$settingsByClass[YSHelcimPaySettings::class] = [
            'test_api_token' => 'enc:stored-api',
            'test_webhook_verifier_token' => '',
            'live_webhook_verifier_token' => 'enc:wrong-mode-verifier',
        ];

        $missing = YSHelcimPayGateway::validateSettings([]);
        self::assertSame('failed', $missing['status']);
        self::assertStringContainsString('Verifier', $missing['message']);

        $complete = YSHelcimPayGateway::validateSettings([
            'test_webhook_verifier_token' => 'new-current-mode-verifier',
        ]);
        self::assertSame('success', $complete['status']);
    }

    public function testHostedEnableValidationCanUseAnExistingCompleteCredentialSetWithoutSecretEcho(): void
    {
        StoreSettings::$orderMode = 'live';
        BaseGatewaySettings::$settingsByClass[YSHelcimPaySettings::class] = [
            'live_api_token' => 'enc:stored-api',
            'live_webhook_verifier_token' => 'enc:stored-verifier',
        ];

        $result = YSHelcimPayGateway::validateSettings([]);

        self::assertSame('success', $result['status']);
        self::assertStringNotContainsString('stored-', json_encode($result, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('gatewaySecretFields')]
    public function testSecretPersistenceFailurePreservesOnlyOldCiphertextAndDisablesTheGateway(
        string $gatewayClass,
        array $secretFields,
        array $validNonSecrets
    ): void {
        Helper::$encryptBehavior = 'plaintext';

        $submitted = ['is_active' => 'yes'] + $validNonSecrets;
        $stored = [];
        foreach ($secretFields as $field) {
            $submitted[$field] = 'new-plaintext-' . $field;
            $stored[$field] = 'enc:old-' . $field;
        }

        $result = $gatewayClass::beforeSettingsUpdate($submitted, $stored);

        self::assertSame('no', $result['is_active']);
        foreach ($secretFields as $field) {
            self::assertSame('enc:old-' . $field, $result[$field]);
        }
        self::assertStringNotContainsString('new-plaintext-', json_encode($result, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('gatewayAndEncryptionFailureModes')]
    public function testUnprovableNewSecretsAreNeverPersistedWithoutOldCiphertext(
        string $gatewayClass,
        array $secretFields,
        array $validNonSecrets,
        string $failureMode
    ): void {
        if (str_starts_with($failureMode, 'verification_')) {
            Helper::$verificationBehavior = substr($failureMode, strlen('verification_'));
        } else {
            Helper::$encryptBehavior = $failureMode;
        }

        $submitted = ['is_active' => 'yes'] + $validNonSecrets;
        foreach ($secretFields as $field) {
            $submitted[$field] = 'new-plaintext-' . $field;
        }

        $result = $gatewayClass::beforeSettingsUpdate($submitted, []);

        self::assertSame('no', $result['is_active']);
        foreach ($secretFields as $field) {
            self::assertSame('', $result[$field]);
        }
        self::assertStringNotContainsString('new-plaintext-', json_encode($result, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('gatewayValidationFailureInputs')]
    public function testValidationCannotEnableFromAPlaintextSecretWhenEncryptionCannotBeProved(
        string $gatewayClass,
        string $settingsClass,
        array $submitted,
        string $failureMode
    ): void {
        if (str_starts_with($failureMode, 'verification_')) {
            Helper::$verificationBehavior = substr($failureMode, strlen('verification_'));
        } else {
            Helper::$encryptBehavior = $failureMode;
        }
        BaseGatewaySettings::$settingsByClass[$settingsClass] = [];

        $result = $gatewayClass::validateSettings($submitted);

        self::assertSame('failed', $result['status']);
        self::assertStringNotContainsString('plaintext-', json_encode($result, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('gatewayValidationInputs')]
    public function testStoredPlaintextSecretsCannotEnableOrExposeAGatewayAtCheckout(
        string $gatewayClass,
        string $settingsClass,
        array $submitted
    ): void {
        BaseGatewaySettings::$settingsByClass[$settingsClass] = ['is_active' => 'yes'] + $submitted;

        $gateway = new $gatewayClass();
        $result = $gatewayClass::validateSettings([]);

        self::assertSame('failed', $result['status']);
        self::assertFalse($gateway->isEnabled());
        self::assertFalse($gateway->meta()['status']);
    }

    #[DataProvider('settingsClasses')]
    public function testStoredCiphertextFailsClosedWhenVerificationOrDecryptionCannotComplete(string $settingsClass): void
    {
        BaseGatewaySettings::$settingsByClass[$settingsClass] = [
            'test_api_token' => 'enc:stored-api',
            'test_js_secret_key' => 'enc:stored-js-secret',
            'test_webhook_verifier_token' => 'enc:stored-verifier',
        ];

        Helper::$verificationBehavior = 'throw';
        $verificationFailure = new $settingsClass();
        self::assertSame('', $verificationFailure->getApiTokenForMode('test'));
        self::assertSame('', $verificationFailure->getWebhookVerifierTokenForMode('test'));
        if ($verificationFailure instanceof YSHelcimJsSettings) {
            self::assertSame('', $verificationFailure->getJsSecretKeyForMode('test'));
        }

        Helper::$verificationBehavior = 'normal';
        Helper::$decryptBehavior = 'throw';
        $decryptionFailure = new $settingsClass();
        self::assertSame('', $decryptionFailure->getApiTokenForMode('test'));
        self::assertSame('', $decryptionFailure->getWebhookVerifierTokenForMode('test'));
        if ($decryptionFailure instanceof YSHelcimJsSettings) {
            self::assertSame('', $decryptionFailure->getJsSecretKeyForMode('test'));
        }
    }

    public static function settingsClasses(): iterable
    {
        yield 'modal settings' => [YSHelcimPaySettings::class];
        yield 'inline settings' => [YSHelcimJsSettings::class];
    }

    public static function gatewayClasses(): iterable
    {
        yield 'modal gateway' => [YSHelcimPayGateway::class, YSHelcimPaySettings::class];
        yield 'inline gateway' => [YSHelcimJsGateway::class, YSHelcimJsSettings::class];
    }

    public static function gatewaySecretFields(): iterable
    {
        yield 'modal gateway' => [
            YSHelcimPayGateway::class,
            [
                'test_api_token',
                'live_api_token',
                'test_webhook_verifier_token',
                'live_webhook_verifier_token',
            ],
            [],
        ];
        yield 'inline gateway' => [
            YSHelcimJsGateway::class,
            [
                'test_api_token',
                'live_api_token',
                'test_js_secret_key',
                'live_js_secret_key',
                'test_webhook_verifier_token',
                'live_webhook_verifier_token',
            ],
            [
                'test_js_token' => 'public-test-token',
                'live_js_token' => 'public-live-token',
            ],
        ];
    }

    public static function gatewayAndEncryptionFailureModes(): iterable
    {
        foreach (self::gatewaySecretFields() as $gatewayName => $arguments) {
            foreach (['plaintext', 'false', 'throw', 'verification_false', 'verification_throw'] as $failureMode) {
                yield $gatewayName . ' / ' . $failureMode => [...$arguments, $failureMode];
            }
        }
    }

    public static function gatewayValidationInputs(): iterable
    {
        yield 'modal gateway' => [
            YSHelcimPayGateway::class,
            YSHelcimPaySettings::class,
            [
                'test_api_token' => 'plaintext-api-token',
                'test_webhook_verifier_token' => 'plaintext-verifier',
            ],
        ];
        yield 'inline gateway' => [
            YSHelcimJsGateway::class,
            YSHelcimJsSettings::class,
            [
                'test_api_token' => 'plaintext-api-token',
                'test_js_token' => 'public-js-token',
                'test_js_secret_key' => 'plaintext-js-secret',
                'test_webhook_verifier_token' => 'plaintext-verifier',
            ],
        ];
    }

    public static function gatewayValidationFailureInputs(): iterable
    {
        foreach (self::gatewayValidationInputs() as $gatewayName => $arguments) {
            foreach (['plaintext', 'false', 'throw', 'verification_false', 'verification_throw'] as $failureMode) {
                yield $gatewayName . ' / ' . $failureMode => [...$arguments, $failureMode];
            }
        }
    }
}
