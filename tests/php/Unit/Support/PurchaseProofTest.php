<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Support\YSHelcimPurchaseProof;

final class PurchaseProofTest extends TestCase
{
    public function testCompleteMatchingPurchaseIsAccepted(): void
    {
        self::assertNull(YSHelcimPurchaseProof::failureReason(
            self::validResponse(),
            1050,
            'USD'
        ));
    }

    #[DataProvider('invalidIds')]
    public function testApprovedPurchaseWithoutStrictTransactionIdIsRejected(mixed $transactionId): void
    {
        $response = self::validResponse();
        $response['transactionId'] = $transactionId;

        self::assertSame(
            'invalid_transaction_id',
            YSHelcimPurchaseProof::failureReason($response, 1050, 'USD')
        );
    }

    public function testResponseIdMustMatchLookupIdWhenOneIsExpected(): void
    {
        self::assertSame(
            'transaction_id_mismatch',
            YSHelcimPurchaseProof::failureReason(self::validResponse(), 1050, 'USD', '51177062')
        );
    }

    public function testAmountCurrencyStatusAndTypeRemainFailClosed(): void
    {
        $cases = [
            [array_merge(self::validResponse(), ['status' => 'DECLINED']), 'status_not_approved'],
            [array_merge(self::validResponse(), ['type' => 'refund']), 'type_not_purchase'],
            [array_merge(self::validResponse(), ['amount' => '10.501']), 'amount_mismatch'],
            [array_merge(self::validResponse(), ['currency' => 'CAD']), 'currency_mismatch'],
        ];

        foreach ($cases as [$response, $reason]) {
            self::assertSame($reason, YSHelcimPurchaseProof::failureReason($response, 1050, 'USD'));
        }
    }

    public static function invalidIds(): iterable
    {
        yield 'missing' => [null];
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'mixed' => ['tx-51177061'];
        yield 'scientific' => ['1e3'];
        yield 'above platform max' => [(string) PHP_INT_MAX . '0'];
    }

    private static function validResponse(): array
    {
        return [
            'status' => 'APPROVED',
            'type' => 'purchase',
            'transactionId' => '51177061',
            'amount' => '10.50',
            'currency' => 'USD',
        ];
    }
}
