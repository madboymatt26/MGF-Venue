<?php
/**
 * Plugin Name: MGF Venue
 * Plugin URI:  https://github.com/madboymatt26/MGF-Venue
 * Description: Venue booking and management system with Home Assistant integration.
 * Version:     3.24.0
 * Author:      MGF Venue
 * License:     GPL-2.0+
 * Text Domain: mathlin-booking
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MBS_VERSION',    '3.24.0' );
define( 'MBS_DB_VERSION', '3.22.0-schema-10' );
define( 'MBS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MBS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MBS_TABLE',      'mathlin_bookings' );
define( 'MBS_SERIES_TABLE', 'mathlin_booking_series' );
define( 'MBS_INVOICE_TABLE', 'mathlin_invoices' );
define( 'MBS_INVOICE_ITEM_TABLE', 'mathlin_invoice_items' );
define( 'MBS_PAYMENT_TRANSACTION_TABLE', 'mathlin_payment_transactions' );
define( 'MBS_BILLING_ALLOCATION_TABLE', 'mathlin_billing_allocations' );
define( 'MBS_PAYMENT_RESERVATION_TABLE', 'mathlin_payment_reservations' );
define( 'MBS_OSM_OUTBOX_TABLE', 'mathlin_osm_outbox' );
define( 'MBS_INVOICE_DOCUMENTS_TABLE', 'mathlin_invoice_documents' );
define( 'MBS_DOCUMENT_ASSETS_TABLE', 'mathlin_document_assets' );
define( 'MBS_DOWNLOAD_TOKENS_TABLE', 'mathlin_download_tokens' );

// ── Load includes ──────────────────────────────────────────────────────────────
require_once MBS_PLUGIN_DIR . 'includes/class-database.php';
require_once MBS_PLUGIN_DIR . 'includes/class-recurrence.php';
require_once MBS_PLUGIN_DIR . 'includes/class-series.php';
require_once MBS_PLUGIN_DIR . 'includes/class-money.php';
require_once MBS_PLUGIN_DIR . 'includes/class-billing-ledger.php';
require_once MBS_PLUGIN_DIR . 'includes/class-billing-engine.php';
require_once MBS_PLUGIN_DIR . 'includes/class-invoice-payment.php';
require_once MBS_PLUGIN_DIR . 'includes/class-invoice-reservation.php';
require_once MBS_PLUGIN_DIR . 'includes/class-bookings.php';
require_once MBS_PLUGIN_DIR . 'includes/class-email.php';
require_once MBS_PLUGIN_DIR . 'includes/class-invoice.php';
require_once MBS_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once MBS_PLUGIN_DIR . 'includes/class-homeassistant.php';
require_once MBS_PLUGIN_DIR . 'includes/class-blocked-dates.php';
require_once MBS_PLUGIN_DIR . 'includes/class-updater.php';
require_once MBS_PLUGIN_DIR . 'includes/class-reminders.php';
require_once MBS_PLUGIN_DIR . 'includes/class-access-details.php';
require_once MBS_PLUGIN_DIR . 'includes/class-feedback.php';
require_once MBS_PLUGIN_DIR . 'includes/class-ical.php';
require_once MBS_PLUGIN_DIR . 'includes/class-csv-export.php';
require_once MBS_PLUGIN_DIR . 'includes/class-dashboard-widget.php';
require_once MBS_PLUGIN_DIR . 'includes/class-audit-log.php';
require_once MBS_PLUGIN_DIR . 'includes/class-woo-payment.php';
require_once MBS_PLUGIN_DIR . 'includes/class-auto-archive.php';
require_once MBS_PLUGIN_DIR . 'includes/class-payment-chaser.php';
require_once MBS_PLUGIN_DIR . 'includes/class-email-templates.php';
require_once MBS_PLUGIN_DIR . 'includes/class-email-queue.php';
require_once MBS_PLUGIN_DIR . 'includes/class-custom-fields.php';
require_once MBS_PLUGIN_DIR . 'includes/class-modification.php';
require_once MBS_PLUGIN_DIR . 'includes/class-hirer-portal.php';
require_once MBS_PLUGIN_DIR . 'includes/class-accounting-export.php';
require_once MBS_PLUGIN_DIR . 'includes/class-osm-integration.php';
require_once MBS_PLUGIN_DIR . 'includes/class-logo-asset.php';
require_once MBS_PLUGIN_DIR . 'includes/class-series-approval-service.php';
require_once MBS_PLUGIN_DIR . 'includes/invoice/class-issued-invoice-snapshot.php';
require_once MBS_PLUGIN_DIR . 'includes/invoice/class-current-account-state.php';
require_once MBS_PLUGIN_DIR . 'includes/invoice/class-invoice-document-view-model.php';
require_once MBS_PLUGIN_DIR . 'includes/invoice/class-invoice-builder.php';
require_once MBS_PLUGIN_DIR . 'includes/invoice/class-document-service.php';
require_once MBS_PLUGIN_DIR . 'includes/invoice/class-series-issuance-service.php';
require_once MBS_PLUGIN_DIR . 'includes/invoice/class-document-security.php';
require_once MBS_PLUGIN_DIR . 'includes/invoice/class-html-renderer.php';
require_once MBS_PLUGIN_DIR . 'includes/invoice/class-pdf-renderer.php';
require_once MBS_PLUGIN_DIR . 'includes/invoice/class-delivery-endpoint.php';
require_once MBS_PLUGIN_DIR . 'includes/class-woo-ux.php';
require_once MBS_PLUGIN_DIR . 'admin/class-admin.php';
require_once MBS_PLUGIN_DIR . 'public/class-public.php';

// ── Activation / Deactivation ──────────────────────────────────────────────────
register_activation_hook( __FILE__, array( 'MBS_Database', 'create_tables' ) );
register_deactivation_hook( __FILE__, array( 'MBS_Database', 'on_deactivate' ) );
register_deactivation_hook( __FILE__, array( 'MBS_Reminders', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'MBS_Access_Details', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'MBS_Feedback', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'MBS_Auto_Archive', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'MBS_Payment_Chaser', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'MBS_Billing_Engine', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'MBS_Email_Queue', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'MBS_Hirer_Portal', 'deactivate' ) );

// ── GDPR Privacy Hooks ─────────────────────────────────────────────────────────
add_filter( 'wp_privacy_personal_data_erasers', 'mbs_register_privacy_eraser' );
add_filter( 'wp_privacy_personal_data_exporters', 'mbs_register_privacy_exporter' );

function mbs_register_privacy_eraser( $erasers ) {
    $erasers['mathlin-booking'] = array(
        'eraser_friendly_name' => 'MGF Venue',
        'callback'             => array( 'MBS_Bookings', 'gdpr_erase_personal_data' ),
    );
    return $erasers;
}

function mbs_register_privacy_exporter( $exporters ) {
    $exporters['mathlin-booking'] = array(
        'exporter_friendly_name' => 'MGF Venue',
        'callback'               => array( 'MBS_Bookings', 'gdpr_export_personal_data' ),
    );
    return $exporters;
}

// ── Boot ───────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'mbs_init' );

function mbs_init() {
    // Run DB migration if version has changed
    if ( get_option( 'mbs_db_version' ) !== MBS_DB_VERSION ) {
        $migration = MBS_Database::create_tables();
        if ( is_wp_error( $migration ) ) error_log( '[MGF Venue] Database migration failed: ' . $migration->get_error_message() );
    }
    $migration_state = get_option( 'mbs_migration_state', array() );
    if ( is_array( $migration_state ) && ( $migration_state['status'] ?? '' ) === 'failed' ) add_action( 'admin_notices', array( 'MBS_Database', 'migration_health_notice' ) );
    if ( MBS_Database::migration_is_current() && get_option( 'mbs_legacy_series_registered' ) !== MBS_DB_VERSION ) {
        $legacy_registration = MBS_Series::register_legacy_groups();
        if ( ! is_wp_error( $legacy_registration ) ) update_option( 'mbs_legacy_series_registered', MBS_DB_VERSION, false );
    }

    $admin   = new MBS_Admin();
    $public  = new MBS_Public();
    $api     = new MBS_Rest_API();
    $updater    = new MBS_Updater();
    $reminders  = new MBS_Reminders();
    $access_details = new MBS_Access_Details();
    $feedback       = new MBS_Feedback();
    $csv_export   = new MBS_CSV_Export();
    $dashboard    = new MBS_Dashboard_Widget();
    $woo_payment  = new MBS_Woo_Payment();
    $auto_archive   = new MBS_Auto_Archive();
    $payment_chaser = new MBS_Payment_Chaser();
    $billing_engine = new MBS_Billing_Engine();
    $email_queue    = new MBS_Email_Queue();
    $modification   = new MBS_Modification();
    $hirer_portal   = new MBS_Hirer_Portal();
    $accounting     = new MBS_Accounting_Export();
    $osm_integration = new MBS_OSM_Integration();

    $woo_ux = new MBS_Woo_UX();

    $admin->init();
    $public->init();
    $api->init();
    $updater->init();
    $reminders->init();
    $access_details->init();
    $feedback->init();
    $csv_export->init();
    $dashboard->init();
    $woo_payment->init();
    $auto_archive->init();
    $payment_chaser->init();
    $billing_engine->init();
    $email_queue->init();
    $modification->init();
    $hirer_portal->init();
    $accounting->init();
    $osm_integration->init();
    $woo_ux->init();

    $invoice_delivery = new MBS_Invoice_Delivery_Endpoint();
    $invoice_delivery->init();

    // User profile: Pricing Tier field
    add_action( 'show_user_profile', 'mbs_user_pricing_tier_field' );
    add_action( 'edit_user_profile', 'mbs_user_pricing_tier_field' );
    add_action( 'personal_options_update', 'mbs_save_user_pricing_tier' );
    add_action( 'edit_user_profile_update', 'mbs_save_user_pricing_tier' );
}

/**
 * Display Pricing Tier dropdown on user profile page.
 */
function mbs_user_pricing_tier_field( $user ) {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $tiers = MBS_Bookings::get_pricing_tiers();
    $current = get_user_meta( $user->ID, 'mbs_pricing_tier', true ) ?: 'standard';
    ?>
    <h3>MGF Venue</h3>
    <table class="form-table">
        <tr>
            <th><label for="mbs_pricing_tier">Pricing Tier</label></th>
            <td>
                <select name="mbs_pricing_tier" id="mbs_pricing_tier">
                    <?php foreach ( $tiers as $key => $tier ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>><?php echo esc_html( $tier['label'] ); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description">Determines which pricing rates apply to this user's bookings.</p>
            </td>
        </tr>
    </table>
    <?php
}

/**
 * Save Pricing Tier from user profile.
 */
function mbs_save_user_pricing_tier( $user_id ) {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( isset( $_POST['mbs_pricing_tier'] ) ) {
        update_user_meta( $user_id, 'mbs_pricing_tier', sanitize_key( $_POST['mbs_pricing_tier'] ) );
    }
}
