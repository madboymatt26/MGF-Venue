<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Email Reminders — sends reminder emails to bookers before their booking.
 *
 * Runs via WP-Cron daily. Configurable reminder period in settings.
 */
class MBS_Reminders {

    /**
     * Register the cron hook and schedule.
     */
    public function init() {
        add_action( 'mbs_daily_reminders', array( $this, 'send_reminders' ) );

        if ( ! wp_next_scheduled( 'mbs_daily_reminders' ) ) {
            // Schedule to run daily at 7am local time
            $timestamp = strtotime( 'tomorrow 07:00:00' );
            wp_schedule_event( $timestamp, 'daily', 'mbs_daily_reminders' );
        }
    }

    /**
     * Unschedule on plugin deactivation.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( 'mbs_daily_reminders' );
    }

    /**
     * Send reminder emails for upcoming bookings.
     */
    public function send_reminders() {
        $hours_before = (int) get_option( 'mbs_reminder_hours', 24 );
        if ( $hours_before <= 0 ) return; // Reminders disabled

        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;

        // Find confirmed/paid bookings happening within the reminder window
        // that haven't already been sent a reminder
        $reminder_date = wp_date( 'Y-m-d', strtotime( "+{$hours_before} hours" ) );
        $today         = wp_date( 'Y-m-d' );

        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE booking_date BETWEEN %s AND %s
             AND status IN ('confirmed', 'paid')
             AND reminder_sent = 0
             ORDER BY booking_date ASC, start_time ASC",
            $today, $reminder_date
        ) );

        if ( empty( $bookings ) ) return;

        foreach ( $bookings as $booking ) {
            MBS_Email::notify_reminder( $booking );

            // Mark as sent
            $wpdb->update(
                $table,
                array( 'reminder_sent' => 1 ),
                array( 'id' => $booking->id ),
                array( '%d' ), array( '%d' )
            );
        }

        error_log( '[MGF Venue] Sent ' . count( $bookings ) . ' reminder email(s).' );
    }
}
