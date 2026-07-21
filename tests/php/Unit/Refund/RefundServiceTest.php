<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationRepository;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundPayload;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundResult;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundService;
use YangSheep\Helcim\FluentCart\Tests\Doubles\FakeWpdb;

final class RefundServiceTest extends TestCase
{
    private FakeWpdb $database;

    private YSHelcimOperationRepository $repository;

    protected function setUp(): void
    {
        $this->database = new FakeWpdb();
        $this->repository = new YSHelcimOperationRepository(
            $this->database,
            static fn (): string => '2026-07-21 00:00:00'
        );
    }

    public function testExactRefundApprovalIsPersistedAsRemoteSuccess(): void
    {
        $calls = [];
        $service = $this->service(
            static function (...$args) use (&$calls): array {
                $calls[] = $args;
                return self::approved('refund', '51177123', 21.00);
            }
        );

        $result = $service->execute($this->request());

        self::assertSame(YSHelcimRefundResult::SUCCEEDED, $result->status());
        self::assertSame('51177123', $result->vendorTransactionId());
        self::assertSame($this->request()['operation_uuid'], $result->refundOperationUuid());
        self::assertSame($this->request()['operation_uuid'], $result->effectiveOperationUuid());
        self::assertSame('refund', $result->providerAction());
        self::assertCount(1, $calls);
        self::assertSame('payment/refund', $calls[0][0]);
        self::assertSame('POST', $calls[0][4]);
        self::assertSame(36, strlen((string) $calls[0][3]));
        self::assertSame(
            'succeeded',
            $this->repository->findByUuid($this->request()['operation_uuid'])['remote_status']
        );
    }

    public function testItAcceptsTheCanonicalPayloadProducedByTheRestRequestBuilder(): void
    {
        $calls = [];
        $service = $this->service(
            static function (...$args) use (&$calls): array {
                $calls[] = $args;
                return self::approved('refund', '51177123', 21.00);
            }
        );
        $request = $this->request([
            'local_payload' => YSHelcimRefundPayload::normalize([
                'reason' => 'REST request builder',
                'item_ids' => [3],
                'actor_user_id' => 7,
            ]),
        ]);

        $result = $service->execute($request);

        self::assertInstanceOf(YSHelcimRefundResult::class, $result);
        self::assertSame(YSHelcimRefundResult::SUCCEEDED, $result->status());
        self::assertCount(1, $calls);
        self::assertSame('succeeded', $this->repository->findByUuid($request['operation_uuid'])['remote_status']);
    }

    public function testApprovalWithoutTransactionIdBecomesIndeterminateAndNeverReverses(): void
    {
        $calls = [];
        $service = $this->service(
            static function (...$args) use (&$calls): array {
                $calls[] = $args;
                return self::approved('refund', '', 21.00);
            }
        );

        $result = $service->execute($this->request());

        self::assertSame(YSHelcimRefundResult::INDETERMINATE, $result->status());
        self::assertCount(1, $calls);
        self::assertSame(
            'indeterminate',
            $this->repository->findByUuid($this->request()['operation_uuid'])['remote_status']
        );
    }

    public function testTransportFailureBecomesIndeterminateAndRetryDoesNotCallProviderAgain(): void
    {
        $calls = [];
        $service = $this->service(
            static function (...$args) use (&$calls): \WP_Error {
                $calls[] = $args;
                return new \WP_Error(
                    'ys_helcim_api_error',
                    'timeout',
                    ['kind' => 'transport', 'indeterminate' => true]
                );
            }
        );
        $request = $this->request();

        $first = $service->execute($request);
        $second = $service->execute($request);

        self::assertSame(YSHelcimRefundResult::INDETERMINATE, $first->status());
        self::assertSame(YSHelcimRefundResult::INDETERMINATE, $second->status());
        self::assertCount(1, $calls);
    }

