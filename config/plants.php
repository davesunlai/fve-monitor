<?php
/**
 * Seed dat pro elektrárny.
 * Spustit jednou po vytvoření DB: php cron/seed_plants.php
 *
 * Pro reálná data vyplň `provider_ps_id` (ps_id z iSolarCloud),
 * v opačném případě nech NULL a používej driver 'mock'.
 */

return [
    [
        'code'            => 'STRAMBERK_DEMO',
        'name'            => 'SEIKON Štramberk - střecha',
        'provider'        => 'mock',
        'provider_ps_id'  => null,
        'latitude'        => 49.5919,
        'longitude'       => 18.1186,
        'peak_power_kwp'  => 9.9,
        'tilt_deg'        => 35,
        'azimuth_deg'     => 0,    // jih
        'system_loss_pct' => 14.0,
    ],
    [
        'code'            => 'KOPRIVNICE_HALA',
        'name'            => 'Kopřivnice - výrobní hala',
        'provider'        => 'mock',
        'provider_ps_id'  => null,
        'latitude'        => 49.5994,
        'longitude'       => 18.1453,
        'peak_power_kwp'  => 49.5,
        'tilt_deg'        => 15,
        'azimuth_deg'     => -10,
        'system_loss_pct' => 14.0,
    ],
    [
        'code'            => 'OSTRAVA_RD',
        'name'            => 'Ostrava - rodinný dům',
        'provider'        => 'mock',
        'provider_ps_id'  => null,
        'latitude'        => 49.8209,
        'longitude'       => 18.2625,
        'peak_power_kwp'  => 7.2,
        'tilt_deg'        => 40,
        'azimuth_deg'     => 15,
        'system_loss_pct' => 14.0,
    ],
];
