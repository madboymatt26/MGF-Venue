<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Accounting Export — generates CSV files compatible with Xero, Sage, and QuickBooks.
 *
 * Exports confirmed/paid invoices in a format that can be imported
 * directly into accounting software.
 */
class MBS_Accounting_Export {

    public function init() {
        add_action( 'wp_ajax_mbs_export_accounting', array( $this, 'handle_export' ) );
    }

    /**
     * Handle the accounting export request.
     */
    public function handle_export() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $format    = sanitize_text_field( $_GET['format'] ?? 'xero' );
        $date_from = sanitize_text_field( $_GET['date_from'] ?? '' );
        $date_to   = sanitize_text_field( $_GET['date_to'] ?? '' );

        $bookings = MBS_Bookings::get_all( array(
            'status'           => '',
            'date_from'        => $date_from,
            'date_to'          => $date_to,
            'orderby'          => 'booking_date',
            'order'            => 'ASC',
            'limit'            => 10000,
            'exclude_archived' => false,
        ) );

        $records = self::normalise_records( $bookings, $date_from, $date_to );

        $filename = 'invoices-' . $format . '-' . date( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );

        $output = fopen( 'php://output', 'w' );
        fwrite( $output, "\xEF\xBB\xBF" ); // UTF-8 BOM

        switch ( $format ) {
            case 'sage':
                self::export_sage( $output, $records );
                break;
            case 'quickbooks':
                self::export_quickbooks( $output, $records );
                break;
            default:
                self::export_xero( $output, $records );
                break;
        }

        fclose( $output );
        exit;
    }

    /** Combine legacy unallocated bookings and the immutable invoice domain once. */
    private static function normalise_records( $bookings, $date_from, $date_to ) {
        global $wpdb;
        $allocation_table = $wpdb->prefix . MBS_BILLING_ALLOCATION_TABLE;
        $allocated = array_fill_keys( $wpdb->get_col( "SELECT DISTINCT booking_ref FROM {$allocation_table}" ), true );
        $records = array();
        $bank = MBS_Bookings::get_bank_details();
        foreach ( $bookings as $booking ) {
            if ( ! in_array( $booking->status, array( 'confirmed', 'deposit_paid', 'paid', 'archived' ), true ) || isset( $allocated[ $booking->ref ] ) ) continue;
            $records[] = (object) array(
                'contact_name' => $booking->organisation ?: $booking->name, 'email' => $booking->email,
                'invoice_number' => $booking->invoice_number, 'invoice_date' => $booking->created_at,
                'due_date' => wp_date( 'Y-m-d', strtotime( $booking->created_at . ' +' . (int) $bank['payment_days'] . ' days' ) ),
                'total_decimal' => number_format( (float) $booking->amount, 2, '.', '' ),
                'description' => $booking->space . ' hire – ' . wp_date( 'j M Y', strtotime( $booking->booking_date ) ),
                'booking_ref' => $booking->ref, 'purpose' => $booking->purpose,
            );
        }

        $invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
        $item_table = $wpdb->prefix . MBS_INVOICE_ITEM_TABLE;
        $where = array( "i.status NOT IN ('draft','void')" ); $params = array();
        if ( $date_from ) { $where[] = 'it.service_date >= %s'; $params[] = $date_from; }
        if ( $date_to ) { $where[] = 'it.service_date <= %s'; $params[] = $date_to; }
        $sql = "SELECT i.*, it.item_ref, it.booking_ref, it.description, it.line_total_minor, it.service_date
                FROM {$invoice_table} i INNER JOIN {$item_table} it ON it.invoice_id = i.id
                WHERE " . implode( ' AND ', $where ) . ' ORDER BY it.service_date ASC, i.id ASC, it.id ASC';
        $rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );
        foreach ( $rows as $row ) {
            $records[] = (object) array(
                'contact_name' => $row->contact_organisation ?: $row->contact_name, 'email' => $row->contact_email,
                'invoice_number' => $row->invoice_ref, 'invoice_date' => $row->issued_at ?: $row->created_at,
                'due_date' => $row->due_at ?: $row->issued_at, 'total_decimal' => MBS_Money::decimal( (int) $row->line_total_minor ),
                'description' => $row->description, 'booking_ref' => $row->booking_ref ?: $row->item_ref,
                'purpose' => $row->document_type === 'credit_note' ? 'Credit note' : 'Venue hire',
            );
        }
        return $records;
    }

    /**
     * Xero CSV format.
     */
    private static function export_xero( $output, $records ) {

        fputcsv( $output, array(
            '*ContactName', 'EmailAddress', '*InvoiceNumber', '*InvoiceDate',
            '*DueDate', 'Total', 'Description', 'Quantity', 'UnitAmount',
            'AccountCode', '*Currency', 'TaxType',
        ) );

        foreach ( $records as $record ) {
            fputcsv( $output, array(
                $record->contact_name, $record->email, $record->invoice_number,
                date( 'd/m/Y', strtotime( $record->invoice_date ) ), date( 'd/m/Y', strtotime( $record->due_date ) ),
                $record->total_decimal, $record->description,
                1,
                $record->total_decimal,
                '200', // Sales account code
                'GBP',
                'No VAT',
            ) );
        }
    }

    /**
     * Sage CSV format.
     */
    private static function export_sage( $output, $records ) {

        fputcsv( $output, array(
            'Type', 'Account Reference', 'Nominal A/C Ref', 'Date',
            'Reference', 'Details', 'Net Amount', 'Tax Code', 'Tax Amount',
        ) );

        foreach ( $records as $record ) {
            fputcsv( $output, array(
                'SI', // Sales Invoice
                $record->invoice_number,
                '4000', // Sales nominal code
                date( 'd/m/Y', strtotime( $record->invoice_date ) ),
                $record->booking_ref,
                $record->description,
                $record->total_decimal,
                'T0', // Zero-rated (charity)
                '0.00',
            ) );
        }
    }

    /**
     * QuickBooks CSV format.
     */
    private static function export_quickbooks( $output, $records ) {

        fputcsv( $output, array(
            'InvoiceNo', 'Customer', 'InvoiceDate', 'DueDate',
            'Item', 'ItemDescription', 'ItemQuantity', 'ItemRate',
            'ItemAmount', 'Memo',
        ) );

        foreach ( $records as $record ) {
            fputcsv( $output, array(
                $record->invoice_number,
                $record->contact_name,
                date( 'm/d/Y', strtotime( $record->invoice_date ) ),
                date( 'm/d/Y', strtotime( $record->due_date ) ),
                'Venue Hire',
                $record->description,
                1,
                $record->total_decimal,
                $record->total_decimal,
                $record->purpose,
            ) );
        }
    }
}