    public function testStaleProcessingCrashWindowPromotesToIndeterminateWithoutProviderResend(): void
    {
        $now = '2026-07-21 00:00:00';
        $this->repository = new YSHelcimOperationRepository(
            $this->database,
            static function () use (&$now): string {
                return $now;
            }
        );
        $calls = 0;
        $operationUuid = $this->request()['operation_uuid'];
        $service = new YSHelcimRefundService(
            $this->repository,
            function () use (&$calls, $operationUuid): array {
                ++$calls;
                $this->database->failNextUpdateForOperationUuid = $operationUuid;
                return self::approved('refund', '51177123', 21.00);
            },
            static fn (): string => '00000000-0000-4000-8000-000000000099',
            static fn (): string => '2026-07-21 00:00:00'
        );

        $first = $service->execute($this->request());
        self::assertSame(YSHelcimRefundResult::INDETERMINATE, $first->status());
        self::assertSame('processing', $this->repository->findByUuid($operationUuid)['remote_status']);

        $now = '2026-07-21 00:06:00';
        $second = $service->execute($this->request());

        self::assertSame(YSHelcimRefundResult::INDETERMINATE, $second->status());
        self::assertSame(1, $calls);
        $stored = $this->repository->findByUuid($operationUuid);
        self::assertSame('indeterminate', $stored['remote_status']);
        self::assertSame('ys_helcim_provider_result_unpersisted', $stored['remote_error_code']);
        self::assertNotNull($stored['active_scope_key']);
    }

    public function testGenericProviderFailureNeverInvokesReverse(): void
    {
        $calls = [];
        $service = $this->service(
            static function (...$args) use (&$calls): \WP_Error {
                $calls[] = $args;
                return new \WP_Error(
                    'ys_helcim_api_error',
                    'API token is invalid',
                    [
                        'kind' => 'provider',
                        'http_code' => 401,
                        'indeterminate' => false,
                        'provider_errors' => ['authentication' => 'API token is invalid'],
                    ]
                );
            }
        );

        $result = $service->execute($this->request());

        self::assertSame(YSHelcimRefundResult::FAILED, $result->status());
        self::assertCount(1, $calls);
    }

    public function testPartialOpenBatchCandidateNeverInvokesReverse(): void
    {
        $calls = [];
        $service = $this->service(
            static function (...$args) use (&$calls): \WP_Error {
                $calls[] = $args;
                return self::openBatchRefundError();
            }
        );
        $request = $this->request([
            'amount' => 1000,
            'remaining_refundable' => 2100,
        ]);

        $result = $service->execute($request);

        self::assertSame(YSHelcimRefundResult::FAILED, $result->status());
        self::assertCount(1, $calls);
        self::assertSame('failed', $this->repository->findByUuid($request['operation_uuid'])['remote_status']);
    }

    public function testFullReverseRequiresExactOriginalTransactionAndOpenBatchProof(): void
    {
        $responses = [
            self::openBatchRefundStringError(),
            [
                'transactionId' => 51177061,
                'cardBatchId' => 4209764,
                'status' => 'APPROVED',
                'type' => 'purchase',
                'amount' => 21.00,
                'currency' => 'USD',
            ],
            ['id' => 4209764, 'closed' => false],
            self::approved('reverse', '51177124', 21.00),
        ];
        $calls = [];
        $service = $this->service(
            static function (...$args) use (&$responses, &$calls): array|\WP_Error {
                $calls[] = $args;
                return array_shift($responses);
            }
        );

        $result = $service->execute($this->request());

        self::assertSame(YSHelcimRefundResult::SUCCEEDED, $result->status());
        self::assertSame('51177124', $result->vendorTransactionId());
        self::assertSame($this->request()['operation_uuid'], $result->refundOperationUuid());
        self::assertSame('00000000-0000-4000-8000-000000000099', $result->effectiveOperationUuid());
        self::assertSame('reverse', $result->providerAction());
        self::assertSame(
            ['payment/refund', 'card-transactions/51177061', 'card-batches/4209764', 'payment/reverse'],
            array_column($calls, 0)
        );
        self::assertSame(['POST', 'GET', 'GET', 'POST'], array_column($calls, 4));
        self::assertSame('51177061', (string) $calls[3][1]['cardTransactionId']);
        self::assertNotSame($calls[0][3], $calls[3][3]);
        self::assertSame(36, strlen((string) $calls[3][3]));

        $rows = $this->database->allRows();
        self::assertCount(2, $rows);
        self::assertSame('refund', $rows[0]['operation_type']);
        self::assertSame('failed', $rows[0]['remote_status']);
        self::assertSame('reverse', $rows[1]['operation_type']);
        self::assertSame($rows[0]['operation_uuid'], $rows[1]['parent_operation_uuid']);
        self::assertSame('succeeded', $rows[1]['remote_status']);
    }

