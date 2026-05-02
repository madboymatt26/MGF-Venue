<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WooCommerce Payment Integration
 *
 * Creates a hidden WooCommerce product for booking payments.
 * When a booking is confirmed, generates a unique checkout URL.
 * When payment completes, auto-updates the booking status to "paid".
 *
 * Requires: WooCommerce plugin active with WooPayments or any payment gateway.
 */
class MBS_Woo_Payment {

    const PRODUCT_SLUG = 'mbs-booking-payment';

    public function init() {
        // Only load if WooCommerce is active
        if ( ! class_exists( 'WooCommerce' ) ) return;

        add_action( 'woocommerce_order_status_completed',  array( $this, 'on_order_completed' ) );
        add_action( 'woocommerce_order_status_processing', array( $this, 'on_order_completed' ) );
        add_action( 'woocommerce_thankyou',                array( $this, 'thankyou_message' ) );

        // REST endpoint for generating payment links
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Check if WooCommerce is available.
     */
    public static function is_available() {
        return class_exists( 'WooCommerce' );
    }

    /**
     * Get or create the hidden booking payment product.
     */
    public static function get_payment_product_id() {
        $product_id = get_option( 'mbs_woo_product_id', 0 );

        // Check if product still exists
        if ( $product_id && get_post( $product_id ) && get_post_type( $product_id ) === 'product' ) {
            return $product_id;
        }

        // Create the product
        $product = new WC_Product_Simple();
        $product->set_name( 'Scout Hall Booking Payment' );
        $product->set_slug( self::PRODUCT_SLUG );
        $product->set_status( 'private' ); // Hidden from shop
        $product->set_catalog_visibility( 'hidden' );
        $product->set_price( 0 );
        $product->set_regular_price( 0 );
        $product->set_sold_individually( true );
        $product->set_virtual( true );
        $product->set_tax_status( 'none' ); // Charity exempt
        $product->set_description( 'Payment for venue booking at Needham Market Scout Hall.' );
        $product->save();

        $product_id = $product->get_id();
        update_option( 'mbs_woo_product_id', $product_id );

        return $product_id;
    }

    /**
     * Generate a payment URL for a booking.
     * Adds the product to cart with the booking amount and ref, then returns checkout URL.
     *
     * @param object $booking  Booking database row
     * @return string  Checkout URL, or empty string if WooCommerce unavailable
     */
    public static function generate_payment_url( $booking ) {
        if ( ! self::is_available() ) return '';

        $product_id = self::get_payment_product_id();
        if ( ! $product_id ) return '';

        // Build a URL that adds the product to cart with booking metadata
        $url = add_query_arg( array(
            'mbs_pay'  => '1',
            'ref'      => $booking->ref,
            'amount'   => $booking->amount,
            'nonce'    => wp_create_nonce( 'mbs_pay_' . $booking->ref ),
        ), wc_get_checkout_url() );

        return $url;
    }

    /**
     * Handle the payment URL — add product to cart and redirect to checkout.
     * Hooked into template_redirect.
     */
    public static function handle_payment_redirect() {
        if ( ! isset( $_GET['mbs_pay'] ) || $_GET['mbs_pay'] !== '1' ) return;
        if ( ! self::is_available() ) return;

        $ref    = sanitize_text_field( $_GET['ref'] ?? '' );
        $amount = floatval( $_GET['amount'] ?? 0 );
        $nonce  = sanitize_text_field( $_GET['nonce'] ?? '' );

        if ( ! $ref || ! $amount || ! wp_verify_nonce( $nonce, 'mbs_pay_' . $ref ) ) {
            wp_die( 'Invalid payment link. Please contact us for assistance.' );
        }

        // Verify booking exists and is payable
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking || ! in_array( $booking->status, array( 'confirmed' ) ) ) {
            wp_die( 'This booking is not available for payment. It may have already been paid or cancelled.' );
        }

        // Clear cart and add our product
        WC()->cart->empty_cart();

        $product_id = self::get_payment_product_id();

        // Set the price dynamically via filter
        add_filter( 'woocommerce_product_get_price', function( $price, $product ) use ( $amount, $product_id ) {
            if ( $product->get_id() == $product_id ) return $amount;
            return $price;
        }, 10, 2 );

        $cart_item_data = array(
            'mbs_booking_ref'    => $ref,
            'mbs_booking_amount' => $amount,
        );

        WC()->cart->add_to_cart( $product_id, 1, 0, array(), $cart_item_data );

        // Redirect to checkout
        wp_redirect( wc_get_checkout_url() );
        exit;
    }

