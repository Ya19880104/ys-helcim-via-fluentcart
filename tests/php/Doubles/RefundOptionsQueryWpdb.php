<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Doubles;

final class RefundOptionsQueryWpdb
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    public bool $failRead = false;
    public bool $failCommit = false;
    /** @var string[] */
    public array $commands = [];
    /** @var array<string,mixed>|null */
    public ?array $order = null;
    /** @var array<int,array<string,mixed>> */
    public array $transactions = [];
    /** @var array<int,array<string,mixed>> */
    public array $items = [];
    /** @var array<int,array<string,mixed>> */
    public array $operations = [];

    /** @var string[] */
    public array $preparedQueries = [];

    /** @return array{query:string,args:array<int,mixed>} */
    public function prepare(string $query, mixed ...$args): array
    {
        $this->preparedQueries[] = $query;
        return ['query' => $query, 'args' => $args];
    }

    public function query(string $sql): int|false
    {
        $command = strtoupper(trim(preg_replace('/\s+/', ' ', $sql) ?? $sql));
        $this->commands[] = $command;
        $this->last_error = '';
        if ($command === 'COMMIT' && $this->failCommit) {
            $this->last_error = 'Simulated commit failure';
            return false;
        }
        return in_array($command, [
            'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
            'START TRANSACTION READ ONLY',
            'COMMIT',
            'ROLLBACK',
        ], true) ? 1 : false;
    }

    /** @param array{query:string,args:array<int,mixed>} $prepared */
    public function get_row(array $prepared, string $output = ARRAY_A): ?array
    {
        unset($output);
        $this->maybeFail();
        return str_contains($prepared['query'], 'ys_helcim_refund_options_order') ? $this->order : null;
    }

    /** @param array{query:string,args:array<int,mixed>} $prepared @return array<int,array<string,mixed>> */
    public function get_results(array $prepared, string $output = ARRAY_A): array
    {
        unset($output);
        $this->maybeFail();
        return match (true) {
            str_contains($prepared['query'], 'ys_helcim_refund_options_transactions') => $this->transactions,
            str_contains($prepared['query'], 'ys_helcim_refund_options_items') => $this->items,
            str_contains($prepared['query'], 'ys_helcim_refund_options_operations') => $this->operations,
            default => [],
        };
    }

    private function maybeFail(): void
    {
        $this->last_error = $this->failRead ? 'Simulated read failure' : '';
    }
}
