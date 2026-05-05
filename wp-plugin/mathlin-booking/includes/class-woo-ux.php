<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WooCommerce UX Integration for Hirers & Booking Managers
 *
 * Cleans up the WooCommerce My Account experience for mbs_hirer role users:
 *   1. Redirects hirers to the Bookings Portal on login
 *   2. Redirects booking managers to wp-admin bookings page on login
 *   3. Adds a "My Hall Bookings" tab to the WooCommerce My Account menu
 *   4. Removes irrelevant tabs (Downloads, etc.) for hirers
 *   5. Prevents WooCommerce from blocking booking managers from wp-admin
 *   6. Auto-flushes rewrite rules on version change to register the endpoint
 */
class MBS_Woo_UX {

    public function init() {
        // Endpoint registration must happen on 'init' regardless of WooCommerce
        add_action( 'init', array( $this, 'register_endpoint' ) );

        // Auto-flush rewrite rules when plugin version changes
        add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 99 );

        if ( ! class_exists( 'WooCommerce' ) ) return;

        // Login redirects
        add_filter( 'woocommerce_login_redirect', array( $this, 'hirer_login_redirect' ), 10, 2 );
        add_filter( 'login_redirect',             array( $this, 'wp_login_redirect' ), 10, 3 );

        // Prevent WooCommerce from blocking booking managers from wp-admin
        add_filter( 'woocommerce_prevent_admin_access', array( $this, 'allow_manager_admin_access' ) );
        add_filter( 'woocommerce_disable_admin_bar',    array( $this, 'show_admin_bar_for_managers' ) );

        // WooCommerce My Account menu customisation
        add_filter( 'woocommerce_account_menu_items', array( $this, 'modify_account_menu' ), 20 );
        add_action( 'woocommerce_account_hall-bookings_endpoint', array( $this, 'render_bookings_tab' ) );

        // Set the endpoint title
        add_filter( 'the_title', array( $this, 'endpoint_title' ), 10, 2 );

