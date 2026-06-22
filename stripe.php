<?php
/**
 * FerayPro Tracer — Module : Paiement Stripe
 * Fichier   : modules/stripe/stripe.php
 *
 * Fonctionnalités :
 *  — Crée une Stripe Checkout Session pour la commission (20%) d'un lot collecté
 *  — Ajoute un bouton "💳 Payer en ligne" dans la facture PDF existante
 *  — Webhook Stripe → confirme automatiquement _fpt_commission_paid = 'paid'
 *  — Paramètres Stripe (clés API, webhook secret) dans le panneau admin existant
 *  — Compatible multi-pays : MAD / EUR / USD / CDF (Stripe ne supporte pas tous,
 *    fallback EUR si devise non supportée)
 *  — Compatible avec le split partenaire (10/10/80 ou 20/80)
 *
 * Dépendances : feraypro-tracer.php chargé avant ce fichier.
 *               Fonctions requises : fpt_get_prix_lot(), fpt_get_currency(),
 *               fpt_invoice_token(), fpt_invoice_number(), fpt_get_lot_partenaire()
 *
 * Installation :
 *  1. Copier ce fichier dans feraypro-tracer/modules/stripe/stripe.php
 *  2. Ajouter dans feraypro-tracer.php, après la ligne finance :
 *         require_once FPT_PLUGIN_DIR . 'modules/stripe/stripe.php';
 *  3. Dans les réglages FP Tracer → renseigner les clés Stripe
 *  4. Configurer le webhook Stripe → URL fournie dans les réglages
 *
 * Version : 2.3.0 — FerayPro Tracer v2.3.0+
 * Licence : MIT
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ══════════════════════════════════════════════════════════════════════════════
// CONSTANTES & HELPERS
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Devises supportées par Stripe (sous-ensemble pertinent pour FerayPro).
 * MAD et CDF ne sont PAS supportées par Stripe → fallback EUR.
 */
function fpt_stripe_supported_currencies() {
    return ['EUR', 'USD', 'GBP', 'CAD', 'AUD', 'CHF', 'SEK', 'NOK', 'DKK', 'JPY', 'BRL'];
}

/**
 * Convertit la devise du site en devise Stripe valide.
 * Si non supportée (MAD, CDF…) → retourne 'EUR' + note de conversion.
 */
function fpt_stripe_currency( $currency ) {
    $supported = fpt_stripe_supported_currencies();
    $cur       = strtoupper( $currency );
    if ( in_array( $cur, $supported, true ) ) return $cur;
    return 'EUR'; // fallback
}

/**
 * Retourne la clé Stripe active (live ou test selon le mode configuré).
 */
function fpt_stripe_secret_key() {
    $mode = get_option( 'fpt_stripe_mode', 'test' );
    return $mode === 'live'
        ? get_option( 'fpt_stripe_live_secret', '' )
        : get_option( 'fpt_stripe_test_secret', '' );
}

function fpt_stripe_public_key() {
    $mode = get_option( 'fpt_stripe_mode', 'test' );
    return $mode === 'live'
        ? get_option( 'fpt_stripe_live_public', '' )
        : get_option( 'fpt_stripe_test_public', '' );
}

function fpt_stripe_webhook_secret() {
    return get_option( 'fpt_stripe_webhook_secret', '' );
}

/**
 * Vérifie que le module Stripe est correctement configuré.
 */
function fpt_stripe_is_configured() {
    $secret = fpt_stripe_secret_key();
    $public = fpt_stripe_public_key();
    // Valider que les clés ne sont pas inversées
    if ( str_starts_with( $secret, 'pk_' ) ) return false;
    if ( str_starts_with( $public, 'sk_' ) ) return false;
    return ! empty( $secret ) && ! empty( $public );
}

/**
 * URL du webhook à communiquer à Stripe Dashboard.
 */
function fpt_stripe_webhook_url() {
    return add_query_arg( 'fpt_stripe_webhook', '1', home_url( '/' ) );
}

// ══════════════════════════════════════════════════════════════════════════════
// 1. SAUVEGARDE DES RÉGLAGES (hook sur fpt_save_settings via admin_post)
// ══════════════════════════════════════════════════════════════════════════════

