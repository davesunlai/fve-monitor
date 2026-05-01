# CHANGELOG

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
