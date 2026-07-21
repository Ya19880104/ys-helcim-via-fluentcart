<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\HelcimJs;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimPurchaseConfirmationToken;

final class PurchaseConfirmationTokenTest extends TestCase
{
    public function testTokenIsBoundToExactTransactionAndExpires(): void
    {
        $now = 1784600000;
        $tokens = new YSHelcimPurchaseConfirmationToken(
            static function () use (&$now): int { return $now; },
            static fn (): string => str_repeat('s', 64)
        );
        $token = $tokens->issue('fc-transaction-123', 20);

        self::assertIsString($token);
        self::assertTrue($tokens->verify($token, 'fc-transaction-123', 20));
        self::assertFalse($tokens->verify($token, 'fc-transaction-123', 21));
        self::assertFalse($tokens->verify($token, 'fc-transaction-124', 20));

        $now += 901;
        self::assertFalse($tokens->verify($token, 'fc-transaction-123', 20));
    }

    public function testTamperingAndInvalidIdentityFailClosed(): void
    {
        $tokens = new YSHelcimPurchaseConfirmationToken(
            static fn (): int => 1784600000,
            static fn (): string => str_repeat('s', 64)
        );
        $token = $tokens->issue('fc-transaction-123', 20);
        self::assertIsString($token);

        self::assertFalse($tokens->verify($token . 'x', 'fc-transaction-123', 20));
		$replacement = substr($token, -1) === 'A' ? 'B' : 'A';
		self::assertFalse($tokens->verify(substr($token, 0, -1) . $replacement, 'fc-transaction-123', 20));
        self::assertInstanceOf(\WP_Error::class, $tokens->issue('', 20));
        self::assertInstanceOf(\WP_Error::class, $tokens->issue('fc-transaction-123', 0));
    }
}
