<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Webhook\YSHelcimWebhookHandler;

final class WebhookHandlerTest extends TestCase
{
    public function testVerifiedEventLooksUpTheExactTransactionAndDelegatesByChannel(): void
    {
        $calls = [];
        $handler = $this->handler(
            lookup: static function (string $id, string $token) use (&$calls): array {
                $calls[] = ['lookup', $id, $token];
                return ['data' => [
                    'transactionId' => 51177061,
                    'status' => 'APPROVED',
                    'type' => 'purchase',
                    'amount' => 21.00,
                    'currency' => 'USD',
                ]];
            },
            reconcile: static function (array $proof, string $id, array $bindings) use (&$calls): array {
                $calls[] = ['reconcile', $proof['transactionId'], $id, $bindings];
                return ['code' => 200, 'message' => 'reconciled'];
            }
        );

        $result = $handler->handle('{"type":"cardTransaction","id":"51177061"}', ['x' => 'y']);

        self::assertSame(200, $result['status']);
        self::assertSame(['message' => 'reconciled'], $result['body']);
        self::assertSame([
            ['lookup', '51177061', 'api-secret'],
            ['reconcile', 51177061, '51177061', [['gateway' => 'ys_helcim_js', 'mode' => 'test']]],
        ], $calls);
    }

    public function testProviderLookupMustProveTheExactWebhookTransactionId(): void
    {
        $reconcileCalls = 0;
        $handler = $this->handler(
            lookup: static fn (): array => ['transactionId' => '51177062'],
            reconcile: static function () use (&$reconcileCalls): array {
                ++$reconcileCalls;
                return ['code' => 200, 'message' => 'wrong'];
            }
        );

        $result = $handler->handle('{"type":"cardTransaction","id":"51177061"}', []);

        self::assertSame(400, $result['status']);
        self::assertSame('transaction proof mismatch', $result['body']['message']);
        self::assertSame(0, $reconcileCalls);
    }

    public function testLookupAndLocalPersistenceFailuresReturnRetryableServerErrorsWithoutLeakingSecrets(): void
    {
        $lookupFailure = $this->handler(
            lookup: static fn (): \WP_Error => new \WP_Error('transport', 'api-secret timed out'),
            reconcile: static fn (): array => ['code' => 200, 'message' => 'unused']
        );
        $lookupResult = $lookupFailure->handle('{"type":"cardTransaction","id":"51177061"}', []);
        self::assertSame(502, $lookupResult['status']);
        self::assertStringNotContainsString('api-secret', json_encode($lookupResult));

        $saveFailure = $this->handler(
            lookup: static fn (): array => ['transactionId' => '51177061'],
            reconcile: static fn (): array => ['code' => 500, 'message' => 'local persistence failed']
        );
        $saveResult = $saveFailure->handle('{"type":"cardTransaction","id":"51177061"}', []);
        self::assertSame(500, $saveResult['status']);
        self::assertSame('local persistence failed', $saveResult['body']['message']);
    }

    public function testMissingCredentialsAndInvalidSignatureFailClosedBeforeLookup(): void
    {
        $lookups = 0;
        $missing = new YSHelcimWebhookHandler(
            static fn (): array => [],
            static fn (): bool => true,
            static function () use (&$lookups): array { ++$lookups; return []; },
            static fn (): array => ['code' => 200, 'message' => 'unused'],
            static fn (): array => ['gateway' => 'ys_helcim_js', 'mode' => 'test']
        );
        self::assertSame(503, $missing->handle('{}', [])['status']);

        $badSignature = new YSHelcimWebhookHandler(
            static fn (): array => [['gateway' => 'ys_helcim', 'mode' => 'test', 'verifier_token' => 'verify-secret', 'api_token' => 'api-secret']],
            static fn (): bool => false,
            static function () use (&$lookups): array { ++$lookups; return []; },
            static fn (): array => ['code' => 200, 'message' => 'unused'],
            static fn (): array => ['gateway' => 'ys_helcim', 'mode' => 'test']
        );
        self::assertSame(401, $badSignature->handle('{}', [])['status']);
        self::assertSame(0, $lookups);
    }

