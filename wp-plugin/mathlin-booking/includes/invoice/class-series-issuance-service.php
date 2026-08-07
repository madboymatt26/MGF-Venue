<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Series Invoice Issuance Service.
 *
 * Owns the single transaction for consolidated invoice creation.
 * Used by both:
 *   - MBS_Series_Approval_Service (within its approval transaction, via primitives)
 *   - MBS_Billing_Engine cron/catch-up (owns its own transaction)
 *
 * Transaction scope:
 *   create draft invoice → add line items → issue invoice →
 *   create immutable document → audit → enqueue email → COMMIT
 *
 * Issue J: issued_email_sent_at is NOT set during issuance.
 * The outbox worker sets it ONLY after successful wp_mail() acceptance.
 */
class MBS_Series_Issuance_Service {

    /**
     * Issue one consolidated invoice for a billing period.
     * Owns the full transaction (for cron/catch-up callers).
     *
     * @param string $series_ref    Series reference.
     * @param array  $period        Period data {period_start, period_end, occurrences[]}.
     * @param array  $logo_ref      Pre-resolved logo {asset_id, content_hash} or null.
     * @return array|WP_Error  {invoice_ref, document_id, invoice} on success.
     */
    public static function issue_period_invoice( $series_ref, $period, $logo_ref = null ) {
        global $wpdb;

        // Pre-transaction: resolve logo
        if ( $logo_ref === null ) {
            $logo_ref = MBS_Logo_Asset::resolve_current_org_logo();
        }

        $series = MBS_Series::get( $series_ref );
        if ( ! $series ) return new WP_Error( 'series_not_found', 'Series not found.' );

        if ( $wpdb->query( 'START TRANSACTION' ) === false ) {
            return new WP_Error( 'transaction_start_failed', 'Could not start invoice issuance.' );
        }

        $result = self::issue_within_transaction( $series, $period, $logo_ref );
        if ( is_wp_error( $result ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $result;
        }

        if ( $wpdb->query( 'COMMIT' ) === false ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'commit_failed', 'Could not commit invoice issuance.' );
        }

        // Post-commit: spawn cron for immediate email delivery
        if ( function_exists( 'spawn_cron' ) ) spawn_cron();

        return $result;
    }

