# FerayPro Tracer — Calculation Methodology

**Last updated:** 2026  
**Version:** 1.7.3  
**License:** MIT  
**Live demo:** [ma.feraypro.com/methodologie](https://ma.feraypro.com/methodologie)

---

## ⚠️ Net Gain Methodology — Important Note

All CO₂ factors use the **net gain method**:

```
CO₂ net gain (t/t) = Primary production emissions − Recycling process emissions
```

Source: FEDEREC/ADEME ACV 2017. This is the scientifically rigorous approach validated by independent auditors. Earlier documentation versions cited gross avoidance figures — the code has always applied net gain values. This document is now fully aligned with the code.

---

## Table of Contents

1. [Input Data](#1-input-data)
2. [Material Detection](#2-material-detection)
3. [Section 1 — CO₂ Impact](#3-section-1--co₂-impact)
4. [Section 2 — Child Health Impact (ERRI)](#4-section-2--child-health-impact-erri)
5. [Dashboard Equivalents](#5-dashboard-equivalents)
6. [Limitations & Validation Status](#6-limitations--validation-status)
7. [Phase 2 — ML Refinement](#7-phase-2--ml-refinement)
8. [All Sources](#8-all-sources)

---

## 1. Input Data

| Field | Meta key | Role |
|-------|----------|------|
| Listing title | `post_title` | Material type detection (NLP) |
| Weight (kg or lb) | `hp_poids` / `hp_weight` | Primary calculation variable |
| City | `hp_ville` / `hp_city` | Geolocation |
| Photo | `_thumbnail_id` | Visual proof |

**Note on pounds (lb):** For US listings, weight entered in lb is automatically converted to kg (× 0.453592).

---

## 2. Material Detection

The plugin normalizes the listing title using `fpt_normalize_text()`, which:
- Converts to lowercase
- Strips accents (FR)
- Applies transliteration dictionary for Darija, Lingala, and Swahili (Phase 2 transitional NLP)

Then compares against 200+ bilingual keywords (FR + EN). First matching keyword determines the factor.

**Darija (Morocco):** خردة → ferraille, نحاس → cuivre, حديد → fer  
**Lingala (DRC):** singa → cable, likoxi → cuivre  
**Swahili (DRC East/Kenya):** chuma → fer, shaba → cuivre, betri → batterie

---

## 3. Section 1 — CO₂ Impact

### Formula

```
CO₂ net gain (t) = Weight (kg) ÷ 1000 × Net gain factor (t CO₂ / t recycled)
```

### Example

- Batch: **Copper pipes — 35 kg**
- Weight in tonnes: 35 ÷ 1000 = 0.035 t
- Copper net gain factor: **0.141 t CO₂/t** (Primary 1.445 − Recycled 1.304)
- CO₂ avoided = 0.035 × 0.141 = **0.00494 t = 4.94 kg CO₂**

### CO₂ Net Gain Factors (FEDEREC/ADEME ACV 2017)

| Material | Factor (t CO₂/t) | Primary | Recycled | Source |
|----------|-----------------|---------|----------|--------|
| **Aluminum** | **6.88** | 7.24 | 0.36 | ADEME Base Carbone |
| **Copper** | **0.141** | 1.445 | 1.304 | FEDEREC/ADEME ACV 2017 |
| **Bronze** | **0.120** | ~1.4 | ~1.28 | Estimated (Cu/Sn alloy) |
| **Brass / Laiton** | **0.100** | ~1.3 | ~1.2 | Estimated (Cu/Zn alloy) |
| **Stainless steel / Inox** | **2.10** | 2.8 | 0.7 | ADEME |
| **Nickel** | **6.00** | 6.5 | 0.5 | ADEME |
| **Zinc** | **0.720** | 0.9 | 0.18 | ADEME |
| **Lead / Plomb** | **0.420** | 0.5 | 0.08 | ADEME |
| **Tin / Étain** | **1.50** | ~2.0 | ~0.5 | Estimated |
| **Iron / Steel / Scrap** | **1.10** | 1.9 | 0.58 | FEDEREC/ADEME ACV 2017 |
| **Cast iron / Fonte** | **0.90** | 1.5 | 0.6 | ADEME |
| **Titanium** | **4.00** | ~5.0 | ~1.0 | Estimated |
| **Magnesium** | **9.00** | ~10.0 | ~1.0 | Estimated |
| **Chrome / Chromium** | **2.00** | ~2.5 | ~0.5 | Estimated |
| **Tungsten / Carbide** | **3.00** | ~3.5 | ~0.5 | Estimated |
| **Cobalt** | **7.00** | ~8.0 | ~1.0 | Estimated |
| **Silver / Argent** | **7.00** | ~8.0 | ~1.0 | Estimated |
| **Gold / Or** | **14.00** | ~16.0 | ~2.0 | Estimated (mining intensity) |
| **Platinum / Platine** | **11.00** | ~13.0 | ~2.0 | Estimated |
| **Palladium** | **9.00** | ~11.0 | ~2.0 | Estimated |
| **Catalytic converter** | **4.50** | — | — | Pt + Pd + Rh recovery |
| **E-waste / Electronics** | **3.50** | — | — | Estimated (precious metals) |
| **Smartphone / Phone** | **4.00** | — | — | Li, Co, Ag, Au extraction |
| **Lithium battery** | **4.00** | — | — | Li, Co, Ni extraction |
| **Lead-acid battery** | **1.80** | — | — | Pb + acid |
| **Car battery (lead)** | **0.90** | — | — | ~50% Pb content × 0.42 + steel |
| **Cable / Wire** | **0.50** | — | — | Cu net gain + plastic sheath |
| **Refrigerator / Fridge** | **1.80** | — | — | Cu + Al + refrigerant gases |
| **Washing machine** | **1.50** | — | — | Steel + motor Cu |
| **Air conditioner** | **2.00** | — | — | Cu + Al + refrigerant |
| **Motor / Engine** | **1.50** | — | — | Steel + Cu winding mix |
| **Alternator** | **2.00** | — | — | Cu winding dominant |
| **Paper / Cardboard** | **0.050** | 0.92 | 0.87 | FEDEREC/ADEME ACV 2017 |
| **PET Plastic** | **1.50** | 2.15 | 0.65 | ADEME 2024 |
| **HDPE / PE Plastic** | **1.40** | 2.0 | 0.6 | ADEME 2024 |
| **PVC** | **0.80** | — | — | Lower gain (chlorine) |
| **PP Plastic** | **1.50** | — | — | ADEME 2024 |
| **Glass** | **0.240** | 0.53 | 0.29 | ADEME Base Carbone |
| **Tires / Pneus** | **0.80** | — | — | Estimated |
| **Rubber / Caoutchouc** | **0.80** | — | — | Estimated |
| **Textile / Clothing** | **0.40** | — | — | Estimated |
| **Wood / Bois** | **0.30** | — | — | Estimated |
| **Motor oil / Huile** | **1.50** | — | — | Re-refining vs virgin |
| **Solar panel** | **2.00** | — | — | Si + Ag + Al |
| **Default (unrecognized)** | **0.50** | — | — | Conservative value |

### CO₂ Produced by Recycling Process

The buyer dashboard shows CO₂ **produced** by the recycler (the recycling process itself):

| Material | CO₂ process (t/t) | Source |
|----------|------------------|--------|
| Copper | 1.304 | FEDEREC/ADEME ACV 2017 |
| Aluminum | 0.36 | ADEME Base Carbone |
| Steel/Scrap | 1.10 | Electric arc furnace — ADEME |
| Paper | 0.87 | FEDEREC/ADEME ACV 2017 |
| PET Plastic | 0.65 | ADEME 2024 |
| Glass | 0.29 | ADEME Base Carbone |

---

## 4. Section 2 — Child Health Impact (ERRI)

### ERRI — Exposure Risk Reduction Index

```
ERRI = ((Lead diverted kg × 50) + (PM2.5 diverted kg × 10)) × density_multiplier
```

**Population density multiplier** (Phase 2 — geospatial context):
- Morocco: × 1.2 (HCP coastal urban density)
- DRC: × 1.8 (Kinshasa informal hub density)
- Senegal: × 1.3
- France / USA: × 0.7–0.8
- Default: × 1.0

### Four Pollutant Indicators

**Bug fix v1.7.1:** Each pollutant is now analyzed **independently** — a batch titled "Lead paint and batteries" correctly contributes to BOTH lead AND cadmium indicators (co-occurrence support).

| Indicator | Formula | Keywords | Source |
|-----------|---------|----------|--------|
| Lead diverted (kg) | Weight (t) × 0.5 | plomb, batterie, peinture, lead, battery | Pure Earth 2016, WHO 2021 |
| PM2.5 diverted (kg) | Weight (t) × 15 | cable, cuivre, plastique, wire, copper | EPA AP-42 2022 |
| Cadmium diverted (g) | Weight (t) × 200 | pile, ewaste, smartphone, battery, e-waste | Pure Earth 2020, UNEP 2018 |
| Mercury diverted (g) | Weight (t) × 50 | ecran, tv, lampe, screen, lamp | UNEP Minamata 2018 |

**ERRI coefficients:**
- Lead × 50: 1 kg lead in 500m radius → ~50 children under 5 affected (WHO GHO 2021)
- PM2.5 × 10: 1 kg PM2.5 → ~10 children at-risk exposure reduced (HEI 2020)

> **Scientific status:** ERRI is an estimative proxy based on WHO and HEI exposure thresholds. Not peer-reviewed or clinically validated. Transitional measurement tool — field validation and ML refinement planned for Phase 2.

---

## 5. Dashboard Equivalents

| Indicator | Formula | Source |
|-----------|---------|--------|
| Equivalent trees/year | CO₂ total (t) × 45 | FAO 2021: 1 tree absorbs ~22 kg CO₂/year |

---

## 6. Limitations & Validation Status

- Estimates based on global average emission factors
- Net gain factors assume average purity and typical recycling processes
- ERRI is indicative, not clinically validated
- Phase 2 will add statistical confidence intervals and locally validated factors

---

## 7. Phase 2 — ML Refinement

- Random Forest model trained on Morocco + DRC field data
- Geospatial health model (distance-decay, population density)
- Extended NLP: Arabic (full), Lingala, Swahili vocabulary expansion
- Statistical confidence intervals on all estimates
- External validation with university/NGO partner

---

## 8. All Sources

| Source | Reference | Used for |
|--------|-----------|----------|
| ADEME Base Carbone | basecarbone.ademe.fr | CO₂ net gain factors |
| FEDEREC/ADEME ACV 2017 | federec.com | Steel, copper, paper net gains |
| ADEME 2024 | ademe.fr | Plastic recycling factors |
| IPCC AR6 (2022) | ipcc.ch/ar6 | Cross-validation |
| FAO (2021) | fao.org | Trees/year equivalent |
| WHO / OMS (2021) | who.int/data/gho | Lead exposure, ERRI coefficient |
| Pure Earth (2020) | pureearth.org | Cadmium & lead at African sites |
| EPA AP-42 (2022) | epa.gov | PM2.5 from open burning |
| UNEP (2018) | mercuryconvention.org | Mercury in e-waste |
| HEI (2020) | healtheffects.org | PM2.5 ERRI coefficient |
| UNICEF (2020) | unicef.org/reports/toxic-truth | Child lead exposure context |
| HCP Maroc | hcp.ma | Morocco demographic data |

---

*FerayPro Tracer v1.7.3 — Open Source MIT *
