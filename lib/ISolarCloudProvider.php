<?php
declare(strict_types=1);

namespace FveMonitor\Lib;

/**
 * iSolarCloud (Sungrow) OpenAPI klient.
 *
 * Kostra. Aktivuje se po obdržení AppKey + AccessKey ze Sungrow developer portálu.
 *
 * Endpointy (verifikováno proti veřejné dokumentaci OpenAPI v jaře 2024):
 *   POST /openapi/login                    — přihlášení, vrací token
 *   POST /openapi/getPowerStationList      — seznam elektráren
 *   POST /openapi/getPowerStationDetail    — detail + aktuální výkon
 *   POST /openapi/getHouseholdStoragePsReport — denní výroba (Day/Month/Year)
 *
 * Autentizace pro každý request:
 *   Header  x-access-key: <ACCESS_KEY>
 *   Body    appkey:        <APP_KEY>
 *           token:         <token z /login>
 *   Heslo se šifruje RSA public klíčem (Sungrow ho posílá s AppKey).
 *
 * Token expiruje po cca 30 dnech, cachuje se v tabulce api_tokens.
 */
class ISolarCloudProvider implements ProviderInterface
{
    private array $cfg;

    public function __construct()
    {
        $config = require __DIR__ . '/../config/config.php';
        $this->cfg = $config['isolarcloud'];

        if (empty($this->cfg['app_key']) || empty($this->cfg['access_key'])) {
            throw new \RuntimeException(
                'iSolarCloud: chybí app_key / access_key. ' .
                'Buď doplň config/config.php, nebo přepni driver na "mock".'
            );
        }
    }

    public function getRealtime(string $providerPsId): array
    {
        $resp = $this->request('/openapi/getPowerStationDetail', [
            'ps_id' => $providerPsId,
        ]);

        // Struktura odpovědi (zjednodušeně):
        //   result_data.curr_power      — W
        //   result_data.today_energy    — kWh
        return [
            'power_kw'         => (float)($resp['result_data']['curr_power'] ?? 0) / 1000,
            'energy_kwh_today' => (float)($resp['result_data']['today_energy'] ?? 0),
            'ts'               => date('Y-m-d H:i:s'),
        ];
    }

    public function getDailyHistory(string $providerPsId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $resp = $this->request('/openapi/getHouseholdStoragePsReport', [
            'ps_id'      => $providerPsId,
            'date_id'    => $from->format('Ym'),       // YYYYMM pro měsíční report
            'date_type'  => 2,                          // 2 = Day, 3 = Month, 4 = Year
        ]);

        $out = [];
        foreach ($resp['result_data']['day_data'] ?? [] as $row) {
            $out[] = [
                'day'        => $row['date'],          // YYYY-MM-DD
                'energy_kwh' => (float) $row['p83022'], // kód pro denní výrobu
                'peak_kw'    => 0.0,                   // OpenAPI peak_kw nevrací → dopočítat z minutových
            ];
        }
        return $out;
    }

    // ───── interní HTTP / autentizace ─────

    private function request(string $endpoint, array $payload): array
    {
        $payload['appkey'] = $this->cfg['app_key'];
        $payload['token']  = $this->getToken();

        $ch = curl_init($this->cfg['base_url'] . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json;charset=UTF-8',
                'x-access-key: ' . $this->cfg['access_key'],
                'sys_code: 901',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            throw new \RuntimeException("iSolarCloud HTTP {$code}: {$body}");
        }
        $json = json_decode($body, true);
        if (($json['result_code'] ?? '') !== '1') {
            throw new \RuntimeException(
                'iSolarCloud error: ' . ($json['result_msg'] ?? 'unknown')
            );
        }
        return $json;
    }

