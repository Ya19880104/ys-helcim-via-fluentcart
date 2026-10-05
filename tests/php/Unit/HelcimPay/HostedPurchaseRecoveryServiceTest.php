<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\HelcimPay;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsPurchaseRuntime;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPayRecoveryService;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPaySettings;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimPurchaseOperation;
use YangSheep\Helcim\FluentCart\Tests\Doubles\FakeWpdb;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class HostedPurchaseRecoveryServiceTest extends TestCase
{
    private const OPERATION_UUID = '00000000-0000-4000-8000-000000000841';
    private const NOW = 1784613600; // 2026-07-21 06:00:00 UTC.

    private YSHelcimOperationRepository $repository;
    private FakeWpdb $database;
    private array|\WP_Error $lookupResult;
    /** @var array<int, array|\WP_Error> One-shot lookup results consumed before $lookupResult. */
    private array $lookupQueue = [];
    private int $lookupCalls = 0;
    /** @var array<int, array{0:int,1:string,2:string}> */
    private array $orderNotes = [];
    private YSHelcimPayRecoveryService $service;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/Doubles/InlineFluentCart.php';
        require_once dirname(__DIR__, 2) . '/Doubles/InlineWordPress.php';

        OrderTransaction::reset();
        Order::reset();
        StatusHelper::reset();
        StoreSettings::$orderMode = 'test';
        BaseGatewaySettings::$settingsByClass[YSHelcimPaySettings::class] = [
            'test_api_token' => 'enc:test-api-secret',
        ];

        $this->database = new FakeWpdb();
        $this->repository = new YSHelcimOperationRepository(
            $this->database,
            static fn (): string => '2026-07-21 06:00:00'
        );
        $this->seedModels();
        $this->seedProcessingOperation('2026-07-21 04:40:00');
        $this->lookupResult = [];

        $runtime = new YSHelcimJsPurchaseRuntime(
            settings: new YSHelcimPaySettings(),
            operations: $this->repository,
            api_request: static fn (): \WP_Error => new \WP_Error('must_not_charge', 'Recovery must never initiate a purchase.'),
            method_slug: 'ys_helcim',
            terminal_meta_keys: $this->terminalMetaKeys()
        );

        $this->service = new YSHelcimPayRecoveryService(
            operations: $this->repository,
            runtime: $runtime,
            transaction_loader: static fn (int $transactionId) => OrderTransaction::query()->where('id', $transactionId)->first(),
            credential_resolver: static fn (string $mode): string => $mode === 'test' ? 'test-api-secret' : '',
            provider_lookup: function (string $invoiceNumber, string $apiToken): array|\WP_Error {
                ++$this->lookupCalls;
                self::assertSame(self::OPERATION_UUID, $invoiceNumber);
                self::assertSame('test-api-secret', $apiToken);
                return [] !== $this->lookupQueue ? array_shift($this->lookupQueue) : $this->lookupResult;
            },
            clock: static fn (): int => self::NOW,
            order_note_writer: function (int $orderId, string $title, string $message): void {
                $this->orderNotes[] = [$orderId, $title, $message];
            }
        );
    }

    /** Move the seeded in-flight operation into the closed (canceled) state. */
    private function releaseSeededOperation(): void
    {
        self::assertTrue(
            $this->repository->transitionRemote(
                self::OPERATION_UUID,
                'processing',
                'canceled',
                ['error_code' => 'ys_helcim_session_expired_released']
            )
        );
        self::assertNull(
            $this->repository->findByUuid(self::OPERATION_UUID)['active_scope_key'],
            'A closed expired checkout frees the order for another payment.'
        );
    }

    /** Late approval proof must bind a released checkout end to end: canceled -> succeeded -> local applied. */
    public function testExactLateApprovalBindsAReleasedCanceledCheckoutEndToEnd(): void
    {
        $this->releaseSeededOperation();
        $this->lookupResult = [$this->providerTransaction('APPROVED', '81178851')];

        $result = $this->service->recover(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertSame('succeeded', $result['status'], var_export($result, true));
        self::assertSame('81178851', OrderTransaction::allRecords()[20]['vendor_charge_id']);
        self::assertSame('paid', Order::allRecords()[10]['payment_status']);
        $row = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('succeeded', $row['remote_status']);
        self::assertSame('applied', $row['local_status']);
    }

    /** An empty read never moves a released checkout: it stays canceled, untouched. */
    public function testEmptyLookupLeavesAReleasedCheckoutUntouched(): void
    {
        $this->releaseSeededOperation();
        $this->lookupResult = [];

        $result = $this->service->recover(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertSame('canceled', $result['status']);
        $row = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('canceled', $row['remote_status']);
        self::assertSame('pending', $row['local_status']);
        self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
    }

    /** A late DECLINE proves no money moved; the released checkout must not transition. */
    public function testLateDeclineChangesNothingOnAReleasedCheckout(): void
    {
        $this->releaseSeededOperation();
        $this->lookupResult = [$this->providerTransaction('DECLINED', '81178852')];

        $result = $this->service->recover(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertSame('canceled', $result['status']);
        self::assertSame('canceled', $this->repository->findByUuid(self::OPERATION_UUID)['remote_status']);
    }

    public function testExactApprovedLookupReconcilesThroughTheDurableRuntime(): void
    {
        $this->lookupResult = [$this->providerTransaction('APPROVED', '81178841')];

        $result = $this->service->recover(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertSame('succeeded', $result['status'], var_export($result, true));
        self::assertSame(1, $this->lookupCalls);
        self::assertSame(Status::TRANSACTION_SUCCEEDED, OrderTransaction::allRecords()[20]['status']);
        self::assertSame('81178841', OrderTransaction::allRecords()[20]['vendor_charge_id']);
        self::assertSame('paid', Order::allRecords()[10]['payment_status']);
        self::assertSame('succeeded', $this->repository->findByUuid(self::OPERATION_UUID)['remote_status']);
        self::assertNotNull($this->repository->findByUuid(self::OPERATION_UUID)['active_scope_key']);
        $this->assertTerminalMetaPurged();
    }

	public function testExactApprovedLookupCanRecoverBeforeCheckoutTokenExpiry(): void
	{
		$this->setOperationCreatedAt('2026-07-21 05:54:00');
		$this->lookupResult = [$this->providerTransaction('APPROVED', '81178846')];

		$result = $this->service->recover(self::OPERATION_UUID);

		self::assertIsArray($result);
		self::assertSame('succeeded', $result['status']);
		self::assertSame('81178846', OrderTransaction::allRecords()[20]['vendor_charge_id']);
		self::assertSame('paid', Order::allRecords()[10]['payment_status']);
	}

    public function testExactDeclinedLookupReleasesScopeWithoutMarkingPaid(): void
    {
        $this->lookupResult = [$this->providerTransaction('DECLINED', '81178842')];

        $result = $this->service->recover(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertSame('declined', $result['status']);
        self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
        self::assertSame('pending', Order::allRecords()[10]['payment_status']);
        $row = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('declined', $row['remote_status']);
        self::assertNull($row['active_scope_key']);
        $this->assertTerminalMetaPurged();
    }

	public function testExactDeclineBeforeCheckoutTokenExpiryDoesNotReleaseScope(): void
	{
		$this->setOperationCreatedAt('2026-07-21 05:54:00');
		$this->lookupResult = [$this->providerTransaction('DECLINED', '81178847')];

		$result = $this->service->recover(self::OPERATION_UUID);

		self::assertIsArray($result);
		self::assertSame('pending', $result['status']);
		self::assertSame('checkout_session_still_valid', $result['reason']);
		$row = $this->repository->findByUuid(self::OPERATION_UUID);
		self::assertSame('processing', $row['remote_status']);
		self::assertNotEmpty($row['active_scope_key']);
		self::assertArrayHasKey('ys_helcim_checkout_token', OrderTransaction::allRecords()[20]['meta']);
	}

    /**
     * Production incident: an abandoned Helcim payment window stayed "indeterminate",
     * burned seven recovery attempts and raised a permanent administrator alarm although
     * Helcim never had a transaction. Past the 60-minute token life plus indexing grace,
     * empty authenticated lookups close the window and free the unpaid order.
     */
    public function testEmptyLookupsAfterExpiryCloseTheAbandonedCheckoutAndFreeTheOrder(): void
    {
        $result = $this->service->recover(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertSame('canceled', $result['status'], var_export($result, true));
        self::assertSame(3, $this->lookupCalls, 'The first recovery read plus two consecutive closing reads.');
        $row = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('canceled', $row['remote_status']);
        self::assertSame('pending', $row['local_status']);
        self::assertNull($row['active_scope_key'], 'The shopper must be able to pay the order again.');
        self::assertSame('ys_helcim_session_expired_released', $row['remote_error_code']);
        self::assertNotNull($row['next_recovery_at'], 'One late-proof follow-up stays scheduled.');
        self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
        self::assertSame('pending', Order::allRecords()[10]['payment_status'], 'Closing never marks the order paid or failed.');
        $this->assertTerminalMetaPurged();
    }

    public function testLegacyIndeterminateAbandonedCheckoutIsClosedOnTheNextLookup(): void
    {
        $this->repository->transitionRemote(
            self::OPERATION_UUID,
            'processing',
            'indeterminate',
            [
                'error_code' => 'ys_helcim_hosted_recovery_attention_required',
                'error_message' => 'Automatic payment recovery paused without exact proof.',
            ]
        );

        $result = $this->service->recover(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertSame('canceled', $result['status']);
        $row = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('canceled', $row['remote_status']);
        self::assertNull($row['active_scope_key']);
    }

    public function testClosingAnAbandonedCheckoutRecordsAnOrderNote(): void
    {
        $this->service->recover(self::OPERATION_UUID);

        self::assertCount(1, $this->orderNotes);
        self::assertSame(10, $this->orderNotes[0][0]);
        self::assertSame('Helcim payment window expired', $this->orderNotes[0][1]);
        self::assertStringContainsString('no payment was taken', $this->orderNotes[0][2]);
    }

    public function testLookupFailureWhileClosingKeepsTheWindowLocked(): void
    {
        $this->lookupQueue = [[], new \WP_Error('http_request_failed', 'Timeout')];

        $result = $this->service->recover(self::OPERATION_UUID);

        self::assertInstanceOf(\WP_Error::class, $result);
        $row = $this->repository->findByUuid(self::OPERATION_UUID);
        self::assertSame('processing', $row['remote_status']);
        self::assertNotNull($row['active_scope_key'], 'An unverified window must never be closed.');
        self::assertSame([], $this->orderNotes);
    }

    public function testTransactionAppearingWhileClosingIsReconciledInsteadOfClosed(): void
    {
        $this->lookupQueue = [[], [$this->providerTransaction('APPROVED', '81178853')]];

        $closing = $this->service->recover(self::OPERATION_UUID);

        self::assertIsArray($closing);
        self::assertSame('pending', $closing['status']);
        self::assertSame('provider_transaction_present', $closing['reason']);
        self::assertSame('processing', $this->repository->findByUuid(self::OPERATION_UUID)['remote_status']);

        $this->lookupResult = [$this->providerTransaction('APPROVED', '81178853')];
        $approved = $this->service->recover(self::OPERATION_UUID);

        self::assertSame('succeeded', $approved['status']);
        self::assertSame('paid', Order::allRecords()[10]['payment_status']);
    }

    public function testEmptyLookupBeforeCheckoutExpiryNeverChangesTheOperation(): void
    {
        $this->setOperationCreatedAt('2026-07-21 05:10:01');

        $result = $this->service->recover(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertSame('pending', $result['status']);
        self::assertSame('checkout_session_still_valid', $result['reason']);
        self::assertSame('processing', $this->repository->findByUuid(self::OPERATION_UUID)['remote_status']);
        self::assertNotEmpty($this->repository->findByUuid(self::OPERATION_UUID)['active_scope_key']);
        $meta = OrderTransaction::allRecords()[20]['meta'];
        self::assertArrayHasKey('ys_helcim_checkout_token', $meta);
        self::assertArrayHasKey('ys_helcim_secret_token_enc', $meta);
        self::assertArrayHasKey('ys_helcim_operation_uuid', $meta);
    }

    public function testUndocumentedCollectionEnvelopeIsRejectedFailClosed(): void
    {
        $this->lookupResult = ['data' => [$this->providerTransaction('APPROVED', '81178845')]];

        $result = $this->service->recover(self::OPERATION_UUID);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_hosted_recovery_ambiguous', $result->get_error_code());
        self::assertSame('processing', $this->repository->findByUuid(self::OPERATION_UUID)['remote_status']);
        self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
    }

	public function testExactLateApprovalAfterClosingCompletesExactlyOnce(): void
	{
		$empty = $this->service->recover(self::OPERATION_UUID);
		self::assertIsArray($empty);
		self::assertSame('canceled', $empty['status']);
		$this->assertTerminalMetaPurged();

		$this->lookupResult = [$this->providerTransaction('APPROVED', '81178848')];
		$approved = $this->service->recover(self::OPERATION_UUID);
		self::assertIsArray($approved);
		self::assertSame('succeeded', $approved['status']);
		self::assertSame('81178848', OrderTransaction::allRecords()[20]['vendor_charge_id']);
		self::assertSame('paid', Order::allRecords()[10]['payment_status']);

		$duplicate = $this->service->recover(self::OPERATION_UUID);
		self::assertIsArray($duplicate);
		self::assertSame('succeeded', $duplicate['status']);
		self::assertSame('81178848', OrderTransaction::allRecords()[20]['vendor_charge_id']);
	}

	public function testPersistedRemoteSuccessResumesLocalBindingWithoutProviderLookup(): void
	{
		self::assertTrue($this->repository->transitionRemote(
			self::OPERATION_UUID,
			'processing',
			'succeeded',
			['vendor_transaction_id' => '81178849']
		));

		$result = $this->service->recover(self::OPERATION_UUID);

		self::assertIsArray($result);
		self::assertSame('succeeded', $result['status']);
		self::assertSame(0, $this->lookupCalls);
		self::assertSame('81178849', OrderTransaction::allRecords()[20]['vendor_charge_id']);
		self::assertSame('paid', Order::allRecords()[10]['payment_status']);
		self::assertNotNull($this->repository->findByUuid(self::OPERATION_UUID)['active_scope_key']);
	}

	public function testScopeFreePersistedSuccessResumesLocalBindingWithoutProviderLookup(): void
	{
		self::assertTrue($this->repository->transitionRemote(
			self::OPERATION_UUID,
			'processing',
			'canceled',
			['error_code' => 'ys_helcim_session_expired_released']
		));
		self::assertTrue($this->repository->transitionRemote(
			self::OPERATION_UUID,
			'canceled',
			'succeeded',
			['vendor_transaction_id' => '81178853']
		));
		$this->database->update(
			'wp_ys_helcim_operations',
			['active_scope_key' => null],
			['operation_uuid' => self::OPERATION_UUID]
		);
		self::assertNull(
			$this->repository->findByUuid(self::OPERATION_UUID)['active_scope_key'],
			'the legacy crash shape has already released its purchase scope'
		);

		$result = $this->service->recover(self::OPERATION_UUID);

		self::assertIsArray($result);
		self::assertSame('succeeded', $result['status']);
		self::assertSame(0, $this->lookupCalls, 'durable success never queries or mutates Helcim again');
		self::assertSame('81178853', OrderTransaction::allRecords()[20]['vendor_charge_id']);
		self::assertSame('paid', Order::allRecords()[10]['payment_status']);
		$row = $this->repository->findByUuid(self::OPERATION_UUID);
		self::assertSame('succeeded', $row['remote_status']);
		self::assertSame('applied', $row['local_status']);
		self::assertNull($row['active_scope_key']);
	}

    public function testMalformedOrAmbiguousLookupNeverReleasesTheScope(): void
    {
        $this->lookupResult = [
            $this->providerTransaction('APPROVED', '81178843'),
            $this->providerTransaction('APPROVED', '81178844'),
        ];

        $result = $this->service->recover(self::OPERATION_UUID);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_hosted_recovery_ambiguous', $result->get_error_code());
        self::assertSame('processing', $this->repository->findByUuid(self::OPERATION_UUID)['remote_status']);
        self::assertNotEmpty($this->repository->findByUuid(self::OPERATION_UUID)['active_scope_key']);
        self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
    }

	public function testDeclineWithoutAnExactProviderTransactionIdNeverReleasesTheScope(): void
	{
		$decline = $this->providerTransaction('DECLINED', '81178850');
		unset($decline['transactionId']);
		$this->lookupResult = [$decline];

		$result = $this->service->recover(self::OPERATION_UUID);

		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame('ys_helcim_hosted_recovery_ambiguous', $result->get_error_code());
		$row = $this->repository->findByUuid(self::OPERATION_UUID);
		self::assertSame('processing', $row['remote_status']);
		self::assertNotEmpty($row['active_scope_key']);
		self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
	}

    public function testProviderOrCredentialFailureNeverReleasesTheScope(): void
    {
        $this->lookupResult = new \WP_Error('provider_down', 'Provider unavailable.');

        $result = $this->service->recover(self::OPERATION_UUID);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('provider_down', $result->get_error_code());
        self::assertSame('processing', $this->repository->findByUuid(self::OPERATION_UUID)['remote_status']);
        self::assertNotEmpty($this->repository->findByUuid(self::OPERATION_UUID)['active_scope_key']);
    }

	public function testAutomaticRecoveryBackoffIsBoundedAndStopsAfterSevenAttempts(): void
	{
		self::assertSame(
			[300, 900, 3600, 10800, 21600, 43200, null],
			array_map(
				static fn (int $attempt): ?int => YSHelcimPayRecoveryService::retryDelayAfterAttempt($attempt),
				range(1, 7)
			)
		);
		self::assertNull(YSHelcimPayRecoveryService::retryDelayAfterAttempt(8));
	}

    private function seedModels(): void
    {
        Order::seed([
            'id' => 10,
            'uuid' => 'hosted-order-uuid',
            'status' => 'pending',
            'payment_status' => 'pending',
            'total_amount' => 2100,
            'total_paid' => 0,
            'billing_address' => null,
            'shipping_address' => null,
            'customer' => null,
        ]);
        OrderTransaction::seed([
            'id' => 20,
            'uuid' => 'fc-hosted-recovery-transaction',
            'order_id' => 10,
            'payment_method' => 'ys_helcim',
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'status' => Status::TRANSACTION_PENDING,
            'total' => 2100,
            'currency' => 'USD',
            'payment_mode' => 'test',
            'vendor_charge_id' => null,
            'payment_method_type' => '',
            'card_last_4' => '',
            'card_brand' => '',
            'meta' => [
                'existing' => 'kept',
                'ys_helcim_checkout_token' => 'checkout-token',
                'ys_helcim_secret_token_enc' => 'encrypted-secret',
                'ys_helcim_card_token' => 'legacy-token',
                'ys_helcim_operation_uuid' => self::OPERATION_UUID,
                'ys_helcim_initialized_at' => '2026-07-21 04:40:00',
            ],
        ]);
    }

    private function seedProcessingOperation(string $createdAt): void
    {
        $purchase = YSHelcimPurchaseOperation::fromTransaction([
            'gateway' => 'ys_helcim',
            'order_id' => 10,
            'transaction_id' => 20,
            'transaction_uuid' => 'fc-hosted-recovery-transaction',
            'amount' => 2100,
            'currency' => 'USD',
            'payment_mode' => 'test',
        ]);
        self::assertInstanceOf(YSHelcimPurchaseOperation::class, $purchase);
        $record = $purchase->repositoryRecord(self::OPERATION_UUID, hash('sha256', 'hosted-recovery-attempt'));
        self::assertIsArray($record);
        $record['provider_correlation_id'] = self::OPERATION_UUID;
        $created = $this->repository->create($record);
        self::assertIsArray($created);
        self::assertTrue($this->repository->claimRemoteProcessing(self::OPERATION_UUID));
        $this->setOperationCreatedAt($createdAt);
    }

    private function providerTransaction(string $status, string $transactionId): array
    {
        return [
            'transactionId' => $transactionId,
            'status' => $status,
            'type' => 'purchase',
            'amount' => 21.00,
            'currency' => 'USD',
            'invoiceNumber' => self::OPERATION_UUID,
        ];
    }

    private function setOperationCreatedAt(string $createdAt): void
    {
        $this->database->update(
            'wp_ys_helcim_operations',
            ['created_at' => $createdAt],
            ['operation_uuid' => self::OPERATION_UUID]
        );
    }

    private function assertTerminalMetaPurged(): void
    {
        $meta = OrderTransaction::allRecords()[20]['meta'];
        self::assertSame('kept', $meta['existing']);
        foreach ($this->terminalMetaKeys() as $key) {
            self::assertArrayNotHasKey($key, $meta);
        }
    }

    private function terminalMetaKeys(): array
    {
        return [
            'ys_helcim_checkout_token',
            'ys_helcim_secret_token_enc',
            'ys_helcim_card_token',
            'ys_helcim_operation_uuid',
            'ys_helcim_initialized_at',
        ];
    }
}
