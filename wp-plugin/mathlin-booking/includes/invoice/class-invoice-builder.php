<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Invoice Document Builder — constructs view models from database records.
 *
 * Provides two entry points:
 *   build_from_booking($booking_id)       — individual booking invoices
 *   build_from_ledger_invoice($invoice_id) — consolidated series invoices
 *
 * Error contract: returns MBS_Invoice_Document_View_Model|WP_Error
 */
class MBS_Invoice_Document_Builder {

    /**
     * Build a view model for an individual booking invoice.
     *
     * @param int    $booking_id  Booking primary key.
     * @param string $mode        'issued' or 'current_account'.
     * @return MBS_Invoice_Document_View_Model|WP_Error
     */
    public static function build_from_booking( $booking_id, $mode = 'issued' ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        $booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $booking_id ) );
        if ( ! $booking ) {
            return new WP_Error( 'booking_not_found', 'Booking not found for document rendering.' );
        }

        // Try to load the immutable document
        $doc_table = $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE;
        $document = null;

        if ( ! empty( $booking->current_invoice_document_id ) ) {
            $document = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$doc_table} WHERE id = %d AND status = 'issued'",
                (int) $booking->current_invoice_document_id
            ) );
        }

        // Fallback: find the latest issued document for this booking
        if ( ! $document ) {
            $document = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$doc_table} WHERE booking_id = %d AND status = 'issued' ORDER BY revision DESC LIMIT 1",
                (int) $booking_id
            ) );
        }

        // If we have an immutable document, use its snapshot
        if ( $document && ! empty( $document->snapshot_json ) ) {
            $snapshot = MBS_Issued_Invoice_Snapshot::from_json( $document->snapshot_json );
            if ( is_wp_error( $snapshot ) ) return $snapshot;

            if ( $mode === 'current_account' ) {
                $account_state = MBS_Booking_Account_State_Builder::build( $booking );
                return MBS_Invoice_Document_View_Model::current_account( $snapshot, $account_state );
            }
            return MBS_Invoice_Document_View_Model::issued( $snapshot );
        }

        // Legacy fallback: no document row exists — reconstruct from current data
        $snapshot = self::reconstruct_booking_snapshot( $booking );
        $account_state = ( $mode === 'current_account' )
            ? MBS_Booking_Account_State_Builder::build( $booking )
            : null;

        return MBS_Invoice_Document_View_Model::legacy_reconstruction( $snapshot, $account_state, $mode );
    }

    /**
     * Build a view model for a consolidated ledger invoice.
     *
     * @param int    $invoice_id  Invoice primary key (mathlin_invoices.id).
     * @param string $mode        'issued' or 'current_account'.
     * @return MBS_Invoice_Document_View_Model|WP_Error
     */
    public static function build_from_ledger_invoice( $invoice_id, $mode = 'issued' ) {
        global $wpdb;
        $invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
        $invoice = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$invoice_table} WHERE id = %d", (int) $invoice_id ) );
        if ( ! $invoice ) {
            return new WP_Error( 'invoice_not_found', 'Invoice not found for document rendering.' );
        }

        // Try to load the immutable document
        $doc_table = $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE;
        $document = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$doc_table} WHERE invoice_id = %d AND status = 'issued' ORDER BY revision DESC LIMIT 1",
            (int) $invoice_id
        ) );

        if ( $document && ! empty( $document->snapshot_json ) ) {
            $snapshot = MBS_Issued_Invoice_Snapshot::from_json( $document->snapshot_json );
            if ( is_wp_error( $snapshot ) ) return $snapshot;

            if ( $mode === 'current_account' ) {
                $account_state = MBS_Ledger_Account_State_Builder::build( $invoice );
                return MBS_Invoice_Document_View_Model::current_account( $snapshot, $account_state );
            }
            return MBS_Invoice_Document_View_Model::issued( $snapshot );
        }

        // Legacy fallback: reconstruct from current invoice + items data
        $snapshot = self::reconstruct_ledger_snapshot( $invoice );
        $account_state = ( $mode === 'current_account' )
            ? MBS_Ledger_Account_State_Builder::build( $invoice )
            : null;

        return MBS_Invoice_Document_View_Model::legacy_reconstruction( $snapshot, $account_state, $mode );
    }

    /**
     * Build from an immutable document row directly (by document ID).
     * Used by the delivery endpoint and email worker.
     *
     * @param int    $document_id  Primary key in mathlin_invoice_documents.
     * @param string $mode         'issued' or 'current_account'.
     * @return MBS_Invoice_Document_View_Model|WP_Error
     */
    public static function build_from_document( $document_id, $mode = 'issued' ) {
        global $wpdb;
        $doc_table = $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE;
        $document = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$doc_table} WHERE id = %d", (int) $document_id ) );
        if ( ! $document ) {
            return new WP_Error( 'document_not_found', 'Invoice document not found.' );
        }

        if ( empty( $document->snapshot_json ) ) {
            return new WP_Error( 'document_no_snapshot', 'Document has no snapshot data.' );
        }

        $snapshot = MBS_Issued_Invoice_Snapshot::from_json( $document->snapshot_json );
        if ( is_wp_error( $snapshot ) ) return $snapshot;

        if ( $mode === 'issued' ) {
            return MBS_Invoice_Document_View_Model::issued( $snapshot );
        }

        // For current_account mode, we need to load the source record
        $account_state = null;
        if ( ! empty( $document->invoice_id ) ) {
            $invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
            $invoice = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$invoice_table} WHERE id = %d", (int) $document->invoice_id ) );
            if ( $invoice ) $account_state = MBS_Ledger_Account_State_Builder::build( $invoice );
        } elseif ( ! empty( $document->booking_id ) ) {
            $booking_table = $wpdb->prefix . MBS_TABLE;
            $booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$booking_table} WHERE id = %d", (int) $document->booking_id ) );
            if ( $booking ) $account_state = MBS_Booking_Account_State_Builder::build( $booking );
        }

        return MBS_Invoice_Document_View_Model::current_account( $snapshot, $account_state );
    }

    // ── Legacy Reconstruction ──────────────────────────────────────────────────

    /**
     * Reconstruct a snapshot from a booking row (pre-migration invoices).
     */
    private static function reconstruct_booking_snapshot( $booking ) {
        $org = class_exists( 'MBS_Email_Templates' ) ? MBS_Email_Templates::get_org_settings() : array();
        $bank = MBS_Bookings::get_bank_details();
        $is_offline = MBS_Bookings::booking_is_offline( $booking );

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

        return MBS_Issued_Invoice_Snapshot::build( array(
            'recipient_name'         => $booking->name,
            'recipient_organisation' => $booking->organisation ?: null,
            'recipient_email'        => $booking->email,
            'recipient_address'      => $booking->address ?: null,
            'invoice_number'         => $booking->invoice_number,
            'booking_ref'            => $booking->ref,
            'document_type'          => 'invoice',
            'issue_date'             => wp_date( 'Y-m-d', strtotime( $booking->created_at ) ),
            'due_date'               => wp_date( 'Y-m-d', strtotime( $booking->created_at . ' +' . $bank['payment_days'] . ' days' ) ),
            'line_items'             => $line_items,
            'currency'               => 'GBP',
            'subtotal_minor'         => (int) round( (float) $booking->amount * 100 ),
            'total_minor'            => (int) round( (float) $booking->amount * 100 ),
            'payment_method'         => $is_offline ? 'offline_bacs' : 'online',
            'payment_terms_days'     => (int) $bank['payment_days'],
            'bank_details'           => ( ! empty( $bank['sort_code'] ) || ! empty( $bank['account_number'] ) )
                ? $bank : null,
            'status_at_issue'        => $booking->status,
        ) );
    }

    /**
     * Reconstruct a snapshot from a ledger invoice row (pre-migration).
     */
    private static function reconstruct_ledger_snapshot( $invoice ) {
        $items = MBS_Billing_Ledger::get_items( $invoice->id );
        $series = $invoice->series_ref ? MBS_Series::get( $invoice->series_ref ) : null;
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
            'recipient_name'         => $invoice->contact_name,
            'recipient_organisation' => $invoice->contact_organisation ?: null,
            'recipient_email'        => $invoice->contact_email,
            'recipient_address'      => $invoice->contact_address ?: null,
            'invoice_number'         => $invoice->invoice_ref,
            'series_ref'             => $invoice->series_ref,
            'document_type'          => $invoice->document_type,
            'issue_date'             => $invoice->issued_at ? wp_date( 'Y-m-d', strtotime( $invoice->issued_at ) ) : wp_date( 'Y-m-d' ),
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
            'bank_details'           => ( ! empty( $bank['sort_code'] ) || ! empty( $bank['account_number'] ) )
                ? $bank : null,
            'status_at_issue'        => $invoice->status,
        ) );
    }
}
