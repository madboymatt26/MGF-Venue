<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MBS_Blocked_Dates {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'mathlin_blocked_dates';
    }

    /**
     * Add a blocked date range.
     * @param string $date_from  Start date (Y-m-d)
     * @param string $date_to    End date (Y-m-d)
     * @param string $space      Space name, or empty for all spaces
     * @param string $reason     Reason for blocking
     */
    public static function add( $date_from, $date_to, $space = '', $reason = '' ) {
        global $wpdb;
        return $wpdb->insert( self::table(), array(
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'space'     => $space,
            'reason'    => $reason,
        ) );
    }

    /**
     * Delete a blocked date entry.
     */
    public static function delete( $id ) {
        global $wpdb;
        return $wpdb->delete( self::table(), array( 'id' => absint( $id ) ), array( '%d' ) );
    }

    /**
     * Get all blocked date entries, ordered by date_from.
     */
    public static function get_all() {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY date_from ASC" );
    }

    /**
     * Get blocked entries that overlap with a specific date range.
     */
    public static function get_for_range( $from, $to ) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE date_from <= %s AND date_to >= %s",
            $to, $from
        ) );
    }

    /**
     * Check if a specific date and space is blocked.
     * @param string $date   Date to check (Y-m-d)
     * @param string $space  Space name to check
     * @return array|false   Returns the blocking entry or false if not blocked
     */
    public static function is_blocked( $date, $space = '' ) {
        global $wpdb;
        $table = self::table();

        // Check for blocks that cover this date and either all spaces or this specific space
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE date_from <= %s AND date_to >= %s AND (space = '' OR space = %s)",
            $date, $date, $space
        ) );

        if ( ! empty( $results ) ) {
            return $results[0];
        }
        return false;
    }

    /**
     * Get blocked dates for a given month (for calendar display).
     * Returns an array of date => reason/space info.
     */
    public static function get_for_month( $year, $month ) {
        $from = sprintf( '%04d-%02d-01', $year, $month );
        $to   = date( 'Y-m-t', strtotime( $from ) );

        $entries = self::get_for_range( $from, $to );
        $blocked = array();

        foreach ( $entries as $entry ) {
            $start = max( strtotime( $from ), strtotime( $entry->date_from ) );
            $end   = min( strtotime( $to ), strtotime( $entry->date_to ) );

            for ( $d = $start; $d <= $end; $d += 86400 ) {
                $date_str = date( 'Y-m-d', $d );
                $blocked[ $date_str ][] = array(
                    'space'  => $entry->space ?: 'All spaces',
                    'reason' => $entry->reason,
                );
            }
        }

        return $blocked;
    }
}
