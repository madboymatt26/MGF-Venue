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
        if ( is_string( $value ) && preg_match( '/^-?\d+$/D', $value ) ) {
            $negative = $value[0] === '-';
            $digits = ltrim( $negative ? substr( $value, 1 ) : $value, '0' );
            $limit = $negative ? substr( (string) PHP_INT_MIN, 1 ) : (string) PHP_INT_MAX;
            if ( strlen( $digits ) > strlen( $limit ) || ( strlen( $digits ) === strlen( $limit ) && strcmp( $digits, $limit ) > 0 ) ) {
                return new WP_Error( 'money_overflow', 'Money exceeds the supported exact integer range.' );
            }
            return (int) $value;
        }
        return new WP_Error( 'invalid_minor_units', 'Money must be supplied as a whole number of minor units.' );
    }

    public static function from_decimal_string( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/^-?\d+(?:\.\d{1,2})?$/D', $value ) ) {
            return new WP_Error( 'invalid_decimal_money', 'Decimal money must be a string with no more than two decimal places.' );
        }
        $negative = strpos( $value, '-' ) === 0;
        $unsigned = ltrim( $value, '-' );
        $parts = explode( '.', $unsigned, 2 );
        $fraction = isset( $parts[1] ) ? str_pad( $parts[1], 2, '0' ) : '00';
        // Concatenate decimal digits before range checking; multiplication could
        // otherwise become a float or silently clamp an oversized integer input.
        return self::minor( ( $negative ? '-' : '' ) . $parts[0] . $fraction );
    }

    public static function format( $minor, $currency = 'GBP' ) {
        $validated = self::minor( $minor );
        if ( is_wp_error( $validated ) ) {
            return $validated;
        }
        $sign = $validated < 0 ? '-' : '';
        $digits = ltrim( (string) $validated, '-' );
        $symbol = strtoupper( $currency ) === 'GBP' ? '£' : strtoupper( $currency ) . ' ';
        return $sign . $symbol . self::decimal_digits( $digits );
    }

    /** Decimal string for gateway APIs; still never a float. */
    public static function decimal( $minor ) {
        $validated = self::minor( $minor );
        if ( is_wp_error( $validated ) ) return $validated;
        $sign = $validated < 0 ? '-' : '';
        return $sign . self::decimal_digits( ltrim( (string) $validated, '-' ) );
    }

    private static function decimal_digits( $digits ) {
        return ( strlen( $digits ) > 2 ? substr( $digits, 0, -2 ) : '0' ) . '.' . substr( '00' . $digits, -2 );
    }

    public static function line_total( $unit_minor, $quantity_milli ) {
        $unit = self::minor( $unit_minor );
        $quantity = self::minor( $quantity_milli );
        if ( is_wp_error( $unit ) ) return $unit;
        if ( is_wp_error( $quantity ) || $quantity <= 0 ) {
            return new WP_Error( 'invalid_quantity', 'Invoice item quantity must be a positive integer number of thousandths.' );
        }
        if ( $unit === PHP_INT_MIN || abs( $unit ) > intdiv( PHP_INT_MAX - 500, $quantity ) ) {
            return new WP_Error( 'money_overflow', 'Quantity calculation exceeds the supported exact integer range.' );
        }
        $product = $unit * $quantity;
        $rounding = $product >= 0 ? 500 : -500;
        return intdiv( $product + $rounding, 1000 );
    }
}
