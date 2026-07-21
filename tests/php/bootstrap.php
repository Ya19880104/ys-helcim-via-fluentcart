<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
}

require __DIR__ . '/Doubles/WordPress.php';
require __DIR__ . '/Doubles/FluentCart.php';
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/Doubles/FakeWpdb.php';
