<?php if ( ! defined( 'ABSPATH' ) ) exit;

$year  = isset( $_GET['cal_year'] )  ? absint( $_GET['cal_year'] )  : (int) date( 'Y' );
$month = isset( $_GET['cal_month'] ) ? absint( $_GET['cal_month'] ) : (int) date( 'n' );

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
    "SELECT * FROM {$table} WHERE booking_date BETWEEN %s AND %s AND status NOT IN ('cancelled', 'archived') ORDER BY start_time ASC",
    $first_day, $last_day
) );

// Group bookings by date
$by_date = array();
foreach ( $bookings as $b ) {
    $by_date[ $b->booking_date ][] = $b;
}

// Calendar grid info
$first_dow = (int) date( 'N', strtotime( $first_day ) ); // 1=Mon, 7=Sun
$days_in_month = (int) date( 't', strtotime( $first_day ) );
$today = date( 'Y-m-d' );

// Selected day
$selected_date = isset( $_GET['cal_date'] ) ? sanitize_text_field( $_GET['cal_date'] ) : '';
$selected_bookings = $selected_date && isset( $by_date[ $selected_date ] ) ? $by_date[ $selected_date ] : array();
?>
<div class="wrap mbs-admin">
    <h1 class="wp-heading-inline">&#9884; Scout Bookings – Calendar</h1>
    <hr class="wp-header-end">

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
                <thead>
                    <tr>
                        <th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th><th>Sun</th>
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
                        $count = $has_bookings ? count( $by_date[ $date_str ] ) : 0;
                        $is_today = ( $date_str === $today );
                        $is_selected = ( $date_str === $selected_date );

                        $classes = 'nms-cal-day';
                        if ( $is_today ) $classes .= ' nms-cal-today';
                        if ( $has_bookings ) $classes .= ' nms-cal-has-bookings';
                        if ( $is_selected ) $classes .= ' nms-cal-selected';

                        $url = add_query_arg( array( 'page' => 'mathlin-calendar', 'cal_year' => $year, 'cal_month' => $month, 'cal_date' => $date_str ) );

                        echo '<td class="' . esc_attr( $classes ) . '">';
                        echo '<a href="' . esc_url( $url ) . '" class="nms-cal-day-link">';
                        echo '<span class="nms-cal-day-num">' . $day . '</span>';
                        if ( $count > 0 ) {
                            echo '<span class="nms-cal-badge">' . $count . '</span>';
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
                <?php if ( empty( $selected_bookings ) ) : ?>
                    <p class="nms-muted">No bookings on this day.</p>
                <?php else : ?>
                    <?php foreach ( $selected_bookings as $b ) :
                        $spaces   = MBS_Bookings::get_spaces();
                        $is_daily = isset( $spaces[ $b->space ] ) && $spaces[ $b->space ]['unit'] === 'day';
                        $time_str = $is_daily ? 'All day' : $b->start_time . ' – ' . $b->end_time;
                    ?>
                    <div class="nms-cal-booking-card">
                        <div class="nms-cal-booking-header">
                            <strong><?php echo esc_html( $b->name ); ?></strong>
                            <span class="nms-status nms-status-<?php echo esc_attr( $b->status ); ?>"><?php echo esc_html( ucfirst( $b->status ) ); ?></span>
                        </div>
                        <?php if ( $b->organisation ) : ?>
                            <div class="nms-muted"><?php echo esc_html( $b->organisation ); ?></div>
                        <?php endif; ?>
                        <div class="nms-cal-booking-details">
                            <span>📍 <?php echo esc_html( $b->space ); ?></span>
                            <span>🕐 <?php echo esc_html( $time_str ); ?></span>
                            <span>👥 <?php echo esc_html( $b->attendees ); ?> people</span>
                            <span>💷 &pound;<?php echo number_format( $b->amount, 2 ); ?></span>
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
