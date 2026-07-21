<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Integration\Operations;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundResolutionSchema;
use YangSheep\Helcim\FluentCart\Tests\Doubles\RefundResolutionSchemaWpdb;

final class RefundResolutionSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        \YSHelcimWpDouble::reset();
    }

    public function testChallengeSchemaStoresOnlyAHashAndEveryRequiredBinding(): void
    {
        $db = new class {
            public string $prefix = 'wp_';
            public function get_charset_collate(): string
            {
                return 'DEFAULT CHARACTER SET utf8mb4';
            }
        };

        $sql = YSHelcimRefundResolutionSchema::challengeCreateSql($db);

        self::assertStringContainsString('CREATE TABLE wp_ys_helcim_resolution_challenges', $sql);
        self::assertStringContainsString('challenge_hash char(64) NOT NULL', $sql);
        self::assertStringNotContainsString('challenge_token', $sql);
        self::assertStringNotContainsString('raw_challenge', $sql);
        foreach ([
            'operation_uuid', 'gateway', 'payment_mode', 'candidate_transaction_id',
            'source_transaction_id', 'action', 'proof_digest', 'state_updated_at',
            'actor_user_id', 'phrase_hash', 'parent_attestation_required', 'expires_at', 'used_at',
        ] as $column) {
            self::assertStringContainsString($column, $sql);
        }
        self::assertStringContainsString('UNIQUE KEY challenge_hash (challenge_hash)', $sql);
        self::assertStringContainsString('ENGINE=InnoDB', $sql);
    }

    public function testAuditSchemaIsTheConservativeSiteWideCandidateReservation(): void
    {
        $db = new class {
            public string $prefix = 'wp_';
            public function get_charset_collate(): string
            {
                return 'DEFAULT CHARACTER SET utf8mb4';
            }
        };

        $sql = YSHelcimRefundResolutionSchema::auditCreateSql($db);

        self::assertStringContainsString('CREATE TABLE wp_ys_helcim_refund_resolutions', $sql);
        self::assertStringContainsString('UNIQUE KEY operation_uuid (operation_uuid)', $sql);
        self::assertStringContainsString('UNIQUE KEY candidate_transaction_id (candidate_transaction_id)', $sql);
        foreach ([
            'challenge_hash', 'gateway', 'payment_mode', 'operation_type', 'source_transaction_id',
            'action', 'proof_digest', 'state_updated_at', 'actor_user_id', 'parent_attested', 'resolved_at',
        ] as $column) {
            self::assertStringContainsString($column, $sql);
        }
        self::assertStringContainsString('ENGINE=InnoDB', $sql);
    }

    public function testInstallMigratesAndVerifiesBothInnoDbTablesAndCriticalUniqueIndexes(): void
    {
        $db = new RefundResolutionSchemaWpdb();
        $migrated = [];
        $migrator = static function (string $sql) use ($db, &$migrated): void {
            $migrated[] = $sql;
            if (str_contains($sql, 'ys_helcim_resolution_challenges')) {
                $db->challengeInstalled = true;
            }
            if (str_contains($sql, 'ys_helcim_refund_resolutions')) {
                $db->auditInstalled = true;
            }
        };

        self::assertTrue(YSHelcimRefundResolutionSchema::install($db, $migrator));
        self::assertCount(2, $migrated);
        self::assertSame(
            YSHelcimRefundResolutionSchema::VERSION,
            \YSHelcimWpDouble::$options[YSHelcimRefundResolutionSchema::OPTION_NAME]
        );
    }

    public function testSchemaVersionRemainsStableForTheExistingGlobalReservation(): void
    {
        self::assertSame('1', YSHelcimRefundResolutionSchema::VERSION);
    }

    public function testInstallFailsClosedWhenEngineOrCriticalUniqueReservationIsMissing(): void
    {
        $db = new RefundResolutionSchemaWpdb();
        $migrator = static function (string $sql) use ($db): void {
            $db->challengeInstalled = true;
            $db->auditInstalled = true;
            unset($sql);
        };

        $db->auditEngine = 'MyISAM';
        self::assertFalse(YSHelcimRefundResolutionSchema::install($db, $migrator));
        self::assertArrayNotHasKey(YSHelcimRefundResolutionSchema::OPTION_NAME, \YSHelcimWpDouble::$options);

        $db->auditEngine = 'InnoDB';
        $db->auditUniqueIndexes = ['operation_uuid', 'challenge_hash'];
        self::assertFalse(YSHelcimRefundResolutionSchema::install($db, $migrator));
        self::assertArrayNotHasKey(YSHelcimRefundResolutionSchema::OPTION_NAME, \YSHelcimWpDouble::$options);
    }

    public function testVersionMismatchUpgradeVerifiesTheMigratedSchemaAndFailsClosed(): void
    {
        $db = new RefundResolutionSchemaWpdb();
        $db->auditEngine = 'MyISAM';
        \YSHelcimWpDouble::$options[YSHelcimRefundResolutionSchema::OPTION_NAME] = '0';
        $migrator = static function (string $sql) use ($db): void {
            if (str_contains($sql, 'ys_helcim_resolution_challenges')) {
                $db->challengeInstalled = true;
            }
            if (str_contains($sql, 'ys_helcim_refund_resolutions')) {
                $db->auditInstalled = true;
            }
        };

        self::assertFalse(YSHelcimRefundResolutionSchema::maybeUpgrade($db, $migrator));
        self::assertSame('0', \YSHelcimWpDouble::$options[YSHelcimRefundResolutionSchema::OPTION_NAME]);
        self::assertGreaterThan(0, $db->schemaMetadataQueryCount);
    }

    public function testCurrentVersionFastGateSkipsMigrationAndAllPhysicalSchemaMetadataQueries(): void
    {
        $db = new RefundResolutionSchemaWpdb();
        $db->challengeInstalled = true;
        $db->auditInstalled = true;
        \YSHelcimWpDouble::$options[YSHelcimRefundResolutionSchema::OPTION_NAME] = YSHelcimRefundResolutionSchema::VERSION;
        $migrationCalls = 0;
        $migrator = static function () use (&$migrationCalls): void {
            ++$migrationCalls;
        };

        self::assertTrue(YSHelcimRefundResolutionSchema::maybeUpgrade($db, $migrator));
        self::assertSame(0, $migrationCalls);
        self::assertSame(0, $db->schemaMetadataQueryCount);

        $db->auditInstalled = false;
        self::assertTrue(YSHelcimRefundResolutionSchema::maybeUpgrade($db, $migrator));
        self::assertSame(0, $migrationCalls);
        self::assertSame(0, $db->schemaMetadataQueryCount);
        self::assertFalse(YSHelcimRefundResolutionSchema::verifyHealth($db));
        self::assertGreaterThan(0, $db->schemaMetadataQueryCount);
    }
}
