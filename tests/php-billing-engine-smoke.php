<?php
$root = dirname( __DIR__ );
$engine = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-billing-engine.php' );
$series = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-series.php' );
$database = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-database.php' );

$checks = 0;
function engine_has( $source, $needle, $message ) {
    global $checks;
    $checks++;
    if ( strpos( $source, $needle ) === false ) {
        fwrite( STDERR, "FAIL: {$message}\nMissing: {$needle}\n" );
        exit( 1 );
    }
}

foreach ( array( "'monthly'", "'termly'", "'legacy_per_occurrence'", "'upfront'" ) as $mode ) {
    engine_has( $engine, $mode, "Billing engine supports {$mode}." );
}
engine_has( $engine, "billing_treatment = 'invoice_managed'", 'Cron only automates deliberately invoice-managed series.' );
engine_has( $engine, ":period:' . \$period['period_key'] . ':v1'", 'Every generated period has a stable idempotency key.' );
engine_has( $engine, "status IN ('confirmed','deposit_paid','paid')", 'Cancelled and archived occurrences are excluded from schedules.' );
engine_has( $engine, 'pricing_snapshot', 'Invoice lines retain their booking price snapshot.' );
engine_has( $engine, 'reconcile_cancelled_occurrences', 'Issued bills reconcile later cancellations.' );
engine_has( $engine, 'create_credit_note', 'Cancellation reconciliation creates additive credits.' );
engine_has( $engine, 'release_booking_allocation', 'Credited cancellations release their active allocation.' );
engine_has( $engine, 'term_metadata_incomplete', 'Missing term coverage is reported rather than inferred.' );
engine_has( $series, 'invoice_lead_days', 'Series store invoice lead time.' );
engine_has( $series, 'payment_terms_days', 'Series store payment terms.' );
engine_has( $database, 'billing_schedule_json', 'Series persist structured term schedules.' );

echo "OK: {$checks} billing-engine source assertions passed.\n";
