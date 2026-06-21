<?php
/**
 * FerayPro Tracer — Module E : Matching Acheteurs
 * Classe les acheteurs partenaires pour chaque lot "Annonces déchets",
 * par PERTINENCE = localisation d'abord (du plus proche au plus loin),
 * prix Net Vendeur en départage à localisation égale/proche.
 *
 * Pipeline :
 *  1. Trouve la fiche "Prix du jour" matchée pour le lot (réutilise fpt_get_prix_du_jour()
 *     du plugin principal — AUCUNE modification de ce fichier requise, on récupère l'ID
 *     via url_to_postid() sur le permalink déjà retourné).
 *  2. IA : extrait du tableau de prix (texte libre, sous-types multiples) la liste des
 *     acheteurs + prix du SOUS-TYPE correspondant au lot.
 *  3. Résout chaque nom d'acheteur vers sa fiche HivePress "Acheteurs réguliers"
 *     (match exact puis flou par similar_text — pas d'IA, c'est du texte structuré).
 *  4. Localisation : règle PHP gratuite (correspondance littérale) en priorité,
 *     IA en complément UNIQUEMENT pour les acheteurs non résolus par la règle —
 *     l'IA doit répondre "inconnu" plutôt qu'inventer une distance si le lieu est ambigu.
 *  5. Tri : tier de proximité ASC → distance_km ASC → prix_net_vendeur DESC.
 *  6. Stocke le résultat en post meta + affiche dans une metabox admin du lot,
 *     avec bouton de recalcul manuel (AJAX).
 *
 * Dépendances (déjà présentes dans le plugin, aucune modification requise) :
 *   - feraypro-tracer.php : fpt_normalize_text(), fpt_key_ville(), fpt_get_prix_du_jour(),
 *                            options fpt_acheteurs_cat_slug / fpt_prix_cat_slug
 *   - modules/ai/ai.php   : fpt_ai_call_cached(), fpt_ai_parse_json(), option fpt_ai_enabled
 *
 * Installation : ajouter dans feraypro-tracer.php, à la suite des autres require_once :
 *   require_once FPT_PLUGIN_DIR . 'modules/ai/ai-buyer-matching.php';
 *
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FPT_BM_CACHE_TTL_OFFERS', 12 * HOUR_IN_SECONDS ); // tableau de prix = volatile
define( 'FPT_BM_CACHE_TTL_DIST',   DAY_IN_SECONDS );        // localisations = stables


// ─────────────────────────────────────────────────────────────────────────────
// 1. HELPERS — catégories & fiches acheteurs
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Vrai si le lot est une "Annonce déchets" (ni acheteur, ni prix du jour).
 */
function fpt_bm_is_waste_listing( int $post_id ): bool {
    $acheteur_slugs = array_map( 'trim', explode( ',', get_option( 'fpt_acheteurs_cat_slug', 'acheteurs' ) ) );
    $prix_slug      = get_option( 'fpt_prix_cat_slug', 'prix' );

    if ( has_term( $acheteur_slugs, 'hp_listing_category', $post_id ) ) return false;
    if ( has_term( [ $prix_slug ], 'hp_listing_category', $post_id ) )  return false;
    return true;
}

/**
 * Toutes les fiches "Acheteurs réguliers" publiées (cache statique le temps de la requête).
 */
function fpt_bm_get_buyer_listings(): array {
    static $cache = null;
    if ( $cache !== null ) return $cache;

    $slug = get_option( 'fpt_acheteurs_cat_slug', 'acheteurs' );

    $cache = get_posts([
        'post_type'   => 'hp_listing',
        'post_status' => 'publish',
        'numberposts' => -1,
        'tax_query'   => [[
            'taxonomy' => 'hp_listing_category',
            'field'    => 'slug',
            'terms'    => $slug,
        ]],
    ]);

    return $cache;
}

/**
 * Résout un nom d'acheteur (extrait du tableau de prix) vers sa fiche HivePress.
 * Match exact normalisé en priorité, puis similarité texte (seuil 65%).
 *
 * @return array{id:int,title:string,score:float}|null
 */
