<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Doubles;

/** Focused repeatable-read wpdb double for refund context loading. */
final class RefundContextWpdb
{
    public string $prefix = 'wp_';

    public string $last_error = '';

    public bool $failIsolation = false;

    public bool $failStart = false;

    public bool $failCommit = false;

    public bool $failRollback = false;

    public ?string $failReadTable = null;

    public ?string $failReadContaining = null;

    public ?string $throwReadTable = null;

    /** @var string[] */
    public array $commands = [];

    /** @var string[] */
    public array $reads = [];

    /** @var callable|null */
    public $afterRead = null;

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $tables = [];

    /** @var array<string, true> */
    private array $existingTables = [];

    /** @var array<string, array<int, array<string, mixed>>>|null */
    private ?array $transactionSnapshot = null;

    public function createTable(string $table): void
    {
        $this->existingTables[$table] = true;
        $this->tables[$table] ??= [];
    }

    /** @param array<string, mixed> $row */
    public function seed(string $table, array $row): void
    {
        $this->createTable($table);
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('Seed rows require a positive ID.');
        }
        $this->tables[$table][$id] = $row;
    }

    /** @param array<string, mixed> $row */
    public function replace(string $table, array $row, ?int $existingId = null): void
    {
        $id = $existingId ?? (int) ($row['id'] ?? 0);
        if (!isset($this->tables[$table][$id])) {
            throw new \InvalidArgumentException('Cannot replace a missing row.');
        }
        $this->tables[$table][$id] = $row;
    }

    /** @return array<string, mixed> */
    public function row(string $table, int $id): array
    {
        return $this->tables[$table][$id] ?? throw new \RuntimeException('Missing seeded row.');
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function dataSnapshot(): array
    {
        $tables = $this->tables;
        ksort($tables);
        foreach ($tables as &$rows) {
            ksort($rows);
        }
        unset($rows);
        return $tables;
    }

    public function inTransaction(): bool
    {
        return $this->transactionSnapshot !== null;
    }

    /** @return array{query:string,args:array<int,mixed>} */
    public function prepare(string $query, mixed ...$args): array
    {
        return ['query' => $query, 'args' => $args];
    }

    public function query(string $sql): int|false
    {
        $normalized = strtoupper(trim(preg_replace('/\s+/', ' ', $sql) ?? $sql));
        $this->commands[] = $normalized;
        $this->last_error = '';

        if ($normalized === 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') {
            if ($this->failIsolation) {
                $this->last_error = 'Simulated isolation failure';
                return false;
            }
            return 1;
        }

        if ($normalized === 'START TRANSACTION READ ONLY') {
            if ($this->failStart || $this->transactionSnapshot !== null) {
                $this->last_error = 'Simulated transaction start failure';
                return false;
            }
            $this->transactionSnapshot = $this->tables;
            return 1;
        }

        if ($normalized === 'COMMIT') {
            if ($this->failCommit) {
                $this->failCommit = false;
                $this->last_error = 'Simulated commit failure';
                return false;
            }
            $this->transactionSnapshot = null;
            return 1;
        }

        if ($normalized === 'ROLLBACK') {
            if ($this->failRollback) {
                $this->last_error = 'Simulated rollback failure';
                return false;
            }
            $this->transactionSnapshot = null;
            return 1;
        }

        $this->last_error = 'Unsupported query';
        return false;
    }

    /** @param array{query:string,args:array<int,mixed>} $prepared @return array<string,mixed>|null */
    public function get_row(array $prepared, string $output = ARRAY_A): ?array
    {
        unset($output);
        $query = $prepared['query'];
        $table = $this->tableFromQuery($query);
        $this->beforeRead($table, $query);

        $id = (int) ($prepared['args'][0] ?? 0);
        $row = $this->readTables()[$table][$id] ?? null;
        $this->runAfterRead($table);
        return $row;
    }

    /** @param array{query:string,args:array<int,mixed>} $prepared @return array<int,array<string,mixed>>|null */
    public function get_results(array $prepared, string $output = ARRAY_A): ?array
    {
        unset($output);
        $query = $prepared['query'];
        $table = $this->tableFromQuery($query);
        $this->beforeRead($table, $query);

        $orderId = (int) ($prepared['args'][0] ?? 0);
        $rows = array_values(array_filter(
            $this->readTables()[$table] ?? [],
            static function (array $row) use ($query, $orderId): bool {
                if ((int) ($row['order_id'] ?? 0) !== $orderId) {
                    return false;
                }
                return !str_contains($query, "transaction_type = 'refund'")
                    || ($row['transaction_type'] ?? null) === 'refund';
            }
        ));
        usort($rows, static fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);
        $this->runAfterRead($table);
        return $rows;
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function readTables(): array
    {
        return $this->transactionSnapshot ?? $this->tables;
    }

    private function beforeRead(string $table, string $query): void
    {
        $this->last_error = '';
        $this->reads[] = preg_replace('/\s+/', ' ', trim($query)) ?? trim($query);
        if ($this->throwReadTable === $table) {
            throw new \RuntimeException('Simulated read exception');
        }
        if (
            $this->failReadTable === $table
            || (is_string($this->failReadContaining) && str_contains($query, $this->failReadContaining))
            || !isset($this->existingTables[$table])
        ) {
            $this->last_error = 'Simulated table read failure';
        }
    }

    private function runAfterRead(string $table): void
    {
        if (is_callable($this->afterRead)) {
            ($this->afterRead)($table, $this);
        }
    }

    private function tableFromQuery(string $query): string
    {
        if (preg_match('/\bFROM\s+`?([A-Za-z0-9_]+)`?/i', $query, $matches) !== 1) {
            throw new \RuntimeException('Unable to identify table.');
        }
        return $matches[1];
    }
}
