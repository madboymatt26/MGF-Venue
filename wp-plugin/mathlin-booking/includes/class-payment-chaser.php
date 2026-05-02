<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Payment Chaser — sends overdue payment reminders automatically and manually.
 *
 * Auto-chase: WP-Cron runs daily, sends a reminder for confirmed bookings
 * where payment is overdue (past the payment terms days).
 *
 * Manual chase: admin can click "Chase Payment" on any confirmed booking.
 *
 * Tracks how many chase emails have been sent per booking to avoid spam.
 */
class MBS_Payment_Chaser {

    public function init() {
        add_action( 'mbs_daily_payment_chase', array( $this, 'auto_chase' ) );

        if ( ! wp_next_scheduled( 'mbs_daily_payment_chase' ) ) {
            wp_schedule_event( strtotime( 'tomorrow 09:00:00' ), 'daily', 'mbs_daily_payment_chase' );
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'mbs_daily_payment_chase' );
    }

    /**
     * Auto-chase: find overdue confirmed bookings and send reminders.
     * Only sends if:
     *   - Status is 'confirmed' (not paid, not cancelled)
     *   - Created more than payment_terms_days ago
     *   - Max 3 chase emails per booking
     *   - At least 3 days between chase emails
     */
    public function auto_chase() {
        $enabled = get_option( 'mbs_auto_chase_enabled', 1 );
        if ( ! $enabled ) return;

        $bank          = MBS_Bookings::get_bank_details();
        $payment_days  = $bank['payment_days'];
        $max_chases    = (int) get_option( 'mbs_max_chase_emails', 3 );
        $chase_interval = 3; // days between chases

        global $wpdb;
        $table    = $wpdb->prefix . MBS_TABLE;
        $overdue  = date( 'Y-m-d H:i:s', strtotime( "-{$payment_days} days" ) );

        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status = 'confirmed'
             AND created_at <= %s
             AND (chase_count < %d OR chase_count IS NULL)
             AND (last_chased IS NULL OR last_chased <= %s)
             ORDER BY created_at ASC
             LIMIT 20",
            $overdue,
            $max_chases,
            date( 'Y-m-d H:i:s', strtotime( "-{$chase_interval} days" ) )
        ) );

        if ( empty( $bookings ) ) return;

        $count = 0;
        foreach ( $bookings as $booking ) {
            self::send_chase( $booking );
            $count++;
        }

        if ( $count > 0 ) {
            error_log( "[Mathlin Booking] Auto-chased {$count} overdue payment(s)." );
        }
    }

    /**
     * Send a payment chase email for a specific booking.
     *
     * @param object $booking  Booking database row
     * @param bool   $manual   Whether this is a manual chase (affects wording)
     */
    public static function send_chase( $booking, $manual = false ) {
        $chase_count = (int) ( $booking->chase_count ?? 0 );
        $bank        = MBS_Bookings::get_bank_details();
        $admin_email = MBS_Bookings::get_admin_email();

        // Determine urgency based on chase count
        if ( $chase_count === 0 ) {
            $subject = 'Payment Reminder – ' . $booking->invoice_number;
            $heading = 'Friendly Payment Reminder';
            $tone    = 'Just a gentle reminder that payment for your booking is now due.';
            $colour  = '#f39c12'; // amber
        } elseif ( $chase_count === 1 ) {
            $subject = 'Payment Overdue – ' . $booking->invoice_number;
            $heading = 'Payment Overdue';
            $tone    = 'Our records show that payment for your booking is now overdue. Please arrange payment at your earliest convenience.';
            $colour  = '#e67e22'; // orange
        } else {
            $subject = 'URGENT: Payment Required – ' . $booking->invoice_number;
            $heading = 'Urgent Payment Required';
            $tone    = 'This is a final reminder. Payment for your booking is significantly overdue. Please arrange payment immediately to avoid your booking being cancelled.';
            $colour  = '#e74c3c'; // red
        }

        $body  = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:0 auto;">';
        $body .= '<div style="background:' . $colour . ';padding:24px 32px;border-radius:8px 8px 0 0;">';
        $body .= '<h1 style="color:#fff;margin:0;font-size:20px;">&#9884; Needham Market Scout Group</h1>';
        $body .= '<p style="color:rgba(255,255,255,0.9);margin:4px 0 0;">Payment Reminder</p>';
        $body .= '</div>';
        $body .= '<div style="background:#fff;padding:32px;border:1px solid #e0d0f0;border-top:none;border-radius:0 0 8px 8px;">';
        $body .= '<h2 style="color:' . $colour . ';">' . $heading . '</h2>';
        $body .= '<p>Hi ' . esc_html( $booking->name ) . ',</p>';
        $body .= '<p>' . $tone . '</p>';

        $body .= '<table style="width:100%;border-collapse:collapse;margin:16px 0;">';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;width:35%;border-bottom:1px solid #e0d0f0;">Invoice</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->invoice_number ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Booking</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->space ) . ' on ' . esc_html( date( 'j F Y', strtotime( $booking->booking_date ) ) ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Amount Due</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;font-size:18px;font-weight:bold;color:' . $colour . ';">&pound;' . number_format( $booking->amount, 2 ) . '</td></tr>';
        $body .= '</table>';

        $body .= '<p><strong>Payment details:</strong><br>';
        $body .= 'Sort Code: <strong>' . esc_html( $bank['sort_code'] ) . '</strong><br>';
        $body .= 'Account: <strong>' . esc_html( $bank['account_number'] ) . '</strong><br>';
        $body .= 'Reference: <strong>' . esc_html( $booking->invoice_number ) . '</strong></p>';

        // Pay Now button if WooCommerce available
        if ( MBS_Woo_Payment::is_available() ) {
            $pay_url = MBS_Woo_Payment::generate_payment_url( $booking );
            if ( $pay_url ) {
                $body .= '<p style="text-align:center;margin:24px 0;">';
                $body .= '<a href="' . esc_url( $pay_url ) . '" style="background:#2ecc71;color:#fff;padding:14px 32px;border-radius:6px;text-decoration:none;font-weight:bold;font-size:16px;">💳 Pay Now Online</a>';
                $body .= '</p>';
            }
        }

        $body .= '<p>If you have already made payment, please disregard this email. If you have any questions, contact us at <a href="mailto:' . esc_attr( $admin_email ) . '">' . esc_html( $admin_email ) . '</a> or call 01449 797577.</p>';
        $body .= '</div>';
        $body .= '<div style="text-align:center;padding:16px;color:#999;font-size:12px;">Needham Market Scout Group &bull; Crown St, Needham Market, IP6 8RY &bull; Charity No. 1038177</div>';
        $body .= '</body></html>';

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: Needham Market Scouts <' . $admin_email . '>',
        );
        wp_mail( $booking->email, $subject, $body, $headers );

        // Update chase tracking
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        $wpdb->update(
            $table,
            array(
                'chase_count' => $chase_count + 1,
                'last_chased' => current_time( 'mysql' ),
            ),
            array( 'ref' => $booking->ref )
        );

        // Audit log
        $type = $manual ? 'Manual payment chase' : 'Auto payment chase';
        MBS_Audit_Log::log( $booking->ref, 'payment_chase', $type . ' (chase #' . ( $chase_count + 1 ) . ')' );
    }
}
