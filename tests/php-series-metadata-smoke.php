<?php
// Static regression checks for the additive recurring-series domain.
$root = dirname( __DIR__ );
$database = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-database.php' );
$series = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-series.php' );
$public = file_get_contents( $root . '/wp-plugin/mathlin-booking/public/class-public.php' );
$email = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-email.php' );
$javascript = file_get_contents( $root . '/wp-plugin/mathlin-booking/public/public.js' );

$assertions = 0;
function contains_text( $haystack, $needle, $message ) {
    global $assertions;
    $assertions++;
    if ( strpos( $haystack, $needle ) === false ) {
        fwrite( STDERR, "FAIL: {$message}\nMissing: {$needle}\n" );
        exit( 1 );
    }
}

foreach ( array(
    'series_ref', 'contact_name', 'contact_email', 'schedule_json',
    'price_per_booking', 'estimated_total', 'requested_count',
    'accepted_count', 'exceptions_json', 'billing_mode',
    'billing_treatment', 'payment_method', 'automatic_reminders',
    'terms_hash', 'terms_accepted_at', 'confirmation_sent_at', 'version',
) as $column ) {
    contains_text( $database, $column, "Series schema contains {$column}." );
}
contains_text( $database, 'UNIQUE KEY series_ref', 'Series references are unique.' );
contains_text( $database, 'idx_series_billing', 'Billing lookup is indexed.' );
if ( preg_match( '/FOREIGN\s+KEY\s*\(/i', $database ) ) {
    fwrite( STDERR, "FAIL: WordPress-managed tables must not introduce foreign keys.\n" );
    exit( 1 );
}

contains_text( $series, "'billing_treatment'    => \$is_scout ? 'none' : 'invoice_managed'", 'New external series default to invoice-managed monthly billing.' );
contains_text( $series, "hash( 'sha256'", 'Terms content is hashed.' );
contains_text( $public, 'notify_admin_series', 'Public submission sends one series-level admin notification.' );
contains_text( $public, 'notify_recurring_summary', 'Public submission sends one series-level request receipt.' );
contains_text( $email, 'Amount due at submission', 'Receipt states the submission amount.' );
contains_text( $email, 'Estimated full series value', 'Receipt labels the estimate accurately.' );
contains_text( $javascript, 'if (!isRecurring && depositSettings.enabled', 'Annual deposits are suppressed for recurring requests.' );
contains_text( $javascript, "$('#nms-cost-recurring-due').text('£0.00')", 'Browser preview shows zero due at submission.' );

echo "OK: {$assertions} recurring-series metadata assertions passed.\n";
