<?php
/**
 * Plugin Name: FerayPro Tracer
 * Plugin URI: https://ma.feraypro.com/impact
 * Description: Traçabilité des lots de déchets recyclés avec calcul CO₂ évité et génération de QR code. Module open source pour UNICEF Venture Fund.
 * Version: 1.1.0
 * Author: FerayPro
 * License: MIT
 * Text Domain: feraypro-tracer
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FPT_VERSION', '1.3.0' );

// ─── Helper : affichage du poids selon l'unité configurée ────────────────────
function fpt_weight_unit_label() {
    return get_option( 'fpt_weight_unit', 'kg' ) === 'lb' ? 'lb' : 'kg';
}

function fpt_display_weight( $poids_kg ) {
    $unit = get_option( 'fpt_weight_unit', 'kg' );
    if ( $unit === 'lb' ) {
        $poids_lb = $poids_kg / 0.453592;
        return number_format( $poids_lb, 0, '.', ' ' ) . ' lb';
    }
    return number_format( $poids_kg, 0, '.', ' ' ) . ' kg';
}

// ─── Helper : langue du site ───────────────────────────────────────────────────
function fpt_lang() {
    return get_option( 'fpt_language', 'fr' ); // 'fr' ou 'en'
}

function fpt_t( $fr, $en ) {
    return fpt_lang() === 'en' ? $en : $fr;
}

// ─── Recherche des prix du jour pour un type de déchet ───────────────────────
function fpt_get_prix_du_jour( $titre_lot ) {
    $titre_lower    = strtolower( remove_accents( $titre_lot ) );
    $category_slug  = get_option( 'fpt_prix_cat_slug', 'prix' );

    // Récupérer toutes les annonces de la catégorie prix
    $prix_listings = get_posts([
        'post_type'   => 'hp_listing',
        'post_status' => 'publish',
        'numberposts' => -1,
        'tax_query'   => [[
            'taxonomy' => 'hp_listing_category',
            'field'    => 'slug',
            'terms'    => $category_slug,
        ]],
    ]);

    if ( empty( $prix_listings ) ) return null;

    // Trouver le mot-clé qui correspond au lot
    $keywords = fpt_co2_factors();
    $matched_keyword = null;

    foreach ( $keywords as $kw => $val ) {
        if ( $kw === 'default' ) continue;
        if ( strpos( $titre_lower, $kw ) !== false ) {
            $matched_keyword = $kw;
            break;
        }
    }

    if ( ! $matched_keyword ) return null;

    // ── Matching par score de pertinence ──────────────────────────────────
    // Cherche l'annonce Prix du jour dont le titre contient le mieux
    // le mot-clé principal du lot (score 10) + mots supplémentaires (score +1)
    // Ex: "Assorted copper" → matched_keyword = "copper"
    //     "Copper price" score 10 > "Radiator price" score 0 → correct
    $best_match = null;
    $best_score = 0;

    $lot_words = array_filter(
        explode( ' ', $titre_lower ),
        function( $w ) { return strlen( $w ) >= 3; }
    );

    foreach ( $prix_listings as $prix_post ) {
        $prix_titre_lower = strtolower( remove_accents( $prix_post->post_title ) );
        $score = 0;

        // Poids fort : le mot-clé principal du lot est dans le titre Prix du jour
        if ( strpos( $prix_titre_lower, $matched_keyword ) !== false ) {
            $score += 10;
        }

        // Poids faible : autres mots du lot présents dans le titre Prix du jour
        foreach ( $lot_words as $word ) {
            if ( strpos( $prix_titre_lower, $word ) !== false ) {
                $score += 1;
            }
        }

        if ( $score > $best_score ) {
            $best_score = $score;
            $best_match = $prix_post;
        }
    }

    // Seuil minimum : le mot-clé principal doit être présent (score >= 10)
    if ( ! $best_match || $best_score < 10 ) return null;

    $prix_key    = 'hp_' . get_option( 'fpt_key_prix_jour', 'prix' );
    $prix_field  = get_post_meta( $best_match->ID, $prix_key, true );

    $description = trim( $best_match->post_content );
    if ( empty( $description ) ) {
        $buyers_key  = 'hp_' . get_option( 'fpt_key_buyersprice', 'buyersprice' );
        $description = get_post_meta( $best_match->ID, $buyers_key, true );
    }

    return [
        'titre'      => $best_match->post_title,
        'description'=> $description,
        'prix_range' => $prix_field,
        'url'        => get_permalink( $best_match->ID ),
        'updated'    => get_the_modified_date( 'd/m/Y', $best_match->ID ),
    ];
}

// ─── Injection automatique du bloc CO₂ sur chaque annonce vendeur ─────────────
add_filter( 'the_content', 'fpt_inject_on_listing', 20 );
function fpt_inject_on_listing( $content ) {
    if ( ! is_singular( 'hp_listing' ) ) return $content;
    if ( ! in_the_loop() )              return $content;

    $post_id  = get_the_ID();
    $poids_kg = fpt_get_poids_kg( $post_id );

    // N'injecter que sur les annonces vendeurs (qui ont un poids)
    if ( $poids_kg <= 0 ) return $content;

    $co2    = (float) get_post_meta( $post_id, '_fpt_co2_avoided', true );
    $lot_id = get_post_meta( $post_id, '_fpt_lot_id', true );

    // Si pas encore tracé (annonce existante avant installation du plugin)
    if ( ! $co2 ) {
        $titre = get_the_title( $post_id );
        $co2   = fpt_calculate_co2( $titre, $poids_kg );
        update_post_meta( $post_id, '_fpt_co2_avoided', $co2 );
        update_post_meta( $post_id, '_fpt_traced_at', current_time( 'mysql' ) );
        $lot_id = 'FP-' . strtoupper( substr( md5( $post_id . $titre ), 0, 8 ) );
        update_post_meta( $post_id, '_fpt_lot_id', $lot_id );
    }

    // Affichage CO₂
    if ( $co2 < 0.001 )    $co2_display = '< 1 g CO₂';
    elseif ( $co2 < 1 )    $co2_display = round( $co2 * 1000, 1 ) . ' kg CO₂ ' . fpt_t('évité','avoided');
    else                   $co2_display = number_format( $co2, 3 ) . ' t CO₂ ' . fpt_t('évité','avoided');

    $lot_url = get_permalink( $post_id );
    $qr_url  = fpt_qr_url( $lot_url );
    $ville   = get_post_meta( $post_id, fpt_key_ville(), true );
    $traced  = get_post_meta( $post_id, '_fpt_traced_at', true );
    $impact_url = home_url( '/impact' );

    // ── Détection du matériau et facteur CO₂ ────────────────────────────────
    $titre_lower     = strtolower( remove_accents( get_the_title( $post_id ) ) );
    $factors         = fpt_co2_factors();
    $detected_kw     = fpt_t('non reconnu','unrecognized');
    $detected_factor = 1.0;
    foreach ( $factors as $kw => $val ) {
        if ( $kw === 'default' ) continue;
        if ( strpos( $titre_lower, $kw ) !== false ) {
            $detected_kw     = ucfirst( $kw );
            $detected_factor = $val;
            break;
        }
    }
    $poids_t   = round( $poids_kg / 1000, 4 );
    $calc_line = $poids_t . ' t × ' . $detected_factor . ' = ' . number_format( $co2, 4 ) . ' t CO₂';

    ob_start(); ?>
    <div class="fpt-inline-block">
        <div class="fpt-inline-header">
            <span class="fpt-inline-icon">🌱</span>
            <div>
                <strong><?php echo fpt_t('Impact environnemental de ce lot','Environmental impact of this batch'); ?></strong>
                <span class="fpt-inline-id"><?php echo esc_html( $lot_id ); ?></span>
            </div>
            <div class="fpt-inline-co2"><?php echo esc_html( $co2_display ); ?></div>
        </div>
        <div class="fpt-inline-body">
            <div class="fpt-inline-stats">
                <div class="fpt-inline-stat">
                    <span class="fpt-inline-val"><?php echo esc_html( fpt_display_weight( $poids_kg ) ); ?></span>
                    <span class="fpt-inline-lbl"><?php echo fpt_t('Poids collecté','Weight collected'); ?></span>
                </div>
                <?php if ( $ville ): ?>
                <div class="fpt-inline-stat">
                    <span class="fpt-inline-val">📍 <?php echo esc_html( $ville ); ?></span>
                    <span class="fpt-inline-lbl"><?php echo fpt_t('Ville','City'); ?></span>
                </div>
                <?php endif; ?>
                <?php if ( $traced ): ?>
                <div class="fpt-inline-stat">
                    <span class="fpt-inline-val"><?php echo esc_html( date_i18n( 'd/m/Y', strtotime($traced) ) ); ?></span>
                    <span class="fpt-inline-lbl"><?php echo fpt_t('Date de traçage','Traced on'); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <div class="fpt-inline-qr">
                <img src="<?php echo esc_url( $qr_url ); ?>" alt="QR <?php echo esc_attr($lot_id); ?>">
                <p><?php echo fpt_t('Scanner ce lot','Scan this batch'); ?></p>
            </div>
        </div>
        <div class="fpt-inline-calc" style="display:flex!important;align-items:center;gap:8px;padding:10px 20px!important;background:#e6f5ee;border-top:1px solid #d0ddd4;flex-wrap:wrap;font-size:13px;box-sizing:border-box;width:100%;margin:0">
            <span class="fpt-inline-calc-label" style="font-weight:700;color:#1a7a4a;flex-shrink:0">🔍 <?php echo fpt_t('Calcul','Calculation'); ?></span>
            <span class="fpt-inline-calc-material" style="font-weight:700;color:#0f1c13;background:#fff;border:1px solid #d0ddd4;padding:2px 8px;border-radius:4px"><?php echo esc_html( $detected_kw ); ?></span>
            <span style="color:#6b8070">·</span>
            <span class="fpt-inline-calc-factor" style="font-family:monospace;font-size:12px;color:#1a7a4a;font-weight:700"><?php echo esc_html( $detected_factor ); ?> t CO₂/t</span>
            <span style="color:#6b8070">·</span>
            <span class="fpt-inline-calc-formula" style="font-family:monospace;font-size:12px;color:#1e2d22;flex:1"><?php echo esc_html( $calc_line ); ?></span>
            <span class="fpt-inline-calc-source" style="font-size:10px;color:#6b8070;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;flex-shrink:0">ADEME</span>
        </div>
        <div class="fpt-inline-footer">
            <a href="<?php echo esc_url( $impact_url ); ?>">🌍 <?php echo fpt_t("Voir l'impact global FerayPro",'View FerayPro global impact'); ?></a>
            <span><?php echo fpt_t('Source : ADEME Base Carbone · Open Source MIT','Source: ADEME Base Carbone · Open Source MIT'); ?></span>
        </div>
    </div>
    <?php
    // ── Bloc Prix du Jour ────────────────────────────────────────────────
    $titre_lot = get_the_title( $post_id );
    $prix_data = fpt_get_prix_du_jour( $titre_lot );
    if ( $prix_data ) :
        $desc_formatted = wp_kses( $prix_data['description'], [
        'p'      => [],
        'br'     => [],
        'strong' => [],
        'b'      => [],
        'em'     => [],
        'i'      => [],
        'ul'     => [],
        'ol'     => [],
        'li'     => [],
        'img'    => [ 'src' => [], 'alt' => [], 'width' => [], 'height' => [], 'class' => [], 'style' => [] ],
        'a'      => [ 'href' => [], 'target' => [] ],
        'h2'     => [],
        'h3'     => [],
        'h4'     => [],
        'span'   => [ 'style' => [], 'class' => [] ],
        'div'    => [ 'style' => [], 'class' => [] ],
        'table'  => [ 'style' => [], 'class' => [] ],
        'tr'     => [],
        'td'     => [ 'style' => [], 'colspan' => [] ],
        'th'     => [ 'style' => [], 'colspan' => [] ],
        'thead'  => [],
        'tbody'  => [],
        'figure' => [ 'class' => [] ],
        'figcaption' => [],
    ] );
    ?>
    <style>
    .fpt-prix-block{font-family:'DM Sans',Arial,sans-serif;margin-top:16px;border:2px solid #e8a020;border-radius:12px;overflow:hidden;background:#fff}
    .fpt-prix-header{background:#f59e0b;padding:14px 20px;display:flex;align-items:center;gap:12px;justify-content:space-between;flex-wrap:wrap}
    .fpt-prix-header-left{display:flex;align-items:center;gap:10px}
    .fpt-prix-icon{font-size:22px}
    .fpt-prix-title{font-weight:700;font-size:15px;color:#1a1a1a}
    .fpt-prix-updated{font-size:11px;color:rgba(0,0,0,0.55)}
    .fpt-prix-range{background:#fff;border-radius:6px;padding:4px 12px;font-weight:700;font-size:14px;color:#92400e}
    .fpt-prix-body{padding:18px 20px}
    .fpt-prix-desc{font-size:14px;line-height:1.75;color:#1e2d22}
    .fpt-prix-desc img{max-width:100%!important;height:auto!important;display:block;margin:12px 0;border-radius:6px}
    .fpt-prix-desc p{margin:0 0 10px}
    .fpt-prix-desc ul,.fpt-prix-desc ol{padding-left:20px;margin:0 0 10px}
    .fpt-prix-desc li{margin-bottom:4px}
    .fpt-prix-desc figure{margin:12px 0}
    .fpt-prix-desc figure img{width:100%}
    .fpt-prix-footer{background:#fef3c7;padding:10px 20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;border-top:1px solid #fde68a}
    .fpt-prix-footer a{font-size:13px;font-weight:600;color:#92400e;text-decoration:none}
    .fpt-prix-footer span{font-size:11px;color:#b45309}
    </style>
    <div class="fpt-prix-block">
        <div class="fpt-prix-header">
            <div class="fpt-prix-header-left">
                <span class="fpt-prix-icon">💰</span>
                <div>
                    <div class="fpt-prix-title"><?php echo fpt_t('Prix du jour — ','Today\'s Prices — '); ?><?php echo esc_html( $prix_data['titre'] ); ?></div>
                    <?php if ( $prix_data['updated'] ): ?>
                    <div class="fpt-prix-updated"><?php echo fpt_t('Mis à jour le','Updated'); ?> <?php echo esc_html( $prix_data['updated'] ); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ( $prix_data['prix_range'] ): 
                $unit_label = get_option( 'fpt_weight_unit', 'kg' ) === 'lb' ? '/lb' : '/kg';
            ?>
            <div class="fpt-prix-range">📊 <?php echo esc_html( $prix_data['prix_range'] ); ?> <?php echo esc_html( $unit_label ); ?></div>
            <?php endif; ?>
        </div>
        <div class="fpt-prix-body">
            <div class="fpt-prix-desc"><?php echo $desc_formatted; ?></div>
        </div>
        <div class="fpt-prix-footer">
            <a href="<?php echo esc_url( $prix_data['url'] ); ?>">📋 <?php echo fpt_t('Voir tous les prix du marché →','View all market prices →'); ?></a>
            <span><?php echo fpt_t('Mis à jour par FerayPro','Updated by FerayPro'); ?></span>
        </div>
    </div>
    <?php endif; ?>
    <?php
    $block = ob_get_clean();
    return $content . $block;
}
define( 'FPT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FPT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ─── Facteurs CO₂ évité par tonne (source ADEME / Base Carbone) ───────────────
function fpt_co2_factors() {
    return [

        // ════════════════════════════════════════════════════════════════
        // MÉTAUX FERREUX / FERROUS METALS
        // ════════════════════════════════════════════════════════════════
        'fer'              => 1.8,
        'iron'             => 1.8,
        'acier'            => 1.8,
        'steel'            => 1.8,
        'ferraille'        => 1.8,
        'scrap'            => 1.8,
        'scrap metal'      => 1.8,
        'fonte'            => 1.6,
        'cast iron'        => 1.6,
        'inox'             => 2.5,
        'stainless'        => 2.5,
        'acier inox'       => 2.5,
        'stainless steel'  => 2.5,
        'tournure'         => 1.8,
        'shavings'         => 1.8,
        'turnings'         => 1.8,
        'copeau'           => 1.8,
        'chips'            => 1.8,
        'limaille'         => 1.8,
        'filings'          => 1.8,
        'radiateur'        => 1.8,
        'radiator'         => 1.8,
        'ressort'          => 1.8,
        'spring'           => 1.8,
        'blindage'         => 1.8,
        'armor'            => 1.8,
        'poutrelle'        => 1.8,
        'beam'             => 1.8,
        'profilé'          => 1.8,
        'profile'          => 1.8,
        'tole'             => 1.8,
        'tôle'             => 1.8,
        'sheet metal'      => 1.8,
        'tube acier'       => 1.8,
        'steel pipe'       => 1.8,
        'rail'             => 1.8,
        'rebar'            => 1.8,
        'fer à béton'      => 1.8,
        'fer beton'        => 1.8,
        'reinforcing bar'  => 1.8,
        'chaudière'        => 1.8,
        'chaudiere'        => 1.8,
        'boiler'           => 1.8,
        'reservoir'        => 1.8,
        'réservoir'        => 1.8,
        'tank'             => 1.8,
        'cuve'             => 1.8,
        'vat'              => 1.8,
        'conteneur'        => 1.8,
        'container'        => 1.8,
        'chassis'          => 1.8,
        'châssis'          => 1.8,
        'frame'            => 1.8,
        'essieu'           => 1.8,
        'axle'             => 1.8,
        'vilebrequin'      => 1.8,
        'crankshaft'       => 1.8,
        'bielle'           => 1.8,
        'connecting rod'   => 1.8,
        'engrenage'        => 1.8,
        'gear'             => 1.8,
        'roulement'        => 1.8,
        'bearing'          => 1.8,
        'arbre'            => 1.8,
        'shaft'            => 1.8,
        'ancre'            => 1.8,
        'anchor'           => 1.8,
        'chaine'           => 1.8,
        'chaîne'           => 1.8,
        'chain'            => 1.8,

        // ════════════════════════════════════════════════════════════════
        // MÉTAUX NON FERREUX
        // ════════════════════════════════════════════════════════════════
        'aluminium'        => 9.5,
        'alu'              => 9.5,
        'profilé alu'      => 9.5,
        'canette'          => 9.5,
        'cuivre'           => 3.5,
        'bronze'           => 3.2,
        'laiton'           => 3.0,
        'zinc'             => 2.0,
        'plomb'            => 1.2,
        'lead'             => 1.2,
        'etain'            => 4.0,
        'étain'            => 4.0,
        'tin'              => 4.0,
        'nickel'           => 6.5,
        'titane'           => 5.0,
        'titanium'         => 5.0,
        'magnesium'        => 7.0,
        'magnésium'        => 7.0,
        'chrome'           => 3.0,
        'chromium'         => 3.0,
        'tungstene'        => 3.5,
        'tungstène'        => 3.5,
        'tungsten'         => 3.5,
        'carbure'          => 3.5,
        'carbide'          => 3.5,
        'cobalt'           => 8.0,
        'bismuth'          => 2.5,
        'antimoine'        => 2.0,
        'antimony'         => 2.0,
        'cadmium'          => 3.0,
        'indium'           => 5.0,
        'gallium'          => 5.0,
        'germanium'        => 5.0,
        'palladium'        => 10.0,
        'platine'          => 12.0,
        'platinum'         => 12.0,
        'argent'           => 8.0,
        'silver'           => 8.0,
        'or'               => 15.0,
        'gold'             => 15.0,
        'molybdene'        => 4.0,
        'molybdène'        => 4.0,
        'molybdenum'       => 4.0,
        'vanadium'         => 4.5,
        'manganèse'        => 2.5,
        'manganese'        => 2.5,
        'copper'           => 3.5,
        'bronze'           => 3.2,
        'brass'            => 3.0,
        'aluminum'         => 9.5,
        'aluminium'        => 9.5,
        'alu'              => 9.5,
        'can'              => 9.5,

        // ════════════════════════════════════════════════════════════════
        // VÉHICULES & PIÈCES AUTO / VEHICLES & AUTO PARTS
        // ════════════════════════════════════════════════════════════════
        'vehicule'         => 1.8,
        'véhicule'         => 1.8,
        'vehicle'          => 1.8,
        'end-of-life'      => 1.8,
        'end of life'      => 1.8,
        'salvage'          => 1.8,
        'junk car'         => 1.8,
        'voiture'          => 1.8,
        'car'              => 1.8,
        'camion'           => 1.8,
        'truck'            => 1.8,
        'camionnette'      => 1.8,
        'van'              => 1.8,
        'bus'              => 1.8,
        'autobus'          => 1.8,
        'tracteur'         => 1.8,
        'tractor'          => 1.8,
        'engin'            => 1.8,
        'moto'             => 1.8,
        'motorcycle'       => 1.8,
        'motocycle'        => 1.8,
        'scooter'          => 1.8,
        'velo'             => 1.8,
        'vélo'             => 1.8,
        'bicycle'          => 1.8,
        'bike'             => 1.8,
        'moteur'           => 2.2,
        'motor'            => 2.2,
        'engine'           => 2.2,
        'alternateur'      => 2.5,
        'alternator'       => 2.5,
        'demarreur'        => 2.2,
        'démarreur'        => 2.2,
        'starter'          => 2.2,
        'boite'            => 1.8,
        'boîte'            => 1.8,
        'gearbox'          => 1.8,
        'transmission'     => 1.8,
        'pont'             => 1.8,
        'axle'             => 1.8,
        'jante'            => 9.5,
        'rim'              => 9.5,
        'wheel'            => 9.5,
        'carter'           => 1.8,
        'casing'           => 1.8,
        'culasse'          => 1.8,
        'cylinder head'    => 1.8,
        'bloc moteur'      => 1.8,
        'engine block'     => 1.8,
        'turbo'            => 2.0,
        'turbocompresseur' => 2.0,
        'turbocharger'     => 2.0,
        'compresseur'      => 2.0,
        'compressor'       => 2.0,
        'pompe'            => 2.0,
        'pump'             => 2.0,
        'amortisseur'      => 1.8,
        'shock absorber'   => 1.8,
        'suspension'       => 1.8,
        'direction'        => 1.8,
        'steering'         => 1.8,
        'frein'            => 1.8,
        'brake'            => 1.8,
        'disque'           => 1.8,
        'disc'             => 1.8,
        'carrosserie'      => 1.8,
        'body'             => 1.8,
        'bodywork'         => 1.8,
        'pare choc'        => 1.5,
        'pare-choc'        => 1.5,
        'bumper'           => 1.5,
        'capot'            => 1.8,
        'hood'             => 1.8,
        'bonnet'           => 1.8,
        'portière'         => 1.8,
        'portiere'         => 1.8,
        'door'             => 1.8,
        'pot echappement'  => 1.8,
        'exhaust'          => 1.8,
        'muffler'          => 1.8,
        'catalyseur'       => 5.0,
        'catalytic'        => 5.0,
        'catalyst'         => 5.0,

        // ════════════════════════════════════════════════════════════════
        // ÉLECTRONIQUE & E-WASTE / ELECTRONICS & E-WASTE
        // ════════════════════════════════════════════════════════════════
        'electronique'     => 4.0,
        'électronique'     => 4.0,
        'electronics'      => 4.0,
        'electronic'       => 4.0,
        'ewaste'           => 4.0,
        'e-waste'          => 4.0,
        'weee'             => 4.0,
        'deee'             => 4.0,
        'electrique'       => 3.5,
        'électrique'       => 3.5,
        'electric'         => 3.5,
        'electrical'       => 3.5,
        'ordinateur'       => 4.0,
        'computer'         => 4.0,
        'laptop'           => 4.0,
        'desktop'          => 4.0,
        'pc'               => 4.0,
        'serveur'          => 4.0,
        'server'           => 4.0,
        'telephone'        => 4.5,
        'téléphone'        => 4.5,
        'phone'            => 4.5,
        'smartphone'       => 4.5,
        'cellphone'        => 4.5,
        'mobile'           => 4.5,
        'gsm'              => 4.5,
        'tablette'         => 4.0,
        'tablet'           => 4.0,
        'imprimante'       => 3.5,
        'printer'          => 3.5,
        'photocopieur'     => 3.5,
        'copier'           => 3.5,
        'scanner'          => 3.5,
        'ecran'            => 3.5,
        'écran'            => 3.5,
        'screen'           => 3.5,
        'monitor'          => 3.5,
        'moniteur'         => 3.5,
        'television'       => 3.5,
        'télévision'       => 3.5,
        'tv'               => 3.5,
        'cable'            => 3.0,
        'câble'            => 3.0,
        'wire'             => 3.0,
        'wiring'           => 3.0,
        'fil'              => 3.0,
        'transformateur'   => 3.0,
        'transformer'      => 3.0,
        'condensateur'     => 3.5,
        'capacitor'        => 3.5,
        'carte'            => 4.0,
        'board'            => 4.0,
        'motherboard'      => 4.0,
        'circuit'          => 4.0,
        'processeur'       => 4.5,
        'processor'        => 4.5,
        'cpu'              => 4.5,
        'gpu'              => 4.5,
        'disque dur'       => 4.0,
        'hard drive'       => 4.0,
        'hard disk'        => 4.0,
        'clavier'          => 3.0,
        'keyboard'         => 3.0,
        'souris'           => 3.0,
        'mouse'            => 3.0,
        'chargeur'         => 3.0,
        'charger'          => 3.0,
        'adaptateur'       => 3.0,
        'adapter'          => 3.0,
        'onduleur'         => 3.0,
        'ups'              => 3.0,
        'groupe electrogene' => 2.0,
        'generator'        => 2.0,
        'generateur'       => 2.0,
        'générateur'       => 2.0,
        'climatiseur'      => 3.5,
        'climatisation'    => 3.5,
        'air conditioner'  => 3.5,
        'ac unit'          => 3.5,
        'refrigerateur'    => 3.0,
        'réfrigérateur'    => 3.0,
        'refrigerator'     => 3.0,
        'fridge'           => 3.0,
        'frigo'            => 3.0,
        'congelateur'      => 3.0,
        'congélateur'      => 3.0,
        'freezer'          => 3.0,
        'lave linge'       => 2.5,
        'lave-linge'       => 2.5,
        'washing machine'  => 2.5,
        'washer'           => 2.5,
        'machine a laver'  => 2.5,
        'seche linge'      => 2.5,
        'sèche-linge'      => 2.5,
        'dryer'            => 2.5,
        'lave vaisselle'   => 2.5,
        'lave-vaisselle'   => 2.5,
        'dishwasher'       => 2.5,
        'four'             => 2.0,
        'oven'             => 2.0,
        'micro onde'       => 3.0,
        'micro-onde'       => 3.0,
        'microwave'        => 3.0,
        'aspirateur'       => 2.5,
        'vacuum'           => 2.5,
        'ventilateur'      => 2.5,
        'fan'              => 2.5,
        'pompe chaleur'    => 3.5,
        'heat pump'        => 3.5,
        'panneau solaire'  => 3.0,
        'solar panel'      => 3.0,
        'photovoltaique'   => 3.0,
        'photovoltaïque'   => 3.0,
        'photovoltaic'     => 3.0,

        // ════════════════════════════════════════════════════════════════
        // BATTERIES & STOCKAGE ÉNERGIE / BATTERIES & ENERGY STORAGE
        // ════════════════════════════════════════════════════════════════
        'batterie'         => 2.5,
        'battery'          => 2.5,
        'batteries'        => 2.5,
        'accumulateur'     => 2.5,
        'pile'             => 2.5,
        'batterie lithium' => 5.0,
        'lithium battery'  => 5.0,
        'lithium'          => 5.0,
        'li-ion'           => 5.0,
        'batterie plomb'   => 2.5,
        'lead battery'     => 2.5,
        'car battery'      => 2.5,
        'batterie voiture' => 2.5,

        // ════════════════════════════════════════════════════════════════
        // PAPIER & CARTON / PAPER & CARDBOARD
        // ════════════════════════════════════════════════════════════════
        'papier'           => 0.9,
        'paper'            => 0.9,
        'carton'           => 0.9,
        'cardboard'        => 0.9,
        'journal'          => 0.9,
        'newspaper'        => 0.9,
        'archive'          => 0.9,
        'livre'            => 0.9,
        'book'             => 0.9,
        'magazine'         => 0.9,
        'emballage'        => 0.9,
        'packaging'        => 0.9,
        'ondule'           => 0.9,
        'ondulé'           => 0.9,
        'corrugated'       => 0.9,
        'magazine'         => 0.9,
        'emballage'        => 0.9,
        'papier craft'     => 0.9,
        'ondule'           => 0.9,
        'ondulé'           => 0.9,

        // ════════════════════════════════════════════════════════════════
        // PLASTIQUES
        // ════════════════════════════════════════════════════════════════
        'plastique'        => 1.5,
        'pet'              => 1.5,   // bouteilles
        'hdpe'             => 1.4,   // bidons
        'pvc'              => 1.3,
        'polypropylene'    => 1.5,
        'polypropylène'    => 1.5,
        'pp'               => 1.5,
        'polyethylene'     => 1.4,
        'polyéthylène'     => 1.4,
        'pe'               => 1.4,
        'polystyrene'      => 1.6,
        'polystyrène'      => 1.6,
        'ps'               => 1.6,
        'abs'              => 1.5,
        'nylon'            => 1.8,
        'polyamide'        => 1.8,
        'caoutchouc'       => 1.2,
        'latex'            => 1.2,
        'silicone'         => 1.3,
        'fibre de verre'   => 1.0,
        'composite'        => 1.2,

        // ════════════════════════════════════════════════════════════════
        // PNEUMATIQUES & CAOUTCHOUC
        // ════════════════════════════════════════════════════════════════
        'pneu'             => 1.2,
        'pneumatique'      => 1.2,
        'chambre air'      => 1.2,
        'chambre à air'    => 1.2,
        'courroie'         => 1.2,
        'joint'            => 1.2,
        'tuyau'            => 1.2,

        // ════════════════════════════════════════════════════════════════
        // VERRE
        // ════════════════════════════════════════════════════════════════
        'verre'            => 0.3,
        'bouteille verre'  => 0.3,
        'vitre'            => 0.3,
        'pare brise'       => 0.3,
        'pare-brise'       => 0.3,
        'miroir'           => 0.3,

        // ════════════════════════════════════════════════════════════════
        // TEXTILES & CUIR
        // ════════════════════════════════════════════════════════════════
        'textile'          => 0.5,
        'tissu'            => 0.5,
        'vetement'         => 0.5,
        'vêtement'         => 0.5,
        'chiffon'          => 0.5,
        'laine'            => 0.5,
        'coton'            => 0.5,
        'cuir'             => 0.6,
        'sac'              => 0.5,
        'chaussure'        => 0.6,

        // ════════════════════════════════════════════════════════════════
        // BOIS & DÉRIVÉS
        // ════════════════════════════════════════════════════════════════
        'bois'             => 0.4,
        'palette'          => 0.4,
        'meuble'           => 0.4,
        'contreplaque'     => 0.4,
        'contreplaqué'     => 0.4,
        'mdf'              => 0.4,
        'sciure'           => 0.4,
        'copeaux bois'     => 0.4,

        // ════════════════════════════════════════════════════════════════
        // DÉCHETS INDUSTRIELS SPÉCIAUX
        // ════════════════════════════════════════════════════════════════
        'huile'            => 2.5,   // huile moteur recyclée
        'lubrifiant'       => 2.5,
        'solvant'          => 1.5,
        'acide'            => 1.0,
        'peinture'         => 1.2,
        'encre'            => 1.5,
        'resine'           => 1.3,
        'résine'           => 1.3,
        'ciment'           => 0.2,
        'beton'            => 0.2,
        'béton'            => 0.2,
        'gravat'           => 0.1,
        'dechet chantier'  => 0.2,
        'déchet chantier'  => 0.2,
        'amiante'          => 0.5,
        'fibrociment'      => 0.3,

        // ════════════════════════════════════════════════════════════════
        // DÉCHETS ORGANIQUES & BIOMASSE
        // ════════════════════════════════════════════════════════════════
        'organique'        => 0.3,
        'alimentaire'      => 0.3,
        'biomasse'         => 0.3,
        'compost'          => 0.3,
        'dechets verts'    => 0.3,
        'déchets verts'    => 0.3,

        // ════════════════════════════════════════════════════════════════
        // ÉQUIPEMENTS INDUSTRIELS
        // ════════════════════════════════════════════════════════════════
        'machine'          => 2.0,
        'machine outil'    => 2.0,
        'tour'             => 2.0,   // tour mécanique
        'fraiseuse'        => 2.0,
        'presse'           => 1.8,
        'grue'             => 1.8,
        'chariot'          => 1.8,
        'elevateur'        => 1.8,
        'élévateur'        => 1.8,
        'convoyeur'        => 1.8,
        'chaudiere'        => 1.8,
        'chaudière'        => 1.8,
        'echangeur'        => 2.0,   // échangeur thermique (cuivre/inox)
        'échangeur'        => 2.0,
        'tuyauterie'       => 2.0,
        'robinetterie'     => 2.5,   // laiton/bronze
        'vanne'            => 2.0,
        'pompe industrielle' => 2.0,
        'motopompe'        => 2.2,
        'compresseur air'  => 2.0,
        'soudure'          => 1.8,
        'electrode'        => 2.0,

        // ════════════════════════════════════════════════════════════════
        // DÉFAUT
        // ════════════════════════════════════════════════════════════════
        'default'          => 1.0,
    ];
}

// ─── Calcul CO₂ évité ─────────────────────────────────────────────────────────
function fpt_calculate_co2( $titre, $poids_kg ) {
    if ( empty( $poids_kg ) || $poids_kg <= 0 ) return 0;

    $factors = fpt_co2_factors();
    $titre_lower = strtolower( remove_accents( $titre ) );
    $factor = $factors['default'];

    foreach ( $factors as $keyword => $value ) {
        if ( strpos( $titre_lower, $keyword ) !== false ) {
            $factor = $value;
            break;
        }
    }

    // poids en kg → tonnes
    $poids_tonnes = $poids_kg / 1000;
    return round( $poids_tonnes * $factor, 4 );
}

// ─── Générer QR Code URL (API gratuite QRServer) ──────────────────────────────
function fpt_qr_url( $lot_url ) {
    return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode( $lot_url );
}

// ─── Helper : meta keys configurables ────────────────────────────────────────
function fpt_key_poids()    { return 'hp_' . get_option( 'fpt_key_poids',    'poids' ); }
function fpt_key_ville()    { return 'hp_' . get_option( 'fpt_key_ville',    'ville' ); }
function fpt_key_whatsapp() { return 'hp_' . get_option( 'fpt_key_whatsapp', 'whatsapp' ); }
function fpt_key_prix()     { return 'hp_' . get_option( 'fpt_key_prix',     'prixvendeur' ); }

function fpt_get_poids_kg( $post_id ) {
    $val  = (float) get_post_meta( $post_id, fpt_key_poids(), true );
    $unit = get_option( 'fpt_weight_unit', 'kg' );
    return $unit === 'lb' ? round( $val * 0.453592, 3 ) : $val;
}


function fpt_calculate_health( $listings_ids ) {
    // Facteurs de polluants évités par tonne recyclée (vs recyclage informel/brûlage)
    // Sources : OMS, Pure Earth, EPA, études terrain Afrique
    $health_factors = [
        // Plomb évité (kg/tonne) — batteries, tuyaux, soudure, peinture
        'plomb_keywords'   => ['plomb','batterie','accumulateur','soudure','peinture','radiateur'],
        'plomb_kg_per_t'   => 0.5,   // ~500g plomb non dispersé / tonne recyclée proprement

        // Cadmium évité (g/tonne) — piles, e-waste, plastiques
        'cadmium_keywords' => ['pile','batterie lithium','electronique','ewaste','plastique','telephone','ordinateur','smartphone'],
        'cadmium_g_per_t'  => 200,   // 200g cadmium / tonne

        // PM2.5 évité (kg/tonne) — brûlage câbles cuivre, plastiques
        'pm25_keywords'    => ['cable','fil','cuivre','plastique','pvc','caoutchouc','pneu'],
        'pm25_kg_per_t'    => 15,    // 15kg PM2.5 / tonne câble brûlé évité

        // Mercure évité (g/tonne) — écrans, thermomètres, lampes
        'mercure_keywords' => ['ecran','moniteur','television','tv','lampe','neon','thermometre'],
        'mercure_g_per_t'  => 50,    // 50g mercure / tonne
    ];

    $plomb_kg = $cadmium_g = $pm25_kg = $mercure_g = 0;

    foreach ( $listings_ids as $id ) {
        $poids_kg = (float) fpt_get_poids_kg( $id );
        if ( $poids_kg <= 0 ) continue;
        $poids_t  = $poids_kg / 1000;
        $titre    = strtolower( remove_accents( get_the_title( $id ) ) );

        foreach ( $health_factors['plomb_keywords'] as $kw ) {
            if ( strpos( $titre, $kw ) !== false ) {
                $plomb_kg += $poids_t * $health_factors['plomb_kg_per_t'];
                break;
            }
        }
        foreach ( $health_factors['cadmium_keywords'] as $kw ) {
            if ( strpos( $titre, $kw ) !== false ) {
                $cadmium_g += $poids_t * $health_factors['cadmium_g_per_t'];
                break;
            }
        }
        foreach ( $health_factors['pm25_keywords'] as $kw ) {
            if ( strpos( $titre, $kw ) !== false ) {
                $pm25_kg += $poids_t * $health_factors['pm25_kg_per_t'];
                break;
            }
        }
        foreach ( $health_factors['mercure_keywords'] as $kw ) {
            if ( strpos( $titre, $kw ) !== false ) {
                $mercure_g += $poids_t * $health_factors['mercure_g_per_t'];
                break;
            }
        }
    }

    return [
        'plomb_kg'   => round( $plomb_kg, 3 ),
        'cadmium_g'  => round( $cadmium_g, 1 ),
        'pm25_kg'    => round( $pm25_kg, 2 ),
        'mercure_g'  => round( $mercure_g, 1 ),
        // Enfants protégés : 1 kg plomb non dispersé = ~50 enfants protégés (OMS)
        'enfants'    => round( $plomb_kg * 50 + $pm25_kg * 10 ),
    ];
}


add_action( 'save_post_hp_listing', 'fpt_on_listing_save', 20, 3 );
function fpt_on_listing_save( $post_id, $post, $update ) {
    if ( wp_is_post_revision( $post_id ) ) return;
    if ( $post->post_status !== 'publish' ) return;

    $titre    = get_the_title( $post_id );
    $poids_kg = fpt_get_poids_kg( $post_id );
    $ville    = get_post_meta( $post_id, fpt_key_ville(), true );

    if ( $poids_kg <= 0 ) return;

    $co2 = fpt_calculate_co2( $titre, $poids_kg );

    // Stocker les données de traçabilité
    update_post_meta( $post_id, '_fpt_co2_avoided', $co2 );
    update_post_meta( $post_id, '_fpt_traced_at', current_time( 'mysql' ) );
    update_post_meta( $post_id, '_fpt_lot_id', 'FP-' . strtoupper( substr( md5( $post_id . $titre ), 0, 8 ) ) );

    // Mettre à jour les totaux globaux
    fpt_update_global_stats( $co2, $poids_kg );
}

// ─── Mettre à jour les stats globales ─────────────────────────────────────────
function fpt_update_global_stats( $co2_new, $poids_new ) {
    $total_co2   = (float) get_option( 'fpt_total_co2', 0 );
    $total_poids = (float) get_option( 'fpt_total_poids', 0 );
    $total_lots  = (int)   get_option( 'fpt_total_lots', 0 );

    update_option( 'fpt_total_co2',   $total_co2 + $co2_new );
    update_option( 'fpt_total_poids', $total_poids + $poids_new );
    update_option( 'fpt_total_lots',  $total_lots + 1 );
}

// ─── Recalculer les stats globales depuis zéro ────────────────────────────────
function fpt_recalculate_global_stats() {
    // Récupérer TOUTES les annonces publiées (pas seulement celles déjà tracées)
    $listings = get_posts([
        'post_type'   => 'hp_listing',
        'post_status' => 'publish',
        'numberposts' => -1,
        'fields'      => 'ids',
    ]);

    $total_co2 = $total_poids = $total_lots = 0;

    foreach ( $listings as $id ) {
        $poids_kg = (float) fpt_get_poids_kg( $id );
        if ( $poids_kg <= 0 ) continue; // ignorer annonces sans poids (acheteurs)

        $titre = get_the_title( $id );
        $co2   = fpt_calculate_co2( $titre, $poids_kg );

        // Enregistrer les metas sur chaque annonce
        update_post_meta( $id, '_fpt_co2_avoided', $co2 );
        update_post_meta( $id, '_fpt_lot_id', 'FP-' . strtoupper( substr( md5( $id . $titre ), 0, 8 ) ) );
        if ( ! get_post_meta( $id, '_fpt_traced_at', true ) ) {
            update_post_meta( $id, '_fpt_traced_at', current_time( 'mysql' ) );
        }

        $total_co2   += $co2;
        $total_poids += $poids_kg;
        $total_lots++;
    }

    update_option( 'fpt_total_co2',   round( $total_co2, 4 ) );
    update_option( 'fpt_total_poids', $total_poids );
    update_option( 'fpt_total_lots',  $total_lots );
}

// ─── Shortcode : fiche publique d'un lot ──────────────────────────────────────
add_shortcode( 'fpt_lot', 'fpt_shortcode_lot' );
function fpt_shortcode_lot( $atts ) {
    $atts = shortcode_atts([ 'id' => 0 ], $atts );
    $post_id = (int) $atts['id'];

    if ( ! $post_id ) {
        $post_id = get_the_ID();
    }

    if ( ! $post_id ) return '';

    $titre    = get_the_title( $post_id );
    $poids    = (float) get_post_meta( $post_id, fpt_key_poids(), true );
    $ville    = get_post_meta( $post_id, fpt_key_ville(), true );
    $prix     = get_post_meta( $post_id, fpt_key_prix(), true );
    $whatsapp = get_post_meta( $post_id, fpt_key_whatsapp(), true );
    $co2      = (float) get_post_meta( $post_id, '_fpt_co2_avoided', true );
    $lot_id   = get_post_meta( $post_id, '_fpt_lot_id', true );
    $traced   = get_post_meta( $post_id, '_fpt_traced_at', true );
    $thumb    = get_the_post_thumbnail_url( $post_id, 'medium' );
    $lot_url  = get_permalink( $post_id );
    $qr_url   = fpt_qr_url( $lot_url );

    // CO₂ en grammes si < 1kg
    if ( $co2 < 0.001 ) {
        $co2_display = '< 1 g';
    } elseif ( $co2 < 1 ) {
        $co2_display = round( $co2 * 1000, 1 ) . ' kg';
    } else {
        $co2_display = number_format( $co2, 3 ) . ' t';
    }

    ob_start(); ?>
    <div class="fpt-lot-card">
        <div class="fpt-lot-header">
            <div class="fpt-lot-badge">
                <span class="fpt-lot-id"><?php echo esc_html( $lot_id ?: 'FP-PENDING' ); ?></span>
                <span class="fpt-lot-verified">✓ Tracé</span>
            </div>
            <h2 class="fpt-lot-title"><?php echo esc_html( $titre ); ?></h2>
            <?php if ( $ville ): ?>
            <p class="fpt-lot-location">📍 <?php echo esc_html( $ville ); ?></p>
            <?php endif; ?>
        </div>

        <div class="fpt-lot-body">
            <?php if ( $thumb ): ?>
            <div class="fpt-lot-photo">
                <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $titre ); ?>">
            </div>
            <?php endif; ?>

            <div class="fpt-lot-data">
                <div class="fpt-stat-grid">
                    <div class="fpt-stat">
                        <span class="fpt-stat-value"><?php echo esc_html( fpt_display_weight( $poids ) ); ?></span>
                        <span class="fpt-stat-label"><?php echo fpt_t('kg collectés', fpt_weight_unit_label() . ' collected'); ?></span>
                    </div>
                    <div class="fpt-stat fpt-stat--green">
                        <span class="fpt-stat-value"><?php echo esc_html( $co2_display ); ?></span>
                        <span class="fpt-stat-label">CO₂ évité</span>
                    </div>
                    <?php if ( $prix ): ?>
                    <div class="fpt-stat">
                        <span class="fpt-stat-value"><?php echo esc_html( number_format( $prix, 0, ',', ' ' ) ); ?> DH</span>
                        <span class="fpt-stat-label">Prix / tonne</span>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ( $traced ): ?>
                <p class="fpt-traced-date"><?php echo fpt_t('Tracé le','Traced on'); ?> <?php echo esc_html( date_i18n( 'd/m/Y à H:i', strtotime( $traced ) ) ); ?></p>
                <?php endif; ?>

                <?php if ( $whatsapp ): ?>
                <a class="fpt-whatsapp-btn" href="https://wa.me/<?php echo esc_attr( preg_replace('/[^0-9]/', '', $whatsapp) ); ?>" target="_blank">
                    💬 <?php echo fpt_t('Contacter via WhatsApp','Contact via WhatsApp'); ?>
                </a>
                <?php endif; ?>
            </div>

            <div class="fpt-lot-qr">
                <img src="<?php echo esc_url( $qr_url ); ?>" alt="QR Code lot <?php echo esc_attr( $lot_id ); ?>">
                <p>Scanner pour accéder à ce lot</p>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ─── Shortcode : Dashboard global ─────────────────────────────────────────────
add_shortcode( 'fpt_dashboard', 'fpt_shortcode_dashboard' );
function fpt_shortcode_dashboard( $atts ) {
    $total_co2    = (float) get_option( 'fpt_total_co2', 0 );
    $total_poids  = (float) get_option( 'fpt_total_poids', 0 );
    $total_lots   = (int)   get_option( 'fpt_total_lots', 0 );
    $country_name = get_option( 'fpt_country_name', '' );
    $site_name    = get_option( 'fpt_site_name', 'FerayPro' );

    $title_suffix = $country_name ? ( fpt_lang() === 'en' ? ' in ' : ' au ' ) . $country_name : '';
    $subtitle     = fpt_t('Traçabilité en temps réel des déchets recyclés','Real-time traceability of recycled waste') . ( $country_name ? ( fpt_lang() === 'en' ? ' in ' : ' au ' ) . $country_name : '' );

    // Équivalents impact
    $arbres     = round( $total_co2 * 45 );
    $km_voiture = round( $total_co2 * 6000 );

    // Récupérer tous les IDs pour calcul santé
    $all_ids = get_posts([
        'post_type'   => 'hp_listing',
        'post_status' => 'publish',
        'numberposts' => -1,
        'fields'      => 'ids',
        'meta_query'  => [[ 'key' => fpt_key_poids(), 'compare' => 'EXISTS' ]],
    ]);
    $health = fpt_calculate_health( $all_ids );

    // Derniers lots tracés
    $recent = get_posts([
        'post_type'   => 'hp_listing',
        'post_status' => 'publish',
        'numberposts' => 5,
        'meta_query'  => [[ 'key' => '_fpt_co2_avoided', 'compare' => 'EXISTS' ]],
        'orderby'     => 'meta_value',
        'meta_key'    => '_fpt_traced_at',
        'order'       => 'DESC',
    ]);

    ob_start(); ?>
    <style>
    .fpt-health-section{margin:36px 0;background:#fff;border:1.5px solid #d0ddd4;border-radius:12px;overflow:hidden}
    .fpt-health-header{background:#1a1a2e;padding:20px 24px}
    .fpt-health-header h3{font-size:18px;font-weight:700;margin:0 0 4px;color:#fff}
    .fpt-health-header p{font-size:12px;color:rgba(255,255,255,0.6);margin:0}
    .fpt-health-grid{display:grid!important;grid-template-columns:repeat(4,1fr)!important;border-bottom:1px solid #d0ddd4}
    .fpt-health-card{padding:20px 16px;text-align:center;border-right:1px solid #d0ddd4;background:#f4f6f4;display:flex!important;flex-direction:column!important;align-items:center!important;gap:4px}
    .fpt-health-card:last-child{border-right:none}
    .fpt-health-icon{font-size:28px;display:block;margin-bottom:6px}
    .fpt-health-value{font-size:20px;font-weight:700;line-height:1.2;display:block}
    .fpt-health-label{font-size:11px;font-weight:600;color:#1e2d22;text-transform:uppercase;letter-spacing:.05em;display:block;margin-top:4px}
    .fpt-health-info{font-size:11px;color:#6b8070;line-height:1.4;display:block;margin-top:4px}
    .fpt-health-card--red .fpt-health-value{color:#c0392b}
    .fpt-health-card--orange .fpt-health-value{color:#e67e22}
    .fpt-health-card--yellow .fpt-health-value{color:#d4a017}
    .fpt-health-card--purple .fpt-health-value{color:#8e44ad}
    .fpt-health-enfants{display:flex!important;align-items:center;gap:16px;padding:16px 24px;background:#fff8e1;border-bottom:1px solid #fde68a}
    .fpt-health-enfants-icon{font-size:32px;flex-shrink:0}
    .fpt-health-enfants strong{display:block;font-size:18px;font-weight:700;color:#92400e}
    .fpt-health-enfants span{font-size:12px;color:#b45309}
    .fpt-health-disclaimer{font-size:11px;color:#6b8070;padding:12px 24px;margin:0;font-style:italic}
    @media(max-width:640px){.fpt-health-grid{grid-template-columns:repeat(2,1fr)!important}}
    </style>
    <div class="fpt-dashboard">
        <div class="fpt-dashboard-header">
            <h2>🌍 <?php echo fpt_t('Impact Environnemental','Environmental Impact'); ?> <?php echo esc_html( $site_name ); ?></h2>
            <p><?php echo esc_html( $subtitle ); ?></p>
        </div>

        <div class="fpt-impact-grid">
            <div class="fpt-impact-card fpt-impact-card--primary">
                <div class="fpt-impact-icon">♻️</div>
                <div class="fpt-impact-value"><?php echo esc_html( number_format( $total_lots ) ); ?></div>
                <div class="fpt-impact-label"><?php echo fpt_t('Lots tracés','Batches traced'); ?></div>
            </div>
            <div class="fpt-impact-card">
                <div class="fpt-impact-icon">⚖️</div>
                <div class="fpt-impact-value"><?php echo esc_html( fpt_display_weight( $total_poids ) ); ?></div>
                <div class="fpt-impact-label"><?php echo fpt_t('Déchets collectés','Waste collected'); ?></div>
            </div>
            <div class="fpt-impact-card fpt-impact-card--green">
                <div class="fpt-impact-icon">🌱</div>
                <div class="fpt-impact-value"><?php echo esc_html( number_format( $total_co2, 2 ) ); ?> t</div>
                <div class="fpt-impact-label"><?php echo fpt_t('CO₂ évité','CO₂ avoided'); ?></div>
            </div>
            <div class="fpt-impact-card">
                <div class="fpt-impact-icon">🌳</div>
                <div class="fpt-impact-value"><?php echo esc_html( number_format( $arbres ) ); ?></div>
                <div class="fpt-impact-label"><?php echo fpt_t('Équivalent arbres/an','Equivalent trees/year'); ?></div>
            </div>
        </div>

        <!-- ── Section Santé Enfants / Child Health ──────────────────── -->
        <div class="fpt-health-section">
            <div class="fpt-health-header">
                <h3>🔬 <?php echo fpt_t('Indicateurs de Réduction d\'Exposition aux Polluants','Pollutant Exposure Risk Reduction Indicators'); ?></h3>
                <p><?php echo fpt_t(
                    'Estimations de polluants détournés du recyclage informel — Sources : OMS · Pure Earth · EPA · UNEP',
                    'Estimated pollutant diversion from informal recycling — Sources: WHO · Pure Earth · EPA · UNEP'
                ); ?></p>
            </div>
            <div class="fpt-health-grid">
                <div class="fpt-health-card fpt-health-card--red">
                    <div class="fpt-health-icon">🔴</div>
                    <div class="fpt-health-value"><?php echo esc_html( number_format( $health['plomb_kg'], 2 ) ); ?> kg</div>
                    <div class="fpt-health-label"><?php echo fpt_t('Plomb détourné (estimé)','Lead diverted (est.)'); ?></div>
                    <div class="fpt-health-info"><?php echo fpt_t('Réduction estimée du risque d\'exposition (OMS — aucun seuil sans effet)','Estimated exposure risk reduction (WHO — no safe threshold)'); ?></div>
                </div>
                <div class="fpt-health-card fpt-health-card--orange">
                    <div class="fpt-health-icon">☁️</div>
                    <div class="fpt-health-value"><?php echo esc_html( number_format( $health['pm25_kg'], 1 ) ); ?> kg</div>
                    <div class="fpt-health-label"><?php echo fpt_t('PM2.5 détournées (estimé)','PM2.5 diverted (est.)'); ?></div>
                    <div class="fpt-health-info"><?php echo fpt_t('Proxy de réduction d\'exposition respiratoire (brûlage câbles évité)','Respiratory exposure risk proxy (avoided cable burning)'); ?></div>
                </div>
                <div class="fpt-health-card fpt-health-card--yellow">
                    <div class="fpt-health-icon">⚠️</div>
                    <div class="fpt-health-value"><?php echo esc_html( number_format( $health['cadmium_g'], 0 ) ); ?> g</div>
                    <div class="fpt-health-label"><?php echo fpt_t('Cadmium détourné (estimé)','Cadmium diverted (est.)'); ?></div>
                    <div class="fpt-health-info"><?php echo fpt_t('Proxy de réduction du risque rénal (e-waste, piles)','Renal risk reduction proxy (e-waste, batteries)'); ?></div>
                </div>
                <div class="fpt-health-card fpt-health-card--purple">
                    <div class="fpt-health-icon">🧠</div>
                    <div class="fpt-health-value"><?php echo esc_html( number_format( $health['mercure_g'], 0 ) ); ?> g</div>
                    <div class="fpt-health-label"><?php echo fpt_t('Mercure détourné (estimé)','Mercury diverted (est.)'); ?></div>
                    <div class="fpt-health-info"><?php echo fpt_t('Proxy de réduction du risque neurologique (écrans, lampes)','Neurological risk reduction proxy (screens, lamps)'); ?></div>
                </div>
            </div>
            <?php if ( $health['enfants'] > 0 ): ?>
            <div class="fpt-health-enfants">
                <span class="fpt-health-enfants-icon">📊</span>
                <div>
                    <strong><?php echo fpt_t('Indice de réduction du risque d\'exposition','Exposure Risk Reduction Index'); ?> : <?php echo esc_html( number_format( $health['enfants'] ) ); ?></strong>
                    <span><?php echo fpt_t(
                        'Proxy estimatif basé sur les seuils d\'exposition OMS et HEI — non validé cliniquement, validation terrain prévue Phase 2',
                        'Estimative proxy based on WHO and HEI exposure thresholds — not clinically validated, field validation planned Phase 2'
                    ); ?></span>
                </div>
            </div>
            <?php endif; ?>
            <p class="fpt-health-disclaimer">* <?php echo fpt_t(
                'Estimations conservatrices basées sur des facteurs d\'émission globaux (OMS, Pure Earth, EPA). Ces indicateurs représentent une réduction estimée du risque d\'exposition aux polluants — ils ne constituent pas une attribution causale d\'impact clinique. Validation terrain et affinage ML prévu en Phase 2.',
                'Conservative estimates based on global emission factors (WHO, Pure Earth, EPA). These indicators represent an estimated pollutant exposure risk reduction — they do not constitute causal clinical impact attribution. Field validation and ML refinement planned for Phase 2.'
            ); ?></p>
        </div>

        <?php if ( ! empty( $recent ) ): ?>
        <div class="fpt-recent-lots">
            <h3><?php echo fpt_t('Derniers lots tracés','Recently traced batches'); ?></h3>
            <div class="fpt-recent-grid">
            <?php foreach ( $recent as $lot ):
                $co2_lot  = (float) get_post_meta( $lot->ID, '_fpt_co2_avoided', true );
                $poids    = (float) fpt_get_poids_kg( $lot->ID );
                $ville    = get_post_meta( $lot->ID, fpt_key_ville(), true );
                $lot_id   = get_post_meta( $lot->ID, '_fpt_lot_id', true );
                $thumb    = get_the_post_thumbnail_url( $lot->ID, 'thumbnail' );
            ?>
            <a class="fpt-recent-card" href="<?php echo esc_url( get_permalink( $lot->ID ) ); ?>">
                <?php if ( $thumb ): ?>
                <img src="<?php echo esc_url( $thumb ); ?>" alt="">
                <?php endif; ?>
                <div class="fpt-recent-info">
                    <strong><?php echo esc_html( get_the_title( $lot->ID ) ); ?></strong>
                    <span><?php echo esc_html( $ville ); ?> · <?php echo esc_html( fpt_display_weight( $poids ) ); ?></span>
                    <span class="fpt-recent-co2">🌱 <?php echo esc_html( number_format($co2_lot,3) ); ?> t CO₂ <?php echo fpt_t('évité','avoided'); ?></span>
                </div>
                <span class="fpt-recent-id"><?php echo esc_html( $lot_id ); ?></span>
            </a>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <p class="fpt-source"><?php echo fpt_t('Facteurs d\'émission','Emission factors'); ?> : ADEME Base Carbone · Open Source MIT · <a href="<?php echo esc_url( home_url('/impact') ); ?>"><?php echo esc_html( parse_url( home_url(), PHP_URL_HOST ) ); ?>/impact</a></p>
    </div>
    <?php
    return ob_get_clean();
}

// ─── CSS frontend ──────────────────────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', 'fpt_enqueue_styles' );
function fpt_enqueue_styles() {
    wp_enqueue_style( 'feraypro-tracer', FPT_PLUGIN_URL . 'assets/tracer.css', [], FPT_VERSION );
}

// ─── Shortcode : Page Méthodologie ────────────────────────────────────────────
add_shortcode( 'fpt_methodologie', 'fpt_shortcode_methodologie' );
function fpt_shortcode_methodologie( $atts ) {
    $site_name = get_option( 'fpt_site_name', 'FerayPro' );
    $unit      = get_option( 'fpt_weight_unit', 'kg' );
    $unit_lbl  = $unit === 'lb' ? 'lb' : 'kg';
    $unit_long = $unit === 'lb' ? 'lb (pounds)' : 'kg (kilogrammes)';
    $poids_ex  = $unit === 'lb' ? '24 lb' : '11 kg';
    $poids_t_ex = $unit === 'lb' ? '24 lb → 0.011 t' : '11 kg → 0.011 t';
    $conv_note = $unit === 'lb' ? fpt_t('Note : les livres (lb) sont automatiquement converties en kg (× 0,453592) avant le calcul.','Note: pounds (lb) are automatically converted to kg (× 0.453592) before calculation.') : '';

    ob_start(); ?>
    <style>
    .fpt-meth{font-family:'DM Sans',Arial,sans-serif;max-width:860px;margin:0 auto;color:#1e2d22}
    .fpt-meth h2{font-size:26px;font-weight:700;color:#1a7a4a;margin:40px 0 8px;padding-bottom:8px;border-bottom:2px solid #e6f5ee}
    .fpt-meth h3{font-size:19px;font-weight:700;color:#0f1c13;margin:28px 0 8px}
    .fpt-meth h4{font-size:16px;font-weight:700;color:#1a1a2e;margin:20px 0 6px}
    .fpt-meth p{font-size:15px;line-height:1.7;margin:0 0 14px;color:#1e2d22}
    .fpt-meth ul{padding-left:20px;margin:0 0 14px}
    .fpt-meth ul li{font-size:15px;line-height:1.7;margin-bottom:6px}
    .fpt-meth-formula{background:#e6f5ee;border-left:4px solid #1a7a4a;border-radius:0 8px 8px 0;padding:16px 20px;margin:16px 0;font-family:monospace;font-size:15px;font-weight:700;color:#1a7a4a}
    .fpt-meth-table{width:100%;border-collapse:collapse;margin:16px 0 24px;font-size:14px}
    .fpt-meth-table th{background:#1a7a4a;color:#fff;padding:10px 14px;text-align:left;font-weight:600}
    .fpt-meth-table td{padding:9px 14px;border-bottom:1px solid #e0e8e2}
    .fpt-meth-table tr:nth-child(even) td{background:#f4f6f4}
    .fpt-meth-source{background:#f4f6f4;border-radius:8px;padding:14px 18px;margin:8px 0;font-size:13px;color:#4a5e50}
    .fpt-meth-source strong{color:#1a7a4a}
    .fpt-meth-tag{display:inline-block;background:#1a7a4a;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px;margin-right:6px;vertical-align:middle}
    .fpt-meth-tag--red{background:#c0392b}
    .fpt-meth-tag--orange{background:#e67e22}
    .fpt-meth-tag--yellow{background:#d4a017}
    .fpt-meth-tag--purple{background:#8e44ad}
    .fpt-meth-intro{background:#0f1c13;color:#fff;border-radius:12px;padding:28px 32px;margin-bottom:36px}
    .fpt-meth-intro h1{font-size:28px;font-weight:700;color:#5dde8a;margin:0 0 8px}
    .fpt-meth-intro p{color:rgba(255,255,255,0.75);margin:0;font-size:15px}
    .fpt-meth-children{background:#fff8e1;border:1.5px solid #fde68a;border-radius:8px;padding:16px 20px;margin:16px 0}
    .fpt-meth-children strong{color:#92400e;display:block;font-size:16px;margin-bottom:6px}
    .fpt-meth-disclaimer{font-size:13px;color:#6b8070;font-style:italic;margin-top:32px;padding-top:16px;border-top:1px solid #d0ddd4}
    </style>

    <div class="fpt-meth">

        <!-- INTRO -->
        <div class="fpt-meth-intro">
            <h1>📊 <?php echo fpt_t('Méthodologie de Calcul','Calculation Methodology'); ?></h1>
            <p><?php echo fpt_t(
                'Transparence totale sur la façon dont FerayPro calcule l\'impact environnemental et sanitaire de chaque lot recyclé.',
                'Full transparency on how FerayPro calculates the environmental and health impact of each recycled batch.'
            ); ?> — <?php echo esc_html($site_name); ?> · Open Source MIT</p>
        </div>

        <!-- DONNÉES D'ENTRÉE -->
        <h2><?php echo fpt_t('📥 Données d\'entrée','📥 Input Data'); ?></h2>
        <p><?php echo fpt_t(
            'Chaque calcul repose uniquement sur les informations saisies par le vendeur au moment de la publication d\'une annonce :',
            'Each calculation relies solely on the information entered by the seller when publishing a listing:'
        ); ?></p>
        <table class="fpt-meth-table">
            <tr><th><?php echo fpt_t('Champ','Field'); ?></th><th><?php echo fpt_t('Rôle','Role'); ?></th></tr>
            <tr><td><strong><?php echo fpt_t('Titre de l\'annonce','Listing title'); ?></strong></td><td><?php echo fpt_t('Identifie le type de déchet → sélectionne le facteur CO₂ et santé','Identifies waste type → selects CO₂ and health factor'); ?></td></tr>
            <tr><td><strong><?php echo fpt_t('Poids','Weight'); ?> (<?php echo esc_html($unit_lbl); ?>)</strong></td><td><?php echo fpt_t('Variable principale de tous les calculs','Primary variable for all calculations'); ?></td></tr>
            <tr><td><strong><?php echo fpt_t('Ville','City'); ?></strong></td><td><?php echo fpt_t('Géolocalisation de l\'impact','Geolocation of impact'); ?></td></tr>
        </table>

        <!-- SECTION 1 CO2 -->
        <h2>🌱 <?php echo fpt_t('Section 1 — Impact CO₂','Section 1 — CO₂ Impact'); ?></h2>

        <h3><?php echo fpt_t('Principe','Principle'); ?></h3>
        <p><?php echo fpt_t(
            'Recycler une tonne de métal, de papier ou de plastique évite les émissions de CO₂ qui auraient été produites par l\'extraction de matière première vierge ou par l\'enfouissement et le brûlage informel du déchet.',
            'Recycling one tonne of metal, paper or plastic avoids the CO₂ emissions that would have been produced by virgin raw material extraction or by informal landfilling and burning of the waste.'
        ); ?></p>

        <h3><?php echo fpt_t('Formule','Formula'); ?></h3>
        <div class="fpt-meth-formula">
            CO₂ <?php echo fpt_t('évité','avoided'); ?> (t) = <?php echo fpt_t('Poids','Weight'); ?> (<?php echo esc_html($unit_lbl); ?>) ÷ 1000 × <?php echo fpt_t('Facteur CO₂ (t CO₂ / t recyclée)','CO₂ Factor (t CO₂ / t recycled)'); ?>
            <?php if ( $unit === 'lb' ) : ?><br><em style="font-size:12px;font-weight:400"><?php echo fpt_t('Note : lb convertis automatiquement en kg (× 0,453592)','Note: lb automatically converted to kg (× 0.453592)'); ?></em><?php endif; ?>
        </div>
        <p><strong><?php echo fpt_t('Exemple','Example'); ?> :</strong> <?php
            if ( $unit === 'lb' ) {
                echo fpt_t(
                    '24 lb de cuivre → 0,011 t × 3,5 = 0,0385 t de CO₂ évité (38,5 kg)',
                    '24 lb of copper → 0.011 t × 3.5 = 0.0385 t CO₂ avoided (38.5 kg)'
                );
            } else {
                echo fpt_t(
                    '11 kg de cuivre → 0,011 t × 3,5 = 0,0385 t de CO₂ évité (38,5 kg)',
                    '11 kg of copper → 0.011 t × 3.5 = 0.0385 t CO₂ avoided (38.5 kg)'
                );
            }
        ?></p>

        <h3><?php echo fpt_t('Détection du type de déchet','Waste type detection'); ?></h3>
        <p><?php echo fpt_t(
            'Le plugin analyse le titre de l\'annonce et le compare à une liste de mots-clés (français + anglais). Le premier mot-clé reconnu détermine le facteur appliqué. Si aucun mot-clé ne correspond, un facteur conservatif de 1,0 t CO₂/t est utilisé.',
            'The plugin analyzes the listing title and compares it against a keyword list (French + English). The first recognized keyword determines the factor applied. If no keyword matches, a conservative factor of 1.0 t CO₂/t is used.'
        ); ?></p>

        <h3><?php echo fpt_t('Principaux facteurs CO₂ (source : ADEME Base Carbone)','Main CO₂ factors (source: ADEME Base Carbone)'); ?></h3>
        <table class="fpt-meth-table">
            <tr><th><?php echo fpt_t('Matière','Material'); ?></th><th><?php echo fpt_t('t CO₂ évité / tonne recyclée','t CO₂ avoided / tonne recycled'); ?></th><th><?php echo fpt_t('Justification','Justification'); ?></th></tr>
            <tr><td><?php echo fpt_t('Aluminium','Aluminum'); ?></td><td><strong>9,5</strong></td><td><?php echo fpt_t('Évite l\'électrolyse Hall-Héroult','Avoids Hall-Héroult electrolysis'); ?></td></tr>
            <tr><td>Cuivre / Copper</td><td><strong>3,5</strong></td><td><?php echo fpt_t('Évite la pyrométallurgie','Avoids pyrometallurgy'); ?></td></tr>
            <tr><td>Bronze</td><td><strong>3,2</strong></td><td><?php echo fpt_t('Alliage cuivre/étain','Copper/tin alloy'); ?></td></tr>
            <tr><td>Laiton / Brass</td><td><strong>3,0</strong></td><td><?php echo fpt_t('Alliage cuivre/zinc','Copper/zinc alloy'); ?></td></tr>
            <tr><td>Inox / Stainless steel</td><td><strong>2,5</strong></td><td><?php echo fpt_t('Évite l\'ajout de nickel vierge','Avoids virgin nickel addition'); ?></td></tr>
            <tr><td>Fer / Acier / Steel</td><td><strong>1,8</strong></td><td><?php echo fpt_t('Évite le haut-fourneau','Avoids blast furnace'); ?></td></tr>
            <tr><td>E-waste / Electronics</td><td><strong>4,0</strong></td><td><?php echo fpt_t('Densité de métaux précieux','High precious metal density'); ?></td></tr>
            <tr><td><?php echo fpt_t('Batterie lithium','Lithium battery'); ?></td><td><strong>5,0</strong></td><td><?php echo fpt_t('Extraction lithium, cobalt, nickel','Lithium, cobalt, nickel extraction'); ?></td></tr>
            <tr><td>Papier / Paper</td><td><strong>0,9</strong></td><td><?php echo fpt_t('Évite déforestation + méthane','Avoids deforestation + methane'); ?></td></tr>
            <tr><td>Plastique PET / PET Plastic</td><td><strong>1,5</strong></td><td><?php echo fpt_t('Évite craquage du naphta','Avoids naphtha cracking'); ?></td></tr>
            <tr><td><?php echo fpt_t('Défaut (non reconnu)','Default (unrecognized)'); ?></td><td><strong>1,0</strong></td><td><?php echo fpt_t('Valeur conservative','Conservative value'); ?></td></tr>
        </table>

        <h3><?php echo fpt_t('Équivalents affichés','Displayed equivalents'); ?></h3>
        <ul>
            <li><strong><?php echo fpt_t('Arbres/an','Trees/year'); ?> :</strong> CO₂ (t) × 45 — <?php echo fpt_t('1 arbre adulte absorbe ~22 kg CO₂/an (FAO)','1 mature tree absorbs ~22 kg CO₂/year (FAO)'); ?></li>
            <li><strong><?php echo fpt_t('km voiture évités','Car km avoided'); ?> :</strong> CO₂ (t) × 6 000 — <?php echo fpt_t('voiture moyenne ~120g CO₂/km (ADEME)','average car ~120g CO₂/km (ADEME)'); ?></li>
        </ul>

        <!-- SECTION 2 SANTÉ -->
        <h2>🔬 <?php echo fpt_t('Section 2 — Indicateurs de Réduction du Risque d\'Exposition','Section 2 — Pollutant Exposure Risk Reduction Indicators'); ?></h2>

        <h3><?php echo fpt_t('Contexte scientifique','Scientific context'); ?></h3>
        <p><?php echo fpt_t(
            'Le recyclage informel est l\'une des principales sources d\'exposition aux métaux lourds dans les zones de collecte. Ces indicateurs estiment la quantité de polluants détournés du recyclage informel (brûlage de câbles, démantèlement de batteries, traitement d\'e-waste) vers des filières formelles contrôlées. Ils représentent une réduction estimée du risque d\'exposition — pas une attribution causale d\'impact clinique.',
            'Informal recycling is one of the main sources of heavy metal exposure in collection areas. These indicators estimate the quantity of pollutants diverted from informal recycling (cable burning, battery dismantling, e-waste processing) toward controlled formal channels. They represent an estimated exposure risk reduction — not causal clinical impact attribution.'
        ); ?></p>

        <h3><?php echo fpt_t('Les 4 indicateurs de diversion estimée','The 4 estimated diversion indicators'); ?></h3>

        <h4><span class="fpt-meth-tag fpt-meth-tag--red">🔴</span> <?php echo fpt_t('Plomb détourné — estimation (kg)','Lead diverted — estimate (kg)'); ?></h4>
        <div class="fpt-meth-formula"><?php echo fpt_t('Plomb détourné (kg)','Lead diverted (kg)'); ?> = Σ [ <?php echo fpt_t('Poids','Weight'); ?> (t) × 0,5 ] — <?php echo fpt_t('lots contenant : plomb, batterie, radiateur, soudure','batches containing: lead, battery, radiator, solder'); ?></div>
        <p><?php echo fpt_t('Source : Pure Earth (2016), WHO Lead Exposure Report (2021) — facteur 0,5 kg/t (conservateur).','Source: Pure Earth (2016), WHO Lead Exposure Report (2021) — factor 0.5 kg/t (conservative).'); ?><br>
        <?php echo fpt_t('Contexte de risque : le plomb est associé à des effets neurodéveloppementaux chez l\'enfant — l\'OMS indique qu\'il n\'existe pas de seuil d\'exposition sans effet.','Risk context: lead is associated with neurodevelopmental effects in children — WHO states there is no safe exposure threshold.'); ?></p>

        <h4><span class="fpt-meth-tag fpt-meth-tag--orange">☁️</span> PM2.5 <?php echo fpt_t('détournées — estimation (kg)','diverted — estimate (kg)'); ?></h4>
        <div class="fpt-meth-formula">PM2.5 <?php echo fpt_t('détournées (kg)','diverted (kg)'); ?> = Σ [ <?php echo fpt_t('Poids','Weight'); ?> (t) × 15 ] — <?php echo fpt_t('lots contenant : câble, cuivre, plastique, pneu','batches containing: cable, copper, plastic, tire'); ?></div>
        <p><?php echo fpt_t('Source : EPA AP-42 (2022) — brûlage d\'une tonne de câbles génère ~15 kg de PM2.5 en conditions terrain.','Source: EPA AP-42 (2022) — burning one tonne of cables generates ~15 kg of PM2.5 under field conditions.'); ?><br>
        <?php echo fpt_t('Contexte de risque : les PM2.5 sont associées aux maladies respiratoires — proxy de réduction du risque d\'exposition.','Risk context: PM2.5 is associated with respiratory disease — proxy for exposure risk reduction.'); ?></p>

        <h4><span class="fpt-meth-tag fpt-meth-tag--yellow">⚠️</span> <?php echo fpt_t('Cadmium détourné — estimation (g)','Cadmium diverted — estimate (g)'); ?></h4>
        <div class="fpt-meth-formula"><?php echo fpt_t('Cadmium détourné (g)','Cadmium diverted (g)'); ?> = Σ [ <?php echo fpt_t('Poids','Weight'); ?> (t) × 200 ] — <?php echo fpt_t('lots contenant : pile, e-waste, smartphone','batches containing: battery, e-waste, smartphone'); ?></div>
        <p><?php echo fpt_t('Source : Pure Earth Toxic Sites Database (2020), UNEP (2018) — 200 g cadmium/tonne d\'e-waste.','Source: Pure Earth Toxic Sites Database (2020), UNEP (2018) — 200g cadmium/tonne of e-waste.'); ?><br>
        <?php echo fpt_t('Contexte de risque : le cadmium est associé aux atteintes rénales — proxy de réduction du risque d\'exposition.','Risk context: cadmium is associated with kidney damage — proxy for exposure risk reduction.'); ?></p>

        <h4><span class="fpt-meth-tag fpt-meth-tag--purple">🧠</span> <?php echo fpt_t('Mercure détourné — estimation (g)','Mercury diverted — estimate (g)'); ?></h4>
        <div class="fpt-meth-formula"><?php echo fpt_t('Mercure détourné (g)','Mercury diverted (g)'); ?> = Σ [ <?php echo fpt_t('Poids','Weight'); ?> (t) × 50 ] — <?php echo fpt_t('lots contenant : écran, TV, lampe, néon','batches containing: screen, TV, lamp, neon'); ?></div>
        <p><?php echo fpt_t('Source : UNEP Minamata Convention (2018) — 50 g mercure/tonne d\'équipements électroniques.','Source: UNEP Minamata Convention (2018) — 50g mercury/tonne of electronic equipment.'); ?><br>
        <?php echo fpt_t('Contexte de risque : le mercure est un neurotoxique — proxy de réduction du risque d\'exposition même à faible dose.','Risk context: mercury is a neurotoxin — proxy for exposure risk reduction even at low doses.'); ?></p>

        <h3><?php echo fpt_t('Indice de Réduction du Risque d\'Exposition (IRRE)','Exposure Risk Reduction Index (ERRI)'); ?></h3>
        <div class="fpt-meth-children">
            <strong>📊 IRRE / ERRI = (<?php echo fpt_t('Plomb détourné kg','Lead diverted kg'); ?> × 50) + (PM2.5 <?php echo fpt_t('détournées kg','diverted kg'); ?> × 10)</strong>
            <p style="margin:0;font-size:14px;color:#92400e"><?php echo fpt_t(
                'Proxy estimatif basé sur les seuils d\'exposition OMS (2021) et HEI (2020). Cet indice n\'est pas peer-reviewed ni validé cliniquement. Il constitue un outil de mesure transitoire basé sur des coefficients globaux conservateurs — une validation terrain et un affinage ML sont prévus en Phase 2.',
                'Estimative proxy based on WHO (2021) and HEI (2020) exposure thresholds. This index is not peer-reviewed or clinically validated. It is a transitional measurement tool based on conservative global coefficients — field validation and ML refinement are planned for Phase 2.'
            ); ?></p>
        </div>

        <!-- SOURCES -->
        <h2>📚 <?php echo fpt_t('Sources officielles','Official Sources'); ?></h2>
        <div class="fpt-meth-source"><strong>ADEME Base Carbone</strong> — <?php echo fpt_t('Facteurs d\'émission CO₂ officiels (France/International)','Official CO₂ emission factors (France/International)'); ?> — basecarbone.ademe.fr</div>
        <div class="fpt-meth-source"><strong>WHO / OMS (2021)</strong> — Global Health Observatory — Lead Exposure in Children</div>
        <div class="fpt-meth-source"><strong>Pure Earth (2020)</strong> — Toxic Sites Database — Africa — Cadmium & Lead</div>
        <div class="fpt-meth-source"><strong>EPA AP-42 (2022)</strong> — Compilation of Air Pollutant Emission Factors — Open Burning</div>
        <div class="fpt-meth-source"><strong>UNEP (2018)</strong> — Minamata Convention — Global Mercury Assessment</div>
        <div class="fpt-meth-source"><strong>HEI (2020)</strong> — Health Effects Institute — Air Pollution in Sub-Saharan Africa</div>
        <div class="fpt-meth-source"><strong>UNICEF (2020)</strong> — The Toxic Truth — Children's Exposure to Lead Pollution</div>
        <div class="fpt-meth-source"><strong>FAO (2021)</strong> — Carbon sequestration in forests</div>
        <div class="fpt-meth-source"><strong>IPCC AR6 (2022)</strong> — <?php echo fpt_t('Facteurs d\'émission industrie lourde','Heavy industry emission factors'); ?></div>

        <!-- LIMITES -->
        <h2>⚠️ <?php echo fpt_t('Limites & Statut de Validation','Limitations & Validation Status'); ?></h2>
        <p><?php echo fpt_t(
            'Ces indicateurs sont des estimations conservatrices basées sur des facteurs d\'émission globaux (OMS, Pure Earth, EPA, UNEP). Ils représentent une réduction estimée du risque d\'exposition aux polluants — ils ne constituent pas une attribution causale d\'impact clinique et n\'ont pas été validés par peer-review.',
            'These indicators are conservative estimates based on global emission factors (WHO, Pure Earth, EPA, UNEP). They represent an estimated pollutant exposure risk reduction — they do not constitute causal clinical impact attribution and have not been peer-reviewed.'
        ); ?></p>
        <p><?php echo fpt_t(
            'Système de mesure transitoire : FerayPro Tracer démarre avec des coefficients globaux conservateurs comme référence de base, et évolue vers des modèles validés localement grâce aux données terrain collectées au Maroc et en RDC (Phase 2). Les résultats de Phase 2 seront accompagnés d\'intervalles de confiance statistiques.',
            'Transitional measurement system: FerayPro Tracer starts with conservative global coefficients as a baseline, and evolves toward locally validated models through field data collected in Morocco and the DRC (Phase 2). Phase 2 results will include statistical confidence intervals.'
        ); ?></p>

        <p class="fpt-meth-disclaimer">
            <?php echo esc_html($site_name); ?> Tracer — Open Source MIT — 
            <a href="<?php echo esc_url( home_url('/impact') ); ?>"><?php echo fpt_t('Voir le dashboard live','View live dashboard'); ?> →</a>
        </p>
    </div>
    <?php
    return ob_get_clean();
}


// ─── Page admin : recalcul + stats ────────────────────────────────────────────
add_action( 'admin_menu', 'fpt_admin_menu' );
function fpt_admin_menu() {
    add_menu_page(
        'FerayPro Tracer',
        'FP Tracer',
        'manage_options',
        'feraypro-tracer',
        'fpt_admin_page',
        'dashicons-chart-area',
        30
    );
}

function fpt_admin_page() {
    if ( isset( $_POST['fpt_recalculate'] ) && check_admin_referer('fpt_recalc') ) {
        fpt_recalculate_global_stats();
        echo '<div class="notice notice-success"><p>Stats recalculées.</p></div>';
    }
    if ( isset( $_POST['fpt_save_settings'] ) && check_admin_referer('fpt_settings') ) {
        update_option( 'fpt_country_name',  sanitize_text_field( $_POST['fpt_country_name'] ) );
        update_option( 'fpt_site_name',     sanitize_text_field( $_POST['fpt_site_name'] ) );
        update_option( 'fpt_language',      in_array( $_POST['fpt_language'], ['fr','en'] ) ? $_POST['fpt_language'] : 'fr' );
        update_option( 'fpt_key_poids',     sanitize_key( $_POST['fpt_key_poids'] ) );
        update_option( 'fpt_key_ville',     sanitize_key( $_POST['fpt_key_ville'] ) );
        update_option( 'fpt_key_whatsapp',  sanitize_key( $_POST['fpt_key_whatsapp'] ) );
        update_option( 'fpt_key_prix',      sanitize_key( $_POST['fpt_key_prix'] ) );
        update_option( 'fpt_weight_unit',   in_array( $_POST['fpt_weight_unit'], ['kg','lb'] ) ? $_POST['fpt_weight_unit'] : 'kg' );
        update_option( 'fpt_prix_cat_slug', sanitize_key( $_POST['fpt_prix_cat_slug'] ) );
        update_option( 'fpt_key_prix_jour',    sanitize_text_field( $_POST['fpt_key_prix_jour'] ) );
        update_option( 'fpt_key_buyersprice',  sanitize_text_field( $_POST['fpt_key_buyersprice'] ) );
        update_option( 'fpt_prix_category_slug', sanitize_key( $_POST['fpt_prix_category_slug'] ) );
        echo '<div class="notice notice-success"><p>Paramètres sauvegardés / Settings saved.</p></div>';
    }
    $total_co2    = (float) get_option( 'fpt_total_co2', 0 );
    $total_poids  = (float) get_option( 'fpt_total_poids', 0 );
    $total_lots   = (int)   get_option( 'fpt_total_lots', 0 );
    $country_name = get_option( 'fpt_country_name', 'votre pays' );
    $site_name    = get_option( 'fpt_site_name', 'FerayPro' );
    ?>
    <div class="wrap">
        <h1>FerayPro Tracer — Dashboard</h1>

        <h2>⚙️ Paramètres</h2>
        <form method="post" style="max-width:500px;background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;margin-bottom:30px;">
            <?php wp_nonce_field('fpt_settings'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="fpt_site_name">Nom de la plateforme / Platform name</label></th>
                    <td><input type="text" id="fpt_site_name" name="fpt_site_name" value="<?php echo esc_attr($site_name); ?>" class="regular-text" placeholder="FerayPro"></td>
                </tr>
                <tr>
                    <th><label for="fpt_country_name">Pays / Région · Country / Region</label></th>
                    <td><input type="text" id="fpt_country_name" name="fpt_country_name" value="<?php echo esc_attr($country_name); ?>" class="regular-text" placeholder="ex: Maroc, Congo, France, USA..."></td>
                </tr>
                <tr>
                    <th><label for="fpt_language">Langue · Language</label></th>
                    <td>
                        <select id="fpt_language" name="fpt_language">
                            <option value="fr" <?php selected( get_option('fpt_language','fr'), 'fr' ); ?>>🇫🇷 Français</option>
                            <option value="en" <?php selected( get_option('fpt_language','fr'), 'en' ); ?>>🇬🇧 English</option>
                        </select>
                    </td>
                </tr>
                <tr><td colspan="2"><hr><strong>🔧 Meta keys HivePress (Field Name dans l'attribut)</strong></td></tr>
                <tr>
                    <th><label for="fpt_key_poids">Poids / Weight field name</label></th>
                    <td>
                        <input type="text" id="fpt_key_poids" name="fpt_key_poids" value="<?php echo esc_attr( get_option('fpt_key_poids','poids') ); ?>" class="regular-text" placeholder="poids">
                        <p class="description">🇫🇷 poids &nbsp;|&nbsp; 🇬🇧 weight</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="fpt_weight_unit">Unité de poids / Weight unit</label></th>
                    <td>
                        <select id="fpt_weight_unit" name="fpt_weight_unit">
                            <option value="kg" <?php selected( get_option('fpt_weight_unit','kg'), 'kg' ); ?>>kg (kilogrammes)</option>
                            <option value="lb" <?php selected( get_option('fpt_weight_unit','kg'), 'lb' ); ?>>lb (livres / pounds)</option>
                        </select>
                        <p class="description">Si lb, le plugin convertit automatiquement en kg pour le calcul CO₂</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="fpt_key_ville">Ville / City field name</label></th>
                    <td>
                        <input type="text" id="fpt_key_ville" name="fpt_key_ville" value="<?php echo esc_attr( get_option('fpt_key_ville','ville') ); ?>" class="regular-text" placeholder="ville">
                        <p class="description">🇫🇷 ville &nbsp;|&nbsp; 🇬🇧 city</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="fpt_key_whatsapp">Téléphone / Phone field name</label></th>
                    <td>
                        <input type="text" id="fpt_key_whatsapp" name="fpt_key_whatsapp" value="<?php echo esc_attr( get_option('fpt_key_whatsapp','whatsapp') ); ?>" class="regular-text" placeholder="whatsapp">
                        <p class="description">🇫🇷 whatsapp &nbsp;|&nbsp; 🇬🇧 telephone</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="fpt_prix_cat_slug"><?php _e('Prix du jour — Category slug'); ?></label></th>
                    <td>
                        <input type="text" id="fpt_prix_cat_slug" name="fpt_prix_cat_slug" value="<?php echo esc_attr( get_option('fpt_prix_cat_slug','prix') ); ?>" class="regular-text" placeholder="prix">
                        <p class="description">🇫🇷 prix &nbsp;|&nbsp; 🇬🇧 price — <?php _e('HivePress → Listings → Categories → slug'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="fpt_key_prix_jour">Prix/kg field name (Prix du jour)</label></th>
                    <td>
                        <input type="text" id="fpt_key_prix_jour" name="fpt_key_prix_jour" value="<?php echo esc_attr( get_option('fpt_key_prix_jour','prix') ); ?>" class="regular-text" placeholder="prix">
                        <p class="description">🇫🇷 prix &nbsp;|&nbsp; 🇬🇧 kg: <strong>price</strong> · 🇺🇸 lb: <strong>price_2</strong></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="fpt_key_buyersprice">Buyers list field name (Prix du jour)</label></th>
                    <td>
                        <input type="text" id="fpt_key_buyersprice" name="fpt_key_buyersprice" value="<?php echo esc_attr( get_option('fpt_key_buyersprice','buyersprice') ); ?>" class="regular-text" placeholder="buyersprice">
                        <p class="description">🇫🇷 laisser vide (description utilisée) &nbsp;|&nbsp; 🇬🇧 <strong>buyersprice</strong></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="fpt_key_prix"><?php _e('Prix / Price field name'); ?></label></th>
                    <td>
                        <input type="text" id="fpt_key_prix" name="fpt_key_prix" value="<?php echo esc_attr( get_option('fpt_key_prix','prixvendeur') ); ?>" class="regular-text" placeholder="prixvendeur">
                        <p class="description">🇫🇷 prixvendeur &nbsp;|&nbsp; 🇬🇧 pricebuyer</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="fpt_prix_category_slug">Slug catégorie Prix du jour · Prices category slug</label></th>
                    <td>
                        <input type="text" id="fpt_prix_category_slug" name="fpt_prix_category_slug" value="<?php echo esc_attr( get_option('fpt_prix_category_slug','prix') ); ?>" class="regular-text" placeholder="prix">
                        <p class="description">🇫🇷 prix &nbsp;|&nbsp; 🇬🇧 price</p>
                    </td>
                </tr>
            </table>
            <button type="submit" name="fpt_save_settings" class="button button-primary">Sauvegarder</button>
        </form>

        <h2>📊 Stats actuelles</h2>
        <table class="widefat" style="max-width:500px;margin-bottom:30px;">
            <tr><th>Lots tracés</th><td><?php echo $total_lots; ?></td></tr>
            <tr><th>Poids total</th><td><?php echo number_format($total_poids,0,'',''); ?> kg</td></tr>
            <tr><th>CO₂ évité total</th><td><?php echo number_format($total_co2,3); ?> tonnes</td></tr>
        </table>

        <h2>📋 Shortcodes disponibles</h2>
        <ul>
            <li><code>[fpt_dashboard]</code> — Dashboard global impact sur n'importe quelle page</li>
            <li><code>[fpt_lot id="241"]</code> — Fiche publique d'un lot spécifique</li>
        </ul>

        <h2>🔄 Recalculer les stats</h2>
        <form method="post">
            <?php wp_nonce_field('fpt_recalc'); ?>
            <p>Utile si vous avez importé des annonces existantes.</p>
            <button type="submit" name="fpt_recalculate" class="button button-primary">Recalculer depuis zéro</button>
        </form>

        <h2 style="margin-top:30px">🌱 Facteurs CO₂ utilisés (ADEME)</h2>
        <table class="widefat" style="max-width:500px">
            <thead><tr><th>Matière</th><th>t CO₂ évité / tonne recyclée</th></tr></thead>
            <tbody>
            <?php foreach ( fpt_co2_factors() as $mat => $val ): ?>
                <?php if ($mat === 'default') continue; ?>
                <tr><td><?php echo ucfirst($mat); ?></td><td><?php echo $val; ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
