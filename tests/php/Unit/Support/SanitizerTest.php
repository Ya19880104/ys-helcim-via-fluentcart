<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Support\YSHelcimSanitizer;

final class SanitizerTest extends TestCase
{
    public function testErrorTextRemovesMarkupPaymentSecretsAndPan(): void
    {
        $unsafe = '<img src=x onerror=alert(1)> api-token=secret-value card-token: reusable-token '
            . 'authorization: Bearer abc.def.ghi card 4111111111111111';

        $safe = YSHelcimSanitizer::errorText($unsafe);

        self::assertStringNotContainsString('<img', $safe);
        self::assertStringNotContainsString('secret-value', $safe);
        self::assertStringNotContainsString('reusable-token', $safe);
        self::assertStringNotContainsString('abc.def.ghi', $safe);
        self::assertStringNotContainsString('4111111111111111', $safe);
        self::assertStringContainsString('[redacted]', $safe);
    }

    public function testProviderErrorsAreBoundedAndRecursivelySanitized(): void
    {
        $errors = [
            'cardTransactionId' => '<b>Card Transaction cannot be refunded</b>',
            'nested' => ['authorization' => 'authorization=very-secret-value'],
            'pan' => '4111 1111 1111 1111',
            'cardCVV' => '123',
            'cardExpiry' => '1228',
            'apiToken' => 'helcim-secret',
        ];

        $safe = YSHelcimSanitizer::providerErrors($errors);

        self::assertSame('Card Transaction cannot be refunded', $safe['cardtransactionid']);
        self::assertStringNotContainsString('very-secret-value', $safe['nested']['authorization']);
        self::assertStringNotContainsString('4111 1111 1111 1111', $safe['pan']);
        self::assertSame('[redacted]', $safe['cardcvv']);
        self::assertSame('[redacted]', $safe['cardexpiry']);
        self::assertSame('[redacted]', $safe['apitoken']);
    }

    public function testLogContextRedactsNumericPanOutsideNamedFields(): void
    {
        $safe = YSHelcimSanitizer::logContext([
            'values' => [4111111111111111, 42, true, null],
        ]);

        self::assertSame('[redacted-number]', $safe['values'][0]);
        self::assertSame('42', $safe['values'][1]);
        self::assertTrue($safe['values'][2]);
        self::assertNull($safe['values'][3]);
    }
}
