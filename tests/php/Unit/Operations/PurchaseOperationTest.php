<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Operations;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimIdempotency;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationScope;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimPurchaseOperation;

final class PurchaseOperationTest extends TestCase
{
    private const OPERATION_UUID = '00000000-0000-4000-8000-000000000123';

    private const ATTEMPT_DIGEST = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testServerOwnedTransactionIdentityBuildsStableUniqueScope(): void
    {
        $first = YSHelcimPurchaseOperation::fromTransaction($this->transaction());
        $same = YSHelcimPurchaseOperation::fromTransaction($this->transaction());
        $differentTransaction = YSHelcimPurchaseOperation::fromTransaction(
            $this->transaction(['transaction_id' => 21, 'transaction_uuid' => 'fc-transaction-124'])
        );
        $sameTransactionWithDriftedContext = YSHelcimPurchaseOperation::fromTransaction(
            $this->transaction(['gateway' => 'ys_helcim_js', 'order_id' => 999, 'transaction_uuid' => 'drifted-uuid'])
        );

        self::assertInstanceOf(YSHelcimPurchaseOperation::class, $first);
        self::assertInstanceOf(YSHelcimPurchaseOperation::class, $same);
        self::assertInstanceOf(YSHelcimPurchaseOperation::class, $differentTransaction);
        self::assertInstanceOf(YSHelcimPurchaseOperation::class, $sameTransactionWithDriftedContext);
        self::assertSame($first->scopeKey(), $same->scopeKey());
        self::assertSame(
            $first->scopeKey(),
            $sameTransactionWithDriftedContext->scopeKey(),
            'Any secondary-field drift must conflict on the same server-owned transaction ID lock.'
        );
        self::assertNotSame($first->scopeKey(), $differentTransaction->scopeKey());
        self::assertMatchesRegularExpression('/\Apurchase-transaction-v1:[a-f0-9]{64}\z/', $first->scopeKey());
    }

    public function testRepositoryRecordPersistsStableProviderKeyWithoutCardToken(): void
    {
        $operation = YSHelcimPurchaseOperation::fromTransaction($this->transaction());
        self::assertInstanceOf(YSHelcimPurchaseOperation::class, $operation);

        $first = $operation->repositoryRecord(self::OPERATION_UUID, self::ATTEMPT_DIGEST);
        $second = $operation->repositoryRecord(self::OPERATION_UUID, self::ATTEMPT_DIGEST);
        $differentTokenDigest = $operation->repositoryRecord(
            self::OPERATION_UUID,
            'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
        );

        self::assertIsArray($first);
        self::assertSame($first, $second);
        self::assertIsArray($differentTokenDigest);
        self::assertSame(self::OPERATION_UUID, $first['operation_uuid']);
        self::assertSame(
            YSHelcimIdempotency::generate('purchase', 'fc-transaction-123', 2100, 'test', self::OPERATION_UUID),
            $first['idempotency_key']
        );
        self::assertTrue(YSHelcimIdempotency::isValid($first['idempotency_key']));
        self::assertGreaterThanOrEqual(25, strlen($first['idempotency_key']));
        self::assertLessThanOrEqual(36, strlen($first['idempotency_key']));
        self::assertSame('purchase', $first['operation_type']);
        self::assertSame(
            $first['idempotency_key'],
            $differentTokenDigest['idempotency_key'],
            'The card-token digest must not influence the provider idempotency key.'
        );
        self::assertNotSame($first['request_fingerprint'], $differentTokenDigest['request_fingerprint']);
        self::assertSame(YSHelcimOperationScope::fromBusinessKey($operation->scopeKey()), YSHelcimOperationScope::fromBusinessKey($first['scope_key']));
        self::assertArrayNotHasKey('card_token', $first);
        self::assertArrayNotHasKey('encrypted_material', $first);
        self::assertStringNotContainsString('card-token-secret', json_encode($first, JSON_THROW_ON_ERROR));
    }

    public function testPersistedRowMustMatchEveryImmutablePurchaseFieldAndStableKey(): void
    {
        $operation = YSHelcimPurchaseOperation::fromTransaction($this->transaction());
        self::assertInstanceOf(YSHelcimPurchaseOperation::class, $operation);
        $row = $operation->repositoryRecord(self::OPERATION_UUID, self::ATTEMPT_DIGEST);
        self::assertIsArray($row);
        $row['scope_key'] = YSHelcimOperationScope::fromBusinessKey($row['scope_key']);

        self::assertTrue($operation->matchesRow($row, self::ATTEMPT_DIGEST));
        self::assertFalse(
            $operation->matchesRow($row, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')
        );

        foreach (['gateway', 'order_id', 'transaction_id', 'transaction_uuid', 'amount', 'currency', 'payment_mode', 'request_fingerprint', 'idempotency_key'] as $field) {
            $changed = $row;
            $changed[$field] = $field === 'amount' ? 2200 : 'changed';
            self::assertFalse($operation->matchesRow($changed, self::ATTEMPT_DIGEST), $field . ' must be immutable');
        }
    }

    public function testAttemptDigestMustBeAFullLowercaseSha256Value(): void
    {
        $operation = YSHelcimPurchaseOperation::fromTransaction($this->transaction());
        self::assertInstanceOf(YSHelcimPurchaseOperation::class, $operation);

        foreach (['', str_repeat('a', 63), str_repeat('A', 64), str_repeat('z', 64)] as $invalidDigest) {
            $result = $operation->repositoryRecord(self::OPERATION_UUID, $invalidDigest);
            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame('ys_helcim_invalid_purchase', $result->get_error_code());
        }
    }

    #[DataProvider('invalidTransactions')]
    public function testInvalidOrClientControlledIdentityIsRejected(array $transaction): void
    {
        $result = YSHelcimPurchaseOperation::fromTransaction($transaction);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_invalid_purchase', $result->get_error_code());
    }

    public static function invalidTransactions(): iterable
    {
        $valid = [
            'gateway' => 'ys_helcim',
            'order_id' => 10,
            'transaction_id' => 20,
            'transaction_uuid' => 'fc-transaction-123',
            'amount' => 2100,
            'currency' => 'USD',
            'payment_mode' => 'test',
        ];

        yield 'unsupported gateway' => [array_replace($valid, ['gateway' => 'stripe'])];
        yield 'numeric string order id' => [array_replace($valid, ['order_id' => '10'])];
        yield 'missing transaction id' => [array_diff_key($valid, ['transaction_id' => true])];
        yield 'blank transaction uuid' => [array_replace($valid, ['transaction_uuid' => ' '])];
        yield 'oversized transaction uuid' => [array_replace($valid, ['transaction_uuid' => str_repeat('x', 192)])];
        yield 'non-positive amount' => [array_replace($valid, ['amount' => 0])];
        yield 'unsupported currency' => [array_replace($valid, ['currency' => 'TWD'])];
        yield 'unsupported mode' => [array_replace($valid, ['payment_mode' => 'production'])];
        yield 'unexpected client token' => [array_replace($valid, ['card_token' => 'card-token-secret'])];
    }

    /** @return array<string, mixed> */
    private function transaction(array $changes = []): array
    {
        return array_replace(
            [
                'gateway' => 'ys_helcim',
                'order_id' => 10,
                'transaction_id' => 20,
                'transaction_uuid' => 'fc-transaction-123',
                'amount' => 2100,
                'currency' => 'USD',
                'payment_mode' => 'test',
            ],
            $changes
        );
    }
}
