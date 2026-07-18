<?php
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    private $message;
    public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_timezone() { return new DateTimeZone( 'Europe/London' ); }
function wp_date( $format, $timestamp = null ) {
    $date = new DateTimeImmutable( '@' . ( $timestamp ?? time() ) );
    return $date->setTimezone( wp_timezone() )->format( $format );
}
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }

require_once dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-money.php';
require_once dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-billing-engine.php';

$checks = 0;
function billing_same( $expected, $actual, $message ) {
    global $checks;
    $checks++;
    if ( $expected !== $actual ) {
        fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
        exit( 1 );
    }
}
function booking( $ref, $date, $amount ) {
    return (object) array( 'ref' => $ref, 'booking_date' => $date, 'amount' => $amount, 'space' => 'Hall', 'kitchen' => 0 );
}

$base = (object) array(
    'billing_mode' => 'monthly', 'invoice_lead_days' => 28,
    'payment_terms_days' => 14, 'billing_schedule_json' => '{}',
);
$bookings = array(
    booking( 'MBS-A', '2026-01-05', '10.00' ),
    booking( 'MBS-B', '2026-01-12', '12.50' ),
    booking( 'MBS-C', '2026-02-02', '5.00' ),
);
$periods = MBS_Billing_Engine::build_periods( $base, $bookings );
billing_same( 2, count( $periods ), 'Monthly billing groups calendar months.' );
billing_same( 2250, $periods[0]['total_minor'], 'Monthly total is exact integer pence.' );
billing_same( 2, $periods[0]['occurrence_count'], 'Monthly preview lists covered occurrences.' );
billing_same( '2025-12-04', $periods[0]['issue_on'], 'Lead date is calculated from the period start.' );
billing_same( '2025-12-18', $periods[0]['due_on'], 'Payment terms are calculated without passing the service period start.' );

$upfront = clone $base;
$upfront->billing_mode = 'upfront';
$periods = MBS_Billing_Engine::build_periods( $upfront, $bookings );
billing_same( 1, count( $periods ), 'Upfront mode creates one period.' );
billing_same( 2750, $periods[0]['total_minor'], 'Upfront total covers all occurrences.' );

$legacy = clone $base;
$legacy->billing_mode = 'legacy_per_occurrence';
$periods = MBS_Billing_Engine::build_periods( $legacy, $bookings );
billing_same( 3, count( $periods ), 'Legacy mode creates one period per occurrence.' );

$termly = clone $base;
$termly->billing_mode = 'termly';
$termly->billing_schedule_json = '{}';
$error = MBS_Billing_Engine::build_periods( $termly, $bookings );
billing_same( 'term_schedule_required', $error->get_error_code(), 'Term dates are never inferred when metadata is missing.' );
$termly->billing_schedule_json = json_encode( array( 'terms' => array(
    array( 'key' => 'invalid', 'start' => '2026-02-30', 'end' => '2026-04-30' ),
) ) );
$error = MBS_Billing_Engine::build_periods( $termly, $bookings );
billing_same( 'invalid_term_schedule', $error->get_error_code(), 'Impossible term dates are rejected.' );
$termly->billing_schedule_json = json_encode( array( 'terms' => array(
    array( 'key' => 'spring', 'label' => 'Spring term', 'start' => '2026-01-01', 'end' => '2026-04-30' ),
) ) );
$periods = MBS_Billing_Engine::build_periods( $termly, $bookings );
billing_same( 1, count( $periods ), 'Explicit term dates group matching occurrences.' );
billing_same( 2750, $periods[0]['total_minor'], 'Termly total remains exact.' );

$float_booking = array( booking( 'MBS-F', '2026-01-01', 10.50 ) );
$error = MBS_Billing_Engine::build_periods( $base, $float_booking );
billing_same( 'float_booking_amount', $error->get_error_code(), 'Float booking snapshots are rejected.' );

echo "OK: {$checks} billing-engine assertions passed.\n";
