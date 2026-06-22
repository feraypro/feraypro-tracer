<?php
/**
 * FerayPro Tracer — Module : Dashboard Financier
 * Fichier   : modules/finance/finance.php
 * Shortcode : [fpt_dashboard_finance period="30" lang=""]
 *
 * Indicateurs :
 *  — Ventes totales, commissions FerayPro (20%), commissions partenaires (10%)
 *  — Lots tracés / collectés / en attente / sans prix renseigné
 *  — Commissions impayées (_fpt_commission_paid ≠ 'paid')
 *  — Délai moyen de collecte (publication → collecte)
 *  — Pipeline visuel des lots
 *  — Graphique mensuel 12 mois (Chart.js)
 *  — Répartition des revenus (split dynamique FP/partenaires/vendeurs)
 *  — Détail lots impayés avec lien admin
 *  — Top partenaires marketing (commissions payées vs à payer)
 *  — Top 10 acheteurs (volume, CO₂)
 *
 * Dépendances : feraypro-tracer.php doit être chargé avant ce fichier.
 *               Fonctions requises : fpt_get_currency(), fpt_get_poids_kg(),
 *               fpt_get_partenaires_list(), fpt_get_partenaire_by_slug(), fpt_lang()
 *               Constantes : FPT_VERSION, FPT_PLUGIN_DIR, FPT_PLUGIN_URL
 *
 * Version : 2.3.0 — FerayPro Tracer v2.3.0+
 * Licence : MIT
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ══════════════════════════════════════════════════════════════════════════════
// 1. ENQUEUE CSS + JS
// ══════════════════════════════════════════════════════════════════════════════

add_action( 'wp_enqueue_scripts', 'fpt_finance_enqueue_assets' );
function fpt_finance_enqueue_assets() {
    // On n'enqueue que si le shortcode est présent sur la page courante
    global $post;
    if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'fpt_dashboard_finance' ) ) return;

    wp_enqueue_style(
        'fpt-finance',
        FPT_PLUGIN_URL . 'modules/finance/finance.css',
        [],
        FPT_VERSION
    );

    // Chart.js (CDN) pour les graphiques
    wp_enqueue_script(
        'chartjs',
        'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
        [],
        '4.4.1',
        true
    );
}

// ══════════════════════════════════════════════════════════════════════════════
// 2. HELPERS FINANCIERS
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Formater un montant avec la devise du site.
 */
function fptf_money( $amount, $currency = null, $decimals = 2 ) {
    if ( $currency === null ) $currency = function_exists('fpt_get_currency') ? fpt_get_currency() : 'MAD';
    return number_format( (float) $amount, $decimals, ',', ' ' ) . ' ' . esc_html( $currency );
}

/**
 * Récupérer le taux TVA configuré.
 */
function fptf_tva_rate() {
    return (float) get_option( 'fpt_tva_rate', 0 );
}

/**
 * Calculer la commission FerayPro TTC sur un prix donné.
 * Si un partenaire est référent : FP reçoit 10%, partenaire 10%.
 * Sinon : FP reçoit 20%.
 */
