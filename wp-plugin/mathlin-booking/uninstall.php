<?php
/**
 * Uninstall script — runs when the plugin is deleted from wp-admin.
 * Removes the custom database table and plugin options.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;
$tables = array(
    'mathlin_download_tokens', 'mathlin_invoice_documents', 'mathlin_document_assets',
    'mathlin_osm_outbox', 'mathlin_billing_allocations', 'mathlin_payment_transactions',
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
delete_option( 'mbs_legacy_series_registered' );
delete_option( 'mbs_ha_webhook_url' );
delete_option( 'mbs_min_notice_days' );
wp_clear_scheduled_hook( 'mbs_daily_series_billing' );
