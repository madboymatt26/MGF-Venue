<?php
// Dependency-free behavioural tests. No production data, email or WordPress needed.
define( 'ABSPATH', __DIR__ );
define( 'MBS_TABLE', 'bookings' );
define( 'MBS_SERIES_TABLE', 'series' );
class WP_Error {
    public $code;
    public $message;
    function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
    function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return sanitize_text_field( $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_date( $format ) { return '2026-10-12'; }
function current_time( $format ) { return '2026-10-12 12:00:00'; }
function get_current_user_id() { return 5; }
function is_user_logged_in() { return $GLOBALS['signed_in']; }
function wp_get_current_user() { return (object) array( 'ID'=>5, 'user_email' => $GLOBALS['email'] ); }
function get_user_meta($id,$key,$single=true){return ($GLOBALS['verified']??true)?hash('sha256',strtolower($GLOBALS['email'])):'';}
function get_option($key,$default=false){return $default;}
require __DIR__ . '/../wp-plugin/mathlin-booking/includes/class-hirer-identity.php';
require __DIR__ . '/../wp-plugin/mathlin-booking/includes/class-campsite-policy.php';
function admin_url( $path ) { return 'https://example.invalid/' . $path; }
class MBS_Series {
    static $series;
    static function get( $ref ) { return $ref === 'SER-TEST' ? self::$series : null; }
}
class MBS_Bookings {
    static $bookings;
    static function get( $ref ) { return self::$bookings[ $ref ] ?? null; }
    static function get_admin_email() { return 'venue@example.invalid'; }
}
class MBS_Audit_Log { static $events = array(); static function log( ...$args ) { self::$events[] = $args; } }
class MBS_Email_Queue { static $messages = array(); static function send( ...$args ) { self::$messages[] = $args; return true; } }
class MBS_Modification {
    static $requests = array();
    static function create_request( $data ) {
        global $wpdb;
        if ( $wpdb->fail_insert ) return false;
        $id = ++$wpdb->insert_id;
        self::$requests[ $id ] = (object) array( 'id' => $id, 'booking_ref' => $data['ref'],
            'request_type' => $data['type'], 'requested_data' => json_encode( $data['changes'] ), 'notes' => $data['notes'], 'status' => 'pending' );
        return 1;
    }
    static function get_request( $id ) { return self::$requests[ $id ] ?? null; }
}
class Fake_Request_DB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 0;
    public $fail_insert = false;
    public $fail_read = false;
    public $race = false;
    public $queries = array();
    function prepare( $sql, ...$args ) { return $sql; }
    function query( $sql ) { $this->queries[] = $sql; return 1; }
    function get_row( $sql ) { return MBS_Series::$series; }
    function get_results( $sql ) {
        if ( $this->fail_read ) { $this->last_error = 'Read failed'; return null; }
        if ( strpos( $sql, 'mathlin_mod_requests' ) !== false ) return array_values( array_filter( MBS_Modification::$requests, function( $r ) { return $r->status === 'pending'; } ) );
        return array_values( MBS_Bookings::$bookings );
    }
    function update( $table, $update, $where ) {
        if ( $this->race ) return 0;
        $request = MBS_Modification::get_request( $where['id'] );
        foreach ( $update as $key => $value ) $request->$key = $value;
        return 1;
    }
}
require __DIR__ . '/../wp-plugin/mathlin-booking/includes/class-series-request.php';
$checks = 0;
function check( $condition, $message ) {
    global $checks;
    if ( ! $condition ) throw new RuntimeException( $message );
    ++$checks;
}
function fixture() {
    $GLOBALS['signed_in'] = true;
    $GLOBALS['email'] = 'Owner@example.invalid';
    $GLOBALS['wpdb'] = new Fake_Request_DB();
    MBS_Modification::$requests = array();
    MBS_Email_Queue::$messages = array();
    MBS_Series::$series = (object) array( 'series_ref' => 'SER-TEST', 'contact_email' => 'owner@example.invalid', 'scout_use' => 0, 'version' => 2 );
    MBS_Bookings::$bookings = array();
    foreach ( array( 'OLD' => '2026-10-11', 'TODAY' => '2026-10-12', 'A' => '2026-10-20', 'B' => '2026-10-27', 'C' => '2026-11-03', 'X' => '2026-11-10' ) as $ref => $date ) {
        MBS_Bookings::$bookings[ $ref ] = (object) array( 'ref' => $ref, 'series_id' => 'SER-TEST', 'booking_date' => $date, 'status' => $ref === 'X' ? 'cancelled' : 'paid', 'amount' => '30.00' );
    }
}
fixture();
$all = array_values( MBS_Bookings::$bookings );
$select = function( $scope, $refs = array(), $date = '' ) use ( $all ) { return MBS_Series_Request::select_targets( $all, $scope, $refs, $date, '2026-10-12' ); };
check( $select( 'one', array( 'A' ) ) === array( 'A' => '2026-10-20' ), 'single session' );
check( count( $select( 'selected', array( 'A', 'C' ) ) ) === 2, 'selected sessions' );
check( $select( 'from_date', array(), '2026-10-27' ) === array( 'B' => '2026-10-27', 'C' => '2026-11-03' ), 'inclusive cutoff, excludes cancelled' );
foreach ( array( array( 'one', array( 'A','B' ), '' ), array( 'one', array( 'OTHER' ), '' ), array( 'selected', array(), '' ), array( 'one', array( 'TODAY' ), '' ), array( 'one', array( 'OLD' ), '' ), array( 'one', array( 'X' ), '' ), array( 'all', array(), '' ), array( 'from_date', array(), '2026-02-30' ), array( 'from_date', array(), '2026-10-12' ), array( 'from_date', array(), '2027-01-01' ), array( 'selected', array( array() ), '' ) ) as $args ) {
    check( is_wp_error( $select( ...$args ) ), 'invalid scope/date/ref rejected' );
}
check( MBS_Series_Request::owned_series( 'SER-TEST' ) !== false, 'case-insensitive owner email' );
$GLOBALS['verified']=false;check(!MBS_Series_Request::owned_series('SER-TEST'),'unverified matching email denied');$GLOBALS['verified']=true;
$GLOBALS['email'] = 'other@example.invalid';
check( ! MBS_Series_Request::owned_series( 'SER-TEST' ), 'other account blocked' );
$GLOBALS['signed_in'] = false;
check( ! MBS_Series_Request::owned_series( 'SER-TEST' ), 'anonymous blocked' );
fixture();
MBS_Series::$series->scout_use = 1;
check( ! MBS_Series_Request::owned_series( 'SER-TEST' ), 'scout series excluded' );
fixture();
$input = array( 'series_ref' => 'SER-TEST', 'kind' => 'cancel', 'scope' => 'selected', 'refs' => array( 'A', 'C' ), 'notes' => 'Please cancel these two sessions.' );
$before = serialize( MBS_Bookings::$bookings );
$id = MBS_Series_Request::submit( $input );
check( $id === 1, 'request persisted' );
check( serialize( MBS_Bookings::$bookings ) === $before, 'submission does not mutate bookings or money' );
check( count( MBS_Email_Queue::$messages ) === 1, 'one admin notification' );
$request = MBS_Modification::get_request( $id );
$data = json_decode( $request->requested_data, true );
check( $data['targets'] === array( 'A' => '2026-10-20', 'C' => '2026-11-03' ) && $data['series_version'] === 2, 'exact targets and version frozen' );
check( is_wp_error( MBS_Series_Request::submit( $input ) ), 'duplicate blocked under series lock' );
check( count( MBS_Email_Queue::$messages ) === 1, 'duplicate does not email' );
check( is_wp_error( MBS_Series_Request::resolve( $id, 'Cancelled as requested.' ) ), 'cannot record cancellation before applying it' );
MBS_Bookings::$bookings['A']->status = 'cancelled';
MBS_Bookings::$bookings['C']->status = 'cancelled';
check( MBS_Series_Request::resolve( $id, 'Cancelled as requested. Credits issued; no cash refund.' ) === true, 'staff records applied outcome' );
check( $request->status === 'handled', 'not falsely labelled automatic approval' );
check( count( MBS_Email_Queue::$messages ) === 2, 'hirer outcome notification' );
check( is_wp_error( MBS_Series_Request::resolve( $id, 'Duplicate outcome.' ) ), 'double resolution blocked' );
check( count( MBS_Email_Queue::$messages ) === 2, 'double resolution no duplicate email' );
fixture();
$GLOBALS['email'] = 'other@example.invalid';
check( is_wp_error( MBS_Series_Request::submit( $input ) ) && ! MBS_Modification::$requests, 'forged series submission blocked' );
check( end( $wpdb->queries ) === 'ROLLBACK', 'ownership failure rolled back' );
fixture();
$wpdb->fail_read = true;
check( is_wp_error( MBS_Series_Request::submit( $input ) ) && ! MBS_Modification::$requests, 'read failure saves nothing' );
fixture();
$wpdb->fail_insert = true;
check( is_wp_error( MBS_Series_Request::submit( $input ) ) && ! MBS_Email_Queue::$messages, 'insert failure no notifications' );
check( end( $wpdb->queries ) === 'ROLLBACK', 'insert failure rolls back' );
fixture();
$id = MBS_Series_Request::submit( $input );
$wpdb->race = true;
check( is_wp_error( MBS_Series_Request::resolve( $id, 'Unable to accept.', 'rejected' ) ), 'concurrent outcome fails closed' );
check( count( MBS_Email_Queue::$messages ) === 1, 'no outcome email on failed conditional update' );
fixture();
$id = MBS_Series_Request::submit( $input );
check( MBS_Series_Request::resolve( $id, 'Unable to accept.', 'rejected' ) === true, 'rejection without booking changes' );
check( MBS_Bookings::$bookings['A']->status === 'paid', 'rejection retains booking' );
$source = file_get_contents( __DIR__ . '/../wp-plugin/mathlin-booking/includes/class-series-request.php' );
check( strpos( $source, 'wp_ajax_nopriv_mbs_series_request' ) === false, 'no anonymous endpoint' );
check( strpos( $source, "check_ajax_referer( 'mbs_public_nonce'" ) !== false && strpos( $source, "check_ajax_referer( 'mbs_admin_nonce'" ) !== false, 'CSRF checks on both endpoints' );
check( strpos( $source, "current_user_can( 'mbs_manage_bookings' )" ) !== false, 'staff capability required' );
echo "Series requests: {$checks} checks passed.\n";
