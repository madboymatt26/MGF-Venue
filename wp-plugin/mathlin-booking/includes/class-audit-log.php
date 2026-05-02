<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Audit Log — tracks all booking actions with who, what, and when.
 *
 * Stored in a separate table: wp_mathlin_audit_log
 */
class MBS_Audit_Log {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'mathlin_audit_log';
    }

    /**
     * Log an action.
     *
     * @param string $ref      Booking reference (or series ID)
     * @param string $action   Action type: created, confirmed, cancelled, paid, archived, deleted, reopened, notes_updated, series_confirmed, series_cancelled, reminder_sent
     * @param string $details  Human-readable description
     * @param int    $user_id  WordPress user ID (0 for system/public actions)
     */
    public static function log( $ref, $action, $details = '', $user_id = null ) {
        global $wpdb;

        if ( $user_id === null ) {
            $user_id = get_current_user_id(); // 0 if not logged in
        }

        $user_name = '';
        if ( $user_id > 0 ) {
            $user = get_userdata( $user_id );
            $user_name = $user ? $user->display_name : 'User #' . $user_id;
        } else {
            $user_name = 'System';
        }

        $wpdb->insert( self::table(), array(
            'ref'        => $ref,
            'action'     => $action,
            'details'    => $details,
            'user_id'    => $user_id,
            'user_name'  => $user_name,
            'ip_address' => self::get_ip(),
            'created_at' => current_time( 'mysql' ),
        ) );
    }

    /**
     * Get audit log entries for a specific booking.
     */
    public static function get_for_booking( $ref ) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE ref = %s ORDER BY created_at DESC",
            $ref
        ) );
    }

    /**
     * Get recent audit log entries (for admin overview).
     */
    public static function get_recent( $limit = 50 ) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d",
            $limit
        ) );
    }

    /**
     * Get a human-readable label for an action type.
     */
    public static function action_label( $action ) {
        $labels = array(
            'created'           => '📝 Booking Created',
            'confirmed'         => '✅ Confirmed',
            'cancelled'         => '❌ Cancelled',
            'paid'              => '💰 Marked as Paid',
            'archived'          => '📦 Archived',
            'deleted'           => '🗑️ Deleted',
            'reopened'          => '↩️ Reopened',
            'notes_updated'     => '📝 Admin Notes Updated',
            'series_confirmed'  => '✅ Series Confirmed',
            'series_cancelled'  => '❌ Series Cancelled',
            'reminder_sent'     => '📧 Reminder Sent',
            'status_changed'    => '🔄 Status Changed',
        );
        return $labels[ $action ] ?? ucfirst( str_replace( '_', ' ', $action ) );
    }

    /**
     * Get the client IP address.
     */
    private static function get_ip() {
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ips = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
            return trim( $ips[0] );
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
