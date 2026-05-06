<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$table = $wpdb->prefix . MBS_TABLE;

// Date range — default to current financial year (April–March)
$now   = new DateTime();
$month = (int) $now->format( 'n' );
$year  = (int) $now->format( 'Y' );
if ( $month >= 4 ) {
    $fy_start = $year . '-04-01';
    $fy_end   = ( $year + 1 ) . '-03-31';
    $fy_label = $year . '/' . ( $year + 1 );
} else {
    $fy_start = ( $year - 1 ) . '-04-01';
    $fy_end   = $year . '-03-31';
    $fy_label = ( $year - 1 ) . '/' . $year;
}

// Bookings per month (last 12 months)
$monthly = $wpdb->get_results(
    "SELECT DATE_FORMAT(booking_date, '%Y-%m') as month, COUNT(*) as count, SUM(amount) as revenue
     FROM {$table} WHERE status IN ('confirmed', 'deposit_paid', 'paid') AND booking_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
     GROUP BY month ORDER BY month ASC"
);

// Bookings by space
$by_space = $wpdb->get_results(
    "SELECT space, COUNT(*) as count, SUM(amount) as revenue
     FROM {$table} WHERE status IN ('confirmed', 'deposit_paid', 'paid') AND booking_date BETWEEN '{$fy_start}' AND '{$fy_end}'
     GROUP BY space ORDER BY count DESC"
);

// Bookings by day of week
$by_day = $wpdb->get_results(
    "SELECT DAYNAME(booking_date) as day_name, DAYOFWEEK(booking_date) as day_num, COUNT(*) as count
     FROM {$table} WHERE status IN ('confirmed', 'deposit_paid', 'paid') AND booking_date BETWEEN '{$fy_start}' AND '{$fy_end}'
     GROUP BY day_name, day_num ORDER BY day_num ASC"
);

// Revenue this FY vs last FY
$revenue_fy = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE status IN ('confirmed', 'deposit_paid', 'paid') AND booking_date BETWEEN %s AND %s",
    $fy_start, $fy_end
) );

if ( $month >= 4 ) {
    $prev_fy_start = ( $year - 1 ) . '-04-01';
    $prev_fy_end   = $year . '-03-31';
} else {
    $prev_fy_start = ( $year - 2 ) . '-04-01';
    $prev_fy_end   = ( $year - 1 ) . '-03-31';
}
$revenue_prev = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE status IN ('confirmed', 'deposit_paid', 'paid') AND booking_date BETWEEN %s AND %s",
    $prev_fy_start, $prev_fy_end
) );

// Total bookings this FY
$total_fy = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE status IN ('confirmed', 'deposit_paid', 'paid') AND booking_date BETWEEN %s AND %s",
    $fy_start, $fy_end
) );

// Average booking value
$avg_value = $total_fy > 0 ? $revenue_fy / $total_fy : 0;

// Payment status breakdown
$paid_count     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'paid' AND booking_date BETWEEN %s AND %s", $fy_start, $fy_end ) );
$unpaid_count   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'confirmed' AND booking_date BETWEEN %s AND %s", $fy_start, $fy_end ) );

// Email queue stats
$email_stats = MBS_Email_Queue::get_stats();

// Prepare chart data
$chart_labels  = array();
$chart_counts  = array();
$chart_revenue = array();
foreach ( $monthly as $m ) {
    $chart_labels[]  = date( 'M Y', strtotime( $m->month . '-01' ) );
    $chart_counts[]  = (int) $m->count;
    $chart_revenue[] = (float) $m->revenue;
}

$space_labels  = array();
$space_counts  = array();
$space_revenue = array();
foreach ( $by_space as $s ) {
    $space_labels[]  = $s->space;
    $space_counts[]  = (int) $s->count;
    $space_revenue[] = (float) $s->revenue;
}

