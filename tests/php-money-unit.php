<?php
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    public function __construct( $code, $message ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
require_once dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-money.php';

$checks = 0;
function same_money( $expected, $actual, $message ) {
    global $checks;
    $checks++;
    if ( $expected !== $actual ) {
        fwrite( STDERR, "FAIL: {$message}; expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n" );
        exit( 1 );
    }
}

same_money( 1234, MBS_Money::from_decimal_string( '12.34' ), 'Decimal string converts exactly to pence.' );
same_money( 1250, MBS_Money::from_decimal_string( '12.5' ), 'One decimal digit is padded.' );
same_money( -5, MBS_Money::from_decimal_string( '-0.05' ), 'Negative adjustments remain exact.' );
same_money( 0, MBS_Money::from_decimal_string( '0' ), 'Whole decimal string converts exactly.' );
same_money( 'float_money_rejected', MBS_Money::minor( 12.34 )->get_error_code(), 'Floats are rejected at financial writes.' );
same_money( 'invalid_decimal_money', MBS_Money::from_decimal_string( '1.234' )->get_error_code(), 'More than two decimal places are rejected.' );
same_money( 1851, MBS_Money::line_total( 1234, 1500 ), 'Quantity multiplication uses integer thousandths and exact rounding.' );
same_money( -1851, MBS_Money::line_total( -1234, 1500 ), 'Negative adjustment rounding is symmetric.' );
same_money( '£12.34', MBS_Money::format( 1234 ), 'GBP minor units format correctly.' );
same_money( '-£0.05', MBS_Money::format( -5 ), 'Negative GBP minor units format correctly.' );
same_money( '12.34', MBS_Money::decimal( 1234 ), 'Gateway decimal remains an exact string.' );
same_money( PHP_INT_MAX, MBS_Money::minor( (string) PHP_INT_MAX ), 'Largest exact integer accepted.' );
same_money( PHP_INT_MIN, MBS_Money::minor( (string) PHP_INT_MIN ), 'Smallest exact integer accepted.' );
same_money( 'money_overflow', MBS_Money::minor( (string) PHP_INT_MAX . '0' )->get_error_code(), 'Oversized minor amount does not clamp.' );
same_money( 'money_overflow', MBS_Money::from_decimal_string( str_repeat( '9', 200 ) )->get_error_code(), 'Oversized decimal never becomes a float.' );
same_money( 'money_overflow', MBS_Money::line_total( PHP_INT_MAX, 1000 )->get_error_code(), 'Overflowing quantity multiplication fails before float conversion.' );
same_money( 'money_overflow', MBS_Money::line_total( PHP_INT_MIN, 1000 )->get_error_code(), 'Smallest integer cannot cause abs()/rounding overflow.' );
same_money( PHP_INT_MIN, MBS_Money::from_decimal_string( MBS_Money::decimal( PHP_INT_MIN ) ), 'Minimum integer formats and parses exactly without abs overflow.' );
same_money( 'invalid_decimal_money', MBS_Money::from_decimal_string( "1.00\n" )->get_error_code(), 'Trailing newline is not part of a money field.' );
same_money( 1234, MBS_Money::from_decimal_string( '00012.34' ), 'Leading zeros preserve exact value.' );

echo "OK: {$checks} exact-money assertions passed.\n";
