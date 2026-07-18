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

// Bookings per month (last 12 months) — exclude internal Scout bookings
$monthly = $wpdb->get_results(
    "SELECT DATE_FORMAT(booking_date, '%Y-%m') as month, COUNT(*) as count, SUM(amount) as revenue
     FROM {$table} WHERE status IN ('confirmed', 'deposit_paid', 'paid') AND scout_use = 0 AND booking_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
     GROUP BY month ORDER BY month ASC"
);

// Bookings by space — exclude internal Scout bookings
$by_space = $wpdb->get_results(
    "SELECT space, COUNT(*) as count, SUM(amount) as revenue
     FROM {$table} WHERE status IN ('confirmed', 'deposit_paid', 'paid') AND scout_use = 0 AND booking_date BETWEEN '{$fy_start}' AND '{$fy_end}'
     GROUP BY space ORDER BY count DESC"
);

// Bookings by day of week — exclude internal Scout bookings
$by_day = $wpdb->get_results(
    "SELECT DAYNAME(booking_date) as day_name, DAYOFWEEK(booking_date) as day_num, COUNT(*) as count
     FROM {$table} WHERE status IN ('confirmed', 'deposit_paid', 'paid') AND scout_use = 0 AND booking_date BETWEEN '{$fy_start}' AND '{$fy_end}'
     GROUP BY day_name, day_num ORDER BY day_num ASC"
);

// Revenue this FY vs last FY — exclude internal Scout bookings
$revenue_fy = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE status IN ('confirmed', 'deposit_paid', 'paid') AND scout_use = 0 AND booking_date BETWEEN %s AND %s",
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
    "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE status IN ('confirmed', 'deposit_paid', 'paid') AND scout_use = 0 AND booking_date BETWEEN %s AND %s",
    $prev_fy_start, $prev_fy_end
) );

// Total bookings this FY — exclude internal Scout bookings
$total_fy = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE status IN ('confirmed', 'deposit_paid', 'paid') AND scout_use = 0 AND booking_date BETWEEN %s AND %s",
    $fy_start, $fy_end
) );

// Average booking value
$avg_value = $total_fy > 0 ? $revenue_fy / $total_fy : 0;

// Payment status breakdown — exclude internal Scout bookings
$paid_count     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'paid' AND scout_use = 0 AND booking_date BETWEEN %s AND %s", $fy_start, $fy_end ) );
$unpaid_count   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'confirmed' AND scout_use = 0 AND booking_date BETWEEN %s AND %s", $fy_start, $fy_end ) );

// ── Financial: immutable invoices + genuinely legacy unallocated bookings ────
$invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
$transaction_table = $wpdb->prefix . MBS_PAYMENT_TRANSACTION_TABLE;
$allocation_table = $wpdb->prefix . MBS_BILLING_ALLOCATION_TABLE;
$legacy_billed = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(b.amount), 0) FROM {$table} b
     WHERE b.status IN ('confirmed','deposit_paid','paid') AND b.scout_use = 0
     AND b.booking_date BETWEEN %s AND %s AND NOT EXISTS (SELECT 1 FROM {$allocation_table} a WHERE a.booking_ref = b.ref)",
    $fy_start, $fy_end
) );
$invoice_billed_minor = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(total_minor), 0) FROM {$invoice_table}
     WHERE document_type IN ('invoice','credit_note') AND status NOT IN ('draft','void') AND DATE(issued_at) BETWEEN %s AND %s",
    $fy_start, $fy_end
) );
$billed_fy = $legacy_billed + ( $invoice_billed_minor / 100 );
$legacy_collected = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(b.amount_paid), 0) FROM {$table} b
     WHERE b.scout_use = 0 AND b.booking_date BETWEEN %s AND %s
     AND NOT EXISTS (SELECT 1 FROM {$allocation_table} a WHERE a.booking_ref = b.ref)",
    $fy_start, $fy_end
) );
$invoice_collected_minor = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(CASE WHEN t.transaction_type = 'payment' THEN t.amount_minor ELSE -t.amount_minor END), 0)
     FROM {$transaction_table} t INNER JOIN {$invoice_table} i ON i.id = t.invoice_id
     WHERE t.status = 'completed' AND DATE(t.occurred_at) BETWEEN %s AND %s",
    $fy_start, $fy_end
) );
$collected_fy = $legacy_collected + ( $invoice_collected_minor / 100 );

