<?php
/**
 * Plugin Name: Mathlin Booking System
 * Plugin URI:  https://needhamscouts.uk
 * Description: Venue booking system for Needham Market Scout Group with Home Assistant integration.
 * Version:     2.14.0
 * Author:      Needham Market Scout Group
 * License:     GPL-2.0+
 * Text Domain: mathlin-booking
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MBS_VERSION',    '2.14.0' );
define( 'MBS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MBS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MBS_TABLE',      'mathlin_bookings' );

// ── Load includes ──────────────────────────────────────────────────────────────
require_once MBS_PLUGIN_DIR . 'includes/class-database.php';
require_once MBS_PLUGIN_DIR . 'includes/class-bookings.php';
require_once MBS_PLUGIN_DIR . 'includes/class-email.php';
require_once MBS_PLUGIN_DIR . 'includes/class-invoice.php';
require_once MBS_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once MBS_PLUGIN_DIR . 'includes/class-homeassistant.php';
require_once MBS_PLUGIN_DIR . 'includes/class-blocked-dates.php';
require_once MBS_PLUGIN_DIR . 'includes/class-updater.php';
require_once MBS_PLUGIN_DIR . 'includes/class-reminders.php';
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
require_once MBS_PLUGIN_DIR . 'includes/class-woo-ux.php';
require_once MBS_PLUGIN_DIR . 'admin/class-admin.php';
require_once MBS_PLUGIN_DIR . 'public/class-public.php';

// ── Activation / Deactivation ──────────────────────────────────────────────────
register_activation_hook( __FILE__, array( 'MBS_Database', 'create_tables' ) );
register_deactivation_hook( __FILE__, array( 'MBS_Database', 'on_deactivate' ) );
register_deactivation_hook( __FILE__, array( 'MBS_Reminders', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'MBS_Auto_Archive', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'MBS_Payment_Chaser', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'MBS_Email_Queue', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'MBS_Hirer_Portal', 'deactivate' ) );

// ── GDPR Privacy Hooks ─────────────────────────────────────────────────────────
add_filter( 'wp_privacy_personal_data_erasers', 'mbs_register_privacy_eraser' );
add_filter( 'wp_privacy_personal_data_exporters', 'mbs_register_privacy_exporter' );

function mbs_register_privacy_eraser( $erasers ) {
    $erasers['mathlin-booking'] = array(
        'eraser_friendly_name' => 'Mathlin Booking System',
        'callback'             => array( 'MBS_Bookings', 'gdpr_erase_personal_data' ),
    );
    return $erasers;
}

function mbs_register_privacy_exporter( $exporters ) {
    $exporters['mathlin-booking'] = array(
        'exporter_friendly_name' => 'Mathlin Booking System',
        'callback'               => array( 'MBS_Bookings', 'gdpr_export_personal_data' ),
    );
    return $exporters;
}

// ── Boot ───────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'mbs_init' );

function mbs_init() {
    // Run DB migration if version has changed
    if ( get_option( 'mbs_db_version' ) !== MBS_VERSION ) {
        MBS_Database::create_tables();
    }

    $admin   = new MBS_Admin();
    $public  = new MBS_Public();
    $api     = new MBS_Rest_API();
    $updater    = new MBS_Updater();
    $reminders  = new MBS_Reminders();
    $csv_export   = new MBS_CSV_Export();
    $dashboard    = new MBS_Dashboard_Widget();
    $woo_payment  = new MBS_Woo_Payment();
    $auto_archive   = new MBS_Auto_Archive();
    $payment_chaser = new MBS_Payment_Chaser();
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
    $csv_export->init();
    $dashboard->init();
    $woo_payment->init();
    $auto_archive->init();
    $payment_chaser->init();
    $email_queue->init();
    $modification->init();
    $hirer_portal->init();
    $accounting->init();
    $osm_integration->init();
    $woo_ux->init();
}
