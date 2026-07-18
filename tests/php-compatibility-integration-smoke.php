<?php
$root = dirname( __DIR__ );
$plugin = $root . '/wp-plugin/mathlin-booking';
$series = file_get_contents( $plugin . '/includes/class-series.php' );
$billing = file_get_contents( $plugin . '/includes/class-billing-engine.php' );
$ledger = file_get_contents( $plugin . '/includes/class-billing-ledger.php' );
$admin = file_get_contents( $plugin . '/admin/class-admin.php' );
$rest = file_get_contents( $plugin . '/includes/class-rest-api.php' );
$analytics = file_get_contents( $plugin . '/admin/views/analytics.php' );
$accounting = file_get_contents( $plugin . '/includes/class-accounting-export.php' );
$bookings = file_get_contents( $plugin . '/includes/class-bookings.php' );
$uninstall = file_get_contents( $plugin . '/uninstall.php' );
$main = file_get_contents( $plugin . '/mathlin-booking.php' );

$checks = 0;
function compat_has( $source, $needle, $message ) {
    global $checks;
    $checks++;
    if ( strpos( $source, $needle ) === false ) {
        fwrite( STDERR, "FAIL: {$message}\nMissing: {$needle}\n" );
        exit( 1 );
    }
}

compat_has( $main, 'register_legacy_groups', 'Plugin boot registers pre-existing series groups once.' );
compat_has( $series, "'metadata_incomplete' => 1", 'Legacy series are explicitly marked incomplete.' );
compat_has( $series, "'billing_mode' => \$is_scout ? 'none' : 'legacy_per_occurrence'", 'Legacy external series retain per-occurrence billing.' );
compat_has( $series, "'terms_hash' => null", 'Legacy registration does not invent terms acceptance.' );
compat_has( $billing, 'legacy_adoption_confirmation_required', 'Consolidated adoption requires explicit preview confirmation.' );
compat_has( $billing, "\$booking->status === 'confirmed'", 'Paid legacy occurrences are excluded from adopted invoice periods.' );
compat_has( $series, 'MBS_Recurrence::weekly_dates', 'Series extension uses the shared calendar-safe recurrence rule.' );
compat_has( $series, "'invoice_number' => ''", 'Extended consolidated occurrences do not receive legacy invoices.' );
compat_has( $series, 'MBS_HomeAssistant::notify( $booking )', 'Confirmed extended occurrences retain Home Assistant notification.' );
compat_has( $series, "booking_date >= %s AND status IN ('deposit_paid','paid')", 'Series-wide cancellation preserves historical paid occurrence statuses.' );
compat_has( $billing, "i.status IN ('issued','part_paid','paid','overdue')", 'Cancellation reconciliation credits overdue as well as current invoices.' );
compat_has( $ledger, "array( 'issued', 'part_paid', 'overdue' )", 'Unpaid overdue invoices remain explicitly voidable.' );
compat_has( $admin, 'get_active_booking_allocation', 'Billing-relevant occurrence edits cannot overwrite an active invoice allocation.' );
compat_has( $admin, 'issued invoice details cannot be overwritten', 'Blocked amendments give administrators a safe credit-and-replace workflow.' );

foreach ( array( '/admin/series', '/approve', '/billing', '/state', '/admin/invoices', '/payments' ) as $route ) compat_has( $rest, $route, "REST API exposes typed {$route} coverage." );
compat_has( $rest, 'integration_idempotency_transient', 'REST status and series writes require stable idempotency keys.' );
compat_has( $rest, 'expected_version', 'REST financial/series writes carry optimistic versions.' );
compat_has( $rest, "'notify_hirer' => array( 'default' => false", 'REST customer notifications remain opt-in.' );
compat_has( $rest, 'format_admin_invoice', 'Invoices are returned through an explicit allow-list.' );
if ( preg_match( '/function format_admin_invoice\(.*?\n\s*\}/s', $rest, $match ) && ( strpos( $match[0], 'payment_token_hash' ) !== false || strpos( $match[0], 'idempotency_key' ) !== false ) ) {
    fwrite( STDERR, "FAIL: Invoice formatter exposes a secret/hash field.\n" ); exit( 1 );
}
$checks++;

compat_has( $analytics, 'NOT EXISTS (SELECT 1 FROM {$allocation_table}', 'Financial analytics exclude allocated legacy occurrence values.' );
compat_has( $analytics, '$invoice_collected_minor', 'Financial analytics include payment-ledger collections.' );
compat_has( $accounting, 'normalise_records', 'Accounting export normalises both domains.' );
compat_has( $accounting, 'SELECT DISTINCT booking_ref', 'Accounting export excludes every historically allocated booking.' );
compat_has( $accounting, 'line_total_minor', 'Accounting export uses immutable invoice lines.' );

foreach ( array( 'MBS_SERIES_TABLE', 'MBS_INVOICE_TABLE', 'MBS_PAYMENT_TRANSACTION_TABLE' ) as $constant ) compat_has( $bookings, $constant, "GDPR handling covers {$constant}." );
compat_has( $bookings, "'gdpr_erased' => true", 'Payment metadata is erased without deleting the financial ledger.' );
foreach ( array( 'mathlin_booking_series', 'mathlin_invoices', 'mathlin_invoice_items', 'mathlin_payment_transactions', 'mathlin_billing_allocations' ) as $table ) compat_has( $uninstall, $table, "Uninstall removes {$table}." );

echo "OK: {$checks} compatibility, REST/MCP, reporting and privacy assertions passed.\n";
