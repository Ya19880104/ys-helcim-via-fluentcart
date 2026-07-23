<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\HelcimJs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\HelcimJs\YSHelcimJsPurchaseResponseAdapter;

final class PurchaseResponseAdapterTest extends TestCase
{
    /** @var array<string, int|string> */
    private array $identity = [
        'gateway' => 'ys_helcim_js',
        'order_id' => 10,
        'transaction_id' => 20,
        'transaction_uuid' => 'fc-transaction-123',
        'amount' => 2100,
        'currency' => 'USD',
        'payment_mode' => 'test',
    ];

    public function testApprovedRawResponseBecomesExactCoordinatorEnvelope(): void
    {
        $result = YSHelcimJsPurchaseResponseAdapter::toCoordinatorOutcome([
            'status' => 'APPROVED',
            'type' => 'purchase',
            'transactionId' => 51177123,
            'amount' => '21.00',
            'currency' => 'USD',
            'cardToken' => 'must-not-survive',
            'approvalCode' => 'SECRET',
        ], $this->identity);

        self::assertSame([
            'outcome' => 'succeeded',
            'transaction' => [
                'status' => 'APPROVED',
                'type' => 'purchase',
                'transactionId' => '51177123',
                'amount' => '21.00',
                'currency' => 'USD',
            ],
        ], $result);
        self::assertStringNotContainsString('must-not-survive', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testExactTwoHundredDeclineBecomesStrictDeclineEnvelope(): void
    {
        $result = YSHelcimJsPurchaseResponseAdapter::toCoordinatorOutcome([
            'status' => 'DECLINED',
            'type' => 'purchase',
            'transactionId' => 51177124,
            'amount' => '21.00',
            'currency' => 'USD',
            'errors' => 'Do not persist this detail',
        ], $this->identity);

        self::assertSame([
            'outcome' => 'declined',
            'definitive' => true,
            'transaction' => [
                'status' => 'DECLINED',
                'type' => 'purchase',
                'amount' => '21.00',
                'currency' => 'USD',
            ],
        ], $result);
    }

    public function testAllowlistedApiClientDeclineBecomesStrictDeclineEnvelope(): void
    {
        $error = new \WP_Error('ys_helcim_api_error', 'Declined', [
            'kind' => 'provider',
            'http_code' => 500,
            'indeterminate' => false,
            'mutation_disposition' => 'definitive_decline',
            'provider_errors' => 'Transaction Declined: CVV does not match',
            'definitive_decline' => true,
        ]);

        $result = YSHelcimJsPurchaseResponseAdapter::toCoordinatorOutcome($error, $this->identity);

        self::assertIsArray($result);
        self::assertSame('declined', $result['outcome']);
        self::assertTrue($result['definitive']);
    }

    #[DataProvider('definitiveNoChargeErrors')]
    public function testOnlyStructuredNoChargeErrorsBecomeTerminalFailureEnvelope(
        \WP_Error $error,
        string $expectedDisposition
    ): void {
        $result = YSHelcimJsPurchaseResponseAdapter::toCoordinatorOutcome($error, $this->identity);

        self::assertSame([
            'outcome' => 'failed',
            'definitive' => true,
            'mutation_disposition' => $expectedDisposition,
        ], $result);
    }

    public static function definitiveNoChargeErrors(): iterable
    {
        yield 'API client rejected before transport' => [
            new \WP_Error('ys_helcim_invalid_idempotency_key', 'Invalid key', [
                'kind' => 'local',
                'indeterminate' => false,
                'mutation_disposition' => 'never_sent',
            ]),
            'never_sent',
        ];
        yield 'provider rejected authentication' => [
            new \WP_Error('ys_helcim_api_error', 'Authentication failed', [
                'kind' => 'provider',
                'http_code' => 401,
                'indeterminate' => false,
                'mutation_disposition' => 'authentication_rejected',
            ]),
            'authentication_rejected',
        ];
        yield 'provider rejected an unverified card token before charging' => [
            new \WP_Error('ys_helcim_api_error', 'Card is not verified', [
                'kind' => 'provider',
                'http_code' => 400,
                'indeterminate' => false,
                'mutation_disposition' => 'validation_rejected',
                'provider_errors' => [
                    'verification' => 'Card is not verified',
                ],
                'provider_response' => [
                    'errors' => [
                        'verification' => 'Card is not verified',
                    ],
                ],
            ]),
            'validation_rejected',
        ];
    }

    #[DataProvider('ambiguousProviderResults')]
    public function testAnythingWithoutExactProofRemainsAnErrorForCoordinator(mixed $providerResult): void
    {
        $result = YSHelcimJsPurchaseResponseAdapter::toCoordinatorOutcome($providerResult, $this->identity);

        self::assertInstanceOf(\WP_Error::class, $result);
    }

    public static function ambiguousProviderResults(): iterable
    {
        yield 'ordinary transport error' => [new \WP_Error('ys_helcim_api_error', 'Timeout', [
            'kind' => 'transport',
            'indeterminate' => true,
            'mutation_disposition' => 'outcome_unknown',
        ])];
        yield 'forbidden is not authentication allowlisted' => [new \WP_Error('ys_helcim_api_error', 'Forbidden', [
            'kind' => 'provider',
            'http_code' => 403,
            'indeterminate' => false,
            'mutation_disposition' => 'authentication_rejected',
        ])];
        yield 'HTTP 401 without API disposition proof' => [new \WP_Error('ys_helcim_api_error', 'Authentication failed', [
            'kind' => 'provider',
            'http_code' => 401,
            'indeterminate' => false,
        ])];
        yield 'validation disposition without exact response proof' => [new \WP_Error('ys_helcim_api_error', 'Card is not verified', [
            'kind' => 'provider',
            'http_code' => 400,
            'indeterminate' => false,
            'mutation_disposition' => 'validation_rejected',
            'provider_errors' => [
                'verification' => 'Card is not verified',
            ],
        ])];
        yield 'validation response under a different error code' => [new \WP_Error('provider_error', 'Card is not verified', [
            'kind' => 'provider',
            'http_code' => 400,
            'indeterminate' => false,
            'mutation_disposition' => 'validation_rejected',
            'provider_errors' => [
                'verification' => 'Card is not verified',
            ],
            'provider_response' => [
                'errors' => [
                    'verification' => 'Card is not verified',
                ],
            ],
        ])];
        yield 'validation response with the wrong provider kind' => [new \WP_Error('ys_helcim_api_error', 'Card is not verified', [
            'kind' => 'http',
            'http_code' => 400,
            'indeterminate' => false,
            'mutation_disposition' => 'validation_rejected',
            'provider_errors' => [
                'verification' => 'Card is not verified',
            ],
            'provider_response' => [
                'errors' => [
                    'verification' => 'Card is not verified',
                ],
            ],
        ])];
        yield 'validation response with the wrong HTTP status' => [new \WP_Error('ys_helcim_api_error', 'Card is not verified', [
            'kind' => 'provider',
            'http_code' => 422,
            'indeterminate' => false,
            'mutation_disposition' => 'validation_rejected',
            'provider_errors' => [
                'verification' => 'Card is not verified',
            ],
            'provider_response' => [
                'errors' => [
                    'verification' => 'Card is not verified',
                ],
            ],
        ])];
        yield 'validation response marked indeterminate' => [new \WP_Error('ys_helcim_api_error', 'Card is not verified', [
            'kind' => 'provider',
            'http_code' => 400,
            'indeterminate' => true,
            'mutation_disposition' => 'validation_rejected',
            'provider_errors' => [
                'verification' => 'Card is not verified',
            ],
            'provider_response' => [
                'errors' => [
                    'verification' => 'Card is not verified',
                ],
            ],
        ])];
        yield 'validation response with contradictory success proof' => [new \WP_Error('ys_helcim_api_error', 'Card is not verified', [
            'kind' => 'provider',
            'http_code' => 400,
            'indeterminate' => false,
            'mutation_disposition' => 'validation_rejected',
            'provider_errors' => [
                'verification' => 'Card is not verified',
            ],
            'provider_response' => [
                'errors' => [
                    'verification' => 'Card is not verified',
                ],
                'status' => 'APPROVED',
            ],
        ])];
        yield 'validation response with extra provider error' => [new \WP_Error('ys_helcim_api_error', 'Card is not verified', [
            'kind' => 'provider',
            'http_code' => 400,
            'indeterminate' => false,
            'mutation_disposition' => 'validation_rejected',
            'provider_errors' => [
                'verification' => 'Card is not verified',
                'other' => 'Rejected',
            ],
            'provider_response' => [
                'errors' => [
                    'verification' => 'Card is not verified',
                    'other' => 'Rejected',
                ],
            ],
        ])];
        yield 'self-asserted decline missing allowlist details' => [new \WP_Error('ys_helcim_api_error', 'Declined', [
            'definitive_decline' => true,
        ])];
        yield 'wrong decline prefix' => [new \WP_Error('ys_helcim_api_error', 'Declined', [
            'kind' => 'provider',
            'http_code' => 500,
            'indeterminate' => false,
            'mutation_disposition' => 'definitive_decline',
            'provider_errors' => 'Internal server error',
            'definitive_decline' => true,
        ])];
        yield 'decline amount mismatch' => [[
            'status' => 'DECLINED',
            'type' => 'purchase',
            'amount' => '20.99',
            'currency' => 'USD',
        ]];
        yield 'approved missing exact transaction id' => [[
            'status' => 'APPROVED',
            'type' => 'purchase',
            'amount' => '21.00',
            'currency' => 'USD',
        ]];
        yield 'malformed response' => [['response' => 1]];
    }
}