    public function testSuccessfulReverseReplayFromRootNeverCallsTheProviderAgain(): void
    {
        $responses = [
            self::openBatchRefundError(),
            [
                'transactionId' => 51177061,
                'cardBatchId' => 4209764,
                'status' => 'APPROVED',
                'type' => 'purchase',
                'amount' => 21.00,
                'currency' => 'USD',
            ],
            ['id' => 4209764, 'closed' => false],
            self::approved('reverse', '51177124', 21.00),
        ];
        $calls = [];
        $service = $this->service(
            static function (...$args) use (&$responses, &$calls): array|\WP_Error {
                $calls[] = $args;
                $response = array_shift($responses);
                if (!is_array($response) && !$response instanceof \WP_Error) {
                    throw new \RuntimeException('Replay unexpectedly called the provider.');
                }
                return $response;
            }
        );
        $request = $this->request();

        $first = $service->execute($request);
        $second = $service->execute($request);
        $third = $service->execute($request);

        foreach ([$first, $second, $third] as $result) {
            self::assertSame(YSHelcimRefundResult::SUCCEEDED, $result->status());
            self::assertSame('51177124', $result->vendorTransactionId());
            self::assertSame('reverse', $result->providerAction());
            self::assertSame('00000000-0000-4000-8000-000000000099', $result->effectiveOperationUuid());
        }
        self::assertCount(4, $calls);
        self::assertSame(
            ['payment/refund', 'card-transactions/51177061', 'card-batches/4209764', 'payment/reverse'],
            array_column($calls, 0)
        );
        self::assertSame([], $responses);
    }

    /**
     * @dataProvider unsafeReverseEvidence
     */
    public function testIncompleteOrContradictoryProviderEvidenceNeverInvokesReverse(array|\WP_Error $transaction, ?array $batch): void
    {
        $responses = [self::openBatchRefundError(), $transaction];
        if ($batch !== null) {
            $responses[] = $batch;
        }
        $calls = [];
        $service = $this->service(
            static function (...$args) use (&$responses, &$calls): array|\WP_Error {
                $calls[] = $args;
                return array_shift($responses);
            }
        );

        $result = $service->execute($this->request());

        self::assertSame(YSHelcimRefundResult::FAILED, $result->status());
        self::assertNotContains('payment/reverse', array_column($calls, 0));
        self::assertLessThanOrEqual(3, count($calls));
    }

    public function testSuccessfulRetryReusesStoredReceiptWithoutASecondProviderCall(): void
    {
        $calls = [];
        $service = $this->service(
            static function (...$args) use (&$calls): array {
                $calls[] = $args;
                return self::approved('refund', '51177123', 21.00);
            }
        );
        $request = $this->request();

        $first = $service->execute($request);
        $second = $service->execute($request);

        self::assertSame('51177123', $first->vendorTransactionId());
        self::assertSame('51177123', $second->vendorTransactionId());
        self::assertCount(1, $calls);
        self::assertSame($calls[0][3], $this->repository->findByUuid($request['operation_uuid'])['idempotency_key']);
    }

