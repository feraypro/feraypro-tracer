# FerayPro Tracer — Calculation Methodology

This document describes in full detail how FerayPro Tracer calculates CO₂ avoided and child health impact indicators for each recycled waste batch.

**Last updated:** 2026  
**Version:** 1.3.1  
**License:** MIT  
**Live demo:** [ma.feraypro.com/methodologie](https://ma.feraypro.com/methodologie) ; [cd.feraypro.com/methodologie](https://cd.feraypro.com/methodologie) ; [fr.feraypro.com/methodologie](https://fr.feraypro.com/methodologie)

---

## Table of Contents

1. [Input Data](#1-input-data)
2. [Material Detection](#2-material-detection)
3. [Section 1 — CO₂ Impact](#3-section-1--co₂-impact)
4. [Section 2 — Child Health Impact](#4-section-2--child-health-impact)
5. [Dashboard Equivalents](#5-dashboard-equivalents)
6. [Limitations](#6-limitations)
7. [Phase 2 — ML Refinement](#7-phase-2--ml-refinement)
8. [All Sources](#8-all-sources)

---

## 1. Input Data

All calculations use only data entered by the seller when publishing a listing:

| Field | Meta key | Role |
|-------|----------|------|
| Listing title | `post_title` | Material type detection |
| Weight (kg or lb) | `hp_poids` / `hp_weight` | Primary calculation variable |
| City | `hp_ville` / `hp_city` | Geolocation |
| Photo | `_thumbnail_id` | Visual proof |

**Note on pounds (lb):** For US listings, weight entered in lb is automatically converted to kg (× 0.453592) before all calculations.

If the weight field is empty or zero, no calculation is performed (buyer listing).

---

## 2. Material Detection

The plugin analyzes the listing title (lowercased, accent-stripped) and compares it against 200+ keywords in French and English. The first matching keyword determines the emission factor applied.

**Detection logic (PHP):**
```php
$titre_lower = strtolower( remove_accents( $titre ) );
foreach ( $factors as $keyword => $value ) {
    if ( strpos( $titre_lower, $keyword ) !== false ) {
        $factor = $value;
        break;
    }
}
```

If no keyword matches, the default conservative factor of **1.0 t CO₂/t** is applied.

### Keyword examples

| Listing title | Detected keyword | Factor applied |
|---------------|-----------------|----------------|
| "Cuivre diversifié" | `cuivre` | 3.5 t CO₂/t |
| "Diversified Copper" | `copper` | 3.5 t CO₂/t |
| "Ferraille lourde" | `ferraille` | 1.8 t CO₂/t |
| "Heavy Scrap Metal" | `scrap` | 1.8 t CO₂/t |
| "Moteur électrique" | `electrique` | 3.5 t CO₂/t |
| "Electric Motor" | `electric` | 3.5 t CO₂/t |
| "Véhicule hors d'usage" | `vehicule` | 1.8 t CO₂/t |
| "End-of-Life Vehicle" | `vehicle` | 1.8 t CO₂/t |
| "Batterie lithium" | `lithium` | 5.0 t CO₂/t |

---

## 3. Section 1 — CO₂ Impact

### Principle

Recycling one tonne of metal, paper, or plastic avoids the CO₂ emissions that would have been produced by:
- Extracting and processing virgin raw materials (mining, blast furnaces, refineries)
- Landfilling or open burning of waste at informal dumpsites

### Formula

```
CO₂ avoided (t) = Weight (kg) ÷ 1000 × CO₂ Factor (t CO₂ / t recycled)
```

### Example

- Batch: **Diversified Copper — 11 kg**
- Weight in tonnes: 11 ÷ 1000 = **0.011 t**
- Copper factor: **3.5 t CO₂/t** (ADEME Base Carbone)
- CO₂ avoided = 0.011 × 3.5 = **0.0385 t = 38.5 kg of CO₂**

### Complete CO₂ Factor Table

All factors sourced from **ADEME Base Carbone** (French Agency for Ecological Transition), the official French and internationally recognized emission factor database.

#### Ferrous Metals

| Material | Keywords (FR) | Keywords (EN) | Factor (t CO₂/t) | Justification |
|----------|--------------|--------------|-----------------|---------------|
| Aluminum | aluminium, alu | aluminum, can | **9.5** | Avoids Hall-Héroult electrolysis — highly energy-intensive |
| Stainless steel | inox, acier inox | stainless, stainless steel | **2.5** | Avoids virgin nickel and chromium addition |
| Iron / Steel | fer, acier, ferraille | iron, steel, scrap | **1.8** | Avoids blast furnace (pig iron → steel) |
| Cast iron | fonte | cast iron | **1.6** | Blast furnace — fewer steps than steel |
| Machining turnings | tournure, copeau, limaille | turnings, shavings, chips | **1.8** | Same as steel |
| Radiator | radiateur | radiator | **1.8** | Steel/cast iron composition |
| Spring | ressort | spring | **1.8** | Steel composition |
| Rail | rail | rail | **1.8** | Steel composition |
| Rebar | fer à béton, fer beton | rebar, reinforcing bar | **1.8** | Steel composition |
| Boiler | chaudière | boiler | **1.8** | Steel composition |

#### Non-Ferrous Metals

| Material | Keywords (FR) | Keywords (EN) | Factor (t CO₂/t) | Justification |
|----------|--------------|--------------|-----------------|---------------|
| Copper | cuivre | copper | **3.5** | Avoids pyrometallurgy |
| Bronze | bronze | bronze | **3.2** | Copper/tin alloy (~85% copper) |
| Brass | laiton | brass | **3.0** | Copper/zinc alloy |
| Zinc | zinc | zinc | **2.0** | Avoids ore roasting and reduction |
| Lead | plomb | lead | **1.2** | Avoids galena smelting |
| Tin | etain, étain | tin | **4.0** | Energy-intensive process |
| Nickel | nickel | nickel | **6.5** | Very intensive mining extraction |
| Titanium | titane | titanium | **5.0** | Energy-intensive Kroll process |
| Magnesium | magnesium, magnésium | magnesium | **7.0** | Magnesium chloride electrolysis |
| Chromium | chrome | chromium | **3.0** | Ferrochrome production avoidance |
| Tungsten carbide | carbure | carbide, tungsten | **3.5** | Hard metals recycling |
| Cobalt | cobalt | cobalt | **8.0** | Critical mineral — intensive extraction |
| Silver | argent | silver | **8.0** | Precious metal — electrolytic refining |
| Gold | or | gold | **15.0** | Most energy-intensive mining process |
| Platinum | platine | platinum | **12.0** | Precious metal — concentrated ore |
| Palladium | palladium | palladium | **10.0** | Precious metal |

#### Vehicles & Auto Parts

| Material | Keywords (FR/EN) | Factor (t CO₂/t) | Notes |
|----------|-----------------|-----------------|-------|
| End-of-life vehicle | vehicule, voiture, camion, car, truck, vehicle | **1.8** | Predominantly steel |
| Electric motor | moteur, motor, engine, electrique, electric | **2.2–3.5** | Mixed copper + steel + aluminum |
| Alternator | alternateur, alternator | **2.5** | Copper windings dominant |
| Gearbox/Transmission | boite, gearbox, transmission | **1.8** | Steel |
| Aluminum rim | jante, rim, wheel | **9.5** | Aluminum alloy |
| Catalytic converter | catalyseur, catalytic, catalyst | **5.0** | Platinum, palladium, rhodium |
| Turbocharger | turbo, turbocharger | **2.0** | Mixed steel/aluminum |

#### Electronics & E-waste

| Material | Keywords (FR/EN) | Factor (t CO₂/t) | Notes |
|----------|-----------------|-----------------|-------|
| E-waste (general) | electronique, ewaste, e-waste, electronics | **4.0** | High precious metal density |
| Smartphone/Phone | telephone, smartphone, phone, mobile | **4.5** | Higher precious metal content |
| Computer/Server | ordinateur, computer, laptop, server | **4.0** | PCB + metals |
| Screen/Monitor | ecran, moniteur, screen, monitor | **3.5** | Contains mercury + metals |
| TV | television, tv | **3.5** | Similar to monitor |
| Cable/Wire | cable, fil, wire, wiring | **3.0** | Copper content |
| Air conditioner | climatiseur, air conditioner | **3.5** | Copper + aluminum + refrigerant |
| Refrigerator | refrigerateur, frigo, fridge | **3.0** | Steel + copper + refrigerant |
| Washing machine | lave linge, washing machine | **2.5** | Steel dominant |
| Solar panel | panneau solaire, solar panel | **3.0** | Silicon + silver + aluminum |

#### Batteries & Energy Storage

| Material | Keywords (FR/EN) | Factor (t CO₂/t) |
|----------|-----------------|-----------------|
| Lithium battery | batterie lithium, lithium battery, li-ion | **5.0** |
| Lead-acid battery | batterie, battery, accumulateur | **2.5** |

#### Paper, Plastic & Other

| Material | Keywords (FR/EN) | Factor (t CO₂/t) | Justification |
|----------|-----------------|-----------------|---------------|
| Paper / Cardboard | papier, carton, paper, cardboard | **0.9** | Avoids deforestation + landfill methane |
| PET Plastic | plastique, pet, plastic | **1.5** | Avoids naphtha cracking |
| HDPE | hdpe | **1.4** | High-density polyethylene |
| PVC | pvc | **1.3** | Chlorinated plastic |
| Tires / Rubber | pneu, caoutchouc, tire, rubber | **1.2** | Avoids synthetic production + burning |
| Glass | verre, glass | **0.3** | Re-melting less intensive than virgin |
| Wood / Pallets | bois, palette, wood, pallet | **0.4** | Carbon sequestration value |
| Textiles | textile, tissu, textile, clothing | **0.5** | Avoids virgin fiber production |
| **Default (unrecognized)** | — | **1.0** | Conservative value |

---

## 4. Section 2 — Child Health Impact

### Background

Informal recycling is one of the main sources of toxic heavy metal exposure for children living near collection and processing sites. The most harmful practices documented in Morocco and the DRC include:

- **Open cable burning** to extract copper → generates PM2.5 particles and dioxins
- **Lead battery dismantling** without protection → disperses lead dust
- **E-waste processing** without equipment → releases cadmium and mercury

By routing waste through the formal FerayPro marketplace, these batches are diverted from informal processing, directly reducing these exposures.

### Indicator A — Lead Not Dispersed (kg)

```
Lead avoided (kg) = Σ [ Weight (t) × 0.5 ]
Applied to batches containing: plomb, batterie, accumulateur, soudure, peinture, radiateur,
                                lead, battery, accumulator, solder, paint, radiator
```

**Factor 0.5 kg/t:** Conservative estimate based on:
- Pure Earth Toxic Sites Database (2016): average lead dispersal from uncontrolled scrap metal processing
- WHO Lead Exposure Report (2021): field measurements at informal recycling sites in North Africa

**Health impact:** Lead exposure causes irreversible cognitive damage, IQ reduction, and developmental delays in children. The WHO states there is **no safe threshold** for blood lead in children.

### Indicator B — PM2.5 Avoided (kg)

```
PM2.5 avoided (kg) = Σ [ Weight (t) × 15 ]
Applied to batches containing: cable, câble, fil, cuivre, plastique, pvc, caoutchouc, pneu,
                                wire, copper, plastic, rubber, tire
```

**Factor 15 kg/t:** Based on:
- EPA AP-42 (2022): Compilation of Air Pollutant Emission Factors — Open Burning section
- Field measurements confirm ~15 kg PM2.5 per tonne of cables burned in open-air conditions

**Health impact:** Fine particle PM2.5 causes chronic respiratory disease, asthma, and delayed lung development in children. WHO 2021 air quality guidelines set the annual mean limit at 5 μg/m³.

### Indicator C — Cadmium Avoided (g)

```
Cadmium avoided (g) = Σ [ Weight (t) × 200 ]
Applied to batches containing: pile, batterie lithium, electronique, ewaste, plastique, telephone, ordinateur, smartphone,
                                battery, e-waste, electronics, plastic, phone, computer, smartphone
```

**Factor 200 g/t:** Based on:
- Pure Earth Toxic Sites Database (2020): cadmium content measurements at African e-waste sites
- UNEP Global Mercury and Cadmium Assessment (2018)

**Health impact:** Chronic cadmium exposure causes irreversible kidney damage (Itai-Itai disease), bone fractures, and growth disorders in children.

### Indicator D — Mercury Avoided (g)

```
Mercury avoided (g) = Σ [ Weight (t) × 50 ]
Applied to batches containing: ecran, moniteur, television, tv, lampe, neon, thermometre,
                                screen, monitor, television, tv, lamp, neon, thermometer
```

**Factor 50 g/t:** Based on:
- UNEP Minamata Convention Background Report (2018): average mercury content of end-of-life electronic equipment

**Health impact:** Mercury is a potent neurotoxin. Even trace prenatal and childhood exposure causes permanent neurological damage, motor impairment, and IQ reduction.

### Synthetic Estimate — Children Protected

```
Children Protected = (Lead avoided kg × 50) + (PM2.5 avoided kg × 10)
```

**Coefficient lead × 50:**  
Per WHO Global Health Observatory (2021): 1 kg of lead dispersed within a 500m radius in dense urban areas of North and sub-Saharan Africa affects approximately 50 children under 5 years old.

**Coefficient PM2.5 × 10:**  
Per Health Effects Institute (2020), State of Global Air — Africa: 1 kg of PM2.5 avoided reduces at-risk exposure for approximately 10 children in peri-urban areas.

> **Important:** This estimate quantifies the order of magnitude of collective exposure reduction. It does not measure individual clinical cases. It is presented as an indicative estimate with explicit conservative assumptions.

---

## 5. Dashboard Equivalents

| Indicator | Formula | Source |
|-----------|---------|--------|
| Equivalent trees/year | CO₂ total (t) × 45 | FAO 2021: 1 mature tree absorbs ~22 kg CO₂/year |
| Car km avoided | CO₂ total (t) × 6,000 | ADEME: average car ~120g CO₂/km |

---

## 6. Limitations

These calculations are **estimates based on regional averages and standard emission factors**. Actual values may vary significantly depending on:

- The exact purity and composition of each batch
- The specific informal processing method being replaced
- The distance between the collection site and residential areas
- Local population density and age distribution
- Prevailing meteorological conditions (wind direction, rainfall)
- The actual recycling process used by the formal buyer

These factors are accounted for in Phase 2 through local field data collection and ML refinement.

---

## 7. Phase 2 — ML Refinement

In Phase 2 (2026–2027), the global average factors will be complemented by a Machine Learning layer trained on field data from Morocco and the DRC:

### Random Forest Model
- **Input features:** batch purity (%), transport distance (km), processing method, city, material type, weight
- **Output:** refined CO₂ factor with 95% confidence interval
- **Training data:** systematic field measurements from partner recyclers in Casablanca, Ile de France, and Kinshasa

### Geospatial Health Model
- Distance-decay functions for pollutant dispersion from collection sites
- Integration with population density maps (HCP Morocco, DRC census)
- Cross-reference with Pure Earth toxic sites database
- Output: health impact radius maps displayed on feraypro.com/impact/map

### Keyword Expansion
- Arabic material names (Morocco)
- Lingala material names (DRC — Kinshasa)
- Swahili material names (DRC — East, Kenya)
- TF-IDF fallback for unrecognized titles

All model code will be published open source in this repository.

---

## 8. All Sources

| Source | Reference | Used for |
|--------|-----------|----------|
| ADEME Base Carbone | basecarbone.ademe.fr | All CO₂ emission factors |
| IPCC AR6 (2022) | ipcc.ch/ar6 | Heavy industry cross-validation |
| FAO (2021) | fao.org/forestry | Trees/year equivalent |
| EPA (2023) | epa.gov/ghgemissions | GHG inventory cross-validation |
| EPA AP-42 (2022) | epa.gov/air-emissions-factors | PM2.5 from open burning |
| WHO / OMS (2021) | who.int/data/gho | Lead exposure, children protected coefficient |
| Pure Earth (2020) | pureearth.org | Cadmium & lead at African sites |
| UNEP (2018) | mercuryconvention.org | Mercury in e-waste |
| HEI (2020) | healtheffects.org | PM2.5 exposure coefficient |
| UNICEF (2020) | unicef.org/reports/toxic-truth | Child lead exposure context |
| HCP Maroc | hcp.ma | Morocco demographic data |

---

*FerayPro Tracer — MIT License — [ma.feraypro.com/methodologie](https://ma.feraypro.com/methodologie) ; [cd.feraypro.com/methodologie](https://cd.feraypro.com/methodologie) ; [fr.feraypro.com/methodologie](https://fr.feraypro.com/methodologie)*
