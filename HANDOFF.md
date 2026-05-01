# FVE Monitor — aktuální stav (handoff k novému chatu)

**Datum**: 2026-05-01
**Aktuální verze**: v0.64
**Poslední commit**: topbar odkaz na dashboard + podtitul 96h grafu

## Co je hotové

### Dashboard (/index.php)
- Tabulkový přehled 9 FVE s live daty
- Detail FVE: roční přehled, 96h graf výkonu (4 dny zpět + 2 dny forecast), mapa Leaflet
- Hamburger menu (sdílený _topbar.php) — nadpis = odkaz na dashboard
- Push notifikace, alert badge, last update time

### Grafy v detailu FVE — NOVĚ v0.63–v0.64
- **96h graf výkonu** (4 dny historie + 2 dny předpověď):
  - Žlutá plná = skutečný výkon (15min vzorky)
  - Modrá přerušovaná = předpověď počasí (Open-Meteo GTI, jen budoucí hodiny)
  - Šedá přerušovaná = PVGIS průměrný den (sinusový profil z měsíčního průměru)
  - Podtitul grafu s rozsahem dat ("Po 28.4. — Ne 3.5., 4 dny historie + 2 dny předpověď")
- **Roční graf** (měsíční sloupce):
  - Šedé sloupce = PVGIS predikce
  - Žluté sloupce = skutečnost
  - Modrá linka = Výhled (PVGIS) pro aktuální + budoucí měsíce

### API endpoint weather_prediction
- `GET ?action=weather_prediction&plant=ID`
- Volá Open-Meteo API (GTI s tilt+azimut ze sekcí panelů)
- Vrací: `forecast` (96 hodinových vzorků), `pvgis_profile` (96 hodinových vzorků)
- PHP cURL (allow_url_fopen=Off na serveru)
- Interpolace na 15min kroky v JS (lineární mezi hodinovými body)

### Comparison (/comparison.php)
- Denní srovnání FVE — kalendář s buňkami
- 3 režimy: denní průměr, měsíční Ø všech, vlastní průměr FVE
- 5stupňové barvy (POD/normál/NAD průměrem)
- Klikatelné buňky → modal s 4 stat kartami + 15min daty

### Performance (/performance.php)
- Tabulka 9 FVE seřazených podle plnění %
- Sloupce: Reálná výroba, PVGIS, Plnění %, Productivity, Σ kumulativní, Roční plnění %
- Sparkline 12 měsíců
- Roční graf: 120 sloupců (12 měsíců × 10 = 9 FVE + PVGIS)

### Admin
- /admin/alert_settings.php — per-FVE underperform threshold + ▶️ Test tlačítko
- /admin/plants_ote.php — OTE/ERÚ metadata, sticky první sloupec
- /admin/ote_report.php — měsíční výkaz CSV/XML
- /admin/import_csv.php — 3-fázový workflow

### Infrastruktura
- _app_head.php (sdílený <head> + meta + manifest + Chart.js + Leaflet)
- _topbar.php (sdílený hamburger menu + aktivní stránka + odkaz na dashboard)
- version.json + sw.js cache bump při každém commitu

### DB metadata (Monkstone licence ERÚ 112441941)
- Všech 9 FVE: evid_number, ote_vyrobna_id, ean_vyrobny, license_number, ico
- Adresa, katastr, parcely, datumy uvedení do provozu

## Co chybí / pending

### OTE/ERÚ data
- **OTE_ID** (registrační ID v OTE/CDS): vyplněno jen 2/9 (Plzeň, Mladá Bol.)
  - Zbývá: Frýdek, Ostrava, Pardubice, Staré Město, Zlín, Vestec, Č.Lípa
  - Zdroj: OTE portál CDS (po přihlášení)
- **EAN_OPM** (z faktur distributora, 18-místný): vyplněno 3/9
  - Zbývá: Frýdek, Pardubice, Staré Město, Zlín, Vestec, Č.Lípa
  - Zdroj: faktura distributora (ČEZ, EG.D, Teplárna Zlín)

### Funkce
- OTE měsíční výkaz pro duben 2026 (deadline 10.5.) — netestováno!
- Cleanup nepotvrzených alertů (70+ communication)
- Email notifikace pro alerty
- Weather forecast v ročním grafu zatím = PVGIS proxy (ne skutečné měsíční weather data)
- VPN přístup k Logger1000 → Sungrow Modbus → InfluxDB

## Technické poznámky

### Open-Meteo integrace
- Endpoint: `api.open-meteo.com/v1/forecast`
- Parametry: `global_tilted_irradiance`, `tilt`, `azimuth` (z plant_sections)
- Performance ratio PR = 0.80
- forecast_days=4, timezone=Europe/Prague
- Server používá cURL (ne file_get_contents)

### DB klíčové
- plants: + 19 OTE/ERÚ metadata + underperform_threshold DECIMAL(3,2) DEFAULT 0.70
- alerts: type ENUM('underperform','offline','communication','manual')
- production_realtime: 15min snapshoty, cleanup 7 dní, sloupec `ts`
- pvgis_monthly: sloupce e_m_kwh (měsíc), e_d_kwh (den), h_i_d, h_i_m, sd_m_kwh
- plant_sections: tilt_deg, azimuth_deg, power_share_pct (pro GTI výpočet)

### Workflow
- Aliasy: fv-save, fv-commit, fv-push, fv-log
- DB: mysql fve_monitor (bez hesla přes /root/.my.cnf)
- GitHub: git@github.com:davesunlai/fve-monitor.git
- Project knowledge v Claude: aktualizovat ručně na začátku nového chatu
