<?php
if ( ! class_exists( 'WooCommerce' ) ) throw new RuntimeException( 'WooCommerce is not active.' );
if ( ! in_array( 'mbs_test', array_keys( WC()->payment_gateways()->payment_gateways() ), true ) ) throw new RuntimeException( 'Deterministic gateway is unavailable.' );

// This smoke proves real Woo objects and hooks load. Detailed fixture creation
// is deliberately kept here so failures occur against Woo, not source strings.
$order = wc_create_order();
$order->set_billing_email( 'integration@example.invalid' );
$order->set_payment_method( 'mbs_test' );
$order->set_total( '10.00' );
$order->save();
update_option( 'mbs_test_gateway_mode', 'failed' );
$gateway = WC()->payment_gateways()->payment_gateways()['mbs_test'];
$failed = $gateway->process_payment( $order->get_id() );
if ( ! is_wp_error( $failed ) ) throw new RuntimeException( 'Failed-payment mode did not fail.' );
update_option( 'mbs_test_gateway_mode', 'delayed' );
$delayed = $gateway->process_payment( $order->get_id() );
$order = wc_get_order( $order->get_id() );
if ( ! is_array( $delayed ) || $order->get_status() !== 'on-hold' ) throw new RuntimeException( 'Delayed payment was not held.' );

do_action( 'woocommerce_order_refunded', $order->get_id(), 999999 );
echo "OK: real Woo order, deterministic failure/delay, and refund hook executed.\n";
