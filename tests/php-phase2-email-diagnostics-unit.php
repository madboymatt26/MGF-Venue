<?php
/**
 * Unit and smoke assertions for Phase 2 (Schema 3.48.0-schema-16 & Interactive Email Diagnostics).
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'MBS_PLUGIN_DIR', dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/' );
define( 'MBS_PLUGIN_URL', 'https://example.test/wp-content/plugins/mathlin-booking/' );
define( 'MBS_VERSION', '3.47.2' );
define( 'MBS_DB_VERSION', '3.48.0-schema-16' );
define( 'MBS_TABLE', 'mathlin_bookings' );
define( 'MBS_SERIES_TABLE', 'mathlin_booking_series' );
define( 'MBS_INVOICE_TABLE', 'mathlin_invoices' );
define( 'MBS_INVOICE_ITEM_TABLE', 'mathlin_invoice_items' );
define( 'MBS_PAYMENT_TRANSACTION_TABLE', 'mathlin_payment_transactions' );
define( 'MBS_BILLING_ALLOCATION_TABLE', 'mathlin_billing_allocations' );
define( 'MBS_PAYMENT_RESERVATION_TABLE', 'mathlin_payment_reservations' );
define( 'MBS_OSM_OUTBOX_TABLE', 'mathlin_osm_outbox' );
define( 'MBS_OSM_PAYOUT_TABLE', 'mathlin_osm_payouts' );
define( 'MBS_INVOICE_DOCUMENTS_TABLE', 'mathlin_invoice_documents' );
define( 'MBS_DOCUMENT_ASSETS_TABLE', 'mathlin_document_assets' );
define( 'MBS_DOWNLOAD_TOKENS_TABLE', 'mathlin_download_tokens' );
if ( ! defined( 'HOUR_IN_SECONDS' ) ) define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['p2_actions']      = array();
$GLOBALS['p2_audit']        = array();
$GLOBALS['p2_mail_mode']    = 'ok';
$GLOBALS['p2_caps']         = array();
$GLOBALS['p2_json_result']  = null;

class WP_Error {
    public $code;
    public $message;
    public $data;
    public function __construct( $code = '', $message = '', $data = '' ) {
        $this->code    = (string) $code;
        $this->message = (string) $message;
        $this->data    = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return trim( (string) $s ); }
function absint( $v ) { return abs( (int) $v ); }
function current_time( $t = 'mysql' ) { return '2026-11-20 12:00:00'; }
function wp_date( $fmt, $ts = null ) { return gmdate( $fmt, $ts ?? time() ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( $p, '/' ); }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function get_current_user_id() { return 42; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['p2_caps'][ $cap ] ); }
function check_ajax_referer( $action, $query_arg = false ) { return true; }
function wp_send_json_success( $data = null, $status = 200 ) { $GLOBALS['p2_json_result'] = array( 'success' => true, 'status' => $status, 'data' => $data ); throw new RuntimeException( '__JSON_EXIT__' ); }
function wp_send_json_error( $data = null, $status = 400 ) { $GLOBALS['p2_json_result'] = array( 'success' => false, 'status' => $status, 'data' => $data ); throw new RuntimeException( '__JSON_EXIT__' ); }

function add_action( $tag, $cb, $prio = 10, $args = 1 ) {
    $GLOBALS['p2_actions'][ $tag ][ $prio ][] = $cb;
}
function remove_action( $tag, $cb, $prio = 10 ) {
    if ( empty( $GLOBALS['p2_actions'][ $tag ][ $prio ] ) ) return false;
    foreach ( $GLOBALS['p2_actions'][ $tag ][ $prio ] as $i => $existing ) {
        if ( $existing === $cb ) {
            unset( $GLOBALS['p2_actions'][ $tag ][ $prio ][ $i ] );
            return true;
        }
    }
    return false;
}
function do_action( $tag, ...$args ) {
    if ( empty( $GLOBALS['p2_actions'][ $tag ] ) ) return;
    ksort( $GLOBALS['p2_actions'][ $tag ] );
    foreach ( $GLOBALS['p2_actions'][ $tag ] as $cbs ) {
        foreach ( $cbs as $cb ) $cb( ...$args );
    }
}
function wp_mail( $to, $subject, $body, $headers = array(), $attachments = array() ) {
    if ( $GLOBALS['p2_mail_mode'] === 'ok' ) return true;
    if ( $GLOBALS['p2_mail_mode'] === 'throw' ) throw new RuntimeException( 'SMTP socket timed out', 421 );
    if ( $GLOBALS['p2_mail_mode'] === 'wp_mail_failed' ) {
        do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP Error: Could not authenticate (535).', array( 'phpmailer_exception_code' => 2, 'to' => array( $to ), 'attachments' => $attachments ) ) );
        return false;
    }
    return false;
}

class MBS_Audit_Log {
    public static function log( $ref, $action, $details ) {
        $GLOBALS['p2_audit'][] = array( 'ref' => $ref, 'action' => $action, 'details' => $details );
        return true;
    }
}

class Phase2QueueDb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 0;
    public $rows = array();
    public $last_query = '';
    public function prepare( $sql, ...$args ) {
        if ( count( $args ) === 1 && is_array( $args[0] ) ) $args = $args[0];
        $this->last_query = $sql;
        foreach ( $args as $a ) {
            $sql = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . addslashes( (string) $a ) . "'", $sql, 1 );
        }
        return $sql;
    }
    public function query( $sql ) {
        $this->last_query = $sql;
        if ( stripos( $sql, 'START TRANSACTION' ) === 0 || stripos( $sql, 'COMMIT' ) === 0 || stripos( $sql, 'ROLLBACK' ) === 0 ) return true;
        if ( preg_match( "/UPDATE wp_mathlin_email_queue\s+SET status = 'failed'.*WHERE id = (\d+)/is", $sql, $m ) ) {
            $id = (int) $m[1];
            if ( isset( $this->rows[ $id ] ) ) {
                $this->rows[ $id ]->status = 'failed';
                if ( preg_match( "/last_error = '([^']*)'/", $sql, $em ) ) $this->rows[ $id ]->last_error = stripslashes( $em[1] );
                if ( preg_match( "/failure_category = '([^']*)'/", $sql, $cm ) ) $this->rows[ $id ]->failure_category = stripslashes( $cm[1] );
                if ( preg_match( "/last_error_code = '([^']*)'/", $sql, $km ) ) $this->rows[ $id ]->last_error_code = stripslashes( $km[1] );
                if ( preg_match( "/failed_at = '([^']*)'/", $sql, $fm ) ) $this->rows[ $id ]->failed_at = stripslashes( $fm[1] );
                if ( preg_match( "/last_attempt_at = '([^']*)'/", $sql, $am ) ) $this->rows[ $id ]->last_attempt_at = stripslashes( $am[1] );
                $this->rows[ $id ]->attempts = (int) $this->rows[ $id ]->attempts + 1;
                return 1;
            }
            return 0;
        }
        if ( preg_match( "/UPDATE wp_mathlin_email_queue\s+SET status = 'processing'.*AND id=(\d+)/is", $sql, $m ) ) {
            $id = (int) $m[1];
            if ( isset( $this->rows[ $id ] ) && $this->rows[ $id ]->status === 'pending' ) {
                $this->rows[ $id ]->status = 'processing';
                if ( preg_match( "/worker_id = '([^']+)'/", $sql, $wm ) ) $this->rows[ $id ]->worker_id = $wm[1];
                return 1;
            }
            return 0;
        }
        if ( preg_match( "/UPDATE wp_mathlin_email_queue\s+SET status = 'sent'.*WHERE id = (\d+)/is", $sql, $m ) ) {
            $id = (int) $m[1];
            if ( isset( $this->rows[ $id ] ) ) {
                $this->rows[ $id ]->status = 'sent';
                $this->rows[ $id ]->accepted_at = '2026-11-20 12:00:00';
                $this->rows[ $id ]->last_attempt_at = '2026-11-20 12:00:00';
                $this->rows[ $id ]->attempts = (int) $this->rows[ $id ]->attempts + 1;
                return 1;
            }
            return 0;
        }
        return 0;
    }
    public function insert( $table, $data ) {
        $id = count( $this->rows ) + 100;
        $this->insert_id = $id;
        $data['id'] = $id;
        $this->rows[ $id ] = (object) $data;
        return 1;
    }
    public function update( $table, $data, $where ) {
        $id = (int) ( $where['id'] ?? 0 );
        if ( isset( $this->rows[ $id ] ) ) {
            foreach ( $data as $k => $v ) $this->rows[ $id ]->$k = $v;
            return 1;
        }
        return 0;
    }
    public function get_var( $sql ) {
        if ( stripos( $sql, 'SELECT COUNT(*)' ) === 0 ) return count( $this->rows );
        if ( stripos( $sql, 'SELECT ref FROM wp_mathlin_bookings' ) === 0 ) return 'MBS-1234ABCD';
        return null;
    }
    public function get_row( $sql ) {
        if ( stripos( $sql, 'SUM(status=' ) !== false ) {
            $pending = $processing = $sent = $failed = $transport = $obsolete = $dismissed = 0;
            foreach ( $this->rows as $r ) {
                if ( $r->status === 'pending' ) $pending++;
                elseif ( $r->status === 'processing' ) $processing++;
                elseif ( $r->status === 'sent' ) $sent++;
                elseif ( $r->status === 'failed' ) {
                    $cat = (string) ( $r->failure_category ?? '' );
                    if ( $cat === 'dismissed' ) { $dismissed++; }
                    else {
                        $failed++;
                        if ( in_array( $cat, array( 'stale_source', 'policy_expired' ), true ) ) $obsolete++;
                        else $transport++;
                    }
                }
            }
            return (object) array(
                'pending' => $pending, 'processing' => $processing, 'sent' => $sent,
                'failed' => $failed, 'transport_failed' => $transport,
                'obsolete_failed' => $obsolete, 'dismissed_failed' => $dismissed,
                'oldest_pending_at' => null, 'next_due_at' => null, 'expired_leases' => 0,
            );
        }
        if ( preg_match( '/WHERE id = (\d+)/i', $sql, $m ) ) {
            return $this->rows[ (int) $m[1] ] ?? null;
        }
        return null;
    }
    public function get_results( $sql ) {
        if ( stripos( $sql, "WHERE status = 'processing' AND worker_id =" ) !== false ) {
            $out = array();
            foreach ( $this->rows as $r ) if ( $r->status === 'processing' ) $out[] = $r;
            return $out;
        }
        if ( stripos( $sql, 'FROM wp_mathlin_bookings' ) !== false ) {
            return array( (object) array( 'id' => 7, 'ref' => 'MBS-1234ABCD', 'name' => 'Test Hirer' ) );
        }
        return array_values( $this->rows );
    }
}
$wpdb = new Phase2QueueDb();

require_once MBS_PLUGIN_DIR . 'includes/class-email-queue.php';
require_once MBS_PLUGIN_DIR . 'admin/class-admin.php';

$checks = 0;
function p2_assert( $cond, $msg ) {
    global $checks;
    $checks++;
    if ( ! $cond ) {
        fwrite( STDERR, "FAIL: {$msg}\n" );
        exit( 1 );
    }
}

// 1. Schema version & migration SQL checks
$main_src = file_get_contents( MBS_PLUGIN_DIR . 'mathlin-booking.php' );
$db_src   = file_get_contents( MBS_PLUGIN_DIR . 'includes/class-database.php' );
p2_assert( strpos( $main_src, "'3.48.0-schema-16'" ) !== false, 'MBS_DB_VERSION bumped to 3.48.0-schema-16.' );
foreach ( array( 'failure_category', 'last_error_code', 'last_error_details', 'last_attempt_at', 'failed_at', 'resent_from_id', 'resent_by', 'related_ref', 'DROP INDEX', 'idx_queue_reference', 'idx_queue_failed', 'idx_item_related_ref' ) as $needle ) {
    p2_assert( strpos( $db_src, $needle ) !== false, "class-database.php contains {$needle}." );
}
$bookings_ddl = substr( $db_src, 0, (int) strpos( $db_src, '$series_table' ) );
p2_assert( strpos( $bookings_ddl, 'idx_ref' ) === false, 'Duplicate bookings idx_ref removed from canonical DDL.' );
p2_assert( strpos( $db_src, 'KEY idx_queue_processing' ) === false, 'Duplicate queue idx_queue_processing removed from canonical DDL.' );

// 2. deliver_via_wp_mail(): wp_mail_failed capture, exception handling, and listener cleanup in finally
$GLOBALS['p2_mail_mode'] = 'wp_mail_failed';
$res = MBS_Email_Queue::deliver_via_wp_mail( 'hirer@example.test', 'Subj', '<p>Secret code 9999</p>' );
p2_assert( $res['ok'] === false, 'wp_mail_failed returns ok=false.' );
p2_assert( $res['error_code'] === 'wp_mail_failed', 'Captures wp_mail_failed error code.' );
p2_assert( strpos( $res['error_message'], 'Could not authenticate (535)' ) !== false, 'Captures exact SMTP error message.' );
p2_assert( ( $res['error_details']['phpmailer_exception_code'] ?? 0 ) === 2, 'Captures phpmailer_exception_code safely.' );
p2_assert( empty( $GLOBALS['p2_actions']['wp_mail_failed'][ PHP_INT_MAX ] ), 'wp_mail_failed listener removed in finally after failure.' );

$GLOBALS['p2_mail_mode'] = 'throw';
$res = MBS_Email_Queue::deliver_via_wp_mail( 'hirer@example.test', 'Subj', '<p>Body</p>' );
p2_assert( $res['ok'] === false && $res['error_code'] === 'mail_exception', 'Catches Throwable from wp_mail().' );
p2_assert( empty( $GLOBALS['p2_actions']['wp_mail_failed'][ PHP_INT_MAX ] ), 'wp_mail_failed listener removed in finally after exception.' );

$GLOBALS['p2_mail_mode'] = 'ok';
$res = MBS_Email_Queue::deliver_via_wp_mail( 'hirer@example.test', 'Subj', '<p>Body</p>' );
p2_assert( $res['ok'] === true, 'deliver_via_wp_mail returns ok=true on success.' );
p2_assert( empty( $GLOBALS['p2_actions']['wp_mail_failed'][ PHP_INT_MAX ] ), 'wp_mail_failed listener removed in finally after success.' );

// 3. release_for_retry() on final attempt calls mark_failed() preserving error details & writes audit log
$wpdb->rows[1] = (object) array(
    'id' => 1, 'to_email' => 'hirer@example.test', 'subject' => 'Access Details',
    'body' => '<p>KEYSAFE CODE 4321</p>', 'headers' => '[]', 'attachments' => '[]',
    'attempts' => 4, 'status' => 'processing', 'worker_id' => 'w1',
    'message_type' => 'access_details', 'reference_type' => 'booking', 'reference_id' => 7,
    'message_key' => 'access:abc', 'payload_hash' => 'hash1', 'attachment_meta' => null,
    'created_at' => '2026-11-20 10:00:00', 'last_error' => '', 'failure_category' => null,
);
$GLOBALS['p2_audit'] = array();
MBS_Email_Queue::release_for_retry( 1, 'w1', 'SMTP 535 Authentication failed', 'wp_mail_failed', 'transport_error', array( 'phpmailer_exception_code' => 2 ) );
p2_assert( $wpdb->rows[1]->status === 'failed', 'Final retry transitions row to failed.' );
p2_assert( $wpdb->rows[1]->last_error === 'SMTP 535 Authentication failed', 'Final failure preserves last_error.' );
p2_assert( $wpdb->rows[1]->last_error_code === 'wp_mail_failed', 'Final failure preserves last_error_code.' );
p2_assert( $wpdb->rows[1]->failure_category === 'transport_error', 'Final failure sets failure_category=transport_error.' );
p2_assert( ! empty( $wpdb->rows[1]->failed_at ) && ! empty( $wpdb->rows[1]->last_attempt_at ), 'Final failure records failed_at and last_attempt_at.' );
p2_assert( count( $GLOBALS['p2_audit'] ) === 1 && $GLOBALS['p2_audit'][0]['action'] === 'email_failed', 'Terminal transport failure logs email_failed to MBS_Audit_Log.' );

// 4. failure_category separation in get_stats() + payload sanitization (NO body/headers/attachments!)
$wpdb->rows[2] = (object) array(
    'id' => 2, 'to_email' => 'stale@example.test', 'subject' => 'Old Reminder',
    'body' => '<p>SECRET BODY SHOULD NEVER APPEAR</p>', 'headers' => '["X-Secret: 1"]', 'attachments' => '["/tmp/a.pdf"]',
    'attempts' => 1, 'status' => 'failed', 'failure_category' => 'stale_source',
    'last_error_code' => 'reminder_stale', 'last_error' => 'Booking changed.',
    'message_type' => 'booking_reminder', 'reference_type' => 'booking', 'reference_id' => 7,
    'created_at' => '2026-11-20 09:00:00',
);
$wpdb->rows[3] = (object) array(
    'id' => 3, 'to_email' => 'dismissed@example.test', 'subject' => 'Dismissed',
    'body' => '<p>Dismissed body</p>', 'headers' => '[]', 'attachments' => '[]',
    'attempts' => 5, 'status' => 'failed', 'failure_category' => 'dismissed',
    'message_type' => 'booking_confirmation', 'reference_type' => 'booking', 'reference_id' => 7,
    'created_at' => '2026-11-20 08:00:00',
);

$stats = MBS_Email_Queue::get_stats();
p2_assert( $stats['transport_failed'] === 1, 'get_stats() counts 1 transport_failed.' );
p2_assert( $stats['obsolete_failed'] === 1, 'get_stats() counts 1 obsolete_failed.' );
p2_assert( $stats['dismissed_failed'] === 1, 'get_stats() counts 1 dismissed_failed.' );
p2_assert( $stats['failed'] === 2, 'get_stats() failed excludes dismissed rows (1 transport + 1 obsolete).' );

$diag = MBS_Email_Queue::query_diagnostics( array( 'status' => 'failed' ) );
p2_assert( ! empty( $diag['items'] ), 'query_diagnostics() returns diagnostic items.' );
foreach ( $diag['items'] as $item ) {
    p2_assert( ! array_key_exists( 'body', $item ), 'Diagnostic payload never includes body.' );
    p2_assert( ! array_key_exists( 'headers', $item ), 'Diagnostic payload never includes headers.' );
    p2_assert( ! array_key_exists( 'attachments', $item ), 'Diagnostic payload never includes attachments.' );
}
$serialized_diag = json_encode( $diag );
p2_assert( strpos( $serialized_diag, 'KEYSAFE CODE 4321' ) === false, 'Keysafe code from email body is never leaked in diagnostics JSON.' );
p2_assert( strpos( $serialized_diag, 'SECRET BODY' ) === false, 'Raw email body is never leaked in diagnostics JSON.' );

// 5. Resend blocks stale_source (409), clones transport failure with resent_from_id/resent_by
$stale_resend = MBS_Email_Queue::resend_row( 2, 42 );
p2_assert( is_wp_error( $stale_resend ) && $stale_resend->get_error_code() === 'email_obsolete', 'resend_row() blocks stale_source with email_obsolete.' );

// Switch row 1 to generic message_type so dispatch_message() runs cleanly without full MBS_Access_Details mock
$wpdb->rows[1]->message_type = 'booking_edit_custom';
$GLOBALS['p2_mail_mode'] = 'ok';
$resent = MBS_Email_Queue::resend_row( 1, 42 );
p2_assert( ! is_wp_error( $resent ), 'resend_row() succeeds for transport_error row.' );
p2_assert( (int) $resent['resent_from_id'] === 1 && (int) $resent['new_id'] >= 100, 'resend_row() clones into a new row ID.' );
p2_assert( $resent['row']['status'] === 'sent', 'resend_row() dispatches immediately and reports sent status.' );
p2_assert( (int) $resent['row']['resent_from_id'] === 1 && (int) $resent['row']['resent_by'] === 42, 'Cloned row tracks resent_from_id and resent_by.' );
p2_assert( ! array_key_exists( 'body', $resent['row'] ), 'resend_row() response never includes body.' );

// 6. Permissions: Booking Manager can list/resend but CANNOT dismiss; Admin CAN dismiss
$admin = new MBS_Admin();
$GLOBALS['p2_caps'] = array( 'mbs_manage_bookings' => true, 'manage_options' => false );
$_POST = array( 'id' => 1 );
try { $admin->ajax_email_queue_dismiss(); } catch ( RuntimeException $e ) {}
p2_assert( $GLOBALS['p2_json_result']['success'] === false && $GLOBALS['p2_json_result']['status'] === 403, 'Booking manager receives 403 on ajax_email_queue_dismiss.' );

$GLOBALS['p2_caps'] = array( 'mbs_manage_bookings' => true, 'manage_options' => true );
try { $admin->ajax_email_queue_dismiss(); } catch ( RuntimeException $e ) {}
p2_assert( $GLOBALS['p2_json_result']['success'] === true && $wpdb->rows[1]->failure_category === 'dismissed', 'Administrator can dismiss a failed email row.' );

// 7. Payment Chaser uses MBS_Email_Queue (no raw wp_mail) and all 5 admin surfaces are wired
$chaser_src = file_get_contents( MBS_PLUGIN_DIR . 'includes/class-payment-chaser.php' );
p2_assert( strpos( $chaser_src, 'wp_mail(' ) === false, 'Raw wp_mail() removed from class-payment-chaser.php.' );
p2_assert( strpos( $chaser_src, 'MBS_Email_Queue::enqueue' ) !== false, 'class-payment-chaser.php queues emails via MBS_Email_Queue.' );

$surfaces = array(
    'admin/views/partials/email-diagnostics-modal.php' => 'mbs-email-diagnostics-modal',
    'admin/views/single.php'                           => 'mbs-open-email-diagnostics',
    'includes/class-integration-health.php'            => 'mbs-open-email-diagnostics',
    'admin/views/analytics.php'                        => 'mbs-open-email-diagnostics',
    'includes/class-attention.php'                     => 'open_email_queue=failed',
    'includes/class-dashboard-widget.php'              => 'open_email_queue=failed',
    'admin/views/additional-charges.php'               => 'mbs-open-email-diagnostics',
    'includes/class-additional-charges.php'            => 'mbs-open-email-diagnostics',
    'includes/class-rest-api.php'                      => '/admin/email-queue',
);
foreach ( $surfaces as $rel => $needle ) {
    p2_assert( strpos( file_get_contents( MBS_PLUGIN_DIR . $rel ), $needle ) !== false, "{$rel} wired with {$needle}." );
}

echo "PHASE2_EMAIL_DIAGNOSTICS_OK ({$checks} checks passed)\n";