function fptf_split( $prix, $has_partner = false ) {
    $tva        = fptf_tva_rate();
    $fp_pct     = $has_partner ? 0.10 : 0.20;
    $pt_pct     = $has_partner ? 0.10 : 0.00;
    $fp_ht      = round( $prix * $fp_pct,  2 );
    $pt_ht      = round( $prix * $pt_pct,  2 );
    $vend       = round( $prix * 0.80,      2 );
    $tva_mont   = $tva > 0 ? round( $fp_ht * $tva / 100, 2 ) : 0;
    return [
        'fp_ht'    => $fp_ht,
        'fp_ttc'   => round( $fp_ht + $tva_mont, 2 ),
        'pt_ht'    => $pt_ht,
        'vendeur'  => $vend,
        'tva'      => $tva_mont,
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
// 3. COLLECTE DES DONNÉES
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Requête principale : récupère toutes les annonces hp_listing publiées.
 * Retourne un tableau de données financières agrégées.
 */
function fptf_get_all_data( $period_days = 30 ) {

    $currency   = function_exists('fpt_get_currency') ? fpt_get_currency() : 'MAD';
    $since_date = $period_days > 0 ? date( 'Y-m-d H:i:s', strtotime( "-{$period_days} days" ) ) : '';

    // ── Requête de base ──────────────────────────────────────────────────────
    $query_args = [
        'post_type'   => 'hp_listing',
        'post_status' => 'publish',
        'numberposts' => -1,
        'fields'      => 'ids',
    ];
    // Pour la période : on filtre sur la date de publication OU date de collecte
    // On récupère tout et on filtre en PHP pour plus de flexibilité
    $all_ids = get_posts( $query_args );

    // ── Initialisation des compteurs ─────────────────────────────────────────
    $stats = [
        'currency'         => $currency,
        'lots_total'       => 0,
        'lots_collected'   => 0,
        'lots_pending'     => 0,   // publiés, non collectés
        'lots_no_price'    => 0,   // collectés mais sans prix renseigné
        'lots_unpaid_comm' => 0,   // collectés + prix renseigné + commission non payée
        'lots_paid_comm'   => 0,   // commission payée
        'lots_archived'    => 0,   // posts trash/inactifs — à part

        'ventes_total'     => 0.0,
        'fp_commission_ht' => 0.0,
        'fp_commission_ttc'=> 0.0,
        'pt_commission'    => 0.0, // partenaires marketing
        'vendeurs_total'   => 0.0,
        'unpaid_amount'    => 0.0, // commissions FP non encaissées

        'delai_collecte_jours' => [], // tableau de délais pour calculer la moyenne
        'poids_total_kg'   => 0.0,
        'co2_total'        => 0.0,

        // Données par acheteur  [acheteur_id => [...]]
        'by_buyer'         => [],
        // Données par partenaire [slug => [...]]
        'by_partner'       => [],
        // Ventes par mois (12 derniers mois) [YYYY-MM => montant]
        'by_month'         => [],
        // Lots récents avec impayés
        'unpaid_lots'      => [],
    ];

    // Initialiser les 12 derniers mois
    for ( $i = 11; $i >= 0; $i-- ) {
        $key = date( 'Y-m', strtotime( "-{$i} months" ) );
        $stats['by_month'][ $key ] = [ 'ventes' => 0, 'fp' => 0, 'lots' => 0 ];
    }

    // ── Parcours de tous les lots ─────────────────────────────────────────────
    foreach ( $all_ids as $id ) {

        $poids_kg     = function_exists('fpt_get_poids_kg') ? fpt_get_poids_kg( $id ) : 0;
        // Filtrer les fiches acheteurs (poids = 0 ou absent)
        // Les acheteurs HivePress n'ont pas de champ poids → on les ignore ici
        // Note : si un lot légitime a poids=0, il n'est pas comptabilisé financièrement
        $is_buyer_listing = ( $poids_kg <= 0 );

        $collected      = get_post_meta( $id, '_fpt_collected',           true ) === '1';
        $prix_raw       = get_post_meta( $id, '_fpt_prix_lot',            true );
        $paid           = get_post_meta( $id, '_fpt_commission_paid',     true ) === 'paid';
        $paid_date      = get_post_meta( $id, '_fpt_commission_paid_date',true );
        $ref_slug       = get_post_meta( $id, '_fpt_ref',                 true );
        $acheteur_id    = (int) get_post_meta( $id, '_fpt_acheteur_id',   true );
        $collect_date   = get_post_meta( $id, '_fpt_collected_date',      true );
        $traced_at      = get_post_meta( $id, '_fpt_traced_at',           true );
        $co2            = (float) get_post_meta( $id, '_fpt_co2_avoided', true );
        $inv_num        = get_post_meta( $id, '_fpt_invoice_number',      true );
        $post_date      = get_post_field( 'post_date', $id );

        // Filtrer par période si demandé
        if ( $period_days > 0 && $since_date ) {
            $ref_date = $collected ? $collect_date : $post_date;
            if ( $ref_date && $ref_date < $since_date ) {
                // Ne pas compter pour la période, mais compter les lots archivés
                continue;
            }
        }

        if ( $is_buyer_listing ) continue; // ne pas inclure les fiches acheteurs

        $stats['lots_total']++;
        $stats['poids_total_kg'] += $poids_kg;
        $stats['co2_total']      += $co2;

        $prix = (float) preg_replace( '/[^\d.]/', '', str_replace( ',', '.', $prix_raw ) );

        if ( $collected ) {
            $stats['lots_collected']++;

            // Délai de collecte
            if ( $traced_at && $collect_date ) {
                $delai = (int) round( ( strtotime( $collect_date ) - strtotime( $traced_at ) ) / DAY_IN_SECONDS );
                if ( $delai >= 0 && $delai < 730 ) { // sanity check : < 2 ans
                    $stats['delai_collecte_jours'][] = $delai;
                }
            }

            $has_partner = ! empty( $ref_slug );
            $split       = fptf_split( $prix, $has_partner );

            if ( $prix > 0 ) {
                $stats['ventes_total']      += $prix;
                $stats['fp_commission_ht']  += $split['fp_ht'];
                $stats['fp_commission_ttc'] += $split['fp_ttc'];
                $stats['pt_commission']     += $split['pt_ht'];
                $stats['vendeurs_total']    += $split['vendeur'];

                // Données par mois (date de collecte)
                $month_key = $collect_date ? substr( $collect_date, 0, 7 ) : substr( $post_date, 0, 7 );
                if ( isset( $stats['by_month'][ $month_key ] ) ) {
                    $stats['by_month'][ $month_key ]['ventes'] += $prix;
                    $stats['by_month'][ $month_key ]['fp']     += $split['fp_ht'];
                    $stats['by_month'][ $month_key ]['lots']++;
                }

                if ( $paid ) {
                    $stats['lots_paid_comm']++;
                } else {
                    $stats['lots_unpaid_comm']++;
                    $stats['unpaid_amount'] += $split['fp_ttc'];

                    // Détail des lots impayés (max 10 pour l'affichage)
                    if ( count( $stats['unpaid_lots'] ) < 10 ) {
                        $stats['unpaid_lots'][] = [
                            'id'          => $id,
                            'title'       => get_the_title( $id ),
                            'prix'        => $prix,
                            'fp_ttc'      => $split['fp_ttc'],
                            'inv'         => $inv_num,
                            'collect_date'=> $collect_date,
                            'has_partner' => $has_partner,
                            'partner_slug'=> $ref_slug,
                            'acheteur_id' => $acheteur_id,
                        ];
                    }
                }
            } else {
                $stats['lots_no_price']++;
            }

            // Données par acheteur
            if ( $acheteur_id ) {
                if ( ! isset( $stats['by_buyer'][ $acheteur_id ] ) ) {
                    $stats['by_buyer'][ $acheteur_id ] = [
                        'name'      => get_the_title( $acheteur_id ),
                        'lots'      => 0,
                        'ventes'    => 0,
                        'fp_comm'   => 0,
                        'poids_kg'  => 0,
                        'co2'       => 0,
                    ];
                }
                $stats['by_buyer'][ $acheteur_id ]['lots']++;
                $stats['by_buyer'][ $acheteur_id ]['ventes']   += $prix;
                $stats['by_buyer'][ $acheteur_id ]['fp_comm']  += $split['fp_ht'];
                $stats['by_buyer'][ $acheteur_id ]['poids_kg'] += $poids_kg;
                $stats['by_buyer'][ $acheteur_id ]['co2']      += $co2;
            }

            // Données par partenaire
            if ( $has_partner ) {
                if ( ! isset( $stats['by_partner'][ $ref_slug ] ) ) {
                    $partenaire_data = function_exists('fpt_get_partenaire_by_slug') ? fpt_get_partenaire_by_slug( $ref_slug ) : [];
                    $stats['by_partner'][ $ref_slug ] = [
                        'name'     => $partenaire_data['nom'] ?? $ref_slug,
                        'couleur'  => $partenaire_data['couleur'] ?? '#1a7a4a',
                        'lots'     => 0,
                        'ventes'   => 0,
                        'comm'     => 0,    // commission 10% HT
                        'paid'     => 0,    // commissions payées
                        'unpaid'   => 0,
                    ];
                }
                $stats['by_partner'][ $ref_slug ]['lots']++;
                $stats['by_partner'][ $ref_slug ]['ventes'] += $prix;
                $stats['by_partner'][ $ref_slug ]['comm']   += $split['pt_ht'];
                if ( $paid ) {
                    $stats['by_partner'][ $ref_slug ]['paid'] += $split['pt_ht'];
                } else {
                    $stats['by_partner'][ $ref_slug ]['unpaid'] += $split['pt_ht'];
                }
            }

        } else {
            $stats['lots_pending']++;
        }
    } // fin foreach

    // ── Calculs dérivés ────────────────────────────────────────────────────────
    $stats['delai_moyen'] = ! empty( $stats['delai_collecte_jours'] )
        ? round( array_sum( $stats['delai_collecte_jours'] ) / count( $stats['delai_collecte_jours'] ) )
        : 0;

    $stats['taux_collecte'] = $stats['lots_total'] > 0
        ? round( $stats['lots_collected'] / $stats['lots_total'] * 100, 1 )
        : 0;

    // Trier acheteurs par ventes décroissantes
    uasort( $stats['by_buyer'], fn($a, $b) => $b['ventes'] <=> $a['ventes'] );
    // Trier partenaires par commissions décroissantes
    uasort( $stats['by_partner'], fn($a, $b) => $b['comm'] <=> $a['comm'] );

    return $stats;
}

// ══════════════════════════════════════════════════════════════════════════════
// 4. SHORTCODE PRINCIPAL
// ══════════════════════════════════════════════════════════════════════════════

add_shortcode( 'fpt_dashboard_finance', 'fpt_shortcode_dashboard_finance' );
function fpt_shortcode_dashboard_finance( $atts ) {
    $atts = shortcode_atts([
        'period' => '30',    // jours : 0 = tout, 7, 30, 90, 365
        'lang'   => '',      // '' = auto-détecté via fpt_lang()
    ], $atts );

    $period_days = max( 0, (int) $atts['period'] );
    $lang        = $atts['lang'] ?: ( function_exists('fpt_lang') ? fpt_lang() : 'fr' );
    $is_en       = ( $lang === 'en' );

    $d  = fptf_get_all_data( $period_days );
    $cur = $d['currency'];
    $tva = fptf_tva_rate();

    // Libellé de la période
    if ( $period_days === 0 ) {
        $period_label = $is_en ? 'All time' : 'Depuis le début';
    } elseif ( $period_days === 7 ) {
        $period_label = $is_en ? 'Last 7 days' : '7 derniers jours';
    } elseif ( $period_days === 30 ) {
        $period_label = $is_en ? 'Last 30 days' : '30 derniers jours';
    } elseif ( $period_days === 90 ) {
        $period_label = $is_en ? 'Last 3 months' : '3 derniers mois';
    } elseif ( $period_days === 365 ) {
        $period_label = $is_en ? 'This year' : 'Cette année';
    } else {
        $period_label = sprintf( $is_en ? 'Last %d days' : '%d derniers jours', $period_days );
    }

    $site_name = get_option( 'fpt_site_name', 'FerayPro' );

    // ── Données graphique mensuel (JSON pour JS) ────────────────────────────────
    $chart_labels  = [];
    $chart_ventes  = [];
    $chart_fp      = [];
    $mois_fr       = ['Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];
    $mois_en       = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    foreach ( $d['by_month'] as $ym => $mv ) {
        list( $y, $m ) = explode( '-', $ym );
        $label = ( $is_en ? $mois_en : $mois_fr )[ (int)$m - 1 ] . ' ' . substr($y, 2);
        $chart_labels[] = $label;
        $chart_ventes[] = round( $mv['ventes'], 2 );
        $chart_fp[]     = round( $mv['fp'], 2 );
    }

    $chart_json_labels  = json_encode( $chart_labels );
    $chart_json_ventes  = json_encode( $chart_ventes );
    $chart_json_fp      = json_encode( $chart_fp );

    // ── ID unique pour les canvas (plusieurs dashboards sur la même page) ───────
    static $fptf_instance = 0;
    $fptf_instance++;
    $uid = 'fptf' . $fptf_instance;

    // ── Rendu HTML ───────────────────────────────────────────────────────────────
    ob_start();
    ?>
    <div class="fptf-dashboard" id="<?php echo esc_attr($uid); ?>">

        <!-- ── EN-TÊTE ─────────────────────────────────────────────────────── -->
        <div class="fptf-header">
            <div class="fptf-header-left">
                <div class="fptf-logo-mark">FP</div>
                <div>
                    <h2 class="fptf-title">
                        <?php echo $is_en ? 'Finance Dashboard' : 'Dashboard Financier'; ?>
                        — <?php echo esc_html( $site_name ); ?>
                    </h2>
                    <p class="fptf-subtitle"><?php echo esc_html( $period_label ); ?> &middot; <?php echo esc_html( $cur ); ?><?php if ($tva > 0) echo ' &middot; TVA ' . $tva . '%'; ?></p>
                </div>
            </div>
            <?php if ( $d['lots_unpaid_comm'] > 0 ) : ?>
            <div class="fptf-alert-badge">
                ⚠ <?php echo $d['lots_unpaid_comm']; ?> <?php echo $is_en ? 'unpaid commissions' : 'commissions impayées'; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── ALERTE COMMISSIONS IMPAYÉES ───────────────────────────────── -->
        <?php if ( $d['lots_unpaid_comm'] > 0 ) : ?>
        <div class="fptf-alert">
            <span class="fptf-alert-icon">⚠️</span>
            <div>
                <strong><?php echo $d['lots_unpaid_comm']; ?> <?php echo $is_en ? 'lots with unpaid commissions' : 'lots avec commissions non perçues'; ?></strong>
                <span><?php echo $is_en ? 'Total outstanding:' : 'Montant en attente :'; ?> <strong><?php echo fptf_money( $d['unpaid_amount'], $cur ); ?></strong> <?php echo $is_en ? '(incl. TVA)' : '(TTC)'; ?></span>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── KPI GRID ─────────────────────────────────────────────────── -->
        <div class="fptf-kpi-grid">

            <div class="fptf-kpi fptf-kpi--dark">
                <span class="fptf-kpi-icon">📦</span>
                <span class="fptf-kpi-val"><?php echo number_format( $d['lots_total'] ); ?></span>
                <span class="fptf-kpi-lbl"><?php echo $is_en ? 'Batches traced' : 'Lots tracés'; ?></span>
                <span class="fptf-kpi-sub"><?php echo $d['taux_collecte']; ?>% <?php echo $is_en ? 'collection rate' : 'taux collecte'; ?></span>
            </div>

            <div class="fptf-kpi fptf-kpi--green">
                <span class="fptf-kpi-icon">✅</span>
                <span class="fptf-kpi-val"><?php echo number_format( $d['lots_collected'] ); ?></span>
                <span class="fptf-kpi-lbl"><?php echo $is_en ? 'Collected' : 'Lots collectés'; ?></span>
                <span class="fptf-kpi-sub"><?php echo $d['lots_pending']; ?> <?php echo $is_en ? 'pending' : 'en attente'; ?></span>
            </div>

            <div class="fptf-kpi">
                <span class="fptf-kpi-icon">💰</span>
                <span class="fptf-kpi-val"><?php echo fptf_money( $d['ventes_total'], $cur, 0 ); ?></span>
                <span class="fptf-kpi-lbl"><?php echo $is_en ? 'Total sales' : 'Ventes totales'; ?></span>
                <span class="fptf-kpi-sub"><?php echo $is_en ? 'excl. VAT' : 'HT'; ?></span>
            </div>

            <div class="fptf-kpi fptf-kpi--green">
                <span class="fptf-kpi-icon">🏢</span>
                <span class="fptf-kpi-val"><?php echo fptf_money( $tva > 0 ? $d['fp_commission_ttc'] : $d['fp_commission_ht'], $cur, 0 ); ?></span>
                <span class="fptf-kpi-lbl"><?php echo esc_html($site_name); ?> (20%)</span>
                <span class="fptf-kpi-sub"><?php echo $tva > 0 ? 'TTC' : 'HT (exonéré TVA)'; ?></span>
            </div>

            <div class="fptf-kpi">
                <span class="fptf-kpi-icon">🤝</span>
                <span class="fptf-kpi-val"><?php echo fptf_money( $d['pt_commission'], $cur, 0 ); ?></span>
                <span class="fptf-kpi-lbl"><?php echo $is_en ? 'Partner commissions' : 'Com. partenaires'; ?></span>
                <span class="fptf-kpi-sub"><?php echo count($d['by_partner']); ?> <?php echo $is_en ? 'active partners' : 'partenaires actifs'; ?></span>
            </div>

            <div class="fptf-kpi">
                <span class="fptf-kpi-icon">👤</span>
                <span class="fptf-kpi-val"><?php echo fptf_money( $d['vendeurs_total'], $cur, 0 ); ?></span>
                <span class="fptf-kpi-lbl"><?php echo $is_en ? 'Vendors (80%)' : 'Vendeurs (80%)'; ?></span>
                <span class="fptf-kpi-sub"><?php echo $d['lots_paid_comm']; ?> <?php echo $is_en ? 'paid invoices' : 'factures payées'; ?></span>
            </div>

            <?php if ( $d['lots_unpaid_comm'] > 0 ) : ?>
            <div class="fptf-kpi fptf-kpi--warn">
                <span class="fptf-kpi-icon">⏳</span>
                <span class="fptf-kpi-val"><?php echo fptf_money( $d['unpaid_amount'], $cur, 0 ); ?></span>
                <span class="fptf-kpi-lbl"><?php echo $is_en ? 'Outstanding commissions' : 'Commissions à percevoir'; ?></span>
                <span class="fptf-kpi-sub"><?php echo $d['lots_unpaid_comm']; ?> <?php echo $is_en ? 'lots' : 'lots'; ?></span>
            </div>
            <?php endif; ?>

            <?php if ( $d['lots_no_price'] > 0 ) : ?>
            <div class="fptf-kpi fptf-kpi--muted">
                <span class="fptf-kpi-icon">📋</span>
                <span class="fptf-kpi-val"><?php echo $d['lots_no_price']; ?></span>
                <span class="fptf-kpi-lbl"><?php echo $is_en ? 'Collected — no price' : 'Collectés sans prix'; ?></span>
                <span class="fptf-kpi-sub"><?php echo $is_en ? 'Invoice pending entry' : 'Facture à renseigner'; ?></span>
            </div>
            <?php endif; ?>

            <div class="fptf-kpi">
                <span class="fptf-kpi-icon">⏱</span>
                <span class="fptf-kpi-val"><?php echo $d['delai_moyen'] > 0 ? $d['delai_moyen'] . 'j' : '—'; ?></span>
                <span class="fptf-kpi-lbl"><?php echo $is_en ? 'Avg. collection time' : 'Délai moyen collecte'; ?></span>
                <span class="fptf-kpi-sub"><?php echo $is_en ? 'publication → collected' : 'publication → collecte'; ?></span>
            </div>

        </div>

        <!-- ── PIPELINE DES LOTS ─────────────────────────────────────────── -->
        <div class="fptf-section-title">
            <span><?php echo $is_en ? '📊 Batch pipeline' : '📊 Pipeline des lots'; ?></span>
        </div>
        <div class="fptf-pipeline">
            <?php
            $pipeline = [
                [ 'label' => $is_en ? 'Traced (published)' : 'Tracés (publiés)',    'count' => $d['lots_total'],       'color' => '#0f1c13', 'icon' => '📦' ],
                [ 'label' => $is_en ? 'Collected'          : 'Collectés',            'count' => $d['lots_collected'],   'color' => '#1a7a4a', 'icon' => '✅' ],
                [ 'label' => $is_en ? 'Awaiting collection': 'En attente collecte',  'count' => $d['lots_pending'],     'color' => '#e67e22', 'icon' => '⏳' ],
                [ 'label' => $is_en ? 'Comm. paid'         : 'Commission payée',     'count' => $d['lots_paid_comm'],   'color' => '#1a7a4a', 'icon' => '💳' ],
                [ 'label' => $is_en ? 'Comm. unpaid'       : 'Commission impayée',   'count' => $d['lots_unpaid_comm'], 'color' => '#c0392b', 'icon' => '🔴' ],
                [ 'label' => $is_en ? 'No price entered'   : 'Sans prix renseigné',  'count' => $d['lots_no_price'],    'color' => '#6b8070', 'icon' => '📋' ],
            ];
            foreach ( $pipeline as $p ) :
                if ( $p['count'] <= 0 ) continue;
                $pct = $d['lots_total'] > 0 ? round( $p['count'] / $d['lots_total'] * 100, 1 ) : 0;
            ?>
            <div class="fptf-pipeline-item">
                <span class="fptf-pipeline-icon"><?php echo $p['icon']; ?></span>
                <div class="fptf-pipeline-info">
                    <span class="fptf-pipeline-label"><?php echo esc_html( $p['label'] ); ?></span>
                    <div class="fptf-pipeline-bar-wrap">
                        <div class="fptf-pipeline-bar" style="width:<?php echo $pct; ?>%;background:<?php echo esc_attr($p['color']); ?>"></div>
                    </div>
                </div>
                <span class="fptf-pipeline-count" style="color:<?php echo esc_attr($p['color']); ?>"><?php echo $p['count']; ?></span>
                <span class="fptf-pipeline-pct"><?php echo $pct; ?>%</span>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ── GRAPHIQUE MENSUEL ──────────────────────────────────────────── -->
        <div class="fptf-section-title">
            <span><?php echo $is_en ? '📈 Monthly sales (12 months)' : '📈 Ventes mensuelles (12 mois)'; ?></span>
        </div>
        <div class="fptf-chart-card">
            <div class="fptf-chart-legend">
                <span><span class="fptf-dot" style="background:#1a7a4a"></span><?php echo $is_en ? 'Total sales' : 'Ventes totales'; ?></span>
                <span><span class="fptf-dot" style="background:#5dde8a"></span><?php echo $is_en ? 'FerayPro commission' : 'Commission FerayPro'; ?></span>
            </div>
            <div class="fptf-chart-wrap">
                <canvas id="<?php echo esc_attr($uid); ?>_chart"
                    role="img"
                    aria-label="<?php echo $is_en ? 'Monthly sales bar chart for the last 12 months' : 'Graphique ventes mensuelles sur 12 mois'; ?>">
                </canvas>
            </div>
        </div>

        <!-- ── RÉPARTITION REVENUS ───────────────────────────────────────── -->
        <?php if ( $d['ventes_total'] > 0 ) : ?>
        <div class="fptf-section-title">
            <span><?php echo $is_en ? '🥧 Revenue split' : '🥧 Répartition des revenus'; ?></span>
        </div>
        <div class="fptf-split-card">
            <?php
            // Les barres sont proportionnelles aux montants réels.
            // Vendeur = toujours 80% du prix de vente.
            // FP = 20% sans partenaire, 10% si partenaire (la somme FP+PT reste 20%).
            // On calcule le % réel de chaque ligne sur le total des ventes.
            $vt = $d['ventes_total'] ?: 1; // éviter division par zéro
            $fp_pct  = $vt > 0 ? round( $d['fp_commission_ht'] / $vt * 100, 1 ) : 0;
            $pt_pct  = $vt > 0 ? round( $d['pt_commission']    / $vt * 100, 1 ) : 0;
            $vd_pct  = 100 - $fp_pct - $pt_pct; // = 80% si pas de partenaires, entre 80-90% sinon

            // Libellés dynamiques selon présence de partenaires
            $has_partners = $d['pt_commission'] > 0;
            $fp_label  = esc_html($site_name) . ' (' . $fp_pct . '%)';
            $vd_label  = ( $is_en ? 'Vendors' : 'Vendeurs' ) . ' (80%)';

            $split_items = [
                [ 'label' => $fp_label,  'amount' => $d['fp_commission_ht'],  'pct' => $fp_pct, 'color' => '#1a7a4a' ],
            ];
            if ( $has_partners ) {
                $split_items[] = [ 'label' => ($is_en ? 'Marketing partners (' . $pt_pct . '%)' : 'Partenaires marketing (' . $pt_pct . '%)'), 'amount' => $d['pt_commission'], 'pct' => $pt_pct, 'color' => '#f0c040' ];
            }
            $split_items[] = [ 'label' => $vd_label, 'amount' => $d['vendeurs_total'], 'pct' => $vd_pct, 'color' => '#0f1c13' ];
            foreach ( $split_items as $si ) :
            ?>
            <div class="fptf-split-row">
                <span class="fptf-split-dot" style="background:<?php echo esc_attr($si['color']); ?>"></span>
                <span class="fptf-split-label"><?php echo $si['label']; ?></span>
                <div class="fptf-split-bar-wrap">
                    <div class="fptf-split-bar" style="width:<?php echo $si['pct']; ?>%;background:<?php echo esc_attr($si['color']); ?>"></div>
                </div>
                <span class="fptf-split-amount"><?php echo fptf_money( $si['amount'], $cur, 0 ); ?></span>
            </div>
            <?php endforeach; ?>
            <?php if ( $tva > 0 ) : ?>
            <div class="fptf-split-tva">
                TVA (<?php echo $tva; ?>%) sur commission FerayPro :
                <strong><?php echo fptf_money( $d['fp_commission_ttc'] - $d['fp_commission_ht'], $cur ); ?></strong>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ── LOTS IMPAYÉS (détail) ─────────────────────────────────────── -->
        <?php if ( ! empty( $d['unpaid_lots'] ) ) : ?>
        <div class="fptf-section-title">
            <span>🔴 <?php echo $is_en ? 'Unpaid commissions — detail' : 'Commissions impayées — détail'; ?></span>
        </div>
        <div class="fptf-table-wrap">
            <table class="fptf-table">
                <thead>
                    <tr>
                        <th><?php echo $is_en ? 'Batch' : 'Lot'; ?></th>
                        <th><?php echo $is_en ? 'Sale price' : 'Prix vente'; ?></th>
                        <th><?php echo $is_en ? 'Commission due' : 'Commission due'; ?></th>
                        <th><?php echo $is_en ? 'Invoice' : 'Facture'; ?></th>
                        <th><?php echo $is_en ? 'Collected on' : 'Collecté le'; ?></th>
                        <th><?php echo $is_en ? 'Partner' : 'Partenaire'; ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $d['unpaid_lots'] as $ul ) :
                    $edit_url = get_edit_post_link( $ul['id'] );
                ?>
                    <tr>
                        <td>
                            <?php if ( $edit_url && current_user_can('edit_posts') ) : ?>
                                <a href="<?php echo esc_url($edit_url); ?>" class="fptf-link"><?php echo esc_html( $ul['title'] ); ?></a>
                            <?php else : ?>
                                <?php echo esc_html( $ul['title'] ); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo fptf_money( $ul['prix'], $cur ); ?></td>
                        <td class="fptf-td-danger"><?php echo fptf_money( $ul['fp_ttc'], $cur ); ?></td>
                        <td><?php echo $ul['inv'] ? '<span class="fptf-mono">' . esc_html($ul['inv']) . '</span>' : '<span class="fptf-muted">—</span>'; ?></td>
                        <td><?php echo $ul['collect_date'] ? date_i18n( 'd/m/Y', strtotime($ul['collect_date']) ) : '—'; ?></td>
                        <td><?php echo $ul['has_partner'] ? '<span class="fptf-badge-partner">' . esc_html($ul['partner_slug']) . '</span>' : '<span class="fptf-muted">—</span>'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ( $d['lots_unpaid_comm'] > count($d['unpaid_lots']) ) : ?>
            <p class="fptf-table-more">
                <?php
                $more = $d['lots_unpaid_comm'] - count($d['unpaid_lots']);
                echo $is_en
                    ? "... and {$more} more. View all in WordPress admin → Listings."
                    : "... et {$more} de plus. Voir tous dans l'admin WordPress → Annonces.";
                ?>
            </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ── PARTENAIRES MARKETING ──────────────────────────────────────── -->
        <?php if ( ! empty( $d['by_partner'] ) ) : ?>
        <div class="fptf-section-title">
            <span>🤝 <?php echo $is_en ? 'Marketing partners' : 'Partenaires marketing'; ?></span>
        </div>
        <div class="fptf-table-wrap">
            <table class="fptf-table">
                <thead>
                    <tr>
                        <th><?php echo $is_en ? 'Partner' : 'Partenaire'; ?></th>
                        <th><?php echo $is_en ? 'Collected lots' : 'Lots collectés'; ?></th>
                        <th><?php echo $is_en ? 'Referred sales' : 'Ventes référées'; ?></th>
                        <th><?php echo $is_en ? 'Commission (10%)' : 'Commission (10%)'; ?></th>
                        <th><?php echo $is_en ? 'Paid' : 'Payée'; ?></th>
                        <th><?php echo $is_en ? 'Outstanding' : 'À payer'; ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $d['by_partner'] as $slug => $pt ) : ?>
                    <tr>
                        <td>
                            <span class="fptf-partner-dot" style="background:<?php echo esc_attr($pt['couleur']); ?>"></span>
                            <?php echo esc_html( $pt['name'] ); ?>
                        </td>
                        <td><?php echo $pt['lots']; ?></td>
                        <td><?php echo fptf_money( $pt['ventes'], $cur, 0 ); ?></td>
                        <td><?php echo fptf_money( $pt['comm'], $cur ); ?></td>
                        <td class="fptf-td-green"><?php echo fptf_money( $pt['paid'], $cur ); ?></td>
                        <td class="<?php echo $pt['unpaid'] > 0 ? 'fptf-td-danger' : ''; ?>">
                            <?php echo $pt['unpaid'] > 0 ? fptf_money( $pt['unpaid'], $cur ) : '<span class="fptf-muted">—</span>'; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ── TOP ACHETEURS ─────────────────────────────────────────────── -->
        <?php if ( ! empty( $d['by_buyer'] ) ) : ?>
        <div class="fptf-section-title">
            <span>🏭 <?php echo $is_en ? 'Top buyers' : 'Top acheteurs'; ?></span>
        </div>
        <div class="fptf-table-wrap">
            <table class="fptf-table">
                <thead>
                    <tr>
                        <th><?php echo $is_en ? 'Buyer' : 'Acheteur'; ?></th>
                        <th><?php echo $is_en ? 'Collected lots' : 'Lots collectés'; ?></th>
                        <th><?php echo $is_en ? 'Purchases' : 'Achats totaux'; ?></th>
                        <th><?php echo $is_en ? 'FP commission' : 'Com. FerayPro'; ?></th>
                        <th><?php echo $is_en ? 'Weight (kg)' : 'Poids (kg)'; ?></th>
                        <th><?php echo $is_en ? 'CO₂ avoided' : 'CO₂ évité'; ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php $rank = 0; foreach ( $d['by_buyer'] as $bid => $bv ) :
                    $rank++;
                    if ( $rank > 10 ) break; // top 10
                ?>
                    <tr>
                        <td>
                            <span class="fptf-rank"><?php echo $rank; ?></span>
                            <?php echo esc_html( $bv['name'] ?: ( $is_en ? 'Unknown buyer' : 'Acheteur inconnu' ) ); ?>
                        </td>
                        <td><?php echo $bv['lots']; ?></td>
                        <td><?php echo fptf_money( $bv['ventes'], $cur, 0 ); ?></td>
                        <td class="fptf-td-green"><?php echo fptf_money( $bv['fp_comm'], $cur ); ?></td>
                        <td><?php echo number_format( $bv['poids_kg'], 0, ',', ' ' ); ?> kg</td>
                        <td><?php echo number_format( $bv['co2'], 3, ',', ' ' ); ?> t</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ── PIED DE PAGE ──────────────────────────────────────────────── -->
        <p class="fptf-footer">
            <?php echo esc_html($site_name); ?> Tracer v<?php echo FPT_VERSION; ?> —
            <?php echo $is_en ? 'Finance data · ' : 'Données financières · '; ?>
            <?php echo esc_html($period_label); ?> —
            <a href="<?php echo esc_url( home_url('/impact') ); ?>" class="fptf-footer-link">
                <?php echo $is_en ? 'Environmental dashboard →' : 'Dashboard environnemental →'; ?>
            </a>
        </p>

    </div><!-- .fptf-dashboard -->

    <!-- ── JAVASCRIPT : Graphique Chart.js ──────────────────────────────────── -->
    <script>
    (function() {
        var labels  = <?php echo $chart_json_labels; ?>;
        var ventes  = <?php echo $chart_json_ventes; ?>;
        var fp      = <?php echo $chart_json_fp; ?>;
        var canvasId = '<?php echo esc_js($uid); ?>_chart';

        function initChart() {
            var canvas = document.getElementById(canvasId);
            if (!canvas || typeof Chart === 'undefined') {
                setTimeout(initChart, 300); return;
            }
            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: '<?php echo $is_en ? 'Total sales' : 'Ventes totales'; ?>',
                            data: ventes,
                            backgroundColor: 'rgba(26,122,74,0.18)',
                            borderColor: '#1a7a4a',
                            borderWidth: 1.5,
                            borderRadius: 4,
                        },
                        {
                            label: '<?php echo $is_en ? 'FP Commission' : 'Commission FP'; ?>',
                            data: fp,
                            backgroundColor: 'rgba(93,222,138,0.55)',
                            borderColor: '#5dde8a',
                            borderWidth: 1.5,
                            borderRadius: 4,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                        y: {
                            grid: { color: 'rgba(0,0,0,0.06)' },
                            ticks: {
                                font: { size: 10 },
                                callback: function(v) {
                                    if (v >= 1000000) return (v/1000000).toFixed(1) + 'M';
                                    if (v >= 1000)    return Math.round(v/1000) + 'k';
                                    return v;
                                }
                            }
                        }
                    }
                }
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initChart);
        } else {
            initChart();
        }
    })();
    </script>
    <?php
    return ob_get_clean();
}
