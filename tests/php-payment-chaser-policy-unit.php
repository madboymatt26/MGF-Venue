<?php
/**
 * Payment chaser vs payment-expiry policy consistency (dependency-free).
 *
 * The auto-chaser must never chase the deposit while an enforced policy
 * owns the deposit deadline: the hirer has an agreed 48 hours from
 * approval, is warned 24 hours before the deadline and the booking cancels
 * automatically at it. Chasing inside that window contradicts the agreed
 * deadline and can offer a checkout the policy has already closed.
 * Balance-phase chasing after a paid deposit must continue.
 *
 * Run: php tests/php-payment-chaser-policy-unit.php
 */

define( 'ABSPATH', __DIR__ );
define( 'MBS_TABLE', 'bookings' );

function get_option( $key, $default = false ) { return $default; }
function wp_date( $format, $time = null ) { return date( $format, $time ?: time() ); }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
class WP_Error {
    private $code, $message;
    public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

class MBS_Payment_Features {
    public static function enabled( $feature = null ) { return true; }
}
class MBS_Series {
    public static function billing_treatment_for_booking( $b ) { return 'one_off'; }
}
class MBS_Credit_Accounts {
    public static function for_booking( $b ) { return false; }
}
class MBS_Bookings {
    public static function get_bank_details() { return array( 'payment_days' => 14 ); }
    public static function get_deposit_settings() { return array( 'enabled' => true, 'percentage' => 25, 'balance_days' => 7 ); }
}
class MBS_Email_Templates {
    public static function get_chase_settings() { return array( 'max_chases' => 3, 'chase_interval' => 3 ); }
}

// Scriptable wpdb: MBS_Payment_Expiry::get() reads one row.
class Policy_DB {
    public $prefix = 'wp_';
    public $row = null;
    public function prepare( $sql, ...$args ) { return $sql; }
    public function get_row( $sql ) { return $this->row; }
}
$wpdb = new Policy_DB();
$GLOBALS['wpdb'] = $wpdb;

require dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-money.php';
require dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-payment-expiry-policy.php';
require dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-payment-expiry.php';
require dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-payment-chaser.php';

$checks = 0;
function check_chaser( $ok, $message ) {
    global $checks;
    $checks++;
    if ( ! $ok ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); }
}

$tz = new DateTimeZone( 'Europe/London' );
$approved = ( new DateTimeImmutable( 'now', $tz ) )->getTimestamp();
$policy = MBS_Payment_Expiry_Policy::approve( $approved, wp_date( 'Y-m-d', $approved + 30 * 86400 ), 3000, 750, true );
check_chaser( ! is_wp_error( $policy ), 'Policy enrols for the chaser scenario.' );

$booking = (object) array(
    'ref'         => 'MBS-CHASE1',
    'status'      => 'confirmed',
    'amount'      => '30.00',
    'amount_paid' => '0.00',
    'scout_use'   => 0,
    // Submitted 30 days before approval: the legacy created_at heuristic
    // would call this overdue the moment it is approved.
    'created_at'  => wp_date( 'Y-m-d H:i:s', $approved - 30 * 86400 ),
);

$wpdb->row = (object) array( 'state' => 'active', 'policy_json' => wp_json_encode( $policy ) );

check_chaser( MBS_Payment_Chaser::should_chase_occurrence( $booking ) === false, 'Deposit phase is never chased while the policy owns the deadline.' );
check_chaser( MBS_Payment_Chaser::send_chase( $booking ) === false, 'A chase send is refused outright during the agreed window.' );
check_chaser( strpos( MBS_Payment_Chaser::chase_suppression_message( $booking ), 'automatic payment policy' ) !== false, 'Suppression explains that the policy owns the deposit deadline.' );

// Even after the nominal deadline (before the worker tick cancels), the
// deposit phase stays unchaseable: the policy cancels, staff do not chase.
$stale = clone $booking;
$stale->created_at = wp_date( 'Y-m-d H:i:s', $approved - 60 * 86400 );
check_chaser( MBS_Payment_Chaser::should_chase_occurrence( $stale ) === false, 'Stale submission date cannot trigger a deposit chase.' );

// Partial payment below the deposit is still the deposit phase.
$partial = clone $booking;
$partial->amount_paid = '5.00';
check_chaser( MBS_Payment_Chaser::should_chase_occurrence( $partial ) === false, 'Partial payment below the deposit stays policy-controlled.' );

// Once the deposit is covered, balance chasing continues per the terms.
$paid = clone $booking;
$paid->status = 'deposit_paid';
$paid->amount_paid = '7.50';
check_chaser( MBS_Payment_Chaser::should_chase_occurrence( $paid ) === true, 'Balance chasing continues once the deposit is paid.' );
check_chaser( MBS_Payment_Chaser::chase_suppression_message( $paid ) === '', 'No suppression reason in the balance phase.' );

// Exempted policy: no enforcement, ordinary chasing resumes.
$exempt = $policy;
$exempt['exempt'] = true;
$wpdb->row = (object) array( 'state' => 'active', 'policy_json' => wp_json_encode( $exempt ) );
check_chaser( MBS_Payment_Chaser::should_chase_occurrence( $booking ) === true, 'Authorised exemption returns the booking to ordinary chasing.' );

// Manual short-notice review row: no automatic deadline, ordinary chasing.
$wpdb->row = (object) array( 'state' => 'manual', 'policy_json' => wp_json_encode( array( 'manual_approval' => true, 'approved_at' => $approved ) ) );
check_chaser( MBS_Payment_Chaser::should_chase_occurrence( $booking ) === true, 'Manual-review bookings remain chaseable.' );

// No policy row at all: ordinary chasing.
$wpdb->row = null;
check_chaser( MBS_Payment_Chaser::should_chase_occurrence( $booking ) === true, 'Bookings without a policy chase as before.' );

echo "OK: $checks payment-chaser/policy consistency assertions passed; no email generated.\n";
