<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundTransactionStatus;

final class RefundTransactionStatusTest extends TestCase
{
    private function context(array $changes = []): array
    {
        return array_replace([
            'order_id' => 42, 'transaction_id' => 60, 'gateway' => 'ys_helcim',
            'payment_mode' => 'test', 'currency' => 'USD', 'status' => 'succeeded',
            'transaction_type' => 'charge', 'vendor_transaction_id' => '800001',
            'transaction_total' => 12300, 'remaining_refundable' => 12300,
        ], $changes);
    }

    private function source(array $changes = []): array
    {
        return array_replace([
            'transactionId' => 800001, 'status' => 'APPROVED', 'currency' => 'USD',
            'amount' => '123.00', 'type' => 'purchase', 'cardBatchId' => 900001,
            'cardToken' => 'private-card-token', 'cardHolderName' => 'Private name',
            'invoiceNumber' => 'private-invoice',
        ], $changes);
    }

    #[DataProvider('batchCases')]
    public function testReadsOnlyTheStoredSourceAndBatchAndProjectsSafeFields(bool $closed, int $remaining, string $mode): void
    {
        $reads = [];
        $service = new YSHelcimRefundTransactionStatus(
            fn (int $id): array => $this->context(['remaining_refundable' => $remaining]),
            static function (string $gateway, string $mode): array {
                self::assertSame('ys_helcim', $gateway);
                self::assertSame('test', $mode);
                return ['current_mode' => 'test', 'api_token' => 'private-api-token'];
            },
            function (string $endpoint, string $token) use (&$reads, $closed): array {
                $reads[] = $endpoint;
                self::assertSame('private-api-token', $token);
                return ['data' => str_starts_with($endpoint, 'card-transactions/')
                    ? $this->source() : ['id' => 900001, 'closed' => $closed]];
            }
        );
        $result = $service->load(42, 60);
        self::assertSame(['card-transactions/800001', 'card-batches/900001'], $reads);
        self::assertSame([
            'order_id' => 42, 'transaction_id' => 60, 'provider_id' => '800001',
            'provider_status' => 'APPROVED', 'amount' => 12300, 'currency' => 'USD',
            'local_remaining' => $remaining, 'batch_state' => $closed ? 'closed' : 'open', 'refund_mode' => $mode,
        ], $result);
        self::assertStringNotContainsString('private-', json_encode($result));
    }

    public static function batchCases(): array
    {
        return [[true, 12300, 'refund'], [true, 3000, 'refund'], [false, 12300, 'full_void'], [false, 3000, 'unavailable'], [true, 0, 'unavailable']];
    }

    public function testRejectsCrossOrderBeforeCredentialsOrProviderRequests(): void
    {
        $service = new YSHelcimRefundTransactionStatus(
            fn (): array => $this->context(['order_id' => 99]),
            static function (): never { self::fail('Credentials must not be accessed.'); },
            static function (): never { self::fail('Provider must not be accessed.'); }
        );
        self::assertSame('ys_helcim_transaction_context', $service->load(42, 60)->get_error_code());
    }

    public function testModeMismatchDoesNotReadAProviderAccount(): void
    {
        $service = new YSHelcimRefundTransactionStatus(
            fn (): array => $this->context(),
            static fn (): array => ['current_mode' => 'live', 'api_token' => 'private-token'],
            static function (): never { self::fail('No account query allowed.'); }
        );
        self::assertSame('ys_helcim_transaction_credential', $service->load(42, 60)->get_error_code());
    }

    #[DataProvider('invalidSources')]
    public function testDoesNotPresentAnUnverifiedSourceAsRefundable(array $changes, string $reason): void
    {
        $calls = 0;
        $service = new YSHelcimRefundTransactionStatus(
            fn (): array => $this->context(),
            static fn (): array => ['current_mode' => 'test', 'api_token' => 'private-token'],
            function () use (&$calls, $changes): array { ++$calls; return $this->source($changes); }
        );
        self::assertSame('ys_helcim_transaction_' . $reason, $service->load(42, 60)->get_error_code());
        self::assertSame(1, $calls);
    }

    public static function invalidSources(): array
    {
        return [
            [['transactionId' => 800002], 'mismatch'], [['amount' => '122.00'], 'mismatch'],
            [['currency' => 'CAD'], 'mismatch'], [['type' => 'refund'], 'mismatch'],
            [['cardBatchId' => null], 'response'], [['transactionId' => true], 'mismatch'],
        ];
    }

    #[DataProvider('providerFailures')]
    public function testProviderErrorsAreSafeAndActionable(int $http, string $reason): void
    {
        $service = new YSHelcimRefundTransactionStatus(
            fn (): array => $this->context(),
            static fn (): array => ['current_mode' => 'test', 'api_token' => 'private-token'],
            static fn (): \WP_Error => new \WP_Error('raw', 'private-token private-card', ['http_code' => $http])
        );
        $result = $service->load(42, 60);
        self::assertSame('ys_helcim_transaction_' . $reason, $result->get_error_code());
        self::assertStringNotContainsString('private-', $result->get_error_message());
    }

    public static function providerFailures(): array
    {
        return [[401, 'credential'], [403, 'credential'], [404, 'missing'], [429, 'rate_limit'], [500, 'unavailable'], [0, 'unavailable']];
    }

    public function testNonApprovedPaymentIsDisplayedButCannotBeRefunded(): void
    {
        $calls = 0;
        $service = new YSHelcimRefundTransactionStatus(
            fn (): array => $this->context(),
            static fn (): array => ['current_mode' => 'test', 'api_token' => 'private-token'],
            function () use (&$calls): array { ++$calls; return $this->source(['status' => 'VOIDED']); }
        );
        $result = $service->load(42, 60);
        self::assertSame('VOIDED', $result['provider_status']);
        self::assertSame('unavailable', $result['refund_mode']);
        self::assertSame(1, $calls);
    }

    public function testWrongBatchOrNonBooleanClosedNeverEnablesRefunds(): void
    {
        foreach ([['id' => 900002, 'closed' => true], ['id' => 900001, 'closed' => 'false'], []] as $batch) {
            $service = new YSHelcimRefundTransactionStatus(
                fn (): array => $this->context(),
                static fn (): array => ['current_mode' => 'test', 'api_token' => 'private-token'],
                fn (string $endpoint): array => str_starts_with($endpoint, 'card-transactions/') ? $this->source() : $batch
            );
            self::assertSame('ys_helcim_transaction_response', $service->load(42, 60)->get_error_code());
        }
    }
}
