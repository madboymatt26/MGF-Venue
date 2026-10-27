<?php
if ( ! defined( 'ABSPATH' ) ) exit;
require_once __DIR__ . '/class-booking-intervals.php';

/** One local-time renderer; keeps existing event UIDs and public redaction. */
class MBS_ICal {
    public static function generate( $booking ) { return self::render( array( $booking ), true ); }
    public static function get_feed_url() { return rest_url( 'mathlin/v1/bookings/ical' ); }
    public static function generate_feed() { return self::render( self::public_rows( MBS_Booking_Intervals::approved() ), false ); }
    public static function campsite_calendar( $bookings ) { return self::render( $bookings, false, true ); }
    private static function render( $bookings, $private, $campsite = null ) {
        $campsite = $campsite === null ? class_exists( 'MBS_Campsite_Policy' ) && MBS_Campsite_Policy::enabled() : $campsite;
        $utc = new DateTimeZone( 'UTC' );
        $org = MBS_Email_Templates::get_org_settings();
        $lines = array( 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//MGF Venue//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH' );
        if ( ! $private ) $lines[] = 'X-WR-CALNAME:' . self::escape( ( $org['name'] ?? get_bloginfo( 'name' ) ) . ' Bookings' );
        foreach ( $bookings as $booking ) {
            foreach ( MBS_Booking_Intervals::intervals( $booking ) as $index => $interval ) {
                $suffix = $campsite || $index > 0 ? '-' . str_replace( '-', '', $interval['day'] ) : '';
                $lines[] = 'BEGIN:VEVENT';
                $lines[] = 'UID:' . self::escape( $booking->ref . $suffix . '@' . wp_parse_url( home_url(), PHP_URL_HOST ) );
                $lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' );
                $lines[] = $interval['all_day'] ? 'DTSTART;VALUE=DATE:' . $interval['start']->format( 'Ymd' ) : 'DTSTART:' . $interval['start']->setTimezone( $utc )->format( 'Ymd\THis\Z' );
                $lines[] = $interval['all_day'] ? 'DTEND;VALUE=DATE:' . $interval['end']->format( 'Ymd' ) : 'DTEND:' . $interval['end']->setTimezone( $utc )->format( 'Ymd\THis\Z' );
                $lines[] = 'SUMMARY:' . self::escape( $booking->space . ' – ' . $booking->purpose );
                $lines[] = 'LOCATION:' . self::escape( $org['address'] ?: get_bloginfo( 'name' ) );
                $status = $booking->status ?? 'confirmed';
                $lines[] = 'STATUS:' . ( in_array( $status, array( 'cancelled','archived' ), true ) ? 'CANCELLED' : ( $status === 'pending' ? 'TENTATIVE' : 'CONFIRMED' ) );
                if ( $private ) {
                    $description = 'Booking Ref: ' . $booking->ref . "\nSpace: " . $booking->space;
                    if(isset($booking->attendees))$description .= "\nAttendees: " . $booking->attendees;
                    $description .= "\nPurpose: " . $booking->purpose;
                    if ( ! empty( $booking->notes ) ) $description .= "\nNotes: " . $booking->notes;
                    if ( isset( $booking->amount ) ) $description .= "\nAmount: £" . number_format( $booking->amount, 2 );
                    $lines[] = 'DESCRIPTION:' . self::escape( $description );
                }
                $lines[] = 'END:VEVENT';
            }
        }
        $lines[] = 'END:VCALENDAR';
        return implode( "\r\n", array_map( array( __CLASS__, 'fold' ), $lines ) ) . "\r\n";
    }
    private static function public_rows( $rows ) {
        return array_map( static function( $row ) {
            $copy = clone $row;
            if ( empty( $copy->is_public ) ) $copy->purpose = 'Reserved';
            $copy->notes = '';
            $copy->ref = hash_hmac( 'sha256', $row->ref, wp_salt( 'auth' ) );
            return $copy;
        }, $rows );
    }
    private static function escape( $text ) {
        return str_replace( array( "\r", "\n", ',', ';' ), array( '', '\\n', '\\,', '\\;' ), str_replace( '\\', '\\\\', (string) $text ) );
    }
    /** RFC 5545 folding by octets, without breaking a UTF-8 character. */
    private static function fold( $line ) {
        $result = ''; $limit = 75;
        while ( strlen( $line ) > $limit ) {
            $cut = $limit;
            while ( $cut > 0 && ( ord( $line[$cut] ) & 0xC0 ) === 0x80 ) $cut--;
            $result .= substr( $line, 0, $cut ) . "\r\n ";
            $line = substr( $line, $cut ); $limit = 74;
        }
        return $result . $line;
    }
}