// Outstanding debtors: balance still owed on live (non-cancelled, non-archived)
// bookings, regardless of event date — this is money currently chaseable.
$legacy_outstanding = (float) $wpdb->get_var(
    "SELECT COALESCE(SUM(GREATEST(b.amount - b.amount_paid, 0)), 0) FROM {$table} b
     WHERE b.status IN ('confirmed','deposit_paid') AND b.scout_use = 0
     AND NOT EXISTS (SELECT 1 FROM {$allocation_table} a WHERE a.booking_ref = b.ref)"
);
$invoice_outstanding_minor = (int) $wpdb->get_var(
    "SELECT COALESCE(SUM(GREATEST(total_minor - paid_minor - credited_minor, 0)), 0) FROM {$invoice_table}
     WHERE document_type = 'invoice' AND status IN ('issued','part_paid','overdue')"
);
$outstanding_total = $legacy_outstanding + ( $invoice_outstanding_minor / 100 );
$outstanding_count = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$table} b WHERE b.status IN ('confirmed','deposit_paid') AND b.scout_use = 0
     AND (b.amount - b.amount_paid) > 0.01 AND NOT EXISTS (SELECT 1 FROM {$allocation_table} a WHERE a.booking_ref = b.ref)"
) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$invoice_table} WHERE document_type = 'invoice' AND status IN ('issued','part_paid','overdue') AND total_minor > paid_minor + credited_minor" );
$overdue_count = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$table} b WHERE b.status IN ('confirmed','deposit_paid') AND b.scout_use = 0
     AND (b.amount - b.amount_paid) > 0.01 AND b.chase_count > 0 AND NOT EXISTS (SELECT 1 FROM {$allocation_table} a WHERE a.booking_ref = b.ref)"
) + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$invoice_table} WHERE document_type = 'invoice' AND status IN ('issued','part_paid','overdue') AND due_at < %s AND total_minor > paid_minor + credited_minor", current_time( 'mysql' ) ) );

// Collection rate (% of billed income actually received) this FY
$collection_rate = $billed_fy > 0 ? round( ( $collected_fy / $billed_fy ) * 100, 1 ) : 0;

// Deposits held: money received on bookings not yet fully paid
$deposits_held = (float) $wpdb->get_var(
    "SELECT COALESCE(SUM(b.amount_paid), 0) FROM {$table} b
     WHERE b.status = 'deposit_paid' AND b.scout_use = 0
     AND NOT EXISTS (SELECT 1 FROM {$allocation_table} a WHERE a.booking_ref = b.ref)"
);

// Kitchen add-on uptake & estimated income (this FY), grouped by the tier
// actually stored on each booking so discounted/uplifted rates are reflected.
$kitchen_by_tier = $wpdb->get_results( $wpdb->prepare(
    "SELECT COALESCE(NULLIF(pricing_tier, ''), 'standard') AS tier, COUNT(*) AS count
     FROM {$table} WHERE kitchen = 1 AND status IN ('confirmed', 'deposit_paid', 'paid')
     AND scout_use = 0 AND booking_date BETWEEN %s AND %s GROUP BY tier",
    $fy_start, $fy_end
) );
$kitchen_count  = 0;
$kitchen_income = 0;
foreach ( $kitchen_by_tier as $kitchen_tier ) {
    $tier_count      = (int) $kitchen_tier->count;
    $kitchen_count  += $tier_count;
    $kitchen_income += $tier_count * MBS_Bookings::get_tiered_kitchen_price( $kitchen_tier->tier );
}

// ── Demand: average booking lead time (days between created and event) ────────
$avg_lead_time = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(AVG(DATEDIFF(booking_date, DATE(created_at))), 0) FROM {$table}
     WHERE status IN ('confirmed', 'deposit_paid', 'paid') AND scout_use = 0 AND booking_date BETWEEN %s AND %s AND booking_date >= DATE(created_at)",
    $fy_start, $fy_end
) );

// Average attendees per booking (this FY)
$avg_attendees = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(AVG(attendees), 0) FROM {$table} WHERE status IN ('confirmed', 'deposit_paid', 'paid') AND scout_use = 0 AND booking_date BETWEEN %s AND %s",
    $fy_start, $fy_end
) );

// Forward pipeline: confirmed/deposit/paid bookings still upcoming
$today_str = wp_date( 'Y-m-d' );
$pipeline_30 = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE status IN ('confirmed', 'deposit_paid', 'paid') AND scout_use = 0 AND booking_date BETWEEN %s AND %s",
    $today_str, wp_date( 'Y-m-d', strtotime( '+30 days' ) )
) );
$pipeline_90 = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE status IN ('confirmed', 'deposit_paid', 'paid') AND scout_use = 0 AND booking_date BETWEEN %s AND %s",
    $today_str, wp_date( 'Y-m-d', strtotime( '+90 days' ) )
) );

