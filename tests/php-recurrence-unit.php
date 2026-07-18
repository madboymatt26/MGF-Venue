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

echo "OK: {$tests} recurrence assertions passed.\n";
