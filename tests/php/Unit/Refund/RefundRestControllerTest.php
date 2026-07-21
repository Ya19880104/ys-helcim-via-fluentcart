<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundRequest;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundRestController;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundResult;
use YangSheep\Helcim\FluentCart\Tests\Doubles\RefundRestRequest;
use YangSheep\Helcim\FluentCart\Tests\Doubles\RefundRestResponse;

final class RefundRestControllerTest extends TestCase
{
    private const ROOT_UUID = '00000000-0000-4000-8000-000000000001';
    private const CHILD_UUID = '00000000-0000-4000-8000-000000000002';

    public function testRegistersOnlyTheProtectedRefundOptionsMutationAndOperationRoutes(): void
    {
        $registered = [];
        $controller = $this->controller(
            routeRegistrar: static function (string $namespace, string $route, array $args) use (&$registered): bool {
                $registered[] = [$namespace, $route, $args];
                return true;
            }
        );

        $controller->registerRoutes();

        self::assertCount(3, $registered);
        self::assertSame('ys-fc-pay/v1', $registered[0][0]);
        self::assertSame('/orders/(?P<order_id>\\d+)/refunds', $registered[0][1]);
        self::assertSame('POST', $registered[0][2]['methods']);
        self::assertSame([$controller, 'create'], $registered[0][2]['callback']);
        self::assertSame([$controller, 'permissionsCheck'], $registered[0][2]['permission_callback']);
        self::assertSame('ys-fc-pay/v1', $registered[1][0]);
        self::assertSame('/orders/(?P<order_id>\d+)/refund-options', $registered[1][1]);
        self::assertSame('GET', $registered[1][2]['methods']);
        self::assertSame([$controller, 'options'], $registered[1][2]['callback']);
        self::assertSame([$controller, 'permissionsCheck'], $registered[1][2]['permission_callback']);
        self::assertSame('ys-fc-pay/v1', $registered[2][0]);
        self::assertSame('/refund-operations/(?P<operation_uuid>[0-9a-f-]{36})', $registered[2][1]);
        self::assertSame('GET', $registered[2][2]['methods']);
        self::assertSame([$controller, 'show'], $registered[2][2]['callback']);
        self::assertSame([$controller, 'permissionsCheck'], $registered[2][2]['permission_callback']);
    }

    public function testPermissionRejectsLoggedOutRequestsBeforeNonceOrFluentCartChecks(): void
    {
        $nonceCalls = 0;
        $permissionCalls = 0;
        $controller = $this->controller(
            loggedIn: static fn (): bool => false,
            nonceVerifier: static function () use (&$nonceCalls): bool {
                ++$nonceCalls;
                return true;
            },
            permissionChecker: static function () use (&$permissionCalls): bool {
                ++$permissionCalls;
                return true;
            }
        );

        $result = $controller->permissionsCheck(new RefundRestRequest(headers: ['X-WP-Nonce' => 'nonce']));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_authentication_required', $result->get_error_code());
        self::assertSame(401, $result->get_error_data()['status']);
        self::assertSame(0, $nonceCalls);
        self::assertSame(0, $permissionCalls);
    }

    public function testPermissionRequiresWpRestNonceBeforeFluentCartPermission(): void
    {
        $verified = [];
        $permissionCalls = 0;
        $controller = $this->controller(
            nonceVerifier: static function (string $nonce, string $action) use (&$verified): bool {
                $verified[] = [$nonce, $action];
                return false;
            },
            permissionChecker: static function () use (&$permissionCalls): bool {
                ++$permissionCalls;
                return true;
            }
        );

        $result = $controller->permissionsCheck(new RefundRestRequest(headers: ['X-WP-Nonce' => 'bad-nonce']));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_invalid_rest_nonce', $result->get_error_code());
        self::assertSame(403, $result->get_error_data()['status']);
        self::assertSame([['bad-nonce', 'wp_rest']], $verified);
        self::assertSame(0, $permissionCalls);
    }

