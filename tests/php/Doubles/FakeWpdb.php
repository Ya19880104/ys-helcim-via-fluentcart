<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Doubles;

final class FakeWpdb
{
    public string $prefix = 'wp_';

    public int $insert_id = 0;

    public string $last_error = '';

    public bool $failNextSchemaInstall = false;

    public bool $failNextInsert = false;

    public bool $failNextLookup = false;

    public bool $failNextResults = false;

    public bool $failNextUpdate = false;

	/** @var array<string,mixed> */
	public array $lastUpdateWhere = [];

    public ?string $failNextUpdateForOperationUuid = null;

    public bool $schemaInstalled = false;

    public bool $outboxSchemaInstalled = false;

    public bool $webhookReceiptSchemaInstalled = false;

    public bool $resolutionChallengeSchemaInstalled = false;

    public bool $resolutionAuditSchemaInstalled = false;

    /** @var string[] */
    public array $schemaIndexes = [];

    /** @var string[] */
    public array $outboxSchemaIndexes = [];

    /** @var string[] */
    public array $webhookReceiptSchemaIndexes = [];

    /** @var string[] */
    public array $resolutionChallengeSchemaIndexes = [];

    /** @var string[] */
    public array $resolutionAuditSchemaIndexes = [];

    /** @var string[] */
    public array $schemaColumns = [];

    public string $schemaEngine = 'InnoDB';

    /** @var string[] */
    public array $nonUniqueIndexes = [];

    /** @var array<string, string> */
    public array $schemaIndexColumns = [];

    public bool $reverseParentOperationIndexRows = false;

    public bool $prefixProviderReceiptIndex = false;

    public int $schemaMetadataQueryCount = 0;

    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    /** @var array{rows: array<int, array<string, mixed>>, insert_id: int}|null */
    private ?array $transactionSnapshot = null;

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function insert(string $table, array $data, ?array $formats = null): int|false
    {
        unset($table, $formats);
        $this->last_error = '';

        if ($this->failNextInsert) {
            $this->failNextInsert = false;
            $this->last_error = 'Table does not exist';
            return false;
        }

        foreach ($this->rows as $row) {
            foreach (['operation_uuid', 'idempotency_key'] as $uniqueField) {
                if (($row[$uniqueField] ?? null) === ($data[$uniqueField] ?? null)) {
                    $this->last_error = 'Duplicate entry for ' . $uniqueField;
                    return false;
                }
            }

            foreach (['active_scope_key', 'provider_correlation_id', 'vendor_transaction_id', 'local_transaction_id'] as $nullableUniqueField) {
                $candidate = $data[$nullableUniqueField] ?? null;
                if ($candidate !== null && ($row[$nullableUniqueField] ?? null) === $candidate) {
                    $this->last_error = 'Duplicate entry for ' . $nullableUniqueField;
                    return false;
                }
            }

			$parent = $data['parent_operation_uuid'] ?? null;
			if (
				$parent !== null &&
				($row['parent_operation_uuid'] ?? null) === $parent &&
				($row['operation_type'] ?? null) === ($data['operation_type'] ?? null)
			) {
				$this->last_error = 'Duplicate entry for parent_operation_type';
				return false;
			}
        }

        $this->insert_id++;
        $data['id'] = $this->insert_id;
        $this->rows[$this->insert_id] = $data;

        return 1;
    }

