<?php
global $wpdb;
$invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
$reservation_table = $wpdb->prefix . MBS_PAYMENT_RESERVATION_TABLE;

function mbs_test_seed_reservation_invoice( $ref, $total, $paid = 0 ) {
    global $wpdb;
    $now = current_time( 'mysql' );
    $ok = $wpdb->insert( $wpdb->prefix . MBS_INVOICE_TABLE, array(
        'invoice_ref'=>$ref,'document_type'=>'invoice','status'=>$paid ? 'part_paid' : 'issued','version'=>1,
        'contact_name'=>'State Machine','contact_email'=>'state@example.invalid','billing_mode'=>'monthly','currency'=>'GBP',
        'subtotal_minor'=>$total,'total_minor'=>$total,'paid_minor'=>$paid,'credited_minor'=>0,
        'idempotency_key'=>hash('sha256','state-'.$ref),'idempotency_request_hash'=>hash('sha256','state-payload-'.$ref),
        'issued_at'=>$now,'created_at'=>$now,'updated_at'=>$now,
    ) );
    if ( $ok === false ) throw new RuntimeException( $wpdb->last_error );
    return MBS_Billing_Ledger::get_invoice( $ref );
}

$invoice = mbs_test_seed_reservation_invoice( 'INT-RES-STALE', 1000 );
$old = MBS_Invoice_Reservation::acquire( $invoice );
if ( is_wp_error( $old ) ) throw new RuntimeException( $old->get_error_message() );
$wpdb->update( $reservation_table, array( 'expires_at' => '2000-01-01 00:00:00' ), array( 'invoice_ref' => $invoice->invoice_ref ) );
$new = MBS_Invoice_Reservation::acquire( $invoice );
if ( is_wp_error( $new ) || $new['reservation_ref'] === $old['reservation_ref'] ) throw new RuntimeException( 'Expired owner was not atomically replaced.' );
if ( MBS_Invoice_Reservation::release( $invoice->invoice_ref, $old['reservation_ref'], 'stale-worker' ) ) throw new RuntimeException( 'A stale worker released the newer owner.' );
if ( MBS_Invoice_Reservation::release_expired( $invoice->invoice_ref, $old['reservation_ref'] ) ) throw new RuntimeException( 'A stale expiry callback released the newer owner.' );
$stale_bind = MBS_Invoice_Reservation::bind_order( $invoice->invoice_ref, $old['reservation_ref'], 1999 );
if ( ! is_wp_error( $stale_bind ) ) throw new RuntimeException( 'A stale worker bound an order after replacement.' );
$fresh = MBS_Invoice_Reservation::get( $invoice->invoice_ref );
if ( $fresh->reservation_ref !== $new['reservation_ref'] || $fresh->status !== 'active' ) throw new RuntimeException( 'New owner changed after stale callbacks.' );
if ( ! MBS_Invoice_Reservation::renew( $invoice->invoice_ref, $new['reservation_ref'] ) ) throw new RuntimeException( 'Current owner could not renew.' );

$cancelled = mbs_test_seed_reservation_invoice( 'INT-RES-CANCEL', 1000 );
$cancel_claim = MBS_Invoice_Reservation::acquire( $cancelled );
if ( is_wp_error( $cancel_claim ) || ! MBS_Invoice_Reservation::release( $cancelled->invoice_ref, $cancel_claim['reservation_ref'], 'checkout_cancelled' ) ) throw new RuntimeException( 'Unbound checkout cancellation failed.' );
$replacement = MBS_Invoice_Reservation::acquire( $cancelled );
if ( is_wp_error( $replacement ) || $replacement['reservation_ref'] === $cancel_claim['reservation_ref'] ) throw new RuntimeException( 'Cancelled checkout blocked a replacement owner.' );

$partial = mbs_test_seed_reservation_invoice( 'INT-RES-PARTIAL', 1000, 400 );
$partial_claim = MBS_Invoice_Reservation::acquire( $partial );
if ( is_wp_error( $partial_claim ) || (int) $partial_claim['amount_minor'] !== 600 ) throw new RuntimeException( 'Reservation did not claim only the partial outstanding balance.' );
echo "OK: expiry, stale release/bind, renewal, cancellation, replacement, and partial-balance ownership passed.\n";
