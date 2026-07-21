<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * OSM (Online Scout Manager) Integration
 *
 * Pushes financial data to OSM when a booking is marked as PAID.
 * Reuses OAuth tokens from the GilbertWeb Connector plugin (if installed)
 * or manages its own credentials.
 *
 * OSM API: https://www.onlinescoutmanager.co.uk
 * OAuth 2.0 Authorization Code flow with Bearer token auth.
 *
 * Settings stored in wp_options with prefix mbs_osm_
 */
class MBS_OSM_Integration {

    const OSM_DOMAIN     = 'https://www.onlinescoutmanager.co.uk';
    const OSM_AUTHORIZE  = self::OSM_DOMAIN . '/oauth/authorize';
    const OSM_TOKEN      = self::OSM_DOMAIN . '/oauth/token';
    const OSM_RESOURCE   = self::OSM_DOMAIN . '/oauth/resource';

    // Finance endpoints (community-documented, may require discovery)
    const OSM_FINANCE_GET_SCHEMES   = self::OSM_DOMAIN . '/ext/finances/onlinepayments/?action=getSchemes';
    const OSM_FINANCE_ADD_RECORD    = self::OSM_DOMAIN . '/ext/finances/?action=addRecord&sectionid=%s';
    const OSM_FINANCE_GET_ACCOUNTS  = self::OSM_DOMAIN . '/ext/finances/accounts/?action=getAccounts&sectionid=%s';

    // The GilbertWeb Connector plugin slug — we read tokens from this if available
    const GWC_SLUG = 'gilbertweb-connector-waiting-list-manager';