function fpt_bm_resolve_buyer( string $acheteur_name, array $buyer_listings ): ?array {
    if ( empty( $acheteur_name ) || empty( $buyer_listings ) ) return null;

    $norm_target = fpt_normalize_text( $acheteur_name );

    foreach ( $buyer_listings as $p ) {
        if ( fpt_normalize_text( $p->post_title ) === $norm_target ) {
            return [ 'id' => $p->ID, 'title' => $p->post_title, 'score' => 1.0 ];
        }
    }

    $best = null;
    $best_pct = 0.0;
    foreach ( $buyer_listings as $p ) {
        similar_text( $norm_target, fpt_normalize_text( $p->post_title ), $pct );
        if ( $pct > $best_pct ) {
            $best_pct = $pct;
            $best     = $p;
        }
    }

    if ( $best && $best_pct >= 65 ) {
        return [ 'id' => $best->ID, 'title' => $best->post_title, 'score' => round( $best_pct / 100, 2 ) ];
    }

    return null;
}

/**
 * Liste des sites d'un acheteur, lus depuis le champ "Votre Ville + votre Quartier"
 * (même clé meta que pour les vendeurs — fpt_key_ville()). Sépare par virgule,
 * point-virgule ou retour à la ligne ; filtre les valeurs vides et "..." de troncature.
 */
function fpt_bm_get_buyer_sites( int $buyer_post_id ): array {
    $raw = get_post_meta( $buyer_post_id, fpt_key_ville(), true );
    if ( empty( $raw ) ) return [];

    $parts = preg_split( '/[\r\n,;]+/', $raw );
    $parts = array_map( 'trim', $parts );
    $parts = array_filter( $parts, function( $s ) {
        return $s !== '' && $s !== '...' && $s !== '…';
    } );
    $parts = array_values( array_unique( $parts ) );

    // Borne raisonnable (réseaux à 25+ sites type DECONS) pour ne pas saturer le prompt IA.
    return array_slice( $parts, 0, 40 );
}

/**
 * Règle PHP gratuite : correspondance littérale entre la localisation du vendeur
 * et l'un des sites de l'acheteur. Pas d'IA — instantané, fiable à 100% quand ça matche.
 */
function fpt_bm_rule_match_location( string $seller_location, array $buyer_sites ): bool {
    // Normalisation locale supplémentaire : tirets/apostrophes → espaces, pour que
    // "Sainte-Radegonde" matche "Sainte Radegonde" (variantes de saisie courantes).
    $loosen = function( string $s ): string {
        return trim( preg_replace( '/\s+/', ' ', str_replace( [ '-', "'" ], ' ', fpt_normalize_text( $s ) ) ) );
    };

    $seller_norm = $loosen( $seller_location );
    if ( $seller_norm === '' ) return false;

    foreach ( $buyer_sites as $site ) {
        $site_norm = $loosen( $site );
        if ( $site_norm === '' ) continue;
        if ( $site_norm === $seller_norm )                  return true;
        if ( strpos( $site_norm, $seller_norm ) !== false )  return true;
        if ( strpos( $seller_norm, $site_norm ) !== false )  return true;
    }
    return false;
}

/**
 * Trouve l'ID de la fiche "Prix du jour" matchée pour ce lot, en réutilisant
 * fpt_get_prix_du_jour() SANS le modifier (on retrouve l'ID via son permalink).
 */
function fpt_bm_find_prix_listing_id( string $titre_lot ): ?int {
    if ( ! function_exists( 'fpt_get_prix_du_jour' ) ) return null;

    $data = fpt_get_prix_du_jour( $titre_lot );
    if ( empty( $data['url'] ) ) return null;

    $id = url_to_postid( $data['url'] );
    return $id ? (int) $id : null;
}


// ─────────────────────────────────────────────────────────────────────────────
// 2. IA — EXTRACTION DU SOUS-TYPE + OFFRES DEPUIS LE TABLEAU DE PRIX
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Identifie le sous-type de matière correspondant au lot dans le tableau de prix
 * (texte libre, plusieurs sous-types) et extrait la liste d'acheteurs + prix de
 * CETTE section uniquement.
 *
 * @return array{sous_type_matched:?string,match_confidence:string,offers:array,source:string}
 */
