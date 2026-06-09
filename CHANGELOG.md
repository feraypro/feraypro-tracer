# Changelog — FerayPro Tracer

All notable changes to this project are documented here.  
Format: [Keep a Changelog](https://keepachangelog.com) · [Semantic Versioning](https://semver.org)

---

## [2.1.1] — 2026

### Removed
- **"Bilan net CO₂"** (`évité − produit`) supprimé du dashboard acheteur `[fpt_acheteur]`
  - Le CO₂ évité (gain net ADEME, baseline production primaire) et le CO₂ process (émissions recycleur, baseline zéro, ajusté mix électrique local) n'ont pas la même baseline — leur soustraction n'a pas de sens physique
  - Les deux cartes restent affichées côte à côte sans agrégation

### Changed
- Version bump 2.1.0 → 2.1.1
- Entrées de traduction `net_co2` et `avoided_minus` vidées (rétrocompatibilité)

---

## [2.1.0] — 2026

### Added
- **`fpt_grid_intensity()`** — Nouvelle fonction d'intensité carbone du réseau électrique par pays
  - Table de 35+ pays avec valeurs en g CO₂/kWh (IEA 2024, EPA eGRID 2023, ONEE/MASEN 2024, RTE 2023)
  - Retourne un multiplicateur normalisé par rapport à la référence France (45 g CO₂/kWh, base ADEME)
  - Option de surcharge manuelle `fpt_grid_intensity_override` (en g CO₂/kWh, 0 = auto)
  - Valeurs clés : 🇫🇷 France 45 g/kWh (×1,00) · 🇺🇸 USA 380 (×8,44) · 🇲🇦 Maroc 644 (×14,3) · 🇨🇩 RDC 35 (×0,78)

- **Ajustement CO₂ process au mix électrique local** (`fpt_calculate_process_co2()`)
  - Le CO₂ produit par le recycleur (dashboard acheteur) est maintenant ajusté à l'intensité carbone du réseau local
  - Les facteurs de base ADEME/FEDEREC (calibrés sur le mix français) sont multipliés par `grid_multiplier`
  - Le CO₂ évité (gain net, `fpt_co2_factors()`, dashboard public) reste **inchangé** — universel ADEME/FEDEREC
  - Exemples pour 1 tonne d'aluminium recyclé :

    | Pays | CO₂ process (avant) | CO₂ process (après) |
    |------|--------------------|--------------------|
    | France | 0,360 t | 0,360 t (×1,00) |
    | USA | 0,360 t | 3,04 t (×8,44) |
    | Maroc | 0,360 t | 5,15 t (×14,3) |
    | RDC | 0,360 t | 0,28 t (×0,78) |

- **Card admin "Mix électrique"** dans FP Tracer → ⚙️ Paramètres
  - Affiche en temps réel : pays détecté, intensité g CO₂/kWh, multiplicateur
  - Exemples concrets alu / cuivre / acier recalculés avec le multiplicateur du pays
  - Champ de surcharge manuelle (0 = détection automatique)

- **Nouveau meta key WordPress** (non stocké — calculé dynamiquement)

- **Nouvelles sources documentées**
  - EPA WARM v16 (2024) — cross-validation facteurs USA
  - IEA Electricity (2024) — intensité carbone réseaux nationaux
  - EPA eGRID (2023) — mix électrique USA
  - ONEE/MASEN (2024) — mix électrique Maroc
  - `[fpt_methodologie]` et dashboard acheteur mis à jour

### Changed
- `fpt_calculate_process_co2()` : application du multiplicateur `fpt_grid_intensity()` sur le facteur process
- Dashboard acheteur `[fpt_acheteur]` : note de bas de page affiche dynamiquement l'intensité et le multiplicateur du pays
- `fpt_admin_page()` : nouvelle card "Mix électrique" dans l'onglet ⚙️ Paramètres
- `fpt_save_settings()` : sauvegarde de `fpt_grid_intensity_override`
- Version bump 2.0.0 → 2.1.0
- README, METHODOLOGY mis à jour

### Notes
- Rétrocompatibilité totale : aucune meta key existante modifiée, aucun shortcode changé
- Les valeurs CO₂ process historiques stockées en base (`_fpt_co2_transport`, `_fpt_co2_total`) ne sont pas recalculées automatiquement. Utiliser **FP Tracer → 🔧 Outils → Recalculer CO₂ transport** après changement de pays.

---

## [2.0.0] — 2026

### Added
- **Module Paiement Stripe** — `modules/stripe/stripe.php`
  - Stripe Checkout Session (mode `payment`) — redirige l'acheteur vers une page de paiement sécurisée Stripe
  - Bouton **"💳 Payer en ligne"** injecté dans la facture PDF/HTML après les modes de règlement manuels (IBAN, Mobile Money)
  - Confirmation automatique du paiement via **webhook Stripe** (`checkout.session.completed`) — aucune intervention admin requise
  - Vérification de signature HMAC-SHA256 native (sans SDK tiers) avec protection anti-replay (fenêtre 5 min)
  - Calcul automatique du montant : commission 20% + TVA configurée
  - Fallback devise : MAD et CDF non supportés par Stripe → conversion automatique en EUR
  - Pré-remplissage email acheteur dans la Checkout Session si disponible (`_fpt_acheteur_email` ou `hp_email`)
  - Statut Stripe visible dans la **metabox admin** du lot : Session ID, Payment Intent ID, date de confirmation
  - **Bandeau mode TEST** dans la facture avec carte de test (4242 4242 4242 4242)
  - Réglages dans FP Tracer → ⚙️ Paramètres : mode test/live, 4 clés API (pub + secret × 2), webhook secret, URL webhook à copier
  - Log interne des paiements Stripe (`fpt_stripe_payment_log`)
  - Architecture 100% modulaire — `do_action` hooks, zéro modification invasive du plugin principal

- **Nouveaux meta keys WordPress**

  | Meta key | Type | Description |
  |----------|------|-------------|
  | `_fpt_stripe_session_id` | string | ID Checkout Session Stripe |
  | `_fpt_stripe_payment_intent` | string | ID Payment Intent confirmé |
  | `_fpt_stripe_paid_at` | int (timestamp) | Date/heure confirmation webhook |

- **Nouveaux hooks WordPress**

  | Hook | Paramètres | Description |
  |------|-----------|-------------|
  | `fpt_admin_settings_extra_cards` | — | Injecte la carte Stripe dans les réglages admin |
  | `fpt_invoice_payment_methods` | `$lot_id`, `$comm20_ttc` | Injecte le bouton Stripe dans la facture |
  | `fpt_metabox_after_commission` | `$post_id` | Injecte le statut Stripe dans la metabox lot |

### Changed
- Version bump 1.9.0 → 2.0.0
- `feraypro-tracer.php` : `require_once` du module Stripe au démarrage (ligne 20)
- `feraypro-tracer.php` : 3 `do_action` ajoutés (metabox, réglages admin, facture)
- README mis à jour : structure plugin, shortcodes, meta keys, installation, roadmap

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
