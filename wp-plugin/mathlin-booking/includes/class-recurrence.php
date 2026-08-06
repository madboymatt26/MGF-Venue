<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Calendar-safe recurrence rules shared by public, admin, REST and MCP flows.
 *
 * This class deliberately deals in local calendar dates, not timestamps. A
 * weekly booking must stay on the same local weekday across GMT/BST changes.
 */
class MBS_Recurrence {

    const MAX_OCCURRENCES = 53;

    /**
     * Generate an inclusive list of weekly occurrence dates.
     *
     * @param array  $booking      Booking fields containing booking_date and,
     *                            optionally, booking_date_end.
     * @param string $repeat_until Inclusive final date (Y-m-d).
     * @return array|WP_Error
     */
    public static function weekly_dates( $booking, $repeat_until ) {
        $start = self::parse_date( $booking['booking_date'] ?? '', 'booking date' );
        if ( is_wp_error( $start ) ) {
            return $start;
        }

        $booking_end_value = ! empty( $booking['booking_date_end'] )
            ? $booking['booking_date_end']
            : $booking['booking_date'];
        $booking_end = self::parse_date( $booking_end_value, 'booking end date' );
        if ( is_wp_error( $booking_end ) ) {
            return $booking_end;
        }
        if ( $booking_end->format( 'Y-m-d' ) !== $start->format( 'Y-m-d' ) ) {
            return new WP_Error(
                'recurring_multi_day',
                'Recurring requests must be for a single-day booking. Please submit multi-day hires separately.'
            );
        }

        if ( trim( (string) $repeat_until ) === '' ) {
            return new WP_Error( 'repeat_until_required', 'A repeat-until date is required for a recurring request.' );
        }
        $until = self::parse_date( $repeat_until, 'repeat-until date' );
        if ( is_wp_error( $until ) ) {
            return $until;
        }
        if ( $until < $start ) {
            return new WP_Error( 'invalid_range', 'Repeat-until date must be on or after the booking date.' );
        }

        $maximum_until = $start->modify( '+1 year' );
        if ( $until > $maximum_until ) {
            return new WP_Error(
                'recurrence_too_long',
                'Recurring requests may cover no more than one calendar year from the first booking date.'
            );
        }

        $dates   = array();
        $current = $start;
        while ( $current <= $until ) {
            $dates[] = $current->format( 'Y-m-d' );
            if ( count( $dates ) > self::MAX_OCCURRENCES ) {
                return new WP_Error(
                    'too_many_occurrences',
                    sprintf( 'Recurring requests may contain no more than %d bookings.', self::MAX_OCCURRENCES )
                );
            }
            $current = $current->modify( '+7 days' );
        }

        return $dates;
    }

    /**
     * Parse a real Y-m-d date in the configured WordPress timezone.
     *
     * @return DateTimeImmutable|WP_Error
     */
    private static function parse_date( $value, $label ) {
        $value = trim( (string) $value );
        $date  = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
        $errors = DateTimeImmutable::getLastErrors();
        $invalid = ! $date
            || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) )
            || ( $date && $date->format( 'Y-m-d' ) !== $value );

        if ( $invalid ) {
            return new WP_Error( 'invalid_date', sprintf( 'Please provide a real %s in YYYY-MM-DD format.', $label ) );
        }
        return $date;
    }
}
