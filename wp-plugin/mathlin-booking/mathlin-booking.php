<?php
/**
 * Plugin Name: Mathlin Booking System
 * Plugin URI:  https://needhamscouts.uk
 * Description: Venue booking system for Needham Market Scout Group with Home Assistant integration.
 * Version:     1.3.1
 * Author:      Needham Market Scout Group
 * License:     GPL-2.0+
 * Text Domain: mathlin-booking
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MBS_VERSION',    '1.3.1' );
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
require_once MBS_PLUGIN_DIR . 'includes/class-updater.php';
require_once MBS_PLUGIN_DIR . 'admin/class-admin.php';
require_once MBS_PLUGIN_DIR . 'public/class-public.php';

// ── Activation / Deactivation ──────────────────────────────────────────────────
register_activation_hook( __FILE__, array( 'MBS_Database', 'create_tables' ) );
register_deactivation_hook( __FILE__, array( 'MBS_Database', 'on_deactivate' ) );

// ── Boot ───────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'mbs_init' );

function mbs_init() {
    $admin   = new MBS_Admin();
    $public  = new MBS_Public();
    $api     = new MBS_Rest_API();
    $updater = new MBS_Updater();

    $admin->init();
    $public->init();
    $api->init();
    $updater->init();
}
