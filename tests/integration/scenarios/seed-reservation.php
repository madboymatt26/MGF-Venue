<?php
global $wpdb;
$invoice_ref = isset( $args[0] ) ? sanitize_text_field( $args[0] ) : 'INT-RES-DIFFERENT';
$shared = isset( $args[1] ) && $args[1] === 'shared';
$reservation_table = $wpdb->prefix . MBS_PAYMENT_RESERVATION_TABLE;
$invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
$barrier_table = $wpdb->prefix . 'mbs_test_barriers';
$wpdb->query( "DROP TABLE IF EXISTS {$barrier_table}" );
$wpdb->query( "CREATE TABLE {$barrier_table} (barrier_key VARCHAR(64) NOT NULL, arrived INT NOT NULL DEFAULT 0, critical_arrived INT NOT NULL DEFAULT 0, PRIMARY KEY (barrier_key)) ENGINE=MyISAM" );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$barrier_table} WHERE barrier_key = %s", $invoice_ref ) );
$wpdb->insert( $barrier_table, array( 'barrier_key' => $invoice_ref, 'arrived' => 0, 'critical_arrived' => 0 ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$reservation_table} WHERE invoice_ref = %s", $invoice_ref ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$invoice_table} WHERE invoice_ref = %s", $invoice_ref ) );
$now = current_time( 'mysql' );
$ok = $wpdb->insert( $invoice_table, array(
    'invoice_ref'=>$invoice_ref,'document_type'=>'invoice','status'=>'issued','version'=>1,
    'contact_name'=>'Integration Hirer','contact_email'=>'integration@example.invalid','contact_address'=>'Test only',
    'billing_mode'=>'monthly','currency'=>'GBP','subtotal_minor'=>1000,'total_minor'=>1000,'paid_minor'=>0,'credited_minor'=>0,
    'idempotency_key'=>hash('sha256','integration-reservation-'.$invoice_ref),'idempotency_request_hash'=>hash('sha256','integration-payload-'.$invoice_ref),
    'issued_at'=>$now,'due_at'=>$now,'created_at'=>$now,'updated_at'=>$now,
) );
if ( $ok === false ) throw new RuntimeException( $wpdb->last_error );
if ( $shared ) {
    $claim = MBS_Invoice_Reservation::acquire( MBS_Billing_Ledger::get_invoice( $invoice_ref ) );
    if ( is_wp_error( $claim ) ) throw new RuntimeException( $claim->get_error_message() );
    update_option( 'mbs_test_shared_reservation_' . $invoice_ref, $claim['reservation_ref'], false );
}
echo "OK: disposable invoice and two-party barrier seeded for {$invoice_ref}.\n";
