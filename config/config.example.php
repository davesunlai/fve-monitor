<?php
/**
 * FVE Monitor — konfigurace (ŠABLONA PRO GIT)
 *
 * Tento soubor JE v gitu, slouží jako šablona.
 * Skutečný config.php je ignorovaný - zkopíruj tento soubor
 * na config.php a vyplň reálné hodnoty.
 */
return [
    'db' => [
        'host'        => '127.0.0.1',
        'port'        => 3306,
        'unix_socket' => '/var/run/mysqld/mysqld.sock',
        'name'        => 'fve_monitor',
        'user'        => 'fve_monitor',
        'pass'        => 'ZMĚŇ_SKUTEČNÉ_HESLO_DB',
        'charset'     => 'utf8mb4',
    ],

    'isolarcloud' => [
        'driver'      => 'mock',
        'base_url'    => 'https://gateway.isolarcloud.eu',
        'app_key'     => 'ZMĚŇ_APPKEY_OD_SUNGROW',
        'access_key'  => 'ZMĚŇ_ACCESS_KEY_OD_SUNGROW',
        'username'    => 'ZMĚŇ_ISOLARCLOUD_LOGIN',
        'password'    => 'ZMĚŇ_ISOLARCLOUD_HESLO',
        'rsa_public_key' => <<<'PEM'
-----BEGIN PUBLIC KEY-----
ZMĚŇ_RSA_KLIC_OD_SUNGROW_V_PEM_FORMATU
-----END PUBLIC KEY-----
PEM,
    ],

    'solaredge' => [
        'driver'   => 'solaredge',
        'base_url' => 'https://monitoringapi.solaredge.com',
        'api_key'  => 'ZMĚŇ_SOLAREDGE_API_KEY',
    ],

    'vapid' => [
        'public_key'  => 'VYGENERUJ_VAPID_PUBLIC_KEY',
        'private_key' => 'VYGENERUJ_VAPID_PRIVATE_KEY',
        'subject'     => 'mailto:admin@example.com',
    ],

    'pvgis' => [
        'base_url'     => 'https://re.jrc.ec.europa.eu/api/v5_3',
        'radiation_db' => 'PVGIS-SARAH3',
        'cache_days'   => 30,
    ],

    'app' => [
        'name'                   => 'FVE Monitor',
        'timezone'               => 'Europe/Prague',
        'underperform_threshold' => 0.70,
        'log_dir'                => __DIR__ . '/../logs',
    ],

    // DEPRECATED - nahrazeno session-based auth (tabulka users)
    'admin' => [
        'username'      => 'deprecated',
        'password_hash' => 'deprecated',
    ],
];