    public function update(string $table, array $data, array $where, ?array $formats = null, ?array $whereFormats = null): int|false
    {
        unset($table, $formats, $whereFormats);
        $this->last_error = '';
		$this->lastUpdateWhere = $where;

        if ($this->failNextUpdate) {
            $this->failNextUpdate = false;
            $this->last_error = 'Simulated update failure';
            return false;
        }

		if (
			$this->failNextUpdateForOperationUuid !== null &&
			($where['operation_uuid'] ?? null) === $this->failNextUpdateForOperationUuid
		) {
			$this->failNextUpdateForOperationUuid = null;
			$this->last_error = 'Simulated targeted update failure';
			return false;
		}

        foreach ($this->rows as $id => $row) {
            if (!$this->matches($row, $where)) {
                continue;
            }

			if ( array_key_exists( 'vendor_transaction_id', $data ) && null !== $data['vendor_transaction_id'] ) {
				foreach ( $this->rows as $other_id => $other_row ) {
					if (
						$other_id !== $id &&
						( $other_row['vendor_transaction_id'] ?? null ) === $data['vendor_transaction_id']
					) {
						$this->last_error = "Duplicate entry for key 'vendor_transaction_id'";
						return false;
					}
				}
			}

            $this->rows[$id] = array_merge($row, $data);
            return 1;
        }

        return 0;
    }

    public function query(string|array $sql): int|false
    {
        if (is_array($sql)) {
            if (str_contains($sql['query'], 'ys_helcim_promote_stale_refund_processing_order')) {
                $orderId = (int) ($sql['args'][4] ?? 0);
                $expectedRemote = (string) ($sql['args'][5] ?? '');
                $cutoff = (string) ($sql['args'][6] ?? '');
                $updated = 0;
                foreach ($this->rows as $id => $row) {
                    if (
                        $updated >= 20 ||
                        (int) ($row['order_id'] ?? 0) !== $orderId ||
                        !in_array(($row['operation_type'] ?? null), ['refund', 'reverse'], true) ||
                        ($row['remote_status'] ?? null) !== $expectedRemote ||
                        !in_array(($row['local_status'] ?? null), ['pending', 'failed'], true) ||
                        empty($row['active_scope_key']) ||
                        (string) ($row['updated_at'] ?? '') > $cutoff
                    ) {
                        continue;
                    }

                    $this->rows[$id] = array_merge($row, [
                        'remote_status' => (string) $sql['args'][0],
                        'remote_error_code' => (string) $sql['args'][1],
                        'remote_error_message' => (string) $sql['args'][2],
                        'encrypted_material' => null,
                        'material_expires_at' => null,
                        'updated_at' => (string) $sql['args'][3],
                    ]);
                    ++$updated;
                }
                return $updated;
            }

            if (str_contains($sql['query'], 'ys_helcim_promote_stale_refund_processing')) {
                $operationUuid = (string) ($sql['args'][4] ?? '');
                $expectedRemote = (string) ($sql['args'][5] ?? '');
                $cutoff = (string) ($sql['args'][6] ?? '');
                foreach ($this->rows as $id => $row) {
                    if (
                        ($row['operation_uuid'] ?? null) !== $operationUuid ||
                        !in_array(($row['operation_type'] ?? null), ['refund', 'reverse'], true) ||
                        ($row['remote_status'] ?? null) !== $expectedRemote ||
                        !in_array(($row['local_status'] ?? null), ['pending', 'failed'], true) ||
                        empty($row['active_scope_key']) ||
                        (string) ($row['updated_at'] ?? '') > $cutoff
                    ) {
                        continue;
                    }

                    $this->rows[$id] = array_merge($row, [
                        'remote_status' => (string) $sql['args'][0],
                        'remote_error_code' => (string) $sql['args'][1],
                        'remote_error_message' => (string) $sql['args'][2],
                        'encrypted_material' => null,
                        'material_expires_at' => null,
                        'updated_at' => (string) $sql['args'][3],
                    ]);
                    return 1;
                }

                return 0;
            }

            if (
                str_contains($sql['query'], 'remote_status = %s') &&
                str_contains($sql['query'], 'created_at <= %s') &&
                str_contains($sql['query'], 'active_scope_key = %s')
            ) {
                $scopeKey = (string) ($sql['args'][8] ?? '');
                $expectedRemote = (string) ($sql['args'][9] ?? '');
                $expectedLocal = (string) ($sql['args'][10] ?? '');
                $cutoff = (string) ($sql['args'][11] ?? '');

                foreach ($this->rows as $id => $row) {
                    if (
                        ($row['active_scope_key'] ?? null) !== $scopeKey ||
                        ($row['remote_status'] ?? null) !== $expectedRemote ||
                        ($row['local_status'] ?? null) !== $expectedLocal ||
                        (string) ($row['created_at'] ?? '') > $cutoff
                    ) {
                        continue;
                    }

                    $this->rows[$id] = array_merge($row, [
                        'remote_status' => (string) $sql['args'][0],
                        'local_status' => (string) $sql['args'][1],
                        'active_scope_key' => null,
                        'remote_error_code' => (string) $sql['args'][2],
                        'remote_error_message' => (string) $sql['args'][3],
                        'local_error_code' => (string) $sql['args'][4],
                        'local_error_message' => (string) $sql['args'][5],
                        'encrypted_material' => null,
                        'material_expires_at' => null,
                        'confirm_token_hash' => null,
                        'confirm_token_expires_at' => null,
                        'resolved_at' => (string) $sql['args'][6],
                        'updated_at' => (string) $sql['args'][7],
                    ]);
                    return 1;
                }

                return 0;
            }

            if (str_contains($sql['query'], 'encrypted_material = NULL')) {
                $now = (string) ($sql['args'][1] ?? '');
                $limit = (int) ($sql['args'][2] ?? 100);
                $updated = 0;
                foreach ($this->rows as $id => $row) {
                    if (
                        $updated < $limit &&
                        !empty($row['encrypted_material']) &&
                        !empty($row['material_expires_at']) &&
                        (string) $row['material_expires_at'] <= $now
                    ) {
                        $this->rows[$id]['encrypted_material'] = null;
                        $this->rows[$id]['material_expires_at'] = null;
                        $this->rows[$id]['updated_at'] = $now;
                        $updated++;
                    }
                }
                return $updated;
            }

            return false;
        }

        $command = strtoupper(trim($sql));
        if ($command === 'START TRANSACTION') {
            $this->transactionSnapshot = ['rows' => $this->rows, 'insert_id' => $this->insert_id];
            return 1;
        }

        if ($command === 'COMMIT') {
            $this->transactionSnapshot = null;
            return 1;
        }

        if ($command === 'ROLLBACK') {
            if ($this->transactionSnapshot !== null) {
                $this->rows = $this->transactionSnapshot['rows'];
                $this->insert_id = $this->transactionSnapshot['insert_id'];
            }
            $this->transactionSnapshot = null;
            return 1;
        }

        return false;
    }