add_action( 'admin_init', 'fpt_stripe_save_settings_hook' );
function fpt_stripe_save_settings_hook() {
    if ( ! isset( $_POST['fpt_save_settings'] ) ) return;
    if ( ! current_user_can( 'manage_options' ) )   return;
    if ( ! check_admin_referer( 'fpt_settings' ) )  return;

    $fields = [
        'fpt_stripe_mode'            => 'test',
        'fpt_stripe_test_public'     => '',
        'fpt_stripe_test_secret'     => '',
        'fpt_stripe_live_public'     => '',
        'fpt_stripe_live_secret'     => '',
        'fpt_stripe_webhook_secret'  => '',
    ];

    foreach ( $fields as $key => $default ) {
        if ( ! isset( $_POST[ $key ] ) ) continue;

        if ( $key === 'fpt_stripe_mode' ) {
            // Mode : valider enum
            update_option( $key, in_array( $_POST[$key], ['test','live'] ) ? $_POST[$key] : 'test' );
        } else {
            // Clés API et webhook secret : ne remplacer que si la valeur soumise est non vide
            // (empêche l'effacement accidentel par un "Enregistrer" sans retoucher les champs)
            $val = sanitize_text_field( $_POST[$key] );
            if ( $val !== '' ) {
                update_option( $key, $val );
            }
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// 2. INJECTION DU BLOC STRIPE DANS LE PANNEAU ADMIN FP TRACER
//    Hook sur l'action personnalisée que l'on va ajouter dans feraypro-tracer.php
//    (voir README d'intégration en bas de ce fichier)
// ══════════════════════════════════════════════════════════════════════════════

add_action( 'fpt_admin_settings_extra_cards', 'fpt_stripe_admin_card' );
function fpt_stripe_admin_card() {
    $mode          = get_option( 'fpt_stripe_mode', 'test' );
    $test_pub      = get_option( 'fpt_stripe_test_public', '' );
    $test_sec      = get_option( 'fpt_stripe_test_secret', '' );
    $live_pub      = get_option( 'fpt_stripe_live_public', '' );
    $live_sec      = get_option( 'fpt_stripe_live_secret', '' );
    $whsec         = get_option( 'fpt_stripe_webhook_secret', '' );
    $webhook_url   = fpt_stripe_webhook_url();
    $configured    = fpt_stripe_is_configured();

    // Masquage partiel des clés secrètes (jamais émises en clair dans le HTML)
    $mask = fn( $k ) => $k ? substr( $k, 0, 12 ) . str_repeat( '•', 16 ) : '';
    $test_sec_hint = $mask( $test_sec );
    $live_sec_hint = $mask( $live_sec );
    $whsec_hint    = $mask( $whsec );

    // Détecter clés inversées
    $keys_inverted = ( str_starts_with($test_sec, 'pk_') || str_starts_with($test_pub, 'sk_')
                    || str_starts_with($live_sec, 'pk_') || str_starts_with($live_pub, 'sk_') );
    ?>
    <div class="fpt-adm-card">
        <div class="fpt-adm-card-head">
            <span class="fpt-adm-card-icon">💳</span>
            <div>
                <h2 class="fpt-adm-card-title">Stripe — Paiement en ligne</h2>
                <p class="fpt-adm-card-desc">
                    Permet à l'acheteur de payer la commission (20%) directement depuis la facture.
                    <?php if ( $keys_inverted ) : ?>
                        <strong style="color:#dc2626">🔴 Clés inversées — la clé secrète contient une clé publique (pk_...) ou vice versa. Corrigez ci-dessous.</strong>
                    <?php elseif ( $configured ) : ?>
                        <strong style="color:#1a7a4a">✅ Configuré (mode <?php echo esc_html( strtoupper($mode) ); ?>)</strong>
                    <?php else : ?>
                        <strong style="color:#e67e22">⚠️ Non configuré — paiement manuel uniquement</strong>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <div class="fpt-adm-card-body">

            <!-- Mode test / live -->
            <div class="fpt-adm-field">
                <label for="fpt_stripe_mode">Mode</label>
                <select id="fpt_stripe_mode" name="fpt_stripe_mode">
                    <option value="test" <?php selected( $mode, 'test' ); ?>>🧪 Test (Sandbox)</option>
                    <option value="live" <?php selected( $mode, 'live' ); ?>>🚀 Live (Production)</option>
                </select>
                <span class="fpt-adm-hint">Utiliser "Test" pendant le développement. Basculer en "Live" uniquement en production.</span>
            </div>

            <!-- Clés TEST -->
            <div class="fpt-adm-field">
                <label>Clé publique TEST</label>
                <input type="text" name="fpt_stripe_test_public"
                       value="<?php echo esc_attr( $test_pub ); ?>"
                       placeholder="pk_test_...">
            </div>
            <div class="fpt-adm-field">
                <label>Clé secrète TEST</label>
                <input type="password" name="fpt_stripe_test_secret"
                       placeholder="<?php echo $test_sec_hint ? esc_attr( $test_sec_hint ) : 'sk_test_...'; ?>"
                       autocomplete="off">
                <span class="fpt-adm-hint">
                    <?php if ( $test_sec_hint ) : ?>Clé actuelle : <code><?php echo esc_html( $test_sec_hint ); ?></code> — laisser vide pour ne pas la modifier.<br><?php endif; ?>
                    Ne jamais exposer cette clé côté client.
                </span>
            </div>

            <!-- Clés LIVE -->
            <div class="fpt-adm-field">
                <label>Clé publique LIVE</label>
                <input type="text" name="fpt_stripe_live_public"
                       value="<?php echo esc_attr( $live_pub ); ?>"
                       placeholder="pk_live_...">
            </div>
            <div class="fpt-adm-field">
                <label>Clé secrète LIVE</label>
                <input type="password" name="fpt_stripe_live_secret"
                       placeholder="<?php echo $live_sec_hint ? esc_attr( $live_sec_hint ) : 'sk_live_...'; ?>"
                       autocomplete="off">
                <span class="fpt-adm-hint">
                    <?php if ( $live_sec_hint ) : ?>Clé actuelle : <code><?php echo esc_html( $live_sec_hint ); ?></code> — laisser vide pour ne pas la modifier.<br><?php endif; ?>
                    Ne jamais exposer cette clé côté client.
                </span>
            </div>

            <!-- Webhook -->
            <div class="fpt-adm-field">
                <label>Webhook Secret</label>
                <input type="password" name="fpt_stripe_webhook_secret"
                       placeholder="<?php echo $whsec_hint ? esc_attr( $whsec_hint ) : 'whsec_...'; ?>"
                       autocomplete="off">
                <span class="fpt-adm-hint">
                    <?php if ( $whsec_hint ) : ?>Secret actuel : <code><?php echo esc_html( $whsec_hint ); ?></code> — laisser vide pour ne pas le modifier.<br><?php endif; ?>
                    Obtenu dans Stripe Dashboard → Developers → Webhooks → Signing secret.
                    <?php if ( ! $whsec ) : ?><strong style="color:#c0392b"> ⚠️ Requis — les webhooks sont rejetés tant qu'aucun secret n'est configuré.</strong><?php endif; ?>
                </span>
            </div>

            <!-- URL du webhook à copier -->
            <div class="fpt-adm-field">
                <label>URL du webhook (à copier dans Stripe)</label>
                <div style="display:flex;gap:8px;align-items:center">
                    <input type="text" readonly
                           value="<?php echo esc_attr( $webhook_url ); ?>"
                           style="flex:1;background:#f4f6f4;font-family:monospace;font-size:11px"
                           onclick="this.select()">
                    <button type="button"
                            onclick="navigator.clipboard.writeText('<?php echo esc_js($webhook_url); ?>');this.textContent='✅ Copié'"
                            style="padding:6px 10px;background:#1a7a4a;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:12px;white-space:nowrap">
                        📋 Copier
                    </button>
                </div>
                <span class="fpt-adm-hint">
                    Dans Stripe Dashboard : Developers → Webhooks → Add endpoint.
                    Événement à écouter : <strong>checkout.session.completed</strong>
                </span>
            </div>

        </div>
    </div>
    <?php
}

// ══════════════════════════════════════════════════════════════════════════════
// 3. CRÉATION DE LA CHECKOUT SESSION (AJAX)
// ══════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_fpt_stripe_checkout',        'fpt_stripe_create_checkout' );
add_action( 'wp_ajax_nopriv_fpt_stripe_checkout', 'fpt_stripe_create_checkout' );

function fpt_stripe_create_checkout() {
    // ── Validation entrées ────────────────────────────────────────────────────
    $lot_id = intval( $_POST['lot_id'] ?? 0 );
    $token  = sanitize_text_field( $_POST['fpt_tok'] ?? '' );

    if ( ! $lot_id || $token !== fpt_invoice_token( $lot_id ) ) {
        wp_send_json_error( ['message' => 'Token invalide.'], 403 );
    }

    if ( ! fpt_stripe_is_configured() ) {
        wp_send_json_error( ['message' => 'Stripe non configuré.'], 500 );
    }

    $prix = fpt_get_prix_lot( $lot_id );
    if ( $prix <= 0 ) {
        wp_send_json_error( ['message' => 'Prix du lot non renseigné.'], 400 );
    }

    // Vérifier si déjà payé
    if ( get_post_meta( $lot_id, '_fpt_commission_paid', true ) === 'paid' ) {
        wp_send_json_error( ['message' => 'Commission déjà marquée comme payée.'], 400 );
    }

    // ── Calcul commission ─────────────────────────────────────────────────────
    $tva_rate    = floatval( get_option( 'fpt_tva_rate', 0 ) );
    $comm20      = round( $prix * 0.20, 2 );
    $tva_montant = $tva_rate > 0 ? round( $comm20 * $tva_rate / 100, 2 ) : 0;
    $comm20_ttc  = $comm20 + $tva_montant;

    // ── Devise Stripe ─────────────────────────────────────────────────────────
    $currency_raw   = fpt_get_currency();
    $currency_stripe = fpt_stripe_currency( $currency_raw );

    // Stripe travaille en centimes (unités entières) sauf JPY
    $zero_decimal = in_array( strtoupper($currency_stripe), ['JPY','KRW','VND'], true );
    $amount       = $zero_decimal
        ? intval( $comm20_ttc )
        : intval( round( $comm20_ttc * 100 ) );

    // ── Description ───────────────────────────────────────────────────────────
    $titre      = get_the_title( $lot_id );
    $inv        = fpt_invoice_number( $lot_id );
    $site       = get_option( 'fpt_site_name', get_bloginfo('name') );
    $partenaire = fpt_get_lot_partenaire( $lot_id );

    $description = sprintf(
        'Commission %s — Lot %s : %s (20%% de %s %s%s)',
        $site,
        $inv,
        $titre,
        number_format( $prix, 2 ),
        $currency_raw,
        $tva_rate > 0 ? ' + TVA ' . $tva_rate . '%' : ''
    );
    if ( $partenaire ) {
        $description .= ' | Partenaire : ' . $partenaire['nom'];
    }

    // ── URLs success / cancel ─────────────────────────────────────────────────
    $base_url    = add_query_arg( [
        'action'  => 'fpt_facture',
        'fpt_lot' => $lot_id,
        'fpt_tok' => substr( md5( $lot_id . AUTH_KEY ), 0, 12 ),
    ], admin_url('admin-ajax.php') );

    $success_url = add_query_arg( 'fpt_stripe_paid', '1', $base_url );
    $cancel_url  = add_query_arg( 'fpt_stripe_cancel', '1', $base_url );

    // ── Appel API Stripe ──────────────────────────────────────────────────────
    $secret_key = fpt_stripe_secret_key();

    $body = [
        'mode'                       => 'payment',
        'payment_method_types[]'     => 'card',
        'line_items[0][price_data][currency]'                  => strtolower( $currency_stripe ),
        'line_items[0][price_data][product_data][name]'        => $site . ' — Commission lot',
        'line_items[0][price_data][product_data][description]' => $description,
        'line_items[0][price_data][unit_amount]'               => $amount,
        'line_items[0][quantity]'                              => 1,
        'success_url'                => $success_url . '&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'                 => $cancel_url,
        'metadata[lot_id]'           => $lot_id,
        'metadata[invoice]'          => $inv,
        'metadata[site]'             => $site,
    ];

    // Ajouter email acheteur si disponible
    $acheteur_id = get_post_meta( $lot_id, '_fpt_acheteur_id', true );
    if ( $acheteur_id ) {
        $email = get_post_meta( $acheteur_id, '_fpt_acheteur_email', true )
               ?: get_post_meta( $acheteur_id, 'hp_email', true );
        if ( $email ) $body['customer_email'] = sanitize_email( $email );
    }

    $response = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $secret_key,
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ],
        'body'    => $body,
        'timeout' => 20,
    ]);

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( ['message' => 'Erreur réseau : ' . $response->get_error_message()], 500 );
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( empty( $data['url'] ) ) {
        $err = $data['error']['message'] ?? 'Erreur Stripe inconnue.';
        wp_send_json_error( ['message' => $err], 500 );
    }

    // Stocker l'ID de session pour pouvoir la vérifier côté webhook
    update_post_meta( $lot_id, '_fpt_stripe_session_id', sanitize_text_field( $data['id'] ) );

    wp_send_json_success( ['url' => $data['url']] );
}

