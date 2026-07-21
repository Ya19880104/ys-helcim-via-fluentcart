<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Doubles;

final class RefundRestResponse
{
    /** @param array<string, mixed> $data */
    public function __construct(
        private readonly array $data,
        private readonly int $status = 200
    ) {
    }

    /** @return array<string, mixed> */
    public function get_data(): array
    {
        return $this->data;
    }

    public function get_status(): int
    {
        return $this->status;
    }
}
