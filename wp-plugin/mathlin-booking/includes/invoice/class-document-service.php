<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Invoice Document Service — manages the append-only document lifecycle.
 *
 * Handles document creation (snapshot capture), revision (modifications),
 * and retrieval for both individual booking and ledger invoice documents.
 *
 * All write operations lock the parent row before determining the next
 * revision. The unique key (booking_id, revision) or (invoice_id, revision)
 * is the final concurrency guard.
 */
class MBS_Invoice_Document_Service {

    /**
     * Create an immutable document for an individual booking.
     * Called when a booking is confirmed (the "issuance" moment).
     *
     * @param object $booking      Booking row (already confirmed).
     * @param array  $logo_ref     Pre-resolved logo {asset_id, content_hash} or null.
     * @return int|WP_Error  Document ID on success.
     */
    public static function issue_booking_document( $booking, $logo_ref = null ) {
        global $wpdb;
        $doc_table = $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE;
        $booking_table = $wpdb->prefix . MBS_TABLE;

        // Pre-resolve logo if not provided
        if ( $logo_ref === null ) {
            $logo_ref = MBS_Logo_Asset::resolve_current_org_logo();
        }

        // Lock the booking row to safely determine revision
        $locked = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, current_invoice_document_id FROM {$booking_table} WHERE id = %d FOR UPDATE",
            (int) $booking->id
        ) );
        if ( ! $locked ) {
            return new WP_Error( 'booking_lock_failed', 'Could not lock the booking for document creation.' );
        }

        // Determine next revision
        $max_revision = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(revision) FROM {$doc_table} WHERE booking_id = %d",
            (int) $booking->id
        ) );
        $next_revision = $max_revision + 1;

        // Build the invoice number with revision
        $invoice_number = $booking->invoice_number ?: ( 'INV-' . $booking->ref );
        if ( $next_revision > 1 ) {
            $invoice_number .= '-R' . $next_revision;
        }

        // Build the snapshot
        $snapshot = self::build_booking_snapshot( $booking, $logo_ref, $invoice_number, $next_revision );

        // Insert the document row
        $inserted = $wpdb->insert( $doc_table, array(
            'booking_id'             => (int) $booking->id,
            'invoice_id'             => null,
            'booking_ref'            => $booking->ref,
            'invoice_number'         => $invoice_number,
            'revision'               => $next_revision,
            'document_type'          => 'invoice',
            'snapshot_json'          => $snapshot->to_json(),
            'snapshot_version'       => 1,
            'logo_asset_id'          => $logo_ref ? (int) $logo_ref['asset_id'] : null,
            'logo_content_hash'      => $logo_ref ? $logo_ref['content_hash'] : null,
            'status'                 => 'issued',
            'supersedes_document_id' => $locked->current_invoice_document_id ?: null,
            'issued_at'              => current_time( 'mysql' ),
            'created_at'             => current_time( 'mysql' ),
        ) );

        if ( $inserted === false ) {
            return new WP_Error( 'document_insert_failed', 'Could not create the invoice document.' );
        }

        $document_id = (int) $wpdb->insert_id;

        // Update the booking's current document pointer
        $wpdb->update( $booking_table,
            array( 'current_invoice_document_id' => $document_id ),
            array( 'id' => (int) $booking->id )
        );

        // Supersede the previous document
        if ( $locked->current_invoice_document_id ) {
            $wpdb->update( $doc_table,
                array( 'status' => 'superseded' ),
                array( 'id' => (int) $locked->current_invoice_document_id, 'status' => 'issued' )
            );
        }

        return $document_id;
    }

    /**
     * Create an immutable document for a ledger invoice.
     * Called within the billing engine / approval transaction.
     *
     * @param object $invoice   Invoice row from mathlin_invoices.
     * @param array  $items     Invoice items.
     * @param object $series    Series row (for context).
     * @param array  $logo_ref  Pre-resolved logo or null.
     * @return int|WP_Error  Document ID on success.
     */
    public static function issue_ledger_document( $invoice, $items, $series, $logo_ref = null ) {
        global $wpdb;
        $doc_table = $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE;

        if ( $logo_ref === null ) {
            $logo_ref = MBS_Logo_Asset::resolve_current_org_logo();
        }

        // Determine next revision for this invoice
        $max_revision = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(revision) FROM {$doc_table} WHERE invoice_id = %d",
            (int) $invoice->id
        ) );
        $next_revision = $max_revision + 1;

        $invoice_number = $invoice->invoice_ref;
        if ( $next_revision > 1 ) {
            $invoice_number .= '-R' . $next_revision;
        }

        $snapshot = self::build_ledger_snapshot( $invoice, $items, $series, $logo_ref, $invoice_number, $next_revision );

        $inserted = $wpdb->insert( $doc_table, array(
            'booking_id'             => null,
            'invoice_id'             => (int) $invoice->id,
            'booking_ref'            => null,
            'invoice_number'         => $invoice_number,
            'revision'               => $next_revision,
            'document_type'          => $invoice->document_type,
            'snapshot_json'          => $snapshot->to_json(),
            'snapshot_version'       => 1,
            'logo_asset_id'          => $logo_ref ? (int) $logo_ref['asset_id'] : null,
            'logo_content_hash'      => $logo_ref ? $logo_ref['content_hash'] : null,
            'status'                 => 'issued',
            'supersedes_document_id' => null,
            'issued_at'              => current_time( 'mysql' ),
            'created_at'             => current_time( 'mysql' ),
        ) );

        if ( $inserted === false ) {
            return new WP_Error( 'document_insert_failed', 'Could not create the invoice document.' );
        }

        return (int) $wpdb->insert_id;
    }

    // ── Snapshot Builders ──────────────────────────────────────────────────────

    private static function build_booking_snapshot( $booking, $logo_ref, $invoice_number, $revision ) {
        $bank = MBS_Bookings::get_bank_details();
        $is_offline = MBS_Bookings::booking_is_offline( $booking );
        $deposit_settings = MBS_Bookings::get_deposit_settings();

        $kitchen_price = $booking->kitchen
            ? MBS_Bookings::get_tiered_kitchen_price( MBS_Bookings::get_booking_tier( $booking ) )
            : 0;
        $space_subtotal = (float) $booking->amount - $kitchen_price;

        $line_items = array();
        $line_items[] = array(
            'date'         => $booking->booking_date,
            'space'        => $booking->space,
            'description'  => $booking->space . ' hire — ' . $booking->purpose,
            'amount_minor' => (int) round( $space_subtotal * 100 ),
        );
        if ( $booking->kitchen ) {
            $line_items[] = array(
                'date'         => $booking->booking_date,
                'space'        => '',
                'description'  => 'Kitchen facilities add-on',
                'amount_minor' => (int) round( $kitchen_price * 100 ),
            );
        }

        // Payment schedule
        $payment_schedule = null;
        $total_amount = (float) $booking->amount;
        if ( $total_amount <= 0 ) {
            $payment_schedule = array( 'no_charge' => true );
        } elseif ( $deposit_settings['enabled'] && ! MBS_Bookings::requires_full_payment( $booking->booking_date ) ) {
            $deposit = MBS_Bookings::calculate_deposit( $total_amount );
            $balance_days = $deposit_settings['balance_days'];
            $payment_schedule = array(
                'deposit_minor'    => (int) round( $deposit * 100 ),
                'deposit_due_date' => wp_date( 'Y-m-d' ), // Due immediately
                'balance_minor'    => (int) round( ( $total_amount - $deposit ) * 100 ),
                'balance_due_date' => wp_date( 'Y-m-d', strtotime( $booking->booking_date . " -{$balance_days} days" ) ),
            );
        } elseif ( $deposit_settings['enabled'] ) {
            $payment_schedule = array( 'immediate' => true );
        }

        return MBS_Issued_Invoice_Snapshot::build( array(
            'logo_asset_id'          => $logo_ref ? $logo_ref['asset_id'] : null,
            'logo_content_hash'      => $logo_ref ? $logo_ref['content_hash'] : null,
            'recipient_name'         => $booking->name,
            'recipient_organisation' => $booking->organisation ?: null,
            'recipient_email'        => $booking->email,
            'recipient_address'      => $booking->address ?: null,
            'invoice_number'         => $invoice_number,
            'booking_ref'            => $booking->ref,
            'document_type'          => 'invoice',
            'revision'               => $revision,
            'issue_date'             => wp_date( 'Y-m-d' ),
            'due_date'               => wp_date( 'Y-m-d', strtotime( '+' . $bank['payment_days'] . ' days' ) ),
            'line_items'             => $line_items,
            'currency'               => 'GBP',
            'subtotal_minor'         => (int) round( $total_amount * 100 ),
            'total_minor'            => (int) round( $total_amount * 100 ),
            'payment_method'         => $is_offline ? 'offline_bacs' : 'online',
            'payment_terms_days'     => (int) $bank['payment_days'],
            'bank_details'           => ( ! empty( $bank['sort_code'] ) || ! empty( $bank['account_number'] ) ) ? $bank : null,
            'payment_schedule'       => $payment_schedule,
            'status_at_issue'        => $booking->status,
        ) );
    }

    private static function build_ledger_snapshot( $invoice, $items, $series, $logo_ref, $invoice_number, $revision ) {
        $bank = MBS_Bookings::get_bank_details();

        $line_items = array();
        foreach ( $items as $item ) {
            $line_items[] = array(
                'date'         => $item->service_date,
                'space'        => $series ? $series->space : '',
                'description'  => $item->description,
                'amount_minor' => (int) $item->line_total_minor,
            );
        }

        return MBS_Issued_Invoice_Snapshot::build( array(
            'logo_asset_id'          => $logo_ref ? $logo_ref['asset_id'] : null,
            'logo_content_hash'      => $logo_ref ? $logo_ref['content_hash'] : null,
            'recipient_name'         => $invoice->contact_name,
            'recipient_organisation' => $invoice->contact_organisation ?: null,
            'recipient_email'        => $invoice->contact_email,
            'recipient_address'      => $invoice->contact_address ?: null,
            'invoice_number'         => $invoice_number,
            'series_ref'             => $invoice->series_ref,
            'document_type'          => $invoice->document_type,
            'revision'               => $revision,
            'issue_date'             => wp_date( 'Y-m-d' ),
            'due_date'               => $invoice->due_at ? wp_date( 'Y-m-d', strtotime( $invoice->due_at ) ) : '',
            'period_start'           => $invoice->period_start,
            'period_end'             => $invoice->period_end,
            'line_items'             => $line_items,
            'currency'               => $invoice->currency,
            'subtotal_minor'         => (int) $invoice->subtotal_minor,
            'total_minor'            => (int) $invoice->total_minor,
            'credits_minor'          => (int) $invoice->credited_minor,
            'payment_method'         => $series ? $series->payment_method : 'online',
            'payment_terms_days'     => $series ? (int) $series->payment_terms_days : 14,
            'bank_details'           => ( $series && $series->payment_method === 'offline_bacs' && ( ! empty( $bank['sort_code'] ) || ! empty( $bank['account_number'] ) ) ) ? $bank : null,
            'status_at_issue'        => $invoice->status,
        ) );
    }
}