// ══════════════════════════════════════════════════════════════════════════════
// 4. WEBHOOK STRIPE → CONFIRMATION AUTOMATIQUE DU PAIEMENT
// ══════════════════════════════════════════════════════════════════════════════

add_action( 'init', 'fpt_stripe_register_webhook_route' );
function fpt_stripe_register_webhook_route() {
    if ( isset( $_GET['fpt_stripe_webhook'] ) ) {
        add_action( 'wp', 'fpt_stripe_handle_webhook' );
    }
}

function fpt_stripe_handle_webhook() {
    // Stripe envoie un POST — ignorer toute autre méthode
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        http_response_code( 405 );
        exit( 'Method Not Allowed' );
    }

    $payload    = @file_get_contents( 'php://input' );
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $whsec      = fpt_stripe_webhook_secret();

    // ── Vérification signature Stripe (obligatoire — fail-closed) ─────────────
    // Tant qu'aucun secret n'est configuré, AUCUN webhook n'est accepté : un secret
    // vide ne doit jamais signifier "faire confiance par défaut", qui permettrait
    // à n'importe qui de POSTer un faux événement et de marquer un lot "payé".
    if ( ! $whsec ) {
        http_response_code( 503 );
        exit( 'Webhook secret not configured' );
    }
    $valid = fpt_stripe_verify_signature( $payload, $sig_header, $whsec );
    if ( ! $valid ) {
        http_response_code( 400 );
        exit( 'Invalid signature' );
    }

    $event = json_decode( $payload, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        http_response_code( 400 );
        exit( 'Invalid JSON' );
    }

    // ── Traiter uniquement checkout.session.completed ─────────────────────────
    if ( ( $event['type'] ?? '' ) !== 'checkout.session.completed' ) {
        http_response_code( 200 );
        exit( 'Ignored' );
    }

    $session    = $event['data']['object'] ?? [];
    $lot_id     = intval( $session['metadata']['lot_id'] ?? 0 );
    $session_id = sanitize_text_field( $session['id'] ?? '' );

    if ( ! $lot_id ) {
        http_response_code( 400 );
        exit( 'Missing lot_id in metadata' );
    }

    // Vérifier qu'une session Stripe a bien été créée pour ce lot, et qu'elle correspond
    $stored_session = get_post_meta( $lot_id, '_fpt_stripe_session_id', true );
    if ( ! $stored_session || $stored_session !== $session_id ) {
        http_response_code( 400 );
        exit( 'Session ID mismatch or no session on record for this lot' );
    }

    // ── Marquer comme payé ────────────────────────────────────────────────────
    update_post_meta( $lot_id, '_fpt_commission_paid',         'paid' );
    update_post_meta( $lot_id, '_fpt_commission_paid_date',    date_i18n('d/m/Y') );
    update_post_meta( $lot_id, '_fpt_stripe_payment_intent',   sanitize_text_field( $session['payment_intent'] ?? '' ) );
    update_post_meta( $lot_id, '_fpt_stripe_paid_at',          time() );

    // ── Log interne (optionnel, visible dans admin) ───────────────────────────
    $log = sprintf(
        '[%s] Stripe payment confirmed — lot #%d — session %s — intent %s',
        date('Y-m-d H:i:s'),
        $lot_id,
        $session_id,
        $session['payment_intent'] ?? 'n/a'
    );
    $existing_log = get_option('fpt_stripe_payment_log', '');
    update_option( 'fpt_stripe_payment_log', $existing_log . "\n" . $log );

    http_response_code( 200 );
    exit( 'OK' );
}

