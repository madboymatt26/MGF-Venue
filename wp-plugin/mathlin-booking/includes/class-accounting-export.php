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

        // Filter to only invoiceable statuses
        $bookings = array_filter( $bookings, function( $b ) {
            return in_array( $b->status, array( 'confirmed', 'deposit_paid', 'paid', 'archived' ) );
        } );

        $filename = 'invoices-' . $format . '-' . date( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );

        $output = fopen( 'php://output', 'w' );
        fwrite( $output, "\xEF\xBB\xBF" ); // UTF-8 BOM

        switch ( $format ) {
            case 'sage':
                self::export_sage( $output, $bookings );
                break;
            case 'quickbooks':
                self::export_quickbooks( $output, $bookings );
                break;
            default:
                self::export_xero( $output, $bookings );
                break;
        }

        fclose( $output );
        exit;
    }

    /**
     * Xero CSV format.
     */
    private static function export_xero( $output, $bookings ) {
        $bank = MBS_Bookings::get_bank_details();

        fputcsv( $output, array(
            '*ContactName', 'EmailAddress', '*InvoiceNumber', '*InvoiceDate',
            '*DueDate', 'Total', 'Description', 'Quantity', 'UnitAmount',
            'AccountCode', '*Currency', 'TaxType',
        ) );

        foreach ( $bookings as $b ) {
            $due_date = date( 'd/m/Y', strtotime( $b->created_at . ' +' . $bank['payment_days'] . ' days' ) );
            $desc     = $b->space . ' hire – ' . date( 'j M Y', strtotime( $b->booking_date ) );

            fputcsv( $output, array(
                $b->organisation ?: $b->name,
                $b->email,
                $b->invoice_number,
                date( 'd/m/Y', strtotime( $b->created_at ) ),
                $due_date,
                number_format( $b->amount, 2, '.', '' ),
                $desc,
                1,
                number_format( $b->amount, 2, '.', '' ),
                '200', // Sales account code
                'GBP',
                'No VAT',
            ) );
        }
    }

    /**
     * Sage CSV format.
     */
    private static function export_sage( $output, $bookings ) {
        $bank = MBS_Bookings::get_bank_details();

        fputcsv( $output, array(
            'Type', 'Account Reference', 'Nominal A/C Ref', 'Date',
            'Reference', 'Details', 'Net Amount', 'Tax Code', 'Tax Amount',
        ) );

        foreach ( $bookings as $b ) {
            fputcsv( $output, array(
                'SI', // Sales Invoice
                $b->invoice_number,
                '4000', // Sales nominal code
                date( 'd/m/Y', strtotime( $b->created_at ) ),
                $b->ref,
                $b->space . ' hire – ' . $b->name,
                number_format( $b->amount, 2, '.', '' ),
                'T0', // Zero-rated (charity)
                '0.00',
            ) );
        }
    }

    /**
     * QuickBooks CSV format.
     */
    private static function export_quickbooks( $output, $bookings ) {
        $bank = MBS_Bookings::get_bank_details();

        fputcsv( $output, array(
            'InvoiceNo', 'Customer', 'InvoiceDate', 'DueDate',
            'Item', 'ItemDescription', 'ItemQuantity', 'ItemRate',
            'ItemAmount', 'Memo',
        ) );

        foreach ( $bookings as $b ) {
            $due_date = date( 'm/d/Y', strtotime( $b->created_at . ' +' . $bank['payment_days'] . ' days' ) );

            fputcsv( $output, array(
                $b->invoice_number,
                $b->organisation ?: $b->name,
                date( 'm/d/Y', strtotime( $b->created_at ) ),
                $due_date,
                'Venue Hire',
                $b->space . ' – ' . date( 'M j, Y', strtotime( $b->booking_date ) ),
                1,
                number_format( $b->amount, 2, '.', '' ),
                number_format( $b->amount, 2, '.', '' ),
                $b->purpose,
            ) );
        }
    }
}
