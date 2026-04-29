<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MBS_Email {

    const ADMIN_EMAIL = 'bookings@needhamscouts.uk';

    public static function notify_admin( $booking ) {
        $subject = '[New Booking] ' . $booking['ref'] . ' – ' . $booking['name'];
        $body    = self::header();
        $body   .= '<h2 style="color:#7413DC;">New Booking Request</h2>';
        $body   .= '<p>A new booking has been submitted and is awaiting your confirmation.</p>';
        $body   .= self::booking_table( $booking );
        $body   .= '<p style="margin-top:24px;"><a href="' . admin_url( 'admin.php?page=mathlin-booking&action=view&ref=' . $booking['ref'] ) . '" style="background:#7413DC;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;">View &amp; Manage Booking</a></p>';
        $body   .= self::footer();
        self::send( self::ADMIN_EMAIL, $subject, $body );
    }

    public static function notify_booker( $booking ) {
        $subject = 'Booking Request Received – ' . $booking['ref'];
        $body    = self::header();
        $body   .= '<h2 style="color:#7413DC;">Thank you for your booking request!</h2>';
        $body   .= '<p>Hi ' . esc_html( $booking['name'] ) . ',</p>';
        $body   .= '<p>We\'ve received your booking request for <strong>' . esc_html( $booking['space'] ) . '</strong> on <strong>' . date( 'l j F Y', strtotime( $booking['booking_date'] ) ) . '</strong>.</p>';
        $body   .= '<p>Your booking reference is: <strong>' . esc_html( $booking['ref'] ) . '</strong></p>';
        $body   .= '<p>We\'ll be in touch shortly to confirm your booking and send an invoice.</p>';
        $body   .= self::booking_table( $booking );
        $body   .= '<p>If you have any questions, please contact us at <a href="mailto:' . self::ADMIN_EMAIL . '">' . self::ADMIN_EMAIL . '</a> or call 01449 797577.</p>';
        $body   .= self::footer();
        self::send( $booking['email'], $subject, $body );
    }

    public static function notify_confirmed( $booking ) {
        $subject = 'Booking Confirmed – ' . $booking->ref;
        $body    = self::header();
        $body   .= '<h2 style="color:#7413DC;">Your Booking is Confirmed!</h2>';
        $body   .= '<p>Hi ' . esc_html( $booking->name ) . ',</p>';
        $body   .= '<p>Great news — your booking has been confirmed. Please find the details below.</p>';
        $body   .= self::booking_table_obj( $booking );
        $body   .= '<p><strong>Invoice Number:</strong> ' . esc_html( $booking->invoice_number ) . '</p>';
        $body   .= '<p>Payment is due within 14 days. Please transfer to:<br>Sort Code: <strong>12-34-56</strong> | Account: <strong>12345678</strong> | Ref: <strong>' . esc_html( $booking->invoice_number ) . '</strong></p>';
        $body   .= '<p>If you have any questions, please contact us at <a href="mailto:' . self::ADMIN_EMAIL . '">' . self::ADMIN_EMAIL . '</a>.</p>';
        $body   .= self::footer();
        self::send( $booking->email, $subject, $body );
    }

    private static function booking_table( $b ) {
        $time = ( $b['space'] === 'Outdoor Area' ) ? 'All day' : ( $b['start_time'] . ' – ' . $b['end_time'] );
        return self::table_html( $b['ref'], $b['space'], $b['booking_date'], $time, $b['attendees'], $b['purpose'], $b['amount'] );
    }

    private static function booking_table_obj( $b ) {
        $time = ( $b->space === 'Outdoor Area' ) ? 'All day' : ( $b->start_time . ' – ' . $b->end_time );
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

    private static function send( $to, $subject, $html_body ) {
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: Needham Market Scouts <' . self::ADMIN_EMAIL . '>',
        );
        wp_mail( $to, $subject, $html_body, $headers );
    }
}