    public function testPermissionRequiresExactFluentCartRefundPermission(): void
    {
        $permissions = [];
        $controller = $this->controller(
            permissionChecker: static function (string $permission) use (&$permissions): bool {
                $permissions[] = $permission;
                return false;
            }
        );

        $result = $controller->permissionsCheck(new RefundRestRequest(headers: ['X-WP-Nonce' => 'valid-nonce']));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_refund_forbidden', $result->get_error_code());
        self::assertSame(403, $result->get_error_data()['status']);
        self::assertSame(['orders/can_refund'], $permissions);
    }

    public function testPermissionAcceptsAllThreeIndependentGates(): void
    {
        $result = $this->controller()->permissionsCheck(
            new RefundRestRequest(headers: ['X-WP-Nonce' => 'valid-nonce'])
        );

        self::assertTrue($result);
    }

    public function testGetRefundOptionsLoadsOnlyTheRouteOrderAndReturnsTheSafeProjection(): void
    {
        $loaded = [];
        $expected = [
            'order_id' => 42,
            'classification' => 'helcim_only',
            'currency' => 'USD',
            'order_remaining' => 2100,
            'transactions' => [[
                'id' => 20,
                'gateway' => 'ys_helcim',
                'payment_mode' => 'test',
                'remaining_refundable' => 2100,
            ]],
            'items' => [],
        ];
        $controller = $this->controller(
            optionsLoader: static function (int $orderId) use (&$loaded, $expected): array {
                $loaded[] = $orderId;
                return $expected;
            }
        );

        $response = $controller->options(new RefundRestRequest(route: ['order_id' => '42']));

        self::assertSame([42], $loaded);
        self::assertSame(200, $response->get_status());
        self::assertSame($expected, $response->get_data());
    }

    public function testGetRefundOptionsFailsClosedForInvalidRouteOrLoaderFailureWithoutLeakingDetails(): void
    {
        $calls = 0;
        $controller = $this->controller(
            optionsLoader: static function () use (&$calls): \WP_Error {
                ++$calls;
                return new \WP_Error(
                    'ys_helcim_refund_options_unavailable',
                    'database password must-not-leak',
                    ['status' => 503]
                );
            }
        );

        $invalid = $controller->options(new RefundRestRequest(route: ['order_id' => '1e2']));
        self::assertSame(422, $invalid->get_status());
        self::assertSame(0, $calls);

        $failed = $controller->options(new RefundRestRequest(route: ['order_id' => '42']));
        self::assertSame(503, $failed->get_status());
        self::assertSame('ys_helcim_refund_options_unavailable', $failed->get_data()['error_code']);
        self::assertStringNotContainsString('password', json_encode($failed->get_data(), JSON_THROW_ON_ERROR));
        self::assertSame(1, $calls);
    }

    public function testGetRefundOptionsExpiresOnlyTheSafeCreatedScopeBeforeLoadingUiState(): void
    {
        $events = [];
        $controller = $this->controller(
            optionsLoader: static function (int $orderId) use (&$events): array {
                $events[] = ['load', $orderId];
                return ['order_id' => $orderId, 'classification' => 'none'];
            },
            staleScopeExpirer: static function (string $scope) use (&$events): int {
                $events[] = ['expire', $scope];
                return 1;
            }
        );

        $response = $controller->options(new RefundRestRequest(route: ['order_id' => '42']));

        self::assertSame(200, $response->get_status());
        self::assertSame([
            ['expire', 'refund-order:42'],
            ['load', 42],
        ], $events);
    }

    public function testGetRefundOptionsFailsClosedWhenCreatedScopeRecoveryIsUnavailable(): void
    {
        $loaderCalls = 0;
        $controller = $this->controller(
            optionsLoader: static function () use (&$loaderCalls): array {
                ++$loaderCalls;
                return [];
            },
            staleScopeExpirer: static fn (): \WP_Error => new \WP_Error(
                'ys_helcim_journal_unavailable',
                'database password must-not-leak',
                ['status' => 503]
            )
        );

        $response = $controller->options(new RefundRestRequest(route: ['order_id' => '42']));

        self::assertSame(503, $response->get_status());
        self::assertSame('ys_helcim_journal_unavailable', $response->get_data()['error_code']);
        self::assertSame(0, $loaderCalls);
        self::assertStringNotContainsString('password', json_encode($response->get_data(), JSON_THROW_ON_ERROR));
    }

