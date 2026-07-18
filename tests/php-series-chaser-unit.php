<?php
define( 'ABSPATH', __DIR__ );

class MBS_Series {
    public static function billing_treatment_for_booking( $booking ) {
        return $booking->billing_treatment;
    }
}
require_once dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-payment-chaser.php';

$checks = 0;
function assert_chase( $expected, $booking, $message ) {
    global $checks;
    $checks++;
    $actual = MBS_Payment_Chaser::should_chase_occurrence( $booking );
    if ( $actual !== $expected ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

for ( $i = 0; $i < 53; $i++ ) {
    assert_chase( false, (object) array( 'billing_treatment' => 'manual_consolidated' ), 'No occurrence in a 53-date consolidated series is chased.' );
}
assert_chase( false, (object) array( 'billing_treatment' => 'invoice_managed' ), 'Invoice-managed occurrences are not chased.' );
assert_chase( false, (object) array( 'billing_treatment' => 'none' ), 'No-billing occurrences are not chased.' );
assert_chase( true, (object) array( 'billing_treatment' => 'one_off' ), 'One-off chasing is preserved.' );
assert_chase( true, (object) array( 'billing_treatment' => 'legacy_per_occurrence' ), 'Legacy per-occurrence chasing is preserved.' );

echo "OK: {$checks} billing-treatment chase assertions passed.\n";
