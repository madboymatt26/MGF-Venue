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
        // M-2: Use wp_date() for timezone-consistent "today" basis (BST/GMT safe),
        // matching the rest of the codebase rather than server-UTC current_time('timestamp').
        $today      = strtotime( wp_date( 'Y-m-d' ) );
        $days_until = ( strtotime( $booking_date ) - $today ) / 86400;
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

    /**
     * Resolve the pricing tier for a booking.
     * Prefers the tier stored on the booking row, then the linked user's tier,
     * then falls back to 'standard'.
     */
    public static function get_booking_tier( $booking ) {
        if ( ! empty( $booking->pricing_tier ) ) {
            return $booking->pricing_tier;
        }
        if ( ! empty( $booking->user_id ) ) {
            return self::get_user_tier( (int) $booking->user_id );
        }
        return 'standard';
    }

    /**
     * Whether a tier is allowed to receive access codes before full payment.
     * Used for trusted B2B tiers (councils, commercial PO customers).
     */
    public static function tier_bypasses_access_gate( $tier = 'standard' ) {
        $tiers = self::get_pricing_tiers();
        return ! empty( $tiers[ $tier ]['bypass_access_gate'] );
    }

    /**
     * Whether a tier is configured for offline invoicing (BACS / Purchase Order).
     * Offline tiers do not receive WooCommerce "Pay Now" links and are exempt
     * from aggressive payment-chasing.
     */
    public static function tier_is_offline( $tier = 'standard' ) {
        $tiers = self::get_pricing_tiers();
        return ! empty( $tiers[ $tier ]['offline_invoicing'] );
    }

    /**
     * Whether a specific booking should be treated as offline-invoiced,
     * based on its resolved pricing tier.
     */
    public static function booking_is_offline( $booking ) {
        return self::tier_is_offline( self::get_booking_tier( $booking ) );
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
        $date_to   = ! empty( $data['booking_date_end'] ) ? sanitize_text_field( $data['booking_date_end'] ) : sanitize_text_field( $data['booking_date'] );
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
            'exclude_scout'    => false,
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
        if ( $args['exclude_scout'] ) {
            $where[] = "scout_use = 0";
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
            // C-2: A cancelled booking must not retain an active access flag.
            $wpdb->update( $table, array( 'access_sent' => 0 ), array( 'ref' => $ref ) );
            MBS_Audit_Log::log( $ref, 'access_revoked', 'Access flag reset on cancellation. Consider rotating the keysafe code if already shared.' );
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
            $msgs[] = $b->space . ' on ' . wp_date( 'j M', strtotime( $b->booking_date ) ) . ' (' . $time . ') – ' . $b->name;
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

    /**
     * Cancel all *future* bookings in a series (booking_date >= today).
     *
     * Used by the Scout Nights bulk "Cancel Entire Series" action. Past
     * bookings are deliberately left untouched so the historical record of
     * meetings that already took place is preserved.
     *
     * @param string $series_id The SER-XXXXXX series reference.
     * @return int|false Number of rows cancelled, or false on failure.
     */
    public static function cancel_series_future( $series_id ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        $today = wp_date( 'Y-m-d' );

        // Grab the affected bookings first so we can notify HA after the update.
        $affected = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE series_id = %s AND booking_date >= %s AND status != 'cancelled'",
            $series_id,
            $today
        ) );

        $result = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'cancelled'
             WHERE series_id = %s AND booking_date >= %s AND status != 'cancelled'",
            $series_id,
            $today
        ) );

        if ( $result === false ) return false;

        // Audit log + HA cancellation notices.
        MBS_Audit_Log::log(
            $series_id,
            'series_bulk_cancel',
            'Bulk-cancelled ' . (int) $result . ' future booking(s) in series (from ' . $today . ')'
        );

        foreach ( $affected as $booking ) {
            MBS_HomeAssistant::notify_cancelled( $booking );
        }

        return (int) $result;
    }

    /**
     * Bulk-edit all *future* bookings in a series (booking_date >= today).
     *
     * Used by the Scout Nights "Edit Series" action to change the time, space
     * or section for every upcoming occurrence in one go. Past bookings are
     * left untouched. Each date is conflict-checked individually (excluding the
     * booking's own row); any date where the new details would clash with
     * another booking is skipped and reported back, so the rest still update.
     *
     * @param string $series_id The SER-XXXXXX series reference.
     * @param array  $fields    Any of: space, start_time, end_time, purpose.
     *                          Only keys that are present are changed.
     * @return array|WP_Error  ['updated' => int, 'skipped' => array of dates]
     */
    public static function update_series_future( $series_id, $fields ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        $today = wp_date( 'Y-m-d' );

        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE series_id = %s AND booking_date >= %s AND status != 'cancelled'
             ORDER BY booking_date ASC",
            $series_id,
            $today
        ) );

        if ( empty( $bookings ) ) {
            return new WP_Error( 'no_bookings', 'No future bookings found in this series.' );
        }

        // Which fields are we changing? null = leave as-is.
        $new_space   = isset( $fields['space'] )      ? sanitize_text_field( $fields['space'] )   : null;
        $new_start   = isset( $fields['start_time'] ) ? sanitize_text_field( $fields['start_time'] ) : null;
        $new_end     = isset( $fields['end_time'] )   ? sanitize_text_field( $fields['end_time'] ) : null;
        $new_purpose = isset( $fields['purpose'] )    ? sanitize_text_field( $fields['purpose'] )  : null;

        if ( $new_space === null && $new_start === null && $new_end === null && $new_purpose === null ) {
            return new WP_Error( 'no_changes', 'No changes were provided.' );
        }

        $updated = 0;
        $skipped = array();

        foreach ( $bookings as $b ) {
            $space   = $new_space !== null ? $new_space : $b->space;
            $start   = $new_start !== null ? $new_start : $b->start_time;
            $end     = $new_end   !== null ? $new_end   : $b->end_time;
            $all_day = (bool) $b->all_day;

            // If the space or time is changing, make sure the new slot is free
            // (excluding this booking's own row so it doesn't clash with itself).
            if ( $new_space !== null || $new_start !== null || $new_end !== null ) {
                $conflicts = self::check_conflicts(
                    $space,
                    $b->booking_date,
                    $all_day ? null : $start,
                    $all_day ? null : $end,
                    $all_day,
                    $b->ref
                );
                if ( ! empty( $conflicts ) ) {
                    $skipped[] = $b->booking_date;
                    continue;
                }
            }

            $update = array();
            if ( $new_space !== null )   $update['space']      = $space;
            if ( $new_start !== null )   $update['start_time'] = $all_day ? null : $start;
            if ( $new_end !== null )     $update['end_time']   = $all_day ? null : $end;
            if ( $new_purpose !== null ) $update['purpose']    = $new_purpose;

            // Recalculate the cost for the new slot (scout-use bookings stay free).
            $update['amount'] = self::calculate_cost(
                $space, $start, $end, (bool) $b->kitchen, $all_day, 1, (bool) $b->scout_use
            );

            $wpdb->update( $table, $update, array( 'ref' => $b->ref ) );
            $updated++;

            // Keep Home Assistant in step for confirmed bookings whose slot moved.
            if ( $b->status === 'confirmed' && ( $new_space !== null || $new_start !== null || $new_end !== null ) ) {
                $fresh = self::get( $b->ref );
                if ( $fresh ) MBS_HomeAssistant::notify( $fresh );
            }
        }

        // Audit log
        $changed = array();
        if ( $new_space !== null )   $changed[] = 'space → ' . $new_space;
        if ( $new_start !== null )   $changed[] = 'start → ' . $new_start;
        if ( $new_end !== null )     $changed[] = 'end → ' . $new_end;
        if ( $new_purpose !== null ) $changed[] = 'section → ' . $new_purpose;
        MBS_Audit_Log::log(
            $series_id,
            'series_bulk_edit',
            'Bulk-edited ' . $updated . ' future booking(s) (' . implode( ', ', $changed ) . ')'
            . ( ! empty( $skipped ) ? '; skipped ' . count( $skipped ) . ' conflicting date(s)' : '' )
        );

        return array( 'updated' => $updated, 'skipped' => $skipped );
    }

    /**
     * Extend an existing series with further weekly occurrences up to a new
     * end date. New bookings reuse the *latest* booking in the series as their
     * template (so any time/space/section edits made via update_series_future()
     * are carried forward) and are linked to the same series_id.
     *
     * The weekly cadence continues from the last existing occurrence. Each new
     * date is conflict- and blocked-checked (with row locking to avoid race
     * conditions) and skipped + reported if unavailable. Capped at 52 new
     * occurrences per call to prevent runaway series.
     *
     * @param string $series_id The SER-XXXXXX series reference.
     * @param string $new_end   Target end date (Y-m-d) to extend up to.
     * @return array|WP_Error  ['created' => int, 'skipped' => array of dates]
     */
    public static function extend_series( $series_id, $new_end ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;

        $bookings = self::get_series( $series_id );
        if ( empty( $bookings ) ) {
            return new WP_Error( 'no_series', 'Series not found.' );
        }

        // The template is the latest occurrence (get_series is ordered ASC).
        $template = end( $bookings );
        $last_ts  = strtotime( $template->booking_date );
        $end_ts   = strtotime( sanitize_text_field( $new_end ) );

        if ( ! $end_ts ) {
            return new WP_Error( 'invalid_date', 'Invalid end date.' );
        }

        // First new occurrence is one week after the current last one.
        $current = strtotime( '+1 week', $last_ts );
        if ( $current > $end_ts ) {
            return new WP_Error(
                'no_extension',
                'The series already runs to ' . wp_date( 'j M Y', $last_ts ) . '. Choose a later date.'
            );
        }

        $all_day = (bool) $template->all_day;
        $created = 0;
        $skipped = array();
        $max_occurrences = 52;
        $count = 0;

        for ( $d = $current; $d <= $end_ts && $count < $max_occurrences; $d = strtotime( '+1 week', $d ) ) {
            $count++;
            $date_str = wp_date( 'Y-m-d', $d );

            // Transaction + row lock: prevent a TOCTOU race with a public booking.
            $wpdb->query( 'START TRANSACTION' );
            $wpdb->query( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE space = %s AND booking_date = %s AND status NOT IN ('cancelled','archived') FOR UPDATE",
                $template->space, $date_str
            ) );

            $conflicts = self::check_conflicts(
                $template->space,
                $date_str,
                $all_day ? null : $template->start_time,
                $all_day ? null : $template->end_time,
                $all_day
            );
            if ( ! empty( $conflicts ) || MBS_Blocked_Dates::is_blocked( $date_str, $template->space ) ) {
                $wpdb->query( 'ROLLBACK' );
                $skipped[] = $date_str;
                continue;
            }

            // Recalculate cost for this occurrence (scout-use stays free).
            $amount = self::calculate_cost(
                $template->space, $template->start_time, $template->end_time,
                (bool) $template->kitchen, $all_day, 1, (bool) $template->scout_use
            );

            $ref = self::generate_ref();
            $wpdb->insert( $table, array(
                'ref'              => $ref,
                'status'           => $template->status,
                'name'             => $template->name,
                'organisation'     => $template->organisation,
                'email'            => $template->email,
                'phone'            => $template->phone,
                'address'          => $template->address,
                'space'            => $template->space,
                'kitchen'          => (int) $template->kitchen,
                'booking_date'     => $date_str,
                'booking_date_end' => $date_str,
                'all_day'          => $all_day ? 1 : 0,
                'scout_use'        => (int) $template->scout_use,
                'start_time'       => $all_day ? null : $template->start_time,
                'end_time'         => $all_day ? null : $template->end_time,
                'attendees'        => (int) $template->attendees,
                'purpose'          => $template->purpose,
                'amount'           => $amount,
                'amount_paid'      => 0,
                'invoice_number'   => $template->scout_use ? '' : 'INV-' . $ref,
                'series_id'        => $series_id,
                'modification_token' => wp_generate_password( 32, false ),
            ) );
            $wpdb->query( 'COMMIT' );

            // Keep Home Assistant in step for confirmed occurrences.
            if ( $template->status === 'confirmed' ) {
                $fresh = self::get( $ref );
                if ( $fresh ) {
                    MBS_HomeAssistant::notify( $fresh );
                    $wpdb->update( $table, array( 'ha_notified' => 1 ), array( 'ref' => $ref ) );
                }
            }

            $created++;
        }

        MBS_Audit_Log::log(
            $series_id,
            'series_extended',
            'Extended series by ' . $created . ' booking(s) up to ' . wp_date( 'Y-m-d', min( $end_ts, $d ) )
            . ( ! empty( $skipped ) ? '; skipped ' . count( $skipped ) . ' conflicting date(s)' : '' )
            . ( $count >= $max_occurrences ? ' (52-week cap reached — run again to extend further)' : '' )
        );

        return array(
            'created'     => $created,
            'skipped'     => $skipped,
            'cap_reached' => ( $count >= $max_occurrences && $d <= $end_ts ),
        );
    }

    /**
     * Reopen all *future* cancelled bookings in a series (booking_date >= today).
     *
     * Symmetric with cancel_series_future(): sets them back to 'confirmed'
     * without sending booker emails. Past bookings are not touched.
     *
     * @param string $series_id The SER-XXXXXX series reference.
     * @return int|false Number of rows reopened, or false on failure.
     */
    public static function reopen_series_future( $series_id ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        $today = wp_date( 'Y-m-d' );

        $affected = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE series_id = %s AND booking_date >= %s AND status = 'cancelled'",
            $series_id,
            $today
        ) );

        $result = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'confirmed'
             WHERE series_id = %s AND booking_date >= %s AND status = 'cancelled'",
            $series_id,
            $today
        ) );

        if ( $result === false ) return false;

        MBS_Audit_Log::log(
            $series_id,
            'series_reopen',
            'Reopened ' . (int) $result . ' future booking(s) in series (from ' . $today . ')'
        );

        // Re-notify Home Assistant so the calendar/automations pick them back up.
        foreach ( $affected as $booking ) {
            $booking->status = 'confirmed';
            MBS_HomeAssistant::notify( $booking );
            $wpdb->update( $table, array( 'ha_notified' => 1 ), array( 'ref' => $booking->ref ) );
        }

        return (int) $result;
    }

    /**
     * Permanently delete an ENTIRE series — every booking, past and future.
     *
     * This is a hard delete intended for admin clean-up (e.g. a series created
     * in error). It removes the historical record too, so callers should gate
     * it behind an administrator capability and a clear confirmation.
     *
     * @param string $series_id The SER-XXXXXX series reference.
     * @return int|false Number of rows deleted, or false on failure.
     */
    public static function delete_series( $series_id ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;

        // Notify HA to clear any active (confirmed) future bookings first.
        $today  = wp_date( 'Y-m-d' );
        $active = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE series_id = %s AND booking_date >= %s AND status IN ('confirmed','deposit_paid','paid')",
            $series_id,
            $today
        ) );
        foreach ( $active as $booking ) {
            MBS_HomeAssistant::notify_cancelled( $booking );
        }

        $result = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE series_id = %s",
            $series_id
        ) );

        if ( $result === false ) return false;

        MBS_Audit_Log::log(
            $series_id,
            'series_deleted',
            'Permanently deleted entire series (' . (int) $result . ' booking(s), past and future)'
        );

        return (int) $result;
    }

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
        $days_after = (int) get_option( 'mbs_auto_archive_days', 7 );
        $threshold  = wp_date( 'Y-m-d', strtotime( "-{$days_after} days" ) );
        return $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'archived' WHERE COALESCE(NULLIF(booking_date_end, ''), booking_date) < %s AND status IN ('confirmed', 'deposit_paid', 'cancelled')",
            $threshold
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
     *
     * @param bool $exclude_scout When true, internal Scout Night bookings
     *                            (scout_use = 1) are excluded from the
     *                            pending/confirmed/total counts so the figures
     *                            reflect public / revenue-generating hires only.
     */
    public static function get_stats( $exclude_scout = false ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;

        // Optional clause to strip internal Scout bookings from the metrics.
        $scout_clause = $exclude_scout ? ' AND scout_use = 0' : '';

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
            'total'      => (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status != 'archived'{$scout_clause}" ),
            'pending'    => (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='pending'{$scout_clause}" ),
            'confirmed'  => (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='confirmed'{$scout_clause}" ),
            'cancelled'  => (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='cancelled'{$scout_clause}" ),
            'archived'   => (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='archived'{$scout_clause}" ),
            'paid'       => (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='paid'{$scout_clause}" ),
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