    /**
     * Issue within an EXISTING transaction (called by Approval_Service).
     * Does NOT own START TRANSACTION / COMMIT.
     * Uses $manage_transaction=false for ledger primitives.
     *
     * @param object $series    Locked series row.
     * @param array  $period    {period_start, period_end, occurrences[{ref, date, amount_minor, description}]}.
     * @param array  $logo_ref  Pre-resolved logo.
     * @return array|WP_Error  {invoice_ref, document_id, invoice_id} on success.
     */
    public static function issue_within_transaction( $series, $period, $logo_ref = null ) {
        global $wpdb;

        $occurrences = $period['occurrences'] ?? array();
        if ( empty( $occurrences ) ) {
            return new WP_Error( 'no_occurrences', 'No occurrences to invoice in this period.' );
        }

        // Idempotency key — use the established canonical format from the billing engine
        // (compatible with existing v3.21 invoices: "series:{ref}:period:{key}:v1")
        $period_key = $period['period_key'] ?? ( $period['period_start'] . ':' . $period['period_end'] );
        $idempotency_key = 'series:' . $series->series_ref . ':period:' . $period_key . ':v1';

        // Use canonical due date from the billing engine (REQUIRED — no fallback)
        if ( empty( $period['due_on'] ) ) {
            return new WP_Error( 'period_due_on_required', 'Canonical due_on date is required for invoice issuance.' );
        }
        if ( empty( $period['period_key'] ) ) {
            return new WP_Error( 'period_key_required', 'period_key is required for idempotent invoice issuance.' );
        }
        $due_date = $period['due_on'] . ' 23:59:59';

        // 1. Create draft invoice (using ledger primitives without own transaction)
        $draft_result = MBS_Billing_Ledger::create_draft_invoice( array(
            'series_ref'           => $series->series_ref,
            'contact_name'         => $series->contact_name,
            'contact_organisation' => $series->contact_organisation,
            'contact_email'        => $series->contact_email,
            'contact_address'      => $series->contact_address,
            'billing_mode'         => $series->billing_mode,
            'period_start'         => $period['period_start'],
            'period_end'           => $period['period_end'],
            'currency'             => 'GBP',
            'due_at'               => $due_date,
        ), $idempotency_key );

        if ( is_wp_error( $draft_result ) ) return $draft_result;

        // Idempotent replay — invoice already exists for this period
        if ( ! empty( $draft_result['idempotent_replay'] ) && ! empty( $draft_result['invoice'] ) ) {
            $existing_invoice = $draft_result['invoice'];
            if ( $existing_invoice->status !== 'draft' ) {
                // Already issued — no-op
                return array(
                    'invoice_ref'  => $existing_invoice->invoice_ref,
                    'invoice_id'   => (int) $existing_invoice->id,
                    'document_id'  => null, // Already created
                    'no_op'        => true,
                );
            }
        }

        $invoice = $draft_result['invoice'];
        $version = (int) $invoice->version;

        // 2. Add line items (without own transaction)
        foreach ( $occurrences as $occurrence ) {
            $item_result = MBS_Billing_Ledger::add_item( $invoice->invoice_ref, array(
                'booking_ref'      => $occurrence['ref'] ?? null,
                'service_date'     => $occurrence['date'],
                'description'      => $occurrence['description'] ?? ( $series->space . ' hire on ' . wp_date( 'j F Y', strtotime( $occurrence['date'] ) ) ),
                'unit_amount_minor' => (int) $occurrence['amount_minor'],
                'quantity_milli'   => 1000,
                'item_type'        => 'hire',
            ), $version, false ); // $manage_transaction = false

            if ( is_wp_error( $item_result ) ) return $item_result;
            $version = (int) $item_result['invoice']->version;
        }

        // 3. Issue the invoice (without own transaction)
        $issued_result = MBS_Billing_Ledger::issue_invoice( $invoice->invoice_ref, $version, false );
        if ( is_wp_error( $issued_result ) ) return $issued_result;

        $issued_invoice = $issued_result['invoice'];
        $items = MBS_Billing_Ledger::get_items( $issued_invoice->id );

        // 4. Create immutable document (within this transaction)
        $document_id = MBS_Invoice_Document_Service::create_ledger_document_within_transaction(
            $issued_invoice, $items, $series, $logo_ref
        );
        if ( is_wp_error( $document_id ) ) return $document_id;

        // 5. Audit
        $audit_ok = MBS_Audit_Log::log(
            $issued_invoice->invoice_ref,
            'invoice_issued',
            'Consolidated invoice issued for ' . $period['period_start'] . ' to ' . $period['period_end'] . ' (' . count( $occurrences ) . ' sessions, ' . MBS_Money::format( (int) $issued_invoice->total_minor ) . ').'
        );
        if ( ! $audit_ok ) return new WP_Error( 'audit_failed', 'Could not record issuance audit.' );

        // 6. Enqueue invoice email (Issue J: do NOT set issued_email_sent_at here)
        $message_key = 'invoice_issued:' . $issued_invoice->invoice_ref . ':doc' . $document_id;
        $subject = 'Invoice ' . $issued_invoice->invoice_ref . ' — ' . $series->series_ref;
        $body = '<p>Your invoice for ' . esc_html( $series->space ) . ' (' . esc_html( wp_date( 'F Y', strtotime( $period['period_start'] ) ) ) . ') is attached.</p>';
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . ( class_exists( 'MBS_Email_Templates' ) ? MBS_Email_Templates::get_org_settings()['name'] : get_bloginfo( 'name' ) ) . ' <' . get_option( 'admin_email' ) . '>',
            'Reply-To: ' . MBS_Bookings::get_admin_email(),
        );
        $attachment_meta = array( 'document_id' => (int) $document_id, 'format' => 'pdf' );
        $payload_hash = MBS_Email_Queue::compute_payload_hash( $series->contact_email, $subject, $body, $headers, $attachment_meta );

        $enqueued = MBS_Email_Queue::enqueue(
            $series->contact_email, $subject, $body, $headers,
            $message_key, $payload_hash, $attachment_meta,
            array( 'message_type' => 'invoice_issued', 'reference_type' => 'invoice', 'reference_id' => (int) $issued_invoice->id )
        );
        if ( is_wp_error( $enqueued ) ) return $enqueued;

        return array(
            'invoice_ref'  => $issued_invoice->invoice_ref,
            'invoice_id'   => (int) $issued_invoice->id,
            'document_id'  => (int) $document_id,
            'no_op'        => false,
        );
    }
}
