<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MBS_Admin {

    public function init() {
        add_action( 'admin_menu',            array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_mbs_update_status',  array( $this, 'ajax_update_status' ) );
        add_action( 'wp_ajax_mbs_delete_booking', array( $this, 'ajax_delete_booking' ) );
        add_action( 'wp_ajax_mbs_get_invoice',    array( $this, 'ajax_get_invoice' ) );
        add_action( 'wp_ajax_mbs_save_settings',  array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_mbs_test_ha',        array( $this, 'ajax_test_ha' ) );
    }

    // ── Menu ───────────────────────────────────────────────────────────────────
    public function add_menu() {
        add_menu_page(
            'Scout Bookings',
            'Scout Bookings',
            'manage_options',
            'mathlin-booking',
            array( $this, 'render_dashboard' ),
            'dashicons-calendar-alt',
            30
        );
        add_submenu_page(
            'mathlin-booking',
            'All Bookings',
            'All Bookings',
            'manage_options',
            'mathlin-booking',
            array( $this, 'render_dashboard' )
        );
        add_submenu_page(
            'mathlin-booking',
            'Settings',
            'Settings',
            'manage_options',
            'mathlin-settings',
            array( $this, 'render_settings' )
        );
    }

    // ── Assets ─────────────────────────────────────────────────────────────────
    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'mathlin' ) === false ) return;
        wp_enqueue_style(  'mbs-admin', MBS_PLUGIN_URL . 'admin/admin.css', array(), MBS_VERSION );
        wp_enqueue_script( 'mbs-admin', MBS_PLUGIN_URL . 'admin/admin.js',  array( 'jquery' ), MBS_VERSION, true );
        wp_localize_script( 'mbs-admin', 'MBS_Admin', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'mbs_admin_nonce' ),
        ) );
    }

    // ── Dashboard ──────────────────────────────────────────────────────────────
    public function render_dashboard() {
        $action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'list';
        $ref    = isset( $_GET['ref'] )    ? sanitize_text_field( $_GET['ref'] )    : '';

        if ( $action === 'view' && $ref ) {
            $this->render_single( $ref );
            return;
        }
        if ( $action === 'invoice' && $ref ) {
            $this->render_invoice_page( $ref );
            return;
        }
        $this->render_list();
    }

    private function render_list() {
        $stats    = MBS_Bookings::get_stats();
        $status   = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
        $search   = isset( $_GET['s'] )      ? sanitize_text_field( $_GET['s'] )      : '';
        $bookings = MBS_Bookings::get_all( array( 'status' => $status, 'search' => $search, 'orderby' => 'booking_date', 'order' => 'ASC' ) );
        include MBS_PLUGIN_DIR . 'admin/views/list.php';
    }

    private function render_single( $ref ) {
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) {
            echo '<div class="notice notice-error"><p>Booking not found.</p></div>';
            return;
        }
        include MBS_PLUGIN_DIR . 'admin/views/single.php';
    }

    private function render_invoice_page( $ref ) {
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) {
            echo '<div class="notice notice-error"><p>Booking not found.</p></div>';
            return;
        }
        include MBS_PLUGIN_DIR . 'admin/views/invoice.php';
    }

    public function render_settings() {
        include MBS_PLUGIN_DIR . 'admin/views/settings.php';
    }

    // ── AJAX handlers ──────────────────────────────────────────────────────────
    public function ajax_update_status() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $ref    = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $status = sanitize_text_field( $_POST['status'] ?? '' );

        $result = MBS_Bookings::update_status( $ref, $status );

        if ( $status === 'confirmed' ) {
            $booking = MBS_Bookings::get( $ref );
            if ( $booking ) MBS_Email::notify_confirmed( $booking );
        }

        wp_send_json_success( array( 'ref' => $ref, 'status' => $status ) );
    }

    public function ajax_delete_booking() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $ref    = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $result = MBS_Bookings::delete( $ref );
        wp_send_json_success( array( 'deleted' => $ref ) );
    }

    public function ajax_get_invoice() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $ref     = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) wp_send_json_error( 'Not found' );

        wp_send_json_success( array( 'html' => MBS_Invoice::generate_html( $booking ) ) );
    }

    public function ajax_save_settings() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $webhook      = esc_url_raw( $_POST['ha_webhook_url'] ?? '' );
        $notice_days  = absint( $_POST['min_notice_days'] ?? 1 );

        // Clamp to a sensible range: 0 = same day allowed, 30 = max notice required
        $notice_days = max( 0, min( 30, $notice_days ) );

        update_option( 'mbs_ha_webhook_url',  $webhook );
        update_option( 'mbs_min_notice_days', $notice_days );

        wp_send_json_success( array( 'saved' => true, 'min_notice_days' => $notice_days ) );
    }

    public function ajax_test_ha() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $webhook_url = get_option( 'mbs_ha_webhook_url', '' );
        if ( empty( $webhook_url ) ) {
            wp_send_json_error( 'No webhook URL configured.' );
        }

        $payload = array(
            'event'        => 'test',
            'message'      => 'Test from Needham Market Scout Group booking system',
            'timestamp'    => current_time( 'c' ),
        );

        $response = wp_remote_post( $webhook_url, array(
            'method'  => 'POST',
            'timeout' => 10,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        wp_send_json_success( array( 'http_code' => $code ) );
    }
}
