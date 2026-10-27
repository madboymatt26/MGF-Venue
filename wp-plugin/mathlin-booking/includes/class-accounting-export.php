<?php
if ( ! defined( 'ABSPATH' ) ) exit;
require_once __DIR__.'/class-export-snapshots.php';

/**
 * Accounting Export — generates CSV files compatible with Xero, Sage, and QuickBooks.
 *
 * Exports confirmed/paid invoices in a format that can be imported
 * directly into accounting software.
 */
class MBS_Accounting_Export {

    private static function write_csv( $output, $fields ) {
        if(fputcsv( $output, MBS_CSV_Export::safe_fields( $fields ), ',', '"', '\\' )===false)throw new RuntimeException('CSV row could not be written.');
        if(ftell($output)>MBS_Export_Snapshots::MAX_BYTES)throw new LengthException('CSV exceeds the 5 MiB safety boundary.');
    }

    public function init() {
        add_action( 'wp_ajax_mbs_export_accounting', array( $this, 'handle_export' ) );
        add_action( 'wp_ajax_mbs_download_accounting', array( $this, 'download' ) );
        add_action( 'mbs_process_email_queue', array('MBS_Export_Snapshots','cleanup'), 20 );
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
        if ( ! in_array( $format, array( 'xero','sage','quickbooks' ), true ) ) wp_die( 'Unknown CSV layout.', '', array( 'response' => 400 ) );
        foreach ( array( $date_from, $date_to ) as $date ) {
            if ( $date === '' ) continue;
            $parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
            if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date ) wp_die( 'Choose valid export dates.', '', array( 'response' => 400 ) );
        }
        if ( $date_from && $date_to && $date_from > $date_to ) wp_die( 'Export end must not precede its start.', '', array( 'response' => 400 ) );

        global $wpdb;
        try { $records = self::consistent_records($date_from,$date_to); }
        catch ( RuntimeException $e ) { wp_die( esc_html($e->getMessage()).' No CSV was generated.', '', array( 'response' => 503 ) ); }
        $output = fopen( 'php://memory', 'w+' );
        if(!$output)wp_die('Export buffer unavailable.','',array('response'=>503));
        fwrite( $output, "\xEF\xBB\xBF" ); // UTF-8 BOM

        try { switch ( $format ) {
            case 'sage':
                self::export_sage( $output, $records );
                break;
            case 'quickbooks':
                self::export_quickbooks( $output, $records );
                break;
            default:
                self::export_xero( $output, $records );
                break;
        } } catch(Throwable $e){fclose($output);wp_die('CSV could not be completed within its safe size. Choose a smaller range.','',array('response'=>413));}

