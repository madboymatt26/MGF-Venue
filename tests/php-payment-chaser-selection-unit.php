<?php
define( 'ABSPATH', __DIR__ );
define( 'MBS_TABLE', 'bookings' );
function get_option( $key, $default = false ) { return $default; }
function wp_date( $format, $time ) { return date( $format, $time ); }
class MBS_Bookings {
    public static function get_bank_details() { return array( 'payment_days' => 14 ); }
    public static function get_deposit_settings() { return array( 'enabled' => true, 'balance_days' => 7 ); }
}
class MBS_Email_Templates {
    public static function get_chase_settings() { return array( 'max_chases' => 3, 'chase_interval' => 3 ); }
}
class MBS_Series {
    public static function billing_treatment_for_booking( $b ) { return $b->treatment; }
}
class Selection_DB {
    public $prefix = 'wp_';
    public $queries = array();
    public $rows = array();
    public $offsets = array();
    public function prepare( $sql, ...$args ) {
        if ( count( $args ) === 1 && is_array( $args[0] ) ) $args = $args[0];
        return array( $sql, $args );
    }
    public function get_results( $prepared ) {
        list( $sql, $args ) = $prepared;
        $this->queries[] = $prepared;
        $offset = end( $args );
        $this->offsets[] = $offset;
        // Model the monetary/internal-use SQL predicates, including NULL paid.
        $rows = array_values( array_filter( $this->rows, function( $b ) {
            return ! $b->scout_use && $b->amount - ( $b->amount_paid ?? 0 ) > 0.01;
        } ) );
        return array_slice( $rows, $offset, 100 );
    }
}
require dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-payment-chaser.php';
$checks = 0;
function check_selection( $ok, $message ) {
    global $checks;
    $checks++;
    if ( ! $ok ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); }
}
$wpdb = new Selection_DB();
// Capture both real query paths without invoking mail or payment-link code.
( new MBS_Payment_Chaser() )->auto_chase();
check_selection( count( $wpdb->queries ) === 2, 'Confirmed and deposit paths are selected.' );
$queries = $wpdb->queries;
$method = new ReflectionMethod( MBS_Payment_Chaser::class, 'eligible_batch' );
if ( PHP_VERSION_ID < 80100 ) $method->setAccessible( true );
foreach ( $queries as $index => $prepared ) {
    list( $sql, $args ) = $prepared;
    array_pop( $args );
    check_selection( strpos( $sql, 'amount - COALESCE(amount_paid, 0) > 0.01' ) !== false, 'Debt predicate precedes LIMIT.' );
    check_selection( strpos( $sql, 'COALESCE(scout_use, 0) = 0' ) !== false, 'Scout bookings excluded in SQL.' );
    check_selection( strpos( $sql, ', ref ASC' ) !== false, 'Ties have deterministic ordering.' );
    check_selection( strpos( $sql, 'last_chased <= %s' ) !== false, 'Chase interval preserved.' );
    check_selection( strpos( $sql, $index ? 'chase_count < 2' : 'chase_count < %d' ) !== false, 'Existing chase caps preserved.' );
    $wpdb->rows = array();
    for ( $n = 0; $n < 150; $n++ ) $wpdb->rows[] = (object) array( 'ref' => "FREE$n", 'scout_use' => true, 'amount' => 0, 'amount_paid' => 0, 'treatment' => 'none' );
    for ( $n = 0; $n < 200; $n++ ) $wpdb->rows[] = (object) array( 'ref' => "SERIES$n", 'scout_use' => false, 'amount' => 30, 'amount_paid' => 0, 'treatment' => 'invoice_managed' );
    $wpdb->rows[] = (object) array( 'ref' => 'SETTLED', 'scout_use' => false, 'amount' => 30, 'amount_paid' => 30, 'treatment' => 'one_off' );
    for ( $n = 0; $n < 30; $n++ ) $wpdb->rows[] = (object) array( 'ref' => "NICK$n", 'scout_use' => false, 'amount' => 30, 'amount_paid' => null, 'treatment' => $n % 2 ? 'legacy_per_occurrence' : 'one_off' );
    $wpdb->offsets = array();
    $limit = $index ? 10 : 20;
    $selected = $method->invoke( null, $sql, $args, $limit );
    check_selection( count( $selected ) === $limit, 'Only eligible bookings consume the batch cap.' );
    check_selection( $selected[0]->ref === 'NICK0', 'Free and consolidated backlog cannot starve Nick.' );
    check_selection( $wpdb->offsets === array( 0, 100, 200 ), 'Full ineligible pages are traversed.' );
    check_selection( $selected[$limit - 1]->ref === 'NICK' . ( $limit - 1 ), 'One-off and orphan legacy bookings preserved.' );
    $wpdb->rows = array_slice( $wpdb->rows, 0, 350 );
    $selected = $method->invoke( null, $sql, $args, $limit );
    check_selection( $selected === array(), 'An entirely ineligible backlog stops without sending.' );
}
echo "OK: $checks payment-chaser selection assertions passed; no email generated.\n";
