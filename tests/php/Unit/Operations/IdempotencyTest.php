<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Operations;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimIdempotency;

final class IdempotencyTest extends TestCase
{
    /**
     * @dataProvider operationAmounts
     */
    public function testGeneratedKeyIsStableAndAlwaysValid(string $operationType, int $amount): void
    {
        $first = YSHelcimIdempotency::generate(
            $operationType,
            'fc-transaction-123',
            $amount,
            'test',
            '00000000-0000-4000-8000-000000000001'
        );
        $again = YSHelcimIdempotency::generate(
            $operationType,
            'fc-transaction-123',
            $amount,
            'test',
            '00000000-0000-4000-8000-000000000001'
        );

        self::assertSame($first, $again);
        self::assertSame(36, strlen($first));
        self::assertMatchesRegularExpression('/^ysh-[a-f0-9]{32}$/', $first);
        self::assertTrue(YSHelcimIdempotency::isValid($first));
    }

    public function testOperationTypeAndScopeBothParticipateInTheKey(): void
    {
        $refund = YSHelcimIdempotency::generate(
            'refund',
            'fc-transaction-123',
            999,
            'test',
            '00000000-0000-4000-8000-000000000001'
        );
        $reverse = YSHelcimIdempotency::generate(
            'reverse',
            'fc-transaction-123',
            999,
            'test',
            '00000000-0000-4000-8000-000000000001'
        );
        $otherRefund = YSHelcimIdempotency::generate(
            'refund',
            'fc-transaction-123',
            999,
            'test',
            '00000000-0000-4000-8000-000000000002'
        );

        self::assertNotSame($refund, $reverse);
        self::assertNotSame($refund, $otherRefund);
    }

    /**
     * @dataProvider invalidGenerationArguments
     */
    public function testInvalidGenerationArgumentsFailClosed(
        string $operationType,
        string $transactionUuid,
        int $amount,
        string $paymentMode,
        string $operationUuid
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        YSHelcimIdempotency::generate(
            $operationType,
            $transactionUuid,
            $amount,
            $paymentMode,
            $operationUuid
        );
    }

    /**
     * @dataProvider invalidKeys
     */
    public function testRejectsMalformedKeys(string $key): void
    {
        self::assertFalse(YSHelcimIdempotency::isValid($key));
    }

    /** @return array<string, array{string}> */
    public static function invalidKeys(): array
    {
        return [
            'empty' => [''],
            'too short' => ['ysh-1234'],
            'too long' => ['ysh-' . str_repeat('a', 33)],
            'space' => [str_repeat('a', 24) . ' '],
            'control character' => [str_repeat('a', 24) . "\r"],
            'period' => [str_repeat('a', 24) . '.'],
            'colon' => [str_repeat('a', 24) . ':'],
        ];
    }

    /** @return array<string, array{string, int}> */
    public static function operationAmounts(): array
    {
        $cases = [];
        foreach (['refund', 'reverse'] as $operationType) {
            foreach ([1, 50, 999, 2100] as $amount) {
                $cases[$operationType . '-' . $amount] = [$operationType, $amount];
            }
        }

        return $cases;
    }

    /** @return array<string, array{string, string, int, string, string}> */
    public static function invalidGenerationArguments(): array
    {
        $uuid = '00000000-0000-4000-8000-000000000001';

        return [
            'unknown operation' => ['capture', 'fc-transaction-123', 100, 'test', $uuid],
            'empty transaction uuid' => ['purchase', '', 100, 'test', $uuid],
            'zero amount' => ['refund', 'fc-transaction-123', 0, 'test', $uuid],
            'negative amount' => ['refund', 'fc-transaction-123', -1, 'test', $uuid],
            'unknown payment mode' => ['refund', 'fc-transaction-123', 100, 'sandbox', $uuid],
            'invalid operation uuid' => ['refund', 'fc-transaction-123', 100, 'test', 'not-a-uuid'],
        ];
    }
}
