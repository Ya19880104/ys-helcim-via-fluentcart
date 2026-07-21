<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundResolutionRestController;
use YangSheep\Helcim\FluentCart\Tests\Doubles\RefundResolutionRestRequest;
use YangSheep\Helcim\FluentCart\Tests\Doubles\RefundResolutionRestResponse;

final class RefundResolutionRestControllerTest extends TestCase
{
    private const OPERATION_UUID = '11111111-2222-4333-8444-555555555555';

    public function testItRegistersOnlyTwoExplicitPositiveResolutionPostRoutesWithStrictSchemas(): void
    {
        $registered = [];
        $controller = $this->controller(
            routeRegistrar: static function (string $namespace, string $route, array $args) use (&$registered): bool {
                $registered[] = compact('namespace', 'route', 'args');
                return true;
            }
        );

        $controller->registerRoutes();

        self::assertCount(2, $registered);
        self::assertSame(YSHelcimRefundResolutionRestController::REST_NAMESPACE, $registered[0]['namespace']);
        self::assertSame(YSHelcimRefundResolutionRestController::INSPECT_ROUTE, $registered[0]['route']);
        self::assertSame('POST', $registered[0]['args']['methods']);
        self::assertSame([$controller, 'inspect'], $registered[0]['args']['callback']);
        self::assertSame([$controller, 'permissionsCheck'], $registered[0]['args']['permission_callback']);
        self::assertSame(['operation_uuid', 'candidate_transaction_id'], array_keys($registered[0]['args']['args']));
        self::assertSame('string', $registered[0]['args']['args']['operation_uuid']['type']);
        self::assertTrue($registered[0]['args']['args']['operation_uuid']['required']);
        self::assertSame('^[1-9][0-9]*$', $registered[0]['args']['args']['candidate_transaction_id']['pattern']);

        self::assertSame(YSHelcimRefundResolutionRestController::COMMIT_ROUTE, $registered[1]['route']);
        self::assertSame('POST', $registered[1]['args']['methods']);
        self::assertSame([$controller, 'commit'], $registered[1]['args']['callback']);
        self::assertSame([$controller, 'permissionsCheck'], $registered[1]['args']['permission_callback']);
        self::assertSame(
            ['operation_uuid', 'candidate_transaction_id', 'challenge', 'confirmation_phrase', 'parent_attestation'],
            array_keys($registered[1]['args']['args'])
        );
        self::assertSame('^(?:[a-f0-9]{2}){32,64}$', $registered[1]['args']['args']['challenge']['pattern']);
        self::assertSame('boolean', $registered[1]['args']['args']['parent_attestation']['type']);

        self::assertStringNotContainsString('unlock', strtolower(implode(' ', array_column($registered, 'route'))));
        self::assertStringNotContainsString('negative', strtolower(implode(' ', array_column($registered, 'route'))));
    }

    public function testPermissionCallbackRequiresAuthenticationNonceWordPressAdminAndFluentCartRefundPermission(): void
    {
        $wpCapabilities = [];
        $fluentPermissions = [];
        $controller = $this->controller(
            wpCapabilityChecker: static function (string $capability) use (&$wpCapabilities): bool {
                $wpCapabilities[] = $capability;
                return $capability === 'manage_options';
            },
            fluentPermissionChecker: static function (string $permission) use (&$fluentPermissions): bool {
                $fluentPermissions[] = $permission;
                return $permission === 'orders/can_refund';
            }
        );

        $result = $controller->permissionsCheck(new RefundResolutionRestRequest());

        self::assertTrue($result);
        self::assertSame(['manage_options'], $wpCapabilities);
        self::assertSame(['orders/can_refund'], $fluentPermissions);
    }

