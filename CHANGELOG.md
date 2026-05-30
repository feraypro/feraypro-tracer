# Changelog

All notable changes to FerayPro Tracer are documented here.

---

## [1.8.0] — 2026-05

### Added — Commission & Invoicing Module

- **Commission calculation engine** — automatic 20/80 split on every collected batch
  - Acheteur pays vendor 80% directly
  - Acheteur transfers 20% to FerayPro (commission)
  - FerayPro redistributes: 10% net + 10% to marketing partner (if `_fpt_ref` present)
- **Price field in collection metabox** — admin enters lot price after confirming collection; saved via AJAX (`action=fpt_save_prix`) without triggering `save_post` (prevents HivePress from wiping buyer field)
- **PDF invoice generator** — accessible via `admin-ajax.php?action=fpt_facture&fpt_lot=ID&fpt_tok=TOKEN`
  - Full A4 page layout (`@page { size: A4; margin: 0 }`) — fills entire PDF
  - Uses WordPress `custom_logo` (theme mod) or site favicon + name — no hardcoded logo
  - Displays: lot details, weight, price, 80/20 split, TVA line, payment instructions (IBAN + WhatsApp)
  - Partner commission block (10%) shown if referral partner exists
  - No payment status on invoice — invoice is sent to request payment
- **"Marquer comme payée" button** — AJAX (`action=fpt_mark_paid_ajax`); displays paid date in metabox after confirmation
- **World currency selector** — 50+ currencies (MAD, EUR, USD, CDF, XOF, XAF, GBP, NGN, KES, ZAR, INR, BRL, SAR, AED…) configurable per site in **FP Tracer → ⚙️ Paramètres**
  - Auto-detection by domain if not configured (`.fr` → EUR, `.cd`/`.us` → USD, default → MAD)
- **TVA field** — configurable rate per site (0 = exonéré; France: 20%; DRC: 16%); shown on invoice
- **IBAN / Mobile Money / Address fields** — appear on invoice footer under "Mode de règlement"
- **Improved `fpt_get_acheteurs()`** — supports comma-separated slugs (e.g. `acheteurs,buyers`); auto-discovers buyer categories containing "achet" or "buyer" if configured slug returns empty (fixes blank buyer list on USA site)

### Changed
- `fpt_get_prix_lot()` reads `_fpt_prix_lot` (dedicated meta) first, then falls back to HivePress `hp_{key}` field
- Commission/invoice settings merged into existing **FP Tracer → ⚙️ Paramètres** form — single save button, no new admin menu
- Invoice link uses `admin-ajax.php` — bypasses WordPress permalink rewriting that caused homepage redirect

### Fixed
- **Buyer field wiped on price save** — price now saved via AJAX, not via `save_post`, so HivePress never re-processes the metabox
- **Invoice page showed homepage** — was using `home_url(?fpt_invoice=)` which got caught by permalink router; switched to `admin-ajax.php`
- **Stale orphan code** — multiple leftover function bodies from iterative development removed; single clean `fpt_afficher_facture()` function

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
- **Fatal error: `fpt_normalize_text()` called before definition** — moved to line 18
- **`fpt_mask_phone()` / `fpt_whatsapp_btn()` missing** — confirmed intact; phone masking restored

### Added
- **`fpt_get_population_density_multiplier()`** — country-aware ERRI density multiplier
- **Health keywords extended to EN** — all four pollutant keyword arrays now include English terms
- **Independent co-occurrence** — each pollutant analyzed with its own loop

### Changed
- METHODOLOGY.md and README.md CO₂ tables expanded to 42 materials

---

## [1.7.1] — 2026

### Fixed
- **Health co-occurrence bug** — each pollutant analyzed independently per batch
- **ERRI label harmonized** to English consistently

### Added
- `fpt_get_population_density_multiplier()` — Phase 2 geospatial ERRI coefficient
- `fpt_normalize_text()` — multilingual NLP: Darija, Lingala, Swahili

---

## [1.7.0] — 2026

### Added
- Partner tracking (`?ref=`), `[fpt_partenaires]`, buyer dashboard, collection metabox
- `fpt_co2_process_factors()`, `fpt_calculate_process_co2()`

---

## [1.6.2] — 2026
- Buyer dashboard real-time calculation fix
- CO₂ transport formula bug fix (kg vs tonnes)

---

## [1.5.x] — 2026
- ERRI scientific reframe, best-match Prix du jour scoring, dynamic weight unit

---

## [1.4.x] — 2026
- Prix du jour block, configurable field names and slugs

---

## [1.3.x] — 2026
- Bilingual FR/EN (200+ English keywords), lb→kg conversion

---

## [1.2.0] — 2026
- Lead, PM2.5, Cadmium, Mercury ERRI indicators

---

## [1.1.x] — 2026
- Auto-inject CO₂ block, FP Tracer Settings panel, `[fpt_methodologie]`

---

## [1.0.0] — 2026
- Initial release: CO₂ engine, QR code, `[fpt_dashboard]`, `[fpt_lot]`

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

*FerayPro Tracer v1.8.0 — Open Source MIT — [github.com/feraypro/feraypro-tracer](https://github.com/feraypro/feraypro-tracer)*
