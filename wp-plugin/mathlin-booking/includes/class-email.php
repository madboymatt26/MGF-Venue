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

        // Add Pay Now button if WooCommerce is available
        if ( MBS_Woo_Payment::is_available() ) {
            $pay_url = MBS_Woo_Payment::generate_payment_url( $booking );
            if ( $pay_url ) {
                $body .= '<p style="margin-top:16px;text-align:center;">';
                $body .= '<a href="' . esc_url( $pay_url ) . '" style="background:#2ecc71;color:#fff;padding:14px 32px;border-radius:6px;text-decoration:none;font-weight:bold;font-size:16px;">💳 Pay Now Online</a>';
                $body .= '</p>';
                $body .= '<p style="text-align:center;font-size:13px;color:#666;">Or pay by bank transfer using the details above.</p>';
            }
        }

        $body .= self::ical_button( $booking );
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
        return '<p style="margin-top:16px;">' .
            '<a href="' . esc_url( $ical_url ) . '" style="background:#7413DC;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:bold;font-size:14px;">📅 Add to Calendar</a>' .
            '</p>';
    }

    private static function header() {
        $org = MBS_Email_Templates::get_org_settings();
        return '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:0 auto;">
        <div style="background:#7413DC;padding:24px 32px;border-radius:8px 8px 0 0;">
            <h1 style="color:#fff;margin:0;font-size:20px;">&#9884; ' . esc_html( $org['name'] ) . '</h1>
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

    private static function send( $to, $subject, $html_body, $attachments = array() ) {
        $admin_email = self::admin_email();
        $org = MBS_Email_Templates::get_org_settings();
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $org['name'] . ' <' . $admin_email . '>',
        );
        wp_mail( $to, $subject, $html_body, $headers, $attachments );
    }
}
