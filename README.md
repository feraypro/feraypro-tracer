# FerayPro Tracer

**Open-source waste batch traceability plugin for WordPress / HivePress**

Automatically calculates CO₂ avoided, child health impact, commission invoicing, Stripe online payment, and financial reporting for every recycled waste batch. Built for [FerayPro Morocco](https://ma.feraypro.com), [FerayPro DRC](https://cd.feraypro.com), [FerayPro France](https://fr.feraypro.com), and [FerayPro USA](https://feraypro.com) — a circular waste marketplace operating globally.

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Version](https://img.shields.io/badge/Version-2.3.0-blue.svg)](CHANGELOG.md)
[![UNICEF Venture Fund](https://img.shields.io/badge/UNICEF-Venture%20Fund%202026-00aeef.svg)](https://unicefinnovationfund.org)

---

## 📁 Structure du plugin

```
feraypro-tracer/
├── feraypro-tracer.php          ← Plugin principal (CO₂, lots, factures, partenaires)
├── tracer.css                   ← Styles publics (blocs inline, lot card, dashboard CO₂)
├── modules/
│   ├── admin/
│   │   └── admin.css            ← Styles du panneau d'administration
│   ├── finance/
│   │   ├── finance.php          ← Module dashboard financier [fpt_dashboard_finance]
│   │   └── finance.css          ← Styles du dashboard financier
│   ├── stripe/
│   │   └── stripe.php           ← Module paiement Stripe
│   └── ai/
│       ├── ai.php                       ← Module Intelligence Artificielle (classification, ERRI, prix, descriptions)
│       └── ai-buyer-matching.php        ← Module Matching Acheteurs (classement par localisation + prix)
├── CHANGELOG.md
└── README.md
```

> **Architecture modulaire** : `feraypro-tracer.php` charge automatiquement tous les modules via `require_once` au démarrage. Ajouter un module = créer un dossier dans `modules/` et l'enregistrer dans le `require_once` du plugin principal. Les modules communiquent avec le plugin principal via des `do_action` hooks — aucune modification invasive du core.

---

## 🌍 Live Demo

| Site | Dashboard CO₂ | Dashboard Finance |
|------|--------------|-------------------|
| Morocco | [ma.feraypro.com/impact](https://ma.feraypro.com/impact) | [ma.feraypro.com/finance](https://ma.feraypro.com/finance) |
| DR Congo | [cd.feraypro.com/impact](https://cd.feraypro.com/impact) | [cd.feraypro.com/finance](https://cd.feraypro.com/finance) |
| France | [fr.feraypro.com/impact](https://fr.feraypro.com/impact) | [fr.feraypro.com/finance](https://fr.feraypro.com/finance) |
| USA | [feraypro.com/impact](https://feraypro.com/impact) | [feraypro.com/finance](https://feraypro.com/finance) |

---

## 🌱 What It Does

When a seller publishes a waste listing on HivePress / ListingHive, the plugin automatically:

1. **Detects the material type** using `fpt_normalize_text()` — 200+ bilingual keywords (FR + EN) + Darija, Lingala, Swahili NLP transliteration — or via **AI classification** (Claude Haiku) when the AI module is enabled, with automatic fallback to keyword detection
2. **Calculates CO₂ net gain** using ADEME Base Carbone / FEDEREC ACV 2017 net gain factors (Primary − Recycled) — **universal, same for all countries**
3. **Adjusts CO₂ process** using `fpt_grid_intensity()` — local electricity grid carbon intensity (IEA 2024, EPA eGRID 2023, ONEE/MASEN 2024) — **buyer dashboard only**
4. **Calculates ERRI** (Exposure Risk Reduction Index) — Lead, PM2.5, Cadmium, Mercury — with a population density multiplier, either fixed per country or **micro-local per neighborhood** (AI-evaluated) when the AI module is enabled
5. **Generates a QR code** linking to the public batch traceability page
6. **Updates the live impact dashboard** with cumulative totals and net CO₂ balance
7. **Generates a commission invoice** (PDF) when the batch is collected — 20% to FerayPro, 80% to vendor
8. **Accepts online payment** via Stripe Checkout — automatic confirmation via webhook
9. **Tracks marketing partner referrals** via `?ref=` cookie system
10. **Reports financial KPIs** via the Finance Dashboard module
11. **Matches today's prices semantically** via AI when keyword scoring finds no match — and **auto-generates a professional listing description** from minimal user input, when the AI module is enabled
12. **Ranks partner buyers by relevance** for each waste listing — location first, price as tiebreaker — by cross-referencing the matched "Today's Prices" sheet with each buyer's registered sites, when the AI module is enabled

---

## 🤖 AI Module (v2.2.0)

### Philosophy — AI classifies, PHP calculates

The AI module never computes CO₂, ERRI, or prices itself. It only returns a **classification input** — a validated material slug, a density multiplier, or a matched price reference — which the existing deterministic PHP logic then turns into a number. This keeps every calculation auditable against `fpt_co2_factors()` and the official sources, regardless of whether the classification came from a keyword match or an AI call.

### Four modules

1. **Material classification** (`fpt_ai_classify_material()`) — returns a slug validated against `fpt_co2_factors()`; PHP then reads the exact ADEME/FEDEREC factor. Cached 24h per title.
2. **Micro-local ERRI** (`fpt_ai_erri_multiplier()`) — returns a density multiplier (bounded 0.3–2.5) based on the actual neighborhood, not just the country. Cached 24h per location.
3. **Semantic price matching** (`fpt_ai_match_price()`) — only triggered when keyword scoring fails (score < 10) or finds no match; preserves the existing scoring as the primary path.
4. **Automatic description generation** (`fpt_ai_generate_description()`) — only runs when the listing description is empty, never overwrites manual input.

### Fallback guarantee

Every AI function falls back to the original logic (`strpos()`, `similar_text()`, fixed country multiplier) when: the API key is missing or invalid, the API call fails or times out, the returned slug isn't in the validated list, or the AI module is disabled (`fpt_ai_enabled = false`, the default).

### Setup

FP Tracer → ⚙️ Paramètres → 🤖 Intelligence Artificielle. Enable the checkbox, paste an [Anthropic API key](https://console.anthropic.com), test the connection. Model used: `claude-haiku-4-5` — estimated cost ~$0.001 per listing processed.

---

## 🎯 Buyer Matching Module (v2.3.0)

### What it does

For every published "Annonces déchets" listing, the module builds a **ranked list of partner buyers** directly in the listing's admin edit screen — answering "which buyer should this seller actually call first?"

It cross-references three different data shapes that don't naturally line up:

| Source | Location data shape |
|--------|---------------------|
| Seller listing | One location ("Paris, 93") |
| "Prix du jour" price table | A region hint per buyer per sub-type ("Aquitaine", "Île-de-France") |
| "Acheteurs réguliers" buyer listing | A free-text list of facility site names, sometimes 25+ per buyer (e.g. a buyer network like DECONS) |

### Pipeline

1. **AI extraction** — identifies which sub-type section of the (often multi-section, free-text) price table matches the listing, and extracts that section's buyer/price offers as structured JSON
2. **Buyer resolution** (pure PHP, no AI) — matches each extracted buyer name to its *Acheteurs réguliers* listing (exact normalized match, then fuzzy `similar_text()`), and reads all of that buyer's registered sites
3. **Location triangulation** — a free PHP rule checks for literal seller↔site matches first (instant, 100% reliable when it hits); only buyers left unresolved are sent to a single batched AI call that estimates a proximity tier and approximate distance, with explicit instructions to return "unknown" rather than invent a precise figure for an unrecognizable place name
4. **Ranking** — sorted by proximity tier first, then distance, with Net Vendor price as the tiebreaker only at equal/near-equal location — verified by unit tests

### Fallback & cost control

Same guarantee as the rest of the AI module: gated entirely behind `fpt_ai_enabled`, results cached (12h price extraction, 24h distances, invalidated automatically when source content changes), and the deterministic location rule short-circuits the AI call whenever a literal match is found — minimizing both API cost and the risk of AI misjudgment on the data that's actually unambiguous.

### Known limitation

Distances are **AI estimates** drawn from the model's general geographic knowledge — not a routing calculation, since the project has no Google Maps/geocoding API key. Reliable for "same region" vs. "opposite side of the country," less precise for separating two nearby sites of the same buyer. Every row in the admin table shows a confidence level so the team can sanity-check before deciding.

### Setup

No new settings — reuses `fpt_ai_enabled`. Just `require_once` the module from `feraypro-tracer.php`; the metabox appears automatically on every "Annonces déchets" listing.

---

## 📋 Shortcodes

| Shortcode | Description |
|-----------|-------------|
| `[fpt_dashboard]` | Live global environmental impact dashboard |
| `[fpt_dashboard_finance]` | Financial dashboard (sales, commissions, pipeline, partners) |
| `[fpt_lot id="241"]` | Public traceability page for a batch |
| `[fpt_methodologie]` | Calculation methodology page |
| `[fpt_acheteur id="XXX"]` | Buyer dashboard — CO₂ produced by recycling |
| `[fpt_partenaires]` | Public partner grid — logos, batch count, CO₂ avoided per partner |

### `[fpt_dashboard_finance]` — Paramètres

| Paramètre | Valeurs | Défaut | Description |
|-----------|---------|--------|-------------|
| `period` | `0`, `7`, `30`, `90`, `365` | `30` | Fenêtre temporelle en jours. `0` = depuis le début. |
| `lang` | `fr`, `en` | auto | Langue. Vide = détection automatique par domaine. |

```
[fpt_dashboard_finance]                          ← 30 derniers jours, langue auto
[fpt_dashboard_finance period="0"]               ← tout l'historique
[fpt_dashboard_finance period="365" lang="en"]   ← année en cours, anglais
```

---

## 💰 Commission & Invoicing Module

When admin confirms a batch collection:

1. Admin enters the **lot price** in the collection metabox
2. The plugin calculates the **80/20 split** automatically:
   - Vendor receives **80%** directly from buyer
   - FerayPro receives **20%** commission from buyer
   - If a marketing partner referred the vendor: **10%** goes to partner, **10%** stays FerayPro
3. Admin clicks **"📄 Ouvrir / Imprimer la facture PDF"** — a full A4 invoice opens
4. Invoice includes: lot details, price breakdown, TVA, IBAN/Mobile Money **and Stripe payment button**
5. Commission is confirmed automatically via Stripe webhook, or manually via **"💰 Marquer comme payée"**

---

## 💳 Stripe Payment Module (v2.0.0)

### How it works

1. Buyer opens the invoice link (shared by admin)
2. Clicks **"💳 Payer [amount] en ligne"**
3. Redirected to a Stripe-hosted Checkout page (card, Apple Pay, Google Pay…)
4. On success → Stripe sends a webhook to the site
5. Plugin automatically sets `_fpt_commission_paid = 'paid'` — no admin action needed

### Setup (3 steps)

**Step 1 — Enter your Stripe keys**  
Go to FP Tracer → ⚙️ Paramètres → 💳 Stripe. Enter your test or live API keys.

**Step 2 — Configure the webhook in Stripe Dashboard**
```
Stripe Dashboard → Developers → Webhooks → Add endpoint
URL     : [copied from FP Tracer settings]
Event   : checkout.session.completed
```
Copy the **Signing secret** (`whsec_...`) back into FP Tracer settings.

**Step 3 — Switch to Live when ready**  
Toggle Mode from `Test` → `Live` and enter your live keys.

### Currency support

Stripe does not support all currencies. The module handles this automatically:

| Country | Site currency | Stripe currency |
|---------|--------------|----------------|
| Morocco 🇲🇦 | MAD | EUR (fallback) |
| France 🇫🇷 | EUR | EUR |
| USA 🇺🇸 | USD | USD |
| DRC 🇨🇩 | CDF / USD | EUR / USD |

### Test card
```
Card   : 4242 4242 4242 4242
Expiry : 12/34   CVC : 123
```

---

## ⚡ Quick Start

### Requirements
- WordPress Multisite (subdomain)
- HivePress + ListingHive
- PHP 7.4+ · MySQL 5.7+

### Installation

1. Upload the `feraypro-tracer/` folder to `wp-content/plugins/`
2. Activate → go to **FP Tracer → ⚙️ Paramètres**
3. Create pages with shortcodes:
   - Environmental: `[fpt_dashboard]`
   - Financial: `[fpt_dashboard_finance]` *(protect with `manage_options` role)*
4. *(Optional)* Configure Stripe for online payments (see above)

### Protect the finance page (recommended)

Add to your theme's `functions.php`:

```php
add_action('template_redirect', function() {
    if ( is_page('finance') && ! current_user_can('manage_options') ) {
        wp_redirect( home_url('/') ); exit;
    }
});
```

### Configuration by country

| Setting | Morocco 🇲🇦 | USA 🇺🇸 | DRC 🇨🇩 | France 🇫🇷 |
|---------|------------|---------|---------|-----------|
| Language | 🇫🇷 Français | 🇬🇧 English | 🇫🇷 Français | 🇫🇷 Français |
| Currency | MAD | USD | USD | EUR |
| TVA | 0% | 0% | 16% | 20% |
| Weight unit | kg | lb | kg | kg |
| Stripe currency | EUR* | USD | EUR* | EUR |
| Grid intensity | 644 g CO₂/kWh | 380 g CO₂/kWh | 35 g CO₂/kWh | 45 g CO₂/kWh |
| Grid multiplier (CO₂ process) | ×14.3 | ×8.44 | ×0.78 | ×1.00 |

*fallback — MAD/CDF not supported by Stripe

> **CO₂ net gain (avoided)** is identical for all countries — ADEME/FEDEREC factors, universal.  
> **CO₂ process** (buyer dashboard) is adjusted by grid multiplier — local electricity mix matters.

---

## 🗝️ Meta Keys WordPress

| Meta key | Type | Description |
|----------|------|-------------|
| `_fpt_co2_avoided` | float | CO₂ évité (tonnes) |
| `_fpt_lot_id` | string | Batch ID public `FP-XXXXXXXX` |
| `_fpt_traced_at` | datetime | Date de tracé |
| `_fpt_collected` | `'1'` | Lot collecté |
| `_fpt_collected_date` | datetime | Date de collecte |
| `_fpt_acheteur_id` | int | ID post acheteur |
| `_fpt_prix_lot` | float | Prix de vente |
| `_fpt_commission_paid` | `'paid'` | Commission payée |
| `_fpt_commission_paid_date` | date | Date du paiement |
| `_fpt_invoice_number` | string | N° facture `FP-INV-YYYYMM-ID` |
| `_fpt_ref` | string | Slug partenaire référent |
| `_fpt_co2_transport` | float | CO₂ transport (tonnes) |
| `_fpt_co2_total` | float | CO₂ total (matière + transport) |
| `_fpt_stripe_session_id` | string | ID Checkout Session Stripe |
| `_fpt_stripe_payment_intent` | string | ID Payment Intent confirmé |
| `_fpt_stripe_paid_at` | int | Timestamp confirmation webhook |
| `_fpt_ai_slug` | string | Slug matière retourné par l'IA (validé contre `fpt_co2_factors()`) |
| `_fpt_ai_confidence` | string | Confiance de la classification IA : `high` / `medium` / `low` |
| `_fpt_ai_source` | string | Origine du calcul CO₂ : `ai` ou `fallback` |
| `_fpt_ai_tags` | array | Tags SEO générés pour la description automatique |
| `_fpt_ai_eco_arg` | string | Argument environnemental généré par l'IA (1 phrase) |
| `_fpt_buyer_ranking` | string (JSON) | Classement des acheteurs partenaires pour ce lot : sous-type matché, offres triées (acheteur, fiche liée, site le plus proche, tier de proximité, distance estimée, confiance, prix net vendeur), horodatage |

---

## 🔗 WordPress Hooks (extensibility)

| Hook | Type | Paramètres | Description |
|------|------|-----------|-------------|
| `fpt_admin_settings_extra_cards` | action | — | Injecte des cartes dans les réglages admin |
| `fpt_invoice_payment_methods` | action | `$lot_id`, `$comm20_ttc` | Injecte des modes de paiement dans la facture |
| `fpt_metabox_after_commission` | action | `$post_id` | Injecte du contenu après le bloc commission dans la metabox |
| `fpt_before_co2_save` | action | `$post_id`, `$titre` | Déclenché avant le calcul CO₂ — le module IA s'y accroche pour classifier la matière et écrire `_fpt_ai_slug` |

---

## 🗺️ Roadmap

### Phase 1 — MVP (Current — v2.3.0)
- [x] CO₂ net gain engine (200+ materials, FR + EN + Darija/Lingala/Swahili NLP)
- [x] CO₂ process factors for buyer dashboard (FEDEREC/ADEME LCA 2017)
- [x] **Grid intensity adjustment** — CO₂ process scaled to local electricity mix (IEA 2024 / EPA eGRID 2023 / ONEE-MASEN 2024) — 35+ countries
- [x] ERRI with population density multiplier
- [x] QR code + Digital Batch ID per batch
- [x] Public environmental impact dashboard
- [x] Multi-country (Morocco, DRC, France, USA)
- [x] Bilingual FR/EN + Darija/Lingala/Swahili
- [x] Configurable fields, kg/lb, 50+ currencies
- [x] Collection confirmation metabox (admin)
- [x] Buyer dashboard (`[fpt_acheteur]`)
- [x] Partner affiliate tracking — `?ref=` cookie, banner, admin dashboard
- [x] Commission & invoicing module — 20% FP / 80% vendor / 10% partner, PDF A4
- [x] Financial dashboard `[fpt_dashboard_finance]`
- [x] Architecture modulaire (`modules/`)
- [x] **Stripe online payment** — Checkout Session + webhook auto-confirmation
- [x] **AI module** — material classification, micro-local ERRI, semantic price matching, auto-generated descriptions (Claude Haiku, systematic fallback to keyword logic)
- [x] **Buyer matching module** — ranks partner buyers per listing by location proximity (tier) then price, triangulating seller location, price-table region hints, and buyer multi-site listings (Claude Haiku, deterministic rule-based location match first, AI fallback for the rest)

### Next (no fixed timeline)
- [ ] **Real geocoding for buyer matching** — replace AI-estimated distances with an actual geocoding/routing API (e.g. Google Maps Distance Matrix) once site addresses are structured, for precision on close-call rankings
- [ ] **Stripe multi-currency native** — MAD via local acquirer, removes the current EUR fallback
- [ ] **GitHub auto-update** across all country sites
- [ ] **Full Arabic, Lingala, Swahili keyword vocabularies** — reduces reliance on AI fallback for low-connectivity sites

---

## 📚 Sources

- [ADEME Base Carbone](https://base-empreinte.ademe.fr) — CO₂ net gain factors
- [FEDEREC/ADEME ACV 2017](https://federec.com) — Steel, copper, paper LCA
- [EPA WARM v16 (2024)](https://www.epa.gov/warm) — US recycling factors (cross-validation)
- [IEA Electricity (2024)](https://www.iea.org) — National grid carbon intensity — CO₂ process adjustment
- [EPA eGRID (2023)](https://www.epa.gov/egrid) — US electricity grid mix — 380 g CO₂/kWh national average
- [ONEE/MASEN (2024)](https://www.masen.ma) — Morocco electricity mix — 644 g CO₂/kWh
- [WHO (2021)](https://www.who.int/data/gho) — Lead exposure, ERRI coefficient
- [Pure Earth (2020)](https://www.pureearth.org) — Cadmium & lead at African sites
- [EPA AP-42 (2022)](https://www.epa.gov) — PM2.5 open burning
- [UNEP (2018)](https://www.mercuryconvention.org) — Mercury in e-waste
- [UNICEF (2020)](https://www.unicef.org/reports/toxic-truth) — Child lead exposure
- [HEI (2020)](https://www.healtheffects.org) — PM2.5 ERRI coefficient
- [FAO (2021)](https://www.fao.org) — Carbon sequestration
- [Anthropic Claude API](https://docs.claude.com) — AI classification, semantic matching, content generation (model: `claude-haiku-4-5`)

---

## 📄 License

MIT License — Copyright (c) 2026 FerayPro

---

## 🏷️ Citation

```
FerayPro Tracer v2.3.0 (2026). Open-source waste batch traceability plugin.
MIT License. https://github.com/feraypro/feraypro-tracer
```

---

*Built for the informal collectors, the scrap dealers, and the children living near recycling sites.*
