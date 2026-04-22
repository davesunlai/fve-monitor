<?php
declare(strict_types=1);

namespace FveMonitor\Lib;

/**
 * Společné rozhraní pro všechny zdroje dat (iSolarCloud, SolarEdge, Mock, Modbus, ...).
 *
 * Aktuální výkon a denní výroba se vrací v jednotném tvaru:
 *   ['power_kw' => float, 'energy_kwh_today' => float, 'ts' => 'Y-m-d H:i:s']
 *
 * Denní historie:
 *   ['day' => 'Y-m-d', 'energy_kwh' => float, 'peak_kw' => float]
 */
interface ProviderInterface
{
    /** Aktuální výkon a kumulativní denní výroba pro elektrárnu. */
    public function getRealtime(string $providerPsId): array;

    /** Denní výroba za zadané období (včetně obou mezí). */
    public function getDailyHistory(string $providerPsId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
