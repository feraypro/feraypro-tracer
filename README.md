# FerayPro Tracer

**Open-source waste batch traceability plugin for WordPress / HivePress**

Automatically calculates CO₂ avoided, child health impact, and commission invoicing for every recycled waste batch. Built for [FerayPro Morocco](https://ma.feraypro.com), [FerayPro DRC](https://cd.feraypro.com), [FerayPro France](https://fr.feraypro.com), and [FerayPro USA](https://feraypro.com) — a circular waste marketplace operating globally.

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Version](https://img.shields.io/badge/Version-1.8.0-blue.svg)](CHANGELOG.md)
[![UNICEF Venture Fund](https://img.shields.io/badge/UNICEF-Venture%20Fund%202026-00aeef.svg)](https://unicefinnovationfund.org)

---

## 🌍 Live Demo

| Site | Dashboard | Methodology |
|------|-----------|-------------|
| Morocco | [ma.feraypro.com/impact](https://ma.feraypro.com/impact) | [METHODOLOGY.md](METHODOLOGY.md) |
| DR Congo | [cd.feraypro.com/impact](https://cd.feraypro.com/impact) | — |
| France | [fr.feraypro.com/impact](https://fr.feraypro.com/impact) | — |
| USA | [feraypro.com/impact](https://feraypro.com/impact) | — |

---

## 🌱 What It Does

When a seller publishes a waste listing on HivePress / ListingHive, the plugin automatically:

1. **Detects the material type** using `fpt_normalize_text()` — 200+ bilingual keywords (FR + EN) + Darija, Lingala, Swahili NLP transliteration
2. **Calculates CO₂ net gain** using ADEME Base Carbone / FEDEREC ACV 2017 net gain factors (Primary − Recycled)
3. **Calculates ERRI** (Exposure Risk Reduction Index) — Lead, PM2.5, Cadmium, Mercury — with population density multiplier by country
4. **Generates a QR code** linking to the public batch traceability page
5. **Updates the live impact dashboard** with cumulative totals and net CO₂ balance
6. **Generates a commission invoice** (PDF) when the batch is collected — 20% to FerayPro, 80% to vendor

---

## 💰 Commission & Invoicing Module (v1.8.0)

When admin confirms a batch collection:

1. Admin enters the **lot price** in the collection metabox
2. The plugin calculates the **80/20 split** automatically:
   - Vendor receives **80%** directly from buyer
   - FerayPro receives **20%** commission from buyer
   - If a marketing partner referred the vendor: **10%** goes to partner, **10%** stays FerayPro
3. Admin clicks **"📄 Ouvrir / Imprimer la facture PDF"** — a full A4 invoice opens
4. Invoice includes: lot details, price breakdown, TVA, payment instructions (IBAN + Mobile Money)
5. Admin clicks **"💰 Marquer comme payée"** when payment is received

### Invoice features
- Uses the site's WordPress custom logo (or favicon fallback)
- Full A4 page on print / PDF export
- TVA configurable per site (0 = exempt, 20% France, 16% DRC…)
- No payment status shown on invoice (sent to request payment)
- Partner commission block shown if `?ref=` referral exists

---

## 📊 Impact Dashboard

| Indicator | Source |
|-----------|--------|
| ♻️ Batches traced | Live count |
| ⚖️ Waste to recycle (kg/lb) | Sum of batch weights |
| 🌱 CO₂ avoided (net gain) | ADEME/FEDEREC net gain factors |
| 🏭 CO₂ produced (recycling) | FEDEREC/ADEME LCA 2017 process factors |
| ⚖️ Net CO₂ balance | Avoided − Produced |
| 🔴 Lead diverted (kg) | WHO Lead Report 2021 |
| ☁️ PM2.5 diverted (kg) | EPA AP-42 2022 |
| ⚠️ Cadmium diverted (g) | Pure Earth DB 2020 |
| 🧠 Mercury diverted (g) | UNEP Minamata 2018 |
| 📊 ERRI score | WHO GHO 2021 + HEI 2020 × density multiplier |

Full methodology: [METHODOLOGY.md](METHODOLOGY.md)

---

## ⚡ Quick Start

### Requirements
- WordPress Multisite (subdomain)
- HivePress + ListingHive
- PHP 7.4+ · MySQL 5.7+

### Installation

1. Upload ZIP → **Plugins → Add New → Upload**
2. Activate → go to **FP Tracer → ⚙️ Paramètres**

### Configuration by country

| Setting | Morocco 🇲🇦 | USA 🇺🇸 | DRC 🇨🇩 | France 🇫🇷 |
|---------|------------|---------|---------|-----------|
| Language | 🇫🇷 Français | 🇬🇧 English | 🇫🇷 Français | 🇫🇷 Français |
| Country | Maroc | USA | Congo | France |
| Currency | MAD | USD | USD | EUR |
| TVA | 0% | 0% | 16% | 20% |
| Weight field | `poids` | `weight` | `poids` | `poids` |
| Weight unit | kg | lb | kg | kg |
| City field | `ville` | `city` | `ville` | `ville` |
| Phone field | `whatsapp` | `telephone` | `whatsapp` | `whatsapp` |
| Price field | `prixvendeur` | `pricebuyer` | `prixvendeur` | `prixvendeur` |
| Prix du jour slug | `prix` | `price` | `prix` | `prix` |
| Buyers slug | `acheteurs` | `buyers` | `acheteurs` | `acheteurs` |

> **Note:** Currency auto-detected by domain if not set: `.fr` → EUR · `.cd`/`.us`/`feraypro.com` → USD · default → MAD.  
> Buyers slug supports comma-separated values: `acheteurs,buyers`

---

## 📋 Shortcodes

| Shortcode | Description |
|-----------|-------------|
| `[fpt_dashboard]` | Live global impact dashboard |
| `[fpt_lot id="241"]` | Public traceability page for a batch |
| `[fpt_methodologie]` | Calculation methodology page |
| `[fpt_acheteur id="XXX"]` | Buyer dashboard — CO₂ produced by recycling |
| `[fpt_partenaires]` | Public partner grid — logos, batch count, CO₂ avoided per partner |

---

## 🤝 Partner Tracking — Affiliate System

Traffic platforms (Avito, LeBonCoin, Jumia…) send visitors via a unique referral link. When a visitor publishes a listing, the batch is automatically attributed to the partner.

```
https://ma.feraypro.com/?ref=avito
https://ma.feraypro.com/?ref=leboncoin
```

**How it works:**
1. Visitor arrives via `?ref=avito` → slug stored in a **30-day cookie**
2. Visitor publishes a listing → `_fpt_ref=avito` saved to the batch post meta
3. **Banner displayed first** on the listing page, above all content
4. When batch is collected and invoiced → partner receives **10% of lot price**
5. Admin dashboard (**FP Tracer → 🤝 Partners**) shows per-partner stats: batches referred, collected, total weight, CO₂ avoided, commission %

**Admin features:**
- Add/edit/delete partners from WordPress admin — no code changes needed
- Upload partner logo via WordPress media library
- Set partner color, commission %, active/inactive toggle
- Copy-ready referral link for each partner

---

## 🔬 ERRI — Exposure Risk Reduction Index

```
ERRI = ((Lead diverted kg × 50) + (PM2.5 diverted kg × 10)) × density_multiplier
```

Density multiplier by country:
- DRC: ×1.8 · Morocco: ×1.2 · Senegal: ×1.3 · France/USA: ×0.7–0.8

> ERRI is an estimative proxy — not peer-reviewed or clinically validated.

---

## 🔧 CO₂ Net Gain Factors (ADEME/FEDEREC)

| Material | Net gain | Primary | Recycled |
|----------|----------|---------|----------|
| Aluminum | **6.88 t/t** | 7.24 | 0.36 |
| Copper | **0.141 t/t** | 1.445 | 1.304 |
| Stainless steel / Inox | **2.10 t/t** | 2.8 | 0.7 |
| Gold / Or | **14.00 t/t** | ~16.0 | ~2.0 |
| Platinum | **11.00 t/t** | ~13.0 | ~2.0 |
| Smartphone / Phone | **4.00 t/t** | — | — |
| E-waste / Electronics | **3.50 t/t** | — | — |
| Steel / Scrap | **1.10 t/t** | 1.9 | 0.58 |
| PET Plastic | **1.50 t/t** | 2.15 | 0.65 |
| Paper / Cardboard | **0.050 t/t** | 0.92 | 0.87 |
| Default (unrecognized) | **0.50 t/t** | — | — |

Full table (42 materials) + sources: [METHODOLOGY.md](METHODOLOGY.md)

---

## 🗺️ Roadmap

### Phase 1 — MVP (Current — v1.8.0)
- [x] CO₂ net gain engine (200+ materials, FR + EN + Darija/Lingala/Swahili NLP)
- [x] CO₂ process factors for buyer dashboard (FEDEREC/ADEME LCA 2017)
- [x] ERRI with population density multiplier
- [x] Health co-occurrence fix — multi-pollutant per batch
- [x] QR code + Digital Batch ID per batch
- [x] Public impact dashboard (CO₂ avoided · CO₂ produced · net balance)
- [x] Multi-country (Morocco, DRC, France, USA)
- [x] Bilingual FR/EN + transitional Darija/Lingala/Swahili
- [x] Configurable field names, weight units (kg/lb), 50+ world currencies
- [x] Prix du jour block — buyer prices on each listing
- [x] Collection confirmation metabox (admin)
- [x] Buyer dashboard (`[fpt_acheteur]`)
- [x] Partner affiliate tracking — `?ref=` cookie, banner, admin dashboard, commission tracking
- [x] **Commission & invoicing module** — 20% FerayPro / 80% vendor / 10% partner split, PDF invoice (A4, logo, TVA), AJAX price save, mark-as-paid

### Phase 2 — ML Refinement (2026–2027)
- [ ] Random Forest model (Morocco + DRC field data)
- [ ] Statistical confidence intervals
- [ ] Geospatial impact map
- [ ] Full Arabic, Lingala, Swahili keyword vocabularies
- [ ] TF-IDF fallback classification
- [ ] Export API for governments/NGOs
- [ ] GitHub auto-update across all country sites

---

## 🤝 Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Most impactful contributions:
- New material keywords in any language
- Arabic, Lingala, Swahili translations
- Emission factor corrections with source citations

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
FerayPro Tracer v1.8.0 (2026). Open-source waste batch traceability plugin.
MIT License. https://github.com/feraypro/feraypro-tracer
```

---

*Built for the informal collectors, the scrap dealers, and the children living near recycling sites.*
