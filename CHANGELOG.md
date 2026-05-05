# CHANGELOG

## v0.62 — 2026-05-01 — Roční graf plnění FVE

### Přidáno
- 📊 **Roční graf** v `/performance.php` pod tabulkou
  - 120 sloupců (12 měsíců × 10 = 9 FVE + PVGIS reference)
  - Y-osa: % plnění (PVGIS = 100%)
  - X-osa: pod každým měsícem `1 2 3 4 5 6 7 8 9 P`
  - Popisky měsíců pod grafem (žluté capitalize)
  - Legenda s evid čísly = názvy FVE
  - Tooltip při hover: měsíc + FVE + plnění %

### Opraveno
- Chart.js wrapper s `position:relative` + pevnou výškou 400px
  (zabránění nekonečnému nafukování canvasu)

---

## v0.61 — 2026-05-01 — Refactor + nová stránka Plnění FVE

### Přidáno
- 📊 **Nová stránka `/performance.php`** — Plnění FVE vs PVGIS
  - Tabulka s 9 FVE seřazenými podle plnění % (nejlepší nahoře)
  - Sloupce: Reálná výroba, PVGIS predikce, Plnění %, Productivity, Σ kumulativní, Roční plnění %
  - Sparkline 12 měsíců s plněním v %
  - Filter: rok (tlačítka) + měsíc (dropdown)
- 🧩 **Refactor**: vytvořeny společné include `_app_head.php` + `_topbar.php`
- ✨ Aktivní položka v hamburger menu zvýrazněna (`.menu-item-active`)

### Opraveno
- Duplicitní načítání `app.js` v index.php
- Hlavičky tabulek bez `text-transform: uppercase` (jednotky `kWh` viditelné správně)

### DB
- Kompletní data licence ERÚ 112441941 pro všech 9 Monkstone FVE
  - evid_number, ote_vyrobna_id, ean_vyrobny, license_number, ico
  - Adresa, katastr, parcely, datumy uvedení do provozu

---

## v0.57 — 2026-05-01 — Test alert tlačítko

### Přidáno
- 🧪 `Predictor::computeAlertStats()` — výpočet bez side-efektů
- API endpoint `?action=test_alert&plant=X`
- ▶️ Test tlačítko v `/admin/alert_settings.php`
- Modal s verdiktem (zelený/červený), 4 stat karty, per-day breakdown 7 dní

---

## v0.56 — 2026-04-30 — Per-FVE underperform threshold

### Přidáno
- DB: `plants.underperform_threshold` (DECIMAL 0.0-1.0, default 0.70)
- Predictor čte threshold per FVE (fallback na globální config)
- 🆕 `/admin/alert_settings.php` — admin stránka pro nastavení per FVE
  - Inline editace, kontext (7 dní výroby), preset 30/40/50/70/90 %
  - Indikátor podpory FVE

### Důvod
- Méně falešných underperform alarmů pro Albert FVE (omezené přebytky 30-60% PVGIS)

---

## v0.55 — 2026-04-30 — 4denní graf na celou šířku

### Změna
- Detail FVE: graf zabírá celou šířku (`grid-column: 1 / -1`)
- 4denní průběh výkonu místo 48h (96 hodin)

---

## v0.50 — 2026-04-30 — 3 režimy porovnání FVE

### Přidáno
- Comparison stránka má 3 režimy:
  1. Denní průměr (proti dennímu Ø všech FVE)
  2. Měsíční průměr (proti měsíčnímu Ø všech)
  3. Vlastní průměr FVE (každá proti sobě)

---

## v0.43 — 2026-04-29 — Oprava výpočtu daily energy

### Opraveno
- `fetch_daily.php` filtr `power_kw >= 1` (vyřazuje noční šum)
- Anomálie v Sungrow API už neprochází do production_daily

---

## v0.41 — 2026-04-29 — Klikatelné buňky s detail modálem

### Přidáno
- Klik na buňku v comparison.php otevře modal
- 4 stat karty + Porovnání denní/měsíční + 15min data
- API endpoint `?action=day_realtime&plant=X&date=Y`

---

## v0.30 — 2026-04-28 — Comparison stránka + verze

### Přidáno
- 📊 `/comparison.php` — denní srovnání FVE
- Konfigurovatelný toast text z `version.json`
- Patička s verzí (`renderVersionFooter`)

---

(starší verze: viz git log)

---

## v0.63 — 2026-05-01 — Predikce počasí v grafech detailu FVE

### Přidáno
- 🌤️ **Nový API endpoint `weather_prediction`**
  - Open-Meteo hourly forecast (DNI + DHI → kW přes kWp + efficiency)
  - PVGIS denní profil (sinusový tvar z měsíčního průměru)
- 📈 **96h graf výkonu**: 2 nové datasety
  - `PVGIS průměr` — šedá přerušovaná linka (průměrný den dle PVGIS)
  - `Předpověď počasí` — modrá přerušovaná linka (jen pro budoucí hodiny)
- 📊 **Roční graf**: linka `Výhled (PVGIS)` pro aktuální + budoucí měsíce

### Technické
- PHP cURL místo file_get_contents (allow_url_fopen=Off na serveru)
- Open-Meteo timezone=Europe/Prague, 4 dny = přesně rozsah 96h grafu

---

## v0.64 — 2026-05-01 — Drobné úpravy UX

### Přidáno
- 🏠 Nadpis v topbaru je odkaz na dashboard (všechny stránky)
- 📅 Podtitul 96h grafu s rozsahem dat (např. "Po 28.4. — Ne 3.5., 4 dny historie + 2 dny předpověď")

---

## v0.66 — 2026-05-02 — Weather forecast widget + OTE RESDATA validní

### Přidáno
- 🌤️ **Předpověď počasí přímo v dashboard tabulce** — nový sloupec "Předpověď"
  - 3 dny dopředu (Dnes, Zítra, Pozítří)
  - Ikona počasí (WMO weather code → emoji)
  - Maximální teplota
  - Odhadovaná denní výroba (kWh) z radiace
  - Tooltip s detaily

### API
- Nový endpoint `weather_summary` — Open-Meteo daily forecast pro všechny aktivní FVE
- Použití: `daily=weather_code,temperature_2m_max,shortwave_radiation_sum`
- Odhad výroby: rad[MJ/m²] × kWp × PR(0.8) / 3.6

### OTE RESDATA — VALIDNÍ FORMÁT! 🎉
- `message-code="PD1"` (POZE měsíční výkaz, NE TD1!)
- ROOT atributy: `id`, `date-time`, `dtd-version="1"`, `dtd-release="1"`, `answer-required="0"`, `language="CS"`
- `SenderIdentification` s EAN13 8591824648933 + `coding-scheme="14"`
- `ReceiverIdentification` s OTE EAN 8591824000007 + `coding-scheme="14"`
- `unit="MWH"` velkými písmeny (ne MWh!)
- `date-from` / `date-to` (xsd:date) na Location
- ✅ XML validně přijat OTE portálem

### Pending
- Bump verze na sw.js + version.json
- Komit + push

---

## v0.67 — 2026-05-03 — OTE RESDATA PD1/PDR + login redirect

### Přidáno
- 🔘 **Dropdown výběr message-code** v OTE export panelu (PD1 / PDR)
  - PD1 = Měsíční výkaz výroby z OZE (s podporou)
  - PDR = Data svorkové výroby vnořeného výrobce (bez podpory)
  - Filename obsahuje msg_code: RESDATA_PD1_2026_04.xml

### Opraveno
- 🔐 Přesměrování na login pro neautorizované přístupy na comparison.php a performance.php
