<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Atomic, expiring claim for a consolidated invoice checkout. */
class MBS_Invoice_Reservation {
    const TTL = 1200;

    public static function acquire( $invoice, $existing_ref = '' ) {
        if ( ! MBS_Invoice_Payment::is_payable( $invoice ) ) return new WP_Error( 'invoice_not_payable', 'This invoice is not available for payment.' );
        $current = self::get( $invoice->invoice_ref );
        if ( $current && self::is_expired( $current ) ) {
            self::release( $invoice->invoice_ref, $current['reservation_ref'], 'expired' );
            $current = null;
        }
        if ( $current ) {
            if ( $existing_ref && hash_equals( $current['reservation_ref'], (string) $existing_ref ) && $current['status'] === 'active' ) return $current;
            return new WP_Error( 'invoice_payment_reserved', 'Another checkout is already paying this invoice. Please wait for it to finish or expire.' );
        }
        $reservation = array(
            'reservation_ref' => self::reference(), 'invoice_ref' => (string) $invoice->invoice_ref,
            'invoice_id' => (int) $invoice->id, 'order_id' => 0,
            'amount_minor' => MBS_Billing_Ledger::balance_minor( $invoice ), 'status' => 'active',
            'created_at' => time(), 'expires_at' => time() + self::TTL, 'last_error' => '',
        );
        if ( ! add_option( self::option_key( $invoice->invoice_ref ), $reservation, '', false ) ) {
            return new WP_Error( 'invoice_payment_reserved', 'Another checkout acquired this invoice first.' );
        }
        if ( function_exists( 'wp_schedule_single_event' ) ) {
            wp_schedule_single_event( $reservation['expires_at'] + 5, 'mbs_release_invoice_reservation', array( $invoice->invoice_ref, $reservation['reservation_ref'] ) );
        }
        return $reservation;
    }

    public static function bind_order( $invoice_ref, $reservation_ref, $order_id ) {
        $current = self::get( $invoice_ref );
        if ( ! self::matches_active( $current, $reservation_ref ) ) return new WP_Error( 'invoice_reservation_lost', 'The invoice checkout reservation expired or was replaced.' );
        if ( ! empty( $current['order_id'] ) && (int) $current['order_id'] !== (int) $order_id ) return new WP_Error( 'invoice_reservation_order_conflict', 'This invoice reservation belongs to another order.' );
        $current['order_id'] = (int) $order_id;
        $current['expires_at'] = max( (int) $current['expires_at'], time() + self::TTL );
        update_option( self::option_key( $invoice_ref ), $current, false );
        return $current;
    }

    public static function validate( $invoice_ref, $reservation_ref, $amount_minor, $order_id = 0 ) {
        $current = self::get( $invoice_ref );
        return self::matches_active( $current, $reservation_ref )
            && (int) $current['amount_minor'] === (int) $amount_minor
            && ( ! $order_id || empty( $current['order_id'] ) || (int) $current['order_id'] === (int) $order_id );
    }

    public static function complete( $invoice_ref, $reservation_ref, $order_id ) {
        $current = self::get( $invoice_ref );
        if ( ! $current || ! hash_equals( (string) $current['reservation_ref'], (string) $reservation_ref ) ) return false;
        if ( ! empty( $current['order_id'] ) && (int) $current['order_id'] !== (int) $order_id ) return false;
        return delete_option( self::option_key( $invoice_ref ) );
    }

    public static function reconciliation_required( $invoice_ref, $reservation_ref, $order_id, $message ) {
        $current = self::get( $invoice_ref );
        if ( ! $current || ! hash_equals( (string) $current['reservation_ref'], (string) $reservation_ref ) ) return false;
        $current['order_id'] = (int) $order_id;
        $current['status'] = 'reconciliation_required';
        $current['last_error'] = sanitize_text_field( $message );
        $current['expires_at'] = time() + DAY_IN_SECONDS * 30;
        return update_option( self::option_key( $invoice_ref ), $current, false );
    }

    public static function release( $invoice_ref, $reservation_ref, $reason = 'released' ) {
        $current = self::get( $invoice_ref );
        if ( ! $current || ! hash_equals( (string) $current['reservation_ref'], (string) $reservation_ref ) ) return false;
        if ( $current['status'] === 'reconciliation_required' ) return false;
        return delete_option( self::option_key( $invoice_ref ) );
    }

    public static function release_order( $order_id ) {
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
        if ( ! $order ) return false;
        $invoice_ref = (string) $order->get_meta( '_mbs_invoice_ref' );
        $reservation_ref = (string) $order->get_meta( '_mbs_invoice_reservation_ref' );
        return $invoice_ref && $reservation_ref ? self::release( $invoice_ref, $reservation_ref, 'order_cancelled' ) : false;
    }

    public static function release_expired( $invoice_ref, $reservation_ref ) {
        $current = self::get( $invoice_ref );
        return $current && self::is_expired( $current ) ? self::release( $invoice_ref, $reservation_ref, 'expired' ) : false;
    }

    public static function get( $invoice_ref ) {
        $value = get_option( self::option_key( $invoice_ref ), null );
        return is_array( $value ) ? $value : null;
    }

    private static function matches_active( $current, $reservation_ref ) {
        return $current && $current['status'] === 'active' && ! self::is_expired( $current )
            && hash_equals( (string) $current['reservation_ref'], (string) $reservation_ref );
    }

    private static function is_expired( $reservation ) { return (int) ( $reservation['expires_at'] ?? 0 ) <= time(); }
    private static function option_key( $invoice_ref ) { return 'mbs_invoice_reservation_' . substr( hash( 'sha256', strtoupper( trim( (string) $invoice_ref ) ) ), 0, 40 ); }
    private static function reference() {
        try { return 'RSV-' . strtoupper( bin2hex( random_bytes( 12 ) ) ); }
        catch ( Exception $e ) { return 'RSV-' . strtoupper( wp_generate_password( 24, false, false ) ); }
    }
}
