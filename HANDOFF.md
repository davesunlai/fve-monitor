# FVE Monitor — aktuální stav (handoff k novému chatu)

**Datum**: 2026-05-01
**Aktuální verze**: v0.62
**Posledni commit**: roční graf plnění FVE (12×10 sloupců)

## Co je hotové

### Dashboard (/index.php)
- Tabulkový přehled 9 FVE s live daty
- Detail FVE: roční přehled, 4denní graf výkonu, mapa Leaflet
- Hamburger menu (sdílený _topbar.php)
- Push notifikace, alert badge, last update time

### Comparison (/comparison.php)
- Denní srovnání FVE — kalendář s buňkami
- 3 režimy: denní průměr, měsíční Ø všech, vlastní průměr FVE
- 5stupňové barvy (POD/normál/NAD průměrem)
- Klikatelné buňky → modal s 4 stat kartami + 15min daty

### Performance (/performance.php) — NOVĚ
- Tabulka 9 FVE seřazených podle plnění %
- Sloupce: Reálná výroba, PVGIS, Plnění %, Productivity, Σ kumulativní, Roční plnění %
- Sparkline 12 měsíců
- Roční graf: 120 sloupců (12 měsíců × 10 = 9 FVE + PVGIS)

### Admin
- /admin/alert_settings.php — per-FVE underperform threshold + ▶️ Test tlačítko
- /admin/plants_ote.php — OTE/ERÚ metadata, sticky první sloupec
- /admin/ote_report.php — měsíční výkaz CSV/XML
- /admin/import_csv.php — 3-fázový workflow

### DB metadata (Monkstone licence ERÚ 112441941)
- Všech 9 FVE: evid_number, ote_vyrobna_id, ean_vyrobny, license_number, ico
- Adresa, katastr, parcely, datumy uvedení

### Refactoring
- _app_head.php (sdílený <head> + meta + manifest + Chart.js + Leaflet)
- _topbar.php (sdílený hamburger menu + aktivní stránka)

## Co chybí

### OTE/ERÚ data
- **OTE_ID** (registrační ID v OTE/CDS): vyplněno jen 2/9 (Plzeň, Mladá Bol.)
  - Zbývá: Frýdek, Ostrava, Pardubice, Staré Město, Zlín, Vestec, Č.Lípa
  - Zdroj: OTE portál CDS (po přihlášení)
- **EAN_OPM** (z faktur distributora, 18-místný): vyplněno 3/9
  - Zbývá: Frýdek, Pardubice, Staré Město, Zlín, Vestec, Č.Lípa
  - Zdroj: faktura distributora (ČEZ, EG.D, Teplárna Zlín)

### Funkce
- OTE měsíční výkaz pro duben 2026 (deadline 10.5.) — netestováno
- Cleanup nepotvrzených alertů (70+ communication)
- Email notifikace pro alerty
- 15min graf výkonu v modal comparison (Fáze B)

## Test cíle pro nový chat

1. Ověř: po refactoru funguje dashboard, comparison, performance
2. Ověř: admin stránky funkční (login, alert_settings, plants_ote)
3. Otestuj OTE měsíční výkaz pro duben 2026
4. Doplň zbývající OTE_ID a EAN_OPM (až seženeš data)

## Kontakt na databázi

```bash
mysql fve_monitor                  # interaktivní
mysql -e "SELECT ... FROM plants"  # one-shot
```

## Související
- Hlavní CHANGELOG.md v rootu
- Sungrow AppKey: D2A6FCFDBF6D56E54ECFEE2876EC391A (EU region)
