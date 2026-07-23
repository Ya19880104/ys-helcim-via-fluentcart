<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\HelcimJs;

require_once dirname(__DIR__, 2) . '/Doubles/GatewayPaymentInstance.php';
require_once dirname(__DIR__, 2) . '/Doubles/InlineWordPress.php';

use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Services\Payments\PaymentInstance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsGateway;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsSettings;

final class GatewayPaymentInitializationTest extends TestCase
{
    protected function setUp(): void
    {
        \YSHelcimWpDouble::reset();
        BaseGatewaySettings::$settingsByClass[YSHelcimJsSettings::class] = [
            'test_api_token' => 'enc:test-api-token',
            'test_js_token' => 'test-js-token',
            'test_js_secret_key' => 'enc:test-js-secret',
            'test_webhook_verifier_token' => '',
            'webhook_verifier_token' => '',
        ];
    }

    public function testPurchaseInitializationRequiresRecoveryWebhookCredentials(): void
    {
        $result = (new YSHelcimJsGateway())->makePaymentFromPaymentInstance($this->paymentInstance());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_js_missing_credentials', $result->get_error_code());
    }

    /** @param array<string,string> $currentModeSettings */
    #[DataProvider('incompleteRecoveryPairs')]
    public function testPurchaseInitializationRequiresTheCompleteCurrentModeRecoveryPair(
        array $currentModeSettings
    ): void {
        BaseGatewaySettings::$settingsByClass[YSHelcimJsSettings::class] = array_merge(
            BaseGatewaySettings::$settingsByClass[YSHelcimJsSettings::class],
            [
                'test_webhook_verifier_token' => 'enc:test-verifier',
                'live_api_token' => 'enc:wrong-mode-api-token',
                'live_webhook_verifier_token' => 'enc:wrong-mode-verifier',
            ],
            $currentModeSettings
        );

        $result = (new YSHelcimJsGateway())->makePaymentFromPaymentInstance($this->paymentInstance());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_js_missing_credentials', $result->get_error_code());
    }

    public function testLegacyVerifierCannotBypassModeSpecificRecoveryCredentialRequirement(): void
    {
        BaseGatewaySettings::$settingsByClass[YSHelcimJsSettings::class]['webhook_verifier_token'] = 'enc:legacy-test-verifier';

        $result = (new YSHelcimJsGateway())->makePaymentFromPaymentInstance($this->paymentInstance());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_js_missing_credentials', $result->get_error_code());
    }

    public function testSuccessfulInitializationReturnsAuthoritativeOrderBillingAvs(): void
    {
        BaseGatewaySettings::$settingsByClass[YSHelcimJsSettings::class]['test_webhook_verifier_token'] = 'enc:test-verifier';

        $gateway = new class extends YSHelcimJsGateway {
            protected function hasDurableRecoverySchedule(): bool
            {
                return true;
            }

            protected function verifyRecoveryApiAccess(): true|\WP_Error
            {
                return true;
            }
        };
        $result = $gateway->makePaymentFromPaymentInstance($this->paymentInstance());

        self::assertIsArray($result);
        self::assertSame('123 Test Street', $result['payment_data']['cardholder_address']);
        self::assertSame('100', $result['payment_data']['cardholder_postal_code']);
    }

    public function testPurchaseInitializationFailsClosedWhenRecurringRecoveryCannotBeScheduled(): void
    {
        BaseGatewaySettings::$settingsByClass[YSHelcimJsSettings::class]['test_webhook_verifier_token'] = 'enc:test-verifier';
        $gateway = new class extends YSHelcimJsGateway {
            protected function hasDurableRecoverySchedule(): bool
            {
                return false;
            }

            protected function verifyRecoveryApiAccess(): true|\WP_Error
            {
                return true;
            }
        };

        $result = $gateway->makePaymentFromPaymentInstance($this->paymentInstance());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_js_recovery_unavailable', $result->get_error_code());
    }

    public function testPurchaseInitializationFailsClosedWithoutCardTransactionReadPermission(): void
    {
        BaseGatewaySettings::$settingsByClass[YSHelcimJsSettings::class]['test_webhook_verifier_token'] = 'enc:test-verifier';
        $gateway = new class extends YSHelcimJsGateway {
            protected function hasDurableRecoverySchedule(): bool
            {
                return true;
            }

            protected function verifyRecoveryApiAccess(): true|\WP_Error
            {
                return new \WP_Error(
                    'ys_helcim_hosted_recovery_permission_unavailable',
                    'Read permission unavailable.'
                );
            }
        };

        $result = $gateway->makePaymentFromPaymentInstance($this->paymentInstance());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_hosted_recovery_permission_unavailable', $result->get_error_code());
    }

