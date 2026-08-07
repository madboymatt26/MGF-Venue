<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Atomic series approval with billing configuration.
 *
 * Owns the single transaction boundary for chargeable series approval.
 * Ensures billing configuration is validated and saved in the same
 * transaction that confirms the series — no separate before/after operation.
 *
 * Transaction scope:
 *   lock series → validate → save billing → confirm occurrences →
 *   create due invoices + snapshots → audit → queue emails → COMMIT
 *
 * Pre-transaction: logo asset resolution (only file I/O phase).
 * Post-commit: HA notifications, optional cron spawn.
 */
class MBS_Series_Approval_Service {

    /**
     * Approve a chargeable series with explicit billing configuration.
     *
     * @param string $series_ref       Series reference.
     * @param array  $billing_config   Reviewed billing configuration.
     * @param int    $expected_version Expected series version for optimistic concurrency.
     * @param bool   $notify_hirer     Whether to queue confirmation email.
     * @return array|WP_Error Result array with series, updated count, etc.
     */
    public static function approve_with_billing( $series_ref, $billing_config, $expected_version, $notify_hirer = true ) {
        global $wpdb;
        $series_table  = $wpdb->prefix . MBS_SERIES_TABLE;
        $booking_table = $wpdb->prefix . MBS_TABLE;
        $series_ref    = sanitize_text_field( $series_ref );

        // ── Pre-transaction: resolve logo asset (file I/O) ─────────────────
        $logo_ref = MBS_Logo_Asset::resolve_current_org_logo();

        // ── Validate billing configuration ─────────────────────────────────
        $billing_validation = self::validate_billing_config( $billing_config );
        if ( is_wp_error( $billing_validation ) ) return $billing_validation;

        // ── BEGIN TRANSACTION ──────────────────────────────────────────────
        if ( $wpdb->query( 'START TRANSACTION' ) === false ) {
            return new WP_Error( 'transaction_start_failed', 'Could not start the approval transaction.' );
        }

        // Lock and validate series
        $series = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$series_table} WHERE series_ref = %s FOR UPDATE",
            $series_ref
        ) );

        if ( ! $series ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'series_not_found', 'Recurring series not found.' );
        }

        // Idempotent retry: already confirmed
        if ( $series->status === 'confirmed' ) {
            $wpdb->query( 'ROLLBACK' );
            return array(
                'series'       => $series,
                'transitioned' => false,
                'no_op'        => true,
                'updated'      => 0,
                'email_sent'   => ! empty( $series->confirmation_sent_at ),
            );
        }

        if ( $series->status !== 'pending' ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'invalid_series_transition', 'Only a pending recurring series can be approved.' );
        }

        if ( (int) $expected_version !== (int) $series->version ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'series_precondition_failed', 'The recurring series changed since it was loaded. Refresh and try again.' );
        }

        // ── Save billing configuration ─────────────────────────────────────
        $now = current_time( 'mysql' );
        $billing_update = array(
            'billing_mode'              => sanitize_key( $billing_config['billing_mode'] ),
            'billing_treatment'         => sanitize_key( $billing_config['billing_treatment'] ),
            'payment_method'            => sanitize_key( $billing_config['payment_method'] ),
            'invoice_lead_days'         => absint( $billing_config['invoice_lead_days'] ?? 28 ),
            'payment_terms_days'        => absint( $billing_config['payment_terms_days'] ?? 14 ),
            'billing_schedule_json'     => ! empty( $billing_config['billing_schedule'] )
                ? wp_json_encode( $billing_config['billing_schedule'] ) : '[]',
            'billing_reviewed_at'       => $now,
            'billing_reviewed_by'       => (int) get_current_user_id(),
            'billing_reviewed_version'  => (int) $series->version,
            'status'                    => 'confirmed',
            'version'                   => (int) $series->version + 1,
            'updated_at'               => $now,
        );

        $series_updated = $wpdb->update( $series_table, $billing_update, array(
            'series_ref' => $series_ref,
            'status'     => 'pending',
            'version'    => (int) $series->version,
        ) );

        if ( $series_updated !== 1 ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'series_concurrent_update', 'The series was updated by another request.' );
        }

        // ── Confirm pending occurrences ────────────────────────────────────
        $affected = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$booking_table} WHERE series_id = %s AND status = 'pending' FOR UPDATE",
            $series_ref
        ) );

        $confirmed_count = $wpdb->query( $wpdb->prepare(
            "UPDATE {$booking_table} SET status = 'confirmed' WHERE series_id = %s AND status = 'pending'",
            $series_ref
        ) );

        if ( $confirmed_count === false ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'series_occurrence_update_failed', 'Could not confirm pending occurrences.' );
        }

        // ── Create immediately-due invoices (if billing_treatment = invoice_managed) ──
        $created_documents = array();
        if ( $billing_config['billing_treatment'] === 'invoice_managed' ) {
            $invoice_result = self::create_due_invoices_within_transaction(
                $series_ref, $series, $billing_config, $logo_ref, $affected
            );
            if ( is_wp_error( $invoice_result ) ) {
                $wpdb->query( 'ROLLBACK' );
                return $invoice_result;
            }
            $created_documents = $invoice_result;
        }

        // ── Audit record (inside transaction) ──────────────────────────────
        $audit_details = 'Approved with billing: ' . $billing_config['billing_mode']
            . ' / ' . $billing_config['billing_treatment']
            . ' / ' . $billing_config['payment_method']
            . '. Confirmed ' . (int) $confirmed_count . ' occurrence(s).';
        MBS_Audit_Log::log( $series_ref, 'series_approved_with_billing', $audit_details );

        // ── Queue confirmation email (inside transaction) ──────────────────
        if ( $notify_hirer ) {
            $fresh_series = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$series_table} WHERE series_ref = %s", $series_ref
            ) );

            $message_key = 'series_confirmation:' . $series_ref . ':v' . ( (int) $series->version + 1 );
            $email_body = self::build_confirmation_email_body( $fresh_series, $affected );
            $subject = 'Your recurring booking is approved — ' . $series_ref;
            $headers = self::build_email_headers();

            $payload_hash = MBS_Email_Queue::compute_payload_hash(
                $fresh_series->contact_email, $subject, $email_body, $headers, null
            );

            $enqueued = MBS_Email_Queue::enqueue(
                $fresh_series->contact_email,
                $subject,
                $email_body,
                $headers,
                $message_key,
                $payload_hash,
                null, // No attachment for confirmation email
                array(
                    'message_type'   => 'series_confirmation',
                    'reference_type' => 'series',
                    'reference_id'   => (int) $fresh_series->id,
                )
            );

            if ( is_wp_error( $enqueued ) ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'queue_insert_failed', 'Could not queue the confirmation email: ' . $enqueued->get_error_message() );
            }
        }

        // ── Queue invoice emails (inside transaction) ──────────────────────
        foreach ( $created_documents as $doc ) {
            $inv_key = 'invoice_issued:' . $doc['invoice_ref'] . ':doc' . $doc['document_id'];
            $inv_body = self::build_invoice_email_body( $doc );
            $inv_subject = 'Invoice ' . $doc['invoice_ref'] . ' — ' . $series_ref;
            $inv_headers = self::build_email_headers();

            $inv_meta = array( 'document_id' => (int) $doc['document_id'], 'format' => 'pdf' );
            $inv_hash = MBS_Email_Queue::compute_payload_hash(
                $series->contact_email, $inv_subject, $inv_body, $inv_headers, $inv_meta
            );

            $inv_enqueued = MBS_Email_Queue::enqueue(
                $series->contact_email,
                $inv_subject,
                $inv_body,
                $inv_headers,
                $inv_key,
                $inv_hash,
                $inv_meta,
                array(
                    'message_type'   => 'invoice_issued',
                    'reference_type' => 'invoice',
                    'reference_id'   => (int) $doc['invoice_id'],
                )
            );

            if ( is_wp_error( $inv_enqueued ) ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'queue_insert_failed', 'Could not queue an invoice email: ' . $inv_enqueued->get_error_message() );
            }
        }

        // ── COMMIT ─────────────────────────────────────────────────────────
        if ( $wpdb->query( 'COMMIT' ) === false ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'transaction_commit_failed', 'Could not commit the approval.' );
        }

        // ── Post-commit: HA notifications ──────────────────────────────────
        foreach ( $affected as $booking ) {
            $booking->status = 'confirmed';
            MBS_HomeAssistant::notify( $booking );
            $wpdb->update( $booking_table, array( 'ha_notified' => 1 ), array( 'ref' => $booking->ref ) );
        }

        // Optionally trigger cron for immediate email processing
        if ( function_exists( 'spawn_cron' ) ) {
            spawn_cron();
        }

        $final_series = MBS_Series::get( $series_ref );
        return array(
            'series'       => $final_series,
            'transitioned' => true,
            'no_op'        => false,
            'updated'      => (int) $confirmed_count,
            'documents'    => $created_documents,
        );
    }

    // ── Validation ─────────────────────────────────────────────────────────────

    /**
     * Validate billing configuration is complete and internally consistent.
     *
     * @param array $config
     * @return true|WP_Error
     */
    private static function validate_billing_config( $config ) {
        $required = array( 'billing_mode', 'billing_treatment', 'payment_method' );
        foreach ( $required as $field ) {
            if ( empty( $config[ $field ] ) ) {
                return new WP_Error( 'billing_config_incomplete', "Missing required billing field: {$field}" );
            }
        }

        $valid_modes = array( 'monthly', 'termly', 'upfront', 'none' );
        if ( ! in_array( $config['billing_mode'], $valid_modes, true ) ) {
            return new WP_Error( 'billing_config_invalid', 'Invalid billing mode.' );
        }

        $valid_treatments = array( 'invoice_managed', 'manual_consolidated', 'none' );
        if ( ! in_array( $config['billing_treatment'], $valid_treatments, true ) ) {
            return new WP_Error( 'billing_config_invalid', 'Invalid billing treatment.' );
        }

        $valid_methods = array( 'online', 'offline_bacs', 'none' );
        if ( ! in_array( $config['payment_method'], $valid_methods, true ) ) {
            return new WP_Error( 'billing_config_invalid', 'Invalid payment method.' );
        }

        // Termly requires billing_schedule
        if ( $config['billing_mode'] === 'termly' ) {
            $schedule = $config['billing_schedule'] ?? array();
            if ( empty( $schedule ) || ! is_array( $schedule ) ) {
                return new WP_Error( 'billing_config_incomplete', 'Termly billing requires at least one term date range.' );
            }
        }

        // Online payment requires WooCommerce
        if ( $config['payment_method'] === 'online' && ! class_exists( 'WooCommerce' ) ) {
            return new WP_Error( 'billing_config_invalid', 'Online payment requires WooCommerce to be active.' );
        }

        return true;
    }

    // ── Invoice Creation (within transaction) ──────────────────────────────────

    /**
     * Create any immediately-due invoices within the approval transaction.
     * Returns array of created document metadata.
     */
    private static function create_due_invoices_within_transaction( $series_ref, $series, $billing_config, $logo_ref, $occurrences ) {
        // Determine which periods are already due
        $lead_days = absint( $billing_config['invoice_lead_days'] ?? 28 );
        $terms_days = absint( $billing_config['payment_terms_days'] ?? 14 );
        $today = wp_date( 'Y-m-d' );

        // For now, the billing engine's period calculation handles this.
        // This method creates invoices for periods whose issue_on date <= today.
        // The full catch-up logic already exists in MBS_Billing_Engine.
        // Here we just trigger it for the first applicable period if due.

        // Simplified: if the series start_date is within lead_days of today,
        // the first period is immediately due.
        $first_period_issue = wp_date( 'Y-m-d', strtotime( $series->start_date . " -{$lead_days} days" ) );
        if ( $first_period_issue > $today ) {
            return array(); // No period due yet
        }

        // Delegate to billing engine for proper period calculation
        // The engine will create invoices and we capture the result
        // NOTE: This is a simplified implementation. The full integration
        // with MBS_Billing_Engine::generate_invoice_for_period() happens
        // in Stage 8 when series invoice integration is complete.
        return array();
    }

    // ── Email Building ─────────────────────────────────────────────────────────

    private static function build_confirmation_email_body( $series, $occurrences ) {
        $org = class_exists( 'MBS_Email_Templates' ) ? MBS_Email_Templates::get_org_settings() : array();
        $org_name = $org['name'] ?: get_bloginfo( 'name' );

        $body = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:0 auto;">';
        $body .= '<div style="background:#7413DC;padding:24px 32px;border-radius:8px 8px 0 0;text-align:center;">';
        $body .= '<h1 style="color:#fff;margin:0;font-size:20px;">' . esc_html( $org_name ) . '</h1></div>';
        $body .= '<div style="padding:32px;border:1px solid #e0d0f0;border-top:none;border-radius:0 0 8px 8px;">';
        $body .= '<h2 style="color:#7413DC;">Your recurring booking is approved</h2>';
        $body .= '<p>Hi ' . esc_html( $series->contact_name ) . ',</p>';
        $body .= '<p>Your recurring booking for <strong>' . esc_html( $series->space ) . '</strong> has been approved.</p>';
        $body .= '<p><strong>Schedule:</strong> Weekly, ' . esc_html( substr( $series->start_time, 0, 5 ) . '–' . substr( $series->end_time, 0, 5 ) ) . '</p>';
        $body .= '<p><strong>Dates:</strong> ' . count( $occurrences ) . ' sessions confirmed</p>';
        $body .= '<p><strong>Billing:</strong> ' . esc_html( ucfirst( str_replace( '_', ' ', $series->billing_mode ) ) ) . ' in advance</p>';

        if ( $series->billing_treatment === 'invoice_managed' ) {
            $body .= '<p>We will issue consolidated invoices covering each billing period. No annual lump sum or per-occurrence payment is required at this time.</p>';
        }

        $body .= '<p>If you have any questions, just reply to this email.</p>';
        $body .= '</div></body></html>';

        return $body;
    }

    private static function build_invoice_email_body( $doc ) {
        // Placeholder — will use the template system in Stage 8
        return '<p>Your invoice ' . esc_html( $doc['invoice_ref'] ) . ' is attached.</p>';
    }

    private static function build_email_headers() {
        $org = class_exists( 'MBS_Email_Templates' ) ? MBS_Email_Templates::get_org_settings() : array();
        $from_email = get_option( 'admin_email' );
        $org_name = $org['name'] ?: get_bloginfo( 'name' );
        return array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $org_name . ' <' . $from_email . '>',
            'Reply-To: ' . MBS_Bookings::get_admin_email(),
        );
    }
}
