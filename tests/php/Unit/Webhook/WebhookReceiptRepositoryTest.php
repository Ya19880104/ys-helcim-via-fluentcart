<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Tests\Doubles\FakeWebhookReceiptWpdb;
use YangSheep\Helcim\FluentCart\Webhook\YSHelcimWebhookReceiptRepository;

final class WebhookReceiptRepositoryTest extends TestCase
{
    private const NOW = '2026-07-21 04:00:00';
    private const EXPIRY = '2026-07-28 04:00:00';
    private const KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private FakeWebhookReceiptWpdb $database;
    private YSHelcimWebhookReceiptRepository $repository;

    protected function setUp(): void
    {
        $this->database = new FakeWebhookReceiptWpdb();
        $this->repository = new YSHelcimWebhookReceiptRepository(
            $this->database,
            null,
            static fn (): string => self::NOW
        );
    }

    public function testRepositoryIsAvailableThroughThePluginAutoloader(): void
    {
        self::assertTrue(class_exists(YSHelcimWebhookReceiptRepository::class));
    }

    public function testRepositoryExposesTheNarrowReceiptPersistenceApi(): void
    {
        self::assertTrue(method_exists(YSHelcimWebhookReceiptRepository::class, 'hasCompleted'));
        self::assertTrue(method_exists(YSHelcimWebhookReceiptRepository::class, 'complete'));
        self::assertTrue(method_exists(YSHelcimWebhookReceiptRepository::class, 'tableName'));
        self::assertTrue(method_exists(YSHelcimWebhookReceiptRepository::class, 'createSql'));
    }

    public function testCompletingAReceiptPersistsOnlyItsHashAndRetentionDates(): void
    {
        $result = $this->repository->complete(self::KEY, self::NOW, self::EXPIRY);

        self::assertTrue($result);
        self::assertCount(1, $this->database->allRows());
        self::assertSame('wp_ys_helcim_webhook_receipts', $this->database->insertCalls[0]['table']);
        self::assertSame(
            [
                'receipt_key' => self::KEY,
                'completed_at' => self::NOW,
                'expires_at' => self::EXPIRY,
            ],
            $this->database->insertCalls[0]['data']
        );
        self::assertTrue($this->repository->hasCompleted(self::KEY));
    }

    public function testMissingReceiptIsFalseButLookupFailureIsAnExplicitError(): void
    {
        self::assertFalse($this->repository->hasCompleted(self::KEY));

        $this->database->failNextLookup = true;
        $failure = $this->repository->hasCompleted(self::KEY);

        self::assertInstanceOf(\WP_Error::class, $failure);
        self::assertSame('ys_helcim_webhook_receipt_unavailable', $failure->get_error_code());
    }

    public function testExistingReceiptCompletesIdempotentlyWithoutAnotherInsert(): void
    {
        self::assertTrue($this->repository->complete(self::KEY, self::NOW, self::EXPIRY));
        self::assertTrue($this->repository->complete(self::KEY, self::NOW, self::EXPIRY));

        self::assertCount(1, $this->database->allRows());
        self::assertCount(1, $this->database->insertCalls);
    }

    public function testUniqueKeyRaceIsReadBackAsTheSameCompletedReceipt(): void
    {
        $this->database->simulateConcurrentInsert = true;

        self::assertTrue($this->repository->complete(self::KEY, self::NOW, self::EXPIRY));
        self::assertCount(1, $this->database->allRows());
        self::assertSame(self::KEY, $this->database->allRows()[0]['receipt_key']);
    }

    public function testInsertFailureWithoutAConcurrentReceiptFailsClosed(): void
    {
        $this->database->failNextInsert = true;

        $result = $this->repository->complete(self::KEY, self::NOW, self::EXPIRY);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_webhook_receipt_unavailable', $result->get_error_code());
        self::assertCount(0, $this->database->allRows());
    }

    public function testReceiptKeyAndDatesAreStrictlyValidatedBeforeDatabaseAccess(): void
    {
        foreach (
            [
                ['ABC', self::NOW, self::EXPIRY],
                [strtoupper(self::KEY), self::NOW, self::EXPIRY],
                [self::KEY, '2026-02-30 04:00:00', self::EXPIRY],
                [self::KEY, self::NOW, self::NOW],
                [self::KEY, self::NOW, '2026-07-20 04:00:00'],
            ] as [$key, $completedAt, $expiresAt]
        ) {
            $result = $this->repository->complete($key, $completedAt, $expiresAt);
            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame('ys_helcim_invalid_webhook_receipt', $result->get_error_code());
        }

        $invalidLookup = $this->repository->hasCompleted(strtoupper(self::KEY));
        self::assertInstanceOf(\WP_Error::class, $invalidLookup);
        self::assertSame('ys_helcim_invalid_webhook_receipt', $invalidLookup->get_error_code());
        self::assertSame([], $this->database->insertCalls);
        self::assertSame([], $this->database->queryCalls);
        self::assertSame([], $this->database->rowQueries);
    }

