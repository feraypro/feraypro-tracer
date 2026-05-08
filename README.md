# FerayPro Tracer

**Open-source waste batch traceability plugin for WordPress **

Automatically calculates CO₂ avoided and child health impact for every recycled waste batch published on a marketplace. Built for [FerayPro](https://ma.feraypro.com ; https://cd.feraypro.com ; https://fr.feraypro.com) — a circular waste marketplace operating in Morocco, DRC, France.

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![WordPress](https://img.shields.io/badge/WordPress-Multisite-blue.svg)](https://wordpress.org)
[![UNICEF Venture Fund](https://img.shields.io/badge/UNICEF-Venture%20Fund%202026-00aeef.svg)](https://unicefinnovationfund.org)

---

## 🌍 Live Demo

| Site | Dashboard | Methodology |
|------|-----------|-------------|
| Morocco | [ma.feraypro.com/impact](https://ma.feraypro.com/impact) | [ma.feraypro.com/methodologie](https://ma.feraypro.com/methodologie) |
| DRC | [cd.feraypro.com/impact](https://cd.feraypro.com/impact) | — | [cd.feraypro.com/methodologie](https://cd.feraypro.com/methodologie) |
| France | [fr.feraypro.com/impact](https://fr.feraypro.com/impact) | — | [fr.feraypro.com/methodologie](https://fr.feraypro.com/methodologie) |


---

## 🌱 What It Does

When a seller publishes a waste listing on FerayPro, the plugin automatically:

1. **Detects the material type** from the listing title using 200+ bilingual keywords (French + English)
2. **Calculates CO₂ avoided** using ADEME Base Carbone emission factors
3. **Calculates child health impact** — lead, PM2.5, cadmium, mercury avoided — using WHO, EPA, Pure Earth, and UNEP factors
4. **Generates a unique QR code** linking to the public batch traceability page
5. **Updates the live impact dashboard** with cumulative totals

---

## 📊 Impact Dashboard

The public dashboard displays:

| Indicator | Source |
|-----------|--------|
| ♻️ Batches traced | Live count |
| ⚖️ Waste collected (t) | Sum of batch weights |
| 🌱 CO₂ avoided (t) | ADEME Base Carbone |
| 🌳 Equivalent trees/year | FAO (22 kg CO₂/tree/year) |
| 🔴 Lead not dispersed (kg) | WHO Lead Report 2021 |
| ☁️ PM2.5 avoided (kg) | EPA AP-42 2022 |
| ⚠️ Cadmium avoided (g) | Pure Earth DB 2020 |
| 🧠 Mercury avoided (g) | UNEP Minamata 2018 |
| 👶 Children protected (est.) | WHO GHO 2021 + HEI 2020 |

Full methodology: [METHODOLOGY.md](METHODOLOGY.md)

---

## ⚡ Quick Start

### Requirements
- WordPress Multisite (subdomain configuration)
- HivePress plugin + ListingHive theme
- PHP 7.4+
- MySQL 5.7+

### Installation

1. Download the latest release ZIP
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**
3. Upload the ZIP and activate
4. Go to **FP Tracer → Settings** and configure:

| Setting | Morocco example | USA example |
|---------|----------------|-------------|
| Platform name | FerayPro | FerayPro |
| Country | Maroc | USA |
| Language | 🇫🇷 Français | 🇬🇧 English |
| Weight field name | `poids` | `weight` |
| Weight unit | kg | lb |
| City field name | `ville` | `city` |
| Phone field name | `whatsapp` | `telephone` |
| Price field name | `prixvendeur` | `pricebuyer` |

5. Create a WordPress page with slug `impact` and add `[fpt_dashboard]`
6. Create a page with slug `methodologie` and add `[fpt_methodologie]`
7. Click **Recalculate from zero** to trace existing listings

---

## 📋 Shortcodes

| Shortcode | Description |
|-----------|-------------|
| `[fpt_dashboard]` | Live global impact dashboard |
| `[fpt_lot id="241"]` | Public traceability page for a specific batch |
| `[fpt_methodologie]` | Full calculation methodology page |

---

## 🔧 HivePress Field Names

The plugin reads HivePress custom attributes via their `Field Name` (meta key prefix `hp_`). Default field names by country:

| Field | Morocco (FR) | USA (EN) |
|-------|-------------|----------|
| Weight | `poids` | `weight` |
| City | `ville` | `city` |
| Phone/WhatsApp | `whatsapp` | `telephone` |
| Price | `prixvendeur` | `pricebuyer` |

To find your field names: **HivePress → Listings → Attributes → Edit attribute → Field Name**

---

## 🌱 CO₂ Factors (ADEME Base Carbone)

The plugin includes 200+ material keywords mapped to ADEME emission factors. Key factors:

| Material | t CO₂ avoided / t recycled |
|----------|---------------------------|
| Aluminum | 9.5 |
| Copper | 3.5 |
| E-waste | 4.0 |
| Lithium battery | 5.0 |
| Steel / Scrap | 1.8 |
| Paper / Cardboard | 0.9 |
| PET Plastic | 1.5 |

Full factor list: [METHODOLOGY.md](METHODOLOGY.md)

---

## 👶 Child Health Factors

| Indicator | Formula | Source |
|-----------|---------|--------|
| Lead avoided (kg) | Weight (t) × 0.5 | Pure Earth 2016, WHO 2021 |
| PM2.5 avoided (kg) | Weight (t) × 15 | EPA AP-42 2022 |
| Cadmium avoided (g) | Weight (t) × 200 | Pure Earth 2020, UNEP 2018 |
| Mercury avoided (g) | Weight (t) × 50 | UNEP Minamata 2018 |
| Children protected | (Lead kg × 50) + (PM2.5 kg × 10) | WHO GHO 2021, HEI 2020 |

---

## 🗺️ Roadmap

### Phase 1 — MVP (Current)
- [x] CO₂ calculation engine (200+ materials, FR + EN)
- [x] Child health impact indicators (4 pollutants)
- [x] QR code generation per batch
- [x] Public impact dashboard
- [x] Methodology page
- [x] Multi-country support (Morocco, DRC, France, USA)
- [x] Bilingual FR/EN
- [x] Configurable field names and weight units

### Phase 2 — ML Refinement (Future)
- [ ] Random Forest model trained on Morocco + DRC field data
- [ ] Geospatial health model with distance-decay functions
- [ ] Statistical confidence intervals on all estimates
- [ ] Arabic, Lingala, Swahili, others keyword support
- [ ] Interactive geospatial impact map
- [ ] Export API for governments and NGOs
- [ ] TF-IDF fallback for unrecognized material descriptions

---

## 🤝 Contributing

We welcome contributions of all kinds. See [CONTRIBUTING.md](CONTRIBUTING.md) for details.

The most impactful contributions right now:
- **New material keywords** in any language
- **Arabic, Lingala, Swahili translations** of existing keywords
- **Emission factor corrections** with source citations
- **Bug reports** and feature requests via GitHub Issues

---

## 📚 Sources

- [ADEME Base Carbone](https://base-empreinte.ademe.fr) — CO₂ emission factors
- [WHO Global Health Observatory (2021)](https://www.who.int/data/gho) — Lead exposure in children
- [Pure Earth Toxic Sites Database (2020)](https://www.pureearth.org) — Cadmium & lead contamination
- [EPA AP-42 (2022)](https://www.epa.gov/air-emissions-factors-and-quantification/ap-42-compilation-air-emissions-factors) — Open burning PM2.5
- [UNEP Minamata Convention (2018)](https://www.mercuryconvention.org) — Mercury assessment
- [UNICEF Toxic Truth (2020)](https://www.unicef.org/reports/toxic-truth) — Lead exposure in children
- [HEI (2020)](https://www.healtheffects.org) — Air pollution sub-Saharan Africa
- [FAO (2021)](https://www.fao.org/forestry) — Carbon sequestration in forests
- [IPCC AR6 (2022)](https://www.ipcc.ch/ar6) — Heavy industry emission factors

---

## 📄 License

MIT License — see [LICENSE](LICENSE)

Copyright (c) 2026 FerayPro

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED.

---

## 🏷️ Citation

If you use FerayPro Tracer in research or reports, please cite:

```
FerayPro Tracer (2026). Open-source waste batch traceability plugin.
MIT License. https://github.com/feraypro/feraypro-tracer
```

---

