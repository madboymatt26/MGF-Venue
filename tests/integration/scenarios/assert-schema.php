<?php
global $wpdb;
$tables = array(
    $wpdb->prefix . MBS_TABLE,
    $wpdb->prefix . MBS_INVOICE_TABLE,
    $wpdb->prefix . MBS_PAYMENT_TRANSACTION_TABLE,
    $wpdb->prefix . MBS_BILLING_ALLOCATION_TABLE,
    $wpdb->prefix . MBS_PAYMENT_RESERVATION_TABLE,
);
foreach ( $tables as $table ) {
    $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ) );
    if ( ! $status || strtolower( $status->Engine ) !== 'innodb' ) throw new RuntimeException( $table . ' is not InnoDB.' );
}
echo "OK: real MariaDB schema exists and financial tables are InnoDB.\n";
