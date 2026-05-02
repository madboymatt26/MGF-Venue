<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MBS_Email {

    /**
     * Get the admin email address (configurable in settings).
     */
    private static function admin_email() {
        return MBS_Bookings::get_admin_email();
    }

    public static function notify_admin( $booking ) {
        $admin_email = self::admin_email();
        $subject = '[New Booking] ' . $booking['ref'] . ' – ' . $booking['name'];
        $body    = self::header();
        $body   .= '<h2 style="color:#7413DC;">New Booking Request</h2>';
        $body   .= '<p>A new booking has been submitted and is awaiting your confirmation.</p>';
        $body   .= self::booking_table( $booking );
        $body   .= '<p style="margin-top:24px;"><a href="' . admin_url( 'admin.php?page=mathlin-booking&action=view&ref=' . $booking['ref'] ) . '" style="background:#7413DC;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;">View &amp; Manage Booking</a></p>';
        $body   .= self::footer();
        self::send( $admin_email, $subject, $body );
    }

    public static function notify_booker( $booking ) {
        $admin_email = self::admin_email();
        $subject = 'Booking Request Received – ' . $booking['ref'];
        $body    = self::header();
        $body   .= '<h2 style="color:#7413DC;">Thank you for your booking request!</h2>';
        $body   .= '<p>Hi ' . esc_html( $booking['name'] ) . ',</p>';
        $body   .= '<p>We\'ve received your booking request for <strong>' . esc_html( $booking['space'] ) . '</strong> on <strong>' . date( 'l j F Y', strtotime( $booking['booking_date'] ) ) . '</strong>.</p>';
        $body   .= '<p>Your booking reference is: <strong>' . esc_html( $booking['ref'] ) . '</strong></p>';
        $body   .= '<p>We\'ll be in touch shortly to confirm your booking and send an invoice.</p>';
        $body   .= self::booking_table( $booking );
        $body   .= '<p>If you have any questions, please contact us at <a href="mailto:' . esc_attr( $admin_email ) . '">' . esc_html( $admin_email ) . '</a> or call 01449 797577.</p>';
        $body   .= self::footer();
        self::send( $booking['email'], $subject, $body );
    }

    public static function notify_confirmed( $booking ) {
        $admin_email = self::admin_email();
        $subject = 'Booking Confirmed – ' . $booking->ref;
        $body    = self::header();
        $body   .= '<h2 style="color:#7413DC;">Your Booking is Confirmed!</h2>';
        $body   .= '<p>Hi ' . esc_html( $booking->name ) . ',</p>';
        $body   .= '<p>Great news — your booking has been confirmed. Please find the details and invoice attached.</p>';
        $body   .= self::booking_table_obj( $booking );
        $body   .= '<p><strong>Invoice Number:</strong> ' . esc_html( $booking->invoice_number ) . '</p>';
        $bank = MBS_Bookings::get_bank_details();
        $body   .= '<p>Payment is due within ' . $bank['payment_days'] . ' days. Please transfer to:<br>Sort Code: <strong>' . esc_html( $bank['sort_code'] ) . '</strong> | Account: <strong>' . esc_html( $bank['account_number'] ) . '</strong> | Ref: <strong>' . esc_html( $booking->invoice_number ) . '</strong></p>';
        $body   .= '<p>If you have any questions, please contact us at <a href="mailto:' . esc_attr( $admin_email ) . '">' . esc_html( $admin_email ) . '</a>.</p>';

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

        $body   .= self::ical_button( $booking );
        $body   .= self::footer();

        // Generate invoice HTML file as attachment
        $attachments = self::generate_invoice_attachment( $booking );

        self::send( $booking->email, $subject, $body, $attachments );
    }

    /**
     * Send a cancellation/denial notification to the booker.
     */
    public static function notify_cancelled( $booking, $reason = '' ) {
        $admin_email = self::admin_email();
        $subject = 'Booking Cancelled – ' . $booking->ref;
        $body    = self::header();
        $body   .= '<h2 style="color:#dc3232;">Booking Cancelled</h2>';
        $body   .= '<p>Hi ' . esc_html( $booking->name ) . ',</p>';
        $body   .= '<p>We\'re sorry, but your booking for <strong>' . esc_html( $booking->space ) . '</strong> on <strong>' . date( 'l j F Y', strtotime( $booking->booking_date ) ) . '</strong> has been cancelled.</p>';
        if ( ! empty( $reason ) ) {
            $body .= '<p><strong>Reason:</strong> ' . esc_html( $reason ) . '</p>';
        }
        $body   .= '<p>We apologise for any inconvenience. If you\'d like to rebook for a different date or have any questions, please don\'t hesitate to contact us.</p>';
        $body   .= self::booking_table_obj( $booking );
        $body   .= '<p>Contact us at <a href="mailto:' . esc_attr( $admin_email ) . '">' . esc_html( $admin_email ) . '</a> or call 01449 797577.</p>';
        $body   .= self::footer();
        self::send( $booking->email, $subject, $body );
    }

    public static function notify_paid( $booking ) {
        $admin_email = self::admin_email();
        $subject = 'Payment Received – ' . $booking->ref;
        $body    = self::header();
        $body   .= '<h2 style="color:#46b450;">Payment Received – Thank You!</h2>';
        $body   .= '<p>Hi ' . esc_html( $booking->name ) . ',</p>';
        $body   .= '<p>We\'ve received your payment for the following booking. Thank you!</p>';
        $body   .= self::booking_table_obj( $booking );
        $body   .= '<p>Your booking is fully confirmed and paid. We look forward to seeing you!</p>';
        $body   .= self::ical_button( $booking );
        $body   .= '<p>If you have any questions, please contact us at <a href="mailto:' . esc_attr( $admin_email ) . '">' . esc_html( $admin_email ) . '</a>.</p>';
        $body   .= self::footer();
        self::send( $booking->email, $subject, $body );
    }

    /**
     * Send a reminder email before a booking.
     */
    public static function notify_reminder( $booking ) {
        $admin_email = self::admin_email();
        $subject = 'Reminder: Your booking is coming up – ' . $booking->ref;
        $body    = self::header();
        $body   .= '<h2 style="color:#7413DC;">Booking Reminder</h2>';
        $body   .= '<p>Hi ' . esc_html( $booking->name ) . ',</p>';
        $body   .= '<p>Just a friendly reminder that your booking is coming up soon:</p>';
        $body   .= self::booking_table_obj( $booking );
        $body   .= self::ical_button( $booking );
        $body   .= '<p><strong>Location:</strong> Needham Market Scout Hall, Crown St, Needham Market, IP6 8RY</p>';
        $body   .= '<p>If you need to make any changes or cancel, please contact us at <a href="mailto:' . esc_attr( $admin_email ) . '">' . esc_html( $admin_email ) . '</a> or call 01449 797577.</p>';
        $body   .= '<p>We look forward to seeing you!</p>';
        $body   .= self::footer();
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
        return '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:0 auto;">
        <div style="background:#7413DC;padding:24px 32px;border-radius:8px 8px 0 0;">
            <h1 style="color:#fff;margin:0;font-size:20px;">&#9884; Needham Market Scout Group</h1>
            <p style="color:rgba(255,255,255,0.8);margin:4px 0 0;">Booking System</p>
        </div>
        <div style="background:#fff;padding:32px;border:1px solid #e0d0f0;border-top:none;border-radius:0 0 8px 8px;">';
    }

    private static function footer() {
        return '</div><div style="text-align:center;padding:16px;color:#999;font-size:12px;">
            Needham Market Scout Group &bull; Crown St, Needham Market, IP6 8RY &bull; 01449 797577<br>
            Registered Charity No. 1038177
        </div></body></html>';
    }

    private static function send( $to, $subject, $html_body, $attachments = array() ) {
        $admin_email = self::admin_email();
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: Needham Market Scouts <' . $admin_email . '>',
        );
        wp_mail( $to, $subject, $html_body, $headers, $attachments );
    }
}
