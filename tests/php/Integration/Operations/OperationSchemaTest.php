<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Integration\Operations;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationSchema;
use YangSheep\Helcim\FluentCart\Tests\Doubles\FakeWpdb;

final class OperationSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        \YSHelcimWpDouble::reset();
    }

    public function testSchemaContainsRequiredUniqueConstraints(): void
    {
        $sql = YSHelcimOperationSchema::createSql(new FakeWpdb());

        self::assertStringContainsString('UNIQUE KEY operation_uuid', $sql);
        self::assertStringContainsString('UNIQUE KEY idempotency_key', $sql);
        self::assertStringContainsString('UNIQUE KEY active_scope_key', $sql);
        self::assertStringContainsString('UNIQUE KEY provider_correlation_id', $sql);
        self::assertStringContainsString(
            'UNIQUE KEY vendor_transaction_id (vendor_transaction_id)',
            $sql
        );
        self::assertStringContainsString(
            'UNIQUE KEY parent_operation_type (parent_operation_uuid, operation_type)',
            $sql
        );
        self::assertStringContainsString('remote_status', $sql);
        self::assertStringContainsString('local_status', $sql);
        self::assertStringContainsString('remote_error_code', $sql);
        self::assertStringContainsString('local_error_code', $sql);
        self::assertStringContainsString('KEY material_expires_at', $sql);
        self::assertStringContainsString('local_payload_hash', $sql);
        self::assertStringContainsString('source_vendor_transaction_id varchar(64)', $sql);
        self::assertStringContainsString('UNIQUE KEY local_transaction_id', $sql);
        self::assertStringContainsString('local_claimed_at datetime DEFAULT NULL', $sql);
		self::assertStringContainsString('recovery_attempt_count smallint(5) unsigned NOT NULL DEFAULT 0', $sql);
		self::assertStringContainsString('next_recovery_at datetime DEFAULT NULL', $sql);
		self::assertStringContainsString('KEY hosted_recovery_due', $sql);
        self::assertStringContainsString('ENGINE=InnoDB', $sql);
        self::assertStringNotContainsString('text DEFAULT NULL', $sql);
    }

    public function testSchemaVersionIsBumpedForHostedRecoveryBackoff(): void
    {
		self::assertSame('8', YSHelcimOperationSchema::VERSION);
    }

    public function testExplicitHealthCheckDetectsMissingRequiredLocalClaimColumn(): void
    {
        global $wpdb;
        $database = new FakeWpdb();
        $database->schemaInstalled = true;
        $database->outboxSchemaInstalled = true;
        $database->webhookReceiptSchemaInstalled = true;
        $database->schemaIndexes = [
            'operation_uuid',
            'idempotency_key',
            'active_scope_key',
            'provider_correlation_id',
            'vendor_transaction_id',
            'parent_operation_type',
            'local_transaction_id',
        ];
        $database->outboxSchemaIndexes = ['operation_effect', 'claim_token'];
        $database->webhookReceiptSchemaIndexes = ['receipt_key'];
        $database->schemaColumns = [];
        $wpdb = $database;
        \YSHelcimWpDouble::$options[YSHelcimOperationSchema::OPTION_NAME] = YSHelcimOperationSchema::VERSION;

        self::assertFalse(YSHelcimOperationSchema::verifyHealth($database));
        self::assertSame([], \YSHelcimWpDouble::$dbDeltaSql);
    }

    public function testExplicitHealthCheckDetectsMissingGlobalProviderReceiptReservationIndex(): void
    {
        global $wpdb;
        $database = new FakeWpdb();
        $database->schemaInstalled = true;
        $database->outboxSchemaInstalled = true;
        $database->webhookReceiptSchemaInstalled = true;
        $database->schemaIndexes = [
            'operation_uuid',
            'idempotency_key',
            'active_scope_key',
            'provider_correlation_id',
            'parent_operation_type',
            'local_transaction_id',
        ];
        $database->outboxSchemaIndexes = ['operation_effect', 'claim_token'];
        $database->webhookReceiptSchemaIndexes = ['receipt_key'];
		$database->schemaColumns = ['local_claimed_at', 'recovery_attempt_count', 'next_recovery_at'];
        $wpdb = $database;
        \YSHelcimWpDouble::$options[YSHelcimOperationSchema::OPTION_NAME] = YSHelcimOperationSchema::VERSION;

        self::assertFalse(YSHelcimOperationSchema::verifyHealth($database));
        self::assertSame([], \YSHelcimWpDouble::$dbDeltaSql);
    }

    public function testSchemaVerificationUsesIndexSequenceInsteadOfResultRowOrder(): void
    {
        global $wpdb;
        $database = new FakeWpdb();
        $database->reverseParentOperationIndexRows = true;
        $wpdb = $database;

        self::assertTrue(YSHelcimOperationSchema::install($database));
        self::assertSame(YSHelcimOperationSchema::VERSION, \YSHelcimWpDouble::$options[YSHelcimOperationSchema::OPTION_NAME]);
    }

    public function testSchemaVerificationRejectsAPrefixOnlyProviderReceiptIndex(): void
    {
        global $wpdb;
        $database = new FakeWpdb();
        $database->prefixProviderReceiptIndex = true;
        $wpdb = $database;

        self::assertFalse(YSHelcimOperationSchema::install($database));
        self::assertArrayNotHasKey(YSHelcimOperationSchema::OPTION_NAME, \YSHelcimWpDouble::$options);
    }

    public function testOutboxSchemaHasClaimAndEffectUniqueness(): void
    {
        $sql = YSHelcimOperationSchema::outboxCreateSql(new FakeWpdb());

        self::assertStringContainsString('UNIQUE KEY operation_effect (operation_uuid, effect_type)', $sql);
        self::assertStringContainsString('UNIQUE KEY claim_token (claim_token)', $sql);
        self::assertStringContainsString('KEY ready_effects (status, available_at, sequence, id)', $sql);
        self::assertStringContainsString('ENGINE=InnoDB', $sql);
    }

    public function testInstallingSchemaRepeatedlyIsIdempotent(): void
    {
        global $wpdb;
        $database = new FakeWpdb();
        $wpdb = $database;

        YSHelcimOperationSchema::install($database);
        YSHelcimOperationSchema::install($database);

        self::assertCount(6, \YSHelcimWpDouble::$dbDeltaSql);
        self::assertSame(\YSHelcimWpDouble::$dbDeltaSql[0], \YSHelcimWpDouble::$dbDeltaSql[3]);
        self::assertSame(\YSHelcimWpDouble::$dbDeltaSql[1], \YSHelcimWpDouble::$dbDeltaSql[4]);
        self::assertSame(\YSHelcimWpDouble::$dbDeltaSql[2], \YSHelcimWpDouble::$dbDeltaSql[5]);
        self::assertSame(
            YSHelcimOperationSchema::VERSION,
            \YSHelcimWpDouble::$options[YSHelcimOperationSchema::OPTION_NAME]
        );
    }

    public function testActivationCallbackAcceptsWordPressNetworkFlag(): void
    {
        global $wpdb;
        $wpdb = new FakeWpdb();

        YSHelcimOperationSchema::activate(true);

        self::assertCount(3, \YSHelcimWpDouble::$dbDeltaSql);
        self::assertSame(
            YSHelcimOperationSchema::VERSION,
            \YSHelcimWpDouble::$options[YSHelcimOperationSchema::OPTION_NAME]
        );
    }

    public function testFailedSchemaInstallDoesNotAdvanceStoredVersion(): void
    {
        global $wpdb;
        $database = new FakeWpdb();
        $wpdb = $database;
        $database->failNextSchemaInstall = true;

        self::assertFalse(YSHelcimOperationSchema::install($database));
        self::assertArrayNotHasKey(
            YSHelcimOperationSchema::OPTION_NAME,
            \YSHelcimWpDouble::$options
        );
    }

    public function testVersionMismatchUpgradeVerifiesTheMigratedSchemaAndFailsClosed(): void
    {
        global $wpdb;
        $database = new FakeWpdb();
        $database->prefixProviderReceiptIndex = true;
        $wpdb = $database;
        \YSHelcimWpDouble::$options[YSHelcimOperationSchema::OPTION_NAME] = '6';

        self::assertFalse(YSHelcimOperationSchema::maybeUpgrade($database));
        self::assertSame('6', \YSHelcimWpDouble::$options[YSHelcimOperationSchema::OPTION_NAME]);
        self::assertGreaterThan(0, $database->schemaMetadataQueryCount);
    }

    public function testCurrentVersionFastGateSkipsAllPhysicalSchemaMetadataQueries(): void
    {
        global $wpdb;
        $database = new FakeWpdb();
        $wpdb = $database;
        \YSHelcimWpDouble::$options[YSHelcimOperationSchema::OPTION_NAME] = YSHelcimOperationSchema::VERSION;

        self::assertTrue(YSHelcimOperationSchema::maybeUpgrade($database));
        self::assertSame(0, $database->schemaMetadataQueryCount);
        self::assertFalse($database->schemaInstalled);
        self::assertFalse($database->outboxSchemaInstalled);
        self::assertFalse($database->webhookReceiptSchemaInstalled);
        self::assertSame([], \YSHelcimWpDouble::$dbDeltaSql);
    }

    public function testNamedButNonUniqueConcurrencyIndexFailsVerification(): void
    {
        global $wpdb;
        $database = new FakeWpdb();
        $database->nonUniqueIndexes = ['active_scope_key'];
        $wpdb = $database;

        self::assertFalse(YSHelcimOperationSchema::install($database));
        self::assertArrayNotHasKey(
            YSHelcimOperationSchema::OPTION_NAME,
            \YSHelcimWpDouble::$options
        );
    }
}
