<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Explicit, canonical area sets. Parent resources remain ordinary resources, never aliases. */
class MBS_Space_Selection {
    const SEPARATOR = ' + ';
    private static $lock_depth = 0;

    public static function enabled() {
        return MBS_Campsite_Policy::enabled() && (bool) get_option( 'mbs_multi_space_enabled', false );
    }

    /** A set is stored losslessly in the existing space field; no synthetic resource is configured. */
    public static function members( $value ) {
        $value = (string) $value;
        if ( isset( MBS_Bookings::get_spaces()[$value] ) ) return array( $value );
        return array_values( array_unique( explode( self::SEPARATOR, $value ) ) );
    }

    public static function normalize( $value, $read_only = false ) {
        if ( ! is_array( $value ) && ! is_string( $value ) ) return self::error();
        $configured = MBS_Bookings::get_spaces();
        if ( is_string( $value ) && isset( $configured[$value] ) ) return $value;
        $members = is_array( $value ) ? $value : self::members( $value );
        if ( ! $members || count( $members ) > 20 ) return self::error();
        foreach ( $members as $name ) if ( ! is_string( $name ) || ! isset( $configured[$name] ) ) return self::error();
        $members = array_values( array_unique( $members ) ); sort( $members, SORT_STRING );
        if ( count( $members ) === 1 ) return $members[0];
        if ( ! $read_only && ! self::enabled() ) return self::error( 'Multiple areas are disabled. Use a single configured space.' );
        $parent = null;
        foreach ( $members as $name ) {
            $info = $configured[$name];
            // Only siblings with a real configured parent; never parent+child or arbitrary paid rooms.
            if ( strpos( $name, self::SEPARATOR ) !== false || empty( $info['parent'] ) || ! isset( $configured[$info['parent']] ) || ( $parent !== null && $parent !== $info['parent'] ) ) return self::error( 'Choose individual areas under the same parent, or choose the whole venue on its own.' );
            foreach ( $configured as $child ) if ( ( $child['parent'] ?? '' ) === $name ) return self::error( 'Whole venue resources must be booked on their own.' );
            $parent = $info['parent'];
        }
        $label = implode( self::SEPARATOR, $members );
        if ( strlen( $label ) > 255 || isset( $configured[$label] ) ) return self::error( 'This area selection cannot be represented unambiguously.' );
        return $label;
    }

    public static function info( $value ) {
        $spaces = MBS_Bookings::get_spaces();
        if ( isset( $spaces[$value] ) ) return $spaces[$value];
        $capacities = array();
        foreach ( self::members( $value ) as $name ) {
            if ( ! isset( $spaces[$name] ) || empty( $spaces[$name]['capacity'] ) ) return null;
            $capacities[] = (int) $spaces[$name]['capacity'];
        }
        // One group may move between areas: do not invent aggregate capacity.
        return array( 'capacity' => min( $capacities ), 'rate_hourly' => 0, 'rate_daily' => 0 );
    }

    public static function overlaps( $left, $right ) {
        foreach ( self::members( $left ) as $a ) foreach ( self::members( $right ) as $b ) {
            if ( $a === $b || in_array( $b, MBS_Bookings::get_related_spaces( $a ), true ) || in_array( $a, MBS_Bookings::get_related_spaces( $b ), true ) ) return true;
        }
        return false;
    }

    private static function error( $message = 'Choose valid configured areas.' ) {
        return new WP_Error( 'invalid_space_selection', $message, array( 'status' => 400 ) );
    }

    /** Serialize ALL campsite occupancy writers, including empty dates and parent selections. */
    public static function locked( $callback ) {
        // Paid single-space and whole-centre writers need the same empty-date lock.
        global $wpdb;
        $key = 'mbs_spaces_' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $wpdb->prefix ), 0, 32 );
        if ( self::$lock_depth === 0 && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $key ) ) !== 1 ) return new WP_Error( 'occupancy_busy', 'Another booking is being saved. Refresh availability and retry.' );
        self::$lock_depth++;
        try { return call_user_func( $callback ); }
        finally { self::$lock_depth--; if ( self::$lock_depth === 0 ) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ); }
    }

    public static function protect_allocations() {
        return MBS_Campsite_Policy::enabled() || get_option( 'mbs_multi_space_enabled', false ) || get_option( 'mbs_multi_space_ever_used', false );
    }

    public static function record_selection( $space ) {
        if ( count( self::members( $space ) ) > 1 ) update_option( 'mbs_multi_space_ever_used', 1, false );
    }

    public static function validate_configuration( $proposed ) {
        if ( ! self::protect_allocations() ) return true;
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT space FROM ' . $wpdb->prefix . MBS_TABLE . " WHERE status NOT IN ('cancelled','archived') AND space LIKE %s", '%' . $wpdb->esc_like( self::SEPARATOR ) . '%' ) );
        if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) return self::error( 'Cannot verify active area allocations. Settings were not changed.' );
        $current = MBS_Bookings::get_spaces();
        foreach ( $rows as $row ) foreach ( self::members( $row->space ) as $name ) {
            if ( ! isset( $proposed[$name] ) || ( $proposed[$name]['parent'] ?? '' ) !== ( $current[$name]['parent'] ?? '' ) || ! isset( $proposed[$current[$name]['parent'] ?? ''] ) ) return self::error( 'An active multi-area booking uses this area and parent. Move or cancel that booking before removing, renaming or reparenting the area.' );
        }
        return true;
    }

    /** Shared accessible selector enhancement, preserving the ordinary single-space dropdown. */
    public static function selector( $id, $current = '' ) {
        if ( ! self::enabled() && count( self::members( $current ) ) < 2 ) return;
        wp_enqueue_script( 'mbs-space-selection', MBS_PLUGIN_URL . 'public/space-selection.js', array(), MBS_VERSION, true );
        $config = array( 'target' => $id, 'spaces' => MBS_Bookings::get_spaces(), 'enabled' => self::enabled(), 'current' => $current );
        echo '<div class="mbs-space-selector" data-config="' . esc_attr( wp_json_encode( $config ) ) . '"></div>';
    }
}
