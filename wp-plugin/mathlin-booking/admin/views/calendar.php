<?php if ( ! defined( 'ABSPATH' ) ) exit;

$year  = isset( $_GET['cal_year'] )  ? absint( $_GET['cal_year'] )  : (int) wp_date( 'Y' );
$month = isset( $_GET['cal_month'] ) ? absint( $_GET['cal_month'] ) : (int) wp_date( 'n' );
$year = max( 1900, min( 2200, $year ) );

// Clamp
if ( $month < 1 ) { $month = 12; $year--; }
if ( $month > 12 ) { $month = 1; $year++; }

$prev_month = $month - 1;
$prev_year  = $year;
if ( $prev_month < 1 ) { $prev_month = 12; $prev_year--; }

$next_month = $month + 1;
$next_year  = $year;
if ( $next_month > 12 ) { $next_month = 1; $next_year++; }

$months_list = array( '', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' );

// Get all bookings for this month (non-archived, non-cancelled)
$first_day = sprintf( '%04d-%02d-01', $year, $month );
$last_day  = date( 'Y-m-t', strtotime( $first_day ) );

global $wpdb;
$table    = $wpdb->prefix . MBS_TABLE;
$bookings = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM {$table} WHERE booking_date <= %s AND COALESCE(NULLIF(booking_date_end, ''), booking_date) >= %s AND status NOT IN ('cancelled', 'archived') ORDER BY start_time ASC, ref ASC",
    $last_day, $first_day
) );

// Group bookings by date
$by_date = array();
foreach ( $bookings as $b ) {
    // Inclusive occupied dates, clipped to this month (including stays starting earlier).
    $start = max( $first_day, $b->booking_date );
    $end = min( $last_day, ! empty( $b->booking_date_end ) ? $b->booking_date_end : $b->booking_date );
    for ( $date = $start; $date <= $end; $date = gmdate( 'Y-m-d', strtotime( $date . ' +1 day UTC' ) ) ) {
        $by_date[ $date ][] = $b;
    }
}

// Get blocked dates for this month
$blocked_dates = MBS_Blocked_Dates::get_for_month( $year, $month );

// Calendar grid info
$first_dow = (int) date( 'N', strtotime( $first_day ) ); // 1=Mon, 7=Sun
$days_in_month = (int) date( 't', strtotime( $first_day ) );
$today = wp_date( 'Y-m-d' );

