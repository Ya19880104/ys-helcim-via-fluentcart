<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Security\YSHelcimSensitiveEnvelope;

final class SensitiveEnvelopeTest extends TestCase
{
    public function testAuthenticatedEnvelopeRoundTripsWithoutExposingPlaintext(): void
    {
        $first = YSHelcimSensitiveEnvelope::encrypt('reusable-card-token-123');
        $second = YSHelcimSensitiveEnvelope::encrypt('reusable-card-token-123');

        self::assertStringStartsWith('ysenc:v1:', $first);
        self::assertStringNotContainsString('reusable-card-token-123', $first);
        self::assertNotSame($first, $second);
        self::assertSame('reusable-card-token-123', YSHelcimSensitiveEnvelope::decrypt($first));
        self::assertTrue(YSHelcimSensitiveEnvelope::isValid($first));
    }

    public function testTamperedOrFakeEnvelopeFailsAuthentication(): void
    {
        $envelope = YSHelcimSensitiveEnvelope::encrypt('reusable-card-token-123');
        $tampered = substr($envelope, 0, -1) . (str_ends_with($envelope, 'A') ? 'B' : 'A');

        self::assertNull(YSHelcimSensitiveEnvelope::decrypt($tampered));
        self::assertFalse(YSHelcimSensitiveEnvelope::isValid('ysenc:v1:not-really-encrypted'));
    }

    public function testEmptyPlaintextIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        YSHelcimSensitiveEnvelope::encrypt('');
    }
}
