<?php
/**
 * Bootstrap — společný setup pro všechny vstupní body
 * (cron skripty, public/index.php, public/api.php).
 */

declare(strict_types=1);

// Composer autoload
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}


// Jednoduchý PSR-4 autoload pro namespace FveMonitor\Lib
spl_autoload_register(function (string $class): void {
    $prefix = 'FveMonitor\\Lib\\';
    if (!str_starts_with($class, $prefix)) return;

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/lib/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
}, true, true);

// Načti config a nastav timezone
$config = require __DIR__ . '/config/config.php';
date_default_timezone_set($config['app']['timezone']);

// Logy
if (!is_dir($config['app']['log_dir'])) {
    @mkdir($config['app']['log_dir'], 0755, true);
}

return $config;
