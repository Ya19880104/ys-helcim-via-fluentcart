<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Checkout;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Checkout\YSHelcimPaymentStatusService;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimPurchaseConfirmationToken;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimPurchaseOperation;
use YangSheep\Helcim\FluentCart\Tests\Doubles\FakeWpdb;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class PaymentStatusServiceTest extends TestCase
{
    private const OPERATION_UUID = '00000000-0000-4000-8000-000000000961';
    private const TRANSACTION_UUID = 'fc-status-transaction';

    private YSHelcimOperationRepository $repository;
    private YSHelcimPurchaseConfirmationToken $tokens;
    private YSHelcimPaymentStatusService $service;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/Doubles/InlineFluentCart.php';
        require_once dirname(__DIR__, 2) . '/Doubles/InlineWordPress.php';

        OrderTransaction::reset();
        Order::reset();
        $this->repository = new YSHelcimOperationRepository(
            new FakeWpdb(),
            static fn (): string => '2026-07-21 06:00:00'
        );
        $this->tokens = new YSHelcimPurchaseConfirmationToken(
            static fn (): int => 1784613600,
            static fn (): string => str_repeat('k', 64),
            YSHelcimPaymentStatusService::STATUS_TOKEN_LIFETIME_SECONDS
        );
        $this->service = new YSHelcimPaymentStatusService($this->repository, $this->tokens);

        Order::seed([
            'id' => 10,
            'uuid' => 'status-order-uuid',
            'status' => 'on-hold',
            'payment_status' => 'pending',
            'total_amount' => 2100,
            'total_paid' => 0,
        ]);
        $this->seedTransaction(Status::TRANSACTION_PENDING, null);
    }

    public function testForeignOrMissingTokenRevealsNothing(): void
    {
        $foreign = $this->tokens->issue('another-transaction', 99);
        self::assertIsString($foreign);

        foreach (['', 'garbage', $foreign] as $token) {
            $result = $this->service->status(self::TRANSACTION_UUID, $token);
            self::assertSame(403, $result['http']);
            self::assertSame('status_session_invalid', $result['body']['code']);
            self::assertFalse($result['body']['retry_allowed']);
            self::assertArrayNotHasKey('redirect_url', $result['body']);
        }

        self::assertSame(403, $this->service->status('missing-transaction', $this->token())['http']);
    }

    public function testNoRecordedAttemptMeansNoChargeAndAllowsRetry(): void
    {
        $result = $this->service->status(self::TRANSACTION_UUID, $this->token());

        self::assertSame(200, $result['http']);
        self::assertSame('failed', $result['body']['status']);
        self::assertTrue($result['body']['retry_allowed']);
        self::assertSame('no_payment_attempt', $result['body']['code']);
    }

    public function testInFlightAttemptKeepsTheShopperWaiting(): void
    {
        $this->seedAttempt();

        $result = $this->service->status(self::TRANSACTION_UUID, $this->token());

        self::assertSame('pending', $result['body']['status']);
        self::assertSame('verifying', $result['body']['code']);
        self::assertArrayNotHasKey('retry_allowed', $result['body']);
    }

    public function testRemoteApprovalStillBindingLocallyReportsFinalizing(): void
    {
        // Production incident: the webhook proved the payment while the browser's own
        // confirmation was still running; the order is paid moments later.
        $this->seedAttempt();
        self::assertTrue($this->repository->transitionRemote(
            self::OPERATION_UUID,
            'processing',
            'succeeded',
            ['vendor_transaction_id' => '84938399']
        ));

        $result = $this->service->status(self::TRANSACTION_UUID, $this->token());

        self::assertSame('pending', $result['body']['status']);
        self::assertSame('finalizing', $result['body']['code']);
    }

    public function testPaidTransactionRedirectsToTheReceipt(): void
    {
        $this->seedTransaction(Status::TRANSACTION_SUCCEEDED, '84938399');

        $result = $this->service->status(self::TRANSACTION_UUID, $this->token());

        self::assertSame(200, $result['http']);
        self::assertSame('success', $result['body']['status']);
        self::assertSame('https://shop.test/receipt/' . self::TRANSACTION_UUID, $result['body']['redirect_url']);
        self::assertSame('status-order-uuid', $result['body']['order']['uuid']);
    }

    public function testSucceededTransactionWithoutProviderIdIsNeverTreatedAsPaid(): void
    {
        $this->seedTransaction(Status::TRANSACTION_SUCCEEDED, '');

        $result = $this->service->status(self::TRANSACTION_UUID, $this->token());

        self::assertSame('pending', $result['body']['status']);
        self::assertArrayNotHasKey('redirect_url', $result['body']);
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function noChargeOutcomes(): array
    {
        return [
            'declined card' => ['declined', 'payment_declined'],
            'never-sent failure' => ['failed', 'payment_not_completed'],
            'closed expired window' => ['canceled', 'payment_window_expired'],
        ];
    }

    #[DataProvider('noChargeOutcomes')]
    public function testDefinitiveNoChargeOutcomesAllowAnotherTry(string $remoteStatus, string $code): void
    {
        $this->seedAttempt();
        $changes = 'declined' === $remoteStatus ? ['error_code' => 'provider_declined'] : [];
        self::assertTrue($this->repository->transitionRemote(self::OPERATION_UUID, 'processing', $remoteStatus, $changes));

        $result = $this->service->status(self::TRANSACTION_UUID, $this->token());

        self::assertSame('failed', $result['body']['status']);
        self::assertTrue($result['body']['retry_allowed']);
        self::assertSame($code, $result['body']['code']);
    }

    public function testStatusTokensOutliveTheHostedWindowButNotForever(): void
    {
        $now = 1784613600;
        $tokens = new YSHelcimPurchaseConfirmationToken(
            static function () use (&$now): int {
                return $now;
            },
            static fn (): string => str_repeat('k', 64),
            YSHelcimPaymentStatusService::STATUS_TOKEN_LIFETIME_SECONDS
        );
        $token = $tokens->issue(self::TRANSACTION_UUID, 20);
        self::assertIsString($token);

        $now += 3600;
        self::assertTrue($tokens->verify($token, self::TRANSACTION_UUID, 20), 'Valid after a full hour in the payment window.');
        $now += 3601;
        self::assertFalse($tokens->verify($token, self::TRANSACTION_UUID, 20));
    }

    public function testStatusTokensAreDomainSeparatedFromConfirmationTokens(): void
    {
        $confirmation = (new YSHelcimPurchaseConfirmationToken())->issue(self::TRANSACTION_UUID, 20);
        self::assertIsString($confirmation);

        self::assertFalse(YSHelcimPaymentStatusService::statusTokens()->verify($confirmation, self::TRANSACTION_UUID, 20));
    }

    private function token(): string
    {
        $token = $this->tokens->issue(self::TRANSACTION_UUID, 20);
        self::assertIsString($token);
        return $token;
    }

    private function seedTransaction(string $status, ?string $vendorChargeId): void
    {
        OrderTransaction::seed([
            'id' => 20,
            'uuid' => self::TRANSACTION_UUID,
            'order_id' => 10,
            'payment_method' => 'ys_helcim_js',
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'status' => $status,
            'total' => 2100,
            'currency' => 'USD',
            'payment_mode' => 'live',
            'vendor_charge_id' => $vendorChargeId,
            'meta' => [],
        ]);
    }

    private function seedAttempt(): void
    {
        $purchase = YSHelcimPurchaseOperation::fromTransaction([
            'gateway' => 'ys_helcim_js',
            'order_id' => 10,
            'transaction_id' => 20,
            'transaction_uuid' => self::TRANSACTION_UUID,
            'amount' => 2100,
            'currency' => 'USD',
            'payment_mode' => 'live',
        ]);
        self::assertInstanceOf(YSHelcimPurchaseOperation::class, $purchase);
        $record = $purchase->repositoryRecord(self::OPERATION_UUID, hash('sha256', 'status-attempt'));
        self::assertIsArray($record);
        self::assertIsArray($this->repository->create($record));
        self::assertTrue($this->repository->claimRemoteProcessing(self::OPERATION_UUID));
    }
}