        if(ftell($output)>MBS_Export_Snapshots::MAX_BYTES){fclose($output);wp_die('Export exceeds 5 MiB. Choose a smaller range.','',array('response'=>413));}
        rewind($output);$csv=stream_get_contents($output);fclose($output);
        self::serve_rest_csv($csv,$format);
        $job=MBS_Export_Snapshots::save($csv,$format,get_current_user_id());
        if(is_wp_error($job))wp_die(esc_html($job->get_error_message()),'',array('response'=>503));
        wp_safe_redirect(self::download_url($job));exit;
    }
    public static function download_url($job){return add_query_arg(array('action'=>'mbs_download_accounting','slot'=>$job['slot'],'job'=>$job['id'],'nonce'=>wp_create_nonce('mbs_export_download_'.$job['id'])),admin_url('admin-ajax.php'));}

    /** Preserve the authenticated MCP CSV contract: API clients must never
     * follow a browser-cookie download redirect or receive an HTML login page.
     * Called only after the existing export capability/nonce/feature checks.
     */
    public static function serve_rest_csv($csv,$format){
        if(!defined('REST_REQUEST')||!REST_REQUEST)return;
        nocache_headers();header('Cache-Control: private, no-store');
        header('Content-Type: text/csv; charset=utf-8');header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="'.($format==='bookings'?'bookings':'invoices-'.$format).'.csv"');
        header('Content-Length: '.strlen($csv));echo $csv;exit;
    }

    /** All pages and ledger joins observe one repeatable-read database snapshot. */
    public static function consistent_records($from,$to){
        global $wpdb;
        if($wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')===false||$wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY')===false)throw new RuntimeException('Consistent export snapshot is unavailable.');
        try{
            $bookings=array();$last=0;$table=$wpdb->prefix.MBS_TABLE;
            do{
                $where='id>%d';$params=array($last);
                if($from!==''){$where.=' AND booking_date>=%s';$params[]=$from;}
                if($to!==''){$where.=' AND booking_date<=%s';$params[]=$to;}
                $page=$wpdb->get_results($wpdb->prepare("SELECT id,ref,status,name,organisation,email,invoice_number,created_at,amount,space,booking_date,purpose FROM {$table} WHERE {$where} ORDER BY id LIMIT 500",$params));
                if(!is_array($page)||$wpdb->last_error)throw new RuntimeException('Booking export read failed.');
                foreach($page as$row){$bookings[]=$row;$last=(int)$row->id;}
                if(count($bookings)>20000)throw new RuntimeException('Export exceeds 20,000 bookings; choose a smaller range.');
            }while(count($page)===500);
            $records=self::normalise_records($bookings,$from,$to);
            if(count($records)>20000)throw new RuntimeException('Export exceeds 20,000 accounting lines; choose a smaller range.');
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('Export snapshot could not finish.');
            return$records;
        }catch(Throwable $e){$wpdb->query('ROLLBACK');throw new RuntimeException($e->getMessage());}
    }
    public function download(){
        if(!current_user_can('manage_options'))wp_die('Forbidden','',array('response'=>403));
        $id=sanitize_text_field(wp_unslash($_GET['job']??''));
        check_ajax_referer('mbs_export_download_'.$id,'nonce');
        $job=MBS_Export_Snapshots::read(get_current_user_id(),$_GET['slot']??'', $id);
        if(is_wp_error($job))wp_die(esc_html($job->get_error_message()),'',array('response'=>404));
        $length=strlen($job['csv']);$range=MBS_Export_Snapshots::byte_range($_SERVER['HTTP_RANGE']??'',$length);
        nocache_headers();header('Cache-Control: private, no-store');header('Accept-Ranges: bytes');header('ETag: "'.$job['sha256'].'"');
        if(is_wp_error($range)){header('Content-Range: bytes */'.$length);status_header(416);exit;}
        list($a,$z,$partial)=$range;
        if($partial){status_header(206);header('Content-Range: bytes '.$a.'-'.$z.'/'.$length);}
        header('Content-Type: text/csv; charset=utf-8');header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="'.($job['format']==='bookings'?'bookings':'invoices-'.$job['format']).'-'.substr($job['created_at'],0,10).'.csv"');
        header('Content-Length: '.($z-$a+1));echo substr($job['csv'],$a,$z-$a+1);exit;
    }

    /** Combine legacy unallocated bookings and the immutable invoice domain once. */
    private static function normalise_records( $bookings, $date_from, $date_to ) {
        global $wpdb;
        $allocation_table = $wpdb->prefix . MBS_BILLING_ALLOCATION_TABLE;
        $booking_table=$wpdb->prefix.MBS_TABLE;$allocation_where=array('1=1');$allocation_params=array();
        if($date_from!==''){$allocation_where[]='b.booking_date>=%s';$allocation_params[]=$date_from;}
        if($date_to!==''){$allocation_where[]='b.booking_date<=%s';$allocation_params[]=$date_to;}
        $allocation_sql="SELECT DISTINCT a.booking_ref FROM {$allocation_table} a INNER JOIN {$booking_table} b ON b.ref=a.booking_ref WHERE ".implode(' AND ',$allocation_where).' LIMIT 20001';
        $allocation_refs = $wpdb->get_col( $allocation_params?$wpdb->prepare($allocation_sql,$allocation_params):$allocation_sql );
        if ( ! is_array( $allocation_refs ) || ! empty( $wpdb->last_error ) ) throw new RuntimeException( 'Allocation read failed.' );
        if(count($allocation_refs)>20000)throw new RuntimeException('Allocation export exceeds its safety boundary.');
        $allocated = array_fill_keys( $allocation_refs, true );
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
                'document_type' => 'invoice', 'currency' => 'GBP',
            );
        }

        $invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
        $item_table = $wpdb->prefix . MBS_INVOICE_ITEM_TABLE;
        $where = array( "i.status NOT IN ('draft','void')" ); $params = array();
	        $date_basis="CASE WHEN i.document_type='credit_note' THEN COALESCE(DATE(i.issued_at),DATE(i.created_at)) ELSE COALESCE(it.service_date,DATE(i.issued_at),DATE(i.created_at)) END";
	        if ( $date_from ) { $where[] = $date_basis.' >= %s'; $params[] = $date_from; }
	        if ( $date_to ) { $where[] = $date_basis.' <= %s'; $params[] = $date_to; }
	        $sql = "SELECT i.*, it.item_ref, it.booking_ref, it.description, it.line_total_minor, it.service_date, {$date_basis} AS accounting_date
	                FROM {$invoice_table} i INNER JOIN {$item_table} it ON it.invoice_id = i.id
                WHERE " . implode( ' AND ', $where ) . ' ORDER BY accounting_date ASC, i.id ASC, it.id ASC LIMIT 20001';
        $rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );
        if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) throw new RuntimeException( 'Ledger read failed.' );
        if(count($rows)>20000)throw new RuntimeException('Ledger export exceeds 20,000 lines; choose a smaller range.');
        foreach ( $rows as $row ) {
            $records[] = (object) array(
                'contact_name' => $row->contact_organisation ?: $row->contact_name, 'email' => $row->contact_email,
                'invoice_number' => $row->invoice_ref, 'invoice_date' => $row->issued_at ?: $row->created_at,
                'due_date' => $row->due_at ?: $row->issued_at, 'total_decimal' => MBS_Money::decimal( (int) $row->line_total_minor ),
                'description' => $row->description, 'booking_ref' => $row->booking_ref ?: $row->item_ref,
                'purpose' => $row->document_type === 'credit_note' ? 'Credit note' : ( $row->billing_mode === 'additional_charge' ? 'Additional charge' : 'Venue hire' ),
                'document_type' => $row->document_type, 'currency' => strtoupper( (string)$row->currency ),
            );
        }
        return $records;
    }

    /**
     * Xero CSV format.
     */
    private static function export_xero( $output, $records ) {

        self::write_csv( $output, array(
            '*ContactName', 'EmailAddress', '*InvoiceNumber', '*InvoiceDate',
            '*DueDate', 'Total', 'Description', 'Quantity', 'UnitAmount',
            'AccountCode', '*Currency', 'TaxType',
        ) );

        foreach ( $records as $record ) {
            // Xero's invoice-shaped CSV represents a credit as a negative line.
            $amount = $record->document_type === 'credit_note'
                ? '-' . ltrim( $record->total_decimal, '-' )
                : ltrim( $record->total_decimal, '-' );
            self::write_csv( $output, array(
                $record->contact_name, $record->email, $record->invoice_number,
                date( 'd/m/Y', strtotime( $record->invoice_date ) ), date( 'd/m/Y', strtotime( $record->due_date ) ),
                $amount, $record->description,
                1,
                $amount,
                '200', // Sales account code
                isset( $record->currency ) && $record->currency ? $record->currency : 'GBP',
                'No VAT',
            ) );
        }
    }

    /**
     * Sage CSV format.
     */
    private static function export_sage( $output, $records ) {

        self::write_csv( $output, array(
            'Type', 'Account Reference', 'Nominal A/C Ref', 'Date',
            'Reference', 'Details', 'Net Amount', 'Tax Code', 'Tax Amount',
        ) );

        foreach ( $records as $record ) {
            $is_credit = $record->document_type === 'credit_note';
            self::write_csv( $output, array(
                $is_credit ? 'SC' : 'SI', // Sales Credit / Sales Invoice
                $record->invoice_number,
                '4000', // Sales nominal code
                date( 'd/m/Y', strtotime( $record->invoice_date ) ),
                $record->booking_ref,
                $record->description,
                ltrim( $record->total_decimal, '-' ),
                'T0', // Zero-rated (charity)
                '0.00',
            ) );
        }
    }

    /**
     * QuickBooks CSV format.
     */
    private static function export_quickbooks( $output, $records ) {

        self::write_csv( $output, array(
            'TransactionType', 'InvoiceNo', 'Customer', 'InvoiceDate', 'DueDate',
            'Item', 'ItemDescription', 'ItemQuantity', 'ItemRate',
            'ItemAmount', 'Memo',
        ) );

        foreach ( $records as $record ) {
            $is_credit = $record->document_type === 'credit_note';
            self::write_csv( $output, array(
                $is_credit ? 'Credit Memo' : 'Invoice',
                $record->invoice_number,
                $record->contact_name,
                date( 'm/d/Y', strtotime( $record->invoice_date ) ),
                date( 'm/d/Y', strtotime( $record->due_date ) ),
                'Venue Hire',
                $record->description,
                1,
                ltrim( $record->total_decimal, '-' ),
                ltrim( $record->total_decimal, '-' ),
                $record->purpose,
            ) );
        }
    }
}
