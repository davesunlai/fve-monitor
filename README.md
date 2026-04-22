# FVE Monitor

Jednoduchý monitorovací systém pro fotovoltaické elektrárny s napojením na **iSolarCloud** (Sungrow) a porovnáním skutečné výroby s **PVGIS** predikcemi.

První fáze: iSolarCloud + mock data. Připraveno na rozšíření o SolarEdge.

## Funkce

- 🟢 Aktuální výkon a denní výroba (15min vzorky)
- 📅 Měsíční přehled s porovnáním vůči PVGIS predikci (progress bar)
- 📊 Roční graf actual vs PVGIS (Chart.js)
- ⚠️ Automatické alerty při podvýkonu (< 70 % průměrné PVGIS predikce za 7 dní)
- 🔌 Modulární providery — přepnutí mock ↔ iSolarCloud změnou jedné konstanty

## Požadavky

- Debian 12 (nebo podobný)
- PHP 8.1+ (`php-cli`, `php-mysql`, `php-curl`, `php-openssl`, `php-mbstring`)
- MariaDB 10.6+
- Apache/nginx
- Volný odchozí přístup k `re.jrc.ec.europa.eu` (PVGIS) a `gateway.isolarcloud.eu`

## Instalace

### 1) Rozbalení

ZIP obsahuje root složku `fve/`, takže rozbalí přímo do správné struktury.

```bash
mkdir -p /var/www/sunlai.org
cd /var/www/sunlai.org
unzip /tmp/fve-monitor.zip
cd /var/www/sunlai.org/fve

# Vlastník + práva
chown -R www-data:www-data /var/www/sunlai.org/fve
find /var/www/sunlai.org/fve -type d -exec chmod 755 {} \;
find /var/www/sunlai.org/fve -type f -exec chmod 644 {} \;

# Citlivé adresáře a logy - přísnější práva
chmod 750 config logs
chmod 640 config/*.php 2>/dev/null || true
```

### 2) Databáze

```bash
mysql -u root -p < sql/schema.sql

# Vytvoř uživatele s minimálními právy
mysql -u root -p -e "
  CREATE USER IF NOT EXISTS 'fve_monitor'@'localhost' IDENTIFIED BY 'TVOJE_HESLO';
  GRANT SELECT, INSERT, UPDATE, DELETE ON fve_monitor.* TO 'fve_monitor'@'localhost';
  FLUSH PRIVILEGES;"
```

### 3) Konfigurace

```bash
cd /var/www/sunlai.org/fve
cp config/config.example.php config/config.php
chown www-data:www-data config/config.php
chmod 640 config/config.php

joe config/config.php   # vyplň DB heslo, případně iSolarCloud klíče
joe config/plants.php   # uprav seznam svých FVE (lat, lon, kWp, sklon)
```

### 4) Naplnění daty

Spouštěj jako `www-data`, aby případné cache patřily správnému uživateli.

```bash
sudo -u www-data php /var/www/sunlai.org/fve/cron/seed_plants.php       # vloží elektrárny do DB
sudo -u www-data php /var/www/sunlai.org/fve/cron/refresh_pvgis.php     # stáhne PVGIS predikce (1× při instalaci)
sudo -u www-data php /var/www/sunlai.org/fve/cron/fetch_realtime.php    # otestuje, že se tahají aktuální data
```

### 5) Cron

```bash
crontab -e -u www-data
```

```
*/15 * * * * php /var/www/sunlai.org/fve/cron/fetch_realtime.php >> /var/www/sunlai.org/fve/logs/realtime.log 2>&1
55 23 * * *  php /var/www/sunlai.org/fve/cron/fetch_daily.php    >> /var/www/sunlai.org/fve/logs/daily.log 2>&1
0 3 1 * *    php /var/www/sunlai.org/fve/cron/refresh_pvgis.php  >> /var/www/sunlai.org/fve/logs/pvgis.log 2>&1
```

### 6) Webserver (Apache vhost)

```apache
<VirtualHost *:443>
    ServerName fve.example.com
    DocumentRoot /var/www/sunlai.org/fve/public

    <Directory /var/www/sunlai.org/fve/public>
        AllowOverride All
        Require all granted
        DirectoryIndex index.php
    </Directory>

    # Důležité: blokovat přístup mimo public/
    <Directory /var/www/sunlai.org/fve/config>  Require all denied </Directory>
    <Directory /var/www/sunlai.org/fve/lib>     Require all denied </Directory>
    <Directory /var/www/sunlai.org/fve/cron>    Require all denied </Directory>
    <Directory /var/www/sunlai.org/fve/sql>     Require all denied </Directory>
    <Directory /var/www/sunlai.org/fve/logs>    Require all denied </Directory>

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/fve.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/fve.example.com/privkey.pem
</VirtualHost>
```