function fpt_ai_extract_buyer_offers( int $prix_post_id, string $lot_title, string $raw_description, bool $force = false ): array {
    $cache_key = 'fpt_bm_offers_' . md5( $prix_post_id . '|' . strtolower( trim( $lot_title ) ) . '|' . md5( $raw_description ) );
    if ( $force ) delete_transient( $cache_key );

    if ( ! function_exists( 'fpt_ai_call_cached' ) || ! get_option( 'fpt_ai_enabled', false ) ) {
        return [ 'sous_type_matched' => null, 'match_confidence' => 'low', 'offers' => [], 'source' => 'ai_disabled' ];
    }

    $system = 'Tu es un expert en extraction de tableaux de prix pour le recyclage de métaux. Tu réponds UNIQUEMENT en JSON valide, sans markdown, sans explication.';

    $prompt = <<<PROMPT
Lot à valoriser : "{$lot_title}"

Tableau de prix brut (plusieurs sous-types de matière, chacun avec sa liste d'acheteurs) :
---
{$raw_description}
---

1. Identifie la section de sous-type la plus pertinente pour ce lot (ex. "Cuivre Mêlé", "Cuivre dénudé neuf Millberry"...).
2. Extrais la liste complète des acheteurs de CETTE section uniquement, avec leur région/spécificité et leurs prix.
3. Utilise le prix "Net Vendeur" (après commission) comme prix de référence — c'est ce que le vendeur touche réellement.

Retourne uniquement :
{
  "sous_type_matched": "nom exact du sous-type identifié",
  "match_confidence": "high|medium|low",
  "offers": [
    {
      "acheteur": "nom de l'acheteur/centre",
      "region_hint": "région ou spécificité mentionnée",
      "prix_brut": 0.0,
      "prix_net_vendeur": 0.0,
      "devise_unit": "€/kg"
    }
  ]
}

Si aucun sous-type ne correspond raisonnablement, retourne "sous_type_matched": null et "offers": [].
PROMPT;

    $raw = fpt_ai_call_cached( $cache_key, $prompt, $system, FPT_BM_CACHE_TTL_OFFERS );

    if ( is_wp_error( $raw ) ) {
        return [ 'sous_type_matched' => null, 'match_confidence' => 'low', 'offers' => [], 'source' => 'fallback', 'error' => $raw->get_error_message() ];
    }

    $data = fpt_ai_parse_json( $raw );
    if ( ! $data || empty( $data['offers'] ) || ! is_array( $data['offers'] ) ) {
        return [ 'sous_type_matched' => $data['sous_type_matched'] ?? null, 'match_confidence' => 'low', 'offers' => [], 'source' => 'ai_empty' ];
    }

    $offers = [];
    foreach ( $data['offers'] as $o ) {
        if ( empty( $o['acheteur'] ) ) continue;
        $offers[] = [
            'acheteur'         => sanitize_text_field( $o['acheteur'] ),
            'region_hint'      => sanitize_text_field( $o['region_hint'] ?? '' ),
            'prix_brut'        => is_numeric( $o['prix_brut'] ?? null )        ? (float) $o['prix_brut']        : null,
            'prix_net_vendeur' => is_numeric( $o['prix_net_vendeur'] ?? null ) ? (float) $o['prix_net_vendeur'] : null,
            'devise_unit'      => sanitize_text_field( $o['devise_unit'] ?? '' ),
        ];
    }

    return [
        'sous_type_matched' => sanitize_text_field( $data['sous_type_matched'] ?? '' ),
        'match_confidence'  => in_array( $data['match_confidence'] ?? '', [ 'high', 'medium', 'low' ], true ) ? $data['match_confidence'] : 'medium',
        'offers'            => $offers,
        'source'            => 'ai',
    ];
}


// ─────────────────────────────────────────────────────────────────────────────
// 3. IA — ESTIMATION DE DISTANCE (uniquement pour les acheteurs non résolus par règle)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Estime, pour une liste d'acheteurs non résolus par la règle PHP, le site le
 * plus proche du vendeur + un tier de proximité + une distance approximative.
 * L'IA doit répondre "inconnu"/confiance basse plutôt que d'inventer un chiffre
 * quand le lieu n'est pas identifiable avec confiance.
 *
 * @param  string $seller_location
 * @param  array  $buyers  [ index => ['name'=>string,'sites'=>string[],'region_hint'=>string] ]
 * @return array  [ index => ['site'=>string,'tier'=>string,'distance_km'=>?float,'confidence'=>string] ]
 */
function fpt_ai_estimate_buyer_distances( string $seller_location, array $buyers, bool $force = false ): array {
    if ( empty( $buyers ) ) return [];

    if ( ! function_exists( 'fpt_ai_call_cached' ) || ! get_option( 'fpt_ai_enabled', false ) ) {
        return [];
    }

    $payload   = wp_json_encode( $buyers );
    $cache_key = 'fpt_bm_dist_' . md5( strtolower( trim( $seller_location ) ) . '|' . md5( $payload ) );
    if ( $force ) delete_transient( $cache_key );

    $list_str = '';
    foreach ( $buyers as $i => $b ) {
        $sites_str = ! empty( $b['sites'] ) ? implode( ', ', array_slice( $b['sites'], 0, 15 ) ) : 'inconnu';
        $list_str .= sprintf(
            "[%d] %s — sites possibles : %s — indice région (tableau de prix) : %s\n",
            $i, $b['name'], $sites_str, $b['region_hint'] ?: 'inconnu'
        );
    }

    $system = 'Tu es un expert en géographie et logistique de transport. Tu estimes des distances routières APPROXIMATIVES entre lieux, à partir de ta connaissance générale. Tu réponds UNIQUEMENT en JSON valide, sans markdown. Si un lieu est trop ambigu pour être localisé avec confiance, ne devine pas une distance précise : retourne tier "inconnu" et confidence "low".';

    $prompt = <<<PROMPT
Localisation du vendeur : "{$seller_location}"

Acheteurs à localiser :
{$list_str}

Pour CHAQUE acheteur, identifie le site le plus proche du vendeur et estime la distance routière approximative.

Catégories de proximité (tier) :
- "meme_quartier" : même quartier/zone immédiate
- "meme_ville" : même ville, quartier différent
- "meme_region" : même région/département, ville différente
- "region_differente" : même pays, région différente
- "pays_different" : pays différent
- "inconnu" : impossible à localiser avec une confiance raisonnable

Retourne uniquement un tableau JSON, un objet par acheteur, dans le même ordre que la liste ci-dessus :
[
  { "index": 0, "site": "nom du site retenu", "tier": "meme_region", "distance_km": 580, "confidence": "medium" }
]
PROMPT;

    $raw = fpt_ai_call_cached( $cache_key, $prompt, $system, FPT_BM_CACHE_TTL_DIST );
    if ( is_wp_error( $raw ) ) return [];

    $data = fpt_ai_parse_json( $raw );
    if ( ! $data || ! is_array( $data ) ) return [];

    $valid_tiers = [ 'meme_quartier', 'meme_ville', 'meme_region', 'region_differente', 'pays_different', 'inconnu' ];
    $valid_conf  = [ 'high', 'medium', 'low' ];

    $out = [];
    foreach ( $data as $row ) {
        $idx = (int) ( $row['index'] ?? -1 );
        if ( ! isset( $buyers[ $idx ] ) ) continue;

        $tier = in_array( $row['tier'] ?? '', $valid_tiers, true ) ? $row['tier'] : 'inconnu';
        $conf = in_array( $row['confidence'] ?? '', $valid_conf, true ) ? $row['confidence'] : 'low';
        $dist = is_numeric( $row['distance_km'] ?? null ) ? max( 0, min( 20000, (float) $row['distance_km'] ) ) : null;
        if ( $tier === 'inconnu' ) $dist = null;

        $out[ $idx ] = [
            'site'        => sanitize_text_field( $row['site'] ?? '' ),
            'tier'        => $tier,
            'distance_km' => $dist,
            'confidence'  => $conf,
        ];
    }

    return $out;
}


// ─────────────────────────────────────────────────────────────────────────────
// 4. ORCHESTRATEUR — calcule et stocke le classement complet d'un lot
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Calcule le classement des acheteurs pertinents pour un lot et le stocke
 * en post meta '_fpt_buyer_ranking' (JSON).
 *
 * Tri : tier de proximité ASC → distance_km ASC → prix_net_vendeur DESC.
 * (Localisation = critère prioritaire ; le prix ne départage qu'à localisation égale/proche.)
 */
function fpt_bm_rank_buyers( int $post_id, bool $force = false ): array {
    if ( ! get_option( 'fpt_ai_enabled', false ) ) {
        return [ 'status' => 'ai_disabled', 'offers' => [] ];
    }

    $titre_lot        = get_the_title( $post_id );
    $seller_location   = (string) get_post_meta( $post_id, fpt_key_ville(), true );

    $prix_post_id = fpt_bm_find_prix_listing_id( $titre_lot );
    if ( ! $prix_post_id ) {
        return [ 'status' => 'no_price_match', 'offers' => [] ];
    }

    $raw_description = trim( (string) get_post_field( 'post_content', $prix_post_id ) );
    if ( empty( $raw_description ) ) {
        return [ 'status' => 'empty_price_table', 'offers' => [] ];
    }

    // 1) Extraction IA des offres pour le sous-type correspondant au lot
    $extracted = fpt_ai_extract_buyer_offers( $prix_post_id, $titre_lot, $raw_description, $force );
    if ( empty( $extracted['offers'] ) ) {
        return [ 'status' => 'no_offers_extracted', 'sous_type' => $extracted['sous_type_matched'] ?? '', 'offers' => [] ];
    }

    // 2) Résolution des fiches acheteurs + leurs sites
    $buyer_listings = fpt_bm_get_buyer_listings();
    $resolved       = [];
    $to_geolocate   = [];

    foreach ( $extracted['offers'] as $i => $offer ) {
        $match = fpt_bm_resolve_buyer( $offer['acheteur'], $buyer_listings );
        $sites = $match ? fpt_bm_get_buyer_sites( $match['id'] ) : [];

        $row = [
            'acheteur'            => $offer['acheteur'],
            'buyer_id'            => $match['id'] ?? null,
            'buyer_match_score'   => $match['score'] ?? 0,
            'region_hint'         => $offer['region_hint'],
            'prix_brut'           => $offer['prix_brut'],
            'prix_net_vendeur'    => $offer['prix_net_vendeur'],
            'devise_unit'         => $offer['devise_unit'],
            'sites'               => $sites,
            'site_le_plus_proche' => null,
            'tier'                => 'inconnu',
            'distance_km'         => null,
            'distance_confidence' => 'low',
            'distance_source'     => 'none',
        ];

        if ( ! empty( $sites ) && fpt_bm_rule_match_location( $seller_location, $sites ) ) {
            // Règle PHP gratuite — correspondance littérale trouvée.
            $row['tier']                = 'meme_ville';
            $row['distance_km']         = 0.0;
            $row['distance_confidence'] = 'high';
            $row['distance_source']     = 'rule';
            $row['site_le_plus_proche'] = $sites[0];
        } elseif ( ! empty( $sites ) || ! empty( $offer['region_hint'] ) ) {
            // Pas de match littéral mais on a un signal exploitable → IA.
            $to_geolocate[ $i ] = [
                'name'        => $offer['acheteur'],
                'sites'       => $sites,
                'region_hint' => $offer['region_hint'],
            ];
        }
        // Sinon (ni site, ni indice région) : reste 'inconnu', aucun appel IA inutile.

        $resolved[ $i ] = $row;
    }

    // 3) Estimation IA des distances pour les acheteurs non résolus par règle
    if ( ! empty( $to_geolocate ) && ! empty( $seller_location ) ) {
        $distances = fpt_ai_estimate_buyer_distances( $seller_location, $to_geolocate, $force );
        foreach ( $distances as $i => $d ) {
            if ( ! isset( $resolved[ $i ] ) ) continue;
            $resolved[ $i ]['tier']                = $d['tier'];
            $resolved[ $i ]['distance_km']         = $d['distance_km'];
            $resolved[ $i ]['distance_confidence'] = $d['confidence'];
            $resolved[ $i ]['site_le_plus_proche']  = $d['site'];
            $resolved[ $i ]['distance_source']      = 'ai';
        }
    }

    // 4) Tri : localisation d'abord, prix en départage
    $tier_rank = [
        'meme_quartier'     => 0,
        'meme_ville'        => 1,
        'meme_region'       => 2,
        'region_differente' => 3,
        'pays_different'    => 4,
        'inconnu'           => 5,
    ];

    usort( $resolved, function( $a, $b ) use ( $tier_rank ) {
        $ta = $tier_rank[ $a['tier'] ] ?? 5;
        $tb = $tier_rank[ $b['tier'] ] ?? 5;
        if ( $ta !== $tb ) return $ta <=> $tb;

        $da = $a['distance_km'] ?? PHP_FLOAT_MAX;
        $db = $b['distance_km'] ?? PHP_FLOAT_MAX;
        if ( abs( $da - $db ) > 0.01 ) return $da <=> $db;

        // Départage final à localisation égale : prix net vendeur le plus élevé.
        return ( $b['prix_net_vendeur'] ?? 0 ) <=> ( $a['prix_net_vendeur'] ?? 0 );
    } );

    $result = [
        'status'       => 'ok',
        'sous_type'    => $extracted['sous_type_matched'] ?? '',
        'prix_post_id' => $prix_post_id,
        'offers'       => array_values( $resolved ),
        'updated_at'   => current_time( 'mysql' ),
    ];

    update_post_meta( $post_id, '_fpt_buyer_ranking', wp_json_encode( $result ) );

    return $result;
}


// ─────────────────────────────────────────────────────────────────────────────
// 5. DÉCLENCHEMENT AUTOMATIQUE — à la publication/MAJ d'un lot déchets
// ─────────────────────────────────────────────────────────────────────────────
// Priorité 40 : après fpt_on_listing_save (20) et fpt_attach_ref_to_listing (25)
// du plugin principal, pour être sûr que les métas (ville, poids) sont déjà sauvées.
add_action( 'save_post_hp_listing', 'fpt_bm_on_listing_save', 40, 3 );
function fpt_bm_on_listing_save( $post_id, $post, $update ) {
    if ( wp_is_post_revision( $post_id ) ) return;
    if ( $post->post_status !== 'publish' ) return;
    if ( ! fpt_bm_is_waste_listing( $post_id ) ) return;
    if ( ! get_option( 'fpt_ai_enabled', false ) ) return;

    fpt_bm_rank_buyers( $post_id, false );
}


// ─────────────────────────────────────────────────────────────────────────────
// 6. METABOX ADMIN — affichage du classement sur la fiche du lot
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'add_meta_boxes', function() {
    add_meta_box(
        'fpt_bm_ranking',
        '🎯 Classement Acheteurs (IA) — FerayPro',
        'fpt_bm_render_metabox',
        'hp_listing',
        'normal',
        'high'
    );
} );

function fpt_bm_render_metabox( $post ) {
    if ( ! fpt_bm_is_waste_listing( $post->ID ) ) {
        echo '<p style="color:#888;font-style:italic;">Disponible uniquement pour les fiches "Annonces déchets".</p>';
        return;
    }
    if ( ! get_option( 'fpt_ai_enabled', false ) ) {
        echo '<p>⚠️ Activez le module IA dans <strong>FP Tracer → Paramètres</strong> pour utiliser le classement automatique des acheteurs.</p>';
        return;
    }

    $raw  = get_post_meta( $post->ID, '_fpt_buyer_ranking', true );
    $data = $raw ? json_decode( $raw, true ) : null;

    $nonce = wp_create_nonce( 'fpt_bm_recalc_' . $post->ID );
    ?>
    <div id="fpt-bm-box" data-post="<?php echo esc_attr( $post->ID ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
        <p>
            <button type="button" class="button button-primary" onclick="fptBmRecalc(this)">🔄 Recalculer le classement</button>
            <span class="fpt-bm-status" style="margin-left:10px;"></span>
        </p>
        <div class="fpt-bm-results"><?php echo fpt_bm_render_results_html( $data ); ?></div>
    </div>
    <script>
    function fptBmRecalc(btn) {
        const box    = document.getElementById('fpt-bm-box');
        const status = box.querySelector('.fpt-bm-status');
        status.textContent = '⏳ Calcul en cours (peut prendre 10-20s)…';
        btn.disabled = true;
        fetch(ajaxurl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=fpt_bm_recalculate&post_id=' + box.dataset.post + '&nonce=' + box.dataset.nonce
        })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            if (d.success) {
                status.textContent = '✅ Mis à jour';
                box.querySelector('.fpt-bm-results').innerHTML = d.data.html;
            } else {
                status.textContent = '❌ ' + d.data;
            }
        })
        .catch(() => { btn.disabled = false; status.textContent = '❌ Erreur réseau'; });
    }
    </script>
    <?php
}

