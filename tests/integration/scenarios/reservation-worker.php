<?php
global $wpdb;
$invoice_ref = isset( $args[0] ) ? sanitize_text_field( $args[0] ) : '';
$order_id = isset( $args[1] ) ? absint( $args[1] ) : 0;
$shared = isset( $args[2] ) && $args[2] === 'shared';
$barrier_table = $wpdb->prefix . 'mbs_test_barriers';
$arrived = $wpdb->query( $wpdb->prepare( "UPDATE {$barrier_table} SET arrived = arrived + 1 WHERE barrier_key = %s", $invoice_ref ) );
if ( $arrived !== 1 ) { fwrite( STDERR, "barrier_missing\n" ); exit( 16 ); }
$deadline = microtime( true ) + 30;
do {
    $participants = (int) $wpdb->get_var( $wpdb->prepare( "SELECT arrived FROM {$barrier_table} WHERE barrier_key = %s", $invoice_ref ) );
    if ( $participants >= 2 ) break;
    usleep( 50000 );
} while ( microtime( true ) < $deadline );
if ( $participants < 2 ) { fwrite( STDERR, "barrier_timeout\n" ); exit( 16 ); }

$invoice = MBS_Billing_Ledger::get_invoice( $invoice_ref );
$existing = $shared ? (string) get_option( 'mbs_test_shared_reservation_' . $invoice_ref, '' ) : '';
$inside = false;
add_action( 'mbs_invoice_reservation_critical_section', static function( $observed_ref ) use ( $wpdb, $barrier_table, $invoice_ref, &$inside ) {
    if ( $observed_ref !== $invoice_ref ) return;
    $inside = true;
    $updated = $wpdb->query( $wpdb->prepare( "UPDATE {$barrier_table} SET critical_arrived = critical_arrived + 1 WHERE barrier_key = %s", $invoice_ref ) );
    if ( $updated !== 1 ) { fwrite(STDERR,"critical_barrier_missing\n"); exit(19); }
    $critical_deadline = microtime(true) + 30;
    do {
        $critical_participants = (int)$wpdb->get_var($wpdb->prepare("SELECT critical_arrived FROM {$barrier_table} WHERE barrier_key=%s",$invoice_ref));
        if ( $critical_participants >= 2 ) break;
        usleep(50000);
    } while ( microtime(true) < $critical_deadline );
    if ( $critical_participants < 2 ) { fwrite(STDERR,"critical_barrier_timeout\n"); exit(19); }
}, 10, 1 );
$claim = MBS_Invoice_Reservation::acquire( $invoice, $existing );
if ( !$inside ) { fwrite( STDERR, "critical_section_not_observed\n" ); exit( 19 ); }
if ( is_wp_error( $claim ) ) { fwrite( STDERR, $claim->get_error_code() . "\n" ); exit( 17 ); }
$bound = MBS_Invoice_Reservation::bind_order( $invoice_ref, $claim['reservation_ref'], $order_id );
if ( is_wp_error( $bound ) ) { fwrite( STDERR, $bound->get_error_code() . "\n" ); exit( 18 ); }
echo wp_json_encode( $bound ) . "\n";