// ── Time-of-day distribution (this FY) ────────────────────────────────────────
$by_timeslot = $wpdb->get_results( $wpdb->prepare(
    "SELECT
        CASE
            WHEN all_day = 1 THEN 'All day'
            WHEN HOUR(start_time) < 12 THEN 'Morning (before 12)'
            WHEN HOUR(start_time) < 17 THEN 'Afternoon (12-5)'
            ELSE 'Evening (after 5)'
        END as slot,
        COUNT(*) as count
     FROM {$table}
     WHERE status IN ('confirmed', 'deposit_paid', 'paid') AND scout_use = 0 AND booking_date BETWEEN %s AND %s
     GROUP BY slot ORDER BY count DESC",
    $fy_start, $fy_end
) );

// ── Revenue by pricing tier (this FY) ─────────────────────────────────────────
$by_tier = $wpdb->get_results( $wpdb->prepare(
    "SELECT COALESCE(NULLIF(pricing_tier, ''), 'standard') as tier, COUNT(*) as count, SUM(amount) as revenue
     FROM {$table} WHERE status IN ('confirmed', 'deposit_paid', 'paid') AND scout_use = 0 AND booking_date BETWEEN %s AND %s
     GROUP BY tier ORDER BY revenue DESC",
    $fy_start, $fy_end
) );
$tier_defs = MBS_Bookings::get_pricing_tiers();

// ── Top hirers by revenue (this FY) ───────────────────────────────────────────
$top_hirers = $wpdb->get_results( $wpdb->prepare(
    "SELECT
        COALESCE(NULLIF(organisation, ''), name) as hirer,
        LOWER(email) as email_key,
        COUNT(*) as bookings,
        SUM(amount) as revenue
     FROM {$table}
     WHERE status IN ('confirmed', 'deposit_paid', 'paid') AND scout_use = 0 AND booking_date BETWEEN %s AND %s
     GROUP BY email_key, hirer ORDER BY revenue DESC LIMIT 10",
    $fy_start, $fy_end
) );

// ── Repeat vs one-time hirers (all-time, commercial) ──────────────────────────
$hirer_freq = $wpdb->get_results(
    "SELECT LOWER(email) as email_key, COUNT(*) as c FROM {$table}
     WHERE status IN ('confirmed', 'deposit_paid', 'paid') AND scout_use = 0
     GROUP BY email_key"
);
$repeat_hirers   = 0;
$onetime_hirers  = 0;
foreach ( $hirer_freq as $h ) {
    if ( (int) $h->c > 1 ) { $repeat_hirers++; } else { $onetime_hirers++; }
}
$total_hirers = $repeat_hirers + $onetime_hirers;
$repeat_rate  = $total_hirers > 0 ? round( ( $repeat_hirers / $total_hirers ) * 100, 1 ) : 0;

// ── Conversion & cancellations (this FY) ──────────────────────────────────────
$cancelled_fy = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE status = 'cancelled' AND scout_use = 0 AND booking_date BETWEEN %s AND %s",
    $fy_start, $fy_end
) );
$pending_fy = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND scout_use = 0 AND booking_date BETWEEN %s AND %s",
    $fy_start, $fy_end
) );
// Cancellation rate = cancelled / (confirmed-ish + cancelled) in the FY
$decided_fy = $total_fy + $cancelled_fy;
$cancel_rate = $decided_fy > 0 ? round( ( $cancelled_fy / $decided_fy ) * 100, 1 ) : 0;
$revenue_lost = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE status = 'cancelled' AND scout_use = 0 AND booking_date BETWEEN %s AND %s",
    $fy_start, $fy_end
) );

// ── Public vs private events (this FY) ────────────────────────────────────────
$public_count  = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE is_public = 1 AND status IN ('confirmed', 'deposit_paid', 'paid') AND scout_use = 0 AND booking_date BETWEEN %s AND %s",
    $fy_start, $fy_end
) );
$private_count = max( 0, $total_fy - $public_count );

// YoY revenue change
$yoy_pct = $revenue_prev > 0 ? round( ( ( $revenue_fy - $revenue_prev ) / $revenue_prev ) * 100, 1 ) : null;

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

// Time-of-day chart data
$slot_labels = array();
$slot_counts = array();
foreach ( $by_timeslot as $t ) {
    $slot_labels[] = $t->slot;
    $slot_counts[] = (int) $t->count;
}

// Revenue-by-tier chart data
$tier_labels  = array();
$tier_revenue = array();
foreach ( $by_tier as $t ) {
    $tier_labels[]  = $tier_defs[ $t->tier ]['label'] ?? ucfirst( $t->tier );
    $tier_revenue[] = (float) $t->revenue;
}

