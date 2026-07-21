<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Doubles;

final class FakeOutboxWpdb
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    public bool $failNextLookup = false;
    public bool $failNextResults = false;
    public ?int $failOnUpdateNumber = null;
    /** @var int[] */
    public array $permanentlyFailUpdateIds = [];
    /** @var array<int,array<string,mixed>>|null */
    public ?array $nextActionableRows = null;
    /** @var array<int,array{query:string,args:array<int,mixed>}> */
    public array $resultQueries = [];
    /** @var array<int,array{query:string,args:array<int,mixed>}> */
    public array $rowQueries = [];
    private int $insertId = 0;
    /** @var array<int,array<string,mixed>> */
    private array $rows = [];
    /** @var array<string,array{operation_type:string,remote_status:string,local_status:string}> */
    private array $operationStates = [];
    private int $updateCount = 0;
    /** @var array<int,array<string,mixed>>|null */
    private ?array $snapshot = null;

    public function insert(string $table, array $data): int|false
    {
        unset($table);
        foreach ($this->rows as $row) {
            if ($row['operation_uuid'] === $data['operation_uuid'] && $row['effect_type'] === $data['effect_type']) {
                $this->last_error = 'Duplicate operation_effect';
                return false;
            }
            if ($data['claim_token'] !== null && $row['claim_token'] === $data['claim_token']) {
                $this->last_error = 'Duplicate claim_token';
                return false;
            }
        }
        $data['id'] = ++$this->insertId;
        $this->rows[$data['id']] = $data;
        $this->operationStates[$data['operation_uuid']] ??= [
            'operation_type' => 'refund',
            'remote_status' => 'succeeded',
            'local_status' => 'recorded',
        ];
        return 1;
    }

    public function update(string $table, array $data, array $where): int|false
    {
        unset($table);
        ++$this->updateCount;
        if (in_array((int) ($where['id'] ?? 0), $this->permanentlyFailUpdateIds, true)) {
            $this->last_error = 'Simulated permanent row update failure';
            return false;
        }
        if ($this->failOnUpdateNumber === $this->updateCount) {
            $this->last_error = 'Simulated outbox update failure';
            return false;
        }
        foreach ($this->rows as $id => $row) {
            if (!$this->matches($row, $where)) {
                continue;
            }
            $this->rows[$id] = array_merge($row, $data);
            return 1;
        }
        return 0;
    }

    public function query(string $sql): int|false
    {
        return match (strtoupper(trim($sql))) {
            'START TRANSACTION' => $this->start(),
            'COMMIT' => $this->commit(),
            'ROLLBACK' => $this->rollback(),
            default => false,
        };
    }

    /** @return array{query:string,args:array<int,mixed>} */
    public function prepare(string $query, mixed ...$args): array
    {
        return ['query' => $query, 'args' => $args];
    }

    /** @param array{query:string,args:array<int,mixed>} $prepared */
    public function get_row(array $prepared, string $output = ARRAY_A): ?array
    {
        unset($output);
        $this->rowQueries[] = $prepared;
        if ($this->failNextLookup) {
            $this->failNextLookup = false;
            $this->last_error = 'Simulated outbox lookup failure';
            return null;
        }
        $query = $prepared['query'];
        $args = $prepared['args'];

        if (str_contains($query, 'id = %d') && str_contains($query, 'claimed_at <= %s')) {
            foreach ($this->rows as $row) {
                if (
                    $row['id'] === ($args[0] ?? null)
                    && $row['status'] === ($args[1] ?? null)
                    && $row['claimed_at'] !== null
                    && $row['claimed_at'] <= ($args[2] ?? null)
                ) {
                    return $row;
                }
            }
            return null;
        }

        if (str_contains($query, "status IN ('pending', 'processing')")) {
            $rows = array_filter($this->rows, static fn (array $row): bool =>
                $row['operation_uuid'] === ($args[0] ?? null)
                && in_array($row['status'], ['pending', 'processing'], true)
            );
            usort($rows, static fn (array $a, array $b): int => [$a['sequence'], $a['id']] <=> [$b['sequence'], $b['id']]);
            return $rows[0] ?? null;
        }

        foreach ($this->rows as $row) {
            if (str_contains($query, 'effect_type = %s')) {
                if ($row['operation_uuid'] === ($args[0] ?? null) && $row['effect_type'] === ($args[1] ?? null)) {
                    return $row;
                }
                continue;
            }
            if (
                str_contains($query, 'id = %d')
                && $row['id'] === ($args[0] ?? null)
                && $row['status'] === ($args[1] ?? null)
                && $row['claim_token'] === ($args[2] ?? null)
            ) {
                return $row;
            }
        }
        return null;
    }

    /** @param array{query:string,args:array<int,mixed>} $prepared @return array<int,array<string,mixed>> */
    public function get_results(array $prepared, string $output = ARRAY_A): array
    {
        unset($output);
        $this->resultQueries[] = $prepared;
        $this->last_error = '';
        if ($this->failNextResults) {
            $this->failNextResults = false;
            $this->last_error = 'Simulated outbox result read failure';
            return [];
        }

        if (str_contains($prepared['query'], 'ys_helcim_actionable_operations')) {
            if ($this->nextActionableRows !== null) {
                $rows = $this->nextActionableRows;
                $this->nextActionableRows = null;
                return $rows;
            }
            $now = (string) ($prepared['args'][0] ?? '');
            $limit = (int) ($prepared['args'][1] ?? 100);
            $groups = [];
            foreach ($this->rows as $row) {
                $groups[$row['operation_uuid']][] = $row;
            }
            $eligible = [];
            foreach ($groups as $operationUuid => $rows) {
                $state = $this->operationStates[$operationUuid] ?? null;
                if (
                    !in_array($state['operation_type'] ?? null, ['refund', 'reverse'], true)
                    || ($state['remote_status'] ?? null) !== 'succeeded'
                    || ($state['local_status'] ?? null) !== 'recorded'
                ) {
                    continue;
                }
                $plan = [];
                foreach ($rows as $row) {
                    $plan[$row['effect_type']] = [$row['effect_class'], (int) $row['sequence']];
                }
                if ($plan !== [
                    'stock_restore' => ['at_most_once', 10],
                    'customer_recount' => ['idempotent', 20],
                    'refund_hooks' => ['at_most_once', 30],
                ]) {
                    continue;
                }
                $stockBlocked = false;
                $hasReady = false;
                $hasUnsettled = false;
                $oldestReady = '9999-12-31 23:59:59';
                $minimumId = PHP_INT_MAX;
                foreach ($rows as $row) {
                    $minimumId = min($minimumId, (int) $row['id']);
                    if ($row['effect_type'] === 'stock_restore' && in_array($row['status'], ['failed', 'indeterminate'], true)) {
                        $stockBlocked = true;
                    }
                    if (in_array($row['status'], ['pending', 'processing'], true)) {
                        $hasUnsettled = true;
                    }
                    if ($row['status'] === 'pending' && $row['available_at'] <= $now) {
                        $hasReady = true;
                        $oldestReady = min($oldestReady, (string) $row['available_at']);
                    }
                }
                if (!$stockBlocked && ($hasReady || !$hasUnsettled)) {
                    $eligible[] = [
                        'operation_uuid' => $operationUuid,
                        'finalize_only' => $hasUnsettled ? 1 : 0,
                        'oldest_ready' => $oldestReady,
                        'minimum_id' => $minimumId,
                    ];
                }
            }
            usort($eligible, static fn (array $a, array $b): int =>
                [$a['finalize_only'], $a['oldest_ready'], $a['minimum_id']]
                <=> [$b['finalize_only'], $b['oldest_ready'], $b['minimum_id']]
            );
            return array_map(
                static fn (array $row): array => ['operation_uuid' => $row['operation_uuid']],
                array_slice($eligible, 0, $limit)
            );
        }

        if (str_contains($prepared['query'], 'SELECT DISTINCT operation_uuid')) {
            $status = $prepared['args'][0] ?? null;
            $threshold = $prepared['args'][1] ?? null;
            $limit = (int) ($prepared['args'][2] ?? 100);
            $operations = [];
            foreach ($this->rows as $row) {
                $isEligible = str_contains($prepared['query'], 'claimed_at <= %s')
                    ? $row['status'] === $status && $row['claimed_at'] !== null && $row['claimed_at'] <= $threshold
                    : $row['status'] === $status && $row['available_at'] <= $threshold;
                if ($isEligible) {
                    $operations[$row['operation_uuid']] = ['operation_uuid' => $row['operation_uuid']];
                }
            }
            ksort($operations, SORT_STRING);
            return array_slice(array_values($operations), 0, $limit);
        }

        if (str_contains($prepared['query'], 'operation_uuid = %s')) {
            $operationUuid = $prepared['args'][0] ?? null;
            $rows = array_filter($this->rows, static fn (array $row): bool =>
                $row['operation_uuid'] === $operationUuid
            );
            usort($rows, static fn (array $a, array $b): int => [$a['sequence'], $a['id']] <=> [$b['sequence'], $b['id']]);
            return array_values($rows);
        }

        $status = $prepared['args'][0] ?? null;
        $cutoff = $prepared['args'][1] ?? null;
        $afterId = str_contains($prepared['query'], 'id > %d') ? (int) ($prepared['args'][2] ?? 0) : 0;
        $limitIndex = str_contains($prepared['query'], 'id > %d') ? 3 : 2;
        $limit = (int) ($prepared['args'][$limitIndex] ?? 100);
        $rows = array_filter($this->rows, static fn (array $row): bool =>
            $row['status'] === $status
            && $row['claimed_at'] !== null
            && $row['claimed_at'] <= $cutoff
            && (int) $row['id'] > $afterId
        );
        usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        return array_slice($rows, 0, $limit);
    }

    /** @return array<int,array<string,mixed>> */
    public function allRows(): array
    {
        return array_values($this->rows);
    }

    /** @param array<string,mixed> $changes */
    public function mutateRow(string $operationUuid, string $effectType, array $changes): void
    {
        foreach ($this->rows as $id => $row) {
            if ($row['operation_uuid'] === $operationUuid && $row['effect_type'] === $effectType) {
                $this->rows[$id] = array_merge($row, $changes);
                return;
            }
        }
    }

    public function setOperationState(string $operationUuid, string $remoteStatus, string $localStatus, string $operationType = 'refund'): void
    {
        $this->operationStates[$operationUuid] = [
            'operation_type' => $operationType,
            'remote_status' => $remoteStatus,
            'local_status' => $localStatus,
        ];
    }

    private function start(): int
    {
        $this->snapshot = $this->rows;
        return 1;
    }

    private function commit(): int
    {
        $this->snapshot = null;
        return 1;
    }

    private function rollback(): int
    {
        if ($this->snapshot !== null) {
            $this->rows = $this->snapshot;
        }
        $this->snapshot = null;
        return 1;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $where */
    private function matches(array $row, array $where): bool
    {
        foreach ($where as $field => $value) {
            if (!array_key_exists($field, $row) || $row[$field] !== $value) {
                return false;
            }
        }
        return true;
    }
}
