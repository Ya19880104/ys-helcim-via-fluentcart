<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\HelcimPay;

use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPayRecoveryCapability;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPaySettings;

final class HostedRecoveryCapabilityTest extends TestCase
{
    protected function setUp(): void
    {
        \YSHelcimWpDouble::reset();
        BaseGatewaySettings::$settingsByClass[YSHelcimPaySettings::class] = [
            'test_api_token' => 'enc:test-api-token',
            'test_webhook_verifier_token' => 'enc:test-verifier',
        ];
    }

    public function testRootCollectionProvesReadPermissionAndIsCachedByCredentialFingerprint(): void
    {
        $calls = 0;
        $capability = new YSHelcimPayRecoveryCapability(
            function (string $endpoint, array $payload, string $token, ?string $key, string $method) use (&$calls): array {
                ++$calls;
                self::assertSame('card-transactions', $endpoint);
                self::assertSame('GET', $method);
                self::assertNull($key);
                self::assertSame('test-api-token', $token);
                self::assertSame(1, $payload['limit']);
                self::assertSame(1, $payload['page']);
                self::assertSame('00000000-0000-4000-8000-000000000000', $payload['invoiceNumber']);
                return [];
            },
            static fn (): int => 1784678400
        );

        self::assertTrue($capability->verify(new YSHelcimPaySettings()));
        self::assertTrue($capability->verify(new YSHelcimPaySettings()));
        self::assertSame(1, $calls);
        $stored = \YSHelcimWpDouble::$options[YSHelcimPayRecoveryCapability::OPTION_NAME] ?? null;
        self::assertIsArray($stored);
        self::assertArrayNotHasKey('api_token', $stored);
        self::assertStringNotContainsString('test-api-token', serialize($stored));
    }

    public function testProviderDenialAndUndocumentedEnvelopeFailClosedWithoutCaching(): void
    {
        $responses = [
            new \WP_Error('ys_helcim_api_error', 'Forbidden.'),
            ['data' => []],
        ];
        $capability = new YSHelcimPayRecoveryCapability(
            static function () use (&$responses): array|\WP_Error {
                return array_shift($responses);
            },
            static fn (): int => 1784678400
        );

        foreach ([1, 2] as $attempt) {
            unset($attempt);
            $result = $capability->verify(new YSHelcimPaySettings());
            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame('ys_helcim_hosted_recovery_permission_unavailable', $result->get_error_code());
        }
        self::assertArrayNotHasKey(YSHelcimPayRecoveryCapability::OPTION_NAME, \YSHelcimWpDouble::$options);
    }
}
