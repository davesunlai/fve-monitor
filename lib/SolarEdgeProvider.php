<?php
declare(strict_types=1);

namespace FveMonitor\Lib;

/**
 * SolarEdge Cloud Monitoring API provider.
 *
 * Dokumentace: https://monitoringapi.solaredge.com
 * Autentizace: API key v URL parameter ?api_key=...
 * Rate limit: 300 requests/day/API key (Basic)
 *
 * Config (config.php):
 *   'solaredge' => [
 *       'driver'   => 'solaredge',
 *       'base_url' => 'https://monitoringapi.solaredge.com',
 *       'api_key'  => 'XXXXXXXXXXXXXXXXXXXXX',  // site-level nebo account-level
 *   ],
 *
 * Plant v DB:
 *   provider = 'solaredge'
 *   provider_ps_id = '1234567'  (SolarEdge site ID z URL)
 */
class SolarEdgeProvider implements ProviderInterface
{
    private array $cfg;

    public function __construct()
    {
        $all = require __DIR__ . '/../config/config.php';
        $this->cfg = $all['solaredge'] ?? [];
        if (empty($this->cfg['api_key'])) {
            throw new \RuntimeException('SolarEdge: chybí api_key v config');
        }
        if (empty($this->cfg['base_url'])) {
            $this->cfg['base_url'] = 'https://monitoringapi.solaredge.com';
        }
    }

