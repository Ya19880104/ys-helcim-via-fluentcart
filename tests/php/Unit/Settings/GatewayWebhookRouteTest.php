<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Settings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsGateway;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPayGateway;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class GatewayWebhookRouteTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/Doubles/InlineWordPress.php';
        \YSHelcimWpDouble::reset();
    }

    #[DataProvider('gatewayClasses')]
    public function testSettingsShowOnlyTheCleanRestWebhookUrl(string $gatewayClass): void
    {
        $fields = (new $gatewayClass())->fields();
        $html = (string) $fields['webhook_desc']['value'];

        self::assertStringContainsString('https://shop.test/wp-json/ys-fc-pay/v1/events/card', $html);
        self::assertStringNotContainsString('fct_payment_listener_ipn', $html);
        self::assertStringNotContainsString('?fluent-cart=', $html);
    }

    #[DataProvider('gatewayClasses')]
    public function testLegacyIpnNeverMutatesAndDirectsHelcimToTheCleanRoute(string $gatewayClass): void
    {
        $gateway = new $gatewayClass();

        try {
            $gateway->handleIPN();
            self::fail('handleIPN must terminate through wp_send_json.');
        } catch (\YSHelcimWpJsonExit $response) {
            self::assertSame(410, $response->statusCode);
            self::assertSame('https://shop.test/wp-json/ys-fc-pay/v1/events/card', $response->payload['webhook_url']);
            self::assertStringContainsString('REST', $response->payload['message']);
        }

        self::assertSame([], \YSHelcimWpDouble::$requests, 'The legacy IPN route must never call Helcim or mutate a payment.');
    }

    public function testHostedSettingsDoNotClaimRecoveryIsOptional(): void
    {
        $fields = (new YSHelcimPayGateway())->fields();
        $html = strtolower((string) $fields['webhook_desc']['value']);

        self::assertStringContainsString('required', $html);
        self::assertStringNotContainsString('payments still work normally when it is not configured', $html);
    }

    #[DataProvider('gatewayClasses')]
    public function testForbiddenDevelopmentHostnameIsBlockedInsteadOfRenderedAsCopyable(string $gatewayClass): void
    {
		\YSHelcimWpDouble::$restUrlBase = 'https://payments-helcim.example/wp-json/';

        $fields = (new $gatewayClass())->fields();
        $html = (string) $fields['webhook_desc']['value'];

        self::assertStringNotContainsString('copyable-content', $html);
        self::assertStringContainsString('not ready', strtolower($html));
    }

    public static function gatewayClasses(): iterable
    {
        yield 'modal gateway' => [YSHelcimPayGateway::class];
        yield 'inline gateway' => [YSHelcimJsGateway::class];
    }
}
