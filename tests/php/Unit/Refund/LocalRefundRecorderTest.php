<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Refund;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationScope;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimLocalRefundRecorder;
use YangSheep\Helcim\FluentCart\Refund\YSHelcimRefundPayload;
use YangSheep\Helcim\FluentCart\Tests\Doubles\LocalRefundWpdb;

final class LocalRefundRecorderTest extends TestCase
{
    private const OPERATION_UUID = '00000000-0000-4000-8000-000000000002';

    private LocalRefundWpdb $database;

    protected function setUp(): void
    {
        $this->database = new LocalRefundWpdb();
        $this->seedCanonicalState();
    }

    public function testRecordsOneCanonicalRefundAndAppliesAbsoluteAccounting(): void
    {
        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertSame(self::OPERATION_UUID, $result['operation_uuid']);
        self::assertSame(22, $result['local_transaction_id']);
        self::assertSame('recorded', $result['local_status']);
        self::assertFalse($result['replayed']);
        self::assertSame(
            ['operation', 'source_transaction', 'order', 'refund_rows', 'items', 'outbox'],
            $this->database->lockSequence
        );

        $refunds = $this->refundRows();
        self::assertCount(2, $refunds);
        $created = $refunds[1];
        self::assertSame(10, $created['order_id']);
        self::assertSame('payment', $created['order_type']);
        self::assertSame('refund', $created['transaction_type']);
        self::assertNull($created['subscription_id']);
        self::assertSame(4242, $created['card_last_4']);
        self::assertSame('visa', $created['card_brand']);
        self::assertSame('51177123', $created['vendor_charge_id']);
        self::assertSame('ys_helcim', $created['payment_method']);
        self::assertSame('test', $created['payment_mode']);
        self::assertSame('card', $created['payment_method_type']);
        self::assertSame('refunded', $created['status']);
        self::assertSame('USD', $created['currency']);
        self::assertSame(2100, $created['total']);
        self::assertSame('1.0000', $created['rate']);
        self::assertSame(self::OPERATION_UUID, $created['uuid']);
        self::assertSame('2026-07-21 03:00:00', $created['created_at']);
        self::assertSame('2026-07-21 03:00:00', $created['updated_at']);

        $meta = json_decode($created['meta'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(20, $meta['parent_id']);
        self::assertSame('Customer requested refund', $meta['reason']);
        self::assertSame(self::OPERATION_UUID, $meta['ys_helcim_operation_uuid']);
        self::assertSame(self::OPERATION_UUID, $meta['ys_helcim_root_refund_uuid']);
        self::assertSame('refund', $meta['ys_helcim_provider_action']);
        self::assertSame('51177061', $meta['ys_helcim_original_vendor_transaction_id']);
        self::assertSame([], $meta['item_ids']);
        self::assertFalse($meta['manageStock']);
        self::assertSame([], $meta['refunded_items']);

        $source = $this->row('wp_fct_order_transactions', 20);
        $sourceMeta = json_decode($source['meta'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('keep-me', $sourceMeta['note']);
        self::assertSame(3100, $sourceMeta['refunded_total']);

        $order = $this->row('wp_fct_orders', 10);
        self::assertSame(3100, $order['total_refund']);
        self::assertSame('partially_refunded', $order['payment_status']);
        self::assertSame('2026-07-21 03:00:00', $order['refunded_at']);

        self::assertSame(1860, $this->row('wp_fct_order_items', 101)['refund_total']);
        self::assertSame(1240, $this->row('wp_fct_order_items', 102)['refund_total']);

        $operation = $this->row('wp_ys_helcim_operations', 1);
        self::assertSame(22, $operation['local_transaction_id']);
        self::assertSame('recorded', $operation['local_status']);
        self::assertSame('2026-07-21 03:00:00', $operation['local_recorded_at']);
        self::assertNull($operation['local_applied_at']);
        self::assertSame(
            YSHelcimOperationScope::fromBusinessKey('refund-order:10'),
            $operation['active_scope_key']
        );
        self::assertNull($operation['resolved_at']);
        self::assertSame(['applying', 'recorded'], $this->database->journalStatusTransitions);

        $effects = $this->outboxRows();
        self::assertCount(3, $effects);
        self::assertSame(['stock_restore', 'customer_recount', 'refund_hooks'], array_column($effects, 'effect_type'));
        self::assertSame([10, 20, 30], array_column($effects, 'sequence'));
        self::assertSame(['at_most_once', 'idempotent', 'at_most_once'], array_column($effects, 'effect_class'));
        self::assertSame(['skipped', 'pending', 'pending'], array_column($effects, 'status'));

        foreach ($effects as $effect) {
            self::assertSame(self::OPERATION_UUID, $effect['operation_uuid']);
            self::assertSame(0, $effect['attempt_count']);
            self::assertNull($effect['claim_token']);
            self::assertSame('2026-07-21 03:00:00', $effect['available_at']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $effect['payload_hash']);
            self::assertSame(hash('sha256', $effect['payload']), $effect['payload_hash']);
        }
        self::assertSame('2026-07-21 03:00:00', $effects[0]['completed_at']);
        self::assertNull($effects[1]['completed_at']);
        self::assertNull($effects[2]['completed_at']);

        $stockPayload = json_decode($effects[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $stockPayload['version']);
        self::assertSame(self::OPERATION_UUID, $stockPayload['operation_uuid']);
        self::assertSame(10, $stockPayload['order_id']);
        self::assertSame(22, $stockPayload['local_transaction_id']);
        self::assertFalse($stockPayload['manage_stock']);
        self::assertSame([], $stockPayload['items']);

        $customerPayload = json_decode($effects[1]['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(77, $customerPayload['customer_id']);
        self::assertSame(22, $customerPayload['local_transaction_id']);

        $hooksPayload = json_decode($effects[2]['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(20, $hooksPayload['source_transaction_id']);
        self::assertSame(22, $hooksPayload['local_transaction_id']);
        self::assertSame(2100, $hooksPayload['refund_amount']);
        self::assertSame('partial', $hooksPayload['refund_type']);
        self::assertSame('refund', $hooksPayload['provider_action']);
        self::assertSame(42, $hooksPayload['actor_user_id']);
        self::assertFalse($hooksPayload['manage_stock']);
        self::assertFalse($hooksPayload['stock_restore_requested']);
    }

    public function testReplayReturnsTheSameRefundWithoutCreatingOrApplyingItAgain(): void
    {
        $first = $this->recorder()->record(self::OPERATION_UUID);
        $snapshot = $this->database->dataSnapshot();

        $second = $this->recorder()->record(self::OPERATION_UUID);

        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame($first['local_transaction_id'], $second['local_transaction_id']);
        self::assertSame('recorded', $second['local_status']);
        self::assertTrue($second['replayed']);
        self::assertSame($snapshot, $this->database->dataSnapshot());
        self::assertCount(2, $this->refundRows());
        self::assertCount(3, $this->outboxRows());
    }

    public function testReplayAcceptsMySqlJsonObjectKeyReorderingWithoutWeakeningTheReceipt(): void
    {
        $this->enableManageStockPayload();
        self::assertIsArray($this->recorder()->record(self::OPERATION_UUID));

        $receipt = $this->refundRows()[1];
        $meta = json_decode($receipt['meta'], true, 512, JSON_THROW_ON_ERROR);
        $meta = $this->reorderJsonObjectKeysRecursively($meta);
        self::assertNotSame(
            ['item_id', 'object_id', 'post_id', 'quantity', 'restore_quantity'],
            array_keys($meta['ys_helcim_stock_snapshot'][0])
        );
        self::assertNotSame(
            ['id', 'order_id', 'post_id', 'object_id', 'fulfillment_type', 'payment_type'],
            array_slice(array_keys($meta['ys_helcim_refunded_item_snapshots'][0]), 0, 6)
        );
        self::assertNotSame(
            ['effect_type', 'effect_class', 'sequence', 'status', 'payload', 'payload_hash'],
            array_keys($meta['ys_helcim_effect_plan'][0])
        );
        $receipt['meta'] = wp_json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->replace('wp_fct_order_transactions', $receipt);
        $before = $this->database->dataSnapshot();

        $replayed = $this->recorder()->record(self::OPERATION_UUID);

        self::assertIsArray($replayed);
        self::assertTrue($replayed['replayed']);
        self::assertSame('recorded', $replayed['local_status']);
        self::assertSame($before, $this->database->dataSnapshot());
        self::assertCount(2, $this->refundRows());
        self::assertCount(3, $this->outboxRows());
    }

    #[DataProvider('outboxPlanTampering')]
    public function testReplayFailsClosedWhenTheDurableOutboxPlanIsMissingOrTampered(string $mutation): void
    {
        self::assertIsArray($this->recorder()->record(self::OPERATION_UUID));
        $effects = $this->outboxRows();

        if ($mutation === 'missing') {
            $this->database->remove('wp_ys_helcim_outbox', (int) $effects[1]['id']);
        } elseif ($mutation === 'extra') {
            $this->database->seed('wp_ys_helcim_outbox', array_merge($effects[1], [
                'id' => 99,
                'effect_type' => 'unexpected_effect',
                'sequence' => 99,
            ]));
        } else {
            $target = $effects[2];
            if ($mutation === 'type') {
                $target['effect_type'] = 'wrong_hook';
            } elseif ($mutation === 'class') {
                $target['effect_class'] = 'idempotent';
            } elseif ($mutation === 'sequence') {
                $target['sequence'] = 31;
            } elseif ($mutation === 'status') {
                $target['status'] = 'unknown';
            } elseif ($mutation === 'hash') {
                $target['payload_hash'] = hash('sha256', 'wrong');
            } elseif ($mutation === 'canonical_payload') {
                $target['payload'] = wp_json_encode(['version' => 1, 'operation_uuid' => self::OPERATION_UUID]);
                $target['payload_hash'] = hash('sha256', $target['payload']);
            }
            $this->replace('wp_ys_helcim_outbox', $target);
        }
        $before = $this->database->dataSnapshot();

        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_accounting_drift', $result->get_error_code());
        self::assertSame($before, $this->database->dataSnapshot());
    }

    /** @return array<string, array{string}> */
    public static function outboxPlanTampering(): array
    {
        return [
            'missing effect' => ['missing'],
            'extra effect' => ['extra'],
            'wrong effect type' => ['type'],
            'wrong effect class' => ['class'],
            'wrong sequence' => ['sequence'],
            'unknown status' => ['status'],
            'wrong payload hash' => ['hash'],
            'self-consistent but noncanonical payload' => ['canonical_payload'],
        ];
    }

    public function testReplayBindsHookActorToTheImmutableLocalRequestEvenIfBothPlansAreRehashed(): void
    {
        self::assertIsArray($this->recorder()->record(self::OPERATION_UUID));
        $hook = $this->outboxRows()[2];
        $hookPayload = json_decode($hook['payload'], true, 512, JSON_THROW_ON_ERROR);
        $hookPayload['actor_user_id'] = 99;
        $hook['payload'] = wp_json_encode($hookPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $hook['payload_hash'] = hash('sha256', $hook['payload']);
        $this->replace('wp_ys_helcim_outbox', $hook);

        $receipt = $this->refundRows()[1];
        $meta = json_decode($receipt['meta'], true, 512, JSON_THROW_ON_ERROR);
        $meta['ys_helcim_effect_plan'][2]['payload'] = $hook['payload'];
        $meta['ys_helcim_effect_plan'][2]['payload_hash'] = $hook['payload_hash'];
        $identity = array_map(static fn (array $effect): array => [
            'effect_type' => $effect['effect_type'],
            'effect_class' => $effect['effect_class'],
            'sequence' => $effect['sequence'],
            'status' => $effect['status'],
            'payload_hash' => $effect['payload_hash'],
        ], $meta['ys_helcim_effect_plan']);
        $meta['ys_helcim_effect_plan_hash'] = hash('sha256', wp_json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $receipt['meta'] = wp_json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->replace('wp_fct_order_transactions', $receipt);
        $before = $this->database->dataSnapshot();

        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_accounting_drift', $result->get_error_code());
        self::assertSame($before, $this->database->dataSnapshot());
    }

    public function testReplayAcceptsKnownOutboxProgressStatesWithoutReapplyingAccounting(): void
    {
        self::assertIsArray($this->recorder()->record(self::OPERATION_UUID));
        foreach ($this->outboxRows() as $effect) {
            if ($effect['status'] !== 'pending') {
                continue;
            }
            $effect['status'] = 'completed';
            $effect['completed_at'] = '2026-07-21 03:05:00';
            $this->replace('wp_ys_helcim_outbox', $effect);
        }
        $before = $this->database->dataSnapshot();

        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertTrue($result['replayed']);
        self::assertSame('recorded', $result['local_status']);
        self::assertSame($before, $this->database->dataSnapshot());
    }

    public function testFailedOperationWithoutAReceiptCanRetryTheLocalTransaction(): void
    {
        $operation = $this->row('wp_ys_helcim_operations', 1);
        $operation['local_status'] = 'failed';
        $operation['local_error_code'] = 'temporary_local_failure';
        $this->replace('wp_ys_helcim_operations', $operation);

        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertFalse($result['replayed']);
        self::assertSame('recorded', $result['local_status']);
        self::assertCount(2, $this->refundRows());
        self::assertCount(3, $this->outboxRows());
    }

    public function testFailedOperationWithADurableReceiptReplaysInsteadOfInsertingAgain(): void
    {
        $first = $this->recorder()->record(self::OPERATION_UUID);
        self::assertIsArray($first);
        $hook = $this->outboxRows()[2];
        $hook['status'] = 'failed';
        $hook['last_error_code'] = 'hook_failed';
        $this->replace('wp_ys_helcim_outbox', $hook);
        $operation = $this->row('wp_ys_helcim_operations', 1);
        $operation['local_status'] = 'failed';
        $operation['local_error_code'] = 'outbox_failed';
        $this->replace('wp_ys_helcim_operations', $operation);
        $before = $this->database->dataSnapshot();

        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertTrue($result['replayed']);
        self::assertSame('failed', $result['local_status']);
        self::assertSame($first['local_transaction_id'], $result['local_transaction_id']);
        self::assertSame($before, $this->database->dataSnapshot());
        self::assertCount(2, $this->refundRows());
    }

    public function testAppliedReplayAcceptsTheCanonicalSkippedStockAndCompletedRemainingEffects(): void
    {
        self::assertIsArray($this->recorder()->record(self::OPERATION_UUID));
        foreach ($this->outboxRows() as $effect) {
            if ($effect['status'] === 'pending') {
                $effect['status'] = 'completed';
                $effect['completed_at'] = '2026-07-21 03:05:00';
                $this->replace('wp_ys_helcim_outbox', $effect);
            }
        }
        $operation = $this->row('wp_ys_helcim_operations', 1);
        $operation['local_status'] = 'applied';
        $operation['local_applied_at'] = '2026-07-21 03:05:00';
        $operation['active_scope_key'] = null;
        $operation['resolved_at'] = '2026-07-21 03:05:00';
        $this->replace('wp_ys_helcim_operations', $operation);

        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertTrue($result['replayed']);
        self::assertSame('applied', $result['local_status']);
    }

    public function testAppliedReplayAcceptsTerminalNonStockWarningsAfterFinalization(): void
    {
        self::assertIsArray($this->recorder()->record(self::OPERATION_UUID));
        foreach ($this->outboxRows() as $effect) {
            if ($effect['effect_type'] === 'customer_recount') {
                $effect['status'] = 'failed';
            } elseif ($effect['effect_type'] === 'refund_hooks') {
                $effect['status'] = 'indeterminate';
            }
            $this->replace('wp_ys_helcim_outbox', $effect);
        }
        $operation = $this->row('wp_ys_helcim_operations', 1);
        $operation['local_status'] = 'applied';
        $operation['local_applied_at'] = '2026-07-21 03:05:00';
        $operation['active_scope_key'] = null;
        $operation['resolved_at'] = '2026-07-21 03:05:00';
        $this->replace('wp_ys_helcim_operations', $operation);
        $before = $this->database->dataSnapshot();

        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertTrue($result['replayed']);
        self::assertSame('applied', $result['local_status']);
        self::assertSame($before, $this->database->dataSnapshot());
    }

    public function testAppliedReplayRejectsAnyNonterminalRequiredEffect(): void
    {
        self::assertIsArray($this->recorder()->record(self::OPERATION_UUID));
        $operation = $this->row('wp_ys_helcim_operations', 1);
        $operation['local_status'] = 'applied';
        $operation['local_applied_at'] = '2026-07-21 03:05:00';
        $operation['active_scope_key'] = null;
        $operation['resolved_at'] = '2026-07-21 03:05:00';
        $this->replace('wp_ys_helcim_operations', $operation);

        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_accounting_drift', $result->get_error_code());
    }

    public function testHistoricalPartialReplayRemainsValidAfterALaterRefundMakesTheOrderFull(): void
    {
        self::assertIsArray($this->recorder()->record(self::OPERATION_UUID));
        foreach ($this->outboxRows() as $effect) {
            if ($effect['status'] === 'pending') {
                $effect['status'] = 'completed';
                $effect['completed_at'] = '2026-07-21 03:05:00';
                $this->replace('wp_ys_helcim_outbox', $effect);
            }
        }
        $operation = $this->row('wp_ys_helcim_operations', 1);
        $operation['local_status'] = 'applied';
        $operation['local_applied_at'] = '2026-07-21 03:05:00';
        $operation['active_scope_key'] = null;
        $operation['resolved_at'] = '2026-07-21 03:05:00';
        $this->replace('wp_ys_helcim_operations', $operation);

        $source = $this->row('wp_fct_order_transactions', 20);
        $sourceMeta = json_decode($source['meta'], true, 512, JSON_THROW_ON_ERROR);
        $sourceMeta['refunded_total'] = 5000;
        $source['meta'] = wp_json_encode($sourceMeta);
        $this->replace('wp_fct_order_transactions', $source);
        $this->database->seed('wp_fct_order_transactions', [
            'id' => 23,
            'order_id' => 10,
            'order_type' => 'payment',
            'transaction_type' => 'refund',
            'subscription_id' => null,
            'card_last_4' => 4242,
            'card_brand' => 'visa',
            'vendor_charge_id' => '51177124',
            'payment_method' => 'ys_helcim',
            'payment_mode' => 'test',
            'payment_method_type' => 'card',
            'status' => 'refunded',
            'currency' => 'USD',
            'total' => 1900,
            'rate' => '1.0000',
            'uuid' => 'later-refund',
            'meta' => wp_json_encode(['parent_id' => 20, 'reason' => 'Later final refund']),
            'created_at' => '2026-07-21 04:00:00',
            'updated_at' => '2026-07-21 04:00:00',
        ]);
        $order = $this->row('wp_fct_orders', 10);
        $order['total_refund'] = 5000;
        $order['payment_status'] = 'refunded';
        $this->replace('wp_fct_orders', $order);
        foreach ([101 => 3000, 102 => 2000] as $itemId => $refundTotal) {
            $item = $this->row('wp_fct_order_items', $itemId);
            $item['refund_total'] = $refundTotal;
            $this->replace('wp_fct_order_items', $item);
        }
        $before = $this->database->dataSnapshot();

        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertTrue($result['replayed']);
        self::assertSame('applied', $result['local_status']);
        $historicalHook = json_decode($this->outboxRows()[2]['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('partial', $historicalHook['refund_type']);
        self::assertSame($before, $this->database->dataSnapshot());
    }

    public function testRejectsAnyOperationWithoutDefinitiveRemoteSuccess(): void
    {
        $operation = $this->row('wp_ys_helcim_operations', 1);
        $operation['remote_status'] = 'indeterminate';
        $this->replace('wp_ys_helcim_operations', $operation);
        $before = $this->database->dataSnapshot();

        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_remote_not_succeeded', $result->get_error_code());
        self::assertSame($before, $this->database->dataSnapshot());
        self::assertCount(1, $this->refundRows());
    }

    #[DataProvider('invalidJournalScopeStates')]
    public function testJournalScopeAndLocalReceiptInvariantsFailClosed(string $field, mixed $value): void
    {
        $operation = $this->row('wp_ys_helcim_operations', 1);
        $operation[$field] = $value;
        $this->replace('wp_ys_helcim_operations', $operation);
        $before = $this->database->dataSnapshot();

        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_accounting_drift', $result->get_error_code());
        self::assertSame($before, $this->database->dataSnapshot());
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidJournalScopeStates(): array
    {
        return [
            'business scope does not match order' => ['scope_key', YSHelcimOperationScope::fromBusinessKey('refund-order:11')],
            'unresolved operation lost active lock' => ['active_scope_key', null],
            'unresolved operation has resolved timestamp' => ['resolved_at', '2026-07-21 02:30:00'],
            'pending operation already points to receipt' => ['local_transaction_id', 99],
            'pending operation already has recorded timestamp' => ['local_recorded_at', '2026-07-21 02:30:00'],
        ];
    }

    #[DataProvider('accountingDriftCases')]
    public function testAccountingDriftFailsClosedBeforeCreatingARefund(string $table, int $id, string $field, mixed $value): void
    {
        $row = $this->row($table, $id);
        if ($field === 'meta.refunded_total') {
            $meta = json_decode($row['meta'], true, 512, JSON_THROW_ON_ERROR);
            $meta['refunded_total'] = $value;
            $row['meta'] = wp_json_encode($meta);
        } else {
            $row[$field] = $value;
        }
        $this->replace($table, $row);
        $before = $this->database->dataSnapshot();

        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_accounting_drift', $result->get_error_code());
        self::assertSame($before, $this->database->dataSnapshot());
    }

    /** @return array<string, array{string, int, string, mixed}> */
    public static function accountingDriftCases(): array
    {
        return [
            'parent metadata disagrees with refund rows' => ['wp_fct_order_transactions', 20, 'meta.refunded_total', 999],
            'parent metadata is not valid JSON' => ['wp_fct_order_transactions', 20, 'meta', '{invalid'],
            'order total disagrees with refund rows' => ['wp_fct_orders', 10, 'total_refund', 999],
            'source UUID differs from immutable journal identity' => ['wp_fct_order_transactions', 20, 'uuid', 'different-source'],
            'source provider transaction differs from remote identity' => ['wp_fct_order_transactions', 20, 'vendor_charge_id', '51177062'],
            'source total differs from remote identity' => ['wp_fct_order_transactions', 20, 'total', 5001],
            'journal source provider identity was tampered' => ['wp_ys_helcim_operations', 1, 'source_vendor_transaction_id', '51177062'],
            'journal request fingerprint was tampered' => ['wp_ys_helcim_operations', 1, 'request_fingerprint', hash('sha256', 'tampered')],
            'order currency differs from immutable journal identity' => ['wp_fct_orders', 10, 'currency', 'CAD'],
        ];
    }

    public function testOverRefundFailsClosedAgainstBothSourceAndOrderBalances(): void
    {
        $operation = $this->row('wp_ys_helcim_operations', 1);
        $operation['amount'] = 4100;
        $this->replace('wp_ys_helcim_operations', $operation);
        $before = $this->database->dataSnapshot();

        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_accounting_drift', $result->get_error_code());
        self::assertSame($before, $this->database->dataSnapshot());
    }

    public function testManageStockOperationStopsAtRecordedForAnOutboxWorker(): void
    {
        $this->enableManageStockPayload();

        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertSame('recorded', $result['local_status']);
        self::assertSame(2700, $this->row('wp_fct_order_items', 101)['refund_total']);
        self::assertSame(400, $this->row('wp_fct_order_items', 102)['refund_total']);
        $stored = $this->row('wp_ys_helcim_operations', 1);
        self::assertSame('recorded', $stored['local_status']);
        self::assertSame('2026-07-21 03:00:00', $stored['local_recorded_at']);
        self::assertNull($stored['local_applied_at']);
        self::assertSame(
            YSHelcimOperationScope::fromBusinessKey('refund-order:10'),
            $stored['active_scope_key']
        );
        self::assertNull($stored['resolved_at']);
        self::assertSame(['applying', 'recorded'], array_slice($this->database->journalStatusTransitions, -2));

        $effects = $this->outboxRows();
        self::assertCount(3, $effects);
        self::assertSame('pending', $effects[0]['status']);
        self::assertNull($effects[0]['completed_at']);
        $stockPayload = json_decode($effects[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($stockPayload['manage_stock']);
        self::assertSame([
            [
                'item_id' => 101,
                'object_id' => 501,
                'post_id' => 301,
                'quantity' => 2,
                'restore_quantity' => 1,
            ],
        ], $stockPayload['items']);
        $hooksPayload = json_decode($effects[2]['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($hooksPayload['manage_stock']);
        self::assertTrue($hooksPayload['stock_restore_requested']);
        $expectedItemSnapshot = [[
            'id' => 101,
            'order_id' => 10,
            'post_id' => 301,
            'object_id' => 501,
            'fulfillment_type' => 'physical',
            'payment_type' => 'onetime',
            'post_title' => 'Product One',
            'title' => 'Variant One',
            'quantity' => 2,
            'unit_price' => 1500,
            'subtotal' => 3000,
            'tax_amount' => 0,
            'shipping_charge' => 0,
            'discount_total' => 0,
            'line_total' => 3000,
            'refund_total' => 2700,
            'rate' => '1.0000',
            'fulfilled_quantity' => 0,
        ]];
        self::assertSame($expectedItemSnapshot, $hooksPayload['refunded_item_snapshots']);
        $receiptMeta = json_decode($this->refundRows()[1]['meta'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expectedItemSnapshot, $receiptMeta['ys_helcim_refunded_item_snapshots']);
    }

    public function testManageStockReplayUsesTheDurableReceiptAfterOrderItemsChange(): void
    {
        $this->enableManageStockPayload();
        self::assertIsArray($this->recorder()->record(self::OPERATION_UUID));
        $stockEffect = $this->outboxRows()[0];
        $stockEffect['status'] = 'completed';
        $stockEffect['completed_at'] = '2026-07-21 03:05:00';
        $this->replace('wp_ys_helcim_outbox', $stockEffect);
        $this->database->remove('wp_fct_order_items', 101);
        $remaining = $this->row('wp_fct_order_items', 102);
        $remaining['quantity'] = 99;
        $this->replace('wp_fct_order_items', $remaining);
        $before = $this->database->dataSnapshot();

        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertTrue($result['replayed']);
        self::assertSame('recorded', $result['local_status']);
        self::assertSame($before, $this->database->dataSnapshot());
    }

    public function testSingleItemRefundCapsItemAccountingAndPreservesTheOrderLevelRemainder(): void
    {
        $payload = YSHelcimRefundPayload::normalize([
            'reason' => 'Item plus shipping refund',
            'item_ids' => [101],
            'actor_user_id' => 42,
        ]);
        $operation = $this->row('wp_ys_helcim_operations', 1);
        $operation['amount'] = 3000;
        $operation['local_payload'] = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $operation['local_payload_hash'] = YSHelcimRefundPayload::hash($payload);
        $operation['request_fingerprint'] = $this->fingerprint($operation, 5000);
        $this->replace('wp_ys_helcim_operations', $operation);

        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertIsArray($result);
        self::assertSame(3000, $this->row('wp_fct_order_items', 101)['refund_total']);
        self::assertSame(400, $this->row('wp_fct_order_items', 102)['refund_total']);
        self::assertSame(4000, $this->row('wp_fct_orders', 10)['total_refund']);
        $meta = json_decode($this->refundRows()[1]['meta'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2400, $meta['ys_helcim_item_allocated_amount']);
        self::assertSame(600, $meta['ys_helcim_unallocated_amount']);
        self::assertSame(3000, $this->refundRows()[1]['total']);
    }

    public function testSelectedFeeItemWithZeroPostAndNullableObjectRecordsAndReplays(): void
    {
        $fee = $this->row('wp_fct_order_items', 101);
        $fee['post_id'] = 0;
        $fee['object_id'] = null;
        $fee['fulfillment_type'] = 'fee';
        $this->replace('wp_fct_order_items', $fee);
        $payload = YSHelcimRefundPayload::normalize([
            'reason' => 'Refund service fee',
            'item_ids' => [101],
            'actor_user_id' => 42,
        ]);
        $operation = $this->row('wp_ys_helcim_operations', 1);
        $operation['local_payload'] = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $operation['local_payload_hash'] = YSHelcimRefundPayload::hash($payload);
        $operation['request_fingerprint'] = $this->fingerprint($operation, 5000);
        $this->replace('wp_ys_helcim_operations', $operation);

        $recorded = $this->recorder()->record(self::OPERATION_UUID);
        $replayed = $this->recorder()->record(self::OPERATION_UUID);

        self::assertIsArray($recorded);
        self::assertIsArray($replayed);
        self::assertTrue($replayed['replayed']);
        $receipt = json_decode($this->refundRows()[1]['meta'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $receipt['ys_helcim_refunded_item_snapshots'][0]['post_id']);
        self::assertNull($receipt['ys_helcim_refunded_item_snapshots'][0]['object_id']);
        self::assertFalse($receipt['manageStock']);
        self::assertSame([], $receipt['refunded_items']);
    }

    public function testReverseChildKeepsRootAndEffectiveOperationIdentityDistinct(): void
    {
        $operation = $this->row('wp_ys_helcim_operations', 1);
        $operation['operation_type'] = 'reverse';
        $operation['parent_operation_uuid'] = '00000000-0000-4000-8000-000000000001';
        $operation['request_fingerprint'] = $this->fingerprint($operation, 5000);
        $this->replace('wp_ys_helcim_operations', $operation);

        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertIsArray($result);
        $meta = json_decode($this->refundRows()[1]['meta'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(self::OPERATION_UUID, $meta['ys_helcim_operation_uuid']);
        self::assertSame('00000000-0000-4000-8000-000000000001', $meta['ys_helcim_root_refund_uuid']);
        self::assertSame('reverse', $meta['ys_helcim_provider_action']);
    }

    public function testTamperedPayloadFailsClosed(): void
    {
        $operation = $this->row('wp_ys_helcim_operations', 1);
        $operation['local_payload'] = str_replace('Customer requested refund', 'Changed later', $operation['local_payload']);
        $this->replace('wp_ys_helcim_operations', $operation);
        $before = $this->database->dataSnapshot();

        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_local_payload_invalid', $result->get_error_code());
        self::assertSame($before, $this->database->dataSnapshot());
    }

    #[DataProvider('rollbackCheckpoints')]
    public function testEveryFaultCheckpointRollsBackAllLocalEffects(string $checkpoint): void
    {
        $before = $this->database->dataSnapshot();
        $recorder = $this->recorder(
            static function (string $current) use ($checkpoint): void {
                if ($current === $checkpoint) {
                    throw new \RuntimeException('Simulated interruption');
                }
            }
        );

        $result = $recorder->record(self::OPERATION_UUID);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_local_storage_unavailable', $result->get_error_code());
        self::assertSame($before, $this->database->dataSnapshot());
        self::assertCount(1, $this->refundRows());
    }

    /** @return array<string, array{string}> */
    public static function rollbackCheckpoints(): array
    {
        return [
            'after refund insert' => ['after_refund_insert'],
            'after local claim' => ['after_local_claim'],
            'after parent update' => ['after_parent_update'],
            'after order update' => ['after_order_update'],
            'after item updates' => ['after_items_update'],
            'after outbox insert' => ['after_outbox_insert'],
            'after journal update' => ['after_journal_update'],
            'before commit' => ['before_commit'],
        ];
    }

    public function testCommitFailureRollsBackTheRecorderTransaction(): void
    {
        $before = $this->database->dataSnapshot();
        $this->database->failNextCommit = true;

        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_local_storage_unavailable', $result->get_error_code());
        self::assertSame($before, $this->database->dataSnapshot());
    }

    #[DataProvider('failedLockReads')]
    public function testAnyFailedLockReadIsAStorageFailureAndNeverAnEmptyResult(string $read): void
    {
        $before = $this->database->dataSnapshot();
        $this->database->failNextSelectFor = $read;

        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_local_storage_unavailable', $result->get_error_code());
        self::assertSame($before, $this->database->dataSnapshot());
    }

    /** @return array<string, array{string}> */
    public static function failedLockReads(): array
    {
        return [
            'operation row' => ['operation'],
            'source transaction row' => ['source_transaction'],
            'order row' => ['order'],
            'refund rows' => ['refund_rows'],
            'order items' => ['items'],
            'outbox rows' => ['outbox'],
        ];
    }

    public function testAnyOutboxUniqueConflictRollsBackTheRefundAndAccounting(): void
    {
        $this->database->seed('wp_ys_helcim_outbox', [
            'id' => 1,
            'operation_uuid' => self::OPERATION_UUID,
            'effect_type' => 'refund_hooks',
            'payload' => '{}',
            'payload_hash' => hash('sha256', '{}'),
            'status' => 'pending',
        ]);
        $before = $this->database->dataSnapshot();

        $result = $this->recorder()->record(self::OPERATION_UUID);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ys_helcim_local_storage_unavailable', $result->get_error_code());
        self::assertSame($before, $this->database->dataSnapshot());
        self::assertCount(1, $this->refundRows());
    }

    private function recorder(?callable $checkpoint = null): YSHelcimLocalRefundRecorder
    {
        return new YSHelcimLocalRefundRecorder(
            $this->database,
            static fn (): string => '2026-07-21 03:00:00',
            $checkpoint
        );
    }

    private function enableManageStockPayload(): void
    {
        $payload = YSHelcimRefundPayload::normalize([
            'reason' => 'Return item one',
            'item_ids' => [101],
            'manage_stock' => true,
            'refunded_items' => [['id' => 101, 'restore_quantity' => 1]],
            'actor_user_id' => 42,
        ]);
        $operation = $this->row('wp_ys_helcim_operations', 1);
        $operation['local_payload'] = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $operation['local_payload_hash'] = YSHelcimRefundPayload::hash($payload);
        $operation['request_fingerprint'] = $this->fingerprint($operation, 5000);
        $this->replace('wp_ys_helcim_operations', $operation);
    }

    private function reorderJsonObjectKeysRecursively(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->reorderJsonObjectKeysRecursively($child);
        }
        if (!array_is_list($value)) {
            krsort($value, SORT_STRING);
        }

        return $value;
    }

    private function seedCanonicalState(): void
    {
        $payload = YSHelcimRefundPayload::normalize([
            'reason' => 'Customer requested refund',
            'actor_user_id' => 42,
        ]);
        $payloadJson = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $operation = [
            'id' => 1,
            'operation_uuid' => self::OPERATION_UUID,
            'operation_type' => 'refund',
            'gateway' => 'ys_helcim',
            'order_id' => 10,
            'transaction_id' => 20,
            'transaction_uuid' => 'fc-transaction-123',
            'parent_operation_uuid' => null,
            'amount' => 2100,
            'currency' => 'USD',
            'payment_mode' => 'test',
            'remote_status' => 'succeeded',
            'local_status' => 'pending',
            'vendor_transaction_id' => '51177123',
            'source_vendor_transaction_id' => '51177061',
            'scope_key' => YSHelcimOperationScope::fromBusinessKey('refund-order:10'),
            'local_payload' => $payloadJson,
            'local_payload_hash' => hash('sha256', $payloadJson),
            'local_transaction_id' => null,
            'local_recorded_at' => null,
            'local_applied_at' => null,
            'active_scope_key' => YSHelcimOperationScope::fromBusinessKey('refund-order:10'),
            'updated_at' => '2026-07-21 02:00:00',
            'resolved_at' => null,
        ];
        $operation['request_fingerprint'] = $this->fingerprint($operation, 5000);
        $this->database->seed('wp_ys_helcim_operations', $operation);
        $this->database->seed('wp_fct_order_transactions', [
            'id' => 20,
            'order_id' => 10,
            'order_type' => 'payment',
            'transaction_type' => 'charge',
            'subscription_id' => null,
            'card_last_4' => 4242,
            'card_brand' => 'visa',
            'vendor_charge_id' => '51177061',
            'payment_method' => 'ys_helcim',
            'payment_mode' => 'test',
            'payment_method_type' => 'card',
            'status' => 'succeeded',
            'currency' => 'USD',
            'total' => 5000,
            'rate' => '1.0000',
            'uuid' => 'fc-transaction-123',
            'meta' => wp_json_encode(['refunded_total' => 1000, 'note' => 'keep-me']),
            'created_at' => '2026-07-21 01:00:00',
            'updated_at' => '2026-07-21 01:00:00',
        ]);
        $this->database->seed('wp_fct_order_transactions', [
            'id' => 21,
            'order_id' => 10,
            'order_type' => 'payment',
            'transaction_type' => 'refund',
            'subscription_id' => null,
            'card_last_4' => 4242,
            'card_brand' => 'visa',
            'vendor_charge_id' => '51177099',
            'payment_method' => 'ys_helcim',
            'payment_mode' => 'test',
            'payment_method_type' => 'card',
            'status' => 'refunded',
            'currency' => 'USD',
            'total' => 1000,
            'rate' => '1.0000',
            'uuid' => 'older-refund',
            'meta' => wp_json_encode(['parent_id' => 20, 'reason' => 'Earlier refund']),
            'created_at' => '2026-07-21 01:30:00',
            'updated_at' => '2026-07-21 01:30:00',
        ]);
        $this->database->seed('wp_fct_orders', [
            'id' => 10,
            'type' => 'payment',
            'customer_id' => 77,
            'uuid' => 'fc-order-10',
            'currency' => 'USD',
            'total_amount' => 5000,
            'total_paid' => 5000,
            'total_refund' => 1000,
            'payment_status' => 'partially_refunded',
            'refunded_at' => '2026-07-21 01:30:00',
            'updated_at' => '2026-07-21 01:30:00',
        ]);
        $this->database->seed('wp_fct_order_items', [
            'id' => 101,
            'order_id' => 10,
            'post_id' => 301,
            'object_id' => 501,
            'quantity' => 2,
            'fulfillment_type' => 'physical',
            'payment_type' => 'onetime',
            'post_title' => 'Product One',
            'title' => 'Variant One',
            'unit_price' => 1500,
            'subtotal' => 3000,
            'tax_amount' => 0,
            'shipping_charge' => 0,
            'discount_total' => 0,
            'rate' => '1.0000',
            'fulfilled_quantity' => 0,
            'line_total' => 3000,
            'refund_total' => 600,
            'updated_at' => '2026-07-21 01:30:00',
        ]);
        $this->database->seed('wp_fct_order_items', [
            'id' => 102,
            'order_id' => 10,
            'post_id' => 302,
            'object_id' => 502,
            'quantity' => 1,
            'fulfillment_type' => 'physical',
            'payment_type' => 'onetime',
            'post_title' => 'Product Two',
            'title' => 'Variant Two',
            'unit_price' => 2000,
            'subtotal' => 2000,
            'tax_amount' => 0,
            'shipping_charge' => 0,
            'discount_total' => 0,
            'rate' => '1.0000',
            'fulfilled_quantity' => 0,
            'line_total' => 2000,
            'refund_total' => 400,
            'updated_at' => '2026-07-21 01:30:00',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function refundRows(): array
    {
        return array_values(array_filter(
            $this->database->rows('wp_fct_order_transactions'),
            static fn (array $row): bool => ($row['transaction_type'] ?? null) === 'refund'
        ));
    }

    /** @return array<int, array<string, mixed>> */
    private function outboxRows(): array
    {
        $rows = $this->database->rows('wp_ys_helcim_outbox');
        usort($rows, static fn (array $left, array $right): int => (int) $left['sequence'] <=> (int) $right['sequence']);

        return $rows;
    }

    /** @return array<string, mixed> */
    private function row(string $table, int $id): array
    {
        foreach ($this->database->rows($table) as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        self::fail("Missing row {$table}:{$id}");
    }

    /** @param array<string, mixed> $row */
    private function replace(string $table, array $row): void
    {
        $updated = $this->database->update($table, $row, ['id' => $row['id']]);
        self::assertSame(1, $updated);
    }

    /** @param array<string, mixed> $operation */
    private function fingerprint(array $operation, int $transactionTotal): string
    {
        $material = wp_json_encode([
            'version' => 2,
            'operation_type' => $operation['operation_type'],
            'parent_operation_uuid' => $operation['parent_operation_uuid'],
            'gateway' => $operation['gateway'],
            'order_id' => $operation['order_id'],
            'transaction_id' => $operation['transaction_id'],
            'transaction_uuid' => $operation['transaction_uuid'],
            'source_vendor_transaction_id' => $operation['source_vendor_transaction_id'],
            'amount' => $operation['amount'],
            'transaction_total' => $transactionTotal,
            'currency' => $operation['currency'],
            'payment_mode' => $operation['payment_mode'],
            'local_payload_hash' => $operation['local_payload_hash'],
        ], JSON_UNESCAPED_SLASHES);
        self::assertIsString($material);

        return hash('sha256', $material);
    }
}
