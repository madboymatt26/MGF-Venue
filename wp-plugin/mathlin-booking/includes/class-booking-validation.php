<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Common invariants; campsite arrival/slot policy remains an additional layer. */
class MBS_Booking_Validation {
    public static function delivery_update( $booking, $update ) {
        // Moving a one-day reservation must not retain its previous end date.
        if ( isset( $update['booking_date'] ) && ! array_key_exists( 'booking_date_end', $update ) && ( empty( $booking->booking_date_end ) || $booking->booking_date_end === $booking->booking_date ) ) $update['booking_date_end'] = $update['booking_date'];
        foreach ( array('booking_date','booking_date_end','start_time','end_time','email','space','all_day') as $field ) {
            if ( array_key_exists( $field, $update ) && (string) $update[$field] !== (string) ( $booking->$field ?? '' ) ) { $update['access_sent']=0; $update['reminder_sent']=0; break; }
        }
        return $update;
    }
    public static function validate( $data, $allow_unknown_attendance = false ) {
        $from = $data['booking_date'] ?? '';
        $to = ! empty( $data['booking_date_end'] ) ? $data['booking_date_end'] : $from;
        if ( ! MBS_Campsite_Policy::valid_date( $from ) || ! MBS_Campsite_Policy::valid_date( $to ) || $to < $from ) return self::error( 'Choose valid booking dates.' );
        $first = new DateTimeImmutable( $from ); $last = new DateTimeImmutable( $to );
        if ( $first->diff( $last )->days > 366 ) return self::error( 'A single booking may span at most 366 days.' );
        if ( empty( $data['all_day'] ) ) {
            foreach ( array( 'start_time','end_time' ) as $field ) if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d(?::00)?$/D', (string) ( $data[$field] ?? '' ) ) ) return self::error( 'Choose valid start and end times.' );
            $continuous = MBS_Campsite_Policy::enabled() && ( ( $data['booking_span'] ?? '' ) === 'continuous' || MBS_Campsite_Policy::span( $data ) === 'continuous' ) && $to > $from;
            if ( ! $continuous && substr( $data['end_time'], 0, 5 ) <= substr( $data['start_time'], 0, 5 ) ) return self::error( 'End time must be after start time.' );
        }
        $info = MBS_Space_Selection::info( $data['space'] ?? '' );
        if ( ! $info ) return self::error( 'Choose a configured space.' );
        if ( ! is_scalar( $data['attendees'] ?? null ) ) return self::error( 'Enter a valid attendance count.' );
        $count = (string) $data['attendees'];
        // Legacy/internal reservations can have an explicitly unknown headcount (0).
        // Public requests still require a positive count; neither path bypasses capacity.
        if ( ! ctype_digit( $count ) || (int) $count < ( $allow_unknown_attendance ? 0 : 1 ) || ( ! empty( $info['capacity'] ) && (int) $count > (int) $info['capacity'] ) ) return self::error( 'Attendance must be a whole number within the selected space capacity.' );
        foreach ( array( 'name','organisation','email','phone','purpose' ) as $field ) if ( isset( $data[$field] ) && ( ! is_scalar( $data[$field] ) || strlen( (string) $data[$field] ) > 500 ) ) return self::error( 'A booking field is too long or invalid.' );
        foreach ( array( 'address','notes' ) as $field ) if ( isset( $data[$field] ) && ( ! is_scalar( $data[$field] ) || strlen( (string) $data[$field] ) > 10000 ) ) return self::error( 'Booking notes or address are too long.' );
        return true;
    }
    private static function error( $message ) { return new WP_Error( 'invalid_booking', $message, array( 'status' => 400 ) ); }
}
