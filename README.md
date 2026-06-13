# FerayPro Tracer

**Open-source waste batch traceability plugin for WordPress / HivePress**

Automatically calculates CO₂ avoided, child health impact, commission invoicing, Stripe online payment, and financial reporting for every recycled waste batch. Built for [FerayPro Morocco](https://ma.feraypro.com), [FerayPro DRC](https://cd.feraypro.com), [FerayPro France](https://fr.feraypro.com), and [FerayPro USA](https://feraypro.com) — a circular waste marketplace operating globally.

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Version](https://img.shields.io/badge/Version-2.1.0-blue.svg)](CHANGELOG.md)
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
│   └── stripe/
│       └── stripe.php           ← Module paiement Stripe 
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

1. **Detects the material type** using `fpt_normalize_text()` — 200+ bilingual keywords (FR + EN) + Darija, Lingala, Swahili NLP transliteration
2. **Calculates CO₂ net gain** using ADEME Base Carbone / FEDEREC ACV 2017 net gain factors (Primary − Recycled) — **universal, same for all countries**
3. **Adjusts CO₂ process** using `fpt_grid_intensity()` — local electricity grid carbon intensity (IEA 2024, EPA eGRID 2023, ONEE/MASEN 2024) — **buyer dashboard only**
4. **Calculates ERRI** (Exposure Risk Reduction Index) — Lead, PM2.5, Cadmium, Mercury — with population density multiplier by country
5. **Generates a QR code** linking to the public batch traceability page
6. **Updates the live impact dashboard** with cumulative totals and net CO₂ balance
7. **Generates a commission invoice** (PDF) when the batch is collected — 20% to FerayPro, 80% to vendor
8. **Accepts online payment** via Stripe Checkout — automatic confirmation via webhook
9. **Tracks marketing partner referrals** via `?ref=` cookie system
10. **Reports financial KPIs** via the Finance Dashboard module

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

---

## 🔗 WordPress Hooks (extensibility)

| Hook | Type | Paramètres | Description |
|------|------|-----------|-------------|
| `fpt_admin_settings_extra_cards` | action | — | Injecte des cartes dans les réglages admin |
| `fpt_invoice_payment_methods` | action | `$lot_id`, `$comm20_ttc` | Injecte des modes de paiement dans la facture |
| `fpt_metabox_after_commission` | action | `$post_id` | Injecte du contenu après le bloc commission dans la metabox |

---

## 🗺️ Roadmap

### Phase 1 — MVP (Current — v2.1.0)
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

### Phase 2 — ML Refinement (2026–2027)
- [ ] Random Forest model (Morocco + DRC field data)
- [ ] Statistical confidence intervals on CO₂ factors
- [ ] Geospatial impact map
- [ ] Full Arabic, Lingala, Swahili keyword vocabularies
- [ ] TF-IDF fallback classification
- [ ] Export API for governments/NGOs
- [ ] GitHub auto-update across all country sites
- [ ] Transient cache for finance dashboard (large datasets)
- [ ] Stripe multi-currency native (MAD via local acquirer)

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

---

## 📄 License

MIT License — Copyright (c) 2026 FerayPro

---

## 🏷️ Citation

```
FerayPro Tracer v2.1.0 (2026). Open-source waste batch traceability plugin.
MIT License. https://github.com/feraypro/feraypro-tracer
```

---

*Built for the informal collectors, the scrap dealers, and the children living near recycling sites.*
