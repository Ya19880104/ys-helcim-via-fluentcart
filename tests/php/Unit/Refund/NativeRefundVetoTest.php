<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimNativeRefundVeto;

final class NativeRefundVetoTest extends TestCase
{
    /** @dataProvider helcimGateways */
    public function testSucceededHelcimChargeHasNoNativeRefundableAmount(string $gateway): void
    {
        $transaction = (object) [
            'payment_method' => $gateway,
            'status' => 'succeeded',
        ];

        self::assertSame(0, YSHelcimNativeRefundVeto::filter(2100, $transaction));
    }

    public function testNonHelcimTransactionKeepsNativeRefundableAmount(): void
    {
        $transaction = (object) [
            'payment_method' => 'stripe',
            'status' => 'succeeded',
        ];

        self::assertSame(2100, YSHelcimNativeRefundVeto::filter(2100, $transaction));
    }

    public function testNonSucceededHelcimTransactionKeepsInputUntouched(): void
    {
        $transaction = (object) [
            'payment_method' => 'ys_helcim',
            'status' => 'pending',
        ];

        self::assertSame(2100, YSHelcimNativeRefundVeto::filter(2100, $transaction));
    }

    /** @return array<string,array{string}> */
    public static function helcimGateways(): array
    {
        return [
            'modal' => ['ys_helcim'],
            'inline' => ['ys_helcim_js'],
        ];
    }
}
