# Changelog — FerayPro Tracer

All notable changes to this project are documented here.  
Format: [Keep a Changelog](https://keepachangelog.com) · [Semantic Versioning](https://semver.org)

---

## [1.9.0] — 2026

### Added
- **Module Dashboard Financier** — shortcode `[fpt_dashboard_finance]`
  - Ventes totales, commissions FerayPro (20%), commissions partenaires (10%), vendeurs (80%)
  - Pipeline des lots : tracés / collectés / en attente / sans prix / impayés
  - Graphique mensuel 12 mois (Chart.js, CDN)
  - Répartition dynamique des revenus (split ajusté selon présence de partenaires)
  - Détail des commissions impayées avec lien admin direct
  - Top partenaires marketing : commissions payées vs à percevoir
  - Top 10 acheteurs : volume, poids, CO₂
  - Paramètres `period` (7 / 30 / 90 / 365 / 0=all) et `lang` (fr / en / auto)
  - TVA automatiquement incluse si `fpt_tva_rate > 0`
  - CSS responsive + print-friendly, design system FerayPro cohérent
- **Architecture modulaire** — dossier `modules/finance/` (finance.php + finance.css)
- Shortcode `[fpt_dashboard_finance]` ajouté à la liste dans FP Tracer → ⚙️ Paramètres

### Changed
- Version bump 1.8.0 → 1.9.0
- Description du plugin mise à jour (mention dashboard financier)
- `feraypro-tracer.php` : `require_once` du module finance au démarrage

### Fixed
- Split revenus : vendeur affiché correctement à **80%** (et non 70%)
- Pourcentages du split calculés sur montants réels (mix lots avec/sans partenaires)

---

## [1.8.0] — 2026

### Added
- **Module Commission & Facturation**
  - Split 80/20 automatique (vendeur / FerayPro), 10% partenaire si `_fpt_ref` présent
  - Facture PDF A4 imprimable (logo WordPress, TVA configurable, IBAN + Mobile Money)
  - Bouton "Marquer comme payée" (AJAX) avec date de paiement
  - Numéro de facture automatique `FP-INV-YYYYMM-ID`
  - Devise configurable (50+ devises mondiales), détection automatique par domaine
  - Champ prix dans la metabox de collecte (AJAX save)
  - TVA : 0% (Maroc/USA), 16% (RDC), 20% (France)
- **Système partenaires — Affiliate tracking**
  - Cookie 30 jours `?ref=slug`
  - Attribution automatique `_fpt_ref` à la publication
  - Bannière partenaire sur l'annonce publique
  - Dashboard admin partenaires (clics, lots, conversion, CO₂, commissions)
  - Shortcode `[fpt_partenaires]` — grille publique
  - Commission % configurable par partenaire

### Changed
- Version bump 1.7.x → 1.8.0

---

## [1.7.0] — 2025

### Added
- Shortcode `[fpt_acheteur id="X"]` — dashboard CO₂ produit par acheteur
- Preuves de pesée (upload AJAX, photos, PDF, bon de bascule)
- Annulation de collecte depuis la metabox admin
- Correction CO₂ transport (formule correcte : poids_t × distance × 0.062)

### Changed
- `fpt_co2_factors()` : 200+ mots-clés matières (FR + EN + Darija + Lingala + Swahili)

---

## [1.6.0] — 2025

### Added
- ERRI (Exposure Risk Reduction Index) avec multiplicateur densité démographique par pays
- Health co-occurrence fix — multi-polluants par lot (Pb, PM2.5, Cd, Hg indépendants)
- Shortcode `[fpt_methodologie]` — page méthodologie complète

---

## [1.5.0] — 2025

### Added
- QR code par lot (API qrserver.com)
- Batch Digital ID `FP-XXXXXXXX`
- Dashboard public `[fpt_dashboard]` avec indicateurs santé enfants
- Support multisite WordPress (subdomain)

---

## [1.0.0] — 2024

### Added
- CO₂ net gain engine (ADEME Base Carbone / FEDEREC ACV 2017)
- Détection matière multilingue (FR + EN)
- Metabox collecte admin
- Bloc inline sur annonce HivePress
- Shortcode `[fpt_lot id="X"]`
- Support kg/lb, multi-pays (Maroc, RDC, France, USA)