    private function getToken(): string
    {
        $row = Database::one(
            'SELECT token FROM api_tokens WHERE provider = ? AND expires_at > NOW()',
            ['isolarcloud']
        );
        if ($row !== null) {
            return $row['token'];
        }

        // Heslo musí být zašifrované RSA public klíčem od Sungrow
        $encryptedPwd = $this->rsaEncrypt($this->cfg['password']);

        $payload = [
            'appkey'      => $this->cfg['app_key'],
            'user_account' => $this->cfg['username'],
            'user_password' => $encryptedPwd,
            'login_type'  => '1',
        ];

        $ch = curl_init($this->cfg['base_url'] . '/openapi/login');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json;charset=UTF-8',
                'x-access-key: ' . $this->cfg['access_key'],
                'sys_code: 901',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        $json = json_decode($body, true);

        if (($json['result_code'] ?? '') !== '1') {
            throw new \RuntimeException('iSolarCloud login failed: ' . ($json['result_msg'] ?? 'unknown'));
        }

        $token = $json['result_data']['token'];
        // Token cache (Sungrow uvádí TTL 30 dní, držíme bezpečně 7)
        $expires = (new \DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s');
        Database::pdo()->prepare(
            'REPLACE INTO api_tokens (provider, token, expires_at) VALUES (?, ?, ?)'
        )->execute(['isolarcloud', $token, $expires]);

        return $token;
    }

    private function rsaEncrypt(string $plain): string
    {
        $pubKey = openssl_pkey_get_public($this->cfg['rsa_public_key']);
        if ($pubKey === false) {
            throw new \RuntimeException('Neplatný RSA public klíč v configu');
        }
        openssl_public_encrypt($plain, $encrypted, $pubKey, OPENSSL_PKCS1_PADDING);
        return base64_encode($encrypted);
    }

    /**
     * Vrátí seznam VŠECH elektráren v iSolarCloud účtu.
     * Volá /openapi/getPowerStationList (pageList strukturováno).
     *
     * @return array Pole asociativních polí s normalizovanými klíči:
     *   ps_id, ps_name, latitude, longitude, ps_location,
     *   peak_power_kwp, install_date,
     *   curr_power_w, today_energy_kwh, total_energy_kwh,
     *   ps_status, alarm_count, raw (původní objekt)
     */
    public function listPowerStations(): array
    {
        $token = $this->getToken();
        $allStations = [];
        $curPage = 1;
        $pageSize = 50;

        while (true) {
            $payload = [
                'appkey' => $this->cfg['app_key'],
                'token'  => $token,
                'curPage' => $curPage,
                'size'    => $pageSize,
            ];
            $ch = curl_init($this->cfg['base_url'] . '/openapi/getPowerStationList');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json;charset=UTF-8',
                    'x-access-key: ' . $this->cfg['access_key'],
                    'sys_code: 901',
                ],
                CURLOPT_TIMEOUT => 30,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code !== 200) {
                throw new \RuntimeException("iSolarCloud HTTP {$code}: {$body}");
            }
            $json = json_decode($body, true);
            if (($json['result_code'] ?? '') !== '1') {
                throw new \RuntimeException('iSolarCloud listPowerStations: ' . ($json['result_msg'] ?? '?'));
            }

            $list = $json['result_data']['pageList'] ?? [];
            foreach ($list as $ps) {
                $allStations[] = $this->normalizeStation($ps);
            }
            $rowCount = (int)($json['result_data']['rowCount'] ?? 0);
            if (count($allStations) >= $rowCount || empty($list)) break;
            $curPage++;
            if ($curPage > 20) break; // pojistka
        }

