<?php
/**
 * FerayPro Tracer — Module IA
 * Classification matériaux · ERRI micro-local · Matching prix · Génération description
 *
 * Règle d'or : l'IA retourne uniquement un SLUG de matière ("cuivre", "acier"…)
 * PHP fait TOUS les calculs CO₂ à partir de fpt_co2_factors() — aucun facteur dans les prompts.
 *
 * @version 2.3.0
 * @requires feraypro-tracer.php (fpt_co2_factors, fpt_calculate_co2, fpt_grid_intensity)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────────────────────────────────────
// 0. CONFIGURATION
// ─────────────────────────────────────────────────────────────────────────────

define( 'FPT_AI_API_URL',   'https://api.anthropic.com/v1/messages' );
define( 'FPT_AI_MODEL',     'claude-haiku-4-5-20251001' );   // modèle léger = ~$0.001/appel
define( 'FPT_AI_MAX_TOKENS', 256 );
define( 'FPT_AI_CACHE_TTL',  DAY_IN_SECONDS );               // 24h de cache transient


// ─────────────────────────────────────────────────────────────────────────────
// 1. CLIENT API — appel HTTP bas niveau
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Envoie un message à l'API Anthropic et retourne le texte brut.
 *
 * @param  string $prompt   Message utilisateur
 * @param  string $system   Instruction système (optionnel)
 * @return string|WP_Error  Texte de la réponse ou WP_Error
 */