    public function testPostBuildsServerContextAndRecordsOnlyTheEffectiveSuccessfulOperation(): void
    {
        $serviceRequests = [];
        $recorded = [];
        $controller = $this->controller(
            serviceExecute: static function (array $request) use (&$serviceRequests): YSHelcimRefundResult {
                $serviceRequests[] = $request;
                return new YSHelcimRefundResult(
                    YSHelcimRefundResult::SUCCEEDED,
                    '51177094',
                    null,
                    null,
                    self::ROOT_UUID,
                    self::CHILD_UUID,
                    'reverse'
                );
            },
            recorder: static function (string $operationUuid) use (&$recorded): array {
                $recorded[] = func_get_args();
                return [
                    'operation_uuid' => $operationUuid,
                    'local_transaction_id' => 44,
                    'local_status' => 'applied',
                    'replayed' => false,
                ];
            }
        );

        $response = $controller->create($this->validRequest());

        self::assertInstanceOf(RefundRestResponse::class, $response);
        self::assertSame(200, $response->get_status());
        self::assertCount(1, $serviceRequests);
        self::assertSame('server-token', $serviceRequests[0]['api_token']);
        self::assertSame('203.0.113.9', $serviceRequests[0]['ip_address']);
        self::assertSame(2100, $serviceRequests[0]['amount']);
        self::assertSame(7, $serviceRequests[0]['local_payload']['actor_user_id']);
        self::assertSame([[self::CHILD_UUID]], $recorded, 'Recorder must receive only the journal-backed effective operation UUID.');
        self::assertSame([
            'operation_uuid' => self::ROOT_UUID,
            'effective_operation_uuid' => self::CHILD_UUID,
            'provider_action' => 'reverse',
            'provider_transaction_id' => '51177094',
            'refund_transaction_id' => 44,
            'remote_status' => 'succeeded',
            'local_status' => 'applied',
            'notification_status' => 'delivered',
            'retry_allowed' => false,
        ], $response->get_data());
    }

    public function testPostReturnsAcceptedWhileLocalOutboxEffectsRemainPending(): void
    {
        $controller = $this->controller(
            serviceExecute: fn (): YSHelcimRefundResult => $this->successfulResult(),
            recorder: static fn (string $operationUuid): array => [
                'operation_uuid' => $operationUuid,
                'local_transaction_id' => 44,
                'local_status' => 'recorded',
                'replayed' => false,
            ]
        );

        $response = $controller->create($this->validRequest());

        self::assertSame(202, $response->get_status());
        self::assertSame('succeeded', $response->get_data()['remote_status']);
        self::assertSame('recorded', $response->get_data()['local_status']);
        self::assertSame('pending', $response->get_data()['notification_status']);
        self::assertFalse($response->get_data()['retry_allowed']);
    }

    public function testPostSurfacesTerminalEffectWarningsWithoutReopeningProviderRetry(): void
    {
        $controller = $this->controller(
            serviceExecute: fn (): YSHelcimRefundResult => $this->successfulResult(),
            recorder: static fn (string $operationUuid): array => [
                'operation_uuid' => $operationUuid,
                'local_transaction_id' => 44,
                'local_status' => 'applied',
                'notification_status' => 'attention_required',
                'effect_status' => 'applied_with_warnings',
                'warnings' => ['refund_hooks'],
                'replayed' => false,
            ]
        );

        $response = $controller->create($this->validRequest());

        self::assertSame(200, $response->get_status());
        self::assertSame('attention_required', $response->get_data()['notification_status']);
        self::assertSame('applied_with_warnings', $response->get_data()['effect_status']);
        self::assertSame(['refund_hooks'], $response->get_data()['warnings']);
        self::assertFalse($response->get_data()['retry_allowed']);
    }