/**
 * Vérifie la signature Stripe sans dépendance externe.
 * Implémente l'algorithme officiel Stripe (v1 HMAC-SHA256).
 */
function fpt_stripe_verify_signature( $payload, $sig_header, $secret ) {
    if ( empty( $sig_header ) ) return false;

    $parts    = explode( ',', $sig_header );
    $timestamp = null;
    $signatures = [];

    foreach ( $parts as $part ) {
        $kv = explode( '=', $part, 2 );
        if ( count($kv) !== 2 ) continue;
        if ( $kv[0] === 't' )  $timestamp    = $kv[1];
        if ( $kv[0] === 'v1' ) $signatures[] = $kv[1];
    }

    if ( ! $timestamp || empty( $signatures ) ) return false;

    // Rejeter les webhooks de plus de 5 minutes (protection replay)
    if ( abs( time() - intval($timestamp) ) > 300 ) return false;

    $signed_payload = $timestamp . '.' . $payload;
    $expected       = hash_hmac( 'sha256', $signed_payload, $secret );

    foreach ( $signatures as $sig ) {
        if ( hash_equals( $expected, $sig ) ) return true;
    }

    return false;
}

// ══════════════════════════════════════════════════════════════════════════════
// 5. BOUTON "PAYER EN LIGNE" DANS LA FACTURE HTML
//    Injecté via le hook fpt_invoice_payment_methods (à ajouter dans la facture)
// ══════════════════════════════════════════════════════════════════════════════

