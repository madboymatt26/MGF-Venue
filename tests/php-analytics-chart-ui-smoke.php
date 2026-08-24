<?php
$plugin = dirname( __DIR__ ) . '/wp-plugin/mathlin-booking';
$reports = file_get_contents( $plugin . '/admin/views/analytics.php' );
$css = file_get_contents( $plugin . '/admin/admin.css' );

function analytics_chart_assert( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

analytics_chart_assert( strpos( $reports, 'class="mbs-analytics-charts"' ) !== false, 'Charts are not in the responsive reports grid.' );
analytics_chart_assert( substr_count( $reports, 'class="mbs-chart-panel"' ) === 7, 'Every report chart needs a bounded panel.' );
analytics_chart_assert( strpos( $reports, 'height="250"' ) === false, 'Intrinsic canvas height attributes must not control responsive sizing.' );
analytics_chart_assert( substr_count( $reports, 'maintainAspectRatio: false' ) === 7, 'Every Chart.js instance must use the bounded parent height.' );
analytics_chart_assert( strpos( $css, 'grid-template-columns: repeat(2, minmax(0, 1fr))' ) !== false, 'Desktop chart columns must be shrinkable.' );
analytics_chart_assert( strpos( $css, 'height: clamp(240px, 25vw, 320px)' ) !== false, 'Desktop chart height is not bounded.' );
analytics_chart_assert( strpos( $css, '.mbs-chart-panel canvas' ) !== false && strpos( $css, 'height: 100% !important' ) !== false, 'Canvas does not fill the bounded panel.' );
analytics_chart_assert( strpos( $css, '.mbs-chart-panel { height: 250px; padding: 1rem; }' ) !== false, 'Mobile chart height is not explicitly bounded.' );

echo "ANALYTICS_CHART_UI_SMOKE_OK\n";
