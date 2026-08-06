<?php
$root = dirname( __DIR__ );
$database = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-database.php' );
$ledger = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-billing-ledger.php' );

$checks = 0;
function domain_has( $source, $needle, $message ) {
    global $checks;
    $checks++;
    if ( strpos( $source, $needle ) === false ) {
        fwrite( STDERR, "FAIL: {$message}\nMissing: {$needle}\n" );
        exit( 1 );
    }
}

foreach ( array( 'MBS_INVOICE_TABLE', 'MBS_INVOICE_ITEM_TABLE', 'MBS_PAYMENT_TRANSACTION_TABLE', 'MBS_BILLING_ALLOCATION_TABLE' ) as $table ) {
    domain_has( $database, $table, "Database creates {$table}." );
}
foreach ( array( 'subtotal_minor', 'total_minor', 'paid_minor', 'credited_minor', 'unit_amount_minor', 'line_total_minor', 'amount_minor', 'allocated_minor' ) as $column ) {
    domain_has( $database, $column, "Financial schema contains {$column}." );
}
domain_has( $database, 'UNIQUE KEY active_booking (active_booking_ref)', 'Only one active allocation can exist per booking.' );
domain_has( $database, 'UNIQUE KEY invoice_idempotency', 'Invoice creation is idempotent at database level.' );
domain_has( $database, 'UNIQUE KEY transaction_idempotency', 'Payment writes are idempotent at database level.' );
domain_has( $database, 'document_type', 'Invoices and credit notes are distinct immutable documents.' );

$financial_schema_start = strpos( $database, '// Immutable consolidated invoice documents.' );
$financial_schema_end = strpos( $database, '// Blocked dates table', $financial_schema_start );
$financial_schema = substr( $database, $financial_schema_start, $financial_schema_end - $financial_schema_start );
if ( preg_match( '/\b(?:FLOAT|DOUBLE|DECIMAL)\b/i', $financial_schema ) ) {
    fwrite( STDERR, "FAIL: New financial tables must persist only integer minor units, not floating point/decimal money.\n" );
    exit( 1 );
}
$checks++;

domain_has( $ledger, "\$invoice->status !== 'draft'", 'Invoice items cannot change after issue.' );
domain_has( $ledger, 'issued_invoice_immutable', 'Immutable issue guard has an explicit error.' );
domain_has( $ledger, 'create_credit_note', 'Credits are additive documents.' );
domain_has( $ledger, 'paid_invoice_requires_credit', 'Paid invoices cannot be voided away.' );
domain_has( $ledger, 'record_transaction', 'Payments and refunds are ledger transactions.' );
domain_has( $ledger, 'idempotency_hash', 'Every financial write is keyed idempotently.' );
domain_has( $ledger, "active_booking_ref = NULL", 'Voiding releases rather than deletes allocations.' );
if ( preg_match( '/\bDELETE\s+FROM\b/i', $ledger ) ) {
    fwrite( STDERR, "FAIL: Financial documents and transactions must never be deleted.\n" );
    exit( 1 );
}
$checks++;

echo "OK: {$checks} financial-domain assertions passed.\n";
