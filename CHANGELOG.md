# Changelog

All notable changes to FerayPro Tracer are documented here.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [1.4.5] — 2026

### Added
- **Dynamic weight unit throughout all displays** — weight unit (kg or lb) now adapts automatically everywhere based on the configured setting:
  - Lot card: "11 kg collectés" / "24 lb collected"
  - Dashboard waste collected card
  - Inline block on listing pages
  - Recent lots list
  - Methodology page formula and example
- `fpt_display_weight()` helper: converts stored kg value to lb for display when unit = lb
- `fpt_weight_unit_label()` helper: returns `kg` or `lb`
- Methodology formula now shows correct unit + conversion note for lb sites
- Methodology example uses 24 lb (copper) on lb sites, 11 kg on kg sites

### Note
Child health indicators (lead, PM2.5, cadmium, mercury) always display in scientific units (kg/g) regardless of weight unit setting — these are WHO/EPA/UNEP standardized units and converting to lb would be scientifically incorrect.

---

## [1.4.4] — 2026

### Fixed
- **Critical**: `hp_buyersprice` field (US sites) was not being read for the Prix du jour description — added as configurable fallback when `post_content` is empty
- **Critical**: `price-2` field name was being sanitized by `sanitize_key()` to `price2` — corrected to use `price_2` (WordPress stores with underscore, not hyphen)

### Added
- **Buyers list field name** configurable in admin settings (`fpt_key_buyersprice`)
  - French sites: leave empty (uses post_content description)
  - English/US sites: `buyersprice`
- Description fallback chain: `post_content` → `hp_{buyersprice_key}`

---

## [1.4.3] — 2026

### Added
- **Prix/kg unit label** displayed alongside price range in Prix du jour block
  - Automatically shows `/kg` or `/lb` based on configured weight unit
- **Prix/kg field name** configurable in admin settings (`fpt_key_prix_jour`)
  - 🇫🇷 `prix` · 🇬🇧 `price` · 🇺🇸 `price_2`
- **Buyers list field name** configurable (`fpt_key_buyersprice`)

---

## [1.4.2] — 2026

### Added
- **Prix du jour category slug** configurable in admin settings (`fpt_prix_cat_slug`)
  - 🇫🇷 `prix` · 🇬🇧/🇺🇸 `price`
- Bilingual title matching for Prix du jour: detects both "Prix du CUIVRE" and "Copper Price" via shared keyword (`cuivre`/`copper`)

### Fixed
- Prix du jour was hardcoded to slug `prix` — now configurable per country site

---

## [1.4.1] — 2026

### Fixed
- **Images in Prix du jour description** were stripped by `strip_tags()` leaving blank spaces — replaced with `wp_kses()` which allows safe HTML including `<img>`, `<p>`, `<ul>`, `<table>`, `<figure>`
- Added CSS rules for images inside `.fpt-prix-desc`: `max-width:100%`, `height:auto`, rounded corners

---

## [1.4.0] — 2026

### Added
- **Prix du jour block** — automatically displays today's buyer prices on each seller listing:
  - Detects material type from listing title (same keyword engine as CO₂ calculation)
  - Finds matching "Prix du jour" listing in the configured price category
  - Displays: listing title, price range (hp_prix field), full description (acheteurs list), last updated date, link to full price page
  - Bilingual labels (FR/EN) following configured language
  - Orange color scheme to visually distinguish from green CO₂ block
- `fpt_get_prix_du_jour()` function added

---

## [1.3.1] — 2026

### Fixed
- Replaced all hardcoded HivePress meta keys with configurable helper functions
- Added `fpt_get_poids_kg()` for automatic lb → kg conversion
- Fixed meta key reading in inject function and `save_post` hook

---

## [1.3.0] — 2026

### Added
- Bilingual support FR/EN for all dashboard labels
- Language selector in admin settings
- 200+ English keywords for CO₂ detection
- Configurable field names per country site
- Configurable weight unit (kg/lb) with automatic conversion

---

## [1.2.0] — 2026