    public function testExpiredReceiptsArePurgedInABoundedPage(): void
    {
        for ($index = 1; $index <= 105; ++$index) {
            $this->database->seed([
                'receipt_key' => sprintf('%064x', $index),
                'completed_at' => '2026-07-20 00:00:00',
                'expires_at' => '2026-07-21 03:59:59',
            ]);
        }
        $this->database->seed([
            'receipt_key' => self::KEY,
            'completed_at' => self::NOW,
            'expires_at' => self::EXPIRY,
        ]);

        self::assertTrue($this->repository->hasCompleted(self::KEY));
        self::assertCount(6, $this->database->allRows());
        self::assertStringContainsString('ORDER BY expires_at ASC, id ASC LIMIT %d', $this->database->queryCalls[0]['query']);
        self::assertSame([self::NOW, 100], $this->database->queryCalls[0]['args']);
    }

    public function testPurgeFailureIsNeverMistakenForAMissingReceipt(): void
    {
        $this->database->failNextPurge = true;

        $result = $this->repository->hasCompleted(self::KEY);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_webhook_receipt_unavailable', $result->get_error_code());
        self::assertSame([], $this->database->rowQueries);
    }

    public function testAnExpiredExactKeyBeyondThePurgePageCanBeReplaced(): void
    {
        for ($index = 1; $index <= 100; ++$index) {
            $this->database->seed([
                'receipt_key' => sprintf('%064x', $index),
                'completed_at' => '2026-07-20 00:00:00',
                'expires_at' => '2026-07-21 03:00:00',
            ]);
        }
        $this->database->seed([
            'receipt_key' => self::KEY,
            'completed_at' => '2026-07-20 00:00:00',
            'expires_at' => '2026-07-21 03:59:59',
        ]);

        self::assertTrue($this->repository->complete(self::KEY, self::NOW, self::EXPIRY));

        $matching = array_values(array_filter(
            $this->database->allRows(),
            static fn (array $row): bool => ($row['receipt_key'] ?? null) === self::KEY
        ));
        self::assertCount(1, $matching);
        self::assertSame(self::EXPIRY, $matching[0]['expires_at']);
        self::assertCount(2, $this->database->queryCalls);
        self::assertStringContainsString('receipt_key = %s', $this->database->queryCalls[1]['query']);
        self::assertSame([self::KEY, self::NOW], $this->database->queryCalls[1]['args']);
    }

    public function testWpdbNumericStringAffectedRowsAndIdsAreAccepted(): void
    {
        $this->database->returnAffectedRowsAsStrings = true;

        self::assertTrue($this->repository->complete(self::KEY, self::NOW, self::EXPIRY));
        self::assertIsString($this->database->allRows()[0]['id']);
        self::assertTrue($this->repository->hasCompleted(self::KEY));
    }

    public function testCorruptStoredReceiptFailsClosed(): void
    {
        $this->database->seed([
            'receipt_key' => self::KEY,
            'completed_at' => self::NOW,
            'expires_at' => 'not-a-date',
        ]);

        $result = $this->repository->hasCompleted(self::KEY);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_webhook_receipt_unavailable', $result->get_error_code());
    }

    public function testDedicatedTableCanBeDerivedOrSafelyInjectedAndItsSchemaIsMinimal(): void
    {
        self::assertSame('wp_ys_helcim_webhook_receipts', YSHelcimWebhookReceiptRepository::tableName($this->database));

        $custom = new YSHelcimWebhookReceiptRepository(
            $this->database,
            'tenant_7_webhook_receipts',
            static fn (): string => self::NOW
        );
        self::assertTrue($custom->complete(self::KEY, self::NOW, self::EXPIRY));
        self::assertSame('tenant_7_webhook_receipts', $this->database->insertCalls[0]['table']);

        $sql = YSHelcimWebhookReceiptRepository::createSql($this->database, 'tenant_7_webhook_receipts');
        self::assertStringContainsString('CREATE TABLE tenant_7_webhook_receipts', $sql);
        self::assertStringContainsString('UNIQUE KEY receipt_key (receipt_key)', $sql);
        self::assertStringContainsString('KEY expires_at (expires_at)', $sql);
        self::assertStringContainsString('ENGINE=InnoDB', $sql);
        self::assertStringNotContainsString('body', strtolower($sql));
        self::assertStringNotContainsString('payload', strtolower($sql));
        self::assertStringNotContainsString('secret', strtolower($sql));
    }

    public function testUnsafeInjectedTableNamesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new YSHelcimWebhookReceiptRepository($this->database, 'receipts; DROP TABLE users', null, 100);
    }

    public function testOutOfBoundsPurgeLimitsAreRejected(): void
    {
        foreach ([0, 1001] as $limit) {
            try {
                new YSHelcimWebhookReceiptRepository($this->database, null, null, $limit);
                self::fail('Expected an invalid purge limit to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('purge limit', $exception->getMessage());
            }
        }
    }
}
