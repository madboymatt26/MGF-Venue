<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MBS_Bookings {

    // ── Pricing ────────────────────────────────────────────────────────────────
    public static function get_spaces() {
        return array(
            'Main Scout Hall' => array( 'rate' => 25, 'unit' => 'hr',  'capacity' => 80 ),
            'Meeting Room'    => array( 'rate' => 12, 'unit' => 'hr',  'capacity' => 20 ),
            'Outdoor Area'    => array( 'rate' => 40, 'unit' => 'day', 'capacity' => 100 ),
        );
    }

    public static function calculate_cost( $space, $start_time, $end_time, $kitchen = false ) {
        $spaces = self::get_spaces();
        if ( ! isset( $spaces[ $space ] ) ) return 0;

        $info = $spaces[ $space ];
        $cost = 0;

        if ( $info['unit'] === 'day' ) {
            $cost = $info['rate'];
        } elseif ( $start_time && $end_time ) {
            $start = strtotime( $start_time );
            $end   = strtotime( $end_time );
            $hours = ceil( max( 0, ( $end - $start ) / 3600 ) );
            $cost  = $hours * $info['rate'];
        }

        if ( $kitchen ) $cost += 10;
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

        $ref  = self::generate_ref();
        $cost = self::calculate_cost(
            sanitize_text_field( $data['space'] ),
            sanitize_text_field( $data['start_time'] ?? '' ),
            sanitize_text_field( $data['end_time'] ?? '' ),
            ! empty( $data['kitchen'] )
        );

        $insert = array(
            'ref'            => $ref,
            'status'         => 'pending',
            'name'           => sanitize_text_field( $data['name'] ),
            'organisation'   => sanitize_text_field( $data['organisation'] ?? '' ),
            'email'          => sanitize_email( $data['email'] ),
            'phone'          => sanitize_text_field( $data['phone'] ),
            'address'        => sanitize_textarea_field( $data['address'] ),
            'space'          => sanitize_text_field( $data['space'] ),
            'kitchen'        => ! empty( $data['kitchen'] ) ? 1 : 0,
            'booking_date'   => sanitize_text_field( $data['booking_date'] ),
            'start_time'     => ! empty( $data['start_time'] ) ? sanitize_text_field( $data['start_time'] ) : null,
            'end_time'       => ! empty( $data['end_time'] )   ? sanitize_text_field( $data['end_time'] )   : null,
            'attendees'      => absint( $data['attendees'] ),
            'purpose'        => sanitize_text_field( $data['purpose'] ),
            'notes'          => sanitize_textarea_field( $data['notes'] ?? '' ),
            'amount'         => $cost,
            'invoice_number' => 'INV-' . $ref,
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
        );
        $args = wp_parse_args( $args, $defaults );

        $where  = array( '1=1' );
        $values = array();

        if ( $args['status'] ) {
            $where[]  = 'status = %s';
            $values[] = $args['status'];
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
            "SELECT * FROM {$table} WHERE booking_date = %s AND status != 'cancelled' ORDER BY start_time ASC",
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
             WHERE booking_date BETWEEN %s AND %s AND status != 'cancelled'
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
        $allowed = array( 'pending', 'confirmed', 'cancelled' );
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

    public static function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        return array(
            'total'     => (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
            'pending'   => (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='pending'" ),
            'confirmed' => (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='confirmed'" ),
            'cancelled' => (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='cancelled'" ),
            'revenue'   => (float) $wpdb->get_var( "SELECT SUM(amount) FROM {$table} WHERE status='confirmed'" ),
        );
    }
}
