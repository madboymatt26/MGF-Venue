<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Access Details — sends keysafe/access code to paid bookers before their event.
 *
 * Runs via WP-Cron daily. Sends access details to bookers and a heads-up
 * notification to admin emails. Can also be triggered manually from admin.
 */
class MBS_Access_Details {

    public function init() {
        add_action( 'mbs_daily_access_details', array( $this, 'send_access_details' ) );

        if ( ! wp_next_scheduled( 'mbs_daily_access_details' ) ) {
            wp_schedule_event( strtotime( 'tomorrow 08:00:00' ), 'daily', 'mbs_daily_access_details' );
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'mbs_daily_access_details' );
    }

    /**
     * Get access settings.
     */
    public static function get_settings() {
        return array(
            'enabled'      => (bool) get_option( 'mbs_access_enabled', false ),
            'code'         => get_option( 'mbs_access_code', '' ),
            'instructions' => get_option( 'mbs_access_instructions', '' ),
            'hours_before' => (int) get_option( 'mbs_access_hours_before', 24 ),
        );
    }

    /**
     * Daily cron: find paid bookings happening soon and send access details.
     */
    public function send_access_details() {
        $settings = self::get_settings();
        if ( ! $settings['enabled'] || empty( $settings['code'] ) ) return;

        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;

        $target_date = wp_date( 'Y-m-d', strtotime( '+' . $settings['hours_before'] . ' hours' ) );
        $today       = wp_date( 'Y-m-d' );

        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE booking_date BETWEEN %s AND %s
             AND status IN ('paid', 'deposit_paid')
             AND access_sent = 0
             ORDER BY booking_date ASC, start_time ASC",
            $today, $target_date
        ) );

        if ( empty( $bookings ) ) return;

        foreach ( $bookings as $booking ) {
            self::send_to_booker( $booking );
            self::send_admin_notification( $booking );

            $wpdb->update(
                $table,
                array( 'access_sent' => 1 ),
                array( 'id' => $booking->id )
            );

            MBS_Audit_Log::log( $booking->ref, 'access_sent', 'Access details sent to booker and admin notification sent.' );
        }

        error_log( '[Mathlin Booking] Sent access details for ' . count( $bookings ) . ' booking(s).' );
    }

    /**
     * Send access details email to the booker.
     */
    public static function send_to_booker( $booking ) {
        $settings = self::get_settings();
        $tpl      = MBS_Email_Templates::get_template( 'access_details' );
        $subject  = MBS_Email_Templates::replace_placeholders( $tpl['subject'], $booking );
        $body_text = MBS_Email_Templates::replace_placeholders( $tpl['body'], $booking );

        $org         = MBS_Email_Templates::get_org_settings();
        $admin_email = MBS_Bookings::get_admin_email();

        $body  = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:0 auto;">';
        $body .= '<div style="background:#2ecc71;padding:24px 32px;border-radius:8px 8px 0 0;text-align:center;">';
        $body .= MBS_Email_Templates::get_logo_html();
        $body .= '<h1 style="color:#fff;margin:8px 0 0;font-size:20px;">' . esc_html( $org['name'] ) . '</h1>';
        $body .= '<p style="color:rgba(255,255,255,0.9);margin:4px 0 0;">Access Details</p>';
        $body .= '</div>';
        $body .= '<div style="background:#fff;padding:32px;border:1px solid #e0d0f0;border-top:none;border-radius:0 0 8px 8px;">';
        $body .= '<h2 style="color:#2ecc71;">Your Access Details</h2>';
        $body .= nl2br( esc_html( $body_text ) );

        // Access code box
        $body .= '<div style="background:#d1fae5;border:2px solid #2ecc71;border-radius:8px;padding:20px;margin:20px 0;text-align:center;">';
        $body .= '<p style="margin:0 0 8px;font-size:13px;color:#065f46;font-weight:600;">🔑 KEYSAFE CODE</p>';
        $body .= '<p style="margin:0;font-size:32px;font-weight:bold;letter-spacing:4px;color:#065f46;">' . esc_html( $settings['code'] ) . '</p>';
        $body .= '</div>';

        // Access instructions
        if ( ! empty( $settings['instructions'] ) ) {
            $body .= '<div style="background:#f5f0ff;border:1px solid #e0d0f0;border-radius:6px;padding:16px;margin:16px 0;">';
            $body .= '<strong>Access Instructions:</strong><br>';
            $body .= wp_kses_post( nl2br( $settings['instructions'] ) );
            $body .= '</div>';
        }

        // Booking summary
        $body .= '<table style="width:100%;border-collapse:collapse;margin:16px 0;">';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;width:35%;border-bottom:1px solid #e0d0f0;">Reference</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->ref ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Space</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->space ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Date</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( wp_date( 'l j F Y', strtotime( $booking->booking_date ) ) ) . '</td></tr>';
        $time_str = $booking->all_day ? 'All day' : ( $booking->start_time . ' – ' . $booking->end_time );
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Time</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $time_str ) . '</td></tr>';
        $body .= '</table>';

        $body .= '<p style="font-size:13px;color:#6b7280;margin-top:16px;"><em>Please do not share this code with anyone outside your booking party.</em></p>';
        $body .= '</div></body></html>';

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $org['name'] . ' <' . get_option( 'admin_email', $admin_email ) . '>',
            'Reply-To: ' . $admin_email,
        );
        MBS_Email_Queue::send( $booking->email, $subject, $body, $headers );
    }

    /**
     * Send admin notification that a booking is happening soon.
     */
    public static function send_admin_notification( $booking ) {
        $org         = MBS_Email_Templates::get_org_settings();
        $admin_email = MBS_Bookings::get_admin_email();
        $emails      = array( $admin_email );

        // Include additional notification emails
        $additional = get_option( 'mbs_additional_emails', '' );
        if ( ! empty( $additional ) ) {
            $extras = array_map( 'trim', explode( ',', $additional ) );
            foreach ( $extras as $email ) {
                if ( is_email( $email ) && $email !== $admin_email ) {
                    $emails[] = $email;
                }
            }
        }

        $time_str = $booking->all_day ? 'All day' : ( $booking->start_time . ' – ' . $booking->end_time );
        $subject  = '[Upcoming] ' . $booking->ref . ' – ' . $booking->name . ' – ' . wp_date( 'D j M', strtotime( $booking->booking_date ) );

        $body  = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:0 auto;">';
        $body .= '<div style="background:#3498db;padding:24px 32px;border-radius:8px 8px 0 0;text-align:center;">';
        $body .= MBS_Email_Templates::get_logo_html();
        $body .= '<h1 style="color:#fff;margin:8px 0 0;font-size:20px;">' . esc_html( $org['name'] ) . '</h1>';
        $body .= '<p style="color:rgba(255,255,255,0.9);margin:4px 0 0;">Upcoming Booking Reminder</p>';
        $body .= '</div>';
        $body .= '<div style="background:#fff;padding:32px;border:1px solid #e0d0f0;border-top:none;border-radius:0 0 8px 8px;">';
        $body .= '<h2 style="color:#3498db;">Booking Tomorrow</h2>';
        $body .= '<p>A booking is happening soon. Access details have been sent to the hirer.</p>';

        $body .= '<table style="width:100%;border-collapse:collapse;margin:16px 0;">';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;width:35%;border-bottom:1px solid #e0d0f0;">Reference</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->ref ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Name</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->name ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Organisation</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->organisation ?: '—' ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Space</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->space ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Date</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( wp_date( 'l j F Y', strtotime( $booking->booking_date ) ) ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Time</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $time_str ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Attendees</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->attendees ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Purpose</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->purpose ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Phone</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->phone ) . '</td></tr>';
        $body .= '</table>';

        $body .= '<p style="margin-top:16px;"><a href="' . admin_url( 'admin.php?page=mathlin-booking&action=view&ref=' . $booking->ref ) . '" style="background:#7413DC;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;">View Booking</a></p>';
        $body .= '</div></body></html>';

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $org['name'] . ' <' . get_option( 'admin_email', $admin_email ) . '>',
        );

        foreach ( $emails as $to ) {
            MBS_Email_Queue::send( $to, $subject, $body, $headers );
        }
    }

    /**
     * Manually resend access details for a specific booking.
     */
    public static function resend( $booking ) {
        self::send_to_booker( $booking );
        MBS_Audit_Log::log( $booking->ref, 'access_resent', 'Access details manually resent to booker by admin.' );
    }
}