### Added
- Section 2 — Child Health Impact (Lead, PM2.5, Cadmium, Mercury)
- Children Protected synthetic estimate
- `fpt_calculate_health()` function

---

## [1.1.2] — 2026

### Fixed
- Health section CSS grid overridden by ListingHive — migrated to inline styles

---

## [1.1.1] — 2026

### Fixed
- `fpt_recalculate_global_stats()` now processes ALL listings, not just already-traced ones
- HivePress meta key prefix confirmed as `hp_`

---

## [1.1.0] — 2026

### Added
- Auto-inject CO₂ block on every listing page
- FP Tracer Settings panel (platform name, country, language)
- `[fpt_methodologie]` shortcode

---

## [1.0.0] — 2026

### Initial Release
- CO₂ calculation engine (50+ materials, ADEME Base Carbone)
- QR code generation per batch
- Digital Batch ID (FP-XXXXXXXX)
- `[fpt_dashboard]` and `[fpt_lot]` shortcodes
- Admin panel with recalculate function

---

## Planned — [2.0.0]

- [ ] Random Forest ML model (Morocco + DRC field data)
- [ ] Statistical confidence intervals
- [ ] Geospatial health model
- [ ] Interactive impact map
- [ ] Arabic, Lingala, Swahili keywords
- [ ] TF-IDF fallback classification
- [ ] Export API for governments/NGOs

---