    public function testCheckoutInfoFailsBeforeCardEntryWhenRecoveryCredentialsAreMissing(): void
    {
        $gateway = new class extends YSHelcimJsGateway {
            public function isCurrencySupported(): bool
            {
                return true;
            }
        };

        try {
            $gateway->getOrderInfo([]);
            self::fail('getOrderInfo must terminate through wp_send_json.');
        } catch (\YSHelcimWpJsonExit $response) {
            self::assertSame(503, $response->statusCode);
            self::assertSame('failed', $response->payload['status']);
        }
    }

    public function testCheckoutInfoFailsBeforeCardEntryWhenRecurringRecoveryCannotBeScheduled(): void
    {
        BaseGatewaySettings::$settingsByClass[YSHelcimJsSettings::class]['test_webhook_verifier_token'] = 'enc:test-verifier';
        $gateway = new class extends YSHelcimJsGateway {
            public function isCurrencySupported(): bool
            {
                return true;
            }

            protected function hasDurableRecoverySchedule(): bool
            {
                return false;
            }

            protected function verifyRecoveryApiAccess(): true|\WP_Error
            {
                return true;
            }
        };

        try {
            $gateway->getOrderInfo([]);
            self::fail('getOrderInfo must terminate through wp_send_json.');
        } catch (\YSHelcimWpJsonExit $response) {
            self::assertSame(503, $response->statusCode);
            self::assertSame('failed', $response->payload['status']);
            self::assertStringContainsString('recovery', strtolower($response->payload['message']));
        }
    }

    public function testCheckoutInfoFailsBeforeCardEntryWithoutCardTransactionReadPermission(): void
    {
        BaseGatewaySettings::$settingsByClass[YSHelcimJsSettings::class]['test_webhook_verifier_token'] = 'enc:test-verifier';
        $gateway = new class extends YSHelcimJsGateway {
            public function isCurrencySupported(): bool
            {
                return true;
            }

            protected function hasDurableRecoverySchedule(): bool
            {
                return true;
            }

            protected function verifyRecoveryApiAccess(): true|\WP_Error
            {
                return new \WP_Error(
                    'ys_helcim_hosted_recovery_permission_unavailable',
                    'Read permission unavailable.'
                );
            }
        };

        try {
            $gateway->getOrderInfo([]);
            self::fail('getOrderInfo must terminate through wp_send_json.');
        } catch (\YSHelcimWpJsonExit $response) {
            self::assertSame(503, $response->statusCode);
            self::assertSame('failed', $response->payload['status']);
            self::assertSame('Read permission unavailable.', $response->payload['message']);
        }
    }

    /** @param array<string,string> $currentModeSettings */
    #[DataProvider('incompleteRecoveryPairs')]
    public function testCheckoutInfoRequiresTheCompleteCurrentModeRecoveryPair(
        array $currentModeSettings
    ): void {
        BaseGatewaySettings::$settingsByClass[YSHelcimJsSettings::class] = array_merge(
            BaseGatewaySettings::$settingsByClass[YSHelcimJsSettings::class],
            [
                'test_webhook_verifier_token' => 'enc:test-verifier',
                'live_api_token' => 'enc:wrong-mode-api-token',
                'live_webhook_verifier_token' => 'enc:wrong-mode-verifier',
            ],
            $currentModeSettings
        );
        $gateway = new class extends YSHelcimJsGateway {
            public function isCurrencySupported(): bool
            {
                return true;
            }
        };

        try {
            $gateway->getOrderInfo([]);
            self::fail('getOrderInfo must terminate through wp_send_json.');
        } catch (\YSHelcimWpJsonExit $response) {
            self::assertSame(503, $response->statusCode);
            self::assertSame('failed', $response->payload['status']);
        }
    }

    public function testSupportedCurrenciesCannotBeExtendedBeyondTheOperationContract(): void
    {
        add_filter(
            'ys_helcim_fct_supported_currencies',
            static fn (): array => ['USD', 'CAD', 'EUR']
        );

        self::assertSame(['USD', 'CAD'], (new YSHelcimJsGateway())->getSupportedCurrencies());
    }

    /** @return iterable<string,array{array<string,string>}> */
    public static function incompleteRecoveryPairs(): iterable
    {
        yield 'current-mode API token missing' => [['test_api_token' => '']];
        yield 'current-mode webhook verifier missing' => [['test_webhook_verifier_token' => '']];
        yield 'current-mode API token whitespace-only' => [['test_api_token' => '   ']];
        yield 'current-mode webhook verifier whitespace-only' => [['test_webhook_verifier_token' => "\t\n"]];
    }

    private function paymentInstance(): PaymentInstance
    {
        return new PaymentInstance(
            (object) [
                'id' => 10,
                'billing_address' => (object) [
                    'address_1' => '123 Test Street',
                    'postcode' => '100',
                ],
            ],
            (object) [
                'id' => 20,
                'uuid' => 'fc-transaction-123',
                'currency' => 'USD',
                'total' => 1050,
            ]
        );
    }
}
