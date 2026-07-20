<?php
global $wpdb;
$invoice_ref = isset( $args[0] ) ? sanitize_text_field( $args[0] ) : '';
$table = $wpdb->prefix . MBS_PAYMENT_RESERVATION_TABLE;
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE invoice_ref = %s", $invoice_ref ) );
if ( count( $rows ) !== 1 || $rows[0]->status !== 'bound' || (int) $rows[0]->order_id < 1 ) {
    throw new RuntimeException( 'Concurrent submissions did not produce exactly one bound authoritative order.' );
}
echo 'OK: one durable reservation and one authoritative order (' . (int) $rows[0]->order_id . ").\n";