*FerayPro Tracer — Open Source MIT — [github.com/feraypro/feraypro-tracer](https://github.com/feraypro/feraypro-tracer)*


### Fixed
- Replaced all hardcoded HivePress meta keys (`hp_poids`, `hp_ville`, etc.) with configurable helper functions (`fpt_key_poids()`, `fpt_key_ville()`, etc.)
- Added `fpt_get_poids_kg()` helper for automatic lb → kg conversion
- Fixed meta key reading in inject function and `save_post` hook
- Fixed remaining hardcoded `hp_poids` in `meta_query` of health calculation

### Changed
- All field names now fully configurable from FP Tracer → Settings
- Weight unit (kg/lb) configurable — automatic conversion applied to all calculations

---

## [1.3.0] — 2026

### Added
- **Bilingual support (FR/EN)**: all dashboard labels, inline block text, health section, and recent lots are now translated based on the configured language setting
- **Language selector** in admin settings (🇫🇷 Français / 🇬🇧 English)
- **200+ English keywords** added to CO₂ factor detection: copper, scrap, wire, battery, phone, washing machine, truck, catalytic converter, and many more
- **Configurable field names** in admin: weight, city, phone, price field names can be set independently per country site
- **Configurable weight unit**: kg (default) or lb (USA) — automatic conversion to kg for all calculations
- New CO₂ keywords: aluminum, steel, iron, engine, motor, vehicle, car, truck, electric, electronics, computer, laptop, server, screen, monitor, television, cable, wire, battery, lithium, phone, smartphone, washing machine, dryer, refrigerator, air conditioner, solar panel, paper, cardboard, plastic, rubber, tire, glass, and 100+ more

### Changed
- Admin settings panel expanded with field name configuration section
- Country subtitle now uses correct preposition per language ("au Maroc" / "in Morocco")
- Dashboard source link now dynamically reads the current site's hostname

---

## [1.2.0] — 2026

### Added
- **Section 2 — Child Health Impact**: four new indicators calculated automatically
  - 🔴 Lead not dispersed (kg) — factor: Weight (t) × 0.5 — Source: Pure Earth 2016, WHO 2021
  - ☁️ PM2.5 avoided (kg) — factor: Weight (t) × 15 — Source: EPA AP-42 2022
  - ⚠️ Cadmium avoided (g) — factor: Weight (t) × 200 — Source: Pure Earth 2020, UNEP 2018
  - 🧠 Mercury avoided (g) — factor: Weight (t) × 50 — Source: UNEP Minamata 2018
- **Children Protected** synthetic estimate: (Lead kg × 50) + (PM2.5 kg × 10) — Source: WHO GHO 2021, HEI 2020
- Health section displayed in dashboard with inline `<style>` tags to override theme CSS
- Health calculation function `fpt_calculate_health()` added
- Health indicators stored per listing as post meta

### Fixed
- Dashboard health grid now renders correctly regardless of theme CSS (inline styles)
- Section 2 cards display in 4-column grid layout matching Section 1

---

## [1.1.2] — 2026

### Fixed
- Health section CSS grid overridden by ListingHive theme — migrated to inline `<style>` block in shortcode output
- All CSS rules updated with `!important` declarations for theme compatibility

---

## [1.1.1] — 2026

### Fixed
- **Critical**: `fpt_recalculate_global_stats()` was only processing listings that already had `_fpt_co2_avoided` meta — now processes ALL published `hp_listing` posts with non-zero weight
- Existing listings (created before plugin installation) now correctly traced on Recalculate
- HivePress meta key prefix confirmed as `hp_` — all meta keys updated from `poids` to `hp_poids`, `ville` to `hp_ville`, `whatsapp` to `hp_whatsapp`

---

## [1.1.0] — 2026

### Added
- **Auto-inject on listing pages**: CO₂ block with QR code automatically appended to every `hp_listing` single page with non-zero weight (no shortcode needed)
- Auto-tracing of existing listings on first page view (retroactive calculation)
- **FP Tracer → Settings** panel: configurable platform name, country name
- Dynamic dashboard title and subtitle using configured country name
- Dashboard source link dynamically reads current hostname
- `[fpt_methodologie]` shortcode added (methodology page)

### Changed
- Plugin URI updated to `ma.feraypro.com/impact`
- Version constant updated to `1.1.0`

---

## [1.0.0] — 2026

### Initial Release

#### Features
- **Custom Post Type hook**: triggers on `hp_listing` publish/update in HivePress
- **CO₂ calculation engine**: 50+ material keywords mapped to ADEME Base Carbone factors
- **QR code generation**: via QRServer free API (`api.qrserver.com`)
- **Unique Digital Batch ID**: format `FP-XXXXXXXX` (8-char MD5 hash of post ID + title)
- **Global stats**: `fpt_total_co₂`, `fpt_total_poids`, `fpt_total_lots` stored as WordPress options
- **`[fpt_dashboard]` shortcode**: displays global impact stats with equivalents (trees/year)
- **`[fpt_lot]` shortcode**: displays individual batch traceability page with QR code, photo, stats
- **Admin panel** (FP Tracer menu): stats overview, shortcode reference, manual recalculate, CO₂ factor table
- **Frontend CSS** (`assets/tracer.css`): DM Sans + Space Mono typography, green brand colors

#### CO₂ Factors (v1.0)
Aluminium (9.5), Cuivre (3.5), Bronze (3.2), Laiton (3.0), Inox (2.5), Nickel (6.5), Titane (5.0), Magnésium (7.0), Zinc (2.0), Plomb (1.2), Fer/Acier/Ferraille (1.8), Fonte (1.6), Moteur électrique (3.5), E-waste (4.0), Batterie lithium (5.0), Batterie plomb (2.5), Papier/Carton (0.9), PET (1.5), HDPE (1.4), Pneus (1.2), Verre (0.3), Défaut (1.0)

---

## Planned — [2.0.0]

### Phase 2 Features
- [ ] Random Forest ML model for local CO₂ factor refinement (Morocco + DRC field data)
- [ ] Statistical confidence intervals on all impact estimates
- [ ] Geospatial health model with distance-decay functions
- [ ] Interactive impact map (feraypro.com/impact/map)
- [ ] Arabic keyword support (Morocco)
- [ ] Lingala keyword support (DRC — Kinshasa)
- [ ] Swahili keyword support (DRC — East, Kenya)
- [ ] TF-IDF fallback classification for unrecognized titles
- [ ] Export API for governments and NGOs
- [ ] GitHub Actions CI for keyword validation

---

*FerayPro Tracer — Open Source MIT — [github.com/feraypro/feraypro-tracer](https://github.com/feraypro/feraypro-tracer)*
