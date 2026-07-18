<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Persistence and shaping for first-class recurring booking series.
 */
class MBS_Series {

    public static function get_all( $args = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_SERIES_TABLE;
        $status = sanitize_key( $args['status'] ?? '' );
        $search = sanitize_text_field( $args['search'] ?? '' );
        $limit = max( 1, min( 500, (int) ( $args['limit'] ?? 100 ) ) );
        $where = array( '1=1' );
        $params = array();
        if ( $status ) {
            $where[] = 'status = %s';
            $params[] = $status;
        }
        if ( $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = '(series_ref LIKE %s OR contact_name LIKE %s OR contact_organisation LIKE %s OR contact_email LIKE %s)';
            array_push( $params, $like, $like, $like, $like );
        }
        $sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC LIMIT %d';
        $params[] = $limit;
        return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    }

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
            'deposit_policy'       => 'none',
            'payment_method'       => $is_scout ? 'none' : ( MBS_Bookings::tier_is_offline( $tier ) ? 'offline_bacs' : 'online' ),
            'automatic_reminders'  => $is_scout ? 0 : 1,
            'invoice_lead_days'    => max( 0, (int) get_option( 'mbs_series_invoice_lead_days', 28 ) ),
            'payment_terms_days'   => max( 0, (int) get_option( 'mbs_payment_terms_days', 14 ) ),
            'billing_schedule_json'=> wp_json_encode( array() ),
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

