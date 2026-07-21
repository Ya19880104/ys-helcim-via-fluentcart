<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Support\YSHelcimLogger;

final class LoggerTest extends TestCase
{
    public function testMaskingPreservesAnAlreadyRedactedSentinel(): void
    {
        $masked = YSHelcimLogger::mask_sensitive([
            'cardCVV' => '[redacted]',
            'apiToken' => '[redacted]',
        ]);

        self::assertSame('[redacted]', $masked['cardCVV']);
        self::assertSame('[redacted]', $masked['apiToken']);
    }

    public function testSensitiveValuesAreFullyRedactedWithoutKeepingReusableFragments(): void
    {
        $masked = YSHelcimLogger::mask_sensitive([
            'apiToken' => 'abcd-reusable-secret-wxyz',
            'card_token' => 'card-reusable-secret',
            'nested' => [
                'authorization' => 'Bearer live-secret',
                'error' => 'request failed api-token=another-secret 4111111111111111',
            ],
        ]);

        self::assertSame('[redacted]', $masked['apiToken']);
        self::assertSame('[redacted]', $masked['card_token']);
        self::assertSame('[redacted]', $masked['nested']['authorization']);
        $encoded = json_encode($masked, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('abcd', $encoded);
        self::assertStringNotContainsString('wxyz', $encoded);
        self::assertStringNotContainsString('another-secret', $encoded);
        self::assertStringNotContainsString('4111111111111111', $encoded);
    }

    public function testReceiptBearerAndCustomerPiiKeysAreFullyRedacted(): void
    {
        $masked = YSHelcimLogger::mask_sensitive([
            'transaction_uuid' => 'receipt-bearer-uuid',
            'billingAddress' => [
                'name' => 'Private Buyer',
                'email' => 'private@example.test',
                'street1' => '123 Private Street',
            ],
            'customer' => ['phone' => '+1-555-0100'],
        ]);

        self::assertSame('[redacted]', $masked['transaction_uuid']);
        self::assertSame('[redacted]', $masked['billingAddress']);
        self::assertSame('[redacted]', $masked['customer']);
    }
}
