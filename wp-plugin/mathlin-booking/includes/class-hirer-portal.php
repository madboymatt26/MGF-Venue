<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Hirer Portal — customer accounts for regular hirers.
 *
 * Creates a 'mbs_hirer' WordPress user role with limited capabilities.
 * Provides a frontend portal where hirers can:
 *   - View all their bookings
 *   - See invoice details and payment status
 *   - Make new bookings with pre-filled details
 *   - Request modifications
 *
 * Shortcode: [mathlin_portal]
 */
class MBS_Hirer_Portal {

    const ROLE = 'mbs_hirer';
    const MANAGER_ROLE = 'mbs_booking_manager';

    public function init() {
        add_action( 'init', array( $this, 'register_roles' ) );
        add_shortcode( 'mathlin_portal', array( $this, 'shortcode_portal' ) );
        add_action( 'wp_ajax_mbs_hirer_register',    array( $this, 'ajax_register' ) );
        add_action( 'wp_ajax_nopriv_mbs_hirer_register', array( $this, 'ajax_register' ) );
        add_action( 'wp_ajax_mbs_hirer_login',       array( $this, 'ajax_login' ) );
        add_action( 'wp_ajax_nopriv_mbs_hirer_login', array( $this, 'ajax_login' ) );

        // Link bookings to user accounts by email
        add_action( 'user_register', array( $this, 'link_existing_bookings' ) );
    }

    /**
     * Register roles on plugin init.
     */
    public function register_roles() {
        // Hirer role — public users who book the hall
        if ( ! get_role( self::ROLE ) ) {
            add_role( self::ROLE, 'Venue Hirer', array(
                'read' => true,
            ) );
        }

        // Booking Manager role — volunteers who manage bookings but aren't full admins
        if ( ! get_role( self::MANAGER_ROLE ) ) {
            add_role( self::MANAGER_ROLE, 'Booking Manager', array(
                'read'                 => true,
                'mbs_manage_bookings'  => true,
            ) );
        }

        // Ensure admins also have the booking capability
        $admin_role = get_role( 'administrator' );
        if ( $admin_role && ! $admin_role->has_cap( 'mbs_manage_bookings' ) ) {
            $admin_role->add_cap( 'mbs_manage_bookings' );
        }
    }

    /**
     * Remove the role on plugin deactivation.
     */
    public static function deactivate() {
        remove_role( self::ROLE );
        remove_role( self::MANAGER_ROLE );
        $admin_role = get_role( 'administrator' );
        if ( $admin_role ) $admin_role->remove_cap( 'mbs_manage_bookings' );
    }

    /**
     * Portal shortcode — shows login/register or the hirer dashboard.
     */
    public function shortcode_portal( $atts ) {
        ob_start();

        if ( is_user_logged_in() ) {
            include MBS_PLUGIN_DIR . 'public/views/hirer-dashboard.php';
        } else {
            include MBS_PLUGIN_DIR . 'public/views/hirer-login.php';
        }

        return ob_get_clean();
    }

