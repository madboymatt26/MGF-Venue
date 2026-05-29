<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MBS_HomeAssistant {

    public static function notify( $booking ) {
        $webhook_url = get_option( 'mbs_ha_webhook_url', '' );
        if ( empty( $webhook_url ) ) return;

        // Skip HA webhook for scout use bookings — HA handles scout heating locally
        if ( ! empty( $booking->scout_use ) ) return;

        $payload = array(
            'event'        => 'booking_confirmed',
            'ref'          => $booking->ref,
            'space'        => $booking->space,
            'booking_date' => $booking->booking_date,
            'start_time'   => $booking->start_time,
            'end_time'     => $booking->end_time,
            'attendees'    => (int)   $booking->attendees,
            'purpose'      => $booking->purpose,
            'kitchen'      => (bool)  $booking->kitchen,
            'amount'       => (float) $booking->amount,
        );

        $response = wp_remote_post( $webhook_url, array(
            'method'  => 'POST',
            'timeout' => 10,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( '[Mathlin Booking] HA webhook failed: ' . $response->get_error_message() );
        }
    }

    public static function notify_cancelled( $booking ) {
        $webhook_url = get_option( 'mbs_ha_webhook_url', '' );
        if ( empty( $webhook_url ) ) return;

        // Skip HA webhook for scout use bookings — HA handles scout heating locally
        if ( ! empty( $booking->scout_use ) ) return;

        $payload = array(
            'event'        => 'booking_cancelled',
            'ref'          => $booking->ref,
            'space'        => $booking->space,
            'booking_date' => $booking->booking_date,
            'start_time'   => $booking->start_time,
            'end_time'     => $booking->end_time,
        );

        wp_remote_post( $webhook_url, array(
            'method'  => 'POST',
            'timeout' => 10,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
        ) );
    }

    public static function get_upcoming_for_ha( $days_ahead = 30 ) {
        $bookings = MBS_Bookings::get_all( array(
            'status'    => 'confirmed',
            'date_from' => wp_date( 'Y-m-d' ),
            'date_to'   => wp_date( 'Y-m-d', strtotime( "+{$days_ahead} days" ) ),
            'orderby'   => 'booking_date',
            'order'     => 'ASC',
            'limit'     => 50,
        ) );

        $result = array();
        foreach ( $bookings as $b ) {
            $result[] = array(
                'ref'          => $b->ref,
                'space'        => $b->space,
                'booking_date' => $b->booking_date,
                'start_time'   => $b->start_time,
                'end_time'     => $b->end_time,
                'attendees'    => (int)  $b->attendees,
                'purpose'      => $b->purpose,
                'kitchen'      => (bool) $b->kitchen,
                'all_day'      => $b->space === 'Outdoor Area',
            );
        }
        return $result;
    }

    public static function get_todays_bookings() {
        $bookings = MBS_Bookings::get_by_date( wp_date( 'Y-m-d' ) );
        $result   = array();
        foreach ( $bookings as $b ) {
            $result[] = array(
                'ref'        => $b->ref,
                'space'      => $b->space,
                'start_time' => $b->start_time,
                'end_time'   => $b->end_time,
                'attendees'  => (int)  $b->attendees,
                'purpose'    => $b->purpose,
                'kitchen'    => (bool) $b->kitchen,
                'all_day'    => (bool) $b->all_day,
            );
        }
        return $result;
    }
}