Otevři `https://fve.example.com/` — uvidíš dashboard.

## Jak získat iSolarCloud OpenAPI klíče

1. Registruj se na https://developer-api.isolarcloud.com/
2. Vytvoř aplikaci (vyplň business kontext, monitoring vlastních FVE)
3. Schválení 2–7 dnů → dostaneš:
   - `appkey` (32 hex znaků)
   - `x-access-key` hodnota
   - **RSA public key** (pro šifrování hesla při loginu)
4. Vlož do `config/config.php`, sekce `isolarcloud`
5. Přepni `'driver' => 'isolarcloud'`
6. V `config/plants.php` doplň `provider_ps_id` (ps_id najdeš v iSolarCloud webu nebo zavoláním `getPowerStationList`)
7. Spusť `php cron/fetch_realtime.php` pro ověření

## Architektura

```
fve-monitor/
├── bootstrap.php          # PSR-4 autoload + config bootstrap
├── config/
│   ├── config.example.php # Šablona, kopíruj na config.php
│   └── plants.php         # Seznam FVE (seed)
├── sql/schema.sql         # 6 tabulek
├── lib/
│   ├── Database.php       # PDO singleton
│   ├── ProviderInterface  # Společné rozhraní providerů
│   ├── ProviderFactory    # Switch mock/isolarcloud/solaredge
│   ├── MockProvider       # Realistická simulace dle PVGIS křivky
│   ├── ISolarCloudProvider# OpenAPI klient (login, getPS, history)
│   ├── PVGIS              # JRC EU API klient
│   └── Predictor          # Actual vs expected, alerty
├── cron/
│   ├── seed_plants.php
│   ├── fetch_realtime.php # každých 15 min
│   ├── fetch_daily.php    # 23:55 + alerty
│   └── refresh_pvgis.php  # 1× měsíčně
└── public/
    ├── index.php          # Dashboard
    ├── api.php            # JSON API (summary, realtime, monthly, yearly, alerts, ack)
    └── assets/            # style.css, app.js, Chart.js z CDN
```

## Datový model

| Tabulka | Účel |
|---|---|
| `plants` | Definice elektráren (lat/lon, kWp, sklon, provider, ps_id) |
| `production_realtime` | 15min vzorky výkonu, ring buffer 7 dní |
| `production_daily` | Denní souhrny (energy_kwh, peak_kw) |
| `pvgis_monthly` | 12 řádků PVGIS predikce na elektrárnu |
| `alerts` | Generované alerty, mažou se po `acknowledged_at` |
| `api_tokens` | Cache iSolarCloud tokenu (TTL 7 dní) |

## Predikce — jak to počítáme

`Predictor::monthlyOverview` vrací:

- `expected_kwh` — PVGIS pro celý aktuální měsíc (z multi-year průměrů)
- `expected_to_date` — PVGIS proporčně k dnešnímu dni v měsíci
- `actual_kwh` — součet `production_daily` od 1. dne
- `ratio = actual / expected_to_date` — 1.0 = na plánu
- `projected_kwh` — extrapolace na konec měsíce při stejném tempu
- `status`:
  - `on_track` (≥ 95 %)
  - `below` (≥ threshold, default 70 %)
  - `underperform` (< threshold) → generuje alert

PVGIS se cachuje 30 dní. Multi-year SARAH3 databáze pro EU se mění minimálně.

## Bezpečnost

- `config/`, `lib/`, `cron/`, `sql/`, `logs/` MUSÍ být mimo DocumentRoot (vhost ukázán výše)
- `config.php` patří do `.gitignore` (obsahuje DB heslo a API klíče)
- DB uživatel má pouze CRUD, žádné DDL/GRANT
- API endpointy jsou read-only kromě `?action=ack` (POST) — pokud budeš deployovat veřejně, přidej autentizaci

## Plánované rozšíření

- SolarEdge provider (siteId, /currentPower, /energyDetails)
- Lokální Modbus TCP z WiNet-S (alternativa, pokud iSolarCloud klíče nedorazí)
- Týdenní e-mail/Slack souhrn
- Export CSV/PDF měsíčního reportu
- Multi-tenant (napříč klienty SEIKON / Monkstone)

## Troubleshooting

**`Mock: plant id X nenalezen`** — Spustit `seed_plants.php`.

**`PVGIS HTTP 429`** — Rate limit, počkej minutu. Nemělo by nastat při běžném použití.

**`iSolarCloud login failed`** — Zkontroluj `app_key`, `access_key`, RSA klíč. Sungrow má specifický PKCS#1 padding.

**Prázdný dashboard** — Cron neběží nebo nemá práva. Zkontroluj `logs/realtime.log`.

---

Vyrobeno pro **SEIKON s.r.o.** · MIT
