<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Local-time occupancy read model shared by exports, integrations and reports. */
class MBS_Booking_Intervals {
    public static function intervals( $booking, $zone = null, $max_days = 366 ) {
        $b = (object) $booking;
        $zone = $zone ?: wp_timezone();
        $first = self::date( $b->booking_date, $zone );
        $last = self::date( empty( $b->booking_date_end ) ? $b->booking_date : $b->booking_date_end, $zone );
        if ( $last < $first || $first->diff( $last )->days > max(1,min(730,(int)$max_days)) ) throw new InvalidArgumentException( 'Invalid booking date range.' );
        $meta = is_string($b->custom_fields??null)?json_decode($b->custom_fields,true):(array)($b->custom_fields??array());
        $continuous = ($meta['_mbs_campsite_span']??'')==='continuous' || (class_exists( 'MBS_Campsite_Policy' ) && MBS_Campsite_Policy::span( $b ) === 'continuous');
        // Match the existing venue charging/creation rule: an overnight slot
        // with the immediately following end date is ONE stay, not two nights.
        $overnight = empty($b->all_day) && strcmp((string)$b->end_time,(string)$b->start_time)<=0;
        $bridge = ! $continuous && $overnight && $first->diff($last)->days === 1;
        $out = array();
        for ( $day = $first; $day <= $last; $day = $day->modify( '+1 day' ) ) {
            $end_day = $continuous || $bridge || ! empty( $b->all_day ) ? $last : $day;
            $start = ! empty( $b->all_day ) ? $day : self::wall_time( $day, $b->start_time );
            $end = ! empty( $b->all_day ) ? $end_day->modify( '+1 day' ) : self::wall_time( $end_day, $b->end_time );
            if ( ! $continuous && ! $bridge && $overnight ) $end = self::wall_time($end_day->modify('+1 day'),$b->end_time);
            if ( $end <= $start ) throw new InvalidArgumentException( 'Booking end must be after its start.' );
            $out[] = array( 'start' => $start, 'end' => $end, 'day' => $day->format( 'Y-m-d' ), 'all_day' => ! empty( $b->all_day ) );
            if ( $continuous || $bridge || ! empty( $b->all_day ) ) break;
        }
        return $out;
    }
    private static function date( $value, $zone ) {
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $value, $zone );
        if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) throw new InvalidArgumentException( 'Invalid booking date.' );
        return $date;
    }
    private static function wall_time( $day, $time ) {
        if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', (string) $time ) ) throw new InvalidArgumentException( 'Invalid booking time.' );
        $value = substr( $time, 0, 5 );
        $result = $day->setTime( (int) substr( $value, 0, 2 ), (int) substr( $value, 3, 2 ), (int) substr( $time, 6, 2 ) );
        if ( $result->format( 'H:i' ) !== $value ) throw new InvalidArgumentException( 'Booking time falls in a clock-change gap.' );
        return $result;
    }
    /** Include ongoing approved stays, without silently truncating a status. */
    public static function approved( $through = '' ) {
        $rows = array();
        foreach ( array( 'confirmed', 'deposit_paid', 'paid' ) as $status ) {
            for ( $offset = 0; ; $offset += 200 ) {
                $page = MBS_Bookings::get_all( array( 'status' => $status, 'date_scope' => 'current', 'date_to' => $through, 'orderby' => 'id', 'order' => 'ASC', 'limit' => 200, 'offset' => $offset ) );
                if ( ! is_array( $page ) ) throw new RuntimeException( 'Booking occupancy could not be read completely.' );
                foreach ( $page as $row ) $rows[$row->ref] = $row;
                if ( count( $page ) < 200 ) break;
            }
        }
        uasort( $rows, static function( $a, $b ) { return strcmp( $a->booking_date . ' ' . $a->start_time . ' ' . $a->ref, $b->booking_date . ' ' . $b->start_time . ' ' . $b->ref ); } );
        return array_values( $rows );
    }
}