    public function testMalformedInvalidAndUnrelatedEventsAreBounded(): void
    {
        $handler = $this->handler();

        self::assertSame(400, $handler->handle('{', [])['status']);
        self::assertSame(400, $handler->handle('{"type":"cardTransaction","id":"abc123"}', [])['status']);
        self::assertSame(200, $handler->handle('{"type":"customer","id":"51177061"}', [])['status']);
        self::assertSame(413, $handler->handle(str_repeat('x', 1048577), [])['status']);
    }

    public function testVerifiedNonPurchaseCardTransactionIsAcknowledgedWithoutPurchaseReconciliation(): void
    {
        $reconciles = 0;
        $handler = $this->handler(
            lookup: static fn (): array => ['transactionId' => '51177061', 'type' => 'refund'],
            reconcile: static function () use (&$reconciles): array {
                ++$reconciles;
                return ['code' => 422, 'message' => 'wrong'];
            }
        );

        $result = $handler->handle('{"type":"cardTransaction","id":"51177061"}', []);

        self::assertSame(['status' => 200, 'body' => ['message' => 'ignored']], $result);
        self::assertSame(0, $reconciles);
    }

    public function testUnrelatedOperationIsAcknowledgedButStorageFailureIsRetryable(): void
    {
        $unrelated = new YSHelcimWebhookHandler(
            static fn (): array => [['gateway' => 'ys_helcim_js', 'mode' => 'test', 'verifier_token' => 'verify-secret', 'api_token' => 'api-secret']],
            static fn (): bool => true,
            static fn (): array => ['transactionId' => '51177061', 'type' => 'purchase'],
            static fn (): array => ['code' => 200, 'message' => 'wrong'],
            static fn (): array => ['status' => 'unrelated']
        );
        $unavailable = new YSHelcimWebhookHandler(
            static fn (): array => [['gateway' => 'ys_helcim_js', 'mode' => 'test', 'verifier_token' => 'verify-secret', 'api_token' => 'api-secret']],
            static fn (): bool => true,
            static fn (): array => ['transactionId' => '51177061', 'type' => 'purchase'],
            static fn (): array => ['code' => 200, 'message' => 'wrong'],
            static fn (): array => ['status' => 'unavailable']
        );

        self::assertSame(200, $unrelated->handle('{"type":"cardTransaction","id":"51177061"}', [])['status']);
        self::assertSame(503, $unavailable->handle('{"type":"cardTransaction","id":"51177061"}', [])['status']);
    }

    public function testCompletedReceiptPreventsProviderAndLocalReplay(): void
    {
        $lookups = 0;
        $reconciles = 0;
        $receipts = [];
        $handler = new YSHelcimWebhookHandler(
            static fn (): array => [['gateway' => 'ys_helcim_js', 'mode' => 'test', 'verifier_token' => 'verify-secret', 'api_token' => 'api-secret']],
            static fn (): bool => true,
            static function () use (&$lookups): array { ++$lookups; return ['transactionId' => '51177061', 'type' => 'purchase']; },
            static function () use (&$reconciles): array { ++$reconciles; return ['code' => 200, 'message' => 'reconciled']; },
            static fn (): array => ['status' => 'matched', 'binding' => ['gateway' => 'ys_helcim_js', 'mode' => 'test']],
            static function (string $key) use (&$receipts): bool { return isset($receipts[$key]); },
            static function (string $key) use (&$receipts): bool { $receipts[$key] = true; return true; }
        );
        $headers = ['Webhook-Id' => 'evt-production-1'];
        $body = '{"type":"cardTransaction","id":"51177061"}';

        self::assertSame(200, $handler->handle($body, $headers)['status']);
        self::assertSame(['status' => 200, 'body' => ['message' => 'duplicate']], $handler->handle($body, $headers));
        self::assertSame(1, $lookups);
        self::assertSame(1, $reconciles);
        self::assertCount(1, $receipts);
    }

