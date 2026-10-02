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

    /** Generate the actual requested dates, using local calendar arithmetic. */
    public static function dates( $booking, $repeat_until ) {
        if ( ! isset( $booking['recurrence_pattern'] ) && ! in_array( (string) ( $booking['recurrence_interval'] ?? 1 ), array( '1', '2' ), true ) ) {
            return new WP_Error( 'invalid_recurrence_interval', 'Repeat interval must be weekly or every two weeks.' );
        }
        $pattern = $booking['recurrence_pattern'] ?? ( (int) ( $booking['recurrence_interval'] ?? 1 ) === 2 ? 'fortnightly' : 'weekly' );
        if ( ! in_array( $pattern, array( 'daily', 'weekly', 'fortnightly', 'monthly_date', 'monthly_weekday', 'selected' ), true ) ) {
            return new WP_Error( 'invalid_recurrence_pattern', 'Please choose a valid repeat pattern.' );
        }
        // Reuse strict date/range and single-day validation before generating.
        $validated = self::weekly_dates( $booking, $repeat_until );
        if ( is_wp_error( $validated ) ) return $validated;
        $start = self::parse_date( $booking['booking_date'], 'booking date' );
        $until = self::parse_date( $repeat_until, 'repeat-until date' );
        if ( $pattern === 'weekly' || $pattern === 'fortnightly' ) {
            return self::weekly_dates( $booking, $repeat_until, $pattern === 'fortnightly' ? 2 : 1 );
        }
        if ( $pattern === 'selected' ) {
            $values = $booking['recurrence_dates'] ?? '';
            $values = is_array( $values ) ? $values : preg_split( '/[\s,]+/', trim( (string) $values ), -1, PREG_SPLIT_NO_EMPTY );
            $dates = array( $start->format( 'Y-m-d' ) );
            foreach ( $values as $value ) {
                $date = self::parse_date( $value, 'selected date' );
                if ( is_wp_error( $date ) ) return $date;
                if ( $date < $start || $date > $until ) return new WP_Error( 'invalid_selected_range', 'Selected dates must be between the start date and repeat-until date.' );
                $dates[] = $date->format( 'Y-m-d' );
            }
            $dates = array_values( array_unique( $dates ) );
            sort( $dates );
            if ( count( $dates ) > 367 ) return new WP_Error( 'too_many_occurrences', 'Choose no more than 367 dates.' );
            return $dates;
        }
        $dates = array();
        if ( $pattern === 'daily' ) {
            for ( $date = $start; $date <= $until; $date = $date->modify( '+1 day' ) ) $dates[] = $date->format( 'Y-m-d' );
            return $dates;
        }
        $day = (int) $start->format( 'j' );
        $weekday = (int) $start->format( 'N' );
        $ordinal = (int) ceil( $day / 7 );
        for ( $month = $start->modify( 'first day of this month' ); $month <= $until; $month = $month->modify( '+1 month' ) ) {
            if ( $pattern === 'monthly_date' ) {
                // Skip months without this date; never silently shift the hire.
                if ( $day > (int) $month->format( 't' ) ) continue;
                $date = $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'm' ), $day );
            } else {
                $offset = ( $weekday - (int) $month->format( 'N' ) + 7 ) % 7 + 7 * ( $ordinal - 1 );
                $date = $month->modify( '+' . $offset . ' days' );
                if ( $date->format( 'm' ) !== $month->format( 'm' ) ) continue;
            }
            if ( $date >= $start && $date <= $until ) $dates[] = $date->format( 'Y-m-d' );
        }
        return $dates;
    }

    public static function rule( $pattern ) {
        $rules = array( 'daily' => 'FREQ=DAILY;INTERVAL=1', 'weekly' => 'FREQ=WEEKLY;INTERVAL=1', 'fortnightly' => 'FREQ=WEEKLY;INTERVAL=2', 'monthly_date' => 'FREQ=MONTHLY;MODE=DATE', 'monthly_weekday' => 'FREQ=MONTHLY;MODE=WEEKDAY', 'selected' => 'RDATE' );
        return $rules[ $pattern ] ?? '';
    }

    /**
     * Generate an inclusive list of weekly or fortnightly occurrence dates.
     *
     * @param array  $booking      Booking fields containing booking_date and,
     *                            optionally, booking_date_end.
     * @param string $repeat_until Inclusive final date (Y-m-d).
     * @param int    $interval_weeks Number of weeks between occurrences (1 or 2).
     * @return array|WP_Error
     */
    public static function weekly_dates( $booking, $repeat_until, $interval_weeks = 1 ) {
        $interval_weeks = absint( $interval_weeks );
        if ( ! in_array( $interval_weeks, array( 1, 2 ), true ) ) {
            return new WP_Error( 'invalid_recurrence_interval', 'Repeat interval must be weekly or every two weeks.' );
        }
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
            $current = $current->modify( '+' . $interval_weeks . ' weeks' );
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
