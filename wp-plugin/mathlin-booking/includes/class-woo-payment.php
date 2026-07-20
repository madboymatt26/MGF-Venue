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
        add_action( 'woocommerce_order_refunded',          array( $this, 'on_order_refunded' ), 10, 2 );
        add_action( 'woocommerce_order_status_failed',     array( 'MBS_Invoice_Reservation', 'release_order' ) );
        add_action( 'woocommerce_order_status_cancelled',  array( 'MBS_Invoice_Reservation', 'release_order' ) );
        add_action( 'mbs_release_invoice_reservation',      array( 'MBS_Invoice_Reservation', 'release_expired' ), 10, 2 );
        add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_invoice_checkout' ), 10, 2 );
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
        $product->set_name( 'Venue Booking Payment' );
        $product->set_slug( self::PRODUCT_SLUG );
        $product->set_status( 'publish' );           // Must be publish for guest checkout
        $product->set_catalog_visibility( 'hidden' ); // Hidden from shop/search/category pages
        $product->set_price( 0 );
        $product->set_regular_price( 0 );
        $product->set_sold_individually( true );
        $product->set_virtual( true );
        $product->set_tax_status( 'none' ); // Charity exempt
        $product->set_description( 'Payment for venue booking.' );
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

        // H-2: Don't generate a pay link if there's no outstanding balance
        // (fully paid, or overpaid/refund-due after a downward price change)
        $balance = (float) $booking->amount - (float) ( $booking->amount_paid ?? 0 );
        if ( $balance <= 0.01 ) return '';

        // B2B: Offline-invoicing tiers (BACS/PO) never get WooCommerce Pay Now links.
        // Returning empty here suppresses the button across ALL emails, since every
        // template guards on `if ( $pay_url )`.
        if ( MBS_Bookings::booking_is_offline( $booking ) ) return '';

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
        if ( isset( $_GET['mbs_invoice_pay'] ) && $_GET['mbs_invoice_pay'] === '1' ) {
            self::handle_invoice_payment_redirect();
            return;
        }
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

    /** Add one consolidated invoice balance to checkout. */
    private static function handle_invoice_payment_redirect() {
        if ( ! self::is_available() ) return;
        $invoice_ref = sanitize_text_field( $_GET['invoice_ref'] ?? '' );
        $token = sanitize_text_field( $_GET['invoice_token'] ?? '' );
        $invoice = MBS_Billing_Ledger::get_invoice( $invoice_ref );
        if ( ! $invoice || ! MBS_Invoice_Payment::verify_token( $invoice, $token ) ) {
            wp_die( 'Invalid invoice payment link. Please contact us for assistance.' );
        }
        $series = $invoice->series_ref ? MBS_Series::get( $invoice->series_ref ) : null;
        if ( $series && $series->payment_method === 'offline_bacs' ) {
            wp_die( 'This invoice is configured for BACS / Purchase Order payment.' );
        }
        $balance_minor = MBS_Billing_Ledger::balance_minor( $invoice );
        if ( ! MBS_Invoice_Payment::is_payable( $invoice ) ) {
            wp_die( 'This invoice is not available for payment. It may already be settled or void.' );
        }
        $session_key = 'mbs_invoice_reservation_' . substr( hash( 'sha256', $invoice->invoice_ref ), 0, 16 );
        $existing_reservation = WC()->session ? (string) WC()->session->get( $session_key, '' ) : '';
        $reservation = MBS_Invoice_Reservation::acquire( $invoice, $existing_reservation );
        if ( is_wp_error( $reservation ) ) wp_die( esc_html( $reservation->get_error_message() ) );
        if ( WC()->session ) WC()->session->set( $session_key, $reservation['reservation_ref'] );
        $decimal = MBS_Money::decimal( $balance_minor );
        if ( is_wp_error( $decimal ) ) wp_die( 'The invoice balance could not be prepared for checkout.' );

        WC()->cart->empty_cart();
        $product_id = self::get_payment_product_id();
        add_filter( 'woocommerce_product_get_price', function( $price, $product ) use ( $decimal, $product_id ) {
            return $product->get_id() == $product_id ? $decimal : $price;
        }, 10, 2 );
        WC()->cart->add_to_cart( $product_id, 1, 0, array(), array(
            'mbs_invoice_ref' => $invoice->invoice_ref,
            'mbs_invoice_amount_minor' => $balance_minor,
            'mbs_invoice_reservation_ref' => $reservation['reservation_ref'],
        ) );
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
        if ( isset( $cart_item['mbs_invoice_ref'] ) ) {
            $item_data[] = array( 'key' => 'Invoice Reference', 'value' => $cart_item['mbs_invoice_ref'] );
        }
        return $item_data;
    }

    /**
     * Set the correct price for the cart item — always from database to prevent tampering.
     */
    public static function set_cart_item_price( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( isset( $cart_item['mbs_invoice_ref'] ) ) {
                $invoice = MBS_Billing_Ledger::get_invoice( $cart_item['mbs_invoice_ref'] );
                if ( $invoice ) {
                    $balance_minor = MBS_Billing_Ledger::balance_minor( $invoice );
                    $decimal = MBS_Money::decimal( $balance_minor );
                    $reserved = MBS_Invoice_Reservation::validate( $invoice->invoice_ref, $cart_item['mbs_invoice_reservation_ref'] ?? '', $balance_minor );
                    if ( ! is_wp_error( $decimal ) && MBS_Invoice_Payment::is_payable( $invoice ) && $reserved ) {
                        $cart_item['data']->set_price( $decimal );
                    } else {
                        $cart->remove_cart_item( $cart_item_key );
                        if ( function_exists( 'wc_add_notice' ) ) wc_add_notice( 'This invoice checkout expired or its balance changed. Please open the payment link again.', 'error' );
                    }
                }
            } elseif ( isset( $cart_item['mbs_booking_ref'] ) ) {
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
        if ( isset( $values['mbs_invoice_ref'] ) ) {
            $item->add_meta_data( '_mbs_invoice_ref', $values['mbs_invoice_ref'], true );
            $item->add_meta_data( '_mbs_invoice_reservation_ref', $values['mbs_invoice_reservation_ref'] ?? '', true );
            $item->add_meta_data( '_mbs_invoice_amount_minor', (int) ( $values['mbs_invoice_amount_minor'] ?? 0 ), true );
            $order->update_meta_data( '_mbs_invoice_ref', $values['mbs_invoice_ref'] );
            $order->update_meta_data( '_mbs_invoice_reservation_ref', $values['mbs_invoice_reservation_ref'] ?? '' );
            $bound = MBS_Invoice_Reservation::bind_order( $values['mbs_invoice_ref'], $values['mbs_invoice_reservation_ref'] ?? '', $order->get_id() );
            if ( is_wp_error( $bound ) ) throw new Exception( $bound->get_error_message() );
        }
    }

    /** Reject stale, mismatched or second-session invoice carts before order creation. */
    public function validate_invoice_checkout( $data, $errors ) {
        if ( ! WC()->cart ) return;
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( empty( $cart_item['mbs_invoice_ref'] ) ) continue;
            $invoice = MBS_Billing_Ledger::get_invoice( $cart_item['mbs_invoice_ref'] );
            $balance = $invoice ? MBS_Billing_Ledger::balance_minor( $invoice ) : 0;
            if ( ! MBS_Invoice_Payment::is_payable( $invoice ) || ! MBS_Invoice_Reservation::validate( $cart_item['mbs_invoice_ref'], $cart_item['mbs_invoice_reservation_ref'] ?? '', $balance ) ) {
                $errors->add( 'mbs_invoice_reservation_invalid', 'This invoice is no longer reserved for this checkout. Please open its payment link again.' );
            }
        }
    }

    /**
     * When a WooCommerce order is completed/processing/paid, update the booking to "paid".
     * Guarded against duplicate processing via order meta flag.
     */
    public function on_order_completed( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Consolidated invoice orders use their own idempotent ledger path and
        // never fall through to legacy per-booking payment processing.
        $invoice_items_found = false;
        $invoice_processed = false;
        foreach ( $order->get_items() as $item ) {
            $invoice_ref = $item->get_meta( '_mbs_invoice_ref' );
            if ( ! $invoice_ref ) continue;
            $invoice_items_found = true;
            $reservation_ref = (string) $item->get_meta( '_mbs_invoice_reservation_ref' );
            $claimed_minor = (int) $item->get_meta( '_mbs_invoice_amount_minor' );
            if ( ! $reservation_ref || $claimed_minor < 1 || ! MBS_Invoice_Reservation::validate( $invoice_ref, $reservation_ref, $claimed_minor, $order_id ) ) {
                $order->update_meta_data( '_mbs_invoice_reconciliation_required', 'yes' );
                $order->update_meta_data( '_mbs_invoice_reconciliation_error', 'The captured order does not own the authoritative invoice reservation.' );
                $order->add_order_note( 'CRITICAL: Captured invoice payment failed reservation ownership validation; do not retry capture. Reconcile or refund this order.' );
                MBS_Audit_Log::log( $invoice_ref, 'payment_reconciliation_required', 'WooCommerce Order #' . $order_id . ' failed reservation ownership validation.', 0 );
                continue;
            }
            // Integration gateways may inject a deterministic pre-ledger
            // failure; production integrations should normally leave this null.
            $payment = apply_filters( 'mbs_invoice_gateway_payment_preflight', null, $invoice_ref, $order_id, $reservation_ref );
            if ( ! is_wp_error( $payment ) ) {
                $payment = MBS_Invoice_Payment::record_gateway_payment( $invoice_ref, $order->get_total(), $order_id, $reservation_ref );
            }
            if ( is_wp_error( $payment ) ) {
                if ( $reservation_ref ) MBS_Invoice_Reservation::reconciliation_required( $invoice_ref, $reservation_ref, $order_id, $payment->get_error_message() );
                $order->update_meta_data( '_mbs_invoice_reconciliation_required', 'yes' );
                $order->update_meta_data( '_mbs_invoice_reconciliation_error', $payment->get_error_message() );
                $order->add_order_note( 'CRITICAL: A captured payment requires reconciliation or a safe gateway refund.' );
                MBS_Audit_Log::log( $invoice_ref, 'payment_reconciliation_required', 'WooCommerce Order #' . $order_id . ' was captured but ledger recording failed: ' . $payment->get_error_message(), 0 );
                $order->add_order_note( '⚠️ Consolidated invoice payment could not be recorded: ' . $payment->get_error_message() );
                continue;
            }
            $completed = MBS_Invoice_Reservation::complete( $invoice_ref, $reservation_ref, $order_id );
            if ( ! $completed ) {
                $current_claim = MBS_Invoice_Reservation::get( $invoice_ref );
                $already_completed = $current_claim && $current_claim->status === 'captured'
                    && (int) $current_claim->order_id === (int) $order_id
                    && hash_equals( $current_claim->reservation_ref, $reservation_ref );
                if ( ! $already_completed ) {
                    $order->update_meta_data( '_mbs_invoice_reconciliation_required', 'yes' );
                    $order->update_meta_data( '_mbs_invoice_reconciliation_error', 'Ledger payment exists but the reservation could not enter captured state.' );
                    $order->add_order_note( 'CRITICAL: Ledger payment exists, but reservation finalisation requires administrator reconciliation.' );
                    MBS_Audit_Log::log( $invoice_ref, 'payment_reconciliation_required', 'Order #' . $order_id . ' ledger payment exists but reservation finalisation failed.', 0 );
                    continue;
                }
            }
            $order->update_meta_data( '_mbs_invoice_ref', $invoice_ref );
            $order->delete_meta_data( '_mbs_invoice_reconciliation_required' );
            $order->delete_meta_data( '_mbs_invoice_reconciliation_error' );
            $order->add_order_note( sprintf( 'MGF Venue invoice %s payment recorded in the consolidated ledger.', $invoice_ref ) );
            $invoice_processed = true;
        }
        if ( $invoice_items_found ) {
            if ( $invoice_processed ) {
                $order->update_meta_data( '_mbs_invoice_payment_processed', 'yes' );
                $pending_refunds = (array) $order->get_meta( '_mbs_pending_invoice_refunds', true );
                $order->delete_meta_data( '_mbs_pending_invoice_refunds' );
                $order->save();
                foreach ( array_unique( array_map( 'absint', $pending_refunds ) ) as $pending_refund_id ) if ( $pending_refund_id ) $this->on_order_refunded( $order_id, $pending_refund_id );
                return;
            }
            $order->save();
            return;
        }

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
                     && (float) ( $booking->amount_paid ?? 0 ) == 0
                     && ! MBS_Bookings::requires_full_payment( $booking->booking_date )
                     && $order_total < $booking_total * 0.9 ) {
                    // This is a deposit payment
                    MBS_Bookings::update_status( $ref, 'deposit_paid' );
                    global $wpdb;
                    $table = $wpdb->prefix . MBS_TABLE;
                    $wpdb->update( $table, array( 'deposit_paid' => $order_total, 'amount_paid' => $order_total ), array( 'ref' => $ref ) );
                    MBS_Audit_Log::log( $ref, 'deposit_paid', 'Deposit of £' . number_format( $order_total, 2 ) . ' received via WooCommerce Order #' . $order_id . '.', 0 );
                    $order->add_order_note( sprintf( 'MGF Venue booking %s: Deposit of £%s received. Balance of £%s due before event.', $ref, number_format( $order_total, 2 ), number_format( $booking_total - $order_total, 2 ) ) );

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
                    $order->add_order_note( sprintf( 'MGF Venue booking %s automatically marked as Paid.', $ref ) );
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
                error_log( "[MGF Venue] CRITICAL: Payment received for cancelled booking {$ref} (Order #{$order_id}). Manual refund required." );
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
    public function on_order_refunded( $order_id, $refund_id = 0 ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $invoice_refs = array();
        foreach ( $order->get_items() as $item ) {
            $invoice_ref = $item->get_meta( '_mbs_invoice_ref' );
            if ( $invoice_ref ) $invoice_refs[ $invoice_ref ] = true;
        }
        if ( $invoice_refs ) {
            $refunds = $refund_id ? array( wc_get_order( $refund_id ) ) : $order->get_refunds();
            foreach ( array_filter( $refunds ) as $refund ) {
                $amount = ltrim( (string) $refund->get_amount(), '-' );
                $requested_allocations = $refund->get_meta( '_mbs_invoice_refund_allocations', true );
                if ( is_string( $requested_allocations ) ) $requested_allocations = json_decode( $requested_allocations, true );
                if ( ! is_array( $requested_allocations ) ) $requested_allocations = array();
                foreach ( array_keys( $invoice_refs ) as $invoice_ref ) {
                    $result = MBS_Invoice_Payment::record_gateway_refund( $invoice_ref, $amount, $order_id, $refund->get_id(), $requested_allocations );
                    if ( is_wp_error( $result ) ) {
                        if ( in_array( $result->get_error_code(), array( 'refund_exceeds_paid', 'refund_payment_not_recorded', 'invoice_not_found' ), true ) ) {
                            $pending = (array) $order->get_meta( '_mbs_pending_invoice_refunds', true );
                            $pending[] = (int) $refund->get_id();
                            $order->update_meta_data( '_mbs_pending_invoice_refunds', array_values( array_unique( $pending ) ) );
                        }
                        $order->add_order_note( '⚠️ Consolidated invoice refund could not be recorded: ' . $result->get_error_message() );
                    } else {
                        $order->add_order_note( sprintf( 'Refund #%d recorded against consolidated invoice %s.', $refund->get_id(), $invoice_ref ) );
                    }
                }
            }
            $order->save();
            return;
        }

        foreach ( $order->get_items() as $item ) {
            $ref = $item->get_meta( '_mbs_booking_ref' );
            if ( ! $ref ) continue;

            $booking = MBS_Bookings::get( $ref );
            if ( ! $booking ) continue;

            // Only revert if currently paid
            if ( $booking->status === 'paid' ) {
                global $wpdb;
                $table = $wpdb->prefix . MBS_TABLE;
                // C-2: Reset access_sent so the (rotated) code is not considered "already issued".
                $wpdb->update( $table, array( 'status' => 'confirmed', 'access_sent' => 0 ), array( 'ref' => $ref ) );

                MBS_Audit_Log::log( $ref, 'status_changed', 'Reverted to Confirmed: WooCommerce Order #' . $order_id . ' was refunded. Access flag reset.', 0 );

                $order->add_order_note(
                    sprintf( 'MGF Venue booking %s reverted to Confirmed due to refund. Access code flag reset.', $ref )
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
            $invoice_ref = $item->get_meta( '_mbs_invoice_ref' );
            if ( $invoice_ref ) {
                echo '<div style="background:#d1fae5;border:1px solid #2ecc71;border-radius:8px;padding:16px 20px;margin:16px 0;">';
                echo '<h3 style="color:#065f46;margin:0 0 8px;">✅ Invoice Payment Received</h3>';
                echo '<p style="margin:0;">Your payment for consolidated invoice <strong>' . esc_html( $invoice_ref ) . '</strong> has been received. Thank you!</p>';
                echo '</div>';
                continue;
            }
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
