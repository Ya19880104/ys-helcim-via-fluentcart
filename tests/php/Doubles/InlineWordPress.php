<?php

declare(strict_types=1);

if (!class_exists('YSHelcimWpJsonExit')) {
    final class YSHelcimWpJsonExit extends RuntimeException
    {
        /** @param array<string, mixed> $payload */
        public function __construct(public array $payload, public int $statusCode)
        {
            parent::__construct('wp_send_json');
        }
    }
}

if (!function_exists('wp_send_json')) {
    function wp_send_json(mixed $response, ?int $statusCode = null): never
    {
        throw new YSHelcimWpJsonExit(is_array($response) ? $response : ['response' => $response], $statusCode ?? 200);
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string|int $action = -1): int|false
    {
        return $nonce === 'nonce-' . $action ? 1 : false;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        foreach (YSHelcimWpDouble::$filters as $registered) {
            if ($registered['hook'] === $hook) {
                $value = ($registered['callback'])($value, ...array_slice($args, 0, $registered['accepted_args'] - 1));
            }
        }
        return $value;
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string
    {
        return '00000000-0000-4000-8000-000000000777';
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        unset($domain);
        return esc_html($text);
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $value): string
    {
        return $value;
    }
}
