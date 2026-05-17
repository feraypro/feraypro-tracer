# FerayPro Tracer

**Open-source waste batch traceability plugin for WordPress / HivePress**

Automatically calculates CO₂ avoided and child health impact for every recycled waste batch. Built for [FerayPro](https://ma.feraypro.com) — a circular waste marketplace in Morocco, DRC, France, and the USA.

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Version](https://img.shields.io/badge/Version-1.7.1-blue.svg)](CHANGELOG.md)
[![UNICEF Venture Fund](https://img.shields.io/badge/UNICEF-Venture%20Fund%202026-00aeef.svg)](https://unicefinnovationfund.org)

---

## 🌍 Live Demo

| Site | Dashboard | Methodology |
|------|-----------|-------------|
| Morocco | [ma.feraypro.com/impact](https://ma.feraypro.com/impact) | [ma.feraypro.com/methodologie](https://ma.feraypro.com/methodologie) |
| DRC | [cd.feraypro.com/impact](https://cd.feraypro.com/impact) | — |
| France | [fr.feraypro.com/impact](https://fr.feraypro.com/impact) | — |
| USA | [us.feraypro.com/impact](https://us.feraypro.com/impact) | — |

---

## 🌱 What It Does

When a seller publishes a waste listing on HivePress / ListingHive, the plugin automatically:

1. **Detects the material type** using `fpt_normalize_text()` — 200+ bilingual keywords (FR + EN) + Darija, Lingala, Swahili NLP transliteration
2. **Calculates CO₂ net gain** using ADEME Base Carbone / FEDEREC ACV 2017 net gain factors (Primary − Recycled)
3. **Calculates ERRI** (Exposure Risk Reduction Index) — Lead, PM2.5, Cadmium, Mercury — with population density multiplier by country
4. **Generates a QR code** linking to the public batch traceability page
5. **Updates the live impact dashboard** with cumulative totals and net CO₂ balance

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
2. Activate → go to **FP Tracer → Settings**

### Configuration by country

| Setting | Morocco 🇲🇦 | USA 🇺🇸 | DRC 🇨🇩 |
|---------|------------|---------|---------|
| Language | 🇫🇷 Français | 🇬🇧 English | 🇫🇷 Français |
| Country | Maroc | USA | Congo |
| Weight field | `poids` | `weight` | `poids` |
| Weight unit | kg | lb | kg |
| City field | `ville` | `city` | `ville` |
| Phone field | `whatsapp` | `telephone` | `whatsapp` |
| Price field | `prixvendeur` | `pricebuyer` | `prixvendeur` |
| Prix du jour slug | `prix` | `price` | `prix` |
| Prix/kg field | `prix` | `price_2` | `prix` |
| Buyers slug | `acheteurs` | `buyers` | `acheteurs` |

---

## 📋 Shortcodes

| Shortcode | Description |
|-----------|-------------|
| `[fpt_dashboard]` | Live global impact dashboard |
| `[fpt_lot id="241"]` | Public traceability page for a batch |
| `[fpt_methodologie]` | Calculation methodology page |
| `[fpt_acheteur id="XXX"]` | Buyer dashboard — CO₂ produced by recycling |
| `[fpt_partenaires]` | Partner traffic source dashboard |

---

## 🤝 Partner Tracking

A key feature for community mobilization — schools, municipalities, and NGOs can organize waste collection campaigns and track their collective impact:

```
https://ma.feraypro.com/?ref=school-name
https://ma.feraypro.com/?ref=ngo-kinshasa
```

When a visitor arrives via this link and publishes a listing, the batch is automatically tagged. View all partner batches and their CO₂ impact at `[fpt_partenaires]`.

This feature enables **community-level recycling campaigns** targeting the neighborhoods where children live near informal recycling sites.

---

## 🔬 ERRI — Exposure Risk Reduction Index

```
ERRI = ((Lead diverted kg × 50) + (PM2.5 diverted kg × 10)) × density_multiplier
```

Density multiplier by country (Phase 2 geospatial):
- DRC: ×1.8 · Morocco: ×1.2 · Senegal: ×1.3 · France/USA: ×0.7–0.8

> ERRI is an estimative proxy — not peer-reviewed or clinically validated. Transitional measurement tool evolving toward locally validated ML models in Phase 2.

---

## 🔧 CO₂ Net Gain Factors (ADEME/FEDEREC)

Key factors (Primary − Recycled = Net gain):

| Material | Net gain | Primary | Recycled |
|----------|----------|---------|----------|
| Aluminum | **6.88 t/t** | 7.24 | 0.36 |
| Copper | **0.141 t/t** | 1.445 | 1.304 |
| Steel/Scrap | **1.10 t/t** | 1.9 | 0.58 |
| PET Plastic | **1.50 t/t** | 2.15 | 0.65 |
| Paper | **0.050 t/t** | 0.92 | 0.87 |
| Glass | **0.240 t/t** | 0.53 | 0.29 |

Full table: [METHODOLOGY.md](METHODOLOGY.md)

---

## 🗺️ Roadmap

### Phase 1 — MVP (Current — v1.7.1)
- [x] CO₂ net gain engine (200+ materials, FR + EN + Darija/Lingala/Swahili NLP)
- [x] CO₂ process factors for buyer dashboard (FEDEREC/ADEME LCA 2017)
- [x] ERRI with population density multiplier
- [x] Health co-occurrence fix — multi-pollutant per batch
- [x] QR code + Digital Batch ID per batch
- [x] Public impact dashboard (CO₂ avoided · CO₂ produced · net balance)
- [x] Methodology page with validation status
- [x] Multi-country (Morocco, DRC, France, USA)
- [x] Bilingual FR/EN + transitional Darija/Lingala/Swahili
- [x] Configurable field names and weight units (kg/lb)
- [x] Prix du jour block — buyer prices on each listing
- [x] Collection confirmation (admin metabox)
- [x] Buyer dashboard (`[fpt_acheteur]`)
- [x] Partner tracking + community campaign dashboard

### Phase 2 — ML Refinement (2026–2027)
- [ ] Random Forest model (Morocco + DRC field data)
- [ ] Statistical confidence intervals
- [ ] Geospatial impact map
- [ ] Full Arabic, Lingala, Swahili keyword vocabularies
- [ ] TF-IDF fallback classification
- [ ] Export API for governments/NGOs

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
FerayPro Tracer v1.7.1 (2026). Open-source waste batch traceability plugin.
MIT License. https://github.com/feraypro/feraypro-tracer
Methodology: https://ma.feraypro.com/methodologie
```

---

*Built for the informal collectors, the scrap dealers, and the children living near recycling sites.*
