<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Email Queue — queues failed emails and retries them via WP-Cron.
 *
 * Wraps wp_mail() with error detection. If an email fails, it's stored
 * in a database table and retried up to 3 times with exponential backoff.
 */
class MBS_Email_Queue {

    private static $table_suffix = 'mathlin_email_queue';

    public function init() {
        add_action( 'mbs_process_email_queue', array( $this, 'process_queue' ) );

        if ( ! wp_next_scheduled( 'mbs_process_email_queue' ) ) {
            wp_schedule_event( time() + 300, 'hourly', 'mbs_process_email_queue' );
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'mbs_process_email_queue' );
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . self::$table_suffix;
    }

    /**
     * Send an email with automatic queue-on-failure.
     * Drop-in replacement for wp_mail() with retry support.
     *
     * @return bool  True if sent immediately, false if queued for retry
     */
    public static function send( $to, $subject, $body, $headers = array(), $attachments = array() ) {
        $result = wp_mail( $to, $subject, $body, $headers, $attachments );

        if ( ! $result ) {
            self::queue( $to, $subject, $body, $headers, $attachments );
            error_log( "[Mathlin Booking] Email to {$to} failed, queued for retry." );
            return false;
        }

        return true;
    }

    /**
     * Add a failed email to the retry queue.
     */
    private static function queue( $to, $subject, $body, $headers, $attachments ) {
        global $wpdb;
        $wpdb->insert( self::table(), array(
            'to_email'    => $to,
            'subject'     => $subject,
            'body'        => $body,
            'headers'     => wp_json_encode( $headers ),
            'attachments' => wp_json_encode( $attachments ),
            'attempts'    => 0,
            'status'      => 'pending',
            'next_retry'  => current_time( 'mysql' ),
            'created_at'  => current_time( 'mysql' ),
        ) );
    }

    /**
     * Process the email queue — retry failed emails.
     * Called hourly by WP-Cron.
     */
    public function process_queue() {
        global $wpdb;
        $table = self::table();
        $now   = current_time( 'mysql' );

        $emails = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status = 'pending' AND next_retry <= %s
             ORDER BY created_at ASC LIMIT 20",
            $now
        ) );

        if ( empty( $emails ) ) return;

        $sent   = 0;
        $failed = 0;

        foreach ( $emails as $email ) {
            $headers     = json_decode( $email->headers, true ) ?: array();
            $attachments = json_decode( $email->attachments, true ) ?: array();
            $attempts    = (int) $email->attempts + 1;

            $result = wp_mail( $email->to_email, $email->subject, $email->body, $headers, $attachments );

            if ( $result ) {
                $wpdb->update( $table,
                    array( 'status' => 'sent', 'attempts' => $attempts ),
                    array( 'id' => $email->id )
                );
                $sent++;
            } else {
                if ( $attempts >= 3 ) {
                    // Give up after 3 attempts
                    $wpdb->update( $table,
                        array( 'status' => 'failed', 'attempts' => $attempts ),
                        array( 'id' => $email->id )
                    );
                    error_log( "[Mathlin Booking] Email to {$email->to_email} permanently failed after {$attempts} attempts." );
                } else {
                    // Exponential backoff: 1hr, 4hr, 16hr
                    $delay_hours = pow( 4, $attempts - 1 );
                    $next_retry  = date( 'Y-m-d H:i:s', strtotime( "+{$delay_hours} hours" ) );
                    $wpdb->update( $table,
                        array( 'attempts' => $attempts, 'next_retry' => $next_retry ),
                        array( 'id' => $email->id )
                    );
                }
                $failed++;
            }
        }

        if ( $sent > 0 || $failed > 0 ) {
            error_log( "[Mathlin Booking] Email queue: {$sent} sent, {$failed} failed/retrying." );
        }

        // UX-006: Clean up old entries
        self::cleanup();
    }

    /**
     * Get queue stats for the admin dashboard.
     */
    public static function get_stats() {
        global $wpdb;
        $table = self::table();

        // Check if table exists
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return array( 'pending' => 0, 'sent' => 0, 'failed' => 0 );
        }

        return array(
            'pending' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='pending'" ),
            'sent'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='sent'" ),
            'failed'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='failed'" ),
        );
    }

    /**
     * Clean up old sent/failed entries (older than 30 days).
     */
    public static function cleanup() {
        global $wpdb;
        $table = self::table();
        $cutoff = date( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE status IN ('sent', 'failed') AND created_at < %s",
            $cutoff
        ) );
    }
}
