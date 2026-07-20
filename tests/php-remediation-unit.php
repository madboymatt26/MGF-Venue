<?php
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MBS_SERIES_TABLE', 'mathlin_booking_series' );
define( 'MBS_TABLE', 'mathlin_bookings' );
define( 'MBS_PAYMENT_RESERVATION_TABLE', 'mathlin_payment_reservations' );
define( 'MBS_PAYMENT_TRANSACTION_TABLE', 'mathlin_payment_transactions' );

class WP_Error {
    private $code;
    private $message;
    public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_generate_password( $length = 24 ) { return str_repeat( 'x', $length ); }
function current_time() { return gmdate( 'Y-m-d H:i:s' ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_date( $format, $timestamp = null ) { return date( $format, $timestamp === null ? time() : $timestamp ); }
function wp_timezone() { return new DateTimeZone( 'Europe/London' ); }
function get_current_user_id() { return 1; }
function esc_sql( $value ) { return str_replace( "'", "''", $value ); }
$mbs_test_options = array();
function get_option( $key, $default = false ) { global $mbs_test_options; return isset($mbs_test_options[$key]) ? $mbs_test_options[$key] : $default; }
function add_option( $key, $value ) { global $mbs_test_options; if(isset($mbs_test_options[$key]))return false;$mbs_test_options[$key]=$value;return true; }
function update_option( $key, $value ) { global $mbs_test_options; $mbs_test_options[$key]=$value;return true; }
function delete_option( $key ) { global $mbs_test_options; unset($mbs_test_options[$key]);return true; }
function wp_schedule_single_event() { return true; }

class MBS_Invoice_Payment {
    public static function is_payable( $invoice ) { return in_array( $invoice->status, array( 'issued', 'part_paid', 'overdue' ), true ) && $invoice->balance > 0; }
    public static function send_due_reminders() {}
}
class MBS_Billing_Ledger { public static function balance_minor( $invoice ) { return $invoice->balance; } }

/** Small SQL-state-machine double: it applies the same predicates as MySQL. */
class MBS_Test_Reservation_WPDB {
    public $prefix = 'wp_';
    public $rows = array();
    public $ledger_payments = array();

    public function prepare( $sql ) {
        $args = array_slice( func_get_args(), 1 );
        if ( count( $args ) === 1 && is_array( $args[0] ) ) $args = $args[0];
        foreach ( $args as $arg ) {
            $replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
            $sql = preg_replace( '/%[ds]/', $replacement, $sql, 1 );
        }
        return $sql;
    }

    public function insert( $table, $data ) {
        foreach ( $this->rows as $row ) {
            if ( (int) $row->invoice_id === (int) $data['invoice_id'] || $row->reservation_ref === $data['reservation_ref'] ) return false;
            if ( $data['order_id'] !== null && (int) $row->order_id === (int) $data['order_id'] ) return false;
        }
        $this->rows[ $data['invoice_ref'] ] = (object) $data;
        return 1;
    }

    public function get_row( $sql ) {
        if ( preg_match( "/WHERE invoice_ref='([^']+)'/", $sql, $match ) ) return isset( $this->rows[ $match[1] ] ) ? clone $this->rows[ $match[1] ] : null;
        return null;
    }

    public function get_var( $sql ) {
        if ( strpos( $sql, 'mathlin_payment_transactions' ) !== false && preg_match( "/provider_transaction_id='([^']+)'/", $sql, $match ) ) {
            return isset( $this->ledger_payments[$match[1]] ) ? $this->ledger_payments[$match[1]] : null;
        }
        return null;
    }

    public function query( $sql ) {
        if ( strpos( $sql, 'SET reservation_ref=' ) !== false ) {
            preg_match( "/SET reservation_ref='([^']+)'/", $sql, $new_ref_match );
            preg_match( '/WHERE invoice_id=(\d+)/', $sql, $invoice_id_match );
            preg_match_all( "/reservation_ref='([^']+)'/", $sql, $ref_matches );
            preg_match( '/AND version=(\d+)/', $sql, $version_match );
            foreach ( $this->rows as $row ) {
                if ( (int) $row->invoice_id !== (int) $invoice_id_match[1] ) continue;
                $expected_ref = end( $ref_matches[1] );
                if ( $row->reservation_ref !== $expected_ref || (int) $row->version !== (int) $version_match[1] || $row->order_id !== null ) return 0;
                if ( $row->status !== 'released' && !( $row->status === 'active' && strtotime( $row->expires_at ) <= time() ) ) return 0;
                $row->reservation_ref = $new_ref_match[1]; $row->status = 'active'; $row->version++; $row->expires_at = gmdate( 'Y-m-d H:i:s', time() + 1200 );
                return 1;
            }
            return 0;
        }
        if ( ! preg_match( "/WHERE invoice_ref='([^']+)'/", $sql, $invoice_match ) ) return 0;
        $invoice_ref = $invoice_match[1];
        if ( ! isset( $this->rows[ $invoice_ref ] ) ) return 0;
        $row = $this->rows[ $invoice_ref ];
        preg_match( "/reservation_ref='([^']+)'[^W]*WHERE|WHERE.*reservation_ref='([^']+)'/", $sql, $unused );
        preg_match_all( "/reservation_ref='([^']+)'/", $sql, $reservation_matches );
        $expected_ref = end( $reservation_matches[1] );
        if ( $row->reservation_ref !== $expected_ref ) return 0;

        if ( strpos( $sql, 'SET expires_at=' ) !== false ) {
            preg_match( '/AND version=(\d+)/', $sql, $version_match );
            if ( $row->status !== 'active' || $row->order_id !== null || (int)$row->version !== (int)$version_match[1] || strtotime($row->expires_at) <= time() ) return 0;
            $row->expires_at = gmdate( 'Y-m-d H:i:s', time() + 1200 ); $row->version++;
            return 1;
        }

        if ( strpos( $sql, "SET order_id=" ) !== false ) {
            preg_match( '/SET order_id=(\d+)/', $sql, $order_match );
            $order_id = (int) $order_match[1];
            if ( $row->status !== 'active' || $row->order_id !== null || strtotime( $row->expires_at ) <= time() ) return 0;
            foreach ( $this->rows as $other ) if ( $other !== $row && (int) $other->order_id === $order_id ) return false;
            $row->order_id = $order_id; $row->status = 'bound'; $row->version++; $row->expires_at = null;
            return 1;
        }

        if ( strpos( $sql, "status='released'" ) !== false ) {
            if ( ! in_array( $row->status, array( 'active', 'bound' ), true ) ) return 0;
            if ( preg_match( '/AND version=(\d+)/', $sql, $version_match ) && (int) $row->version !== (int) $version_match[1] ) return 0;
            if ( preg_match( '/AND order_id=(\d+)/', $sql, $order_match ) && (int) $row->order_id !== (int) $order_match[1] ) return 0;
            if ( strpos( $sql, 'order_id IS NULL' ) !== false && $row->order_id !== null ) return 0;
            $row->status = 'released'; $row->version++;
            return 1;
        }

        if ( preg_match( "/SET status='([^']+)'/", $sql, $status_match ) ) {
            preg_match( '/AND order_id=(\d+)/', $sql, $order_match );
            preg_match( '/AND version=(\d+)/', $sql, $version_match );
            if ( (int) $row->order_id !== (int) $order_match[1] || (int) $row->version !== (int) $version_match[1] ) return 0;
            preg_match( '/status IN \(([^)]+)\)/', $sql, $states_match );
            $states = array_map( function ( $state ) { return trim( $state, " '\"" ); }, explode( ',', $states_match[1] ) );
            if ( ! in_array( $row->status, $states, true ) ) return 0;
            $row->status = $status_match[1]; $row->version++;
            return 1;
        }
        return 0;
    }
}

$wpdb = new MBS_Test_Reservation_WPDB();
$plugin_root = getenv( 'MBS_TEST_PLUGIN_ROOT' );
if ( ! $plugin_root ) $plugin_root = dirname( __DIR__ ) . '/wp-plugin/mathlin-booking';
require_once $plugin_root . '/includes/class-invoice-reservation.php';

$invoice = (object) array( 'id' => 7, 'invoice_ref' => 'INV-7', 'status' => 'overdue', 'balance' => 1200 );
$first = MBS_Invoice_Reservation::acquire( $invoice );
$second = MBS_Invoice_Reservation::acquire( $invoice );
$same = MBS_Invoice_Reservation::acquire( $invoice, $first['reservation_ref'] );
$bound = MBS_Invoice_Reservation::bind_order( 'INV-7', $first['reservation_ref'], 41 );
$repeat = MBS_Invoice_Reservation::bind_order( 'INV-7', $first['reservation_ref'], 41 );
$conflict = MBS_Invoice_Reservation::bind_order( 'INV-7', $first['reservation_ref'], 42 );
if ( ! isset( $wpdb->rows['INV-7'] ) ) { fwrite( STDERR, "FAIL: checkout ownership is not persisted in the SQL reservation state machine.\n" ); exit( 1 ); }
$wpdb->rows['INV-7']->expires_at = '2000-01-01 00:00:00';
$slow_replacement = MBS_Invoice_Reservation::acquire( $invoice );

$checks = array(
    is_array( $first ),
    is_wp_error( $second ) && $second->get_error_code() === 'invoice_payment_reserved',
    $same['reservation_ref'] === $first['reservation_ref'],
    $bound['order_id'] === 41,
    $repeat['order_id'] === 41,
    is_wp_error( $conflict ),
    MBS_Invoice_Reservation::validate( 'INV-7', $first['reservation_ref'], 1200, 41 ),
    is_wp_error( $slow_replacement ),
);
foreach ( $checks as $ok ) if ( ! $ok ) { fwrite( STDERR, "FAIL: durable reservation contention or ownership assertion.\n" ); exit( 1 ); }

$reconcile = MBS_Invoice_Reservation::reconciliation_required( 'INV-7', $first['reservation_ref'], 41, 'injected ledger failure' );
$visible = MBS_Invoice_Reservation::get( 'INV-7' );
if ( ! $reconcile || $visible->status !== 'reconciliation_required' || MBS_Invoice_Reservation::release( 'INV-7', $first['reservation_ref'], 'unsafe', 41 ) ) {
    fwrite( STDERR, "FAIL: captured-payment failure was not retained for reconciliation.\n" ); exit( 1 );
}
$wpdb->ledger_payments['41'] = 501;
if ( ! MBS_Invoice_Reservation::resolve( 'INV-7', $first['reservation_ref'], 41, 'ledger_recorded' ) || MBS_Invoice_Reservation::get( 'INV-7' )->status !== 'captured' ) {
    fwrite( STDERR, "FAIL: administrator reconciliation did not reach a terminal captured state.\n" ); exit( 1 );
}

$expiring = (object) array( 'id' => 8, 'invoice_ref' => 'INV-8', 'status' => 'issued', 'balance' => 500 );
$old = MBS_Invoice_Reservation::acquire( $expiring );
$wpdb->rows['INV-8']->expires_at = '2000-01-01 00:00:00';
$replacement = MBS_Invoice_Reservation::acquire( $expiring );
if ( ! is_array( $replacement ) || $replacement['reservation_ref'] === $old['reservation_ref'] || MBS_Invoice_Reservation::release( 'INV-8', $old['reservation_ref'], 'stale' ) ) {
    fwrite( STDERR, "FAIL: expired replacement was not compare-and-swap safe.\n" ); exit( 1 );
}

class MBS_Series {
    public static $rows = array();
    public static function get( $ref ) { return isset( self::$rows[$ref] ) ? self::$rows[$ref] : null; }
}
class MBS_Money {}
class MBS_Bookings {}
class MBS_Test_Billing_WPDB {
    public $prefix = 'wp_'; public $page_calls = 0; public $seen = array();
    public function prepare( $sql ) { $args=array_slice(func_get_args(),1); foreach($args as $arg)$sql=preg_replace('/%d/',(string)$arg,$sql,1); return $sql; }
    public function get_results( $sql ) {
        if ( strpos( $sql, 'mathlin_booking_series' ) !== false ) { preg_match('/id > (\d+)/',$sql,$match);$after=(int)(isset($match[1])?$match[1]:0);$this->page_calls++;$rows=array();for($id=$after+1;$id<=min(101,$after+100);$id++){ $ref='SER-'.$id;$row=(object)array('id'=>$id,'series_ref'=>$ref,'status'=>'confirmed','billing_treatment'=>'invoice_managed','billing_mode'=>'monthly','metadata_incomplete'=>0);MBS_Series::$rows[$ref]=$row;$this->seen[]=$id;$rows[]=$row;}return $rows; }
        return array();
    }
    public function get_col( $sql ) { return array(); }
}
$wpdb = new MBS_Test_Billing_WPDB();
require_once $plugin_root . '/includes/class-billing-engine.php';
$result = MBS_Billing_Engine::catch_up( '2026-07-19' );
if ( is_wp_error($result) || $wpdb->page_calls !== 2 || count(array_unique($wpdb->seen)) !== 101 ) { fwrite(STDERR,"FAIL: catch-up did not paginate through 101 eligible series.\n");exit(1); }

echo 'OK: 18 remediation assertions passed (SQL reservation ownership/CAS/reconciliation, 101-series catch-up; PHP 7.4 syntax).' . PHP_EOL;
