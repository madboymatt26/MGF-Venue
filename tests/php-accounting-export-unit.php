<?php
define( 'ABSPATH', __DIR__ );
require_once dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-accounting-export.php';

$records = array(
    (object) array( 'contact_name'=>'Hirer','email'=>'hirer@example.invalid','invoice_number'=>'INV-1','invoice_date'=>'2026-04-01','due_date'=>'2026-04-15','total_decimal'=>'100.00','description'=>'Hall hire','booking_ref'=>'MBS-1','purpose'=>'Venue hire','document_type'=>'invoice' ),
    (object) array( 'contact_name'=>'Hirer','email'=>'hirer@example.invalid','invoice_number'=>'CN-1','invoice_date'=>'2026-04-02','due_date'=>'2026-04-02','total_decimal'=>'-25.00','description'=>'Cancellation credit','booking_ref'=>'MBS-1','purpose'=>'Credit note','document_type'=>'credit_note' ),
);

function export_rows( $method_name, $records ) {
    $stream = fopen( 'php://temp', 'w+' );
    $method = new ReflectionMethod( 'MBS_Accounting_Export', $method_name );
    if ( PHP_VERSION_ID < 80100 ) $method->setAccessible( true );
    $method->invoke( null, $stream, $records );
    rewind( $stream );
    $rows = array();
    while ( ( $row = fgetcsv( $stream, 0, ',', '"', '\\' ) ) !== false ) $rows[] = $row;
    fclose( $stream );
    return $rows;
}

$xero = export_rows( 'export_xero', $records );
$sage = export_rows( 'export_sage', $records );
$quickbooks = export_rows( 'export_quickbooks', $records );

$checks = array(
    $xero[1][5] === '100.00' && $xero[2][5] === '-25.00' && $xero[2][8] === '-25.00',
    $sage[1][0] === 'SI' && $sage[2][0] === 'SC' && $sage[2][6] === '25.00',
    $quickbooks[0][0] === 'TransactionType' && $quickbooks[1][0] === 'Invoice' && $quickbooks[2][0] === 'Credit Memo',
    $quickbooks[2][8] === '25.00' && $quickbooks[2][9] === '25.00',
);
foreach ( $checks as $check ) if ( ! $check ) { fwrite( STDERR, "FAIL: accounting credit-note CSV semantics.\n" ); exit( 1 ); }
echo "OK: 4 behavioural accounting CSV assertions passed (Xero signs, Sage SC, QuickBooks Credit Memo).\n";
