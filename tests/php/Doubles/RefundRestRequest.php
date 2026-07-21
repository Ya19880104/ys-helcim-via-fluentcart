<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Doubles;

final class RefundRestRequest
{
    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>      $route
     * @param array<string, string>     $headers
     */
    public function __construct(
        private readonly ?array $body = [],
        private readonly array $route = [],
        private readonly array $headers = []
    ) {
    }

    /** @return array<string, mixed>|null */
    public function get_json_params(): ?array
    {
        return $this->body;
    }

    /** @return array<string, mixed> */
    public function get_url_params(): array
    {
        return $this->route;
    }

    public function get_header(string $name): string
    {
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === strtolower($name)) {
                return $value;
            }
        }

        return '';
    }
}
