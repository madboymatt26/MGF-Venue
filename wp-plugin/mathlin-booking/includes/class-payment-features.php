<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Site-wide financial feature policy. Switching off never deletes ledger history. */
class MBS_Payment_Features {
    public static function definitions() {
        return array( 'payments' => 'Payments and billing', 'invoices' => 'Invoices and series billing', 'additional_charges' => 'Additional charges', 'online' => 'Online checkout and payment links', 'manual' => 'Manual payment and refund recording', 'reminders' => 'Payment reminders and chasing', 'reports' => 'Financial reports, exports and OSM accounting' );
    }
    public static function enabled( $feature = 'payments' ) {
        $default = ! ( class_exists( 'MBS_Campsite_Policy' ) && MBS_Campsite_Policy::enabled() );
        if ( ! (bool) get_option( 'mbs_payments_enabled', $default ) ) return false;
        if ( $feature === 'payments' ) return true;
        if ( ! array_key_exists( $feature, self::definitions() ) ) return false;
        if ( $feature === 'additional_charges' && ! self::enabled( 'invoices' ) ) return false;
        return (bool) get_option( 'mbs_payment_feature_' . $feature, true );
    }
    public static function free_hire() { return ! self::enabled() || ( class_exists( 'MBS_Campsite_Policy' ) && MBS_Campsite_Policy::enabled() ); }
    public static function error( $feature = 'payments' ) { return new WP_Error( 'payment_feature_disabled', ( self::definitions()[$feature] ?? 'This payment feature' ) . ' is disabled in MGF Venue Settings.', array( 'status' => 409 ) ); }
    public static function state() {
        $state = array();
        foreach ( self::definitions() as $feature => $label ) $state[$feature] = array( 'label' => $label, 'enabled' => self::enabled( $feature ), 'configured' => (bool) get_option( $feature === 'payments' ? 'mbs_payments_enabled' : 'mbs_payment_feature_' . $feature, $feature === 'payments' ? ! ( class_exists( 'MBS_Campsite_Policy' ) && MBS_Campsite_Policy::enabled() ) : true ) );
        return $state;
    }
    public static function save( $data ) {
        if ( ! is_array( $data ) ) return new WP_Error( 'invalid_payment_features', 'Payment features must be an object.' );
        $values = array();
        foreach ( self::definitions() as $feature => $label ) {
            $key = $feature === 'payments' ? 'payments_enabled' : 'payment_feature_' . $feature;
            if ( ! array_key_exists( $key, $data ) ) continue;
            if ( ! in_array( $data[$key], array( true, false, 0, 1, '0', '1' ), true ) ) return new WP_Error( 'invalid_payment_feature', 'Payment switches must be enabled or disabled.' );
            $values['mbs_' . $key] = in_array( $data[$key], array( true, 1, '1' ), true ) ? 1 : 0;
        }
        foreach ( $values as $key => $value ) update_option( $key, $value, false );
        return self::state();
    }
    public function init() {
        add_action( 'admin_init', array( $this, 'guard_ajax' ), 1 );
        add_action( 'wp_ajax_mbs_save_payment_features', array( $this, 'ajax_save' ) );
        add_action( 'rest_api_init', function() {
            register_rest_route( 'mathlin/v1', '/admin/payment-features', array(
                array( 'methods' => 'GET', 'callback' => function() { return rest_ensure_response( self::state() ); }, 'permission_callback' => function() { return current_user_can( 'manage_options' ); } ),
                array( 'methods' => 'POST', 'callback' => function( $request ) { $result = self::save( $request->get_json_params() ?: array() ); return is_wp_error( $result ) ? $result : rest_ensure_response( $result ); }, 'permission_callback' => function() { return current_user_can( 'manage_options' ); } ),
            ) );
        } );
        add_filter( 'rest_pre_dispatch', array( $this, 'guard_rest' ), 9, 3 );
    }
    public function ajax_save() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Administrator access required.', 403 );
        $result = self::save( wp_unslash( $_POST ) );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message(), 400 );
        wp_send_json_success( $result );
    }
    public static function action_feature( $action, $data = array() ) {
        if ( $action === 'manage_additional_charge' ) return 'additional_charges';
        if ( in_array( $action, array( 'mark_refunded', 'mark_deposit_paid', 'undo_deposit', 'record_invoice_manual_payment' ), true ) ) return 'manual';
        if ( $action === 'chase_payment' ) return 'reminders';
        if ( in_array( $action, array( 'catch_up_series_billing', 'configure_series_billing', 'pause_series' ), true ) ) {
            if ( $action === 'configure_series_billing' && ( $data['billing_mode'] ?? '' ) === 'none' && ( $data['billing_treatment'] ?? '' ) === 'none' ) return '';
            return 'invoices';
        }
        if ( $action === 'bulk_action' && in_array( $data['bulk_action'] ?? '', array( 'paid', 'deposit_paid' ), true ) ) return 'manual';
        if ( in_array( $action, array( 'update_status', 'restore_booking' ), true ) && in_array( $data['status'] ?? '', array( 'paid', 'deposit_paid', 'unpaid' ), true ) ) return 'manual';
        if ( strpos( $action, 'osm_' ) === 0 || in_array( $action, array( 'save_osm_settings', 'test_osm_connection' ), true ) ) return 'reports';
        if ( $action === 'export_accounting' ) return 'reports';
        return '';
    }
    public function guard_ajax() {
        if ( ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax() ) return;
        $action = preg_replace( '/^mbs_/', '', sanitize_key( $_REQUEST['action'] ?? '' ) );
        $feature = self::action_feature( $action, $_REQUEST );
        if ( $feature && ! self::enabled( $feature ) ) wp_send_json_error( self::error( $feature )->get_error_message(), 409 );
    }
    public function guard_rest( $result, $server, $request ) {
        $route = $request->get_route(); $method = $request->get_method();
        if ( strpos( $route, '/mathlin/v1/' ) !== 0 ) return $result;
        $feature = '';
        if ( $method !== 'GET' && strpos( $route, '/admin/additional-charges' ) !== false ) $feature = 'additional_charges';
        if ( $method !== 'GET' && preg_match( '#/admin/invoices/[^/]+/payments$#', $route ) ) $feature = 'manual';
        if ( strpos( $route, '/payment-url' ) !== false ) $feature = 'online';
        if ( $method !== 'GET' && preg_match( '#/bookings/[^/]+/status$#', $route ) ) $feature = self::action_feature( 'update_status', $request->get_params() );
        // pre_dispatch runs before WordPress attaches regex URL parameters.
        if ( preg_match( '#/admin/actions/([a-z_]+)$#', $route, $matches ) ) { $data = $request->get_json_params(); if ( ! is_array( $data ) ) $data = $request->get_params(); $feature = self::action_feature( $matches[1], $data ); }
        return $feature && ! self::enabled( $feature ) ? self::error( $feature ) : $result;
    }
}
