<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\HelcimPay;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsPurchaseRuntime;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPayConfirmationService;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPaySettings;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimPurchaseOperation;
use YangSheep\Helcim\FluentCart\Tests\Doubles\FakeWpdb;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class ConfirmationServiceTest extends TestCase
{
    private const OPERATION_UUID = '00000000-0000-4000-8000-000000000731';
    private const RAW_CONFIRM_TOKEN = 'raw-hosted-confirm-token-with-enough-entropy';
    private const SECRET_TOKEN = 'provider-one-time-secret';

    private YSHelcimOperationRepository $repository;
    private YSHelcimPayConfirmationService $service;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/Doubles/InlineFluentCart.php';
        require_once dirname(__DIR__, 2) . '/Doubles/InlineWordPress.php';
        OrderTransaction::reset();
        Order::reset();
        StatusHelper::reset();
        Helper::$encryptionAvailable = true;
        StoreSettings::$orderMode = 'test';
        BaseGatewaySettings::$settingsByClass[YSHelcimPaySettings::class] = [
            'test_api_token' => 'enc:test-api-secret',
        ];
        $this->repository = new YSHelcimOperationRepository(
            new FakeWpdb(),
            static fn (): string => '2026-07-21 00:00:00'
        );
        $this->seedModels();
        $this->seedProcessingOperation();

        $runtime = new YSHelcimJsPurchaseRuntime(
            settings: new YSHelcimPaySettings(),
            operations: $this->repository,
            api_request: static fn (): \WP_Error => new \WP_Error('must_not_call_provider', 'Must not call provider'),
            method_slug: 'ys_helcim',
            terminal_meta_keys: [
                'ys_helcim_checkout_token',
                'ys_helcim_secret_token_enc',
                'ys_helcim_card_token',
                'ys_helcim_operation_uuid',
                'ys_helcim_initialized_at',
            ]
        );
        $this->service = new YSHelcimPayConfirmationService($this->repository, $runtime);
    }

    public function testExactHashCorrelationProofAndOneTimeTokenApplyThePaymentOnce(): void
    {
        $event = $this->approvedEvent();
        $result = $this->service->confirm(
            'fc-hosted-transaction',
            self::OPERATION_UUID,
            self::RAW_CONFIRM_TOKEN,
            $event,
            $this->hash($event)
        );

        self::assertIsArray($result);
        self::assertSame('succeeded', $result['status']);
        self::assertSame('51177991', OrderTransaction::allRecords()[20]['vendor_charge_id']);
        self::assertSame('paid', Order::allRecords()[10]['payment_status']);
        self::assertCount(1, StatusHelper::$syncs);
        $meta = OrderTransaction::allRecords()[20]['meta'];
        self::assertSame('kept', $meta['existing']);
        self::assertArrayNotHasKey('ys_helcim_checkout_token', $meta);
        self::assertArrayNotHasKey('ys_helcim_secret_token_enc', $meta);
        self::assertArrayNotHasKey('ys_helcim_card_token', $meta);
        self::assertArrayNotHasKey('ys_helcim_operation_uuid', $meta);
        self::assertArrayNotHasKey('ys_helcim_initialized_at', $meta);

        $replay = $this->service->confirm(
            'fc-hosted-transaction',
            self::OPERATION_UUID,
            self::RAW_CONFIRM_TOKEN,
            [],
            ''
        );
        self::assertIsArray($replay);
        self::assertSame('succeeded', $replay['status']);
        self::assertCount(1, StatusHelper::$syncs, 'A completed replay must not apply the payment twice.');
    }

    public function testWrongHashOrCorrelationCannotBurnTheOneTimeTokenOrMutateLocalState(): void
    {
        $event = $this->approvedEvent();
        $wrongHash = $this->service->confirm(
            'fc-hosted-transaction',
            self::OPERATION_UUID,
            self::RAW_CONFIRM_TOKEN,
            $event,
            str_repeat('0', 64)
        );
        self::assertInstanceOf(\WP_Error::class, $wrongHash);
        self::assertSame('ys_helcim_confirm_hash_invalid', $wrongHash->get_error_code());

        $event['invoiceNumber'] = '00000000-0000-4000-8000-000000000999';
        $wrongCorrelation = $this->service->confirm(
            'fc-hosted-transaction',
            self::OPERATION_UUID,
            self::RAW_CONFIRM_TOKEN,
            $event,
            $this->hash($event)
        );
        self::assertInstanceOf(\WP_Error::class, $wrongCorrelation);
        self::assertSame('ys_helcim_confirm_correlation_invalid', $wrongCorrelation->get_error_code());

        self::assertTrue($this->repository->consumeConfirmToken(self::OPERATION_UUID, self::RAW_CONFIRM_TOKEN));
        self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
        self::assertCount(0, StatusHelper::$syncs);
    }

    public function testTransactionOperationMismatchIsRejectedBeforeHashAndCannotBurnTheOneTimeToken(): void
    {
        $record = OrderTransaction::allRecords()[20];
        $record['meta']['ys_helcim_operation_uuid'] = '00000000-0000-4000-8000-000000000999';
        OrderTransaction::seed($record);
        $event = $this->approvedEvent();

        $result = $this->service->confirm(
            'fc-hosted-transaction',
            self::OPERATION_UUID,
            self::RAW_CONFIRM_TOKEN,
            $event,
            $this->hash($event)
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_confirm_transaction_correlation_invalid', $result->get_error_code());
        self::assertTrue($this->repository->consumeConfirmToken(self::OPERATION_UUID, self::RAW_CONFIRM_TOKEN));
        self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
        self::assertCount(0, StatusHelper::$syncs);
    }

    public function testMissingProviderTransactionIdNeverConsumesTokenOrMarksPaid(): void
    {
        $event = $this->approvedEvent();
        unset($event['transactionId']);

        $result = $this->service->confirm(
            'fc-hosted-transaction',
            self::OPERATION_UUID,
            self::RAW_CONFIRM_TOKEN,
            $event,
            $this->hash($event)
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_confirm_proof_invalid', $result->get_error_code());
        self::assertTrue($this->repository->consumeConfirmToken(self::OPERATION_UUID, self::RAW_CONFIRM_TOKEN));
        self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
    }

    public function testExactDeclineConsumesTheConfirmationTokenAndReleasesTheAttemptWithoutMarkingPaid(): void
    {
        $event = $this->approvedEvent();
        $event['status'] = 'DECLINED';
        unset($event['transactionId']);

        $result = $this->service->confirm(
            'fc-hosted-transaction',
            self::OPERATION_UUID,
            self::RAW_CONFIRM_TOKEN,
            $event,
            $this->hash($event)
        );

        self::assertIsArray($result);
        self::assertSame('declined', $result['status']);
        self::assertSame('declined', $result['remote_status']);
        self::assertFalse($this->repository->consumeConfirmToken(self::OPERATION_UUID, self::RAW_CONFIRM_TOKEN));
        self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
        self::assertSame('pending', Order::allRecords()[10]['payment_status']);
        self::assertCount(0, StatusHelper::$syncs);
        $meta = OrderTransaction::allRecords()[20]['meta'];
        self::assertSame('kept', $meta['existing']);
        self::assertArrayNotHasKey('ys_helcim_checkout_token', $meta);
        self::assertArrayNotHasKey('ys_helcim_secret_token_enc', $meta);
        self::assertArrayNotHasKey('ys_helcim_operation_uuid', $meta);
        self::assertArrayNotHasKey('ys_helcim_initialized_at', $meta);
        self::assertArrayNotHasKey('ys_helcim_card_token', $meta);
    }

    public function testDeclineCleanupThatCannotBeReadBackReturnsAnErrorInsteadOfAReusableDecline(): void
    {
        OrderTransaction::$savePersists = false;
        $event = $this->approvedEvent();
        $event['status'] = 'DECLINED';
        unset($event['transactionId']);

        $result = $this->service->confirm(
            'fc-hosted-transaction',
            self::OPERATION_UUID,
            self::RAW_CONFIRM_TOKEN,
            $event,
            $this->hash($event)
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_purchase_meta_purge_unverified', $result->get_error_code());
        self::assertFalse($this->repository->consumeConfirmToken(self::OPERATION_UUID, self::RAW_CONFIRM_TOKEN));
        self::assertSame('declined', $this->repository->findByUuid(self::OPERATION_UUID)['remote_status']);
        self::assertArrayHasKey('ys_helcim_secret_token_enc', OrderTransaction::allRecords()[20]['meta']);
        self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
    }

    public function testDeclineCleanupSaveFailureReturnsAnErrorInsteadOfAReusableDecline(): void
    {
        OrderTransaction::$saveResult = false;
        $event = $this->approvedEvent();
        $event['status'] = 'DECLINED';
        unset($event['transactionId']);

        $result = $this->service->confirm(
            'fc-hosted-transaction',
            self::OPERATION_UUID,
            self::RAW_CONFIRM_TOKEN,
            $event,
            $this->hash($event)
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_purchase_meta_purge_failed', $result->get_error_code());
        self::assertFalse($this->repository->consumeConfirmToken(self::OPERATION_UUID, self::RAW_CONFIRM_TOKEN));
        self::assertSame('declined', $this->repository->findByUuid(self::OPERATION_UUID)['remote_status']);
        self::assertArrayHasKey('ys_helcim_checkout_token', OrderTransaction::allRecords()[20]['meta']);
        self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
    }

    public function testPlaintextSecretMetadataIsRejectedWithoutConsumingTheConfirmationToken(): void
    {
        $record = OrderTransaction::allRecords()[20];
        $record['meta']['ys_helcim_secret_token_enc'] = self::SECRET_TOKEN;
        OrderTransaction::seed($record);
        $event = $this->approvedEvent();

        $result = $this->service->confirm(
            'fc-hosted-transaction',
            self::OPERATION_UUID,
            self::RAW_CONFIRM_TOKEN,
            $event,
            $this->hash($event)
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_confirm_secret_invalid', $result->get_error_code());
        self::assertTrue($this->repository->consumeConfirmToken(self::OPERATION_UUID, self::RAW_CONFIRM_TOKEN));
        self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
    }

    private function seedModels(): void
    {
        OrderTransaction::seed([
            'id' => 20,
            'uuid' => 'fc-hosted-transaction',
            'order_id' => 10,
            'payment_method' => 'ys_helcim',
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'total' => 2100,
            'currency' => 'USD',
            'payment_mode' => 'test',
            'status' => Status::TRANSACTION_PENDING,
            'vendor_charge_id' => null,
            'payment_method_type' => '',
            'card_last_4' => '',
            'card_brand' => '',
            'meta' => [
                'existing' => 'kept',
                'ys_helcim_checkout_token' => 'checkout-token',
                'ys_helcim_secret_token_enc' => 'enc:' . self::SECRET_TOKEN,
                'ys_helcim_card_token' => 'legacy-card-token',
                'ys_helcim_operation_uuid' => self::OPERATION_UUID,
                'ys_helcim_initialized_at' => '2026-07-21 00:00:00',
            ],
        ]);
        Order::seed([
            'id' => 10,
            'uuid' => 'order-uuid-10',
            'status' => 'pending',
            'payment_status' => 'pending',
            'total_amount' => 2100,
            'total_paid' => 0,
            'billing_address' => null,
            'shipping_address' => null,
            'customer' => null,
        ]);
    }

    private function seedProcessingOperation(): void
    {
        $purchase = YSHelcimPurchaseOperation::fromTransaction([
            'gateway' => 'ys_helcim',
            'order_id' => 10,
            'transaction_id' => 20,
            'transaction_uuid' => 'fc-hosted-transaction',
            'amount' => 2100,
            'currency' => 'USD',
            'payment_mode' => 'test',
        ]);
        self::assertInstanceOf(YSHelcimPurchaseOperation::class, $purchase);
        $record = $purchase->repositoryRecord(self::OPERATION_UUID, hash('sha256', 'hosted-attempt'));
        self::assertIsArray($record);
        $record['provider_correlation_id'] = self::OPERATION_UUID;
        $record['confirm_token_hash'] = hash('sha256', self::RAW_CONFIRM_TOKEN);
        $record['confirm_token_expires_at'] = '2026-07-21 00:10:00';
        self::assertIsArray($this->repository->create($record));
        self::assertTrue($this->repository->claimRemoteProcessing(self::OPERATION_UUID));
    }

    /** @return array<string,mixed> */
    private function approvedEvent(): array
    {
        return [
            'status' => 'APPROVED',
            'type' => 'purchase',
            'transactionId' => '51177991',
            'amount' => '21.00',
            'currency' => 'USD',
            'invoiceNumber' => self::OPERATION_UUID,
            'cardNumber' => '************9990',
            'cardType' => 'VI',
        ];
    }

    /** @param array<string,mixed> $event */
    private function hash(array $event): string
    {
        return hash('sha256', json_encode($event) . self::SECRET_TOKEN);
    }
}
