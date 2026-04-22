<?php
declare(strict_types=1);

namespace FveMonitor\Lib;

/**
 * PVGIS klient v2 — podpora více sekcí panelů s různou orientací.
 *
 * Postup:
 *   1) Pro každou sekci zavolá PVGIS /PVcalc s jejím tilt/azimuth
 *      a peakpower = total_kwp * (section.power_share_pct / 100)
 *   2) Uloží per-sekce predikci do pvgis_monthly
 *   3) Predictor pak může sčítat per-elektrárnu z více řádků
 *
 * Degradace:
 *   Výsledky se násobí (1 - degradation_pct * years_since_install),
 *   kde years_since_install = current_year - install_year.
 */
class PVGIS
{
    private array $cfg;

    public function __construct()
    {
        $config = require __DIR__ . '/../config/config.php';
        $this->cfg = $config['pvgis'];
    }

    /**
     * Načte čerstvá data z PVGIS pro všechny sekce elektrárny.
     * Vrací pole per sekci: [ section_id => [ month => data ] ]
     */
    public function refreshForPlant(array $plant): array
    {
        $sections = Database::all(
            'SELECT * FROM plant_sections WHERE plant_id = ? ORDER BY sort_order, id',
            [$plant['id']]
        );

        if (empty($sections)) {
            // Fallback — stará elektrárna bez sekcí, použij tilt/azimuth z plants
            $sections = [[
                'id' => null,
                'plant_id' => $plant['id'],
                'name' => 'Hlavní',
                'tilt_deg' => $plant['tilt_deg'],
                'azimuth_deg' => $plant['azimuth_deg'],
                'power_share_pct' => 100.0,
            ]];
        }

        // Degradační faktor
        $degradationFactor = $this->degradationFactor(
            $plant['install_year'] ?? null,
            (float)($plant['degradation_pct_per_year'] ?? 0.5)
        );

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $allRows = [];

        try {
            // Vymaž stará data pro tuto elektrárnu
            $pdo->prepare('DELETE FROM pvgis_monthly WHERE plant_id = ?')
                ->execute([$plant['id']]);

            $insertStmt = $pdo->prepare(
                'INSERT INTO pvgis_monthly
                 (plant_id, section_id, month, e_d_kwh, e_m_kwh, h_i_d, h_i_m, sd_m_kwh, fetched_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );

            foreach ($sections as $section) {
                $sectionKwp = (float)$plant['peak_power_kwp']
                    * ((float)$section['power_share_pct'] / 100);

                $params = [
                    'lat'         => $plant['latitude'],
                    'lon'         => $plant['longitude'],
                    'peakpower'   => $sectionKwp,
                    'loss'        => $plant['system_loss_pct'],
                    'angle'       => $section['tilt_deg'],
                    'aspect'      => $section['azimuth_deg'],
                    'mountingplace' => 'free',
                    'pvtechchoice' => 'crystSi',
                    'raddatabase' => $this->cfg['radiation_db'],
                    'outputformat' => 'json',
                ];

                $json = $this->callPvgis($params);

                foreach ($json['outputs']['monthly']['fixed'] as $m) {
                    // Aplikuj degradaci
                    $e_d = (float)$m['E_d'] * $degradationFactor;
                    $e_m = (float)$m['E_m'] * $degradationFactor;

                    $insertStmt->execute([
                        $plant['id'],
                        $section['id'],
                        (int)$m['month'],
                        round($e_d, 3),
                        round($e_m, 3),
                        round((float)$m['H(i)_d'], 3),
                        round((float)$m['H(i)_m'], 3),
                        isset($m['SD_m']) ? round((float)$m['SD_m'], 3) : null,
                    ]);

                    $allRows[] = [
                        'section_id' => $section['id'],
                        'section_name' => $section['name'],
                        'month' => (int)$m['month'],
                        'e_m_kwh' => round($e_m, 3),
                    ];
                }

                // Rate limit - buďme slušní k PVGIS API
                usleep(250_000);
            }

            $pdo->commit();
            return $allRows;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Načte SUMU per měsíc pro elektrárnu (agreguje všechny sekce).
     */
    public function loadCachedForPlant(int $plantId): array
    {
        $rows = Database::all(
            'SELECT month,
                    SUM(e_d_kwh) AS e_d_kwh,
                    SUM(e_m_kwh) AS e_m_kwh,
                    AVG(h_i_d)   AS h_i_d,
                    AVG(h_i_m)   AS h_i_m,
                    SUM(sd_m_kwh) AS sd_m_kwh
             FROM pvgis_monthly
             WHERE plant_id = ?
             GROUP BY month
             ORDER BY month',
            [$plantId]
        );
        $byMonth = [];
        foreach ($rows as $r) {
            $byMonth[(int)$r['month']] = $r;
        }
        return $byMonth;
    }

    // ───── helpery ─────

    private function callPvgis(array $params): array
    {
        $url = $this->cfg['base_url'] . '/PVcalc?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => 'FveMonitor/1.0',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            throw new \RuntimeException("PVGIS HTTP {$code}: " . substr((string)$body, 0, 200));
        }

        $json = json_decode((string)$body, true);
        if (!isset($json['outputs']['monthly']['fixed'])) {
            throw new \RuntimeException('PVGIS: neočekávaná struktura odpovědi');
        }
        return $json;
    }

    private function degradationFactor(?int $installYear, float $annualDegradationPct): float
    {
        if ($installYear === null) return 1.0;
        $years = max(0, (int)date('Y') - $installYear);
        return max(0.0, 1.0 - ($years * $annualDegradationPct / 100));
    }
}
