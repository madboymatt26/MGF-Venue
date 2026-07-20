<?php
$invoice_ref = isset( $args[0] ) ? sanitize_text_field( $args[0] ) : '';
$order_id = isset( $args[1] ) ? absint( $args[1] ) : 0;
$invoice = MBS_Billing_Ledger::get_invoice( $invoice_ref );
$claim = MBS_Invoice_Reservation::acquire( $invoice );
if ( is_wp_error( $claim ) ) { fwrite( STDERR, $claim->get_error_code() . "\n" ); exit( 17 ); }
$bound = MBS_Invoice_Reservation::bind_order( $invoice_ref, $claim['reservation_ref'], $order_id );
if ( is_wp_error( $bound ) ) { fwrite( STDERR, $bound->get_error_code() . "\n" ); exit( 18 ); }
echo wp_json_encode( $bound ) . "\n";