function fpt_ai_call( string $prompt, string $system = '' ) {
    $api_key = get_option( 'fpt_ai_api_key', '' );
    if ( empty( $api_key ) ) {
        return new WP_Error( 'fpt_ai_no_key', 'Clé API Anthropic manquante (FP Tracer → Paramètres → IA).' );
    }

    $body = [
        'model'      => FPT_AI_MODEL,
        'max_tokens' => FPT_AI_MAX_TOKENS,
        'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
    ];
    if ( $system ) {
        $body['system'] = $system;
    }

    $response = wp_remote_post( FPT_AI_API_URL, [
        'timeout' => 20,
        'headers' => [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
        ],
        'body' => wp_json_encode( $body ),
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) {
        $msg = $data['error']['message'] ?? "Erreur API ($code)";
        return new WP_Error( 'fpt_ai_api_error', $msg );
    }

    return trim( $data['content'][0]['text'] ?? '' );
}

/**
 * Appel API avec cache transient WordPress.
 *
 * @param  string $cache_key  Clé unique pour le cache
 * @param  string $prompt
 * @param  string $system
 * @param  int    $ttl        Durée de vie du cache en secondes
 * @return string|WP_Error
 */
function fpt_ai_call_cached( string $cache_key, string $prompt, string $system = '', int $ttl = FPT_AI_CACHE_TTL ) {
    $cached = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    $result = fpt_ai_call( $prompt, $system );
    if ( ! is_wp_error( $result ) && ! empty( $result ) ) {
        set_transient( $cache_key, $result, $ttl );
    }
    return $result;
}

/**
 * Parse JSON retourné par l'IA (supprime les balises markdown si présentes).
 *
 * @param  string $text
 * @return array|null
 */
function fpt_ai_parse_json( string $text ): ?array {
    $clean = preg_replace( '/^```(?:json)?\s*/i', '', trim( $text ) );
    $clean = preg_replace( '/\s*```$/', '', $clean );
    $data  = json_decode( $clean, true );
    return is_array( $data ) ? $data : null;
}


// ─────────────────────────────────────────────────────────────────────────────
// 2. MODULE A — CLASSIFICATION MATÉRIAUX
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Slugs valides : doit correspondre aux clés de fpt_co2_factors().
 * L'IA DOIT retourner l'un de ces slugs — rien d'autre.
 */
function fpt_ai_valid_slugs(): array {
    return array_keys( fpt_co2_factors() );
}

/**
 * Classifie un titre d'annonce via l'IA et retourne le slug matière.
 * Fallback sur la logique strpos() existante si l'IA échoue.
 *
 * @param  string $title   Titre de l'annonce
 * @param  int    $post_id ID du post (pour log WP)
 * @return array {
 *     slug        : string  — ex. "cuivre"
 *     co2_factor  : float   — ex. 0.141
 *     confidence  : string  — "high"|"medium"|"low"
 *     lang        : string  — langue détectée
 *     source      : string  — "ai"|"fallback"
 * }
 */
function fpt_ai_classify_material( string $title, int $post_id = 0 ): array {
    $slugs     = fpt_ai_valid_slugs();
    $slugs_str = implode( ', ', $slugs );

    $cache_key = 'fpt_ai_cls_' . md5( strtolower( trim( $title ) ) );

    $system = 'Tu es un moteur de classification de déchets recyclables. Tu réponds UNIQUEMENT en JSON valide, sans markdown, sans explication.';

    $prompt = <<<PROMPT
Titre de l'annonce : "{$title}"

Slugs autorisés (retournes EXACTEMENT l'un d'eux) :
{$slugs_str}

Retourne un objet JSON :
{
  "slug": "slug_exact_de_la_liste",
  "confidence": "high|medium|low",
  "lang": "FR|AR|Lingala|EN|mixte"
}
PROMPT;

    $raw = fpt_ai_call_cached( $cache_key, $prompt, $system );

    // Fallback si erreur API
    if ( is_wp_error( $raw ) ) {
        $fallback = fpt_detect_material_slug_legacy( $title );
        return array_merge( $fallback, [ 'source' => 'fallback', 'error' => $raw->get_error_message() ] );
    }

    $data = fpt_ai_parse_json( $raw );

    // Validation : le slug retourné doit être dans la liste
    if ( ! $data || ! in_array( $data['slug'] ?? '', $slugs, true ) ) {
        $fallback = fpt_detect_material_slug_legacy( $title );
        return array_merge( $fallback, [ 'source' => 'fallback' ] );
    }

    $factors   = fpt_co2_factors();
    $co2_factor = $factors[ $data['slug'] ] ?? 0.50;

    // Log admin (optionnel)
    if ( $post_id && WP_DEBUG ) {
        error_log( "[FPT AI] post#{$post_id} slug={$data['slug']} conf={$data['confidence']}" );
    }

    return [
        'slug'       => $data['slug'],
        'co2_factor' => $co2_factor,
        'confidence' => $data['confidence'] ?? 'medium',
        'lang'       => $data['lang']       ?? 'FR',
        'source'     => 'ai',
    ];
}

/**
 * Wrapper final : retourne directement le facteur CO₂ à partir du slug IA.
 * Remplace fpt_detect_material() dans fpt_calculate_co2().
 *
 * @param  string $title
 * @param  float  $weight_kg
 * @return array { co2_avoided_t, co2_factor, slug, source }
 */
function fpt_ai_calculate_co2( string $title, float $weight_kg ): array {
    $classification = fpt_ai_classify_material( $title );
    $weight_t       = $weight_kg / 1000;
    $co2_avoided_t  = $weight_t * $classification['co2_factor'];

    return [
        'co2_avoided_t' => $co2_avoided_t,
        'co2_avoided_kg' => $co2_avoided_t * 1000,
        'co2_factor'    => $classification['co2_factor'],
        'slug'          => $classification['slug'],
        'confidence'    => $classification['confidence'],
        'source'        => $classification['source'],
    ];
}

/**
 * Fallback legacy : détection par strpos() existante.
 * Ne modifie pas fpt_co2_factors() — réutilise la logique du plugin principal.
 */
function fpt_detect_material_slug_legacy( string $title ): array {
    $normalized = fpt_normalize_text( $title );
    $factors    = fpt_co2_factors();

    foreach ( $factors as $slug => $factor ) {
        if ( strpos( $normalized, $slug ) !== false ) {
            return [ 'slug' => $slug, 'co2_factor' => $factor, 'confidence' => 'low', 'lang' => 'FR' ];
        }
    }
    return [ 'slug' => 'default', 'co2_factor' => $factors['default'] ?? 0.50, 'confidence' => 'low', 'lang' => 'FR' ];
}


// ─────────────────────────────────────────────────────────────────────────────
// 3. MODULE B — ERRI MICRO-LOCAL
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Calcule le multiplicateur de densité démographique micro-local via l'IA.
 * Remplace les constantes pays fixes (MA×1.2, CD×1.8, etc.)
 *
 * @param  string $location    Localisation saisie par l'utilisateur (ville, quartier)
 * @param  string $waste_type  Type de déchet (ex. "batteries au plomb")
 * @return array {
 *     multiplier       : float   — ex. 1.6
 *     zone_type        : string  — ex. "Quartier résidentiel dense"
 *     health_context   : string  — description courte
 *     confidence       : string  — "high"|"medium"|"low"
 *     source           : string  — "ai"|"fallback"
 * }
 */
function fpt_ai_erri_multiplier( string $location, string $waste_type = '' ): array {
    if ( empty( $location ) ) {
        return fpt_erri_multiplier_fallback();
    }

    $cache_key = 'fpt_ai_erri_' . md5( strtolower( $location ) . '|' . strtolower( $waste_type ) );

    $system = 'Tu es un expert en santé environnementale et démographie urbaine. Tu réponds UNIQUEMENT en JSON valide, sans markdown.';

    $prompt = <<<PROMPT
Localisation : "{$location}"
Type de déchet : "{$waste_type}"

Évalue la densité de population et le risque sanitaire pour les enfants dans cette zone.
Facteurs à considérer : densité urbaine, présence de zones résidentielles informelles, type de quartier
(industriel, résidentiel, rural, péri-urbain, commercial).

Retourne uniquement :
{
  "multiplier": 1.0,
  "zone_type": "description courte max 5 mots",
  "health_context": "1 phrase courte sur le risque local",
  "confidence": "high|medium|low"
}

Le multiplicateur doit être entre 0.3 (zone rurale très peu dense) et 2.5 (quartier urbain informel très dense).
PROMPT;

    $raw = fpt_ai_call_cached( $cache_key, $prompt, $system );

    if ( is_wp_error( $raw ) ) {
        return array_merge( fpt_erri_multiplier_fallback(), [ 'source' => 'fallback' ] );
    }

    $data = fpt_ai_parse_json( $raw );
    $mult = (float) ( $data['multiplier'] ?? 1.0 );

    // Sécurité : borner entre 0.3 et 2.5
    $mult = max( 0.3, min( 2.5, $mult ) );

    return [
        'multiplier'     => $mult,
        'zone_type'      => sanitize_text_field( $data['zone_type']     ?? 'Zone indéterminée' ),
        'health_context' => sanitize_text_field( $data['health_context'] ?? '' ),
        'confidence'     => $data['confidence'] ?? 'medium',
        'source'         => 'ai',
    ];
}

/**
 * Fallback : multiplicateurs pays fixes (logique actuelle du plugin).
 * Reprend exactement la logique de fpt_get_population_density_multiplier()
 * du plugin principal — basée sur fpt_country_name (texte libre, ex. "Maroc").
 */
function fpt_erri_multiplier_fallback(): array {
    $country = strtolower( get_option( 'fpt_country_name', '' ) );

    if ( strpos( $country, 'maroc' ) !== false || strpos( $country, 'morocco' ) !== false ) {
        return [ 'multiplier' => 1.2, 'zone_type' => 'Maroc (moyen)', 'health_context' => 'Densité côtière urbaine modérée.', 'confidence' => 'low', 'source' => 'fallback' ];
    }
    if ( strpos( $country, 'congo' ) !== false || strpos( $country, 'rdc' ) !== false ) {
        return [ 'multiplier' => 1.8, 'zone_type' => 'RDC (Kinshasa)', 'health_context' => 'Densité informelle élevée.', 'confidence' => 'low', 'source' => 'fallback' ];
    }
    if ( strpos( $country, 'senegal' ) !== false || strpos( $country, 'sénégal' ) !== false ) {
        return [ 'multiplier' => 1.3, 'zone_type' => 'Sénégal (moyen)', 'health_context' => '', 'confidence' => 'low', 'source' => 'fallback' ];
    }
    if ( strpos( $country, 'nigeria' ) !== false ) {
        return [ 'multiplier' => 1.5, 'zone_type' => 'Nigeria (moyen)', 'health_context' => '', 'confidence' => 'low', 'source' => 'fallback' ];
    }
    if ( strpos( $country, 'kenya' ) !== false ) {
        return [ 'multiplier' => 1.2, 'zone_type' => 'Kenya (moyen)', 'health_context' => '', 'confidence' => 'low', 'source' => 'fallback' ];
    }
    if ( strpos( $country, 'france' ) !== false ) {
        return [ 'multiplier' => 0.8, 'zone_type' => 'France (faible)', 'health_context' => '', 'confidence' => 'low', 'source' => 'fallback' ];
    }
    if ( strpos( $country, 'usa' ) !== false || strpos( $country, 'états-unis' ) !== false ) {
        return [ 'multiplier' => 0.7, 'zone_type' => 'USA (faible)', 'health_context' => '', 'confidence' => 'low', 'source' => 'fallback' ];
    }

    return [ 'multiplier' => 1.0, 'zone_type' => 'Défaut', 'health_context' => '', 'confidence' => 'low', 'source' => 'fallback' ];
}

/**
 * Calcule l'ERRI complet avec multiplicateur micro-local.
 *
 * @param  float  $weight_kg
 * @param  string $location
 * @param  string $waste_type
 * @param  bool   $has_lead      Déchet contient du plomb
 * @param  bool   $has_pm25      Déchet génère des PM2.5
 * @param  bool   $has_cadmium
 * @param  bool   $has_mercury
 * @return array
 */
function fpt_ai_calculate_erri( float $weight_kg, string $location, string $waste_type, bool $has_lead, bool $has_pm25, bool $has_cadmium = false, bool $has_mercury = false ): array {
    $density = fpt_ai_erri_multiplier( $location, $waste_type );
    $mult    = $density['multiplier'];
    $weight_t = $weight_kg / 1000;

    $lead_kg   = $has_lead    ? $weight_t * 0.5   * $mult : 0;
    $pm25_kg   = $has_pm25   ? $weight_t * 15     * $mult : 0;
    $cadmium_g = $has_cadmium ? $weight_t * 200   * $mult : 0;
    $mercury_g = $has_mercury ? $weight_t * 50    * $mult : 0;

    $erri = ( $lead_kg * 50 ) + ( $pm25_kg * 10 );
    $children_protected = round( $erri );

    return [
        'erri'                => round( $erri, 2 ),
        'children_protected'  => $children_protected,
        'lead_diverted_kg'    => round( $lead_kg, 3 ),
        'pm25_diverted_kg'    => round( $pm25_kg, 3 ),
        'cadmium_diverted_g'  => round( $cadmium_g, 2 ),
        'mercury_diverted_g'  => round( $mercury_g, 2 ),
        'density_multiplier'  => $mult,
        'zone_type'           => $density['zone_type'],
        'health_context'      => $density['health_context'],
        'multiplier_source'   => $density['source'],
    ];
}


// ─────────────────────────────────────────────────────────────────────────────
// 4. MODULE C — MATCHING SÉMANTIQUE PRIX DU JOUR
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Trouve la référence de prix la plus pertinente sémantiquement.
 * Remplace fpt_get_prix_du_jour() basée sur le scoring par mots-clés.
 *
 * @param  string $lot_title   Titre du lot à valoriser
 * @param  array  $price_refs  Tableau de références : [ ['title'=>'', 'price'=>0, 'unit'=>'', 'id'=>0], … ]
 * @return array {
 *     matched_id    : int|null
 *     matched_title : string
 *     price         : float
 *     unit          : string
 *     score         : float   — 0.0–1.0
 *     reasoning     : string
 *     source        : string  — "ai"|"fallback"
 * }
 */
function fpt_ai_match_price( string $lot_title, array $price_refs ): array {
    if ( empty( $price_refs ) ) {
        return [ 'matched_id' => null, 'matched_title' => '', 'price' => 0, 'unit' => '', 'score' => 0, 'reasoning' => 'Aucune référence disponible.', 'source' => 'fallback' ];
    }

    // Construire la liste numérotée pour le prompt
    $refs_str = '';
    foreach ( $price_refs as $i => $ref ) {
        $refs_str .= sprintf( "[%d] %s — %s %s\n", $i, $ref['title'], $ref['price'], $ref['unit'] );
    }

    $cache_key = 'fpt_ai_price_' . md5( strtolower( $lot_title ) . '|' . md5( $refs_str ) );

    $system = 'Tu es un expert en valorisation de déchets recyclables. Tu réponds UNIQUEMENT en JSON valide, sans markdown.';

    $prompt = <<<PROMPT
Lot à valoriser : "{$lot_title}"

Références de prix disponibles :
{$refs_str}

Trouve la référence sémantiquement la plus pertinente. Si aucune ne correspond bien, retourne index -1.

Retourne uniquement :
{
  "index": 0,
  "score": 0.0,
  "reasoning": "explication courte max 10 mots"
}
PROMPT;

    $raw = fpt_ai_call_cached( $cache_key, $prompt, $system );

    if ( is_wp_error( $raw ) ) {
        return fpt_price_match_fallback( $lot_title, $price_refs );
    }

    $data  = fpt_ai_parse_json( $raw );
    $index = (int) ( $data['index'] ?? -1 );

    if ( $index < 0 || ! isset( $price_refs[ $index ] ) ) {
        return fpt_price_match_fallback( $lot_title, $price_refs );
    }

    $ref = $price_refs[ $index ];
    return [
        'matched_id'    => $ref['id']    ?? null,
        'matched_title' => $ref['title'] ?? '',
        'price'         => (float) ( $ref['price'] ?? 0 ),
        'unit'          => $ref['unit']  ?? '',
        'score'         => (float) ( $data['score'] ?? 0 ),
        'reasoning'     => sanitize_text_field( $data['reasoning'] ?? '' ),
        'source'        => 'ai',
    ];
}

/**
 * Fallback : matching par longueur de sous-chaîne commune (algo existant simplifié).
 */
function fpt_price_match_fallback( string $lot_title, array $price_refs ): array {
    $normalized = fpt_normalize_text( $lot_title );
    $best_score = -1;
    $best_ref   = null;

    foreach ( $price_refs as $ref ) {
        $ref_norm = fpt_normalize_text( $ref['title'] ?? '' );
        similar_text( $normalized, $ref_norm, $pct );
        if ( $pct > $best_score ) {
            $best_score = $pct;
            $best_ref   = $ref;
        }
    }

    if ( ! $best_ref ) return [ 'matched_id' => null, 'matched_title' => '', 'price' => 0, 'unit' => '', 'score' => 0, 'reasoning' => 'Fallback — aucune correspondance.', 'source' => 'fallback' ];

    return [
        'matched_id'    => $best_ref['id']    ?? null,
        'matched_title' => $best_ref['title'] ?? '',
        'price'         => (float) ( $best_ref['price'] ?? 0 ),
        'unit'          => $best_ref['unit']  ?? '',
        'score'         => round( $best_score / 100, 2 ),
        'reasoning'     => 'Fallback similar_text()',
        'source'        => 'fallback',
    ];
}


// ─────────────────────────────────────────────────────────────────────────────
// 5. MODULE D — GÉNÉRATION DE DESCRIPTION
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Génère une description de lot professionnelle pour le marketplace HivePress.
 *
 * @param  string $raw_input   Saisie brute de l'utilisateur
 * @param  float  $weight_kg
 * @param  string $city        Ville de collecte
 * @param  float  $co2_kg      CO₂ évité calculé par PHP
 * @param  string $lang        "fr"|"en"
 * @return array {
 *     title       : string  — titre optimisé
 *     description : string  — description complète
 *     tags        : array   — mots-clés SEO
 *     eco_arg     : string  — argument environnemental
 *     source      : string  — "ai"|"fallback"
 * }
 */
function fpt_ai_generate_description( string $raw_input, float $weight_kg, string $city = '', float $co2_kg = 0, string $lang = 'fr' ): array {
    // Pas de cache sur les descriptions (contenu unique par lot)
    $lang_label = ( $lang === 'en' ) ? 'English' : 'Français';

    $system = "Tu es un rédacteur spécialisé en marketplace de matières recyclables. Tu réponds UNIQUEMENT en JSON valide, sans markdown. Langue de sortie : {$lang_label}.";

    $prompt = <<<PROMPT
Saisie brute : "{$raw_input}"
Poids : {$weight_kg} kg
Ville de collecte : "{$city}"
CO₂ évité calculé : {$co2_kg} kg

Génère une fiche technique professionnelle pour attirer des acheteurs industriels.

Retourne uniquement :
{
  "title": "titre court professionnel max 60 caractères",
  "description": "3-4 phrases professionnelles avec matériau déduit, état estimé, conditionnement, impact CO₂",
  "tags": ["tag1", "tag2", "tag3"],
  "eco_arg": "1 phrase courte sur l'impact environnemental"
}
PROMPT;

    $raw = fpt_ai_call( $prompt, $system );  // Pas de cache — description unique

    if ( is_wp_error( $raw ) ) {
        return [
            'title'       => sanitize_text_field( $raw_input ),
            'description' => '',
            'tags'        => [],
            'eco_arg'     => $co2_kg > 0 ? sprintf( '%.1f kg de CO₂ évités grâce à ce recyclage.', $co2_kg ) : '',
            'source'      => 'fallback',
        ];
    }

    $data = fpt_ai_parse_json( $raw );

    return [
        'title'       => sanitize_text_field( $data['title']       ?? $raw_input ),
        'description' => wp_kses_post( $data['description']         ?? '' ),
        'tags'        => array_map( 'sanitize_text_field', $data['tags'] ?? [] ),
        'eco_arg'     => sanitize_text_field( $data['eco_arg']      ?? '' ),
        'source'      => 'ai',
    ];
}


// ─────────────────────────────────────────────────────────────────────────────
// 6. HOOK D'INTÉGRATION — remplace fpt_calculate_co2() sur publication
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Surcharge la détection de matière au moment de la publication/mise à jour
 * d'une annonce HivePress, si l'option IA est activée.
 *
 * Déclenché par do_action( 'fpt_before_co2_save', $post_id, $titre ) dans
 * fpt_on_listing_save() (feraypro-tracer.php) — déjà intégré.
 *
 * @param  int    $post_id
 * @param  string $title
 */
function fpt_ai_override_material( int $post_id, string $title ): void {
    if ( ! get_option( 'fpt_ai_enabled', false ) ) return;

    $weight_kg = fpt_get_poids_kg( $post_id );
    if ( $weight_kg <= 0 ) return;

    $result = fpt_ai_calculate_co2( $title, $weight_kg );

    // Stocker les méta enrichies
    update_post_meta( $post_id, '_fpt_ai_slug',       $result['slug'] );
    update_post_meta( $post_id, '_fpt_ai_confidence', $result['confidence'] );
    update_post_meta( $post_id, '_fpt_ai_source',     $result['source'] );

    // Le facteur CO₂ correct est maintenant dans _fpt_co2_avoided via fpt_calculate_co2()
    // On ne stocke que les méta IA supplémentaires — le calcul CO₂ reste dans le plugin principal.
}
add_action( 'fpt_before_co2_save', 'fpt_ai_override_material', 10, 2 );


// ─────────────────────────────────────────────────────────────────────────────
// 7. PAGE DE PARAMÈTRES — carte IA dans FP Tracer → Paramètres
// ─────────────────────────────────────────────────────────────────────────────
// S'accroche au hook fpt_admin_settings_extra_cards déjà présent dans
// fpt_admin_page() (feraypro-tracer.php), à l'intérieur du <form> du tab Paramètres.
// La sauvegarde (fpt_ai_enabled, fpt_ai_api_key) est gérée par fpt_save_settings()
// dans le plugin principal — pas de register_setting() séparé nécessaire.

add_action( 'fpt_admin_settings_extra_cards', 'fpt_ai_settings_card' );

function fpt_ai_settings_card(): void {
    $key     = get_option( 'fpt_ai_api_key', '' );
    $enabled = (bool) get_option( 'fpt_ai_enabled', false );
    $masked  = $key ? substr( $key, 0, 10 ) . str_repeat( '•', 16 ) : '';
    ?>
    <div class="fpt-adm-card">
        <div class="fpt-adm-card-head">
            <span class="fpt-adm-card-icon">🤖</span>
            <div>
                <h2 class="fpt-adm-card-title">Intelligence Artificielle</h2>
                <p class="fpt-adm-card-desc">Classification matières · ERRI micro-local · Matching prix · Descriptions auto</p>
            </div>
        </div>
        <div class="fpt-adm-fields">
            <div class="fpt-adm-field">
                <label for="fpt_ai_enabled_cb">
                    <input type="checkbox" id="fpt_ai_enabled_cb" name="fpt_ai_enabled" value="1" <?php checked( $enabled ); ?> />
                    Activer la classification et les modules IA
                </label>
                <span class="fpt-adm-hint">Fallback automatique sur la détection par mots-clés (strpos) si l'API est indisponible ou si la confiance est faible.</span>
            </div>
            <div class="fpt-adm-field">
                <label for="fpt_ai_api_key">Clé API Anthropic</label>
                <input type="password" id="fpt_ai_api_key" name="fpt_ai_api_key"
                       placeholder="<?php echo $masked ? esc_attr( $masked ) : 'sk-ant-...'; ?>"
                       autocomplete="off" style="font-family:monospace">
                <span class="fpt-adm-hint">
                    <?php if ( $masked ) : ?>Clé actuelle enregistrée : <code><?php echo esc_html( $masked ); ?></code> — laisser vide pour ne pas la modifier.<br><?php endif; ?>
                    <a href="https://console.anthropic.com" target="_blank">Obtenir une clé →</a>
                    · Modèle : <code><?php echo esc_html( FPT_AI_MODEL ); ?></code>
                    · Coût estimé : ~$0.001 par annonce
                </span>
            </div>
            <div class="fpt-adm-field">
                <?php fpt_ai_test_button(); ?>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Bouton de test de la clé API en AJAX.
 */
function fpt_ai_test_button(): void {
    $nonce = wp_create_nonce( 'fpt_ai_test' );
    ?>
    <button type="button" id="fpt-ai-test-btn"
            onclick="fptAiTest('<?php echo esc_js( $nonce ); ?>')"
            class="fpt-adm-btn">
        🔌 Tester la connexion API
    </button>
    <span id="fpt-ai-test-result" style="margin-left:12px;font-size:13px;"></span>
    <script>
    function fptAiTest(nonce) {
        const el = document.getElementById('fpt-ai-test-result');
        el.textContent = '⏳ Test en cours…';
        fetch(ajaxurl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=fpt_ai_test&nonce=' + nonce
        })
        .then(r => r.json())
        .then(d => { el.textContent = d.success ? '✅ ' + d.data : '❌ ' + d.data; })
        .catch(() => { el.textContent = '❌ Erreur réseau'; });
    }
    </script>
    <?php
}

add_action( 'wp_ajax_fpt_ai_test', function() {
    check_ajax_referer( 'fpt_ai_test', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission refusée.' );

    $result = fpt_ai_call( 'Réponds uniquement : "OK"', 'Tu es un assistant de test.' );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( 'Connexion réussie — réponse : ' . esc_html( $result ) );
} );