    public function init() {
        // Hook into booking status changes
        add_action( 'mbs_booking_status_changed', array( $this, 'on_status_change' ), 10, 3 );

        // Fallback: also hook directly into the places where status is set to paid
	        add_action( 'mbs_booking_paid', array( $this, 'push_payment_to_osm' ), 10, 2 );
	        add_action( 'mbs_process_osm_outbox', array( __CLASS__, 'deliver_outbox_event' ) );
	        add_action( 'init', array( __CLASS__, 'schedule_outbox_recovery' ) );
	        add_action( 'admin_notices', array( __CLASS__, 'outbox_health_notice' ) );

        // Admin settings tab
        add_action( 'wp_ajax_mbs_save_osm_settings', array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_mbs_test_osm_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_mbs_osm_get_sections', array( $this, 'ajax_get_sections' ) );
    }

    // ── Settings ───────────────────────────────────────────────────────────────

    /**
     * Get all OSM integration settings.
     */
    public static function get_settings() {
        return array(
            'enabled'          => (bool) get_option( 'mbs_osm_enabled', false ),
            'sandbox_mode'     => (bool) get_option( 'mbs_osm_sandbox_mode', true ),
            'auth_source'      => get_option( 'mbs_osm_auth_source', 'gilbertweb' ), // 'gilbertweb' or 'standalone'
            'client_id'        => get_option( 'mbs_osm_client_id', '' ),
            'client_secret'    => get_option( 'mbs_osm_client_secret', '' ),
            'section_id'       => get_option( 'mbs_osm_section_id', '' ),
            'category_id'      => get_option( 'mbs_osm_category_id', '' ),
            'account_id'       => get_option( 'mbs_osm_account_id', '' ),
            'description_tpl'  => get_option( 'mbs_osm_description_template', 'Hall Hire: {ref} - {name}' ),
        );
    }

    /**
     * Check if GilbertWeb Connector is installed and has valid tokens.
     */
    public static function gilbertweb_available() {
        $token = get_option( self::GWC_SLUG . '_osm_access_token_data' );
        return ! empty( $token );
    }

    /**
     * Get a valid access token — either from GilbertWeb Connector or standalone.
     */
    private static function get_access_token() {
        $settings = self::get_settings();

        if ( $settings['auth_source'] === 'gilbertweb' && self::gilbertweb_available() ) {
            return self::get_gilbertweb_token();
        }

        return self::get_standalone_token();
    }

    /**
     * Read the access token from the GilbertWeb Connector plugin's stored options.
     */
    private static function get_gilbertweb_token() {
        $token_data = get_option( self::GWC_SLUG . '_osm_access_token_data' );
        $expiry     = get_option( self::GWC_SLUG . '_osm_access_token_expiry' );

        if ( empty( $token_data ) || ! isset( $token_data->access_token ) ) {
            return null;
        }

        // Check if expired
        if ( $expiry && time() > (int) $expiry ) {
            // Try to refresh using GilbertWeb's refresh token
            $refreshed = self::refresh_gilbertweb_token();
            if ( ! $refreshed ) return null;
            $token_data = get_option( self::GWC_SLUG . '_osm_access_token_data' );
        }

        return $token_data->access_token ?? null;
    }

    /**
     * Refresh the GilbertWeb Connector's token using its stored refresh token.
     */
    private static function refresh_gilbertweb_token() {
        $refresh_token = get_option( self::GWC_SLUG . '_osm_refresh_token' );
        if ( empty( $refresh_token ) ) return false;

        // Read credentials from ACF options (same keys as GilbertWeb Connector)
        $client_id     = function_exists( 'get_field' )
            ? get_field( self::GWC_SLUG . '_osm_oauth_client_id', 'option' )
            : get_option( 'options_' . self::GWC_SLUG . '_osm_oauth_client_id' );
        $client_secret = function_exists( 'get_field' )
            ? get_field( self::GWC_SLUG . '_osm_oauth_secret', 'option' )
            : get_option( 'options_' . self::GWC_SLUG . '_osm_oauth_secret' );

        if ( empty( $client_id ) || empty( $client_secret ) ) return false;

        $response = wp_remote_post( self::OSM_TOKEN, array(
            'timeout' => 30,
            'body'    => array(
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh_token,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'scope'         => 'section:finance:read section:finance:write',
            ),
            'headers' => array( 'Accept' => 'application/json' ),
        ) );

        if ( is_wp_error( $response ) ) return false;

        $data = json_decode( wp_remote_retrieve_body( $response ) );
        if ( empty( $data->access_token ) ) return false;

        update_option( self::GWC_SLUG . '_osm_access_token_data', $data );
        update_option( self::GWC_SLUG . '_osm_access_token_expiry', time() + ( $data->expires_in ?? 3600 ) );
        if ( ! empty( $data->refresh_token ) ) {
            update_option( self::GWC_SLUG . '_osm_refresh_token', $data->refresh_token );
        }

        return true;
    }

    /**
     * Get standalone access token (when GilbertWeb Connector is not available).
     */
    private static function get_standalone_token() {
        $token_data = get_option( 'mbs_osm_access_token_data' );
        $expiry     = get_option( 'mbs_osm_access_token_expiry' );

        if ( empty( $token_data ) || ! isset( $token_data->access_token ) ) {
            return null;
        }

        if ( $expiry && time() > (int) $expiry ) {
            $refreshed = self::refresh_standalone_token();
            if ( ! $refreshed ) return null;
            $token_data = get_option( 'mbs_osm_access_token_data' );
        }

        return $token_data->access_token ?? null;
    }

    /**
     * Refresh standalone token.
     */
    private static function refresh_standalone_token() {
        $refresh_token = get_option( 'mbs_osm_refresh_token' );
        $settings      = self::get_settings();

        if ( empty( $refresh_token ) || empty( $settings['client_id'] ) ) return false;

        $response = wp_remote_post( self::OSM_TOKEN, array(
            'timeout' => 30,
            'body'    => array(
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh_token,
                'client_id'     => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'scope'         => 'section:finance:read section:finance:write',
            ),
            'headers' => array( 'Accept' => 'application/json' ),
        ) );

        if ( is_wp_error( $response ) ) return false;

        $data = json_decode( wp_remote_retrieve_body( $response ) );
        if ( empty( $data->access_token ) ) return false;

        update_option( 'mbs_osm_access_token_data', $data );
        update_option( 'mbs_osm_access_token_expiry', time() + ( $data->expires_in ?? 3600 ) );
        if ( ! empty( $data->refresh_token ) ) {
            update_option( 'mbs_osm_refresh_token', $data->refresh_token );
        }

        return true;
    }

    // ── API Calls ──────────────────────────────────────────────────────────────

    /**
     * Make an authenticated API call to OSM.
     */
    private static function api_call( $method, $url, $body = array() ) {
        $token = self::get_access_token();
        if ( ! $token ) {
            error_log( '[MBS-OSM] No valid access token available.' );
            return new \WP_Error( 'no_token', 'No valid OSM access token.' );
        }

        $args = array(
            'timeout' => 30,
            'method'  => strtoupper( $method ),
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ),
        );

        if ( ! empty( $body ) ) {
            $args['body'] = $body;
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            error_log( '[MBS-OSM] API error: ' . $response->get_error_message() );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            error_log( '[MBS-OSM] API HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
            return new \WP_Error( 'api_error', 'OSM API returned HTTP ' . $code, array( 'response_code' => $code, 'body' => $data ) );
        }

        return $data;
    }

    // ── Payment Push ───────────────────────────────────────────────────────────

    /**
     * Build the OSM finance payload from a booking.
     */
    public static function build_payload( $booking ) {
        $settings = self::get_settings();

        // Replace placeholders in description template
        $description = str_replace(
            array( '{ref}', '{name}', '{space}', '{date}', '{purpose}', '{organisation}' ),
            array(
                $booking->ref,
                $booking->name,
                $booking->space,
                $booking->booking_date,
                $booking->purpose ?? '',
                $booking->organisation ?? '',
            ),
            $settings['description_tpl']
        );

        return array(
            'sectionid'   => $settings['section_id'],
            'categoryid'  => $settings['category_id'],
            'accountid'   => $settings['account_id'],
            'amount'      => number_format( (float) $booking->amount, 2, '.', '' ),
            'type'        => 'income',
            'description' => $description,
            'date'        => wp_date( 'Y-m-d' ), // Date payment was received
            'ref'         => $booking->ref,
            'name'        => $booking->name,
        );
    }

    /**
     * Push a payment record to OSM when a booking is marked as paid.
     *
     * @param object $booking  The booking object
     * @param int    $order_id Optional WooCommerce order ID
     */
	    public function push_payment_to_osm( $booking, $order_id = 0 ) {
        $settings = self::get_settings();

        if ( ! $settings['enabled'] ) return;
        if ( empty( $settings['section_id'] ) ) {
            error_log( '[MBS-OSM] Cannot push to OSM: no section ID configured.' );
            return;
        }

        $payload = self::build_payload( $booking );

        // ── Sandbox Mode: log instead of sending ───────────────────────────────
        if ( $settings['sandbox_mode'] ) {
            error_log( '[MBS-OSM] SANDBOX MODE — Would POST to OSM:' );
            error_log( '[MBS-OSM] Endpoint: ' . sprintf( self::OSM_FINANCE_ADD_RECORD, $settings['section_id'] ) );
            error_log( '[MBS-OSM] Payload: ' . wp_json_encode( $payload, JSON_PRETTY_PRINT ) );
            error_log( '[MBS-OSM] Booking: ' . $booking->ref . ' | Amount: £' . $booking->amount . ' | Hirer: ' . $booking->name );

            MBS_Audit_Log::log(
                $booking->ref,
                'osm_sandbox',
                'OSM sandbox: payment payload logged (£' . number_format( $booking->amount, 2 ) . ')'
            );
            return;
        }

        // ── Live Mode: POST to OSM ────────────────────────────────────────────
        $url    = sprintf( self::OSM_FINANCE_ADD_RECORD, $settings['section_id'] );
        $result = self::api_call( 'POST', $url, $payload );

        if ( is_wp_error( $result ) ) {
            error_log( '[MBS-OSM] Failed to push payment for ' . $booking->ref . ': ' . $result->get_error_message() );
            MBS_Audit_Log::log(
                $booking->ref,
                'osm_error',
                'OSM finance push failed: ' . $result->get_error_message()
            );
            return;
        }

        MBS_Audit_Log::log(
            $booking->ref,
            'osm_synced',
            'Payment pushed to OSM (Section: ' . $settings['section_id'] . ', £' . number_format( $booking->amount, 2 ) . ')'
        );

	        error_log( '[MBS-OSM] Successfully pushed payment for ' . $booking->ref . ' to OSM.' );
	    }

	    /** Inserted while the caller's refund transaction is still open. */
	    public static function queue_refund_reversal( $booking, $invoice_ref, $amount_minor, $order_id, $refund_id, $reversal_kind = '' ) {
	        global $wpdb;
	        $settings = self::get_settings();
	        if ( ! $settings['enabled'] ) return 0;
	        $amount = MBS_Money::decimal( (int) $amount_minor );
	        if ( is_wp_error( $amount ) ) return $amount;
	        $booking_minor = MBS_Money::from_decimal_string( (string) $booking->amount );
	        $kind = in_array( $reversal_kind, array('partial','full'), true ) ? $reversal_kind : ( ! is_wp_error( $booking_minor ) && (int) $amount_minor >= (int) $booking_minor ? 'full' : 'partial' );
	        $payload = self::build_payload( $booking );
	        $payload['amount'] = $amount;
	        $payload['type'] = 'expenditure';
	        $payload['description'] = 'Refund: ' . $payload['description'];
	        $payload['order_id'] = (int) $order_id;
	        $payload['refund_id'] = (int) $refund_id;
	        $payload['reversal_kind'] = $kind;
	        $event_ref = 'osm-refund:' . sanitize_text_field( $invoice_ref ) . ':' . (int) $refund_id . ':' . sanitize_text_field( $booking->ref );
	        $table = $wpdb->prefix . MBS_OSM_OUTBOX_TABLE;
	        $inserted = $wpdb->insert( $table, array(
	            'event_ref' => $event_ref, 'booking_ref' => $booking->ref, 'invoice_ref' => $invoice_ref,
	            'order_id' => (int) $order_id, 'refund_id' => (int) $refund_id, 'amount_minor' => (int) $amount_minor,
	            'reversal_kind' => $kind, 'payload_json' => wp_json_encode( $payload ), 'status' => 'pending',
	            'attempts' => 0, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
	        ) );
	        if ( $inserted === false ) {
	            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_ref=%s", $event_ref ) );
	            if ( $existing && (int)$existing->amount_minor === (int)$amount_minor && (int)$existing->refund_id === (int)$refund_id ) return (int)$existing->id;
	            return new WP_Error( 'osm_outbox_insert_failed', 'Could not durably queue the OSM reversal: ' . $wpdb->last_error );
	        }
	        return (int) $wpdb->insert_id;
	    }

	    public static function deliver_outbox_event( $event_id ) {
	        global $wpdb;
	        $table = $wpdb->prefix . MBS_OSM_OUTBOX_TABLE;
	        $event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", (int)$event_id ) );
	        if ( ! $event || ! in_array( $event->status, array( 'pending','retry' ), true ) ) return false;
	        $claimed = $wpdb->query( $wpdb->prepare(
	            "UPDATE {$table} SET status='processing',attempts=attempts+1,updated_at=%s WHERE id=%d AND status=%s",
	            current_time('mysql'), (int)$event->id, $event->status
	        ) );
	        if ( $claimed !== 1 ) return false;
	        $event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", (int)$event->id ) );
	        $payload = json_decode( $event->payload_json, true );
	        $settings = self::get_settings();
	        if ( ! is_array( $payload ) || empty( $settings['section_id'] ) ) {
	            return self::mark_manual_reconciliation( $event, 'OSM reversal payload or section configuration is missing.', null );
	        }
	        if ( $settings['sandbox_mode'] ) {
	            $wpdb->update( $table, array( 'status'=>'delivered','delivered_at'=>current_time('mysql'),'updated_at'=>current_time('mysql'),'last_error'=>'' ), array( 'id'=>(int)$event->id ) );
	            MBS_Audit_Log::log( $event->booking_ref, 'osm_sandbox_refund', 'OSM sandbox: refund reversal payload logged (' . MBS_Money::format((int)$event->amount_minor) . ').' );
	            return true;
	        }
	        $result = self::api_call( 'POST', sprintf( self::OSM_FINANCE_ADD_RECORD, $settings['section_id'] ), $payload );
	        if ( ! is_wp_error( $result ) ) {
	            $wpdb->update( $table, array( 'status'=>'delivered','delivered_at'=>current_time('mysql'),'updated_at'=>current_time('mysql'),'last_error'=>'' ), array( 'id'=>(int)$event->id ) );
	            MBS_Audit_Log::log( $event->booking_ref, 'osm_refund_synced', 'Refund reversal ' . $event->event_ref . ' pushed to OSM (' . MBS_Money::format((int)$event->amount_minor) . ').' );
	            return true;
	        }
	        $data = $result->get_error_data();
	        $code = is_array( $data ) && isset( $data['response_code'] ) ? (int)$data['response_code'] : 0;
	        // Network errors and timeouts are ambiguous: without a remote
	        // idempotency contract, blind retry could create duplicate spending.
	        if ( $code === 0 || ( $code >= 400 && $code < 500 ) || (int)$event->attempts >= 5 ) {
	            return self::mark_manual_reconciliation( $event, $result->get_error_message(), $code ?: null );
	        }
	        $backoff = array( 60, 300, 1800, 7200 );
	        $delay = $backoff[min(count($backoff)-1,max(0,(int)$event->attempts-1))];
	        $next = gmdate( 'Y-m-d H:i:s', time() + $delay );
	        $wpdb->update( $table, array( 'status'=>'retry','next_attempt_at'=>$next,'last_error'=>$result->get_error_message(),'response_code'=>$code,'updated_at'=>current_time('mysql') ), array( 'id'=>(int)$event->id ) );
	        wp_schedule_single_event( time()+$delay, 'mbs_process_osm_outbox', array( (int)$event->id ) );
	        MBS_Audit_Log::log( $event->booking_ref, 'osm_refund_retry', 'OSM reversal ' . $event->event_ref . ' scheduled for bounded retry.' );
	        return false;
	    }

	    private static function mark_manual_reconciliation( $event, $message, $response_code ) {
	        global $wpdb;
	        $wpdb->update( $wpdb->prefix.MBS_OSM_OUTBOX_TABLE, array(
	            'status'=>'manual_reconciliation','last_error'=>sanitize_text_field($message),'response_code'=>$response_code,'updated_at'=>current_time('mysql')
	        ), array( 'id'=>(int)$event->id ) );
	        MBS_Audit_Log::log( $event->booking_ref, 'osm_refund_reconcile', 'OSM reversal ' . $event->event_ref . ' requires manual reconciliation: ' . sanitize_text_field($message) );
	        return false;
	    }

	    public static function schedule_outbox_recovery() {
	        global $wpdb;
	        $table = $wpdb->prefix . MBS_OSM_OUTBOX_TABLE;
	        if ( $wpdb->get_var( $wpdb->prepare('SHOW TABLES LIKE %s',$table) ) !== $table ) return;
	        $ids = $wpdb->get_col( "SELECT id FROM {$table} WHERE status='pending' OR (status='retry' AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP())) ORDER BY id ASC LIMIT 20" );
	        foreach ( $ids as $id ) if ( ! wp_next_scheduled( 'mbs_process_osm_outbox', array((int)$id) ) ) wp_schedule_single_event( time()+5, 'mbs_process_osm_outbox', array((int)$id) );
	    }

	    public static function outbox_health_notice() {
	        if ( ! current_user_can( 'manage_options' ) ) return;
	        global $wpdb; $table=$wpdb->prefix.MBS_OSM_OUTBOX_TABLE;
	        if ( $wpdb->get_var( $wpdb->prepare('SHOW TABLES LIKE %s',$table) ) !== $table ) return;
	        $count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='manual_reconciliation'");
	        if($count)echo '<div class="notice notice-error"><p><strong>MGF Venue OSM reconciliation required.</strong> '.esc_html($count).' refund reversal(s) need administrator review before another OSM entry is attempted.</p></div>';
	    }

	    public static function retry_outbox( $event_id ) {
	        if ( ! current_user_can('manage_options') ) return new WP_Error('forbidden','Administrator permission is required.');
	        global $wpdb; $table=$wpdb->prefix.MBS_OSM_OUTBOX_TABLE;
	        $event=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d",(int)$event_id));
	        if(!$event||$event->status!=='manual_reconciliation')return new WP_Error('invalid_outbox_state','Only reconciliation events can be retried explicitly.');
	        $wpdb->update($table,array('status'=>'pending','next_attempt_at'=>null,'updated_at'=>current_time('mysql')),array('id'=>(int)$event->id));
	        MBS_Audit_Log::log($event->booking_ref,'osm_refund_admin_retry','Administrator '.get_current_user_id().' retried '.$event->event_ref.'.');
	        return self::deliver_outbox_event((int)$event->id);
	    }

	    public static function resolve_outbox( $event_id, $note ) {
	        if ( ! current_user_can('manage_options') ) return new WP_Error('forbidden','Administrator permission is required.');
	        global $wpdb; $table=$wpdb->prefix.MBS_OSM_OUTBOX_TABLE;
	        $event=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d",(int)$event_id));
	        if(!$event||$event->status!=='manual_reconciliation')return new WP_Error('invalid_outbox_state','Only reconciliation events can be resolved.');
	        $wpdb->update($table,array('status'=>'resolved','resolved_at'=>current_time('mysql'),'resolved_by'=>get_current_user_id(),'last_error'=>sanitize_text_field($note),'updated_at'=>current_time('mysql')),array('id'=>(int)$event->id));
	        MBS_Audit_Log::log($event->booking_ref,'osm_refund_resolved','Administrator '.get_current_user_id().' resolved '.$event->event_ref.': '.sanitize_text_field($note));
	        return true;
	    }

    /**
     * Hook: when any booking status changes, check if it's now PAID.
     */
    public function on_status_change( $ref, $old_status, $new_status ) {
        if ( $new_status !== 'paid' ) return;

        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) return;

        $this->push_payment_to_osm( $booking );
    }