    public function testPostPreservesRemoteSuccessWhenLocalRecordingFailsAndNeverPermitsProviderRetry(): void
    {
        $controller = $this->controller(
            serviceExecute: fn (): YSHelcimRefundResult => $this->successfulResult(),
            recorder: static fn (): \WP_Error => new \WP_Error(
                'ys_helcim_local_recording_failed',
                'database password=must-not-leak'
            )
        );

        $response = $controller->create($this->validRequest());

        self::assertSame(503, $response->get_status());
        self::assertSame('succeeded', $response->get_data()['remote_status']);
        self::assertSame('unknown', $response->get_data()['local_status']);
        self::assertSame('pending', $response->get_data()['notification_status']);
        self::assertFalse($response->get_data()['retry_allowed']);
        self::assertSame('ys_helcim_local_recording_failed', $response->get_data()['error_code']);
        self::assertStringNotContainsString('password', json_encode($response->get_data(), JSON_THROW_ON_ERROR));
    }

    public function testPostMapsLocalAccountingDriftToConflictWithoutPermittingProviderRetry(): void
    {
        $controller = $this->controller(
            serviceExecute: fn (): YSHelcimRefundResult => $this->successfulResult(),
            recorder: static fn (): \WP_Error => new \WP_Error(
                'ys_helcim_accounting_drift',
                'Local totals changed after provider success.'
            )
        );

        $response = $controller->create($this->validRequest());

        self::assertSame(409, $response->get_status());
        self::assertSame('succeeded', $response->get_data()['remote_status']);
        self::assertSame('unknown', $response->get_data()['local_status']);
        self::assertFalse($response->get_data()['retry_allowed']);
        self::assertSame('ys_helcim_accounting_drift', $response->get_data()['error_code']);
    }

    public function testPostRecoversACommittedRefundWhenTheFirstCommitAcknowledgementWasLost(): void
    {
        $recorderCalls = 0;
        $failureCalls = 0;
        $controller = $this->controller(
            serviceExecute: fn (): YSHelcimRefundResult => $this->successfulResult(),
            recorder: static function (string $operationUuid) use (&$recorderCalls): array|\WP_Error {
                ++$recorderCalls;
                if ($recorderCalls === 1) {
                    return new \WP_Error('ys_helcim_local_storage_unavailable', 'Commit acknowledgement lost.');
                }
                return [
                    'operation_uuid' => $operationUuid,
                    'local_transaction_id' => 44,
                    'local_status' => 'recorded',
                    'replayed' => true,
                ];
            },
            operationReader: static fn (): array => [
                'operation_uuid' => self::ROOT_UUID,
                'remote_status' => 'succeeded',
                'local_status' => 'recorded',
                'local_transaction_id' => 44,
            ],
            localFailureRecorder: static function () use (&$failureCalls): bool {
                ++$failureCalls;
                return true;
            }
        );

        $response = $controller->create($this->validRequest());

        self::assertSame(202, $response->get_status());
        self::assertSame('recorded', $response->get_data()['local_status']);
        self::assertSame(2, $recorderCalls);
        self::assertSame(0, $failureCalls);
    }

    public function testPostDurablyMarksARolledBackLocalFailureWithoutAllowingProviderRetry(): void
    {
        $failures = [];
        $controller = $this->controller(
            serviceExecute: fn (): YSHelcimRefundResult => $this->successfulResult(),
            recorder: static fn (): \WP_Error => new \WP_Error(
                'ys_helcim_accounting_drift',
                'database password=must-not-leak'
            ),
            operationReader: static fn (): array => [
                'operation_uuid' => self::ROOT_UUID,
                'remote_status' => 'succeeded',
                'local_status' => 'pending',
            ],
            localFailureRecorder: static function (string $uuid, string $code, string $message) use (&$failures): bool {
                $failures[] = [$uuid, $code, $message];
                return true;
            }
        );

        $response = $controller->create($this->validRequest());

        self::assertSame(409, $response->get_status());
        self::assertSame('succeeded', $response->get_data()['remote_status']);
        self::assertSame('failed', $response->get_data()['local_status']);
        self::assertFalse($response->get_data()['retry_allowed']);
        self::assertCount(1, $failures);
        self::assertSame(self::ROOT_UUID, $failures[0][0]);
        self::assertSame('ys_helcim_accounting_drift', $failures[0][1]);
        self::assertStringNotContainsString('password', json_encode($response->get_data(), JSON_THROW_ON_ERROR));
    }

