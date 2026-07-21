<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Operations;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimStoragePreflight;

final class StoragePreflightTest extends TestCase
{
    public function testAllJournalAndFluentCartTablesMustBeInnoDb(): void
    {
        $database = new StorageEngineWpdb();

        self::assertTrue((new YSHelcimStoragePreflight($database))->verify());
        self::assertSame([
            'wp_ys_helcim_operations',
            'wp_ys_helcim_outbox',
            'wp_fct_order_transactions',
            'wp_fct_orders',
            'wp_fct_order_items',
        ], $database->requestedTables);
    }

    #[DataProvider('unsafeEngines')]
    public function testMissingOrNonTransactionalTableFailsClosed(?string $engine): void
    {
        $database = new StorageEngineWpdb(['wp_fct_orders' => $engine]);

        $result = (new YSHelcimStoragePreflight($database))->verify();

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_storage_not_transactional', $result->get_error_code());
        self::assertSame(503, $result->get_error_data()['status']);
    }

    public function testDatabaseFailureFailsClosedWithoutLeakingDetails(): void
    {
        $database = new StorageEngineWpdb();
        $database->throw = true;

        $result = (new YSHelcimStoragePreflight($database))->verify();

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_storage_not_transactional', $result->get_error_code());
    }

    public static function unsafeEngines(): iterable
    {
        yield 'missing table' => [null];
        yield 'MyISAM' => ['MyISAM'];
        yield 'empty engine' => [''];
    }
}

final class StorageEngineWpdb
{
    public string $prefix = 'wp_';
    public bool $throw = false;
    /** @var string[] */
    public array $requestedTables = [];
    /** @var array<string, string|null> */
    private array $overrides;

    /** @param array<string, string|null> $overrides */
    public function __construct(array $overrides = [])
    {
        $this->overrides = $overrides;
    }

    public function prepare(string $query, string $table): array
    {
        return ['query' => $query, 'table' => $table];
    }

    public function get_var(array $prepared): ?string
    {
        if ($this->throw) {
            throw new \RuntimeException('database unavailable with secret detail');
        }

        $table = $prepared['table'];
        $this->requestedTables[] = $table;
        return array_key_exists($table, $this->overrides) ? $this->overrides[$table] : 'InnoDB';
    }
}
