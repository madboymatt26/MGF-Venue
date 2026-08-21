<?php
$root = dirname( __DIR__ );
$admin = file_get_contents( $root . '/wp-plugin/mathlin-booking/admin/class-admin.php' );
$bookings = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-bookings.php' );
$series = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-series.php' );
$view = file_get_contents( $root . '/wp-plugin/mathlin-booking/admin/views/scout-nights.php' );
$js = file_get_contents( $root . '/wp-plugin/mathlin-booking/admin/admin.js' );
$css = file_get_contents( $root . '/wp-plugin/mathlin-booking/admin/admin.css' );
$rest = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-rest-api.php' );
$mcp = file_get_contents( $root . '/mcp-server/mgf-venue-mcp.ps1' );
$bootstrap = file_get_contents( $root . '/wp-plugin/mathlin-booking/mathlin-booking.php' );

$checks = 0;
function scout_series_has( $source, $needle, $message ) {
    global $checks;
    $checks++;
    if ( strpos( $source, $needle ) === false ) {
        fwrite( STDERR, "FAIL: {$message}\nMissing: {$needle}\n" );
        exit( 1 );
    }
}

scout_series_has( $admin, "'scout_use' => 1", 'Scout Nights list is filtered to Scout series.' );
scout_series_has( $admin, "'scout_use' => 0", 'External recurring list excludes Scout series.' );
scout_series_has( $admin, 'MBS_Bookings::create_recurring( $data, $date_to, true )', 'Scout creation uses canonical atomic recurrence creation.' );
scout_series_has( $admin, 'MBS_Series::approve( $result[\'series_id\'], \'pending\'', 'New Scout series are approved through the no-charge series transition.' );
scout_series_has( $series, 'synchronize_scout_series', 'Scout parent snapshots have a synchronisation service.' );
scout_series_has( $series, 'occurrence_summaries', 'Series lists use batched occurrence summaries.' );
scout_series_has( $series, 'synchronize_all_scout_series', 'Existing Scout parents have an upgrade reconciliation path.' );
scout_series_has( $bootstrap, 'mbs_scout_series_reconciled', 'Scout parent reconciliation is version-gated and retryable.' );
scout_series_has( $bookings, 'return self::is_scout_series( $series_id )', 'Legacy Scout guards accept canonical no-charge Scout series.' );
scout_series_has( $view, 'Scout use · no charge', 'Scout detail clearly labels no-charge internal use.' );
scout_series_has( $view, 'Edit future nights', 'Scout-specific future edit controls remain available.' );
scout_series_has( $view, 'Cancel this night', 'Individual Scout occurrence cancellation remains available.' );
scout_series_has( $view, 'Skipped dates and exceptions', 'Skipped dates remain visible.' );
scout_series_has( $view, 'Audit history', 'Scout series audit history remains visible.' );
scout_series_has( $js, 'Scout series administration', 'Scout actions are maintained in the shared admin script.' );
scout_series_has( $css, 'Shared series administration / Scout Nights', 'Scout series layout has responsive shared styling.' );
scout_series_has( $rest, "'series_kind'", 'REST series listing exposes the Scout/external discriminator.' );
scout_series_has( $mcp, 'series_kind', 'MCP list_series exposes the Scout/external discriminator.' );

if ( strpos( $view, 'Invoices &amp; payments' ) !== false || strpos( $view, 'Billing arrangement' ) !== false ) {
    fwrite( STDERR, "FAIL: Scout series detail exposes external billing controls.\n" );
    exit( 1 );
}
$checks++;

echo "OK: {$checks} Scout-series administration assertions passed.\n";
