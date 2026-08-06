<?php
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'wc_get_order' ) ) {
    throw new RuntimeException( 'WordPress and WooCommerce were not both loaded.' );
}
if ( ! class_exists( 'MBS_Billing_Ledger' ) || ! class_exists( 'MBS_Test_Gateway' ) ) {
    throw new RuntimeException( 'The checked-out plugin and deterministic gateway were not both loaded.' );
}
global $wpdb;
$engine = strtolower( (string) $wpdb->get_var( $wpdb->prepare(
    'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
    $wpdb->prefix . MBS_INVOICE_TABLE
) ) );
if ( $engine !== 'innodb' ) throw new RuntimeException( 'The invoice table is not using InnoDB.' );
echo sprintf(
    "OK: PHP %s, WordPress %s, WooCommerce %s, %s %s, MGF Venue %s.\n",
    PHP_VERSION,
    get_bloginfo( 'version' ),
    defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
    $wpdb->db_server_info() ? 'database' : 'database',
    $wpdb->db_version(),
    defined( 'MBS_VERSION' ) ? MBS_VERSION : 'unknown'
);
