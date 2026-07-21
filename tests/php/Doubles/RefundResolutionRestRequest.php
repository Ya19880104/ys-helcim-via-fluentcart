<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Doubles;

final class RefundResolutionRestRequest
{
    /** @param array<string,mixed> $body @param array<string,mixed> $route @param array<string,mixed> $headers */
    public function __construct(
        private array $body = [],
        private array $route = [],
        private array $headers = ['X-WP-Nonce' => 'valid-nonce']
    ) {
    }

    /** @return array<string,mixed> */
    public function get_json_params(): array
    {
        return $this->body;
    }

    /** @return array<string,mixed> */
    public function get_url_params(): array
    {
        return $this->route;
    }

    public function get_header(string $name): mixed
    {
        foreach ($this->headers as $header => $value) {
            if (strcasecmp($header, $name) === 0) {
                return $value;
            }
        }

        return '';
    }
}
