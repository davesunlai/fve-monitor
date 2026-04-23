<?php
declare(strict_types=1);

namespace FveMonitor\Lib;

/**
 * Porovnává skutečnou výrobu s PVGIS predikcí a generuje alerty.
 *
 * Výstupní data jsou používána:
 *   - dashboard:  index.php (měsíční progress bar, ratio, status)
 *   - alerty:     pokud v posledních 7 dnech actual/expected < threshold → alert
 */
class Predictor
{
    private float $threshold;

    public function __construct()
    {
        $config = require __DIR__ . '/../config/config.php';
        $this->threshold = (float) $config['app']['underperform_threshold'];
    }

    /**
     * Vrátí přehled za aktuální měsíc:
     *   [
     *     'plant_id'         => int,
     *     'month'            => 1..12,
     *     'expected_kwh'     => float (PVGIS pro celý měsíc),
     *     'actual_kwh'       => float (součet production_daily od 1. dne měsíce),
     *     'expected_to_date' => float (PVGIS poměrově do dnešního dne)
     *     'ratio'            => float (actual / expected_to_date, 1.0 = na plánu)
     *     'days_in_month'    => int,
     *     'days_elapsed'     => int,
     *     'projected_kwh'    => float (extrapolace na konec měsíce)
     *   ]
     */
    public function monthlyOverview(int $plantId, ?\DateTimeImmutable $now = null): array
    {
        $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague'));
        $month = (int) $now->format('n');
        $year  = (int) $now->format('Y');
        $daysInMonth = (int) $now->format('t');
        $dayOfMonth  = (int) $now->format('j');

        // PVGIS očekávání
        $cached = (new PVGIS())->loadCachedForPlant($plantId);
        $expectedMonth = (float) ($cached[$month]['e_m_kwh'] ?? 0);
        // Lineární odhad do dneška (PVGIS dává průměry, takže poměr OK)
        $expectedToDate = $expectedMonth * ($dayOfMonth / $daysInMonth);

        // Skutečná výroba
        $sum = Database::one(
            'SELECT COALESCE(SUM(energy_kwh), 0) AS total
             FROM production_daily
             WHERE plant_id = ?
               AND day BETWEEN ? AND ?',
            [
                $plantId,
                sprintf('%04d-%02d-01', $year, $month),
                $now->format('Y-m-d'),
            ]
        );
        $actual = (float) ($sum['total'] ?? 0);

        $ratio = $expectedToDate > 0 ? $actual / $expectedToDate : 0;

        // Projekce: pokud zbytek měsíce běží stejným poměrem
        $projected = $ratio > 0 ? $expectedMonth * $ratio : 0;

        return [
            'plant_id'         => $plantId,
            'month'            => $month,
            'year'             => $year,
            'expected_kwh'     => round($expectedMonth, 1),
            'actual_kwh'       => round($actual, 1),
            'expected_to_date' => round($expectedToDate, 1),
            'ratio'            => round($ratio, 3),
            'days_in_month'    => $daysInMonth,
            'days_elapsed'     => $dayOfMonth,
            'projected_kwh'    => round($projected, 1),
            'status'           => $this->statusFromRatio($ratio),
        ];
    }

    /** Roční přehled – součet 12 měsíců actual vs PVGIS (annual E_y). */
    public function yearlyOverview(int $plantId, int $year): array
    {
        $cached = (new PVGIS())->loadCachedForPlant($plantId);
        $expectedYear = 0;
        foreach ($cached as $m) $expectedYear += (float) $m['e_m_kwh'];

        $rows = Database::all(
            'SELECT MONTH(day) AS m, SUM(energy_kwh) AS kwh
             FROM production_daily
             WHERE plant_id = ? AND YEAR(day) = ?
             GROUP BY MONTH(day)',
            [$plantId, $year]
        );
        $monthlyActual = array_fill(1, 12, 0.0);
        $monthlyExpected = array_fill(1, 12, 0.0);
        foreach ($cached as $month => $r) {
            $monthlyExpected[$month] = (float) $r['e_m_kwh'];
        }
        foreach ($rows as $r) {
            $monthlyActual[(int) $r['m']] = (float) $r['kwh'];
        }
        $actualYear = array_sum($monthlyActual);

        return [
            'year'              => $year,
            'expected_year_kwh' => round($expectedYear, 1),
            'actual_year_kwh'   => round($actualYear, 1),
            'monthly_expected'  => array_map(fn($v) => round($v, 1), $monthlyExpected),
            'monthly_actual'    => array_map(fn($v) => round($v, 1), $monthlyActual),
        ];
    }

    /**
     * Vyhodnotí posledních 7 dní a pokud actual/expected < threshold → vloží alert.
     * Spouštět z cron/fetch_daily.php.
     */
    public function evaluateAlerts(int $plantId): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague'));
        $weekAgo = $now->modify('-7 days');

        $cached = (new PVGIS())->loadCachedForPlant($plantId);

