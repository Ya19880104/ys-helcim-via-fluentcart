<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Doubles;

/**
 * Read-only, repeatable-read wpdb double for the historical integrity scanner.
 */
final class HistoricalRefundIntegrityWpdb
{
    public string $prefix = 'wp_';

    public string $last_error = '';

    public bool $failIsolation = false;

    public bool $failStart = false;

    public bool $failCommit = false;

    public bool $failRollback = false;

    public ?string $failReadContaining = null;

    public ?string $throwReadContaining = null;

    /** @var string[] */
    public array $commands = [];

    /** @var string[] */
    public array $reads = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    private array $tables = [
        'wp_fct_orders' => [],
        'wp_fct_order_transactions' => [],
    ];

    /** @var array<string,array<int,array<string,mixed>>>|null */
    private ?array $transactionSnapshot = null;

    /** @param array<string,mixed> $row */
    public function seed(string $table, array $row): void
    {
        if (!array_key_exists($table, $this->tables)) {
            throw new \InvalidArgumentException('Unknown table.');
        }

        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('Seed rows require a positive ID.');
        }

        $this->tables[$table][$id] = $row;
    }

    /** @param array<string,mixed> $row */
    public function replace(string $table, int $id, array $row): void
    {
        if (!isset($this->tables[$table][$id])) {
            throw new \InvalidArgumentException('Cannot replace a missing row.');
        }

        $this->tables[$table][$id] = $row;
    }

    /** @return array<string,mixed> */
    public function row(string $table, int $id): array
    {
        return $this->tables[$table][$id] ?? throw new \RuntimeException('Missing row.');
    }

    /** @return array<string,array<int,array<string,mixed>>> */
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
        return null !== $this->transactionSnapshot;
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

        if ('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' === $normalized) {
            if ($this->failIsolation) {
                $this->last_error = 'Simulated isolation failure.';
                return false;
            }
            return 1;
        }

        if ('START TRANSACTION READ ONLY' === $normalized) {
            if ($this->failStart || null !== $this->transactionSnapshot) {
                $this->last_error = 'Simulated transaction start failure.';
                return false;
            }
            $this->transactionSnapshot = $this->tables;
            return 1;
        }

        if ('COMMIT' === $normalized) {
            if ($this->failCommit) {
                $this->failCommit = false;
                $this->last_error = 'Simulated commit failure.';
                return false;
            }
            $this->transactionSnapshot = null;
            return 1;
        }

        if ('ROLLBACK' === $normalized) {
            if ($this->failRollback) {
                $this->last_error = 'Simulated rollback failure.';
                return false;
            }
            $this->transactionSnapshot = null;
            return 1;
        }

        throw new \RuntimeException('The scanner attempted a non-read-only query: ' . $normalized);
    }

    /**
     * @param array{query:string,args:array<int,mixed>} $prepared
     * @return array<int,array<string,mixed>>|false
     */
    public function get_results(array $prepared, string $output = ARRAY_A): array|false
    {
        unset($output);
        $query = $prepared['query'];
        $args = $prepared['args'];
        $this->beforeRead($query);

        if (str_contains($query, 'SELECT DISTINCT order_id')) {
            $cursor = (int) ($args[0] ?? 0);
            $limit = (int) ($args[1] ?? 100);
            $inclusive = str_contains($query, 'order_id >= %d');
            $orderIds = [];
            foreach ($this->readTables()['wp_fct_orders'] as $row) {
                if (!in_array($row['payment_method'] ?? null, ['ys_helcim', 'ys_helcim_js'], true)) {
                    continue;
                }
                $orderId = (int) ($row['id'] ?? 0);
                if ($orderId > $cursor || ($inclusive && $orderId === $cursor)) {
                    $orderIds[$orderId] = true;
                }
            }
            foreach ($this->readTables()['wp_fct_order_transactions'] as $row) {
                if (!in_array($row['payment_method'] ?? null, ['ys_helcim', 'ys_helcim_js'], true)) {
                    continue;
                }
                $orderId = (int) ($row['order_id'] ?? 0);
                if ($orderId > $cursor || ($inclusive && $orderId === $cursor)) {
                    $orderIds[$orderId] = true;
                }
            }
            $ids = array_keys($orderIds);
            sort($ids, SORT_NUMERIC);
            $rows = array_map(
                static fn (int $orderId): array => ['order_id' => (string) $orderId],
                array_slice($ids, 0, $limit)
            );
            return $this->afterRead($rows);
        }

        if (str_contains($query, 'duplicate_count')) {
            $cursor = (int) ($args[0] ?? 0);
            $limit = (int) ($args[1] ?? 100);
            $groups = [];
            foreach ($this->readTables()['wp_fct_order_transactions'] as $row) {
                $receipt = $row['vendor_charge_id'] ?? null;
                if (
                    'refund' !== ($row['transaction_type'] ?? null)
                    || 'refunded' !== ($row['status'] ?? null)
                    || !in_array($row['payment_method'] ?? null, ['ys_helcim', 'ys_helcim_js'], true)
                    || !is_string($receipt)
                    || preg_match('/\A[1-9][0-9]*\z/', $receipt) !== 1
                ) {
                    continue;
                }
                $key = (string) ($row['payment_mode'] ?? '') . "\0" . $receipt;
                $groups[$key][] = (int) $row['id'];
            }

            $duplicates = [];
            foreach ($groups as $ids) {
                sort($ids, SORT_NUMERIC);
                if (count($ids) <= 1 || $ids[0] <= $cursor) {
                    continue;
                }
                $duplicates[] = [
                    'first_transaction_id' => (string) $ids[0],
                    'last_transaction_id' => (string) $ids[count($ids) - 1],
                    'duplicate_count' => (string) count($ids),
                ];
            }
            usort(
                $duplicates,
                static fn (array $left, array $right): int => (int) $left['first_transaction_id'] <=> (int) $right['first_transaction_id']
            );
            return $this->afterRead(array_slice($duplicates, 0, $limit));
        }

        if (str_contains($query, 'FROM `wp_fct_orders`')) {
            $wanted = array_fill_keys(array_map('intval', $args), true);
            $rows = array_values(array_filter(
                $this->readTables()['wp_fct_orders'],
                static fn (array $row): bool => isset($wanted[(int) ($row['id'] ?? 0)])
            ));
            usort($rows, static fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);
            return $this->afterRead($rows);
        }

        if (str_contains($query, 'FROM `wp_fct_order_transactions`')) {
            $wanted = array_fill_keys(array_map('intval', $args), true);
            $rows = array_values(array_filter(
                $this->readTables()['wp_fct_order_transactions'],
                static fn (array $row): bool => isset($wanted[(int) ($row['order_id'] ?? 0)])
            ));
            usort($rows, static function (array $left, array $right): int {
                return [(int) ($left['order_id'] ?? 0), (int) ($left['id'] ?? 0)]
                    <=> [(int) ($right['order_id'] ?? 0), (int) ($right['id'] ?? 0)];
            });
            return $this->afterRead($rows);
        }

        throw new \RuntimeException('Unsupported read query.');
    }

    private function beforeRead(string $query): void
    {
        $this->last_error = '';
        $normalized = preg_replace('/\s+/', ' ', trim($query)) ?? trim($query);
        $this->reads[] = $normalized;
        if (is_string($this->throwReadContaining) && str_contains($query, $this->throwReadContaining)) {
            throw new \RuntimeException('Simulated read exception.');
        }
        if (is_string($this->failReadContaining) && str_contains($query, $this->failReadContaining)) {
            $this->last_error = 'Simulated read failure.';
        }
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>>|false */
    private function afterRead(array $rows): array|false
    {
        return '' === $this->last_error ? $rows : false;
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    private function readTables(): array
    {
        return $this->transactionSnapshot ?? $this->tables;
    }
}