$day_labels = array();
$day_counts = array();
foreach ( $by_day as $d ) {
    $day_labels[] = $d->day_name;
    $day_counts[] = (int) $d->count;
}
?>
<div class="wrap mbs-admin">
    <h1>&#9884; Scout Bookings – Analytics</h1>
    <p class="nms-muted">Financial Year: <?php echo esc_html( $fy_label ); ?></p>

    <!-- Summary cards -->
    <div class="nms-stats-row" style="margin-bottom:2rem;">
        <div class="nms-stat-card">
            <div class="nms-stat-val"><?php echo esc_html( $total_fy ); ?></div>
            <div class="nms-stat-label">Bookings This FY</div>
        </div>
        <div class="nms-stat-card nms-stat-revenue">
            <div class="nms-stat-val">&pound;<?php echo number_format( $revenue_fy, 0 ); ?></div>
            <div class="nms-stat-label">Revenue This FY</div>
        </div>
        <div class="nms-stat-card">
            <div class="nms-stat-val">&pound;<?php echo number_format( $revenue_prev, 0 ); ?></div>
            <div class="nms-stat-label">Revenue Last FY</div>
        </div>
        <div class="nms-stat-card">
            <div class="nms-stat-val">&pound;<?php echo number_format( $avg_value, 0 ); ?></div>
            <div class="nms-stat-label">Avg Booking Value</div>
        </div>
        <div class="nms-stat-card nms-stat-confirmed">
            <div class="nms-stat-val"><?php echo $paid_count; ?> / <?php echo $paid_count + $unpaid_count; ?></div>
            <div class="nms-stat-label">Paid / Total</div>
        </div>
    </div>

    <!-- Charts -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">

        <!-- Monthly bookings -->
        <div class="nms-card">
            <div class="nms-card-header"><h2>📊 Bookings Per Month</h2></div>
            <div style="padding:1.5rem;">
                <canvas id="mbs-chart-monthly" height="250"></canvas>
            </div>
        </div>

        <!-- Monthly revenue -->
        <div class="nms-card">
            <div class="nms-card-header"><h2>💷 Revenue Per Month</h2></div>
            <div style="padding:1.5rem;">
                <canvas id="mbs-chart-revenue" height="250"></canvas>
            </div>
        </div>

        <!-- By space -->
        <div class="nms-card">
            <div class="nms-card-header"><h2>🏛️ Bookings By Space</h2></div>
            <div style="padding:1.5rem;">
                <canvas id="mbs-chart-space" height="250"></canvas>
            </div>
        </div>

        <!-- By day of week -->
        <div class="nms-card">
            <div class="nms-card-header"><h2>📅 Busiest Days</h2></div>
            <div style="padding:1.5rem;">
                <canvas id="mbs-chart-days" height="250"></canvas>
            </div>
        </div>
    </div>

    <!-- Tables -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-top:1.5rem;">

        <!-- Space breakdown -->
        <div class="nms-card">
            <div class="nms-card-header"><h2>Space Breakdown (FY <?php echo esc_html( $fy_label ); ?>)</h2></div>
            <div style="padding:1rem 1.5rem;">
                <table class="wp-list-table widefat fixed striped" style="font-size:0.85rem;">
                    <thead><tr><th>Space</th><th>Bookings</th><th>Revenue</th></tr></thead>
                    <tbody>
                    <?php foreach ( $by_space as $s ) : ?>
                        <tr>
                            <td><?php echo esc_html( $s->space ); ?></td>
                            <td><?php echo esc_html( $s->count ); ?></td>
                            <td>&pound;<?php echo number_format( $s->revenue, 2 ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ( empty( $by_space ) ) : ?>
                        <tr><td colspan="3" class="nms-muted">No data yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Email queue status -->
        <div class="nms-card">
            <div class="nms-card-header"><h2>📧 Email Queue</h2></div>
            <div style="padding:1rem 1.5rem;">
                <table class="wp-list-table widefat fixed striped" style="font-size:0.85rem;">
                    <tbody>
                        <tr><td>Pending (queued for retry)</td><td><strong><?php echo $email_stats['pending']; ?></strong></td></tr>
                        <tr><td>Sent (retried successfully)</td><td><strong><?php echo $email_stats['sent']; ?></strong></td></tr>
                        <tr><td>Failed (gave up after 3 attempts)</td><td><strong style="color:#e74c3c;"><?php echo $email_stats['failed']; ?></strong></td></tr>
                    </tbody>
                </table>
                <p class="nms-muted" style="margin-top:8px;font-size:0.8rem;">Queue processes hourly. Failed emails are cleaned up after 30 days.</p>
            </div>
        </div>
    </div>

    <!-- Occupancy Report -->
    <?php
    // Calculate occupancy per space for this FY
    // Assume 12 hours available per day (8am-8pm), 7 days a week
    $hours_per_day = 12;
    $fy_days = max( 1, (int) round( ( strtotime( min( date('Y-m-d'), $fy_end ) ) - strtotime( $fy_start ) ) / 86400 ) );
    $total_available_hours = $fy_days * $hours_per_day;

    $occupancy_data = array();
    foreach ( MBS_Bookings::get_spaces() as $space_name => $space_info ) {
        $booked_hours = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(
                CASE
                    WHEN all_day = 1 THEN {$hours_per_day}
                    WHEN start_time IS NOT NULL AND end_time IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, start_time, end_time) / 60
                    ELSE 0
                END
            ), 0) FROM {$table}
            WHERE space = %s AND status IN ('confirmed', 'deposit_paid', 'paid') AND booking_date BETWEEN %s AND %s",
            $space_name, $fy_start, $fy_end
        ) );
        $pct = $total_available_hours > 0 ? round( ( $booked_hours / $total_available_hours ) * 100, 1 ) : 0;
        $occupancy_data[] = array(
            'space'   => $space_name,
            'hours'   => round( $booked_hours, 1 ),
            'total'   => $total_available_hours,
            'percent' => $pct,
        );
    }
    ?>
    <div class="nms-card" style="margin-top:1.5rem;">
        <div class="nms-card-header"><h2>📈 Occupancy / Utilisation (FY <?php echo esc_html( $fy_label ); ?>)</h2></div>
        <div style="padding:1rem 1.5rem;">
            <p class="nms-muted" style="margin-bottom:1rem;">Based on <?php echo $hours_per_day; ?> available hours per day (8am–8pm), <?php echo $fy_days; ?> days in the period.</p>
            <table class="wp-list-table widefat fixed striped" style="font-size:0.85rem;">
                <thead><tr><th>Space</th><th>Hours Booked</th><th>Hours Available</th><th>Utilisation</th></tr></thead>
                <tbody>
                <?php foreach ( $occupancy_data as $occ ) : ?>
                    <tr>
                        <td><?php echo esc_html( $occ['space'] ); ?></td>
                        <td><?php echo esc_html( $occ['hours'] ); ?> hrs</td>
                        <td><?php echo esc_html( $occ['total'] ); ?> hrs</td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div style="flex:1;background:#e5e7eb;border-radius:4px;height:16px;overflow:hidden;">
                                    <div style="width:<?php echo min( 100, $occ['percent'] ); ?>%;background:#7413DC;height:100%;border-radius:4px;"></div>
                                </div>
                                <strong><?php echo $occ['percent']; ?>%</strong>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Accounting Export -->
    <div class="nms-card" style="margin-top:1.5rem;">
        <div class="nms-card-header"><h2>💼 Accounting Export</h2></div>
        <div style="padding:1.5rem;">
            <p>Export invoices in a format compatible with your accounting software.</p>
            <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-top:1rem;">
                <div style="display:flex;gap:0.5rem;align-items:center;">
                    <label style="font-size:0.85rem;font-weight:600;">From:</label>
                    <input type="date" id="mbs-acc-from" value="<?php echo esc_attr( $fy_start ); ?>">
                    <label style="font-size:0.85rem;font-weight:600;">To:</label>
                    <input type="date" id="mbs-acc-to" value="<?php echo esc_attr( min( date('Y-m-d'), $fy_end ) ); ?>">
                </div>
            </div>
            <div style="display:flex;gap:0.5rem;margin-top:1rem;">
                <a id="mbs-export-xero" href="#" class="button button-primary">📊 Export for Xero</a>
                <a id="mbs-export-sage" href="#" class="button">📊 Export for Sage</a>
                <a id="mbs-export-qb" href="#" class="button">📊 Export for QuickBooks</a>
            </div>
            <p class="nms-muted" style="margin-top:0.75rem;font-size:0.8rem;">Exports confirmed, paid, and archived bookings as CSV. Import directly into your accounting software.</p>
        </div>
    </div>
