<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MBS_Email {

    /**
     * Get the admin email address(es).
     * Returns the primary email. For multiple recipients, use get_notification_emails().
     */
    private static function admin_email() {
        return MBS_Bookings::get_admin_email();
    }

    /**
     * Get all notification email addresses (comma-separated in settings).
     */
    private static function notification_emails() {
        $primary    = MBS_Bookings::get_admin_email();
        $additional = get_option( 'mbs_additional_emails', '' );

        $emails = array( $primary );
        if ( ! empty( $additional ) ) {
            $extras = array_map( 'trim', explode( ',', $additional ) );
            foreach ( $extras as $email ) {
                if ( is_email( $email ) && $email !== $primary ) {
                    $emails[] = $email;
                }
            }
        }
        return $emails;
    }

    public static function notify_admin( $booking ) {
        $emails  = self::notification_emails();
        $subject = '[New Booking] ' . $booking['ref'] . ' – ' . $booking['name'];
        $body    = self::header();
        $body   .= '<h2 style="color:#7413DC;">New Booking Request</h2>';
        $body   .= '<p>A new booking has been submitted and is awaiting your confirmation.</p>';
        $body   .= self::booking_table( $booking );
        $body   .= '<p style="margin-top:24px;"><a href="' . admin_url( 'admin.php?page=mathlin-booking&action=view&ref=' . $booking['ref'] ) . '" style="background:#7413DC;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;">View &amp; Manage Booking</a></p>';
        $body   .= self::footer();
        foreach ( $emails as $email ) {
            self::send( $email, $subject, $body );
        }
    }

    public static function notify_booker( $booking ) {
        $tpl     = MBS_Email_Templates::get_template( 'booking_received' );
        $subject = MBS_Email_Templates::replace_placeholders( $tpl['subject'], $booking );
        $body_text = MBS_Email_Templates::replace_placeholders( $tpl['body'], $booking );

        $body  = self::header();
        $body .= '<h2 style="color:#7413DC;">Thank you for your booking request!</h2>';
        $body .= nl2br( esc_html( $body_text ) );
        $body .= self::booking_table( $booking );
        $body .= self::footer();
        self::send( $booking['email'], $subject, $body );
    }

    public static function notify_confirmed( $booking ) {
        $tpl       = MBS_Email_Templates::get_template( 'booking_confirmed' );
        $subject   = MBS_Email_Templates::replace_placeholders( $tpl['subject'], $booking );
        $body_text = MBS_Email_Templates::replace_placeholders( $tpl['body'], $booking );

        $body  = self::header();
        $body .= '<h2 style="color:#7413DC;">Your Booking is Confirmed!</h2>';
        $body .= nl2br( esc_html( $body_text ) );
        $body .= self::booking_table_obj( $booking );

        // Deposit-aware payment terms
        $deposit_settings = MBS_Bookings::get_deposit_settings();
        $total_amount     = (float) $booking->amount;

        if ( $deposit_settings['enabled'] && $total_amount > 0 ) {
            $requires_full  = MBS_Bookings::requires_full_payment( $booking->booking_date );
            $deposit_amount = MBS_Bookings::calculate_deposit( $total_amount );
            $balance_amount = $total_amount - $deposit_amount;
            $balance_days   = $deposit_settings['balance_days'];

            if ( ! $requires_full ) {
                $body .= '<div style="background:#f5f0ff;border:1px solid #e0d0f0;border-radius:6px;padding:16px;margin:16px 0;">';
                $body .= '<strong style="color:#7413DC;">Payment Schedule</strong><br><br>';
                $body .= '&#9679; <strong>Deposit due now:</strong> &pound;' . number_format( $deposit_amount, 2 ) . ' (' . (int) $deposit_settings['percentage'] . '% of total)<br>';
                $body .= '&#9679; <strong>Final balance:</strong> &pound;' . number_format( $balance_amount, 2 ) . ' — due by ' . date( 'j F Y', strtotime( $booking->booking_date . " -{$balance_days} days" ) ) . '<br><br>';
                $body .= '<em style="font-size:13px;color:#6b7280;">Bookings made less than ' . $balance_days . ' days before the event require full payment immediately.</em>';
                $body .= '</div>';
            } else {
                $body .= '<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:16px;margin:16px 0;">';
                $body .= '<strong>Full payment of &pound;' . number_format( $total_amount, 2 ) . ' is due immediately</strong> as your event is within ' . $balance_days . ' days.';
                $body .= '</div>';
            }
        } elseif ( $total_amount > 0 ) {
            // Deposits disabled — show standard payment terms
            $bank = MBS_Bookings::get_bank_details();
            $body .= '<div style="background:#f5f0ff;border:1px solid #e0d0f0;border-radius:6px;padding:16px;margin:16px 0;">';
            $body .= '<strong>Payment due within ' . $bank['payment_days'] . ' days.</strong><br>';
            $body .= 'Sort Code: ' . esc_html( $bank['sort_code'] ) . '<br>';
            $body .= 'Account: ' . esc_html( $bank['account_number'] ) . '<br>';
            $body .= 'Reference: ' . esc_html( $booking->invoice_number );
            $body .= '</div>';
        }

        // Add Pay Now button if WooCommerce is available
        if ( MBS_Woo_Payment::is_available() ) {
            $pay_url = MBS_Woo_Payment::generate_payment_url( $booking );
            if ( $pay_url ) {
                // Label the button appropriately
                $deposit_settings_check = MBS_Bookings::get_deposit_settings();
                $btn_label = '💳 Pay Now Online';
                if ( $deposit_settings_check['enabled'] && $total_amount > 0 && ! MBS_Bookings::requires_full_payment( $booking->booking_date ) ) {
                    $btn_label = '💳 Pay Deposit Now (&pound;' . number_format( MBS_Bookings::calculate_deposit( $total_amount ), 2 ) . ')';
                }

                $body .= '<p style="margin-top:16px;text-align:center;">';
                $body .= '<a href="' . esc_url( $pay_url ) . '" style="background:#2ecc71;color:#fff;padding:14px 32px;border-radius:6px;text-decoration:none;font-weight:bold;font-size:16px;">' . $btn_label . '</a>';
                $body .= '</p>';
                $body .= '<p style="text-align:center;font-size:13px;color:#666;">Or pay by bank transfer using the details on the attached invoice.</p>';
            }
        }

        $body .= self::ical_button( $booking );

        // Add T&C reminder with link
        $terms_url = self::get_terms_url();
        if ( $terms_url ) {
            $body .= '<p style="margin-top:16px;padding:12px 16px;background:#f5f0ff;border-radius:6px;font-size:13px;color:#6b7280;">';
            $body .= 'By confirming this booking, you agree to our <a href="' . esc_url( $terms_url ) . '" style="color:#7413DC;">Terms &amp; Conditions of Hire</a>. ';
            $body .= 'Please ensure all members of your party are aware of the conditions.';
            $body .= '</p>';
        }

        $body .= self::footer();

        $attachments = self::generate_invoice_attachment( $booking );
        self::send( $booking->email, $subject, $body, $attachments );
    }

    /**
     * Send a cancellation/denial notification to the booker.
     */
    public static function notify_cancelled( $booking, $reason = '' ) {
        $tpl       = MBS_Email_Templates::get_template( 'booking_cancelled' );
        $extra     = array( '{reason}' => $reason ? 'Reason: ' . $reason : '' );
        $subject   = MBS_Email_Templates::replace_placeholders( $tpl['subject'], $booking, $extra );
        $body_text = MBS_Email_Templates::replace_placeholders( $tpl['body'], $booking, $extra );

        $body  = self::header();
        $body .= '<h2 style="color:#dc3232;">Booking Cancelled</h2>';
        $body .= nl2br( esc_html( $body_text ) );
        $body .= self::booking_table_obj( $booking );
        $body .= self::footer();
        self::send( $booking->email, $subject, $body );
    }

    public static function notify_paid( $booking ) {
        $tpl       = MBS_Email_Templates::get_template( 'payment_received' );
        $subject   = MBS_Email_Templates::replace_placeholders( $tpl['subject'], $booking );
        $body_text = MBS_Email_Templates::replace_placeholders( $tpl['body'], $booking );

        $body  = self::header();
        $body .= '<h2 style="color:#46b450;">Payment Received – Thank You!</h2>';
        $body .= nl2br( esc_html( $body_text ) );
        $body .= self::booking_table_obj( $booking );
        $body .= self::ical_button( $booking );
        $body .= self::footer();
        self::send( $booking->email, $subject, $body );
    }

    /**
     * Send a summary email for a recurring booking series.
     */
    public static function notify_recurring_summary( $series_id, $refs, $skipped, $name, $email, $space, $time_str ) {
        $admin_email = self::admin_email();
        $org         = MBS_Email_Templates::get_org_settings();

        $subject = 'Recurring Booking Submitted – ' . $series_id;

        $body  = self::header();
        $body .= '<h2 style="color:#7413DC;">Recurring Booking Submitted</h2>';
        $body .= '<p>Hi ' . esc_html( $name ) . ',</p>';
        $body .= '<p>Your recurring booking request has been submitted. Here is a summary:</p>';

        $body .= '<table style="width:100%;border-collapse:collapse;margin:16px 0;">';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;width:35%;border-bottom:1px solid #e0d0f0;">Series Reference</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $series_id ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Space</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $space ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Time</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $time_str ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Bookings Created</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;"><strong>' . count( $refs ) . '</strong></td></tr>';

        // QA-005: Calculate and show total cost for the series
        $total_series_cost = 0;
        foreach ( $refs as $r ) {
            $b = MBS_Bookings::get( $r );
            if ( $b ) $total_series_cost += (float) $b->amount;
        }
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Total Cost</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;font-weight:bold;">&pound;' . number_format( $total_series_cost, 2 ) . '</td></tr>';

        $body .= '</table>';

        $body .= '<h3 style="color:#7413DC;margin-top:24px;">Booked Dates</h3>';
        $body .= '<ul style="margin:8px 0;padding-left:20px;">';
        foreach ( $refs as $ref ) {
            $booking = MBS_Bookings::get( $ref );
            if ( $booking ) {
                $body .= '<li style="margin-bottom:4px;">' . esc_html( date( 'l j F Y', strtotime( $booking->booking_date ) ) ) . ' &mdash; <code>' . esc_html( $ref ) . '</code></li>';
            }
        }
        $body .= '</ul>';

        if ( ! empty( $skipped ) ) {
            $body .= '<h3 style="color:#f39c12;margin-top:16px;">Skipped Dates</h3>';
            $body .= '<p style="font-size:0.85rem;color:#6b7280;">These dates were skipped due to conflicts or blocked dates:</p>';
            $body .= '<ul style="margin:8px 0;padding-left:20px;color:#856404;">';
            foreach ( $skipped as $skip_date ) {
                $body .= '<li style="margin-bottom:4px;">' . esc_html( date( 'l j F Y', strtotime( $skip_date ) ) ) . '</li>';
            }
            $body .= '</ul>';
        }

        $body .= '<p style="margin-top:16px;">Each booking is pending confirmation. We will review and confirm them shortly.</p>';
        $body .= '<p>If you have any questions, contact us at <a href="mailto:' . esc_attr( $admin_email ) . '">' . esc_html( $admin_email ) . '</a> or call ' . esc_html( $org['phone'] ) . '.</p>';
        $body .= self::footer();

        self::send( $email, $subject, $body );
    }

    /**
     * Send a reminder email before a booking.
     */
    public static function notify_reminder( $booking ) {
        $tpl       = MBS_Email_Templates::get_template( 'booking_reminder' );
        $subject   = MBS_Email_Templates::replace_placeholders( $tpl['subject'], $booking );
        $body_text = MBS_Email_Templates::replace_placeholders( $tpl['body'], $booking );

        $body  = self::header();
        $body .= '<h2 style="color:#7413DC;">Booking Reminder</h2>';
        $body .= nl2br( esc_html( $body_text ) );
        $body .= self::booking_table_obj( $booking );
        $body .= self::ical_button( $booking );
        $body .= self::footer();
        self::send( $booking->email, $subject, $body );
    }

    /**
     * Generate an invoice HTML file and return the path for email attachment.
     */
    private static function generate_invoice_attachment( $booking ) {
        $invoice_html = MBS_Invoice::generate_email_invoice( $booking );

        // Create a temporary HTML file
        $upload_dir = wp_upload_dir();
        $invoice_dir = $upload_dir['basedir'] . '/mbs-invoices/';

        if ( ! file_exists( $invoice_dir ) ) {
            wp_mkdir_p( $invoice_dir );
            // Add an index.php to prevent directory listing
            file_put_contents( $invoice_dir . 'index.php', '<?php // Silence is golden.' );
        }

        $filename = sanitize_file_name( $booking->invoice_number . '.html' );
        $filepath = $invoice_dir . $filename;

        file_put_contents( $filepath, $invoice_html );

        return array( $filepath );
    }

    /**
     * Public wrapper for generating invoice attachments.
     * Used by modification approval emails to attach updated invoices.
     *
     * @param  object $booking  Booking object
     * @return array            Array of file paths for wp_mail attachments
     */
    public static function generate_invoice_attachment_for( $booking ) {
        return self::generate_invoice_attachment( $booking );
    }

    private static function booking_table( $b ) {
        $time = ( $b['space'] === 'Outdoor Area' ) ? 'All day' : ( $b['start_time'] . ' – ' . $b['end_time'] );
        return self::table_html( $b['ref'], $b['space'], $b['booking_date'], $time, $b['attendees'], $b['purpose'], $b['amount'] );
    }

    private static function booking_table_obj( $b ) {
        $spaces   = MBS_Bookings::get_spaces();
        $is_daily = isset( $spaces[ $b->space ] ) && $spaces[ $b->space ]['unit'] === 'day';
        $time     = $is_daily ? 'All day' : ( $b->start_time . ' – ' . $b->end_time );
        return self::table_html( $b->ref, $b->space, $b->booking_date, $time, $b->attendees, $b->purpose, $b->amount );
    }

    private static function table_html( $ref, $space, $date, $time, $attendees, $purpose, $amount ) {
        $rows = array(
            'Reference'  => esc_html( $ref ),
            'Space'      => esc_html( $space ),
            'Date'       => esc_html( date( 'l j F Y', strtotime( $date ) ) ),
            'Time'       => esc_html( $time ),
            'Attendees'  => esc_html( $attendees ),
            'Purpose'    => esc_html( $purpose ),
            'Amount Due' => '&pound;' . number_format( $amount, 2 ),
        );
        $html = '<table style="width:100%;border-collapse:collapse;margin:16px 0;">';
        foreach ( $rows as $label => $value ) {
            $html .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;width:35%;border-bottom:1px solid #e0d0f0;">' . $label . '</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . $value . '</td></tr>';
        }
        return $html . '</table>';
    }

    /**
     * Generate an "Add to Calendar" button for emails.
     */
    private static function ical_button( $booking ) {
        $ical_url = rest_url( 'mathlin/v1/bookings/' . $booking->ref . '/ical' );
        $html = '<p style="margin-top:16px;">' .
            '<a href="' . esc_url( $ical_url ) . '" style="background:#7413DC;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:bold;font-size:14px;">📅 Add to Calendar</a>' .
            '</p>';

        // Add modification link if token exists
        $mod_url = MBS_Modification::get_modification_url( $booking );
        if ( $mod_url ) {
            $html .= '<p style="margin-top:8px;text-align:center;">' .
                '<a href="' . esc_url( $mod_url ) . '" style="color:#7413DC;font-size:13px;">Need to change something? Request a modification</a>' .
                '</p>';
        }

        return $html;
    }

    private static function header() {
        $org  = MBS_Email_Templates::get_org_settings();
        $logo = MBS_Email_Templates::get_logo_html();
        return '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:0 auto;">
        <div style="background:#7413DC;padding:24px 32px;border-radius:8px 8px 0 0;text-align:center;">
            ' . $logo . '
            <h1 style="color:#fff;margin:8px 0 0;font-size:20px;">' . esc_html( $org['name'] ) . '</h1>
            <p style="color:rgba(255,255,255,0.8);margin:4px 0 0;">Booking System</p>
        </div>
        <div style="background:#fff;padding:32px;border:1px solid #e0d0f0;border-top:none;border-radius:0 0 8px 8px;">';
    }

    private static function footer() {
        $org = MBS_Email_Templates::get_org_settings();
        return '</div><div style="text-align:center;padding:16px;color:#999;font-size:12px;">
            ' . esc_html( $org['name'] ) . ' &bull; ' . esc_html( $org['address'] ) . ' &bull; ' . esc_html( $org['phone'] ) . '<br>
            Registered Charity No. ' . esc_html( $org['charity_number'] ) . '
        </div></body></html>';
    }

    /**
     * Get the URL to the Terms & Conditions page.
     */
    private static function get_terms_url() {
        $terms_page_id = (int) get_option( 'mbs_terms_page_id', 0 );
        if ( $terms_page_id && get_post( $terms_page_id ) ) {
            return get_permalink( $terms_page_id );
        }
        // Fallback: find a page with [mathlin_terms] shortcode
        $pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 's' => 'mathlin_terms', 'numberposts' => 1 ) );
        if ( ! empty( $pages ) ) {
            return get_permalink( $pages[0]->ID );
        }
        return '';
    }

    private static function send( $to, $subject, $html_body, $attachments = array() ) {
        $admin_email = self::admin_email();
        $org = MBS_Email_Templates::get_org_settings();

        // Use WordPress site email as the technical sender to avoid SPF/DKIM issues
        // when the admin notification email is the same as the recipient.
        // The Reply-To header ensures replies go to the admin email.
        $from_email = get_option( 'admin_email', $admin_email );
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $org['name'] . ' <' . $from_email . '>',
            'Reply-To: ' . $admin_email,
        );
        // Use the email queue for automatic retry on failure
        MBS_Email_Queue::send( $to, $subject, $html_body, $headers, $attachments );
    }
}
