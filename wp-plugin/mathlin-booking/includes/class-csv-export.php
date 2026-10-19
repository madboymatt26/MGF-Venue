<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CSV Export for bookings.
 *
 * Generates a downloadable CSV file with booking data.
 * Triggered via admin AJAX action.
 */
class MBS_CSV_Export {
    private static function write_row( $output, $fields ) {
        if ( class_exists( 'MBS_Payment_Features' ) && ! MBS_Payment_Features::enabled( 'reports' ) ) {
            foreach ( array( 6, 18, 19 ) as $index ) unset( $fields[$index] );
            $fields = array_values( $fields );
        }
        fputcsv( $output, $fields, ',', '"', '\\' );
    }

    /**
     * Register the export handler.
     */
    public function init() {
        add_action( 'wp_ajax_mbs_export_csv', array( $this, 'handle_export' ) );
    }

    /**
     * Handle the CSV export request.
     */
    public function handle_export() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $status    = sanitize_text_field( $_GET['status'] ?? '' );
        $date_from = sanitize_text_field( $_GET['date_from'] ?? '' );
        $date_to   = sanitize_text_field( $_GET['date_to'] ?? '' );
        $search    = sanitize_text_field( $_GET['search'] ?? '' );

        $args = array(
            'status'           => $status,
            'date_from'        => $date_from,
            'date_to'          => $date_to,
            'search'           => $search,
            'orderby'          => 'booking_date',
            'order'            => 'ASC',
            'limit'            => 10000,
            'exclude_archived' => false,
        );

        $bookings = MBS_Bookings::get_all( $args );

        // Build filename
        $parts = array( 'bookings' );
        if ( $status ) $parts[] = $status;
        if ( $date_from ) $parts[] = 'from-' . $date_from;
        if ( $date_to ) $parts[] = 'to-' . $date_to;
        $filename = implode( '-', $parts ) . '-' . date( 'Y-m-d' ) . '.csv';

        // Output headers
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );

        // UTF-8 BOM for Excel compatibility
        fwrite( $output, "\xEF\xBB\xBF" );

        // Header row
        self::write_row( $output, array(
            'Reference',
            'Status',
            'Name',
            'Organisation',
            'Email',
            'Phone',
            'Address',
            'Space',
            'Kitchen',
            'Booking Date',
            'End Date',
            'All Day',
            'Start Time',
            'End Time',
            'Attendees',
            'Purpose',
            'Notes',
            'Admin Notes',
            'Amount (£)',
            'Invoice Number',
            'Series ID',
            'Submitted',
        ) );

        // Data rows
        foreach ( $bookings as $b ) {
            self::write_row( $output, array(
                $b->ref,
                ucfirst( $b->status ),
                $b->name,
                $b->organisation,
                $b->email,
                $b->phone,
                str_replace( "\n", ', ', $b->address ),
                $b->space,
                $b->kitchen ? 'Yes' : 'No',
                $b->booking_date,
                $b->booking_date_end ?: $b->booking_date,
                $b->all_day ? 'Yes' : 'No',
                $b->start_time ?: '',
                $b->end_time ?: '',
                $b->attendees,
                $b->purpose,
                $b->notes,
                $b->admin_notes ?? '',
                number_format( $b->amount, 2, '.', '' ),
                $b->invoice_number,
                $b->series_id ?? '',
                $b->created_at,
            ) );
        }

        fclose( $output );
        exit;
    }
}
