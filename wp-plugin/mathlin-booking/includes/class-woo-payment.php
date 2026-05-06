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
        // Register hooks on 'init' to guarantee WooCommerce is loaded
        // (plugins_loaded order is not guaranteed between plugins)
        add_action( 'init', array( $this, 'register_woo_hooks' ) );
    }

    /**
     * Register WooCommerce hooks — called on 'init' when WooCommerce is guaranteed loaded.
     */
    public function register_woo_hooks() {
        if ( ! class_exists( 'WooCommerce' ) ) return;

        add_action( 'woocommerce_order_status_completed',  array( $this, 'on_order_completed' ) );
        add_action( 'woocommerce_order_status_processing', array( $this, 'on_order_completed' ) );
        add_action( 'woocommerce_payment_complete',        array( $this, 'on_order_completed' ) );
        add_action( 'woocommerce_order_status_refunded',   array( $this, 'on_order_refunded' ) );
        add_action( 'woocommerce_order_fully_refunded',    array( $this, 'on_order_refunded' ) );
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
            // Fix: ensure existing product is published (not private) so guests can purchase
            $product = wc_get_product( $product_id );
            if ( $product && $product->get_status() !== 'publish' ) {
                $product->set_status( 'publish' );
                $product->set_catalog_visibility( 'hidden' );
                $product->save();
            }
            return $product_id;
        }

        // Create the product
        $product = new WC_Product_Simple();
        $product->set_name( 'Scout Hall Booking Payment' );
        $product->set_slug( self::PRODUCT_SLUG );
        $product->set_status( 'publish' );           // Must be publish for guest checkout
        $product->set_catalog_visibility( 'hidden' ); // Hidden from shop/search/category pages
        $product->set_price( 0 );
        $product->set_regular_price( 0 );
        $product->set_sold_individually( true );
        $product->set_virtual( true );
        $product->set_tax_status( 'none' ); // Charity exempt
        $product->set_description( 'Payment for venue booking at Needham Market Scout Hall.' );
        $product->set_reviews_allowed( false );
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

        // UX-002: Don't generate payment URLs for £0 bookings (scout use etc)
        if ( (float) $booking->amount <= 0 ) return '';

        $product_id = self::get_payment_product_id();
        if ( ! $product_id ) return '';

        // Use the modification_token as a session-independent secret
        // This allows payment links to work from any device/browser
        $token = $booking->modification_token;
        if ( empty( $token ) ) {
            // Generate one if missing (pre-v2.0 bookings)
            $token = wp_generate_password( 32, false );
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . MBS_TABLE,
                array( 'modification_token' => $token ),
                array( 'ref' => $booking->ref )
            );
        }

        $url = add_query_arg( array(
            'mbs_pay'  => '1',
            'ref'      => $booking->ref,
            'token'    => $token,
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

        $ref   = sanitize_text_field( $_GET['ref'] ?? '' );
        $token = sanitize_text_field( $_GET['token'] ?? '' );

        if ( ! $ref || ! $token ) {
            wp_die( 'Invalid payment link. Please contact us for assistance.' );
        }

        // Verify booking exists and token matches
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) {
            wp_die( 'Booking not found. Please contact us for assistance.' );
        }

        if ( empty( $booking->modification_token ) || ! hash_equals( $booking->modification_token, $token ) ) {
            wp_die( 'Invalid payment link. Please contact us for assistance.' );
        }

        // Allow payment if there's a balance due (regardless of status)
        $balance = (float) $booking->amount - (float) ( $booking->amount_paid ?? 0 );
        if ( $balance <= 0.01 || in_array( $booking->status, array( 'cancelled', 'archived' ) ) ) {
            wp_die( 'This booking is not available for payment. It may have already been paid or cancelled.' );
        }

        // Determine payment amount based on deposit settings
        $deposit_settings = MBS_Bookings::get_deposit_settings();
        $total_amount     = floatval( $booking->amount );
        $amount_paid      = floatval( $booking->amount_paid ?? 0 );

        if ( $amount_paid > 0 ) {
            // Pay whatever is still owed
            $amount = $total_amount - $amount_paid;
        } elseif ( $deposit_settings['enabled'] && ! MBS_Bookings::requires_full_payment( $booking->booking_date ) ) {
            // Pay deposit only
            $amount = MBS_Bookings::calculate_deposit( $total_amount );
        } else {
            // Full payment required
            $amount = $total_amount;
        }

        $amount = max( 0.01, round( $amount, 2 ) );

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
            'mbs_payment_type'   => ( $amount_paid > 0 ) ? 'balance' : ( ( $amount < $total_amount ) ? 'deposit' : 'full' ),
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
     * Set the correct price for the cart item — always from database to prevent tampering.
     */
    public static function set_cart_item_price( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

        foreach ( $cart->get_cart() as $cart_item ) {
            if ( isset( $cart_item['mbs_booking_ref'] ) ) {
                // SEC-004: Always read price from database, not cart session
                $booking = MBS_Bookings::get( $cart_item['mbs_booking_ref'] );
                if ( $booking ) {
                    $deposit_settings = MBS_Bookings::get_deposit_settings();
                    $total_amount     = (float) $booking->amount;
                    $amount_paid      = (float) ( $booking->amount_paid ?? 0 );

                    if ( $amount_paid > 0 ) {
                        // Pay whatever is still owed
                        $price = $total_amount - $amount_paid;
                    } elseif ( $deposit_settings['enabled'] && ! MBS_Bookings::requires_full_payment( $booking->booking_date ) ) {
                        // Deposit payment
                        $price = MBS_Bookings::calculate_deposit( $total_amount );
                    } else {
                        // Full payment
                        $price = $total_amount;
                    }

                    $cart_item['data']->set_price( max( 0.01, round( $price, 2 ) ) );
                }
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
     * When a WooCommerce order is completed/processing/paid, update the booking to "paid".
     * Guarded against duplicate processing via order meta flag.
     */
    public function on_order_completed( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Guard: skip if we've already processed this order
        if ( $order->get_meta( '_mbs_payment_processed' ) === 'yes' ) return;

        $processed_any = false;

        foreach ( $order->get_items() as $item ) {
            $ref = $item->get_meta( '_mbs_booking_ref' );
            if ( ! $ref ) continue;

            $booking = MBS_Bookings::get( $ref );
            if ( ! $booking ) continue;

            // Process payment if there's a balance due (any non-cancelled/archived status)
            $current_balance = (float) $booking->amount - (float) ( $booking->amount_paid ?? 0 );
            if ( $current_balance > 0.01 && ! in_array( $booking->status, array( 'cancelled', 'archived' ) ) ) {
                $deposit_settings = MBS_Bookings::get_deposit_settings();
                $order_total      = (float) $order->get_total();
                $booking_total    = (float) $booking->amount;
                $deposit_already  = (float) ( $booking->deposit_paid ?? 0 );

                // Determine if this is a deposit payment or full/balance payment
                if ( $booking->status === 'confirmed' && $deposit_settings['enabled']
                     && ! MBS_Bookings::requires_full_payment( $booking->booking_date )
                     && $order_total < $booking_total * 0.9 ) {
                    // This is a deposit payment
                    MBS_Bookings::update_status( $ref, 'deposit_paid' );
                    global $wpdb;
                    $table = $wpdb->prefix . MBS_TABLE;
                    $wpdb->update( $table, array( 'deposit_paid' => $order_total, 'amount_paid' => $order_total ), array( 'ref' => $ref ) );
                    MBS_Audit_Log::log( $ref, 'deposit_paid', 'Deposit of £' . number_format( $order_total, 2 ) . ' received via WooCommerce Order #' . $order_id . '.', 0 );
                    $order->add_order_note( sprintf( 'Mathlin Booking %s: Deposit of £%s received. Balance of £%s due before event.', $ref, number_format( $order_total, 2 ), number_format( $booking_total - $order_total, 2 ) ) );

                    // Send deposit received confirmation email
                    $updated_booking = MBS_Bookings::get( $ref );
                    if ( $updated_booking ) {
                        MBS_Email::notify_deposit_received( $updated_booking, $order_total );
                    }
                } else {
                    // Full payment or balance payment
                    MBS_Bookings::update_status( $ref, 'paid' );
                    $amount_paid_so_far = (float) ( $booking->amount_paid ?? 0 );
                    global $wpdb;
                    $table = $wpdb->prefix . MBS_TABLE;
                    $wpdb->update( $table, array(
                        'deposit_paid' => $deposit_already + $order_total,
                        'amount_paid'  => $amount_paid_so_far + $order_total,
                    ), array( 'ref' => $ref ) );
                    MBS_Audit_Log::log( $ref, 'paid', 'Payment received via WooCommerce Order #' . $order_id . '. Status updated to Paid.', 0 );
                    MBS_Email::notify_paid( $booking );
                    $order->add_order_note( sprintf( 'Mathlin Booking %s automatically marked as Paid.', $ref ) );
                }

                // Store order ID on the booking for cross-reference
                global $wpdb;
                $table = $wpdb->prefix . MBS_TABLE;
                $wpdb->update(
                    $table,
                    array( 'admin_notes' => trim( $booking->admin_notes . "\nPayment: WooCommerce Order #" . $order_id ) ),
                    array( 'ref' => $ref )
                );

                // Save booking ref as order-level meta for easier lookup
                $order->update_meta_data( '_mbs_booking_ref', $ref );

                $processed_any = true;

            } elseif ( $booking->status === 'cancelled' ) {
                // SEC-FIX-005: Payment received for a cancelled booking — flag for manual refund
                $order->add_order_note(
                    sprintf( '⚠️ CRITICAL: Payment received for CANCELLED booking %s. Manual refund required.', $ref ),
                    0, true // $is_customer_note = false, $added_by_user = true (shows prominently)
                );
                MBS_Audit_Log::log( $ref, 'payment_error', 'Payment received via WooCommerce Order #' . $order_id . ' for CANCELLED booking. Manual refund required.', 0 );
                error_log( "[Mathlin Booking] CRITICAL: Payment received for cancelled booking {$ref} (Order #{$order_id}). Manual refund required." );
                $processed_any = true;
            }
        }

        // Mark order as processed to prevent duplicate runs
        if ( $processed_any ) {
            $order->update_meta_data( '_mbs_payment_processed', 'yes' );
            $order->save();
        }
    }

    /**
     * When a WooCommerce order is refunded, revert the booking status to confirmed.
     * SEC-FIX-001: Handles the case where admin processes a refund directly in WooCommerce.
     */
    public function on_order_refunded( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        foreach ( $order->get_items() as $item ) {
            $ref = $item->get_meta( '_mbs_booking_ref' );
            if ( ! $ref ) continue;

            $booking = MBS_Bookings::get( $ref );
            if ( ! $booking ) continue;

            // Only revert if currently paid
            if ( $booking->status === 'paid' ) {
                global $wpdb;
                $table = $wpdb->prefix . MBS_TABLE;
                $wpdb->update( $table, array( 'status' => 'confirmed' ), array( 'ref' => $ref ) );

                MBS_Audit_Log::log( $ref, 'status_changed', 'Reverted to Confirmed: WooCommerce Order #' . $order_id . ' was refunded.', 0 );

                $order->add_order_note(
                    sprintf( 'Mathlin Booking %s reverted to Confirmed due to refund.', $ref )
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
                    echo '(' . esc_html( $booking->space ) . ' on ' . esc_html( wp_date( 'j F Y', strtotime( $booking->booking_date ) ) ) . ') ';
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
