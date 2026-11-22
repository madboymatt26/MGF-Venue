<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Email Queue / Transactional Outbox.
 *
 * Provides two APIs:
 *   send()    — legacy public wrapper: try wp_mail, queue on failure (unchanged behaviour)
 *   enqueue() — transactional insertion: caller inserts within their DB transaction,
 *               delivery happens asynchronously via the WP-Cron worker.
 *
 * Status state machine: pending → processing → sent | failed
 * Email delivery is at-least-once, not exactly-once.
 * Two concurrent cron workers cannot process the same row (compare-and-set claiming).
 */
class MBS_Email_Queue {

    const MAX_ATTEMPTS = 5;
    const LEASE_SECONDS = 300; // 5 minutes
    const WAKE_HOOK = 'mbs_email_after_commit';

    private static $table_suffix = 'mathlin_email_queue';

    public function init() {
        add_action( 'mbs_process_email_queue', array( $this, 'process_queue' ) );
        add_action( self::WAKE_HOOK, array( $this, 'process_queue' ) );
        add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
        $event = wp_get_scheduled_event( 'mbs_process_email_queue' );
        if ( $event && $event->schedule !== 'mbs_email_five_minutes' ) {
            wp_clear_scheduled_hook( 'mbs_process_email_queue' );
            $event = false;
        }
        if ( ! $event ) {
            wp_schedule_event( time() + 60, 'mbs_email_five_minutes', 'mbs_process_email_queue' );
        }
    }

    public function cron_schedules( $schedules ) {
        $schedules['mbs_email_five_minutes'] = array( 'interval' => 300, 'display' => 'Every five minutes (MGF email retries)' );
        return $schedules;
    }

