# FerayPro Tracer — Calculation Methodology

**Last updated:** 2026  
**Version:** 2.3.0  
**License:** MIT  

---

## ⚠️ Net Gain Methodology — Important Note

All CO₂ factors use the **net gain method**:

```
CO₂ net gain (t/t) = Primary production emissions − Recycling process emissions
```

Source: FEDEREC/ADEME ACV 2017. This is the scientifically rigorous approach validated by independent auditors. 

---

## Table of Contents

1. [Input Data](#1-input-data)
2. [Material Detection](#2-material-detection)
3. [Material Detection — AI-Assisted Classification](#2b-material-detection--ai-assisted-classification)
4. [Section 1 — CO₂ Impact](#3-section-1--co₂-impact)
5. [Section 1b — CO₂ Process Adjustment (Grid Intensity)](#4-section-1b--co₂-process-adjustment-grid-intensity)
6. [Section 2 — Child Health Impact (ERRI)](#5-section-2--child-health-impact-erri)
7. [Section 2b — Micro-Local Density (AI-Assisted)](#5b-section-2b--micro-local-density-ai-assisted)
8. [Section 3 — Buyer Matching Triangulation (AI-Assisted)](#5c-section-3--buyer-matching-triangulation-ai-assisted)
9. [Dashboard Equivalents](#6-dashboard-equivalents)
10. [Limitations & Validation Status](#7-limitations--validation-status)
11. [Next Steps](#8-next-steps)
12. [All Sources](#9-all-sources)

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

## 2b. Material Detection — AI-Assisted Classification

### Principle: AI classifies, PHP calculates

When the AI module is enabled (`fpt_ai_enabled = true`), material detection can be delegated to an AI classifier (Claude Haiku) instead of — or as a complement to — the keyword matching described in Section 2. The architecture enforces a strict separation of concerns:

```
AI responsibility   : title text  →  validated material slug
PHP responsibility  : material slug  →  CO₂ factor (fpt_co2_factors())  →  CO₂ avoided (t)
```

The AI never returns a CO₂ value, a factor, or any number used in the final calculation. It returns a slug, which is checked against the exact same `fpt_co2_factors()` table used by the keyword method. If the returned slug is not in that table, the result is rejected and the keyword method runs instead.

### Why this matters for auditability

Because the AI's only output is a slug from a closed, versioned list, every CO₂ figure on the platform — whether classified by keyword or by AI — traces back to the same ADEME/FEDEREC factor table documented in Section 3. The AI changes *which* row of the table is selected; it never changes the table itself or the arithmetic applied to it.

### When AI classification is used

| Condition | Material source |
|-----------|-----------------|
| `fpt_ai_enabled = false` (default) | Keyword matching only (Section 2) |
| `fpt_ai_enabled = true`, AI call succeeds, slug is valid | AI-classified slug |
| `fpt_ai_enabled = true`, AI call fails, times out, or returns an invalid slug | Keyword matching (automatic fallback) |

### Worked example

- Listing title: *"Câbles électriques dénudés de section moyenne"* — no exact keyword match for "cuivre" in the title
- Keyword method (Section 2): falls through to `default` factor (0.50 t CO₂/t) — likely under-credits the batch
- AI method: classifies the title as `cable` (0.50 t CO₂/t) or `cuivre` (0.141 t CO₂/t) depending on context inferred from "câbles électriques" — the exact slug returned is validated against `fpt_co2_factors()` before use

### Caching

Classification results are cached per normalized title for 24 hours (`fpt_ai_call_cached()`), so republishing similar listings does not generate redundant API calls.

> **Scientific status:** AI classification accuracy has not yet been independently validated against manually-verified batches. Field validation against ground-truth labels is planned for Phase 2 (Section 8).

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

| Material | CO₂ process base (t/t) | Source |
|----------|----------------------|--------|
| Copper | 1.304 | FEDEREC/ADEME ACV 2017 |
| Aluminum | 0.36 | ADEME Base Carbone |
| Steel/Scrap | 1.10 | Electric arc furnace — ADEME |
| Paper | 0.87 | FEDEREC/ADEME ACV 2017 |
| PET Plastic | 0.65 | ADEME 2024 |
| Glass | 0.29 | ADEME Base Carbone |

> These base factors are calibrated on the **French electricity mix** (45 g CO₂/kWh, nuclear-dominant — RTE 2023). They are adjusted per country using the grid intensity multiplier (Section 1b below).

---

## 4. Section 1b — CO₂ Process Adjustment (Grid Intensity)

### Why it matters

The CO₂ produced by recycling a tonne of aluminum depends heavily on the electricity mix of the country where recycling occurs. Smelting aluminum consumes large amounts of electricity — in France (nuclear, 45 g CO₂/kWh) that process is nearly carbon-free; in Morocco (coal + gas, 644 g CO₂/kWh) it is 14× more carbon-intensive.

### What is adjusted and what is not

| Indicator | Adjusted by grid? | Rationale |
|-----------|------------------|-----------|
| **CO₂ net gain** (avoided, public dashboard) | ❌ No | Universal — ADEME/FEDEREC primary vs recycled, physics doesn't change by country |
| **CO₂ process** (produced, buyer dashboard) | ✅ Yes | Depends on local electricity carbon intensity |

### Formula

```
CO₂ process adjusted (t/t) = CO₂ process base (ADEME) × grid_multiplier

grid_multiplier = local_grid_intensity (g CO₂/kWh) ÷ 45 (France reference)
```

### Grid Intensity Table

| Country | Grid intensity | Multiplier | Source |
|---------|---------------|------------|--------|
| 🇫🇷 France | 45 g CO₂/kWh | ×1.00 | RTE 2023 — nuclear dominant |
| 🇺🇸 USA | 380 g CO₂/kWh | ×8.44 | EPA eGRID 2023 — national average |
| 🇲🇦 Morocco | 644 g CO₂/kWh | ×14.3 | ONEE/MASEN 2024 — coal + gas |
| 🇨🇩 DRC | 35 g CO₂/kWh | ×0.78 | SNE 2023 — Inga hydropower |
| 🇸🇳 Senegal | 500 g CO₂/kWh | ×11.1 | SENELEC 2023 — fuel + gas |
| 🇰🇪 Kenya | 120 g CO₂/kWh | ×2.67 | KPLC 2023 — geothermal + hydro |
| 🇬🇧 UK | 170 g CO₂/kWh | ×3.78 | National Grid 2023 — wind + gas |
| 🇩🇪 Germany | 350 g CO₂/kWh | ×7.78 | UBA 2023 — coal + renewables |

### Worked example — 35 kg Copper, Morocco

**CO₂ net gain (avoided)** — unchanged, universal:
- 0.035 t × 0.141 (ADEME net factor) = **0.00494 t = 4.94 kg CO₂ avoided**

**CO₂ process (produced by recycler)** — adjusted to Moroccan grid:
- Base factor (France ref): 1.304 t CO₂/t
- Grid multiplier Morocco: ×14.3 (644 g/kWh ÷ 45 g/kWh)
- Adjusted factor: 1.304 × 14.3 = **18.65 t CO₂/t**
- For 35 kg: 0.035 × 18.65 = **0.653 t CO₂ produced**

This reflects the reality that recycling copper in Morocco, where the grid is predominantly coal-fired, is significantly more carbon-intensive than in France or the DRC.

> **Scientific status:** Grid intensity values are country-level averages from IEA and national TSO reports. Sub-national and site-level variation is not captured. The adjustment is proportional and conservative. Phase 2 will refine this with actual energy consumption data from recycling facilities.

---

## 5. Section 2 — Child Health Impact (ERRI)

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

## 5b. Section 2b — Micro-Local Density (AI-Assisted)

### Why a fixed country multiplier is too coarse

The population density multiplier above is applied uniformly across an entire country. This is a reasonable starting approximation, but it ignores intra-country variation: collecting or processing batteries in a dense informal settlement (e.g. Sidi Moumen, Casablanca) carries a materially different child exposure risk than the same activity in a sparsely populated rural area of the same country, even though both currently receive Morocco's single ×1.2 multiplier.

### AI-assisted approach

When the AI module is enabled and a free-text location (city, neighborhood, commune) is available for the batch, `fpt_ai_erri_multiplier()` evaluates that specific location instead of falling back to the country-wide constant. The model is asked to assess population density, the presence of informal residential zones, and the general zone type (industrial, residential, rural, peri-urban) from the location text alone — it does not have access to satellite imagery or official census data.

```
density_multiplier = fpt_ai_erri_multiplier(location, waste_type)   if AI enabled and location provided
density_multiplier = fixed country constant (Section 5)              otherwise
```

The returned multiplier is bounded to the range **0.3 – 2.5** regardless of what the model outputs, to prevent an erroneous response from producing an implausible ERRI value. As with material classification (Section 2b), the AI's output is a single bounded number that feeds into the exact same downstream formula — it does not alter the ERRI formula itself.

### Worked example

- Batch: 200 kg lead batteries, location "Sidi Moumen, Casablanca"
- Country-wide multiplier (Morocco): ×1.2
- AI-assessed multiplier for this specific neighborhood (dense informal urban): potentially higher, e.g. ×1.6, reflecting the documented population density of that district relative to the national average

### Fallback

If the AI call fails, the location is empty, or the AI module is disabled, `fpt_get_population_density_multiplier()` falls back to the fixed country-level constants from Section 5 — the same values used since v1.6.0.

> **Scientific status:** this is a transitional, text-based heuristic, not a substitute for the geospatial population density model planned for Phase 2 (distance-decay, actual census/satellite data). It should be read as a refinement of the per-country approximation, not as a clinically validated measurement.

---

## 5c. Section 3 — Buyer Matching Triangulation (AI-Assisted)

### Why this needs triangulation, not a lookup

Ranking partner buyers for a given batch requires combining three location signals that don't share a common format:

| Source | Location data shape |
|--------|---------------------|
| Seller listing | One location (e.g. "Paris, 93") |
| "Prix du jour" price table | A region hint per buyer per sub-type (e.g. "Aquitaine", "Île-de-France") |
| "Acheteurs réguliers" buyer listing | A free-text list of facility site names, sometimes 25+ per buyer, named individually rather than by city |

None of these is a structured address or a coordinate pair. A literal string match resolves the easy cases (seller and buyer share an explicit city name); everything else requires inference from place names, regional hints, and general geography — which is what the AI step provides.

### Principle: AI estimates location, PHP ranks deterministically

Same separation of concerns as Sections 2b and 5b:

```
AI responsibility   : seller location + buyer site names + region hint  →  proximity tier + approximate distance (km)
PHP responsibility  : tier + distance + price  →  sorted ranking (deterministic comparator)
```

The AI never decides the final order. It returns, per buyer, a tier from a closed enum (`meme_quartier` / `meme_ville` / `meme_region` / `region_differente` / `pays_different` / `inconnu`) and an approximate distance, both validated before use. The sort itself — tier ascending, then distance ascending, then Net Vendor price descending as the final tiebreaker — is plain PHP `usort()`, fully deterministic and auditable.

### Two-tier resolution: rule first, AI only when needed

1. **Free PHP rule** (`fpt_bm_rule_match_location()`) — literal substring match between the seller's location and any of the buyer's registered sites (accent- and hyphen-normalized). When it hits: instant, free, 100% certain — no AI call for that buyer.
2. **AI estimate** (`fpt_ai_estimate_buyer_distances()`) — only for buyers the rule could not resolve, batched into a single call per lot. The model is explicitly instructed to return `tier: "inconnu"` and low confidence rather than invent a precise distance for a place name it cannot locate with reasonable certainty.

### Worked example

- Lot: *Cuivre Mêlé*, seller located in **Paris, 93**
- Matched sub-type offers (from "Prix du jour"):

  | Acheteur | Région (indice) | Prix net vendeur |
  |----------|------------------|-------------------|
  | City Débarras | Île-de-France | 6,80 €/kg |
  | MP Métaux | Île-de-France | 6,48 €/kg |
  | Groupe Péna | Aquitaine | 7,20 €/kg |
  | G2D2 | Région lyonnaise | 6,08 €/kg |

- City Débarras has a registered site containing "Paris" → **rule match**, tier `meme_ville`, 0 km, no AI call needed
- The other three have no literal match → sent to the batched AI distance call, which returns `meme_region` for MP Métaux (Île-de-France ≈ Paris region) and `region_differente` for Groupe Péna (Aquitaine) and G2D2 (Lyon region)
- Final ranking: **City Débarras** first despite a lower price than Groupe Péna — location outranks price by design; among the `region_differente` buyers, price decides

### Fallback

If the AI module is disabled, the API call fails, or no seller location is available, buyers the literal rule cannot resolve are returned with `tier: "inconnu"` and no distance. They still appear in the ranking — price stays visible — but sort after every buyer with a known location. Nothing is silently dropped.

> **Scientific status:** distance and proximity-tier estimates are an AI-based geographic heuristic drawn from the model's general training knowledge — not a routing or geocoding calculation, since the project has no Maps/geocoding API key. Reliable for distinguishing "same region" from "opposite side of the country," materially less precise for separating two nearby sites of the same buyer. Each ranked row carries a confidence flag (`high` / `medium` / `low`) in the admin UI for human sanity-check before a sourcing decision. Phase 2 (Section 8) plans to replace this heuristic with an actual geocoding/routing API once buyer site addresses are captured as structured data.

---

## 6. Dashboard Equivalents

| Indicator | Formula | Source |
|-----------|---------|--------|
| Equivalent trees/year | CO₂ total (t) × 45 | FAO 2021: 1 tree absorbs ~22 kg CO₂/year |

---

## 7. Limitations & Validation Status

- Estimates based on global average emission factors
- Net gain factors assume average purity and typical recycling processes
- ERRI is indicative, not clinically validated
- Phase 2 will add statistical confidence intervals and locally validated factors

---

## 8. Next Steps

- **Real geocoding/routing API for buyer matching** (Section 5c) — replaces the AI-estimated distance heuristic with actual road-network distances once buyer site addresses are structured data instead of free text
- **Stripe multi-currency native** — MAD via local acquirer, removes the current EUR fallback
- **GitHub auto-update** across all country sites
- **Full Arabic, Lingala, Swahili keyword vocabularies** — reduces reliance on AI fallback in low-connectivity deployments

---

## 9. All Sources

| Source | Reference | Used for |
|--------|-----------|----------|
| ADEME Base Carbone | basecarbone.ademe.fr | CO₂ net gain factors |
| FEDEREC/ADEME ACV 2017 | federec.com | Steel, copper, paper net gains |
| ADEME 2024 | ademe.fr | Plastic recycling factors |
| **EPA WARM v16 (2024)** | epa.gov/warm | US recycling factors — cross-validation |
| **IEA Electricity (2024)** | iea.org | National grid carbon intensity — CO₂ process adjustment |
| **EPA eGRID (2023)** | epa.gov/egrid | US electricity grid mix — 380 g CO₂/kWh |
| **ONEE/MASEN (2024)** | masen.ma | Morocco electricity mix — 644 g CO₂/kWh |
| **RTE (2023)** | rte-france.com | France electricity mix — 45 g CO₂/kWh (reference) |
| IPCC AR6 (2022) | ipcc.ch/ar6 | Cross-validation |
| FAO (2021) | fao.org | Trees/year equivalent |
| WHO / OMS (2021) | who.int/data/gho | Lead exposure, ERRI coefficient |
| Pure Earth (2020) | pureearth.org | Cadmium & lead at African sites |
| EPA AP-42 (2022) | epa.gov | PM2.5 from open burning |
| UNEP (2018) | mercuryconvention.org | Mercury in e-waste |
| HEI (2020) | healtheffects.org | PM2.5 ERRI coefficient |
| UNICEF (2020) | unicef.org/reports/toxic-truth | Child lead exposure context |
| HCP Maroc | hcp.ma | Morocco demographic data |
| **Anthropic Claude API** | docs.claude.com | AI-assisted material classification (Section 2b), micro-local density assessment (Section 5b), and buyer location triangulation (Section 5c) — model `claude-haiku-4-5` |

---

*FerayPro Tracer v2.3.0 — Open Source MIT*
