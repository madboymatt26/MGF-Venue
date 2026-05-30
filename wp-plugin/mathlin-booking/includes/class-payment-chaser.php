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
            error_log( "[MGF Venue] Auto-chased {$count} overdue payment(s)." );
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

        // Core calculation: what does the customer actually owe?
        $total_amount    = (float) $booking->amount;
        $amount_paid_val = (float) ( $booking->amount_paid ?? 0 );
        $balance_due     = $total_amount - $amount_paid_val;

        // If nothing is owed, don't chase
        if ( $balance_due <= 0.01 ) return;

        // B2B EXEMPTION: offline-invoicing tiers get a gentle statement reminder
        // instead of the escalating "pay now or cancel" chase sequence.
        if ( MBS_Bookings::booking_is_offline( $booking ) ) {
            self::send_b2b_statement_reminder( $booking, $balance_due, $manual );
            return;
        }

        // Determine chase type based on what's already been paid
        $deposit_settings = MBS_Bookings::get_deposit_settings();
        $deposit_amount   = MBS_Bookings::calculate_deposit( $total_amount );

        if ( $amount_paid_val > 0 ) {
            // They've paid something — this is a balance chase
            $chase_type = 'balance';
            $amount_due = $balance_due;
        } elseif ( $deposit_settings['enabled'] && ! MBS_Bookings::requires_full_payment( $booking->booking_date ) ) {
            // No payment yet, deposits enabled, event far away — chase the deposit
            $chase_type = 'deposit';
            $amount_due = $deposit_amount;
        } else {
            // No payment, no deposits (or event imminent) — chase full amount
            $chase_type = 'full';
            $amount_due = $balance_due;
        }

        // Determine which template and colour based on chase count
        $headings = array(
            'balance' => array( 'Balance Payment Reminder', 'Balance Payment Overdue', 'Urgent: Balance Payment Required' ),
            'deposit' => array( 'Deposit Payment Reminder', 'Deposit Payment Overdue', 'Urgent: Deposit Payment Required' ),
            'full'    => array( 'Friendly Payment Reminder', 'Payment Overdue', 'Urgent Payment Required' ),
        );
        $colours = array( '#f39c12', '#e67e22', '#e74c3c' );
        $tpl_keys = array( 'chase_gentle', 'chase_overdue', 'chase_urgent' );

        $level   = min( $chase_count, 2 );
        $tpl_key = $tpl_keys[ $level ];
        $heading = $headings[ $chase_type ][ $level ];
        $colour  = $colours[ $level ];

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

        // Add context box before the template text
        if ( $chase_type === 'balance' ) {
            $body .= '<div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:6px;padding:12px;margin:0 0 16px;color:#92400e;">';
            $body .= '<strong>Balance due: &pound;' . number_format( $amount_due, 2 ) . '</strong><br>';
            $body .= '<span style="font-size:13px;">Already paid: &pound;' . number_format( $amount_paid_val, 2 ) . ' | Total: &pound;' . number_format( $total_amount, 2 ) . '</span>';
            $body .= '</div>';
        } elseif ( $chase_type === 'deposit' ) {
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
                if ( $chase_type === 'balance' ) {
                    $btn_label = '💳 Pay Balance Now (&pound;' . number_format( $amount_due, 2 ) . ')';
                } elseif ( $chase_type === 'deposit' ) {
                    $btn_label = '💳 Pay Deposit Now (&pound;' . number_format( $amount_due, 2 ) . ')';
                } else {
                    $btn_label = '💳 Pay Now (&pound;' . number_format( $amount_due, 2 ) . ')';
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
        $what = ' (' . $chase_type . ' £' . number_format( $amount_due, 2 ) . ')';
        MBS_Audit_Log::log( $booking->ref, 'payment_chase', $type . ' (chase #' . ( $chase_count + 1 ) . ')' . $what );
    }

    /**
     * Send a gentle "statement reminder" for B2B offline-invoicing bookings.
     *
     * Councils / commercial hirers pay via BACS or Purchase Order on their own
     * finance cycle, so they should never receive the escalating "pay now or
     * we'll cancel" sequence. Instead we send a single calm reminder that an
     * invoice is outstanding, addressed at their accounts payable department,
     * with the configured BACS / PO instructions.
     *
     * @param object $booking     Booking database row
     * @param float  $balance_due Outstanding balance
     * @param bool   $manual      Whether triggered manually by an admin
     */
    public static function send_b2b_statement_reminder( $booking, $balance_due, $manual = false ) {
        $chase_count = (int) ( $booking->chase_count ?? 0 );
        $org         = MBS_Email_Templates::get_org_settings();
        $admin_email = MBS_Bookings::get_admin_email();

        $total_amount = (float) $booking->amount;
        $reference    = $booking->invoice_number ?: $booking->ref;

        $subject = 'Statement Reminder: Outstanding Invoice ' . $reference . ' — ' . $org['name'];

        $colour = '#4f46e5'; // calm indigo, not an alarm colour
        $body  = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:0 auto;">';
        $body .= '<div style="background:' . $colour . ';padding:24px 32px;border-radius:8px 8px 0 0;text-align:center;">';
        $body .= MBS_Email_Templates::get_logo_html();
        $body .= '<h1 style="color:#fff;margin:8px 0 0;font-size:20px;">' . esc_html( $org['name'] ) . '</h1>';
        $body .= '<p style="color:rgba(255,255,255,0.9);margin:4px 0 0;">Account Statement Reminder</p>';
        $body .= '</div>';
        $body .= '<div style="background:#fff;padding:32px;border:1px solid #e0d0f0;border-top:none;border-radius:0 0 8px 8px;">';
        $body .= '<h2 style="color:' . $colour . ';">Invoice ' . esc_html( $reference ) . ' is outstanding</h2>';
        $body .= '<p>Dear ' . esc_html( $booking->name ) . ',</p>';
        $body .= '<p>This is a courtesy reminder for your finance / accounts payable department that the following invoice remains outstanding on your account. No action is required if payment is already in progress through your normal purchase order or BACS cycle.</p>';

        $body .= '<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px;">';
        $body .= '<tr><td style="padding:6px 0;color:#555;">Invoice / Reference</td><td style="padding:6px 0;text-align:right;"><strong>' . esc_html( $reference ) . '</strong></td></tr>';
        $body .= '<tr><td style="padding:6px 0;color:#555;">Booking date</td><td style="padding:6px 0;text-align:right;">' . esc_html( wp_date( 'j M Y', strtotime( $booking->booking_date ) ) ) . '</td></tr>';
        $body .= '<tr><td style="padding:6px 0;color:#555;">Invoice total</td><td style="padding:6px 0;text-align:right;">&pound;' . number_format( $total_amount, 2 ) . '</td></tr>';
        if ( (float) ( $booking->amount_paid ?? 0 ) > 0 ) {
            $body .= '<tr><td style="padding:6px 0;color:#555;">Received to date</td><td style="padding:6px 0;text-align:right;">&pound;' . number_format( (float) $booking->amount_paid, 2 ) . '</td></tr>';
        }
        $body .= '<tr style="border-top:1px solid #e0d0f0;"><td style="padding:8px 0;color:#1a1a2e;"><strong>Balance outstanding</strong></td><td style="padding:8px 0;text-align:right;"><strong>&pound;' . number_format( $balance_due, 2 ) . '</strong></td></tr>';
        $body .= '</table>';

        // BACS / Purchase Order instructions
        $body .= MBS_Email::offline_payment_block( $booking, $balance_due );

        $body .= '<p style="font-size:13px;color:#777;margin-top:16px;">If this invoice has already been settled, please accept our thanks and disregard this reminder. For any queries, just reply to this email.</p>';
        $body .= '</div>';
        $body .= '<div style="text-align:center;padding:16px;color:#999;font-size:12px;">' . esc_html( $org['name'] ) . ' &bull; ' . esc_html( $org['address'] ) . ' &bull; Charity No. ' . esc_html( $org['charity_number'] ) . '</div>';
        $body .= '</body></html>';

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $org['name'] . ' <' . get_option( 'admin_email', $admin_email ) . '>',
            'Reply-To: ' . $admin_email,
        );
        wp_mail( $booking->email, $subject, $body, $headers );

        // Update chase tracking (so we respect the chase interval / max cap)
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
        $type = $manual ? 'Manual B2B statement reminder' : 'Auto B2B statement reminder';
        MBS_Audit_Log::log( $booking->ref, 'payment_chase', $type . ' (reminder #' . ( $chase_count + 1 ) . ', balance £' . number_format( $balance_due, 2 ) . ')' );
    }
}
