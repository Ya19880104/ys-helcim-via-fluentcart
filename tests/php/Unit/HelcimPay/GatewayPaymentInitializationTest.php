<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\HelcimPay;

require_once dirname(__DIR__, 2) . '/Doubles/GatewayPaymentInstance.php';
require_once dirname(__DIR__, 2) . '/Doubles/InlineWordPress.php';

use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Services\Payments\PaymentInstance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPayGateway;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPayProcessor;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPaySettings;

final class GatewayPaymentInitializationTest extends TestCase
{
    protected function setUp(): void
    {
        \YSHelcimWpDouble::reset();
    }

    /** @param array<string,string> $currentModeSettings */
    #[DataProvider('incompleteRecoveryPairs')]
    public function testPurchaseInitializationRequiresTheCompleteCurrentModeRecoveryPair(
        array $currentModeSettings
    ): void {
        BaseGatewaySettings::$settingsByClass[YSHelcimPaySettings::class] = array_merge(
            [
                'test_api_token' => 'enc:test-api-token',
                'test_webhook_verifier_token' => 'enc:test-verifier',
                'live_api_token' => 'enc:wrong-mode-api-token',
                'live_webhook_verifier_token' => 'enc:wrong-mode-verifier',
            ],
            $currentModeSettings
        );

        $gateway = new class extends YSHelcimPayGateway {
            public function processor(): YSHelcimPayProcessor
            {
                return new class extends YSHelcimPayProcessor {
                    public function __construct()
                    {
                    }

                    public function initialize(PaymentInstance $paymentInstance): array
                    {
                        unset($paymentInstance);
                        return ['status' => 'unexpected-initialization'];
                    }
                };
            }
        };

        $result = $gateway->makePaymentFromPaymentInstance($this->paymentInstance());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_missing_credentials', $result->get_error_code());
    }

    /** @param array<string,string> $currentModeSettings */
    #[DataProvider('incompleteRecoveryPairs')]
    public function testCheckoutInfoFailsBeforeOrderCreationWithoutTheCompleteCurrentModeRecoveryPair(
        array $currentModeSettings
    ): void {
        BaseGatewaySettings::$settingsByClass[YSHelcimPaySettings::class] = array_merge(
            [
                'test_api_token' => 'enc:test-api-token',
                'test_webhook_verifier_token' => 'enc:test-verifier',
                'live_api_token' => 'enc:wrong-mode-api-token',
                'live_webhook_verifier_token' => 'enc:wrong-mode-verifier',
            ],
            $currentModeSettings
        );

        $gateway = new class extends YSHelcimPayGateway {
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

    /** @return iterable<string,array{array<string,string>}> */
    public static function incompleteRecoveryPairs(): iterable
    {
        yield 'current-mode API token missing' => [['test_api_token' => '']];
        yield 'current-mode webhook verifier missing' => [['test_webhook_verifier_token' => '']];
        yield 'current-mode API token whitespace-only' => [['test_api_token' => '   ']];
        yield 'current-mode webhook verifier whitespace-only' => [['test_webhook_verifier_token' => "\t\n"]];
    }

	public function testPurchaseInitializationFailsClosedWhenRecurringRecoveryCannotBeScheduled(): void
	{
		BaseGatewaySettings::$settingsByClass[YSHelcimPaySettings::class] = [
			'test_api_token' => 'enc:test-api-token',
			'test_webhook_verifier_token' => 'enc:test-verifier',
		];
		$gateway = new class extends YSHelcimPayGateway {
			protected function hasDurableRecoverySchedule(): bool
			{
				return false;
			}

			public function processor(): YSHelcimPayProcessor
			{
				return new class extends YSHelcimPayProcessor {
					public function __construct() {}
					public function initialize(PaymentInstance $paymentInstance): array
					{
						unset($paymentInstance);
						return ['status' => 'must-not-initialize'];
					}
				};
			}
		};

		$result = $gateway->makePaymentFromPaymentInstance($this->paymentInstance());

		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame('ys_helcim_hosted_recovery_unavailable', $result->get_error_code());
	}

	public function testCheckoutInfoFailsBeforeOrderCreationWhenRecurringRecoveryCannotBeScheduled(): void
	{
		BaseGatewaySettings::$settingsByClass[YSHelcimPaySettings::class] = [
			'test_api_token' => 'enc:test-api-token',
			'test_webhook_verifier_token' => 'enc:test-verifier',
		];
		$gateway = new class extends YSHelcimPayGateway {
			protected function hasDurableRecoverySchedule(): bool
			{
				return false;
			}
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
			self::assertStringContainsString('recovery', strtolower($response->payload['message']));
		}
	}

	public function testPurchaseInitializationFailsClosedWithoutCardTransactionReadPermission(): void
	{
		BaseGatewaySettings::$settingsByClass[YSHelcimPaySettings::class] = [
			'test_api_token' => 'enc:test-api-token',
			'test_webhook_verifier_token' => 'enc:test-verifier',
		];
		$gateway = new class extends YSHelcimPayGateway {
			protected function hasDurableRecoverySchedule(): bool
			{
				return true;
			}
			protected function verifyRecoveryApiAccess(): true|\WP_Error
			{
				return new \WP_Error('ys_helcim_hosted_recovery_permission_unavailable', 'Read permission unavailable.');
			}
		};

		$result = $gateway->makePaymentFromPaymentInstance($this->paymentInstance());

		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame('ys_helcim_hosted_recovery_permission_unavailable', $result->get_error_code());
	}

    private function paymentInstance(): PaymentInstance
    {
        return new PaymentInstance(
            (object) ['id' => 10],
            (object) [
                'id' => 20,
                'uuid' => 'fc-hosted-transaction-123',
                'currency' => 'USD',
                'total' => 1050,
            ]
        );
    }
}