add_action( 'fpt_invoice_payment_methods', 'fpt_stripe_invoice_button', 10, 2 );
function fpt_stripe_invoice_button( $lot_id, $comm20_ttc ) {
    if ( ! fpt_stripe_is_configured() ) return;

    $paid       = get_post_meta( $lot_id, '_fpt_commission_paid', true );
    $currency   = fpt_get_currency();
    $token      = fpt_invoice_token( $lot_id );
    $mode       = get_option( 'fpt_stripe_mode', 'test' );
    $pub_key    = fpt_stripe_public_key();

    // Détecter si on arrive depuis un paiement Stripe réussi
    $just_paid = isset( $_GET['fpt_stripe_paid'] ) && $_GET['fpt_stripe_paid'] === '1';

    if ( $paid === 'paid' || $just_paid ) : ?>
        <div style="background:#e6f5ee;border:1px solid #1a7a4a;border-radius:8px;padding:14px 18px;margin-top:16px;text-align:center">
            <strong style="color:#1a7a4a;font-size:15px">✅ Paiement confirmé</strong><br>
            <span style="color:#555;font-size:13px">
                Commission réglée le <?php echo esc_html( get_post_meta($lot_id,'_fpt_commission_paid_date',true) ?: date_i18n('d/m/Y') ); ?>
            </span>
        </div>
    <?php else : ?>

        <?php if ( $mode === 'test' ) : ?>
        <div style="background:#fff8e1;border:1px solid #f59e0b;border-radius:6px;padding:8px 12px;margin-top:12px;font-size:11px;color:#92400e">
            ⚠️ <strong>Mode TEST</strong> — Carte de test : 4242 4242 4242 4242 · Exp : 12/34 · CVC : 123
        </div>
        <?php endif; ?>

        <div id="fpt-stripe-wrap" style="margin-top:14px">
            <button id="fpt-stripe-btn"
                    onclick="fptStripeCheckout()"
                    style="display:block;width:100%;background:#635bff;color:#fff;border:none;
                           padding:14px;border-radius:8px;font-size:15px;font-weight:700;
                           cursor:pointer;letter-spacing:.3px;transition:background .2s">
                💳 Payer <?php echo number_format($comm20_ttc, 2) . ' ' . esc_html($currency); ?> en ligne
            </button>
            <p id="fpt-stripe-msg" style="text-align:center;font-size:12px;color:#888;margin-top:8px"></p>
        </div>

        <script>
        async function fptStripeCheckout() {
            const btn = document.getElementById('fpt-stripe-btn');
            const msg = document.getElementById('fpt-stripe-msg');
            btn.disabled   = true;
            btn.textContent = '⏳ Redirection vers le paiement sécurisé…';
            msg.textContent = '';

            try {
                const fd = new FormData();
                fd.append('action',  'fpt_stripe_checkout');
                fd.append('lot_id',  '<?php echo intval($lot_id); ?>');
                fd.append('fpt_tok', '<?php echo esc_js($token); ?>');

                const res  = await fetch('<?php echo esc_url( admin_url("admin-ajax.php") ); ?>', {
                    method: 'POST',
                    body:   fd,
                });
                const data = await res.json();

                if ( data.success && data.data.url ) {
                    window.location.href = data.data.url;
                } else {
                    msg.style.color  = '#dc2626';
                    msg.textContent  = '❌ ' + ( data.data?.message || 'Erreur inattendue.' );
                    btn.disabled     = false;
                    btn.textContent  = '💳 Réessayer le paiement';
                }
            } catch (e) {
                msg.style.color  = '#dc2626';
                msg.textContent  = '❌ Erreur réseau. Veuillez réessayer.';
                btn.disabled     = false;
                btn.textContent  = '💳 Réessayer le paiement';
            }
        }
        </script>
    <?php endif;
}