// Billed vs collected vs outstanding (for the financial bar)
$fin_labels = array( 'Billed', 'Collected', 'Outstanding' );
$fin_values = array( round( $billed_fy, 2 ), round( $collected_fy, 2 ), round( max( 0, $billed_fy - $collected_fy ), 2 ) );
?>
<div class="wrap mbs-admin">
    <h1><?php echo MBS_Admin::brand_mark(); ?>MGF Venue – Analytics</h1>
    <p class="nms-muted">Financial Year: <?php echo esc_html( $fy_label ); ?></p>

    <!-- Summary cards -->
    <div class="nms-stats-row" style="margin-bottom:2rem;">
        <div class="nms-stat-card">
            <div class="nms-stat-val"><?php echo esc_html( $total_fy ); ?></div>
            <div class="nms-stat-label">Bookings This FY</div>
        </div>
        <div class="nms-stat-card nms-stat-revenue">
            <div class="nms-stat-val">&pound;<?php echo number_format( $revenue_fy, 0 ); ?></div>
            <div class="nms-stat-label">Revenue This FY
                <?php if ( $yoy_pct !== null ) : ?>
                    <span style="color:<?php echo $yoy_pct >= 0 ? '#2ecc71' : '#e74c3c'; ?>;font-weight:700;">
                        <?php echo $yoy_pct >= 0 ? '▲' : '▼'; ?> <?php echo esc_html( abs( $yoy_pct ) ); ?>%
                    </span>
                <?php endif; ?>
            </div>
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

    <!-- Financial summary cards -->
    <h2 style="margin:0 0 0.75rem;font-size:1.1rem;color:#1a1a2e;">💷 Financial Overview</h2>
    <div class="nms-stats-row" style="margin-bottom:2rem;">
        <div class="nms-stat-card nms-stat-revenue">
            <div class="nms-stat-val">&pound;<?php echo number_format( $collected_fy, 0 ); ?></div>
            <div class="nms-stat-label">Collected This FY (<?php echo esc_html( $collection_rate ); ?>%)</div>
        </div>
        <div class="nms-stat-card nms-stat-pending">
            <div class="nms-stat-val">&pound;<?php echo number_format( $outstanding_total, 0 ); ?></div>
            <div class="nms-stat-label">Outstanding (<?php echo $outstanding_count; ?> booking<?php echo $outstanding_count === 1 ? '' : 's'; ?><?php if ( $overdue_count > 0 ) : ?>, <?php echo $overdue_count; ?> overdue<?php endif; ?>)</div>
        </div>
        <div class="nms-stat-card">
            <div class="nms-stat-val">&pound;<?php echo number_format( $deposits_held, 0 ); ?></div>
            <div class="nms-stat-label">Deposits Held</div>
        </div>
        <div class="nms-stat-card">
            <div class="nms-stat-val">&pound;<?php echo number_format( $kitchen_income, 0 ); ?></div>
            <div class="nms-stat-label">Kitchen Income est. (<?php echo $kitchen_count; ?>)</div>
        </div>
        <div class="nms-stat-card">
            <div class="nms-stat-val"><?php echo esc_html( round( $avg_lead_time ) ); ?></div>
            <div class="nms-stat-label">Avg Lead Time (days)</div>
        </div>
    </div>

    <!-- Demand / pipeline cards -->
    <div class="nms-stats-row" style="margin-bottom:2rem;">
        <div class="nms-stat-card">
            <div class="nms-stat-val"><?php echo $pipeline_30; ?></div>
            <div class="nms-stat-label">Upcoming (next 30 days)</div>
        </div>
        <div class="nms-stat-card">
            <div class="nms-stat-val"><?php echo $pipeline_90; ?></div>
            <div class="nms-stat-label">Upcoming (next 90 days)</div>
        </div>
        <div class="nms-stat-card">
            <div class="nms-stat-val"><?php echo esc_html( round( $avg_attendees ) ); ?></div>
            <div class="nms-stat-label">Avg Attendees</div>
        </div>
        <div class="nms-stat-card">
            <div class="nms-stat-val"><?php echo esc_html( $repeat_rate ); ?>%</div>
            <div class="nms-stat-label">Repeat Hirers (<?php echo $repeat_hirers; ?>/<?php echo $total_hirers; ?>)</div>
        </div>
        <div class="nms-stat-card <?php echo $cancel_rate > 0 ? 'nms-stat-pending' : ''; ?>">
            <div class="nms-stat-val"><?php echo esc_html( $cancel_rate ); ?>%</div>
            <div class="nms-stat-label">Cancellation Rate</div>
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

        <!-- Billed vs Collected vs Outstanding -->
        <div class="nms-card">
            <div class="nms-card-header"><h2>💷 Billed vs Collected (FY)</h2></div>
            <div style="padding:1.5rem;">
                <canvas id="mbs-chart-financial" height="250"></canvas>
            </div>
        </div>

        <!-- Revenue by tier -->
        <div class="nms-card">
            <div class="nms-card-header"><h2>🏷️ Revenue By Pricing Tier</h2></div>
            <div style="padding:1.5rem;">
                <canvas id="mbs-chart-tier" height="250"></canvas>
            </div>
        </div>

        <!-- Time of day -->
        <div class="nms-card">
            <div class="nms-card-header"><h2>🕐 Bookings By Time of Day</h2></div>
            <div style="padding:1.5rem;">
                <canvas id="mbs-chart-timeslot" height="250"></canvas>
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

    <!-- Top hirers + conversion -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-top:1.5rem;">

        <!-- Top hirers -->
        <div class="nms-card">
            <div class="nms-card-header"><h2>🏆 Top Hirers (FY <?php echo esc_html( $fy_label ); ?>)</h2></div>
            <div style="padding:1rem 1.5rem;">
                <table class="wp-list-table widefat fixed striped" style="font-size:0.85rem;">
                    <thead><tr><th>Hirer</th><th>Bookings</th><th>Revenue</th></tr></thead>
                    <tbody>
                    <?php foreach ( $top_hirers as $h ) : ?>
                        <tr>
                            <td><?php echo esc_html( $h->hirer ); ?></td>
                            <td><?php echo esc_html( $h->bookings ); ?></td>
                            <td>&pound;<?php echo number_format( $h->revenue, 2 ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ( empty( $top_hirers ) ) : ?>
                        <tr><td colspan="3" class="nms-muted">No data yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Conversion & cancellations -->
        <div class="nms-card">
            <div class="nms-card-header"><h2>🔄 Conversion &amp; Cancellations (FY <?php echo esc_html( $fy_label ); ?>)</h2></div>
            <div style="padding:1rem 1.5rem;">
                <table class="wp-list-table widefat fixed striped" style="font-size:0.85rem;">
                    <tbody>
                        <tr><td>Pending (awaiting decision)</td><td><strong><?php echo $pending_fy; ?></strong></td></tr>
                        <tr><td>Confirmed / paid</td><td><strong><?php echo $total_fy; ?></strong></td></tr>
                        <tr><td>Cancelled</td><td><strong><?php echo $cancelled_fy; ?></strong></td></tr>
                        <tr><td>Cancellation rate</td><td><strong><?php echo esc_html( $cancel_rate ); ?>%</strong></td></tr>
                        <tr><td>Revenue lost to cancellations</td><td><strong style="color:#e74c3c;">&pound;<?php echo number_format( $revenue_lost, 2 ); ?></strong></td></tr>
                        <tr><td>Public vs private events</td><td><strong><?php echo $public_count; ?></strong> public / <strong><?php echo $private_count; ?></strong> private</td></tr>
                    </tbody>
                </table>
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
        // NOTE: occupancy/utilisation intentionally INCLUDES Scout bookings —
        // they physically occupy the hall even though they're £0 and excluded
        // from the commercial revenue/count metrics above. Do not add scout_use = 0 here.
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

    // Billed vs Collected vs Outstanding
    new Chart(document.getElementById('mbs-chart-financial'), {
        type: 'bar',
        data: {
            labels: <?php echo wp_json_encode( $fin_labels ); ?>,
            datasets: [{ label: '£', data: <?php echo wp_json_encode( $fin_values ); ?>, backgroundColor: [purple, green, '#e67e22'], borderRadius: 4 }]
        },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });

    // Revenue by tier
    new Chart(document.getElementById('mbs-chart-tier'), {
        type: 'doughnut',
        data: {
            labels: <?php echo wp_json_encode( $tier_labels ); ?>,
            datasets: [{ data: <?php echo wp_json_encode( $tier_revenue ); ?>, backgroundColor: [purple, gold, green, blue, '#e74c3c', '#9b59b6'] }]
        },
        options: { responsive: true }
    });

    // Time of day
    new Chart(document.getElementById('mbs-chart-timeslot'), {
        type: 'bar',
        data: {
            labels: <?php echo wp_json_encode( $slot_labels ); ?>,
            datasets: [{ label: 'Bookings', data: <?php echo wp_json_encode( $slot_counts ); ?>, backgroundColor: blue, borderRadius: 4 }]
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