    // ── AJAX Handlers ──────────────────────────────────────────────────────────

    /**
     * Save OSM integration settings.
     */
    public function ajax_save_settings() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'You do not have permission to perform this action.', 403 );
        }

        update_option( 'mbs_osm_enabled',              ! empty( $_POST['osm_enabled'] ) );
        update_option( 'mbs_osm_sandbox_mode',          ! empty( $_POST['osm_sandbox_mode'] ) );
        update_option( 'mbs_osm_auth_source',           sanitize_text_field( $_POST['osm_auth_source'] ?? 'gilbertweb' ) );
        update_option( 'mbs_osm_client_id',             sanitize_text_field( $_POST['osm_client_id'] ?? '' ) );
        update_option( 'mbs_osm_client_secret',         sanitize_text_field( $_POST['osm_client_secret'] ?? '' ) );
        update_option( 'mbs_osm_section_id',            sanitize_text_field( $_POST['osm_section_id'] ?? '' ) );
        update_option( 'mbs_osm_category_id',           sanitize_text_field( $_POST['osm_category_id'] ?? '' ) );
        update_option( 'mbs_osm_account_id',            sanitize_text_field( $_POST['osm_account_id'] ?? '' ) );
        update_option( 'mbs_osm_description_template',  sanitize_text_field( $_POST['osm_description_template'] ?? 'Hall Hire: {ref} - {name}' ) );

        wp_send_json_success( array( 'saved' => true ) );
    }

    /**
     * Test the OSM API connection.
     */
    public function ajax_test_connection() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'You do not have permission to perform this action.', 403 );
        }

        $token = self::get_access_token();
        if ( ! $token ) {
            wp_send_json_error( 'No valid access token. Please check your credentials or ensure GilbertWeb Connector is authenticated.' );
        }

        // Test by calling the resource endpoint
        $result = self::api_call( 'GET', self::OSM_RESOURCE );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( 'Connection failed: ' . $result->get_error_message() );
        }

        $user_name = $result['data']['firstname'] ?? 'Unknown';
        wp_send_json_success( array(
            'message'   => 'Connected to OSM as: ' . $user_name,
            'user_data' => $result['data'] ?? array(),
        ) );
    }

    /**
     * Get available OSM sections for the dropdown.
     */
    public function ajax_get_sections() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'You do not have permission to perform this action.', 403 );
        }

        $result = self::api_call( 'GET', self::OSM_DOMAIN . '/api.php?action=getUserRoles' );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( 'Could not fetch sections: ' . $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }
}
