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

    public static function get_bank_details() {
        return array(
            'sort_code'      => get_option( 'mbs_bank_sort_code', '12-34-56' ),
            'account_number' => get_option( 'mbs_bank_account_number', '12345678' ),
            'account_name'   => get_option( 'mbs_bank_account_name', 'Needham Market Scout Group' ),
            'payment_days'   => (int) get_option( 'mbs_payment_terms_days', 14 ),
        );
    }

    public static function calculate_cost( $space, $start_time, $end_time, $kitchen = false, $all_day = false, $num_days = 1 ) {
        $spaces = self::get_spaces();
        if ( ! isset( $spaces[ $space ] ) ) return 0;

        $info = $spaces[ $space ];
        $cost = 0;

        if ( $all_day ) {
            $rate_daily = isset( $info['rate_daily'] ) ? (float) $info['rate_daily'] : ( isset( $info['rate'] ) ? (float) $info['rate'] : 0 );
            $cost = $rate_daily * max( 1, $num_days );
        } elseif ( $start_time && $end_time ) {
            $rate_hourly = isset( $info['rate_hourly'] ) ? (float) $info['rate_hourly'] : ( isset( $info['rate'] ) ? (float) $info['rate'] : 0 );
            $start = strtotime( $start_time );
            $end   = strtotime( $end_time );
            $hours = ceil( max( 0, ( $end - $start ) / 3600 ) );
            $cost  = $hours * $rate_hourly;
        }

        if ( $kitchen ) $cost += self::get_kitchen_price();
        return $cost;
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

        $cost = self::calculate_cost(
            sanitize_text_field( $data['space'] ),
            sanitize_text_field( $data['start_time'] ?? '' ),
            sanitize_text_field( $data['end_time'] ?? '' ),
            ! empty( $data['kitchen'] ),
            $all_day,
            $num_days
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
            'start_time'       => ! empty( $data['start_time'] ) ? sanitize_text_field( $data['start_time'] ) : null,
            'end_time'         => ! empty( $data['end_time'] )   ? sanitize_text_field( $data['end_time'] )   : null,
            'attendees'        => absint( $data['attendees'] ),
            'purpose'          => sanitize_text_field( $data['purpose'] ),
            'notes'            => sanitize_textarea_field( $data['notes'] ?? '' ),
            'amount'           => $cost,
            'invoice_number'   => 'INV-' . $ref,
        );

        $result = $wpdb->insert( $table, $insert );
        if ( $result === false ) {
            return new WP_Error( 'db_error', 'Could not save booking.' );
        }
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
            $values[] = $like; $values[] = $like; $values[] = $like;
            $values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
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
        $to      = date( 'Y-m-t', strtotime( $from ) );
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT booking_date, COUNT(*) as count FROM {$table}
             WHERE booking_date BETWEEN %s AND %s AND status NOT IN ('cancelled', 'archived')
             GROUP BY booking_date",
            $from, $to
        ) );
        $map = array();
        foreach ( $results as $row ) {
            $map[ $row->booking_date ] = (int) $row->count;
        }
        return $map;
    }

    public static function update_status( $ref, $status ) {
        global $wpdb;
        $table   = $wpdb->prefix . MBS_TABLE;
        $allowed = array( 'pending', 'confirmed', 'cancelled', 'archived', 'paid' );
        if ( ! in_array( $status, $allowed ) ) return false;

        $result = $wpdb->update(
            $table,
            array( 'status' => $status ),
            array( 'ref'    => $ref ),
            array( '%s' ), array( '%s' )
        );

        if ( $result !== false && $status === 'confirmed' ) {
            $booking = self::get( $ref );
            if ( $booking ) {
                MBS_HomeAssistant::notify( $booking );
                $wpdb->update( $table, array( 'ha_notified' => 1 ), array( 'ref' => $ref ) );
            }
        }

        if ( $result !== false && $status === 'cancelled' ) {
            $booking = self::get( $ref );
            if ( $booking ) MBS_HomeAssistant::notify_cancelled( $booking );
        }

        return $result;
    }

    public static function delete( $ref ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
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
            $date_str = date( 'Y-m-d', $current );

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
        $allowed = array( 'pending', 'confirmed', 'cancelled', 'archived', 'paid' );
        if ( ! in_array( $status, $allowed ) ) return false;

        $result = $wpdb->update(
            $table,
            array( 'status' => $status ),
            array( 'series_id' => $series_id ),
            array( '%s' ), array( '%s' )
        );

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
        $today = date( 'Y-m-d' );
        return $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'archived' WHERE booking_date < %s AND status IN ('confirmed', 'cancelled')",
            $today
        ) );
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
                "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE status IN ('confirmed', 'paid') AND booking_date BETWEEN %s AND %s",
                $fy_start, $fy_end
            ) ),
            'fy_label'   => $fy_label,
        );
    }
}
