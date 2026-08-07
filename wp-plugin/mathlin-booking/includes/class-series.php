<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Persistence and shaping for first-class recurring booking series.
 */
class MBS_Series {

    /** Register pre-existing series_id groups without inventing missing history. */
    public static function register_legacy_groups() {
        global $wpdb;
        $booking_table = $wpdb->prefix . MBS_TABLE;
        $series_table = $wpdb->prefix . MBS_SERIES_TABLE;
        $refs = $wpdb->get_col(
            "SELECT DISTINCT b.series_id FROM {$booking_table} b
             LEFT JOIN {$series_table} s ON s.series_ref = b.series_id
             WHERE b.series_id IS NOT NULL AND b.series_id != '' AND s.id IS NULL
             ORDER BY b.series_id ASC"
        );
        if ( $refs === null ) return new WP_Error( 'legacy_series_scan_failed', 'Could not scan legacy recurring series.' );
        $registered = 0;
        foreach ( $refs as $series_ref ) {
            $bookings = MBS_Bookings::get_series( $series_ref );
            if ( ! $bookings ) continue;
            $first = reset( $bookings );
            $last = end( $bookings );
            $statuses = array_map( static function ( $booking ) { return $booking->status; }, $bookings );
            $has_active = (bool) array_intersect( $statuses, array( 'confirmed', 'deposit_paid', 'paid' ) );
            $status = $has_active ? 'confirmed' : ( in_array( 'pending', $statuses, true ) ? 'pending' : 'cancelled' );
            $estimated_total = '0.00';
            $minor_total = 0;
            foreach ( $bookings as $booking ) {
                $minor = MBS_Money::from_decimal_string( (string) $booking->amount );
                if ( ! is_wp_error( $minor ) ) $minor_total += $minor;
            }
            $estimated_total = MBS_Money::decimal( $minor_total );
            $tier = MBS_Bookings::get_booking_tier( $first );
            $is_scout = ! empty( $first->scout_use );
            $schedule = array(
                'frequency' => 'weekly', 'interval' => 1, 'start_date' => $first->booking_date,
                'repeat_until' => $last->booking_date, 'all_day' => (bool) $first->all_day,
                'start_time' => $first->start_time, 'end_time' => $first->end_time,
                'source' => 'legacy_registration',
            );
            $now = current_time( 'mysql' );
            if ( $wpdb->query( 'START TRANSACTION' ) === false ) return new WP_Error( 'transaction_start_failed', 'Could not start legacy-series registration.' );
            $inserted = $wpdb->insert( $series_table, array(
                'series_ref' => sanitize_text_field( $series_ref ), 'status' => $status, 'version' => 1,
                'contact_name' => sanitize_text_field( $first->name ), 'contact_organisation' => sanitize_text_field( $first->organisation ),
                'contact_email' => sanitize_email( $first->email ), 'contact_phone' => sanitize_text_field( $first->phone ),
                'contact_address' => sanitize_textarea_field( $first->address ), 'space' => sanitize_text_field( $first->space ),
                'kitchen' => (int) $first->kitchen, 'all_day' => (int) $first->all_day, 'scout_use' => $is_scout ? 1 : 0,
                'pricing_tier' => sanitize_key( $tier ), 'start_time' => $first->start_time ?: null, 'end_time' => $first->end_time ?: null,
                'attendees' => (int) $first->attendees, 'purpose' => sanitize_text_field( $first->purpose ), 'notes' => '',
                'start_date' => $first->booking_date, 'repeat_until' => $last->booking_date,
                'recurrence_rule' => 'FREQ=WEEKLY;INTERVAL=1', 'schedule_json' => wp_json_encode( $schedule ),
                'price_per_booking' => (string) $first->amount, 'estimated_total' => $estimated_total,
                'requested_count' => count( $bookings ), 'accepted_count' => count( $bookings ),
                'exceptions_json' => wp_json_encode( array() ), 'billing_mode' => $is_scout ? 'none' : 'legacy_per_occurrence',
                'billing_treatment' => $is_scout ? 'none' : 'legacy_per_occurrence',
                'deposit_policy' => $is_scout ? 'none' : 'legacy_per_occurrence',
                'payment_method' => $is_scout ? 'none' : ( MBS_Bookings::tier_is_offline( $tier ) ? 'offline_bacs' : 'online' ),
                'automatic_reminders' => $is_scout ? 0 : 1, 'invoice_lead_days' => 28, 'payment_terms_days' => 14,
                'billing_schedule_json' => wp_json_encode( array() ), 'terms_hash' => null, 'terms_accepted_at' => null,
                'confirmation_sent_at' => null, 'metadata_incomplete' => 1, 'adopted_at' => null,
                'adopted_by' => null, 'adoption_state' => 'eligible', 'adoption_version' => null,
                'created_at' => $first->created_at ?: $now, 'updated_at' => $now,
            ) );
            if ( $inserted === false ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'legacy_series_registration_failed', 'Could not register legacy series ' . $series_ref . '.' ); }
            $excluded = MBS_Database::backfill_legacy_financial_history( $series_ref );
            if ( is_wp_error( $excluded ) ) { $wpdb->query( 'ROLLBACK' ); return $excluded; }
            if ( $wpdb->query( 'COMMIT' ) === false ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'transaction_commit_failed', 'Could not commit legacy-series registration.' ); }
            MBS_Audit_Log::log( $series_ref, 'legacy_series_registered', 'Registered existing occurrence group as legacy per-occurrence billing. Missing terms, skipped dates and prior billing intent were not inferred.', 0 );
            $registered++;
        }
        return array( 'registered' => $registered );
    }

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
            'billing_treatment'    => $is_scout ? 'none' : 'invoice_managed',
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

    /** Safely extend a first-class weekly series within its original one-year window. */
    public static function extend( $series_ref, $new_repeat_until, $expected_version, $notify_hirer = false ) {
        global $wpdb;
        $series_table = $wpdb->prefix . MBS_SERIES_TABLE;
        $booking_table = $wpdb->prefix . MBS_TABLE;
        $series_ref = sanitize_text_field( $series_ref );
        $seed = self::get( $series_ref );
        if ( ! $seed ) return new WP_Error( 'series_not_found', 'Recurring series not found.' );
        $dates = MBS_Recurrence::weekly_dates( array( 'booking_date' => $seed->start_date, 'booking_date_end' => $seed->start_date ), $new_repeat_until );
        if ( is_wp_error( $dates ) ) return $dates;

        if ( $wpdb->query( 'START TRANSACTION' ) === false ) return new WP_Error( 'transaction_start_failed', 'Could not start the series-extension transaction.' );
        $series = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$series_table} WHERE series_ref = %s FOR UPDATE", $series_ref ) );
        if ( ! $series ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'series_not_found', 'Recurring series not found.' ); }
        if ( (int) $series->version !== (int) $expected_version ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'series_precondition_failed', 'The recurring series changed since it was loaded.' ); }
        if ( in_array( $series->status, array( 'cancelled', 'cancelled_future' ), true ) ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'series_not_extendable', 'A cancelled series cannot be extended.' ); }
        if ( $new_repeat_until <= $series->repeat_until ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'no_extension', 'Choose a repeat-until date after the current series end.' ); }
        $new_dates = array_values( array_filter( $dates, static function ( $date ) use ( $series ) { return $date > $series->repeat_until; } ) );
        $template = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$booking_table} WHERE series_id = %s ORDER BY booking_date DESC, id DESC LIMIT 1", $series_ref ) );
        if ( ! $template ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'series_template_missing', 'No occurrence is available to extend this series.' ); }
        $status = in_array( $series->status, array( 'confirmed', 'paused' ), true ) ? 'confirmed' : 'pending';
        $created = array(); $exceptions = self::exceptions( $series ); $new_blocked = 0; $new_conflicts = 0;
        foreach ( $new_dates as $date ) {
            $wpdb->query( $wpdb->prepare( "SELECT id FROM {$booking_table} WHERE space = %s AND booking_date = %s AND status NOT IN ('cancelled','archived') FOR UPDATE", $series->space, $date ) );
            $conflicts = MBS_Bookings::check_conflicts( $series->space, $date, $series->all_day ? null : $series->start_time, $series->all_day ? null : $series->end_time, (bool) $series->all_day );
            $blocked = MBS_Blocked_Dates::is_blocked( $date, $series->space );
            if ( $blocked || $conflicts ) {
                $exceptions[] = array( 'date' => $date, 'status' => $blocked ? 'blocked' : 'conflict', 'message' => $blocked && $blocked->reason ? sanitize_text_field( $blocked->reason ) : 'The requested time is unavailable.' );
                if ( $blocked ) $new_blocked++; else $new_conflicts++;
                continue;
            }
            $ref = MBS_Bookings::generate_ref();
            $inserted = $wpdb->insert( $booking_table, array(
                'ref' => $ref, 'status' => $status, 'name' => $series->contact_name, 'organisation' => $series->contact_organisation,
                'email' => $series->contact_email, 'phone' => $series->contact_phone, 'address' => $series->contact_address,
                'space' => $series->space, 'kitchen' => (int) $series->kitchen, 'booking_date' => $date, 'booking_date_end' => $date,
                'all_day' => (int) $series->all_day, 'scout_use' => (int) $series->scout_use, 'pricing_tier' => $series->pricing_tier,
                'start_time' => $series->all_day ? null : $series->start_time, 'end_time' => $series->all_day ? null : $series->end_time,
                'attendees' => (int) $series->attendees, 'purpose' => $series->purpose, 'notes' => $template->notes,
                'amount' => (string) $series->price_per_booking, 'amount_paid' => 0, 'deposit_paid' => 0, 'invoice_number' => '',
                'series_id' => $series_ref, 'user_id' => (int) $template->user_id, 'custom_fields' => $template->custom_fields,
                'is_public' => (int) $template->is_public, 'modification_token' => wp_generate_password( 32, false ),
            ) );
            if ( $inserted === false ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'series_extension_failed', 'Could not create an extended occurrence; no extension was saved.' ); }
            $verified = $wpdb->get_var( $wpdb->prepare( "SELECT series_id FROM {$booking_table} WHERE ref = %s", $ref ) );
            if ( $verified !== $series_ref ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'series_extension_link_failed', 'Could not verify an extended occurrence; no extension was saved.' ); }
            $created[] = $ref;
        }
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$series_table} SET repeat_until = %s, requested_count = requested_count + %d, accepted_count = accepted_count + %d,
             conflict_count = conflict_count + %d, blocked_count = blocked_count + %d, exceptions_json = %s, version = version + 1, updated_at = %s
             WHERE series_ref = %s AND version = %d",
            $new_repeat_until, count( $new_dates ), count( $created ), $new_conflicts, $new_blocked, wp_json_encode( $exceptions ), current_time( 'mysql' ), $series_ref, (int) $expected_version
        ) );
        if ( $updated !== 1 ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'series_precondition_failed', 'The recurring series changed during extension; no extension was saved.' ); }
        if ( $wpdb->query( 'COMMIT' ) === false ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'transaction_commit_failed', 'Could not commit the series extension.' ); }
        foreach ( $created as $ref ) {
            $booking = MBS_Bookings::get( $ref );
            if ( $booking && $status === 'confirmed' ) { MBS_HomeAssistant::notify( $booking ); $wpdb->update( $booking_table, array( 'ha_notified' => 1 ), array( 'ref' => $ref ) ); }
        }
        $fresh = self::get( $series_ref );
        MBS_Audit_Log::log( $series_ref, 'series_extended', 'Extended request to ' . $new_repeat_until . '; created ' . count( $created ) . ' occurrence(s), skipped ' . ( count( $new_dates ) - count( $created ) ) . '.' );
        if ( $notify_hirer ) MBS_Email::notify_series_changed( $fresh, self::active_occurrences( $series_ref ) );
        return array( 'series' => $fresh, 'created' => $created, 'skipped' => count( $new_dates ) - count( $created ) );
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
    public static function approve( $series_ref, $expected_status, $expected_version, $notify_hirer = true, $billing_config = null ) {
        global $wpdb;
        $series_table  = $wpdb->prefix . MBS_SERIES_TABLE;
        $booking_table = $wpdb->prefix . MBS_TABLE;
        $series_ref    = sanitize_text_field( $series_ref );

        // Pre-flight: check if this is a chargeable series that requires reviewed billing
        $preflight_series = self::get( $series_ref );
        if ( $preflight_series && $preflight_series->status === 'pending' ) {
            // Only genuine Scout/internal-use series may bypass the Approval_Service.
            // Every normal pending series — including one being set to no-charge by an
            // administrator — must go through Approval_Service for atomic billing save.
            $is_genuine_scout = (bool) $preflight_series->scout_use;

            if ( ! $is_genuine_scout ) {
                // If inline billing_config provided (MCP/REST/admin), delegate to Approval_Service
                if ( $billing_config !== null ) {
                    return MBS_Series_Approval_Service::approve_with_billing(
                        $series_ref, $billing_config, $expected_version, $notify_hirer
                    );
                }

                // Otherwise, require a persisted billing review
                $has_review = ! empty( $preflight_series->billing_reviewed_at )
                    && (int) ( $preflight_series->billing_reviewed_version ?? 0 ) === (int) $preflight_series->version;

                if ( ! $has_review ) {
                    return new WP_Error(
                        'billing_configuration_required',
                        'A non-Scout series requires explicit billing configuration review before approval. Use the review-and-approve workflow or provide billing_config.'
                    );
                }

                // Has persisted review — delegate to Approval_Service with current config
                $current_config = array(
                    'billing_mode'      => $preflight_series->billing_mode,
                    'billing_treatment' => $preflight_series->billing_treatment,
                    'payment_method'    => $preflight_series->payment_method,
                    'invoice_lead_days' => (int) $preflight_series->invoice_lead_days,
                    'payment_terms_days' => (int) $preflight_series->payment_terms_days,
                    'billing_schedule'  => json_decode( $preflight_series->billing_schedule_json ?: '[]', true ),
                );
                return MBS_Series_Approval_Service::approve_with_billing(
                    $series_ref, $current_config, $expected_version, $notify_hirer
                );
            }
        }

        // ── No-charge / scout path (existing behaviour preserved) ──────────
        if ( $wpdb->query( 'START TRANSACTION' ) === false ) return new WP_Error( 'transaction_start_failed', 'Could not start the series-approval transaction.' );
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
        if ( $wpdb->query( 'COMMIT' ) === false ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'transaction_commit_failed', 'Could not commit series approval.' ); }

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
    public static function cancel( $series_ref, $scope, $expected_status, $expected_version, $notify_hirer = false ) {
        global $wpdb;
        $series_table  = $wpdb->prefix . MBS_SERIES_TABLE;
        $booking_table = $wpdb->prefix . MBS_TABLE;
        $series_ref = sanitize_text_field( $series_ref );
        $scope = $scope === 'future' ? 'future' : 'all';

        if ( $wpdb->query( 'START TRANSACTION' ) === false ) return new WP_Error( 'transaction_start_failed', 'Could not start the series-cancellation transaction.' );
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

        $today = wp_date( 'Y-m-d' );
        // "All" means the whole still-actionable series, not rewriting the
        // historical record of occurrences which were already paid. Future
        // paid/deposit-paid occurrences are cancellable so their immutable
        // invoice allocation can be credited by the billing reconciler.
        $eligible_sql = $scope === 'future'
            ? "booking_date >= %s AND status IN ('pending','confirmed','deposit_paid','paid')"
            : "(status IN ('pending','confirmed') OR (booking_date >= %s AND status IN ('deposit_paid','paid')))";
        $select_sql = "SELECT * FROM {$booking_table} WHERE series_id = %s AND {$eligible_sql} FOR UPDATE";
        $update_sql = "UPDATE {$booking_table} SET status = 'cancelled', access_sent = 0 WHERE series_id = %s AND {$eligible_sql}";
        $params = array( $series_ref, $today );
        $affected = $wpdb->get_results( $wpdb->prepare( $select_sql, $params ) );
        $reconciled = MBS_Billing_Engine::reconcile_occurrences( wp_list_pluck( $affected, 'ref' ), false );
        if ( is_wp_error( $reconciled ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'series_financial_reconciliation_failed', 'The series was not cancelled because its invoice reconciliation failed: ' . $reconciled->get_error_message() );
        }
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
        if ( $wpdb->query( 'COMMIT' ) === false ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'transaction_commit_failed', 'Could not commit series cancellation.' ); }

        foreach ( $affected as $booking ) {
            if ( ! empty( $booking->ha_notified ) ) {
                MBS_HomeAssistant::notify_cancelled( $booking );
            }
        }
        MBS_Audit_Log::log( $series_ref, 'series_cancelled', 'Cancelled ' . (int) $cancelled . ' ' . $scope . ' occurrence(s).' );
        $fresh = self::get( $series_ref );
        if ( $notify_hirer ) MBS_Email::notify_series_cancelled( $fresh, self::active_occurrences( $series_ref ) );
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