    /** Dispatch exactly one committed outbox row, never an unrelated batch. */
    public static function dispatch_message( $message_id ) {
        if ( ! is_int( $message_id ) || $message_id < 1 ) return new WP_Error( 'invalid_email_id', 'A positive outbox message ID is required.' );
        return ( new self() )->process_queue( $message_id );
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'mbs_process_email_queue' );
        wp_clear_scheduled_hook( self::WAKE_HOOK );
    }
    /** SQL scheduling only. Call after commit; never launch HTTP under a booking lock. */
    public static function wake() {
        try {
            if(function_exists('wp_schedule_single_event')&&!wp_next_scheduled(self::WAKE_HOOK))return wp_schedule_single_event(time()+1,self::WAKE_HOOK);
            return true;
        }catch(Throwable $e){error_log('[MGF Venue] Post-commit email wake could not be scheduled; the regular outbox worker remains the fallback.');return false;}
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . self::$table_suffix;
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Send an email via wp_mail() while capturing exact PHPMailer / WP_Error
     * diagnostics via a scoped wp_mail_failed listener always removed in finally.
     *
     * @param string $to          Recipient email.
     * @param string $subject     Email subject.
     * @param string $body        Email body.
     * @param array  $headers     Email headers.
     * @param array  $attachments File attachment paths.
     * @return array{ok:bool,error_code:string,error_message:string,error_details:array}
     */
    public static function deliver_via_wp_mail( $to, $subject, $body, $headers = array(), $attachments = array() ) {
        $captured_error = null;
        $listener = static function ( $wp_error ) use ( &$captured_error ) {
            if ( is_wp_error( $wp_error ) ) {
                $captured_error = $wp_error;
            }
        };

        $hooked = function_exists( 'add_action' ) && function_exists( 'remove_action' );
        if ( $hooked ) {
            add_action( 'wp_mail_failed', $listener, PHP_INT_MAX, 1 );
        }

        try {
            $sent = (bool) wp_mail( $to, $subject, $body, $headers, $attachments );
            if ( $sent ) {
                return array(
                    'ok'            => true,
                    'error_code'    => '',
                    'error_message' => '',
                    'error_details' => array(),
                );
            }

            if ( is_wp_error( $captured_error ) ) {
                $code    = sanitize_key( (string) $captured_error->get_error_code() ) ?: 'wp_mail_failed';
                $message = sanitize_text_field( substr( (string) $captured_error->get_error_message(), 0, 500 ) );
                if ( $message === '' ) {
                    $message = 'Mail transport rejected the message.';
                }
                $raw_data = method_exists( $captured_error, 'get_error_data' ) ? $captured_error->get_error_data() : null;
                $details  = array( 'wp_error_code' => $code );
                if ( is_array( $raw_data ) ) {
                    if ( isset( $raw_data['phpmailer_exception_code'] ) ) {
                        $details['phpmailer_exception_code'] = (int) $raw_data['phpmailer_exception_code'];
                    }
                    if ( isset( $raw_data['to'] ) && is_array( $raw_data['to'] ) ) {
                        $details['recipient_count'] = count( $raw_data['to'] );
                    }
                    if ( ! empty( $raw_data['attachments'] ) && is_array( $raw_data['attachments'] ) ) {
                        $details['attachment_count'] = count( $raw_data['attachments'] );
                    }
                }
                return array(
                    'ok'            => false,
                    'error_code'    => $code,
                    'error_message' => $message,
                    'error_details' => $details,
                );
            }

            return array(
                'ok'            => false,
                'error_code'    => 'wp_mail_returned_false',
                'error_message' => 'wp_mail() returned false without firing wp_mail_failed.',
                'error_details' => array(),
            );
        } catch ( Throwable $e ) {
            return array(
                'ok'            => false,
                'error_code'    => 'mail_exception',
                'error_message' => sanitize_text_field( substr( $e->getMessage(), 0, 500 ) ) ?: 'Mail transport threw an exception.',
                'error_details' => array(
                    'exception_class' => get_class( $e ),
                    'exception_code'  => (int) $e->getCode(),
                ),
            );
        } finally {
            if ( $hooked ) {
                remove_action( 'wp_mail_failed', $listener, PHP_INT_MAX );
            }
        }
    }

    /**
     * Legacy public wrapper: try immediate send, queue on failure.
     * Existing callers continue using this method unchanged.
     *
     * @return bool True if sent immediately, false if queued for retry.
     */
    public static function send( $to, $subject, $body, $headers = array(), $attachments = array() ) {
        $delivery = self::deliver_via_wp_mail( $to, $subject, $body, $headers, $attachments );

        if ( ! $delivery['ok'] ) {
            self::legacy_queue( $to, $subject, $body, $headers, $attachments, $delivery );
            error_log( "[MGF Venue] Email to {$to} failed ({$delivery['error_code']}): {$delivery['error_message']}; queued for retry." );
            return false;
        }

        return true;
    }

    /**
     * Transactional enqueue: insert a durable queue record within the caller's
     * existing database transaction. Delivery happens asynchronously.
     *
     * @param string      $to              Recipient email.
     * @param string      $subject         Email subject.
     * @param string      $body            Email body (HTML).
     * @param array       $headers         Email headers.
     * @param string      $message_key     Unique deterministic idempotency key.
     * @param string      $payload_hash    SHA-256 of canonical message payload.
     * @param array|null  $attachment_meta  Document attachment metadata, e.g. {"document_id":123,"format":"pdf"}.
     * @param array       $domain_context  Post-delivery bookkeeping: [message_type, reference_type, reference_id].
     * @return true|WP_Error
     */
    public static function enqueue( $to, $subject, $body, $headers, $message_key, $payload_hash, $attachment_meta = null, $domain_context = array() ) {
        global $wpdb;
        $table = self::table();

        // Idempotency check
        if ( $message_key ) {
            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, payload_hash, status FROM {$table} WHERE message_key = %s",
                $message_key
            ) );
            if ( $existing ) {
                if ( $existing->payload_hash === $payload_hash ) {
                    return true; // Idempotent success
                }
                return new WP_Error( 'idempotency_conflict', 'This message key was already used with a different payload.' );
            }
        }

        $inserted = $wpdb->insert( $table, array(
            'to_email'       => sanitize_email( $to ),
            'subject'        => sanitize_text_field( $subject ),
            'body'           => $body,
            'headers'        => wp_json_encode( (array) $headers ),
            'attachments'    => '[]',
            'attempts'       => 0,
            'status'         => 'pending',
            'next_retry'     => current_time( 'mysql' ),
            'message_key'    => $message_key ? sanitize_text_field( $message_key ) : null,
            'payload_hash'   => $payload_hash ? sanitize_text_field( $payload_hash ) : null,
            'attachment_meta' => $attachment_meta ? wp_json_encode( $attachment_meta ) : null,
            'message_type'   => isset( $domain_context['message_type'] ) ? sanitize_key( $domain_context['message_type'] ) : null,
            'reference_type' => isset( $domain_context['reference_type'] ) ? sanitize_key( $domain_context['reference_type'] ) : null,
            'reference_id'   => isset( $domain_context['reference_id'] ) ? absint( $domain_context['reference_id'] ) : null,
            'created_at'     => current_time( 'mysql' ),
        ) );

        if ( $inserted === false ) {
            // Race condition: another process may have inserted the same message_key
            if ( $message_key ) {
                $existing = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id, payload_hash FROM {$table} WHERE message_key = %s", $message_key
                ) );
                if ( $existing && $existing->payload_hash === $payload_hash ) {
                    return true; // Idempotent success
                }
            }
            return new WP_Error( 'queue_insert_failed', 'Could not enqueue the email for delivery.' );
        }

        return true;
    }

    /**
     * Generate a deterministic payload hash for idempotency validation.
     *
     * @param string      $to              Recipient.
     * @param string      $subject         Subject.
     * @param string      $body            Body/template data.
     * @param array       $headers         Headers.
     * @param array|null  $attachment_meta  Attachment metadata.
     * @return string SHA-256 hash.
     */
    public static function compute_payload_hash( $to, $subject, $body, $headers, $attachment_meta = null ) {
        $canonical = wp_json_encode( array(
            'to'      => strtolower( trim( $to ) ),
            'subject' => $subject,
            'body'    => $body,
            'headers' => $headers,
            'attach'  => $attachment_meta,
        ), JSON_UNESCAPED_UNICODE );
        return hash( 'sha256', $canonical );
    }

    // ── Worker Methods ─────────────────────────────────────────────────────────

    /**
     * Claim a batch of pending rows for processing using compare-and-set.
     * Two concurrent workers cannot claim the same row.
     *
     * @param string $worker_id  Unique worker identifier.
     * @param int    $limit      Maximum rows to claim.
     * @return array Claimed row objects.
     */
    public static function claim_pending_batch( $worker_id, $limit = 10, $message_id = 0 ) {
        global $wpdb;
        $table = self::table();
        $now = current_time( 'mysql' );
        $lease_expires = wp_date( 'Y-m-d H:i:s', time() + self::LEASE_SECONDS );
        $target = $message_id > 0 ? $wpdb->prepare( ' AND id=%d', $message_id ) : '';

        // Claim rows atomically
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET status = 'processing', claimed_at = %s, lease_expires_at = %s, worker_id = %s
             WHERE status = 'pending' AND (next_retry IS NULL OR next_retry <= %s)
             {$target}
             ORDER BY CASE WHEN message_type='mailbox_verification' THEN 0 WHEN message_type IN ('access_details','access_manual') THEN 1 ELSE 2 END, created_at ASC, id ASC LIMIT %d",
            $now, $lease_expires, sanitize_text_field( $worker_id ), $now, (int) $limit
        ) );

        // Fetch what we claimed
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = 'processing' AND worker_id = %s",
            sanitize_text_field( $worker_id )
        ) );
    }

    /**
     * Mark a row as successfully delivered.
     *
     * @return bool
     */
    public static function mark_accepted( $id, $worker_id ) {
        global $wpdb;
        $now = current_time( 'mysql' );
        return $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table() . "
             SET status = 'sent', accepted_at = %s, last_attempt_at = %s, attempts = attempts + 1,
                 claimed_at = NULL, lease_expires_at = NULL, worker_id = NULL
             WHERE id = %d AND worker_id = %s AND status = 'processing'",
            $now, $now, (int) $id, sanitize_text_field( $worker_id )
        ) ) === 1;
    }

    /**
     * Release a row for retry after delivery failure, or permanently fail on max attempts.
     *
     * @param int         $id               Queue row ID.
     * @param string      $worker_id        Claiming worker ID.
     * @param string      $error_message    Human-readable error summary.
     * @param string|null $error_code       Machine error code (e.g. wp_mail_failed).
     * @param string      $failure_category Failure category (default transport_error).
     * @param array       $error_details    Sanitized metadata array.
     * @return bool
     */
    public static function release_for_retry( $id, $worker_id, $error_message = '', $error_code = null, $failure_category = 'transport_error', $error_details = array() ) {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT attempts FROM {$table} WHERE id = %d", (int) $id ) );
        $attempts = $row ? (int) $row->attempts + 1 : 1;

        if ( $attempts >= self::MAX_ATTEMPTS ) {
            return self::mark_failed( $id, $worker_id, $error_message, $failure_category ?: 'transport_error', $error_code, $error_details );
        }

        // Exponential backoff: 5min, 20min, 1hr, 4hr, 16hr
        $delay_minutes = (int) pow( 4, $attempts - 1 ) * 5;
        $next_retry    = wp_date( 'Y-m-d H:i:s', time() + ( $delay_minutes * 60 ) );
        $now           = current_time( 'mysql' );
        $clean_error   = sanitize_text_field( substr( (string) $error_message, 0, 500 ) );
        $clean_cat     = $failure_category ? sanitize_key( $failure_category ) : null;
        $clean_code    = $error_code ? substr( sanitize_key( (string) $error_code ), 0, 80 ) : null;
        $details_json  = ! empty( $error_details ) ? wp_json_encode( $error_details ) : '';

        return $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET status = 'pending', attempts = %d, next_retry = %s,
                 last_error = %s, failure_category = %s, last_error_code = %s, last_error_details = %s,
                 last_attempt_at = %s, claimed_at = NULL, lease_expires_at = NULL, worker_id = NULL
             WHERE id = %d AND worker_id = %s AND status = 'processing'",
            $attempts, $next_retry, $clean_error, $clean_cat, $clean_code, $details_json, $now,
            (int) $id, sanitize_text_field( $worker_id )
        ) ) === 1;
    }

    /**
     * Permanently fail a row with explicit category, error code, message, and timestamps.
     *
     * @param int         $id               Queue row ID.
     * @param string      $worker_id        Claiming worker ID.
     * @param string      $error_message    Human-readable failure reason.
     * @param string      $failure_category Failure category (transport_error|stale_source|policy_expired|attachment_error|stalled_timeout|exception).
     * @param string|null $error_code       Machine error code.
     * @param array       $error_details    Sanitized diagnostic metadata.
     * @return bool
     */
    public static function mark_failed( $id, $worker_id, $error_message = '', $failure_category = 'transport_error', $error_code = null, $error_details = array() ) {
        global $wpdb;
        $table        = self::table();
        $now          = current_time( 'mysql' );
        $clean_error  = sanitize_text_field( substr( (string) $error_message, 0, 500 ) );
        $clean_cat    = $failure_category ? sanitize_key( $failure_category ) : 'transport_error';
        $clean_code   = $error_code ? substr( sanitize_key( (string) $error_code ), 0, 80 ) : null;
        $details_json = ! empty( $error_details ) ? wp_json_encode( $error_details ) : '';

        $row_before = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, to_email, message_type, reference_type, reference_id, attempts FROM {$table} WHERE id = %d",
            (int) $id
        ) );

        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET status = 'failed', attempts = attempts + 1,
                 last_error = %s, failure_category = %s, last_error_code = %s, last_error_details = %s,
                 last_attempt_at = %s, failed_at = %s,
                 claimed_at = NULL, lease_expires_at = NULL, worker_id = NULL
             WHERE id = %d AND worker_id = %s AND status = 'processing'",
            $clean_error, $clean_cat, $clean_code, $details_json, $now, $now,
            (int) $id, sanitize_text_field( $worker_id )
        ) ) === 1;

        if ( $updated && class_exists( 'MBS_Audit_Log' ) && in_array( $clean_cat, array( 'transport_error', 'attachment_error', 'exception' ), true ) ) {
            $audit_ref = self::resolve_audit_ref( $row_before, (int) $id );
            $type_lbl  = self::message_type_label( $row_before->message_type ?? '' );
            $to_email  = (string) ( $row_before->to_email ?? '' );
            $attempts  = (int) ( $row_before->attempts ?? 0 ) + 1;
            MBS_Audit_Log::log(
                $audit_ref,
                'email_failed',
                sprintf( '%s email to %s failed after %d attempt(s) [%s]: %s', $type_lbl, $to_email, $attempts, $clean_code ?: $clean_cat, $clean_error ?: 'Unknown transport error.' )
            );
        }

        return $updated;
    }

    /** Resolve an audit reference string for a queue row. */
    private static function resolve_audit_ref( $row, $fallback_id ) {
        global $wpdb;
        if ( $row && ! empty( $row->reference_type ) && ! empty( $row->reference_id ) ) {
            $ref_id = (int) $row->reference_id;
            if ( $row->reference_type === 'booking' && defined( 'MBS_TABLE' ) ) {
                $ref = $wpdb->get_var( $wpdb->prepare( 'SELECT ref FROM ' . $wpdb->prefix . MBS_TABLE . ' WHERE id = %d', $ref_id ) );
                if ( $ref ) return (string) $ref;
            } elseif ( $row->reference_type === 'invoice' && defined( 'MBS_INVOICE_TABLE' ) ) {
                $ref = $wpdb->get_var( $wpdb->prepare( 'SELECT invoice_ref FROM ' . $wpdb->prefix . MBS_INVOICE_TABLE . ' WHERE id = %d', $ref_id ) );
                if ( $ref ) return (string) $ref;
            } elseif ( $row->reference_type === 'series' && defined( 'MBS_SERIES_TABLE' ) ) {
                $ref = $wpdb->get_var( $wpdb->prepare( 'SELECT series_ref FROM ' . $wpdb->prefix . MBS_SERIES_TABLE . ' WHERE id = %d', $ref_id ) );
                if ( $ref ) return (string) $ref;
            }
        }
        return 'EMAIL-' . (int) $fallback_id;
    }

    /** A disabled policy is not a failed delivery attempt. Keep its intent. */
    public static function pause_for_policy( $id, $worker_id, $reason, $delay_seconds = null ) {
        global $wpdb;
        $delay = $delay_seconds !== null ? max( 60, (int) $delay_seconds ) : ( defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 );
        return $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table() . " SET status='pending',next_retry=%s,last_error=%s,claimed_at=NULL,lease_expires_at=NULL,worker_id=NULL WHERE id=%d AND worker_id=%s AND status='processing'",
            wp_date( 'Y-m-d H:i:s', time() + $delay ), sanitize_text_field( $reason ), (int) $id, sanitize_text_field( $worker_id )
        ) ) === 1;
    }

    /**
     * Recover stale processing leases (worker crashed/timed out).
     */
    public static function recover_stale_leases( $message_id = 0 ) {
        global $wpdb;
        $table = self::table();
        $now = current_time( 'mysql' );

        $target = $message_id > 0 ? $wpdb->prepare( ' AND id=%d', $message_id ) : '';
        $recovered = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET status = 'pending', attempts = attempts + 1,
                 last_error = 'Worker lease expired — released for retry.',
                 claimed_at = NULL, lease_expires_at = NULL, worker_id = NULL,
                 next_retry = %s
             WHERE status = 'processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < %s {$target}",
            $now, $now
        ) );

        if ( $recovered > 0 ) {
            error_log( "[MGF Venue] Email queue: recovered {$recovered} stale processing lease(s)." );
        }
    }

    // ── Queue Processing (Cron Worker) ─────────────────────────────────────────

    /**
     * Process the queue on its retry schedule, or exactly one committed row.
     * Handles both legacy file-path entries and new attachment_meta entries.
     */
    public function process_queue( $message_id = 0 ) {
        global $wpdb;
        if ( ! is_int( $message_id ) || $message_id < 0 ) return new WP_Error( 'invalid_email_id', 'Invalid outbox message ID.' );
        // First recover any stale leases from crashed workers
        self::recover_stale_leases( $message_id );

        $worker_id = 'mbs-' . substr( md5( uniqid( '', true ) ), 0, 12 );
        $claimed = self::claim_pending_batch( $worker_id, $message_id ? 1 : 20, $message_id );

        if ( empty( $claimed ) ) return array( 'sent' => 0, 'failed' => 0 );

        $sent = 0;
        $failed = 0;

        foreach ( $claimed as $email ) {
            if ( class_exists( 'MBS_Payment_Expiry' ) && in_array( $email->message_type, array( 'expiry_warning', 'expiry_cancel', 'expiry_admin' ), true ) && ! MBS_Payment_Expiry::email_current( $email ) ) { self::mark_failed( $email->id, $worker_id, 'Payment expiry email is no longer current for this booking.', 'stale_source', 'expiry_not_current' ); continue; }
            if ( $email->message_type === 'credit_reminder' && ! MBS_Credit_Accounts::reminder_current( $email ) ) { self::mark_failed( $email->id, $worker_id, 'Credit account reminder is no longer current.', 'stale_source', 'credit_reminder_not_current' ); continue; }
            // A failed ordinary chase may outlive the staff decision to grant
            // credit or move hire onto consolidated invoices. Never retry its
            // old deposit/cancellation wording under the new billing treatment.
            if ( in_array( $email->message_type, array( 'payment_chase', 'b2b_statement_reminder' ), true ) ) {
                if ( ! MBS_Payment_Features::enabled( 'reminders' ) ) {
                    self::pause_for_policy( $email->id, $worker_id, 'Payment reminders disabled; delivery paused.' );
                    continue;
                }
                $booking = MBS_Bookings::get_by_id( (int) $email->reference_id );
                if ( $wpdb->last_error ) { self::release_for_retry( $email->id, $worker_id, 'Payment reminder source temporarily unavailable.', 'source_unavailable', 'transport_error' ); continue; }
                if ( ! $booking || ! in_array( $booking->status, array( 'confirmed', 'deposit_paid' ), true )
                    || strcasecmp( $booking->email, $email->to_email ) !== 0
                    || (float) $booking->amount <= (float) $booking->amount_paid
                    || MBS_Credit_Accounts::for_booking( $booking ) || ! MBS_Payment_Chaser::should_chase_occurrence( $booking ) ) {
                    self::mark_failed( $email->id, $worker_id, 'Booking or billing treatment changed before payment reminder dispatch. Review current invoice terms.', 'stale_source', 'payment_chase_not_current' );
                    continue;
                }
            }
            if ( $email->message_type === 'request_rejected' ) {
                $request = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mathlin_mod_requests WHERE id=%d', (int) $email->reference_id ) );
                if ( $wpdb->last_error ) { self::release_for_retry( $email->id, $worker_id, 'Request source temporarily unavailable.', 'source_unavailable', 'transport_error' ); continue; }
                $booking = $request ? MBS_Bookings::get( $request->booking_ref ) : null;
                if ( $wpdb->last_error ) { self::release_for_retry( $email->id, $worker_id, 'Booking source temporarily unavailable.', 'source_unavailable', 'transport_error' ); continue; }
                if ( ! $request || $request->status !== 'rejected' || ! $booking || strcasecmp( $booking->email, $email->to_email ) !== 0 ) { self::mark_failed( $email->id, $worker_id, 'Modification request or booking changed before dispatch.', 'stale_source', 'request_not_current' ); continue; }
            }
            if($email->message_type==='series_confirmation'){
                $source=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.MBS_SERIES_TABLE.' WHERE id=%d',(int)$email->reference_id));
                if(!empty($wpdb->last_error)){self::release_for_retry($email->id,$worker_id,'Series source temporarily unavailable.','source_unavailable','transport_error');continue;}
                $version=self::series_message_version($email);
                if(!$source||$source->status!=='confirmed'||strcasecmp($source->contact_email,$email->to_email)!==0||($version!==null&&(int)$source->version!==(int)$version)){self::mark_failed($email->id,$worker_id,'Recurring series changed or is no longer confirmed.','stale_source','series_confirmation_stale');continue;}
            }
            if($email->message_type==='series_state'){
                $meta=json_decode((string)$email->attachment_meta,true);
                $series=is_array($meta)&&!empty($meta['series_ref'])?MBS_Series::get($meta['series_ref']):null;
                if(!empty($wpdb->last_error)){self::release_for_retry($email->id,$worker_id,'Series source temporarily unavailable.','source_unavailable','transport_error');continue;}
                if(!$series||!isset($meta['series_version'])||(int)$series->version!==(int)$meta['series_version']||strcasecmp($series->contact_email,$email->to_email)!==0){self::mark_failed($email->id,$worker_id,'Recurring series version or contact email changed.','stale_source','series_state_stale');continue;}
            }
            if(in_array($email->message_type,array('booking_edit','request_change','request_cancel'),true)){
                $booking=MBS_Bookings::get_by_id((int)$email->reference_id);
                if(!empty($wpdb->last_error)){self::release_for_retry($email->id,$worker_id,'Booking source temporarily unavailable.','source_unavailable','transport_error');continue;}
                if($email->message_type==='request_cancel'){
                    // A committed cancellation remains a valid historical notice
                    // after deletion; reopening it supersedes the queued notice.
                    if($booking&&($booking->status!=='cancelled'||strcasecmp($booking->email,$email->to_email)!==0)){self::mark_failed($email->id,$worker_id,'Booking was reopened or recipient email changed.','stale_source','booking_reopened');continue;}
                }else{
                    if(!$booking||!in_array($booking->status,array('pending','confirmed','deposit_paid','paid'),true)||$email->message_key!==$email->message_type.':'.MBS_Email::change_source_hash($booking)){self::mark_failed($email->id,$worker_id,'Booking changed or is no longer active before dispatch.','stale_source','booking_changed');continue;}
                    $message=$email->message_type==='booking_edit'?MBS_Admin::build_edit_notification($booking,(float)$booking->amount,(float)$booking->amount):MBS_Modification::build_booker_approved_simple($booking);
                    $email->subject=$message['subject'];$email->body=$message['body'];$email->headers=wp_json_encode($message['headers']);
                }
            }
            if ( $email->message_type === 'mailbox_verification' ) {
                $created = new DateTimeImmutable( $email->created_at, wp_timezone() );
                if ( $created->getTimestamp() + 900 <= time() ) { self::mark_failed( $email->id, $worker_id, 'Mailbox verification link expired (15-minute window).', 'policy_expired', 'verification_expired' ); continue; }
            }
            if ( in_array( $email->message_type, array( 'feedback_request', 'feedback_manual' ), true ) ) {
                $booking = MBS_Bookings::get_by_id( (int) $email->reference_id );
                if(!empty($wpdb->last_error)){self::release_for_retry($email->id,$worker_id,'Feedback source temporarily unavailable.','source_unavailable','transport_error');continue;}
                if ( ! $booking || strcasecmp( $booking->email, $email->to_email ) !== 0 || strpos( $email->message_key, 'feedback:' . MBS_Access_Details::source_hash( $booking ) ) !== 0 || ! in_array( $booking->status, array( 'confirmed','deposit_paid','paid' ), true ) || ! empty( $booking->scout_use ) ) { self::mark_failed( $email->id, $worker_id, 'Booking changed or is no longer eligible for feedback.', 'stale_source', 'feedback_ineligible' ); continue; }
                if ( $email->message_type === 'feedback_request' && ! get_option( 'mbs_feedback_enabled', 0 ) ) { self::pause_for_policy( $email->id, $worker_id, 'Feedback is disabled; delivery paused.' ); continue; }
                $message = MBS_Feedback::build_request_message( $booking );
                $email->subject = $message['subject']; $email->body = $message['body']; $email->headers = wp_json_encode( $message['headers'] );
            }
            if ( $email->message_type === 'booking_reminder' ) {
                $booking = MBS_Bookings::get_by_id( (int) $email->reference_id );
                if(!empty($wpdb->last_error)){self::release_for_retry($email->id,$worker_id,'Reminder source temporarily unavailable.','source_unavailable','transport_error');continue;}
                $now = new DateTimeImmutable( 'now', wp_timezone() );
                try { $intervals = $booking ? MBS_Booking_Intervals::intervals($booking) : array(); $start = $intervals ? $intervals[0]['start'] : null; }
                catch(Throwable $e){ self::mark_failed($email->id,$worker_id,'Booking schedule is invalid for reminder dispatch.','stale_source','reminder_invalid_schedule'); continue; }
                if ( ! $booking || ! in_array( $booking->status, array( 'confirmed','deposit_paid','paid' ), true ) || $now >= $start || $email->message_key !== 'reminder:' . MBS_Access_Details::source_hash( $booking ) ) {
                    $rem_cat  = ( $booking && $start && $now >= $start ) ? 'policy_expired' : 'stale_source';
                    $rem_code = ( $booking && $start && $now >= $start ) ? 'reminder_expired' : 'reminder_stale';
                    self::mark_failed( $email->id, $worker_id, 'Booking start passed or booking changed before reminder dispatch.', $rem_cat, $rem_code );
                    continue;
                }
                $hours = (int) get_option( 'mbs_reminder_hours', 24 );
                if ( $hours <= 0 || $now < $start->modify( '-' . $hours . ' hours' ) ) {
                    $retry = $hours > 0 ? $start->modify( '-' . $hours . ' hours' )->getTimestamp() : time() + HOUR_IN_SECONDS;
                    self::pause_for_policy( $email->id, $worker_id, 'Reminder paused or not yet due', max( 60, $retry - time() ) );
                    continue;
                }
                $message = MBS_Email::build_reminder_message( $booking );
                $email->subject=$message['subject']; $email->body=$message['body']; $email->headers=wp_json_encode( $message['headers'] );
            }
            if ( in_array( $email->message_type, array( 'access_details', 'access_manual' ), true ) ) {
                $message = MBS_Access_Details::queued_message( $email );
                if ( is_wp_error( $message ) ) {
                    $err_code = $message->get_error_code();
                    if ( in_array( $err_code, array( 'access_inactive','access_expired','access_stale','access_estate','access_invalid_schedule','access_already_sent' ), true ) ) {
                        $cat = ( $err_code === 'access_expired' ) ? 'policy_expired' : 'stale_source';
                        self::mark_failed( $email->id, $worker_id, $message->get_error_message() ?: 'Access details email is no longer eligible.', $cat, $err_code );
                    } else {
                        // Policy/timing pauses do not exhaust transport retries.
                        self::pause_for_policy( $email->id, $worker_id, $message->get_error_message() );
                    }
                    continue;
                }
                $email->subject = $message['subject'];
                $email->body = $message['body'];
                $email->headers = wp_json_encode( $message['headers'] );
            }
            // Pause disabled payment requests without consuming retries. Receipts
            // and gateway reconciliation remain available for captured payments.
            $feature = $email->message_type === 'invoice_reminder' ? 'reminders' : ( $email->message_type === 'invoice_issued' ? 'invoices' : '' );
            if ( $feature && class_exists( 'MBS_Payment_Features' ) && ! MBS_Payment_Features::enabled( $feature ) ) {
                self::pause_for_policy( $email->id, $worker_id, 'Payment feature disabled; delivery paused.' );
                continue;
            }
            // A queued request can become stale while awaiting PDF rendering/retry.
            if ( class_exists( 'MBS_Additional_Charges' ) && $email->reference_type === 'invoice'
                && in_array( $email->message_type, array( 'invoice_issued','invoice_reminder' ), true ) ) {
                $source_invoice = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . MBS_INVOICE_TABLE . ' WHERE id=%d', $email->reference_id ) );
                if ( ! $source_invoice || in_array( $source_invoice->status, array( 'draft','void' ), true ) || ( $email->message_type === 'invoice_reminder' && (int) $source_invoice->total_minor <= (int) $source_invoice->paid_minor + (int) $source_invoice->credited_minor ) ) {
                    self::mark_failed( $email->id, $worker_id, 'Invoice is void, draft, or already settled.', 'stale_source', 'invoice_ineligible' );
                    continue;
                }
                if ( MBS_Additional_Charges::is_charge( $source_invoice ) ) {
                    if ( class_exists( 'MBS_Payment_Features' ) && ! MBS_Payment_Features::enabled( 'additional_charges' ) ) {
                        self::pause_for_policy( $email->id, $worker_id, 'Additional charges disabled; delivery paused.' );
                        continue;
                    }
                    $hold_reason = MBS_Additional_Charges::queued_delivery_error( $source_invoice, json_decode( $email->attachment_meta ?: '{}', true ) ?: array() );
                    if ( $hold_reason ) {
                        self::release_for_retry( $email->id, $worker_id, $hold_reason, 'charge_delivery_hold', 'attachment_error' );
                        $failed++;
                        continue;
                    }
                }
            }
            $headers = json_decode( $email->headers, true ) ?: array();
            $attachments = array();
            $temp_file = null;
            $download_link_appended = false;

            // Transactional booking confirmations are enqueued with a stable,
            // side-effect-free placeholder. Build the complete branded email
            // only after the confirmation transaction has committed.
            if ( $email->message_type === 'booking_confirmation' && $email->reference_type === 'booking' && ! empty( $email->reference_id ) ) {
                $booking = MBS_Bookings::get_by_id( (int) $email->reference_id );
                if(!empty($wpdb->last_error)){self::release_for_retry($email->id,$worker_id,'Confirmation source temporarily unavailable.','source_unavailable','transport_error');continue;}
                if ( ! $booking || ! in_array( $booking->status, array( 'confirmed','deposit_paid','paid' ), true ) || strcasecmp( $email->to_email, $booking->email ) !== 0 ) {
                    self::mark_failed( $email->id, $worker_id, 'Booking status or recipient email changed before confirmation dispatch.', 'stale_source', 'confirmation_stale' );
                    continue;
                }
                $message = MBS_Email::build_confirmation_message( $booking );
                $attachment = json_decode( (string) ( $email->attachment_meta ?? '' ), true );
                if ( ! empty( $attachment['document_id'] ) && ! empty( $booking->current_invoice_document_id ) && (int) $attachment['document_id'] !== (int) $booking->current_invoice_document_id ) { self::mark_failed( $email->id, $worker_id, 'Booking invoice was reissued before this confirmation was sent.', 'stale_source', 'invoice_superseded' ); continue; }
                $email->subject = $message['subject'];
                $email->body = $message['body'];
            }

            // Determine attachment source
            if ( ! empty( $email->attachment_meta ) ) {
                $meta = json_decode( $email->attachment_meta, true );
                if ( is_array( $meta ) && ( ! empty( $meta['document_id'] ) || ! empty( $meta['booking_id'] ) ) ) {
                    $temp_file = self::render_attachment_from_meta( $meta );
                    if ( $temp_file && file_exists( $temp_file ) ) {
                        // Check size against email attachment limit
                        $file_size = filesize( $temp_file );
                        $max_attach = (int) get_option( 'mbs_email_attachment_max_bytes', 5242880 );
                        if ( $file_size <= $max_attach ) {
                            $attachments = array( $temp_file );
                        } elseif ( ! empty( $meta['document_id'] ) ) {
                            // Oversized: create secure download token and append link to body
                            @unlink( $temp_file );
                            $temp_file = null;
                            $token = MBS_Invoice_Delivery_Endpoint::create_guest_token( (int) $meta['document_id'] );
                            if ( is_wp_error( $token ) ) {
                                // Token creation failed — release for retry, do NOT send without attachment or link
                                self::release_for_retry( $email->id, $worker_id, 'Oversized PDF and token creation failed: ' . $token->get_error_message(), 'attachment_token_failed', 'attachment_error' );
                                $failed++;
                                continue;
                            }
                            $download_url = add_query_arg( array(
                                'action'      => 'mbs_invoice_document',
                                'token'       => $token,
                                'document_id' => (int) $meta['document_id'],
                                'format'      => 'pdf',
                            ), admin_url( 'admin-ajax.php' ) );
                            $email->body .= '<p style="margin-top:16px;padding:12px;background:#f5f0ff;border-radius:6px;"><strong>Your invoice is available for download:</strong><br><a href="' . esc_url( $download_url ) . '">' . esc_url( $download_url ) . '</a><br><small>This link expires in 72 hours.</small></p>';
                            $download_link_appended = true;
                            error_log( '[MGF Venue] Invoice PDF oversized (' . size_format( $file_size ) . '); sent with download link instead.' );
                        } else {
                            @unlink( $temp_file );
                            $temp_file = null;
                            $legacy_booking = MBS_Bookings::get_by_id( (int) $meta['booking_id'] );
                            $download_url = $legacy_booking ? MBS_Invoice_Delivery_Endpoint::guest_booking_pdf_url( $legacy_booking, 'pdf' ) : '';
                            if ( $download_url ) {
                                $email->body .= '<p style="margin-top:16px;padding:12px;background:#f5f0ff;border-radius:6px;"><strong>Your invoice is available for download:</strong><br><a href="' . esc_url( $download_url ) . '">' . esc_url( $download_url ) . '</a></p>';
                                $download_link_appended = true;
                            }
                        }
                    } elseif ( ! empty( $meta['document_id'] ) ) {
                        // Render failed: provide HTML view link (not PDF which will just fail again)
                        $token = MBS_Invoice_Delivery_Endpoint::create_guest_token( (int) $meta['document_id'] );
                        if ( ! is_wp_error( $token ) ) {
                            $download_url = add_query_arg( array(
                                'action'      => 'mbs_invoice_document',
                                'token'       => $token,
                                'document_id' => (int) $meta['document_id'],
                                'format'      => 'html',
                            ), admin_url( 'admin-ajax.php' ) );
                            $email->body .= '<p style="margin-top:16px;padding:12px;background:#fee2e2;border-radius:6px;"><strong>Invoice attachment could not be generated.</strong> View your invoice online:<br><a href="' . esc_url( $download_url ) . '">View Invoice</a></p>';
                            $download_link_appended = true;
                        } else {
                            // Cannot create token either — release for retry, do NOT send
                            self::release_for_retry( $email->id, $worker_id, 'PDF render failed and token creation failed.', 'attachment_render_failed', 'attachment_error' );
                            $failed++;
                            continue;
                        }
                        error_log( '[MGF Venue] Invoice PDF render failed for document ' . $meta['document_id'] . '; sent with HTML view link fallback.' );
                    } else {
                        $legacy_booking = MBS_Bookings::get_by_id( (int) $meta['booking_id'] );
                        $download_url = $legacy_booking ? MBS_Invoice_Delivery_Endpoint::guest_booking_pdf_url( $legacy_booking, 'html' ) : '';
                        if ( $download_url ) {
                            $email->body .= '<p style="margin-top:16px;padding:12px;background:#fee2e2;border-radius:6px;"><strong>Invoice attachment could not be generated.</strong> View your invoice online:<br><a href="' . esc_url( $download_url ) . '">View Invoice</a></p>';
                            $download_link_appended = true;
                        }
                    }
                }
            } else {
                // Legacy: use stored file paths
                $attachments = json_decode( $email->attachments, true ) ?: array();
            }

            try {
                $delivery = self::deliver_via_wp_mail( $email->to_email, $email->subject, $email->body, $headers, $attachments );

                if ( $delivery['ok'] ) {
                    $accepted = self::mark_accepted( $email->id, $worker_id );
                    if ( $accepted ) {
                        // Only perform domain bookkeeping if the outbox row
                        // successfully transitioned to 'sent'. This prevents
                        // setting confirmation_sent_at / issued_email_sent_at
                        // when the row was claimed by another worker.
                        self::perform_domain_bookkeeping( $email );
                    }
                    // Note: at-least-once delivery semantics. If the process dies
                    // after wp_mail() accepts but before mark_accepted() succeeds,
                    // the stale-lease recovery will re-deliver the email. This is
                    // an unavoidable edge documented by design.
                    $sent++;
                } else {
                    $cat = ( $delivery['error_code'] === 'mail_exception' ) ? 'exception' : 'transport_error';
                    self::release_for_retry( $email->id, $worker_id, $delivery['error_message'], $delivery['error_code'], $cat, $delivery['error_details'] );
                    $failed++;
                }
            } catch ( Throwable $e ) {
                self::release_for_retry( $email->id, $worker_id, $e->getMessage(), 'mail_exception', 'exception', array( 'exception_class' => get_class( $e ), 'exception_code' => (int) $e->getCode() ) );
                $failed++;
            } finally {
                // Always clean up temp files
                if ( $temp_file && file_exists( $temp_file ) ) {
                    @unlink( $temp_file );
                }
            }
        }

        if ( $sent > 0 || $failed > 0 ) {
            error_log( "[MGF Venue] Email queue: {$sent} sent, {$failed} failed/retrying." );
        }

        if ( ! $message_id ) self::cleanup();
        return array( 'sent' => $sent, 'failed' => $failed );
    }

    /**
     * Render a PDF attachment from document metadata.
     * Returns temp file path on success, null on failure.
     *
     * @param array $meta Attachment metadata with document_id or booking_id and format.
     * @return string|null Temp file path or null.
     */
    private static function render_attachment_from_meta( $meta ) {
        $format = $meta['format'] ?? 'pdf';
        if ( $format !== 'pdf' ) return null;

        $document_id = (int) ( $meta['document_id'] ?? 0 );
        if ( $document_id >= 1 ) {
            $result = MBS_PDF_Renderer::render_to_temp_file( $document_id );
            if ( is_wp_error( $result ) ) {
                error_log( '[MGF Venue] Email attachment render failed for document ' . $document_id . ': ' . $result->get_error_message() );
                return null; // Graceful degradation: send without attachment
            }
            return $result;
        }

        $booking_id = (int) ( $meta['booking_id'] ?? 0 );
        if ( $booking_id >= 1 ) {
            $result = MBS_PDF_Renderer::render_booking_to_temp_file( $booking_id );
            if ( is_wp_error( $result ) ) {
                error_log( '[MGF Venue] Email attachment render failed for booking ' . $booking_id . ': ' . $result->get_error_message() );
                return null;
            }
            return $result;
        }

        return null;
    }

    /**
     * After successful delivery, update domain-specific timestamps.
     */
    private static function perform_domain_bookkeeping( $email ) {
        if ( empty( $email->message_type ) || empty( $email->reference_type ) || empty( $email->reference_id ) ) return;

        global $wpdb;
        $now = current_time( 'mysql' );

        switch ( $email->message_type ) {
            case 'credit_reminder':
                MBS_Credit_Accounts::reminder_delivered( $email );
                break;
            case 'feedback_request':
            case 'feedback_manual':
                $booking = MBS_Bookings::get_by_id( (int) $email->reference_id );
                if ( $booking ) {
                    $wpdb->update( $wpdb->prefix . MBS_TABLE, array( 'feedback_sent' => 1 ), array( 'id' => (int) $booking->id ) );
                    MBS_Audit_Log::log( $booking->ref, 'feedback_sent', 'Feedback email accepted by transport; inbox delivery not independently confirmed.' );
                }
                break;
            case 'booking_reminder':
                $booking=MBS_Bookings::get_by_id((int)$email->reference_id);
                if($booking&&$email->message_key==='reminder:'.MBS_Access_Details::source_hash($booking))$wpdb->update($wpdb->prefix.MBS_TABLE,array('reminder_sent'=>1),self::delivery_source_where($booking));
                break;
            case 'access_details':
            case 'access_manual':
                $booking = MBS_Bookings::get_by_id( (int) $email->reference_id );
                if ( $booking && MBS_Access_Details::is_eligible_for_access($booking) && strpos((string)$email->message_key,'access:'.MBS_Access_Details::source_hash($booking))===0 ) {
                    $marked=$wpdb->update( $wpdb->prefix . MBS_TABLE, array( 'access_sent' => 1 ), self::delivery_source_where($booking) );
                    if($marked!==1)break;
                    MBS_Audit_Log::log( $booking->ref, 'access_sent', 'Access email accepted by the configured mail transport; inbox delivery not independently confirmed.' );
                    if ( $email->message_type === 'access_details' ) MBS_Access_Details::send_admin_notification( $booking );
                }
                break;
            case 'series_confirmation':
                if ( $email->reference_type === 'series' ) {
                    $where=array('id'=>(int)$email->reference_id,'status'=>'confirmed','contact_email'=>$email->to_email);
                    $version=self::series_message_version($email);if($version!==null)$where['version']=$version;
                    $wpdb->update(
                        $wpdb->prefix . MBS_SERIES_TABLE,
                        array( 'confirmation_sent_at' => $now ),
                        $where
                    );
                }
                break;

            case 'invoice_issued':
                if ( $email->reference_type === 'invoice' ) {
                    $wpdb->update(
                        $wpdb->prefix . MBS_INVOICE_TABLE,
                        array( 'issued_email_sent_at' => $now ),
                        array( 'id' => (int) $email->reference_id )
                    );
                }
                break;

            case 'invoice_reminder':
                if ( $email->reference_type === 'invoice' ) {
                    $wpdb->update(
                        $wpdb->prefix . MBS_INVOICE_TABLE,
                        array( 'last_reminded_at' => $now, 'reminder_count' => $wpdb->get_var( $wpdb->prepare(
                            "SELECT reminder_count FROM " . $wpdb->prefix . MBS_INVOICE_TABLE . " WHERE id = %d",
                            (int) $email->reference_id
                        ) ) + 1 ),
                        array( 'id' => (int) $email->reference_id )
                    );
                }
                break;

            case 'payment_chase':
            case 'b2b_statement_reminder':
                if ( $email->reference_type === 'booking' && class_exists( 'MBS_Bookings' ) ) {
                    $booking = MBS_Bookings::get_by_id( (int) $email->reference_id );
                    if ( $booking ) {
                        $meta  = json_decode( (string) ( $email->attachment_meta ?? '' ), true );
                        $count = (int) ( $booking->chase_count ?? 0 ) + 1;
                        $wpdb->update(
                            $wpdb->prefix . MBS_TABLE,
                            array( 'chase_count' => $count, 'last_chased' => $now ),
                            array( 'id' => (int) $booking->id )
                        );
                        if ( class_exists( 'MBS_Audit_Log' ) ) {
                            $manual = ! empty( $meta['manual'] );
                            if ( $email->message_type === 'b2b_statement_reminder' ) {
                                $lbl = $manual ? 'Manual B2B statement reminder' : 'Auto B2B statement reminder';
                                $amt = isset( $meta['amount_due'] ) ? ' (£' . number_format( (float) $meta['amount_due'], 2 ) . ')' : '';
                                MBS_Audit_Log::log( $booking->ref, 'payment_chase', $lbl . ' (#' . $count . ')' . $amt );
                            } else {
                                $lbl   = $manual ? 'Manual payment chase' : 'Auto payment chase';
                                $ctype = (string) ( $meta['chase_type'] ?? 'payment' );
                                $amt   = isset( $meta['amount_due'] ) ? ' (' . $ctype . ' £' . number_format( (float) $meta['amount_due'], 2 ) . ')' : '';
                                MBS_Audit_Log::log( $booking->ref, 'payment_chase', $lbl . ' (chase #' . $count . ')' . $amt );
                            }
                        }
                    }
                }
                break;
        }
    }

    private static function series_message_version($email){
        $meta=json_decode((string)($email->attachment_meta??''),true);
        if(isset($meta['series_version']))return(int)$meta['series_version'];
        return preg_match('/:v(\d+)(?::manual[a-f0-9]+)?$/D',(string)$email->message_key,$match)?(int)$match[1]:null;
    }
    private static function delivery_source_where($booking){
        $where=array('id'=>(int)$booking->id);
        foreach(array('status','booking_date','booking_date_end','start_time','end_time','all_day','space','email','organisation','notes')as$key)$where[$key]=$booking->$key??null;
        return$where;
    }

    // ── Legacy ─────────────────────────────────────────────────────────────────

    /**
     * Legacy queue insertion (private, for send() fallback).
     */
    private static function legacy_queue( $to, $subject, $body, $headers, $attachments, $delivery = array() ) {
        global $wpdb;
        $now = current_time( 'mysql' );
        $err_msg  = ! empty( $delivery['error_message'] ) ? sanitize_text_field( substr( (string) $delivery['error_message'], 0, 500 ) ) : '';
        $err_code = ! empty( $delivery['error_code'] ) ? substr( sanitize_key( (string) $delivery['error_code'] ), 0, 80 ) : null;
        $err_cat  = ( $err_code === 'mail_exception' ) ? 'exception' : ( $err_msg !== '' ? 'transport_error' : null );
        $err_det  = ! empty( $delivery['error_details'] ) ? wp_json_encode( $delivery['error_details'] ) : '';
        $wpdb->insert( self::table(), array(
            'to_email'           => $to,
            'subject'            => $subject,
            'body'               => $body,
            'headers'            => wp_json_encode( $headers ),
            'attachments'        => wp_json_encode( $attachments ),
            'attempts'           => 1,
            'status'             => 'pending',
            'next_retry'         => $now,
            'last_error'         => $err_msg,
            'failure_category'   => $err_cat,
            'last_error_code'    => $err_code,
            'last_error_details' => $err_det,
            'last_attempt_at'    => $now,
            'created_at'         => $now,
        ) );
    }

    // ── Statistics & Cleanup ───────────────────────────────────────────────────

    /**
     * Get queue stats for the admin dashboard.
     * Separates transport_failed from obsolete_failed while keeping failed backwards-compatible.
     */
    public static function get_stats() {
        global $wpdb;
        $table = self::table();

        // One consistent aggregate, with no customer payloads. An unavailable
        // table/read is unknown, not a healthy empty outbox.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT SUM(status='pending') AS pending, SUM(status='processing') AS processing,
             SUM(status='sent') AS sent,
             SUM(status='failed' AND (failure_category IS NULL OR failure_category <> 'dismissed')) AS failed,
             SUM(status='failed' AND (failure_category IS NULL OR failure_category IN ('transport_error','attachment_error','exception','stalled_timeout'))) AS transport_failed,
             SUM(status='failed' AND failure_category IN ('stale_source','policy_expired')) AS obsolete_failed,
             SUM(status='failed' AND failure_category = 'dismissed') AS dismissed_failed,
             MIN(CASE WHEN status='pending' THEN created_at END) AS oldest_pending_at,
             MIN(CASE WHEN status='pending' THEN COALESCE(next_retry,created_at) END) AS next_due_at,
             SUM(status='processing' AND lease_expires_at<%s) AS expired_leases
             FROM {$table}", current_time( 'mysql' )
        ) );
        if ( ! $row || ! empty( $wpdb->last_error ) ) {
            return array(
                'available'        => false,
                'pending'          => null,
                'processing'       => null,
                'sent'             => null,
                'failed'           => null,
                'transport_failed' => null,
                'obsolete_failed'  => null,
                'dismissed_failed' => null,
            );
        }
        $failed           = (int) $row->failed;
        $transport_failed = isset( $row->transport_failed ) ? (int) $row->transport_failed : $failed;
        $obsolete_failed  = isset( $row->obsolete_failed ) ? (int) $row->obsolete_failed : 0;
        $dismissed_failed = isset( $row->dismissed_failed ) ? (int) $row->dismissed_failed : 0;
        return array(
            'available'         => true,
            'pending'           => (int) $row->pending,
            'processing'        => (int) $row->processing,
            'sent'              => (int) $row->sent,
            'failed'            => $failed,
            'transport_failed'  => $transport_failed,
            'obsolete_failed'   => $obsolete_failed,
            'dismissed_failed'  => $dismissed_failed,
            'oldest_pending_at' => $row->oldest_pending_at,
            'next_due_at'       => $row->next_due_at,
            'expired_leases'    => (int) $row->expired_leases,
            'delivery_evidence' => 'sent means transport accepted, not confirmed inbox delivery',
        );
    }

    /**
     * Clean up old entries and recover stale processing leases.
     */
    public static function cleanup() {
        if ( class_exists( 'MBS_Hirer_Identity' ) ) MBS_Hirer_Identity::cleanup();
        global $wpdb;
        $table = self::table();

        // Recover stale processing leases
        self::recover_stale_leases();

        // Clean up orphaned PDF render files
        MBS_PDF_Renderer::cleanup_orphans();

        // Clean sent/failed entries older than 30 days
        $cutoff_30 = wp_date( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE status IN ('sent', 'failed') AND created_at < %s",
            $cutoff_30
        ) );

        // Legacy untyped rows cannot be revalidated. Typed policy-sensitive
        // intents are revalidated by the worker and must survive feature pauses;
        // actual transport failures still exhaust MAX_ATTEMPTS normally.
        $cutoff_7 = wp_date( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
        $now      = current_time( 'mysql' );
        $stalled = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET status = 'failed',
                 failure_category = 'stalled_timeout',
                 last_error_code = 'stalled_timeout',
                 last_error = 'Queued email stalled for more than 7 days without delivery.',
                 failed_at = %s
             WHERE status = 'pending' AND created_at < %s AND COALESCE(message_type,'') NOT IN ('access_details','access_manual','booking_reminder','feedback_request','feedback_manual','invoice_issued','invoice_reminder')",
            $now, $cutoff_7
        ) );
        if ( $stalled > 0 ) {
            error_log( "[MGF Venue] Email queue: force-failed {$stalled} stalled entries older than 7 days." );
        }
    }

    // ── Interactive Diagnostics, Resend & Dismiss ──────────────────────────────

    /**
     * Human-readable label for a queue message_type.
     */
    public static function message_type_label( $type ) {
        $map = array(
            'booking_confirmation'   => 'Booking Confirmation',
            'access_details'         => 'Access Details',
            'access_manual'          => 'Access Details (Manual)',
            'booking_reminder'       => 'Booking Reminder',
            'feedback_request'       => 'Feedback Request',
            'feedback_manual'        => 'Feedback Request (Manual)',
            'invoice_issued'         => 'Invoice Issued',
            'invoice_reminder'       => 'Invoice Reminder',
            'invoice_receipt'        => 'Payment Receipt',
            'series_confirmation'    => 'Series Confirmation',
            'series_state'           => 'Series Update',
            'booking_edit'           => 'Booking Edited',
            'request_change'         => 'Change Approved',
            'request_cancel'         => 'Cancellation Notice',
            'request_rejected'       => 'Request Declined',
            'expiry_warning'         => 'Payment Expiry Warning',
            'expiry_cancel'          => 'Expiry Cancellation',
            'expiry_admin'           => 'Expiry Admin Alert',
            'credit_reminder'        => 'Credit Account Reminder',
            'mailbox_verification'   => 'Mailbox Verification',
            'payment_chase'          => 'Payment Reminder',
            'b2b_statement_reminder' => 'B2B Statement Reminder',
        );
        $key = sanitize_key( (string) $type );
        return $map[ $key ] ?? ( $key !== '' ? ucwords( str_replace( '_', ' ', $key ) ) : 'General Email' );
    }

    /**
     * Query email queue rows for the interactive diagnostics modal/API.
     * Raw email HTML body, headers, and attachments are NEVER selected or returned.
     *
     * @param array $filters status, failure_category (transport|obsolete|dismissed|all), reference_type, reference_id, booking_ref, limit, offset.
     * @return array{items:array,total:int,stats:array}
     */
    public static function query_diagnostics( $filters = array() ) {
        global $wpdb;
        $table = self::table();
        $where = array( '1=1' );
        $args  = array();

        $status   = sanitize_key( (string) ( $filters['status'] ?? '' ) );
        $cat_mode = sanitize_key( (string) ( $filters['failure_category'] ?? '' ) );
        $ref_type = sanitize_key( (string) ( $filters['reference_type'] ?? '' ) );
        $ref_id   = absint( $filters['reference_id'] ?? 0 );
        $bref     = strtoupper( sanitize_text_field( (string) ( $filters['booking_ref'] ?? '' ) ) );

        if ( $bref !== '' && $ref_id === 0 && defined( 'MBS_TABLE' ) ) {
            $found_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . MBS_TABLE . ' WHERE ref = %s', $bref ) );
            if ( $found_id > 0 ) {
                $ref_type = 'booking';
                $ref_id   = $found_id;
            } else {
                return array( 'items' => array(), 'total' => 0, 'stats' => self::get_stats() );
            }
        }

        if ( $ref_type !== '' && $ref_id > 0 ) {
            $where[] = 'reference_type = %s';
            $args[]  = $ref_type;
            $where[] = 'reference_id = %d';
            $args[]  = $ref_id;
        }

        if ( $status !== '' && in_array( $status, array( 'pending', 'processing', 'sent', 'failed' ), true ) ) {
            $where[] = 'status = %s';
            $args[]  = $status;
        }

        if ( $cat_mode === 'transport' ) {
            $where[] = "status = 'failed' AND (failure_category IS NULL OR failure_category IN ('transport_error','attachment_error','exception','stalled_timeout'))";
        } elseif ( $cat_mode === 'obsolete' ) {
            $where[] = "status = 'failed' AND failure_category IN ('stale_source','policy_expired')";
        } elseif ( $cat_mode === 'dismissed' ) {
            $where[] = "status = 'failed' AND failure_category = 'dismissed'";
        } elseif ( $status === 'failed' && $cat_mode !== 'all' ) {
            $where[] = "(failure_category IS NULL OR failure_category <> 'dismissed')";
        }

        $limit  = max( 1, min( 100, (int) ( $filters['limit'] ?? 50 ) ) );
        $offset = max( 0, (int) ( $filters['offset'] ?? 0 ) );

        $where_sql = implode( ' AND ', $where );
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );

        $select_cols = 'id, to_email, subject, attempts, status, next_retry, message_type, reference_type, reference_id, created_at, accepted_at, last_attempt_at, failed_at, last_error, failure_category, last_error_code, last_error_details, resent_from_id, resent_by';
        $order_sql   = ( $status === 'failed' || $cat_mode === 'transport' || $cat_mode === 'obsolete' )
            ? 'COALESCE(failed_at, last_attempt_at, created_at) DESC, id DESC'
            : 'id DESC';
        $list_sql    = "SELECT {$select_cols} FROM {$table} WHERE {$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d";
        $list_args   = array_merge( $args, array( $limit, $offset ) );
        $rows        = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_args ) );

        $links = self::resolve_reference_links( (array) $rows );
        $items = array();
        foreach ( (array) $rows as $row ) {
            $items[] = self::format_diagnostic_row( $row, $links );
        }

        return array(
            'items' => $items,
            'total' => $total,
            'stats' => self::get_stats(),
        );
    }

    /**
     * Batch-resolve booking/series/invoice references and admin URLs for diagnostic rows.
     */
    private static function resolve_reference_links( $rows ) {
        global $wpdb;
        $by_type = array( 'booking' => array(), 'invoice' => array(), 'series' => array() );
        foreach ( $rows as $row ) {
            $t = (string) ( $row->reference_type ?? '' );
            $i = (int) ( $row->reference_id ?? 0 );
            if ( $i > 0 && isset( $by_type[ $t ] ) ) {
                $by_type[ $t ][ $i ] = $i;
            }
        }
        $links = array();
        $admin_base = function_exists( 'admin_url' ) ? admin_url( 'admin.php' ) : 'admin.php';

        if ( ! empty( $by_type['booking'] ) && defined( 'MBS_TABLE' ) ) {
            $ids = array_values( $by_type['booking'] );
            $ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $found = $wpdb->get_results( $wpdb->prepare( 'SELECT id, ref, name FROM ' . $wpdb->prefix . MBS_TABLE . " WHERE id IN ({$ph})", $ids ) );
            foreach ( (array) $found as $b ) {
                $links[ 'booking:' . (int) $b->id ] = array(
                    'ref'   => (string) $b->ref,
                    'label' => trim( (string) $b->ref . ( ! empty( $b->name ) ? ' — ' . $b->name : '' ) ),
                    'url'   => function_exists( 'add_query_arg' ) ? add_query_arg( array( 'page' => 'mathlin-booking', 'ref' => $b->ref ), $admin_base ) : '',
                );
            }
        }
        if ( ! empty( $by_type['invoice'] ) && defined( 'MBS_INVOICE_TABLE' ) ) {
            $ids = array_values( $by_type['invoice'] );
            $ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $found = $wpdb->get_results( $wpdb->prepare( 'SELECT id, invoice_ref, billing_mode, series_ref FROM ' . $wpdb->prefix . MBS_INVOICE_TABLE . " WHERE id IN ({$ph})", $ids ) );
            foreach ( (array) $found as $inv ) {
                $url = '';
                if ( function_exists( 'add_query_arg' ) ) {
                    if ( (string) ( $inv->billing_mode ?? '' ) === 'additional_charge' ) {
                        $url = add_query_arg( array( 'page' => 'mathlin-additional-charges', 'charge' => $inv->invoice_ref ), $admin_base );
                    } elseif ( ! empty( $inv->series_ref ) ) {
                        $url = add_query_arg( array( 'page' => 'mathlin-series', 'ref' => $inv->series_ref ), $admin_base );
                    }
                }
                $links[ 'invoice:' . (int) $inv->id ] = array(
                    'ref'   => (string) $inv->invoice_ref,
                    'label' => (string) $inv->invoice_ref,
                    'url'   => $url,
                );
            }
        }
        if ( ! empty( $by_type['series'] ) && defined( 'MBS_SERIES_TABLE' ) ) {
            $ids = array_values( $by_type['series'] );
            $ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $found = $wpdb->get_results( $wpdb->prepare( 'SELECT id, series_ref, contact_name FROM ' . $wpdb->prefix . MBS_SERIES_TABLE . " WHERE id IN ({$ph})", $ids ) );
            foreach ( (array) $found as $s ) {
                $links[ 'series:' . (int) $s->id ] = array(
                    'ref'   => (string) $s->series_ref,
                    'label' => trim( (string) $s->series_ref . ( ! empty( $s->contact_name ) ? ' — ' . $s->contact_name : '' ) ),
                    'url'   => function_exists( 'add_query_arg' ) ? add_query_arg( array( 'page' => 'mathlin-series', 'ref' => $s->series_ref ), $admin_base ) : '',
                );
            }
        }
        return $links;
    }

    /**
     * Format a queue row for API/UI consumption (explicit allow-list, NEVER includes body/headers/attachments).
     */
    public static function format_diagnostic_row( $row, $links = array() ) {
        $ref_key  = ( ! empty( $row->reference_type ) && ! empty( $row->reference_id ) )
            ? ( $row->reference_type . ':' . (int) $row->reference_id )
            : '';
        $link     = $ref_key !== '' && isset( $links[ $ref_key ] ) ? $links[ $ref_key ] : null;
        $category = (string) ( $row->failure_category ?? '' );
        $status   = (string) ( $row->status ?? '' );

        $can_resend          = true;
        $resend_block_reason = '';
        if ( $status === 'processing' ) {
            $can_resend          = false;
            $resend_block_reason = 'Currently being processed by a mail worker.';
        } elseif ( in_array( $category, array( 'stale_source', 'policy_expired' ), true ) ) {
            $can_resend          = false;
            $resend_block_reason = 'Source record changed or email window expired; trigger a fresh email from the booking/invoice if still needed.';
        }

        $details_decoded = null;
        if ( ! empty( $row->last_error_details ) ) {
            $d = json_decode( (string) $row->last_error_details, true );
            if ( is_array( $d ) ) {
                $details_decoded = $d;
            }
        }

        return array(
            'id'                  => (int) $row->id,
            'to_email'            => (string) ( $row->to_email ?? '' ),
            'subject'             => (string) ( $row->subject ?? '' ),
            'message_type'        => (string) ( $row->message_type ?? '' ),
            'message_type_label'  => self::message_type_label( $row->message_type ?? '' ),
            'status'              => $status,
            'attempts'            => (int) ( $row->attempts ?? 0 ),
            'max_attempts'        => self::MAX_ATTEMPTS,
            'failure_category'    => $category !== '' ? $category : ( $status === 'failed' ? 'transport_error' : '' ),
            'last_error_code'     => (string) ( $row->last_error_code ?? '' ),
            'last_error'          => (string) ( $row->last_error ?? '' ),
            'last_error_details'  => $details_decoded,
            'created_at'          => (string) ( $row->created_at ?? '' ),
            'last_attempt_at'     => (string) ( $row->last_attempt_at ?? '' ),
            'failed_at'           => (string) ( $row->failed_at ?? '' ),
            'next_retry'          => (string) ( $row->next_retry ?? '' ),
            'accepted_at'         => (string) ( $row->accepted_at ?? '' ),
            'reference_type'      => (string) ( $row->reference_type ?? '' ),
            'reference_id'        => (int) ( $row->reference_id ?? 0 ),
            'reference_ref'       => $link ? $link['ref'] : '',
            'reference_label'     => $link ? $link['label'] : '',
            'reference_url'       => $link ? $link['url'] : '',
            'resent_from_id'      => ! empty( $row->resent_from_id ) ? (int) $row->resent_from_id : null,
            'resent_by'           => ! empty( $row->resent_by ) ? (int) $row->resent_by : null,
            'can_resend'          => $can_resend,
            'resend_block_reason' => $resend_block_reason,
        );
    }

    /**
     * Clone a queue row for forensic-safe immediate resend and dispatch it now.
     *
     * @param int $id      Source queue row ID.
     * @param int $user_id Acting WordPress user ID.
     * @return array|WP_Error Formatted result of the newly dispatched row.
     */
    public static function resend_row( $id, $user_id = 0 ) {
        global $wpdb;
        $id = absint( $id );
        if ( $id < 1 ) return new WP_Error( 'invalid_email_id', 'A valid email queue ID is required.', array( 'status' => 400 ) );
        $table = self::table();

        if ( $wpdb->query( 'START TRANSACTION' ) === false ) {
            return new WP_Error( 'transaction_failed', 'Could not start resend transaction.', array( 'status' => 500 ) );
        }
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d FOR UPDATE", $id ) );
        if ( ! $row ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'email_not_found', 'Email queue entry not found.', array( 'status' => 404 ) );
        }
        if ( $row->status === 'processing' ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'email_processing', 'This email is currently being processed by a worker.', array( 'status' => 409 ) );
        }
        if ( in_array( (string) ( $row->failure_category ?? '' ), array( 'stale_source', 'policy_expired' ), true ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'email_obsolete', 'This email became stale or expired because the source record changed. Trigger a fresh email from the booking or invoice instead.', array( 'status' => 409 ) );
        }

        $now       = current_time( 'mysql' );
        $suffix    = ':retry:' . bin2hex( random_bytes( 4 ) );
        $base_key  = ! empty( $row->message_key ) ? (string) $row->message_key : ( 'msg:' . $id );
        $retry_key = substr( $base_key, 0, 100 - strlen( $suffix ) ) . $suffix;

        $inserted = $wpdb->insert( $table, array(
            'to_email'           => $row->to_email,
            'subject'            => $row->subject,
            'body'               => $row->body,
            'headers'            => $row->headers,
            'attachments'        => $row->attachments,
            'attempts'           => 0,
            'status'             => 'pending',
            'next_retry'         => $now,
            'message_key'        => $retry_key,
            'payload_hash'       => $row->payload_hash,
            'attachment_meta'    => $row->attachment_meta,
            'message_type'       => $row->message_type,
            'reference_type'     => $row->reference_type,
            'reference_id'       => $row->reference_id,
            'resent_from_id'     => (int) $row->id,
            'resent_by'          => $user_id > 0 ? (int) $user_id : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : null ),
            'created_at'         => $now,
        ) );
        if ( $inserted === false ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'resend_insert_failed', 'Could not queue the resend attempt.', array( 'status' => 500 ) );
        }
        $new_id = (int) $wpdb->insert_id;

        if ( $wpdb->query( 'COMMIT' ) === false ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'resend_commit_failed', 'Could not commit the resend attempt.', array( 'status' => 500 ) );
        }

        if ( class_exists( 'MBS_Audit_Log' ) ) {
            $audit_ref = self::resolve_audit_ref( $row, $id );
            MBS_Audit_Log::log(
                $audit_ref,
                'email_resent',
                sprintf( 'Manual resend triggered for %s email to %s (cloned from queue #%d → #%d).', self::message_type_label( $row->message_type ?? '' ), (string) $row->to_email, $id, $new_id )
            );
        }

        self::dispatch_message( $new_id );

        $select_cols = 'id, to_email, subject, attempts, status, next_retry, message_type, reference_type, reference_id, created_at, accepted_at, last_attempt_at, failed_at, last_error, failure_category, last_error_code, last_error_details, resent_from_id, resent_by';
        $fresh = $wpdb->get_row( $wpdb->prepare( "SELECT {$select_cols} FROM {$table} WHERE id = %d", $new_id ) );
        $links = $fresh ? self::resolve_reference_links( array( $fresh ) ) : array();

        return array(
            'resent_from_id' => $id,
            'new_id'         => $new_id,
            'row'            => $fresh ? self::format_diagnostic_row( $fresh, $links ) : null,
            'stats'          => self::get_stats(),
        );
    }

    /**
     * Dismiss / acknowledge a failed queue row so it clears from active failure alerts.
     *
     * @param int $id      Queue row ID.
     * @param int $user_id Acting administrator user ID.
     * @return array|WP_Error
     */
    public static function dismiss_row( $id, $user_id = 0 ) {
        global $wpdb;
        $id = absint( $id );
        if ( $id < 1 ) return new WP_Error( 'invalid_email_id', 'A valid email queue ID is required.', array( 'status' => 400 ) );
        $table = self::table();

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status, to_email, message_type, reference_type, reference_id, failure_category FROM {$table} WHERE id = %d",
            $id
        ) );
        if ( ! $row ) {
            return new WP_Error( 'email_not_found', 'Email queue entry not found.', array( 'status' => 404 ) );
        }
        if ( $row->status !== 'failed' ) {
            return new WP_Error( 'email_not_failed', 'Only failed email rows can be dismissed.', array( 'status' => 409 ) );
        }

        $updated = $wpdb->update(
            $table,
            array( 'failure_category' => 'dismissed' ),
            array( 'id' => $id, 'status' => 'failed' )
        );
        if ( $updated === false ) {
            return new WP_Error( 'dismiss_failed', 'Could not dismiss this email failure.', array( 'status' => 500 ) );
        }

        if ( class_exists( 'MBS_Audit_Log' ) ) {
            $audit_ref = self::resolve_audit_ref( $row, $id );
            MBS_Audit_Log::log(
                $audit_ref,
                'email_failure_dismissed',
                sprintf( 'Dismissed failed %s email #%d to %s.', self::message_type_label( $row->message_type ?? '' ), $id, (string) $row->to_email )
            );
        }

        return array(
            'dismissed' => true,
            'id'        => $id,
            'stats'     => self::get_stats(),
        );
    }

    /**
     * Lightweight summary of queued/failed emails for a single booking
     * (used by Single Booking → Access email readiness card).
     *
     * @param int $booking_id Booking numeric ID.
     * @return array
     */
    public static function get_booking_email_summary( $booking_id ) {
        global $wpdb;
        $booking_id = absint( $booking_id );
        if ( $booking_id < 1 ) {
            return array( 'failed_count' => 0, 'pending_count' => 0, 'latest_access' => null, 'latest_failed' => null );
        }
        $table = self::table();
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, to_email, subject, attempts, status, next_retry, message_type, reference_type, reference_id, created_at, accepted_at, last_attempt_at, failed_at, last_error, failure_category, last_error_code
             FROM {$table}
             WHERE reference_type = 'booking' AND reference_id = %d
             ORDER BY id DESC LIMIT 25",
            $booking_id
        ) );

        $failed_count  = 0;
        $pending_count = 0;
        $latest_access = null;
        $latest_failed = null;
        foreach ( (array) $rows as $row ) {
            $cat = (string) ( $row->failure_category ?? '' );
            if ( $row->status === 'failed' && $cat !== 'dismissed' && ! in_array( $cat, array( 'stale_source', 'policy_expired' ), true ) ) {
                $failed_count++;
                if ( ! $latest_failed ) $latest_failed = $row;
            } elseif ( $row->status === 'pending' ) {
                $pending_count++;
            }
            if ( ! $latest_access && in_array( (string) $row->message_type, array( 'access_details', 'access_manual' ), true ) ) {
                $latest_access = $row;
            }
        }
        return array(
            'failed_count'  => $failed_count,
            'pending_count' => $pending_count,
            'latest_access' => $latest_access,
            'latest_failed' => $latest_failed,
        );
    }
}
