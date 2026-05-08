# Changelog

All notable changes to FerayPro Tracer are documented here.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [1.3.1] — 2026

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
