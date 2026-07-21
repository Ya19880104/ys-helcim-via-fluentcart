<?php

declare(strict_types=1);

if (!class_exists('WP_Error')) {
    final class WP_Error
    {
        public function __construct(
            private readonly string $code = '',
            private readonly string $message = '',
            private readonly mixed $data = null
        ) {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data(): mixed
        {
            return $this->data;
        }
    }
}

if (!class_exists('YSHelcimFluentCartApiDouble')) {
    final class YSHelcimFluentCartApiDouble
    {
        /** @var array<string,object> */
        public static array $registered = [];

        /** @var string[] */
        public static array $registrationAttempts = [];

        /** @var string[] */
        public static array $failRegistrationSlugs = [];

        public function registerCustomPaymentMethod(string $slug, object $gateway): void
        {
            self::$registrationAttempts[] = $slug;
            if (in_array($slug, self::$failRegistrationSlugs, true)) {
                throw new RuntimeException('Simulated gateway registration failure: ' . $slug);
            }

            self::$registered[$slug] = $gateway;
        }
    }
}

if (!function_exists('fluent_cart_api')) {
    function fluent_cart_api(): YSHelcimFluentCartApiDouble
    {
        static $api;
        return $api ??= new YSHelcimFluentCartApiDouble();
    }
}

final class YSHelcimWpDouble
{
    /** @var array<int, array{url: string, args: array}> */
    public static array $requests = [];

    /** @var array{response: array{code: int}, body: string}|WP_Error */
    public static array|WP_Error $response = [
        'response' => ['code' => 200],
        'body' => '{}',
    ];

    /** @var array<string, mixed> */
    public static array $options = [];

    /** @var array<string, mixed> */
    public static array $fluentCartOptions = [];

    /** @var array<int, array{key:string,value:mixed}> */
    public static array $fluentCartOptionWrites = [];

    /** @var string[] */
    public static array $dbDeltaSql = [];

    /** @var array<int, array{hook: string, callback: mixed, priority: int, accepted_args: int}> */
    public static array $actions = [];

    /** @var array<int, array{hook: string, callback: mixed, priority: int, accepted_args: int}> */
    public static array $filters = [];

    /** @var array<int, array{parent_slug:?string,page_title:string,menu_title:string,capability:string,menu_slug:string,callback:mixed}> */
    public static array $submenuPages = [];

    public static bool $failSubmenuPageRegistration = false;

    /** @var array<int, array{domain: string, path: string}> */
    public static array $loadedTextdomains = [];

    /** @var array<int,array{timestamp:int,hook:string,args:array,recurrence:?string}> */
    public static array $scheduledEvents = [];
    /** @var array<int,array{file:string,callback:mixed}> */
    public static array $activationHooks = [];
    /** @var array<int,array{file:string,callback:mixed}> */
    public static array $deactivationHooks = [];
    public static bool $failRecurringSchedule = false;
    public static bool $failUnschedule = false;
    public static string $restUrlBase = 'https://shop.test/wp-json/';

    /** @var array<string,bool> */
    public static array $currentUserCapabilities = ['manage_options' => true];

    public static function reset(): void
    {
        self::$requests = [];
        self::$response = [
            'response' => ['code' => 200],
            'body' => '{}',
        ];
        self::$options = [];
        self::$fluentCartOptions = [];
        self::$fluentCartOptionWrites = [];
        self::$dbDeltaSql = [];
        self::$actions = [];
        self::$filters = [];
        self::$submenuPages = [];
        self::$failSubmenuPageRegistration = false;
        self::$loadedTextdomains = [];
        self::$scheduledEvents = [];
        self::$activationHooks = [];
        self::$deactivationHooks = [];
        self::$failRecurringSchedule = false;
        self::$failUnschedule = false;
        self::$restUrlBase = 'https://shop.test/wp-json/';
        self::$currentUserCapabilities = ['manage_options' => true];
        if (class_exists('YSHelcimFluentCartApiDouble')) {
            YSHelcimFluentCartApiDouble::$registered = [];
            YSHelcimFluentCartApiDouble::$registrationAttempts = [];
            YSHelcimFluentCartApiDouble::$failRegistrationSlugs = [];
        }
        if (class_exists(\FluentCart\App\Helpers\Helper::class)) {
            \FluentCart\App\Helpers\Helper::$encryptionAvailable = true;
            \FluentCart\App\Helpers\Helper::$encryptBehavior = 'normal';
            \FluentCart\App\Helpers\Helper::$verificationBehavior = 'normal';
            \FluentCart\App\Helpers\Helper::$decryptBehavior = 'normal';
        }
    }
}

if (!function_exists('_get_cron_array')) {
    function _get_cron_array(): array
    {
        $crons = [];
        foreach (YSHelcimWpDouble::$scheduledEvents as $event) {
            $crons[$event['timestamp']][$event['hook']][md5(serialize($event['args']))] = [
                'schedule' => $event['recurrence'] ?? false,
                'args' => $event['args'],
            ];
        }
        ksort($crons, SORT_NUMERIC);
        return $crons;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return YSHelcimWpDouble::$currentUserCapabilities[$capability] ?? false;
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        unset($domain);
        return esc_html($text);
    }
}

if (!function_exists('wp_get_scheduled_event')) {
    function wp_get_scheduled_event(string $hook, array $args = [], ?int $timestamp = null): object|false
    {
        foreach (YSHelcimWpDouble::$scheduledEvents as $event) {
            if (
                $event['hook'] === $hook
                && $event['args'] === $args
                && ($timestamp === null || $event['timestamp'] === $timestamp)
            ) {
                return (object) [
                    'hook' => $event['hook'],
                    'timestamp' => $event['timestamp'],
                    'schedule' => $event['recurrence'],
                    'args' => $event['args'],
                ];
            }
        }
        return false;
    }
}

if (!function_exists('wp_unschedule_event')) {
    function wp_unschedule_event(int $timestamp, string $hook, array $args = [], bool $wpError = false): bool|WP_Error
    {
        if (YSHelcimWpDouble::$failUnschedule) {
            return $wpError ? new WP_Error('unschedule_failed', 'Simulated unschedule failure.') : false;
        }
        foreach (YSHelcimWpDouble::$scheduledEvents as $index => $event) {
            if ($event['timestamp'] === $timestamp && $event['hook'] === $hook && $event['args'] === $args) {
                array_splice(YSHelcimWpDouble::$scheduledEvents, $index, 1);
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = []): int|false
    {
        foreach (YSHelcimWpDouble::$scheduledEvents as $event) {
            if ($event['hook'] === $hook && $event['args'] === $args) {
                return $event['timestamp'];
            }
        }
        return false;
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event(int $timestamp, string $hook, array $args = [], bool $wpError = false): bool|WP_Error
    {
        unset($wpError);
        YSHelcimWpDouble::$scheduledEvents[] = [
            'timestamp' => $timestamp,
            'hook' => $hook,
            'args' => $args,
            'recurrence' => null,
        ];
        return true;
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = [], bool $wpError = false): bool|WP_Error
    {
        if (YSHelcimWpDouble::$failRecurringSchedule) {
            return $wpError ? new WP_Error('schedule_failed', 'Simulated recurring schedule failure.') : false;
        }
        YSHelcimWpDouble::$scheduledEvents[] = compact('timestamp', 'hook', 'args', 'recurrence');
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        YSHelcimWpDouble::$filters[] = [
            'hook' => $hook,
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $acceptedArgs,
        ];
        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        YSHelcimWpDouble::$actions[] = [
            'hook' => $hook,
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $acceptedArgs,
        ];
        return true;
    }
}

if (!function_exists('add_submenu_page')) {
    function add_submenu_page(
        ?string $parentSlug,
        string $pageTitle,
        string $menuTitle,
        string $capability,
        string $menuSlug,
        mixed $callback = ''
    ): string|false {
        YSHelcimWpDouble::$submenuPages[] = [
            'parent_slug' => $parentSlug,
            'page_title' => $pageTitle,
            'menu_title' => $menuTitle,
            'capability' => $capability,
            'menu_slug' => $menuSlug,
            'callback' => $callback,
        ];
		if (YSHelcimWpDouble::$failSubmenuPageRegistration) {
			return false;
		}

        return ($parentSlug === null ? 'admin' : $parentSlug) . '_page_' . $menuSlug;
    }
}

if (!function_exists('did_action')) {
    function did_action(string $hook): int
    {
        unset($hook);
        return 0;
    }
}

if (!function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain(string $domain, bool $deprecated = false, string $path = ''): bool
    {
        unset($deprecated);
        YSHelcimWpDouble::$loadedTextdomains[] = compact('domain', 'path');
        return true;
    }
}

if (!function_exists('plugin_basename')) {
    function plugin_basename(string $file): string
    {
        return basename($file);
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string
    {
        return dirname($file) . DIRECTORY_SEPARATOR;
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url(string $file): string
    {
        unset($file);
        return 'https://shop.test/wp-content/plugins/ys-helcim-via-fluentcart/';
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook(string $file, mixed $callback): void
    {
        YSHelcimWpDouble::$activationHooks[] = compact('file', 'callback');
    }
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook(string $file, mixed $callback): void
    {
        YSHelcimWpDouble::$deactivationHooks[] = compact('file', 'callback');
    }
}

if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string
    {
        return 'unit-test-wordpress-salt-' . $scheme;
    }
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        unset($domain);
        return $text;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0): string|false
    {
        return json_encode($value, $flags);
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(array $args, string $url): string
    {
        if ($args === []) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
    }
}

if (!function_exists('wp_remote_request')) {
    function wp_remote_request(string $url, array $args): array|WP_Error
    {
        YSHelcimWpDouble::$requests[] = ['url' => $url, 'args' => $args];
        return YSHelcimWpDouble::$response;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(array $response): int
    {
        return (int) ($response['response']['code'] ?? 0);
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body(array $response): string
    {
        return (string) ($response['body'] ?? '');
    }
}

if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return YSHelcimWpDouble::$options[$name] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $name, mixed $value, bool|string $autoload = true): bool
    {
        unset($autoload);
        YSHelcimWpDouble::$options[$name] = $value;
        return true;
    }
}

if (!function_exists('add_option')) {
    function add_option(string $name, mixed $value = '', string $deprecated = '', bool|string $autoload = true): bool
    {
        unset($deprecated, $autoload);
        if (array_key_exists($name, YSHelcimWpDouble::$options)) {
            return false;
        }
        YSHelcimWpDouble::$options[$name] = $value;
        return true;
    }
}

if (!function_exists('fluent_cart_get_option')) {
    function fluent_cart_get_option(string $key, mixed $default = false, bool $cache = true): mixed
    {
        unset($cache);
        return YSHelcimWpDouble::$fluentCartOptions[$key] ?? $default;
    }
}

if (!function_exists('fluent_cart_update_option')) {
    function fluent_cart_update_option(string $key, mixed $value): object
    {
        YSHelcimWpDouble::$fluentCartOptions[$key] = $value;
        YSHelcimWpDouble::$fluentCartOptionWrites[] = compact('key', 'value');
        return (object) ['meta_key' => $key, 'meta_value' => $value];
    }
}

if (!function_exists('dbDelta')) {
    function dbDelta(string $sql): array
    {
        YSHelcimWpDouble::$dbDeltaSql[] = $sql;
        global $wpdb;
        if (isset($wpdb) && $wpdb instanceof \YangSheep\Helcim\FluentCart\Tests\Doubles\FakeWpdb && $wpdb->failNextSchemaInstall) {
            $wpdb->last_error = 'Simulated schema failure';
            $wpdb->failNextSchemaInstall = false;
        } elseif (isset($wpdb) && $wpdb instanceof \YangSheep\Helcim\FluentCart\Tests\Doubles\FakeWpdb) {
            if (str_contains($sql, 'ys_helcim_webhook_receipts')) {
                $wpdb->webhookReceiptSchemaInstalled = true;
                $wpdb->webhookReceiptSchemaIndexes = [
                    'PRIMARY',
                    'receipt_key',
                    'expires_at',
                ];
            } elseif (str_contains($sql, 'ys_helcim_resolution_challenges')) {
                $wpdb->resolutionChallengeSchemaInstalled = true;
                $wpdb->resolutionChallengeSchemaIndexes = [
                    'PRIMARY',
                    'challenge_hash',
                    'operation_uuid',
                    'candidate_transaction_id',
                    'expires_at',
                ];
            } elseif (str_contains($sql, 'ys_helcim_refund_resolutions')) {
                $wpdb->resolutionAuditSchemaInstalled = true;
                $wpdb->resolutionAuditSchemaIndexes = [
                    'PRIMARY',
                    'operation_uuid',
                    'challenge_hash',
                    'candidate_transaction_id',
                    'source_transaction_id',
                ];
            } elseif (str_contains($sql, 'ys_helcim_outbox')) {
                $wpdb->outboxSchemaInstalled = true;
                $wpdb->outboxSchemaIndexes = [
                    'PRIMARY',
                    'operation_effect',
                    'claim_token',
                    'ready_effects',
                    'operation_uuid',
                ];
            } else {
                $wpdb->schemaInstalled = true;
				$wpdb->schemaColumns = str_contains($sql, 'local_claimed_at')
					? ['local_claimed_at', 'recovery_attempt_count', 'next_recovery_at']
					: [];
                $wpdb->schemaIndexes = [
                    'PRIMARY',
                    'operation_uuid',
                    'idempotency_key',
                    'active_scope_key',
                    'provider_correlation_id',
                    'vendor_transaction_id',
                    'parent_operation_type',
                    'local_transaction_id',
                ];
            }
        }
        return [];
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $value): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)) ?? '';
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        return $value;
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $value): string
    {
        return rtrim($value, '/\\') . '/';
    }
}

if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string
    {
        return YSHelcimWpDouble::$restUrlBase . ltrim($path, '/');
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): array|string|int|null|false
    {
        return parse_url($url, $component);
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://shop.test/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action): string
    {
        return 'nonce-' . $action;
    }
}
