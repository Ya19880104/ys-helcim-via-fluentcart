<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Support\YSHelcimTransactionId;

final class TransactionIdTest extends TestCase
{
    #[DataProvider('validIds')]
    public function testItNormalizesPositivePlatformIntegers(mixed $value, string $expected): void
    {
        self::assertSame($expected, YSHelcimTransactionId::normalize($value));
    }

    #[DataProvider('invalidIds')]
    public function testItRejectsMissingMalformedOrUnsafeIds(mixed $value): void
    {
        self::assertNull(YSHelcimTransactionId::normalize($value));
    }

    public static function validIds(): iterable
    {
        yield 'integer' => [51177061, '51177061'];
        yield 'numeric string' => ['51177061', '51177061'];
        yield 'platform max' => [(string) PHP_INT_MAX, (string) PHP_INT_MAX];
    }

    public static function invalidIds(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'zero integer' => [0];
        yield 'zero string' => ['0'];
        yield 'negative' => [-1];
        yield 'signed string' => ['+1'];
        yield 'decimal string' => ['1.0'];
        yield 'scientific notation' => ['1e3'];
        yield 'mixed characters' => ['tx-51177061'];
        yield 'leading zero' => ['051177061'];
        yield 'boolean' => [true];
        yield 'float' => [51177061.0];
        yield 'array' => [[51177061]];
        yield 'above platform max' => [(string) PHP_INT_MAX . '0'];
    }
}
