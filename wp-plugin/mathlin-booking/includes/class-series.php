<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Persistence and shaping for first-class recurring booking series.
 */
class MBS_Series {

    public static function get( $series_ref ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_SERIES_TABLE;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE series_ref = %s",
            sanitize_text_field( $series_ref )
        ) );
    }

    /**
     * Persist the immutable request snapshot after occurrence creation.
     *
     * @return object|WP_Error Stored series row.
     */
    public static function create_from_request( $series_ref, $data, $repeat_until, $occurrences, $refs ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_SERIES_TABLE;

        $bookings = array();
        foreach ( $refs as $ref ) {
            $booking = MBS_Bookings::get( $ref );
            if ( $booking ) {
                $bookings[] = $booking;
            }
        }
        if ( count( $bookings ) !== count( $refs ) ) {
            return new WP_Error( 'series_snapshot_incomplete', 'Could not read every occurrence while creating the series record.' );
        }

        $counts = array(
            'accepted' => 0,
            'conflict' => 0,
            'blocked'  => 0,
            'error'    => 0,
        );
        $exceptions = array();
        foreach ( $occurrences as $occurrence ) {
            $status = $occurrence['status'] ?? 'error';
            if ( isset( $counts[ $status ] ) ) {
                $counts[ $status ]++;
            }
            if ( $status !== 'accepted' ) {
                $exceptions[] = array(
                    'date'    => sanitize_text_field( $occurrence['date'] ?? '' ),
                    'status'  => sanitize_key( $status ),
                    'message' => sanitize_text_field( $occurrence['message'] ?? '' ),
                );
            }
        }

        $first = reset( $bookings );
        $estimated_total = 0.0;
        foreach ( $bookings as $booking ) {
            $estimated_total += (float) $booking->amount;
        }

        $is_scout = ! empty( $first->scout_use );
        $tier = MBS_Bookings::get_booking_tier( $first );
        $terms_text = (string) get_option( 'mbs_terms_text', MBS_Bookings::get_default_terms() );
        $accepted_terms = ! empty( $data['accept_terms'] );
        $now = current_time( 'mysql' );

        $schedule = array(
            'frequency'    => 'weekly',
            'interval'     => 1,
            'start_date'   => sanitize_text_field( $data['booking_date'] ),
            'repeat_until' => sanitize_text_field( $repeat_until ),
            'all_day'      => ! empty( $data['all_day'] ),
            'start_time'   => sanitize_text_field( $data['start_time'] ?? '' ),
            'end_time'     => sanitize_text_field( $data['end_time'] ?? '' ),
        );

        $insert = array(
            'series_ref'           => sanitize_text_field( $series_ref ),
            'status'               => 'pending',
            'version'              => 1,
            'contact_name'         => sanitize_text_field( $data['name'] ?? '' ),
            'contact_organisation' => sanitize_text_field( $data['organisation'] ?? '' ),
            'contact_email'        => sanitize_email( $data['email'] ?? '' ),
            'contact_phone'        => sanitize_text_field( $data['phone'] ?? '' ),
            'contact_address'      => sanitize_textarea_field( $data['address'] ?? '' ),
            'space'                => sanitize_text_field( $first->space ),
            'kitchen'              => ! empty( $first->kitchen ) ? 1 : 0,
            'all_day'              => ! empty( $first->all_day ) ? 1 : 0,
            'scout_use'            => $is_scout ? 1 : 0,
            'pricing_tier'         => sanitize_key( $tier ),
            'start_time'           => ! empty( $first->start_time ) ? $first->start_time : null,
            'end_time'             => ! empty( $first->end_time ) ? $first->end_time : null,
            'attendees'            => absint( $first->attendees ),
            'purpose'              => sanitize_text_field( $first->purpose ),
            'notes'                => sanitize_textarea_field( $first->notes ),
            'start_date'           => sanitize_text_field( $data['booking_date'] ),
            'repeat_until'         => sanitize_text_field( $repeat_until ),
            'recurrence_rule'      => 'FREQ=WEEKLY;INTERVAL=1',
            'schedule_json'        => wp_json_encode( $schedule ),
            'price_per_booking'    => (float) $first->amount,
            'estimated_total'      => round( $estimated_total, 2 ),
            'requested_count'      => count( $occurrences ),
            'accepted_count'       => $counts['accepted'],
            'conflict_count'       => $counts['conflict'],
            'blocked_count'        => $counts['blocked'],
            'error_count'          => $counts['error'],
            'exceptions_json'      => wp_json_encode( $exceptions ),
            'billing_mode'         => $is_scout ? 'none' : 'monthly',
            'billing_treatment'    => $is_scout ? 'none' : 'manual_consolidated',
            'payment_method'       => $is_scout ? 'none' : ( MBS_Bookings::tier_is_offline( $tier ) ? 'offline_bacs' : 'online' ),
            'automatic_reminders'  => $is_scout ? 0 : 1,
            'terms_hash'           => hash( 'sha256', $terms_text ),
            'terms_accepted_at'    => $accepted_terms ? $now : null,
            'created_at'           => $now,
            'updated_at'           => $now,
        );

        if ( $wpdb->insert( $table, $insert ) === false ) {
            return new WP_Error( 'series_db_error', 'Could not save the recurring-series record.' );
        }

        $stored = self::get( $series_ref );
        if ( ! $stored ) {
            return new WP_Error( 'series_verify_failed', 'The recurring-series record could not be verified after saving.' );
        }
        return $stored;
    }

    public static function exceptions( $series ) {
        $decoded = json_decode( (string) ( $series->exceptions_json ?? '' ), true );
        return is_array( $decoded ) ? $decoded : array();
    }
}