    /** @return array{query: string, args: array<int, mixed>} */
    public function prepare(string $query, mixed ...$args): array
    {
        return ['query' => $query, 'args' => $args];
    }

    /** @param array{query: string, args: array<int, mixed>} $prepared */
    public function get_row(array $prepared, string $output = ARRAY_A): ?array
    {
        unset($output);
        if ($this->failNextLookup) {
            $this->failNextLookup = false;
            $this->last_error = 'Simulated lookup failure';
            return null;
        }
        $query = $prepared['query'];
        $value = $prepared['args'][0] ?? null;

		if (str_contains($query, 'parent_operation_uuid')) {
			foreach ($this->rows as $row) {
				if (
					($row['parent_operation_uuid'] ?? null) === $value &&
					($row['operation_type'] ?? null) === ($prepared['args'][1] ?? null)
				) {
					return $row;
				}
			}

			return null;
		}

		$field = str_contains($query, 'active_scope_key') ? 'active_scope_key' : 'operation_uuid';
        foreach ($this->rows as $row) {
            if (($row[$field] ?? null) === $value) {
                return $row;
            }
        }

        return null;
    }

    /** @param array{query: string, args: array<int, mixed>} $prepared */
    public function get_var(array $prepared): ?string
    {
        if (
            str_contains($prepared['query'], 'SHOW TABLES LIKE')
            || str_contains($prepared['query'], 'information_schema.TABLES')
        ) {
            ++$this->schemaMetadataQueryCount;
        }

        $isOutbox = str_contains($prepared['query'], 'ys_helcim_outbox')
            || str_contains((string) ($prepared['args'][0] ?? ''), 'ys_helcim_outbox');
        $isWebhookReceipt = str_contains($prepared['query'], 'ys_helcim_webhook_receipts')
            || str_contains((string) ($prepared['args'][0] ?? ''), 'ys_helcim_webhook_receipts');
        $isResolutionChallenge = str_contains($prepared['query'], 'ys_helcim_resolution_challenges')
            || str_contains((string) ($prepared['args'][0] ?? ''), 'ys_helcim_resolution_challenges');
        $isResolutionAudit = str_contains($prepared['query'], 'ys_helcim_refund_resolutions')
            || str_contains((string) ($prepared['args'][0] ?? ''), 'ys_helcim_refund_resolutions');
        if (
            ($isOutbox && !$this->outboxSchemaInstalled) ||
            ($isWebhookReceipt && !$this->webhookReceiptSchemaInstalled) ||
            ($isResolutionChallenge && !$this->resolutionChallengeSchemaInstalled) ||
            ($isResolutionAudit && !$this->resolutionAuditSchemaInstalled) ||
            (!$isOutbox && !$isWebhookReceipt && !$isResolutionChallenge && !$isResolutionAudit && !$this->schemaInstalled)
        ) {
            return null;
        }

        if (str_contains($prepared['query'], 'SELECT ENGINE')) {
            return $this->schemaEngine;
        }

        return (string) ($prepared['args'][0] ?? $this->prefix . 'ys_helcim_operations');
    }

