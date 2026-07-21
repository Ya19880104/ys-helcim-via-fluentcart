<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Doubles;

/**
 * Focused transactional wpdb double for the canonical local-refund recorder.
 *
 * It deliberately models separate FluentCart and operation-journal tables so
 * rollback assertions exercise the same all-or-nothing boundary as production.
 */
final class LocalRefundWpdb
{
    public string $prefix = 'wp_';

    public int $insert_id = 0;

    public string $last_error = '';

    public bool $failNextCommit = false;

    public ?string $failNextSelectFor = null;

    /** @var string[] */
    public array $lockSequence = [];

    /** @var string[] */
    public array $journalStatusTransitions = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $tables = [];

    /** @var array{tables: array<string, array<int, array<string, mixed>>>, insert_id: int}|null */
    private ?array $transactionSnapshot = null;

    /** @param array<string, mixed> $row */
    public function seed(string $table, array $row): void
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('Seed rows require a positive ID.');
        }

        $this->tables[$table][$id] = $row;
        $this->insert_id = max($this->insert_id, $id);
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(string $table): array
    {
        $rows = array_values($this->tables[$table] ?? []);
        usort($rows, static fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);

        return $rows;
    }

    public function remove(string $table, int $id): void
    {
        unset($this->tables[$table][$id]);
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

    /** @return array{query: string, args: array<int, mixed>} */
    public function prepare(string $query, mixed ...$args): array
    {
        return ['query' => $query, 'args' => $args];
    }

    public function query(string|array $sql): int|false
    {
        if (is_array($sql)) {
            $sql = $sql['query'];
        }

        $command = strtoupper(trim($sql));
        if ($command === 'START TRANSACTION') {
            if ($this->transactionSnapshot !== null) {
                $this->last_error = 'Nested transaction';
                return false;
            }

            $this->transactionSnapshot = [
                'tables' => $this->tables,
                'insert_id' => $this->insert_id,
            ];
            return 1;
        }

        if ($command === 'COMMIT') {
            if ($this->failNextCommit) {
                $this->failNextCommit = false;
                $this->last_error = 'Simulated commit failure';
                return false;
            }

            $this->transactionSnapshot = null;
            return 1;
        }

        if ($command === 'ROLLBACK') {
            if ($this->transactionSnapshot !== null) {
                $this->tables = $this->transactionSnapshot['tables'];
                $this->insert_id = $this->transactionSnapshot['insert_id'];
            }
            $this->transactionSnapshot = null;
            return 1;
        }

        $this->last_error = 'Unsupported query';
        return false;
    }

    /** @param array{query: string, args: array<int, mixed>} $prepared */
    public function get_row(array $prepared, string $output = ARRAY_A): ?array
    {
        unset($output);
        $query = $prepared['query'];
        $table = $this->tableFromQuery($query);
        $this->recordLock($query, $table);
        if ($this->shouldFailSelect($query, $table)) {
            return null;
        }

        $value = $prepared['args'][0] ?? null;
        foreach ($this->tables[$table] ?? [] as $row) {
            if (str_contains($query, 'operation_uuid = %s') && (string) ($row['operation_uuid'] ?? '') === (string) $value) {
                return $row;
            }
            if (str_contains($query, 'id = %d') && (int) ($row['id'] ?? 0) === (int) $value) {
                return $row;
            }
        }

        return null;
    }

    /** @param array{query: string, args: array<int, mixed>} $prepared @return array<int, array<string, mixed>> */
    public function get_results(array $prepared, string $output = ARRAY_A): array
    {
        unset($output);
        $query = $prepared['query'];
        $table = $this->tableFromQuery($query);
        $this->recordLock($query, $table);
        if ($this->shouldFailSelect($query, $table)) {
            return [];
        }

        if (str_ends_with($table, 'ys_helcim_outbox')) {
            $operationUuid = (string) ($prepared['args'][0] ?? '');
            $rows = array_values(array_filter(
                $this->tables[$table] ?? [],
                static fn (array $row): bool => (string) ($row['operation_uuid'] ?? '') === $operationUuid
            ));
            usort(
                $rows,
                static fn (array $left, array $right): int => [(int) $left['sequence'], (int) $left['id']]
                    <=> [(int) $right['sequence'], (int) $right['id']]
            );

            return $rows;
        }

        $orderId = (int) ($prepared['args'][0] ?? 0);
        $rows = array_values(array_filter(
            $this->tables[$table] ?? [],
            static function (array $row) use ($query, $orderId): bool {
                if ((int) ($row['order_id'] ?? 0) !== $orderId) {
                    return false;
                }

                return !str_contains($query, "transaction_type = 'refund'")
                    || ($row['transaction_type'] ?? null) === 'refund';
            }
        ));
        usort($rows, static fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);

        return $rows;
    }

    /** @param array<string, mixed> $data @param array<int, string>|null $formats */
    public function insert(string $table, array $data, ?array $formats = null): int|false
    {
        unset($formats);
        $this->last_error = '';

        if (str_ends_with($table, 'ys_helcim_outbox')) {
            foreach ($this->tables[$table] ?? [] as $row) {
                if (
                    ($row['operation_uuid'] ?? null) === ($data['operation_uuid'] ?? null)
                    && ($row['effect_type'] ?? null) === ($data['effect_type'] ?? null)
                ) {
                    $this->last_error = 'Duplicate entry for operation_effect';
                    return false;
                }
            }
        }

        $nextId = empty($this->tables[$table]) ? 1 : max(array_keys($this->tables[$table])) + 1;
        $data['id'] = $nextId;
        $this->tables[$table][$nextId] = $data;
        $this->insert_id = $nextId;

        return 1;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     * @param array<int, string>|null $formats
     * @param array<int, string>|null $whereFormats
     */
    public function update(
        string $table,
        array $data,
        array $where,
        ?array $formats = null,
        ?array $whereFormats = null
    ): int|false {
        unset($formats, $whereFormats);
        $this->last_error = '';

        foreach ($this->tables[$table] ?? [] as $id => $row) {
            if (!$this->matches($row, $where)) {
                continue;
            }

            $this->tables[$table][$id] = array_merge($row, $data);
            if (str_ends_with($table, 'ys_helcim_operations') && isset($data['local_status'])) {
                $this->journalStatusTransitions[] = (string) $data['local_status'];
            }
            return 1;
        }

        return 0;
    }

    private function tableFromQuery(string $query): string
    {
        if (preg_match('/\bFROM\s+`?([A-Za-z0-9_]+)`?/i', $query, $matches) !== 1) {
            throw new \RuntimeException('Unable to identify table in query: ' . $query);
        }

        return $matches[1];
    }

    private function recordLock(string $query, string $table): void
    {
        if (!str_contains(strtoupper($query), 'FOR UPDATE')) {
            return;
        }

        $this->lockSequence[] = match ($table) {
            $this->prefix . 'ys_helcim_operations' => 'operation',
            $this->prefix . 'fct_orders' => 'order',
            $this->prefix . 'fct_order_items' => 'items',
            $this->prefix . 'ys_helcim_outbox' => 'outbox',
            $this->prefix . 'fct_order_transactions' => str_contains($query, "transaction_type = 'refund'")
                ? 'refund_rows'
                : 'source_transaction',
            default => $table,
        };
    }

    private function shouldFailSelect(string $query, string $table): bool
    {
        $label = match ($table) {
            $this->prefix . 'ys_helcim_operations' => 'operation',
            $this->prefix . 'fct_orders' => 'order',
            $this->prefix . 'fct_order_items' => 'items',
            $this->prefix . 'ys_helcim_outbox' => 'outbox',
            $this->prefix . 'fct_order_transactions' => str_contains($query, "transaction_type = 'refund'")
                ? 'refund_rows'
                : 'source_transaction',
            default => $table,
        };
        if ($this->failNextSelectFor !== $label) {
            return false;
        }

        $this->failNextSelectFor = null;
        $this->last_error = 'Simulated SELECT failure for ' . $label;
        return true;
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $where */
    private function matches(array $row, array $where): bool
    {
        foreach ($where as $field => $expected) {
            if (!array_key_exists($field, $row) || (string) $row[$field] !== (string) $expected) {
                return false;
            }
        }

        return true;
    }
}