    public function testPostReturns202ForIndeterminateProviderOutcomeWithoutRecordingLocally(): void
    {
        $recorderCalls = 0;
        $controller = $this->controller(
            serviceExecute: static fn (): YSHelcimRefundResult => new YSHelcimRefundResult(
                YSHelcimRefundResult::INDETERMINATE,
                null,
                'provider_timeout',
                'The provider outcome is unknown.',
                self::ROOT_UUID,
                self::ROOT_UUID,
                'refund'
            ),
            recorder: static function () use (&$recorderCalls): array {
                ++$recorderCalls;
                return [];
            }
        );

        $response = $controller->create($this->validRequest());

        self::assertSame(202, $response->get_status());
        self::assertSame('indeterminate', $response->get_data()['remote_status']);
        self::assertSame('pending', $response->get_data()['local_status']);
        self::assertFalse($response->get_data()['retry_allowed']);
        self::assertSame(0, $recorderCalls);
    }

    #[DataProvider('serviceErrorStatuses')]
    public function testPostMapsServiceErrorsWithoutLeakingTheirMessages(string $code, int $status): void
    {
        $controller = $this->controller(
            serviceExecute: static fn (): \WP_Error => new \WP_Error($code, 'secret API token must-not-leak')
        );

        $response = $controller->create($this->validRequest());

        self::assertSame($status, $response->get_status());
        self::assertSame($code, $response->get_data()['error_code']);
        self::assertStringNotContainsString('secret', json_encode($response->get_data(), JSON_THROW_ON_ERROR));
    }

    /** @return array<string, array{string, int}> */
    public static function serviceErrorStatuses(): array
    {
        return [
            'operation conflict' => ['ys_helcim_operation_conflict', 409],
            'scope busy' => ['ys_helcim_scope_busy', 409],
            'credential drift' => ['ys_helcim_credential_changed', 409],
            'accounting drift' => ['ys_helcim_accounting_drift', 409],
            'invalid refund' => ['ys_helcim_invalid_refund', 422],
            'journal unavailable' => ['ys_helcim_journal_unavailable', 503],
            'context unavailable' => ['ys_helcim_refund_context_unavailable', 503],
            'unknown failure fails closed' => ['ys_helcim_unexpected_failure', 503],
        ];
    }

    #[DataProvider('definiteProviderOutcomes')]
    public function testPostMapsDefiniteProviderFailureTo422AndAllowsCorrectedNewAttempt(string $outcome): void
    {
        $recorderCalls = 0;
        $controller = $this->controller(
            serviceExecute: static fn (): YSHelcimRefundResult => new YSHelcimRefundResult(
                $outcome,
                null,
                'provider_' . $outcome,
                'Provider did not approve the refund.',
                self::ROOT_UUID,
                self::ROOT_UUID,
                'refund'
            ),
            recorder: static function () use (&$recorderCalls): array {
                ++$recorderCalls;
                return [];
            }
        );

        $response = $controller->create($this->validRequest());

        self::assertSame(422, $response->get_status());
        self::assertSame($outcome, $response->get_data()['remote_status']);
        self::assertTrue($response->get_data()['retry_allowed']);
        self::assertSame(0, $recorderCalls);
    }

    /** @return array<string, array{string}> */
    public static function definiteProviderOutcomes(): array
    {
        return [
            'declined' => [YSHelcimRefundResult::DECLINED],
            'failed' => [YSHelcimRefundResult::FAILED],
        ];
    }

    public function testPostNeverCallsServiceWhenStrictRequestValidationFails(): void
    {
        $serviceCalls = 0;
        $controller = $this->controller(
            serviceExecute: function () use (&$serviceCalls): YSHelcimRefundResult {
                ++$serviceCalls;
                return $this->successfulResult();
            }
        );
        $request = $this->validRequest(['amount' => 21.0]);

        $response = $controller->create($request);

        self::assertSame(422, $response->get_status());
        self::assertSame('ys_helcim_invalid_refund_request', $response->get_data()['error_code']);
        self::assertSame(0, $serviceCalls);
    }

