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
     * Legacy public wrapper: try immediate send, queue on failure.
     * Existing callers continue using this method unchanged.
     *
     * @return bool True if sent immediately, false if queued for retry.
     */
    public static function send( $to, $subject, $body, $headers = array(), $attachments = array() ) {
        $result = wp_mail( $to, $subject, $body, $headers, $attachments );

        if ( ! $result ) {
            self::legacy_queue( $to, $subject, $body, $headers, $attachments );
            error_log( "[MGF Venue] Email to {$to} failed, queued for retry." );
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
             SET status = 'sent', accepted_at = %s, attempts = attempts + 1
             WHERE id = %d AND worker_id = %s AND status = 'processing'",
            $now, (int) $id, sanitize_text_field( $worker_id )
        ) ) === 1;
    }

    /**
     * Release a row for retry after delivery failure.
     *
     * @return bool
     */
    public static function release_for_retry( $id, $worker_id, $error_message = '' ) {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT attempts FROM {$table} WHERE id = %d", (int) $id ) );
        $attempts = $row ? (int) $row->attempts + 1 : 1;

        if ( $attempts >= self::MAX_ATTEMPTS ) {
            return self::mark_failed( $id, $worker_id );
        }

        // Exponential backoff: 5min, 20min, 1hr, 4hr, 16hr
        $delay_minutes = (int) pow( 4, $attempts - 1 ) * 5;
        $next_retry = wp_date( 'Y-m-d H:i:s', time() + ( $delay_minutes * 60 ) );

        return $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET status = 'pending', attempts = %d, next_retry = %s,
                 last_error = %s, claimed_at = NULL, lease_expires_at = NULL, worker_id = NULL
             WHERE id = %d AND worker_id = %s AND status = 'processing'",
            $attempts, $next_retry, sanitize_text_field( substr( $error_message, 0, 500 ) ),
            (int) $id, sanitize_text_field( $worker_id )
        ) ) === 1;
    }

    /**
     * Permanently fail a row after max retries.
     *
     * @return bool
     */
    public static function mark_failed( $id, $worker_id ) {
        global $wpdb;
        return $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table() . "
             SET status = 'failed', attempts = attempts + 1,
                 claimed_at = NULL, lease_expires_at = NULL, worker_id = NULL
             WHERE id = %d AND worker_id = %s AND status = 'processing'",
            (int) $id, sanitize_text_field( $worker_id )
        ) ) === 1;
    }

    /** A disabled policy is not a failed delivery attempt. Keep its intent. */
    public static function pause_for_policy( $id, $worker_id, $reason ) {
        global $wpdb;
        return $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table() . " SET status='pending',next_retry=%s,last_error=%s,claimed_at=NULL,lease_expires_at=NULL,worker_id=NULL WHERE id=%d AND worker_id=%s AND status='processing'",
            wp_date( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ), sanitize_text_field( $reason ), (int) $id, sanitize_text_field( $worker_id )
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
            if ( $email->message_type === 'request_rejected' ) {
                $request = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mathlin_mod_requests WHERE id=%d', (int) $email->reference_id ) );
                if ( $wpdb->last_error ) { self::release_for_retry( $email->id, $worker_id, 'Request source temporarily unavailable.' ); continue; }
                $booking = $request ? MBS_Bookings::get( $request->booking_ref ) : null;
                if ( $wpdb->last_error ) { self::release_for_retry( $email->id, $worker_id, 'Booking source temporarily unavailable.' ); continue; }
                if ( ! $request || $request->status !== 'rejected' || ! $booking || strcasecmp( $booking->email, $email->to_email ) !== 0 ) { self::mark_failed( $email->id, $worker_id ); continue; }
            }
            if($email->message_type==='series_confirmation'){
                $source=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.MBS_SERIES_TABLE.' WHERE id=%d',(int)$email->reference_id));
                if(!empty($wpdb->last_error)){self::release_for_retry($email->id,$worker_id,'Series source temporarily unavailable.');continue;}
                $version=self::series_message_version($email);
                if(!$source||$source->status!=='confirmed'||strcasecmp($source->contact_email,$email->to_email)!==0||($version!==null&&(int)$source->version!==(int)$version)){self::mark_failed($email->id,$worker_id);continue;}
            }
            if($email->message_type==='series_state'){
                $meta=json_decode((string)$email->attachment_meta,true);
                $series=is_array($meta)&&!empty($meta['series_ref'])?MBS_Series::get($meta['series_ref']):null;
                if(!empty($wpdb->last_error)){self::release_for_retry($email->id,$worker_id,'Series source temporarily unavailable.');continue;}
                if(!$series||!isset($meta['series_version'])||(int)$series->version!==(int)$meta['series_version']||strcasecmp($series->contact_email,$email->to_email)!==0){self::mark_failed($email->id,$worker_id);continue;}
            }
            if(in_array($email->message_type,array('booking_edit','request_change','request_cancel'),true)){
                $booking=MBS_Bookings::get_by_id((int)$email->reference_id);
                if(!empty($wpdb->last_error)){self::release_for_retry($email->id,$worker_id,'Booking source temporarily unavailable.');continue;}
                if($email->message_type==='request_cancel'){
                    // A committed cancellation remains a valid historical notice
                    // after deletion; reopening it supersedes the queued notice.
                    if($booking&&($booking->status!=='cancelled'||strcasecmp($booking->email,$email->to_email)!==0)){self::mark_failed($email->id,$worker_id);continue;}
                }else{
                    if(!$booking||!in_array($booking->status,array('pending','confirmed','deposit_paid','paid'),true)||$email->message_key!==$email->message_type.':'.MBS_Email::change_source_hash($booking)){self::mark_failed($email->id,$worker_id);continue;}
                    $message=$email->message_type==='booking_edit'?MBS_Admin::build_edit_notification($booking,(float)$booking->amount,(float)$booking->amount):MBS_Modification::build_booker_approved_simple($booking);
                    $email->subject=$message['subject'];$email->body=$message['body'];$email->headers=wp_json_encode($message['headers']);
                }
            }
            if ( $email->message_type === 'mailbox_verification' ) {
                $created = new DateTimeImmutable( $email->created_at, wp_timezone() );
                if ( $created->getTimestamp() + 900 <= time() ) { self::mark_failed( $email->id, $worker_id ); continue; }
            }
            if ( in_array( $email->message_type, array( 'feedback_request', 'feedback_manual' ), true ) ) {
                $booking = MBS_Bookings::get_by_id( (int) $email->reference_id );
                if(!empty($wpdb->last_error)){self::release_for_retry($email->id,$worker_id,'Feedback source temporarily unavailable.');continue;}
                if ( ! $booking || strcasecmp( $booking->email, $email->to_email ) !== 0 || strpos( $email->message_key, 'feedback:' . MBS_Access_Details::source_hash( $booking ) ) !== 0 || ! in_array( $booking->status, array( 'confirmed','deposit_paid','paid' ), true ) || ! empty( $booking->scout_use ) ) { self::mark_failed( $email->id, $worker_id ); continue; }
                if ( $email->message_type === 'feedback_request' && ! get_option( 'mbs_feedback_enabled', 0 ) ) { self::pause_for_policy( $email->id, $worker_id, 'Feedback is disabled; delivery paused.' ); continue; }
                $message = MBS_Feedback::build_request_message( $booking );
                $email->subject = $message['subject']; $email->body = $message['body']; $email->headers = wp_json_encode( $message['headers'] );
            }
            if ( $email->message_type === 'booking_reminder' ) {
                $booking = MBS_Bookings::get_by_id( (int) $email->reference_id );
                if(!empty($wpdb->last_error)){self::release_for_retry($email->id,$worker_id,'Reminder source temporarily unavailable.');continue;}
                $now = new DateTimeImmutable( 'now', wp_timezone() );
                try { $intervals = $booking ? MBS_Booking_Intervals::intervals($booking) : array(); $start = $intervals ? $intervals[0]['start'] : null; }
                catch(Throwable $e){ self::mark_failed($email->id,$worker_id); continue; }
                if ( ! $booking || ! in_array( $booking->status, array( 'confirmed','deposit_paid','paid' ), true ) || $now >= $start || $email->message_key !== 'reminder:' . MBS_Access_Details::source_hash( $booking ) ) { self::mark_failed( $email->id, $worker_id ); continue; }
                $hours = (int) get_option( 'mbs_reminder_hours', 24 );
                if ( $hours <= 0 || $now < $start->modify( '-' . $hours . ' hours' ) ) {
                    $retry = $hours > 0 ? $start->modify( '-' . $hours . ' hours' )->getTimestamp() : time() + HOUR_IN_SECONDS;
                    $wpdb->query( $wpdb->prepare( "UPDATE " . self::table() . " SET status='pending',next_retry=%s,last_error='Reminder paused or not yet due',claimed_at=NULL,lease_expires_at=NULL,worker_id=NULL WHERE id=%d AND worker_id=%s AND status='processing'", wp_date( 'Y-m-d H:i:s', max( time()+60, $retry ) ), $email->id, $worker_id ) );
                    continue;
                }
                $message = MBS_Email::build_reminder_message( $booking );
                $email->subject=$message['subject']; $email->body=$message['body']; $email->headers=wp_json_encode( $message['headers'] );
            }
            if ( in_array( $email->message_type, array( 'access_details', 'access_manual' ), true ) ) {
                $message = MBS_Access_Details::queued_message( $email );
                if ( is_wp_error( $message ) ) {
                    if ( in_array( $message->get_error_code(), array( 'access_inactive','access_expired','access_stale','access_estate','access_invalid_schedule','access_already_sent' ), true ) ) {
                        self::mark_failed( $email->id, $worker_id );
                    } else {
                        // Policy/timing pauses do not exhaust transport retries.
                        $wpdb->query( $wpdb->prepare( "UPDATE " . self::table() . " SET status='pending',next_retry=%s,last_error=%s,claimed_at=NULL,lease_expires_at=NULL,worker_id=NULL WHERE id=%d AND worker_id=%s AND status='processing'", wp_date( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ), $message->get_error_message(), $email->id, $worker_id ) );
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
                $wpdb->query( $wpdb->prepare( "UPDATE " . self::table() . " SET status='pending',next_retry=%s,last_error=%s,claimed_at=NULL,lease_expires_at=NULL,worker_id=NULL WHERE id=%d AND worker_id=%s AND status='processing'", wp_date( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ), 'Payment feature disabled; delivery paused.', $email->id, $worker_id ) );
                continue;
            }
            // A queued request can become stale while awaiting PDF rendering/retry.
            if ( class_exists( 'MBS_Additional_Charges' ) && $email->reference_type === 'invoice'
                && in_array( $email->message_type, array( 'invoice_issued','invoice_reminder' ), true ) ) {
                $source_invoice = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . MBS_INVOICE_TABLE . ' WHERE id=%d', $email->reference_id ) );
                if ( ! $source_invoice || in_array( $source_invoice->status, array( 'draft','void' ), true ) || ( $email->message_type === 'invoice_reminder' && (int) $source_invoice->total_minor <= (int) $source_invoice->paid_minor + (int) $source_invoice->credited_minor ) ) {
                    self::mark_failed( $email->id, $worker_id );
                    continue;
                }
                if ( MBS_Additional_Charges::is_charge( $source_invoice ) ) {
                    if ( class_exists( 'MBS_Payment_Features' ) && ! MBS_Payment_Features::enabled( 'additional_charges' ) ) {
                        $wpdb->query( $wpdb->prepare( "UPDATE " . self::table() . " SET status='pending',next_retry=%s,last_error=%s,claimed_at=NULL,lease_expires_at=NULL,worker_id=NULL WHERE id=%d AND worker_id=%s AND status='processing'", wp_date( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ), 'Additional charges disabled; delivery paused.', $email->id, $worker_id ) );
                        continue;
                    }
                    $hold_reason = MBS_Additional_Charges::queued_delivery_error( $source_invoice, json_decode( $email->attachment_meta ?: '{}', true ) ?: array() );
                    if ( $hold_reason ) {
                        self::release_for_retry( $email->id, $worker_id, $hold_reason );
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
                if(!empty($wpdb->last_error)){self::release_for_retry($email->id,$worker_id,'Confirmation source temporarily unavailable.');continue;}
                if ( ! $booking || ! in_array( $booking->status, array( 'confirmed','deposit_paid','paid' ), true ) || strcasecmp( $email->to_email, $booking->email ) !== 0 ) {
                    self::mark_failed( $email->id, $worker_id );
                    continue;
                }
                $message = MBS_Email::build_confirmation_message( $booking );
                $attachment = json_decode( (string) ( $email->attachment_meta ?? '' ), true );
                if ( ! empty( $attachment['document_id'] ) && ! empty( $booking->current_invoice_document_id ) && (int) $attachment['document_id'] !== (int) $booking->current_invoice_document_id ) { self::mark_failed( $email->id, $worker_id ); continue; }
                $email->subject = $message['subject'];
                $email->body = $message['body'];
            }

            // Determine attachment source
            if ( ! empty( $email->attachment_meta ) ) {
                $meta = json_decode( $email->attachment_meta, true );
                if ( is_array( $meta ) && ! empty( $meta['document_id'] ) ) {
                    $temp_file = self::render_attachment_from_meta( $meta );
                    if ( $temp_file && file_exists( $temp_file ) ) {
                        // Check size against email attachment limit
                        $file_size = filesize( $temp_file );
                        $max_attach = (int) get_option( 'mbs_email_attachment_max_bytes', 5242880 );
                        if ( $file_size <= $max_attach ) {
                            $attachments = array( $temp_file );
                        } else {
                            // Oversized: create secure download token and append link to body
                            @unlink( $temp_file );
                            $temp_file = null;
                            $token = MBS_Invoice_Delivery_Endpoint::create_guest_token( (int) $meta['document_id'] );
                            if ( is_wp_error( $token ) ) {
                                // Token creation failed — release for retry, do NOT send without attachment or link
                                self::release_for_retry( $email->id, $worker_id, 'Oversized PDF and token creation failed: ' . $token->get_error_message() );
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
                        }
                    } else {
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
                            self::release_for_retry( $email->id, $worker_id, 'PDF render failed and token creation failed.' );
                            $failed++;
                            continue;
                        }
                        error_log( '[MGF Venue] Invoice PDF render failed for document ' . $meta['document_id'] . '; sent with HTML view link fallback.' );
                    }
                }
            } else {
                // Legacy: use stored file paths
                $attachments = json_decode( $email->attachments, true ) ?: array();
            }

            try {
                $result = wp_mail( $email->to_email, $email->subject, $email->body, $headers, $attachments );

                if ( $result ) {
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
                    self::release_for_retry( $email->id, $worker_id, 'wp_mail() returned false.' );
                    $failed++;
                }
            } catch ( \Exception $e ) {
                self::release_for_retry( $email->id, $worker_id, $e->getMessage() );
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
     * @param array $meta Attachment metadata with document_id and format.
     * @return string|null Temp file path or null.
     */
    private static function render_attachment_from_meta( $meta ) {
        $document_id = (int) ( $meta['document_id'] ?? 0 );
        if ( $document_id < 1 ) return null;

        $format = $meta['format'] ?? 'pdf';
        if ( $format !== 'pdf' ) return null;

        $result = MBS_PDF_Renderer::render_to_temp_file( $document_id );
        if ( is_wp_error( $result ) ) {
            error_log( '[MGF Venue] Email attachment render failed for document ' . $document_id . ': ' . $result->get_error_message() );
            return null; // Graceful degradation: send without attachment
        }

        return $result;
    }

    /**
     * After successful delivery, update domain-specific timestamps.
     */
    private static function perform_domain_bookkeeping( $email ) {
        if ( empty( $email->message_type ) || empty( $email->reference_type ) || empty( $email->reference_id ) ) return;

        global $wpdb;
        $now = current_time( 'mysql' );

        switch ( $email->message_type ) {
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
    private static function legacy_queue( $to, $subject, $body, $headers, $attachments ) {
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

    // ── Statistics & Cleanup ───────────────────────────────────────────────────

    /**
     * Get queue stats for the admin dashboard.
     */
    public static function get_stats() {
        global $wpdb;
        $table = self::table();

        // One consistent aggregate, with no customer payloads. An unavailable
        // table/read is unknown, not a healthy empty outbox.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT SUM(status='pending') AS pending, SUM(status='processing') AS processing,
             SUM(status='sent') AS sent, SUM(status='failed') AS failed,
             MIN(CASE WHEN status='pending' THEN created_at END) AS oldest_pending_at,
             MIN(CASE WHEN status='pending' THEN COALESCE(next_retry,created_at) END) AS next_due_at,
             SUM(status='processing' AND lease_expires_at<%s) AS expired_leases
             FROM {$table}", current_time( 'mysql' )
        ) );
        if ( ! $row || ! empty( $wpdb->last_error ) ) return array( 'available'=>false, 'pending'=>null, 'processing'=>null, 'sent'=>null, 'failed'=>null );
        return array( 'available'=>true, 'pending'=>(int)$row->pending, 'processing'=>(int)$row->processing,
            'sent'=>(int)$row->sent, 'failed'=>(int)$row->failed, 'oldest_pending_at'=>$row->oldest_pending_at,
            'next_due_at'=>$row->next_due_at, 'expired_leases'=>(int)$row->expired_leases,
            'delivery_evidence'=>'sent means transport accepted, not confirmed inbox delivery' );
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
        $stalled = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'failed' WHERE status = 'pending' AND created_at < %s AND COALESCE(message_type,'') NOT IN ('access_details','access_manual','booking_reminder','feedback_request','feedback_manual','invoice_issued','invoice_reminder')",
            $cutoff_7
        ) );
        if ( $stalled > 0 ) {
            error_log( "[MGF Venue] Email queue: force-failed {$stalled} stalled entries older than 7 days." );
        }
    }
}
