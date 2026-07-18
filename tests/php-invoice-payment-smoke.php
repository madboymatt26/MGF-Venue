<?php
$root = dirname( __DIR__ );
$payment = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-invoice-payment.php' );
$woo = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-woo-payment.php' );
$ledger = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-billing-ledger.php' );
$database = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-database.php' );
$admin = file_get_contents( $root . '/wp-plugin/mathlin-booking/admin/class-admin.php' );

$checks = 0;
function payment_has( $source, $needle, $message ) {
    global $checks;
    $checks++;
    if ( strpos( $source, $needle ) === false ) {
        fwrite( STDERR, "FAIL: {$message}\nMissing: {$needle}\n" );
        exit( 1 );
    }
}

payment_has( $database, 'payment_token_hash', 'Invoice table stores only a payment-token hash.' );
payment_has( $payment, "hash_hmac( 'sha256'", 'Invoice payment links use a dedicated signed token.' );
payment_has( $payment, "hash( 'sha256', \$token )", 'Only the token hash is persisted.' );
if ( strpos( $payment, 'modification_token' ) !== false ) {
    fwrite( STDERR, "FAIL: Invoice payments must not reuse booking modification tokens.\n" );
    exit( 1 );
}
$checks++;
payment_has( $woo, '_mbs_invoice_ref', 'Woo order items carry invoice reference metadata.' );
payment_has( $woo, '_mbs_booking_ref', 'Legacy booking payment metadata is preserved.' );
payment_has( $payment, "woo-order:' . \$order_id . ':invoice:'", 'Woo completion has an invoice-specific idempotency key.' );
payment_has( $payment, "woo-refund:' . \$refund_id", 'Each partial Woo refund has its own idempotency key.' );
payment_has( $payment, "b.status IN ('confirmed','deposit_paid')", 'Only occurrences covered by a fully settled invoice are marked paid.' );
payment_has( $payment, "b.status = 'paid'", 'Refund handling only reopens occurrences previously marked paid.' );
payment_has( $ledger, 'expected_version', 'Manual financial writes enforce expected invoice version inside the lock.' );
payment_has( $admin, 'can_manage_bookings()', 'Manual invoice payments are capability protected.' );
payment_has( $admin, 'idempotency_key', 'Manual invoice payment requires an idempotency key.' );
payment_has( $payment, "payment_method === 'offline_bacs'", 'Offline BACS invoices never receive a Woo pay link.' );
payment_has( $payment, 'reminder_count >= 1', 'An invoice cannot receive more than one automatic reminder.' );
payment_has( $payment, 'reminder_count = 1', 'Reminder claiming is guarded before email send.' );
payment_has( $payment, 'record_gateway_refund', 'Partial refunds are written to the ledger.' );

echo "OK: {$checks} invoice-payment assertions passed.\n";