        // Register the query var so WordPress recognises it
        add_filter( 'woocommerce_get_query_vars', array( $this, 'add_query_var' ) );
    }

    // ── 1. Smart Login Redirect ────────────────────────────────────────────────

    /**
     * WooCommerce-specific login redirect.
     */
    public function hirer_login_redirect( $redirect, $user ) {
        if ( $this->user_is_manager( $user ) ) {
            return admin_url( 'admin.php?page=mathlin-booking' );
        }
        if ( $this->user_is_hirer( $user ) ) {
            $portal_url = $this->get_portal_url();
            if ( $portal_url ) return $portal_url;
        }
        return $redirect;
    }

    /**
     * WordPress core login redirect.
     */
    public function wp_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
        if ( is_wp_error( $user ) || ! is_object( $user ) ) return $redirect_to;

        // Don't override if user explicitly requested a specific page (e.g. redirect_to param)
        if ( $requested_redirect_to && $requested_redirect_to !== admin_url() && $requested_redirect_to !== home_url() ) {
            return $redirect_to;
        }

        if ( $this->user_is_manager( $user ) ) {
            return admin_url( 'admin.php?page=mathlin-booking' );
        }
        if ( $this->user_is_hirer( $user ) ) {
            $portal_url = $this->get_portal_url();
            if ( $portal_url ) return $portal_url;
        }
        return $redirect_to;
    }

    // ── 2. WooCommerce Admin Access for Booking Managers ───────────────────────

    /**
     * Prevent WooCommerce from blocking booking managers from wp-admin.
     * WooCommerce redirects non-shop roles to My Account — we override that.
     */
    public function allow_manager_admin_access( $prevent_access ) {
        if ( current_user_can( 'mbs_manage_bookings' ) ) {
            return false; // Allow access
        }
        return $prevent_access;
    }

    /**
     * Show the WordPress admin bar for booking managers.
     * WooCommerce hides it for non-shop roles by default.
     */
    public function show_admin_bar_for_managers( $hide ) {
        if ( current_user_can( 'mbs_manage_bookings' ) ) {
            return false; // Don't hide
        }
        return $hide;
    }

    // ── 3. WooCommerce Account Menu: Add "My Hall Bookings" ────────────────────

    /**
     * Register the custom endpoint on 'init' so WordPress adds the rewrite rule.
     * This must fire early and unconditionally (not inside a WooCommerce hook).
     */
    public function register_endpoint() {
        add_rewrite_endpoint( 'hall-bookings', EP_ROOT | EP_PAGES );
    }

    /**
     * Register the query var with WooCommerce so it recognises the endpoint.
     */
    public function add_query_var( $vars ) {
        $vars['hall-bookings'] = 'hall-bookings';
        return $vars;
    }

    /**
     * Auto-flush rewrite rules when the plugin version changes.
     * This ensures the endpoint works immediately after update without
     * requiring a manual Permalinks save.
     */
    public function maybe_flush_rewrites() {
        $flushed_version = get_option( 'mbs_rewrite_flushed_version', '' );
        if ( $flushed_version !== MBS_VERSION ) {
            flush_rewrite_rules( false ); // false = don't write .htaccess (soft flush)
            update_option( 'mbs_rewrite_flushed_version', MBS_VERSION );
        }
    }

    /**
     * Modify the WooCommerce My Account menu items.
     */
    public function modify_account_menu( $items ) {
        if ( ! $this->current_user_is_hirer() && ! $this->current_user_is_manager() ) return $items;

        // Insert "My Hall Bookings" after Dashboard
        $new_items = array();
        foreach ( $items as $key => $label ) {
            $new_items[ $key ] = $label;
            if ( $key === 'dashboard' ) {
                $new_items['hall-bookings'] = 'My Hall Bookings';
            }
        }

        // Remove irrelevant tabs for hirers (managers may still want addresses for their own orders)
        if ( $this->current_user_is_hirer() && ! $this->current_user_is_manager() ) {
            $remove = array( 'downloads', 'edit-address' );
            foreach ( $remove as $key ) {
                unset( $new_items[ $key ] );
            }
        }

        return $new_items;
    }

    /**
     * Render the "My Hall Bookings" tab content.
     */
    public function render_bookings_tab() {
        if ( ! is_user_logged_in() ) return;
        include MBS_PLUGIN_DIR . 'public/views/hirer-dashboard.php';
    }

    /**
     * Set the page title when viewing the hall-bookings endpoint.
     */
    public function endpoint_title( $title, $id = null ) {
        global $wp_query;
        if ( is_main_query() && in_the_loop() && is_account_page() && isset( $wp_query->query_vars['hall-bookings'] ) ) {
            return 'My Hall Bookings';
        }
        return $title;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function user_is_hirer( $user ) {
        if ( ! $user || ! is_object( $user ) || ! isset( $user->roles ) ) return false;
        return in_array( 'mbs_hirer', (array) $user->roles, true );
    }

    private function user_is_manager( $user ) {
        if ( ! $user || ! is_object( $user ) ) return false;
        // Check for the capability (covers both mbs_booking_manager role and administrators)
        return user_can( $user, 'mbs_manage_bookings' );
    }

    private function current_user_is_hirer() {
        if ( ! is_user_logged_in() ) return false;
        $user = wp_get_current_user();
        return $this->user_is_hirer( $user );
    }

    private function current_user_is_manager() {
        if ( ! is_user_logged_in() ) return false;
        return current_user_can( 'mbs_manage_bookings' );
    }

    private function get_portal_url() {
        $pages = get_posts( array(
            'post_type'   => 'page',
            'post_status' => 'publish',
            's'           => 'mathlin_portal',
            'numberposts' => 1,
        ) );

        if ( ! empty( $pages ) ) {
            return get_permalink( $pages[0]->ID );
        }

        if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
            return wc_get_account_endpoint_url( 'hall-bookings' );
        }

        return '';
    }
}