    public function testWordPressRestCanonicalizedWebhookIdStillDeduplicatesDelivery(): void
    {
        $lookups = 0;
        $receipts = [];
        $handler = new YSHelcimWebhookHandler(
            static fn (): array => [['gateway' => 'ys_helcim_js', 'mode' => 'test', 'verifier_token' => 'verify-secret', 'api_token' => 'api-secret']],
            static fn (): bool => true,
            static function () use (&$lookups): array { ++$lookups; return ['transactionId' => '51177061', 'type' => 'purchase']; },
            static fn (): array => ['code' => 200, 'message' => 'reconciled'],
            static fn (): array => ['status' => 'matched', 'binding' => ['gateway' => 'ys_helcim_js', 'mode' => 'test']],
            static function (string $key) use (&$receipts): bool { return isset($receipts[$key]); },
            static function (string $key) use (&$receipts): bool { $receipts[$key] = true; return true; }
        );
        $headers = ['webhook_id' => ['evt-wordpress-rest-1']];
        $body = '{"type":"cardTransaction","id":"51177061"}';

        self::assertSame(200, $handler->handle($body, $headers)['status']);
        self::assertSame('duplicate', $handler->handle($body, $headers)['body']['message']);
        self::assertSame(1, $lookups);
    }

    public function testSuccessfulReconciliationIsRetryableUntilItsReceiptIsDurable(): void
    {
        $handler = new YSHelcimWebhookHandler(
            static fn (): array => [['gateway' => 'ys_helcim_js', 'mode' => 'test', 'verifier_token' => 'verify-secret', 'api_token' => 'api-secret']],
            static fn (): bool => true,
            static fn (): array => ['transactionId' => '51177061', 'type' => 'purchase'],
            static fn (): array => ['code' => 200, 'message' => 'reconciled'],
            static fn (): array => ['status' => 'matched', 'binding' => ['gateway' => 'ys_helcim_js', 'mode' => 'test']],
            static fn (): bool => false,
            static fn (): \WP_Error => new \WP_Error('db_failed', 'secret detail')
        );

        $result = $handler->handle(
            '{"type":"cardTransaction","id":"51177061"}',
            ['webhook-id' => 'evt-production-2']
        );

        self::assertSame(['status' => 503, 'body' => ['message' => 'webhook receipt storage unavailable']], $result);
    }

    public function testPartialAccountLookupFailureCannotAcknowledgeAnUnrelatedNumericCollision(): void
    {
        $handler = new YSHelcimWebhookHandler(
            static fn (): array => [
                ['gateway' => 'ys_helcim_js', 'mode' => 'test', 'verifier_token' => 'same-verifier', 'api_token' => 'account-a'],
                ['gateway' => 'ys_helcim_js', 'mode' => 'live', 'verifier_token' => 'same-verifier', 'api_token' => 'account-b'],
            ],
            static fn (): bool => true,
            static fn (string $id, string $token): array|\WP_Error => $token === 'account-a'
                ? ['transactionId' => $id, 'type' => 'purchase', 'invoiceNumber' => 'not-ours']
                : new \WP_Error('transport', 'offline'),
            static fn (): array => ['code' => 200, 'message' => 'wrong'],
            static fn (): array => ['status' => 'unrelated']
        );

        $result = $handler->handle('{"type":"cardTransaction","id":"51177061"}', []);

        self::assertSame(502, $result['status']);
        self::assertSame('transaction lookup incomplete', $result['body']['message']);
    }