    /**
     * Vrátí aktuální výkon a dnes vyrobenou energii pro konkrétní site.
     *
     * @param string $siteId SolarEdge site ID
     * @return array ['ts' => 'Y-m-d H:i:s', 'power_kw' => float, 'energy_kwh_today' => float]
     */
    public function getRealtime(string $siteId): array
    {
        if ($siteId === '' || $siteId === null) {
            throw new \RuntimeException('SolarEdge: siteId je prázdný');
        }

        $url = sprintf('%s/site/%s/overview?api_key=%s',
            rtrim($this->cfg['base_url'], '/'),
            urlencode($siteId),
            urlencode($this->cfg['api_key'])
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($code !== 200) {
            throw new \RuntimeException("SolarEdge /overview HTTP $code: " . substr((string)$body, 0, 300));
        }

        $json = json_decode($body, true);
        $ov   = $json['overview'] ?? null;
        if (!is_array($ov)) {
            throw new \RuntimeException('SolarEdge: neočekávaná odpověď: ' . substr($body, 0, 300));
        }

        // currentPower.power je ve Wattech, převedeme na kW
        $powerW = (float) ($ov['currentPower']['power'] ?? 0);
        // lastDayData.energy je ve Wh, převedeme na kWh
        $energyTodayWh = (float) ($ov['lastDayData']['energy'] ?? 0);

        // lastUpdateTime format: "2024-04-21 08:30:00"
        $ts = $ov['lastUpdateTime'] ?? date('Y-m-d H:i:s');

        return [
            'ts'               => $ts,
            'power_kw'         => round($powerW / 1000.0, 3),
            'energy_kwh_today' => round($energyTodayWh / 1000.0, 3),
        ];
    }

    /**
     * Vrátí seznam všech sites dostupných k tomuto API klíči.
     * Funguje jen pro account-level API key. Pro site-level key vrátí jen 1 site.
     *
     * @return array Normalizované pole sites
     */
    public function listSites(): array
    {
        $url = sprintf('%s/sites/list?api_key=%s',
            rtrim($this->cfg['base_url'], '/'),
            urlencode($this->cfg['api_key'])
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            throw new \RuntimeException("SolarEdge /sites/list HTTP $code: " . substr((string)$body, 0, 300));
        }

        $json  = json_decode($body, true);
        $sites = $json['sites']['site'] ?? [];
        $out   = [];

        foreach ($sites as $s) {
            $loc = $s['location'] ?? [];
            $out[] = [
                'ps_id'            => (string) ($s['id'] ?? ''),
                'ps_name'          => $s['name'] ?? '',
                'latitude'         => 0.0,  // SolarEdge /sites/list nevrací GPS
                'longitude'        => 0.0,
                'ps_location'      => trim(
                    ($loc['city']    ?? '') . ', ' .
                    ($loc['country'] ?? '')
                , ' ,'),
                'peak_power_kwp'   => (float) ($s['peakPower'] ?? 0),  // už v kWp
                'install_date'     => $s['installationDate'] ?? null,
                'curr_power_w'     => (float) ($s['currentPower']['power'] ?? 0),
                'today_energy_kwh' => (float) ($s['lastDayData']['energy'] ?? 0) / 1000.0,
                'total_energy_kwh' => (float) ($s['lifeTimeData']['energy'] ?? 0) / 1000.0,
                'ps_status'        => ($s['status'] ?? '') === 'Active' ? 1 : 0,
                'alarm_count'      => 0,  // SolarEdge overview neukazuje alarmy
                'raw'              => $s,
            ];
        }
        return $out;
    }

    /**
     * Agregovaný update pro všechny aktivní SolarEdge plants v DB.
     * (Analogicky k ISolarCloudProvider::updateRealtimeForAll(), ale SolarEdge
     * má samostatný endpoint per site, takže voláme v cyklu.)
     */
    public function updateRealtimeForAll(): array
    {
        $plants = Database::all(
            "SELECT id, code, provider_ps_id FROM plants
             WHERE is_active = 1 AND provider = 'solaredge'
               AND provider_ps_id IS NOT NULL"
        );

        $updated = 0; $errors = [];
        foreach ($plants as $plant) {
            try {
                $data = $this->getRealtime($plant['provider_ps_id']);
                Database::pdo()->prepare(
                    'INSERT INTO production_realtime (plant_id, ts, power_kw, energy_kwh)
                     VALUES (?, NOW(), ?, ?)
                     ON DUPLICATE KEY UPDATE
                        power_kw = VALUES(power_kw),
                        energy_kwh = VALUES(energy_kwh)'
                )->execute([
                    $plant['id'],
                    $data['power_kw'],
                    $data['energy_kwh_today'],
                ]);
                $updated++;
            } catch (\Throwable $e) {
                $errors[] = "{$plant['code']}: " . $e->getMessage();
            }
        }
        return ['updated' => $updated, 'errors' => $errors, 'total' => count($plants)];
    }

    /**
     * Denní historie výroby SolarEdge site za zadané období.
     *
     * Endpoint: /site/{siteId}/energy?timeUnit=DAY&startDate=YYYY-MM-DD&endDate=YYYY-MM-DD
     * Limit: max 1 rok pro timeUnit=DAY (dokumentace)
     *
     * @return array Pole [['date' => 'Y-m-d', 'energy_kwh' => float], ...]
     */
    public function getDailyHistory(string $providerPsId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($providerPsId === '') {
            throw new \RuntimeException('SolarEdge: siteId je prázdný');
        }

        $url = sprintf('%s/site/%s/energy?timeUnit=DAY&startDate=%s&endDate=%s&api_key=%s',
            rtrim($this->cfg['base_url'], '/'),
            urlencode($providerPsId),
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
            urlencode($this->cfg['api_key'])
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            throw new \RuntimeException("SolarEdge /energy HTTP $code: " . substr((string)$body, 0, 300));
        }

        $json   = json_decode($body, true);
        $values = $json['energy']['values'] ?? [];
        $unit   = $json['energy']['unit'] ?? 'Wh';  // SolarEdge default: Wh

        // Konverze do kWh
        $divisor = 1000;                              // Wh -> kWh
        if ($unit === 'kWh') $divisor = 1;
        if ($unit === 'MWh') $divisor = 0.001;

        $out = [];
        foreach ($values as $v) {
            // date format: "2024-04-21 00:00:00"
            $date = substr($v['date'] ?? '', 0, 10);
            if ($date === '') continue;
            $energy = $v['value'];
            $out[] = [
                'date'       => $date,
                'energy_kwh' => $energy === null ? 0.0 : round((float)$energy / $divisor, 3),
            ];
        }
        return $out;
    }

}
