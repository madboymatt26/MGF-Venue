<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Payout-aware OSM accountancy bridge.
 *
 * The imported bank statement is authoritative. WooPayments settlements can
 * combine venue invoices, shop sales, refunds and fees, so individual card
 * payments are queued and later consolidated into exactly one cashbook record
 * attached to exactly one existing OSM bank transaction.
 */
class MBS_OSM_Integration {
    const OSM_DOMAIN = 'https://www.onlinescoutmanager.co.uk';
    const OSM_TOKEN = 'https://www.onlinescoutmanager.co.uk/oauth/token';
    const GWC_SLUG = 'gilbertweb-connector-waiting-list-manager';

    public function init() {
        add_action( 'init', array( __CLASS__, 'recover_stale_claims' ) );
        add_action( 'admin_notices', array( __CLASS__, 'outbox_health_notice' ) );
        add_action( 'wp_ajax_mbs_save_osm_settings', array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_mbs_test_osm_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_mbs_osm_get_sections', array( $this, 'ajax_get_sections' ) );
        add_action( 'wp_ajax_mbs_osm_discover', array( $this, 'ajax_discover' ) );
        add_action( 'wp_ajax_mbs_osm_sync_woopayments', array( $this, 'ajax_sync_woopayments' ) );
        add_action( 'wp_ajax_mbs_osm_retry_event', array( $this, 'ajax_retry_event' ) );
        add_action( 'wp_ajax_mbs_osm_resolve_event', array( $this, 'ajax_resolve_event' ) );
    }

    public static function get_settings() {
        $client_id = defined( 'MBS_OSM_CLIENT_ID' ) ? MBS_OSM_CLIENT_ID : get_option( 'mbs_osm_client_id', '' );
        $client_secret = defined( 'MBS_OSM_CLIENT_SECRET' ) ? MBS_OSM_CLIENT_SECRET : get_option( 'mbs_osm_client_secret', '' );
        $legacy_enabled = (bool) get_option( 'mbs_osm_enabled', false );
        $v2_configured = (string) get_option( 'mbs_osm_configuration_version', '' ) === '2';
        return array(
            'enabled' => $v2_configured && $legacy_enabled,
            'upgrade_required' => ! $v2_configured && $legacy_enabled,
            'sandbox_mode' => (bool) get_option( 'mbs_osm_sandbox_mode', true ),
            'auth_source' => sanitize_key( get_option( 'mbs_osm_auth_source', 'gilbertweb' ) ),
            'client_id' => (string) $client_id,
            'client_secret' => (string) $client_secret,
            'section_id' => (string) get_option( 'mbs_osm_section_id', '' ),
            'bank_account_id' => (string) get_option( 'mbs_osm_bank_account_id', get_option( 'mbs_osm_account_id', '' ) ),
            'venue_category_id' => (string) get_option( 'mbs_osm_venue_category_id', get_option( 'mbs_osm_category_id', '' ) ),
            'venue_item_id' => (string) get_option( 'mbs_osm_venue_item_id', '' ),
            'clothing_category_id' => (string) get_option( 'mbs_osm_clothing_category_id', '' ),
            'clothing_item_id' => (string) get_option( 'mbs_osm_clothing_item_id', '' ),
            'fees_category_id' => (string) get_option( 'mbs_osm_fees_category_id', '' ),
            'fees_item_id' => (string) get_option( 'mbs_osm_fees_item_id', '' ),
            'product_mappings' => self::decode_mappings( get_option( 'mbs_osm_product_mappings', '[]' ) ),
            'description_tpl' => $v2_configured ? (string) get_option( 'mbs_osm_description_template', 'WooPayments payout {payout_id}' ) : 'WooPayments payout {payout_id}',
            'match_days' => max( 0, min( 7, (int) get_option( 'mbs_osm_match_days', 3 ) ) ),
        );
    }

    private static function decode_mappings( $value ) {
        if ( is_array( $value ) ) return $value;
        $decoded = json_decode( (string) $value, true );
        return is_array( $decoded ) ? $decoded : array();
    }

    private static function token_from_value( $value ) {
        if ( is_object( $value ) && ! empty( $value->access_token ) ) return (string) $value->access_token;
        if ( is_array( $value ) && ! empty( $value['access_token'] ) ) return (string) $value['access_token'];
        return '';
    }

    /** Deprecated read-only compatibility; this plugin never refreshes another plugin's token. */
    public static function gilbertweb_available() {
        $expiry = (int) get_option( self::GWC_SLUG . '_osm_access_token_expiry', 0 );
        return self::token_from_value( get_option( self::GWC_SLUG . '_osm_access_token_data' ) ) !== '' && ( ! $expiry || $expiry > time() + 30 );
    }

    private static function get_access_token( $force_refresh = false ) {
        $filtered = apply_filters( 'mbs_osm_access_token', '', $force_refresh );
        if ( is_string( $filtered ) && $filtered !== '' ) return $filtered;
        $settings = self::get_settings();
        if ( $settings['auth_source'] === 'gilbertweb' ) {
            if ( $force_refresh || ! self::gilbertweb_available() ) return '';
            return self::token_from_value( get_option( self::GWC_SLUG . '_osm_access_token_data' ) );
        }
        $stored = get_option( 'mbs_osm_access_token_data' );
        $expiry = (int) get_option( 'mbs_osm_access_token_expiry', 0 );
        if ( ! $force_refresh && self::token_from_value( $stored ) !== '' && ( ! $expiry || $expiry > time() + 60 ) ) return self::token_from_value( $stored );
        return self::refresh_standalone_token();
    }

    private static function refresh_standalone_token() {
        $settings = self::get_settings();
        if ( $settings['client_id'] === '' || $settings['client_secret'] === '' ) return '';
        $body = array(
            'grant_type' => 'client_credentials', 'client_id' => $settings['client_id'],
            'client_secret' => $settings['client_secret'],
            'scope' => 'section:finance:read section:finance:write',
        );
        $refresh = (string) get_option( 'mbs_osm_refresh_token', '' );
        if ( $refresh !== '' ) { $body['grant_type'] = 'refresh_token'; $body['refresh_token'] = $refresh; }
        $response = wp_remote_post( self::OSM_TOKEN, array( 'timeout' => 30, 'headers' => array( 'Accept' => 'application/json' ), 'body' => $body ) );
        $parsed = self::parse_http_response( $response );
        if ( is_wp_error( $parsed ) && $refresh !== '' ) {
            unset( $body['refresh_token'] ); $body['grant_type'] = 'client_credentials';
            $parsed = self::parse_http_response( wp_remote_post( self::OSM_TOKEN, array( 'timeout' => 30, 'headers' => array( 'Accept' => 'application/json' ), 'body' => $body ) ) );
        }
        if ( is_wp_error( $parsed ) || empty( $parsed['access_token'] ) ) return '';
        update_option( 'mbs_osm_access_token_data', $parsed, false );
        update_option( 'mbs_osm_access_token_expiry', time() + max( 60, (int) ( $parsed['expires_in'] ?? 3600 ) ), false );
        if ( ! empty( $parsed['refresh_token'] ) ) update_option( 'mbs_osm_refresh_token', (string) $parsed['refresh_token'], false );
        return (string) $parsed['access_token'];
    }

    /** Reject OSM's HTTP-200 HTML anti-abuse response as an ambiguous failure. */
    private static function parse_http_response( $response ) {
        if ( is_wp_error( $response ) ) return new WP_Error( 'osm_network_ambiguous', $response->get_error_message(), array( 'response_code' => 0, 'ambiguous' => true ) );
        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw = (string) wp_remote_retrieve_body( $response );
        $content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
        $trimmed = ltrim( $raw );
        if ( strpos( $content_type, 'text/html' ) !== false || ( $trimmed !== '' && $trimmed[0] === '<' ) ) return new WP_Error( 'unexpected_html_response', 'OSM returned HTML. Stop and retry later; this can indicate soft rate limiting.', array( 'response_code' => $code, 'ambiguous' => $code >= 200 && $code < 300 ) );
        $data = $trimmed === '' ? array() : json_decode( $raw, true );
        if ( $trimmed !== '' && json_last_error() !== JSON_ERROR_NONE ) return new WP_Error( 'osm_invalid_json', 'OSM returned a non-JSON response.', array( 'response_code' => $code, 'ambiguous' => $code >= 200 && $code < 300 ) );
        if ( $code < 200 || $code >= 300 ) {
            $message = is_array( $data ) ? ( $data['message'] ?? $data['error_description'] ?? $data['error'] ?? '' ) : '';
            return new WP_Error( $code === 429 ? 'osm_rate_limited' : 'osm_http_error', $message !== '' ? sanitize_text_field( $message ) : 'OSM API returned HTTP ' . $code . '.', array( 'response_code' => $code, 'retry_after' => (int) wp_remote_retrieve_header( $response, 'retry-after' ) ) );
        }
        return is_array( $data ) ? $data : array();
    }

    private static function api_call( $method, $path, $body = array(), $refreshed = false ) {
        if ( strpos( $path, '/' ) !== 0 || strpos( $path, '//' ) === 0 ) return new WP_Error( 'invalid_osm_path', 'Invalid OSM API path.' );
        $token = self::get_access_token();
        if ( $token === '' ) return new WP_Error( 'no_token', 'No valid OSM access token is available.' );
        $method = strtoupper( $method );
        $url = self::OSM_DOMAIN . $path;
        $args = array( 'timeout' => 30, 'method' => $method, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ) );
        if ( $method === 'GET' && $body ) $url = add_query_arg( $body, $url ); elseif ( $body ) $args['body'] = $body;
        $result = self::parse_http_response( wp_remote_request( $url, $args ) );
        if ( is_wp_error( $result ) ) {
            $data = $result->get_error_data(); $code = is_array( $data ) ? (int) ( $data['response_code'] ?? 0 ) : 0;
            if ( $code === 401 && ! $refreshed && self::get_access_token( true ) !== '' ) return self::api_call( $method, $path, $body, true );
        }
        return $result;
    }

