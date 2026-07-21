<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundEffectIntegrityVerifier;

final class RefundEffectIntegrityVerifierTest extends TestCase
{
    public function testItAdaptsAClaimedEffectToTheRecordersUuidOnlyContract(): void
    {
        $calls = [];
        $verifier = new YSHelcimRefundEffectIntegrityVerifier(
            static function (string $uuid) use (&$calls): array {
                $calls[] = func_get_args();
                return ['operation_uuid' => $uuid, 'local_status' => 'recorded'];
            }
        );

        $result = $verifier->verify(
            ['operation_uuid' => '00000000-0000-4000-8000-000000000001'],
            ['api_token' => 'must-not-be-forwarded']
        );

        self::assertIsArray($result);
        self::assertSame([['00000000-0000-4000-8000-000000000001']], $calls);
    }

    public function testMissingOrMalformedEffectUuidFailsBeforeCallingRecorder(): void
    {
        $calls = 0;
        $verifier = new YSHelcimRefundEffectIntegrityVerifier(
            static function () use (&$calls): array {
                ++$calls;
                return [];
            }
        );

        foreach ([[], ['operation_uuid' => '../bad']] as $effect) {
            $result = $verifier->verify($effect, []);
            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame('ys_helcim_effect_integrity_unavailable', $result->get_error_code());
        }
        self::assertSame(0, $calls);
    }
}