// ══════════════════════════════════════════════════════════════════════════════
// 6. MÉTADONNÉES SUPPLÉMENTAIRES (readme + admin notice log)
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Affiche les N dernières lignes du log Stripe dans la metabox admin du lot.
 * Hook sur fpt_metabox_after_commission (à ajouter dans feraypro-tracer.php).
 */
add_action( 'fpt_metabox_after_commission', 'fpt_stripe_metabox_status', 10, 1 );
function fpt_stripe_metabox_status( $post_id ) {
    if ( ! fpt_stripe_is_configured() ) {
        // Stripe non configuré — afficher un lien vers les réglages
        $settings_url = admin_url( 'admin.php?page=feraypro-tracer' );
        ?>
        <div style="margin-top:10px;background:#fff8f0;border:1px solid #e67e22;border-radius:6px;padding:9px 11px;font-size:11px;color:#7a4a1a">
            <strong>💳 Stripe</strong> — Non configuré<br>
            <a href="<?php echo esc_url($settings_url); ?>" style="color:#e67e22;font-weight:600">
                ⚙️ Configurer les clés API →
            </a>
        </div>
        <?php
        return;
    }

    $session_id  = get_post_meta( $post_id, '_fpt_stripe_session_id', true );
    $intent_id   = get_post_meta( $post_id, '_fpt_stripe_payment_intent', true );
    $paid_at     = get_post_meta( $post_id, '_fpt_stripe_paid_at', true );
    $comm_paid   = get_post_meta( $post_id, '_fpt_commission_paid', true );
    $mode        = get_option( 'fpt_stripe_mode', 'test' );

    // Construire le lien de la facture (lien de paiement à partager)
    $token        = fpt_invoice_token( $post_id );
    $facture_url  = add_query_arg([
        'action'  => 'fpt_facture',
        'fpt_lot' => $post_id,
        'fpt_tok' => $token,
    ], admin_url('admin-ajax.php'));

    // Lien Stripe Dashboard vers la session ou l'intent
    $stripe_base    = $mode === 'live' ? 'https://dashboard.stripe.com' : 'https://dashboard.stripe.com/test';
    $stripe_session_url = $session_id
        ? $stripe_base . '/payments/' . $session_id
        : $stripe_base . '/payments';
    $stripe_intent_url  = $intent_id
        ? $stripe_base . '/payments/' . $intent_id
        : null;

    // Statut visuel
    if ( $comm_paid === 'paid' && $paid_at ) {
        $status_bg     = '#e6f5ee';
        $status_border = '#1a7a4a';
        $status_color  = '#1a7a4a';
        $status_icon   = '✅';
        $status_label  = 'Paiement confirmé';
        $status_sub    = 'Le ' . date_i18n( 'd/m/Y à H:i', $paid_at );
    } elseif ( $session_id ) {
        $status_bg     = '#fffbea';
        $status_border = '#f59e0b';
        $status_color  = '#92400e';
        $status_icon   = '⏳';
        $status_label  = 'En attente de paiement';
        $status_sub    = 'Session créée — paiement non reçu';
    } else {
        $status_bg     = '#f0f4ff';
        $status_border = '#635bff';
        $status_color  = '#3730a3';
        $status_icon   = '💳';
        $status_label  = 'Prêt';
        $status_sub    = 'Le bouton de paiement est actif sur la facture';
    }
    ?>

    <div style="margin-top:10px;border:1.5px solid <?php echo $status_border; ?>;border-radius:7px;overflow:hidden;font-size:12px;font-family:sans-serif">

        <!-- En-tête statut -->
        <div style="background:<?php echo $status_bg; ?>;padding:8px 11px;display:flex;align-items:center;gap:7px;border-bottom:1px solid <?php echo $status_border; ?>">
            <span style="font-size:15px"><?php echo $status_icon; ?></span>
            <div>
                <strong style="color:<?php echo $status_color; ?>;font-size:12px"><?php echo esc_html($status_label); ?></strong><br>
                <span style="color:#666;font-size:10px"><?php echo esc_html($status_sub); ?></span>
            </div>
            <span style="margin-left:auto;font-size:9px;background:<?php echo $mode==='live'?'#1a7a4a':'#635bff'; ?>;color:#fff;padding:2px 6px;border-radius:3px;font-weight:700;text-transform:uppercase">
                <?php echo $mode === 'live' ? 'LIVE' : 'TEST'; ?>
            </span>
        </div>

        <!-- Corps -->
        <div style="padding:9px 11px;background:#fff;display:flex;flex-direction:column;gap:6px">

            <?php if ( $session_id ) : ?>
            <!-- Session ID -->
            <div style="display:flex;align-items:center;gap:5px">
                <span style="color:#888;font-size:10px;min-width:52px">Session</span>
                <code style="font-size:9px;background:#f4f4f8;padding:2px 5px;border-radius:3px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?php echo esc_html( $session_id ); ?>
                </code>
                <?php if ( $stripe_session_url ) : ?>
                <a href="<?php echo esc_url($stripe_session_url); ?>" target="_blank"
                   style="color:#635bff;font-size:10px;text-decoration:none;white-space:nowrap" title="Voir dans Stripe">↗</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ( $intent_id ) : ?>
            <!-- Payment Intent -->
            <div style="display:flex;align-items:center;gap:5px">
                <span style="color:#888;font-size:10px;min-width:52px">Intent</span>
                <code style="font-size:9px;background:#f4f4f8;padding:2px 5px;border-radius:3px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?php echo esc_html( $intent_id ); ?>
                </code>
                <?php if ( $stripe_intent_url ) : ?>
                <a href="<?php echo esc_url($stripe_intent_url); ?>" target="_blank"
                   style="color:#635bff;font-size:10px;text-decoration:none;white-space:nowrap" title="Voir dans Stripe">↗</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>



        </div>
    </div>
    <?php
}