/**
 * Génère le HTML du tableau de classement à partir des données stockées.
 */
function fpt_bm_render_results_html( $data ): string {
    if ( empty( $data ) || ( $data['status'] ?? '' ) !== 'ok' || empty( $data['offers'] ) ) {
        $messages = [
            'no_price_match'      => 'Aucune fiche "Prix du jour" correspondante trouvée pour ce lot.',
            'empty_price_table'   => 'La fiche "Prix du jour" correspondante ne contient pas de tableau de prix exploitable.',
            'no_offers_extracted' => 'Aucun sous-type / acheteur n\'a pu être extrait du tableau de prix.',
            'ai_disabled'         => 'Module IA désactivé.',
        ];
        $status = $data['status'] ?? '';
        $msg    = $messages[ $status ] ?? 'Aucun classement disponible — cliquez sur « Recalculer ».';
        return '<p style="color:#888;">' . esc_html( $msg ) . '</p>';
    }

    $tier_labels = [
        'meme_quartier'     => '🟢 Même quartier',
        'meme_ville'        => '🟢 Même ville',
        'meme_region'       => '🟡 Même région',
        'region_differente' => '🟠 Région différente',
        'pays_different'    => '🔴 Pays différent',
        'inconnu'           => '⚪ Localisation inconnue',
    ];
    $conf_suffix = [ 'high' => '', 'medium' => ' (estimation)', 'low' => ' (incertain)' ];

    ob_start();
    ?>
    <p style="font-size:13px;color:#555;">
        Sous-type matché : <strong><?php echo esc_html( $data['sous_type'] ?: '—' ); ?></strong>
        · Mis à jour : <?php echo esc_html( $data['updated_at'] ?? '' ); ?>
        · <em>Distances estimées par IA — à valider avant décision finale.</em>
    </p>
    <table class="widefat striped">
        <thead>
            <tr>
                <th>#</th>
                <th>Acheteur</th>
                <th>Site le plus proche</th>
                <th>Localisation</th>
                <th>Prix net vendeur</th>
                <th>Région (tableau prix)</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $data['offers'] as $i => $o ) :
            $tier = $o['tier'] ?? 'inconnu';
            $prix = $o['prix_net_vendeur'] ?? null;
        ?>
            <tr>
                <td><?php echo (int) $i + 1; ?></td>
                <td>
                    <?php if ( ! empty( $o['buyer_id'] ) ) : ?>
                        <a href="<?php echo esc_url( get_edit_post_link( $o['buyer_id'] ) ); ?>" target="_blank"><?php echo esc_html( $o['acheteur'] ); ?></a>
                    <?php else : ?>
                        <?php echo esc_html( $o['acheteur'] ); ?>
                        <span style="color:#c0392b;font-size:11px;">(fiche non identifiée)</span>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html( $o['site_le_plus_proche'] ?: '—' ); ?></td>
                <td>
                    <?php echo $tier_labels[ $tier ] ?? esc_html( $tier ); ?>
                    <?php if ( $o['distance_km'] !== null ) : ?>
                        — ~<?php echo esc_html( number_format( (float) $o['distance_km'], 0 ) ); ?> km
                    <?php endif; ?>
                    <small style="color:#888;"><?php echo esc_html( $conf_suffix[ $o['distance_confidence'] ?? 'low' ] ?? '' ); ?></small>
                </td>
                <td><strong><?php echo $prix !== null ? esc_html( number_format( (float) $prix, 2 ) . ' ' . $o['devise_unit'] ) : '—'; ?></strong></td>
                <td><?php echo esc_html( $o['region_hint'] ?? '' ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
}


// ─────────────────────────────────────────────────────────────────────────────
// 7. AJAX — recalcul manuel
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_fpt_bm_recalculate', function() {
    $post_id = (int) ( $_POST['post_id'] ?? 0 );
    if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( 'Permission refusée.' );
    }
    check_ajax_referer( 'fpt_bm_recalc_' . $post_id, 'nonce' );

    $data = fpt_bm_rank_buyers( $post_id, true ); // $force = true → bypass cache
    wp_send_json_success( [ 'html' => fpt_bm_render_results_html( $data ) ] );
} );