    public function testTerminalReplayIgnoresMutableTotalsModeTokenAndRequestIp(): void
    {
        $calls = [];
        $service = $this->service(
            static function (...$args) use (&$calls): array {
                $calls[] = $args;
                return self::approved('refund', '51177123', 21.00);
            }
        );
        $request = $this->request();
        self::assertSame(YSHelcimRefundResult::SUCCEEDED, $service->execute($request)->status());

        $replay = $request;
        $replay['refunded_total'] = 2100;
        $replay['remaining_refundable'] = 0;
        $replay['current_mode'] = 'live';
        $replay['api_token'] = '';
        $replay['ip_address'] = 'not-an-ip';

        $result = $service->execute($replay);

        self::assertSame(YSHelcimRefundResult::SUCCEEDED, $result->status());
        self::assertSame('51177123', $result->vendorTransactionId());
        self::assertCount(1, $calls);
    }

    public function testIndeterminateReplayDoesNotRequireMutableMutationContext(): void
    {
        $calls = [];
        $service = $this->service(
            static function (...$args) use (&$calls): \WP_Error {
                $calls[] = $args;
                return new \WP_Error('ys_helcim_api_error', 'timeout', ['kind' => 'transport', 'indeterminate' => true]);
            }
        );
        $request = $this->request();
        self::assertSame(YSHelcimRefundResult::INDETERMINATE, $service->execute($request)->status());

        $replay = $request;
        $replay['refunded_total'] = 500;
        $replay['remaining_refundable'] = 1600;
        $replay['current_mode'] = 'live';
        $replay['api_token'] = '';
        $replay['ip_address'] = 'not-an-ip';

        self::assertSame(YSHelcimRefundResult::INDETERMINATE, $service->execute($replay)->status());
        self::assertCount(1, $calls);
    }

    public function testOperationUuidCannotBeReusedForDifferentRefundIdentity(): void
    {
        $calls = [];
        $service = $this->service(
            static function (...$args) use (&$calls): array {
                $calls[] = $args;
                return self::approved('refund', '51177123', 21.00);
            }
        );
        $request = $this->request();
        self::assertInstanceOf(YSHelcimRefundResult::class, $service->execute($request));

        $tampered = $request;
        $tampered['amount'] = 2000;
        $result = $service->execute($tampered);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_operation_conflict', $result->get_error_code());
        self::assertCount(1, $calls);
    }

    public function testOperationUuidCannotChangeItsPersistedLocalEffectsPayload(): void
    {
        $calls = [];
        $service = $this->service(
            static function (...$args) use (&$calls): array {
                $calls[] = $args;
                return self::approved('refund', '51177123', 21.00);
            }
        );
        $request = $this->request([
            'local_payload' => ['reason' => 'First request'],
        ]);
        self::assertSame(YSHelcimRefundResult::SUCCEEDED, $service->execute($request)->status());

        $tampered = $request;
        $tampered['local_payload']['reason'] = 'Changed after provider success';
        $result = $service->execute($tampered);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_operation_conflict', $result->get_error_code());
        self::assertCount(1, $calls);

        $row = $this->repository->findByUuid($request['operation_uuid']);
        self::assertSame('First request', json_decode($row['local_payload'], true, 512, JSON_THROW_ON_ERROR)['reason']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row['local_payload_hash']);
    }

    public function testActiveRefundScopeSerializesDifferentChargesOnTheSameOrder(): void
    {
        $calls = [];
        $service = $this->service(
            static function (...$args) use (&$calls): \WP_Error {
                $calls[] = $args;
                return new \WP_Error('ys_helcim_api_error', 'timeout', ['kind' => 'transport', 'indeterminate' => true]);
            }
        );

        $first = $service->execute($this->request());
        self::assertSame(YSHelcimRefundResult::INDETERMINATE, $first->status());

        $second = $service->execute($this->request([
            'operation_uuid' => '00000000-0000-4000-8000-000000000002',
            'transaction_id' => 21,
            'transaction_uuid' => 'fc-transaction-456',
            'vendor_transaction_id' => '51177062',
        ]));

        self::assertInstanceOf(\WP_Error::class, $second);
        self::assertSame('ys_helcim_scope_busy', $second->get_error_code());
        self::assertCount(1, $calls);
    }