    public static function validate_enabled_configuration( $settings = null ) {
        $settings = is_array( $settings ) ? $settings : self::get_settings();
        if ( empty( $settings['enabled'] ) ) return true;
        $required = array( 'section_id' => 'OSM section', 'bank_account_id' => 'OSM bank account', 'venue_category_id' => 'venue income category', 'venue_item_id' => 'venue income item' );
        $missing = array();
        foreach ( $required as $key => $label ) if ( empty( $settings[$key] ) || ! ctype_digit( (string) $settings[$key] ) ) $missing[] = $label;
        return $missing ? new WP_Error( 'osm_configuration_incomplete', 'OSM integration is enabled but the following mapping is missing or invalid: ' . implode( ', ', $missing ) . '.' ) : true;
    }

    /** Inserted before the billing-ledger transaction commits. */
    public static function queue_ledger_transaction( $transaction_id, $invoice ) {
        global $wpdb;
        $settings = self::get_settings();
        if ( ! $settings['enabled'] ) return 0;
        $valid = self::validate_enabled_configuration( $settings ); if ( is_wp_error( $valid ) ) return $valid;
        $transaction = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . MBS_PAYMENT_TRANSACTION_TABLE . ' WHERE id=%d', (int) $transaction_id ) );
        if ( ! $transaction || $transaction->status !== 'completed' || ! in_array( $transaction->transaction_type, array( 'payment', 'refund' ), true ) ) return 0;
        $metadata = json_decode( (string) $transaction->metadata_json, true ); $metadata = is_array( $metadata ) ? $metadata : array();
        $order_id = (int) ( $metadata['woocommerce_order_id'] ?? 0 ); $refund_id = (int) ( $metadata['woocommerce_refund_id'] ?? 0 );
        $gateway = '';
        if ( $order_id && function_exists( 'wc_get_order' ) ) { $order = wc_get_order( $order_id ); if ( $order ) $gateway = sanitize_key( $order->get_payment_method() ); }
        $is_wcpay = $gateway !== '' && ( strpos( $gateway, 'woocommerce_payments' ) === 0 || strpos( $gateway, 'wcpay' ) === 0 );
        $target = $is_wcpay ? 'woopayments_payout' : 'bank_match'; $status = $is_wcpay ? 'awaiting_payout' : 'awaiting_bank_match';
        $booking_refs = array(); foreach ( MBS_Billing_Ledger::get_items( (int) $invoice->id ) as $item ) if ( ! empty( $item->booking_ref ) ) $booking_refs[] = (string) $item->booking_ref;
        $booking_refs = array_values( array_unique( $booking_refs ) );
        $payload = array(
            'schema' => 2, 'transaction_id' => (int) $transaction->id, 'transaction_ref' => (string) $transaction->transaction_ref,
            'transaction_type' => (string) $transaction->transaction_type, 'provider' => (string) $transaction->provider,
            'provider_transaction_id' => (string) $transaction->provider_transaction_id, 'payment_gateway' => $gateway,
            'invoice_ref' => (string) $invoice->invoice_ref, 'booking_refs' => $booking_refs, 'order_id' => $order_id,
            'refund_id' => $refund_id, 'amount_minor' => (int) $transaction->amount_minor,
            'currency' => strtoupper( (string) $transaction->currency ), 'occurred_at' => (string) $transaction->occurred_at,
            'target' => array( 'mode' => $target, 'section_id' => (int) $settings['section_id'], 'bank_account_id' => (int) $settings['bank_account_id'], 'category_id' => (int) $settings['venue_category_id'], 'item_id' => (int) $settings['venue_item_id'] ),
        );
        $json = wp_json_encode( $payload ); $event_ref = 'osm-ledger:' . $transaction->transaction_ref;
        $table = $wpdb->prefix . MBS_OSM_OUTBOX_TABLE;
        $inserted = $wpdb->insert( $table, array(
            'event_ref' => $event_ref, 'transaction_id' => (int) $transaction->id, 'transaction_ref' => $transaction->transaction_ref,
            'event_type' => $transaction->transaction_type, 'provider' => $transaction->provider,
            'provider_transaction_id' => (string) $transaction->provider_transaction_id, 'payment_gateway' => $gateway,
            'target_mode' => $target, 'booking_ref' => $booking_refs ? $booking_refs[0] : '', 'invoice_ref' => $invoice->invoice_ref,
            'order_id' => $order_id, 'refund_id' => $refund_id, 'amount_minor' => (int) $transaction->amount_minor,
            'currency' => strtoupper( (string) $transaction->currency ), 'occurred_at' => $transaction->occurred_at,
            'section_id' => (int) $settings['section_id'], 'bank_account_id' => (int) $settings['bank_account_id'],
            'category_id' => (int) $settings['venue_category_id'], 'item_id' => (int) $settings['venue_item_id'],
            'reversal_kind' => '', 'payload_json' => $json, 'payload_hash' => hash( 'sha256', $json ), 'status' => $status,
            'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
        ) );
        if ( $inserted !== false ) return (int) $wpdb->insert_id;
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE transaction_id=%d OR event_ref=%s LIMIT 1", (int) $transaction->id, $event_ref ) );
        if ( $existing && hash_equals( (string) $existing->payload_hash, hash( 'sha256', $json ) ) ) return (int) $existing->id;
        return new WP_Error( 'osm_outbox_insert_failed', 'Could not durably queue the OSM accounting event: ' . $wpdb->last_error );
    }

    /** Compatibility only: old per-booking refunds are held for review, never auto-posted. */
    public static function queue_refund_reversal( $booking, $invoice_ref, $amount_minor, $order_id, $refund_id, $reversal_kind = '' ) {
        global $wpdb;
        if ( ! self::get_settings()['enabled'] ) return 0;
        $event_ref = 'osm-legacy-refund:' . sanitize_text_field( $invoice_ref ) . ':' . (int) $refund_id . ':' . sanitize_text_field( $booking->ref );
        $json = wp_json_encode( array( 'schema' => 1, 'legacy' => true, 'booking_ref' => $booking->ref, 'invoice_ref' => $invoice_ref, 'amount_minor' => (int) $amount_minor ) );
        $inserted = $wpdb->insert( $wpdb->prefix . MBS_OSM_OUTBOX_TABLE, array(
            'event_ref' => $event_ref, 'event_type' => 'legacy_refund', 'target_mode' => 'manual_review', 'booking_ref' => $booking->ref,
            'invoice_ref' => $invoice_ref, 'order_id' => (int) $order_id, 'refund_id' => (int) $refund_id,
            'amount_minor' => (int) $amount_minor, 'currency' => 'GBP', 'reversal_kind' => sanitize_key( $reversal_kind ),
            'payload_json' => $json, 'payload_hash' => hash( 'sha256', $json ), 'status' => 'manual_reconciliation',
            'last_error' => 'Legacy per-booking refund retained for review; it must not be posted separately from its payout.',
            'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
        ) );
        if ( $inserted !== false ) return (int) $wpdb->insert_id;
        $existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . MBS_OSM_OUTBOX_TABLE . ' WHERE event_ref=%s', $event_ref ) );
        return $existing ? (int) $existing : new WP_Error( 'osm_outbox_insert_failed', 'Could not retain the legacy OSM event.' );
    }

    /** Individual event delivery is intentionally disabled. */
    public static function deliver_outbox_event( $event_id ) {
        global $wpdb; $table = $wpdb->prefix . MBS_OSM_OUTBOX_TABLE;
        $event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", (int) $event_id ) );
        if ( $event && in_array( $event->status, array( 'processing', 'pending', 'retry' ), true ) ) $wpdb->update( $table, array( 'status' => 'manual_reconciliation', 'last_error' => 'Individual delivery is disabled; reconcile the containing payout or bank transaction.', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $event->id ) );
        return false;
    }

    private static function woopayments_request( $path, $params = array() ) {
        if ( ! function_exists( 'rest_do_request' ) || ! class_exists( 'WP_REST_Request' ) ) return new WP_Error( 'woopayments_unavailable', 'WooPayments REST support is not available.' );
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) return new WP_Error( 'forbidden', 'WooCommerce management permission is required.' );
        $request = new WP_REST_Request( 'GET', $path ); foreach ( $params as $key => $value ) $request->set_param( $key, $value );
        $response = rest_do_request( $request ); if ( is_wp_error( $response ) ) return $response;
        $status = method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 200;
        $data = method_exists( $response, 'get_data' ) ? $response->get_data() : $response;
        if ( $status < 200 || $status >= 300 ) return new WP_Error( 'woopayments_http_error', is_array( $data ) ? sanitize_text_field( $data['message'] ?? 'WooPayments request failed.' ) : 'WooPayments request failed.', array( 'status' => $status ) );
        return $data;
    }

    private static function collection_rows( $data, $keys ) {
        if ( ! is_array( $data ) ) return array();
        foreach ( $keys as $key ) if ( isset( $data[$key] ) && is_array( $data[$key] ) ) return array_values( $data[$key] );
        if ( isset( $data['data'] ) && is_array( $data['data'] ) ) return array_values( $data['data'] );
        return array_keys( $data ) === range( 0, count( $data ) - 1 ) ? array_values( $data ) : array();
    }

    private static function minor_value( $value ) {
        if ( is_array( $value ) ) {
            foreach ( array( 'amount', 'value', 'minor', 'total' ) as $key ) {
                if ( array_key_exists( $key, $value ) ) return self::minor_value( $value[$key] );
            }
            return null;
        }
        if ( is_int( $value ) ) return $value; if ( is_float( $value ) ) return (int) round( $value );
        $value = trim( (string) $value ); if ( preg_match( '/^-?\d+$/', $value ) ) return (int) $value;
        if ( preg_match( '/^-?\d+\.\d{1,2}$/', $value ) ) { $minor = MBS_Money::from_decimal_string( $value ); return is_wp_error( $minor ) ? null : (int) $minor; }
        return null;
    }

    private static function normalise_date( $value ) {
        if ( is_numeric( $value ) ) return gmdate( 'Y-m-d', (int) $value ); $timestamp = strtotime( (string) $value ); return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : '';
    }

    private static function payout_id( $row ) {
        foreach ( array( 'id', 'deposit_id', 'payout_id' ) as $key ) if ( isset( $row[$key] ) && (string) $row[$key] !== '' ) return sanitize_text_field( (string) $row[$key] ); return '';
    }

    private static function payout_snapshot( $row ) {
        $amount = $row['amount'] ?? $row['net'] ?? null;
        $currency = $row['currency'] ?? ( is_array( $amount ) ? ( $amount['currency'] ?? 'GBP' ) : 'GBP' );
        return array( 'payout_id' => self::payout_id( $row ), 'date' => self::normalise_date( $row['date'] ?? $row['arrival_date'] ?? $row['created'] ?? '' ), 'amount_minor' => self::minor_value( $amount ), 'currency' => strtoupper( sanitize_text_field( (string) $currency ) ), 'status' => sanitize_key( (string) ( $row['status'] ?? '' ) ) );
    }

    private static function transaction_snapshot( $row ) {
        $amount = $row['amount'] ?? $row['gross'] ?? null;
        $fee_value = $row['fees'] ?? $row['fee'] ?? $row['fee_amount'] ?? 0;
        $fee = self::minor_value( $fee_value );
        $order = $row['order_id'] ?? $row['order'] ?? 0;
        if ( is_array( $order ) ) $order = $order['id'] ?? $order['order_id'] ?? 0;
        $currency = $row['currency'] ?? ( is_array( $amount ) ? ( $amount['currency'] ?? 'GBP' ) : 'GBP' );
        return array( 'id' => sanitize_text_field( (string) ( $row['id'] ?? $row['transaction_id'] ?? '' ) ), 'type' => sanitize_key( (string) ( $row['type'] ?? $row['transaction_type'] ?? '' ) ), 'order_id' => (int) $order, 'amount_minor' => self::minor_value( $amount ), 'fee_minor' => $fee === null ? 0 : abs( (int) $fee ), 'currency' => strtoupper( sanitize_text_field( (string) $currency ) ), 'mapping_key' => sanitize_key( (string) ( $row['mapping_key'] ?? '' ) ) );
    }

    private static function mapping_pair( $settings, $kind ) {
        return array( 'kind' => $kind === 'fees' ? 'expense' : 'income', 'category_id' => (int) ( $settings[$kind . '_category_id'] ?? 0 ), 'item_id' => (int) ( $settings[$kind . '_item_id'] ?? 0 ), 'label' => $kind );
    }

    private static function configured_rule( $settings, $mapping_key, $product_id, $category_ids, $name ) {
        foreach ( (array) $settings['product_mappings'] as $rule ) {
            if ( ! is_array( $rule ) ) continue;
            $keys = array_map( 'sanitize_key', (array) ( $rule['mapping_keys'] ?? array() ) ); $products = array_map( 'intval', (array) ( $rule['product_ids'] ?? array() ) ); $categories = array_map( 'intval', (array) ( $rule['category_ids'] ?? array() ) );
            if ( ( $mapping_key && in_array( $mapping_key, $keys, true ) ) || in_array( $product_id, $products, true ) || array_intersect( $category_ids, $categories ) ) return array( 'kind' => 'income', 'category_id' => (int) ( $rule['category_id'] ?? 0 ), 'item_id' => (int) ( $rule['item_id'] ?? 0 ), 'label' => sanitize_text_field( (string) ( $rule['label'] ?? 'shop' ) ) );
        }
        if ( preg_match( '/\b(clothing|uniform|scarf|necker|neckie)\b/i', $name ) ) return self::mapping_pair( $settings, 'clothing' );
        return null;
    }

    private static function order_allocations( $order_id, $gross, $mapping_key, $settings ) {
        if ( $mapping_key === 'venue' ) return array( array_merge( self::mapping_pair( $settings, 'venue' ), array( 'amount' => $gross ) ) );
        if ( $mapping_key === 'clothing' ) return array( array_merge( self::mapping_pair( $settings, 'clothing' ), array( 'amount' => $gross ) ) );
        if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) return new WP_Error( 'payout_order_missing', 'A WooPayments transaction has no WooCommerce order to classify.' );
        $order = wc_get_order( $order_id ); if ( ! $order ) return new WP_Error( 'payout_order_missing', 'WooCommerce order #' . $order_id . ' could not be loaded.' );
        if ( $order->get_meta( '_mbs_invoice_ref' ) ) return array( array_merge( self::mapping_pair( $settings, 'venue' ), array( 'amount' => $gross ) ) );
        $weighted = array(); $total = 0;
        foreach ( $order->get_items() as $item ) {
            $product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
            $category_ids = $product_id ? wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) ) : array(); if ( is_wp_error( $category_ids ) ) $category_ids = array();
            $name = method_exists( $item, 'get_name' ) ? (string) $item->get_name() : '';
            if ( $product_id ) { $names = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) ); if ( ! is_wp_error( $names ) ) $name .= ' ' . implode( ' ', $names ); }
            $mapping = self::configured_rule( $settings, $mapping_key, $product_id, array_map( 'intval', (array) $category_ids ), $name );
            if ( ! $mapping || empty( $mapping['category_id'] ) || empty( $mapping['item_id'] ) ) return new WP_Error( 'unmapped_woocommerce_product', 'Order #' . $order_id . ' contains an unmapped product: ' . sanitize_text_field( $name ) . '.' );
            $weight = MBS_Money::from_decimal_string( (string) $item->get_total() ); $weight = is_wp_error( $weight ) ? 0 : abs( (int) $weight );
            $key = $mapping['category_id'] . ':' . $mapping['item_id']; if ( ! isset( $weighted[$key] ) ) $weighted[$key] = array( 'mapping' => $mapping, 'weight' => 0 ); $weighted[$key]['weight'] += $weight; $total += $weight;
        }
        if ( ! $weighted || $total <= 0 ) return new WP_Error( 'unmapped_woocommerce_order', 'Order #' . $order_id . ' has no classifiable value.' );
        $result = array(); $allocated = 0; $keys = array_keys( $weighted );
        foreach ( $keys as $index => $key ) { $amount = $index === count( $keys ) - 1 ? $gross - $allocated : (int) floor( $gross * $weighted[$key]['weight'] / $total ); $allocated += $amount; $result[] = array_merge( $weighted[$key]['mapping'], array( 'amount' => $amount ) ); }
        return $result;
    }

    /** Classify and prove that gross income - refunds - fees equals the payout. */
    public static function classify_payout( $payout, $transactions, $settings = null ) {
        $settings = is_array( $settings ) ? $settings : self::get_settings(); $p = self::payout_snapshot( $payout );
        if ( $p['payout_id'] === '' || $p['date'] === '' || $p['amount_minor'] === null ) return new WP_Error( 'invalid_payout', 'WooPayments payout ID, date or amount is missing.' );
        if ( $p['currency'] !== 'GBP' ) return new WP_Error( 'unsupported_payout_currency', 'Only GBP payouts can use this OSM bank mapping.' );
        $grouped = array(); $orders = array(); $components = array();
        foreach ( (array) $transactions as $raw ) {
            if ( ! is_array( $raw ) ) continue; $transaction = self::transaction_snapshot( $raw );
            if ( $transaction['amount_minor'] === null ) return new WP_Error( 'invalid_payout_transaction', 'A WooPayments transaction amount is missing.' );
            if ( $transaction['currency'] !== $p['currency'] ) return new WP_Error( 'mixed_payout_currency', 'The payout contains mixed currencies.' );
            if ( in_array( $transaction['type'], array( 'charge', 'payment', 'capture', 'captured' ), true ) ) $sign = 1;
            elseif ( in_array( $transaction['type'], array( 'refund', 'partial_refund', 'full_refund' ), true ) ) $sign = -1;
            else return new WP_Error( 'unsupported_payout_transaction', 'Payout ' . $p['payout_id'] . ' contains unsupported transaction type "' . $transaction['type'] . '".' );
            $allocations = self::order_allocations( $transaction['order_id'], abs( (int) $transaction['amount_minor'] ), $transaction['mapping_key'], $settings ); if ( is_wp_error( $allocations ) ) return $allocations;
            $component_lines = array();
            foreach ( $allocations as $line ) {
                if ( empty( $line['category_id'] ) || empty( $line['item_id'] ) ) return new WP_Error( 'payout_mapping_incomplete', 'An OSM category/item mapping is missing.' );
                $key = 'income:' . $line['category_id'] . ':' . $line['item_id']; if ( ! isset( $grouped[$key] ) ) $grouped[$key] = array( 'kind' => 'income', 'category_id' => (int) $line['category_id'], 'item_id' => (int) $line['item_id'], 'amount' => 0, 'label' => $line['label'] ); $grouped[$key]['amount'] += $sign * (int) $line['amount'];
                $component_lines[] = array( 'label' => $line['label'], 'category_id' => (int) $line['category_id'], 'item_id' => (int) $line['item_id'], 'amount' => $sign * (int) $line['amount'] );
            }
            $components[] = array(
                'transaction_id' => $transaction['id'], 'type' => $transaction['type'], 'order_id' => (int) $transaction['order_id'],
                'gross_minor' => $sign * abs( (int) $transaction['amount_minor'] ), 'fee_minor' => abs( (int) $transaction['fee_minor'] ),
                'net_minor' => ( $sign * abs( (int) $transaction['amount_minor'] ) ) - abs( (int) $transaction['fee_minor'] ),
                'allocations' => $component_lines,
            );
            if ( $transaction['fee_minor'] !== 0 ) {
                $fees = self::mapping_pair( $settings, 'fees' ); if ( empty( $fees['category_id'] ) || empty( $fees['item_id'] ) ) return new WP_Error( 'fee_mapping_incomplete', 'The payout contains fees but the OSM Bank Fees category/item is not configured.' );
                $key = 'expense:' . $fees['category_id'] . ':' . $fees['item_id']; if ( ! isset( $grouped[$key] ) ) $grouped[$key] = array( 'kind' => 'expense', 'category_id' => $fees['category_id'], 'item_id' => $fees['item_id'], 'amount' => 0, 'label' => 'WooPayments fees' ); $grouped[$key]['amount'] += (int) $transaction['fee_minor'];
            }
            if ( $transaction['order_id'] ) $orders[] = (int) $transaction['order_id'];
        }
        if ( ! $grouped ) return new WP_Error( 'empty_payout', 'No transactions were returned for payout ' . $p['payout_id'] . '.' );
        $net = 0; $categories = array(); foreach ( $grouped as $line ) { if ( $line['amount'] === 0 ) continue; $net += $line['kind'] === 'expense' ? -$line['amount'] : $line['amount']; $categories[] = $line; }
        if ( $net !== (int) $p['amount_minor'] ) return new WP_Error( 'payout_net_mismatch', 'Classified lines total ' . MBS_Money::format( $net ) . ' but payout ' . $p['payout_id'] . ' is ' . MBS_Money::format( (int) $p['amount_minor'] ) . '. Nothing was written to OSM.' );
        $description = str_replace( array( '{payout_id}', '{date}', '{amount}' ), array( $p['payout_id'], $p['date'], MBS_Money::format( abs( $p['amount_minor'] ) ) ), $settings['description_tpl'] );
        return array( 'schema' => 3, 'payout' => $p, 'description' => sanitize_text_field( $description ), 'type' => $p['amount_minor'] >= 0 ? 'income' : 'expense', 'amount' => abs( (int) $p['amount_minor'] ), 'categories' => array_values( $categories ), 'components' => $components, 'component_order_ids' => array_values( array_unique( $orders ) ) );
    }

    private static function discover_bank_match( $classification, $preferred_id = 0 ) {
        $settings = self::get_settings(); $result = self::api_call( 'GET', '/v3/finances/accounting/bank_accounts/' . (int) $settings['bank_account_id'] . '/transactions', array( 'page' => 1, 'per_page' => 500 ) ); if ( is_wp_error( $result ) ) return $result;
        $rows = self::collection_rows( $result, array( 'transactions' ) ); $payout = $classification['payout']; $target = strtotime( $payout['date'] . ' 12:00:00 UTC' ); $matches = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue; $id = (int) ( $row['transaction_id'] ?? $row['id'] ?? 0 ); if ( ! $id || ( $preferred_id && $id !== (int) $preferred_id ) ) continue;
            if ( self::minor_value( $row['amount'] ?? $row['transaction_amount'] ?? null ) !== (int) $payout['amount_minor'] ) continue;
            $date = self::normalise_date( $row['date'] ?? $row['transaction_date'] ?? '' ); if ( $date === '' || abs( strtotime( $date . ' 12:00:00 UTC' ) - $target ) > $settings['match_days'] * DAY_IN_SECONDS ) continue;
            $reference = (string) ( $row['reference'] ?? $row['description'] ?? $row['transaction_reference'] ?? '' ); if ( ! $preferred_id && ! preg_match( '/woo\s*payments|stripe/i', $reference ) ) continue;
            if ( ! empty( $row['transaction'] ?? $row['cashbook_transaction'] ?? null ) ) continue; $matches[] = array( 'id' => $id, 'date' => $date, 'amount' => (int) $payout['amount_minor'], 'reference' => sanitize_text_field( $reference ) );
        }
        if ( count( $matches ) !== 1 ) return new WP_Error( $matches ? 'ambiguous_bank_match' : 'bank_match_not_found', $matches ? 'More than one imported OSM bank transaction could match payout ' . $payout['payout_id'] . '.' : 'Awaiting the matching Co-op bank transaction for payout ' . $payout['payout_id'] . '. Import the statement into OSM, then retry.', array( 'candidates' => $matches ) );
        return $matches[0];
    }

    /** Match a non-card ledger event to one existing imported bank line. */
    private static function discover_event_bank_match( $event, $preferred_id = 0 ) {
        global $wpdb;
        $settings = self::get_settings();
        $result = self::api_call( 'GET', '/v3/finances/accounting/bank_accounts/' . (int) $event->bank_account_id . '/transactions', array( 'page' => 1, 'per_page' => 500 ) );
        if ( is_wp_error( $result ) ) return $result;
        $signed_amount = $event->event_type === 'refund' ? -abs( (int) $event->amount_minor ) : abs( (int) $event->amount_minor );
        $date = self::normalise_date( $event->occurred_at );
        $target = $date ? strtotime( $date . ' 12:00:00 UTC' ) : 0;
        $tokens = array_filter( array( (string) $event->invoice_ref, (string) $event->booking_ref, (string) $event->provider_transaction_id, $event->order_id ? '#' . (int) $event->order_id : '' ) );
        $matches = array();
        foreach ( self::collection_rows( $result, array( 'transactions' ) ) as $row ) {
            if ( ! is_array( $row ) ) continue;
            $id = (int) ( $row['transaction_id'] ?? $row['id'] ?? 0 );
            if ( ! $id || ( $preferred_id && $id !== (int) $preferred_id ) ) continue;
            if ( self::minor_value( $row['amount'] ?? $row['transaction_amount'] ?? null ) !== $signed_amount ) continue;
            $bank_date = self::normalise_date( $row['date'] ?? $row['transaction_date'] ?? '' );
            if ( ! $target || $bank_date === '' || abs( strtotime( $bank_date . ' 12:00:00 UTC' ) - $target ) > $settings['match_days'] * DAY_IN_SECONDS ) continue;
            if ( ! empty( $row['transaction'] ?? $row['cashbook_transaction'] ?? null ) ) continue;
            $reference = sanitize_text_field( (string) ( $row['reference'] ?? $row['description'] ?? $row['transaction_reference'] ?? '' ) );
            if ( ! $preferred_id ) {
                $referenced = false;
                foreach ( $tokens as $token ) if ( $token !== '' && stripos( $reference, $token ) !== false ) { $referenced = true; break; }
                if ( ! $referenced ) continue;
            }
            $owned_payout = $wpdb->get_var( $wpdb->prepare( 'SELECT payout_ref FROM ' . $wpdb->prefix . MBS_OSM_PAYOUT_TABLE . ' WHERE bank_transaction_id=%d LIMIT 1', $id ) );
            $owned_event = $wpdb->get_var( $wpdb->prepare( 'SELECT event_ref FROM ' . $wpdb->prefix . MBS_OSM_OUTBOX_TABLE . ' WHERE bank_transaction_id=%d AND id<>%d LIMIT 1', $id, (int) $event->id ) );
            if ( $owned_payout || $owned_event ) continue;
            $matches[] = array( 'id' => $id, 'date' => $bank_date, 'amount' => $signed_amount, 'reference' => $reference );
        }
        if ( count( $matches ) !== 1 ) return new WP_Error( $matches ? 'ambiguous_bank_match' : 'bank_match_not_found', $matches ? 'More than one imported bank transaction could match ' . $event->invoice_ref . '.' : 'Awaiting the matching Co-op bank transaction for ' . $event->invoice_ref . '. Import the statement into OSM, then retry or enter its exact OSM transaction ID.', array( 'candidates' => $matches ) );
        return $matches[0];
    }

    public static function reconcile_bank_event( $event_id, $preferred_bank_id = 0 ) {
        if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'forbidden', 'Administrator permission is required.' );
        global $wpdb; $table = $wpdb->prefix . MBS_OSM_OUTBOX_TABLE;
        $event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", (int) $event_id ) );
        if ( ! $event || $event->target_mode !== 'bank_match' || $event->event_type === 'legacy_refund' ) return new WP_Error( 'invalid_outbox_event', 'This finance event cannot be matched to a bank transaction.' );
        if ( $event->status === 'delivered' ) return array( 'id' => (int) $event->id, 'status' => 'already_delivered', 'cashbook_transaction_id' => (int) $event->remote_transaction_id );
        if ( ! in_array( $event->status, array( 'awaiting_bank_match', 'awaiting_bank_import', 'manual_reconciliation', 'sandbox_preview' ), true ) ) return new WP_Error( 'invalid_outbox_state', 'This finance event is not ready for bank matching.' );
        $match = self::discover_event_bank_match( $event, (int) ( $preferred_bank_id ?: $event->bank_transaction_id ) );
        if ( is_wp_error( $match ) ) { $status = $match->get_error_code() === 'bank_match_not_found' ? 'awaiting_bank_import' : 'manual_reconciliation'; $wpdb->update( $table, array( 'status' => $status, 'last_error' => sanitize_text_field( $match->get_error_message() ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $event->id ) ); return $match; }
        $now = current_time( 'mysql' );
        $claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status='processing',bank_transaction_id=%d,attempts=attempts+1,claimed_at=%s,lease_expires_at=%s,last_error='',updated_at=%s WHERE id=%d AND status IN ('awaiting_bank_match','awaiting_bank_import','manual_reconciliation','sandbox_preview')", (int) $match['id'], $now, gmdate( 'Y-m-d H:i:s', time() + 300 ), $now, (int) $event->id ) );
        if ( $claimed !== 1 ) return new WP_Error( 'event_already_claimed', 'This finance event is already being reconciled.' );
        $settings = self::get_settings();
        if ( $settings['sandbox_mode'] ) { $wpdb->update( $table, array( 'status' => 'sandbox_preview', 'lease_expires_at' => null, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $event->id ) ); return array( 'id' => (int) $event->id, 'status' => 'sandbox_preview', 'bank_transaction_id' => (int) $match['id'] ); }
        $is_refund = $event->event_type === 'refund';
        $description = sanitize_text_field( ( $is_refund ? 'Venue refund ' : 'Venue payment ' ) . $event->invoice_ref );
        $categories = array( array( 'category_id' => (int) $event->category_id, 'item_id' => (int) $event->item_id, 'amount' => $is_refund ? -abs( (int) $event->amount_minor ) : abs( (int) $event->amount_minor ) ) );
        $body = array( 'description' => $description, 'type' => $is_refund ? 'expense' : 'income', 'date' => $match['date'], 'amount' => abs( (int) $event->amount_minor ), 'categories' => wp_json_encode( $categories ), 'attachments_id' => '', 'bank_account_transaction_id' => (int) $match['id'] );
        $result = self::api_call( 'POST', '/v3/finances/accounting/financial_year/cashbook/transactions/section/' . (int) $event->section_id, $body );
        if ( is_wp_error( $result ) ) { $wpdb->update( $table, array( 'status' => 'manual_reconciliation', 'lease_expires_at' => null, 'last_error' => sanitize_text_field( $result->get_error_message() ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $event->id ) ); return $result; }
        $remote = self::remote_id( $result );
        if ( ! $remote ) { $message = 'OSM accepted the request but returned no transaction ID. Check OSM before retrying.'; $wpdb->update( $table, array( 'status' => 'manual_reconciliation', 'lease_expires_at' => null, 'last_error' => $message, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $event->id ) ); return new WP_Error( 'osm_write_ambiguous', $message ); }
        $now = current_time( 'mysql' ); $wpdb->update( $table, array( 'status' => 'delivered', 'remote_transaction_id' => $remote, 'delivered_at' => $now, 'lease_expires_at' => null, 'last_error' => '', 'updated_at' => $now ), array( 'id' => (int) $event->id ) );
        MBS_Audit_Log::log( (string) $event->invoice_ref, 'osm_bank_event_reconciled', $description . ' linked to imported bank transaction ' . $match['id'] . ' and cashbook transaction ' . $remote . '.' );
        return array( 'id' => (int) $event->id, 'status' => 'delivered', 'bank_transaction_id' => (int) $match['id'], 'cashbook_transaction_id' => $remote );
    }

    private static function remote_id( $result ) {
        if ( ! is_array( $result ) ) return 0; foreach ( array( 'transaction_id', 'id', 'cashbook_transaction_id' ) as $key ) if ( ! empty( $result[$key] ) ) return (int) $result[$key]; return isset( $result['data'] ) && is_array( $result['data'] ) ? self::remote_id( $result['data'] ) : 0;
    }

    private static function save_payout( $classification, $status, $error = '', $bank_id = null ) {
        global $wpdb; $settings = self::get_settings(); $table = $wpdb->prefix . MBS_OSM_PAYOUT_TABLE; $json = wp_json_encode( $classification ); $hash = hash( 'sha256', $json ); $p = $classification['payout'];
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE payout_ref=%s", $p['payout_id'] ) );
        if ( $existing ) {
            if ( $existing->status === 'delivered' && ! hash_equals( (string) $existing->payload_hash, $hash ) ) return new WP_Error( 'delivered_payout_changed', 'WooPayments changed an already-delivered payout. Manual reconciliation is required.' );
            $values = array( 'status' => $status, 'last_error' => sanitize_text_field( $error ), 'payload_json' => $json, 'payload_hash' => $hash, 'updated_at' => current_time( 'mysql' ) ); if ( $bank_id ) $values['bank_transaction_id'] = (int) $bank_id;
            return $wpdb->update( $table, $values, array( 'id' => (int) $existing->id ) ) === false ? new WP_Error( 'payout_state_failed', 'Could not update the payout state: ' . $wpdb->last_error ) : (int) $existing->id;
        }
        $inserted = $wpdb->insert( $table, array( 'payout_ref' => $p['payout_id'], 'payout_date' => $p['date'], 'currency' => $p['currency'], 'amount_minor' => (int) $p['amount_minor'], 'bank_account_id' => (int) $settings['bank_account_id'], 'bank_transaction_id' => $bank_id ? (int) $bank_id : null, 'payload_json' => $json, 'payload_hash' => $hash, 'status' => $status, 'last_error' => sanitize_text_field( $error ), 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
        return $inserted === false ? new WP_Error( 'payout_state_failed', 'Could not create the payout state. Another payout may already own that bank transaction: ' . $wpdb->last_error ) : (int) $wpdb->insert_id;
    }

    public static function reconcile_payout( $payout, $transactions, $preferred_bank_id = 0 ) {
        global $wpdb; $classification = self::classify_payout( $payout, $transactions ); if ( is_wp_error( $classification ) ) return $classification;
        $table = $wpdb->prefix . MBS_OSM_PAYOUT_TABLE; $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE payout_ref=%s", $classification['payout']['payout_id'] ) );
        if ( $existing && $existing->status === 'delivered' ) return array( 'payout_id' => $existing->payout_ref, 'status' => 'already_delivered', 'cashbook_transaction_id' => (int) $existing->cashbook_transaction_id );
        $match = self::discover_bank_match( $classification, (int) $preferred_bank_id ); if ( is_wp_error( $match ) ) { $status = $match->get_error_code() === 'bank_match_not_found' ? 'awaiting_bank_import' : 'manual_reconciliation'; self::save_payout( $classification, $status, $match->get_error_message() ); return $match; }
        $id = self::save_payout( $classification, 'pending', '', $match['id'] ); if ( is_wp_error( $id ) ) return $id;
        $now = current_time( 'mysql' ); $claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status='processing',attempts=attempts+1,claimed_at=%s,lease_expires_at=%s,updated_at=%s WHERE id=%d AND status IN ('pending','awaiting_bank_import','manual_reconciliation','sandbox_preview')", $now, gmdate( 'Y-m-d H:i:s', time() + 300 ), $now, (int) $id ) );
        if ( $claimed !== 1 ) return new WP_Error( 'payout_already_claimed', 'This payout is already being reconciled.' );
        $settings = self::get_settings();
        if ( $settings['sandbox_mode'] ) { $wpdb->update( $table, array( 'status' => 'sandbox_preview', 'lease_expires_at' => null, 'last_error' => '', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ) ); return array( 'payout_id' => $classification['payout']['payout_id'], 'status' => 'sandbox_preview', 'bank_transaction_id' => $match['id'], 'classification' => $classification ); }
        $categories = array_map( static function ( $line ) { return array( 'category_id' => (int) $line['category_id'], 'item_id' => (int) $line['item_id'], 'amount' => (int) $line['amount'] ); }, $classification['categories'] );
        $body = array( 'description' => $classification['description'], 'type' => $classification['type'], 'date' => $classification['payout']['date'], 'amount' => (int) $classification['amount'], 'categories' => wp_json_encode( $categories ), 'attachments_id' => '', 'bank_account_transaction_id' => (int) $match['id'] );
        $result = self::api_call( 'POST', '/v3/finances/accounting/financial_year/cashbook/transactions/section/' . (int) $settings['section_id'], $body );
        if ( is_wp_error( $result ) ) { $data = $result->get_error_data(); $code = is_array( $data ) ? (int) ( $data['response_code'] ?? 0 ) : 0; $wpdb->update( $table, array( 'status' => 'manual_reconciliation', 'lease_expires_at' => null, 'last_error' => sanitize_text_field( $result->get_error_message() ), 'response_code' => $code ?: null, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ) ); return $result; }
        $remote = self::remote_id( $result ); if ( ! $remote ) { $message = 'OSM accepted the request but returned no transaction ID. Check OSM before retrying.'; $wpdb->update( $table, array( 'status' => 'manual_reconciliation', 'lease_expires_at' => null, 'last_error' => $message, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ) ); return new WP_Error( 'osm_write_ambiguous', $message ); }
        $now = current_time( 'mysql' ); $wpdb->update( $table, array( 'status' => 'delivered', 'cashbook_transaction_id' => $remote, 'delivered_at' => $now, 'lease_expires_at' => null, 'last_error' => '', 'updated_at' => $now ), array( 'id' => (int) $id ) );
        $orders = array_map( 'intval', $classification['component_order_ids'] );
        if ( $orders ) { $placeholders = implode( ',', array_fill( 0, count( $orders ), '%d' ) ); $query = $wpdb->prepare( "UPDATE " . $wpdb->prefix . MBS_OSM_OUTBOX_TABLE . " SET status='consolidated',payout_id=%s,bank_transaction_id=%d,remote_transaction_id=%d,delivered_at=%s,updated_at=%s WHERE order_id IN ({$placeholders}) AND target_mode='woopayments_payout' AND status<>'consolidated'", array_merge( array( $classification['payout']['payout_id'], (int) $match['id'], $remote, $now, $now ), $orders ) ); $wpdb->query( $query ); }
        MBS_Audit_Log::log( 'OSM-' . $classification['payout']['payout_id'], 'osm_payout_reconciled', 'WooPayments payout ' . $classification['payout']['payout_id'] . ' linked to imported bank transaction ' . $match['id'] . ' and cashbook transaction ' . $remote . '.' );
        return array( 'payout_id' => $classification['payout']['payout_id'], 'status' => 'delivered', 'bank_transaction_id' => $match['id'], 'cashbook_transaction_id' => $remote );
    }

    private static function woopayments_payout_rows( $requested_id ) {
        if ( $requested_id !== '' ) {
            $single = self::woopayments_request( '/wc/v3/payments/deposits/' . rawurlencode( $requested_id ) );
            if ( ! is_wp_error( $single ) ) {
                $candidate = isset( $single['deposit'] ) && is_array( $single['deposit'] ) ? $single['deposit'] : ( isset( $single['payout'] ) && is_array( $single['payout'] ) ? $single['payout'] : $single );
                if ( is_array( $candidate ) && self::payout_id( $candidate ) === $requested_id ) return array( $candidate );
            }
        }
        $deposits = self::woopayments_request( '/wc/v3/payments/deposits', array( 'page' => 1, 'per_page' => 25, 'status' => 'paid' ) );
        if ( is_wp_error( $deposits ) ) return $deposits;
        $rows = self::collection_rows( $deposits, array( 'deposits', 'payouts' ) );
        if ( $requested_id !== '' ) $rows = array_values( array_filter( $rows, static function ( $row ) use ( $requested_id ) { return is_array( $row ) && self::payout_id( $row ) === $requested_id; } ) );
        return $rows;
    }

    private static function woopayments_payout_transactions( $payout_id ) {
        $rows = array();
        for ( $page = 1; $page <= 20; $page++ ) {
            $data = self::woopayments_request( '/wc/v3/payments/transactions', array( 'deposit_id' => $payout_id, 'page' => $page, 'per_page' => 100 ) );
            if ( is_wp_error( $data ) ) return $data;
            $batch = self::collection_rows( $data, array( 'transactions' ) );
            $rows = array_merge( $rows, $batch );
            if ( count( $batch ) < 100 ) return $rows;
        }
        return new WP_Error( 'payout_too_large', 'Payout ' . $payout_id . ' contains more than 2,000 transactions. Nothing was written to OSM.' );
    }

    public static function sync_woopayments( $requested_id = '', $preferred_bank_id = 0 ) {
        $valid = self::validate_enabled_configuration(); if ( is_wp_error( $valid ) ) return $valid;
        $rows = self::woopayments_payout_rows( $requested_id ); if ( is_wp_error( $rows ) ) return $rows;
        if ( ! $rows ) return new WP_Error( 'payout_not_found', $requested_id ? 'WooPayments payout ' . $requested_id . ' was not found among recent paid payouts.' : 'WooPayments returned no recent paid payouts.' );
        $results = array(); foreach ( $rows as $payout ) { $id = self::payout_id( $payout ); $transactions = self::woopayments_payout_transactions( $id ); if ( is_wp_error( $transactions ) ) { $results[] = array( 'payout_id' => $id, 'status' => 'error', 'message' => $transactions->get_error_message() ); continue; } $result = self::reconcile_payout( $payout, $transactions, count( $rows ) === 1 ? (int) $preferred_bank_id : 0 ); $results[] = is_wp_error( $result ) ? array( 'payout_id' => $id, 'status' => $result->get_error_code() === 'bank_match_not_found' ? 'awaiting_bank_import' : 'manual_reconciliation', 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ) : $result; }
        return array( 'items' => $results );
    }

    public static function recover_stale_claims() {
        global $wpdb;
        $payouts = $wpdb->prefix . MBS_OSM_PAYOUT_TABLE;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $payouts ) ) === $payouts ) $wpdb->query( "UPDATE {$payouts} SET status='manual_reconciliation',last_error='The previous worker stopped without a definite result. Check OSM before retrying.',lease_expires_at=NULL,updated_at=UTC_TIMESTAMP() WHERE status='processing' AND lease_expires_at IS NOT NULL AND lease_expires_at<UTC_TIMESTAMP()" );
        $events = $wpdb->prefix . MBS_OSM_OUTBOX_TABLE;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $events ) ) === $events ) $wpdb->query( "UPDATE {$events} SET status='manual_reconciliation',last_error='The previous worker stopped without a definite result. Check OSM before retrying.',lease_expires_at=NULL,updated_at=UTC_TIMESTAMP() WHERE target_mode='bank_match' AND status='processing' AND lease_expires_at IS NOT NULL AND lease_expires_at<UTC_TIMESTAMP()" );
    }

    public static function get_queue_health() {
        global $wpdb; $outbox = $wpdb->prefix . MBS_OSM_OUTBOX_TABLE; $payouts = $wpdb->prefix . MBS_OSM_PAYOUT_TABLE; $health = array( 'events' => array(), 'payouts' => array(), 'recent_events' => array(), 'recent_payouts' => array() );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $outbox ) ) === $outbox ) { foreach ( (array) $wpdb->get_results( "SELECT status,COUNT(*) AS total FROM {$outbox} GROUP BY status" ) as $row ) $health['events'][$row->status] = (int) $row->total; $health['recent_events'] = $wpdb->get_results( "SELECT id,event_ref,event_type,invoice_ref,order_id,amount_minor,currency,target_mode,payout_id,status,last_error,created_at,delivered_at FROM {$outbox} ORDER BY id DESC LIMIT 25", ARRAY_A ); }
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $payouts ) ) === $payouts ) { foreach ( (array) $wpdb->get_results( "SELECT status,COUNT(*) AS total FROM {$payouts} GROUP BY status" ) as $row ) $health['payouts'][$row->status] = (int) $row->total; $health['recent_payouts'] = $wpdb->get_results( "SELECT id,payout_ref,payout_date,currency,amount_minor,bank_transaction_id,cashbook_transaction_id,payload_json,payload_hash,status,attempts,last_error,created_at,updated_at,delivered_at,resolved_at FROM {$payouts} ORDER BY id DESC LIMIT 25", ARRAY_A ); }
        return $health;
    }

    public static function status_label( $status ) {
        $labels = array(
            'awaiting_payout' => 'Awaiting WooPayments payout', 'awaiting_bank_match' => 'Awaiting bank match',
            'awaiting_bank_import' => 'Awaiting Co-op bank import', 'pending' => 'Ready to reconcile',
            'processing' => 'Reconciliation in progress', 'sandbox_preview' => 'Sandbox preview',
            'manual_reconciliation' => 'Needs attention', 'delivered' => 'Added to OSM',
            'consolidated' => 'Included in OSM payout', 'resolved' => 'Resolved manually',
        );
        return $labels[$status] ?? ucwords( str_replace( '_', ' ', (string) $status ) );
    }

    /** Ledger-safe OSM reporting: delivered totals are separate from previews/pending data. */
    public static function get_reporting_snapshot( $date_from, $date_to ) {
        global $wpdb;
        $report = array(
            'available' => false, 'status_counts' => array(), 'delivered_payouts' => 0, 'delivered_net_minor' => 0,
            'delivered_direct_events' => 0, 'delivered_direct_minor' => 0,
            'awaiting_import' => 0, 'needs_attention' => 0, 'sandbox_previews' => 0,
            'category_totals' => array(), 'event_status_counts' => array(), 'recent_delivered' => array(), 'recent_direct' => array(),
        );
        $payouts = $wpdb->prefix . MBS_OSM_PAYOUT_TABLE;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $payouts ) ) !== $payouts ) return $report;
        $report['available'] = true;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$payouts} WHERE payout_date BETWEEN %s AND %s ORDER BY payout_date DESC,id DESC", $date_from, $date_to ), ARRAY_A );
        foreach ( (array) $rows as $row ) {
            $status = (string) $row['status']; $report['status_counts'][$status] = ( $report['status_counts'][$status] ?? 0 ) + 1;
            if ( $status === 'awaiting_bank_import' ) $report['awaiting_import']++;
            if ( $status === 'manual_reconciliation' ) $report['needs_attention']++;
            if ( $status === 'sandbox_preview' ) $report['sandbox_previews']++;
            if ( $status !== 'delivered' ) continue;
            $report['delivered_payouts']++; $report['delivered_net_minor'] += (int) $row['amount_minor'];
            $classification = json_decode( (string) $row['payload_json'], true );
            foreach ( (array) ( $classification['categories'] ?? array() ) as $line ) {
                if ( ! is_array( $line ) ) continue;
                $key = (int) ( $line['category_id'] ?? 0 ) . ':' . (int) ( $line['item_id'] ?? 0 ) . ':' . sanitize_key( (string) ( $line['kind'] ?? 'income' ) );
                if ( ! isset( $report['category_totals'][$key] ) ) $report['category_totals'][$key] = array( 'label' => sanitize_text_field( (string) ( $line['label'] ?? 'Unlabelled' ) ), 'kind' => sanitize_key( (string) ( $line['kind'] ?? 'income' ) ), 'category_id' => (int) ( $line['category_id'] ?? 0 ), 'item_id' => (int) ( $line['item_id'] ?? 0 ), 'amount_minor' => 0 );
                $report['category_totals'][$key]['amount_minor'] += (int) ( $line['amount'] ?? 0 );
            }
            if ( count( $report['recent_delivered'] ) < 20 ) $report['recent_delivered'][] = $row;
        }
        $events = $wpdb->prefix . MBS_OSM_OUTBOX_TABLE;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $events ) ) === $events ) {
            foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT status,COUNT(*) AS total FROM {$events} WHERE DATE(occurred_at) BETWEEN %s AND %s GROUP BY status", $date_from, $date_to ) ) as $row ) {
                $report['event_status_counts'][$row->status] = (int) $row->total;
                if ( $row->status === 'awaiting_bank_import' ) $report['awaiting_import'] += (int) $row->total;
                if ( $row->status === 'manual_reconciliation' ) $report['needs_attention'] += (int) $row->total;
            }
            $direct = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$events} WHERE target_mode='bank_match' AND status='delivered' AND DATE(occurred_at) BETWEEN %s AND %s ORDER BY occurred_at DESC,id DESC", $date_from, $date_to ), ARRAY_A );
            foreach ( (array) $direct as $event ) {
                $signed = $event['event_type'] === 'refund' ? -abs( (int) $event['amount_minor'] ) : abs( (int) $event['amount_minor'] );
                $report['delivered_direct_events']++; $report['delivered_direct_minor'] += $signed;
                $key = (int) $event['category_id'] . ':' . (int) $event['item_id'] . ':income';
                if ( ! isset( $report['category_totals'][$key] ) ) $report['category_totals'][$key] = array( 'label' => 'Venue hire', 'kind' => 'income', 'category_id' => (int) $event['category_id'], 'item_id' => (int) $event['item_id'], 'amount_minor' => 0 );
                $report['category_totals'][$key]['amount_minor'] += $signed;
                if ( count( $report['recent_direct'] ) < 20 ) $report['recent_direct'][] = $event;
            }
        }
        $report['category_totals'] = array_values( $report['category_totals'] );
        usort( $report['category_totals'], static function ( $a, $b ) { return abs( $b['amount_minor'] ) <=> abs( $a['amount_minor'] ); } );
        return $report;
    }

    public static function outbox_health_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return; $health = self::get_queue_health(); $manual = (int) ( $health['events']['manual_reconciliation'] ?? 0 ) + (int) ( $health['payouts']['manual_reconciliation'] ?? 0 );
        if ( $manual ) echo '<div class="notice notice-error"><p><strong>MGF Venue OSM reconciliation required.</strong> ' . esc_html( $manual ) . ' finance event(s) need review. No automatic duplicate entry will be attempted.</p></div>';
    }

    public static function retry_outbox( $event_id, $preferred_bank_id = 0 ) {
        if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'forbidden', 'Administrator permission is required.' ); global $wpdb; $table = $wpdb->prefix . MBS_OSM_OUTBOX_TABLE; $event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", (int) $event_id ) );
        if ( ! $event || ! in_array( $event->status, array( 'manual_reconciliation', 'awaiting_bank_match', 'awaiting_bank_import', 'sandbox_preview' ), true ) ) return new WP_Error( 'invalid_outbox_state', 'Only reconciliation events can be retried.' ); if ( $event->event_type === 'legacy_refund' ) return new WP_Error( 'legacy_event_manual_only', 'Legacy per-booking events cannot be posted automatically.' );
        if ( $event->target_mode === 'bank_match' ) return self::reconcile_bank_event( (int) $event->id, (int) $preferred_bank_id );
        $status = $event->target_mode === 'woopayments_payout' ? 'awaiting_payout' : 'awaiting_bank_match'; $wpdb->update( $table, array( 'status' => $status, 'last_error' => '', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $event->id ) ); return array( 'id' => (int) $event->id, 'status' => $status );
    }

    public static function resolve_outbox( $event_id, $note ) {
        if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'forbidden', 'Administrator permission is required.' ); global $wpdb; $table = $wpdb->prefix . MBS_OSM_OUTBOX_TABLE; $event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", (int) $event_id ) );
        if ( ! $event || ! in_array( $event->status, array( 'manual_reconciliation', 'awaiting_bank_match' ), true ) ) return new WP_Error( 'invalid_outbox_state', 'This event is not awaiting manual reconciliation.' ); $note = sanitize_text_field( $note ); if ( $note === '' ) return new WP_Error( 'resolution_note_required', 'Record what was checked in OSM.' );
        $wpdb->update( $table, array( 'status' => 'resolved', 'resolved_at' => current_time( 'mysql' ), 'resolved_by' => get_current_user_id(), 'last_error' => $note, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $event->id ) ); return true;
    }

    public static function retry_payout( $payout_id ) {
        if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'forbidden', 'Administrator permission is required.' );
        global $wpdb; $table = $wpdb->prefix . MBS_OSM_PAYOUT_TABLE;
        $payout = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", (int) $payout_id ) );
        if ( ! $payout || ! in_array( $payout->status, array( 'awaiting_bank_import', 'manual_reconciliation', 'sandbox_preview' ), true ) ) return new WP_Error( 'invalid_payout_state', 'Only a waiting, review or sandbox payout can be retried.' );
        return self::sync_woopayments( (string) $payout->payout_ref, (int) $payout->bank_transaction_id );
    }

    public static function resolve_payout( $payout_id, $note ) {
        if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'forbidden', 'Administrator permission is required.' );
        global $wpdb; $table = $wpdb->prefix . MBS_OSM_PAYOUT_TABLE;
        $payout = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", (int) $payout_id ) );
        if ( ! $payout || $payout->status !== 'manual_reconciliation' ) return new WP_Error( 'invalid_payout_state', 'This payout is not awaiting manual reconciliation.' );
        $note = sanitize_text_field( $note ); if ( $note === '' ) return new WP_Error( 'resolution_note_required', 'Record what was checked in WooPayments and OSM.' );
        $updated = $wpdb->update( $table, array( 'status' => 'resolved', 'resolved_at' => current_time( 'mysql' ), 'resolved_by' => get_current_user_id(), 'last_error' => $note, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $payout->id ) );
        return $updated === false ? new WP_Error( 'payout_resolution_failed', 'Could not resolve the payout.' ) : true;
    }

    private static function require_admin_ajax() { check_ajax_referer( 'mbs_admin_nonce', 'nonce' ); if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Administrator permission is required.' ), 403 ); }

    public function ajax_save_settings() {
        self::require_admin_ajax(); $auth = sanitize_key( $_POST['osm_auth_source'] ?? 'standalone' ); if ( ! in_array( $auth, array( 'standalone', 'gilbertweb' ), true ) ) $auth = 'standalone';
        $requested_enabled = ! empty( $_POST['osm_enabled'] );
        // Keep delivery disabled until every required mapping has validated.
        // This avoids turning a partially saved admin form into a payment-flow
        // outage, because outbox insertion is deliberately transactional.
        update_option( 'mbs_osm_enabled', false ); update_option( 'mbs_osm_sandbox_mode', ! empty( $_POST['osm_sandbox_mode'] ) ); update_option( 'mbs_osm_auth_source', $auth );
        if ( ! defined( 'MBS_OSM_CLIENT_ID' ) && trim( (string) ( $_POST['osm_client_id'] ?? '' ) ) !== '' ) update_option( 'mbs_osm_client_id', sanitize_text_field( $_POST['osm_client_id'] ) );
        if ( ! defined( 'MBS_OSM_CLIENT_SECRET' ) && trim( (string) ( $_POST['osm_client_secret'] ?? '' ) ) !== '' ) update_option( 'mbs_osm_client_secret', sanitize_text_field( $_POST['osm_client_secret'] ) );
        $numeric = array( 'osm_section_id' => 'mbs_osm_section_id', 'osm_bank_account_id' => 'mbs_osm_bank_account_id', 'osm_venue_category_id' => 'mbs_osm_venue_category_id', 'osm_venue_item_id' => 'mbs_osm_venue_item_id', 'osm_clothing_category_id' => 'mbs_osm_clothing_category_id', 'osm_clothing_item_id' => 'mbs_osm_clothing_item_id', 'osm_fees_category_id' => 'mbs_osm_fees_category_id', 'osm_fees_item_id' => 'mbs_osm_fees_item_id' ); foreach ( $numeric as $post => $option ) update_option( $option, preg_replace( '/\D+/', '', (string) ( $_POST[$post] ?? '' ) ) );
        update_option( 'mbs_osm_description_template', sanitize_text_field( $_POST['osm_description_template'] ?? 'WooPayments payout {payout_id}' ) ); update_option( 'mbs_osm_match_days', max( 0, min( 7, (int) ( $_POST['osm_match_days'] ?? 3 ) ) ) ); update_option( 'mbs_osm_product_mappings', wp_json_encode( self::decode_mappings( wp_unslash( $_POST['osm_product_mappings'] ?? '[]' ) ) ) );
        update_option( 'mbs_osm_configuration_version', '2', false );
        $candidate = self::get_settings(); $candidate['enabled'] = $requested_enabled;
        $valid = self::validate_enabled_configuration( $candidate ); if ( is_wp_error( $valid ) ) wp_send_json_error( array( 'message' => $valid->get_error_message() . ' Settings were saved, but the integration remains disabled.', 'saved' => true ), 422 );
        update_option( 'mbs_osm_enabled', $requested_enabled );
        wp_send_json_success( array( 'saved' => true, 'message' => 'OSM accounting settings saved.' ) );
    }

    public function ajax_test_connection() {
        self::require_admin_ajax(); $resource = self::api_call( 'GET', '/oauth/resource' ); if ( is_wp_error( $resource ) ) wp_send_json_error( array( 'message' => $resource->get_error_message(), 'code' => $resource->get_error_code() ) ); $settings = self::get_settings();
        if ( $settings['section_id'] ) { $finance = self::api_call( 'GET', '/v3/finances/accounting/bank_accounts/section/' . (int) $settings['section_id'] ); if ( is_wp_error( $finance ) ) wp_send_json_error( array( 'message' => 'Authentication works, but finance access failed: ' . $finance->get_error_message() ) ); }
        $name = $resource['data']['firstname'] ?? $resource['firstname'] ?? 'OSM user'; wp_send_json_success( array( 'message' => 'Connected to OSM as ' . sanitize_text_field( $name ) . ' with finance access.' ) );
    }

    public function ajax_get_sections() { self::require_admin_ajax(); $result = self::api_call( 'GET', '/api.php', array( 'action' => 'getUserRoles' ) ); if ( is_wp_error( $result ) ) wp_send_json_error( array( 'message' => $result->get_error_message() ) ); wp_send_json_success( $result ); }

    public function ajax_discover() {
        self::require_admin_ajax(); $kind = sanitize_key( $_POST['kind'] ?? '' ); if ( $kind === 'sections' ) return $this->ajax_get_sections(); $section = (int) ( $_POST['section_id'] ?? self::get_settings()['section_id'] ); if ( ! $section ) wp_send_json_error( array( 'message' => 'Choose an OSM section first.' ), 422 );
        $paths = array( 'bank_accounts' => '/v3/finances/accounting/bank_accounts/section/' . $section, 'categories' => '/v3/finances/accounting/categories/section/' . $section, 'items' => '/v3/finances/accounting/items/section/' . $section ); if ( ! isset( $paths[$kind] ) ) wp_send_json_error( array( 'message' => 'Unknown discovery type.' ), 400 );
        $result = self::api_call( 'GET', $paths[$kind] ); if ( is_wp_error( $result ) ) wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ) ); wp_send_json_success( $result );
    }

    public function ajax_sync_woopayments() { self::require_admin_ajax(); $result = self::sync_woopayments( sanitize_text_field( $_POST['payout_id'] ?? '' ), (int) ( $_POST['bank_transaction_id'] ?? 0 ) ); if ( is_wp_error( $result ) ) wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ) ); wp_send_json_success( $result ); }
    public function ajax_retry_event() { self::require_admin_ajax(); $entity = sanitize_key( $_POST['entity_type'] ?? 'event' ); $result = $entity === 'payout' ? self::retry_payout( (int) ( $_POST['event_id'] ?? 0 ) ) : self::retry_outbox( (int) ( $_POST['event_id'] ?? 0 ), (int) ( $_POST['bank_transaction_id'] ?? 0 ) ); if ( is_wp_error( $result ) ) wp_send_json_error( array( 'message' => $result->get_error_message() ) ); wp_send_json_success( $result ); }
    public function ajax_resolve_event() { self::require_admin_ajax(); $entity = sanitize_key( $_POST['entity_type'] ?? 'event' ); $note = sanitize_text_field( $_POST['note'] ?? '' ); $result = $entity === 'payout' ? self::resolve_payout( (int) ( $_POST['event_id'] ?? 0 ), $note ) : self::resolve_outbox( (int) ( $_POST['event_id'] ?? 0 ), $note ); if ( is_wp_error( $result ) ) wp_send_json_error( array( 'message' => $result->get_error_message() ) ); wp_send_json_success( array( 'resolved' => true ) ); }
}