    /**
     * Display booking ref in cart/checkout.
     */
    public static function display_cart_item_data( $item_data, $cart_item ) {
        if ( isset( $cart_item['mbs_booking_ref'] ) ) {
            $item_data[] = array(
                'key'   => 'Booking Reference',
                'value' => $cart_item['mbs_booking_ref'],
            );
        }
        return $item_data;
    }

    /**
     * Set the correct price for the cart item.
     */
    public static function set_cart_item_price( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

        foreach ( $cart->get_cart() as $cart_item ) {
            if ( isset( $cart_item['mbs_booking_amount'] ) ) {
                $cart_item['data']->set_price( $cart_item['mbs_booking_amount'] );
            }
        }
    }

    /**
     * Save booking ref to order meta.
     */
    public static function save_order_meta( $item, $cart_item_key, $values, $order ) {
        if ( isset( $values['mbs_booking_ref'] ) ) {
            $item->add_meta_data( '_mbs_booking_ref', $values['mbs_booking_ref'], true );
        }
    }

    /**
     * When a WooCommerce order is completed/processing, update the booking to "paid".
     */
    public function on_order_completed( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        foreach ( $order->get_items() as $item ) {
            $ref = $item->get_meta( '_mbs_booking_ref' );
            if ( ! $ref ) continue;

            $booking = MBS_Bookings::get( $ref );
            if ( ! $booking ) continue;

            // Only update if currently confirmed (not already paid)
            if ( $booking->status === 'confirmed' ) {
                MBS_Bookings::update_status( $ref, 'paid' );
                MBS_Audit_Log::log( $ref, 'paid', 'Payment received via WooCommerce (Order #' . $order_id . ')', 0 );
                MBS_Email::notify_paid( $booking );

                // Store order ID on the booking for reference
                global $wpdb;
                $table = $wpdb->prefix . MBS_TABLE;
                $wpdb->update(
                    $table,
                    array( 'admin_notes' => trim( $booking->admin_notes . "\nPayment: WooCommerce Order #" . $order_id ) ),
                    array( 'ref' => $ref )
                );
            }
        }
    }

    /**
     * Custom thank you message for booking payments.
     */
    public function thankyou_message( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        foreach ( $order->get_items() as $item ) {
            $ref = $item->get_meta( '_mbs_booking_ref' );
            if ( $ref ) {
                $booking = MBS_Bookings::get( $ref );
                if ( $booking ) {
                    echo '<div style="background:#d1fae5;border:1px solid #2ecc71;border-radius:8px;padding:16px 20px;margin:16px 0;">';
                    echo '<h3 style="color:#065f46;margin:0 0 8px;">✅ Booking Payment Received</h3>';
                    echo '<p style="margin:0;">Your payment for booking <strong>' . esc_html( $ref ) . '</strong> ';
                    echo '(' . esc_html( $booking->space ) . ' on ' . esc_html( date( 'j F Y', strtotime( $booking->booking_date ) ) ) . ') ';
                    echo 'has been received. Thank you!</p>';
                    echo '</div>';
                }
            }
        }
    }

    /**
     * Register REST route for generating payment links (used by admin).
     */
    public function register_routes() {
        register_rest_route( 'mathlin/v1', '/bookings/(?P<ref>[A-Z0-9\-]+)/payment-url', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_get_payment_url' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ) );
    }

    public function rest_get_payment_url( WP_REST_Request $request ) {
        $ref     = strtoupper( sanitize_text_field( $request->get_param( 'ref' ) ) );
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) {
            return new WP_Error( 'not_found', 'Booking not found', array( 'status' => 404 ) );
        }
        $url = self::generate_payment_url( $booking );
        return rest_ensure_response( array( 'payment_url' => $url ) );
    }
}

// ── WooCommerce hooks (must be outside the class for proper filter registration) ──
add_action( 'template_redirect', array( 'MBS_Woo_Payment', 'handle_payment_redirect' ) );
add_filter( 'woocommerce_get_item_data', array( 'MBS_Woo_Payment', 'display_cart_item_data' ), 10, 2 );
add_action( 'woocommerce_before_calculate_totals', array( 'MBS_Woo_Payment', 'set_cart_item_price' ) );
add_action( 'woocommerce_checkout_create_order_line_item', array( 'MBS_Woo_Payment', 'save_order_meta' ), 10, 4 );