// Selected day
$selected_date = isset( $_GET['cal_date'] ) ? sanitize_text_field( $_GET['cal_date'] ) : '';
if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $selected_date ) || $selected_date < $first_day || $selected_date > $last_day ) {
    $selected_date = $today >= $first_day && $today <= $last_day ? $today : $first_day;
}
$selected_bookings = $selected_date && isset( $by_date[ $selected_date ] ) ? $by_date[ $selected_date ] : array();
$selected_blocks   = $selected_date && isset( $blocked_dates[ $selected_date ] ) ? $blocked_dates[ $selected_date ] : array();
?>
<div class="wrap mbs-admin mbs-calendar-page">
    <h1 class="wp-heading-inline"><?php echo MBS_Admin::brand_mark(); ?>MGF Venue – Calendar</h1>
    <hr class="wp-header-end">
    <p class="nms-muted">Select any date to view its bookings. Multi-day stays appear on every occupied day.</p>
    <div class="nms-cal-toolbar"><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'mathlin-calendar', 'cal_year' => wp_date( 'Y' ), 'cal_month' => wp_date( 'n' ), 'cal_date' => $today ), admin_url( 'admin.php' ) ) ); ?>">Today</a>
        <span><?php echo count( $bookings ); ?> booking<?php echo count( $bookings ) === 1 ? '' : 's'; ?> overlapping this month</span>
        <span>Day counts include pending and approved bookings.</span>
    </div>

    <div class="nms-calendar-layout">
        <div class="nms-calendar-main">
            <!-- Month navigation -->
            <div class="nms-cal-nav">
                <a href="?page=mathlin-calendar&cal_year=<?php echo $prev_year; ?>&cal_month=<?php echo $prev_month; ?>" class="button">&laquo; Previous</a>
                <h2 class="nms-cal-title"><?php echo esc_html( $months_list[ $month ] . ' ' . $year ); ?></h2>
                <a href="?page=mathlin-calendar&cal_year=<?php echo $next_year; ?>&cal_month=<?php echo $next_month; ?>" class="button">Next &raquo;</a>
            </div>

            <!-- Calendar grid -->
            <table class="nms-cal-grid">
                <caption class="screen-reader-text"><?php echo esc_html( $months_list[$month] . ' ' . $year . ' bookings. Each date links to its booking details.' ); ?></caption>
                <thead>
                    <tr>
                        <th scope="col">Mon</th><th scope="col">Tue</th><th scope="col">Wed</th><th scope="col">Thu</th><th scope="col">Fri</th><th scope="col">Sat</th><th scope="col">Sun</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $cell = 1;
                    $day  = 1;
                    echo '<tr>';
                    // Empty cells before first day
                    for ( $i = 1; $i < $first_dow; $i++ ) {
                        echo '<td class="nms-cal-empty"></td>';
                        $cell++;
                    }
                    // Day cells
                    while ( $day <= $days_in_month ) {
                        $date_str = sprintf( '%04d-%02d-%02d', $year, $month, $day );
                        $has_bookings = isset( $by_date[ $date_str ] );
                        $is_blocked = isset( $blocked_dates[ $date_str ] );
                        $count = $has_bookings ? count( $by_date[ $date_str ] ) : 0;
                        $is_today = ( $date_str === $today );
                        $is_selected = ( $date_str === $selected_date );

                        $classes = 'nms-cal-day';
                        if ( $is_today ) $classes .= ' nms-cal-today';
                        if ( $has_bookings ) $classes .= ' nms-cal-has-bookings';
                        if ( $is_blocked ) $classes .= ' nms-cal-blocked';
                        if ( $is_selected ) $classes .= ' nms-cal-selected';

                        $url = add_query_arg( array( 'page' => 'mathlin-calendar', 'cal_year' => $year, 'cal_month' => $month, 'cal_date' => $date_str ) );

                        echo '<td class="' . esc_attr( $classes ) . '">';
                        $day_label = $date_str . ': ' . $count . ' booking' . ( $count === 1 ? '' : 's' ) . ( $is_blocked ? ', blocked spaces' : '' );
                        echo '<a href="' . esc_url( $url ) . '" class="nms-cal-day-link" aria-label="' . esc_attr( $day_label ) . '"' . ( $is_selected ? ' aria-current="date"' : '' ) . '>';
                        echo '<span class="nms-cal-day-num">' . $day . '</span>';
                        if ( $count > 0 ) {
                            echo '<span class="nms-cal-badge">' . $count . '</span>';
                        }
                        if ( $is_blocked ) {
                            echo '<span class="nms-cal-blocked-badge">Blocked spaces</span>';
                        }
                        if ( $has_bookings ) {
                            echo '<span class="nms-cal-previews" aria-hidden="true">';
                            foreach ( array_slice( $by_date[ $date_str ], 0, 2 ) as $preview ) {
                                echo '<span class="nms-cal-preview">' . esc_html( $preview->space ) . '</span>';
                            }
                            if ( $count > 2 ) echo '<span class="nms-cal-preview nms-muted">+' . ( $count - 2 ) . ' more</span>';
                            echo '</span>';
                        }
                        echo '</a>';
                        echo '</td>';

                        if ( $cell % 7 === 0 && $day < $days_in_month ) echo '</tr><tr>';
                        $cell++;
                        $day++;
                    }
                    // Empty cells after last day
                    while ( $cell % 7 !== 1 ) {
                        echo '<td class="nms-cal-empty"></td>';
                        $cell++;
                    }
                    echo '</tr>';
                    ?>
                </tbody>
            </table>
        </div>

        <!-- Sidebar: selected day bookings -->
        <div class="nms-calendar-sidebar">
            <?php if ( $selected_date ) : ?>
                <h3><?php echo esc_html( date( 'l j F Y', strtotime( $selected_date ) ) ); ?></h3>
                <?php if ( ! empty( $selected_blocks ) ) : ?>
                    <div class="nms-cal-blocked-notice">
                        <strong>🚫 Blocked</strong>
                        <?php foreach ( $selected_blocks as $block ) : ?>
                            <div><?php echo esc_html( $block['space'] ); ?><?php if ( $block['reason'] ) echo ' — ' . esc_html( $block['reason'] ); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ( empty( $selected_bookings ) ) : ?>
                    <p class="nms-muted">No bookings on this day.</p>
                <?php else : ?>
                    <?php foreach ( $selected_bookings as $b ) :
                        $is_daily = ! empty( $b->all_day );
                        $interval = class_exists( 'MBS_Campsite_Policy' ) && MBS_Campsite_Policy::enabled() ? MBS_Campsite_Policy::interval( $b, $selected_date ) : array( $b->start_time, $b->end_time );
                        $time_str = $is_daily ? 'All day' : substr( $interval[0], 0, 5 ) . ' – ' . substr( $interval[1], 0, 5 );
                    ?>
                    <div class="nms-cal-booking-card">
                        <div class="nms-cal-booking-header">
                            <strong><?php echo esc_html( $b->name ); ?></strong>
                            <span class="nms-status nms-status-<?php echo esc_attr( $b->status ); ?>"><?php echo esc_html( ucfirst( $b->status ) ); ?></span>
                        </div>
                        <?php if ( $b->organisation ) : ?>
                            <div class="nms-muted"><?php echo esc_html( $b->organisation ); ?></div>
                        <?php endif; ?>
                        <div class="nms-muted"><?php echo esc_html( $b->ref ); ?><?php if ( ! empty( $b->booking_date_end ) && $b->booking_date_end !== $b->booking_date ) echo ' · ' . esc_html( $b->booking_date . ' – ' . $b->booking_date_end ); ?></div>
                        <div class="nms-cal-booking-details">
                            <span>📍 <?php echo esc_html( $b->space ); ?></span>
                            <span>🕐 <?php echo esc_html( $time_str ); ?></span>
                            <span>👥 <?php echo esc_html( $b->attendees ); ?> people</span>
                            <?php if ( MBS_Payment_Features::enabled() ) : ?><span>💷 &pound;<?php echo number_format( $b->amount, 2 ); ?></span><?php endif; ?>
                        </div>
                        <div class="nms-cal-booking-purpose"><?php echo esc_html( $b->purpose ); ?></div>
                        <a href="?page=mathlin-booking&action=view&ref=<?php echo esc_attr( $b->ref ); ?>" class="button button-small">View Full Booking</a>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php else : ?>
                <h3>Select a Day</h3>
                <p class="nms-muted">Click on a day in the calendar to see its bookings.</p>
                <?php if ( ! empty( $bookings ) ) : ?>
                    <p style="margin-top:1rem;"><strong><?php echo count( $bookings ); ?></strong> booking<?php echo count( $bookings ) !== 1 ? 's' : ''; ?> this month.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
