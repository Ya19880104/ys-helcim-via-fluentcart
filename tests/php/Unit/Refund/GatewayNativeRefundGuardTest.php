<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsGateway;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPayGateway;

final class GatewayNativeRefundGuardTest extends TestCase
{
    #[DataProvider('gatewayClasses')]
    public function testGatewayDoesNotAdvertiseNativeRefundAndFailsClosed(string $gatewayClass): void
    {
        $gateway = new $gatewayClass();

        self::assertNotContains('refund', $gateway->supportedFeatures);

        $result = $gateway->processRefund((object) [], 100, []);
        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_native_refund_disabled', $result->get_error_code());
    }

    /** @return array<string,array{class-string}> */
    public static function gatewayClasses(): array
    {
        return [
            'modal' => [YSHelcimPayGateway::class],
            'inline' => [YSHelcimJsGateway::class],
        ];
    }
}
