<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Auto-Archive — automatically archives past bookings via WP-Cron.
 *
 * Runs daily. Archives confirmed/cancelled/paid bookings where the
 * booking date has passed by more than the configured number of days.
 */
class MBS_Auto_Archive {

    public function init() {
        add_action( 'mbs_daily_auto_archive', array( $this, 'run_archive' ) );

        if ( ! wp_next_scheduled( 'mbs_daily_auto_archive' ) ) {
            wp_schedule_event( strtotime( 'tomorrow 02:00:00' ), 'daily', 'mbs_daily_auto_archive' );
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'mbs_daily_auto_archive' );
    }

    /**
     * Archive bookings that are older than the configured threshold.
     */
    public function run_archive() {
        $days_after = (int) get_option( 'mbs_auto_archive_days', 7 );
        if ( $days_after <= 0 ) return; // Disabled

        global $wpdb;
        $table     = $wpdb->prefix . MBS_TABLE;
        $today     = wp_date( 'Y-m-d' );
        $threshold = wp_date( 'Y-m-d', strtotime( "-{$days_after} days" ) );

        // Safety: only archive bookings where the event date is BOTH:
        // 1. In the past (before today)
        // 2. Older than the configured threshold
        // Note: Use NULLIF to handle empty string booking_date_end values
        $count = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET status = 'archived'
             WHERE COALESCE(NULLIF(booking_date_end, ''), booking_date) < %s
             AND COALESCE(NULLIF(booking_date_end, ''), booking_date) < %s
             AND status IN ('confirmed', 'deposit_paid', 'cancelled', 'paid')",
            $today,
            $threshold
        ) );

        if ( $count > 0 ) {
            error_log( "[MGF Venue] Auto-archived {$count} past booking(s) (threshold: {$threshold})." );
        }
    }
}