    public function testGetMapsTheEffectiveReverseRowToTheStablePublicEnvelope(): void
    {
        $read = [];
        $controller = $this->controller(
            operationReader: static function (string $uuid) use (&$read): array {
                $read[] = $uuid;
                return [
                    'operation_uuid' => self::CHILD_UUID,
                    'parent_operation_uuid' => self::ROOT_UUID,
                    'operation_type' => 'reverse',
                    'vendor_transaction_id' => '51177094',
                    'remote_status' => 'succeeded',
                    'local_status' => 'recorded',
                    'local_transaction_id' => 44,
                ];
            }
        );

        $response = $controller->show(new RefundRestRequest(route: ['operation_uuid' => self::CHILD_UUID]));

        self::assertSame(200, $response->get_status());
        self::assertSame([self::CHILD_UUID], $read);
        self::assertSame([
            'operation_uuid' => self::ROOT_UUID,
            'effective_operation_uuid' => self::CHILD_UUID,
            'provider_action' => 'reverse',
            'provider_transaction_id' => '51177094',
            'refund_transaction_id' => 44,
            'remote_status' => 'succeeded',
            'local_status' => 'recorded',
            'notification_status' => 'pending',
            'retry_allowed' => false,
        ], $response->get_data());
    }

    public function testGetUsesDurableEffectStateInsteadOfAssumingAppliedHooksWereDelivered(): void
    {
        $controller = $this->controller(
            operationReader: static fn (): array => [
                'operation_uuid' => self::ROOT_UUID,
                'parent_operation_uuid' => null,
                'operation_type' => 'refund',
                'vendor_transaction_id' => '51177094',
                'remote_status' => 'succeeded',
                'local_status' => 'applied',
                'local_transaction_id' => 44,
            ],
            effectStateReader: static fn (): array => [
                'status' => 'applied_with_warnings',
                'local_status' => 'applied',
                'notification_status' => 'attention_required',
                'warnings' => ['refund_hooks'],
                'effect_statuses' => [
                    'stock_restore' => 'skipped',
                    'customer_recount' => 'completed',
                    'refund_hooks' => 'indeterminate',
                ],
                'replayed' => true,
            ]
        );

        $response = $controller->show(new RefundRestRequest(route: ['operation_uuid' => self::ROOT_UUID]));

        self::assertSame(200, $response->get_status());
        self::assertSame('attention_required', $response->get_data()['notification_status']);
        self::assertSame('applied_with_warnings', $response->get_data()['effect_status']);
        self::assertSame(['refund_hooks'], $response->get_data()['warnings']);
    }

    public function testGetFailsClosedWhenDurableEffectStateCannotBeRead(): void
    {
        $controller = $this->controller(
            operationReader: static fn (): array => [
                'operation_uuid' => self::ROOT_UUID,
                'parent_operation_uuid' => null,
                'operation_type' => 'refund',
                'vendor_transaction_id' => '51177094',
                'remote_status' => 'succeeded',
                'local_status' => 'applied',
                'local_transaction_id' => 44,
            ],
            effectStateReader: static fn (): \WP_Error => new \WP_Error(
                'ys_helcim_outbox_read_failed',
                'database password must-not-leak'
            )
        );

        $response = $controller->show(new RefundRestRequest(route: ['operation_uuid' => self::ROOT_UUID]));

        self::assertSame(503, $response->get_status());
        self::assertSame('ys_helcim_effect_state_unavailable', $response->get_data()['error_code']);
        self::assertStringNotContainsString('password', json_encode($response->get_data(), JSON_THROW_ON_ERROR));
    }

    public function testGetRejectsContradictoryDurableEffectState(): void
    {
        $controller = $this->controller(
            operationReader: static fn (): array => [
                'operation_uuid' => self::ROOT_UUID,
                'parent_operation_uuid' => null,
                'operation_type' => 'refund',
                'vendor_transaction_id' => '51177094',
                'remote_status' => 'succeeded',
                'local_status' => 'applied',
                'local_transaction_id' => 44,
            ],
            effectStateReader: static fn (): array => [
                'status' => 'applied',
                'local_status' => 'recorded',
                'notification_status' => 'delivered',
                'warnings' => [],
            ]
        );

        $response = $controller->show(new RefundRestRequest(route: ['operation_uuid' => self::ROOT_UUID]));

        self::assertSame(503, $response->get_status());
        self::assertSame('ys_helcim_effect_state_unavailable', $response->get_data()['error_code']);
    }

