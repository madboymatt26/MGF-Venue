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
        add_action( 'init', array( $this, 'register_role' ) );
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

    /**
     * Get hirer stats for their dashboard.
     */
    public static function get_hirer_stats( $email ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;

        return array(
            'total'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email = %s AND status != 'archived'", $email ) ),
            'upcoming'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email = %s AND booking_date >= CURDATE() AND status IN ('confirmed', 'paid')", $email ) ),
            'pending'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email = %s AND status = 'pending'", $email ) ),
            'total_spent' => (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE email = %s AND status IN ('confirmed', 'paid')", $email ) ),
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
            wp_send_json_error( array( 'message' => 'An account with this email already exists. Please log in instead.' ) );
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
     */
    public function link_existing_bookings( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;

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