</div>

<!-- Chart.js from CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
    var purple = '#7413DC';
    var gold   = '#f5a623';
    var green  = '#2ecc71';
    var blue   = '#3498db';

    // Monthly bookings
    new Chart(document.getElementById('mbs-chart-monthly'), {
        type: 'bar',
        data: {
            labels: <?php echo wp_json_encode( $chart_labels ); ?>,
            datasets: [{ label: 'Bookings', data: <?php echo wp_json_encode( $chart_counts ); ?>, backgroundColor: purple, borderRadius: 4 }]
        },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    });

    // Monthly revenue
    new Chart(document.getElementById('mbs-chart-revenue'), {
        type: 'line',
        data: {
            labels: <?php echo wp_json_encode( $chart_labels ); ?>,
            datasets: [{ label: 'Revenue (£)', data: <?php echo wp_json_encode( $chart_revenue ); ?>, borderColor: green, backgroundColor: 'rgba(46,204,113,0.1)', fill: true, tension: 0.3 }]
        },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });

    // By space (doughnut)
    new Chart(document.getElementById('mbs-chart-space'), {
        type: 'doughnut',
        data: {
            labels: <?php echo wp_json_encode( $space_labels ); ?>,
            datasets: [{ data: <?php echo wp_json_encode( $space_counts ); ?>, backgroundColor: [purple, gold, green, blue, '#e74c3c', '#9b59b6'] }]
        },
        options: { responsive: true }
    });

    // By day of week
    new Chart(document.getElementById('mbs-chart-days'), {
        type: 'bar',
        data: {
            labels: <?php echo wp_json_encode( $day_labels ); ?>,
            datasets: [{ label: 'Bookings', data: <?php echo wp_json_encode( $day_counts ); ?>, backgroundColor: gold, borderRadius: 4 }]
        },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    });
})();

// Accounting export links
document.querySelectorAll('#mbs-export-xero, #mbs-export-sage, #mbs-export-qb').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        var format = 'xero';
        if (this.id === 'mbs-export-sage') format = 'sage';
        if (this.id === 'mbs-export-qb') format = 'quickbooks';
        var from = document.getElementById('mbs-acc-from').value;
        var to   = document.getElementById('mbs-acc-to').value;
        var url  = '<?php echo admin_url( "admin-ajax.php" ); ?>?action=mbs_export_accounting&nonce=<?php echo wp_create_nonce( "mbs_admin_nonce" ); ?>&format=' + format + '&date_from=' + from + '&date_to=' + to;
        window.location.href = url;
    });
});
</script>
