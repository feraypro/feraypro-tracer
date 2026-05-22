<?php
/**
 * Plugin Name: FerayPro Tracer
 * Plugin URI: https://ma.feraypro.com/impact
 * Description: Traçabilité des lots de déchets recyclés avec calcul CO₂ évité et génération de QR code. Module open source pour UNICEF Venture Fund.
 * Version: 1.7.3
 * Author: FerayPro
 * License: MIT
 * Text Domain: feraypro-tracer
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FPT_VERSION',    '1.7.3' );
define( 'FPT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FPT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ─── Normalisation texte multilingue ─────────────────────────────────────────
// Supporte FR, EN + translittérations Darija, Lingala, Swahili de base
function fpt_normalize_text( $text ) {
    $text = mb_strtolower( $text, 'UTF-8' );
    $replacements = [
        // FR accents
        'é'=>'e','è'=>'e','ê'=>'e','à'=>'a','â'=>'a','ç'=>'c',
        'î'=>'i','ï'=>'i','ô'=>'o','û'=>'u','ù'=>'u',
        // Darija (Maroc)
        'خردة'=>'ferraille','نحاس'=>'cuivre','حديد'=>'fer',
        'ألومنيوم'=>'aluminium','بطارية'=>'batterie',
        // Lingala (RDC)
        'singa'=>'cable','motele'=>'metal','likoxi'=>'cuivre',
        // Swahili (RDC Est / Kenya)
        'chuma'=>'fer','shaba'=>'cuivre','alumini'=>'aluminium',
        'betri'=>'batterie','taka'=>'dechet',
    ];
    $text = strtr( $text, $replacements );
    return trim( preg_replace( '/\s+/', ' ', $text ) );
}

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

// ─── Badge statut sur les cards de la liste principale — injection JS universelle
add_action( 'wp_footer', 'fpt_inject_badges_via_js' );
function fpt_inject_badges_via_js() {
    // Ne s'exécute que sur les pages avec des annonces hp_listing
    if ( ! is_post_type_archive('hp_listing') && ! is_tax() && ! is_home() && ! is_front_page() ) {
        // On injecte quand même sur toutes les pages pour couvrir les widgets
    }

    // Récupérer toutes les annonces vendeurs avec leur statut
    $listings = get_posts([
        'post_type'   => 'hp_listing',
        'post_status' => 'publish',
        'numberposts' => -1,
        'fields'      => 'ids',
        'meta_query'  => [[ 'key' => fpt_key_poids(), 'compare' => 'EXISTS' ]],
    ]);

    if ( empty($listings) ) return;

    $data = [];
    foreach ( $listings as $id ) {
        $poids = fpt_get_poids_kg($id);
        if ( $poids <= 0 ) continue;

        $collected   = get_post_meta($id, '_fpt_collected', true);
        $co2         = (float) get_post_meta($id, '_fpt_co2_avoided', true);
        $ach_id      = get_post_meta($id, '_fpt_acheteur_id', true);
        $ach_nom     = $ach_id ? get_the_title($ach_id) : '';
        $co2_display = '';
        if ($co2 > 0) {
            $co2_display = $co2 < 1
                ? round($co2 * 1000, 1) . ' kg CO₂'
                : number_format($co2, 3, '.', '') . ' t CO₂';
        }

        $data[$id] = [
            'collected'   => (bool) $collected,
            'acheteur'    => $ach_nom,
            'co2'         => $co2_display,
        ];
    }

    $label_collected  = fpt_t('Collecté','Collected');
    $label_available  = fpt_t('À collecter','Available');
    ?>
    <script>
    (function() {
        var data = <?php echo json_encode($data); ?>;
        var labelCollected = <?php echo json_encode($label_collected); ?>;
        var labelAvailable = <?php echo json_encode($label_available); ?>;

        function injectBadges() {
            Object.keys(data).forEach(function(id) {
                var d = data[id];
                // HivePress génère des liens avec /listing/slug/ — on cherche par data-id ou lien
                // Chercher tous les éléments article ou div avec data-id ou lien contenant l'ID du post
                var selectors = [
                    'article[data-id="' + id + '"]',
                    '[data-listing-id="' + id + '"]',
                    '.hp-listing[data-id="' + id + '"]',
                ];

                var el = null;
                selectors.forEach(function(sel) {
                    if (!el) el = document.querySelector(sel);
                });

                // Fallback : chercher via le lien permalink
                if (!el) {
                    var links = document.querySelectorAll('a[href*="?p=' + id + '"], a[href*="/listing/"]');
                    links.forEach(function(link) {
                        if (!el && link.href && link.closest('article')) {
                            // Vérifier si l'article contient ce post via wp classes
                            var art = link.closest('article');
                            if (art && (art.classList.contains('post-' + id) || art.id === 'post-' + id)) {
                                el = art;
                            }
                        }
                    });
                }

                // Fallback final : via classe WordPress post-{id}
                if (!el) el = document.querySelector('.post-' + id);
                if (!el) el = document.getElementById('post-' + id);

                if (!el) return;

                // Éviter le doublon
                if (el.querySelector('.fpt-card-badge')) return;

                var badge = document.createElement('div');
                badge.className = 'fpt-card-badge';
                badge.style.cssText = 'display:flex;gap:5px;align-items:center;flex-wrap:wrap;padding:6px 10px 4px;';

                if (d.collected) {
                    badge.innerHTML =
                        '<span style="background:#1a7a4a;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px;display:inline-block">✅ ' + labelCollected + (d.acheteur ? ' · ' + d.acheteur : '') + '</span>' +
                        (d.co2 ? '<span style="background:#e6f5ee;color:#1a7a4a;font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px;display:inline-block">🌱 ' + d.co2 + '</span>' : '');
                } else {
                    badge.innerHTML =
                        '<span style="background:#f59e0b;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px;display:inline-block">⏳ ' + labelAvailable + '</span>' +
                        (d.co2 ? '<span style="background:#e6f5ee;color:#1a7a4a;font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px;display:inline-block">🌱 ' + d.co2 + '</span>' : '');
                }

                // Insérer après le titre ou en début d'article
                var titre = el.querySelector('h2, h3, .hp-listing__title, .entry-title');
                if (titre && titre.parentNode) {
                    titre.parentNode.insertBefore(badge, titre.nextSibling);
                } else {
                    el.prepend(badge);
                }
            });
        }

        // Exécuter après chargement DOM + délai pour les pages dynamiques
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', injectBadges);
        } else {
            injectBadges();
        }
        // Re-exécuter après 1s pour les pages avec lazy loading / AJAX
        setTimeout(injectBadges, 1000);
        setTimeout(injectBadges, 2500);
    })();
    </script>
    <?php
}

// ─── Distance fixe par pays (Option A) ────────────────────────────────────────
function fpt_transport_distance_km() {
    $country = strtolower( get_option( 'fpt_country_name', '' ) );
    $map = [
        'maroc'   => 150, 'morocco'  => 150,
        'france'  => 200,
        'congo'   => 120, 'rdc'      => 120, 'drc'      => 120,
        'usa'     => 400, 'états-unis'=> 400, 'etats-unis'=> 400,
        'uk'      => 180, 'royaume-uni'=> 180,
        'canada'  => 350,
        'algerie' => 160, 'algérie'  => 160,
        'tunisie' => 140, 'tunisia'  => 140,
        'kenya'   => 130,
        'senegal' => 140, 'sénégal'  => 140,
    ];
    foreach ( $map as $key => $dist ) {
        if ( strpos( $country, $key ) !== false ) return $dist;
    }
    return 150; // défaut
}

// ─── Calcul CO₂ transport (ADEME fret routier : 0.062 kg CO₂/t·km) ───────────
// Formule : (poids_kg ÷ 1000) × distance_km × 0.062 = t CO₂
// Ex: 30 kg × 150 km → 0.030 t × 150 × 0.062 = 0.000279 t CO₂
// Ex: 10 000 kg × 150 km → 10 t × 150 × 0.062 = 0.093 t CO₂
function fpt_calculate_transport_co2( $poids_kg, $distance_km = null ) {
    if ( ! $distance_km ) $distance_km = fpt_transport_distance_km();
    $poids_t = (float) $poids_kg / 1000.0; // conversion kg → tonnes OBLIGATOIRE
    $co2     = $poids_t * (float) $distance_km * 0.062;
    return round( $co2, 6 ); // 6 décimales pour les petits lots
}

// ─── Facteurs CO₂ produit par le process de recyclage (t CO₂/t recyclée) ──────
// Source : FEDEREC/ADEME ACV 2017, ADEME Base Carbone
// Ce sont les émissions PRODUITES par le recycleur (énergie, process)
// ≠ CO₂ évité (gain net vs primaire)
function fpt_co2_process_factors() {
    return [
        // Métaux ferreux — four électrique (ADEME/FEDEREC 2017)
        'fer'        => 1.10,  // acier recyclé : 1,10 t CO₂/t (four arc électrique)
        'iron'       => 1.10,
        'acier'      => 1.10,
        'steel'      => 1.10,
        'ferraille'  => 1.10,
        'scrap'      => 1.10,
        'fonte'      => 0.90,
        'cast iron'  => 0.90,
        'inox'       => 0.70,  // acier inox recyclé
        'stainless'  => 0.70,

        // Aluminium — affinage/refusion (ADEME Base Carbone)
        'aluminium'  => 0.36,  // aluminium recyclé : 0,36 t CO₂/t
        'aluminum'   => 0.36,
        'alu'        => 0.36,
        'canette'    => 0.36,
        'can'        => 0.36,

        // Cuivre — fusion/affinage (FEDEREC/ADEME 2017)
        'cuivre'     => 1.304, // cuivre recyclé : 1,304 t CO₂/t
        'copper'     => 1.304,
        'bronze'     => 1.200,
        'laiton'     => 1.100,
        'brass'      => 1.100,

        // Zinc, plomb, autres non ferreux
        'zinc'       => 0.180,
        'plomb'      => 0.080,
        'lead'       => 0.080,
        'etain'      => 0.300,
        'tin'        => 0.300,
        'nickel'     => 0.500,
        'aluminium'  => 0.360,

        // E-waste / Électronique (estimation ACV)
        'electronique' => 1.500,
        'electronics'  => 1.500,
        'ewaste'       => 1.500,
        'e-waste'      => 1.500,
        'telephone'    => 2.000,
        'smartphone'   => 2.000,
        'ordinateur'   => 1.500,
        'computer'     => 1.500,

        // Batteries
        'batterie'   => 0.900,
        'battery'    => 0.900,
        'lithium'    => 2.000,
        'li-ion'     => 2.000,

        // Papier / Carton (FEDEREC/ADEME 2017)
        'papier'     => 0.870,
        'paper'      => 0.870,
        'carton'     => 0.870,
        'cardboard'  => 0.870,

        // Plastiques (ADEME 2024)
        'plastique'  => 0.650,
        'plastic'    => 0.650,
        'pet'        => 0.650,
        'hdpe'       => 0.600,

        // Verre (ADEME Base Carbone)
        'verre'      => 0.290,
        'glass'      => 0.290,

        // Pneus
        'pneu'       => 0.500,
        'tire'       => 0.500,

        // Défaut conservateur
        'default'    => 0.580,
    ];
}

// ─── Calcul CO₂ produit par le process de recyclage ──────────────────────────
function fpt_calculate_process_co2( $titre, $poids_kg ) {
    $factors     = fpt_co2_process_factors();
    $titre_lower = fpt_normalize_text( $titre );
    $factor      = $factors['default'];

    foreach ( $factors as $kw => $val ) {
        if ( $kw === 'default' ) continue;
        if ( strpos( $titre_lower, $kw ) !== false ) {
            $factor = $val;
            break;
        }
    }
    return round( ($poids_kg / 1000) * $factor, 6 );
}

function fpt_get_acheteurs() {
    $slug = get_option( 'fpt_acheteurs_cat_slug', 'acheteurs' );
    return get_posts([
        'post_type'   => 'hp_listing',
        'post_status' => 'publish',
        'numberposts' => -1,
        'tax_query'   => [[
            'taxonomy' => 'hp_listing_category',
            'field'    => 'slug',
            'terms'    => $slug,
        ]],
        'orderby' => 'title',
        'order'   => 'ASC',
    ]);
}

// ─── Hook : bouton "Confirmer collecte" dans wp-admin (meta box) ──────────────
add_action( 'add_meta_boxes', 'fpt_add_collection_metabox' );
function fpt_add_collection_metabox() {
    add_meta_box(
        'fpt_collection',
        '♻️ ' . fpt_t('Confirmer la collecte','Confirm Collection') . ' — FerayPro Tracer',
        'fpt_collection_metabox_html',
        'hp_listing',
        'side',
        'high'
    );
}

function fpt_collection_metabox_html( $post ) {
    $collected      = get_post_meta( $post->ID, '_fpt_collected', true );
    $acheteur_id    = get_post_meta( $post->ID, '_fpt_acheteur_id', true );
    $collected_date = get_post_meta( $post->ID, '_fpt_collected_date', true );
    $poids_kg       = fpt_get_poids_kg( $post->ID );
    $acheteurs      = fpt_get_acheteurs();

    wp_nonce_field( 'fpt_collection_nonce', 'fpt_collection_nonce' );
    ?>
    <div style="font-family:Arial,sans-serif;font-size:13px">
    <?php if ( $collected ) :
        $acheteur_titre = get_the_title( $acheteur_id );
        $co2_mat        = (float) get_post_meta( $post->ID, '_fpt_co2_avoided', true );
        $co2_process    = fpt_calculate_process_co2( get_the_title($post->ID), $poids_kg );
    ?>
        <div style="background:#e6f5ee;border:1px solid #1a7a4a;border-radius:6px;padding:10px;margin-bottom:10px">
            <strong style="color:#1a7a4a">✅ <?php echo fpt_t('Lot collecté','Batch collected'); ?></strong><br>
            <span style="color:#555"><?php echo fpt_t('Acheteur','Buyer'); ?> : <strong><?php echo esc_html($acheteur_titre); ?></strong></span><br>
            <span style="color:#555"><?php echo fpt_t('Date','Date'); ?> : <?php echo esc_html( date_i18n('d/m/Y', strtotime($collected_date)) ); ?></span><br>
            <span style="color:#1a7a4a">🌱 CO₂ évité : <?php echo esc_html(number_format($co2_mat,4)); ?> t</span><br>
            <span style="color:#e67e22">🏭 CO₂ recyclage : <?php echo esc_html(number_format($co2_process,6)); ?> t</span>
        </div>
        <button type="button" onclick="document.getElementById('fpt_uncollect_form').style.display='block'" 
            style="background:#c0392b;color:#fff;border:none;padding:6px 12px;border-radius:4px;cursor:pointer;font-size:12px">
            <?php echo fpt_t('Annuler la collecte','Cancel collection'); ?>
        </button>
        <div id="fpt_uncollect_form" style="display:none;margin-top:8px">
            <input type="hidden" name="fpt_uncollect" value="1">
            <button type="submit" style="background:#c0392b;color:#fff;border:none;padding:6px 12px;border-radius:4px;cursor:pointer">
                <?php echo fpt_t('Confirmer annulation','Confirm cancellation'); ?>
            </button>
        </div>
    <?php else : ?>
        <?php if ( $poids_kg <= 0 ) : ?>
            <p style="color:#c0392b"><?php echo fpt_t('⚠️ Poids non renseigné — impossible de confirmer.','⚠️ Weight missing — cannot confirm.'); ?></p>
        <?php else : ?>
        <p style="color:#555;margin-bottom:8px"><?php echo fpt_t('Sélectionner l\'acheteur qui a collecté ce lot :','Select the buyer who collected this batch:'); ?></p>
        <select name="fpt_acheteur_id" style="width:100%;margin-bottom:8px;padding:4px">
            <option value=""><?php echo fpt_t('— Choisir un acheteur —','— Select a buyer —'); ?></option>
            <?php foreach ( $acheteurs as $a ) : ?>
                <option value="<?php echo esc_attr($a->ID); ?>"><?php echo esc_html( get_the_title($a->ID) ); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" name="fpt_confirm_collect" value="1"
            style="background:#1a7a4a;color:#fff;border:none;padding:8px 14px;border-radius:4px;cursor:pointer;width:100%;font-size:13px;font-weight:bold">
            ✅ <?php echo fpt_t('Confirmer la collecte','Confirm collection'); ?>
        </button>
        <?php endif; ?>
    <?php endif; ?>
    </div>
    <?php
}

// ─── Sauvegarder la confirmation de collecte ──────────────────────────────────
add_action( 'save_post_hp_listing', 'fpt_save_collection', 30, 2 );
function fpt_save_collection( $post_id, $post ) {
    if ( ! isset($_POST['fpt_collection_nonce']) ) return;
    if ( ! wp_verify_nonce($_POST['fpt_collection_nonce'], 'fpt_collection_nonce') ) return;
    if ( ! current_user_can('manage_options') ) return;
    if ( wp_is_post_revision($post_id) ) return;

    // Confirmer collecte
    if ( isset($_POST['fpt_confirm_collect']) && ! empty($_POST['fpt_acheteur_id']) ) {
        $acheteur_id = intval($_POST['fpt_acheteur_id']);
        $poids_kg    = fpt_get_poids_kg($post_id);
        $co2_mat     = (float) get_post_meta($post_id, '_fpt_co2_avoided', true);
        update_post_meta($post_id, '_fpt_collected',      1);
        update_post_meta($post_id, '_fpt_acheteur_id',    $acheteur_id);
        update_post_meta($post_id, '_fpt_collected_date', current_time('mysql'));

        // Mettre à jour les stats — supprimé (calculé en temps réel dans le dashboard)
    }

    // Annuler collecte
    if ( isset($_POST['fpt_uncollect']) ) {
        $acheteur_id = get_post_meta($post_id, '_fpt_acheteur_id', true);
        $co2_total   = (float) get_post_meta($post_id, '_fpt_co2_total', true);
        $poids_kg    = fpt_get_poids_kg($post_id);

        // Soustraire des stats acheteur
        if ($acheteur_id) {
            $prev_co2   = (float) get_post_meta($acheteur_id, '_fpt_buyer_co2_total', true);
            $prev_lots  = (int)   get_post_meta($acheteur_id, '_fpt_buyer_lots_count', true);
            $prev_poids = (float) get_post_meta($acheteur_id, '_fpt_buyer_poids_total', true);
            update_post_meta($acheteur_id, '_fpt_buyer_co2_total',   max(0, round($prev_co2 - $co2_total, 4)));
            update_post_meta($acheteur_id, '_fpt_buyer_lots_count',  max(0, $prev_lots - 1));
            update_post_meta($acheteur_id, '_fpt_buyer_poids_total', max(0, $prev_poids - $poids_kg));
        }

        delete_post_meta($post_id, '_fpt_collected');
        delete_post_meta($post_id, '_fpt_acheteur_id');
        delete_post_meta($post_id, '_fpt_collected_date');
        delete_post_meta($post_id, '_fpt_co2_transport');
        delete_post_meta($post_id, '_fpt_co2_total');
    }
}

// ─── Shortcode : Dashboard acheteur ──────────────────────────────────────────
add_shortcode( 'fpt_acheteur', 'fpt_shortcode_acheteur' );
function fpt_shortcode_acheteur( $atts ) {
    $atts = shortcode_atts([ 'id' => 0 ], $atts);
    $acheteur_id = (int) $atts['id'];
    if ( ! $acheteur_id ) return '';

    $titre     = get_the_title($acheteur_id);
    $ville_ach = get_post_meta($acheteur_id, fpt_key_ville(), true);
    $zones     = get_post_meta($acheteur_id, 'hp_zones', true);
    $vehicules = get_post_meta($acheteur_id, 'hp_vehicules', true);

    // ── Calcul en temps réel depuis les lots (pas de cache) ───────────────
    $lots = get_posts([
        'post_type'   => 'hp_listing',
        'post_status' => 'publish',
        'numberposts' => -1,
        'meta_query'  => [
            [ 'key' => '_fpt_collected',   'value' => '1',              'compare' => '=' ],
            [ 'key' => '_fpt_acheteur_id', 'value' => $acheteur_id,     'compare' => '=' ],
        ],
        'orderby'  => 'meta_value',
        'meta_key' => '_fpt_collected_date',
        'order'    => 'DESC',
    ]);

    $lots_count  = count($lots);
    $poids_total = 0;
    $co2_process_total = 0;
    foreach ($lots as $lot) {
        $poids              = fpt_get_poids_kg($lot->ID);
        $poids_total       += $poids;
        $co2_process_total += fpt_calculate_process_co2(get_the_title($lot->ID), $poids);
    }
    $co2_produit_total = round($co2_process_total, 4);

    ob_start(); ?>
    <style>
    .fpt-buyer-dash{font-family:'DM Sans',Arial,sans-serif;max-width:860px;margin:0 auto}
    .fpt-buyer-header{background:#0f1c13;color:#fff;border-radius:12px;padding:24px 28px;margin-bottom:24px}
    .fpt-buyer-header h2{font-size:22px;font-weight:700;color:#5dde8a;margin:0 0 4px}
    .fpt-buyer-header p{font-size:13px;color:rgba(255,255,255,0.6);margin:0}
    .fpt-buyer-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:24px}
    .fpt-buyer-stat{background:#fff;border:1.5px solid #d0ddd4;border-radius:10px;padding:16px;text-align:center}
    .fpt-buyer-stat-val{font-family:monospace;font-size:22px;font-weight:700;color:#1a7a4a;display:block}
    .fpt-buyer-stat-lbl{font-size:11px;color:#6b8070;text-transform:uppercase;letter-spacing:.05em}
    .fpt-buyer-lots h3{font-size:16px;font-weight:700;margin-bottom:12px;color:#0f1c13}
    .fpt-buyer-lot{background:#fff;border:1.5px solid #d0ddd4;border-radius:10px;padding:14px 16px;margin-bottom:10px;display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center}
    .fpt-buyer-lot-title{font-weight:700;font-size:14px;color:#0f1c13;margin-bottom:3px}
    .fpt-buyer-lot-meta{font-size:12px;color:#6b8070}
    .fpt-buyer-lot-co2{text-align:right}
    .fpt-buyer-lot-co2-mat{font-family:monospace;font-size:15px;font-weight:700;color:#1a7a4a}
    .fpt-buyer-lot-co2-trans{font-size:11px;color:#6b8070}
    .fpt-buyer-lot-co2-total{font-size:12px;font-weight:700;color:#0f1c13;border-top:1px solid #d0ddd4;padding-top:3px;margin-top:3px}
    @media(max-width:580px){.fpt-buyer-stats{grid-template-columns:1fr 1fr}.fpt-buyer-lot{grid-template-columns:1fr}}
    </style>
    <div class="fpt-buyer-dash">
        <div class="fpt-buyer-header">
            <h2>♻️ <?php echo esc_html($titre); ?></h2>
            <p>
                <?php if($ville_ach) echo '📍 ' . esc_html($ville_ach) . ' · '; ?>
                <?php if($zones) echo fpt_t('Zones : ','Zones: ') . esc_html($zones) . ' · '; ?>
                <?php if($vehicules) echo fpt_t('Véhicules : ','Vehicles: ') . esc_html($vehicules); ?>
            </p>
        </div>

        <div class="fpt-buyer-stats">
            <div class="fpt-buyer-stat">
                <span class="fpt-buyer-stat-val"><?php echo $lots_count; ?></span>
                <span class="fpt-buyer-stat-lbl"><?php echo fpt_t('Lots collectés','Batches collected'); ?></span>
            </div>
            <div class="fpt-buyer-stat">
                <span class="fpt-buyer-stat-val"><?php echo esc_html(fpt_display_weight($poids_total)); ?></span>
                <span class="fpt-buyer-stat-lbl"><?php echo fpt_t('Poids total','Total weight'); ?></span>
            </div>
            <div class="fpt-buyer-stat" style="border-top:3px solid #e67e22">
                <span class="fpt-buyer-stat-val" style="color:#e67e22"><?php echo number_format($co2_produit_total, 4); ?> t</span>
                <span class="fpt-buyer-stat-lbl">CO₂ <?php echo fpt_t('produit (recyclage)','produced (recycling)'); ?></span>
            </div>
        </div>

        <?php if ( empty($lots) ) : ?>
            <p style="color:#6b8070;font-size:14px"><?php echo fpt_t('Aucun lot collecté pour l\'instant.','No batches collected yet.'); ?></p>
        <?php else : ?>
        <div class="fpt-buyer-lots">
            <h3><?php echo fpt_t('Lots collectés','Collected batches'); ?></h3>
            <?php foreach ( $lots as $lot ) :
                $poids       = fpt_get_poids_kg($lot->ID);
                $titre_lot   = get_the_title($lot->ID);
                $co2_process = fpt_calculate_process_co2($titre_lot, $poids);
                $ville_vend  = get_post_meta($lot->ID, fpt_key_ville(), true);
                $date_col    = get_post_meta($lot->ID, '_fpt_collected_date', true);
                $lot_id      = get_post_meta($lot->ID, '_fpt_lot_id', true);
            ?>
            <div class="fpt-buyer-lot">
                <div>
                    <div class="fpt-buyer-lot-title">
                        <a href="<?php echo esc_url(get_permalink($lot->ID)); ?>" style="color:#0f1c13;text-decoration:none">
                            <?php echo esc_html(get_the_title($lot->ID)); ?>
                        </a>
                    </div>
                    <div class="fpt-buyer-lot-meta">
                        <?php echo esc_html(fpt_display_weight($poids)); ?>
                        <?php if($ville_vend) echo ' · 📍 ' . esc_html($ville_vend); ?>
                        <?php if($date_col) echo ' · ' . date_i18n('d/m/Y', strtotime($date_col)); ?>
                        <?php if($lot_id) echo ' · ' . esc_html($lot_id); ?>
                    </div>
                </div>
                <div class="fpt-buyer-lot-co2">
                    <div class="fpt-buyer-lot-co2-mat" style="color:#e67e22">🏭 <?php echo number_format($co2_process, 6); ?> t CO₂ <?php echo fpt_t('recyclage','recycling'); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <p style="font-size:11px;color:#6b8070;margin-top:20px">
            * <?php echo fpt_t(
                'CO₂ recyclage : émissions produites par le process de recyclage (FEDEREC/ADEME ACV 2017). Gain net CO₂ = CO₂ évité − CO₂ recyclage.',
                'CO₂ recycling: emissions produced by the recycling process (FEDEREC/ADEME LCA 2017). Net CO₂ gain = CO₂ avoided − CO₂ recycling.'
            ); ?>
        </p>
    </div>
    <?php
    return ob_get_clean();
}

// ─── Recherche des prix du jour pour un type de déchet ───────────────────────
function fpt_get_prix_du_jour( $titre_lot ) {
    $titre_lower    = fpt_normalize_text( $titre_lot );
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
        $prix_titre_lower = fpt_normalize_text( $prix_post->post_title );
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
    $titre_lower     = fpt_normalize_text( get_the_title( $post_id ) );
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

    ob_start();

    // ── Badge partenaire EN PREMIER si ce lot vient d'un partenaire ──────────
    $fpt_ref        = get_post_meta( $post_id, '_fpt_ref', true );
    $fpt_partenaire = $fpt_ref ? fpt_get_partenaire_by_slug( $fpt_ref ) : null;
    if ( $fpt_partenaire ):
        $p_couleur = esc_attr( $fpt_partenaire['couleur'] ?? '#1a7a4a' );
        $p_nom     = esc_html( $fpt_partenaire['nom'] );
        $p_logo    = ! empty( $fpt_partenaire['logo_url'] ) ? esc_url( $fpt_partenaire['logo_url'] ) : '';
    ?>
    <div class="fpt-partner-banner" style="
        display:flex;align-items:center;gap:12px;
        padding:10px 18px;
        background:<?php echo $p_couleur; ?>14;
        border:2px solid <?php echo $p_couleur; ?>;
        border-radius:10px;
        margin-bottom:12px;
        font-family:var(--fpt-sans,sans-serif);
    ">
        <?php if ( $p_logo ): ?>
            <img src="<?php echo $p_logo; ?>"
                 alt="<?php echo $p_nom; ?>"
                 style="height:28px;width:auto;object-fit:contain;flex-shrink:0">
        <?php else: ?>
            <span style="font-size:22px;flex-shrink:0">🤝</span>
        <?php endif; ?>
        <div style="display:flex;flex-direction:column;gap:1px">
            <span style="font-size:11px;color:<?php echo $p_couleur; ?>;font-weight:700;text-transform:uppercase;letter-spacing:0.08em">
                <?php echo fpt_t('Recommandé par','Recommended by'); ?>
            </span>
            <span style="font-size:15px;font-weight:700;color:#0f1c13">
                <?php echo $p_nom; ?>
            </span>
        </div>
    </div>
    <?php endif; ?>

    <div class="fpt-inline-block">
        <div class="fpt-inline-header">
            <span class="fpt-inline-icon">🌱</span>
            <div>
                <strong><?php echo fpt_t('Impact environnemental de ce lot','Environmental impact of this batch'); ?></strong>
                <span class="fpt-inline-id"><?php echo esc_html( $lot_id ); ?></span>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px">
                <div class="fpt-inline-co2"><?php echo esc_html( $co2_display ); ?></div>
                <?php
                $collected = get_post_meta($post_id, '_fpt_collected', true);
                if ($collected) :
                    $ach_id = get_post_meta($post_id, '_fpt_acheteur_id', true);
                    $ach_nom = $ach_id ? get_the_title($ach_id) : '';
                ?>
                <span style="background:#1a7a4a;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px">
                    ✅ <?php echo fpt_t('Collecté','Collected'); ?><?php if($ach_nom) echo ' · ' . esc_html($ach_nom); ?>
                </span>
                <?php else : ?>
                <span style="background:#f59e0b;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px">
                    ⏳ <?php echo fpt_t('En attente de collecte','Awaiting collection'); ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="fpt-inline-body">
            <div class="fpt-inline-stats">
                <div class="fpt-inline-stat">
                    <span class="fpt-inline-val"><?php echo esc_html( fpt_display_weight( $poids_kg ) ); ?></span>
                    <span class="fpt-inline-lbl"><?php echo fpt_t('Poids à collecter','Weight to collect'); ?></span>
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

// ─── Facteurs CO₂ évité par tonne (source ADEME / Base Carbone) ───────────────
function fpt_co2_factors() {
    // ════════════════════════════════════════════════════════════════════════
    // FACTEURS CO₂ — GAIN NET = émissions primaire − émissions recyclage
    // Sources : ADEME Base Carbone, FEDEREC ACV 2017/2019, ADEME-Deloitte 2023
    // Formule : CO₂ évité (t) = Poids (kg) ÷ 1000 × Facteur (t CO₂/t recyclée)
    // ════════════════════════════════════════════════════════════════════════
    return [

        // ════════════════════════════════════════════════════════════════
        // MÉTAUX FERREUX / FERROUS METALS
        // Source : FEDEREC/ADEME ACV 2017 — acier primaire 1,9 t − recyclé 0,58 t = 1,10 t net
        // ════════════════════════════════════════════════════════════════
        'fer'              => 1.10,
        'iron'             => 1.10,
        'acier'            => 1.10,
        'steel'            => 1.10,
        'ferraille'        => 1.10,
        'scrap'            => 1.10,
        'scrap metal'      => 1.10,
        'fonte'            => 0.90,   // fonte : gain légèrement inférieur à l'acier
        'cast iron'        => 0.90,
        'inox'             => 2.10,   // inox : primaire ~2,8 − recyclé ~0,7
        'stainless'        => 2.10,
        'acier inox'       => 2.10,
        'stainless steel'  => 2.10,
        'tournure'         => 1.10,
        'shavings'         => 1.10,
        'turnings'         => 1.10,
        'copeau'           => 1.10,
        'chips'            => 1.10,
        'limaille'         => 1.10,
        'filings'          => 1.10,
        'radiateur'        => 1.10,
        'radiator'         => 1.10,
        'ressort'          => 1.10,
        'spring'           => 1.10,
        'blindage'         => 1.10,
        'armor'            => 1.10,
        'poutrelle'        => 1.10,
        'beam'             => 1.10,
        'profilé'          => 1.10,
        'profile'          => 1.10,
        'tole'             => 1.10,
        'tôle'             => 1.10,
        'sheet metal'      => 1.10,
        'tube acier'       => 1.10,
        'steel pipe'       => 1.10,
        'rail'             => 1.10,
        'rebar'            => 1.10,
        'fer à béton'      => 1.10,
        'fer beton'        => 1.10,
        'reinforcing bar'  => 1.10,
        'chaudière'        => 1.10,
        'chaudiere'        => 1.10,
        'boiler'           => 1.10,
        'reservoir'        => 1.10,
        'réservoir'        => 1.10,
        'tank'             => 1.10,
        'cuve'             => 1.10,
        'vat'              => 1.10,
        'conteneur'        => 1.10,
        'container'        => 1.10,
        'chassis'          => 1.10,
        'châssis'          => 1.10,
        'frame'            => 1.10,
        'essieu'           => 1.10,
        'axle'             => 1.10,
        'vilebrequin'      => 1.10,
        'crankshaft'       => 1.10,
        'bielle'           => 1.10,
        'connecting rod'   => 1.10,
        'engrenage'        => 1.10,
        'gear'             => 1.10,
        'roulement'        => 1.10,
        'bearing'          => 1.10,
        'arbre'            => 1.10,
        'shaft'            => 1.10,
        'ancre'            => 1.10,
        'anchor'           => 1.10,
        'chaine'           => 1.10,
        'chaîne'           => 1.10,
        'chain'            => 1.10,

        // ════════════════════════════════════════════════════════════════
        // MÉTAUX NON FERREUX / NON-FERROUS METALS
        // ════════════════════════════════════════════════════════════════

        // Aluminium : primaire 7,24 t − recyclé 0,36 t = 6,88 t net
        // Source : ADEME Base Carbone — "aluminium recyclé émet 20× moins" (ADEME 2024)
        'aluminium'        => 6.88,
        'aluminum'         => 6.88,
        'alu'              => 6.88,
        'profilé alu'      => 6.88,
        'aluminum profile' => 6.88,
        'canette'          => 6.88,
        'can'              => 6.88,
        'aluminum can'     => 6.88,

        // Cuivre : primaire 1,445 t − recyclé 1,304 t = 0,141 t net
        // Source : FEDEREC/ADEME ACV 2017, confirmé évaluateur indépendant 2026
        'cuivre'           => 0.141,
        'copper'           => 0.141,

        // Bronze : alliage cuivre/étain — gain estimé ~0,12 t/t
        'bronze'           => 0.120,

        // Laiton : alliage cuivre/zinc — gain estimé ~0,10 t/t
        'laiton'           => 0.100,
        'brass'            => 0.100,

        // Zinc : primaire ~0,9 t − recyclé ~0,18 t = 0,72 t net
        'zinc'             => 0.720,

        // Plomb : primaire ~0,5 t − recyclé ~0,08 t = 0,42 t net
        'plomb'            => 0.420,
        'lead'             => 0.420,

        // Étain : gain estimé ~1,5 t/t (procédé énergivore)
        'etain'            => 1.50,
        'étain'            => 1.50,
        'tin'              => 1.50,

        // Nickel : primaire ~6,5 t − recyclé ~0,5 t = 6,0 t net
        'nickel'           => 6.00,

        // Titane : gain estimé ~4,0 t/t
        'titane'           => 4.00,
        'titanium'         => 4.00,

        // Magnésium : primaire ~10 t − recyclé ~1,0 t = 9,0 t net
        'magnesium'        => 9.00,
        'magnésium'        => 9.00,

        // Chrome : gain estimé ~2,0 t/t
        'chrome'           => 2.00,
        'chromium'         => 2.00,

        // Tungstène/Carbure
        'tungstene'        => 3.00,
        'tungstène'        => 3.00,
        'tungsten'         => 3.00,
        'carbure'          => 3.00,
        'carbide'          => 3.00,

        // Cobalt : gain estimé ~7,0 t/t
        'cobalt'           => 7.00,

        // Bismuth, antimoine
        'bismuth'          => 2.00,
        'antimoine'        => 1.50,
        'antimony'         => 1.50,

        // Cadmium, indium, gallium, germanium
        'cadmium'          => 2.50,
        'indium'           => 4.00,
        'gallium'          => 4.00,
        'germanium'        => 4.00,

        // Métaux précieux — gains très élevés (extraction minière intensive)
        'palladium'        => 9.00,
        'platine'          => 11.00,
        'platinum'         => 11.00,
        'argent'           => 7.00,
        'silver'           => 7.00,
        'or'               => 14.00,
        'gold'             => 14.00,

        // Molybdène, vanadium, manganèse
        'molybdene'        => 3.50,
        'molybdène'        => 3.50,
        'molybdenum'       => 3.50,
        'vanadium'         => 4.00,
        'manganèse'        => 2.00,
        'manganese'        => 2.00,

        // ════════════════════════════════════════════════════════════════
        // VÉHICULES & PIÈCES AUTO / VEHICLES & AUTO PARTS
        // ════════════════════════════════════════════════════════════════
        'vehicule'         => 1.10,   // VHU : majoritairement acier
        'véhicule'         => 1.10,
        'vehicle'          => 1.10,
        'end-of-life'      => 1.10,
        'end of life'      => 1.10,
        'salvage'          => 1.10,
        'junk car'         => 1.10,
        'voiture'          => 1.10,
        'car'              => 1.10,
        'camion'           => 1.10,
        'truck'            => 1.10,
        'camionnette'      => 1.10,
        'van'              => 1.10,
        'bus'              => 1.10,
        'autobus'          => 1.10,
        'tracteur'         => 1.10,
        'tractor'          => 1.10,
        'engin'            => 1.10,
        'moto'             => 1.10,
        'motorcycle'       => 1.10,
        'motocycle'        => 1.10,
        'scooter'          => 1.10,
        'velo'             => 1.10,
        'vélo'             => 1.10,
        'bicycle'          => 1.10,
        'bike'             => 1.10,
        'moteur'           => 1.50,   // moteur = acier + cuivre mixte
        'motor'            => 1.50,
        'engine'           => 1.50,
        'alternateur'      => 2.00,   // bobinage cuivre dominant
        'alternator'       => 2.00,
        'demarreur'        => 1.50,
        'démarreur'        => 1.50,
        'starter'          => 1.50,
        'boite'            => 1.10,
        'boîte'            => 1.10,
        'gearbox'          => 1.10,
        'transmission'     => 1.10,
        'pont'             => 1.10,
        'jante'            => 6.88,   // jantes alu
        'rim'              => 6.88,
        'wheel'            => 6.88,
        'carter'           => 1.10,
        'casing'           => 1.10,
        'culasse'          => 1.10,
        'cylinder head'    => 1.10,
        'bloc moteur'      => 1.10,
        'engine block'     => 1.10,
        'turbo'            => 1.50,
        'turbocompresseur' => 1.50,
        'turbocharger'     => 1.50,
        'compresseur'      => 1.50,
        'compressor'       => 1.50,
        'pompe'            => 1.50,
        'pump'             => 1.50,
        'amortisseur'      => 1.10,
        'shock absorber'   => 1.10,
        'suspension'       => 1.10,
        'direction'        => 1.10,
        'steering'         => 1.10,
        'frein'            => 1.10,
        'brake'            => 1.10,
        'disque'           => 1.10,
        'disc'             => 1.10,
        'carrosserie'      => 1.10,
        'body'             => 1.10,
        'bodywork'         => 1.10,
        'pare choc'        => 1.00,
        'pare-choc'        => 1.00,
        'bumper'           => 1.00,
        'capot'            => 1.10,
        'hood'             => 1.10,
        'bonnet'           => 1.10,
        'portière'         => 1.10,
        'portiere'         => 1.10,
        'door'             => 1.10,
        'pot echappement'  => 1.10,
        'exhaust'          => 1.10,
        'muffler'          => 1.10,
        'catalyseur'       => 4.50,   // platine + palladium + rhodium
        'catalytic'        => 4.50,
        'catalyst'         => 4.50,

        // ════════════════════════════════════════════════════════════════
        // ÉLECTRONIQUE & E-WASTE
        // Gain estimé ~3-4 t/t (densité métaux précieux + cuivre)
        // ════════════════════════════════════════════════════════════════
        'electronique'     => 3.50,
        'électronique'     => 3.50,
        'electronics'      => 3.50,
        'electronic'       => 3.50,
        'ewaste'           => 3.50,
        'e-waste'          => 3.50,
        'weee'             => 3.50,
        'deee'             => 3.50,
        'electrique'       => 2.00,
        'électrique'       => 2.00,
        'electric'         => 2.00,
        'electrical'       => 2.00,
        'ordinateur'       => 3.50,
        'computer'         => 3.50,
        'laptop'           => 3.50,
        'desktop'          => 3.50,
        'pc'               => 3.50,
        'serveur'          => 3.50,
        'server'           => 3.50,
        'telephone'        => 4.00,
        'téléphone'        => 4.00,
        'phone'            => 4.00,
        'smartphone'       => 4.00,
        'cellphone'        => 4.00,
        'mobile'           => 4.00,
        'gsm'              => 4.00,
        'tablette'         => 3.50,
        'tablet'           => 3.50,
        'imprimante'       => 3.00,
        'printer'          => 3.00,
        'photocopieur'     => 3.00,
        'copier'           => 3.00,
        'scanner'          => 3.00,
        'ecran'            => 3.00,
        'écran'            => 3.00,
        'screen'           => 3.00,
        'monitor'          => 3.00,
        'moniteur'         => 3.00,
        'television'       => 3.00,
        'télévision'       => 3.00,
        'tv'               => 3.00,
        'cable'            => 0.50,   // câble cuivre : gain net cuivre ~0,14 + gaine plastique
        'câble'            => 0.50,
        'wire'             => 0.50,
        'wiring'           => 0.50,
        'fil'              => 0.50,
        'transformateur'   => 2.00,
        'transformer'      => 2.00,
        'condensateur'     => 3.00,
        'capacitor'        => 3.00,
        'carte'            => 3.50,
        'board'            => 3.50,
        'motherboard'      => 3.50,
        'circuit'          => 3.50,
        'processeur'       => 4.00,
        'processor'        => 4.00,
        'cpu'              => 4.00,
        'gpu'              => 4.00,
        'disque dur'       => 3.00,
        'hard drive'       => 3.00,
        'hard disk'        => 3.00,
        'clavier'          => 2.50,
        'keyboard'         => 2.50,
        'souris'           => 2.50,
        'mouse'            => 2.50,
        'chargeur'         => 2.00,
        'charger'          => 2.00,
        'adaptateur'       => 2.00,
        'adapter'          => 2.00,
        'onduleur'         => 2.50,
        'ups'              => 2.50,
        'groupe electrogene' => 1.50,
        'generator'        => 1.50,
        'generateur'       => 1.50,
        'générateur'       => 1.50,
        'climatiseur'      => 2.00,   // cuivre + alu + gaz réfrigérant
        'climatisation'    => 2.00,
        'air conditioner'  => 2.00,
        'ac unit'          => 2.00,
        'refrigerateur'    => 1.80,
        'réfrigérateur'    => 1.80,
        'refrigerator'     => 1.80,
        'fridge'           => 1.80,
        'frigo'            => 1.80,
        'congelateur'      => 1.80,
        'congélateur'      => 1.80,
        'freezer'          => 1.80,
        'lave linge'       => 1.50,
        'lave-linge'       => 1.50,
        'washing machine'  => 1.50,
        'washer'           => 1.50,
        'machine a laver'  => 1.50,
        'seche linge'      => 1.50,
        'sèche-linge'      => 1.50,
        'dryer'            => 1.50,
        'lave vaisselle'   => 1.50,
        'lave-vaisselle'   => 1.50,
        'dishwasher'       => 1.50,
        'four'             => 1.20,
        'oven'             => 1.20,
        'micro onde'       => 1.50,
        'micro-onde'       => 1.50,
        'microwave'        => 1.50,
        'aspirateur'       => 1.50,
        'vacuum'           => 1.50,
        'ventilateur'      => 1.50,
        'fan'              => 1.50,
        'pompe chaleur'    => 2.00,
        'heat pump'        => 2.00,
        'panneau solaire'  => 2.00,   // silicium + argent + alu
        'solar panel'      => 2.00,
        'photovoltaique'   => 2.00,
        'photovoltaïque'   => 2.00,
        'photovoltaic'     => 2.00,

        // ════════════════════════════════════════════════════════════════
        // BATTERIES / BATTERIES
        // ════════════════════════════════════════════════════════════════
        'batterie'         => 1.80,   // plomb-acide : plomb recyclé gain ~0,42 + acier
        'battery'          => 1.80,
        'batteries'        => 1.80,
        'accumulateur'     => 1.80,
        'pile'             => 1.50,
        'batterie lithium' => 4.00,   // Li, Co, Ni — gains élevés
        'lithium battery'  => 4.00,
        'lithium'          => 4.00,
        'li-ion'           => 4.00,
        'batterie plomb'   => 0.90,   // gain net plomb ~0,42 × teneur ~50% + acier
        'lead battery'     => 0.90,
        'car battery'      => 0.90,
        'batterie voiture' => 0.90,

        // ════════════════════════════════════════════════════════════════
        // PAPIER & CARTON
        // Source : FEDEREC/ADEME ACV 2017 — papier primaire ~0,92 t − recyclé ~0,87 t = 0,05 t net
        // Note : gain CO₂ faible mais économie d'eau et de bois très significative
        // ════════════════════════════════════════════════════════════════
        'papier'           => 0.050,
        'paper'            => 0.050,
        'carton'           => 0.050,
        'cardboard'        => 0.050,
        'journal'          => 0.050,
        'newspaper'        => 0.050,
        'archive'          => 0.050,
        'livre'            => 0.050,
        'book'             => 0.050,
        'magazine'         => 0.050,
        'emballage'        => 0.050,
        'packaging'        => 0.050,
        'ondule'           => 0.050,
        'ondulé'           => 0.050,
        'corrugated'       => 0.050,

        // ════════════════════════════════════════════════════════════════
        // PLASTIQUES
        // Source : ADEME 2024 — 1 t plastique recyclé économise 2,7 t CO₂
        // Gain net PET : primaire ~2,15 − recyclé ~0,65 = 1,50 t/t
        // ════════════════════════════════════════════════════════════════
        'plastique'        => 1.50,
        'plastic'          => 1.50,
        'pet'              => 1.50,
        'hdpe'             => 1.40,
        'pvc'              => 0.80,   // PVC : gain plus faible (chlore)
        'polypropylene'    => 1.50,
        'polypropylène'    => 1.50,
        'pp'               => 1.50,
        'polyethylene'     => 1.40,
        'polyéthylène'     => 1.40,
        'pe'               => 1.40,
        'polystyrene'      => 1.30,
        'polystyrène'      => 1.30,
        'ps'               => 1.30,
        'abs'              => 1.40,
        'nylon'            => 1.60,
        'polyamide'        => 1.60,
        'caoutchouc'       => 0.80,
        'latex'            => 0.80,
        'silicone'         => 0.90,
        'fibre de verre'   => 0.70,
        'composite'        => 0.80,

        // ════════════════════════════════════════════════════════════════
        // PNEUMATIQUES & CAOUTCHOUC
        // ════════════════════════════════════════════════════════════════
        'pneu'             => 0.80,
        'pneumatique'      => 0.80,
        'tire'             => 0.80,
        'rubber'           => 0.80,
        'chambre air'      => 0.80,
        'chambre à air'    => 0.80,
        'courroie'         => 0.80,
        'joint'            => 0.60,
        'tuyau'            => 0.60,

        // ════════════════════════════════════════════════════════════════
        // VERRE
        // Source : ADEME Base Carbone — primaire 0,53 − recyclé 0,29 = 0,24 t/t
        // ════════════════════════════════════════════════════════════════
        'verre'            => 0.240,
        'glass'            => 0.240,
        'bouteille verre'  => 0.240,
        'vitre'            => 0.240,
        'pare brise'       => 0.240,
        'pare-brise'       => 0.240,
        'miroir'           => 0.240,

        // ════════════════════════════════════════════════════════════════
        // TEXTILES & CUIR
        // ════════════════════════════════════════════════════════════════
        'textile'          => 0.40,
        'tissu'            => 0.40,
        'vetement'         => 0.40,
        'vêtement'         => 0.40,
        'chiffon'          => 0.40,
        'clothing'         => 0.40,
        'laine'            => 0.40,
        'coton'            => 0.40,
        'cuir'             => 0.50,
        'leather'          => 0.50,
        'sac'              => 0.40,
        'chaussure'        => 0.50,

        // ════════════════════════════════════════════════════════════════
        // BOIS & DÉRIVÉS
        // ════════════════════════════════════════════════════════════════
        'bois'             => 0.30,
        'wood'             => 0.30,
        'palette'          => 0.30,
        'pallet'           => 0.30,
        'meuble'           => 0.30,
        'furniture'        => 0.30,
        'contreplaque'     => 0.30,
        'contreplaqué'     => 0.30,
        'mdf'              => 0.30,
        'sciure'           => 0.20,
        'copeaux bois'     => 0.20,

        // ════════════════════════════════════════════════════════════════
        // DÉCHETS INDUSTRIELS
        // ════════════════════════════════════════════════════════════════
        'huile'            => 1.50,   // huile moteur recyclée vs vierge
        'lubrifiant'       => 1.50,
        'solvant'          => 1.00,
        'peinture'         => 0.80,
        'ciment'           => 0.15,
        'beton'            => 0.10,
        'béton'            => 0.10,
        'gravat'           => 0.05,

        // ════════════════════════════════════════════════════════════════
        // ÉQUIPEMENTS INDUSTRIELS (mixte acier/cuivre)
        // ════════════════════════════════════════════════════════════════
        'machine'          => 1.20,
        'machine outil'    => 1.20,
        'tour'             => 1.20,
        'fraiseuse'        => 1.20,
        'presse'           => 1.10,
        'grue'             => 1.10,
        'chariot'          => 1.10,
        'elevateur'        => 1.10,
        'élévateur'        => 1.10,
        'convoyeur'        => 1.10,
        'echangeur'        => 1.50,   // échangeur thermique cuivre/inox
        'échangeur'        => 1.50,
        'tuyauterie'       => 1.00,
        'robinetterie'     => 0.80,   // laiton/bronze — gain net faible
        'vanne'            => 0.80,
        'pompe industrielle' => 1.50,
        'motopompe'        => 1.50,
        'compresseur air'  => 1.20,
        'soudure'          => 0.50,

        // ════════════════════════════════════════════════════════════════
        // DÉFAUT — valeur conservative si matière non reconnue
        // ════════════════════════════════════════════════════════════════
        'default'          => 0.50,
    ];
}

// ─── Calcul CO₂ évité ─────────────────────────────────────────────────────────
function fpt_calculate_co2( $titre, $poids_kg ) {
    if ( empty( $poids_kg ) || $poids_kg <= 0 ) return 0;

    $factors = fpt_co2_factors();
    $titre_lower = fpt_normalize_text( $titre );
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

// ─── Population density multiplier for ERRI ───────────────────────────────────
function fpt_get_population_density_multiplier() {
    $country = strtolower( get_option( 'fpt_country_name', '' ) );
    if ( strpos($country, 'maroc') !== false || strpos($country, 'morocco') !== false ) return 1.2;
    if ( strpos($country, 'congo') !== false || strpos($country, 'rdc') !== false )     return 1.8;
    if ( strpos($country, 'senegal') !== false || strpos($country, 'sénégal') !== false ) return 1.3;
    if ( strpos($country, 'nigeria') !== false )  return 1.5;
    if ( strpos($country, 'kenya') !== false )    return 1.2;
    if ( strpos($country, 'france') !== false )   return 0.8;
    if ( strpos($country, 'usa') !== false || strpos($country, 'états-unis') !== false ) return 0.7;
    return 1.0;
}

// ─── Helper : masquer partiellement le numéro de téléphone ───────────────────
// Ex: +212 662-119988 → +212 662-XXXXXX
// Garde les 7 premiers chiffres, masque le reste
function fpt_mask_phone( $phone, $collected = false ) {
    if ( $collected ) return $phone; // numéro complet si lot collecté

    // Nettoyer et formater
    $digits = preg_replace('/[^0-9+]/', '', $phone);
    $len    = strlen($digits);

    if ( $len <= 7 ) return str_repeat('X', $len); // trop court → tout masquer

    // Garder les 7 premiers caractères (indicatif + début numéro)
    $visible = substr($digits, 0, 7);
    $masked  = str_repeat('X', $len - 7);

    // Reformater lisiblement
    return $visible . $masked;
}

// ─── Helper : URL WhatsApp avec numéro masqué dans le texte ──────────────────
function fpt_whatsapp_btn( $phone, $collected = false ) {
    $clean    = preg_replace('/[^0-9]/', '', $phone);
    $wa_url   = 'https://wa.me/' . $clean;
    $display  = fpt_mask_phone( $phone, $collected );
    $label    = fpt_t('Contacter via WhatsApp','Contact via WhatsApp');
    return sprintf(
        '<a class="fpt-whatsapp-btn" href="%s" target="_blank" rel="noopener">💬 %s <span style="font-size:11px;opacity:.8">%s</span></a>',
        esc_url($wa_url),
        esc_html($label),
        esc_html($display)
    );
}

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
    // Facteurs polluants évités (Sources : OMS, Pure Earth, EPA, UNEP)
    // Chaque polluant analysé indépendamment — un lot peut contribuer à plusieurs
    $plomb_kw   = ['plomb','lead','batterie','battery','accumulateur','soudure','solder','peinture','paint','radiateur','radiator'];
    $cadmium_kw = ['pile','batterie lithium','lithium battery','electronique','electronics','ewaste','e-waste','plastique','plastic','telephone','phone','smartphone','ordinateur','computer'];
    $pm25_kw    = ['cable','fil','wire','cuivre','copper','plastique','plastic','pvc','caoutchouc','rubber','pneu','tire'];
    $mercure_kw = ['ecran','screen','moniteur','monitor','television','tv','lampe','lamp','neon','thermometre','thermometer'];

    $plomb_kg = $cadmium_g = $pm25_kg = $mercure_g = 0;

    foreach ( $listings_ids as $id ) {
        $poids_kg = (float) fpt_get_poids_kg( $id );
        if ( $poids_kg <= 0 ) continue;
        $poids_t = $poids_kg / 1000;
        $titre   = fpt_normalize_text( get_the_title( $id ) );

        // Chaque polluant avec son propre break — indépendants les uns des autres
        foreach ( $plomb_kw as $kw ) {
            if ( strpos( $titre, $kw ) !== false ) {
                $plomb_kg += $poids_t * 0.5; // 500g plomb/t — Pure Earth 2016, WHO 2021
                break;
            }
        }
        foreach ( $cadmium_kw as $kw ) {
            if ( strpos( $titre, $kw ) !== false ) {
                $cadmium_g += $poids_t * 200; // 200g cadmium/t — Pure Earth 2020, UNEP 2018
                break;
            }
        }
        foreach ( $pm25_kw as $kw ) {
            if ( strpos( $titre, $kw ) !== false ) {
                $pm25_kg += $poids_t * 15; // 15kg PM2.5/t — EPA AP-42 2022
                break;
            }
        }
        foreach ( $mercure_kw as $kw ) {
            if ( strpos( $titre, $kw ) !== false ) {
                $mercure_g += $poids_t * 50; // 50g mercure/t — UNEP Minamata 2018
                break;
            }
        }
    }

    // ERRI avec multiplicateur densité démographique
    $multiplier = fpt_get_population_density_multiplier();
    $erri       = round( ($plomb_kg * 50 + $pm25_kg * 10) * $multiplier );

    return [
        'plomb_kg'   => round( $plomb_kg, 3 ),
        'cadmium_g'  => round( $cadmium_g, 1 ),
        'pm25_kg'    => round( $pm25_kg, 2 ),
        'mercure_g'  => round( $mercure_g, 1 ),
        'enfants'    => $erri, // ERRI — Exposure Risk Reduction Index (OMS)
        'multiplier' => $multiplier,
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

                <?php if ( $whatsapp ) :
                    $is_collected = (bool) get_post_meta($post_id, '_fpt_collected', true);
                    echo fpt_whatsapp_btn($whatsapp, $is_collected);
                endif; ?>
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
                <div class="fpt-impact-label"><?php echo fpt_t('Déchets à recycler','Waste to recycle'); ?></div>
            </div>
            <div class="fpt-impact-card fpt-impact-card--green">
                <div class="fpt-impact-icon">🌱</div>
                <div class="fpt-impact-value"><?php echo esc_html( number_format( $total_co2, 2 ) ); ?> t</div>
                <div class="fpt-impact-label"><?php echo fpt_t('CO₂ évité (recyclage)','CO₂ avoided (recycling)'); ?></div>
            </div>
            <?php
            // CO₂ produit par le recyclage formel (matière recyclée + transport)
            // Facteur recyclage : ~10% du CO₂ évité (estimation conservative)
            // CO₂ transport = somme des transports de tous les lots collectés
            $co2_recyclage_process = round($total_co2 * 0.10, 2);
            $co2_produit_total = $co2_recyclage_process;
            ?>
            <div class="fpt-impact-card" style="border-top:3px solid #e67e22">
                <div class="fpt-impact-icon">🏭</div>
                <div class="fpt-impact-value" style="color:#e67e22"><?php echo esc_html( number_format( $co2_produit_total, 2 ) ); ?> t</div>
                <div class="fpt-impact-label"><?php echo fpt_t('CO₂ produit (recyclage)','CO₂ produced (recycling)'); ?></div>
            </div>
        </div>
        <?php
        // Bilan net
        $bilan_net = round($total_co2 - $co2_produit_total, 2);
        ?>
        <div style="background:#e6f5ee;border:1.5px solid #1a7a4a;border-radius:10px;padding:14px 20px;margin:-8px 0 24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
            <span style="font-size:14px;font-weight:600;color:#0f1c13">
                ⚖️ <?php echo fpt_t('Bilan net CO₂','Net CO₂ balance'); ?>
            </span>
            <span style="font-family:monospace;font-size:20px;font-weight:700;color:#1a7a4a">
                <?php echo esc_html( number_format($bilan_net, 2) ); ?> t CO₂
            </span>
            <span style="font-size:12px;color:#6b8070">
                <?php echo fpt_t('évité − produit','avoided − produced'); ?>
            </span>
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

    // Recalculer le CO₂ transport de tous les lots collectés (correction bug)
    if ( isset( $_POST['fpt_recalc_transport'] ) && check_admin_referer('fpt_recalc') ) {
        $collected = get_posts([
            'post_type'   => 'hp_listing',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [['key' => '_fpt_collected', 'value' => '1']],
        ]);
        foreach ( $collected as $id ) {
            $poids_kg    = fpt_get_poids_kg($id);
            $co2_mat     = (float) get_post_meta($id, '_fpt_co2_avoided', true);
            $co2_trans   = fpt_calculate_transport_co2($poids_kg);
            $co2_total   = round($co2_mat + $co2_trans, 6);
            update_post_meta($id, '_fpt_co2_transport', $co2_trans);
            update_post_meta($id, '_fpt_co2_total',     $co2_total);
        }
        // Recalculer les stats de chaque acheteur
        $acheteurs = fpt_get_acheteurs();
        foreach ($acheteurs as $a) {
            $lots_ach = get_posts([
                'post_type'   => 'hp_listing',
                'post_status' => 'publish',
                'numberposts' => -1,
                'fields'      => 'ids',
                'meta_query'  => [
                    ['key' => '_fpt_collected',   'value' => '1'],
                    ['key' => '_fpt_acheteur_id', 'value' => $a->ID],
                ],
            ]);
            $total_co2 = $total_poids = 0;
            foreach ($lots_ach as $lid) {
                $total_co2   += (float) get_post_meta($lid, '_fpt_co2_total', true);
                $total_poids += fpt_get_poids_kg($lid);
            }
            update_post_meta($a->ID, '_fpt_buyer_co2_total',   round($total_co2, 6));
            update_post_meta($a->ID, '_fpt_buyer_poids_total', $total_poids);
            update_post_meta($a->ID, '_fpt_buyer_lots_count',  count($lots_ach));
        }
        echo '<div class="notice notice-success"><p>✅ CO₂ transport recalculé correctement pour ' . count($collected) . ' lots collectés.</p></div>';
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
        update_option( 'fpt_acheteurs_cat_slug', sanitize_key( $_POST['fpt_acheteurs_cat_slug'] ) );
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
                    <th><label for="fpt_acheteurs_cat_slug">Acheteurs réguliers — Category slug</label></th>
                    <td>
                        <input type="text" id="fpt_acheteurs_cat_slug" name="fpt_acheteurs_cat_slug" value="<?php echo esc_attr( get_option('fpt_acheteurs_cat_slug','acheteurs') ); ?>" class="regular-text" placeholder="acheteurs">
                        <p class="description">🇫🇷 acheteurs &nbsp;|&nbsp; 🇬🇧 buyers</p>
                    </td>
                </tr>
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
            <li><code>[fpt_methodologie]</code> — Page méthodologie complète</li>
            <li><code>[fpt_acheteur id="XXX"]</code> — Dashboard d'un acheteur régulier (remplacer XXX par l'ID du post acheteur)</li>
        </ul>

        <h2>🔄 Recalculer les stats</h2>
        <form method="post">
            <?php wp_nonce_field('fpt_recalc'); ?>
            <p>Utile si vous avez importé des annonces existantes.</p>
            <button type="submit" name="fpt_recalculate" class="button button-primary">Recalculer CO₂ depuis zéro</button>
        </form>

        <h2 style="margin-top:20px">🚛 Corriger le CO₂ transport</h2>
        <form method="post">
            <?php wp_nonce_field('fpt_recalc'); ?>
            <p style="color:#c0392b"><strong>⚠️ À exécuter une fois</strong> si le CO₂ transport était erroné (bug avant v1.6.1).<br>
            Formule correcte : (Poids kg ÷ 1000) × <?php echo fpt_transport_distance_km(); ?> km × 0,062 = t CO₂</p>
            <p><strong>Exemple vérification :</strong> 30 kg × 150 km → 0,030 t × 150 × 0,062 = <strong>0,000279 t CO₂</strong></p>
            <button type="submit" name="fpt_recalc_transport" class="button button-secondary" style="color:#c0392b;border-color:#c0392b">
                🔧 Recalculer CO₂ transport (correction bug)
            </button>
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


// ══════════════════════════════════════════════════════════════════════════════
// SYSTÈME PARTENAIRES — Affiliate tracking
// ══════════════════════════════════════════════════════════════════════════════

// ─── 1. Capture du ?ref= à l'arrivée du visiteur ─────────────────────────────
add_action( 'init', 'fpt_capture_ref' );
function fpt_capture_ref() {
    if ( empty( $_GET['ref'] ) ) return;
    $ref = sanitize_key( $_GET['ref'] );

    // Vérifier que ce ref correspond à un partenaire actif
    $partenaires = fpt_get_partenaires_list();
    $slugs = array_column( $partenaires, 'slug' );
    if ( ! in_array( $ref, $slugs, true ) ) return;

    // Compter le clic uniquement si c'est une nouvelle session pour ce partenaire
    // (évite de compter chaque page visitée — on compte l'arrivée depuis le lien ?ref=)
    $cookie_actuel = isset( $_COOKIE['fpt_ref'] ) ? $_COOKIE['fpt_ref'] : '';
    if ( $cookie_actuel !== $ref ) {
        // Nouveau visiteur ou visiteur d'un autre partenaire — incrémenter
        $key   = 'fpt_clicks_' . $ref;
        $total = (int) get_option( $key, 0 );
        update_option( $key, $total + 1, false ); // autoload=false
    }

    // Sauvegarder dans un cookie 30 jours
    if ( ! headers_sent() ) {
        setcookie( 'fpt_ref', $ref, time() + ( 30 * DAY_IN_SECONDS ), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
    }
    $_COOKIE['fpt_ref'] = $ref;
}

// ─── 2. Attacher le partenaire au lot à la publication ───────────────────────
add_action( 'save_post_hp_listing', 'fpt_attach_ref_to_listing', 25, 3 );
function fpt_attach_ref_to_listing( $post_id, $post, $update ) {
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( $post->post_status !== 'publish' ) return;

    // Ne pas écraser si déjà défini
    $existing = get_post_meta( $post_id, '_fpt_ref', true );
    if ( $existing ) return;

    $ref = isset( $_COOKIE['fpt_ref'] ) ? sanitize_key( $_COOKIE['fpt_ref'] ) : '';
    if ( ! $ref ) return;

    // Vérifier que le partenaire est toujours actif
    $partenaires = fpt_get_partenaires_list();
    $slugs = array_column( $partenaires, 'slug' );
    if ( ! in_array( $ref, $slugs, true ) ) return;

    update_post_meta( $post_id, '_fpt_ref', $ref );
}

// ─── 3. Badge sur l'annonce publique ─────────────────────────────────────────
// Injecté via fpt_inject_on_listing() déjà existant — on ajoute un filtre
add_filter( 'fpt_after_inline_block', 'fpt_render_partner_badge', 10, 1 );
function fpt_render_partner_badge( $post_id ) {
    $ref = get_post_meta( $post_id, '_fpt_ref', true );
    if ( ! $ref ) return '';

    $partenaire = fpt_get_partenaire_by_slug( $ref );
    if ( ! $partenaire ) return '';

    $nom    = esc_html( $partenaire['nom'] );
    $couleur = esc_attr( $partenaire['couleur'] );
    $logo   = ! empty( $partenaire['logo_url'] ) ? $partenaire['logo_url'] : '';

    $logo_html = $logo
        ? '<img src="' . esc_url( $logo ) . '" alt="' . $nom . '" style="height:16px;vertical-align:middle;margin-right:6px;border-radius:2px">'
        : '';

    return '<div class="fpt-partner-badge" style="
        display:inline-flex;align-items:center;gap:6px;
        margin-top:10px;padding:6px 12px;
        background:' . $couleur . '18;
        border:1.5px solid ' . $couleur . ';
        border-radius:20px;font-family:var(--fpt-sans,sans-serif);
        font-size:12px;font-weight:600;color:' . $couleur . ';
    ">' . $logo_html . '⭐ ' . fpt_t('Recommandé par','Recommended by') . ' ' . $nom . '</div>';
}

// Badge intégré directement dans fpt_inject_on_listing — voir ci-dessous

// ─── 4. Données des partenaires ───────────────────────────────────────────────
function fpt_get_partenaires_list() {
    $raw = get_option( 'fpt_partenaires', [] );
    if ( ! is_array( $raw ) ) return [];
    return $raw;
}

function fpt_get_partenaire_by_slug( $slug ) {
    foreach ( fpt_get_partenaires_list() as $p ) {
        if ( isset( $p['slug'] ) && $p['slug'] === $slug ) return $p;
    }
    return null;
}

// ─── 5. Stats partenaire — nombre de lots, kg, CO₂ ───────────────────────────
function fpt_get_stats_partenaire( $slug ) {
    $lots = get_posts([
        'post_type'      => 'hp_listing',
        'post_status'    => 'publish',
        'numberposts'    => -1,
        'fields'         => 'ids',
        'meta_query'     => [[ 'key' => '_fpt_ref', 'value' => sanitize_key($slug) ]],
    ]);

    $total_lots      = count( $lots );
    $total_poids     = 0;
    $total_co2       = 0;
    $total_collected = 0;

    foreach ( $lots as $id ) {
        $total_poids     += fpt_get_poids_kg( $id );
        $total_co2       += (float) get_post_meta( $id, '_fpt_co2_avoided', true );
        if ( get_post_meta( $id, '_fpt_collected', true ) == '1' ) $total_collected++;
    }

    $clicks     = (int) get_option( 'fpt_clicks_' . sanitize_key($slug), 0 );
    $conversion = $clicks > 0 ? round( ( $total_lots / $clicks ) * 100, 1 ) : 0;

    return [
        'lots'       => $total_lots,
        'collected'  => $total_collected,
        'poids_kg'   => round( $total_poids, 1 ),
        'co2_t'      => round( $total_co2, 4 ),
        'clicks'     => $clicks,
        'conversion' => $conversion, // % lots publiés / clics
        'ids'        => $lots,
    ];
}

// ─── 6. Sous-menu admin "Partenaires" ─────────────────────────────────────────
add_action( 'admin_menu', 'fpt_admin_menu_partenaires' );
function fpt_admin_menu_partenaires() {
    add_submenu_page(
        'feraypro-tracer',
        'Partenaires',
        '🤝 Partenaires',
        'manage_options',
        'feraypro-partenaires',
        'fpt_admin_page_partenaires'
    );
}

function fpt_admin_page_partenaires() {
    if ( ! current_user_can('manage_options') ) wp_die('Accès refusé');

    // ── Sauvegarde ajout/modif partenaire ──
    if ( isset( $_POST['fpt_save_partenaire'] ) && check_admin_referer('fpt_partenaire_save') ) {
        $slug    = sanitize_key( $_POST['fpt_p_slug'] );
        $nom     = sanitize_text_field( $_POST['fpt_p_nom'] );
        $couleur = sanitize_hex_color( $_POST['fpt_p_couleur'] ) ?: '#1a7a4a';
        $logo    = esc_url_raw( $_POST['fpt_p_logo'] );
        $actif   = isset( $_POST['fpt_p_actif'] ) ? 1 : 0;
        $commission = floatval( $_POST['fpt_p_commission'] );

        if ( $slug && $nom ) {
            $liste = fpt_get_partenaires_list();
            // Mettre à jour si slug existe déjà, sinon ajouter
            $found = false;
            foreach ( $liste as &$p ) {
                if ( $p['slug'] === $slug ) {
                    $p = compact('slug','nom','couleur','logo','actif','commission');
                    $found = true; break;
                }
            }
            unset($p);
            if ( ! $found ) {
                $liste[] = compact('slug','nom','couleur','logo','actif','commission');
            }
            update_option( 'fpt_partenaires', $liste );
            echo '<div class="notice notice-success"><p>✅ Partenaire "' . esc_html($nom) . '" sauvegardé.</p></div>';
        }
    }

    // ── Suppression partenaire ──
    if ( isset( $_GET['fpt_delete_ref'] ) && check_admin_referer('fpt_delete_ref') ) {
        $slug  = sanitize_key( $_GET['fpt_delete_ref'] );
        $liste = array_filter( fpt_get_partenaires_list(), fn($p) => $p['slug'] !== $slug );
        update_option( 'fpt_partenaires', array_values($liste) );
        echo '<div class="notice notice-success"><p>Partenaire supprimé.</p></div>';
    }

    $partenaires = fpt_get_partenaires_list();
    ?>
    <div class="wrap">
        <h1>🤝 Partenaires FerayPro</h1>
        <p style="color:#6b8070">
            Chaque partenaire dispose d'un lien de suivi :
            <code><?php echo esc_url( home_url('/') ); ?><strong>?ref=slug-partenaire</strong></code><br>
            Quand un visiteur arrive via ce lien et publie une annonce, le lot lui est automatiquement attribué (cookie 30 jours).
        </p>

        <?php if ( ! empty($partenaires) ): ?>
        <!-- ── Tableau des stats ── -->
        <h2>📊 Stats par partenaire</h2>
        <table class="widefat striped" style="max-width:980px;margin-bottom:30px">
            <thead>
                <tr>
                    <th>Partenaire</th>
                    <th>Slug / Lien</th>
                    <th style="text-align:center">Clics</th>
                    <th style="text-align:center">Lots publiés</th>
                    <th style="text-align:center">Conversion</th>
                    <th style="text-align:center">Collectés</th>
                    <th style="text-align:right">Poids (kg)</th>
                    <th style="text-align:right">CO₂ évité (t)</th>
                    <th style="text-align:right">Commission %</th>
                    <th style="text-align:center">Statut</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $partenaires as $p ):
                $stats   = fpt_get_stats_partenaire( $p['slug'] );
                $couleur = esc_attr( $p['couleur'] ?? '#1a7a4a' );
                $actif   = ! empty( $p['actif'] );
                $lien    = add_query_arg( 'ref', $p['slug'], home_url('/') );

                // Couleur du taux de conversion
                $conv    = $stats['conversion'];
                $conv_color = $conv >= 10 ? '#1a7a4a' : ( $conv >= 3 ? '#e67e22' : '#c0392b' );
                ?>
                <tr>
                    <td>
                        <span style="display:inline-flex;align-items:center;gap:8px">
                            <span style="width:12px;height:12px;border-radius:50%;background:<?php echo $couleur; ?>;display:inline-block;flex-shrink:0"></span>
                            <?php if ( ! empty($p['logo_url']) ): ?>
                                <img src="<?php echo esc_url($p['logo_url']); ?>" style="height:20px;object-fit:contain">
                            <?php endif; ?>
                            <strong><?php echo esc_html($p['nom']); ?></strong>
                        </span>
                    </td>
                    <td>
                        <code style="font-size:11px"><?php echo esc_html($p['slug']); ?></code><br>
                        <a href="<?php echo esc_url($lien); ?>" target="_blank" style="font-size:11px">🔗 <?php echo esc_html($lien); ?></a>
                    </td>
                    <td style="text-align:center"><?php echo number_format($stats['clicks'], 0, ',', ' '); ?></td>
                    <td style="text-align:center"><strong><?php echo $stats['lots']; ?></strong></td>
                    <td style="text-align:center;font-weight:700;color:<?php echo $conv_color; ?>">
                        <?php echo $stats['clicks'] > 0 ? $conv . '%' : '—'; ?>
                    </td>
                    <td style="text-align:center"><?php echo $stats['collected']; ?></td>
                    <td style="text-align:right"><?php echo number_format($stats['poids_kg'], 1, ',', ' '); ?> kg</td>
                    <td style="text-align:right;color:#1a7a4a"><strong><?php echo number_format($stats['co2_t'], 3, ',', ' '); ?> t</strong></td>
                    <td style="text-align:right"><?php echo number_format($p['commission'] ?? 0, 1); ?>%</td>
                    <td style="text-align:center">
                        <?php if ($actif): ?>
                            <span style="color:#1a7a4a;font-weight:600">✅ Actif</span>
                        <?php else: ?>
                            <span style="color:#c0392b">⏸ Inactif</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="#form-<?php echo esc_attr($p['slug']); ?>"
                           onclick="fptFillForm(<?php echo esc_attr(json_encode($p)); ?>)"
                           class="button button-small">✏️ Modifier</a>
                        &nbsp;
                        <a href="<?php echo wp_nonce_url( add_query_arg(['page'=>'feraypro-partenaires','fpt_delete_ref'=>$p['slug']], admin_url('admin.php')), 'fpt_delete_ref' ); ?>"
                           class="button button-small"
                           style="color:#c0392b;border-color:#c0392b"
                           onclick="return confirm('Supprimer <?php echo esc_js($p['nom']); ?> ?')">🗑 Supprimer</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- ── Formulaire ajout / modification ── -->
        <h2 id="form-new">➕ Ajouter / Modifier un partenaire</h2>
        <form method="post" style="max-width:600px;background:#fff;border:1px solid #d0ddd4;padding:24px;border-radius:8px" id="fpt-partner-form">
            <?php wp_nonce_field('fpt_partenaire_save'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="fpt_p_nom">Nom affiché</label></th>
                    <td><input type="text" id="fpt_p_nom" name="fpt_p_nom" class="regular-text" placeholder="Avito Maroc" required></td>
                </tr>
                <tr>
                    <th><label for="fpt_p_slug">Slug (URL)</label></th>
                    <td>
                        <input type="text" id="fpt_p_slug" name="fpt_p_slug" class="regular-text" placeholder="avito" pattern="[a-z0-9\-]+" required>
                        <p class="description">Minuscules, chiffres et tirets uniquement. Ex: <code>avito</code>, <code>leboncoin</code>, <code>jumia</code></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="fpt_p_couleur">Couleur du badge</label></th>
                    <td>
                        <input type="color" id="fpt_p_couleur" name="fpt_p_couleur" value="#1a7a4a" style="height:36px;width:60px;cursor:pointer">
                        <span style="font-size:12px;color:#6b8070;margin-left:8px">Couleur de la marque partenaire</span>
                    </td>
                </tr>
                <tr>
                    <th><label>Logo</label></th>
                    <td>
                        <!-- Champ caché qui stocke l'URL finale -->
                        <input type="hidden" id="fpt_p_logo" name="fpt_p_logo" value="">

                        <!-- Prévisualisation -->
                        <div id="fpt-logo-preview" style="margin-bottom:10px;min-height:40px">
                            <img id="fpt-logo-img" src="" alt="" style="height:40px;object-fit:contain;display:none;border:1px solid #d0ddd4;border-radius:4px;padding:4px;background:#f9f9f9">
                        </div>

                        <button type="button" id="fpt-logo-upload-btn" class="button">
                            📁 Choisir depuis la médiathèque
                        </button>
                        <button type="button" id="fpt-logo-remove-btn" class="button" style="margin-left:6px;display:none;color:#c0392b;border-color:#c0392b">
                            ✕ Supprimer le logo
                        </button>
                        <p class="description" style="margin-top:6px">Optionnel — PNG ou SVG, hauteur recommandée 32px</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="fpt_p_commission">Commission (%)</label></th>
                    <td>
                        <input type="number" id="fpt_p_commission" name="fpt_p_commission" value="0" min="0" max="100" step="0.1" style="width:80px">
                        <span style="font-size:12px;color:#6b8070;margin-left:8px">% de commission sur chaque lot collecté (info seulement)</span>
                    </td>
                </tr>
                <tr>
                    <th>Statut</th>
                    <td>
                        <label>
                            <input type="checkbox" id="fpt_p_actif" name="fpt_p_actif" value="1" checked>
                            Partenaire actif (les ?ref= de ce partenaire sont capturés)
                        </label>
                    </td>
                </tr>
            </table>

            <p style="margin-top:16px">
                <button type="submit" name="fpt_save_partenaire" class="button button-primary" style="background:#1a7a4a;border-color:#1a7a4a">
                    💾 Sauvegarder le partenaire
                </button>
                <button type="button" class="button" onclick="fptResetForm()" style="margin-left:8px">✕ Annuler</button>
            </p>
        </form>

        <script>
        // ── Médiathèque WordPress ──────────────────────────────────────────────
        var fptMediaFrame;
        document.getElementById('fpt-logo-upload-btn').addEventListener('click', function(e) {
            e.preventDefault();
            if ( fptMediaFrame ) { fptMediaFrame.open(); return; }
            fptMediaFrame = wp.media({
                title:    'Choisir le logo du partenaire',
                button:   { text: 'Utiliser ce logo' },
                multiple: false,
                library:  { type: [ 'image' ] }
            });
            fptMediaFrame.on('select', function() {
                var attachment = fptMediaFrame.state().get('selection').first().toJSON();
                fptSetLogo( attachment.url );
            });
            fptMediaFrame.open();
        });

        document.getElementById('fpt-logo-remove-btn').addEventListener('click', function() {
            fptSetLogo('');
        });

        function fptSetLogo( url ) {
            document.getElementById('fpt_p_logo').value = url;
            var img = document.getElementById('fpt-logo-img');
            var removeBtn = document.getElementById('fpt-logo-remove-btn');
            if ( url ) {
                img.src = url;
                img.style.display = 'inline-block';
                removeBtn.style.display = 'inline-block';
            } else {
                img.src = '';
                img.style.display = 'none';
                removeBtn.style.display = 'none';
            }
        }

        // ── Remplir le formulaire depuis le bouton Modifier ───────────────────
        function fptFillForm(p) {
            document.getElementById('fpt_p_nom').value        = p.nom        || '';
            document.getElementById('fpt_p_slug').value       = p.slug       || '';
            document.getElementById('fpt_p_couleur').value    = p.couleur    || '#1a7a4a';
            document.getElementById('fpt_p_commission').value = p.commission || 0;
            document.getElementById('fpt_p_actif').checked    = p.actif == 1;
            fptSetLogo( p.logo_url || '' );
            // Scroll vers le formulaire
            var form = document.getElementById('fpt-partner-form');
            form.id  = 'form-' + p.slug;
            form.scrollIntoView({ behavior: 'smooth' });
        }

        function fptResetForm() {
            document.getElementById('fpt_p_nom').value        = '';
            document.getElementById('fpt_p_slug').value       = '';
            document.getElementById('fpt_p_couleur').value    = '#1a7a4a';
            document.getElementById('fpt_p_commission').value = 0;
            document.getElementById('fpt_p_actif').checked    = true;
            fptSetLogo('');
            document.getElementById('fpt-partner-form').id = 'form-new';
        }
        </script>
    </div>
    <?php
    // Charger la médiathèque WP sur cette page admin
    wp_enqueue_media();
}

// ─── 7. Shortcode [fpt_partenaires] — page publique optionnelle ───────────────
add_shortcode( 'fpt_partenaires', 'fpt_shortcode_partenaires' );
function fpt_shortcode_partenaires( $atts ) {
    $atts = shortcode_atts( [ 'show_co2' => 'yes' ], $atts );
    $partenaires = array_filter( fpt_get_partenaires_list(), fn($p) => ! empty($p['actif']) );
    if ( empty($partenaires) ) return '';

    ob_start();
    echo '<div class="fpt-partenaires-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin:24px 0">';
    foreach ( $partenaires as $p ) {
        $stats   = fpt_get_stats_partenaire( $p['slug'] );
        $couleur = esc_attr( $p['couleur'] ?? '#1a7a4a' );
        echo '<div style="background:#fff;border:2px solid ' . $couleur . ';border-radius:12px;padding:20px;text-align:center">';
        if ( ! empty($p['logo_url']) ) {
            echo '<img src="' . esc_url($p['logo_url']) . '" style="height:32px;object-fit:contain;margin-bottom:10px"><br>';
        }
        echo '<strong style="color:' . $couleur . ';font-size:15px">' . esc_html($p['nom']) . '</strong><br>';
        echo '<span style="font-size:13px;color:#6b8070;margin-top:6px;display:block">';
        echo $stats['lots'] . ' ' . fpt_t('lots recommandés','recommended batches');
        echo '</span>';
        if ( $atts['show_co2'] === 'yes' ) {
            echo '<span style="font-size:12px;color:#1a7a4a;font-weight:600;display:block;margin-top:4px">';
            echo number_format( $stats['co2_t'] * 1000, 0, ',', ' ') . ' kg CO₂ évité';
            echo '</span>';
        }
        echo '</div>';
    }
    echo '</div>';
    return ob_get_clean();
}
