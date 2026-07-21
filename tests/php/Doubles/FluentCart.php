<?php

declare(strict_types=1);

namespace FluentCart\App\Modules\PaymentMethods\Core;

if (!class_exists(BaseGatewaySettings::class)) {
    class BaseGatewaySettings
    {
        /** @var array<class-string,array<string,mixed>> */
        public static array $settingsByClass = [];

        public function __construct()
        {
            if (method_exists(static::class, 'getDefaults')) {
                $this->settings = array_replace(
                    static::getDefaults(),
                    self::$settingsByClass[static::class] ?? []
                );
            }
        }
    }
}

namespace FluentCart\Api;

if (!class_exists(StoreSettings::class)) {
    class StoreSettings
    {
        public static string $orderMode = 'test';

        public function get(string $key): string
        {
            unset($key);
            return self::$orderMode;
        }
    }
}

namespace FluentCart\App\Helpers;

if (!class_exists(Helper::class)) {
    class Helper
    {
        public static bool $encryptionAvailable = true;

        /** @var 'normal'|'plaintext'|'false'|'throw' */
        public static string $encryptBehavior = 'normal';

        /** @var 'normal'|'false'|'throw' */
        public static string $verificationBehavior = 'normal';

        /** @var 'normal'|'false'|'throw' */
        public static string $decryptBehavior = 'normal';

        public static function isValueEncrypted(mixed $value): bool
        {
            if (self::$verificationBehavior === 'throw') {
                throw new \RuntimeException('Simulated ciphertext verification failure.');
            }

            if (self::$verificationBehavior === 'false') {
                return false;
            }

            return self::$encryptionAvailable
                && is_string($value)
                && str_starts_with($value, 'enc:')
                && strlen($value) > 4;
        }

        public static function decryptKey(string $value): string|false
        {
            if (self::$decryptBehavior === 'throw') {
                throw new \RuntimeException('Simulated decryption failure.');
            }

            if (self::$decryptBehavior === 'false') {
                return false;
            }

            return self::isValueEncrypted($value) ? substr($value, 4) : $value;
        }

        public static function encryptKey(string $value): string|false
        {
            if (self::$encryptBehavior === 'throw') {
                throw new \RuntimeException('Simulated encryption failure.');
            }

            if (self::$encryptBehavior === 'false') {
                return false;
            }

            if (self::$encryptBehavior === 'plaintext') {
                return $value;
            }

            if (!self::$encryptionAvailable) {
                return $value;
            }
            return self::isValueEncrypted($value) ? $value : 'enc:' . $value;
        }
    }
}

namespace FluentCart\App\Services\Permission;

if (!class_exists(PermissionManager::class)) {
    final class PermissionManager
    {
        public static bool $allow = true;

        public static function hasPermission(string|array $permission): bool
        {
            unset($permission);
            return self::$allow;
        }
    }
}

namespace FluentCart\App\Modules\PaymentMethods\Core;

if (!class_exists(AbstractPaymentGateway::class)) {
    abstract class AbstractPaymentGateway
    {
        protected mixed $settings;

        public function __construct(mixed $settings)
        {
            $this->settings = $settings;
        }

        protected function renderStoreModeNotice(): string
        {
            return '<div class="store-mode-notice"></div>';
        }
    }
}