/*
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
README D'INTÉGRATION — 3 lignes à ajouter dans feraypro-tracer.php
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

1. CHARGER LE MODULE (après la ligne finance.php) :
   ─────────────────────────────────────────────────
   require_once FPT_PLUGIN_DIR . 'modules/stripe/stripe.php';


2. INJECTER LES RÉGLAGES STRIPE dans le panneau admin.
   Dans fpt_admin_page(), juste après la carte "Facturation & Commission",
   ajouter :
   ─────────────────────────────────────────────────
   do_action( 'fpt_admin_settings_extra_cards' );


3. INJECTER LE BOUTON STRIPE dans la facture (fpt_afficher_facture()).
   Dans fpt_afficher_facture(), après le bloc "Mode de règlement" (la <div class="note">),
   ajouter :
   ─────────────────────────────────────────────────
   <?php do_action( 'fpt_invoice_payment_methods', $lot_id, $comm20_ttc ); ?>


4. INJECTER LE STATUT STRIPE dans la metabox admin.
   Dans fpt_collection_metabox_html(), après le bouton "Marquer comme payée",
   ajouter :
   ─────────────────────────────────────────────────
   <?php do_action( 'fpt_metabox_after_commission', $post->ID ); ?>


CONFIGURATION STRIPE DASHBOARD :
   ─────────────────────────────────────────────────
   1. stripe.com/dashboard → Developers → Webhooks → Add endpoint
   2. URL : [copiée depuis FP Tracer → Réglages → bloc Stripe]
   3. Événement : checkout.session.completed
   4. Copier le "Signing secret" (whsec_...) dans les réglages FP Tracer
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
*/