    public function testSharedCredentialsAreLookedUpOnceAndKeepBothEligibleGateways(): void
    {
        $lookups = 0;
        $gateways = [];
        $handler = new YSHelcimWebhookHandler(
            static fn (): array => [
                ['gateway' => 'ys_helcim', 'mode' => 'live', 'verifier_token' => 'verify-secret', 'api_token' => 'api-secret'],
                ['gateway' => 'ys_helcim_js', 'mode' => 'test', 'verifier_token' => 'verify-secret', 'api_token' => 'api-secret'],
            ],
            static fn (): bool => true,
            static function () use (&$lookups): array { ++$lookups; return ['transactionId' => '51177061']; },
            static function (array $proof, string $id, array $eligible) use (&$gateways): array {
                unset($proof, $id);
                $gateways = $eligible;
                return ['code' => 200, 'message' => 'ok'];
            },
            static fn (): array => ['status' => 'matched', 'binding' => ['gateway' => 'ys_helcim_js', 'mode' => 'test']]
        );

        $result = $handler->handle('{"type":"cardTransaction","id":"51177061"}', []);

        self::assertSame(200, $result['status']);
        self::assertSame(1, $lookups);
        self::assertSame([
            ['gateway' => 'ys_helcim', 'mode' => 'live'],
            ['gateway' => 'ys_helcim_js', 'mode' => 'test'],
        ], $gateways);
    }

    public function testExactOperationBindingDisambiguatesTwoAccountsWithTheSameNumericId(): void
    {
        $reconciles = 0;
        $selectedBindings = [];
        $handler = new YSHelcimWebhookHandler(
            static fn (): array => [
                ['gateway' => 'ys_helcim', 'mode' => 'live', 'verifier_token' => 'same-verifier', 'api_token' => 'account-a'],
                ['gateway' => 'ys_helcim_js', 'mode' => 'test', 'verifier_token' => 'same-verifier', 'api_token' => 'account-b'],
            ],
            static fn (): bool => true,
            static fn (): array => ['transactionId' => '51177061'],
            static function (array $proof, string $id, array $bindings) use (&$reconciles, &$selectedBindings): array {
                unset($proof, $id);
                ++$reconciles;
                $selectedBindings = $bindings;
                return ['code' => 200, 'message' => 'ok'];
            },
            static fn (): array => ['status' => 'matched', 'binding' => ['gateway' => 'ys_helcim_js', 'mode' => 'test']]
        );

        $result = $handler->handle('{"type":"cardTransaction","id":"51177061"}', []);

        self::assertSame(200, $result['status']);
        self::assertSame(1, $reconciles);
        self::assertSame([['gateway' => 'ys_helcim_js', 'mode' => 'test']], $selectedBindings);
    }

    public function testDuplicateDistinctCredentialGroupsForOneBindingRemainAmbiguous(): void
    {
        $reconciles = 0;
        $handler = new YSHelcimWebhookHandler(
            static fn (): array => [
                ['gateway' => 'ys_helcim_js', 'mode' => 'test', 'verifier_token' => 'same-verifier', 'api_token' => 'account-a'],
                ['gateway' => 'ys_helcim_js', 'mode' => 'test', 'verifier_token' => 'same-verifier', 'api_token' => 'account-b'],
            ],
            static fn (): bool => true,
            static fn (): array => ['transactionId' => '51177061'],
            static function () use (&$reconciles): array { ++$reconciles; return ['code' => 200, 'message' => 'wrong']; },
            static fn (): array => ['status' => 'matched', 'binding' => ['gateway' => 'ys_helcim_js', 'mode' => 'test']]
        );

        $result = $handler->handle('{"type":"cardTransaction","id":"51177061"}', []);

        self::assertSame(409, $result['status']);
        self::assertSame(0, $reconciles);
    }

    private function handler(?callable $lookup = null, ?callable $reconcile = null): YSHelcimWebhookHandler
    {
        return new YSHelcimWebhookHandler(
            static fn (): array => [[
                'gateway' => 'ys_helcim_js',
                'mode' => 'test',
                'verifier_token' => 'verify-secret',
                'api_token' => 'api-secret',
            ]],
            static fn (array $headers, string $raw, string $secret): bool =>
                $secret === 'verify-secret' && is_array($headers) && $raw !== '',
            $lookup ?? static fn (): array => ['transactionId' => '51177061'],
            $reconcile ?? static fn (): array => ['code' => 200, 'message' => 'ok'],
            static fn (): array => ['status' => 'matched', 'binding' => ['gateway' => 'ys_helcim_js', 'mode' => 'test']]
        );
    }
}
