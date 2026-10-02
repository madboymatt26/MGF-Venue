<?php
// Dependency-free unit checks for the calendar recurrence domain.
define( 'ABSPATH', __DIR__ );

class WP_Error {
    private $code;
    private $message;
    public function __construct( $code, $message ) {
        $this->code = $code;
        $this->message = $message;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_timezone() { return new DateTimeZone( 'Europe/London' ); }
function absint( $value ) { return abs( (int) $value ); }

require_once dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-recurrence.php';

$tests = 0;
function assert_same( $expected, $actual, $message ) {
    global $tests;
    $tests++;
    if ( $expected !== $actual ) {
        fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
        exit( 1 );
    }
}

$dates = MBS_Recurrence::weekly_dates(
    array( 'booking_date' => '2026-03-22', 'booking_date_end' => '2026-03-22' ),
    '2026-04-12'
);
assert_same( array( '2026-03-22', '2026-03-29', '2026-04-05', '2026-04-12' ), $dates, 'Weekly dates stay on Sunday through the BST transition.' );

$dates = MBS_Recurrence::weekly_dates(
    array( 'booking_date' => '2026-10-12', 'booking_date_end' => '2026-10-12' ),
    '2026-12-14',
    2
);
assert_same(
    array( '2026-10-12', '2026-10-26', '2026-11-09', '2026-11-23', '2026-12-07' ),
    $dates,
    'Fortnightly dates remain on the same weekday through the autumn GMT transition.'
);

$dates = MBS_Recurrence::weekly_dates( array( 'booking_date' => '2026-09-27' ), '2026-12-20' );
assert_same( 13, count( $dates ), 'A 13-occurrence series is generated exactly.' );
assert_same( '2026-10-25', $dates[4], 'Weekly dates stay local through the autumn GMT transition.' );

$dates = MBS_Recurrence::weekly_dates( array( 'booking_date' => '2028-02-07' ), '2029-01-29' );
assert_same( 52, count( $dates ), 'A 52-occurrence series starting months in the future is generated exactly.' );
assert_same( '2028-02-28', $dates[3], 'Leap-year February dates remain real weekly dates.' );

$dates = MBS_Recurrence::weekly_dates( array( 'booking_date' => '2024-01-01' ), '2024-12-30' );
assert_same( 53, count( $dates ), 'A valid calendar year can contain 53 weekly occurrences.' );
assert_same( '2024-12-30', end( $dates ), 'The 53rd occurrence is retained.' );

$error = MBS_Recurrence::weekly_dates( array( 'booking_date' => '2026-01-01' ), '' );
assert_same( 'repeat_until_required', $error->get_error_code(), 'Repeat-until is required.' );

$error = MBS_Recurrence::weekly_dates( array( 'booking_date' => '2026-02-30' ), '2026-03-30' );
assert_same( 'invalid_date', $error->get_error_code(), 'Impossible calendar dates are rejected.' );

$error = MBS_Recurrence::weekly_dates( array( 'booking_date' => '2026-05-10' ), '2026-05-09' );
assert_same( 'invalid_range', $error->get_error_code(), 'Repeat-until cannot precede the start.' );

$error = MBS_Recurrence::weekly_dates( array( 'booking_date' => '2026-05-10' ), '2027-05-11' );
assert_same( 'recurrence_too_long', $error->get_error_code(), 'More than one calendar year is rejected.' );

$error = MBS_Recurrence::weekly_dates(
    array( 'booking_date' => '2026-05-10', 'booking_date_end' => '2026-05-11' ),
    '2026-06-10'
);
assert_same( 'recurring_multi_day', $error->get_error_code(), 'Recurring multi-day requests are rejected.' );

$error = MBS_Recurrence::weekly_dates( array( 'booking_date' => '2026-05-10' ), '2026-06-10', 3 );
assert_same( 'invalid_recurrence_interval', $error->get_error_code(), 'Unsupported repeat intervals are rejected.' );

echo "OK: {$tests} recurrence assertions passed.\n";

assert_same( array( '2026-03-28', '2026-03-29', '2026-03-30' ), MBS_Recurrence::dates( array( 'booking_date' => '2026-03-28', 'recurrence_pattern' => 'daily' ), '2026-03-30' ), 'Daily dates survive the spring clock change.' );
assert_same( 366, count( MBS_Recurrence::dates( array( 'booking_date' => '2026-01-01', 'recurrence_pattern' => 'daily' ), '2027-01-01' ) ), 'Daily pattern supports an inclusive full year.' );
assert_same( array( '2026-01-31', '2026-03-31', '2026-05-31' ), MBS_Recurrence::dates( array( 'booking_date' => '2026-01-31', 'recurrence_pattern' => 'monthly_date' ), '2026-05-31' ), 'Monthly dates skip unavailable days without drifting.' );
assert_same( array( '2026-01-12', '2026-02-09', '2026-03-09', '2026-04-13' ), MBS_Recurrence::dates( array( 'booking_date' => '2026-01-12', 'recurrence_pattern' => 'monthly_weekday' ), '2026-04-30' ), 'Second Monday stays second Monday each month.' );
assert_same( array( '2026-03-30', '2026-06-29' ), MBS_Recurrence::dates( array( 'booking_date' => '2026-03-30', 'recurrence_pattern' => 'monthly_weekday' ), '2026-06-30' ), 'Fifth weekdays skip months without a fifth occurrence.' );
$emma_dates = array( '2026-10-12', '2026-10-26', '2026-11-02', '2026-11-16', '2026-11-30', '2026-12-14' );
assert_same( $emma_dates, MBS_Recurrence::dates( array( 'booking_date' => '2026-10-12', 'recurrence_pattern' => 'selected', 'recurrence_dates' => '2026-12-14,2026-10-26,2026-11-02,2026-11-16,2026-11-30,2026-10-26' ), '2026-12-14' ), 'Selected dates reproduce irregular schedules and deduplicate.' );
assert_same( 'invalid_date', MBS_Recurrence::dates( array( 'booking_date' => '2026-01-01', 'recurrence_pattern' => 'selected', 'recurrence_dates' => '2026-02-30' ), '2026-03-01' )->get_error_code(), 'Invalid selected dates are rejected.' );
assert_same( 'invalid_selected_range', MBS_Recurrence::dates( array( 'booking_date' => '2026-01-01', 'recurrence_pattern' => 'selected', 'recurrence_dates' => '2026-04-01' ), '2026-03-01' )->get_error_code(), 'Selected dates outside the requested range are rejected.' );
echo "OK: {$tests} expanded recurrence assertions passed.\n";
