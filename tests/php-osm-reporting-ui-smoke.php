<?php
$plugin = dirname( __DIR__ ) . '/wp-plugin/mathlin-booking';
$osm = file_get_contents( $plugin . '/includes/class-osm-accounting-v2.php' );
$osm_view = file_get_contents( $plugin . '/admin/views/osm-settings-v2.php' );
$reports = file_get_contents( $plugin . '/admin/views/analytics.php' );
$admin = file_get_contents( $plugin . '/admin/class-admin.php' );
$rest = file_get_contents( $plugin . '/includes/class-rest-api.php' );
$mcp = file_get_contents( dirname( __DIR__ ) . '/mcp-server/mgf-venue-mcp.ps1' );

function osm_reporting_assert( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

osm_reporting_assert( strpos( $osm, "'awaiting_bank_import' => 'Awaiting Co-op bank import'" ) !== false, 'Friendly waiting status is missing.' );
osm_reporting_assert( strpos( $osm, "'schema' => 3" ) !== false && strpos( $osm, "'components' => \$components" ) !== false, 'Immutable payout component audit is missing.' );
osm_reporting_assert( strpos( $osm, "if ( \$status !== 'delivered' ) continue" ) !== false, 'OSM report does not restrict posted totals to delivered payouts.' );
osm_reporting_assert( strpos( $osm_view, 'How reconciliation works' ) !== false && strpos( $osm_view, 'Expand calculation and audit trail' ) !== false, 'OSM workflow or drill-down is missing.' );
osm_reporting_assert( strpos( $osm_view, 'WooPayments components' ) !== false && strpos( $osm_view, 'Snapshot fingerprint' ) !== false, 'Payout calculation/audit detail is incomplete.' );
osm_reporting_assert( strpos( $admin, "'Reports & Analytics', 'Reports'" ) !== false, 'Reports menu rename is missing.' );
foreach ( array( 'Who venue income came from', 'Venue cash by payment route', 'What was added to OSM', 'Recent additions to OSM' ) as $heading ) osm_reporting_assert( strpos( $reports, $heading ) !== false, "Report section missing: {$heading}" );
osm_reporting_assert( strpos( $osm, "target_mode='bank_match' AND status='delivered'" ) !== false, 'Delivered direct/BACS entries are missing from OSM reporting.' );
osm_reporting_assert( strpos( $reports, 'do not add the two totals together' ) !== false, 'Double-counting warning is missing.' );
osm_reporting_assert( strpos( $reports, 'DATE(t.occurred_at) BETWEEN %s AND %s' ) !== false, 'Customer cash report is not transaction-date bounded.' );
osm_reporting_assert( strpos( $reports, "t.status='completed'" ) !== false, 'Customer cash report includes non-completed ledger transactions.' );
osm_reporting_assert( strpos( $rest, "'customers' => \$customers, 'payment_routes' => \$routes, 'osm' => \$osm_summary" ) !== false, 'Structured REST/MCP reporting parity is missing.' );
osm_reporting_assert( strpos( $mcp, '"/admin/analytics?${query}"' ) !== false && strpos( $mcp, 'date_from' ) !== false, 'MCP cannot request date-filtered reports.' );

echo "OSM_REPORTING_UI_SMOKE_OK\n";
