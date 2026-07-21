<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Doubles;

final class RefundResolutionRestResponse
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data, private int $status)
    {
    }

    /** @return array<string,mixed> */
    public function get_data(): array
    {
        return $this->data;
    }

    public function get_status(): int
    {
        return $this->status;
    }
}