    /**
     * Get all bookings for a specific email address.
     */
    public static function get_bookings_for_email( $email ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE email = %s AND status != 'archived' ORDER BY booking_date DESC",
            $email
        ) );
    }

    public static function get_series_for_email( $email ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_SERIES_TABLE;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE contact_email = %s ORDER BY start_date DESC",
            sanitize_email( $email )
        ) );
    }

    public static function get_invoices_for_email( $email ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_INVOICE_TABLE;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE contact_email = %s AND document_type = 'invoice' ORDER BY period_start DESC, created_at DESC",
            sanitize_email( $email )
        ) );
    }

    public static function invoice_transactions( $invoice_id ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_PAYMENT_TRANSACTION_TABLE;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT transaction_ref, provider, transaction_type, status, amount_minor, currency, occurred_at FROM {$table} WHERE invoice_id = %d ORDER BY occurred_at DESC, id DESC",
            (int) $invoice_id
        ) );
    }

    /**
     * Get hirer stats for their dashboard.
     */
    public static function get_hirer_stats( $email ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;

        // Consolidated invoices are authoritative once an occurrence has ever
        // been allocated. Count completed ledger payments minus refunds once,
        // then add only genuinely legacy booking payments.
        $invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
        $transaction_table = $wpdb->prefix . MBS_PAYMENT_TRANSACTION_TABLE;
        $allocation_table = $wpdb->prefix . MBS_BILLING_ALLOCATION_TABLE;
        $invoice_paid_minor = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(CASE WHEN t.transaction_type = 'payment' THEN t.amount_minor ELSE -t.amount_minor END), 0)
             FROM {$transaction_table} t INNER JOIN {$invoice_table} i ON i.id = t.invoice_id
             WHERE i.contact_email = %s AND i.document_type = 'invoice' AND t.status = 'completed'",
            sanitize_email( $email )
        ) );
        $legacy_paid_decimal = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(CASE WHEN b.amount_paid > 0 THEN b.amount_paid WHEN b.status = 'paid' THEN b.amount ELSE 0 END), 0)
             FROM {$table} b LEFT JOIN {$allocation_table} a ON a.booking_ref = b.ref
             WHERE b.email = %s AND a.id IS NULL",
            sanitize_email( $email )
        ) );
        $legacy_paid_minor = MBS_Money::from_decimal_string( $legacy_paid_decimal );
        if ( is_wp_error( $legacy_paid_minor ) ) $legacy_paid_minor = 0;

        return array(
            'total'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email = %s AND status != 'archived'", $email ) ),
            'upcoming'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email = %s AND booking_date >= CURDATE() AND status IN ('confirmed', 'deposit_paid', 'paid')", $email ) ),
            'pending'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email = %s AND status = 'pending'", $email ) ),
            'total_spent_minor' => max( 0, $invoice_paid_minor + $legacy_paid_minor ),
        );
    }

    /**
     * AJAX: Register a new hirer account.
     */
    public function ajax_register() {
        check_ajax_referer( 'mbs_public_nonce', 'nonce' );

        // SEC-005: Rate limit registrations by IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $rate_key = 'mbs_reg_' . md5( $ip );
        $attempts = (int) get_transient( $rate_key );
        if ( $attempts >= 5 ) {
            wp_send_json_error( array( 'message' => 'Too many registration attempts. Please try again in an hour.' ) );
        }
        set_transient( $rate_key, $attempts + 1, 3600 );

        $name  = sanitize_text_field( $_POST['name'] ?? '' );
        $email = sanitize_email( $_POST['email'] ?? '' );
        $phone = sanitize_text_field( $_POST['phone'] ?? '' );
        $pass  = $_POST['password'] ?? '';

        if ( ! $name || ! $email || ! $pass ) {
            wp_send_json_error( array( 'message' => 'Please fill in all required fields.' ) );
        }
        if ( ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => 'Please enter a valid email address.' ) );
        }
        if ( strlen( $pass ) < 8 ) {
            wp_send_json_error( array( 'message' => 'Password must be at least 8 characters.' ) );
        }
        if ( email_exists( $email ) ) {
            wp_send_json_error( array( 'message' => 'Unable to create account. If you already have an account, please log in instead.' ) );
        }

        $user_id = wp_create_user( $email, $pass, $email );
        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
        }

        // Set role and meta
        $user = new WP_User( $user_id );
        $user->set_role( self::ROLE );
        update_user_meta( $user_id, 'first_name', $name );
        update_user_meta( $user_id, 'mbs_phone', $phone );
        update_user_meta( $user_id, 'mbs_organisation', sanitize_text_field( $_POST['organisation'] ?? '' ) );

        // Auto-login
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id );

        // Link any existing bookings
        $this->link_existing_bookings( $user_id );

        wp_send_json_success( array( 'message' => 'Account created! Redirecting…' ) );
    }

    /**
     * AJAX: Log in an existing hirer.
     */
    public function ajax_login() {
        check_ajax_referer( 'mbs_public_nonce', 'nonce' );

        $email = sanitize_email( $_POST['email'] ?? '' );
        $pass  = $_POST['password'] ?? '';

        if ( ! $email || ! $pass ) {
            wp_send_json_error( array( 'message' => 'Please enter your email and password.' ) );
        }

        $user = wp_authenticate( $email, $pass );
        if ( is_wp_error( $user ) ) {
            wp_send_json_error( array( 'message' => 'Invalid email or password.' ) );
        }

        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID );

        wp_send_json_success( array( 'message' => 'Logged in! Redirecting…' ) );
    }

    /**
     * Link existing bookings to a newly registered user by email.
     * SEC-FIX-004: Only links for users with the mbs_hirer role to prevent
     * admin-created users from inadvertently gaining access to another person's bookings.
     */
    public function link_existing_bookings( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        // Only link bookings for hirer role users
        if ( ! in_array( self::ROLE, (array) $user->roles, true ) ) return;

        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        $wpdb->update(
            $table,
            array( 'user_id' => $user_id ),
            array( 'email' => $user->user_email ),
            array( '%d' ), array( '%s' )
        );
    }

    /**
     * Get the current hirer's details for pre-filling forms.
     */
    public static function get_hirer_details() {
        if ( ! is_user_logged_in() ) return null;

        $user = wp_get_current_user();
        return array(
            'name'         => get_user_meta( $user->ID, 'first_name', true ) ?: $user->display_name,
            'email'        => $user->user_email,
            'phone'        => get_user_meta( $user->ID, 'mbs_phone', true ),
            'organisation' => get_user_meta( $user->ID, 'mbs_organisation', true ),
            'address'      => get_user_meta( $user->ID, 'mbs_address', true ),
        );
    }
}
