<?php
global $wpdb;
$tables = array(
    $wpdb->prefix . MBS_TABLE,
    $wpdb->prefix . MBS_SERIES_TABLE,
    $wpdb->prefix . MBS_INVOICE_TABLE,
    $wpdb->prefix . MBS_INVOICE_ITEM_TABLE,
    $wpdb->prefix . MBS_PAYMENT_TRANSACTION_TABLE,
    $wpdb->prefix . MBS_BILLING_ALLOCATION_TABLE,
    $wpdb->prefix . MBS_PAYMENT_RESERVATION_TABLE,
);
foreach ( $tables as $table ) {
    $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ) );
    if ( ! $status || strtolower( $status->Engine ) !== 'innodb' ) throw new RuntimeException( $table . ' is not InnoDB.' );
}
$state = get_option( 'mbs_migration_state', array() );
if ( get_option( 'mbs_db_version' ) !== MBS_DB_VERSION || ( $state['status'] ?? '' ) !== 'complete' ) {
    throw new RuntimeException( 'Migration marker advanced without a completely verified schema.' );
}
echo "OK: real MariaDB schema is complete, verified, versioned, and InnoDB.\n";
