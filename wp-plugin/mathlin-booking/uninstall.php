<?php
/**
 * Uninstall script — runs when the plugin is deleted from wp-admin.
 * Removes the custom database table and plugin options.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;
$table = $wpdb->prefix . 'mathlin_bookings';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
delete_option( 'mbs_db_version' );
delete_option( 'mbs_ha_webhook_url' );
delete_option( 'mbs_min_notice_days' );
