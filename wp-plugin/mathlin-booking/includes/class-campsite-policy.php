<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Site-local, opt-in free campsite policy. Never inferred from a hostname or a posted email. */
class MBS_Campsite_Policy {
    public static function enabled() {
        return (bool) get_option( 'mbs_campsite_mode', false );
    }

    public static function can_book() {
        if ( ! self::enabled() ) return true;
        if ( ! is_user_logged_in() ) return false;
        if ( current_user_can( 'manage_options' ) || current_user_can( 'mbs_manage_bookings' ) ) return true;
        $user = wp_get_current_user();
        $roles = (array) get_option( 'mbs_campsite_roles', array() );
        if ( ! array_intersect( $roles, (array) $user->roles ) ) return false;
        // Ultimate Member approval must also pass when that plugin manages this account.
        $status = (string) get_user_meta( $user->ID, 'account_status', true );
        return $status === '' || $status === 'approved';
    }

    public static function access_error() {
        return new WP_Error( 'campsite_access_denied', 'Please sign in with an approved campsite booking account. Contact the booking coordinator if you need access.' );
    }

    public static function validate( $data, $trusted_admin = false ) {
        if ( ! self::enabled() ) return true;
        if ( ! $trusted_admin && ! get_option( 'mbs_campsite_booking_open', true ) ) return new WP_Error( 'campsite_staging', 'The replacement booking form is being prepared. Please use the existing booking page.' );
        if ( ! $trusted_admin && ! self::can_book() ) return self::access_error();
        // Approval is still required; free use is not permission to confirm a booking.
        if ( ! empty( $data['kitchen'] ) ) return new WP_Error( 'campsite_kitchen', 'Kitchen hire is not available in free campsite mode.' );
        if ( isset( $data['custom_amount'] ) && (float) $data['custom_amount'] !== 0.0 ) return new WP_Error( 'campsite_amount', 'Free campsite bookings cannot include a hire charge.' );
        if ( isset( $data['attendees'] ) ) {
            $space = class_exists( 'MBS_Space_Selection' ) ? MBS_Space_Selection::info( $data['space'] ?? '' ) : ( MBS_Bookings::get_spaces()[ $data['space'] ?? '' ] ?? array() );
            if ( ! $space ) return new WP_Error( 'campsite_space', 'Choose a valid area selection.' );
            if ( (int) $data['attendees'] < 1 || ( ! empty( $space['capacity'] ) && (int) $data['attendees'] > (int) $space['capacity'] ) ) return new WP_Error( 'campsite_capacity', 'Enter a positive attendance within the selected area capacity.' );
        }
        return true;
    }

    public static function slots() {
        return get_option( 'mbs_campsite_slots', array(
            array( 'label' => '09:00–12:00', 'start' => '09:00', 'end' => '12:00' ),
            array( 'label' => '12:00–15:00', 'start' => '12:00', 'end' => '15:00' ),
            array( 'label' => '15:00–18:00', 'start' => '15:00', 'end' => '18:00' ),
            array( 'label' => '18:00–21:00', 'start' => '18:00', 'end' => '21:00' ),
            array( 'label' => '19:00–22:00', 'start' => '19:00', 'end' => '22:00' ),
        ) );
    }

