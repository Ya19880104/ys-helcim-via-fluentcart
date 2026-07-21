<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Doubles;

final class RefundResolutionWpdb
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    public int $insert_id = 0;
    public bool $failStart = false;
    public bool $failCommit = false;
    public bool $failOperationCas = false;
    public bool $failOperationReceiptRead = false;
    public bool $failTransactionReceiptRead = false;
    public bool $throwOperationReceiptRead = false;
    public bool $throwTransactionReceiptRead = false;
    public bool $failOperationCasWithDuplicateReceipt = false;
    public bool $failOperationCasWithOtherDuplicate = false;
    public bool $throwStart = false;
    public bool $throwCommit = false;
    public bool $throwChallengeRead = false;
    public bool $throwOperationRead = false;
    public bool $throwOperationUpdate = false;
    public bool $failAuditInsertWithNonDuplicateCandidateError = false;

    /** @var array<string,array<string,mixed>> */
    public array $operations = [];
    /** @var array<string,array<string,mixed>> */
    public array $challenges = [];
    /** @var array<string,array<string,mixed>> */
    public array $audits = [];
    /** @var array<int,array<string,mixed>> */
    public array $transactions = [];
    /** @var string[] */
    public array $log = [];

    /** @var array<string,mixed>|null */
    private ?array $snapshot = null;

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $index = 0;
        return (string) preg_replace_callback('/%[sd]/', static function (array $match) use (&$index, $args): string {
            $value = $args[$index++] ?? null;
            return $match[0] === '%d'
                ? (string) (int) $value
                : "'" . str_replace("'", "''", (string) $value) . "'";
        }, $query);
    }

    public function get_row(string $query, mixed $output = null): ?array
    {
        unset($output);
        $this->last_error = '';
        $table = $this->tableFrom($query);
        $this->log[] = match (true) {
            $table === 'wp_ys_helcim_operations' && str_contains($query, 'vendor_transaction_id')
                => 'SELECT operation receipt FOR UPDATE',
            $table === 'wp_fct_order_transactions'
                => 'SELECT FluentCart receipt FOR UPDATE',
            default => 'SELECT ' . $table,
        };
        if ($table === 'wp_ys_helcim_operations') {
            if (str_contains($query, 'vendor_transaction_id')) {
                if ($this->throwOperationReceiptRead) {
                    $this->throwOperationReceiptRead = false;
                    throw new \RuntimeException('Simulated operation receipt lookup exception');
                }
                if ($this->failOperationReceiptRead) {
                    $this->failOperationReceiptRead = false;
                    $this->last_error = 'Simulated operation receipt lookup failure';
                    return null;
                }
                $candidate = $this->whereValue($query, 'vendor_transaction_id');
                $mode = $this->whereValue($query, 'payment_mode');
                foreach ($this->operations as $operation) {
                    if (
                        ($operation['vendor_transaction_id'] ?? null) === $candidate
                        && ($mode === null || ($operation['payment_mode'] ?? null) === $mode)
                    ) {
                        return $operation;
                    }
                }
                return null;
            }
            if ($this->throwOperationRead) {
                $this->throwOperationRead = false;
                throw new \RuntimeException('Simulated operation lookup exception');
            }
            $uuid = $this->whereValue($query, 'operation_uuid');
            return $uuid === null ? null : ($this->operations[$uuid] ?? null);
        }
        if ($table === 'wp_ys_helcim_resolution_challenges') {
            if ($this->throwChallengeRead) {
                $this->throwChallengeRead = false;
                throw new \RuntimeException('Simulated challenge lookup exception');
            }
            $hash = $this->whereValue($query, 'challenge_hash');
            return $hash === null ? null : ($this->challenges[$hash] ?? null);
        }
        if ($table === 'wp_ys_helcim_refund_resolutions') {
            $uuid = $this->whereValue($query, 'operation_uuid');
            if ($uuid !== null) {
                return $this->audits[$uuid] ?? null;
            }
            $candidate = $this->whereValue($query, 'candidate_transaction_id');
            $mode = $this->whereValue($query, 'payment_mode');
            foreach ($this->audits as $audit) {
                if (
                    ($audit['candidate_transaction_id'] ?? null) === $candidate
                    && ($mode === null || ($audit['payment_mode'] ?? null) === $mode)
                ) {
                    return $audit;
                }
            }
        }
        if ($table === 'wp_fct_order_transactions') {
            if ($this->throwTransactionReceiptRead) {
                $this->throwTransactionReceiptRead = false;
                throw new \RuntimeException('Simulated FluentCart receipt lookup exception');
            }
            if ($this->failTransactionReceiptRead) {
                $this->failTransactionReceiptRead = false;
                $this->last_error = 'Simulated FluentCart receipt lookup failure';
                return null;
            }
            $candidate = $this->whereValue($query, 'vendor_charge_id');
            $mode = $this->whereValue($query, 'payment_mode');
            foreach ($this->transactions as $transaction) {
                if (
                    ($transaction['vendor_charge_id'] ?? null) === $candidate
                    && ($mode === null || ($transaction['payment_mode'] ?? null) === $mode)
                    && ($transaction['transaction_type'] ?? null) === 'refund'
                    && in_array(($transaction['payment_method'] ?? null), ['ys_helcim', 'ys_helcim_js'], true)
                ) {
                    return $transaction;
                }
            }
        }
        return null;
    }

    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        unset($format);
        $this->last_error = '';
        $this->log[] = 'INSERT ' . $table;
        if ($table === 'wp_ys_helcim_resolution_challenges') {
            $hash = (string) ($data['challenge_hash'] ?? '');
            if (isset($this->challenges[$hash])) {
                $this->last_error = 'Duplicate entry challenge_hash';
                return false;
            }
            $this->insert_id++;
            $data['id'] = $this->insert_id;
            $this->challenges[$hash] = $data;
            return 1;
        }
        if ($table === 'wp_ys_helcim_refund_resolutions') {
            if ($this->failAuditInsertWithNonDuplicateCandidateError) {
                $this->failAuditInsertWithNonDuplicateCandidateError = false;
                $this->last_error = 'Invalid candidate_transaction_id value';
                return false;
            }
            foreach ($this->audits as $audit) {
                if (($audit['operation_uuid'] ?? null) === ($data['operation_uuid'] ?? null)) {
                    $this->last_error = 'Duplicate entry operation_uuid';
                    return false;
                }
                if (($audit['candidate_transaction_id'] ?? null) === ($data['candidate_transaction_id'] ?? null)) {
                    $this->last_error = "Duplicate entry for key 'candidate_transaction_id'";
                    return false;
                }
            }
            $this->insert_id++;
            $data['id'] = $this->insert_id;
            $this->audits[(string) $data['operation_uuid']] = $data;
            return 1;
        }
        $this->last_error = 'Unknown table';
        return false;
    }

    public function update(string $table, array $data, array $where, ?array $format = null, ?array $whereFormat = null): int|false
    {
        unset($format, $whereFormat);
        $this->last_error = '';
        $this->log[] = 'UPDATE ' . $table;
        if ($table === 'wp_ys_helcim_operations') {
            if ($this->throwOperationUpdate) {
                $this->throwOperationUpdate = false;
                throw new \RuntimeException('Simulated operation update exception');
            }
            if ($this->failOperationCasWithDuplicateReceipt && isset($data['vendor_transaction_id'])) {
                $this->failOperationCasWithDuplicateReceipt = false;
                $this->last_error = "Duplicate entry for key 'vendor_transaction_id'";
                return false;
            }
            if ($this->failOperationCasWithOtherDuplicate && isset($data['vendor_transaction_id'])) {
                $this->failOperationCasWithOtherDuplicate = false;
                $this->last_error = "Duplicate entry for key 'some_future_unique_key'";
                return false;
            }
            if ($this->failOperationCas) {
                return 0;
            }
            $uuid = (string) ($where['operation_uuid'] ?? '');
            $row = $this->operations[$uuid] ?? null;
            if (!is_array($row) || !$this->matches($row, $where)) {
                return 0;
            }
            $this->operations[$uuid] = array_merge($row, $data);
            return 1;
        }
        if ($table === 'wp_ys_helcim_resolution_challenges') {
            $hash = (string) ($where['challenge_hash'] ?? '');
            $row = $this->challenges[$hash] ?? null;
            if (!is_array($row) || !$this->matches($row, $where)) {
                return 0;
            }
            $this->challenges[$hash] = array_merge($row, $data);
            return 1;
        }
        return false;
    }

    public function query(string $query): int|false
    {
        $command = strtoupper(trim($query));
        $this->log[] = $command;
        if ($command === 'START TRANSACTION') {
            if ($this->throwStart) {
                $this->throwStart = false;
                throw new \RuntimeException('Simulated transaction start exception');
            }
            if ($this->failStart) {
                return false;
            }
            $this->snapshot = [
                'operations' => $this->operations,
                'challenges' => $this->challenges,
                'audits' => $this->audits,
                'transactions' => $this->transactions,
                'insert_id' => $this->insert_id,
            ];
            return 1;
        }
        if ($command === 'ROLLBACK') {
            if (is_array($this->snapshot)) {
                $this->operations = $this->snapshot['operations'];
                $this->challenges = $this->snapshot['challenges'];
                $this->audits = $this->snapshot['audits'];
                $this->transactions = $this->snapshot['transactions'];
                $this->insert_id = $this->snapshot['insert_id'];
            }
            $this->snapshot = null;
            return 1;
        }
        if ($command === 'COMMIT') {
            if ($this->throwCommit) {
                $this->throwCommit = false;
                throw new \RuntimeException('Simulated commit exception');
            }
            if ($this->failCommit) {
                return false;
            }
            $this->snapshot = null;
            return 1;
        }
        return 1;
    }

    private function tableFrom(string $query): string
    {
        if (preg_match('/\b(?:FROM|INTO|UPDATE)\s+`?([a-zA-Z0-9_]+)`?/i', $query, $matches) === 1) {
            return $matches[1];
        }
        return '';
    }

    private function whereValue(string $query, string $field): ?string
    {
        if (preg_match('/\b' . preg_quote($field, '/') . "\s*=\s*'([^']*)'/i", $query, $matches) === 1) {
            return str_replace("''", "'", $matches[1]);
        }
        return null;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $where */
    private function matches(array $row, array $where): bool
    {
        foreach ($where as $field => $value) {
            if (($row[$field] ?? null) !== $value) {
                return false;
            }
        }
        return true;
    }
}