    #[DataProvider('deniedPermissionProvider')]
    public function testPermissionCallbackFailsClosedForEveryMissingOrBrokenGate(
        bool $loggedIn,
        mixed $nonce,
        mixed $nonceResult,
        mixed $wpCapability,
        mixed $fluentPermission,
        int $expectedStatus,
        string $expectedCode
    ): void {
        $callback = static function (mixed $result): callable {
            return static function () use ($result): mixed {
                if ($result instanceof \Throwable) {
                    throw $result;
                }
                return $result;
            };
        };
        $controller = $this->controller(
            isLoggedIn: $callback($loggedIn),
            nonceVerifier: $callback($nonceResult),
            wpCapabilityChecker: $callback($wpCapability),
            fluentPermissionChecker: $callback($fluentPermission)
        );
        $request = new RefundResolutionRestRequest(headers: ['X-WP-Nonce' => $nonce]);

        $result = $controller->permissionsCheck($request);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame($expectedCode, $result->get_error_code());
        self::assertSame($expectedStatus, $result->get_error_data()['status']);
    }

    public static function deniedPermissionProvider(): iterable
    {
        yield 'not logged in' => [false, 'valid-nonce', true, true, true, 401, 'ys_helcim_resolution_authentication_required'];
        yield 'nonce missing' => [true, '', true, true, true, 403, 'ys_helcim_resolution_invalid_rest_nonce'];
        yield 'nonce not text' => [true, ['bad'], true, true, true, 403, 'ys_helcim_resolution_invalid_rest_nonce'];
        yield 'nonce rejected' => [true, 'bad', false, true, true, 403, 'ys_helcim_resolution_invalid_rest_nonce'];
        yield 'wordpress capability false' => [true, 'valid-nonce', true, false, true, 403, 'ys_helcim_resolution_forbidden'];
        yield 'wordpress capability non-boolean truthy' => [true, 'valid-nonce', true, 1, true, 403, 'ys_helcim_resolution_forbidden'];
        yield 'fluent permission false' => [true, 'valid-nonce', true, true, false, 403, 'ys_helcim_resolution_forbidden'];
        yield 'fluent permission non-boolean truthy' => [true, 'valid-nonce', true, true, 1, 403, 'ys_helcim_resolution_forbidden'];
        yield 'capability checker throws' => [true, 'valid-nonce', true, new \RuntimeException('boom'), true, 403, 'ys_helcim_resolution_forbidden'];
        yield 'permission checker throws' => [true, 'valid-nonce', true, true, new \RuntimeException('boom'), 403, 'ys_helcim_resolution_forbidden'];
    }