    public function testReverseTransportFailureLeavesChildIndeterminateAndDoesNotRetry(): void
    {
        $responses = [
            self::openBatchRefundError(),
            self::sourceTransaction(),
            ['id' => 4209764, 'closed' => false],
            new \WP_Error('ys_helcim_api_error', 'timeout', ['kind' => 'transport', 'indeterminate' => true]),
        ];
        $calls = [];
        $service = $this->service(
            static function (...$args) use (&$responses, &$calls): array|\WP_Error {
                $calls[] = $args;
                return array_shift($responses);
            }
        );
        $request = $this->request();

        $first = $service->execute($request);
        $second = $service->execute($request);

        self::assertSame(YSHelcimRefundResult::INDETERMINATE, $first->status());
        self::assertSame(YSHelcimRefundResult::INDETERMINATE, $second->status());
        self::assertCount(4, $calls);
        $rows = $this->database->allRows();
        self::assertSame('failed', $rows[0]['remote_status']);
        self::assertSame('indeterminate', $rows[1]['remote_status']);
        self::assertNotNull($rows[1]['active_scope_key']);
    }

    public function testCrashWindowAfterHandoffResumesCreatedChildWithItsStoredKey(): void
    {
        $responses = [
            self::openBatchRefundError(),
            self::sourceTransaction(),
            ['id' => 4209764, 'closed' => false],
            self::approved('reverse', '51177124', 21.00),
        ];
        $calls = [];
        $childUuid = '00000000-0000-4000-8000-000000000099';
        $this->database->failNextUpdateForOperationUuid = $childUuid;
        $service = $this->service(
            static function (...$args) use (&$responses, &$calls): array|\WP_Error {
                $calls[] = $args;
                return array_shift($responses);
            }
        );
        $request = $this->request();

        $first = $service->execute($request);
        self::assertInstanceOf(\WP_Error::class, $first);
        self::assertSame('ys_helcim_journal_unavailable', $first->get_error_code());
        self::assertSame('created', $this->repository->findByUuid($childUuid)['remote_status']);
        $storedKey = $this->repository->findByUuid($childUuid)['idempotency_key'];

        $resumedRequest = $request;
        $resumedRequest['ip_address'] = '192.0.2.99';
        $second = $service->execute($resumedRequest);

        self::assertSame(YSHelcimRefundResult::SUCCEEDED, $second->status());
        self::assertSame($childUuid, $second->effectiveOperationUuid());
        self::assertSame(['payment/refund', 'card-transactions/51177061', 'card-batches/4209764', 'payment/reverse'], array_column($calls, 0));
        self::assertSame($storedKey, $calls[3][3]);
        self::assertSame('127.0.0.1', $calls[3][1]['ipAddress']);
    }

    public function testCreatedChildCannotResumeWithDifferentCredential(): void
    {
        $responses = [self::openBatchRefundError(), self::sourceTransaction(), ['id' => 4209764, 'closed' => false]];
        $calls = [];
        $childUuid = '00000000-0000-4000-8000-000000000099';
        $this->database->failNextUpdateForOperationUuid = $childUuid;
        $service = $this->service(
            static function (...$args) use (&$responses, &$calls): array|\WP_Error {
                $calls[] = $args;
                return array_shift($responses);
            }
        );
        $request = $this->request();
        self::assertInstanceOf(\WP_Error::class, $service->execute($request));

        $wrongCredential = $request;
        $wrongCredential['api_token'] = 'different-account-token';
        $result = $service->execute($wrongCredential);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_operation_conflict', $result->get_error_code());
        self::assertCount(3, $calls);
        self::assertSame('created', $this->repository->findByUuid($childUuid)['remote_status']);
    }

    public function testThrownReadProbeCannotEscapeOrTriggerReverse(): void
    {
        $calls = [];
        $service = $this->service(
            static function (...$args) use (&$calls): array|\WP_Error {
                $calls[] = $args;
                if (count($calls) === 1) {
                    return self::openBatchRefundError();
                }
                throw new \RuntimeException('simulated read failure');
            }
        );

        $result = $service->execute($this->request());

        self::assertSame(YSHelcimRefundResult::FAILED, $result->status());
        self::assertSame(['payment/refund', 'card-transactions/51177061'], array_column($calls, 0));
    }

