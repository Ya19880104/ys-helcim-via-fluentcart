<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\HelcimJs;

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
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsSettings;
use YangSheep\Helcim\FluentCart\HelcimPay\YSHelcimPayRecoveryService;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimPurchaseOperation;
use YangSheep\Helcim\FluentCart\Tests\Doubles\FakeWpdb;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class InlinePurchaseRecoveryServiceTest extends TestCase
{
	private const OPERATION_UUID = '00000000-0000-4000-8000-000000000851';
	private const NOW = 1784613600; // 2026-07-21 06:00:00 UTC.

	private YSHelcimOperationRepository $repository;
	private array|\WP_Error $lookupResult;
	private int $lookupCalls = 0;
	private int $purchaseCalls = 0;
	private YSHelcimPayRecoveryService $service;

	protected function setUp(): void
	{
		require_once dirname(__DIR__, 2) . '/Doubles/InlineFluentCart.php';
		require_once dirname(__DIR__, 2) . '/Doubles/InlineWordPress.php';

		OrderTransaction::reset();
		Order::reset();
		StatusHelper::reset();
		StoreSettings::$orderMode = 'test';
		BaseGatewaySettings::$settingsByClass[YSHelcimJsSettings::class] = [
			'test_api_token' => 'enc:test-api-secret',
		];

		$database = new FakeWpdb();
		$this->repository = new YSHelcimOperationRepository(
			$database,
			static fn (): string => '2026-07-21 06:00:00'
		);
		$this->seedModels();
		$this->seedIndeterminateOperation();
		$this->lookupResult = [];

		$runtime = new YSHelcimJsPurchaseRuntime(
			settings: new YSHelcimJsSettings(),
			operations: $this->repository,
			api_request: function (): \WP_Error {
				++$this->purchaseCalls;
				return new \WP_Error('must_not_charge', 'Recovery must never initiate a purchase.');
			},
			method_slug: 'ys_helcim_js',
			terminal_meta_keys: []
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
				return $this->lookupResult;
			},
			clock: static fn (): int => self::NOW,
			gateway: 'ys_helcim_js',
			policy: YSHelcimPayRecoveryService::POLICY_SERVER_PURCHASE
		);
	}

	public function testExactApprovedLookupAppliesInlinePurchaseWithoutResendingPurchase(): void
	{
		$this->lookupResult = [$this->providerTransaction('APPROVED', '51178851')];

		$result = $this->service->recover(self::OPERATION_UUID);

		self::assertIsArray($result);
		self::assertSame('succeeded', $result['status']);
		self::assertSame(1, $this->lookupCalls);
		self::assertSame(0, $this->purchaseCalls);
		self::assertSame(Status::TRANSACTION_SUCCEEDED, OrderTransaction::allRecords()[20]['status']);
		self::assertSame('51178851', OrderTransaction::allRecords()[20]['vendor_charge_id']);
		self::assertSame('paid', Order::allRecords()[10]['payment_status']);
		self::assertNull($this->repository->findByUuid(self::OPERATION_UUID)['active_scope_key']);
	}

	public function testExactInlineDeclineReleasesScopeImmediatelyWithoutMarkingPaid(): void
	{
		$this->lookupResult = [$this->providerTransaction('DECLINED', '51178852')];

		$result = $this->service->recover(self::OPERATION_UUID);

		self::assertIsArray($result);
		self::assertSame('declined', $result['status']);
		self::assertSame(0, $this->purchaseCalls);
		self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
		self::assertSame('pending', Order::allRecords()[10]['payment_status']);
		$row = $this->repository->findByUuid(self::OPERATION_UUID);
		self::assertSame('declined', $row['remote_status']);
		self::assertNull($row['active_scope_key']);
	}

	public function testInlineEmptyLookupRemainsIndeterminateAndKeepsScopeLocked(): void
	{
		$result = $this->service->recover(self::OPERATION_UUID);

		self::assertIsArray($result);
		self::assertSame('pending', $result['status']);
		self::assertSame('provider_lookup_empty_unresolved', $result['reason']);
		self::assertSame(0, $this->purchaseCalls);
		$row = $this->repository->findByUuid(self::OPERATION_UUID);
		self::assertSame('indeterminate', $row['remote_status']);
		self::assertNotEmpty($row['active_scope_key']);
		self::assertSame(Status::TRANSACTION_PENDING, OrderTransaction::allRecords()[20]['status']);
	}

	public function testInlineAmbiguousLookupFailsClosedAndKeepsScopeLocked(): void
	{
		$this->lookupResult = [
			$this->providerTransaction('APPROVED', '51178853'),
			$this->providerTransaction('APPROVED', '51178854'),
		];

		$result = $this->service->recover(self::OPERATION_UUID);

		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame('ys_helcim_hosted_recovery_ambiguous', $result->get_error_code());
		self::assertSame(0, $this->purchaseCalls);
		$row = $this->repository->findByUuid(self::OPERATION_UUID);
		self::assertSame('indeterminate', $row['remote_status']);
		self::assertNotEmpty($row['active_scope_key']);
	}

	private function seedModels(): void
	{
		Order::seed([
			'id' => 10,
			'uuid' => 'inline-order-uuid',
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
			'uuid' => 'fc-inline-recovery-transaction',
			'order_id' => 10,
			'payment_method' => 'ys_helcim_js',
			'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
			'status' => Status::TRANSACTION_PENDING,
			'total' => 2100,
			'currency' => 'USD',
			'payment_mode' => 'test',
			'vendor_charge_id' => null,
			'payment_method_type' => '',
			'card_last_4' => '',
			'card_brand' => '',
			'meta' => ['existing' => 'kept'],
		]);
	}

	private function seedIndeterminateOperation(): void
	{
		$purchase = YSHelcimPurchaseOperation::fromTransaction([
			'gateway' => 'ys_helcim_js',
			'order_id' => 10,
			'transaction_id' => 20,
			'transaction_uuid' => 'fc-inline-recovery-transaction',
			'amount' => 2100,
			'currency' => 'USD',
			'payment_mode' => 'test',
		]);
		self::assertInstanceOf(YSHelcimPurchaseOperation::class, $purchase);
		$record = $purchase->repositoryRecord(self::OPERATION_UUID, hash('sha256', 'inline-recovery-attempt'));
		self::assertIsArray($record);
		$record['provider_correlation_id'] = self::OPERATION_UUID;
		self::assertIsArray($this->repository->create($record));
		self::assertTrue($this->repository->claimRemoteProcessing(self::OPERATION_UUID));
		self::assertTrue($this->repository->transitionRemote(
			self::OPERATION_UUID,
			'processing',
			'indeterminate',
			[
				'error_code' => 'provider_outcome_unproven',
				'error_message' => 'The provider outcome could not be proven.',
			]
		));
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
}