    public function testInspectPassesOnlyCanonicalRouteBodyAndActorToServiceAndReturnsAWhitelistedChallenge(): void
    {
        $calls = [];
        $result = $this->validInspectResult() + [
            'proof_digest' => str_repeat('d', 64),
            'api_token' => 'must-not-leak',
            'credential' => ['secret' => 'must-not-leak'],
        ];
        $controller = $this->controller(
            inspect: static function (string $uuid, string $candidate, int $actor) use (&$calls, $result): array {
                $calls[] = compact('uuid', 'candidate', 'actor');
                return $result;
            }
        );

        $response = $controller->inspect($this->inspectRequest());

        self::assertInstanceOf(RefundResolutionRestResponse::class, $response);
        self::assertSame(200, $response->get_status());
        self::assertSame([[
            'uuid' => self::OPERATION_UUID,
            'candidate' => '51177094',
            'actor' => 7,
        ]], $calls);
        self::assertSame([
            'status' => 'confirmation_required',
            'operation_uuid' => self::OPERATION_UUID,
            'candidate_transaction_id' => '51177094',
            'source_transaction_id' => '51177061',
            'action' => 'resolve_positive',
            'parent_attestation_required' => false,
            'challenge' => str_repeat('a5', 32),
            'challenge_expires_at' => '2026-07-21 01:05:00',
            'confirmation_phrase' => 'RESOLVE ' . self::OPERATION_UUID . ' WITH HELCIM 51177094',
        ], $response->get_data());
        self::assertStringNotContainsString('must-not-leak', json_encode($response->get_data(), JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('proof_digest', $response->get_data());
    }

    #[DataProvider('invalidInspectRequestProvider')]
    public function testInspectRejectsNonCanonicalOrNonExactRequestsBeforeCallingService(mixed $request): void
    {
        $calls = 0;
        $controller = $this->controller(inspect: static function () use (&$calls): array {
            ++$calls;
            return [];
        });

        $response = $controller->inspect($request);

        self::assertSame(0, $calls);
        $this->assertErrorResponse($response, 422, 'ys_helcim_invalid_resolution_request');
    }

    public static function invalidInspectRequestProvider(): iterable
    {
        yield 'not a request' => [null];
        yield 'missing route uuid' => [new RefundResolutionRestRequest(body: ['candidate_transaction_id' => '51177094'])];
        yield 'uppercase uuid' => [new RefundResolutionRestRequest(
            body: ['candidate_transaction_id' => '51177094'],
            route: ['operation_uuid' => 'AAAAAAAA-2222-4333-8444-555555555555']
        )];
        yield 'unknown route input' => [new RefundResolutionRestRequest(
            body: ['candidate_transaction_id' => '51177094'],
            route: ['operation_uuid' => self::OPERATION_UUID, 'unlock' => true]
        )];
        yield 'missing body input' => [new RefundResolutionRestRequest(route: ['operation_uuid' => self::OPERATION_UUID])];
        yield 'unknown body input' => [new RefundResolutionRestRequest(
            body: ['candidate_transaction_id' => '51177094', 'api_token' => 'secret'],
            route: ['operation_uuid' => self::OPERATION_UUID]
        )];
        yield 'integer candidate' => [new RefundResolutionRestRequest(
            body: ['candidate_transaction_id' => 51177094],
            route: ['operation_uuid' => self::OPERATION_UUID]
        )];
        yield 'candidate with leading zero' => [new RefundResolutionRestRequest(
            body: ['candidate_transaction_id' => '051177094'],
            route: ['operation_uuid' => self::OPERATION_UUID]
        )];
        yield 'candidate beyond platform integer' => [new RefundResolutionRestRequest(
            body: ['candidate_transaction_id' => '999999999999999999999999999999'],
            route: ['operation_uuid' => self::OPERATION_UUID]
        )];
    }

    public function testInspectFailsClosedWhenCurrentActorCannotBeProven(): void
    {
        foreach ([0, '7', new \RuntimeException('actor lookup failed')] as $actor) {
            $calls = 0;
            $currentUser = static function () use ($actor): mixed {
                if ($actor instanceof \Throwable) {
                    throw $actor;
                }
                return $actor;
            };
            $controller = $this->controller(
                inspect: static function () use (&$calls): array {
                    ++$calls;
                    return [];
                },
                currentUserId: $currentUser
            );

            $response = $controller->inspect($this->inspectRequest());

            self::assertSame(0, $calls);
            $this->assertErrorResponse($response, 403, 'ys_helcim_resolution_forbidden');
        }
    }

    #[DataProvider('serviceErrorProvider')]
    public function testInspectMapsServiceErrorsWithoutReturningProviderOrCredentialMessages(
        \WP_Error $error,
        int $expectedStatus
    ): void {
        $controller = $this->controller(inspect: static fn (): \WP_Error => $error);

        $response = $controller->inspect($this->inspectRequest());

        $this->assertErrorResponse($response, $expectedStatus, $error->get_error_code());
        self::assertStringNotContainsString('raw-secret-message', json_encode($response->get_data(), JSON_THROW_ON_ERROR));
    }

    public static function serviceErrorProvider(): iterable
    {
        yield 'proof mismatch' => [new \WP_Error('ys_helcim_resolution_proof_mismatch', 'raw-secret-message'), 422];
        yield 'confirmation mismatch' => [new \WP_Error('ys_helcim_resolution_confirmation_mismatch', 'raw-secret-message'), 422];
        yield 'attestation required' => [new \WP_Error('ys_helcim_resolution_attestation_required', 'raw-secret-message'), 422];
        yield 'operation conflict' => [new \WP_Error('ys_helcim_resolution_operation_conflict', 'raw-secret-message'), 409];
        yield 'candidate used' => [new \WP_Error('ys_helcim_resolution_candidate_used', 'raw-secret-message'), 409];
        yield 'not found' => [new \WP_Error('ys_helcim_resolution_not_found', 'raw-secret-message'), 404];
        yield 'provider unavailable' => [new \WP_Error('ys_helcim_resolution_provider_unavailable', 'raw-secret-message'), 503];
        yield 'store unavailable' => [new \WP_Error('ys_helcim_resolution_store_unavailable', 'raw-secret-message'), 503];
        yield 'challenge unavailable' => [new \WP_Error('ys_helcim_resolution_challenge_unavailable', 'raw-secret-message'), 503];
        yield 'unknown failure' => [new \WP_Error('upstream_token_failure', 'raw-secret-message'), 503];
        yield 'allowed explicit status' => [new \WP_Error('custom_safe_failure', 'raw-secret-message', ['status' => 403]), 403];
        yield 'disallowed explicit status ignored' => [new \WP_Error('custom_safe_failure', 'raw-secret-message', ['status' => 418]), 503];
    }

    #[DataProvider('invalidInspectServiceResultProvider')]
    public function testInspectFailsClosedForThrownOrMalformedServiceResults(mixed $serviceResult): void
    {
        $controller = $this->controller(inspect: static function () use ($serviceResult): mixed {
            if ($serviceResult instanceof \Throwable) {
                throw $serviceResult;
            }
            return $serviceResult;
        });

        $response = $controller->inspect($this->inspectRequest());

        $this->assertErrorResponse($response, 503, 'ys_helcim_resolution_service_unavailable');
    }

    public static function invalidInspectServiceResultProvider(): iterable
    {
        yield 'exception' => [new \RuntimeException('secret exception')];
        yield 'not array' => ['not-an-array'];
        yield 'wrong operation' => [self::validInspectResult(['operation_uuid' => '22222222-2222-4333-8444-555555555555'])];
        yield 'wrong candidate' => [self::validInspectResult(['candidate_transaction_id' => '51177095'])];
        yield 'candidate equals source' => [self::validInspectResult(['source_transaction_id' => '51177094'])];
        yield 'invalid action' => [self::validInspectResult(['action' => 'unlock_negative'])];
        yield 'invalid challenge' => [self::validInspectResult(['challenge' => str_repeat('A5', 32)])];
        yield 'invalid expiry' => [self::validInspectResult(['challenge_expires_at' => 'next Tuesday'])];
        yield 'inconsistent phrase' => [self::validInspectResult(['confirmation_phrase' => 'RESOLVE SOMETHING ELSE'])];
        yield 'missing proof digest' => [array_diff_key(self::validInspectResult(), ['proof_digest' => true])];
    }

    public function testCommitPassesOnlyExactCanonicalConfirmationToServiceAndReturnsSafeAppliedState(): void
    {
        $calls = [];
        $controller = $this->controller(
            commit: static function (array $request, int $actor) use (&$calls): array {
                $calls[] = compact('request', 'actor');
                return [
                    'status' => 'resolved',
                    'operation_uuid' => self::OPERATION_UUID,
                    'remote_status' => 'succeeded',
                    'replayed' => false,
                    'local_recording_status' => 'continued',
                    'local' => [
                        'operation_uuid' => self::OPERATION_UUID,
                        'local_status' => 'applied',
                        'local_transaction_id' => 41,
                        'notification_status' => 'delivered',
                        'effect_status' => 'applied',
                        'warnings' => [],
                        'manual_reconciliation_required' => false,
                        'api_token' => 'nested-secret',
                    ],
                    'credential' => 'top-level-secret',
                ];
            }
        );

        $response = $controller->commit($this->commitRequest());

        self::assertSame(200, $response->get_status());
        self::assertSame([[
            'request' => $this->validCommitBody() + ['operation_uuid' => self::OPERATION_UUID],
            'actor' => 7,
        ]], $calls);
        self::assertSame([
            'status' => 'resolved',
            'operation_uuid' => self::OPERATION_UUID,
            'remote_status' => 'succeeded',
            'replayed' => false,
            'local_recording_status' => 'continued',
            'local_status' => 'applied',
            'local_transaction_id' => 41,
            'notification_status' => 'delivered',
            'effect_status' => 'applied',
            'warnings' => [],
            'manual_reconciliation_required' => false,
        ], $response->get_data());
        self::assertStringNotContainsString('secret', json_encode($response->get_data(), JSON_THROW_ON_ERROR));
    }

    public function testInspectAndCommitBindTheExplicitParentAttestationPhrase(): void
    {
        $inspectResult = self::validInspectResult([
            'parent_attestation_required' => true,
            'confirmation_phrase' => 'ATTEST AND RESOLVE ' . self::OPERATION_UUID . ' WITH HELCIM 51177094',
        ]);
        $commitCalls = [];
        $controller = $this->controller(
            inspect: static fn (): array => $inspectResult,
            commit: static function (array $request, int $actor) use (&$commitCalls): array {
                $commitCalls[] = compact('request', 'actor');
                return [
                    'status' => 'resolved',
                    'operation_uuid' => self::OPERATION_UUID,
                    'remote_status' => 'succeeded',
                    'replayed' => false,
                    'local_recording_status' => 'continued',
                    'local' => ['local_status' => 'applied'],
                ];
            }
        );

        $inspectResponse = $controller->inspect($this->inspectRequest());
        $body = self::validCommitBody();
        $body['parent_attestation'] = true;
        $body['confirmation_phrase'] = $inspectResponse->get_data()['confirmation_phrase'];
        $commitResponse = $controller->commit(new RefundResolutionRestRequest(
            body: $body,
            route: ['operation_uuid' => self::OPERATION_UUID]
        ));

        self::assertSame(200, $inspectResponse->get_status());
        self::assertTrue($inspectResponse->get_data()['parent_attestation_required']);
        self::assertSame(200, $commitResponse->get_status());
        self::assertSame(true, $commitCalls[0]['request']['parent_attestation']);
        self::assertSame($body['confirmation_phrase'], $commitCalls[0]['request']['confirmation_phrase']);
        self::assertSame(7, $commitCalls[0]['actor']);
    }

    #[DataProvider('commitOutcomeProvider')]
    public function testCommitUses202UntilLocalRecordingIsApplied(array $result, int $expectedStatus, array $expected): void
    {
        $controller = $this->controller(commit: static fn (): array => $result);

        $response = $controller->commit($this->commitRequest());

        self::assertSame($expectedStatus, $response->get_status());
        self::assertSame($expected, $response->get_data());
    }

    public static function commitOutcomeProvider(): iterable
    {
        yield 'local attention required' => [[
            'status' => 'resolved',
            'operation_uuid' => self::OPERATION_UUID,
            'remote_status' => 'succeeded',
            'replayed' => false,
            'local_recording_status' => 'attention_required',
            'local_error_code' => 'ys_helcim_local_recording_failed',
            'raw_error' => 'do not return',
        ], 202, [
            'status' => 'resolved',
            'operation_uuid' => self::OPERATION_UUID,
            'remote_status' => 'succeeded',
            'replayed' => false,
            'local_recording_status' => 'attention_required',
            'local_error_code' => 'ys_helcim_local_recording_failed',
        ]];
        yield 'recorded but effects pending' => [[
            'status' => 'resolved',
            'operation_uuid' => self::OPERATION_UUID,
            'remote_status' => 'succeeded',
            'replayed' => false,
            'local_recording_status' => 'continued',
            'local' => ['local_status' => 'recorded'],
        ], 202, [
            'status' => 'resolved',
            'operation_uuid' => self::OPERATION_UUID,
            'remote_status' => 'succeeded',
            'replayed' => false,
            'local_recording_status' => 'continued',
            'local_status' => 'recorded',
        ]];
        yield 'applied replay' => [[
            'status' => 'resolved',
            'operation_uuid' => self::OPERATION_UUID,
            'remote_status' => 'succeeded',
            'replayed' => true,
            'local_recording_status' => 'continued',
            'local' => ['local_status' => 'applied'],
        ], 200, [
            'status' => 'resolved',
            'operation_uuid' => self::OPERATION_UUID,
            'remote_status' => 'succeeded',
            'replayed' => true,
            'local_recording_status' => 'continued',
            'local_status' => 'applied',
        ]];
    }

    #[DataProvider('invalidCommitRequestProvider')]
    public function testCommitRejectsNonCanonicalOrNonExactConfirmationBeforeCallingService(mixed $request): void
    {
        $calls = 0;
        $controller = $this->controller(commit: static function () use (&$calls): array {
            ++$calls;
            return [];
        });

        $response = $controller->commit($request);

        self::assertSame(0, $calls);
        $this->assertErrorResponse($response, 422, 'ys_helcim_invalid_resolution_request');
    }

    public static function invalidCommitRequestProvider(): iterable
    {
        $valid = self::validCommitBody();
        yield 'not a request' => [null];
        yield 'missing route uuid' => [new RefundResolutionRestRequest(body: $valid)];
        yield 'unknown route input' => [new RefundResolutionRestRequest(
            body: $valid,
            route: ['operation_uuid' => self::OPERATION_UUID, 'negative' => true]
        )];
        yield 'missing body field' => [new RefundResolutionRestRequest(
            body: array_diff_key($valid, ['challenge' => true]),
            route: ['operation_uuid' => self::OPERATION_UUID]
        )];
        yield 'unknown body input' => [new RefundResolutionRestRequest(
            body: $valid + ['credential' => 'secret'],
            route: ['operation_uuid' => self::OPERATION_UUID]
        )];
        yield 'integer candidate' => [new RefundResolutionRestRequest(
            body: array_replace($valid, ['candidate_transaction_id' => 51177094]),
            route: ['operation_uuid' => self::OPERATION_UUID]
        )];
        yield 'short challenge' => [new RefundResolutionRestRequest(
            body: array_replace($valid, ['challenge' => str_repeat('a', 62)]),
            route: ['operation_uuid' => self::OPERATION_UUID]
        )];
        yield 'odd challenge' => [new RefundResolutionRestRequest(
            body: array_replace($valid, ['challenge' => str_repeat('a', 65)]),
            route: ['operation_uuid' => self::OPERATION_UUID]
        )];
        yield 'uppercase challenge' => [new RefundResolutionRestRequest(
            body: array_replace($valid, ['challenge' => str_repeat('A', 64)]),
            route: ['operation_uuid' => self::OPERATION_UUID]
        )];
        yield 'blank phrase' => [new RefundResolutionRestRequest(
            body: array_replace($valid, ['confirmation_phrase' => '   ']),
            route: ['operation_uuid' => self::OPERATION_UUID]
        )];
        yield 'control character in phrase' => [new RefundResolutionRestRequest(
            body: array_replace($valid, ['confirmation_phrase' => "RESOLVE\nSOMETHING"]),
            route: ['operation_uuid' => self::OPERATION_UUID]
        )];
        yield 'overlong phrase' => [new RefundResolutionRestRequest(
            body: array_replace($valid, ['confirmation_phrase' => str_repeat('x', 201)]),
            route: ['operation_uuid' => self::OPERATION_UUID]
        )];
        yield 'string attestation' => [new RefundResolutionRestRequest(
            body: array_replace($valid, ['parent_attestation' => 'false']),
            route: ['operation_uuid' => self::OPERATION_UUID]
        )];
    }

    public function testCommitMapsServiceErrorsAndDoesNotReturnRawMessages(): void
    {
        $error = new \WP_Error('ys_helcim_resolution_candidate_used', 'raw-secret-message');
        $controller = $this->controller(commit: static fn (): \WP_Error => $error);

        $response = $controller->commit($this->commitRequest());

        $this->assertErrorResponse($response, 409, 'ys_helcim_resolution_candidate_used');
        self::assertStringNotContainsString('raw-secret-message', json_encode($response->get_data(), JSON_THROW_ON_ERROR));
    }

    #[DataProvider('invalidCommitServiceResultProvider')]
    public function testCommitFailsClosedForThrownOrMalformedServiceResults(mixed $serviceResult): void
    {
        $controller = $this->controller(commit: static function () use ($serviceResult): mixed {
            if ($serviceResult instanceof \Throwable) {
                throw $serviceResult;
            }
            return $serviceResult;
        });

        $response = $controller->commit($this->commitRequest());

        $this->assertErrorResponse($response, 503, 'ys_helcim_resolution_service_unavailable');
    }

    public static function invalidCommitServiceResultProvider(): iterable
    {
        $base = [
            'status' => 'resolved',
            'operation_uuid' => self::OPERATION_UUID,
            'remote_status' => 'succeeded',
            'replayed' => false,
            'local_recording_status' => 'continued',
            'local' => ['local_status' => 'applied'],
        ];
        yield 'exception' => [new \RuntimeException('secret exception')];
        yield 'not array' => [true];
        yield 'wrong operation' => [array_replace($base, ['operation_uuid' => '22222222-2222-4333-8444-555555555555'])];
        yield 'wrong remote status' => [array_replace($base, ['remote_status' => 'indeterminate'])];
        yield 'truthy replay flag' => [array_replace($base, ['replayed' => 1])];
        yield 'continued without local state' => [array_diff_key($base, ['local' => true])];
        yield 'invalid local status' => [array_replace($base, ['local' => ['local_status' => 'pending']])];
        yield 'malformed local transaction' => [array_replace($base, ['local' => ['local_status' => 'applied', 'local_transaction_id' => '41']])];
        yield 'unsafe warning' => [array_replace($base, ['local' => ['local_status' => 'applied', 'warnings' => ['<script>']]])];
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    private static function validInspectResult(array $changes = []): array
    {
        return array_replace([
            'status' => 'confirmation_required',
            'operation_uuid' => self::OPERATION_UUID,
            'candidate_transaction_id' => '51177094',
            'source_transaction_id' => '51177061',
            'action' => 'resolve_positive',
            'proof_digest' => str_repeat('d', 64),
            'parent_attestation_required' => false,
            'challenge' => str_repeat('a5', 32),
            'challenge_expires_at' => '2026-07-21 01:05:00',
            'confirmation_phrase' => 'RESOLVE ' . self::OPERATION_UUID . ' WITH HELCIM 51177094',
        ], $changes);
    }

    /** @return array<string,mixed> */
    private static function validCommitBody(): array
    {
        return [
            'candidate_transaction_id' => '51177094',
            'challenge' => str_repeat('a5', 32),
            'confirmation_phrase' => 'RESOLVE ' . self::OPERATION_UUID . ' WITH HELCIM 51177094',
            'parent_attestation' => false,
        ];
    }

    private function inspectRequest(): RefundResolutionRestRequest
    {
        return new RefundResolutionRestRequest(
            body: ['candidate_transaction_id' => '51177094'],
            route: ['operation_uuid' => self::OPERATION_UUID]
        );
    }

    private function commitRequest(): RefundResolutionRestRequest
    {
        return new RefundResolutionRestRequest(
            body: self::validCommitBody(),
            route: ['operation_uuid' => self::OPERATION_UUID]
        );
    }

    private function assertErrorResponse(
        mixed $response,
        int $expectedStatus,
        string $expectedCode
    ): void {
        self::assertInstanceOf(RefundResolutionRestResponse::class, $response);
        self::assertSame($expectedStatus, $response->get_status());
        self::assertSame($expectedCode, $response->get_data()['error_code'] ?? null);
        self::assertArrayHasKey('message', $response->get_data());
    }

    private function controller(
        ?callable $inspect = null,
        ?callable $commit = null,
        ?callable $isLoggedIn = null,
        ?callable $nonceVerifier = null,
        ?callable $wpCapabilityChecker = null,
        ?callable $fluentPermissionChecker = null,
        ?callable $currentUserId = null,
        ?callable $routeRegistrar = null,
        ?callable $responseFactory = null
    ): YSHelcimRefundResolutionRestController {
        return new YSHelcimRefundResolutionRestController(
            $inspect ?? static fn (): array => [],
            $commit ?? static fn (): array => [],
            $isLoggedIn ?? static fn (): bool => true,
            $nonceVerifier ?? static fn (string $nonce, string $action): bool => $nonce === 'valid-nonce' && $action === 'wp_rest',
            $wpCapabilityChecker ?? static fn (string $capability): bool => $capability === 'manage_options',
            $fluentPermissionChecker ?? static fn (string $permission): bool => $permission === 'orders/can_refund',
            $currentUserId ?? static fn (): int => 7,
            $routeRegistrar,
            $responseFactory ?? static fn (array $data, int $status): RefundResolutionRestResponse => new RefundResolutionRestResponse($data, $status)
        );
    }
}