    public static function occurrences( $series_ref, $include_archived = true ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        $archived = $include_archived ? '' : " AND status != 'archived'";
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE series_id = %s{$archived} ORDER BY booking_date ASC, start_time ASC",
            sanitize_text_field( $series_ref )
        ) );
    }

    public static function invoices( $series_ref ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_INVOICE_TABLE;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE series_ref = %s ORDER BY period_start ASC, created_at ASC",
            sanitize_text_field( $series_ref )
        ) );
    }

    /** Pause or resume invoice generation without rewriting occurrences. */
    public static function set_paused( $series_ref, $paused, $expected_status, $expected_version ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_SERIES_TABLE;
        $series = self::get( $series_ref );
        if ( ! $series ) return new WP_Error( 'series_not_found', 'Recurring series not found.' );
        if ( $series->status !== $expected_status || (int) $series->version !== (int) $expected_version ) {
            return new WP_Error( 'series_precondition_failed', 'The recurring series changed since it was loaded. Refresh and try again.' );
        }
        $target = $paused ? 'paused' : 'confirmed';
        if ( $series->status === $target ) return array( 'series' => $series, 'no_op' => true );
        if ( $paused && $series->status !== 'confirmed' ) return new WP_Error( 'invalid_series_transition', 'Only a confirmed series can be paused.' );
        if ( ! $paused && $series->status !== 'paused' ) return new WP_Error( 'invalid_series_transition', 'Only a paused series can be resumed.' );
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = %s, version = version + 1, updated_at = %s WHERE series_ref = %s AND status = %s AND version = %d",
            $target, current_time( 'mysql' ), sanitize_text_field( $series_ref ), $series->status, (int) $series->version
        ) );
        if ( $updated !== 1 ) return new WP_Error( 'series_precondition_failed', 'The recurring series changed since it was loaded. Refresh and try again.' );
        MBS_Audit_Log::log( $series_ref, $paused ? 'series_paused' : 'series_resumed', $paused ? 'Invoice generation paused; occurrences were unchanged.' : 'Invoice generation resumed; occurrences were unchanged.' );
        return array( 'series' => self::get( $series_ref ), 'no_op' => false );
    }

    /** Resolve how an occurrence participates in payment chasing. */
    public static function billing_treatment_for_booking( $booking ) {
        if ( ! empty( $booking->scout_use ) ) {
            return 'none';
        }
        if ( empty( $booking->series_id ) ) {
            return 'one_off';
        }
        $series = self::get( $booking->series_id );
        if ( ! $series ) {
            return 'legacy_per_occurrence';
        }
        return $series->billing_treatment ?: 'manual_consolidated';
    }

    /**
     * Idempotently approve a first-class series with optimistic preconditions.
     * Only pending occurrences transition; all financial/history statuses are
     * preserved exactly as they are.
     */
    public static function approve( $series_ref, $expected_status, $expected_version, $notify_hirer = true ) {
        global $wpdb;
        $series_table  = $wpdb->prefix . MBS_SERIES_TABLE;
        $booking_table = $wpdb->prefix . MBS_TABLE;
        $series_ref    = sanitize_text_field( $series_ref );

        $wpdb->query( 'START TRANSACTION' );
        $series = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$series_table} WHERE series_ref = %s FOR UPDATE",
            $series_ref
        ) );
        if ( ! $series ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'series_not_found', 'Recurring series not found.' );
        }

        // Safe retry after a completed transition: no email, audit or HA replay.
        if ( $series->status === 'confirmed' ) {
            $wpdb->query( 'ROLLBACK' );
            return array(
                'series'      => $series,
                'transitioned' => false,
                'no_op'       => true,
                'updated'     => 0,
                'email_sent'  => ! empty( $series->confirmation_sent_at ),
            );
        }
        if ( $series->status !== 'pending' ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'invalid_series_transition', 'Only a pending recurring series can be approved.' );
        }
        if ( $expected_status !== $series->status || (int) $expected_version !== (int) $series->version ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'series_precondition_failed', 'The recurring series changed since it was loaded. Refresh and try again.' );
        }

        $affected = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$booking_table} WHERE series_id = %s AND status = 'pending' FOR UPDATE",
            $series_ref
        ) );
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$booking_table} SET status = 'confirmed' WHERE series_id = %s AND status = 'pending'",
            $series_ref
        ) );
        if ( $updated === false ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'series_occurrence_update_failed', 'Could not confirm the pending occurrences.' );
        }

        $series_updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$series_table}
             SET status = 'confirmed', version = version + 1, updated_at = %s
             WHERE series_ref = %s AND status = 'pending' AND version = %d",
            current_time( 'mysql' ),
            $series_ref,
            (int) $expected_version
        ) );
        if ( $series_updated !== 1 ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'series_concurrent_update', 'The recurring series was updated by another request. Refresh and try again.' );
        }
        $wpdb->query( 'COMMIT' );

        foreach ( $affected as $booking ) {
            $booking->status = 'confirmed';
            MBS_HomeAssistant::notify( $booking );
            $wpdb->update( $booking_table, array( 'ha_notified' => 1 ), array( 'ref' => $booking->ref ) );
        }
        MBS_Audit_Log::log(
            $series_ref,
            'series_confirmed',
            'Approved recurring series; confirmed ' . (int) $updated . ' pending occurrence(s). Preserved all non-pending statuses.'
        );

        $fresh = self::get( $series_ref );
        $email_sent = false;
        if ( $notify_hirer && $fresh ) {
            $email_sent = MBS_Email::notify_series_confirmed( $fresh, self::active_occurrences( $series_ref ) );
            if ( $email_sent ) {
                $sent_at = current_time( 'mysql' );
                $wpdb->update(
                    $series_table,
                    array( 'confirmation_sent_at' => $sent_at ),
                    array( 'series_ref' => $series_ref, 'status' => 'confirmed' )
                );
                $fresh->confirmation_sent_at = $sent_at;
            }
        }

        return array(
            'series'       => $fresh,
            'transitioned' => true,
            'no_op'        => false,
            'updated'      => (int) $updated,
            'email_sent'   => $email_sent,
        );
    }

    /** Explicitly resend the consolidated approval email without a transition. */
    public static function resend_confirmation( $series_ref ) {
        global $wpdb;
        $series = self::get( $series_ref );
        if ( ! $series ) {
            return new WP_Error( 'series_not_found', 'Recurring series not found.' );
        }
        if ( $series->status !== 'confirmed' ) {
            return new WP_Error( 'series_not_confirmed', 'Only a confirmed recurring series has an approval email to resend.' );
        }

        $sent = MBS_Email::notify_series_confirmed( $series, self::active_occurrences( $series_ref ) );
        if ( $sent ) {
            $sent_at = current_time( 'mysql' );
            $wpdb->update(
                $wpdb->prefix . MBS_SERIES_TABLE,
                array( 'confirmation_sent_at' => $sent_at ),
                array( 'series_ref' => $series_ref, 'status' => 'confirmed' )
            );
        }
        MBS_Audit_Log::log( $series_ref, 'series_confirmation_resent', $sent ? 'Consolidated approval email resent.' : 'Consolidated approval email queued after immediate send failure.' );
        return array( 'sent' => $sent, 'queued' => ! $sent );
    }

    /** Safely cancel all occurrences or future occurrences only. */
    public static function cancel( $series_ref, $scope, $expected_status, $expected_version ) {
        global $wpdb;
        $series_table  = $wpdb->prefix . MBS_SERIES_TABLE;
        $booking_table = $wpdb->prefix . MBS_TABLE;
        $series_ref = sanitize_text_field( $series_ref );
        $scope = $scope === 'future' ? 'future' : 'all';

        $wpdb->query( 'START TRANSACTION' );
        $series = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$series_table} WHERE series_ref = %s FOR UPDATE",
            $series_ref
        ) );
        if ( ! $series ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'series_not_found', 'Recurring series not found.' );
        }
        if ( $series->status === 'cancelled' || ( $series->status === 'cancelled_future' && $scope === 'future' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return array( 'transitioned' => false, 'no_op' => true, 'cancelled' => 0, 'series' => $series );
        }
        if ( $expected_status !== $series->status || (int) $expected_version !== (int) $series->version ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'series_precondition_failed', 'The recurring series changed since it was loaded. Refresh and try again.' );
        }

        $date_sql = $scope === 'future' ? ' AND booking_date >= %s' : '';
        $select_sql = "SELECT * FROM {$booking_table} WHERE series_id = %s AND status NOT IN ('cancelled','archived'){$date_sql} FOR UPDATE";
        $update_sql = "UPDATE {$booking_table} SET status = 'cancelled', access_sent = 0 WHERE series_id = %s AND status NOT IN ('cancelled','archived'){$date_sql}";
        $params = array( $series_ref );
        if ( $scope === 'future' ) {
            $params[] = wp_date( 'Y-m-d' );
        }
        $affected = $wpdb->get_results( $wpdb->prepare( $select_sql, $params ) );
        $cancelled = $wpdb->query( $wpdb->prepare( $update_sql, $params ) );
        if ( $cancelled === false ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'series_cancel_failed', 'Could not cancel the selected occurrences.' );
        }
        $new_status = $scope === 'future' ? 'cancelled_future' : 'cancelled';
        $series_updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$series_table} SET status = %s, version = version + 1, updated_at = %s
             WHERE series_ref = %s AND status = %s AND version = %d",
            $new_status, current_time( 'mysql' ), $series_ref, $series->status, (int) $series->version
        ) );
        if ( $series_updated !== 1 ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'series_concurrent_update', 'The recurring series was updated by another request. Refresh and try again.' );
        }
        $wpdb->query( 'COMMIT' );

        foreach ( $affected as $booking ) {
            if ( ! empty( $booking->ha_notified ) ) {
                MBS_HomeAssistant::notify_cancelled( $booking );
            }
        }
        MBS_Audit_Log::log( $series_ref, 'series_cancelled', 'Cancelled ' . (int) $cancelled . ' ' . $scope . ' occurrence(s).' );
        $fresh = self::get( $series_ref );
        MBS_Email::notify_series_cancelled( $fresh, self::active_occurrences( $series_ref ) );
        return array(
            'transitioned' => true,
            'no_op'        => false,
            'cancelled'    => (int) $cancelled,
            'series'       => $fresh,
        );
    }

    public static function active_occurrences( $series_ref ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE series_id = %s AND status NOT IN ('cancelled','archived') ORDER BY booking_date ASC",
            sanitize_text_field( $series_ref )
        ) );
    }
}