    /** @return array<int, array<string, mixed>> */
    public function get_results(string|array $query, string $output = ARRAY_A): array
    {
		unset($output);
		if (is_array($query)) {
			if ($this->failNextResults) {
				$this->failNextResults = false;
				$this->last_error = 'Simulated results failure';
				return [];
			}

			if (str_contains($query['query'], 'ys_helcim_hosted_purchase_recovery_scan')) {
				$gateway = (string) ($query['args'][0] ?? '');
				$localClaimedBefore = (string) ($query['args'][1] ?? '');
				$cutoff = (string) ($query['args'][2] ?? '');
				$maxAttempts = (int) ($query['args'][3] ?? 0);
				$dueBefore = (string) ($query['args'][4] ?? '');
				$limit = (int) ($query['args'][5] ?? 0);
				$rows = array_values(array_filter(
					$this->rows,
					static fn (array $row): bool =>
						($row['operation_type'] ?? null) === 'purchase' &&
						($row['gateway'] ?? null) === $gateway &&
						in_array(($row['remote_status'] ?? null), ['processing', 'indeterminate', 'succeeded'], true) &&
						(
							in_array(($row['local_status'] ?? null), ['pending', 'failed'], true) ||
							(
								($row['remote_status'] ?? null) === 'succeeded' &&
								($row['local_status'] ?? null) === 'applying' &&
								!empty($row['local_claimed_at']) &&
								(string) $row['local_claimed_at'] <= $localClaimedBefore
							)
						) &&
						!empty($row['active_scope_key']) &&
						(
							($row['remote_status'] ?? null) === 'succeeded' ||
							(string) ($row['created_at'] ?? '') <= $cutoff
						) &&
						(int) ($row['recovery_attempt_count'] ?? 0) < $maxAttempts &&
						(
							empty($row['next_recovery_at']) ||
							(string) $row['next_recovery_at'] <= $dueBefore
						)
				));
				usort($rows, static fn (array $left, array $right): int =>
					[
						($left['remote_status'] ?? null) === 'succeeded' ? 0 : 1,
						(string) ($left['next_recovery_at'] ?? $left['created_at'] ?? ''),
						(int) ($left['id'] ?? 0),
					]
					<=>
					[
						($right['remote_status'] ?? null) === 'succeeded' ? 0 : 1,
						(string) ($right['next_recovery_at'] ?? $right['created_at'] ?? ''),
						(int) ($right['id'] ?? 0),
					]
				);
				return array_slice($rows, 0, max(0, $limit));
			}

			if (str_contains($query['query'], 'ys_helcim_hosted_purchase_attention_scan')) {
				$gateway = (string) ($query['args'][0] ?? '');
				$maxAttempts = (int) ($query['args'][1] ?? 0);
				$limit = (int) ($query['args'][2] ?? 0);
				$rows = array_values(array_filter(
					$this->rows,
					static fn (array $row): bool =>
						($row['operation_type'] ?? null) === 'purchase' &&
						($row['gateway'] ?? null) === $gateway &&
						!empty($row['active_scope_key']) &&
						in_array(($row['local_status'] ?? null), ['pending', 'failed', 'applying'], true) &&
						(
							in_array(($row['remote_status'] ?? null), ['indeterminate', 'succeeded'], true) ||
							(int) ($row['recovery_attempt_count'] ?? 0) >= $maxAttempts
						)
				));
				usort($rows, static fn (array $left, array $right): int =>
					[(string) ($left['updated_at'] ?? ''), (int) ($left['id'] ?? 0)]
					<=>
					[(string) ($right['updated_at'] ?? ''), (int) ($right['id'] ?? 0)]
				);
				return array_slice($rows, 0, max(0, $limit));
			}

			if (str_contains($query['query'], 'operation_type = %s')) {
				[$operationType, $transactionId] = $query['args'];
				$rows = array_values(array_filter(
					$this->rows,
					static fn (array $row): bool =>
						($row['operation_type'] ?? null) === $operationType &&
						(int) ($row['transaction_id'] ?? 0) === (int) $transactionId
				));
				usort($rows, static fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);
				return $rows;
			}

			return [];
		}

		if (str_contains($query, 'SHOW INDEX') || str_contains($query, 'SHOW COLUMNS')) {
			++$this->schemaMetadataQueryCount;
		}

		if (str_contains($query, 'SHOW COLUMNS')) {
			return array_map(
				static fn (string $column): array => ['Field' => $column],
				$this->schemaColumns
			);
		}

		$indexes = str_contains($query, 'ys_helcim_outbox')
            ? $this->outboxSchemaIndexes
            : (str_contains($query, 'ys_helcim_webhook_receipts')
                ? $this->webhookReceiptSchemaIndexes
                : (str_contains($query, 'ys_helcim_resolution_challenges')
                    ? $this->resolutionChallengeSchemaIndexes
                    : (str_contains($query, 'ys_helcim_refund_resolutions')
                        ? $this->resolutionAuditSchemaIndexes
                        : $this->schemaIndexes)));
		$results = [];
		foreach ($indexes as $key) {
			$columns = match ($key) {
				'parent_operation_type' => ['parent_operation_uuid', 'operation_type'],
				'operation_effect' => ['operation_uuid', 'effect_type'],
				default => [$this->schemaIndexColumns[$key] ?? $key],
			};
			foreach ($columns as $offset => $column) {
				$results[] = [
					'Key_name' => $key,
					'Non_unique' => in_array($key, $this->nonUniqueIndexes, true) ? '1' : '0',
					'Column_name' => $column,
					'Seq_in_index' => (string) ($offset + 1),
					'Sub_part' => $key === 'vendor_transaction_id' && $this->prefixProviderReceiptIndex
						? '16'
						: null,
				];
			}
			if ($key === 'parent_operation_type' && $this->reverseParentOperationIndexRows) {
				$tail = array_splice($results, -count($columns));
				$results = array_merge($results, array_reverse($tail));
			}
		}

		return $results;
    }

    /** @return array<int, array<string, mixed>> */
    public function allRows(): array
    {
        return array_values($this->rows);
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $where */
    private function matches(array $row, array $where): bool
    {
        foreach ($where as $field => $expected) {
            if (!array_key_exists($field, $row) || $row[$field] !== $expected) {
                return false;
            }
        }

        return true;
    }
}
