<?php

declare(strict_types=1);

namespace FluentCart\App\Services\Payments;

if (!class_exists(PaymentInstance::class)) {
    final class PaymentInstance
    {
        public function __construct(
            public mixed $order,
            public mixed $transaction,
            public mixed $subscription = null
        ) {
        }
    }
}
