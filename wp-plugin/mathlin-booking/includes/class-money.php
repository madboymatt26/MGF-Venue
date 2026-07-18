<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Exact integer-minor-unit money helpers. */
class MBS_Money {

    public static function minor( $value ) {
        if ( is_float( $value ) ) {
            return new WP_Error( 'float_money_rejected', 'Financial writes must use integer minor units, never floating-point values.' );
        }
        if ( is_int( $value ) ) {
            return $value;
        }
        if ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) ) {
            return (int) $value;
        }
        return new WP_Error( 'invalid_minor_units', 'Money must be supplied as a whole number of minor units.' );
    }

    public static function from_decimal_string( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/^-?\d+(?:\.\d{1,2})?$/', $value ) ) {
            return new WP_Error( 'invalid_decimal_money', 'Decimal money must be a string with no more than two decimal places.' );
        }
        $negative = strpos( $value, '-' ) === 0;
        $unsigned = ltrim( $value, '-' );
        $parts = explode( '.', $unsigned, 2 );
        $major = (int) $parts[0];
        $fraction = isset( $parts[1] ) ? str_pad( $parts[1], 2, '0' ) : '00';
        $minor = ( $major * 100 ) + (int) $fraction;
        return $negative ? -$minor : $minor;
    }

    public static function format( $minor, $currency = 'GBP' ) {
        $validated = self::minor( $minor );
        if ( is_wp_error( $validated ) ) {
            return $validated;
        }
        $sign = $validated < 0 ? '-' : '';
        $absolute = abs( $validated );
        $symbol = strtoupper( $currency ) === 'GBP' ? '£' : strtoupper( $currency ) . ' ';
        return $sign . $symbol . intdiv( $absolute, 100 ) . '.' . str_pad( (string) ( $absolute % 100 ), 2, '0', STR_PAD_LEFT );
    }

    public static function line_total( $unit_minor, $quantity_milli ) {
        $unit = self::minor( $unit_minor );
        $quantity = self::minor( $quantity_milli );
        if ( is_wp_error( $unit ) ) return $unit;
        if ( is_wp_error( $quantity ) || $quantity <= 0 ) {
            return new WP_Error( 'invalid_quantity', 'Invoice item quantity must be a positive integer number of thousandths.' );
        }
        $product = $unit * $quantity;
        $rounding = $product >= 0 ? 500 : -500;
        return intdiv( $product + $rounding, 1000 );
    }
}
