<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * OSM (Online Scout Manager) Integration
 *
 * Records booking income in OSM's finance / accountancy tools. Every real money
 * event (deposit received, balance / full payment, refund) is written to a
 * local sync ledger (wp_mathlin_osm_ledger) and pushed to OSM from there, so:
 *   - each payment event is recorded exactly once (idempotent via a unique
 *     source_ref — a re-fired hook or a retried WooCommerce webhook can't create
 *     a duplicate income record);
 *   - deposits and balances appear as separate income records for the amounts
 *     ACTUALLY received, never the booking total;
 *   - refunds create a reversing (negative) record;
 *   - failed pushes are retried hourly by cron and can be retried manually from
 *     the reconciliation table on the OSM Integration admin page.
 *
 * Authentication: OAuth 2.0 Authorization Code flow. "Standalone" mode has a
 * full Connect-to-OSM handshake (admin-post start + callback handlers below).
 * Alternatively, tokens can be borrowed from the GilbertWeb Connector plugin.
 *
 * IMPORTANT — the finance endpoint and payload shape are community-documented
 * and UNVERIFIED against a live OSM section. Keep Sandbox Mode ON until OSM has
 * confirmed finance-write API access for your section and one real
 * request/response has been captured. The endpoint and payload are filterable
 * (mbs_osm_finance_endpoint / mbs_osm_finance_payload) so they can be corrected
 * without shipping a new release.
 *
 * Settings stored in wp_options with prefix mbs_osm_.
 */
class MBS_OSM_Integration {

    const OSM_DOMAIN     = 'https://www.onlinescoutmanager.co.uk';
    const OSM_AUTHORIZE  = self::OSM_DOMAIN . '/oauth/authorize';
    const OSM_TOKEN      = self::OSM_DOMAIN . '/oauth/token';
    const OSM_RESOURCE   = self::OSM_DOMAIN . '/oauth/resource';
    const OAUTH_SCOPE    = 'section:finance:read section:finance:write';

    // Finance endpoint (community-documented — UNVERIFIED, keep sandbox on).
    const OSM_FINANCE_ADD_RECORD = self::OSM_DOMAIN . '/ext/finances/?action=addRecord&sectionid=%s';

    // The GilbertWeb Connector plugin slug — we read tokens from this if available.
    const GWC_SLUG = 'gilbertweb-connector-waiting-list-manager';

    // Placeholder rendered in the settings UI in place of a stored secret so we
    // never echo the real value back into the page source.
    const SECRET_MASK = '__mbs_secret_unchanged__';

    // How many times a ledger row is retried before it is parked as 'failed'.
    const MAX_ATTEMPTS = 6;

    public function init() {
        // Single money-event hook fired at every point a payment/refund lands.
        // Signature: ( string $ref, float $amount, string $type, string $source_ref )
        add_action( 'mbs_payment_recorded', array( $this, 'record_payment' ), 10, 4 );

        // Retry queue for finance pushes that failed (OSM down, token expired…).
        add_action( 'mbs_osm_retry', array( $this, 'process_retry_queue' ) );
        if ( ! wp_next_scheduled( 'mbs_osm_retry' ) ) {
            wp_schedule_event( time() + 600, 'hourly', 'mbs_osm_retry' );
        }

        // OAuth Authorization Code handshake (Standalone mode).
        add_action( 'admin_post_mbs_osm_connect',    array( $this, 'handle_oauth_connect' ) );
        add_action( 'admin_post_mbs_osm_callback',   array( $this, 'handle_oauth_callback' ) );
        add_action( 'admin_post_mbs_osm_disconnect', array( $this, 'handle_oauth_disconnect' ) );

        // Admin settings / reconciliation AJAX.
        add_action( 'wp_ajax_mbs_save_osm_settings',   array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_mbs_test_osm_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_mbs_osm_get_sections',    array( $this, 'ajax_get_sections' ) );
        add_action( 'wp_ajax_mbs_osm_retry_row',       array( $this, 'ajax_retry_row' ) );
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'mbs_osm_retry' );
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
     * The redirect URI OSM must call back after authorization. Register this
     * exact URL in your OSM OAuth application.
     */
    public static function redirect_uri() {
        return admin_url( 'admin-post.php?action=mbs_osm_callback' );
    }

    /**
     * Whether we currently hold a usable token — used by the settings UI to show
     * connected/disconnected state without exposing the token itself.
     */
    public static function is_connected() {
        return (bool) self::get_access_token();
    }

    // ── OAuth: standalone Authorization Code handshake ──────────────────────────

    /**
     * Kick off the OAuth handshake: redirect the admin to OSM's consent screen.
     */
    public function handle_oauth_connect() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );
        check_admin_referer( 'mbs_osm_connect' );

