<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * iCal / .ics file generation for bookings.
 *
 * Generates downloadable .ics files that can be imported into
 * Google Calendar, Apple Calendar, Outlook, etc.
 */
class MBS_ICal {

    /**
     * Generate an .ics file content string for a booking.
     *
     * @param object $booking  Booking database row
     * @return string  iCalendar formatted string
     */
    public static function generate( $booking ) {
        $spaces = MBS_Bookings::get_spaces();

        // Determine start/end datetimes
        if ( $booking->all_day ) {
            // All-day event: use DATE format (no time)
            $dtstart = 'DTSTART;VALUE=DATE:' . date( 'Ymd', strtotime( $booking->booking_date ) );
            $end_date = $booking->booking_date_end ?: $booking->booking_date;
            // iCal all-day events: DTEND is exclusive, so add 1 day
            $dtend = 'DTEND;VALUE=DATE:' . date( 'Ymd', strtotime( $end_date . ' +1 day' ) );
        } else {
            $start_dt = $booking->booking_date . ' ' . $booking->start_time;
            $end_dt   = $booking->booking_date . ' ' . $booking->end_time;
            $dtstart  = 'DTSTART:' . gmdate( 'Ymd\THis\Z', strtotime( $start_dt ) );
            $dtend    = 'DTEND:' . gmdate( 'Ymd\THis\Z', strtotime( $end_dt ) );
        }

        $summary     = self::escape( $booking->space . ' – ' . $booking->purpose );
        $description = self::escape(
            'Booking Ref: ' . $booking->ref . '\n' .
            'Space: ' . $booking->space . '\n' .
            'Attendees: ' . $booking->attendees . '\n' .
            'Purpose: ' . $booking->purpose . '\n' .
            ( $booking->kitchen ? 'Kitchen: Yes\n' : '' ) .
            ( $booking->notes ? 'Notes: ' . $booking->notes . '\n' : '' ) .
            'Amount: £' . number_format( $booking->amount, 2 )
        );
        $location = self::escape( MBS_Email_Templates::get_org_settings()['address'] ?: get_bloginfo( 'name' ) );
        $uid      = $booking->ref . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
        $now      = gmdate( 'Ymd\THis\Z' );

        $ics  = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//MGF Venue//EN\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:{$uid}\r\n";
        $ics .= "DTSTAMP:{$now}\r\n";
        $ics .= "{$dtstart}\r\n";
        $ics .= "{$dtend}\r\n";
        $ics .= "SUMMARY:{$summary}\r\n";
        $ics .= "DESCRIPTION:{$description}\r\n";
        $ics .= "LOCATION:{$location}\r\n";
        $ics .= "STATUS:CONFIRMED\r\n";
        $ics .= "END:VEVENT\r\n";
        $ics .= "END:VCALENDAR\r\n";

        return $ics;
    }

    /**
     * Generate an iCal feed URL for all upcoming confirmed bookings.
     * This can be subscribed to in Google Calendar / Outlook.
     */
    public static function get_feed_url() {
        return rest_url( 'mathlin/v1/bookings/ical' );
    }

    /**
     * Generate a full iCal feed with all upcoming confirmed bookings.
     */
    public static function generate_feed() {
        $bookings = MBS_Bookings::get_all( array(
            'status'    => 'confirmed',
            'date_from' => wp_date( 'Y-m-d' ),
            'orderby'   => 'booking_date',
            'order'     => 'ASC',
            'limit'     => 200,
        ) );

        // Also include paid bookings
        $paid = MBS_Bookings::get_all( array(
            'status'    => 'paid',
            'date_from' => wp_date( 'Y-m-d' ),
            'orderby'   => 'booking_date',
            'order'     => 'ASC',
            'limit'     => 200,
        ) );
        $bookings = array_merge( $bookings, $paid );

        $ics  = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//MGF Venue//EN\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";
        $org = MBS_Email_Templates::get_org_settings();
        $cal_name = $org['name'] ? $org['name'] . ' Bookings' : get_bloginfo( 'name' ) . ' Bookings';
        $ics .= "X-WR-CALNAME:" . self::escape( $cal_name ) . "\r\n";

        foreach ( $bookings as $booking ) {
            if ( $booking->all_day ) {
                $dtstart = 'DTSTART;VALUE=DATE:' . date( 'Ymd', strtotime( $booking->booking_date ) );
                $end_date = $booking->booking_date_end ?: $booking->booking_date;
                $dtend = 'DTEND;VALUE=DATE:' . date( 'Ymd', strtotime( $end_date . ' +1 day' ) );
            } else {
                $start_dt = $booking->booking_date . ' ' . $booking->start_time;
                $end_dt   = $booking->booking_date . ' ' . $booking->end_time;
                $dtstart  = 'DTSTART:' . gmdate( 'Ymd\THis\Z', strtotime( $start_dt ) );
                $dtend    = 'DTEND:' . gmdate( 'Ymd\THis\Z', strtotime( $end_dt ) );
            }

            $summary = self::escape( $booking->space . ' – ' . $booking->purpose );
            $uid     = $booking->ref . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
            $now     = gmdate( 'Ymd\THis\Z' );

            $ics .= "BEGIN:VEVENT\r\n";
            $ics .= "UID:{$uid}\r\n";
            $ics .= "DTSTAMP:{$now}\r\n";
            $ics .= "{$dtstart}\r\n";
            $ics .= "{$dtend}\r\n";
            $ics .= "SUMMARY:{$summary}\r\n";
            $ics .= "LOCATION:" . self::escape( $org['address'] ?: get_bloginfo( 'name' ) ) . "\r\n";
            $ics .= "STATUS:CONFIRMED\r\n";
            $ics .= "END:VEVENT\r\n";
        }

        $ics .= "END:VCALENDAR\r\n";
        return $ics;
    }

    /**
     * Escape text for iCal format.
     */
    private static function escape( $text ) {
        $text = str_replace( '\\', '\\\\', $text );
        $text = str_replace( ',', '\\,', $text );
        $text = str_replace( ';', '\\;', $text );
        $text = str_replace( "\n", '\\n', $text );
        $text = str_replace( "\r", '', $text );
        return $text;
    }
}
