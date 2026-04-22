<?php
declare(strict_types=1);

namespace FveMonitor\Lib;

/**
 * Mock provider — generuje realistická simulovaná data.
 *
 * Použití před obdržením skutečných API klíčů. Křivka výkonu během dne
 * je sinusoida mezi východem a západem slunce, modulovaná měsíčním
 * faktorem (cca odpovídá ČR / SARAH3).
 *
 * Konstruktor přijímá ID elektrárny v naší DB (ne ps_id), aby si mock
 * mohl natáhnout zeměpisné parametry pro výpočet východu/západu.
 */
class MockProvider implements ProviderInterface
{
    /** Empiricky odvozené násobky vůči denní špičce v červnu (1.0 = červen). */
    private const MONTH_FACTOR = [
        1 => 0.18, 2 => 0.30, 3 => 0.55, 4 => 0.80, 5 => 0.95, 6 => 1.00,
        7 => 1.00, 8 => 0.92, 9 => 0.70, 10 => 0.45, 11 => 0.22, 12 => 0.15,
    ];

    private array $plant;

    public function __construct(int $plantId)
    {
        $row = Database::one('SELECT * FROM plants WHERE id = ?', [$plantId]);
        if ($row === null) {
            throw new \RuntimeException("Mock: plant id {$plantId} nenalezen");
        }
        $this->plant = $row;
    }

    public function getRealtime(string $providerPsId): array
    {
        $now   = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague'));
        $power = $this->simulatePower($now);
        $energy = $this->simulateDailyEnergy($now);

        return [
            'power_kw'         => round($power, 3),
            'energy_kwh_today' => round($energy, 3),
            'ts'               => $now->format('Y-m-d H:i:s'),
        ];
    }

    public function getDailyHistory(string $providerPsId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $out = [];
        $cursor = $from;
        $endOfDay = $to->setTime(23, 59, 59);

        while ($cursor <= $endOfDay) {
            $month   = (int) $cursor->format('n');
            $factor  = self::MONTH_FACTOR[$month];
            $kwp     = (float) $this->plant['peak_power_kwp'];
            // Náhodné kolísání ±15 % kolem průměru (počasí)
            $weather = 0.85 + (mt_rand(0, 30) / 100);
            // Typické denní hodiny ekvivalentu plného slunce v ČR: 4.2 v červnu, 0.6 v prosinci
            $peakHours = $factor * 4.2;
            $energy    = $kwp * $peakHours * $weather * (1 - $this->plant['system_loss_pct'] / 100);

            $out[] = [
                'day'        => $cursor->format('Y-m-d'),
                'energy_kwh' => round($energy, 3),
                'peak_kw'    => round($kwp * $factor * $weather * 0.85, 3),
            ];
            $cursor = $cursor->modify('+1 day');
        }
        return $out;
    }

    // ───── interní helpery ─────

    private function simulatePower(\DateTimeImmutable $now): float
    {
        [$sunrise, $sunset] = $this->sunriseSunset($now);
        if ($now < $sunrise || $now > $sunset) {
            return 0.0;
        }

        $month   = (int) $now->format('n');
        $factor  = self::MONTH_FACTOR[$month];
        $kwp     = (float) $this->plant['peak_power_kwp'];

        $dayLen  = $sunset->getTimestamp() - $sunrise->getTimestamp();
        $elapsed = $now->getTimestamp() - $sunrise->getTimestamp();
        $phase   = ($elapsed / $dayLen) * M_PI; // 0..π
        $bell    = sin($phase);                  // 0..1..0

        // Náhodné mraky
        $cloud = 0.85 + (mt_rand(0, 30) / 100);
        $loss  = 1 - $this->plant['system_loss_pct'] / 100;

        return max(0.0, $kwp * $factor * $bell * $cloud * $loss);
    }

    private function simulateDailyEnergy(\DateTimeImmutable $now): float
    {
        [$sunrise, $_] = $this->sunriseSunset($now);
        if ($now < $sunrise) return 0.0;

        // Integruj sinusovou křivku po 15min krocích od východu po teď
        $energy = 0.0;
        $cursor = $sunrise;
        $step   = 900; // 15 min
        while ($cursor <= $now) {
            $p = $this->simulatePower($cursor);
            $energy += $p * ($step / 3600);
            $cursor = $cursor->modify('+15 minutes');
        }
        return $energy;
    }

    /**
     * Zjednodušený výpočet východu/západu (přesnost ~5 min, pro mock dostačuje).
     */
    private function sunriseSunset(\DateTimeImmutable $day): array
    {
        $tz = new \DateTimeZone('Europe/Prague');
        $lat = (float) $this->plant['latitude'];
        $lon = (float) $this->plant['longitude'];
        $ts  = $day->setTime(12, 0)->getTimestamp();

        $sunriseTs = date_sunrise($ts, SUNFUNCS_RET_TIMESTAMP, $lat, $lon, 90 + 50/60, 1);
        $sunsetTs  = date_sunset($ts, SUNFUNCS_RET_TIMESTAMP, $lat, $lon, 90 + 50/60, 1);

        $sunrise = (new \DateTimeImmutable('@' . $sunriseTs))->setTimezone($tz);
        $sunset  = (new \DateTimeImmutable('@' . $sunsetTs))->setTimezone($tz);
        return [$sunrise, $sunset];
    }
}
