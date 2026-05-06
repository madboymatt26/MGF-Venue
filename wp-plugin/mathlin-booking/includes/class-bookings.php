<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MBS_Bookings {

    // ── Spaces / Resources ─────────────────────────────────────────────────────
    public static function get_spaces() {
        $spaces = get_option( 'mbs_spaces', array() );
        if ( empty( $spaces ) ) {
            $spaces = self::get_default_spaces();
        }
        return $spaces;
    }

    public static function get_default_spaces() {
        return array(
            'Main Scout Hall' => array( 'rate_hourly' => 25, 'rate_daily' => 150, 'capacity' => 80 ),
            'Meeting Room'    => array( 'rate_hourly' => 12, 'rate_daily' => 70,  'capacity' => 20 ),
            'Outdoor Area'    => array( 'rate_hourly' => 10, 'rate_daily' => 40,  'capacity' => 100 ),
        );
    }

    public static function save_spaces( $spaces ) {
        return update_option( 'mbs_spaces', $spaces );
    }

    public static function get_kitchen_price() {
        return (float) get_option( 'mbs_kitchen_price', 10 );
    }

    public static function get_admin_email() {
        return get_option( 'mbs_admin_email', 'bookings@needhamscouts.uk' );
    }

    /**
     * Get a human-readable label for a booking status.
     */
    public static function status_label( $status ) {
        $labels = array(
            'pending'      => 'Pending',
            'confirmed'    => 'Confirmed',
            'deposit_paid' => 'Deposit Paid',
            'paid'         => 'Paid',
            'cancelled'    => 'Cancelled',
            'archived'     => 'Archived',
        );
        return $labels[ $status ] ?? ucfirst( $status );
    }

    public static function get_bank_details() {
        return array(
            'sort_code'      => get_option( 'mbs_bank_sort_code', '12-34-56' ),
            'account_number' => get_option( 'mbs_bank_account_number', '12345678' ),
            'account_name'   => get_option( 'mbs_bank_account_name', 'Needham Market Scout Group' ),
            'payment_days'   => (int) get_option( 'mbs_payment_terms_days', 14 ),
        );
    }

    // ── Deposit Configuration ──────────────────────────────────────────────────

    /**
     * Get deposit settings.
     */
    public static function get_deposit_settings() {
        return array(
            'enabled'         => (bool) get_option( 'mbs_deposit_enabled', false ),
            'percentage'      => (float) get_option( 'mbs_deposit_percentage', 25 ),
            'balance_days'    => (int) get_option( 'mbs_deposit_balance_days', 7 ),
        );
    }

    /**
     * Calculate the deposit amount for a booking.
     */
    public static function calculate_deposit( $total_amount ) {
        $settings = self::get_deposit_settings();
        if ( ! $settings['enabled'] || $total_amount <= 0 ) return $total_amount;
        return round( $total_amount * ( $settings['percentage'] / 100 ), 2 );
    }

    /**
     * Determine if a booking requires full payment immediately (event within balance_days).
     */
    public static function requires_full_payment( $booking_date ) {
        $settings = self::get_deposit_settings();
        if ( ! $settings['enabled'] ) return true;
        $days_until = ( strtotime( $booking_date ) - current_time( 'timestamp' ) ) / 86400;
        return $days_until <= $settings['balance_days'];
    }

    // ── Pricing Tiers ──────────────────────────────────────────────────────────

    /**
     * Get configured pricing tiers.
     */
    public static function get_pricing_tiers() {
        $tiers = get_option( 'mbs_pricing_tiers', array() );
        if ( empty( $tiers ) ) {
            $tiers = self::get_default_tiers();
        }
        return $tiers;
    }

    public static function get_default_tiers() {
        return array(
            'standard'  => array( 'label' => 'Standard', 'multiplier' => 1.0 ),
            'community' => array( 'label' => 'Charity / Community', 'multiplier' => 0.75 ),
            'commercial' => array( 'label' => 'Commercial', 'multiplier' => 1.5 ),
        );
    }

    /**
     * Get the pricing tier for a user. Defaults to 'standard' for guests.
     */
    public static function get_user_tier( $user_id = null ) {
        if ( ! $user_id ) $user_id = get_current_user_id();
        if ( ! $user_id ) return 'standard';
        $tier = get_user_meta( $user_id, 'mbs_pricing_tier', true );
        return $tier ?: 'standard';
    }

    /**
     * Get the rate multiplier for a given tier.
     */
    public static function get_tier_multiplier( $tier = 'standard' ) {
        $tiers = self::get_pricing_tiers();
        if ( isset( $tiers[ $tier ] ) ) {
            return (float) ( $tiers[ $tier ]['multiplier'] ?? 1.0 );
        }
        return 1.0;
    }

    // ── Space Bundling ─────────────────────────────────────────────────────────

    /**
     * Get related spaces for conflict detection (parent/child bundling).
     * Returns an array of space names that must also be checked for conflicts.
     */
    public static function get_related_spaces( $space ) {
        $spaces = self::get_spaces();
        $related = array();

        // If this space has a parent, add the parent
        if ( ! empty( $spaces[ $space ]['parent'] ) ) {
            $related[] = $spaces[ $space ]['parent'];
        }

        // If this space IS a parent, add all its children
        foreach ( $spaces as $name => $info ) {
            if ( ! empty( $info['parent'] ) && $info['parent'] === $space ) {
                $related[] = $name;
            }
        }

        return $related;
    }

    public static function calculate_cost( $space, $start_time, $end_time, $kitchen = false, $all_day = false, $num_days = 1, $scout_use = false, $tier = 'standard' ) {
        // Scout use bookings are free
        if ( $scout_use ) return 0;

        $spaces = self::get_spaces();
        if ( ! isset( $spaces[ $space ] ) ) return 0;

        $info = $spaces[ $space ];
        $cost = 0;

        // Get tier-specific rates if available, otherwise apply multiplier
        $multiplier = self::get_tier_multiplier( $tier );

        // Check for tier-specific rates in the space config
        $rate_hourly_key = 'rate_hourly_' . $tier;
        $rate_daily_key  = 'rate_daily_' . $tier;

        if ( $all_day ) {
            if ( isset( $info[ $rate_daily_key ] ) && (float) $info[ $rate_daily_key ] > 0 ) {
                $rate_daily = (float) $info[ $rate_daily_key ];
            } else {
                $rate_daily = isset( $info['rate_daily'] ) ? (float) $info['rate_daily'] : ( isset( $info['rate'] ) ? (float) $info['rate'] : 0 );
                $rate_daily = $rate_daily * $multiplier;
            }
            $cost = $rate_daily * max( 1, $num_days );
        } elseif ( $start_time && $end_time ) {
            if ( isset( $info[ $rate_hourly_key ] ) && (float) $info[ $rate_hourly_key ] > 0 ) {
                $rate_hourly = (float) $info[ $rate_hourly_key ];
            } else {
                $rate_hourly = isset( $info['rate_hourly'] ) ? (float) $info['rate_hourly'] : ( isset( $info['rate'] ) ? (float) $info['rate'] : 0 );
                $rate_hourly = $rate_hourly * $multiplier;
            }
            $start = strtotime( $start_time );
            $end   = strtotime( $end_time );
            // QA-001: Handle bookings spanning midnight (end time next day)
            $is_overnight = ( $end <= $start );
            if ( $is_overnight ) $end += 86400;
            $hours = ceil( max( 0, ( $end - $start ) / 3600 ) );
            // QA-003: Multi-day hourly bookings multiply by number of days
            // BUT: if overnight and end_date is exactly start_date + 1, it's one continuous block
            $effective_days = max( 1, $num_days );
            if ( $is_overnight && $num_days == 2 ) {
                $effective_days = 1; // Single continuous overnight block, not 2 separate days
            }
            $cost  = $hours * $rate_hourly * $effective_days;
        }

        if ( $kitchen ) $cost += self::get_kitchen_price();
        return round( $cost, 2 );
    }

    public static function generate_ref() {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        do {
            $ref    = 'MBS-' . strtoupper( substr( base_convert( uniqid(), 16, 36 ), -6 ) );
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE ref = %s", $ref ) );
        } while ( $exists );
        return $ref;
    }

    public static function create( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;

        $ref     = self::generate_ref();
        $all_day = ! empty( $data['all_day'] );
        $date_from = sanitize_text_field( $data['booking_date'] );
        $date_to   = sanitize_text_field( $data['booking_date_end'] ?? $data['booking_date'] );
        $num_days  = max( 1, (int) round( ( strtotime( $date_to ) - strtotime( $date_from ) ) / 86400 ) + 1 );

        // SEC-003: Validate scout_use server-side — only allow if email is in volunteer list
        $scout_use = false;
        if ( ! empty( $data['scout_use'] ) ) {
            $vol_emails = array_filter( array_map( 'trim', explode( "\n", get_option( 'mbs_scout_volunteer_emails', '' ) ) ) );
            $submitter_email = sanitize_email( $data['email'] ?? '' );
            $scout_use = in_array( strtolower( $submitter_email ), array_map( 'strtolower', $vol_emails ) );
        }

        $cost = self::calculate_cost(
            sanitize_text_field( $data['space'] ),
            sanitize_text_field( $data['start_time'] ?? '' ),
            sanitize_text_field( $data['end_time'] ?? '' ),
            ! empty( $data['kitchen'] ),
            $all_day,
            $num_days,
            $scout_use
        );

        $insert = array(
            'ref'              => $ref,
            'status'           => 'pending',
            'name'             => sanitize_text_field( $data['name'] ),
            'organisation'     => sanitize_text_field( $data['organisation'] ?? '' ),
            'email'            => sanitize_email( $data['email'] ),
            'phone'            => sanitize_text_field( $data['phone'] ),
            'address'          => sanitize_textarea_field( $data['address'] ),
            'space'            => sanitize_text_field( $data['space'] ),
            'kitchen'          => ! empty( $data['kitchen'] ) ? 1 : 0,
            'booking_date'     => $date_from,
            'booking_date_end' => $date_to,
            'all_day'          => $all_day ? 1 : 0,
            'scout_use'        => $scout_use ? 1 : 0,
            'start_time'       => ! empty( $data['start_time'] ) ? sanitize_text_field( $data['start_time'] ) : null,
            'end_time'         => ! empty( $data['end_time'] )   ? sanitize_text_field( $data['end_time'] )   : null,
            'attendees'        => absint( $data['attendees'] ),
            'purpose'          => sanitize_text_field( $data['purpose'] ),
            'notes'            => sanitize_textarea_field( $data['notes'] ?? '' ),
            'amount'           => $cost,
            'invoice_number'   => 'INV-' . $ref,
        );

        // Store custom field responses
        if ( ! empty( $data['custom_fields'] ) && is_array( $data['custom_fields'] ) ) {
            $custom = MBS_Custom_Fields::validate_submission( $data );
            if ( ! is_wp_error( $custom ) && ! empty( $custom ) ) {
                $insert['custom_fields'] = wp_json_encode( $custom );
            }
        }

        // Generate modification token
        $insert['modification_token'] = wp_generate_password( 32, false );

        // Public/private visibility
        $insert['is_public'] = ! empty( $data['is_public'] ) ? 1 : 0;

        // Link to logged-in user if applicable
        if ( is_user_logged_in() ) {
            $insert['user_id'] = get_current_user_id();
        }

        // SEC-001: Use transaction with row locking to prevent race condition double bookings
        $wpdb->query( 'START TRANSACTION' );

        // Acquire an exclusive lock on the relevant date/space rows to prevent concurrent inserts
        $wpdb->query( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE space = %s AND booking_date = %s AND status NOT IN ('cancelled','archived') FOR UPDATE",
            sanitize_text_field( $data['space'] ), $date_from
        ) );

        // Re-check conflicts inside the transaction (now with lock held)
        $space_val = sanitize_text_field( $data['space'] );
        $tx_conflicts = self::check_conflicts(
            $space_val, $date_from,
            $all_day ? null : ( $data['start_time'] ?? '' ),
            $all_day ? null : ( $data['end_time'] ?? '' ),
            $all_day
        );
        if ( ! empty( $tx_conflicts ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'conflict', self::format_conflict_message( $tx_conflicts ) );
        }

        $result = $wpdb->insert( $table, $insert );
        if ( $result === false ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_error', 'Could not save booking.' );
        }

        $wpdb->query( 'COMMIT' );

        // Audit log
        MBS_Audit_Log::log( $ref, 'created', 'Booking created by ' . sanitize_text_field( $data['name'] ) . ' for ' . sanitize_text_field( $data['space'] ) . ' on ' . sanitize_text_field( $data['booking_date'] ), 0 );

        return array_merge( $insert, array( 'id' => $wpdb->insert_id ) );
    }

    public static function get( $ref ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE ref = %s", $ref ) );
    }

    public static function get_by_id( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
    }

    public static function get_all( $args = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;

        $defaults = array(
            'status'    => '',
            'date_from' => '',
            'date_to'   => '',
            'search'    => '',
            'orderby'   => 'booking_date',
            'order'     => 'ASC',
            'limit'     => 200,
            'offset'    => 0,
            'exclude_archived' => true,
        );
        $args = wp_parse_args( $args, $defaults );

        $where  = array( '1=1' );
        $values = array();

        if ( $args['status'] ) {
            $where[]  = 'status = %s';
            $values[] = $args['status'];
        } elseif ( $args['exclude_archived'] ) {
            $where[] = "status != 'archived'";
        }
        if ( $args['date_from'] ) {
            $where[]  = 'booking_date >= %s';
            $values[] = $args['date_from'];
        }
        if ( $args['date_to'] ) {
            $where[]  = 'booking_date <= %s';
            $values[] = $args['date_to'];
        }
        if ( $args['search'] ) {
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]  = '(name LIKE %s OR organisation LIKE %s OR ref LIKE %s OR purpose LIKE %s)';
            $values[] = $like; $values[] = $like; $values[] = $like; $values[] = $like;
        }

        $allowed_order = array( 'booking_date', 'created_at', 'name', 'status', 'amount' );
        $orderby = in_array( $args['orderby'], $allowed_order ) ? $args['orderby'] : 'booking_date';
        $order   = strtoupper( $args['order'] ) === 'DESC' ? 'DESC' : 'ASC';

        $sql      = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) .
                    " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $values[] = $args['limit'];
        $values[] = $args['offset'];

        return $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
    }

    public static function get_by_date( $date ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE booking_date = %s AND status NOT IN ('cancelled', 'archived') ORDER BY start_time ASC",
            $date
        ) );
    }

    public static function get_booked_dates( $year, $month ) {
        global $wpdb;
        $table   = $wpdb->prefix . MBS_TABLE;
        $from    = sprintf( '%04d-%02d-01', $year, $month );
        $to      = wp_date( 'Y-m-t', strtotime( $from ) );

        // UX-005: Include multi-day bookings that span into this month
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT booking_date, booking_date_end, COUNT(*) as count FROM {$table}
             WHERE (
                 (booking_date BETWEEN %s AND %s)
                 OR (booking_date_end IS NOT NULL AND booking_date <= %s AND booking_date_end >= %s)
             )
             AND status NOT IN ('cancelled', 'archived')
             GROUP BY booking_date, booking_date_end",
            $from, $to, $to, $from
        ) );

        $map = array();
        foreach ( $results as $row ) {
            // Expand multi-day bookings into individual dates
            $start = max( strtotime( $from ), strtotime( $row->booking_date ) );
            $end   = $row->booking_date_end ? min( strtotime( $to ), strtotime( $row->booking_date_end ) ) : $start;

            for ( $d = $start; $d <= $end; $d += 86400 ) {
                $date_str = wp_date( 'Y-m-d', $d );
                $map[ $date_str ] = ( $map[ $date_str ] ?? 0 ) + (int) $row->count;
            }
        }
        return $map;
    }

    public static function update_status( $ref, $status ) {
        global $wpdb;
        $table   = $wpdb->prefix . MBS_TABLE;
        $allowed = array( 'pending', 'confirmed', 'cancelled', 'archived', 'paid', 'deposit_paid' );
        if ( ! in_array( $status, $allowed ) ) return false;

        $result = $wpdb->update(
            $table,
            array( 'status' => $status ),
            array( 'ref'    => $ref ),
            array( '%s' ), array( '%s' )
        );

        if ( $result !== false ) {
            // Audit log
            MBS_Audit_Log::log( $ref, $status === 'pending' ? 'reopened' : $status, 'Status changed to ' . $status );
        }

        if ( $result !== false && $status === 'confirmed' ) {
            $booking = self::get( $ref );
            if ( $booking ) {
                MBS_HomeAssistant::notify( $booking );
                $wpdb->update( $table, array( 'ha_notified' => 1 ), array( 'ref' => $ref ) );

                // Auto-promote £0 bookings (scout use / free) straight to paid
                if ( (float) $booking->amount <= 0 ) {
                    $wpdb->update( $table, array( 'status' => 'paid' ), array( 'ref' => $ref ) );
                    MBS_Audit_Log::log( $ref, 'paid', 'Auto-marked as Paid (£0 booking — no payment required)' );
                    do_action( 'mbs_booking_paid', self::get( $ref ), 0 );
                }
            }
        }

        if ( $result !== false && $status === 'cancelled' ) {
            $booking = self::get( $ref );
            if ( $booking ) MBS_HomeAssistant::notify_cancelled( $booking );
        }

        // Fire action when booking is marked as paid (for OSM integration etc.)
        if ( $result !== false && $status === 'paid' ) {
            $booking = self::get( $ref );
            if ( $booking ) {
                do_action( 'mbs_booking_paid', $booking, 0 );
            }
        }

        return $result;
    }

    public static function delete( $ref ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        MBS_Audit_Log::log( $ref, 'deleted', 'Booking permanently deleted' );
        return $wpdb->delete( $table, array( 'ref' => $ref ), array( '%s' ) );
    }

    // ── Conflict Detection ─────────────────────────────────────────────────────

    /**
     * Check if a proposed booking conflicts with existing bookings.
     *
     * @param string $space      Space name
     * @param string $date       Booking date (Y-m-d)
     * @param string $start_time Start time (H:i or H:i:s), null for all-day
     * @param string $end_time   End time, null for all-day
     * @param bool   $all_day    Whether this is an all-day booking
     * @param string $exclude_ref  Ref to exclude (for editing existing bookings)
     * @return array  Array of conflicting booking objects (empty = no conflicts)
     */
    public static function check_conflicts( $space, $date, $start_time = null, $end_time = null, $all_day = false, $exclude_ref = '' ) {
        // Check the requested space AND any related spaces (parent/child bundling)
        $spaces_to_check = array_merge( array( $space ), self::get_related_spaces( $space ) );
        $all_conflicts = array();

        foreach ( $spaces_to_check as $check_space ) {
            $conflicts = self::check_conflicts_for_space( $check_space, $date, $start_time, $end_time, $all_day, $exclude_ref );
            $all_conflicts = array_merge( $all_conflicts, $conflicts );
        }

        return $all_conflicts;
    }

    /**
     * Check conflicts for a single space (internal helper).
     */
    private static function check_conflicts_for_space( $space, $date, $start_time = null, $end_time = null, $all_day = false, $exclude_ref = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;

        // Also check multi-day bookings that span this date
        $where = "space = %s AND status NOT IN ('cancelled', 'archived')
                  AND (
                      booking_date = %s
                      OR (booking_date_end IS NOT NULL AND booking_date <= %s AND booking_date_end >= %s)
                  )";
        $values = array( $space, $date, $date, $date );

        if ( $exclude_ref ) {
            $where .= " AND ref != %s";
            $values[] = $exclude_ref;
        }

        $existing = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where} ORDER BY start_time ASC",
            $values
        ) );

        if ( empty( $existing ) ) return array();

        // If the new booking is all-day, it conflicts with everything on that date
        if ( $all_day ) return $existing;

        $conflicts = array();
        foreach ( $existing as $b ) {
            // Existing all-day booking conflicts with any timed booking
            if ( $b->all_day ) {
                $conflicts[] = $b;
                continue;
            }

            // Check time overlap: new_start < existing_end AND new_end > existing_start
            if ( $start_time && $end_time && $b->start_time && $b->end_time ) {
                $new_start = strtotime( $start_time );
                $new_end   = strtotime( $end_time );
                $ex_start  = strtotime( $b->start_time );
                $ex_end    = strtotime( $b->end_time );

                if ( $new_start < $ex_end && $new_end > $ex_start ) {
                    $conflicts[] = $b;
                }
            }
        }

        return $conflicts;
    }

    /**
     * Format conflict information into a human-readable message.
     */
    public static function format_conflict_message( $conflicts ) {
        if ( empty( $conflicts ) ) return '';

        $msgs = array();
        foreach ( $conflicts as $b ) {
            $time = $b->all_day ? 'all day' : ( $b->start_time . '–' . $b->end_time );
            $msgs[] = $b->space . ' on ' . date( 'j M', strtotime( $b->booking_date ) ) . ' (' . $time . ') – ' . $b->name;
        }

        return 'This booking conflicts with: ' . implode( '; ', $msgs ) . '. Please choose a different time or space.';
    }

    // ── Recurring Bookings ─────────────────────────────────────────────────────

    /**
     * Generate a unique series ID for recurring bookings.
     */
    public static function generate_series_id() {
        return 'SER-' . strtoupper( substr( base_convert( uniqid(), 16, 36 ), -6 ) );
    }

    /**
     * Create a recurring booking series (weekly repeat).
     *
     * @param array  $data       Base booking data
     * @param string $repeat_until  End date for recurrence (Y-m-d)
     * @return array  Array of created booking refs, or WP_Error
     */
    public static function create_recurring( $data, $repeat_until ) {
        $series_id  = self::generate_series_id();
        $start_date = sanitize_text_field( $data['booking_date'] );
        $end_date   = sanitize_text_field( $repeat_until );
        $refs       = array();
        $conflicts  = array();

        $current = strtotime( $start_date );
        $end     = strtotime( $end_date );

        if ( $current > $end ) {
            return new WP_Error( 'invalid_range', 'Repeat-until date must be after the booking date.' );
        }

        // Limit to 52 weeks max to prevent abuse
        $max_occurrences = 52;
        $count = 0;

        while ( $current <= $end && $count < $max_occurrences ) {
            $date_str = wp_date( 'Y-m-d', $current );

            // Check for conflicts on each date
            $all_day = ! empty( $data['all_day'] );
            $date_conflicts = self::check_conflicts(
                sanitize_text_field( $data['space'] ),
                $date_str,
                $all_day ? null : sanitize_text_field( $data['start_time'] ?? '' ),
                $all_day ? null : sanitize_text_field( $data['end_time'] ?? '' ),
                $all_day
            );

            // Check blocked dates
            $blocked = MBS_Blocked_Dates::is_blocked( $date_str, sanitize_text_field( $data['space'] ) );

            if ( ! empty( $date_conflicts ) || $blocked ) {
                $conflicts[] = $date_str;
                $current += 7 * 86400; // Skip this week
                $count++;
                continue;
            }

            // Create the individual booking
            $booking_data = $data;
            $booking_data['booking_date'] = $date_str;
            $booking_data['booking_date_end'] = $date_str;

            $result = self::create( $booking_data );

            if ( is_wp_error( $result ) ) {
                $current += 7 * 86400;
                $count++;
                continue;
            }

            // Link to series
            global $wpdb;
            $table = $wpdb->prefix . MBS_TABLE;
            $wpdb->update(
                $table,
                array( 'series_id' => $series_id ),
                array( 'ref' => $result['ref'] ),
                array( '%s' ), array( '%s' )
            );

            $refs[] = $result['ref'];
            $current += 7 * 86400; // Next week
            $count++;
        }

        if ( empty( $refs ) ) {
            return new WP_Error( 'no_bookings', 'Could not create any bookings. All dates had conflicts or were blocked.' );
        }

        return array(
            'series_id'  => $series_id,
            'refs'       => $refs,
            'created'    => count( $refs ),
            'skipped'    => $conflicts,
            'total_weeks' => $count,
        );
    }

    /**
     * Get all bookings in a series.
     */
    public static function get_series( $series_id ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE series_id = %s ORDER BY booking_date ASC",
            $series_id
        ) );
    }

    /**
     * Update status for all bookings in a series.
     */
    public static function update_series_status( $series_id, $status ) {
        global $wpdb;
        $table   = $wpdb->prefix . MBS_TABLE;
        $allowed = array( 'pending', 'confirmed', 'deposit_paid', 'cancelled', 'archived', 'paid' );
        if ( ! in_array( $status, $allowed ) ) return false;

        $result = $wpdb->update(
            $table,
            array( 'status' => $status ),
            array( 'series_id' => $series_id ),
            array( '%s' ), array( '%s' )
        );

        if ( $result !== false ) {
            MBS_Audit_Log::log( $series_id, 'series_' . $status, 'Entire series status changed to ' . $status );
        }

        // Trigger HA notifications for each booking in the series
        if ( $result !== false && in_array( $status, array( 'confirmed', 'cancelled' ) ) ) {
            $bookings = self::get_series( $series_id );
            foreach ( $bookings as $booking ) {
                if ( $status === 'confirmed' ) {
                    MBS_HomeAssistant::notify( $booking );
                    $wpdb->update( $table, array( 'ha_notified' => 1 ), array( 'ref' => $booking->ref ) );
                } elseif ( $status === 'cancelled' ) {
                    MBS_HomeAssistant::notify_cancelled( $booking );
                }
            }
        }

        return $result;
    }

    // ── Admin Notes ────────────────────────────────────────────────────────────

    /**
     * Update admin notes for a booking.
     */
    public static function update_admin_notes( $ref, $notes ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        MBS_Audit_Log::log( $ref, 'notes_updated', 'Admin notes updated' );
        return $wpdb->update(
            $table,
            array( 'admin_notes' => sanitize_textarea_field( $notes ) ),
            array( 'ref' => $ref ),
            array( '%s' ), array( '%s' )
        );
    }

    /**
     * Archive all past bookings (booking_date before today) that are confirmed or cancelled.
     * Returns the number of bookings archived.
     */
    public static function archive_past_bookings() {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        $today = wp_date( 'Y-m-d' );
        return $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'archived' WHERE booking_date < %s AND status IN ('confirmed', 'deposit_paid', 'cancelled')",
            $today
        ) );
    }

    /**
     * Count bookings with 'pending' status.
     */
    public static function get_pending_count() {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
    }

    /**
     * Get stats with financial year support (April to March).
     */
    public static function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;

        // Calculate current financial year (April to March)
        $now   = new DateTime();
        $month = (int) $now->format( 'n' );
        $year  = (int) $now->format( 'Y' );

        if ( $month >= 4 ) {
            $fy_start = $year . '-04-01';
            $fy_end   = ( $year + 1 ) . '-03-31';
            $fy_label = $year . '/' . ( $year + 1 );
        } else {
            $fy_start = ( $year - 1 ) . '-04-01';
            $fy_end   = $year . '-03-31';
            $fy_label = ( $year - 1 ) . '/' . $year;
        }

        return array(
            'total'      => (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status != 'archived'" ),
            'pending'    => (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='pending'" ),
            'confirmed'  => (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='confirmed'" ),
            'cancelled'  => (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='cancelled'" ),
            'archived'   => (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='archived'" ),
            'paid'       => (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='paid'" ),
            'revenue_fy' => (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE status IN ('confirmed', 'deposit_paid', 'paid') AND booking_date BETWEEN %s AND %s",
                $fy_start, $fy_end
            ) ),
            'fy_label'   => $fy_label,
        );
    }

    // ── Venue & Legal ──────────────────────────────────────────────────────────

    /**
     * Get the default Terms & Conditions template with placeholders.
     */
    public static function get_default_terms() {
        return '<h3>Terms &amp; Conditions of Hire</h3>

<p>Any enquiries concerning hiring should be made via email to {admin_email}.</p>

<ol>
<li>The Hirer shall be over the age of 21.</li>
<li>All events must end by {curfew_saturday} on a Saturday or {curfew_sunday} on a Sunday.</li>
<li>Maximum permitted number of the Main Hall is {venue_capacity} seating capacity.</li>
<li>It is the responsibility of the Hirer to ensure &lsquo;Conditions of Hire&rsquo; for the centre are understood and adhered to by all persons using the hall during the period of hire. (The &lsquo;Conditions of Hire&rsquo; are permanently displayed in the foyer and a copy accompanies each booking form).</li>
<li>The Hirer must provide sufficient numbers of responsible adult attendants or stewards for adequate supervision of the premises and users therein. Two stewards for up to 40 people and one for the additional 20 people.</li>
<li>Any hirer who hires the hall regularly and whose activities involve children or young people must follow good practice and follow their own policy. Those in charge of children must be DBS (Disclosure &amp; Barring Service) checked.</li>
<li>The Hirer will, during the period of hire, be responsible for the supervision, care and protection from damage of the premises, fabric and contents and for the behaviour of all persons using the premises whatever their capacity.</li>
<li>The Hirer shall indemnify {org_name} for the cost of repair of any accidental or wilful damage to any part of the premises or contents which may occur during the period of hire. {org_name} reserve the right to take legal action to reclaim monies owing.</li>
<li>To the extent permitted by the Unfair Contract Terms Act 1977 {org_name} shall not be liable for any injury to persons, loss or damage to property brought onto the premises or for any consequential loss.</li>
<li>The Hirer shall report to the Booking Secretary any injury to persons or loss or damage of the property. In the case of destruction, damage or loss by theft or attempt thereat, the Hirer shall immediately notify {org_name} and provide information as required to the Booking Secretary.</li>
<li>The Hirer will enter any damages into the &lsquo;Defect Book&rsquo; kept in the kitchen.</li>
<li>A First Aid kit is located in the kitchen.</li>
<li>The Hirer shall enter any injuries in the Accident Book located in the kitchen.</li>
<li>The Hirer shall not sublet or use the premises in any unlawful way or bring anything on to the premises, which may endanger same.</li>
<li>Any complaints concerning the premises must be made as soon as possible in writing to {org_name}.</li>
<li>In the event of the premises being rendered unfit for the use it was hired for, {org_name} shall not be liable for any loss whatsoever.</li>
<li>{org_name} reserves the right of free admission during the period of hire to observe compliance with the &lsquo;Conditions of Hire&rsquo;.</li>
<li>{org_name} reserves the right to cancel bookings with 3 (three) months notice if the hall is required for scouting activities.</li>
<li>The selling of alcohol on the premises is forbidden (unless the hirer has obtained a licence).</li>
<li>{org_name} does not have a Public Entertainments Licence.</li>
<li>{org_name} has a NO Smoking Policy throughout the premises and grounds.</li>
<li>Car parking next to the premises is available:<br>
a) Spaces are not guaranteed.<br>
b) Park in orderly manner to allow maximum use of the area.<br>
c) Do not block other users in.<br>
d) Overnight parking is not permitted.<br>
e) Users park at their own risk.<br>
f) The car park is owned by the local council and not {org_name}.</li>
<li>As the hall is in a residential area, music and noise must be kept down to a reasonable level so as not to disturb the residents. This also applies to the car park.</li>
<li>FIRE<br>
a) Fire exits are clearly marked.<br>
b) Ensure escape routes are kept clear and free of obstruction.<br>
c) Fire doors must be kept closed and not wedged open.<br>
d) Extinguishers must not be taken from the wall to use as doorstops.<br>
e) The Hirer must ensure they are familiar with locations of fire exits and extinguishers.<br>
f) On detection of a fire, the Hirer must break a glass and assist evacuation of the building to the evacuation point (Car Park). Dial 999 to fire brigade.</li>
<li>The Hirer shall be responsible for leaving the premises clean and tidy at the proper time and fit to be used by the next hirer or Scout Meeting. Toilets must be cleaned, floors swept/mopped, kitchen surfaces and sinks cleaned and bins emptied. (Floor cleaning equipment to be found in cleaning cupboard and brooms in cupboard under stairs). Crockery/cutlery &amp; cooking equipment must be washed/dried and stored away. All rubbish is to be bagged and taken away by the Hirer for disposal.</li>
<li>Hirers using the kitchen must leave it in a clean and acceptable state (see item 25) and should comply with notices posted in the kitchen. An additional cleaning charge may be levied if the Hirer fails to do this and additional cleaning is deemed necessary.</li>
<li>The Hirer will not have access to {org_name}&rsquo;s camp store, dry store, mezzanine store or office.</li>
<li>The Hirer will not stick anything to the walls/doors or {org_name} notice boards.</li>
<li>Telephone &ndash; the nearest public telephone is near the council offices on the high street, opposite the corner shop.</li>
<li>The Hirer will wipe clean tables, which have been used and leave all tables &amp; chairs for the large hall tidily away in the chair/table store.</li>
<li>Hirers will check all fire exits are secure, lights turned off, taps off, electrical equipment including the cooker are all switched off prior to exiting the building.</li>
<li>The hiring prices are reviewed annually, and prices charged will be those in force at the time of the let, regardless of when the booking was made. Full payment for the Hire must be made {payment_days_required} days before the event or the booking will be cancelled. Short notice bookings, less than {payment_days_required} days before the event will require full payment at the time of booking. Cancellation by the Hirer within {payment_days_required} days of the event will incur a charge of 50% of the total hire cost.</li>
</ol>';
    }

    /**
     * Parse T&C/venue text, replacing placeholders with actual values.
     */
    public static function parse_venue_placeholders( $text ) {
        $org = class_exists( 'MBS_Email_Templates' ) ? MBS_Email_Templates::get_org_settings() : array();

        $replacements = array(
            '{org_name}'              => $org['name'] ?? get_bloginfo( 'name' ),
            '{org_address}'           => $org['address'] ?? '',
            '{org_phone}'             => $org['phone'] ?? '',
            '{charity_number}'        => $org['charity_number'] ?? '',
            '{admin_email}'           => self::get_admin_email(),
            '{venue_capacity}'        => get_option( 'mbs_venue_capacity', 100 ),
            '{curfew_saturday}'       => get_option( 'mbs_curfew_saturday', '11:00 PM' ),
            '{curfew_sunday}'         => get_option( 'mbs_curfew_sunday', '10:00 PM' ),
            '{payment_days_required}' => get_option( 'mbs_payment_days_required', 28 ),
        );

        return str_replace( array_keys( $replacements ), array_values( $replacements ), $text );
    }

    // ── GDPR: Personal Data Erasure ────────────────────────────────────────────

    /**
     * SEC-FIX-002: WordPress Privacy Eraser callback.
     * Anonymises PII in booking records while preserving financial audit trail.
     */
    public static function gdpr_erase_personal_data( $email, $page = 1 ) {
        global $wpdb;
        $table       = $wpdb->prefix . MBS_TABLE;
        $audit_table = $wpdb->prefix . 'mathlin_audit_log';
        $queue_table = $wpdb->prefix . 'mathlin_email_queue';
        $mod_table   = $wpdb->prefix . 'mathlin_mod_requests';

        // Anonymise PII in bookings but preserve financial data (amount, dates, space)
        $affected = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET
                name = 'Anonymised',
                email = CONCAT('erased-', id, '@anonymised.invalid'),
                phone = '',
                address = '',
                organisation = '',
                notes = '',
                admin_notes = CONCAT('[GDPR erasure ', NOW(), '] ', COALESCE(admin_notes, '')),
                custom_fields = ''
            WHERE email = %s",
            $email
        ) );

        // Anonymise IP addresses in audit log for this person's bookings
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$audit_table} SET ip_address = '0.0.0.0', user_name = 'Anonymised'
             WHERE ref IN (SELECT ref FROM {$table} WHERE email LIKE 'erased-%@anonymised.invalid')
             AND user_name != 'System'",
            $email
        ) );

        // Delete any queued emails containing this person's data
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$queue_table} WHERE to_email = %s",
            $email
        ) );

        // Anonymise modification request notes
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$mod_table} SET notes = '', admin_response = ''
             WHERE booking_ref IN (SELECT ref FROM {$table} WHERE email LIKE 'erased-%@anonymised.invalid')"
        ) );

        $items_removed = $affected > 0;

        return array(
            'items_removed'  => $items_removed,
            'items_retained' => $items_removed, // Financial records retained but anonymised
            'messages'       => $items_removed
                ? array( sprintf( '%d booking record(s) anonymised. Financial totals preserved for audit.', $affected ) )
                : array(),
            'done'           => true,
        );
    }

    /**
     * WordPress Privacy Exporter callback.
     * Exports all personal data held in booking records.
     */
    public static function gdpr_export_personal_data( $email, $page = 1 ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;

        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE email = %s LIMIT 100",
            $email
        ) );

        $export_items = array();
        foreach ( $bookings as $b ) {
            $export_items[] = array(
                'group_id'    => 'mathlin-bookings',
                'group_label' => 'Venue Bookings',
                'item_id'     => 'booking-' . $b->id,
                'data'        => array(
                    array( 'name' => 'Reference',    'value' => $b->ref ),
                    array( 'name' => 'Name',         'value' => $b->name ),
                    array( 'name' => 'Email',        'value' => $b->email ),
                    array( 'name' => 'Phone',        'value' => $b->phone ),
                    array( 'name' => 'Address',      'value' => $b->address ),
                    array( 'name' => 'Organisation', 'value' => $b->organisation ),
                    array( 'name' => 'Space',        'value' => $b->space ),
                    array( 'name' => 'Date',         'value' => $b->booking_date ),
                    array( 'name' => 'Amount',       'value' => '£' . number_format( $b->amount, 2 ) ),
                    array( 'name' => 'Status',       'value' => $b->status ),
                    array( 'name' => 'Created',      'value' => $b->created_at ),
                ),
            );
        }

        return array(
            'data' => $export_items,
            'done' => true,
        );
    }
}
