<?php
if ( ! defined( 'ABSPATH' ) ) exit;
require_once __DIR__.'/class-export-snapshots.php';

/**
 * CSV Export for bookings.
 *
 * Generates a downloadable CSV file with booking data.
 * Triggered via admin AJAX action.
 */
class MBS_CSV_Export {
    public static function safe_fields( $fields ) {
        return array_map( static function( $value ) {
            // Only text is neutralised: legitimate numeric amount columns stay numeric.
            if ( is_string( $value ) && preg_match( '/^[\x00-\x20]*[=+@-]/u', $value ) && ! preg_match( '/^-?\d+(?:\.\d+)?$/D', $value ) ) return "'" . $value;
            return $value;
        }, $fields );
    }
    private static function write_row( $output, $fields ) {
        if ( class_exists( 'MBS_Payment_Features' ) && ! MBS_Payment_Features::enabled( 'reports' ) ) {
            foreach ( array( 6, 18, 19 ) as $index ) unset( $fields[$index] );
            $fields = array_values( $fields );
        }
        if(fputcsv( $output, self::safe_fields( $fields ), ',', '"', '\\' )===false||ftell($output)>MBS_Export_Snapshots::MAX_BYTES)throw new RuntimeException('CSV exceeds its 5 MiB safety boundary or could not be written. Choose a smaller range.');
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
            'orderby'          => 'id',
            'order'            => 'ASC',
            'limit'            => 500,
            'exclude_archived' => false,
        );

        global $wpdb;
        $output = fopen( 'php://memory', 'w+' );
        if(!$output)wp_die('Export buffer unavailable.','',array('response'=>503));
        if($wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')===false||$wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY')===false){fclose($output);wp_die('Consistent export snapshot unavailable.','',array('response'=>503));}
        try {

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

        // Every page uses the same read-only database snapshot; no partial CSV
        // escapes on read/size failure. OFFSET is stable within this snapshot.
        $count=0;
        for($offset=0;;$offset+=500){
        $args['offset']=$offset;$bookings=MBS_Bookings::get_all($args);
        if(!is_array($bookings)||$wpdb->last_error)throw new RuntimeException('Bookings could not be read completely.');
        foreach ( $bookings as $b ) {
            if(++$count>20000)throw new RuntimeException('Export exceeds 20,000 bookings. Choose a smaller range.');
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
        if(count($bookings)<500)break;
        }
        if($wpdb->query('COMMIT')===false)throw new RuntimeException('Export snapshot could not finish.');
        }catch(Throwable $e){$wpdb->query('ROLLBACK');fclose($output);wp_die(esc_html($e->getMessage()).' No CSV was generated.','',array('response'=>503));}
        rewind($output);$csv=stream_get_contents($output);fclose($output);
        MBS_Accounting_Export::serve_rest_csv($csv,'bookings');
        $job=MBS_Export_Snapshots::save($csv,'bookings',get_current_user_id());
        if(is_wp_error($job))wp_die(esc_html($job->get_error_message()),'',array('response'=>503));
        wp_safe_redirect(MBS_Accounting_Export::download_url($job));exit;
    }
}
