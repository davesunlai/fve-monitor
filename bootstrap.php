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

// Activity tracking (heartbeat) — tichý, nesmí shodit aplikaci
if (PHP_SAPI !== 'cli') {
    \FveMonitor\Lib\ActivityTracker::track();
    // Session lock fix: session je od teď read-only ($_SESSION zůstává čitelná).
    // Paralelní AJAX requesty tak neblokují jeden druhého.
    // Stránky co potřebují zápis (login/logout/passkey) volají Auth::reopen().
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

return $config;
