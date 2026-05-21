# Changelog

All notable changes to FerayPro Tracer are documented here.

---

## [1.7.3] — 2026

### Added
- **Partner affiliate tracking system** — full implementation
  - `?ref=slug` cookie capture (30 days) — only registered active partners are tracked
  - Auto-attach `_fpt_ref` to listing post meta on publish (first-click wins, never overwritten)
  - **Partner banner on listing page** — displayed first, above all content, with partner logo and brand color
  - Logo upload via WordPress media library (replaces manual URL field)
  - Admin page **FP Tracer → 🤝 Partenaires** — add/edit/delete partners, per-partner stats (lots, collected, kg, CO₂, commission %)
  - Copy-ready referral link per partner
  - Active/inactive toggle — inactive partners' `?ref=` links are silently ignored
  - `[fpt_partenaires]` shortcode — public partner grid with logos, batch count, CO₂ avoided
  - `fpt_get_partenaires_list()`, `fpt_get_partenaire_by_slug()`, `fpt_get_stats_partenaire()` helpers

### Fixed
- **CSS loading failure** — `FPT_PLUGIN_DIR` and `FPT_PLUGIN_URL` were defined at line 894 (mid-file, after function closures) — moved to lines 14–16, immediately after `FPT_VERSION`, before any hook registration

---

## [1.7.2] — 2026

### Fixed
- **Fatal error: `fpt_normalize_text()` called before definition** — function was defined at line ~1427 but called at line ~283; moved to line 18, immediately after `define('FPT_VERSION')`
- **Fatal error: `fpt_key_poids()` missing** in problem version — confirmed intact
- **`fpt_mask_phone()` / `fpt_whatsapp_btn()` missing** in problem version — confirmed intact; phone masking restored

### Added
- **`fpt_get_population_density_multiplier()`** — country-aware ERRI density multiplier (was missing from deployed version)
- **Health keywords extended to EN** — all four pollutant keyword arrays now include English terms (lead, battery, wire, copper, screen, lamp…)
- **Independent co-occurrence** — each pollutant analyzed with its own `foreach`/`break` loop; a batch can contribute to multiple pollutants simultaneously

### Changed
- METHODOLOGY.md CO₂ factors table expanded from 19 to 42 materials — aligned with full `fpt_co2_factors()` array in PHP
- README.md CO₂ table expanded from 6 to 32 materials — aligned with PHP
- All versions bumped to 1.7.2

---

## [1.7.1] — 2026

### Fixed — Critical bugs (audit-ready)
- **Version harmonized to 1.7.1** across plugin header, PHP constant, README, METHODOLOGY
- **Health co-occurrence bug fixed** — each pollutant (Lead, PM2.5, Cadmium, Mercury) now analyzed **independently** per batch — a batch titled "Lead paint and batteries" now correctly contributes to BOTH lead AND cadmium indicators simultaneously
- **ERRI label harmonized** — English `ERRI` (Exposure Risk Reduction Index) used consistently in code comments, dashboard, and documentation

### Added
- **`fpt_get_population_density_multiplier()`** — Phase 2 geospatial ERRI coefficient by country
  - Morocco: ×1.2 (HCP coastal urban density)
  - DRC: ×1.8 (Kinshasa informal hub density)
  - Senegal: ×1.3 · Nigeria: ×1.5 · Kenya: ×1.2 · France/USA: ×0.7–0.8
- **`fpt_normalize_text()`** — multilingual NLP normalization replaces `strtolower+remove_accents`
  - Darija (Morocco): خردة→ferraille, نحاس→cuivre, حديد→fer, بطارية→batterie
  - Lingala (DRC): singa→cable, likoxi→cuivre
  - Swahili (DRC East/Kenya): chuma→fer, shaba→cuivre, betri→batterie
  - Applied to all material detection: CO₂ factors, Prix du jour, health indicators

### Changed
- **METHODOLOGY.md fully rewritten** to match code v1.7.1
  - All CO₂ factors now show net gain values (Primary − Recycled) with source table
  - ERRI formula documented with density multiplier
  - Co-occurrence fix documented
  - Version aligned to 1.7.1

---

## [1.7.0] — 2026

### Changed
- Transport CO₂ completely removed from all displays
- Phone number masking removed (caused critical PHP error)

### Added
- Partner tracking system (`?ref=company-name`) — 30-day cookie, auto-tag on publish
- `[fpt_partenaires]` shortcode — partner traffic source dashboard
- `fpt_co2_process_factors()` — FEDEREC/ADEME LCA 2017 recycling process emission factors
- `fpt_calculate_process_co2()` — CO₂ produced by recycler (buyer dashboard)
- Collection confirmation metabox (admin only) — buyer selection, date, CO₂ record
- `[fpt_acheteur id="XXX"]` — buyer dashboard with real-time CO₂ process calculation
- Status badge on listing cards (JS injection via wp_footer)
- Label fix: "Poids collecté" → "Poids à collecter"

---

## [1.6.2] — 2026

### Fixed
- Buyer dashboard real-time calculation (no more cached meta)
- CO₂ transport formula bug: was using kg instead of tonnes (×1000 error)

---

## [1.5.x] — 2026

- Scientific language reframe: "Children Protected" → ERRI
- Best-match scoring for Prix du jour
- CO₂ calculation detail bar on listings
- Dynamic weight unit (kg/lb)

---

## [1.4.x] — 2026

- Prix du jour block on seller listings
- Configurable field names, category slugs, weight units
- Images in Prix du jour description

---

## [1.3.x] — 2026

- Bilingual FR/EN (200+ English keywords)
- Configurable HivePress field names
- lb → kg auto-conversion

---

## [1.2.0] — 2026

- Pollutant Exposure Risk Reduction Indicators (Lead, PM2.5, Cadmium, Mercury)
- ERRI calculation

---

## [1.1.x] — 2026

- Auto-inject CO₂ block on listing pages
- FP Tracer Settings admin panel
- `[fpt_methodologie]` shortcode

---

## [1.0.0] — 2026

- Initial release: CO₂ engine (ADEME net gain factors), QR code, `[fpt_dashboard]`, `[fpt_lot]`

---

## Planned — [2.0.0]
- [ ] Random Forest ML (Morocco + DRC field data)
- [ ] Statistical confidence intervals
- [ ] Geospatial impact map
- [ ] Full Arabic, Lingala, Swahili keyword vocabularies
- [ ] TF-IDF fallback classification
- [ ] Export API for governments/NGOs
- [ ] GitHub auto-update across all country sites

---

*FerayPro Tracer v1.7.3 — Open Source MIT — [github.com/feraypro/feraypro-tracer](https://github.com/feraypro/feraypro-tracer)*
