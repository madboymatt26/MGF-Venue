<?php
/**
 * Plugin Name: MGF Venue deterministic test gateway
 * Description: Disposable integration-only WooCommerce gateway. Never enable in production.
 * Version: 1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'woocommerce_payment_gateways', function ( $gateways ) { $gateways[] = 'MBS_Test_Gateway'; return $gateways; } );
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;
    class MBS_Test_Gateway extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'mbs_test';
            $this->method_title = 'MGF deterministic test gateway';
            $this->has_fields = false;
            $this->supports = array( 'products', 'refunds' );
            $this->enabled = 'yes';
            $this->title = 'MGF deterministic test gateway';
        }
        public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );
            $mode = get_option( 'mbs_test_gateway_mode', 'success' );
            if ( $mode === 'failed' ) return new WP_Error( 'mbs_test_declined', 'Deterministic decline.' );
            if ( $mode === 'capture_ledger_failure' ) $order->update_meta_data( '_mbs_test_force_ledger_failure', 'yes' );
            if ( $mode === 'delayed' ) $order->update_status( 'on-hold', 'Deterministic delayed completion.' );
            else $order->payment_complete( 'mbs-test-' . $order_id );
            $order->save();
            return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
        }
        public function process_refund( $order_id, $amount = null, $reason = '' ) { return true; }
    }
} );

add_filter( 'mbs_invoice_gateway_payment_preflight', function ( $result, $invoice_ref, $order_id ) {
    $order = wc_get_order( $order_id );
    if ( $order && $order->get_meta( '_mbs_test_force_ledger_failure' ) === 'yes' ) return new WP_Error( 'mbs_test_ledger_failure', 'Deterministic pre-ledger failure after capture.' );
    return $result;
}, 10, 3 );