        $settings = self::get_settings();
        if ( empty( $settings['client_id'] ) ) {
            wp_safe_redirect( add_query_arg( 'mbs_osm', 'noclient', admin_url( 'admin.php?page=mathlin-osm' ) ) );
            exit;
        }

        // CSRF state — short-lived, single-use.
        $state = wp_generate_password( 24, false );
        set_transient( 'mbs_osm_oauth_state', $state, 15 * MINUTE_IN_SECONDS );

        $url = add_query_arg( array(
            'response_type' => 'code',
            'client_id'     => rawurlencode( $settings['client_id'] ),
            'redirect_uri'  => rawurlencode( self::redirect_uri() ),
            'scope'         => rawurlencode( self::OAUTH_SCOPE ),
            'state'         => $state,
        ), self::OSM_AUTHORIZE );

        wp_redirect( $url ); // external host — wp_safe_redirect would block it
        exit;
    }

    /**
     * OAuth callback: verify state, exchange the code for tokens, store them.
     */
    public function handle_oauth_callback() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $back = admin_url( 'admin.php?page=mathlin-osm' );

        $state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        $expected = get_transient( 'mbs_osm_oauth_state' );
        delete_transient( 'mbs_osm_oauth_state' );

        if ( ! $state || ! $expected || ! hash_equals( (string) $expected, $state ) ) {
            wp_safe_redirect( add_query_arg( 'mbs_osm', 'badstate', $back ) );
            exit;
        }

        if ( isset( $_GET['error'] ) ) {
            wp_safe_redirect( add_query_arg( 'mbs_osm', 'denied', $back ) );
            exit;
        }

        $code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
        if ( ! $code ) {
            wp_safe_redirect( add_query_arg( 'mbs_osm', 'nocode', $back ) );
            exit;
        }

        $settings = self::get_settings();
        $response = wp_remote_post( self::OSM_TOKEN, array(
            'timeout' => 30,
            'body'    => array(
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => self::redirect_uri(),
                'client_id'     => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'scope'         => self::OAUTH_SCOPE,
            ),
            'headers' => array( 'Accept' => 'application/json' ),
        ) );

        if ( is_wp_error( $response ) ) {
            wp_safe_redirect( add_query_arg( 'mbs_osm', 'exchangefail', $back ) );
            exit;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ) );
        if ( empty( $data->access_token ) ) {
            wp_safe_redirect( add_query_arg( 'mbs_osm', 'exchangefail', $back ) );
            exit;
        }

        update_option( 'mbs_osm_access_token_data', $data );
        update_option( 'mbs_osm_access_token_expiry', time() + ( $data->expires_in ?? 3600 ) );
        if ( ! empty( $data->refresh_token ) ) {
            update_option( 'mbs_osm_refresh_token', $data->refresh_token );
        }

        wp_safe_redirect( add_query_arg( 'mbs_osm', 'connected', $back ) );
        exit;
    }

    /**
     * Forget the standalone tokens.
     */
    public function handle_oauth_disconnect() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );
        check_admin_referer( 'mbs_osm_disconnect' );

        delete_option( 'mbs_osm_access_token_data' );
        delete_option( 'mbs_osm_access_token_expiry' );
        delete_option( 'mbs_osm_refresh_token' );

        wp_safe_redirect( add_query_arg( 'mbs_osm', 'disconnected', admin_url( 'admin.php?page=mathlin-osm' ) ) );
        exit;
    }

    // ── Token resolution ────────────────────────────────────────────────────────

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

        if ( $expiry && time() > (int) $expiry ) {
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
                'scope'         => self::OAUTH_SCOPE,
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
     * Get standalone access token (obtained via the Connect handshake above).
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
                'scope'         => self::OAUTH_SCOPE,
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
     * Make an authenticated API call to OSM. On a 401 the token is refreshed
     * once and the call retried, so a silently-expired token doesn't fail a push.
     */
    private static function api_call( $method, $url, $body = array(), $allow_refresh = true ) {
        $token = self::get_access_token();
        if ( ! $token ) {
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

        // Expired/invalid token — refresh once and retry.
        if ( $code === 401 && $allow_refresh ) {
            $settings  = self::get_settings();
            $refreshed = ( $settings['auth_source'] === 'gilbertweb' )
                ? self::refresh_gilbertweb_token()
                : self::refresh_standalone_token();
            if ( $refreshed ) {
                return self::api_call( $method, $url, $body, false );
            }
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            error_log( '[MBS-OSM] API HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
            return new \WP_Error( 'api_error', 'OSM API returned HTTP ' . $code, $data );
        }

        return $data;
    }

    // ── Payment recording (ledger + idempotency) ────────────────────────────────

    /**
     * Record a money event against a booking. Called via the mbs_payment_recorded
     * action from every point a payment or refund lands (WooCommerce + manual
     * admin actions). Writes a ledger row (deduped on source_ref) and attempts an
     * immediate push; failures are left pending for the retry cron.
     *
     * @param string $ref        Booking reference.
     * @param float  $amount     Amount of THIS event (negative for a refund).
     * @param string $type       'deposit' | 'balance' | 'full' | 'refund'.
     * @param string $source_ref Stable unique key for this event (idempotency).
     */
    public function record_payment( $ref, $amount, $type = 'balance', $source_ref = '' ) {
        $settings = self::get_settings();
        if ( ! $settings['enabled'] ) return;

        $amount = round( (float) $amount, 2 );
        if ( abs( $amount ) < 0.01 ) return; // nothing to record (e.g. £0 scout booking)

        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) return;

        // Scout / internal bookings are not commercial income — never sync.
        if ( ! empty( $booking->scout_use ) ) return;

        if ( empty( $source_ref ) ) {
            $source_ref = $ref . ':' . $type . ':' . md5( microtime( true ) . wp_rand() );
        }

        global $wpdb;
        $ledger = $wpdb->prefix . 'mathlin_osm_ledger';

        // Idempotency: source_ref is UNIQUE. A duplicate insert (re-fired hook /
        // retried webhook) fails here and we simply stop — no double income record.
        $inserted = $wpdb->insert( $ledger, array(
            'booking_ref' => $ref,
            'source_ref'  => $source_ref,
            'type'        => in_array( $type, array( 'deposit', 'balance', 'full', 'refund' ), true ) ? $type : 'balance',
            'amount'      => $amount,
            'status'      => 'pending',
            'attempts'    => 0,
            'created_at'  => current_time( 'mysql' ),
        ) );

        if ( ! $inserted ) {
            // Duplicate source_ref (already recorded) or DB error — do not push again.
            return;
        }

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ledger} WHERE id = %d", $wpdb->insert_id ) );
        if ( $row ) {
            self::push_ledger_row( $row );
        }
    }

    /**
     * Attempt to push a single ledger row to OSM. Updates the row to synced or
     * records the failure for retry. Used by both the immediate path and cron.
     */
    private static function push_ledger_row( $row ) {
        $settings = self::get_settings();
        $booking  = MBS_Bookings::get( $row->booking_ref );

        global $wpdb;
        $ledger = $wpdb->prefix . 'mathlin_osm_ledger';

        if ( ! $booking ) {
            $wpdb->update( $ledger, array( 'status' => 'skipped', 'last_error' => 'Booking no longer exists.' ), array( 'id' => $row->id ) );
            return;
        }

        if ( empty( $settings['section_id'] ) ) {
            self::mark_failed( $row, 'No OSM section ID configured.' );
            return;
        }

        $payload = self::build_payload( $booking, (float) $row->amount, $row->type );

        // ── Sandbox Mode: log the exact payload, mark synced (simulated). ──────
        if ( $settings['sandbox_mode'] ) {
            error_log( '[MBS-OSM] SANDBOX — would POST finance record: ' . wp_json_encode( $payload ) );
            $wpdb->update( $ledger, array(
                'status'        => 'synced',
                'osm_record_id' => 'SANDBOX-' . $row->id,
                'attempts'      => (int) $row->attempts + 1,
                'synced_at'     => current_time( 'mysql' ),
                'last_error'    => '',
            ), array( 'id' => $row->id ) );
            MBS_Audit_Log::log( $booking->ref, 'osm_sandbox', 'OSM sandbox: ' . $row->type . ' £' . number_format( (float) $row->amount, 2 ) . ' logged (not sent).' );
            return;
        }

        // ── Live Mode ─────────────────────────────────────────────────────────
        $endpoint = apply_filters(
            'mbs_osm_finance_endpoint',
            sprintf( self::OSM_FINANCE_ADD_RECORD, rawurlencode( $settings['section_id'] ) ),
            $booking, $row
        );

        $result = self::api_call( 'POST', $endpoint, $payload );

        if ( is_wp_error( $result ) ) {
            self::mark_failed( $row, $result->get_error_message() );
            MBS_Audit_Log::log( $booking->ref, 'osm_error', 'OSM finance push failed (' . $row->type . ' £' . number_format( (float) $row->amount, 2 ) . '): ' . $result->get_error_message() );
            return;
        }

        // Try to capture OSM's returned record id for later reconciliation/reversal.
        $record_id = '';
        if ( is_array( $result ) ) {
            $record_id = $result['id'] ?? $result['recordid'] ?? $result['record_id'] ?? '';
        }

        $wpdb->update( $ledger, array(
            'status'        => 'synced',
            'osm_record_id' => (string) $record_id,
            'attempts'      => (int) $row->attempts + 1,
            'synced_at'     => current_time( 'mysql' ),
            'last_error'    => '',
        ), array( 'id' => $row->id ) );

        MBS_Audit_Log::log(
            $booking->ref,
            'osm_synced',
            'Recorded in OSM (' . $row->type . ' £' . number_format( (float) $row->amount, 2 ) . ', section ' . $settings['section_id'] . ')'
        );
    }

    /**
     * Mark a ledger row failed, parking it after MAX_ATTEMPTS so cron stops
     * hammering a permanently-broken push.
     */
    private static function mark_failed( $row, $error ) {
        global $wpdb;
        $ledger   = $wpdb->prefix . 'mathlin_osm_ledger';
        $attempts = (int) $row->attempts + 1;
        $wpdb->update( $ledger, array(
            'status'     => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
            'attempts'   => $attempts,
            'last_error' => substr( (string) $error, 0, 250 ),
        ), array( 'id' => $row->id ) );
    }

    /**
     * Cron: retry pending ledger rows. Rows parked as 'failed' are left for a
     * manual retry from the reconciliation table.
     */
    public function process_retry_queue() {
        $settings = self::get_settings();
        if ( ! $settings['enabled'] ) return;

        global $wpdb;
        $ledger = $wpdb->prefix . 'mathlin_osm_ledger';
        $rows   = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$ledger} WHERE status = 'pending' AND attempts < %d ORDER BY created_at ASC LIMIT 20",
            self::MAX_ATTEMPTS
        ) );

        foreach ( $rows as $row ) {
            self::push_ledger_row( $row );
        }
    }

    /**
     * Build the OSM finance payload for one payment event. Filterable so the
     * exact field names can be corrected once verified against the live API,
     * without a code release.
     */
    public static function build_payload( $booking, $amount, $type ) {
        $settings = self::get_settings();

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

        // Note the payment type + reference so it reconciles against bank / Woo.
        $description .= ' [' . ucfirst( $type ) . ' · ' . ( $booking->invoice_number ?: $booking->ref ) . ']';

        $payload = array(
            'sectionid'   => $settings['section_id'],
            'categoryid'  => $settings['category_id'],
            'accountid'   => $settings['account_id'],
            'amount'      => number_format( (float) $amount, 2, '.', '' ),
            'type'        => $amount < 0 ? 'expense' : 'income', // refunds book as a reversal
            'description' => $description,
            'date'        => wp_date( 'Y-m-d' ),
            'ref'         => $booking->ref,
            'name'        => $booking->name,
        );

        return apply_filters( 'mbs_osm_finance_payload', $payload, $booking, $amount, $type );
    }

    // ── Reconciliation helpers (admin view) ─────────────────────────────────────

    /**
     * Recent ledger rows for the reconciliation table.
     */
    public static function get_ledger( $limit = 50 ) {
        global $wpdb;
        $ledger = $wpdb->prefix . 'mathlin_osm_ledger';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ledger}'" ) !== $ledger ) return array();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$ledger} ORDER BY created_at DESC LIMIT %d",
            (int) $limit
        ) );
    }

    /**
     * Ledger summary counts for the admin banner.
     */
    public static function get_ledger_stats() {
        global $wpdb;
        $ledger = $wpdb->prefix . 'mathlin_osm_ledger';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ledger}'" ) !== $ledger ) {
            return array( 'synced' => 0, 'pending' => 0, 'failed' => 0 );
        }
        return array(
            'synced'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ledger} WHERE status = 'synced'" ),
            'pending' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ledger} WHERE status = 'pending'" ),
            'failed'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ledger} WHERE status = 'failed'" ),
        );
    }

    // ── AJAX Handlers ──────────────────────────────────────────────────────────

    /**
     * Save OSM integration settings. The client secret is only overwritten when
     * a real new value is submitted (the UI sends a masking placeholder when the
     * stored secret is left untouched), so it is never blanked or round-tripped.
     */
    public function ajax_save_settings() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'You do not have permission to perform this action.', 403 );
        }

        update_option( 'mbs_osm_enabled',      ! empty( $_POST['osm_enabled'] ) );
        update_option( 'mbs_osm_sandbox_mode', ! empty( $_POST['osm_sandbox_mode'] ) );
        update_option( 'mbs_osm_auth_source',  sanitize_text_field( $_POST['osm_auth_source'] ?? 'gilbertweb' ) );
        update_option( 'mbs_osm_client_id',    sanitize_text_field( $_POST['osm_client_id'] ?? '' ) );

        // Only update the secret if the admin actually typed a new one.
        $secret = (string) ( $_POST['osm_client_secret'] ?? '' );
        if ( $secret !== '' && $secret !== self::SECRET_MASK ) {
            update_option( 'mbs_osm_client_secret', sanitize_text_field( $secret ) );
        }

        update_option( 'mbs_osm_section_id',           sanitize_text_field( $_POST['osm_section_id'] ?? '' ) );
        update_option( 'mbs_osm_category_id',          sanitize_text_field( $_POST['osm_category_id'] ?? '' ) );
        update_option( 'mbs_osm_account_id',           sanitize_text_field( $_POST['osm_account_id'] ?? '' ) );
        update_option( 'mbs_osm_description_template', sanitize_text_field( $_POST['osm_description_template'] ?? 'Hall Hire: {ref} - {name}' ) );

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
            wp_send_json_error( 'No valid access token. Connect to OSM (Standalone) or ensure GilbertWeb Connector is authenticated.' );
        }

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

        $result = self::api_call( 'GET', self::OSM_RESOURCE );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( 'Could not fetch sections: ' . $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    /**
     * Manually retry a single ledger row from the reconciliation table.
     */
    public function ajax_retry_row() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'You do not have permission to perform this action.', 403 );
        }

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'Invalid ledger row.' );

        global $wpdb;
        $ledger = $wpdb->prefix . 'mathlin_osm_ledger';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ledger} WHERE id = %d", $id ) );
        if ( ! $row ) wp_send_json_error( 'Ledger row not found.' );

        // Reset the parked state so a manual retry gets a fresh attempt budget.
        $wpdb->update( $ledger, array( 'status' => 'pending', 'attempts' => 0 ), array( 'id' => $id ) );
        $row->status   = 'pending';
        $row->attempts = 0;

        self::push_ledger_row( $row );

        $fresh = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ledger} WHERE id = %d", $id ) );
        wp_send_json_success( array( 'id' => $id, 'status' => $fresh->status, 'error' => $fresh->last_error ) );
    }
}
