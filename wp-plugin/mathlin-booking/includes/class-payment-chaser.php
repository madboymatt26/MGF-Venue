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
     * Also finds deposit_paid bookings approaching their balance due date.
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
        $chase_settings = MBS_Email_Templates::get_chase_settings();
        $max_chases    = $chase_settings['max_chases'];
        $chase_interval = $chase_settings['chase_interval'];

        global $wpdb;
        $table    = $wpdb->prefix . MBS_TABLE;
        $overdue  = wp_date( 'Y-m-d H:i:s', strtotime( "-{$payment_days} days" ) );

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
            wp_date( 'Y-m-d H:i:s', strtotime( "-{$chase_interval} days" ) )
        ) );

        if ( empty( $bookings ) ) $bookings = array();

        // Also chase deposit_paid bookings where balance is due soon
        $deposit_settings = MBS_Bookings::get_deposit_settings();
        if ( $deposit_settings['enabled'] ) {
            $balance_due_date = wp_date( 'Y-m-d', strtotime( '+' . $deposit_settings['balance_days'] . ' days' ) );
            $deposit_bookings = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE status = 'deposit_paid'
                 AND booking_date <= %s
                 AND (chase_count < 2 OR chase_count IS NULL)
                 AND (last_chased IS NULL OR last_chased <= %s)
                 ORDER BY booking_date ASC
                 LIMIT 10",
                $balance_due_date,
                wp_date( 'Y-m-d H:i:s', strtotime( "-{$chase_interval} days" ) )
            ) );
            if ( ! empty( $deposit_bookings ) ) {
                $bookings = array_merge( $bookings, $deposit_bookings );
            }
        }

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
        $org         = MBS_Email_Templates::get_org_settings();
        $admin_email = MBS_Bookings::get_admin_email();

        // Determine what's being chased: deposit, balance, or full payment
        $is_balance_chase  = ( $booking->status === 'deposit_paid' );
        $deposit_settings  = MBS_Bookings::get_deposit_settings();
        $is_deposit_chase  = ( $booking->status === 'confirmed' && $deposit_settings['enabled'] && ! MBS_Bookings::requires_full_payment( $booking->booking_date ) );
        $total_amount      = (float) $booking->amount;
        $deposit_paid_amt  = (float) ( $booking->deposit_paid ?? 0 );
        $deposit_amount    = MBS_Bookings::calculate_deposit( $total_amount );

        if ( $is_balance_chase ) {
            $amount_due = $total_amount - $deposit_paid_amt;
        } elseif ( $is_deposit_chase ) {
            $amount_due = $deposit_amount;
        } else {
            $amount_due = $total_amount;
        }

        // Determine which template and colour based on chase count
        if ( $chase_count === 0 ) {
            $tpl_key = 'chase_gentle';
            if ( $is_balance_chase ) $heading = 'Balance Payment Reminder';
            elseif ( $is_deposit_chase ) $heading = 'Deposit Payment Reminder';
            else $heading = 'Friendly Payment Reminder';
            $colour  = '#f39c12';
        } elseif ( $chase_count === 1 ) {
            $tpl_key = 'chase_overdue';
            if ( $is_balance_chase ) $heading = 'Balance Payment Overdue';
            elseif ( $is_deposit_chase ) $heading = 'Deposit Payment Overdue';
            else $heading = 'Payment Overdue';
            $colour  = '#e67e22';
        } else {
            $tpl_key = 'chase_urgent';
            if ( $is_balance_chase ) $heading = 'Urgent: Balance Payment Required';
            elseif ( $is_deposit_chase ) $heading = 'Urgent: Deposit Payment Required';
            else $heading = 'Urgent Payment Required';
            $colour  = '#e74c3c';
        }

        $tpl       = MBS_Email_Templates::get_template( $tpl_key );
        $subject   = MBS_Email_Templates::replace_placeholders( $tpl['subject'], $booking );
        $body_text = MBS_Email_Templates::replace_placeholders( $tpl['body'], $booking );

        $body  = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:0 auto;">';
        $body .= '<div style="background:' . $colour . ';padding:24px 32px;border-radius:8px 8px 0 0;text-align:center;">';
        $body .= MBS_Email_Templates::get_logo_html();
        $body .= '<h1 style="color:#fff;margin:8px 0 0;font-size:20px;">' . esc_html( $org['name'] ) . '</h1>';
        $body .= '<p style="color:rgba(255,255,255,0.9);margin:4px 0 0;">Payment Reminder</p>';
        $body .= '</div>';
        $body .= '<div style="background:#fff;padding:32px;border:1px solid #e0d0f0;border-top:none;border-radius:0 0 8px 8px;">';
        $body .= '<h2 style="color:' . $colour . ';">' . $heading . '</h2>';

        // Add deposit-specific context before the template text
        if ( $is_balance_chase ) {
            $body .= '<div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:6px;padding:12px;margin:0 0 16px;color:#92400e;">';
            $body .= '<strong>Remaining balance due: &pound;' . number_format( $amount_due, 2 ) . '</strong><br>';
            $body .= '<span style="font-size:13px;">Deposit of &pound;' . number_format( $deposit_paid_amt, 2 ) . ' received — thank you. The remaining balance is now due before your event.</span>';
            $body .= '</div>';
        } elseif ( $is_deposit_chase ) {
            $balance_days = $deposit_settings['balance_days'];
            $body .= '<div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:6px;padding:12px;margin:0 0 16px;color:#92400e;">';
            $body .= '<strong>Deposit due: &pound;' . number_format( $amount_due, 2 ) . '</strong> (' . (int) $deposit_settings['percentage'] . '% of &pound;' . number_format( $total_amount, 2 ) . ')<br>';
            $body .= '<span style="font-size:13px;">The remaining balance of &pound;' . number_format( $total_amount - $amount_due, 2 ) . ' will be due at least ' . $balance_days . ' days before your event.</span>';
            $body .= '</div>';
        }

        $body .= nl2br( esc_html( $body_text ) );

        // Pay Now button if WooCommerce available
        if ( MBS_Woo_Payment::is_available() ) {
            $pay_url = MBS_Woo_Payment::generate_payment_url( $booking );
            if ( $pay_url ) {
                if ( $is_balance_chase ) {
                    $btn_label = '💳 Pay Balance Now (&pound;' . number_format( $amount_due, 2 ) . ')';
                } elseif ( $is_deposit_chase ) {
                    $btn_label = '💳 Pay Deposit Now (&pound;' . number_format( $amount_due, 2 ) . ')';
                } else {
                    $btn_label = '💳 Pay Now Online';
                }
                $body .= '<p style="text-align:center;margin:24px 0;">';
                $body .= '<a href="' . esc_url( $pay_url ) . '" style="background:#2ecc71;color:#fff;padding:14px 32px;border-radius:6px;text-decoration:none;font-weight:bold;font-size:16px;">' . $btn_label . '</a>';
                $body .= '</p>';
            }
        }

        $body .= '</div>';
        $body .= '<div style="text-align:center;padding:16px;color:#999;font-size:12px;">' . esc_html( $org['name'] ) . ' &bull; ' . esc_html( $org['address'] ) . ' &bull; Charity No. ' . esc_html( $org['charity_number'] ) . '</div>';
        $body .= '</body></html>';

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $org['name'] . ' <' . get_option( 'admin_email', $admin_email ) . '>',
            'Reply-To: ' . $admin_email,
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
        $what = $is_balance_chase ? ' (balance £' . number_format( $amount_due, 2 ) . ')' : ' (full £' . number_format( $total_amount, 2 ) . ')';
        MBS_Audit_Log::log( $booking->ref, 'payment_chase', $type . ' (chase #' . ( $chase_count + 1 ) . ')' . $what );
    }
}
