<?php

declare(strict_types=1);

namespace FluentCart\App\Helpers;

if (!class_exists(Status::class)) {
    final class Status
    {
        public const TRANSACTION_TYPE_CHARGE = 'charge';
        public const TRANSACTION_SUCCEEDED = 'succeeded';
        public const TRANSACTION_PENDING = 'pending';
    }
}

if (!class_exists(StatusHelper::class)) {
    final class StatusHelper
    {
        /** @var array<int, array{order: object, transaction: object}> */
        public static array $syncs = [];

        public function __construct(private object $order)
        {
        }

        public function syncOrderStatuses(object $transaction): bool
        {
            self::$syncs[] = ['order' => $this->order, 'transaction' => $transaction];
            if (method_exists($this->order, 'markPaid')) {
                $this->order->markPaid();
            }
            return true;
        }

        public static function reset(): void
        {
            self::$syncs = [];
        }
    }
}

namespace FluentCart\App\Models;

final class InlineModelQuery
{
    /** @var array<string, mixed> */
    private array $conditions = [];

    private ?string $orderBy = null;

    private string $direction = 'asc';

    public function __construct(private string $modelClass)
    {
    }

    public function where(string $field, mixed $value): self
    {
        $this->conditions[$field] = $value;
        return $this;
    }

    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $this->orderBy = $field;
        $this->direction = strtolower($direction);
        return $this;
    }

    public function first(): ?object
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = ($this->modelClass)::allRecords();
        $rows = array_values(array_filter($rows, function (array $row): bool {
            foreach ($this->conditions as $field => $value) {
                if (($row[$field] ?? null) != $value) {
                    return false;
                }
            }
            return true;
        }));

        if ($this->orderBy !== null) {
            $field = $this->orderBy;
            usort($rows, static fn (array $left, array $right): int => ($left[$field] ?? null) <=> ($right[$field] ?? null));
            if ($this->direction === 'desc') {
                $rows = array_reverse($rows);
            }
        }

        return isset($rows[0]) ? ($this->modelClass)::fromRecord($rows[0]) : null;
    }
}

if (!class_exists(OrderTransaction::class)) {
    class OrderTransaction
    {
        /** @var array<int, array<string, mixed>> */
        private static array $records = [];

        public static bool $saveResult = true;

        /** Simulates an ORM reporting success before the write is observable on readback. */
        public static bool $savePersists = true;

        public int $id = 0;
        public string $uuid = '';
        public int $order_id = 0;
        public string $payment_method = '';
        public string $transaction_type = '';
        public int $total = 0;
        public string $currency = '';
        public string $payment_mode = '';
        public string $status = '';
        public mixed $vendor_charge_id = null;
        public string $payment_method_type = '';
        public string $card_last_4 = '';
        public string $card_brand = '';
        /** @var array<string, mixed> */
        public array $meta = [];
        public mixed $order = null;

        /** @param array<string, mixed> $record */
        public static function seed(array $record): void
        {
            self::$records[(int) $record['id']] = $record;
        }

        public static function reset(): void
        {
            self::$records = [];
            self::$saveResult = true;
            self::$savePersists = true;
        }

        /** @return array<int, array<string, mixed>> */
        public static function allRecords(): array
        {
            return self::$records;
        }

        /** @param array<string, mixed> $record */
        public static function fromRecord(array $record): self
        {
            $model = new self();
            $model->fill($record);
            return $model;
        }

        public static function query(): InlineModelQuery
        {
            return new InlineModelQuery(self::class);
        }

        /** @param array<string, mixed> $data */
        public function fill(array $data): self
        {
            foreach ($data as $field => $value) {
                if (property_exists($this, $field)) {
                    $this->{$field} = $value;
                }
            }
            return $this;
        }

        public function save(): bool
        {
            if (!self::$saveResult || $this->id <= 0) {
                return false;
            }

            if (!self::$savePersists) {
                return true;
            }

            self::$records[$this->id] = [
                'id' => $this->id,
                'uuid' => $this->uuid,
                'order_id' => $this->order_id,
                'payment_method' => $this->payment_method,
                'transaction_type' => $this->transaction_type,
                'total' => $this->total,
                'currency' => $this->currency,
                'payment_mode' => $this->payment_mode,
                'status' => $this->status,
                'vendor_charge_id' => $this->vendor_charge_id,
                'payment_method_type' => $this->payment_method_type,
                'card_last_4' => $this->card_last_4,
                'card_brand' => $this->card_brand,
                'meta' => $this->meta,
            ];
            return true;
        }

        public function getReceiptPageUrl(bool $absolute = false): string
        {
            unset($absolute);
            return 'https://shop.test/receipt/' . rawurlencode($this->uuid);
        }
    }
}

if (!class_exists(Order::class)) {
    class Order
    {
        /** @var array<int, array<string, mixed>> */
        private static array $records = [];

        public int $id = 0;
        public string $uuid = '';
        public string $status = 'pending';
        public string $payment_status = 'pending';
        public int $total_amount = 0;
        public int $total_paid = 0;
        public mixed $billing_address = null;
        public mixed $shipping_address = null;
        public mixed $customer = null;

        /** @param array<string, mixed> $record */
        public static function seed(array $record): void
        {
            self::$records[(int) $record['id']] = $record;
        }

        public static function reset(): void
        {
            self::$records = [];
        }

        /** @return array<int, array<string, mixed>> */
        public static function allRecords(): array
        {
            return self::$records;
        }

        /** @param array<string, mixed> $record */
        public static function fromRecord(array $record): self
        {
            $model = new self();
            foreach ($record as $field => $value) {
                if (property_exists($model, $field)) {
                    $model->{$field} = $value;
                }
            }
            return $model;
        }

        public static function query(): InlineModelQuery
        {
            return new InlineModelQuery(self::class);
        }

        public function markPaid(): void
        {
            $this->payment_status = 'paid';
            $this->total_paid = $this->total_amount;
            $this->status = 'processing';
            self::$records[$this->id] = [
                'id' => $this->id,
                'uuid' => $this->uuid,
                'status' => $this->status,
                'payment_status' => $this->payment_status,
                'total_amount' => $this->total_amount,
                'total_paid' => $this->total_paid,
                'billing_address' => $this->billing_address,
                'shipping_address' => $this->shipping_address,
                'customer' => $this->customer,
            ];
        }
    }
}