    public function testReverseApprovalProofMismatchIsIndeterminate(): void
    {
        $responses = [
            self::openBatchRefundError(),
            self::sourceTransaction(),
            ['id' => 4209764, 'closed' => false],
            self::approved('reverse', '', 21.00),
        ];
        $service = $this->service(
            static function (...$args) use (&$responses): array|\WP_Error {
                unset($args);
                return array_shift($responses);
            }
        );

        $result = $service->execute($this->request());

        self::assertSame(YSHelcimRefundResult::INDETERMINATE, $result->status());
        self::assertSame('indeterminate', $this->repository->findByUuid($result->effectiveOperationUuid())['remote_status']);
    }

    public function testFailedAtomicHandoffSendsNoReverse(): void
    {
        $responses = [self::openBatchRefundError(), self::sourceTransaction(), ['id' => 4209764, 'closed' => false]];
        $calls = [];
        $service = $this->service(
            function (...$args) use (&$responses, &$calls): array|\WP_Error {
                $calls[] = $args;
                $response = array_shift($responses);
                if (count($calls) === 3) {
                    $this->database->failNextInsert = true;
                }
                return $response;
            }
        );

        $result = $service->execute($this->request());

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertCount(3, $calls);
        self::assertNotContains('payment/reverse', array_column($calls, 0));
        self::assertCount(1, $this->database->allRows());
        self::assertSame('processing', $this->database->allRows()[0]['remote_status']);
    }

    public function testReentrantConcurrentExecutionSendsOneProviderMutation(): void
    {
        $calls = [];
        $request = $this->request();
        $concurrent = null;
        $service = null;
        $service = $this->service(
            static function (...$args) use (&$calls, &$service, &$concurrent, $request): array {
                $calls[] = $args;
                $concurrent = $service->execute($request);
                return self::approved('refund', '51177123', 21.00);
            }
        );

        $primary = $service->execute($request);

        self::assertSame(YSHelcimRefundResult::SUCCEEDED, $primary->status());
        self::assertInstanceOf(YSHelcimRefundResult::class, $concurrent);
        self::assertSame(YSHelcimRefundResult::INDETERMINATE, $concurrent->status());
        self::assertCount(1, $calls);
    }

    /**
     * @dataProvider invalidRequestValues
     */
    public function testLossyOrStaleRequestValuesAreRejectedBeforeJournalOrProvider(string $field, mixed $value): void
    {
        $calls = [];
        $service = $this->service(
            static function (...$args) use (&$calls): array {
                $calls[] = $args;
                return self::approved('refund', '51177123', 21.00);
            }
        );

        $result = $service->execute($this->request([$field => $value]));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_invalid_refund', $result->get_error_code());
        self::assertCount(0, $calls);
        self::assertCount(0, $this->database->allRows());
    }

    public function testOrderLevelCapMayBeLowerThanTheSourceTransactionBalance(): void
    {
        $calls = [];
        $service = $this->service(
            static function (...$args) use (&$calls): array {
                $calls[] = $args;
                return self::approved('refund', '51177123', 10.00);
            }
        );

        $result = $service->execute($this->request([
            'amount' => 1000,
            'transaction_total' => 2100,
            'refunded_total' => 0,
            'remaining_refundable' => 1000,
        ]));

        self::assertInstanceOf(YSHelcimRefundResult::class, $result);
        self::assertSame(YSHelcimRefundResult::SUCCEEDED, $result->status());
        self::assertCount(1, $calls);
    }

