<?php
/**
 * Plugin Name: FerayPro Tracer
 * Plugin URI: https://ma.feraypro.com/impact
 * Description: Traçabilité des lots de déchets recyclés avec calcul CO₂ évité et génération de QR code. Module open source pour UNICEF Venture Fund.
 * Version: 2.0.0
 * Author: FerayPro
 * License: MIT
 * Text Domain: feraypro-tracer
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FPT_VERSION',    '2.0.0' );
define( 'FPT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FPT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ─── Chargement des modules ────────────────────────────────────────────────────
require_once FPT_PLUGIN_DIR . 'modules/finance/finance.php';
require_once FPT_PLUGIN_DIR . 'modules/stripe/stripe.php';

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
// fpt_language         = override manuel (rarement utilisé, admin reste FR)
// fpt_invoice_language = langue de la FACTURE envoyée au client (en/es/pt/fr)
//
// fpt_lang() : détecte automatiquement la langue du FRONT par domaine.
// Ordre de priorité : option WP → détection domaine → 'fr' par défaut.
// Admin WordPress : toujours français (is_admin() court-circuite tout).
function fpt_lang() {
    // L'admin reste toujours en français
    if ( is_admin() ) return 'fr';

    // Override manuel dans les settings (optionnel — laisser vide = auto)
    $opt = get_option('fpt_language', '');
    if ( $opt && $opt !== 'auto' ) return $opt;

    // Détection automatique par domaine
    $host = strtolower( $_SERVER['HTTP_HOST'] ?? '' );
    if ( strpos($host, '.us')          !== false ) return 'en';
    if ( strpos($host, 'feraypro.com') !== false && strpos($host, '.') === strpos($host, strtolower('feraypro.com')) ) return 'en'; // feraypro.com (USA, sans sous-domaine pays)
    if ( strpos($host, 'feraypro.com') !== false && ! preg_match('/\.(fr|ma|cd|sn)\./', $host) ) return 'en';
    if ( strpos($host, 'es.feraypro')  !== false ) return 'es';
    if ( strpos($host, 'pt.feraypro')  !== false ) return 'pt';
    if ( strpos($host, 'br.feraypro')  !== false ) return 'pt';
    if ( strpos($host, '.fr')          !== false ) return 'fr';
    if ( strpos($host, 'ma.feraypro')  !== false ) return 'fr';
    if ( strpos($host, '.cd')          !== false ) return 'fr';
    if ( strpos($host, '.sn')          !== false ) return 'fr';

    return 'fr'; // défaut
}

function fpt_invoice_lang() {
    return get_option( 'fpt_invoice_language', 'fr' ); // 'fr' | 'en' | 'es' | 'pt'
}

// Tableau centralisé de toutes les chaînes de l'interface.
// Clé = identifiant interne · chaque langue est une colonne.
function fpt_strings() {
    return [
        // ── Statuts lots ───────────────────────────────────────────────
        'collected'           => ['fr'=>'Collecté',                'en'=>'Collected',              'es'=>'Recogido',               'pt'=>'Recolhido'],
        'available'           => ['fr'=>'À collecter',             'en'=>'Available',               'es'=>'Disponible',             'pt'=>'Disponível'],
        'batch_collected'     => ['fr'=>'Lot collecté',            'en'=>'Batch collected',         'es'=>'Lote recogido',          'pt'=>'Lote recolhido'],
        'awaiting_collection' => ['fr'=>'En attente de collecte',  'en'=>'Awaiting collection',     'es'=>'A la espera de recogida','pt'=>'Aguardando coleta'],
        // ── Metabox collecte ───────────────────────────────────────────
        'confirm_collection'  => ['fr'=>'Confirmer la collecte',   'en'=>'Confirm Collection',      'es'=>'Confirmar recogida',     'pt'=>'Confirmar coleta'],
        'cancel_collection'   => ['fr'=>'Annuler la collecte',     'en'=>'Cancel collection',       'es'=>'Cancelar recogida',      'pt'=>'Cancelar coleta'],
        'confirm_cancel'      => ['fr'=>'Confirmer annulation',    'en'=>'Confirm cancellation',    'es'=>'Confirmar cancelación',  'pt'=>'Confirmar cancelamento'],
        'select_buyer'        => ['fr'=>'Sélectionner l\'acheteur qui a collecté ce lot :', 'en'=>'Select the buyer who collected this batch:', 'es'=>'Seleccionar el comprador que recogió este lote:', 'pt'=>'Selecionar o comprador que coletou este lote:'],
        'choose_buyer'        => ['fr'=>'— Choisir un acheteur —', 'en'=>'— Select a buyer —',      'es'=>'— Seleccionar comprador —','pt'=>'— Selecionar comprador —'],
        'weight_missing'      => ['fr'=>'⚠️ Poids non renseigné — impossible de confirmer.', 'en'=>'⚠️ Weight missing — cannot confirm.', 'es'=>'⚠️ Peso no indicado — no se puede confirmar.', 'pt'=>'⚠️ Peso não informado — impossível confirmar.'],
        // ── Preuve de pesée ────────────────────────────────────────────
        'weighing_proofs'     => ['fr'=>'Preuves de pesée',        'en'=>'Weighing proofs',         'es'=>'Pruebas de pesaje',      'pt'=>'Comprovantes de pesagem'],
        'proof_subtitle'      => ['fr'=>'photo, bon de bascule, PDF','en'=>'photo, weigh slip, PDF','es'=>'foto, albarán de pesaje, PDF','pt'=>'foto, nota de pesagem, PDF'],
        'delete'              => ['fr'=>'Supprimer',               'en'=>'Delete',                  'es'=>'Eliminar',               'pt'=>'Excluir'],
        'click_or_drag'       => ['fr'=>'Cliquer ou glisser un fichier','en'=>'Click or drag a file','es'=>'Haga clic o arrastre un archivo','pt'=>'Clique ou arraste um arquivo'],
        'file_too_large'      => ['fr'=>'Fichier trop lourd (max 8 Mo)','en'=>'File too large (max 8MB)','es'=>'Archivo demasiado grande (máx 8 MB)','pt'=>'Arquivo muito grande (máx 8 MB)'],
        'uploading'           => ['fr'=>'Envoi en cours...',       'en'=>'Uploading...',            'es'=>'Subiendo...',            'pt'=>'Enviando...'],
        'file_added'          => ['fr'=>'Fichier ajouté',          'en'=>'File added',              'es'=>'Archivo añadido',        'pt'=>'Arquivo adicionado'],
        'upload_error'        => ['fr'=>'Erreur upload',           'en'=>'Upload error',            'es'=>'Error al subir',         'pt'=>'Erro de upload'],
        'network_error'       => ['fr'=>'Erreur réseau',           'en'=>'Network error',           'es'=>'Error de red',           'pt'=>'Erro de rede'],
        'delete_proof'        => ['fr'=>'Supprimer cette preuve ?','en'=>'Delete this proof?',      'es'=>'¿Eliminar esta prueba?', 'pt'=>'Excluir este comprovante?'],
        'delete_error'        => ['fr'=>'Erreur suppression',      'en'=>'Delete error',            'es'=>'Error al eliminar',      'pt'=>'Erro ao excluir'],
        'file_type_error'     => ['fr'=>'Type de fichier non autorisé','en'=>'File type not allowed','es'=>'Tipo de archivo no permitido','pt'=>'Tipo de arquivo não permitido'],
        'file_too_large_s'    => ['fr'=>'Fichier trop lourd',      'en'=>'File too large',          'es'=>'Archivo demasiado grande','pt'=>'Arquivo muito grande'],
        // ── Dashboard & lots ───────────────────────────────────────────
        'buyer'               => ['fr'=>'Acheteur',                'en'=>'Buyer',                   'es'=>'Comprador',              'pt'=>'Comprador'],
        'date'                => ['fr'=>'Date',                    'en'=>'Date',                    'es'=>'Fecha',                  'pt'=>'Data'],
        'batches_traced'      => ['fr'=>'Lots tracés',             'en'=>'Batches traced',          'es'=>'Lotes trazados',         'pt'=>'Lotes rastreados'],
        'waste_to_recycle'    => ['fr'=>'Déchets à recycler',      'en'=>'Waste to recycle',        'es'=>'Residuos a reciclar',    'pt'=>'Resíduos a reciclar'],
        'co2_avoided'         => ['fr'=>'CO₂ évité (recyclage)',   'en'=>'CO₂ avoided (recycling)', 'es'=>'CO₂ evitado (reciclaje)','pt'=>'CO₂ evitado (reciclagem)'],
        'co2_produced'        => ['fr'=>'CO₂ produit (recyclage)', 'en'=>'CO₂ produced (recycling)','es'=>'CO₂ producido (reciclaje)','pt'=>'CO₂ produzido (reciclagem)'],
        'net_co2'             => ['fr'=>'',  'en'=>'',  'es'=>'',  'pt'=>''],  // supprimé v2.1.1
        'co2_avoided_short'   => ['fr'=>'évité',                   'en'=>'avoided',                 'es'=>'evitado',                'pt'=>'evitado'],
        'recycling'           => ['fr'=>'recyclage',               'en'=>'recycling',               'es'=>'reciclaje',              'pt'=>'reciclagem'],
        'weight_to_collect'   => ['fr'=>'Poids à collecter',       'en'=>'Weight to collect',       'es'=>'Peso a recoger',         'pt'=>'Peso a coletar'],
        'city'                => ['fr'=>'Ville',                   'en'=>'City',                    'es'=>'Ciudad',                 'pt'=>'Cidade'],
        'traced_on'           => ['fr'=>'Tracé le',                'en'=>'Traced on',               'es'=>'Trazado el',             'pt'=>'Rastreado em'],
        'scan_batch'          => ['fr'=>'Scanner ce lot',          'en'=>'Scan this batch',         'es'=>'Escanear este lote',     'pt'=>'Escanear este lote'],
        'calculation'         => ['fr'=>'Calcul',                  'en'=>'Calculation',             'es'=>'Cálculo',                'pt'=>'Cálculo'],
        'global_impact'       => ['fr'=>'Voir l\'impact global FerayPro','en'=>'View FerayPro global impact','es'=>'Ver impacto global FerayPro','pt'=>'Ver impacto global FerayPro'],
        'source_ademe'        => ['fr'=>'Source : ADEME Base Carbone · Open Source MIT','en'=>'Source: ADEME Base Carbone · Open Source MIT','es'=>'Fuente: ADEME Base Carbone · Open Source MIT','pt'=>'Fonte: ADEME Base Carbone · Open Source MIT'],
        'recent_batches'      => ['fr'=>'Derniers lots tracés',    'en'=>'Recently traced batches', 'es'=>'Últimos lotes trazados', 'pt'=>'Últimos lotes rastreados'],
        'kg_collected'        => ['fr'=>'kg collectés',            'en'=>'collected',               'es'=>'recogidos',              'pt'=>'coletados'],
        'unrecognized'        => ['fr'=>'non reconnu',             'en'=>'unrecognized',            'es'=>'no reconocido',          'pt'=>'não reconhecido'],
        'env_impact'          => ['fr'=>'Impact environnemental de ce lot','en'=>'Environmental impact of this batch','es'=>'Impacto ambiental de este lote','pt'=>'Impacto ambiental deste lote'],
        'env_impact_title'    => ['fr'=>'Impact Environnemental',  'en'=>'Environmental Impact',    'es'=>'Impacto Ambiental',      'pt'=>'Impacto Ambiental'],
        'recommended_by'      => ['fr'=>'Recommandé par',          'en'=>'Recommended by',          'es'=>'Recomendado por',        'pt'=>'Recomendado por'],
        'emission_factors'    => ['fr'=>'Facteurs d\'émission',    'en'=>'Emission factors',        'es'=>'Factores de emisión',    'pt'=>'Fatores de emissão'],
        // ── Acheteur dashboard ─────────────────────────────────────────
        'batches_collected'   => ['fr'=>'Lots collectés',          'en'=>'Batches collected',       'es'=>'Lotes recogidos',        'pt'=>'Lotes coletados'],
        'total_weight'        => ['fr'=>'Poids total',             'en'=>'Total weight',            'es'=>'Peso total',             'pt'=>'Peso total'],
        'co2_produced_recycl' => ['fr'=>'CO₂ produit (recyclage)', 'en'=>'CO₂ produced (recycling)','es'=>'CO₂ producido (reciclaje)','pt'=>'CO₂ produzido (reciclagem)'],
        'no_batches_yet'      => ['fr'=>'Aucun lot collecté pour l\'instant.','en'=>'No batches collected yet.','es'=>'Ningún lote recogido todavía.','pt'=>'Nenhum lote coletado ainda.'],
        'zones'               => ['fr'=>'Zones : ',                'en'=>'Zones: ',                 'es'=>'Zonas: ',                'pt'=>'Zonas: '],
        'vehicles'            => ['fr'=>'Véhicules : ',            'en'=>'Vehicles: ',              'es'=>'Vehículos: ',            'pt'=>'Veículos: '],
        'lots_collected_tab'  => ['fr'=>'Lots collectés',          'en'=>'Collected batches',       'es'=>'Lotes recogidos',        'pt'=>'Lotes coletados'],
        'recycling_short'     => ['fr'=>'recyclage',               'en'=>'recycling',               'es'=>'reciclaje',              'pt'=>'reciclagem'],
        // ── Dashboard header ───────────────────────────────────────────
        'dashboard_subtitle'  => ['fr'=>'Traçabilité en temps réel des déchets recyclés','en'=>'Real-time traceability of recycled waste','es'=>'Trazabilidad en tiempo real de residuos reciclados','pt'=>'Rastreabilidade em tempo real de resíduos reciclados'],
        'in_country_fr'       => ['fr'=>' au ',                    'en'=>' in ',                    'es'=>' en ',                   'pt'=>' em '],
        // ── Prix du jour ───────────────────────────────────────────────
        'prices_today'        => ['fr'=>'Prix du jour — ',         'en'=>'Today\'s Prices — ',      'es'=>'Precios de hoy — ',      'pt'=>'Preços de hoje — '],
        'updated_on'          => ['fr'=>'Mis à jour le',           'en'=>'Updated',                 'es'=>'Actualizado',            'pt'=>'Atualizado'],
        'all_prices'          => ['fr'=>'Voir tous les prix du marché →','en'=>'View all market prices →','es'=>'Ver todos los precios →','pt'=>'Ver todos os preços →'],
        'updated_by'          => ['fr'=>'Mis à jour par FerayPro', 'en'=>'Updated by FerayPro',     'es'=>'Actualizado por FerayPro','pt'=>'Atualizado por FerayPro'],
        // ── WhatsApp ───────────────────────────────────────────────────
        'contact_whatsapp'    => ['fr'=>'Contacter via WhatsApp',  'en'=>'Contact via WhatsApp',    'es'=>'Contactar vía WhatsApp', 'pt'=>'Contatar via WhatsApp'],
        // ── Partenaires ────────────────────────────────────────────────
        'lots_recommended'    => ['fr'=>'lots recommandés',        'en'=>'recommended batches',     'es'=>'lotes recomendados',     'pt'=>'lotes recomendados'],
        // ── Santé enfants ──────────────────────────────────────────────
        'pollution_indicators'=> ['fr'=>'Indicateurs de Réduction d\'Exposition aux Polluants','en'=>'Pollutant Exposure Risk Reduction Indicators','es'=>'Indicadores de Reducción de Exposición a Contaminantes','pt'=>'Indicadores de Redução de Exposição a Poluentes'],
        'lead_diverted'       => ['fr'=>'Plomb détourné (estimé)', 'en'=>'Lead diverted (est.)',    'es'=>'Plomo desviado (est.)',   'pt'=>'Chumbo desviado (est.)'],
        'lead_info'           => ['fr'=>'Réduction estimée du risque d\'exposition (OMS — aucun seuil sans effet)','en'=>'Estimated exposure risk reduction (WHO — no safe threshold)','es'=>'Reducción estimada del riesgo de exposición (OMS — sin umbral seguro)','pt'=>'Redução estimada do risco de exposição (OMS — sem limite seguro)'],
        'pm25_diverted'       => ['fr'=>'PM2.5 détournées (estimé)','en'=>'PM2.5 diverted (est.)', 'es'=>'PM2.5 desviadas (est.)',  'pt'=>'PM2.5 desviadas (est.)'],
        'pm25_info'           => ['fr'=>'Proxy de réduction d\'exposition respiratoire (brûlage câbles évité)','en'=>'Respiratory exposure risk proxy (avoided cable burning)','es'=>'Indicador de reducción de exposición respiratoria (quema de cables evitada)','pt'=>'Indicador de redução de exposição respiratória (queima de cabos evitada)'],
        'cadmium_diverted'    => ['fr'=>'Cadmium détourné (estimé)','en'=>'Cadmium diverted (est.)','es'=>'Cadmio desviado (est.)', 'pt'=>'Cádmio desviado (est.)'],
        'cadmium_info'        => ['fr'=>'Proxy de réduction du risque rénal (e-waste, piles)','en'=>'Renal risk reduction proxy (e-waste, batteries)','es'=>'Indicador de reducción del riesgo renal (e-waste, pilas)','pt'=>'Indicador de redução do risco renal (e-waste, pilhas)'],
        'mercury_diverted'    => ['fr'=>'Mercure détourné (estimé)','en'=>'Mercury diverted (est.)','es'=>'Mercurio desviado (est.)','pt'=>'Mercúrio desviado (est.)'],
        'mercury_info'        => ['fr'=>'Proxy de réduction du risque neurologique (écrans, lampes)','en'=>'Neurological risk reduction proxy (screens, lamps)','es'=>'Indicador de reducción del riesgo neurológico (pantallas, lámparas)','pt'=>'Indicador de redução do risco neurológico (telas, lâmpadas)'],
        'erri_label'          => ['fr'=>'Indice de réduction du risque d\'exposition','en'=>'Exposure Risk Reduction Index','es'=>'Índice de reducción del riesgo de exposición','pt'=>'Índice de redução do risco de exposição'],
        // ── Méthodologie ───────────────────────────────────────────────
        'methodology'         => ['fr'=>'Méthodologie de Calcul',  'en'=>'Calculation Methodology', 'es'=>'Metodología de cálculo', 'pt'=>'Metodologia de cálculo'],
        'input_data'          => ['fr'=>'📥 Données d\'entrée',    'en'=>'📥 Input Data',           'es'=>'📥 Datos de entrada',    'pt'=>'📥 Dados de entrada'],
        'field'               => ['fr'=>'Champ',                   'en'=>'Field',                   'es'=>'Campo',                  'pt'=>'Campo'],
        'role'                => ['fr'=>'Rôle',                    'en'=>'Role',                    'es'=>'Función',                'pt'=>'Função'],
        'listing_title_role'  => ['fr'=>'Identifie le type de déchet → sélectionne le facteur CO₂ et santé','en'=>'Identifies waste type → selects CO₂ and health factor','es'=>'Identifica el tipo de residuo → selecciona el factor CO₂ y salud','pt'=>'Identifica o tipo de resíduo → seleciona o fator CO₂ e saúde'],
        'weight_primary'      => ['fr'=>'Variable principale de tous les calculs','en'=>'Primary variable for all calculations','es'=>'Variable principal de todos los cálculos','pt'=>'Variável principal de todos os cálculos'],
        'city_geo'            => ['fr'=>'Géolocalisation de l\'impact','en'=>'Geolocation of impact','es'=>'Geolocalización del impacto','pt'=>'Geolocalização do impacto'],
        'co2_section'         => ['fr'=>'Section 1 — Impact CO₂',  'en'=>'Section 1 — CO₂ Impact',  'es'=>'Sección 1 — Impacto CO₂','pt'=>'Seção 1 — Impacto de CO₂'],
        'principle'           => ['fr'=>'Principe',                'en'=>'Principle',               'es'=>'Principio',              'pt'=>'Princípio'],
        'formula'             => ['fr'=>'Formule',                 'en'=>'Formula',                 'es'=>'Fórmula',                'pt'=>'Fórmula'],
        'example'             => ['fr'=>'Exemple',                 'en'=>'Example',                 'es'=>'Ejemplo',                'pt'=>'Exemplo'],
        'waste_detection'     => ['fr'=>'Détection du type de déchet','en'=>'Waste type detection', 'es'=>'Detección del tipo de residuo','pt'=>'Detecção do tipo de resíduo'],
        'material'            => ['fr'=>'Matière',                 'en'=>'Material',                'es'=>'Material',               'pt'=>'Material'],
        'co2_factor_header'   => ['fr'=>'t CO₂ évité / tonne recyclée','en'=>'t CO₂ avoided / tonne recycled','es'=>'t CO₂ evitado / tonelada reciclada','pt'=>'t CO₂ evitado / tonelada reciclada'],
        'justification'       => ['fr'=>'Justification',           'en'=>'Justification',           'es'=>'Justificación',          'pt'=>'Justificativa'],
        'equivalents'         => ['fr'=>'Équivalents affichés',    'en'=>'Displayed equivalents',   'es'=>'Equivalentes mostrados', 'pt'=>'Equivalentes exibidos'],
        'trees_year'          => ['fr'=>'Arbres/an',               'en'=>'Trees/year',              'es'=>'Árboles/año',            'pt'=>'Árvores/ano'],
        'car_km'              => ['fr'=>'km voiture évités',       'en'=>'Car km avoided',          'es'=>'km de coche evitados',   'pt'=>'km de carro evitados'],
        'official_sources'    => ['fr'=>'Sources officielles',     'en'=>'Official Sources',        'es'=>'Fuentes oficiales',      'pt'=>'Fontes oficiais'],
        'limitations'         => ['fr'=>'Limites & Statut de Validation','en'=>'Limitations & Validation Status','es'=>'Limitaciones y estado de validación','pt'=>'Limitações e status de validação'],
        'live_dashboard'      => ['fr'=>'Voir le dashboard live',  'en'=>'View live dashboard',     'es'=>'Ver el panel en vivo',   'pt'=>'Ver painel ao vivo'],
        'weight_label'        => ['fr'=>'Poids',                   'en'=>'Weight',                  'es'=>'Peso',                   'pt'=>'Peso'],
        'note_lb'             => ['fr'=>'Note : lb convertis automatiquement en kg (× 0,453592)','en'=>'Note: lb automatically converted to kg (× 0.453592)','es'=>'Nota: lb convertidos automáticamente a kg (× 0,453592)','pt'=>'Nota: lb convertidos automaticamente para kg (× 0,453592)'],
        'note_lb_full'        => ['fr'=>'Note : les livres (lb) sont automatiquement converties en kg (× 0,453592) avant le calcul.','en'=>'Note: pounds (lb) are automatically converted to kg (× 0.453592) before calculation.','es'=>'Nota: las libras (lb) se convierten automáticamente a kg (× 0,453592) antes del cálculo.','pt'=>'Nota: libras (lb) são convertidas automaticamente para kg (× 0,453592) antes do cálculo.'],
        'co2_factor_main'     => ['fr'=>'Principaux facteurs CO₂ (source : ADEME Base Carbone)','en'=>'Main CO₂ factors (source: ADEME Base Carbone)','es'=>'Principales factores CO₂ (fuente: ADEME Base Carbone)','pt'=>'Principais fatores CO₂ (fonte: ADEME Base Carbone)'],
        // ── Facture / Invoice ─────────────────────────────────────────
        'inv_emitter'         => ['fr'=>'Émetteur',                'en'=>'Issuer',                  'es'=>'Emisor',                 'pt'=>'Emissor'],
        'inv_recipient'       => ['fr'=>'Destinataire — Acheteur', 'en'=>'Recipient — Buyer',       'es'=>'Destinatario — Comprador','pt'=>'Destinatário — Comprador'],
        'inv_description'     => ['fr'=>'Description',             'en'=>'Description',             'es'=>'Descripción',            'pt'=>'Descrição'],
        'inv_weight'          => ['fr'=>'Poids',                   'en'=>'Weight',                  'es'=>'Peso',                   'pt'=>'Peso'],
        'inv_total_price'     => ['fr'=>'Prix total lot',          'en'=>'Total batch price',       'es'=>'Precio total del lote',  'pt'=>'Preço total do lote'],
        'inv_vendor_share'    => ['fr'=>'🤝 Vendeur reçoit directement (80%)','en'=>'🤝 Vendor receives directly (80%)','es'=>'🤝 Vendedor recibe directamente (80%)','pt'=>'🤝 Vendedor recebe diretamente (80%)'],
        'inv_commission'      => ['fr'=>'Commission',              'en'=>'Commission',              'es'=>'Comisión',               'pt'=>'Comissão'],
        'inv_commission_ht'   => ['fr'=>'HT',                      'en'=>'excl. tax',               'es'=>'sin IVA',                'pt'=>'s/ IVA'],
        'inv_tva_exempt'      => ['fr'=>'Exonéré',                 'en'=>'Exempt',                  'es'=>'Exento',                 'pt'=>'Isento'],
        'inv_total_due'       => ['fr'=>'TOTAL DÛ À',              'en'=>'TOTAL DUE TO',            'es'=>'TOTAL A PAGAR A',        'pt'=>'TOTAL DEVIDO A'],
        'inv_ttc'             => ['fr'=>'TTC',                     'en'=>'incl. tax',               'es'=>'IVA incluido',           'pt'=>'c/ IVA'],
        'inv_payment_title'   => ['fr'=>'Mode de règlement :',     'en'=>'Payment instructions:',   'es'=>'Instrucciones de pago:', 'pt'=>'Instruções de pagamento:'],
        'inv_vendor_pays'     => ['fr'=>'L\'acheteur règle',       'en'=>'The buyer pays',          'es'=>'El comprador paga',      'pt'=>'O comprador paga'],
        'inv_directly'        => ['fr'=>'directement au vendeur.', 'en'=>'directly to the vendor.', 'es'=>'directamente al vendedor.','pt'=>'diretamente ao vendedor.'],
        'inv_buyer_transfers' => ['fr'=>'L\'acheteur transfère',   'en'=>'The buyer transfers',     'es'=>'El comprador transfiere', 'pt'=>'O comprador transfere'],
        'inv_to_site'         => ['fr'=>'à',                       'en'=>'to',                      'es'=>'a',                      'pt'=>'a'],
        'inv_partner_ref'     => ['fr'=>'Partenaire référent :',   'en'=>'Referring partner:',      'es'=>'Socio referente:',       'pt'=>'Parceiro referente:'],
        'inv_partner_comm'    => ['fr'=>'Commission à reverser par','en'=>'Commission to be paid by','es'=>'Comisión a abonar por',  'pt'=>'Comissão a pagar por'],
        'inv_date'            => ['fr'=>'Date :',                  'en'=>'Date:',                   'es'=>'Fecha:',                 'pt'=>'Data:'],
        'inv_collect_date'    => ['fr'=>'Collecte :',              'en'=>'Collection:',             'es'=>'Recogida:',              'pt'=>'Coleta:'],
        'inv_print'           => ['fr'=>'🖨️ Imprimer / Enregistrer en PDF','en'=>'🖨️ Print / Save as PDF','es'=>'🖨️ Imprimir / Guardar como PDF','pt'=>'🖨️ Imprimir / Salvar como PDF'],
        'inv_footer_doc'      => ['fr'=>'Document généré par FerayPro Tracer — Traçabilité des déchets recyclés','en'=>'Document generated by FerayPro Tracer — Recycled waste traceability','es'=>'Documento generado por FerayPro Tracer — Trazabilidad de residuos reciclados','pt'=>'Documento gerado pelo FerayPro Tracer — Rastreabilidade de resíduos reciclados'],
        'inv_commission_paid' => ['fr'=>'Commission payée le',     'en'=>'Commission paid on',      'es'=>'Comisión pagada el',     'pt'=>'Comissão paga em'],
        'mark_paid'           => ['fr'=>'💰 Marquer commission comme payée','en'=>'💰 Mark commission as paid','es'=>'💰 Marcar comisión como pagada','pt'=>'💰 Marcar comissão como paga'],
        'saving'              => ['fr'=>'⏳ Enregistrement...',    'en'=>'⏳ Saving...',            'es'=>'⏳ Guardando...',         'pt'=>'⏳ Salvando...'],
        'saved_ok'            => ['fr'=>'✅ Prix enregistré !',    'en'=>'✅ Price saved!',         'es'=>'✅ Precio guardado.',     'pt'=>'✅ Preço salvo!'],
        'invalid_price'       => ['fr'=>'⚠️ Entrez un prix valide.','en'=>'⚠️ Enter a valid price.','es'=>'⚠️ Ingrese un precio válido.','pt'=>'⚠️ Insira um preço válido.'],
        'confirm_paid'        => ['fr'=>'Confirmer que la commission a été reçue ?','en'=>'Confirm that the commission has been received?','es'=>'¿Confirmar que la comisión fue recibida?','pt'=>'Confirmar que a comissão foi recebida?'],
    ];
}

// Fonction principale de traduction — remplace l'ancien fpt_t($fr, $en)
// Usage : fpt__('key')          → langue admin (toujours FR)
//         fpt__('key', 'inv')   → langue facture (configurée séparément)
function fpt__( $key, $ctx = 'admin' ) {
    static $strings = null;
    if ( $strings === null ) $strings = fpt_strings();
    $lang = ( $ctx === 'inv' ) ? fpt_invoice_lang() : fpt_lang();
    if ( isset($strings[$key][$lang]) ) return $strings[$key][$lang];
    if ( isset($strings[$key]['en']) )  return $strings[$key]['en'];
    if ( isset($strings[$key]['fr']) )  return $strings[$key]['fr'];
    return $key;
}

// Raccourci pour la facture : fpt_inv__('key')
function fpt_inv__( $key ) {
    return fpt__( $key, 'inv' );
}

// Alias de compatibilité — garde tout l'ancien code fonctionnel
// Si la clé est connue dans le nouveau système → l'utilise
// Sinon → comportement original (fr/en seulement)
function fpt_t( $fr, $en ) {
    // Chercher dans le tableau par valeur FR exacte
    static $fr_index = null;
    if ( $fr_index === null ) {
        $fr_index = [];
        foreach ( fpt_strings() as $key => $langs ) {
            if ( isset($langs['fr']) ) $fr_index[$langs['fr']] = $key;
        }
    }
    if ( isset($fr_index[$fr]) ) return fpt__($fr_index[$fr]);
    // Fallback : comportement original pour les chaînes non encore migrées
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

// ─── Intensité carbone du réseau électrique par pays (g CO₂/kWh) ────────────
// Source : IEA Electricity 2024, MASEN/ONEE 2024, eGRID USA 2023, RTE 2023
// Utilisé pour ajuster le facteur CO₂ process selon le mix énergétique local.
// Le gain net (fpt_co2_factors) reste ADEME/FEDEREC — seul le CO₂ process varie.
//
// Référence normalisée : France = 1.0 (mix bas-carbone nucléaire, ~45 g CO₂/kWh)
// Les facteurs process dans fpt_co2_process_factors() sont calibrés sur France.
// Le multiplicateur ajuste à la hausse pour les pays plus carbonés.
//
// Valeurs gCO₂/kWh → multiplicateur (divisé par référence France 45 g/kWh) :
//   France  :  45 g/kWh → × 1.00  (nucléaire dominant — RTE 2023)
//   USA     : 380 g/kWh → × 8.44  (mix national — EPA eGRID 2023)
//   Maroc   : 644 g/kWh → × 14.3  (charbon + gaz dominant — ONEE/MASEN 2024)
//   RDC     :  35 g/kWh → × 0.78  (hydroélectrique Inga dominant)
//   Sénégal : 500 g/kWh → × 11.1
//   Kenya   : 120 g/kWh → × 2.67  (géothermie + hydraulique)
//
// ⚠️ Le multiplicateur ne s'applique QU'au CO₂ process (dashboard acheteur).
//    Le CO₂ évité (gain net, dashboard public) reste identique pour tous les pays :
//    la physique du recyclage ne change pas, seule l'énergie locale varie.
function fpt_grid_intensity() {
    $country = strtolower( get_option( 'fpt_country_name', '' ) );

    // Table : [g CO₂/kWh, source]
    $grid = [
        // ─ Afrique du Nord ────────────────────────────────────────────────
        'maroc'      => 644,  // ONEE/MASEN 2024 — charbon + gaz (éolien en forte hausse)
        'morocco'    => 644,
        'algerie'    => 580,  // mix gaz naturel dominant — AIE 2023
        'algérie'    => 580,
        'tunisie'    => 430,  // gaz + renouvelables montants — STEG 2023
        'tunisia'    => 430,
        'egypte'     => 460,  // gaz + Assouane — IEA 2023
        'egypt'      => 460,
        // ─ Afrique subsaharienne ──────────────────────────────────────────
        'congo'      =>  35,  // hydroélectrique Inga — SNE/RDC 2023
        'rdc'        =>  35,
        'drc'        =>  35,
        'senegal'    => 500,  // fuel + gaz — SENELEC 2023
        'sénégal'    => 500,
        'kenya'      => 120,  // géothermie + hydraulique — KPLC 2023
        'nigeria'    => 440,  // gaz naturel + fuel — NERC 2023
        'ghana'      => 280,  // hydraulique + gaz — ECG 2023
        'afrique du sud' => 760,  // charbon dominant — Eskom 2023
        'south africa'   => 760,
        'ethiopia'   =>  20,  // hydroélectrique — EEP 2023
        'ethiopie'   =>  20,
        'éthiopie'   =>  20,
        'cameroun'   => 110,  // hydroélectrique — AES-Sonel 2023
        'cameroon'   => 110,
        'cote ivoire'=> 390,  // gaz + fuel — CIE 2023
        "côte d'ivoire" => 390,
        // ─ Europe ─────────────────────────────────────────────────────────
        'france'     =>  45,  // nucléaire dominant — RTE 2023
        'allemagne'  => 350,  // charbon + renouvelables — UBA 2023
        'germany'    => 350,
        'espagne'    => 160,  // renouvelables + nucléaire — REE 2023
        'spain'      => 160,
        'royaume-uni'=> 170,  // éolien + gaz — National Grid 2023
        'uk'         => 170,
        // ─ Amériques ──────────────────────────────────────────────────────
        'usa'        => 380,  // mix national — EPA eGRID 2023
        'états-unis' => 380,
        'etats-unis' => 380,
        'canada'     => 130,  // hydraulique dominant — NEB 2023
        'bresil'     => 100,  // hydraulique — ONS 2023
        'brésil'     => 100,
        'brazil'     => 100,
        'mexique'    => 350,  // gaz + pétrole — CFE 2023
        'mexico'     => 350,
        // ─ Asie ───────────────────────────────────────────────────────────
        'chine'      => 560,  // charbon dominant — NEA 2023
        'china'      => 560,
        'inde'       => 630,  // charbon majoritaire — CEA 2023
        'india'      => 630,
    ];

    // Intensité de référence : France = 45 g CO₂/kWh (base des facteurs ADEME)
    $reference_gco2 = 45.0;

    // Détection du pays dans la table
    $intensity = null;
    foreach ( $grid as $key => $val ) {
        if ( strpos( $country, $key ) !== false ) {
            $intensity = $val;
            break;
        }
    }

    // Option admin de surcharge manuelle (en g CO₂/kWh)
    $manual = floatval( get_option( 'fpt_grid_intensity_override', 0 ) );
    if ( $manual > 0 ) $intensity = $manual;

    // Défaut : 380 g/kWh (moyenne mondiale IEA 2023) si pays non reconnu
    if ( ! $intensity ) $intensity = 380;

    $multiplier = round( $intensity / $reference_gco2, 4 );

    return [
        'gco2_per_kwh' => $intensity,
        'multiplier'   => $multiplier,
        'country'      => $country,
        'source'       => 'IEA 2024 / ADEME ref France 45 gCO₂/kWh',
    ];
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
// Les facteurs de fpt_co2_process_factors() sont calibrés sur le mix électrique
// français (ADEME/FEDEREC, référence 45 g CO₂/kWh).
// fpt_grid_intensity() retourne un multiplicateur qui ajuste ces facteurs au
// mix électrique réel du pays configuré.
//
// Exemples :
//   Aluminium — France  : 0,360 × 1,00 = 0,360 t CO₂/t  (nucléaire)
//   Aluminium — Maroc   : 0,360 × 14,3 = 5,15  t CO₂/t  (charbon/gaz)
//   Aluminium — USA     : 0,360 × 8,44 = 3,04  t CO₂/t  (mix national eGRID)
//   Aluminium — RDC     : 0,360 × 0,78 = 0,281 t CO₂/t  (hydraulique Inga)
//
// Note : le CO₂ évité (gain net, dashboard public) n'est PAS modifié — il reste
// ADEME/FEDEREC universel. Seul le CO₂ process (dashboard acheteur) est local.
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

    // Ajustement au mix électrique local
    $grid       = fpt_grid_intensity();
    $factor_adj = $factor * $grid['multiplier'];

    return round( ($poids_kg / 1000) * $factor_adj, 6 );
}

// ─── Retourner le détail grid pour affichage (dashboard acheteur, méthodologie) ─
function fpt_get_grid_info() {
    return fpt_grid_intensity();
}

function fpt_get_acheteurs() {
    $slug = get_option( 'fpt_acheteurs_cat_slug', 'acheteurs' );
    // Supporte plusieurs slugs séparés par virgule (ex: "acheteurs,buyers")
    $slugs = array_map('trim', explode(',', $slug));

    $posts = get_posts([
        'post_type'   => 'hp_listing',
        'post_status' => 'publish',
        'numberposts' => -1,
        'tax_query'   => [[
            'taxonomy' => 'hp_listing_category',
            'field'    => 'slug',
            'terms'    => $slugs,
        ]],
        'orderby' => 'title',
        'order'   => 'ASC',
    ]);

    // Fallback : si vide, chercher par toutes les catégories contenant "achet" ou "buyer"
    if ( empty($posts) ) {
        $terms = get_terms(['taxonomy' => 'hp_listing_category', 'hide_empty' => true]);
        $found_slugs = [];
        foreach ($terms as $term) {
            if ( stripos($term->slug, 'achet') !== false || stripos($term->slug, 'buyer') !== false ) {
                $found_slugs[] = $term->slug;
            }
        }
        if ($found_slugs) {
            $posts = get_posts([
                'post_type'   => 'hp_listing',
                'post_status' => 'publish',
                'numberposts' => -1,
                'tax_query'   => [[
                    'taxonomy' => 'hp_listing_category',
                    'field'    => 'slug',
                    'terms'    => $found_slugs,
                ]],
                'orderby' => 'title',
                'order'   => 'ASC',
            ]);
        }
    }

    return $posts;
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

        // ── Commission ──
        $prix_key    = 'hp_' . get_option('fpt_key_prix', 'prixvendeur');
        $prix_raw    = get_post_meta( $post->ID, '_fpt_prix_lot', true )  // champ dédié (saisi dans la metabox)
                    ?: get_post_meta( $post->ID, $prix_key, true );        // fallback champ HivePress
        $prix        = (float) preg_replace('/[^\d.]/', '', str_replace(',', '.', $prix_raw));
        $currency    = fpt_get_currency();
        $comm_20     = $prix > 0 ? round($prix * 0.20, 2) : 0;
        $vendeur_80  = $prix > 0 ? round($prix * 0.80, 2) : 0;
        $fp_10       = $prix > 0 ? round($prix * 0.10, 2) : 0;

        // Partenaire référent
        $ref_slug    = get_post_meta($post->ID, '_fpt_ref', true);
        $partenaire  = $ref_slug ? fpt_get_partenaire_by_slug($ref_slug) : null;

        // Contact acheteur
        $wa_key      = 'hp_' . get_option('fpt_key_whatsapp', 'whatsapp');
        $wa_phone    = preg_replace('/[^0-9]/', '', get_post_meta($acheteur_id, $wa_key, true) ?: '');

        // Numéro facture
        $inv_stored  = get_post_meta($post->ID, '_fpt_invoice_number', true);
        if ( ! $inv_stored ) {
            $inv_stored = 'FP-INV-' . date('Ym') . '-' . $post->ID;
            update_post_meta($post->ID, '_fpt_invoice_number', $inv_stored);
        }

        // Statut paiement
        $paid        = get_post_meta($post->ID, '_fpt_commission_paid', true);
        $paid_date   = get_post_meta($post->ID, '_fpt_commission_paid_date', true);
    ?>
        <div style="background:#e6f5ee;border:1px solid #1a7a4a;border-radius:6px;padding:10px;margin-bottom:10px">
            <strong style="color:#1a7a4a">✅ <?php echo fpt_t('Lot collecté','Batch collected'); ?></strong><br>
            <span style="color:#555"><?php echo fpt_t('Acheteur','Buyer'); ?> : <strong><?php echo esc_html($acheteur_titre); ?></strong></span><br>
            <span style="color:#555"><?php echo fpt_t('Date','Date'); ?> : <?php echo esc_html( date_i18n('d/m/Y', strtotime($collected_date)) ); ?></span><br>
            <span style="color:#1a7a4a">🌱 CO₂ évité : <?php echo esc_html(number_format($co2_mat,4)); ?> t</span><br>
            <span style="color:#e67e22">🏭 CO₂ recyclage : <?php echo esc_html(number_format($co2_process,6)); ?> t</span>
        </div>

        <!-- ── Bloc Facture Commission ── -->
        <div style="margin-top:10px;border-top:1px solid #d0ddd4;padding-top:10px" id="fpt-facture-bloc-<?php echo $post->ID; ?>">
            <strong style="font-size:12px;color:#333;display:block;margin-bottom:6px">📄 Facture commission (20%)</strong>
            <?php
            $inv_url = add_query_arg([
                'action'  => 'fpt_facture',
                'fpt_lot' => $post->ID,
                'fpt_tok' => substr(md5($post->ID . AUTH_KEY), 0, 12),
            ], admin_url('admin-ajax.php'));
            ?>
            <?php if ( $prix > 0 ) : ?>
                <div style="background:#f4f6f4;border-radius:4px;padding:6px 8px;font-size:12px;margin-bottom:6px">
                    💼 <strong><?php echo number_format($prix,2).' '.esc_html($currency); ?></strong>
                    &nbsp;|&nbsp; ✅ FerayPro : <strong style="color:#1a7a4a"><?php echo number_format($comm_20,2).' '.esc_html($currency); ?></strong>
                    &nbsp;|&nbsp; 🤝 Vendeur : <strong><?php echo number_format($vendeur_80,2).' '.esc_html($currency); ?></strong>
                </div>
                <a href="<?php echo esc_url($inv_url); ?>" target="_blank"
                   style="display:block;background:#1a7a4a;color:#fff;text-decoration:none;padding:9px;border-radius:4px;font-size:13px;font-weight:700;text-align:center;margin-bottom:6px">
                    📄 Ouvrir / Imprimer la facture PDF
                </a>
                <?php if ( $paid === 'paid' ) : ?>
                    <div style="background:#e6f5ee;border:1px solid #1a7a4a;border-radius:4px;padding:7px 10px;font-size:12px;font-weight:700;color:#1a7a4a;text-align:center">
                        ✅ Commission payée le <?php echo esc_html($paid_date); ?>
                    </div>
                <?php else : ?>
                    <button type="button" onclick="fptMarquerPaye(<?php echo $post->ID; ?>)"
                            style="background:#f59e0b;color:#fff;border:none;padding:7px;border-radius:4px;cursor:pointer;width:100%;font-size:12px;font-weight:700">
                        💰 Marquer commission comme payée
                    </button>
                    <span id="fpt_paid_msg_<?php echo $post->ID; ?>" style="font-size:12px;display:block;margin-top:4px"></span>
                <?php endif; ?>
                <?php do_action( 'fpt_metabox_after_commission', $post->ID ); ?>
            <?php else : ?>
                <div style="display:flex;gap:6px;align-items:center;margin-bottom:6px">
                    <input type="number" id="fpt_prix_input_<?php echo $post->ID; ?>" step="0.01" min="0"
                           placeholder="Prix total (ex: 1500)"
                           style="flex:1;padding:6px;border:1px solid #1a7a4a;border-radius:4px;font-size:13px">
                    <select id="fpt_currency_input_<?php echo $post->ID; ?>" style="padding:6px;border:1px solid #ccc;border-radius:4px;font-size:13px">
                        <?php foreach(['MAD','EUR','USD','CDF','XOF'] as $cur) : ?>
                        <option value="<?php echo $cur; ?>" <?php selected(fpt_get_currency(),$cur); ?>><?php echo $cur; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button"
                        onclick="fptSavePrix(<?php echo $post->ID; ?>)"
                        style="background:#1a7a4a;color:#fff;border:none;padding:9px;border-radius:4px;cursor:pointer;width:100%;font-size:13px;font-weight:700">
                    💾 Enregistrer le prix
                </button>
                <span id="fpt_prix_msg_<?php echo $post->ID; ?>" style="font-size:12px;display:block;margin-top:4px"></span>
            <?php endif; ?>
        </div>
        <script>
        function fptSavePrix(lotId) {
            var prix = document.getElementById('fpt_prix_input_' + lotId).value;
            var cur  = document.getElementById('fpt_currency_input_' + lotId).value;
            var msg  = document.getElementById('fpt_prix_msg_' + lotId);
            if (!prix || parseFloat(prix) <= 0) { msg.style.color='red'; msg.textContent='<?php echo esc_js(fpt__('invalid_price')); ?>'; return; }
            msg.style.color='#555'; msg.textContent='<?php echo esc_js(fpt__('saving')); ?>';
            fetch(ajaxurl, {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: 'action=fpt_save_prix&lot_id=' + lotId + '&prix=' + encodeURIComponent(prix) + '&currency=' + encodeURIComponent(cur) + '&nonce=<?php echo wp_create_nonce("fpt_save_prix"); ?>'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    msg.style.color='green'; msg.textContent='<?php echo esc_js(fpt__('saved_ok')); ?>';
                    setTimeout(() => location.reload(), 800);
                } else {
                    msg.style.color='red'; msg.textContent='❌ Erreur : ' + (data.data || 'inconnue');
                }
            });
        }
        function fptMarquerPaye(lotId) {
            if (!confirm('<?php echo esc_js(fpt__('confirm_paid')); ?>')) return;
            var msg = document.getElementById('fpt_paid_msg_' + lotId);
            msg.style.color='#555'; msg.textContent='<?php echo esc_js(fpt__('saving')); ?>';
            fetch(ajaxurl, {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: 'action=fpt_mark_paid_ajax&lot_id=' + lotId + '&nonce=<?php echo wp_create_nonce("fpt_mark_paid_ajax"); ?>'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    msg.style.color='green'; msg.textContent='✅ Payé !';
                    setTimeout(() => location.reload(), 600);
                } else {
                    msg.style.color='red'; msg.textContent='❌ Erreur.';
                }
            });
        }
        </script>
        <!-- ── Bloc Preuve de pesée ── -->
        <?php
        $preuves = get_post_meta($post->ID, '_fpt_preuves_pesee', true);
        $preuves = is_array($preuves) ? $preuves : [];
        ?>
        <div style="margin-top:10px;border-top:1px solid #d0ddd4;padding-top:10px">
            <strong style="font-size:12px;color:#333;display:block;margin-bottom:6px">
                ⚖️ <?php echo fpt_t('Preuves de pesée','Weighing proofs'); ?>
                <span style="font-weight:400;color:#888;font-size:11px"> — <?php echo fpt_t('photo, bon de bascule, PDF','photo, weigh slip, PDF'); ?></span>
            </strong>

            <?php if ( ! empty($preuves) ) : ?>
            <div id="fpt-preuves-list-<?php echo $post->ID; ?>" style="display:flex;flex-direction:column;gap:5px;margin-bottom:8px">
                <?php foreach ($preuves as $att_id) :
                    $att_url  = wp_get_attachment_url($att_id);
                    $att_mime = get_post_mime_type($att_id);
                    $att_name = basename(get_attached_file($att_id));
                    if ( ! $att_url ) continue;
                    $icon = strpos($att_mime, 'pdf') !== false ? '📄' : '🖼️';
                    $thumb = strpos($att_mime, 'image') !== false
                        ? wp_get_attachment_image($att_id, [40,40], false, ['style'=>'width:40px;height:40px;object-fit:cover;border-radius:4px;flex-shrink:0'])
                        : '<span style="font-size:28px;flex-shrink:0">'.$icon.'</span>';
                ?>
                <div style="display:flex;align-items:center;gap:8px;background:#f4f6f4;border:1px solid #d0ddd4;border-radius:5px;padding:5px 8px">
                    <?php echo $thumb; ?>
                    <div style="flex:1;min-width:0">
                        <a href="<?php echo esc_url($att_url); ?>" target="_blank"
                           style="font-size:12px;color:#1a7a4a;text-decoration:none;font-weight:600;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                            <?php echo esc_html($att_name); ?>
                        </a>
                        <span style="font-size:10px;color:#888"><?php echo esc_html(strtoupper(str_replace('image/','',$att_mime))); ?></span>
                    </div>
                    <button type="button"
                        onclick="fptSupprimerPreuve(<?php echo $post->ID; ?>, <?php echo $att_id; ?>, this)"
                        style="background:none;border:none;cursor:pointer;color:#c0392b;font-size:14px;padding:2px 4px"
                        title="<?php echo fpt_t('Supprimer','Delete'); ?>">✕</button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else : ?>
            <div id="fpt-preuves-list-<?php echo $post->ID; ?>" style="margin-bottom:8px"></div>
            <?php endif; ?>

            <!-- Zone d'upload -->
            <div id="fpt-upload-zone-<?php echo $post->ID; ?>"
                 style="border:2px dashed #b0c9bb;border-radius:6px;padding:12px;text-align:center;cursor:pointer;transition:border-color .2s;background:#fafcfb"
                 onclick="document.getElementById('fpt-file-input-<?php echo $post->ID; ?>').click()"
                 ondragover="event.preventDefault();this.style.borderColor='#1a7a4a'"
                 ondragleave="this.style.borderColor='#b0c9bb'"
                 ondrop="fptHandleDrop(event,<?php echo $post->ID; ?>)">
                <span style="font-size:20px">📎</span>
                <p style="margin:4px 0 0;font-size:12px;color:#6b8070">
                    <?php echo fpt_t('Cliquer ou glisser un fichier','Click or drag a file'); ?><br>
                    <span style="font-size:10px;color:#aaa">JPG · PNG · PDF · max 8 Mo</span>
                </p>
            </div>
            <input type="file" id="fpt-file-input-<?php echo $post->ID; ?>"
                   accept="image/jpeg,image/png,image/webp,application/pdf"
                   style="display:none"
                   onchange="fptUploadPreuve(<?php echo $post->ID; ?>, this)">
            <div id="fpt-upload-msg-<?php echo $post->ID; ?>" style="font-size:12px;margin-top:5px;min-height:16px"></div>
        </div>
        <script>
        function fptUploadPreuve(lotId, input) {
            var file = input.files[0];
            if (!file) return;
            if (file.size > 8 * 1024 * 1024) {
                document.getElementById('fpt-upload-msg-' + lotId).innerHTML = '<span style="color:red">⚠️ <?php echo fpt_t('Fichier trop lourd (max 8 Mo)','File too large (max 8MB)'); ?></span>';
                return;
            }
            var msg = document.getElementById('fpt-upload-msg-' + lotId);
            msg.innerHTML = '<span style="color:#555">⏳ <?php echo fpt_t('Envoi en cours...','Uploading...'); ?></span>';
            var fd = new FormData();
            fd.append('action',  'fpt_upload_preuve');
            fd.append('lot_id',  lotId);
            fd.append('nonce',   '<?php echo wp_create_nonce("fpt_upload_preuve"); ?>');
            fd.append('file',    file);
            fetch(ajaxurl, { method:'POST', body:fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    msg.innerHTML = '<span style="color:green">✅ <?php echo fpt_t('Fichier ajouté','File added'); ?></span>';
                    var list = document.getElementById('fpt-preuves-list-' + lotId);
                    list.insertAdjacentHTML('beforeend', data.data.html);
                    input.value = '';
                    setTimeout(() => { msg.innerHTML = ''; }, 2000);
                } else {
                    msg.innerHTML = '<span style="color:red">❌ ' + (data.data || '<?php echo fpt_t('Erreur upload','Upload error'); ?>') + '</span>';
                }
            })
            .catch(() => {
                msg.innerHTML = '<span style="color:red">❌ <?php echo fpt_t('Erreur réseau','Network error'); ?></span>';
            });
        }
        function fptHandleDrop(event, lotId) {
            event.preventDefault();
            document.getElementById('fpt-upload-zone-' + lotId).style.borderColor = '#b0c9bb';
            var input = document.getElementById('fpt-file-input-' + lotId);
            var dt = new DataTransfer();
            dt.items.add(event.dataTransfer.files[0]);
            input.files = dt.files;
            fptUploadPreuve(lotId, input);
        }
        function fptSupprimerPreuve(lotId, attId, btn) {
            if (!confirm('<?php echo fpt_t('Supprimer cette preuve ?','Delete this proof?'); ?>')) return;
            btn.disabled = true;
            fetch(ajaxurl, {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: 'action=fpt_delete_preuve&lot_id=' + lotId + '&att_id=' + attId + '&nonce=<?php echo wp_create_nonce("fpt_delete_preuve"); ?>'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    btn.closest('div[style]').remove();
                } else {
                    btn.disabled = false;
                    alert('<?php echo fpt_t('Erreur suppression','Delete error'); ?>');
                }
            });
        }
        </script>

        <button type="button" onclick="document.getElementById('fpt_uncollect_form').style.display='block'" 
            style="background:#c0392b;color:#fff;border:none;padding:6px 12px;border-radius:4px;cursor:pointer;font-size:12px;margin-top:10px">
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

// ─── Marquer commission payée via GET (lien dans la metabox) ─────────────────
add_action( 'admin_init', 'fpt_handle_mark_paid_get' );
function fpt_handle_mark_paid_get() {
    if ( empty($_GET['fpt_do']) || $_GET['fpt_do'] !== 'paid' ) return;
    if ( ! current_user_can('manage_options') ) return;
    $lot_id = intval($_GET['fpt_lot'] ?? 0);
    if ( ! $lot_id ) return;
    if ( ! wp_verify_nonce($_GET['fpt_nonce'] ?? '', 'fpt_paid_' . $lot_id) ) return;

    update_post_meta($lot_id, '_fpt_commission_paid', 'paid');
    update_post_meta($lot_id, '_fpt_commission_paid_date', date_i18n('d/m/Y'));

    wp_redirect( add_query_arg(['post' => $lot_id, 'action' => 'edit', 'fpt_ok' => '1'], admin_url('post.php')) );
    exit;
}

add_action('admin_notices', function() {
    if ( ! empty($_GET['fpt_ok']) ) {
        echo '<div class="notice notice-success is-dismissible"><p>✅ Commission marquée comme payée.</p></div>';
    }
});

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

// ─── AJAX : Upload preuve de pesée ───────────────────────────────────────────
add_action( 'wp_ajax_fpt_upload_preuve', 'fpt_ajax_upload_preuve' );
function fpt_ajax_upload_preuve() {
    check_ajax_referer( 'fpt_upload_preuve', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error('unauthorized');

    $lot_id = intval($_POST['lot_id'] ?? 0);
    if ( ! $lot_id ) wp_send_json_error('invalid_lot');

    if ( empty($_FILES['file']['name']) ) wp_send_json_error('no_file');

    // Vérification type MIME
    $allowed_types = ['image/jpeg','image/png','image/webp','application/pdf'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $_FILES['file']['tmp_name']);
    finfo_close($finfo);
    if ( ! in_array($mime, $allowed_types, true) ) wp_send_json_error(fpt_t('Type de fichier non autorisé','File type not allowed'));

    // Vérification taille (8 Mo)
    if ( $_FILES['file']['size'] > 8 * 1024 * 1024 ) wp_send_json_error(fpt_t('Fichier trop lourd','File too large'));

    // Charger les fonctions d'upload WP
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    // Attacher le fichier au post (lot)
    $att_id = media_handle_upload('file', $lot_id);

    if ( is_wp_error($att_id) ) {
        wp_send_json_error($att_id->get_error_message());
    }

    // Enregistrer l'ID dans les metas du lot
    $preuves   = get_post_meta($lot_id, '_fpt_preuves_pesee', true);
    $preuves   = is_array($preuves) ? $preuves : [];
    $preuves[] = $att_id;
    update_post_meta($lot_id, '_fpt_preuves_pesee', $preuves);

    // Construire le HTML de la ligne pour insertion JS
    $att_url  = wp_get_attachment_url($att_id);
    $att_mime = get_post_mime_type($att_id);
    $att_name = basename(get_attached_file($att_id));
    $icon     = strpos($att_mime, 'pdf') !== false ? '📄' : '🖼️';
    $thumb    = strpos($att_mime, 'image') !== false
        ? wp_get_attachment_image($att_id, [40,40], false, ['style'=>'width:40px;height:40px;object-fit:cover;border-radius:4px;flex-shrink:0'])
        : '<span style="font-size:28px;flex-shrink:0">'.$icon.'</span>';

    $lang_delete = fpt_t('Supprimer','Delete');
    $mime_label  = strtoupper(str_replace('image/', '', $att_mime));

    $html = '<div style="display:flex;align-items:center;gap:8px;background:#f4f6f4;border:1px solid #d0ddd4;border-radius:5px;padding:5px 8px">'
        . $thumb
        . '<div style="flex:1;min-width:0">'
        . '<a href="' . esc_url($att_url) . '" target="_blank" style="font-size:12px;color:#1a7a4a;text-decoration:none;font-weight:600;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'
        . esc_html($att_name)
        . '</a><span style="font-size:10px;color:#888">' . esc_html($mime_label) . '</span>'
        . '</div>'
        . '<button type="button" onclick="fptSupprimerPreuve(' . $lot_id . ',' . $att_id . ',this)" '
        . 'style="background:none;border:none;cursor:pointer;color:#c0392b;font-size:14px;padding:2px 4px" title="' . esc_attr($lang_delete) . '">✕</button>'
        . '</div>';

    wp_send_json_success(['html' => $html, 'att_id' => $att_id]);
}

// ─── AJAX : Supprimer une preuve de pesée ────────────────────────────────────
add_action( 'wp_ajax_fpt_delete_preuve', 'fpt_ajax_delete_preuve' );
function fpt_ajax_delete_preuve() {
    check_ajax_referer( 'fpt_delete_preuve', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error('unauthorized');

    $lot_id = intval($_POST['lot_id'] ?? 0);
    $att_id = intval($_POST['att_id'] ?? 0);
    if ( ! $lot_id || ! $att_id ) wp_send_json_error('invalid');

    $preuves = get_post_meta($lot_id, '_fpt_preuves_pesee', true);
    $preuves = is_array($preuves) ? $preuves : [];
    $preuves = array_values(array_filter($preuves, function($id) use ($att_id) { return (int)$id !== $att_id; }));
    update_post_meta($lot_id, '_fpt_preuves_pesee', $preuves);

    // Supprimer l'attachment WP (fichier + miniatures)
    wp_delete_attachment($att_id, true);

    wp_send_json_success();
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
            * <?php
            $grid_info = fpt_get_grid_info();
            echo fpt_t(
                'CO₂ process : émissions produites par votre activité de recyclage, ajustées au mix électrique local (' . number_format($grid_info['gco2_per_kwh']) . ' g CO₂/kWh · ×' . $grid_info['multiplier'] . '). Source : FEDEREC/ADEME ACV 2017 (base France) × IEA 2024. Le CO₂ évité (gain net) est calculé séparément : émissions production primaire − émissions process recyclage (facteur ADEME).',
                'CO₂ process: emissions produced by your recycling activity, adjusted for local electricity grid (' . number_format($grid_info['gco2_per_kwh']) . ' g CO₂/kWh · ×' . $grid_info['multiplier'] . '). Source: FEDEREC/ADEME LCA 2017 (France base) × IEA 2024. CO₂ avoided (net gain) is calculated separately: primary production emissions − recycling process emissions (ADEME factor).'
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
        // Termes spécifiques EN PREMIER — avant le terme générique 'batterie'
        // Sinon le foreach s'arrête sur 'batterie' (1.80) avant d'atteindre 'batterie plomb' (0.90)
        'batterie lithium' => 4.00,   // Li, Co, Ni — gains élevés
        'lithium battery'  => 4.00,
        'batterie plomb'   => 0.90,   // gain net plomb ~0,42 × teneur ~50% + acier (FEDEREC)
        'lead acid battery'=> 0.90,
        'lead battery'     => 0.90,
        'batterie voiture' => 0.90,   // plomb-acide voiture = même facteur
        'car battery'      => 0.90,
        'batterie acide'   => 0.90,
        // Terme générique APRÈS les spécifiques
        'batterie'         => 1.80,   // défaut batterie générique (plomb-acide standard)
        'battery'          => 1.80,
        'batteries'        => 1.80,
        'accumulateur'     => 1.80,
        'pile'             => 1.50,
        'lithium'          => 4.00,
        'li-ion'           => 4.00,

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

    // Exclure les fiches acheteurs (catégorie "acheteurs" / "buyers")
    $acheteur_slugs = array_map( 'trim', explode( ',', get_option( 'fpt_acheteurs_cat_slug', 'acheteurs' ) ) );
    if ( has_term( $acheteur_slugs, 'hp_listing_category', $post_id ) ) return;

    $titre    = get_the_title( $post_id );
    $poids_kg = fpt_get_poids_kg( $post_id );

    if ( $poids_kg <= 0 ) return;

    $co2 = fpt_calculate_co2( $titre, $poids_kg );

    // Stocker les données de traçabilité sur le lot
    update_post_meta( $post_id, '_fpt_co2_avoided', $co2 );
    update_post_meta( $post_id, '_fpt_lot_id', 'FP-' . strtoupper( substr( md5( $post_id . $titre ), 0, 8 ) ) );
    // _fpt_traced_at : ne mettre la date que lors de la première publication (pas aux updates)
    if ( ! get_post_meta( $post_id, '_fpt_traced_at', true ) ) {
        update_post_meta( $post_id, '_fpt_traced_at', current_time( 'mysql' ) );
    }

    // Synchroniser les totaux globaux en temps réel (via SQL COUNT/SUM)
    // → immunisé contre les doubles comptages lors des mises à jour
    add_action( 'shutdown', 'fpt_sync_options_from_live', 5 );
}

// ─── Stats en temps réel depuis la BDD ────────────────────────────────────────
// Source unique de vérité : on compte les posts publiés avec _fpt_co2_avoided.
// Les options fpt_total_* restent pour rétrocompatibilité mais sont
// synchronisées à chaque recalcul et à chaque suppression/dépublication.
function fpt_get_live_stats() {
    global $wpdb;

    // Une seule requête SQL : COUNT + SUM sur les posts publiés avec CO2 tracé
    // On exclut les fiches acheteurs (pas de champ poids = pas de _fpt_co2_avoided)
    $row = $wpdb->get_row( $wpdb->prepare("
        SELECT
            COUNT( DISTINCT p.ID )          AS lots,
            COALESCE( SUM( pm_co2.meta_value + 0 ), 0 ) AS co2,
            COALESCE( SUM( pm_poids.meta_value + 0 ), 0 ) AS poids
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm_co2
            ON pm_co2.post_id = p.ID
            AND pm_co2.meta_key = '_fpt_co2_avoided'
            AND pm_co2.meta_value != ''
            AND pm_co2.meta_value + 0 > 0
        INNER JOIN {$wpdb->postmeta} pm_poids
            ON pm_poids.post_id = p.ID
            AND pm_poids.meta_key = %s
            AND pm_poids.meta_value + 0 > 0
        WHERE p.post_type   = 'hp_listing'
          AND p.post_status = 'publish'
    ", fpt_key_poids() ) );

    $lots  = (int)   ( $row->lots  ?? 0 );
    $co2   = (float) ( $row->co2   ?? 0 );
    $poids = (float) ( $row->poids ?? 0 );

    return [
        'total_lots'  => $lots,
        'total_co2'   => round( $co2, 4 ),
        'total_poids' => round( $poids, 3 ),
    ];
}

// ─── Mettre à jour les stats globales (appelée au save_post) ──────────────────
// On délègue maintenant à fpt_get_live_stats() pour garder les options à jour.
function fpt_update_global_stats( $co2_new, $poids_new ) {
    // Recalcul live immédiat → synchronise les options
    // Léger délai pour laisser WordPress valider le post avant la requête SQL
    add_action( 'shutdown', 'fpt_sync_options_from_live', 5 );
}

function fpt_sync_options_from_live() {
    $stats = fpt_get_live_stats();
    update_option( 'fpt_total_lots',  $stats['total_lots'],  false );
    update_option( 'fpt_total_co2',   $stats['total_co2'],   false );
    update_option( 'fpt_total_poids', $stats['total_poids'], false );
}

// ─── Décrémenter quand une annonce est dépubliée / mise à la corbeille ────────
add_action( 'transition_post_status', 'fpt_on_listing_status_change', 10, 3 );
function fpt_on_listing_status_change( $new_status, $old_status, $post ) {
    if ( $post->post_type !== 'hp_listing' ) return;
    // On ne réagit que si le post quitte l'état "publish"
    if ( $old_status !== 'publish' ) return;
    if ( $new_status === 'publish' ) return;
    // Resynchroniser les options (le post n'est plus publish → SQL l'exclura)
    add_action( 'shutdown', 'fpt_sync_options_from_live', 5 );
}

// ─── Décrémenter quand une annonce est supprimée définitivement ───────────────
add_action( 'before_delete_post', 'fpt_on_listing_delete', 10, 1 );
function fpt_on_listing_delete( $post_id ) {
    if ( get_post_type( $post_id ) !== 'hp_listing' ) return;
    // Resynchroniser après suppression
    add_action( 'shutdown', 'fpt_sync_options_from_live', 5 );
}

// ─── Recalculer les stats globales depuis zéro (bouton admin) ─────────────────
function fpt_recalculate_global_stats() {
    $listings = get_posts([
        'post_type'   => 'hp_listing',
        'post_status' => 'publish',
        'numberposts' => -1,
        'fields'      => 'ids',
    ]);

    $total_co2 = $total_poids = $total_lots = 0;

    foreach ( $listings as $id ) {
        $poids_kg = (float) fpt_get_poids_kg( $id );
        if ( $poids_kg <= 0 ) continue; // ignorer fiches acheteurs (pas de poids)

        $titre = get_the_title( $id );
        $co2   = fpt_calculate_co2( $titre, $poids_kg );

        update_post_meta( $id, '_fpt_co2_avoided', $co2 );
        update_post_meta( $id, '_fpt_lot_id', 'FP-' . strtoupper( substr( md5( $id . $titre ), 0, 8 ) ) );
        if ( ! get_post_meta( $id, '_fpt_traced_at', true ) ) {
            update_post_meta( $id, '_fpt_traced_at', current_time( 'mysql' ) );
        }

        $total_co2   += $co2;
        $total_poids += $poids_kg;
        $total_lots++;
    }

    update_option( 'fpt_total_co2',   round( $total_co2, 4 ), false );
    update_option( 'fpt_total_poids', $total_poids,           false );
    update_option( 'fpt_total_lots',  $total_lots,            false );

    return $total_lots; // retourne le nombre pour confirmation admin
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
                <span class="fpt-lot-verified">✓ <?php echo fpt_t('Tracé','Traced'); ?></span>
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
                        <span class="fpt-stat-label"><?php echo fpt_t('CO₂ évité','CO₂ avoided'); ?></span>
                    </div>
                    <?php if ( $prix ): ?>
                    <div class="fpt-stat">
                        <span class="fpt-stat-value"><?php echo esc_html( number_format( $prix, 0, ',', ' ' ) ); ?> DH</span>
                        <span class="fpt-stat-label"><?php echo fpt_t('Prix / tonne','Price / tonne'); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ( $traced ): ?>
                <p class="fpt-traced-date"><?php echo fpt_t('Tracé le','Traced on'); ?> <?php echo esc_html( date_i18n( 'd/m/Y' . fpt_t(' à H:i',' H:i'), strtotime( $traced ) ) ); ?></p>
                <?php endif; ?>

                <?php if ( $whatsapp ) :
                    $is_collected = (bool) get_post_meta($post_id, '_fpt_collected', true);
                    echo fpt_whatsapp_btn($whatsapp, $is_collected);
                endif; ?>
            </div>

            <div class="fpt-lot-qr">
                <img src="<?php echo esc_url( $qr_url ); ?>" alt="QR Code lot <?php echo esc_attr( $lot_id ); ?>">
                <p><?php echo fpt_t('Scanner pour accéder à ce lot','Scan to access this batch'); ?></p>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ─── Shortcode : Dashboard global ─────────────────────────────────────────────
add_shortcode( 'fpt_dashboard', 'fpt_shortcode_dashboard' );
function fpt_shortcode_dashboard( $atts ) {
    // ── Lecture en temps réel ─────────────────────────────────────────────────
    // fpt_get_live_stats() compte directement les posts publiés avec _fpt_co2_avoided
    // → immunisé contre les suppressions, la corbeille et les dépublications.
    $live         = fpt_get_live_stats();
    $total_co2    = $live['total_co2'];
    $total_poids  = $live['total_poids'];
    $total_lots   = $live['total_lots'];

    $country_name = get_option( 'fpt_country_name', '' );
    $site_name    = get_option( 'fpt_site_name', 'FerayPro' );

    $in_prep = [ 'fr' => ' au ', 'en' => ' in ', 'es' => ' en ', 'pt' => ' em ' ][ fpt_lang() ] ?? ' in ';
    $title_suffix = $country_name ? $in_prep . $country_name : '';
    $subtitle     = fpt_t('Traçabilité en temps réel des déchets recyclés','Real-time traceability of recycled waste') . ( $country_name ? $in_prep . $country_name : '' );

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
            // CO₂ produit par le recyclage formel — somme réelle des facteurs ADEME/FEDEREC
            // Calcul : pour chaque lot, on applique fpt_calculate_process_co2(titre, poids)
            // Conforme ADEME Base Carbone — remplace l'ancien forfait arbitraire de 10%
            $co2_recyclage_process = 0.0;
            foreach ( $all_ids as $lot_id ) {
                $lot_titre = get_the_title( $lot_id );
                $lot_poids = fpt_get_poids_kg( $lot_id );
                if ( $lot_poids > 0 ) {
                    $co2_recyclage_process += fpt_calculate_process_co2( $lot_titre, $lot_poids );
                }
            }
            $co2_recyclage_process = round( $co2_recyclage_process, 2 );
            $co2_produit_total = $co2_recyclage_process;
            ?>
            <div class="fpt-impact-card" style="border-top:3px solid #e67e22">
                <div class="fpt-impact-icon">🏭</div>
                <div class="fpt-impact-value" style="color:#e67e22"><?php echo esc_html( number_format( $co2_produit_total, 2 ) ); ?> t</div>
                <div class="fpt-impact-label"><?php echo fpt_t('CO₂ produit (recyclage)','CO₂ produced (recycling)'); ?></div>
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

// ─── CSS frontend + admin ──────────────────────────────────────────────────────
add_action( 'wp_enqueue_scripts',    'fpt_enqueue_styles' );
add_action( 'admin_enqueue_scripts', 'fpt_enqueue_styles' );
function fpt_enqueue_styles() {
    wp_enqueue_style( 'feraypro-tracer', FPT_PLUGIN_URL . 'tracer.css', [], FPT_VERSION );
    // CSS admin FerayPro — injecté uniquement sur les pages du plugin
    if ( isset( $_GET['page'] ) && strpos( $_GET['page'], 'feraypro' ) === 0 ) {
        wp_enqueue_style( 'fpt-admin', FPT_PLUGIN_URL . 'modules/admin/admin.css', [], FPT_VERSION );
    }
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
        <div class="fpt-meth-source"><strong>FEDEREC/ADEME ACV 2017</strong> — <?php echo fpt_t('Facteurs gain net : acier, cuivre, papier (ACV)','Net gain factors: steel, copper, paper (LCA)'); ?> — federec.com</div>
        <div class="fpt-meth-source"><strong>EPA WARM v16 (2024)</strong> — <?php echo fpt_t('Waste Reduction Model — Facteurs de recyclage USA (cross-validation)','Waste Reduction Model — US recycling factors (cross-validation)'); ?> — epa.gov/warm</div>
        <div class="fpt-meth-source"><strong>IEA Electricity (2024)</strong> — <?php echo fpt_t('Intensité carbone des réseaux électriques nationaux — ajustement CO₂ process','Carbon intensity of national electricity grids — process CO₂ adjustment'); ?> — iea.org</div>
        <div class="fpt-meth-source"><strong>EPA eGRID (2023)</strong> — <?php echo fpt_t('Mix électrique USA — 380 g CO₂/kWh (mix national)','US electricity grid mix — 380 g CO₂/kWh (national average)'); ?> — epa.gov/egrid</div>
        <div class="fpt-meth-source"><strong>ONEE/MASEN (2024)</strong> — <?php echo fpt_t('Mix électrique Maroc — 644 g CO₂/kWh (charbon + gaz, éolien en forte hausse)','Morocco electricity mix — 644 g CO₂/kWh (coal + gas, wind growing fast)'); ?></div>
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
    if ( isset( $_POST['fpt_cleanup_refs'] ) && check_admin_referer('fpt_recalc') ) {
        // Supprimer _fpt_ref des fiches acheteurs (correction rétroactive)
        $acheteur_slugs = array_map( 'trim', explode( ',', get_option( 'fpt_acheteurs_cat_slug', 'acheteurs' ) ) );
        $acheteurs_ids  = get_posts([
            'post_type'   => 'hp_listing',
            'post_status' => ['publish','draft','trash'],
            'numberposts' => -1,
            'fields'      => 'ids',
            'tax_query'   => [[
                'taxonomy' => 'hp_listing_category',
                'field'    => 'slug',
                'terms'    => $acheteur_slugs,
            ]],
        ]);
        $cleaned = 0;
        foreach ( $acheteurs_ids as $id ) {
            if ( get_post_meta( $id, '_fpt_ref', true ) ) {
                delete_post_meta( $id, '_fpt_ref' );
                $cleaned++;
            }
        }
        // Aussi supprimer _fpt_ref des lots sans poids (sécurité supplémentaire)
        $no_poids = get_posts([
            'post_type'   => 'hp_listing',
            'post_status' => ['publish','draft'],
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [
                'relation' => 'AND',
                [ 'key' => '_fpt_ref', 'compare' => 'EXISTS' ],
                [ 'key' => fpt_key_poids(), 'value' => '0', 'compare' => '<=', 'type' => 'NUMERIC' ],
            ],
        ]);
        foreach ( $no_poids as $id ) {
            delete_post_meta( $id, '_fpt_ref' );
            $cleaned++;
        }
        echo '<div class="notice notice-success"><p>✅ Nettoyage terminé — <strong>' . $cleaned . ' _fpt_ref orphelins supprimés</strong> des fiches acheteurs et lots sans poids.</p></div>';
    }

    if ( isset( $_POST['fpt_recalculate'] ) && check_admin_referer('fpt_recalc') ) {
        $n = fpt_recalculate_global_stats();
        echo '<div class="notice notice-success"><p>✅ Stats recalculées depuis zéro — <strong>' . $n . ' lots actifs</strong> trouvés (annonces publiées avec poids).</p></div>';
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
        update_option( 'fpt_language', in_array( $_POST['fpt_language'] ?? '', ['','fr','en','es','pt'] ) ? $_POST['fpt_language'] : '' );
        if (isset($_POST['fpt_invoice_language'])) update_option( 'fpt_invoice_language', in_array( $_POST['fpt_invoice_language'], ['fr','en','es','pt'] ) ? $_POST['fpt_invoice_language'] : 'fr' );
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
        // Facture & Commission
        if (isset($_POST['fpt_currency']))           update_option('fpt_currency',            sanitize_text_field($_POST['fpt_currency']));
        if (isset($_POST['fpt_tva_rate']))            update_option('fpt_tva_rate',             sanitize_text_field($_POST['fpt_tva_rate']));
        if (isset($_POST['fpt_rib_iban']))            update_option('fpt_rib_iban',             sanitize_text_field($_POST['fpt_rib_iban']));
        if (isset($_POST['fpt_rib_whatsapp']))        update_option('fpt_rib_whatsapp',         sanitize_text_field($_POST['fpt_rib_whatsapp']));
        if (isset($_POST['fpt_adresse_facturation'])) update_option('fpt_adresse_facturation',  sanitize_text_field($_POST['fpt_adresse_facturation']));
        // Mix électrique
        if (isset($_POST['fpt_grid_intensity_override'])) update_option('fpt_grid_intensity_override', max(0, intval($_POST['fpt_grid_intensity_override'])));
        echo '<div class="notice notice-success"><p>Paramètres sauvegardés / Settings saved.</p></div>';
    }
    // ── Stats en temps réel pour l'admin ───────────────────────────────────────
    $live_stats   = fpt_get_live_stats();
    $total_co2    = $live_stats['total_co2'];
    $total_poids  = $live_stats['total_poids'];
    $total_lots   = $live_stats['total_lots'];
    // Synchroniser les options avec la réalité
    update_option( 'fpt_total_lots',  $total_lots,  false );
    update_option( 'fpt_total_co2',   $total_co2,   false );
    update_option( 'fpt_total_poids', $total_poids, false );
    $country_name = get_option( 'fpt_country_name', 'votre pays' );
    $site_name    = get_option( 'fpt_site_name', 'FerayPro' );
    ?>
    <div class="wrap fpt-admin-wrap">

    <!-- ── HEADER ──────────────────────────────────────────────────────────── -->
    <div class="fpt-adm-header">
        <div class="fpt-adm-header-left">
            <div class="fpt-adm-logo">FP</div>
            <div>
                <h1 class="fpt-adm-title">FerayPro Tracer</h1>
                <p class="fpt-adm-subtitle">v<?php echo FPT_VERSION; ?> · <?php echo esc_html($site_name); ?> · <?php echo esc_html($country_name); ?></p>
            </div>
        </div>
        <div class="fpt-adm-kpis">
            <div class="fpt-adm-kpi fpt-adm-kpi--dark">
                <span class="fpt-adm-kpi-val"><?php echo number_format($total_lots); ?></span>
                <span class="fpt-adm-kpi-lbl">Lots tracés</span>
            </div>
            <div class="fpt-adm-kpi fpt-adm-kpi--green">
                <span class="fpt-adm-kpi-val"><?php echo number_format($total_co2,2); ?> t</span>
                <span class="fpt-adm-kpi-lbl">CO₂ évité</span>
            </div>
            <div class="fpt-adm-kpi">
                <span class="fpt-adm-kpi-val"><?php echo number_format($total_poids,0,'',''); ?> kg</span>
                <span class="fpt-adm-kpi-lbl">Poids total</span>
            </div>
        </div>
    </div>

    <!-- ── TABS ──────────────────────────────────────────────────────────────── -->
    <div class="fpt-adm-tabs">
        <button class="fpt-adm-tab active" onclick="fptTab('settings',this)">⚙️ Paramètres</button>
        <button class="fpt-adm-tab" onclick="fptTab('tools',this)">🔧 Outils</button>
        <button class="fpt-adm-tab" onclick="fptTab('shortcodes',this)">📋 Shortcodes</button>
        <button class="fpt-adm-tab" onclick="fptTab('factors',this)">🌱 Facteurs CO₂</button>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB : PARAMÈTRES
    ══════════════════════════════════════════════════════════════════════ -->
    <div id="fpt-tab-settings" class="fpt-adm-tabcontent" style="display:block">
        <form method="post">
            <?php wp_nonce_field('fpt_settings'); ?>

            <!-- Section : Site -->
            <div class="fpt-adm-card">
                <div class="fpt-adm-card-head">
                    <span class="fpt-adm-card-icon">🏢</span>
                    <div>
                        <h2 class="fpt-adm-card-title">Identité du site</h2>
                        <p class="fpt-adm-card-desc">Nom, pays et langue affichés sur les pages publiques</p>
                    </div>
                </div>
                <div class="fpt-adm-fields">
                    <div class="fpt-adm-field">
                        <label for="fpt_site_name">Nom de la plateforme</label>
                        <input type="text" id="fpt_site_name" name="fpt_site_name"
                               value="<?php echo esc_attr($site_name); ?>" placeholder="FerayPro">
                        <span class="fpt-adm-hint">Affiché dans les blocs inline et factures</span>
                    </div>
                    <div class="fpt-adm-field">
                        <label for="fpt_country_name">Pays / Région</label>
                        <input type="text" id="fpt_country_name" name="fpt_country_name"
                               value="<?php echo esc_attr($country_name); ?>" placeholder="ex : Maroc, Congo, France, USA">
                    </div>
                    <div class="fpt-adm-field">
                        <label for="fpt_language">Langue interface front-end</label>
                        <select id="fpt_language" name="fpt_language">
                            <option value=""   <?php selected(get_option('fpt_language',''),'');   ?>>🌐 Auto (détection par domaine)</option>
                            <option value="fr" <?php selected(get_option('fpt_language',''),'fr'); ?>>🇫🇷 Français (forcé)</option>
                            <option value="en" <?php selected(get_option('fpt_language',''),'en'); ?>>🇬🇧 English (forcé)</option>
                            <option value="es" <?php selected(get_option('fpt_language',''),'es'); ?>>🇪🇸 Español (forcé)</option>
                            <option value="pt" <?php selected(get_option('fpt_language',''),'pt'); ?>>🇵🇹 Português (forcé)</option>
                        </select>
                        <span class="fpt-adm-hint"><code>.fr/ma./.cd</code> → FR · <code>feraypro.com/.us</code> → EN · <code>es.</code> → ES</span>
                    </div>
                    <div class="fpt-adm-field">
                        <label for="fpt_invoice_language">Langue de la facture PDF</label>
                        <select id="fpt_invoice_language" name="fpt_invoice_language">
                            <option value="fr" <?php selected(get_option('fpt_invoice_language','fr'),'fr'); ?>>🇫🇷 Français</option>
                            <option value="en" <?php selected(get_option('fpt_invoice_language','fr'),'en'); ?>>🇬🇧 English</option>
                            <option value="es" <?php selected(get_option('fpt_invoice_language','fr'),'es'); ?>>🇪🇸 Español</option>
                            <option value="pt" <?php selected(get_option('fpt_invoice_language','fr'),'pt'); ?>>🇵🇹 Português</option>
                        </select>
                        <span class="fpt-adm-hint">N'affecte pas l'interface admin</span>
                    </div>
                </div>
            </div>

            <!-- Section : HivePress -->
            <div class="fpt-adm-card">
                <div class="fpt-adm-card-head">
                    <span class="fpt-adm-card-icon">🔧</span>
                    <div>
                        <h2 class="fpt-adm-card-title">Meta keys HivePress</h2>
                        <p class="fpt-adm-card-desc">Nom des champs dans l'attribut HivePress (Field Name)</p>
                    </div>
                </div>
                <div class="fpt-adm-fields fpt-adm-fields--grid">
                    <div class="fpt-adm-field">
                        <label for="fpt_key_poids">Champ Poids</label>
                        <input type="text" id="fpt_key_poids" name="fpt_key_poids"
                               value="<?php echo esc_attr(get_option('fpt_key_poids','poids')); ?>" placeholder="poids">
                        <span class="fpt-adm-hint">🇫🇷 poids · 🇬🇧 weight</span>
                    </div>
                    <div class="fpt-adm-field">
                        <label for="fpt_weight_unit">Unité de poids</label>
                        <select id="fpt_weight_unit" name="fpt_weight_unit">
                            <option value="kg" <?php selected(get_option('fpt_weight_unit','kg'),'kg'); ?>>kg — kilogrammes</option>
                            <option value="lb" <?php selected(get_option('fpt_weight_unit','kg'),'lb'); ?>>lb — livres (auto-converti en kg)</option>
                        </select>
                    </div>
                    <div class="fpt-adm-field">
                        <label for="fpt_key_ville">Champ Ville</label>
                        <input type="text" id="fpt_key_ville" name="fpt_key_ville"
                               value="<?php echo esc_attr(get_option('fpt_key_ville','ville')); ?>" placeholder="ville">
                        <span class="fpt-adm-hint">🇫🇷 ville · 🇬🇧 city</span>
                    </div>
                    <div class="fpt-adm-field">
                        <label for="fpt_key_whatsapp">Champ Téléphone</label>
                        <input type="text" id="fpt_key_whatsapp" name="fpt_key_whatsapp"
                               value="<?php echo esc_attr(get_option('fpt_key_whatsapp','whatsapp')); ?>" placeholder="whatsapp">
                        <span class="fpt-adm-hint">🇫🇷 whatsapp · 🇬🇧 telephone</span>
                    </div>
                    <div class="fpt-adm-field">
                        <label for="fpt_key_prix">Champ Prix vendeur</label>
                        <input type="text" id="fpt_key_prix" name="fpt_key_prix"
                               value="<?php echo esc_attr(get_option('fpt_key_prix','prixvendeur')); ?>" placeholder="prixvendeur">
                        <span class="fpt-adm-hint">🇫🇷 prixvendeur · 🇬🇧 pricebuyer</span>
                    </div>
                    <div class="fpt-adm-field">
                        <label for="fpt_key_prix_jour">Slug prix du jour</label>
                        <input type="text" id="fpt_key_prix_jour" name="fpt_key_prix_jour"
                               value="<?php echo esc_attr(get_option('fpt_key_prix_jour','prix')); ?>" placeholder="prix">
                        <span class="fpt-adm-hint">🇫🇷 prix · 🇬🇧 price</span>
                    </div>
                    <div class="fpt-adm-field">
                        <label for="fpt_key_buyersprice">Champ prix acheteur</label>
                        <input type="text" id="fpt_key_buyersprice" name="fpt_key_buyersprice"
                               value="<?php echo esc_attr(get_option('fpt_key_buyersprice','')); ?>" placeholder="prixacheteur">
                    </div>
                    <div class="fpt-adm-field">
                        <label for="fpt_prix_cat_slug">Catégorie slug prix du jour</label>
                        <input type="text" id="fpt_prix_cat_slug" name="fpt_prix_cat_slug"
                               value="<?php echo esc_attr(get_option('fpt_prix_cat_slug','prix')); ?>" placeholder="prix">
                        <span class="fpt-adm-hint">Slug de la catégorie HivePress contenant les prix</span>
                    </div>
                    <div class="fpt-adm-field">
                        <label for="fpt_acheteurs_cat_slug">Catégorie slug acheteurs</label>
                        <input type="text" id="fpt_acheteurs_cat_slug" name="fpt_acheteurs_cat_slug"
                               value="<?php echo esc_attr(get_option('fpt_acheteurs_cat_slug','acheteurs')); ?>" placeholder="acheteurs">
                        <span class="fpt-adm-hint">Supporte plusieurs slugs séparés par virgule : <code>acheteurs,buyers</code></span>
                    </div>
                    <div class="fpt-adm-field">
                        <label for="fpt_prix_category_slug">Catégorie slug prix marché</label>
                        <input type="text" id="fpt_prix_category_slug" name="fpt_prix_category_slug"
                               value="<?php echo esc_attr(get_option('fpt_prix_category_slug','')); ?>" placeholder="prix-marche">
                    </div>
                </div>
            </div>

            <!-- Section : Facturation -->
            <div class="fpt-adm-card">
                <div class="fpt-adm-card-head">
                    <span class="fpt-adm-card-icon">📄</span>
                    <div>
                        <h2 class="fpt-adm-card-title">Facturation & Commission</h2>
                        <p class="fpt-adm-card-desc">Devise, TVA et coordonnées bancaires pour les factures PDF</p>
                    </div>
                </div>
                <div class="fpt-adm-fields fpt-adm-fields--grid">
                    <div class="fpt-adm-field">
                        <label for="fpt_currency">Devise</label>
                        <select id="fpt_currency" name="fpt_currency">
                            <?php
                            $currencies = [
                                'MAD'=>'MAD — Dirham marocain','EUR'=>'EUR — Euro','USD'=>'USD — Dollar US',
                                'CDF'=>'CDF — Franc congolais','XOF'=>'XOF — Franc CFA Ouest','XAF'=>'XAF — Franc CFA Central',
                                'GBP'=>'GBP — Livre sterling','CAD'=>'CAD — Dollar canadien','AUD'=>'AUD — Dollar australien',
                                'CHF'=>'CHF — Franc suisse','NGN'=>'NGN — Naira','GHS'=>'GHS — Cedi','KES'=>'KES — Shilling kényan',
                                'TZS'=>'TZS — Shilling tanzanien','ZAR'=>'ZAR — Rand','EGP'=>'EGP — Livre égyptienne',
                                'TND'=>'TND — Dinar tunisien','DZD'=>'DZD — Dinar algérien','BRL'=>'BRL — Real',
                                'MXN'=>'MXN — Peso mexicain','INR'=>'INR — Roupie','SAR'=>'SAR — Riyal saoudien',
                                'AED'=>'AED — Dirham UAE','TRY'=>'TRY — Livre turque','CNY'=>'CNY — Yuan',
                                'JPY'=>'JPY — Yen','KRW'=>'KRW — Won',
                            ];
                            $cur = fpt_get_currency();
                            foreach ($currencies as $v => $l):
                            ?>
                            <option value="<?php echo $v; ?>" <?php selected($cur,$v); ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fpt-adm-field">
                        <label for="fpt_tva_rate">TVA (%)</label>
                        <div class="fpt-adm-input-row">
                            <input type="number" id="fpt_tva_rate" name="fpt_tva_rate"
                                   value="<?php echo esc_attr(get_option('fpt_tva_rate','0')); ?>"
                                   min="0" max="100" step="0.1" style="width:90px"> <span>%</span>
                        </div>
                        <span class="fpt-adm-hint">0 = exonéré · France : 20 · RDC : 16 · Maroc : 20</span>
                    </div>
                    <div class="fpt-adm-field">
                        <label for="fpt_rib_iban">IBAN / RIB</label>
                        <input type="text" id="fpt_rib_iban" name="fpt_rib_iban"
                               value="<?php echo esc_attr(get_option('fpt_rib_iban','')); ?>" placeholder="MA64 0000 ...">
                    </div>
                    <div class="fpt-adm-field">
                        <label for="fpt_rib_whatsapp">WhatsApp / Mobile Money</label>
                        <input type="text" id="fpt_rib_whatsapp" name="fpt_rib_whatsapp"
                               value="<?php echo esc_attr(get_option('fpt_rib_whatsapp','')); ?>" placeholder="+212 6XX XXX XXX">
                    </div>
                    <div class="fpt-adm-field fpt-adm-field--full">
                        <label for="fpt_adresse_facturation">Adresse de facturation</label>
                        <input type="text" id="fpt_adresse_facturation" name="fpt_adresse_facturation"
                               value="<?php echo esc_attr(get_option('fpt_adresse_facturation','')); ?>"
                               placeholder="Casablanca, Maroc">
                    </div>
                </div>
            </div>

            <!-- Section : Mix électrique (CO₂ process) -->
            <?php $grid_info = fpt_grid_intensity(); ?>
            <div class="fpt-adm-card">
                <div class="fpt-adm-card-head">
                    <span class="fpt-adm-card-icon">⚡</span>
                    <div>
                        <h2 class="fpt-adm-card-title">Mix électrique — CO₂ process recyclage</h2>
                        <p class="fpt-adm-card-desc">
                            Intensité carbone du réseau électrique local · Ajuste le CO₂ produit par le process de recyclage (dashboard acheteur uniquement)
                        </p>
                    </div>
                </div>
                <div class="fpt-adm-fields">
                    <!-- Affichage de la valeur détectée automatiquement -->
                    <div style="background:var(--gray);border:1.5px solid var(--border);border-radius:8px;padding:14px 18px;font-size:13px">
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <span style="font-size:20px">🌍</span>
                            <div>
                                <strong style="font-size:14px;color:var(--dark)">
                                    Pays détecté : <?php echo esc_html( $country_name ?: '(non configuré)' ); ?>
                                </strong><br>
                                <span style="color:var(--muted)">
                                    Intensité réseau : <strong style="font-family:var(--mono);color:var(--g)"><?php echo number_format($grid_info['gco2_per_kwh']); ?> g CO₂/kWh</strong>
                                    &nbsp;·&nbsp; Multiplicateur : <strong style="font-family:var(--mono);color:var(--g)">×<?php echo $grid_info['multiplier']; ?></strong>
                                    &nbsp;·&nbsp; <span style="font-size:11px"><?php echo esc_html($grid_info['source']); ?></span>
                                </span>
                            </div>
                        </div>
                        <?php
                        // Exemples concrets avec le multiplicateur actuel
                        $m   = $grid_info['multiplier'];
                        $alu = round(0.36 * $m, 3);
                        $cu  = round(1.304 * $m, 3);
                        $fe  = round(1.10 * $m, 3);
                        ?>
                        <div style="margin-top:12px;display:flex;gap:12px;flex-wrap:wrap">
                            <span style="background:var(--white);border:1px solid var(--border);border-radius:5px;padding:5px 10px;font-size:12px">
                                🔵 Alu : <strong style="font-family:var(--mono)"><?php echo $alu; ?> t CO₂/t</strong>
                            </span>
                            <span style="background:var(--white);border:1px solid var(--border);border-radius:5px;padding:5px 10px;font-size:12px">
                                🟤 Cuivre : <strong style="font-family:var(--mono)"><?php echo $cu; ?> t CO₂/t</strong>
                            </span>
                            <span style="background:var(--white);border:1px solid var(--border);border-radius:5px;padding:5px 10px;font-size:12px">
                                ⚙️ Acier : <strong style="font-family:var(--mono)"><?php echo $fe; ?> t CO₂/t</strong>
                            </span>
                        </div>
                        <p style="font-size:11px;color:var(--muted);margin-top:8px;margin-bottom:0">
                            ⚠️ Ces valeurs s'appliquent uniquement au <strong>CO₂ process</strong> (dashboard acheteur).
                            Le <strong>CO₂ évité</strong> (gain net, dashboard public) reste ADEME/FEDEREC — universel.
                        </p>
                    </div>

                    <!-- Surcharge manuelle -->
                    <div class="fpt-adm-field">
                        <label for="fpt_grid_intensity_override">Surcharge manuelle (optionnel)</label>
                        <div class="fpt-adm-input-row">
                            <input type="number" id="fpt_grid_intensity_override" name="fpt_grid_intensity_override"
                                   value="<?php echo esc_attr(get_option('fpt_grid_intensity_override','0')); ?>"
                                   min="0" max="1200" step="1" style="width:100px">
                            <span>g CO₂/kWh (0 = détection automatique par pays)</span>
                        </div>
                        <span class="fpt-adm-hint">
                            Références : 🇫🇷 France 45 · 🇺🇸 USA 380 · 🇲🇦 Maroc 644 · 🇨🇩 RDC 35 · 🌍 Mondiale 380
                            — Sources : IEA 2024, EPA eGRID 2023, ONEE/MASEN 2024
                        </span>
                    </div>
                </div>
            </div>

            <?php do_action( 'fpt_admin_settings_extra_cards' ); ?>

            <div class="fpt-adm-save-bar">
                <button type="submit" name="fpt_save_settings" class="fpt-adm-btn fpt-adm-btn--primary">
                    💾 Sauvegarder les paramètres
                </button>
            </div>
        </form>
    </div><!-- #fpt-tab-settings -->

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB : OUTILS
    ══════════════════════════════════════════════════════════════════════ -->
    <div id="fpt-tab-tools" class="fpt-adm-tabcontent" style="display:none">

        <div class="fpt-adm-card">
            <div class="fpt-adm-card-head">
                <span class="fpt-adm-card-icon">🔄</span>
                <div>
                    <h2 class="fpt-adm-card-title">Recalculer les stats CO₂</h2>
                    <p class="fpt-adm-card-desc">Utile après un import d'annonces ou une migration</p>
                </div>
            </div>
            <div class="fpt-adm-fields">
                <form method="post">
                    <?php wp_nonce_field('fpt_recalc'); ?>
                    <p style="color:#6b8070;font-size:13px;margin-bottom:12px">
                        Recalcule le CO₂ évité de chaque lot depuis zéro en utilisant les facteurs ADEME/FEDEREC.
                        Recalcule aussi les totaux globaux (lots, poids, CO₂).
                    </p>
                    <button type="submit" name="fpt_recalculate" class="fpt-adm-btn fpt-adm-btn--primary">
                        🔄 Recalculer CO₂ depuis zéro
                    </button>
                </form>
            </div>
        </div>

        <div class="fpt-adm-card">
            <div class="fpt-adm-card-head">
                <span class="fpt-adm-card-icon">🧹</span>
                <div>
                    <h2 class="fpt-adm-card-title">Nettoyer les attributions partenaires</h2>
                    <p class="fpt-adm-card-desc">Corriger les <code>_fpt_ref</code> orphelins sur fiches acheteurs (bug avant v1.9.0)</p>
                </div>
            </div>
            <div class="fpt-adm-fields">
                <form method="post">
                    <?php wp_nonce_field('fpt_recalc'); ?>
                    <p style="color:#b45309;font-size:13px;margin-bottom:12px">
                        Supprime le champ <code>_fpt_ref</code> sur toutes les fiches de la catégorie
                        <strong><?php echo esc_html(get_option('fpt_acheteurs_cat_slug','acheteurs')); ?></strong>
                        et sur les lots avec poids = 0. À exécuter <strong>une fois</strong> après la mise à jour v1.9.0.
                    </p>
                    <button type="submit" name="fpt_cleanup_refs" class="fpt-adm-btn fpt-adm-btn--warn">
                        🧹 Nettoyer les _fpt_ref orphelins
                    </button>
                </form>
            </div>
        </div>

        <div class="fpt-adm-card">
            <div class="fpt-adm-card-head">
                <span class="fpt-adm-card-icon">🚛</span>
                <div>
                    <h2 class="fpt-adm-card-title">Recalculer le CO₂ transport</h2>
                    <p class="fpt-adm-card-desc">Correction bug avant v1.6.1 — à exécuter une seule fois</p>
                </div>
            </div>
            <div class="fpt-adm-fields">
                <form method="post">
                    <?php wp_nonce_field('fpt_recalc'); ?>
                    <p style="color:#6b8070;font-size:13px;margin-bottom:8px">
                        Formule correcte : (Poids kg ÷ 1000) × <?php echo fpt_transport_distance_km(); ?> km × 0,062 = t CO₂<br>
                        <strong>Exemple :</strong> 30 kg × 150 km → <strong>0,000279 t CO₂</strong>
                    </p>
                    <button type="submit" name="fpt_recalc_transport" class="fpt-adm-btn fpt-adm-btn--danger">
                        🔧 Recalculer CO₂ transport
                    </button>
                </form>
            </div>
        </div>

    </div><!-- #fpt-tab-tools -->

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB : SHORTCODES
    ══════════════════════════════════════════════════════════════════════ -->
    <div id="fpt-tab-shortcodes" class="fpt-adm-tabcontent" style="display:none">
        <div class="fpt-adm-card">
            <div class="fpt-adm-card-head">
                <span class="fpt-adm-card-icon">📋</span>
                <div>
                    <h2 class="fpt-adm-card-title">Shortcodes disponibles</h2>
                    <p class="fpt-adm-card-desc">Coller dans n'importe quelle page WordPress</p>
                </div>
            </div>
            <div class="fpt-adm-shortcodes">
                <?php
                $scs = [
                    ['[fpt_dashboard]',                    '🌍', 'Dashboard impact environnemental global',   ''],
                    ['[fpt_dashboard_finance]',            '💰', 'Dashboard financier — ventes, commissions, partenaires', 'new'],
                    ['[fpt_lot id="241"]',                 '📦', 'Fiche publique d\'un lot (remplacer 241 par l\'ID)',    ''],
                    ['[fpt_methodologie]',                 '📖', 'Page méthodologie complète (facteurs ADEME)', ''],
                    ['[fpt_acheteur id="XXX"]',            '🏭', 'Dashboard d\'un acheteur régulier',          ''],
                    ['[fpt_partenaires]',                  '🤝', 'Grille publique des partenaires',            ''],
                ];
                foreach ($scs as [$sc, $icon, $desc, $badge]):
                ?>
                <div class="fpt-adm-sc-row">
                    <span class="fpt-adm-sc-icon"><?php echo $icon; ?></span>
                    <div class="fpt-adm-sc-info">
                        <code class="fpt-adm-sc-code"><?php echo esc_html($sc); ?></code>
                        <span class="fpt-adm-sc-desc"><?php echo esc_html($desc); ?></span>
                    </div>
                    <?php if ($badge === 'new'): ?>
                    <span class="fpt-adm-badge-new">NEW v1.9</span>
                    <?php endif; ?>
                    <button type="button" class="fpt-adm-btn fpt-adm-btn--copy"
                            onclick="navigator.clipboard.writeText('<?php echo esc_js($sc); ?>');this.textContent='✅ Copié';setTimeout(()=>this.textContent='📋 Copier',1500)">
                        📋 Copier
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div><!-- #fpt-tab-shortcodes -->

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB : FACTEURS CO₂
    ══════════════════════════════════════════════════════════════════════ -->
    <div id="fpt-tab-factors" class="fpt-adm-tabcontent" style="display:none">
        <div class="fpt-adm-card">
            <div class="fpt-adm-card-head">
                <span class="fpt-adm-card-icon">🌱</span>
                <div>
                    <h2 class="fpt-adm-card-title">Facteurs CO₂ utilisés (ADEME / FEDEREC)</h2>
                    <p class="fpt-adm-card-desc">t CO₂ évité par tonne de matière recyclée — source : Base Carbone ADEME · ACV FEDEREC 2017</p>
                </div>
            </div>
            <div class="fpt-adm-factors-grid">
                <?php foreach ( fpt_co2_factors() as $mat => $val ):
                    if ($mat === 'default') continue;
                    $pct = min(100, round($val / 15 * 100));
                ?>
                <div class="fpt-adm-factor-row">
                    <span class="fpt-adm-factor-name"><?php echo esc_html(ucfirst($mat)); ?></span>
                    <div class="fpt-adm-factor-bar-wrap">
                        <div class="fpt-adm-factor-bar" style="width:<?php echo $pct; ?>%"></div>
                    </div>
                    <span class="fpt-adm-factor-val"><?php echo $val; ?> t/t</span>
                </div>
                <?php endforeach; ?>
                <div class="fpt-adm-factor-row fpt-adm-factor-row--default">
                    <span class="fpt-adm-factor-name">Défaut (non reconnu)</span>
                    <div class="fpt-adm-factor-bar-wrap">
                        <div class="fpt-adm-factor-bar" style="width:3.3%;background:#6b8070"></div>
                    </div>
                    <span class="fpt-adm-factor-val"><?php echo fpt_co2_factors()['default']; ?> t/t</span>
                </div>
            </div>
        </div>
    </div><!-- #fpt-tab-factors -->

    </div><!-- .fpt-admin-wrap -->

    <script>
    function fptTab(id, btn) {
        document.querySelectorAll('.fpt-adm-tabcontent').forEach(t => t.style.display = 'none');
        document.querySelectorAll('.fpt-adm-tab').forEach(t => t.classList.remove('active'));
        document.getElementById('fpt-tab-' + id).style.display = 'block';
        btn.classList.add('active');
    }
    // Activer l'onglet "Outils" si un message de succès lié aux outils est présent
    document.addEventListener('DOMContentLoaded', function() {
        var notices = document.querySelectorAll('.notice-success p');
        notices.forEach(function(n) {
            if (n.textContent.includes('recalcul') || n.textContent.includes('Nettoyage') || n.textContent.includes('transport')) {
                fptTab('tools', document.querySelectorAll('.fpt-adm-tab')[1]);
            }
        });
    });
    </script>
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

    // Ne pas attribuer de ref aux fiches acheteurs
    $acheteur_slugs = array_map( 'trim', explode( ',', get_option( 'fpt_acheteurs_cat_slug', 'acheteurs' ) ) );
    if ( has_term( $acheteur_slugs, 'hp_listing_category', $post_id ) ) return;

    // Ne pas écraser si déjà défini
    $existing = get_post_meta( $post_id, '_fpt_ref', true );
    if ( $existing ) return;

    $ref = isset( $_COOKIE['fpt_ref'] ) ? sanitize_key( $_COOKIE['fpt_ref'] ) : '';
    if ( ! $ref ) return;

    // Vérifier que le partenaire est toujours actif
    $partenaires = fpt_get_partenaires_list();
    $actifs      = array_filter( $partenaires, fn($p) => ! empty( $p['actif'] ) );
    $slugs       = array_column( $actifs, 'slug' );
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
    // Exclure les fiches acheteurs de la catégorie "acheteurs"/"buyers"
    $acheteur_slugs = array_map( 'trim', explode( ',', get_option( 'fpt_acheteurs_cat_slug', 'acheteurs' ) ) );

    $lots = get_posts([
        'post_type'      => 'hp_listing',
        'post_status'    => 'publish',
        'numberposts'    => -1,
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'AND',
            [ 'key' => '_fpt_ref',       'value' => sanitize_key( $slug ) ],
            [ 'key' => fpt_key_poids(),  'value' => '0', 'compare' => '>', 'type' => 'NUMERIC' ],
        ],
        'tax_query' => [[
            'taxonomy' => 'hp_listing_category',
            'field'    => 'slug',
            'terms'    => $acheteur_slugs,
            'operator' => 'NOT IN',
        ]],
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

    $clicks     = (int) get_option( 'fpt_clicks_' . sanitize_key( $slug ), 0 );
    // Conversion : nb lots publiés / nb clics. Plafonné à 100% pour rester lisible.
    // Un taux > 100% signale un problème de données (ex: cookies multi-devices).
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

// ══════════════════════════════════════════════════════════════════════════════
// MODULE COMMISSION — Facturation 20% FerayPro + 10% Partenaire
// ══════════════════════════════════════════════════════════════════════════════
// Logique financière :
//   Prix total lot  = 100%
//   Acheteur paie vendeur  = 80% (directement)
//   Acheteur envoie FerayPro = 20% (via cette facture)
//   FerayPro redistribue :
//     → Partenaire marketing = 10% du prix total
//     → FerayPro net         = 10% du prix total

// ─── Helper : récupérer le prix du lot en nombre ─────────────────────────────
function fpt_get_prix_lot( $post_id ) {
    // D'abord notre champ dédié (saisi dans la metabox)
    $val = get_post_meta( $post_id, '_fpt_prix_lot', true );
    if ( $val ) return (float) $val;
    // Sinon le champ HivePress configuré
    $key = 'hp_' . get_option('fpt_key_prix', 'prixvendeur');
    $val = get_post_meta( $post_id, $key, true );
    $val = preg_replace('/[^\d.]/', '', str_replace(',', '.', $val));
    return (float) $val;
}

// ─── Helper : numéro de facture unique ───────────────────────────────────────
function fpt_invoice_number( $post_id ) {
    $stored = get_post_meta( $post_id, '_fpt_invoice_number', true );
    if ( $stored ) return $stored;
    // Générer : FP-INV-YYYYMM-{post_id}
    $num = 'FP-INV-' . date('Ym') . '-' . $post_id;
    update_post_meta( $post_id, '_fpt_invoice_number', $num );
    return $num;
}

// ─── Helper : données acheteur (email, téléphone) ────────────────────────────
function fpt_get_acheteur_contact( $acheteur_id ) {
    if ( ! $acheteur_id ) return [];
    return [
        'nom'       => get_the_title( $acheteur_id ),
        'ville'     => get_post_meta( $acheteur_id, fpt_key_ville(), true ),
        'telephone' => get_post_meta( $acheteur_id, fpt_key_whatsapp(), true ),
        'email'     => get_post_meta( $acheteur_id, '_fpt_acheteur_email', true )
                       ?: get_post_meta( $acheteur_id, 'hp_email', true )
                       ?: '',
    ];
}

// ─── Helper : infos partenaire du lot ────────────────────────────────────────
function fpt_get_lot_partenaire( $post_id ) {
    $ref = get_post_meta( $post_id, '_fpt_ref', true );
    if ( ! $ref ) return null;
    return fpt_get_partenaire_by_slug( $ref );
}

// (ancienne metabox commission supprimée — logique intégrée dans fpt_collection_metabox_html)


// ─── Marquer comme payé — via admin-post.php (form séparé dans la metabox) ───
add_action( 'admin_post_fpt_mark_paid', 'fpt_handle_mark_paid' );
function fpt_handle_mark_paid() {
    if ( ! current_user_can('manage_options') ) wp_die('Accès refusé', 403);
    if ( ! isset($_POST['fpt_mark_paid_nonce']) || ! wp_verify_nonce($_POST['fpt_mark_paid_nonce'], 'fpt_mark_paid_nonce') ) {
        wp_die('Nonce invalide', 403);
    }
    $post_id = intval( $_POST['fpt_post_id'] ?? 0 );
    if ( ! $post_id ) wp_die('Post ID manquant', 400);

    update_post_meta( $post_id, '_fpt_commission_paid', 'paid' );
    update_post_meta( $post_id, '_fpt_commission_paid_date', date_i18n('d/m/Y') );

    // Rediriger vers la page d'édition du lot avec message de succès
    wp_redirect( add_query_arg(
        [ 'post' => $post_id, 'action' => 'edit', 'fpt_paid' => '1' ],
        admin_url('post.php')
    ));
    exit;
}

// ─── Notice admin après marquage payé ────────────────────────────────────────
add_action( 'admin_notices', 'fpt_paid_admin_notice' );
function fpt_paid_admin_notice() {
    if ( ! empty($_GET['fpt_paid']) && $_GET['fpt_paid'] === '1' ) {
        echo '<div class="notice notice-success is-dismissible"><p>✅ <strong>Commission marquée comme payée.</strong></p></div>';
    }
}

// ─── Token sécurisé pour accès public à la facture ───────────────────────────
function fpt_invoice_token( $post_id ) {
    return substr( md5( $post_id . AUTH_KEY . 'fpt_invoice' ), 0, 16 );
}

// ─── (réglages facture intégrés dans fpt_admin_page — voir fpt_save_settings) ─

// ─── Devise intelligente : option du site ou détection par domaine ───────────
function fpt_get_currency() {
    $saved = get_option('fpt_currency', '');
    if ($saved) return $saved;
    // Détection automatique par domaine
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (strpos($host, '.fr') !== false || strpos($host, 'france') !== false) return 'EUR';
    if (strpos($host, '.us') !== false || strpos($host, 'feraypro.com') !== false) return 'USD';
    if (strpos($host, '.cd') !== false || strpos($host, 'congo') !== false) return 'USD';
    return 'MAD'; // défaut Maroc
}


add_action( 'wp_ajax_fpt_mark_paid_ajax', 'fpt_ajax_mark_paid' );
function fpt_ajax_mark_paid() {
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Accès refusé');
    if ( ! wp_verify_nonce($_POST['nonce'] ?? '', 'fpt_mark_paid_ajax') ) wp_send_json_error('Nonce invalide');
    $lot_id = intval($_POST['lot_id'] ?? 0);
    if ( ! $lot_id ) wp_send_json_error('ID manquant');
    update_post_meta($lot_id, '_fpt_commission_paid', 'paid');
    update_post_meta($lot_id, '_fpt_commission_paid_date', date_i18n('d/m/Y'));
    wp_send_json_success();
}

// ─── AJAX : sauvegarder le prix du lot ───────────────────────────────────────
add_action( 'wp_ajax_fpt_save_prix', 'fpt_ajax_save_prix' );
function fpt_ajax_save_prix() {
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Accès refusé');
    if ( ! wp_verify_nonce($_POST['nonce'] ?? '', 'fpt_save_prix') ) wp_send_json_error('Nonce invalide');
    $lot_id = intval($_POST['lot_id'] ?? 0);
    $prix   = floatval($_POST['prix'] ?? 0);
    if ( ! $lot_id || $prix <= 0 ) wp_send_json_error('Données invalides');
    update_post_meta($lot_id, '_fpt_prix_lot', $prix);
    if ( ! empty($_POST['currency']) ) update_option('fpt_currency', sanitize_text_field($_POST['currency']));
    wp_send_json_success(['prix' => $prix]);
}

// ─── Facture PDF — via admin-ajax (bypasse les permaliens) ───────────────────
add_action( 'wp_ajax_fpt_facture',        'fpt_afficher_facture' );
add_action( 'wp_ajax_nopriv_fpt_facture', 'fpt_afficher_facture' );
function fpt_afficher_facture() {
    $lot_id = intval($_GET['fpt_lot'] ?? $_GET['fpt_facture'] ?? 0);
    $token  = sanitize_text_field($_GET['fpt_tok'] ?? '');
    if ( ! $lot_id || $token !== substr(md5($lot_id . AUTH_KEY), 0, 12) ) {
        wp_die('Lien invalide.', 403);
    }

    $lot = get_post($lot_id);
    if (!$lot) wp_die('Lot introuvable.', 404);

    // Données lot
    $titre       = get_the_title($lot_id);
    $poids_kg    = fpt_get_poids_kg($lot_id);
    $prix        = fpt_get_prix_lot($lot_id);
    $acheteur_id = get_post_meta($lot_id, '_fpt_acheteur_id', true);
    $acheteur    = $acheteur_id ? get_the_title($acheteur_id) : '—';
    $date_col    = get_post_meta($lot_id, '_fpt_collected_date', true);
    $ref_slug    = get_post_meta($lot_id, '_fpt_ref', true);
    $partenaire  = $ref_slug ? fpt_get_partenaire_by_slug($ref_slug) : null;
    $site        = get_option('fpt_site_name', get_bloginfo('name'));
    $site_url    = home_url();

    // Devise selon le pays configuré
    $currency = fpt_get_currency();

    // Logo du site (custom logo WordPress)
    $logo_html = '';
    $logo_id   = get_theme_mod('custom_logo');
    if ($logo_id) {
        $logo_src = wp_get_attachment_image_url($logo_id, 'medium');
        if ($logo_src) $logo_html = '<img src="' . esc_url($logo_src) . '" style="max-height:60px;max-width:200px;object-fit:contain">';
    }
    if (!$logo_html) {
        // Fallback : favicon du site
        $icon_url = get_site_icon_url(64);
        if ($icon_url) $logo_html = '<img src="' . esc_url($icon_url) . '" style="height:40px;width:40px;object-fit:contain;margin-right:8px;vertical-align:middle">';
        $logo_html .= '<span style="font-size:20px;font-weight:700;color:#1a7a4a;vertical-align:middle">' . esc_html($site) . '</span>';
    }

    // Numéro facture
    $inv = get_post_meta($lot_id, '_fpt_invoice_number', true);
    if (!$inv) {
        $inv = 'FP-' . date('Ym') . '-' . $lot_id;
        update_post_meta($lot_id, '_fpt_invoice_number', $inv);
    }

    // TVA
    $tva_rate    = floatval(get_option('fpt_tva_rate', 0)); // 0 = exonéré par défaut
    $comm20      = round($prix * 0.20, 2);
    $tva_montant = $tva_rate > 0 ? round($comm20 * $tva_rate / 100, 2) : 0;
    $comm20_ttc  = $comm20 + $tva_montant;
    $vend80      = round($prix * 0.80, 2);
    $fp10        = round($prix * 0.10, 2);

    // Coordonnées paiement
    $rib_iban = get_option('fpt_rib_iban', '');
    $rib_wa   = get_option('fpt_rib_whatsapp', '');

    header('Content-Type: text/html; charset=UTF-8');
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Facture <?php echo esc_html($inv); ?> — <?php echo esc_html($site); ?></title>
<style>
/* ── Reset & page pleine ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { width: 100%; height: 100%; }
body { font-family: Arial, Helvetica, sans-serif; font-size: 13px; color: #111; background: #e8e8e8; }

/* ── Bouton impression (masqué à l'impression) ── */
.no-print { background: #1a7a4a; padding: 12px; text-align: center; }
.no-print button {
    background: #fff; color: #1a7a4a; border: none;
    padding: 10px 32px; border-radius: 5px; font-size: 14px;
    font-weight: 700; cursor: pointer; letter-spacing: .02em;
}
.no-print button:hover { background: #f0fdf4; }

/* ── Page facture ── */
.page {
    width: 210mm;
    min-height: 297mm;
    margin: 16px auto;
    background: #fff;
    padding: 16mm 18mm;
    display: flex;
    flex-direction: column;
    box-shadow: 0 4px 24px rgba(0,0,0,.15);
}

/* ── En-tête ── */
.inv-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding-bottom: 14px;
    border-bottom: 3px solid #1a7a4a;
    margin-bottom: 20px;
}
.inv-meta { text-align: right; font-size: 12px; color: #444; line-height: 1.7; }
.inv-meta strong { display: block; font-size: 17px; color: #111; margin-bottom: 2px; }
.inv-meta .badge {
    display: inline-block; padding: 3px 10px; border-radius: 4px;
    font-size: 11px; font-weight: 700; margin-top: 4px;
}
.badge-paid    { background: #e6f5ee; color: #1a7a4a; border: 1px solid #1a7a4a; }
.badge-pending { background: #fff8e1; color: #92400e; border: 1px solid #f59e0b; }

/* ── Parties ── */
.parties { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
.party label { font-size: 9px; text-transform: uppercase; letter-spacing: .1em; color: #888; display: block; margin-bottom: 5px; }
.party strong { font-size: 14px; display: block; margin-bottom: 3px; }
.party span { font-size: 11px; color: #666; line-height: 1.5; display: block; }

/* ── Tableau lot ── */
.inv-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
.inv-table th {
    background: #f4f4f4; font-size: 10px; text-transform: uppercase;
    letter-spacing: .06em; color: #666; padding: 7px 10px;
    text-align: left; border-bottom: 2px solid #ddd;
}
.inv-table td { padding: 10px; border-bottom: 1px solid #eee; font-size: 13px; vertical-align: top; }
.inv-table .right { text-align: right; font-weight: 700; }

/* ── Totaux ── */
.totals { margin-bottom: 18px; }
.total-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 7px 10px; font-size: 13px; border-bottom: 1px solid #eee;
}
.total-row:nth-child(odd)  { background: #fafafa; }
.total-row:nth-child(even) { background: #fff; }
.total-row.main {
    background: #e6f5ee; font-size: 15px; font-weight: 700;
    border-top: 2px solid #1a7a4a; border-bottom: 2px solid #1a7a4a;
    padding: 10px;
}
.total-row.main .val { color: #1a7a4a; font-size: 17px; }
.total-row.tva { background: #fff8e1; font-size: 12px; color: #92400e; }

/* ── Note règlement ── */
.note {
    background: #f0fdf4; border-left: 4px solid #1a7a4a;
    padding: 10px 14px; font-size: 12px; color: #1a7a4a;
    margin-bottom: 18px; line-height: 1.7;
}

/* ── Partenaire ── */
.partner-box {
    background: #fff8e1; border: 1px solid #f0c040;
    border-radius: 5px; padding: 10px 14px; font-size: 12px; margin-bottom: 18px;
}

/* ── Footer ── */
.inv-footer {
    margin-top: auto;
    padding-top: 14px;
    border-top: 1px solid #ddd;
    font-size: 10px; color: #999; text-align: center; line-height: 1.6;
}

/* ── Impression pleine page ── */
@media print {
    html, body { background: #fff; }
    .no-print  { display: none !important; }
    .page {
        width: 100%;
        min-height: 100vh;
        margin: 0;
        padding: 12mm 16mm;
        box-shadow: none;
    }
    @page { size: A4; margin: 0; }
}
</style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()"><?php echo fpt_inv__('inv_print'); ?></button>
</div>

<div class="page">

    <!-- En-tête -->
    <div class="inv-header">
        <div><?php echo $logo_html; ?></div>
        <div class="inv-meta">
            <?php $ilang = fpt_invoice_lang(); ?>
            <strong><?php echo $ilang === 'en' ? 'INVOICE N°' : ( $ilang === 'es' ? 'FACTURA N°' : ( $ilang === 'pt' ? 'FATURA N°' : 'FACTURE N°' ) ); ?> <?php echo esc_html($inv); ?></strong>
            <?php echo fpt_inv__('inv_date'); ?> <?php echo date_i18n('d/m/Y'); ?><br>
            <?php if ($date_col) echo fpt_inv__('inv_collect_date') . ' ' . date_i18n('d/m/Y', strtotime($date_col)); ?>
        </div>
    </div>

    <!-- Parties -->
    <div class="parties">
        <div class="party">
            <label><?php echo fpt_inv__('inv_emitter'); ?></label>
            <strong><?php echo esc_html($site); ?></strong>
            <span><?php echo esc_html($site_url); ?></span>
            <?php $adresse = get_option('fpt_adresse_facturation','');
            if ($adresse) echo '<span>' . nl2br(esc_html($adresse)) . '</span>'; ?>
        </div>
        <div class="party">
            <label><?php echo fpt_inv__('inv_recipient'); ?></label>
            <strong><?php echo esc_html($acheteur); ?></strong>
        </div>
    </div>

    <!-- Détail lot -->
    <table class="inv-table">
        <thead>
            <tr>
                <th>Description</th>
                <th><?php echo fpt_inv__('inv_weight'); ?></th>
                <th style="text-align:right"><?php echo fpt_inv__('inv_total_price'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php echo esc_html($titre); ?></td>
                <td><?php echo esc_html(fpt_display_weight($poids_kg)); ?></td>
                <td class="right"><?php echo number_format($prix, 2, '.', ' ') . ' ' . esc_html($currency); ?></td>
            </tr>
        </tbody>
    </table>

    <!-- Totaux -->
    <div class="totals">
        <div class="total-row">
            <span><?php echo fpt_inv__('inv_vendor_share'); ?></span>
            <span><?php echo number_format($vend80, 2, '.', ' ') . ' ' . esc_html($currency); ?></span>
        </div>
        <div class="total-row">
            <span><?php echo fpt_inv__('inv_commission'); ?> <?php echo esc_html($site); ?> <?php echo fpt_inv__('inv_commission_ht'); ?> (20%)</span>
            <span><?php echo number_format($comm20, 2, '.', ' ') . ' ' . esc_html($currency); ?></span>
        </div>
        <?php if ($tva_rate > 0) : ?>
        <div class="total-row tva">
            <span>TVA (<?php echo $tva_rate; ?>%)</span>
            <span><?php echo number_format($tva_montant, 2, '.', ' ') . ' ' . esc_html($currency); ?></span>
        </div>
        <?php else : ?>
        <div class="total-row tva">
            <span>TVA</span>
            <span><?php echo fpt_inv__('inv_tva_exempt'); ?></span>
        </div>
        <?php endif; ?>
        <div class="total-row main">
            <span>💰 <?php echo fpt_inv__('inv_total_due'); ?> <?php echo esc_html(strtoupper($site)); ?> <?php echo fpt_inv__('inv_ttc'); ?></span>
            <span class="val"><?php echo number_format($comm20_ttc, 2, '.', ' ') . ' ' . esc_html($currency); ?></span>
        </div>
    </div>

    <?php if ($partenaire) : ?>
    <div class="partner-box">
        🤝 <strong><?php echo fpt_inv__('inv_partner_ref'); ?> <?php echo esc_html($partenaire['nom']); ?></strong> —
        <?php echo fpt_inv__('inv_partner_comm'); ?> <?php echo esc_html($site); ?> :
        <strong><?php echo number_format($fp10, 2, '.', ' ') . ' ' . esc_html($currency); ?></strong> (10%)
    </div>
    <?php endif; ?>

    <!-- Mode de règlement -->
    <div class="note">
        <strong><?php echo fpt_inv__('inv_payment_title'); ?></strong><br>
        • <?php echo fpt_inv__('inv_vendor_pays'); ?> <strong><?php echo number_format($vend80, 2, '.', ' ') . ' ' . esc_html($currency); ?></strong> <?php echo fpt_inv__('inv_directly'); ?><br>
        • <?php echo fpt_inv__('inv_buyer_transfers'); ?> <strong><?php echo number_format($comm20_ttc, 2, '.', ' ') . ' ' . esc_html($currency); ?> <?php echo fpt_inv__('inv_ttc'); ?></strong> <?php echo fpt_inv__('inv_to_site'); ?> <?php echo esc_html($site); ?>.<br>
        <?php if ($rib_iban) echo '🏦 IBAN : <strong>' . esc_html($rib_iban) . '</strong><br>'; ?>
        <?php if ($rib_wa)   echo '📱 ' . ( $ilang === 'en' ? 'Mobile payment' : ( $ilang === 'es' ? 'Pago móvil' : ( $ilang === 'pt' ? 'Pagamento móvel' : 'Paiement mobile' ) ) ) . ' : <strong>' . esc_html($rib_wa) . '</strong>'; ?>
    </div>

    <?php do_action( 'fpt_invoice_payment_methods', $lot_id, $comm20_ttc ); ?>

    <!-- Footer -->
    <div class="inv-footer">
        <?php echo esc_html($site); ?> · <?php echo esc_html($site_url); ?> ·
        <?php echo $ilang === 'en' ? 'Invoice' : ( $ilang === 'es' ? 'Factura' : ( $ilang === 'pt' ? 'Fatura' : 'Facture' ) ); ?> N° <?php echo esc_html($inv); ?> · <?php echo date_i18n('d/m/Y'); ?><br>
        <?php echo fpt_inv__('inv_footer_doc'); ?>
    </div>

</div>
</body>
</html>
<?php
    exit;
}
// ─── (fin du module commission) ───────────────────────────────────────────────