    public static function valid_date( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) ) return false;
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
        return $date && $date->format( 'Y-m-d' ) === $value;
    }

    /** Enforced for every occurrence, including API/backend creation. Exceptions require a trusted manager. */
    public static function validate_schedule( $data, $trusted_admin = false ) {
        if ( ! self::enabled() ) return true;
        $from = $data['booking_date'] ?? '';
        $to = ! empty( $data['booking_date_end'] ) ? $data['booking_date_end'] : $from;
        $span = $data['booking_span'] ?? 'daily';
        if ( ! in_array( $span, array( 'daily', 'continuous' ), true ) || ! self::valid_date( $from ) || ! self::valid_date( $to ) || $to < $from ) return new WP_Error( 'campsite_dates', 'Select valid arrival and departure dates.' );
        $start_date = new DateTimeImmutable( $from );
        $last_date = new DateTimeImmutable( $to );
        if ( $start_date->diff( $last_date )->days > 366 ) return new WP_Error( 'campsite_dates', 'Request no more than one year in a single booking.' );
        $exception = $trusted_admin && ! empty( $data['campsite_exception'] );
        if ( ! $exception ) {
            $earliest = ( new DateTimeImmutable( wp_date( 'Y-m-d' ) ) )->modify( '+' . max( 0, (int) get_option( 'mbs_min_notice_days', 14 ) ) . ' days' );
            if ( $start_date < $earliest ) return new WP_Error( 'campsite_notice', 'Campsite bookings require the configured advance notice. Contact the booking coordinator for an agreed exception.' );
        }
        $all_day = ! empty( $data['all_day'] );
        $start = substr( (string) ( $data['start_time'] ?? '' ), 0, 5 );
        $end = substr( (string) ( $data['end_time'] ?? '' ), 0, 5 );
        if ( ! $all_day ) {
            if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $start ) || ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $end ) ) return new WP_Error( 'campsite_times', 'Select valid arrival and departure times.' );
            if ( ( $span === 'daily' || $from === $to ) && $end <= $start ) return new WP_Error( 'campsite_times', 'Departure must be after arrival. For an overnight stay choose a later departure date and Continuous stay.' );
            if ( $span === 'daily' && ! $exception ) {
                $match = false;
                foreach ( self::slots() as $slot ) if ( $slot['start'] === $start && $slot['end'] === $end ) $match = true;
                if ( ! $match ) return new WP_Error( 'campsite_slot', 'Choose one of the configured day-session time slots, or contact the booking coordinator.' );
            }
        }
        if ( ! $exception ) {
            for ( $day = $start_date; $day <= $last_date; $day = $day->modify( '+1 day' ) ) {
                // A continuous camp only arrives once; do not treat each following morning as a new arrival.
                if ( $span === 'continuous' && $day > $start_date ) break;
                if ( (int) $day->format( 'N' ) <= 5 && ( $all_day || $start < '17:00' ) ) return new WP_Error( 'campsite_weekday', 'Weekday arrival is from 17:00. Earlier access requires an agreed exception through the booking coordinator.' );
            }
        }
        return true;
    }

    public static function span( $booking ) {
        $data = (array) $booking;
        $custom = json_decode( (string) ( $data['custom_fields'] ?? '' ), true );
        return ( $custom['_mbs_campsite_span'] ?? 'daily' ) === 'continuous' ? 'continuous' : 'daily';
    }

    /** Local wall-clock intervals, clipped to a calendar day; exclusive end supports midnight. */
    public static function interval( $booking, $day ) {
        $data = (array) $booking;
        if ( ! empty( $data['all_day'] ) ) return array( '00:00', '24:00' );
        $start = substr( (string) ( $data['start_time'] ?? '' ), 0, 5 );
        $end = substr( (string) ( $data['end_time'] ?? '' ), 0, 5 );
        if ( self::span( $booking ) === 'continuous' ) {
            if ( $day > $data['booking_date'] ) $start = '00:00';
            if ( $day < ( $data['booking_date_end'] ?: $data['booking_date'] ) ) $end = '24:00';
        }
        return array( $start, $end );
    }

    public static function request_interval( $data, $day ) {
        $data['custom_fields'] = json_encode( array( '_mbs_campsite_span' => $data['booking_span'] ?? 'daily' ) );
        $data['booking_date_end'] = $data['booking_date_end'] ?? $data['booking_date'];
        return self::interval( $data, $day );
    }

    /** Revalidate every occupied day of an amendment, including its departure date. */
    public static function validate_amendment( $booking, $update, $exception = false ) {
        if ( class_exists( 'MBS_Booking_Validation' ) ) {
            $preserve_unknown = isset( $booking->attendees ) && (int) $booking->attendees === 0 && ( ! array_key_exists( 'attendees', $update ) || (string) $update['attendees'] === (string) $booking->attendees );
            $common = MBS_Booking_Validation::validate( array_merge( (array) $booking, $update ), $preserve_unknown ); if ( is_wp_error( $common ) ) return $common;
        }
        if ( ! self::enabled() ) return true;
        $proposed = array_merge( (array) $booking, $update );
        if ( class_exists( 'MBS_Space_Selection' ) && $proposed['space'] !== $booking->space ) {
            $selection = MBS_Space_Selection::normalize( $proposed['space'] ); if ( is_wp_error( $selection ) ) return $selection;
            $proposed['space'] = $selection;
        }
        $proposed['booking_span'] = self::span( $booking );
        $proposed['campsite_exception'] = $exception;
        if ( class_exists( 'MBS_Space_Selection' ) && ( $proposed['space'] !== $booking->space || (string) ( $proposed['attendees'] ?? '' ) !== (string) ( $booking->attendees ?? '' ) ) ) {
            $info = MBS_Space_Selection::info( $proposed['space'] );
            if ( ! $info || (int) $proposed['attendees'] < 1 || ( ! empty( $info['capacity'] ) && (int) $proposed['attendees'] > $info['capacity'] ) ) return new WP_Error( 'campsite_capacity', 'Enter attendance within the selected area capacity.' );
        }
        $changed = false;
        foreach ( array( 'space', 'booking_date', 'booking_date_end', 'start_time', 'end_time', 'all_day' ) as $key ) if ( (string) ( $proposed[$key] ?? '' ) !== (string) ( $booking->$key ?? '' ) ) $changed = true;
        if ( ! $changed ) return true;
        $valid = self::validate_schedule( $proposed, true );
        if ( is_wp_error( $valid ) ) return $valid;
        $last = new DateTimeImmutable( $proposed['booking_date_end'] ?: $proposed['booking_date'] );
        for ( $day = new DateTimeImmutable( $proposed['booking_date'] ); $day <= $last; $day = $day->modify( '+1 day' ) ) {
            $date = $day->format( 'Y-m-d' ); $interval = self::request_interval( $proposed, $date );
            if ( MBS_Blocked_Dates::is_blocked( $date, $proposed['space'] ) || MBS_Bookings::check_conflicts( $proposed['space'], $date, $interval[0], $interval[1], ! empty( $proposed['all_day'] ), $booking->ref ) ) return new WP_Error( 'campsite_amendment_conflict', 'The proposed booking conflicts or is blocked on ' . $date . '. Nothing was changed.' );
        }
        return true;
    }

    /** Atomic future-session amendment; never mutates issued financial records. */
    public static function edit_series( $ref, $fields, $version, $notify = false, $exception = false ) {
        if ( class_exists( 'MBS_Space_Selection' ) ) return MBS_Space_Selection::locked( static function() use ( $ref, $fields, $version, $notify, $exception ) { return self::edit_series_locked( $ref, $fields, $version, $notify, $exception ); } );
        return self::edit_series_locked( $ref, $fields, $version, $notify, $exception );
    }

    private static function edit_series_locked( $ref, $fields, $version, $notify, $exception ) {
        global $wpdb;
        if ( ! self::enabled() || ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'mbs_manage_bookings' ) ) ) return self::access_error();
        $table = $wpdb->prefix . MBS_SERIES_TABLE;
        if ( $wpdb->query( 'START TRANSACTION' ) === false ) return new WP_Error( 'transaction_start_failed', 'Could not start schedule update.' );
        try {
            $series = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE series_ref=%s FOR UPDATE", $ref ) );
            if ( ! $series || (int) $series->version !== (int) $version || $series->billing_treatment !== 'none' || (float) $series->price_per_booking !== 0.0 ) throw new RuntimeException( 'Refresh and review the current no-charge series version before editing.' );
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . MBS_TABLE . " WHERE series_id=%s AND booking_date >= %s AND status IN ('pending','confirmed') ORDER BY booking_date FOR UPDATE", $ref, wp_date( 'Y-m-d' ) ) );
            if ( ! $rows ) throw new RuntimeException( 'There are no active future sessions to change.' );
            $changes = array();
            foreach ( $rows as $booking ) {
                if ( MBS_Bookings::has_financial_history( $booking->ref ) ) throw new RuntimeException( 'A session has financial history and cannot be edited in place.' );
                $proposed = (array) $booking;
                foreach ( array( 'space', 'start_time', 'end_time', 'purpose' ) as $field ) if ( isset( $fields[$field] ) && $fields[$field] !== '' ) $proposed[$field] = sanitize_text_field( $fields[$field] );
                if ( class_exists( 'MBS_Space_Selection' ) && $proposed['space'] !== $booking->space ) {
                    $selection = MBS_Space_Selection::normalize( $proposed['space'] ); if ( is_wp_error( $selection ) ) throw new RuntimeException( $selection->get_error_message() ); $proposed['space'] = $selection;
                }
                // Time inputs display minutes; do not discard legacy endpoint seconds on a text-only edit.
                foreach ( array( 'start_time', 'end_time' ) as $field ) if ( substr( (string) $proposed[$field], 0, 5 ) === substr( (string) $booking->$field, 0, 5 ) ) $proposed[$field] = $booking->$field;
                if ( class_exists( 'MBS_Space_Selection' ) ? ! MBS_Space_Selection::info( $proposed['space'] ) : ! isset( MBS_Bookings::get_spaces()[$proposed['space']] ) ) throw new RuntimeException( 'Unknown campsite area.' );
                if ( class_exists( 'MBS_Space_Selection' ) && $proposed['space'] !== $booking->space && (int) $booking->attendees > (int) MBS_Space_Selection::info( $proposed['space'] )['capacity'] ) throw new RuntimeException( 'Existing attendance exceeds the selected area capacity.' );
                $schedule_changed = $proposed['space'] !== $booking->space || substr( (string) $proposed['start_time'], 0, 5 ) !== substr( (string) $booking->start_time, 0, 5 ) || substr( (string) $proposed['end_time'], 0, 5 ) !== substr( (string) $booking->end_time, 0, 5 );
                if ( $schedule_changed ) {
                    $proposed['booking_span'] = self::span( $booking ); $proposed['campsite_exception'] = $exception;
                    $valid = self::validate_schedule( $proposed, true );
                    if ( is_wp_error( $valid ) ) throw new RuntimeException( $valid->get_error_message() );
                    if ( MBS_Blocked_Dates::is_blocked( $booking->booking_date, $proposed['space'] ) || MBS_Bookings::check_conflicts( $proposed['space'], $booking->booking_date, $booking->all_day ? null : $proposed['start_time'], $booking->all_day ? null : $proposed['end_time'], (bool) $booking->all_day, $booking->ref ) ) throw new RuntimeException( 'A session on ' . $booking->booking_date . ' conflicts or is blocked. Nothing was changed.' );
                }
                $changes[$booking->ref] = array_intersect_key( $proposed, array_flip( array( 'space', 'start_time', 'end_time', 'purpose' ) ) );
            }
            foreach ( $changes as $booking_ref => $update ) {
                $update = MBS_Booking_Validation::delivery_update( MBS_Bookings::get($booking_ref), $update );
                if ( class_exists( 'MBS_Space_Selection' ) ) MBS_Space_Selection::record_selection( $update['space'] );
                if ( $wpdb->update( $wpdb->prefix . MBS_TABLE, $update, array( 'ref' => $booking_ref ) ) === false ) throw new RuntimeException( 'Could not save every session.' );
            }
            $snapshot = reset( $changes ); $snapshot['version'] = (int) $series->version + 1; $snapshot['updated_at'] = current_time( 'mysql' );
            if ( $wpdb->update( $table, $snapshot, array( 'series_ref' => $ref, 'version' => (int) $version ) ) !== 1 ) throw new RuntimeException( 'The series changed concurrently. Nothing was saved.' );
            if ( $wpdb->query( 'COMMIT' ) === false ) throw new RuntimeException( 'Could not commit the schedule change.' );
        } catch ( Exception $error ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'campsite_edit_failed', $error->getMessage() ); }
        MBS_Audit_Log::log( $ref, 'campsite_series_edited', 'Updated ' . count( $changes ) . ' future sessions. Agreed rule exception: ' . ( $exception ? 'yes' : 'no' ) );
        if ( $notify ) MBS_Email::notify_series_changed( MBS_Series::get( $ref ), MBS_Series::active_occurrences( $ref ) );
        return array( 'updated' => count( $changes ), 'skipped' => array() );
    }

    /** Validate the whole patch before writing any policy options. Missing fields preserve saved values. */
    public static function save_settings( $data ) {
        $enabled = array_key_exists( 'campsite_mode', $data ) ? ! empty( $data['campsite_mode'] ) : self::enabled();
        $roles = (array) get_option( 'mbs_campsite_roles', array() );
        $slots = self::slots();
        if ( array_key_exists( 'campsite_slots', $data ) ) {
            $slots = array();
            foreach ( preg_split( '/\r?\n/', trim( (string) $data['campsite_slots'] ) ) as $line ) {
                $parts = array_map( 'trim', explode( '|', $line, 3 ) );
                if ( count( $parts ) !== 3 || ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $parts[0] ) || ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $parts[1] ) || $parts[1] <= $parts[0] || ! $parts[2] ) return new WP_Error( 'campsite_slots', 'Enter day-session slots as start|end|label, one per line.' );
                $slots[] = array( 'start' => $parts[0], 'end' => $parts[1], 'label' => sanitize_text_field( $parts[2] ) );
            }
            if ( ! $slots || count( $slots ) > 30 ) return new WP_Error( 'campsite_slots', 'Configure between 1 and 30 day-session slots.' );
        }
        if ( array_key_exists( 'campsite_roles', $data ) ) {
            $submitted = is_array( $data['campsite_roles'] ) ? $data['campsite_roles'] : explode( ',', (string) $data['campsite_roles'] );
            $roles = array_values( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'trim', $submitted ) ) ) ) );
            $known = wp_roles()->roles;
            foreach ( $roles as $role ) {
                if ( ! isset( $known[ $role ] ) ) return new WP_Error( 'campsite_role', 'Unknown campsite booking role: ' . $role );
            }
        }
        if ( $enabled && get_option( 'mbs_kitchen_enabled', 1 ) && ! isset( $data['kitchen_enabled'] ) ) {
            return new WP_Error( 'campsite_kitchen_enabled', 'Disable the kitchen add-on before enabling free campsite mode.' );
        }
        if ( $enabled && ! empty( $data['kitchen_enabled'] ) ) return new WP_Error( 'campsite_kitchen_enabled', 'Disable the kitchen add-on before enabling free campsite mode.' );
        update_option( 'mbs_campsite_mode', $enabled ? 1 : 0 );
        if ( array_key_exists( 'multi_space_enabled', $data ) ) update_option( 'mbs_multi_space_enabled', ! empty( $data['multi_space_enabled'] ) ? 1 : 0 );
        update_option( 'mbs_campsite_roles', $roles );
        if ( array_key_exists( 'campsite_slots', $data ) ) update_option( 'mbs_campsite_slots', $slots );
        return true;
    }
}
