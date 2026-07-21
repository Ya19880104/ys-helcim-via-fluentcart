<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Doubles;

use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundResolutionStore;

final class RefundResolutionStoreDouble implements YSHelcimRefundResolutionStore
{
    /** @var array<string,array<string,mixed>> */
    public array $operations = [];

    /** @var array<string,array<string,mixed>> */
    public array $challenges = [];

    /** @var array<string,array<string,mixed>> */
    public array $resolutions = [];

    /** @var array<int,array<string,mixed>> */
    public array $commitCalls = [];

    /** @var string[] */
    public array $promoteCalls = [];

    public mixed $createChallengeResult = true;
    public mixed $commitResult = null;
    public mixed $promoteResult = 0;

    public function findOperation(string $operationUuid): array|\WP_Error|null
    {
        return $this->operations[strtolower($operationUuid)] ?? null;
    }

    public function promoteStaleProcessing(string $operationUuid): int|\WP_Error
    {
        $this->promoteCalls[] = $operationUuid;
        if (
            $this->promoteResult === 1
            && isset($this->operations[$operationUuid])
            && ($this->operations[$operationUuid]['remote_status'] ?? null) === 'processing'
        ) {
            $this->operations[$operationUuid]['remote_status'] = 'indeterminate';
        }
        return $this->promoteResult;
    }

    public function createChallenge(array $challenge): bool|\WP_Error
    {
        if ($this->createChallengeResult !== true) {
            return $this->createChallengeResult;
        }

        foreach ($this->resolutions as $resolution) {
            if ($resolution['candidate_transaction_id'] === $challenge['candidate_transaction_id']) {
                return new \WP_Error('ys_helcim_resolution_candidate_used', 'Candidate already used.');
            }
        }

        $this->challenges[$challenge['challenge_hash']] = $challenge + ['used_at' => null];
        return true;
    }

    public function findResolutionReplay(array $binding): array|\WP_Error|null
    {
        $challenge = $this->challenges[$binding['challenge_hash']] ?? null;
        $resolution = $this->resolutions[$binding['operation_uuid']] ?? null;
        if (!is_array($challenge) || !is_array($resolution) || $challenge['used_at'] === null) {
            return null;
        }

        foreach (['operation_uuid', 'candidate_transaction_id', 'actor_user_id', 'phrase_hash'] as $field) {
            if (($challenge[$field] ?? null) !== ($binding[$field] ?? null)) {
                return null;
            }
        }

        $operation = $this->operations[$binding['operation_uuid']] ?? null;
        if (
            !is_array($operation)
            || ($operation['remote_status'] ?? null) !== 'succeeded'
            || ($operation['vendor_transaction_id'] ?? null) !== $binding['candidate_transaction_id']
            || ($resolution['challenge_hash'] ?? null) !== $binding['challenge_hash']
        ) {
            return null;
        }

        return $resolution + ['operation' => $operation, 'replayed' => true];
    }

    public function commitResolution(array $resolution): array|\WP_Error
    {
        $this->commitCalls[] = $resolution;
        if ($this->commitResult instanceof \WP_Error) {
            return $this->commitResult;
        }

        $challenge = $this->challenges[$resolution['challenge_hash']] ?? null;
        $operation = $this->operations[$resolution['operation_uuid']] ?? null;
        if (!is_array($challenge) || !is_array($operation)) {
            return new \WP_Error('ys_helcim_resolution_conflict', 'Missing binding.');
        }

        foreach (
            [
                'operation_uuid', 'candidate_transaction_id', 'source_transaction_id', 'action',
                'proof_digest', 'state_updated_at', 'actor_user_id', 'phrase_hash',
                'parent_attestation_required',
            ] as $field
        ) {
            if (($challenge[$field] ?? null) !== ($resolution[$field] ?? null)) {
                return new \WP_Error('ys_helcim_resolution_conflict', 'Binding changed.');
            }
        }

        if (
            $challenge['used_at'] !== null
            || $challenge['expires_at'] <= $resolution['now']
            || ($operation['remote_status'] ?? null) !== 'indeterminate'
            || !in_array(($operation['local_status'] ?? null), ['pending', 'failed'], true)
            || ($operation['updated_at'] ?? null) !== $resolution['state_updated_at']
            || ($operation['active_scope_key'] ?? null) !== ($operation['scope_key'] ?? null)
        ) {
            return new \WP_Error('ys_helcim_resolution_conflict', 'State changed.');
        }

        foreach ($this->resolutions as $existing) {
            if ($existing['candidate_transaction_id'] === $resolution['candidate_transaction_id']) {
                return new \WP_Error('ys_helcim_resolution_candidate_used', 'Candidate already used.');
            }
        }

        $this->operations[$resolution['operation_uuid']]['remote_status'] = 'succeeded';
        $this->operations[$resolution['operation_uuid']]['vendor_transaction_id'] = $resolution['candidate_transaction_id'];
        $this->operations[$resolution['operation_uuid']]['updated_at'] = $resolution['now'];
        $this->challenges[$resolution['challenge_hash']]['used_at'] = $resolution['now'];
        $stored = $resolution + ['resolved_at' => $resolution['now']];
        $this->resolutions[$resolution['operation_uuid']] = $stored;

        return $stored + [
            'operation' => $this->operations[$resolution['operation_uuid']],
            'replayed' => false,
        ];
    }
}