        return $allStations;
    }

    /**
     * Aktualizuje real-time data v MariaDB pro VŠECHNY active iSolarCloud plants.
     * Použití z cronu místo getRealtime() po jedné.
     *
     * @return array ['updated' => N, 'errors' => [..]]
     */
    public function updateRealtimeForAll(): array
    {
        $stations = $this->listPowerStations();
        $byPsId = [];
        foreach ($stations as $s) $byPsId[$s['ps_id']] = $s;

        $plants = Database::all(
            "SELECT id, code, provider_ps_id FROM plants
             WHERE is_active = 1 AND provider = 'isolarcloud'
               AND provider_ps_id IS NOT NULL"
        );

        $updated = 0; $errors = [];
        foreach ($plants as $plant) {
            $ps = $byPsId[$plant['provider_ps_id']] ?? null;
            if ($ps === null) {
                $errors[] = "{$plant['code']}: ps_id {$plant['provider_ps_id']} nenalezeno v cloudu";
                continue;
            }
            try {
                Database::pdo()->prepare(
                    'INSERT INTO production_realtime (plant_id, ts, power_kw, energy_kwh)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        power_kw = VALUES(power_kw),
                        energy_kwh = VALUES(energy_kwh)'
                )->execute([
                    $plant['id'],
                    date('Y-m-d H:i:s'),
                    $ps['curr_power_w'] / 1000.0,
                    $ps['today_energy_kwh'],
                ]);

                // Aktualizuj stav elektrárny (alarmy, fault, status)
                Database::pdo()->prepare(
                    'UPDATE plants SET alarm_count = ?, fault_status = ?, ps_status = ? WHERE id = ?'
                )->execute([
                    $ps['alarm_count'],
                    (int) ($ps['raw']['ps_fault_status'] ?? 0),
                    (int) $ps['ps_status'],
                    $plant['id'],
                ]);
                $updated++;
            } catch (\Throwable $e) {
                $errors[] = "{$plant['code']}: " . $e->getMessage();
            }
        }
        return ['updated' => $updated, 'errors' => $errors, 'total' => count($plants)];
    }

    /**
     * Normalizuje strukturu z iSolarCloud /getPowerStationList do plochého pole.
     * Sungrow vrací hodnoty jako {unit, value} - my je převádíme na SI/standardní jednotky:
     *   - Výkon (curr_power) → W
     *   - Energie (today_energy, total_energy) → kWh
     *   - Kapacita (total_capcity) → kWp
     */
    private function normalizeStation(array $ps): array
    {
        return [
            'ps_id'            => (string) $ps['ps_id'],
            'ps_name'          => $ps['ps_name'] ?? '',
            'latitude'         => (float) ($ps['latitude'] ?? 0),
            'longitude'        => (float) ($ps['longitude'] ?? 0),
            'ps_location'      => $ps['ps_location'] ?? '',
            'peak_power_kwp'   => $this->extractValue($ps, 'total_capcity', 0, 'capacity'),
            'install_date'     => $ps['install_date'] ?? null,
            'curr_power_w'     => $this->extractValue($ps, 'curr_power', 0, 'power'),
            'today_energy_kwh' => $this->extractValue($ps, 'today_energy', 0, 'energy'),
            'total_energy_kwh' => $this->extractValue($ps, 'total_energy', 0, 'energy'),
            'ps_status'        => $ps['ps_status'] ?? 0,
            'alarm_count'      => $ps['alarm_count'] ?? 0,
            'raw'              => $ps,
        ];
    }

    /**
     * Vytáhne {unit, value} strukturu z odpovědi a převede do standardní jednotky.
     *
     * @param array  $ps      Surová elektrárna z API
     * @param string $key     Klíč v $ps (např. 'curr_power')
     * @param float  $default Defaultní hodnota
     * @param string $kind    'power' (→ W), 'energy' (→ kWh), 'capacity' (→ kWp)
     */
    private function extractValue(array $ps, string $key, float $default, string $kind): float
    {
        if (!isset($ps[$key])) return $default;
        $v = $ps[$key];

        if (!is_array($v)) {
            return (float) $v;
        }

        $value = (float) ($v['value'] ?? $default);
        $unit  = trim($v['unit'] ?? '');

        $multipliers = [
            // Výkon → W
            'power' => [
                'W' => 1,        'kW' => 1_000,    'MW' => 1_000_000,
                'GW' => 1_000_000_000,
            ],
            // Energie → kWh (Sungrow čínské jednotky: 度=kWh, 千度=MWh, 万度=10MWh)
            'energy' => [
                'kWh' => 1,      'MWh' => 1_000,   'GWh' => 1_000_000,
                'Wh'  => 0.001,
                '度'   => 1,      '千度' => 1_000,   '万度' => 10_000,
                'kvarh' => 1,    // pro jistotu
            ],
            // Kapacita → kWp
            'capacity' => [
                'kWp' => 1,      'MWp' => 1_000,   'GWp' => 1_000_000,
                'Wp'  => 0.001,  'kW' => 1, 'W' => 0.001,
            ],
        ];

        $map = $multipliers[$kind] ?? [];
        $mult = $map[$unit] ?? 1;

        return $value * $mult;
    }

}
