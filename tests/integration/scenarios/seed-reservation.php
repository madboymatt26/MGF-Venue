<?php
global $wpdb;
$reservation_table = $wpdb->prefix . MBS_PAYMENT_RESERVATION_TABLE;
$invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
$wpdb->query( $wpdb->prepare( "DELETE FROM {$reservation_table} WHERE invoice_ref = %s", 'INT-RES-1' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$invoice_table} WHERE invoice_ref = %s", 'INT-RES-1' ) );
$now = current_time( 'mysql' );
$ok = $wpdb->insert( $invoice_table, array(
    'invoice_ref'=>'INT-RES-1','document_type'=>'invoice','status'=>'issued','version'=>1,
    'contact_name'=>'Integration Hirer','contact_email'=>'integration@example.invalid','contact_address'=>'Test only',
    'billing_mode'=>'monthly','currency'=>'GBP','subtotal_minor'=>1000,'total_minor'=>1000,'paid_minor'=>0,'credited_minor'=>0,
    'idempotency_key'=>hash('sha256','integration-reservation'),'idempotency_request_hash'=>hash('sha256','integration-payload'),
    'issued_at'=>$now,'due_at'=>$now,'created_at'=>$now,'updated_at'=>$now,
) );
if ( $ok === false ) throw new RuntimeException( $wpdb->last_error );
echo "OK: disposable invoice seeded.\n";
