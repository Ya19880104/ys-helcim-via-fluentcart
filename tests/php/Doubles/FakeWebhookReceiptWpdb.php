<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Doubles;

final class FakeWebhookReceiptWpdb
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    public bool $failNextLookup = false;
    public bool $failNextInsert = false;
    public bool $failNextPurge = false;
    public bool $simulateConcurrentInsert = false;
    public bool $returnAffectedRowsAsStrings = false;

    /** @var array<int,array{table:string,data:array<string,mixed>}> */
    public array $insertCalls = [];

    /** @var array<int,array{query:string,args:array<int,mixed>}> */
    public array $queryCalls = [];

    /** @var array<int,array{query:string,args:array<int,mixed>}> */
    public array $rowQueries = [];

    private int $insertId = 0;

    /** @var array<int,array<string,mixed>> */
    private array $rows = [];

    /** @return array{query:string,args:array<int,mixed>} */
    public function prepare(string $query, mixed ...$args): array
    {
        return ['query' => $query, 'args' => $args];
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function insert(string $table, array $data): int|string|false
    {
        $this->insertCalls[] = ['table' => $table, 'data' => $data];
        $this->last_error = '';

        if ($this->failNextInsert) {
            $this->failNextInsert = false;
            $this->last_error = 'Simulated receipt insert failure';
            return false;
        }

        foreach ($this->rows as $row) {
            if (($row['receipt_key'] ?? null) === ($data['receipt_key'] ?? null)) {
                $this->last_error = 'Duplicate receipt_key';
                return false;
            }
        }

        $data['id'] = $this->returnAffectedRowsAsStrings ? (string) (++$this->insertId) : ++$this->insertId;
        $this->rows[$this->insertId] = $data;

        if ($this->simulateConcurrentInsert) {
            $this->simulateConcurrentInsert = false;
            $this->last_error = 'Duplicate receipt_key';
            return false;
        }

        return $this->returnAffectedRowsAsStrings ? '1' : 1;
    }

    /** @param array{query:string,args:array<int,mixed>} $prepared */
    public function get_row(array $prepared, string $output = ARRAY_A): ?array
    {
        unset($output);
        $this->rowQueries[] = $prepared;
        $this->last_error = '';

        if ($this->failNextLookup) {
            $this->failNextLookup = false;
            $this->last_error = 'Simulated receipt lookup failure';
            return null;
        }

        $key = (string) ($prepared['args'][0] ?? '');
        foreach ($this->rows as $row) {
            if (($row['receipt_key'] ?? null) === $key) {
                return $row;
            }
        }

        return null;
    }

    /** @param array{query:string,args:array<int,mixed>}|string $prepared */
    public function query(array|string $prepared): int|string|false
    {
        $prepared = is_array($prepared) ? $prepared : ['query' => $prepared, 'args' => []];
        $this->queryCalls[] = $prepared;
        $this->last_error = '';

        if ($this->failNextPurge) {
            $this->failNextPurge = false;
            $this->last_error = 'Simulated receipt purge failure';
            return false;
        }

        $query = $prepared['query'];
        $args = $prepared['args'];
        if (!str_starts_with(ltrim($query), 'DELETE FROM')) {
            $this->last_error = 'Unsupported receipt query';
            return false;
        }

        $removed = 0;
        if (str_contains($query, 'receipt_key = %s')) {
            $key = (string) ($args[0] ?? '');
            $now = (string) ($args[1] ?? '');
            foreach ($this->rows as $id => $row) {
                if (($row['receipt_key'] ?? null) === $key && (string) ($row['expires_at'] ?? '') <= $now) {
                    unset($this->rows[$id]);
                    $removed = 1;
                    break;
                }
            }
        } else {
            $now = (string) ($args[0] ?? '');
            $limit = (int) ($args[1] ?? 0);
            $expired = array_filter(
                $this->rows,
                static fn (array $row): bool => (string) ($row['expires_at'] ?? '') <= $now
            );
            uasort(
                $expired,
                static fn (array $left, array $right): int =>
                    [(string) $left['expires_at'], (int) $left['id']]
                    <=> [(string) $right['expires_at'], (int) $right['id']]
            );
            foreach (array_slice(array_keys($expired), 0, $limit) as $id) {
                unset($this->rows[$id]);
                ++$removed;
            }
        }

        return $this->returnAffectedRowsAsStrings ? (string) $removed : $removed;
    }

    /** @param array<string,mixed> $row */
    public function seed(array $row): void
    {
        $id = isset($row['id']) ? (int) $row['id'] : ++$this->insertId;
        $this->insertId = max($this->insertId, $id);
        $row['id'] ??= $this->returnAffectedRowsAsStrings ? (string) $id : $id;
        $this->rows[$id] = $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function allRows(): array
    {
        return array_values($this->rows);
    }
}
