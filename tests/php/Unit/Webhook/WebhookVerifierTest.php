<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Webhook\YSHelcimWebhookVerifier;

final class WebhookVerifierTest extends TestCase
{
    public function testOfficialSignatureFormulaAcceptsCaseInsensitiveArrayHeadersAndMultipleCandidates(): void
    {
        $body = '{"type":"cardTransaction","id":51177061}';
        $timestamp = (string) time();
        $token = base64_encode('fixed-verifier-key');
        $signature = base64_encode(hash_hmac(
            'sha256',
            'event-fixed-1.' . $timestamp . '.' . $body,
            'fixed-verifier-key',
            true
        ));

        self::assertTrue(YSHelcimWebhookVerifier::verify([
            'Webhook-Id' => ['event-fixed-1'],
            'WEBHOOK-TIMESTAMP' => [$timestamp],
            'webhook-signature' => ['v1,' . base64_encode('wrong') . ' v1,' . $signature],
        ], $body, $token));
    }

    public function testWordPressRestCanonicalizedHeaderNamesAreAccepted(): void
    {
        [$headers, $body, $token] = $this->vector();

        self::assertTrue(YSHelcimWebhookVerifier::verify([
            'webhook_id' => [$headers['webhook-id']],
            'webhook_timestamp' => [$headers['webhook-timestamp']],
            'webhook_signature' => [$headers['webhook-signature']],
        ], $body, $token));
    }

    public function testAnyBodyByteChangeInvalidatesTheSignature(): void
    {
        [$headers, $body, $token] = $this->vector();

        self::assertFalse(YSHelcimWebhookVerifier::verify($headers, $body . "\n", $token));
    }

    /** @dataProvider invalidVectorProvider */
    public function testMalformedExpiredAndFutureVectorsFailClosed(callable $mutate): void
    {
        [$headers, $body, $token] = $this->vector();
        [$headers, $body, $token] = $mutate($headers, $body, $token);

        self::assertFalse(YSHelcimWebhookVerifier::verify($headers, $body, $token));
    }

    /** @return array<string,array{callable}> */
    public static function invalidVectorProvider(): array
    {
        return [
            'invalid verifier base64' => [static fn (array $h, string $b): array => [$h, $b, '***']],
            'empty verifier key' => [static fn (array $h, string $b): array => [$h, $b, base64_encode('')]],
            'missing id' => [static function (array $h, string $b, string $t): array { unset($h['webhook-id']); return [$h, $b, $t]; }],
            'nonnumeric timestamp' => [static function (array $h, string $b, string $t): array { $h['webhook-timestamp'] = 'soon'; return [$h, $b, $t]; }],
            'stale timestamp' => [static function (array $h, string $b, string $t): array { $h['webhook-timestamp'] = (string) (time() - 301); return [$h, $b, $t]; }],
            'future timestamp' => [static function (array $h, string $b, string $t): array { $h['webhook-timestamp'] = (string) (time() + 301); return [$h, $b, $t]; }],
            'missing signature' => [static function (array $h, string $b, string $t): array { unset($h['webhook-signature']); return [$h, $b, $t]; }],
            'empty signature candidate' => [static function (array $h, string $b, string $t): array { $h['webhook-signature'] = 'v1,'; return [$h, $b, $t]; }],
        ];
    }

    /** @return array{array<string,string>,string,string} */
    private function vector(): array
    {
        $body = '{"type":"cardTransaction","id":"51177061"}';
        $timestamp = (string) time();
        $key = 'fixed-verifier-key';
        $signature = base64_encode(hash_hmac(
            'sha256',
            'event-fixed-2.' . $timestamp . '.' . $body,
            $key,
            true
        ));
        return [[
            'webhook-id' => 'event-fixed-2',
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => 'v1,' . $signature,
        ], $body, base64_encode($key)];
    }
}
