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
    public static function claim_pending_batch( $worker_id, $limit = 10 ) {
        global $wpdb;
        $table = self::table();
        $now = current_time( 'mysql' );
        $lease_expires = wp_date( 'Y-m-d H:i:s', time() + self::LEASE_SECONDS );

        // Claim rows atomically
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET status = 'processing', claimed_at = %s, lease_expires_at = %s, worker_id = %s
             WHERE status = 'pending' AND (next_retry IS NULL OR next_retry <= %s)
             ORDER BY created_at ASC LIMIT %d",
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

    /**
     * Recover stale processing leases (worker crashed/timed out).
     */
    public static function recover_stale_leases() {
        global $wpdb;
        $table = self::table();
        $now = current_time( 'mysql' );

        $recovered = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET status = 'pending', attempts = attempts + 1,
                 last_error = 'Worker lease expired — released for retry.',
                 claimed_at = NULL, lease_expires_at = NULL, worker_id = NULL,
                 next_retry = %s
             WHERE status = 'processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < %s",
            $now, $now
        ) );

        if ( $recovered > 0 ) {
            error_log( "[MGF Venue] Email queue: recovered {$recovered} stale processing lease(s)." );
        }
    }

    // ── Queue Processing (Cron Worker) ─────────────────────────────────────────

    /**
     * Process the email queue — called hourly by WP-Cron.
     * Handles both legacy file-path entries and new attachment_meta entries.
     */
    public function process_queue() {
        // First recover any stale leases from crashed workers
        self::recover_stale_leases();

        $worker_id = 'mbs-' . substr( md5( uniqid( '', true ) ), 0, 12 );
        $claimed = self::claim_pending_batch( $worker_id, 20 );

        if ( empty( $claimed ) ) return;

        $sent = 0;
        $failed = 0;

        foreach ( $claimed as $email ) {
            $headers = json_decode( $email->headers, true ) ?: array();
            $attachments = array();
            $temp_file = null;
            $download_link_appended = false;

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
                            if ( ! is_wp_error( $token ) ) {
                                $download_url = add_query_arg( array(
                                    'action'      => 'mbs_invoice_document',
                                    'token'       => $token,
                                    'document_id' => (int) $meta['document_id'],
                                    'format'      => 'pdf',
                                ), admin_url( 'admin-ajax.php' ) );
                                $email->body .= '<p style="margin-top:16px;padding:12px;background:#f5f0ff;border-radius:6px;"><strong>Your invoice is available for download:</strong><br><a href="' . esc_url( $download_url ) . '">' . esc_url( $download_url ) . '</a><br><small>This link expires in 72 hours.</small></p>';
                                $download_link_appended = true;
                            }
                            error_log( '[MGF Venue] Invoice PDF oversized (' . size_format( $file_size ) . '); sent with download link instead.' );
                        }
                    } else {
                        // Render failed: also provide secure download link as fallback
                        $token = MBS_Invoice_Delivery_Endpoint::create_guest_token( (int) $meta['document_id'] );
                        if ( ! is_wp_error( $token ) ) {
                            $download_url = add_query_arg( array(
                                'action'      => 'mbs_invoice_document',
                                'token'       => $token,
                                'document_id' => (int) $meta['document_id'],
                                'format'      => 'pdf',
                            ), admin_url( 'admin-ajax.php' ) );
                            $email->body .= '<p style="margin-top:16px;padding:12px;background:#fee2e2;border-radius:6px;"><strong>Invoice attachment could not be generated.</strong> Download your invoice here:<br><a href="' . esc_url( $download_url ) . '">' . esc_url( $download_url ) . '</a></p>';
                            $download_link_appended = true;
                        }
                        error_log( '[MGF Venue] Invoice PDF render failed for document ' . $meta['document_id'] . '; sent with download link fallback.' );
                    }
                }
            } else {
                // Legacy: use stored file paths
                $attachments = json_decode( $email->attachments, true ) ?: array();
            }

            try {
                $result = wp_mail( $email->to_email, $email->subject, $email->body, $headers, $attachments );

                if ( $result ) {
                    self::mark_accepted( $email->id, $worker_id );
                    self::perform_domain_bookkeeping( $email );
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

        self::cleanup();
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
            case 'series_confirmation':
                if ( $email->reference_type === 'series' ) {
                    $wpdb->update(
                        $wpdb->prefix . MBS_SERIES_TABLE,
                        array( 'confirmation_sent_at' => $now ),
                        array( 'id' => (int) $email->reference_id )
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

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return array( 'pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0 );
        }

        return array(
            'pending'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='pending'" ),
            'processing' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='processing'" ),
            'sent'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='sent'" ),
            'failed'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='failed'" ),
        );
    }

    /**
     * Clean up old entries and recover stale processing leases.
     */
    public static function cleanup() {
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

        // Force-fail stalled pending entries older than 7 days
        $cutoff_7 = wp_date( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
        $stalled = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'failed' WHERE status = 'pending' AND created_at < %s",
            $cutoff_7
        ) );
        if ( $stalled > 0 ) {
            error_log( "[MGF Venue] Email queue: force-failed {$stalled} stalled entries older than 7 days." );
        }
    }
}