    /** @return array<string, array{array|\WP_Error, array|null}> */
    public static function unsafeReverseEvidence(): array
    {
        $validTransaction = [
            'transactionId' => 51177061,
            'cardBatchId' => 4209764,
            'status' => 'APPROVED',
            'type' => 'purchase',
            'amount' => 21.00,
            'currency' => 'USD',
        ];

        return [
            'transaction lookup transport error' => [
                new \WP_Error('ys_helcim_api_error', 'timeout', ['kind' => 'transport', 'indeterminate' => true]),
                null,
            ],
            'wrong transaction id' => [array_merge($validTransaction, ['transactionId' => 51177062]), null],
            'wrong original amount' => [array_merge($validTransaction, ['amount' => 20.00]), null],
            'wrong original currency' => [array_merge($validTransaction, ['currency' => 'CAD']), null],
            'missing batch id' => [array_diff_key($validTransaction, ['cardBatchId' => true]), null],
            'batch is closed' => [$validTransaction, ['id' => 4209764, 'closed' => true]],
            'batch id mismatch' => [$validTransaction, ['id' => 4209765, 'closed' => false]],
            'batch closed value is not boolean' => [$validTransaction, ['id' => 4209764, 'closed' => 0]],
        ];
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidRequestValues(): array
    {
        return [
            'fractional amount' => ['amount', 2100.9],
            'numeric amount string' => ['amount', '2100'],
            'malformed order id' => ['order_id', '10x'],
            'boolean transaction id' => ['transaction_id', true],
            'stale refundable arithmetic' => ['remaining_refundable', 2000],
            'mode mismatch' => ['current_mode', 'live'],
            'invalid source id' => ['vendor_transaction_id', '51177061x'],
			'source id above platform integer' => ['vendor_transaction_id', (string) PHP_INT_MAX . '0'],
            'invalid ip' => ['ip_address', 'not-an-ip'],
        ];
    }

    private function service(callable $api): YSHelcimRefundService
    {
        return new YSHelcimRefundService(
            $this->repository,
            $api,
            static fn (): string => '00000000-0000-4000-8000-000000000099',
            static fn (): string => '2026-07-21 00:00:00'
        );
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function request(array $overrides = []): array
    {
        return array_merge(
            [
                'operation_uuid' => '00000000-0000-4000-8000-000000000001',
                'gateway' => 'ys_helcim',
                'order_id' => 10,
                'transaction_id' => 20,
                'transaction_uuid' => 'fc-transaction-123',
                'vendor_transaction_id' => '51177061',
                'amount' => 2100,
                'transaction_total' => 2100,
                'refunded_total' => 0,
                'remaining_refundable' => 2100,
                'currency' => 'USD',
                'payment_mode' => 'test',
                'current_mode' => 'test',
                'api_token' => 'unit-test-api-token',
                'ip_address' => '127.0.0.1',
            ],
            $overrides
        );
    }

    /** @return array<string, mixed> */
    private static function approved(string $type, string $transactionId, float $amount): array
    {
        return [
            'status' => 'APPROVED',
            'type' => $type,
            'transactionId' => $transactionId,
            'amount' => $amount,
            'currency' => 'USD',
        ];
    }

    /** @return array<string, mixed> */
    private static function sourceTransaction(): array
    {
        return [
            'transactionId' => 51177061,
            'cardBatchId' => 4209764,
            'status' => 'APPROVED',
            'type' => 'purchase',
            'amount' => 21.00,
            'currency' => 'USD',
        ];
    }

    private static function openBatchRefundError(): \WP_Error
    {
        return new \WP_Error(
            'ys_helcim_api_error',
            'Card Transaction cannot be refunded',
            [
                'kind' => 'provider',
                'http_code' => 400,
                'indeterminate' => false,
                'provider_errors' => ['cardtransactionid' => 'Card Transaction cannot be refunded'],
            ]
        );
    }

    private static function openBatchRefundStringError(): \WP_Error
    {
        return new \WP_Error(
            'ys_helcim_api_error',
            'Card Transaction cannot be refunded',
            [
                'kind' => 'provider',
                'http_code' => 422,
                'indeterminate' => false,
                'provider_errors' => 'Card Transaction cannot be refunded',
            ]
        );
    }
}