        $rows = Database::all(
            'SELECT day, energy_kwh FROM production_daily
             WHERE plant_id = ? AND day BETWEEN ? AND ?',
            [$plantId, $weekAgo->format('Y-m-d'), $now->format('Y-m-d')]
        );
        if (count($rows) < 3) return; // málo dat

        $actualSum = 0; $expectedSum = 0;
        foreach ($rows as $r) {
            $month = (int) (new \DateTimeImmutable($r['day']))->format('n');
            $eM    = (float) ($cached[$month]['e_m_kwh'] ?? 0);
            $daysInMonth = (int) (new \DateTimeImmutable($r['day']))->format('t');
            $expectedSum += $eM / $daysInMonth;
            $actualSum   += (float) $r['energy_kwh'];
        }
        if ($expectedSum <= 0) return;

        $ratio = $actualSum / $expectedSum;
        if ($ratio < $this->threshold) {
            // Vlož pouze pokud podobný alert v posledních 24h neexistuje
            $exists = Database::one(
                "SELECT id FROM alerts
                 WHERE plant_id = ? AND type = 'underperform'
                   AND created_at > NOW() - INTERVAL 24 HOUR
                   AND acknowledged_at IS NULL",
                [$plantId]
            );
            if ($exists !== null) return;

            $msg = sprintf(
                'Podvýkon za posledních 7 dní: %.1f kWh (očekáváno %.1f), ratio %.0f %%',
                $actualSum, $expectedSum, $ratio * 100
            );
            Database::pdo()->prepare(
                'INSERT INTO alerts (plant_id, type, severity, message, metric)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $plantId,
                'underperform',
                $ratio < 0.4 ? 'critical' : 'warning',
                $msg,
                json_encode(['ratio' => $ratio, 'actual' => $actualSum, 'expected' => $expectedSum]),
            ]);
        }
    }

    private function statusFromRatio(float $r): string
    {
        if ($r >= 0.95) return 'on_track';
        if ($r >= $this->threshold) return 'below';
        return 'underperform';
    }

    /**
     * Vyhodnotí provider alarmy (Sungrow alarm_count, fault_status, offline).
     * Vytvoří záznamy v `alerts` pro nové alarmy, auto-acknowledge pro vyřešené.
     *
     * Logika:
     *   - alarm_count > 0 nebo fault_status ∈ {1,2}  → vytvoř/zachovej 'communication' alert
     *   - alarm_count = 0 a fault_status = 3 (zdravé) → auto-ack existující 'communication' alerty
     */
    public function evaluateProviderAlarms(): void
    {
        $plants = Database::all(
            'SELECT id, code, name, alarm_count, fault_status, ps_status, raw_data
             FROM plants WHERE is_active = 1'
        );

        foreach ($plants as $p) {
            $hasAlarm = ($p['alarm_count'] > 0)
                     || in_array((int) $p['fault_status'], [1, 2], true);

            // Aktivní (nepotvrzený) communication alert pro tuto FVE?
            $activeAlert = Database::one(
                "SELECT id FROM alerts
                 WHERE plant_id = ? AND type = 'communication'
                   AND acknowledged_at IS NULL
                 LIMIT 1",
                [$p['id']]
            );

            if ($hasAlarm && !$activeAlert) {
                // Vytvoř nový alert
                $severity = ((int) $p['fault_status']) === 2 ? 'warning' : 'critical';
                if ($p['alarm_count'] >= 3) $severity = 'critical';

                $faultMap = [0 => 'OK', 1 => 'critical', 2 => 'warning', 3 => 'OK'];
                $faultText = $faultMap[(int) $p['fault_status']] ?? 'unknown';

                $msg = sprintf(
                    'Cloud hlásí %d %s na FVE (fault_status=%s)',
                    (int) $p['alarm_count'],
                    $p['alarm_count'] === 1 ? 'alarm' : 'alarmů',
                    $faultText
                );

                // Načti raw_data ze Sungrow (uloženo při fetch_realtime)
                $rawData = json_decode($p['raw_data'] ?? 'null', true);

                Database::pdo()->prepare(
                    "INSERT INTO alerts (plant_id, type, severity, message, metric, created_at)
                     VALUES (?, 'communication', ?, ?, ?, NOW())"
                )->execute([
                    $p['id'],
                    $severity,
                    $msg,
                    json_encode([
                        'alarm_count'  => (int) $p['alarm_count'],
                        'fault_status' => (int) $p['fault_status'],
                        'ps_status'    => (int) $p['ps_status'],
                        'raw_snapshot' => $rawData,
                    ], JSON_UNESCAPED_UNICODE),
                ]);

            } elseif (!$hasAlarm && $activeAlert) {
                // FVE je teď OK, ale máme starý alert → auto-ack
                Database::pdo()->prepare(
                    "UPDATE alerts
                     SET acknowledged_at = NOW(),
                         acknowledgement_note = '[Auto] Cloud hlásí, že problém pominul.'
                     WHERE id = ?"
                )->execute([$activeAlert['id']]);
            }
        }
    }

}
