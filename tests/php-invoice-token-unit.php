<?php
define( 'ABSPATH', __DIR__ );
require_once dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-invoice-payment.php';

$token = 'test-invoice-bearer-token';
$invoice = (object) array( 'payment_token_hash' => hash( 'sha256', $token ) );
if ( ! MBS_Invoice_Payment::verify_token( $invoice, $token ) ) {
    fwrite( STDERR, "FAIL: Correct invoice token was rejected.\n" );
    exit( 1 );
}
if ( MBS_Invoice_Payment::verify_token( $invoice, 'wrong-token' ) ) {
    fwrite( STDERR, "FAIL: Incorrect invoice token was accepted.\n" );
    exit( 1 );
}
if ( MBS_Invoice_Payment::verify_token( (object) array( 'payment_token_hash' => '' ), $token ) ) {
    fwrite( STDERR, "FAIL: Missing stored token hash was accepted.\n" );
    exit( 1 );
}
echo "OK: 3 invoice-token assertions passed.\n";
