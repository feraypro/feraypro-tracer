# FerayPro Tracer

**Open-source waste batch traceability plugin for WordPress / HivePress**

Automatically calculates CO₂ avoided, child health impact, commission invoicing, and financial reporting for every recycled waste batch. Built for [FerayPro Morocco](https://ma.feraypro.com), [FerayPro DRC](https://cd.feraypro.com), [FerayPro France](https://fr.feraypro.com), and [FerayPro USA](https://feraypro.com) — a circular waste marketplace operating globally.

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Version](https://img.shields.io/badge/Version-1.9.0-blue.svg)](CHANGELOG.md)
[![UNICEF Venture Fund](https://img.shields.io/badge/UNICEF-Venture%20Fund%202026-00aeef.svg)](https://unicefinnovationfund.org)

---

## 📁 Structure du plugin

```
feraypro-tracer/
├── feraypro-tracer.php          ← Plugin principal (CO₂, lots, factures, partenaires)
├── tracer.css                   ← Styles publics (blocs inline, lot card, dashboard CO₂)
├── modules/
│   └── finance/
│       ├── finance.php          ← Module dashboard financier [fpt_dashboard_finance]
│       └── finance.css          ← Styles du dashboard financier
├── CHANGELOG.md
└── README.md
```

> **Architecture modulaire** : `feraypro-tracer.php` charge automatiquement tous les modules via `require_once` au démarrage. Ajouter un module = créer un dossier dans `modules/` et l'enregistrer dans le `require_once` du plugin principal.

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
2. **Calculates CO₂ net gain** using ADEME Base Carbone / FEDEREC ACV 2017 net gain factors (Primary − Recycled)
3. **Calculates ERRI** (Exposure Risk Reduction Index) — Lead, PM2.5, Cadmium, Mercury — with population density multiplier by country
4. **Generates a QR code** linking to the public batch traceability page
5. **Updates the live impact dashboard** with cumulative totals and net CO₂ balance
6. **Generates a commission invoice** (PDF) when the batch is collected — 20% to FerayPro, 80% to vendor
7. **Tracks marketing partner referrals** via `?ref=` cookie system
8. **Reports financial KPIs** via the Finance Dashboard module

---

## 📋 Shortcodes

| Shortcode | Description |
|-----------|-------------|
| `[fpt_dashboard]` | Live global environmental impact dashboard |
| `[fpt_dashboard_finance]` | **NEW v1.9.0** — Financial dashboard (sales, commissions, pipeline, partners) |
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

**Indicateurs affichés :**
- KPIs : lots tracés, collectés, ventes totales, commission FerayPro TTC, commissions partenaires, vendeurs, commissions impayées, délai moyen de collecte
- Pipeline visuel des lots (barres de progression par statut)
- Graphique mensuel 12 mois (Chart.js)
- Répartition des revenus : split dynamique FP / partenaires / vendeurs (20/10/80 avec partenaire, 20/80 sans)
- Détail des lots avec commissions impayées (lien admin direct)
- Top partenaires marketing (commissions payées vs à percevoir)
- Top 10 acheteurs (volume, poids, CO₂)

---

## 💰 Commission & Invoicing Module (v1.8.0+)

When admin confirms a batch collection:

1. Admin enters the **lot price** in the collection metabox
2. The plugin calculates the **80/20 split** automatically:
   - Vendor receives **80%** directly from buyer
   - FerayPro receives **20%** commission from buyer
   - If a marketing partner referred the vendor: **10%** goes to partner, **10%** stays FerayPro
3. Admin clicks **"📄 Ouvrir / Imprimer la facture PDF"** — a full A4 invoice opens
4. Invoice includes: lot details, price breakdown, TVA, payment instructions (IBAN + Mobile Money)
5. Admin clicks **"💰 Marquer comme payée"** when payment is received

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

---

## 🗺️ Roadmap

### Phase 1 — MVP (Current — v1.9.0)
- [x] CO₂ net gain engine (200+ materials, FR + EN + Darija/Lingala/Swahili NLP)
- [x] CO₂ process factors for buyer dashboard (FEDEREC/ADEME LCA 2017)
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
- [x] **Financial dashboard** `[fpt_dashboard_finance]` — v1.9.0
- [x] Architecture modulaire (`modules/`)

### Phase 2 — ML Refinement (2026–2027)
- [ ] Random Forest model (Morocco + DRC field data)
- [ ] Statistical confidence intervals on CO₂ factors
- [ ] Geospatial impact map
- [ ] Full Arabic, Lingala, Swahili keyword vocabularies
- [ ] TF-IDF fallback classification
- [ ] Export API for governments/NGOs
- [ ] GitHub auto-update across all country sites
- [ ] Transient cache for finance dashboard (large datasets)

---

## 📚 Sources

- [ADEME Base Carbone](https://base-empreinte.ademe.fr) — CO₂ net gain factors
- [FEDEREC/ADEME ACV 2017](https://federec.com) — Steel, copper, paper LCA
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
FerayPro Tracer v1.9.0 (2026). Open-source waste batch traceability plugin.
MIT License. https://github.com/feraypro/feraypro-tracer
```

---

*Built for the informal collectors, the scrap dealers, and the children living near recycling sites.*
