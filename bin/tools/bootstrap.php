<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

// Unit tests of the core run without Magento: the module namespace maps to the repository root.
// TOOLS_VENDOR points to another vendor folder with PHPUnit 9 (bin/tools/vendor by default).
require (getenv('TOOLS_VENDOR') ?: __DIR__ . '/vendor') . '/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'CoinsPay\\Payment\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = dirname(__DIR__, 2) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