    public function testGetReturns404ForMissingOperation(): void
    {
        $controller = $this->controller(operationReader: static fn (): null => null);

        $response = $controller->show(new RefundRestRequest(route: ['operation_uuid' => self::ROOT_UUID]));

        self::assertSame(404, $response->get_status());
        self::assertSame('ys_helcim_operation_not_found', $response->get_data()['error_code']);
    }

    public function testGetRejectsMalformedUuidBeforeReadingJournal(): void
    {
        $readCalls = 0;
        $controller = $this->controller(
            operationReader: static function () use (&$readCalls): null {
                ++$readCalls;
                return null;
            }
        );

        $response = $controller->show(new RefundRestRequest(route: ['operation_uuid' => '../../secret']));

        self::assertSame(422, $response->get_status());
        self::assertSame(0, $readCalls);
    }

    private function controller(
        ?callable $serviceExecute = null,
        ?callable $recorder = null,
        ?callable $operationReader = null,
        ?callable $loggedIn = null,
        ?callable $nonceVerifier = null,
        ?callable $permissionChecker = null,
        ?callable $routeRegistrar = null,
        ?callable $localFailureRecorder = null,
        ?callable $effectStateReader = null,
        ?callable $optionsLoader = null,
        ?callable $staleScopeExpirer = null
    ): YSHelcimRefundRestController {
        return new YSHelcimRefundRestController(
            $this->requestBuilder(),
            $serviceExecute ?? fn (): YSHelcimRefundResult => $this->successfulResult(),
            $recorder ?? static fn (string $operationUuid): array => [
                'operation_uuid' => $operationUuid,
                'local_transaction_id' => 44,
                'local_status' => 'applied',
                'replayed' => false,
            ],
            $operationReader ?? static fn (): null => null,
            $loggedIn ?? static fn (): bool => true,
            $nonceVerifier ?? static fn (string $nonce, string $action): bool => $nonce === 'valid-nonce' && $action === 'wp_rest',
            $permissionChecker ?? static fn (string $permission): bool => $permission === 'orders/can_refund',
            static fn (): int => 7,
            $routeRegistrar,
            static fn (array $data, int $status): RefundRestResponse => new RefundRestResponse($data, $status),
            $localFailureRecorder,
            $effectStateReader,
            $optionsLoader,
            $staleScopeExpirer
        );
    }

    private function requestBuilder(): YSHelcimRefundRequest
    {
        return new YSHelcimRefundRequest(
            static fn (): array => [
                'order_id' => 10,
                'transaction_id' => 20,
                'transaction_uuid' => 'fc-transaction-123',
                'vendor_transaction_id' => '51177061',
                'gateway' => 'ys_helcim',
                'status' => 'succeeded',
                'transaction_type' => 'charge',
                'transaction_total' => 2100,
                'refunded_total' => 0,
                'remaining_refundable' => 2100,
                'currency' => 'USD',
                'payment_mode' => 'test',
                'order_item_quantities' => [101 => 2],
            ],
            static fn (): array => ['current_mode' => 'test', 'api_token' => 'server-token'],
            static fn (): string => '203.0.113.9',
            static fn (): bool => true
        );
    }

    /** @param array<string, mixed> $overrides */
    private function validRequest(array $overrides = []): RefundRestRequest
    {
        return new RefundRestRequest(
            array_merge([
                'operation_uuid' => self::ROOT_UUID,
                'transaction_id' => 20,
                'amount' => '21.00',
                'reason' => 'Customer request',
                'item_ids' => [],
                'manage_stock' => false,
                'refunded_items' => [],
                'cancel_subscription' => false,
            ], $overrides),
            ['order_id' => '10']
        );
    }

    private function successfulResult(): YSHelcimRefundResult
    {
        return new YSHelcimRefundResult(
            YSHelcimRefundResult::SUCCEEDED,
            '51177094',
            null,
            null,
            self::ROOT_UUID,
            self::ROOT_UUID,
            'refund'
        );
    }
}
