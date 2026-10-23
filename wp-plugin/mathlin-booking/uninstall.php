<?php
/**
 * Uninstall script — runs when the plugin is deleted from wp-admin.
 * Removes the custom database table and plugin options.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

// Reinstall/update recovery must not destroy bookings or financial evidence.
// A deliberate purge requires a host administrator to opt in after backup.
if ( ! defined( 'MBS_PURGE_ON_UNINSTALL' ) || MBS_PURGE_ON_UNINSTALL !== true ) return;

global $wpdb;
$tables = array(
    'mathlin_download_tokens', 'mathlin_invoice_documents', 'mathlin_document_assets',
    'mathlin_osm_payouts', 'mathlin_osm_outbox', 'mathlin_billing_allocations', 'mathlin_payment_transactions',
    'mathlin_invoice_items', 'mathlin_invoices', 'mathlin_booking_series',
    'mathlin_mod_requests', 'mathlin_email_queue', 'mathlin_audit_log',
    'mathlin_blocked_dates', 'mathlin_bookings',
);
foreach ( $tables as $table_name ) {
    $table = $wpdb->prefix . $table_name;
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}
delete_option( 'mbs_db_version' );
delete_option( 'mbs_migration_state' );
delete_option( 'mbs_migration_lock' );
wp_clear_scheduled_hook( 'mbs_release_invoice_reservation' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'mbs_invoice_reservation_%'" );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'mathlin_payment_reservations' );

// Purge includes credential/configuration/link options, not just table data.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'mbs_' ) . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'mbs_' ) . '%' ) );
foreach ( (array) _get_cron_array() as $timestamp => $hooks ) foreach ( $hooks as $hook => $events ) if ( strpos( $hook, 'mbs_' ) === 0 ) wp_clear_scheduled_hook( $hook );
delete_option( 'mbs_legacy_series_registered' );
delete_option( 'mbs_ha_webhook_url' );
delete_option( 'mbs_min_notice_days' );
wp_clear_scheduled_hook( 'mbs_daily_series_billing' );
