<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Doubles;

final class RefundResolutionSchemaWpdb
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    public bool $challengeInstalled = false;
    public bool $auditInstalled = false;
    public string $challengeEngine = 'InnoDB';
    public string $auditEngine = 'InnoDB';
    /** @var string[] */
    public array $challengeUniqueIndexes = ['challenge_hash'];
    /** @var string[] */
    public array $auditUniqueIndexes = ['operation_uuid', 'challenge_hash', 'candidate_transaction_id'];
    public int $schemaMetadataQueryCount = 0;

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4';
    }

    public function esc_like(string $value): string
    {
        return $value;
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $index = 0;
        return (string) preg_replace_callback('/%s/', static function () use (&$index, $args): string {
            return "'" . (string) ($args[$index++] ?? '') . "'";
        }, $query);
    }

    public function get_var(string $query): ?string
    {
        if (str_contains($query, 'SHOW TABLES LIKE') || str_contains($query, 'information_schema.TABLES')) {
            ++$this->schemaMetadataQueryCount;
        }

        if (str_contains($query, 'SHOW TABLES LIKE')) {
            if (str_contains($query, 'ys_helcim_resolution_challenges') && $this->challengeInstalled) {
                return 'wp_ys_helcim_resolution_challenges';
            }
            if (str_contains($query, 'ys_helcim_refund_resolutions') && $this->auditInstalled) {
                return 'wp_ys_helcim_refund_resolutions';
            }
            return null;
        }
        if (str_contains($query, 'information_schema.TABLES')) {
            return str_contains($query, 'ys_helcim_resolution_challenges')
                ? $this->challengeEngine
                : $this->auditEngine;
        }
        return null;
    }

    /** @return array<int,array<string,string>> */
    public function get_results(string $query, mixed $output = null): array
    {
        unset($output);
        if (str_contains($query, 'SHOW INDEX')) {
            ++$this->schemaMetadataQueryCount;
        }
        $indexes = str_contains($query, 'ys_helcim_resolution_challenges')
            ? $this->challengeUniqueIndexes
            : $this->auditUniqueIndexes;
        $rows = [];
        foreach ($indexes as $name) {
            $columns = [$name];
            foreach ($columns as $offset => $column) {
                $rows[] = [
                    'Key_name' => $name,
                    'Column_name' => $column,
                    'Non_unique' => '0',
                    'Seq_in_index' => (string) ($offset + 1),
                    'Sub_part' => null,
                ];
            }
        }
        return $rows;
    }
}
