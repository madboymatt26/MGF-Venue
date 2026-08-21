<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MGF Venue – REST API
 *
 * Base URL: /wp-json/mathlin/v1/
 *
 * Public (no auth):
 *   GET /bookings/upcoming
 *   GET /bookings/today
 *   GET /bookings/calendar?year=&month=
 *   GET /bookings/date/{YYYY-MM-DD}
 *
 * Admin only (WP Application Password or cookie auth):
 *   GET  /bookings
 *   GET  /bookings/{ref}
 *   POST /bookings/{ref}/status   { "status": "confirmed" }
 */
class MBS_Rest_API {

    const API_NAMESPACE = 'mathlin/v1';

    public function init() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {

        register_rest_route( self::API_NAMESPACE, '/bookings/upcoming', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_upcoming' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'days' => array( 'default' => 30, 'sanitize_callback' => 'absint' ),
            ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/bookings/today', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_today' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( self::API_NAMESPACE, '/bookings/calendar', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_calendar' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'year'  => array( 'default' => (int) wp_date('Y'), 'sanitize_callback' => 'absint' ),
                'month' => array( 'default' => (int) wp_date('n'), 'sanitize_callback' => 'absint' ),
            ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/bookings/date/(?P<date>\d{4}-\d{2}-\d{2})', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_by_date' ),
            'permission_callback' => '__return_true',
        ) );

        // ── Public: iCal download for a single booking ──────────────────────────
        register_rest_route( self::API_NAMESPACE, '/bookings/(?P<ref>[A-Z0-9\-]+)/ical', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_ical' ),
            'permission_callback' => '__return_true',
        ) );

        // ── Public: iCal feed for all upcoming bookings ───────────────────────
        register_rest_route( self::API_NAMESPACE, '/bookings/ical', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_ical_feed' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( self::API_NAMESPACE, '/bookings', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_all' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/bookings/(?P<ref>[A-Z0-9\-]+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_single' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/bookings/(?P<ref>[A-Z0-9\-]+)/status', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'update_admin_status' ),
            'permission_callback' => array( $this, 'admin_permission' ),
            'args'                => array(
                'status' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function( $v ) {
                        return in_array( $v, array( 'pending', 'confirmed', 'cancelled' ) );
                    },
                ),
                'expected_status' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
                'idempotency_key' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
                'notify_hirer' => array( 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
            ),
        ) );

        // MCP/admin integration routes. These intentionally return shaped payloads
        // rather than raw database rows (which contain private security tokens).
        register_rest_route( self::API_NAMESPACE, '/admin/bookings', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_admin_bookings' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
            'args'                => array(
                'status'           => array( 'sanitize_callback' => 'sanitize_text_field' ),
                'date_from'        => array( 'sanitize_callback' => 'sanitize_text_field' ),
                'date_to'          => array( 'sanitize_callback' => 'sanitize_text_field' ),
                'search'           => array( 'sanitize_callback' => 'sanitize_text_field' ),
                'orderby'          => array( 'default' => 'booking_date', 'sanitize_callback' => 'sanitize_key' ),
                'order'            => array( 'default' => 'ASC', 'sanitize_callback' => 'sanitize_key' ),
                'limit'            => array( 'default' => 50, 'sanitize_callback' => 'absint' ),
                'offset'           => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
                'exclude_archived' => array( 'default' => true, 'sanitize_callback' => 'rest_sanitize_boolean' ),
                'exclude_scout'    => array( 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
                'scout_only'       => array( 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
            ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/bookings/create', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'create_admin_booking' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
            'args'                => array(
                'idempotency_key' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
                'space'           => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
                'booking_date'    => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
                'purpose'         => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/bookings/(?P<ref>[A-Z0-9\-]+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_admin_booking' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/bookings/(?P<ref>[A-Z0-9\-]+)/audit', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_admin_audit' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/availability', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_admin_availability' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
            'args'                => array(
                'space'       => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
                'date'        => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
                'start_time'  => array( 'sanitize_callback' => 'sanitize_text_field' ),
                'end_time'    => array( 'sanitize_callback' => 'sanitize_text_field' ),
                'all_day'     => array( 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
                'exclude_ref' => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/bookings/(?P<ref>[A-Z0-9\-]+)/status', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'update_admin_status' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
            'args'                => array(
                'status' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function( $value ) {
                        return in_array( $value, array( 'pending', 'confirmed', 'cancelled' ), true );
                    },
                ),
                'expected_status' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function( $value ) {
                        return in_array( $value, array( 'pending', 'confirmed', 'deposit_paid', 'paid', 'cancelled', 'archived' ), true );
                    },
                ),
                'idempotency_key' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
                'notify_hirer'    => array( 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
                'reason'          => array( 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
            ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/bookings/(?P<ref>[A-Z0-9\-]+)/notes', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'update_admin_notes' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
            'args'                => array(
                'notes' => array( 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
            ),
        ) );

        // Strictly allow-listed bridge to the same handlers used by the MGF Venue
        // admin screens. Reusing those handlers keeps emails, HA webhooks, audit
        // entries, conflict checks and payment transitions identical to the UI.
        register_rest_route( self::API_NAMESPACE, '/admin/actions/(?P<action>[a-z_]+)', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'dispatch_admin_action' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/capabilities', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_admin_capabilities' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/blocked-dates', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_admin_blocked_dates' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/series/(?P<series_id>[A-Z0-9\-]+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_admin_series' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/series', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_admin_series_list' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
            'args'                => array(
                'status'      => array( 'sanitize_callback' => 'sanitize_key' ),
                'search'      => array( 'sanitize_callback' => 'sanitize_text_field' ),
                'series_kind' => array(
                    'default'           => 'all',
                    'sanitize_callback' => 'sanitize_key',
                    'validate_callback' => static function ( $value ) { return in_array( $value, array( 'all', 'external', 'scout' ), true ); },
                ),
                'limit'       => array( 'default' => 100, 'sanitize_callback' => 'absint' ),
            ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/series/(?P<series_id>[A-Z0-9\-]+)/approve', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'approve_admin_series' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/series/(?P<series_id>[A-Z0-9\-]+)/billing', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'configure_admin_series' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/series/(?P<series_id>[A-Z0-9\-]+)/state', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'update_admin_series_state' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/invoices', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_admin_invoices' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/invoices/(?P<invoice_ref>[A-Z0-9\-]+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_admin_invoice' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/invoices/(?P<invoice_ref>[A-Z0-9\-]+)/payments', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'record_admin_invoice_payment' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/requests', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_admin_requests' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
            'args'                => array(
                'status' => array( 'default' => 'pending', 'sanitize_callback' => 'sanitize_key' ),
                'limit'  => array( 'default' => 100, 'sanitize_callback' => 'absint' ),
            ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/audit', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_admin_global_audit' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
            'args'                => array(
                'search' => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
                'limit'  => array( 'default' => 200, 'sanitize_callback' => 'absint' ),
            ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/dashboard', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_admin_dashboard' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/configuration', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_admin_configuration' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/email-configuration', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_admin_email_configuration' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/custom-fields', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_admin_custom_fields' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/osm-configuration', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_admin_osm_configuration' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::API_NAMESPACE, '/admin/analytics', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_admin_analytics' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
        ) );
    }

    public function get_upcoming( WP_REST_Request $request ) {
        return rest_ensure_response( MBS_HomeAssistant::get_upcoming_for_ha( $request->get_param('days') ) );
    }

    public function get_today( WP_REST_Request $request ) {
        return rest_ensure_response( MBS_HomeAssistant::get_todays_bookings() );
    }

    public function get_calendar( WP_REST_Request $request ) {
        return rest_ensure_response(
            MBS_Bookings::get_booked_dates( $request->get_param('year'), $request->get_param('month') )
        );
    }

    public function get_by_date( WP_REST_Request $request ) {
        $bookings = MBS_Bookings::get_by_date( sanitize_text_field( $request->get_param('date') ) );
        $safe = array_map( function( $b ) {
            $data = array(
                'space'      => $b->space,
                'start_time' => $b->start_time,
                'end_time'   => $b->end_time,
                'all_day'    => (bool) $b->all_day,
                'is_public'  => (bool) $b->is_public,
            );
            // Only show event details for public bookings
            if ( ! empty( $b->is_public ) ) {
                $data['purpose'] = $b->purpose;
                $data['name']    = $b->organisation ?: $b->name;
            }
            return $data;
        }, $bookings );
        return rest_ensure_response( $safe );
    }

    public function get_all( WP_REST_Request $request ) {
        $args = array(
            'status'    => sanitize_text_field( $request->get_param('status')    ?? '' ),
            'date_from' => sanitize_text_field( $request->get_param('date_from') ?? '' ),
            'date_to'   => sanitize_text_field( $request->get_param('date_to')   ?? '' ),
            'search'    => sanitize_text_field( $request->get_param('search')    ?? '' ),
        );
        return rest_ensure_response( MBS_Bookings::get_all( $args ) );
    }

    public function get_single( WP_REST_Request $request ) {
        $ref     = strtoupper( sanitize_text_field( $request->get_param('ref') ) );
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) {
            return new WP_Error( 'not_found', 'Booking not found', array( 'status' => 404 ) );
        }
        return rest_ensure_response( $booking );
    }

    public function update_status( WP_REST_Request $request ) {
        $ref    = strtoupper( sanitize_text_field( $request->get_param('ref') ) );
        $status = sanitize_text_field( $request->get_param('status') );
        $booking = MBS_Bookings::get( $ref );
        if ( $booking && ! empty( $booking->series_id ) && MBS_Series::get( $booking->series_id ) ) {
            return new WP_Error( 'series_operation_required', 'Change first-class recurring occurrences through the versioned series endpoint.', array( 'status' => 409 ) );
        }
        $result = MBS_Bookings::update_status( $ref, $status );
        if ( $result === false ) {
            return new WP_Error( 'update_failed', 'Could not update status', array( 'status' => 500 ) );
        }
        return rest_ensure_response( array( 'success' => true, 'ref' => $ref, 'status' => $status ) );
    }

    public function admin_permission() {
        return current_user_can( 'manage_options' );
    }

    public function booking_manager_permission() {
        return current_user_can( 'manage_options' ) || current_user_can( 'mbs_manage_bookings' );
    }

    private function integration_idempotency_transient( $scope, $key, $payload = array() ) {
        $key = sanitize_text_field( $key );
        if ( strlen( $key ) < 8 || strlen( $key ) > 128 ) {
            return new WP_Error( 'invalid_idempotency_key', 'idempotency_key must be between 8 and 128 characters.', array( 'status' => 400 ) );
        }
        if ( is_array( $payload ) ) unset( $payload['idempotency_key'] );
        $payload = self::canonical_idempotency_payload( $payload );
        $request_hash = hash( 'sha256', sanitize_key( $scope ) . '|' . wp_json_encode( $payload ) );
        $registry_key = 'mbs_api_idem_' . substr( hash( 'sha256', get_current_user_id() . ':' . $key ), 0, 40 );
        $registered = get_transient( $registry_key );
        if ( $registered && ! hash_equals( (string) $registered, $request_hash ) ) {
            return new WP_Error( 'idempotency_conflict', 'This idempotency key was already used for a different operation, target or payload.', array( 'status' => 409 ) );
        }
        if ( ! $registered ) set_transient( $registry_key, $request_hash, DAY_IN_SECONDS );
        return 'mbs_api_' . substr( hash( 'sha256', get_current_user_id() . ':' . sanitize_key( $scope ) . ':' . $key ), 0, 40 );
    }

    private static function canonical_idempotency_payload( $value ) {
        if ( ! is_array( $value ) ) return $value;
        if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ksort( $value, SORT_STRING );
        foreach ( $value as $key => $item ) $value[ $key ] = self::canonical_idempotency_payload( $item );
        return $value;
    }

    /**
     * Create a normal one-off booking from a trusted admin integration.
     *
     * This deliberately uses the core booking creator and status transition
     * methods so pricing, conflict checks, audit logging, Home Assistant and
     * the free-booking auto-paid rule stay identical to existing web flows.
     */
    public function create_admin_booking( WP_REST_Request $request ) {
        $idempotency_key = sanitize_text_field( $request->get_param( 'idempotency_key' ) ?? '' );
        $transient_key = $this->integration_idempotency_transient( 'admin_create', $idempotency_key, $request->get_params() );
        if ( is_wp_error( $transient_key ) ) return $transient_key;
        $existing_ref  = get_transient( $transient_key );
        if ( $existing_ref ) {
            if ( strpos( $existing_ref, 'SER-' ) === 0 ) {
                $existing_series = MBS_Bookings::get_series( $existing_ref );
                if ( ! empty( $existing_series ) ) {
                    return rest_ensure_response( array(
                        'created'           => false,
                        'idempotent_replay' => true,
                        'notified_hirer'    => false,
                        'series'            => array(
                            'series_id' => $existing_ref,
                            'count'     => count( $existing_series ),
                            'items'     => array_map( array( $this, 'format_admin_booking' ), $existing_series ),
                        ),
                    ) );
                }
            }
            $existing = MBS_Bookings::get( $existing_ref );
            if ( $existing ) {
                return rest_ensure_response( array(
                    'created'            => false,
                    'idempotent_replay'  => true,
                    'notified_hirer'     => false,
                    'booking'            => $this->format_admin_booking( $existing ),
                ) );
            }
            delete_transient( $transient_key );
        }

        $space       = sanitize_text_field( $request->get_param( 'space' ) ?? '' );
        $date_from   = sanitize_text_field( $request->get_param( 'booking_date' ) ?? '' );
        $date_to     = sanitize_text_field( $request->get_param( 'booking_date_end' ) ?? '' ) ?: $date_from;
        $repeat_until = sanitize_text_field( $request->get_param( 'repeat_until' ) ?? '' );
        $all_day     = rest_sanitize_boolean( $request->get_param( 'all_day' ) );
        $start_time  = sanitize_text_field( $request->get_param( 'start_time' ) ?? '' );
        $end_time    = sanitize_text_field( $request->get_param( 'end_time' ) ?? '' );
        $purpose     = sanitize_text_field( $request->get_param( 'purpose' ) ?? '' );
        $status      = sanitize_key( $request->get_param( 'status' ) ?: 'confirmed' );
        $scout_use   = rest_sanitize_boolean( $request->get_param( 'scout_use' ) );
        $pricing_tier = sanitize_key( $request->get_param( 'pricing_tier' ) ?: 'standard' );
        $notify      = rest_sanitize_boolean( $request->get_param( 'notify_hirer' ) );
        $spaces      = MBS_Bookings::get_spaces();

        if ( ! isset( $spaces[ $space ] ) ) {
            return new WP_Error( 'invalid_space', 'Unknown venue space.', array( 'status' => 400 ) );
        }
        if ( ! $this->is_valid_date( $date_from ) || ! $this->is_valid_date( $date_to ) ) {
            return new WP_Error( 'invalid_date', 'Booking dates must use YYYY-MM-DD.', array( 'status' => 400 ) );
        }
        if ( $date_from < wp_date( 'Y-m-d' ) ) {
            return new WP_Error( 'past_date', 'Administrators cannot create a booking in the past.', array( 'status' => 400 ) );
        }
        if ( $date_to < $date_from ) {
            return new WP_Error( 'invalid_date_range', 'booking_date_end must be on or after booking_date.', array( 'status' => 400 ) );
        }
        if ( ( strtotime( $date_to ) - strtotime( $date_from ) ) > 366 * DAY_IN_SECONDS ) {
            return new WP_Error( 'date_range_too_long', 'A booking cannot span more than 367 days.', array( 'status' => 400 ) );
        }
        if ( $repeat_until ) {
            $recurrence_check = MBS_Recurrence::weekly_dates( array( 'booking_date' => $date_from, 'booking_date_end' => $date_to ), $repeat_until );
            if ( is_wp_error( $recurrence_check ) ) {
                $recurrence_check->add_data( array( 'status' => 400 ) );
                return $recurrence_check;
            }
        }
        if ( ! $purpose ) {
            return new WP_Error( 'missing_purpose', 'purpose is required.', array( 'status' => 400 ) );
        }
        if ( ! in_array( $status, array( 'pending', 'confirmed' ), true ) ) {
            return new WP_Error( 'invalid_status', 'New bookings can be pending or confirmed.', array( 'status' => 400 ) );
        }
        if ( ! isset( MBS_Bookings::get_pricing_tiers()[ $pricing_tier ] ) ) {
            return new WP_Error( 'invalid_pricing_tier', 'Unknown pricing tier.', array( 'status' => 400 ) );
        }
        if ( ! $all_day ) {
            if ( ! $this->is_valid_time( $start_time ) || ! $this->is_valid_time( $end_time ) ) {
                return new WP_Error( 'invalid_time', 'Timed bookings require HH:MM start and end times.', array( 'status' => 400 ) );
            }
            if ( strtotime( $end_time ) <= strtotime( $start_time ) ) {
                return new WP_Error( 'invalid_time_range', 'end_time must be after start_time.', array( 'status' => 400 ) );
            }
        }

        $current_user = wp_get_current_user();
        $name          = sanitize_text_field( $request->get_param( 'name' ) ?? '' );
        $email         = sanitize_email( $request->get_param( 'email' ) ?? '' );
        if ( ! $name ) $name = $scout_use ? $purpose : sanitize_text_field( $current_user->display_name );
        if ( ! $email ) $email = sanitize_email( $current_user->user_email ?: MBS_Bookings::get_admin_email() );
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', 'A valid booking contact email is required.', array( 'status' => 400 ) );
        }

        $custom_fields = $request->get_param( 'custom_fields' );
        if ( ! is_array( $custom_fields ) ) $custom_fields = array();
        $custom_check = MBS_Custom_Fields::validate_submission( array( 'custom_fields' => $custom_fields ) );
        if ( is_wp_error( $custom_check ) ) return $custom_check;

        $data = array(
            'name'             => $name,
            'organisation'     => sanitize_text_field( $request->get_param( 'organisation' ) ?: get_option( 'mbs_org_name', get_bloginfo( 'name' ) ) ),
            'email'            => $email,
            'phone'            => sanitize_text_field( $request->get_param( 'phone' ) ?? '' ),
            'address'          => sanitize_textarea_field( $request->get_param( 'address' ) ?? '' ),
            'space'            => $space,
            'kitchen'          => rest_sanitize_boolean( $request->get_param( 'kitchen' ) ),
            'booking_date'     => $date_from,
            'booking_date_end' => $date_to,
            'all_day'          => $all_day,
            'scout_use'        => $scout_use,
            'pricing_tier'     => $pricing_tier,
            'start_time'       => $all_day ? '' : $start_time,
            'end_time'         => $all_day ? '' : $end_time,
            'attendees'        => absint( $request->get_param( 'attendees' ) ?? 0 ),
            'purpose'          => $purpose,
            'notes'            => sanitize_textarea_field( $request->get_param( 'notes' ) ?? '' ),
            'is_public'        => rest_sanitize_boolean( $request->get_param( 'is_public' ) ),
            'custom_fields'    => $custom_fields,
        );

        $custom_amount = $request->get_param( 'custom_amount' );
        if ( $custom_amount !== null && $custom_amount !== '' ) {
            if ( ! is_numeric( $custom_amount ) || (float) $custom_amount < 0 ) {
                return new WP_Error( 'invalid_custom_amount', 'custom_amount must be a non-negative number.', array( 'status' => 400 ) );
            }
            if ( $scout_use && (float) $custom_amount !== 0.0 ) {
                return new WP_Error( 'scout_booking_must_be_free', 'Scout-use bookings must have a zero amount.', array( 'status' => 400 ) );
            }
            $data['custom_amount'] = (float) $custom_amount;
        }

        if ( $repeat_until ) {
            $result = MBS_Bookings::create_recurring( $data, $repeat_until, true );
            if ( is_wp_error( $result ) ) return $result;

            if ( $status === 'confirmed' ) {
                $approval = MBS_Series::approve(
                    $result['series_id'],
                    'pending',
                    (int) $result['series']->version,
                    (bool) $notify
                );
                if ( is_wp_error( $approval ) ) {
                    return new WP_Error( 'confirmation_failed', $approval->get_error_message(), array( 'status' => 500, 'series_id' => $result['series_id'] ) );
                }
            }

            $series_items = MBS_Bookings::get_series( $result['series_id'] );
            if ( $notify && $status !== 'confirmed' ) {
                MBS_Email::notify_recurring_summary( $result['series'], $result['occurrences'] );
            }

            set_transient( $transient_key, $result['series_id'], DAY_IN_SECONDS );
            return rest_ensure_response( array(
                'created'           => true,
                'idempotent_replay' => false,
                'notified_hirer'    => (bool) $notify,
                'series'            => array(
                    'series_id' => $result['series_id'],
                    'count'     => count( $series_items ),
                    'skipped'   => $result['skipped'],
                    'items'     => array_map( array( $this, 'format_admin_booking' ), $series_items ),
                ),
            ) );
        }

        $result = MBS_Bookings::create( $data, true );
        if ( is_wp_error( $result ) ) return $result;

        if ( $status === 'confirmed' && MBS_Bookings::update_status( $result['ref'], 'confirmed', (bool) $notify ) === false ) {
            return new WP_Error( 'confirmation_failed', 'Booking was created but could not be confirmed.', array( 'status' => 500, 'ref' => $result['ref'] ) );
        }

        $booking = MBS_Bookings::get( $result['ref'] );
        if ( $notify && $booking ) {
            if ( $status === 'confirmed' ) {
                if ( empty( $booking->current_invoice_document_id ) ) MBS_Email::notify_confirmed( $booking );
            } else {
                MBS_Email::notify_booker( $result );
            }
        }

        set_transient( $transient_key, $result['ref'], DAY_IN_SECONDS );
        return rest_ensure_response( array(
            'created'           => true,
            'idempotent_replay' => false,
            'notified_hirer'    => (bool) $notify,
            'booking'           => $this->format_admin_booking( $booking ),
        ) );
    }

    /**
     * Return a filtered, paginated booking list for trusted admin integrations.
     */
    public function get_admin_bookings( WP_REST_Request $request ) {
        $limit  = min( 200, max( 1, absint( $request->get_param( 'limit' ) ?: 50 ) ) );
        $offset = max( 0, absint( $request->get_param( 'offset' ) ?: 0 ) );

        $args = array(
            'status'           => sanitize_text_field( $request->get_param( 'status' ) ?? '' ),
            'date_from'        => sanitize_text_field( $request->get_param( 'date_from' ) ?? '' ),
            'date_to'          => sanitize_text_field( $request->get_param( 'date_to' ) ?? '' ),
            'search'           => sanitize_text_field( $request->get_param( 'search' ) ?? '' ),
            'orderby'          => sanitize_key( $request->get_param( 'orderby' ) ?: 'booking_date' ),
            'order'            => strtoupper( sanitize_text_field( $request->get_param( 'order' ) ?: 'ASC' ) ),
            'limit'            => $limit,
            'offset'           => $offset,
            'exclude_archived' => rest_sanitize_boolean( $request->get_param( 'exclude_archived' ) ),
            'exclude_scout'    => rest_sanitize_boolean( $request->get_param( 'exclude_scout' ) ),
            'scout_only'       => rest_sanitize_boolean( $request->get_param( 'scout_only' ) ),
        );

        $bookings = MBS_Bookings::get_all( $args );
        return rest_ensure_response( array(
            'items'  => array_map( array( $this, 'format_admin_booking' ), $bookings ),
            'count'  => count( $bookings ),
            'limit'  => $limit,
            'offset' => $offset,
        ) );
    }

    /**
     * Return one booking without internal authentication/modification tokens.
     */
    public function get_admin_booking( WP_REST_Request $request ) {
        $booking = $this->find_booking( $request );
        if ( is_wp_error( $booking ) ) return $booking;
        return rest_ensure_response( $this->format_admin_booking( $booking ) );
    }

    /**
     * Return a booking audit trail without stored IP addresses or numeric user IDs.
     */
    public function get_admin_audit( WP_REST_Request $request ) {
        $booking = $this->find_booking( $request );
        if ( is_wp_error( $booking ) ) return $booking;

        $entries = array_map( function( $entry ) {
            return array(
                'action'     => $entry->action,
                'label'      => MBS_Audit_Log::action_label( $entry->action ),
                'details'    => $entry->details,
                'user_name'  => $entry->user_name,
                'created_at' => $entry->created_at,
            );
        }, MBS_Audit_Log::get_for_booking( $booking->ref ) );

        return rest_ensure_response( array( 'ref' => $booking->ref, 'items' => $entries ) );
    }

    /**
     * Return the global Audit Log with the same search and row limits as the
     * admin page. Stored IP addresses and numeric user IDs are omitted.
     */
    public function get_admin_global_audit( WP_REST_Request $request ) {
        $search = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
        $limit  = max( 20, min( 1000, absint( $request->get_param( 'limit' ) ?: 200 ) ) );
        $rows   = $search !== '' ? MBS_Audit_Log::search( $search, $limit ) : MBS_Audit_Log::get_recent( $limit );

        $items = array_map( function( $entry ) {
            return array(
                'ref'        => $entry->ref,
                'action'     => $entry->action,
                'label'      => MBS_Audit_Log::action_label( $entry->action ),
                'details'    => $entry->details,
                'user_name'  => $entry->user_name,
                'created_at' => $entry->created_at,
            );
        }, $rows );

        return rest_ensure_response( array(
            'search' => $search,
            'limit'  => $limit,
            'count'  => count( $items ),
            'items'  => $items,
        ) );
    }

    /**
     * Return the compact counters shown around the booking dashboard without
     * requiring integrations to render and scrape an admin HTML page.
     */
    public function get_admin_dashboard() {
        return rest_ensure_response( array(
            'external_bookings'       => MBS_Bookings::get_stats( true ),
            'all_bookings'            => MBS_Bookings::get_stats( false ),
            'pending_change_requests' => MBS_Modification::get_pending_count(),
        ) );
    }

    /**
     * Check blocked dates and live booking conflicts for a proposed slot.
     */
    public function get_admin_availability( WP_REST_Request $request ) {
        $space       = sanitize_text_field( $request->get_param( 'space' ) );
        $date        = sanitize_text_field( $request->get_param( 'date' ) );
        $start_time  = sanitize_text_field( $request->get_param( 'start_time' ) ?? '' );
        $end_time    = sanitize_text_field( $request->get_param( 'end_time' ) ?? '' );
        $all_day     = rest_sanitize_boolean( $request->get_param( 'all_day' ) );
        $exclude_ref = strtoupper( sanitize_text_field( $request->get_param( 'exclude_ref' ) ?? '' ) );

        if ( ! array_key_exists( $space, MBS_Bookings::get_spaces() ) ) {
            return new WP_Error( 'invalid_space', 'Unknown venue space.', array( 'status' => 400 ) );
        }
        if ( ! $this->is_valid_date( $date ) ) {
            return new WP_Error( 'invalid_date', 'Date must use YYYY-MM-DD.', array( 'status' => 400 ) );
        }
        if ( ! $all_day && ( ! $this->is_valid_time( $start_time ) || ! $this->is_valid_time( $end_time ) ) ) {
            return new WP_Error( 'invalid_time', 'Timed availability checks require HH:MM start and end times.', array( 'status' => 400 ) );
        }

        $blocked   = MBS_Blocked_Dates::is_blocked( $date, $space );
        $conflicts = MBS_Bookings::check_conflicts(
            $space,
            $date,
            $all_day ? null : $start_time,
            $all_day ? null : $end_time,
            $all_day,
            $exclude_ref
        );

        $safe_conflicts = array_map( function( $booking ) {
            return array(
                'ref'          => $booking->ref,
                'status'       => $booking->status,
                'space'        => $booking->space,
                'booking_date' => $booking->booking_date,
                'start_time'   => $booking->start_time,
                'end_time'     => $booking->end_time,
                'all_day'      => (bool) $booking->all_day,
                'name'         => $booking->organisation ?: $booking->name,
            );
        }, $conflicts );

        return rest_ensure_response( array(
            'available' => ! $blocked && empty( $safe_conflicts ),
            'blocked'   => $blocked ? array(
                'date_from' => $blocked->date_from,
                'date_to'   => $blocked->date_to,
                'space'     => $blocked->space ?: 'All spaces',
                'reason'    => $blocked->reason,
            ) : null,
            'conflicts' => $safe_conflicts,
        ) );
    }

    /**
     * Idempotently change a booking status, optionally sending the normal hirer email.
     */
    public function update_admin_status( WP_REST_Request $request ) {
        $transient_key = $this->integration_idempotency_transient( 'booking_status', $request->get_param( 'idempotency_key' ), $request->get_params() );
        if ( is_wp_error( $transient_key ) ) return $transient_key;
        $replay = get_transient( $transient_key );
        if ( is_array( $replay ) ) {
            $replay['idempotent_replay'] = true;
            return rest_ensure_response( $replay );
        }
        $booking = $this->find_booking( $request );
        if ( is_wp_error( $booking ) ) return $booking;

        $status          = sanitize_text_field( $request->get_param( 'status' ) );
        $expected_status = sanitize_text_field( $request->get_param( 'expected_status' ) ?? '' );
        $notify_hirer    = rest_sanitize_boolean( $request->get_param( 'notify_hirer' ) );
        $reason          = sanitize_textarea_field( $request->get_param( 'reason' ) ?? '' );

        if ( $expected_status && $booking->status !== $expected_status ) {
            return new WP_Error(
                'status_conflict',
                'Booking status changed since it was last read.',
                array( 'status' => 409, 'current_status' => $booking->status )
            );
        }

        if ( $booking->status === $status ) {
            $response = array(
                'success'        => true,
                'changed'        => false,
                'notified_hirer' => false,
                'idempotent_replay' => false,
                'booking'        => $this->format_admin_booking( $booking ),
            );
            set_transient( $transient_key, $response, DAY_IN_SECONDS );
            return rest_ensure_response( $response );
        }

        $series = ! empty( $booking->series_id ) ? MBS_Series::get( $booking->series_id ) : null;
        if ( $series ) {
            return new WP_Error( 'series_operation_required', 'Change first-class recurring occurrences through the versioned series endpoint.', array( 'status' => 409 ) );
        }

        $allowed_transitions = array(
            'pending'      => array( 'confirmed', 'cancelled' ),
            'confirmed'    => array( 'pending', 'cancelled' ),
            'deposit_paid' => array( 'cancelled' ),
            'paid'         => array( 'cancelled' ),
            'cancelled'    => array( 'pending', 'confirmed' ),
            'archived'     => array( 'pending' ),
        );
        if ( empty( $allowed_transitions[ $booking->status ] ) || ! in_array( $status, $allowed_transitions[ $booking->status ], true ) ) {
            return new WP_Error(
                'invalid_status_transition',
                'That status transition is not allowed through the integration.',
                array( 'status' => 409, 'current_status' => $booking->status, 'requested_status' => $status )
            );
        }

        if ( MBS_Bookings::update_status( $booking->ref, $status, $status === 'confirmed' && $notify_hirer ) === false ) {
            return new WP_Error( 'update_failed', 'Could not update status.', array( 'status' => 500 ) );
        }

        $updated = MBS_Bookings::get( $booking->ref );
        if ( $notify_hirer && $updated ) {
            if ( $series && $status === 'cancelled' ) {
                MBS_Email::notify_series_changed( MBS_Series::get( $series->series_ref ), MBS_Series::active_occurrences( $series->series_ref ) );
            } elseif ( $status === 'confirmed' ) {
                if ( empty( $updated->current_invoice_document_id ) ) MBS_Email::notify_confirmed( $updated );
            } elseif ( $status === 'cancelled' ) {
                MBS_Email::notify_cancelled( $updated, $reason );
            }
        }
        $response = array(
            'success'        => true,
            'changed'        => true,
            'notified_hirer' => (bool) ( $notify_hirer && in_array( $status, array( 'confirmed', 'cancelled' ), true ) ),
            'idempotent_replay' => false,
            'booking'        => $this->format_admin_booking( $updated ),
        );
        set_transient( $transient_key, $response, DAY_IN_SECONDS );
        return rest_ensure_response( $response );
    }

    /**
     * Replace the internal administrator note for a booking.
     */
    public function update_admin_notes( WP_REST_Request $request ) {
        $booking = $this->find_booking( $request );
        if ( is_wp_error( $booking ) ) return $booking;

        $notes = sanitize_textarea_field( $request->get_param( 'notes' ) );
        if ( (string) $booking->admin_notes === $notes ) {
            return rest_ensure_response( array(
                'success' => true,
                'changed' => false,
                'ref'     => $booking->ref,
                'notes'   => $notes,
            ) );
        }
        if ( MBS_Bookings::update_admin_notes( $booking->ref, $notes ) === false ) {
            return new WP_Error( 'update_failed', 'Could not update administrator notes.', array( 'status' => 500 ) );
        }

        $updated = MBS_Bookings::get( $booking->ref );
        return rest_ensure_response( array(
            'success' => true,
            'changed' => true,
            'ref'     => $booking->ref,
            'notes'   => $updated ? $updated->admin_notes : $notes,
        ) );
    }

    /**
     * Dispatch an authenticated REST request to an existing MGF Venue admin
     * action. The handler map is deliberately closed; arbitrary WordPress AJAX
     * callbacks cannot be invoked through this route.
     *
     * Existing handlers call wp_send_json_*() and terminate after producing the
     * response, so their HTTP body and status are preserved exactly.
     */
    public function dispatch_admin_action( WP_REST_Request $request ) {
        $handlers = $this->get_admin_action_handlers();
        $action   = sanitize_key( $request->get_param( 'action' ) );

        if ( ! isset( $handlers[ $action ] ) ) {
            return new WP_Error( 'unknown_admin_action', 'That MGF Venue admin action is not available.', array( 'status' => 404 ) );
        }

        $payload = $request->get_json_params();
        if ( ! is_array( $payload ) ) {
            $payload = $request->get_body_params();
        }
        if ( ! is_array( $payload ) ) $payload = array();

        // Application Password authentication establishes the WordPress user.
        // The server-generated nonce lets the existing AJAX handler retain its
        // normal CSRF and capability checks without exposing a nonce to clients.
        $payload['nonce'] = wp_create_nonce( 'mbs_admin_nonce' );
        $_POST            = $payload;
        $_GET             = array_merge( $_GET, $payload );
        $_REQUEST         = array_merge( $_REQUEST, $payload );

        list( $class_name, $method_name ) = $handlers[ $action ];
        $handler = new $class_name();
        call_user_func( array( $handler, $method_name ) );

        return new WP_Error( 'admin_action_no_response', 'The admin action completed without returning a response.', array( 'status' => 500 ) );
    }

    /**
     * One-to-one allow-list for every action exposed by the MGF Venue admin UI.
     */
    private function get_admin_action_handlers() {
        return array(
            'update_status'         => array( 'MBS_Admin', 'ajax_update_status' ),
            'delete_booking'        => array( 'MBS_Admin', 'ajax_delete_booking' ),
            'mark_refunded'         => array( 'MBS_Admin', 'ajax_mark_refunded' ),
            'mark_deposit_paid'     => array( 'MBS_Admin', 'ajax_mark_deposit_paid' ),
            'undo_deposit'          => array( 'MBS_Admin', 'ajax_undo_deposit' ),
            'restore_booking'       => array( 'MBS_Admin', 'ajax_restore_booking' ),
            'resend_access'         => array( 'MBS_Admin', 'ajax_resend_access' ),
            'send_feedback_request' => array( 'MBS_Admin', 'ajax_send_feedback_request' ),
            'create_scout_recurring'=> array( 'MBS_Admin', 'ajax_create_scout_recurring' ),
            'get_invoice'           => array( 'MBS_Admin', 'ajax_get_invoice' ),
            'save_settings'         => array( 'MBS_Admin', 'ajax_save_settings' ),
            'test_ha'               => array( 'MBS_Admin', 'ajax_test_ha' ),
            'check_update'          => array( 'MBS_Admin', 'ajax_check_update' ),
            'archive_past'          => array( 'MBS_Admin', 'ajax_archive_past' ),
            'add_blocked'           => array( 'MBS_Admin', 'ajax_add_blocked' ),
            'delete_blocked'        => array( 'MBS_Admin', 'ajax_delete_blocked' ),
            'clear_expired_blocks'  => array( 'MBS_Admin', 'ajax_clear_expired_blocks' ),
            'update_series_status'  => array( 'MBS_Admin', 'ajax_update_series_status' ),
            'resend_series_confirmation' => array( 'MBS_Admin', 'ajax_resend_series_confirmation' ),
            'record_invoice_manual_payment' => array( 'MBS_Admin', 'ajax_record_invoice_manual_payment' ),
            'resolve_invoice_reconciliation' => array( 'MBS_Admin', 'ajax_resolve_invoice_reconciliation' ),
            'configure_series_billing' => array( 'MBS_Admin', 'ajax_configure_series_billing' ),
            'approve_series_with_billing' => array( 'MBS_Admin', 'ajax_approve_series_with_billing' ),
            'get_series_for_approval' => array( 'MBS_Admin', 'ajax_get_series_for_approval' ),
            'delete_archive_series' => array( 'MBS_Admin', 'ajax_delete_archive_series' ),
            'billing_preview'       => array( 'MBS_Admin', 'ajax_billing_preview' ),
            'pause_series'          => array( 'MBS_Admin', 'ajax_pause_series' ),
            'catch_up_series_billing' => array( 'MBS_Admin', 'ajax_catch_up_series_billing' ),
            'extend_external_series' => array( 'MBS_Admin', 'ajax_extend_external_series' ),
            'cancel_scout_series'   => array( 'MBS_Admin', 'ajax_cancel_scout_series' ),
            'edit_scout_series'     => array( 'MBS_Admin', 'ajax_edit_scout_series' ),
            'extend_scout_series'   => array( 'MBS_Admin', 'ajax_extend_scout_series' ),
            'reopen_scout_series'   => array( 'MBS_Admin', 'ajax_reopen_scout_series' ),
            'delete_scout_series'   => array( 'MBS_Admin', 'ajax_delete_scout_series' ),
            'save_admin_notes'      => array( 'MBS_Admin', 'ajax_save_admin_notes' ),
            'chase_payment'         => array( 'MBS_Admin', 'ajax_chase_payment' ),
            'save_email_settings'   => array( 'MBS_Admin', 'ajax_save_email_settings' ),
            'save_custom_fields'    => array( 'MBS_Admin', 'ajax_save_custom_fields' ),
            'edit_booking'          => array( 'MBS_Admin', 'ajax_edit_booking' ),
            'approve_request'       => array( 'MBS_Admin', 'ajax_approve_request' ),
            'reject_request'        => array( 'MBS_Admin', 'ajax_reject_request' ),
            'bulk_action'           => array( 'MBS_Admin', 'ajax_bulk_action' ),
            'save_osm_settings'     => array( 'MBS_OSM_Integration', 'ajax_save_settings' ),
            'test_osm_connection'   => array( 'MBS_OSM_Integration', 'ajax_test_connection' ),
            'osm_get_sections'      => array( 'MBS_OSM_Integration', 'ajax_get_sections' ),
            'osm_discover'          => array( 'MBS_OSM_Integration', 'ajax_discover' ),
            'osm_sync_woopayments'  => array( 'MBS_OSM_Integration', 'ajax_sync_woopayments' ),
            'osm_retry_event'       => array( 'MBS_OSM_Integration', 'ajax_retry_event' ),
            'osm_resolve_event'     => array( 'MBS_OSM_Integration', 'ajax_resolve_event' ),
            'export_csv'            => array( 'MBS_CSV_Export', 'handle_export' ),
            'export_accounting'     => array( 'MBS_Accounting_Export', 'handle_export' ),
        );
    }

    public function get_admin_capabilities() {
        $is_admin = current_user_can( 'manage_options' );
        $actions  = array_merge( array( 'create_booking' ), array_keys( $this->get_admin_action_handlers() ) );
        $reads    = array(
            'bookings', 'booking', 'availability', 'audit', 'global_audit',
            'dashboard', 'blocked_dates', 'series', 'series_list', 'invoices', 'invoice', 'requests', 'analytics',
        );

        if ( $is_admin ) {
            $reads = array_merge( $reads, array(
                'configuration', 'email_configuration', 'custom_fields', 'osm_configuration',
            ) );
        } else {
            $admin_only_actions = array(
                'delete_booking', 'resolve_invoice_reconciliation', 'save_settings', 'test_ha', 'check_update',
                'delete_scout_series', 'save_email_settings', 'save_custom_fields',
                'save_osm_settings', 'test_osm_connection', 'osm_get_sections', 'osm_discover',
                'osm_sync_woopayments', 'osm_retry_event', 'osm_resolve_event',
                'export_csv', 'export_accounting',
            );
            $actions = array_values( array_diff( $actions, $admin_only_actions ) );
        }

        return rest_ensure_response( array(
            'plugin_version' => MBS_VERSION,
            'role'           => $is_admin ? 'administrator' : 'booking_manager',
            'actions'        => $actions,
            'reads'          => $reads,
        ) );
    }

    public function get_admin_blocked_dates() {
        return rest_ensure_response( array( 'items' => MBS_Blocked_Dates::get_all() ) );
    }

    public function get_admin_series( WP_REST_Request $request ) {
        $series_id = strtoupper( sanitize_text_field( $request->get_param( 'series_id' ) ) );
        $series = MBS_Series::get( $series_id );
        $items = MBS_Bookings::get_series( $series_id );
        if ( ! $series && $items && ! array_filter( $items, static function ( $item ) { return empty( $item->scout_use ); } ) ) {
            MBS_Series::register_legacy_groups();
            $series = MBS_Series::get( $series_id );
        }
        if ( ! $series && empty( $items ) ) {
            return new WP_Error( 'not_found', 'Booking series not found.', array( 'status' => 404 ) );
        }
        if ( ! $series ) {
            return rest_ensure_response( array(
                'series' => array( 'series_ref' => $series_id, 'metadata_incomplete' => true, 'billing_mode' => 'legacy_per_occurrence', 'billing_treatment' => 'legacy_per_occurrence' ),
                'occurrences' => array_map( array( $this, 'format_admin_booking' ), $items ),
            ) );
        }
        $invoices = array_map( array( $this, 'format_admin_invoice' ), MBS_Series::invoices( $series_id ) );
        $preview = MBS_Billing_Engine::preview( $series_id );
        return rest_ensure_response( array(
            'series' => $this->format_admin_series( $series ),
            'occurrence_count' => count( $items ),
            'occurrences' => array_map( array( $this, 'format_admin_booking' ), $items ),
            'exceptions' => MBS_Series::exceptions( $series ),
            'invoice_preview' => is_wp_error( $preview ) ? array( 'error' => $preview->get_error_message() ) : $preview,
            'invoices' => $invoices,
            'audit' => array_map( array( $this, 'format_audit_entry' ), MBS_Audit_Log::get_for_booking( $series_id ) ),
        ) );
    }

    public function get_admin_series_list( WP_REST_Request $request ) {
        MBS_Series::register_legacy_groups();
        $series_kind = sanitize_key( $request->get_param( 'series_kind' ) ?: 'all' );
        $args = array(
            'status' => sanitize_key( $request->get_param( 'status' ) ?? '' ),
            'search' => sanitize_text_field( $request->get_param( 'search' ) ?? '' ),
            'limit' => min( 500, max( 1, absint( $request->get_param( 'limit' ) ?: 100 ) ) ),
        );
        if ( $series_kind === 'scout' ) $args['scout_use'] = 1;
        if ( $series_kind === 'external' ) $args['scout_use'] = 0;
        $rows = MBS_Series::get_all( $args );
        $summaries = MBS_Series::occurrence_summaries( array_map( static function ( $row ) { return $row->series_ref; }, $rows ) );
        $items = array_map( function ( $row ) use ( $summaries ) {
            $formatted = $this->format_admin_series( $row );
            $summary = $summaries[ $row->series_ref ] ?? null;
            $formatted['occurrence_summary'] = $summary ? array(
                'total' => (int) $summary->total_count,
                'future_active' => (int) $summary->future_active_count,
                'future_cancelled' => (int) $summary->future_cancelled_count,
                'cancelled' => (int) $summary->cancelled_count,
                'next_date' => $summary->next_date ?: null,
                'last_date' => $summary->last_date ?: null,
            ) : array();
            return $formatted;
        }, $rows );
        return rest_ensure_response( array( 'series_kind' => $series_kind, 'items' => $items ) );
    }

    public function approve_admin_series( WP_REST_Request $request ) {
        $key = $this->integration_idempotency_transient( 'series_approve', $request->get_param( 'idempotency_key' ), $request->get_params() );
        if ( is_wp_error( $key ) ) return $key;
        $replay = get_transient( $key );
        if ( is_array( $replay ) ) { $replay['idempotent_replay'] = true; return rest_ensure_response( $replay ); }
        $series_ref = strtoupper( sanitize_text_field( $request->get_param( 'series_id' ) ) );
        $result = MBS_Series::approve(
            $series_ref,
            sanitize_key( $request->get_param( 'expected_status' ) ),
            absint( $request->get_param( 'expected_version' ) ),
            rest_sanitize_boolean( $request->get_param( 'notify_hirer' ) )
        );
        if ( is_wp_error( $result ) ) return $result;
        $response = array( 'series' => $this->format_admin_series( $result['series'] ), 'changed' => ! empty( $result['transitioned'] ), 'notified_hirer' => ! empty( $result['email_sent'] ), 'idempotent_replay' => false );
        set_transient( $key, $response, DAY_IN_SECONDS );
        return rest_ensure_response( $response );
    }

    public function configure_admin_series( WP_REST_Request $request ) {
        $key = $this->integration_idempotency_transient( 'series_billing', $request->get_param( 'idempotency_key' ), $request->get_params() );
        if ( is_wp_error( $key ) ) return $key;
        $replay = get_transient( $key );
        if ( is_array( $replay ) ) { $replay['idempotent_replay'] = true; return rest_ensure_response( $replay ); }
        $series_ref = strtoupper( sanitize_text_field( $request->get_param( 'series_id' ) ) );
        $schedule = $request->get_param( 'billing_schedule' );
        if ( ! is_array( $schedule ) ) $schedule = array();
        $result = MBS_Billing_Engine::configure_series( $series_ref, array(
            'billing_mode' => sanitize_key( $request->get_param( 'billing_mode' ) ),
            'billing_treatment' => sanitize_key( $request->get_param( 'billing_treatment' ) ),
            'payment_method' => sanitize_key( $request->get_param( 'payment_method' ) ),
            'deposit_policy' => 'none', 'invoice_lead_days' => absint( $request->get_param( 'invoice_lead_days' ) ),
            'payment_terms_days' => absint( $request->get_param( 'payment_terms_days' ) ),
            'billing_schedule' => $schedule, 'adopt_legacy' => rest_sanitize_boolean( $request->get_param( 'adopt_legacy' ) ),
        ), absint( $request->get_param( 'expected_version' ) ) );
        if ( is_wp_error( $result ) ) return $result;
        $notified = false;
        if ( rest_sanitize_boolean( $request->get_param( 'notify_hirer' ) ) ) $notified = MBS_Email::notify_series_changed( $result, MBS_Series::active_occurrences( $series_ref ) );
        MBS_Audit_Log::log( $series_ref, 'series_billing_changed', 'Integration changed billing to ' . $result->billing_mode . ' / ' . $result->billing_treatment . '.' );
        $response = array( 'series' => $this->format_admin_series( $result ), 'notified_hirer' => (bool) $notified, 'idempotent_replay' => false );
        set_transient( $key, $response, DAY_IN_SECONDS );
        return rest_ensure_response( $response );
    }

    public function update_admin_series_state( WP_REST_Request $request ) {
        $key = $this->integration_idempotency_transient( 'series_state', $request->get_param( 'idempotency_key' ), $request->get_params() );
        if ( is_wp_error( $key ) ) return $key;
        $replay = get_transient( $key );
        if ( is_array( $replay ) ) { $replay['idempotent_replay'] = true; return rest_ensure_response( $replay ); }
        $series_ref = strtoupper( sanitize_text_field( $request->get_param( 'series_id' ) ) );
        $operation = sanitize_key( $request->get_param( 'operation' ) );
        $status = sanitize_key( $request->get_param( 'expected_status' ) );
        $version = absint( $request->get_param( 'expected_version' ) );
        if ( $operation === 'pause' || $operation === 'resume' ) {
            $result = MBS_Series::set_paused( $series_ref, $operation === 'pause', $status, $version );
        } elseif ( $operation === 'cancel' ) {
            $result = MBS_Series::cancel( $series_ref, sanitize_key( $request->get_param( 'scope' ) ?: 'future' ), $status, $version, rest_sanitize_boolean( $request->get_param( 'notify_hirer' ) ) );
        } elseif ( $operation === 'extend' ) {
            $result = MBS_Series::extend( $series_ref, sanitize_text_field( $request->get_param( 'repeat_until' ) ), $version, rest_sanitize_boolean( $request->get_param( 'notify_hirer' ) ) );
        } else {
            return new WP_Error( 'invalid_series_operation', 'operation must be pause, resume, cancel or extend.', array( 'status' => 400 ) );
        }
        if ( is_wp_error( $result ) ) return $result;
        $response = array( 'series' => $this->format_admin_series( $result['series'] ), 'changed' => empty( $result['no_op'] ), 'notified_hirer' => in_array( $operation, array( 'cancel', 'extend' ), true ) && rest_sanitize_boolean( $request->get_param( 'notify_hirer' ) ), 'idempotent_replay' => false );
        set_transient( $key, $response, DAY_IN_SECONDS );
        return rest_ensure_response( $response );
    }

    public function get_admin_invoices( WP_REST_Request $request ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_INVOICE_TABLE;
        $status = sanitize_key( $request->get_param( 'status' ) ?? '' );
        $series_ref = strtoupper( sanitize_text_field( $request->get_param( 'series_ref' ) ?? '' ) );
        $where = array( "document_type = 'invoice'" ); $params = array();
        if ( $status ) { $where[] = 'status = %s'; $params[] = $status; }
        if ( $series_ref ) { $where[] = 'series_ref = %s'; $params[] = $series_ref; }
        $params[] = min( 500, max( 1, absint( $request->get_param( 'limit' ) ?: 100 ) ) );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC LIMIT %d', $params ) );
        return rest_ensure_response( array( 'items' => array_map( array( $this, 'format_admin_invoice' ), $rows ) ) );
    }

    public function get_admin_invoice( WP_REST_Request $request ) {
        $invoice = MBS_Billing_Ledger::get_invoice( strtoupper( sanitize_text_field( $request->get_param( 'invoice_ref' ) ) ) );
        if ( ! $invoice ) return new WP_Error( 'not_found', 'Invoice not found.', array( 'status' => 404 ) );
        return rest_ensure_response( array(
            'invoice' => $this->format_admin_invoice( $invoice ),
            'items' => array_map( array( $this, 'format_admin_invoice_item' ), MBS_Billing_Ledger::get_items( $invoice->id ) ),
            'transactions' => $this->get_safe_invoice_transactions( $invoice->id ),
        ) );
    }

    public function record_admin_invoice_payment( WP_REST_Request $request ) {
        $result = MBS_Invoice_Payment::record_manual_payment(
            strtoupper( sanitize_text_field( $request->get_param( 'invoice_ref' ) ) ),
            sanitize_text_field( $request->get_param( 'amount_minor' ) ),
            sanitize_text_field( $request->get_param( 'idempotency_key' ) ),
            absint( $request->get_param( 'expected_version' ) ),
            sanitize_text_field( $request->get_param( 'note' ) )
        );
        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( array( 'invoice' => $this->format_admin_invoice( $result['invoice'] ), 'transaction' => $this->format_admin_transaction( $result['transaction'] ), 'idempotent_replay' => ! empty( $result['idempotent_replay'] ) ) );
    }

    public function get_admin_requests( WP_REST_Request $request ) {
        $status = sanitize_key( $request->get_param( 'status' ) ?: 'pending' );
        $limit  = min( 500, max( 1, absint( $request->get_param( 'limit' ) ?: 100 ) ) );
        $items  = ( $status === 'pending' )
            ? MBS_Modification::get_pending()
            : MBS_Modification::get_all_requests( $limit );
        return rest_ensure_response( array( 'status' => $status, 'items' => $items ) );
    }

    public function get_admin_configuration() {
        $keys = array(
            'mbs_min_notice_days', 'mbs_min_duration_hours', 'mbs_kitchen_price',
            'mbs_kitchen_enabled', 'mbs_reminder_hours', 'mbs_terms_page_id',
            'mbs_auto_archive_days', 'mbs_additional_emails', 'mbs_auto_chase_enabled',
            'mbs_scout_volunteer_emails', 'mbs_scout_nights_enabled', 'mbs_admin_email',
            'mbs_bank_sort_code', 'mbs_bank_account_number', 'mbs_bank_account_name',
            'mbs_payment_terms_days', 'mbs_deposit_enabled', 'mbs_deposit_percentage',
            'mbs_deposit_balance_days', 'mbs_access_enabled', 'mbs_access_instructions',
            'mbs_access_health_safety', 'mbs_access_hours_before', 'mbs_venue_capacity',
            'mbs_curfew_saturday', 'mbs_curfew_sunday', 'mbs_payment_days_required',
            'mbs_terms_text', 'mbs_booking_notice', 'mbs_facilities_text',
            'mbs_offline_payment_instructions', 'mbs_feedback_enabled',
            'mbs_feedback_review_url', 'mbs_feedback_distribution_email',
            'mbs_feedback_subject', 'mbs_feedback_body',
        );
        $values = array();
        foreach ( $keys as $key ) $values[ $key ] = get_option( $key, null );
        $values['spaces']             = MBS_Bookings::get_spaces();
        $values['pricing_tiers']      = MBS_Bookings::get_pricing_tiers();
        $values['ha_webhook_configured'] = (bool) get_option( 'mbs_ha_webhook_url', '' );
        $values['github_token_configured'] = (bool) get_option( 'mbs_github_token', '' );
        $values['access_code_configured']  = (bool) get_option( 'mbs_access_code', '' );
        return rest_ensure_response( $values );
    }

    public function get_admin_email_configuration() {
        $templates = array();
        foreach ( MBS_Email_Templates::get_template_types() as $type => $definition ) {
            $templates[ $type ] = array_merge(
                array( 'label' => $definition['label'] ),
                MBS_Email_Templates::get_template( $type )
            );
        }
        return rest_ensure_response( array(
            'organisation' => MBS_Email_Templates::get_org_settings(),
            'chasing'      => MBS_Email_Templates::get_chase_settings(),
            'templates'    => $templates,
        ) );
    }

    public function get_admin_custom_fields() {
        return rest_ensure_response( array( 'items' => MBS_Custom_Fields::get_fields() ) );
    }

    public function get_admin_osm_configuration() {
        $settings = MBS_OSM_Integration::get_settings();
        $settings['client_id_configured']     = ! empty( $settings['client_id'] );
        $settings['client_secret_configured'] = ! empty( $settings['client_secret'] );
        unset( $settings['client_id'], $settings['client_secret'] );
        $settings['gilbertweb_available'] = MBS_OSM_Integration::gilbertweb_available();
        $settings['queue'] = MBS_OSM_Integration::get_queue_health();
        return rest_ensure_response( $settings );
    }

    /**
     * Render the existing analytics view so the integration sees exactly the
     * same metrics and date basis as the WordPress admin page. The HTML is kept
     * intact because the page contains a broad set of derived charts and tables.
     */
    public function get_admin_analytics( WP_REST_Request $request ) {
        $previous = array( 'report_from' => $_GET['report_from'] ?? null, 'report_to' => $_GET['report_to'] ?? null );
        foreach ( array( 'report_from', 'report_to' ) as $key ) {
            $value = sanitize_text_field( (string) $request->get_param( $key ) );
            if ( $value !== '' ) $_GET[$key] = $value;
        }
        ob_start();
        include MBS_PLUGIN_DIR . 'admin/views/analytics.php';
        $html = ob_get_clean();
        foreach ( $previous as $key => $value ) { if ( $value === null ) unset( $_GET[$key] ); else $_GET[$key] = $value; }
        $customers = array_map( static function ( $customer ) { return array( 'name' => $customer['name'], 'email' => $customer['email'], 'invoice_count' => count( $customer['invoice_ids'] ), 'legacy_booking_count' => (int) $customer['legacy_bookings'], 'received_minor' => (int) $customer['payments_minor'], 'refunded_minor' => (int) $customer['refunds_minor'], 'net_minor' => (int) $customer['payments_minor'] - (int) $customer['refunds_minor'] ); }, $customer_cash );
        $routes = array(); foreach ( $payment_routes as $label => $totals ) $routes[] = array( 'label' => $label, 'transactions' => (int) $totals['transactions'], 'received_minor' => (int) $totals['payments'], 'refunded_minor' => (int) $totals['refunds'], 'net_minor' => (int) $totals['payments'] - (int) $totals['refunds'] );
        $osm_summary = $osm_report;
        $osm_summary['recent_delivered'] = array_map( static function ( $row ) { return array_intersect_key( $row, array_flip( array( 'payout_ref', 'payout_date', 'amount_minor', 'bank_transaction_id', 'cashbook_transaction_id', 'delivered_at' ) ) ); }, $osm_report['recent_delivered'] );
        $osm_summary['recent_direct'] = array_map( static function ( $row ) { return array_intersect_key( $row, array_flip( array( 'event_ref', 'invoice_ref', 'event_type', 'amount_minor', 'occurred_at', 'bank_transaction_id', 'remote_transaction_id', 'delivered_at' ) ) ); }, $osm_report['recent_direct'] );
        return rest_ensure_response( array(
            'period' => array( 'from' => $fy_start, 'to' => $fy_end, 'label' => $fy_label ),
            'summary' => array( 'bookings' => (int) $total_fy, 'invoiced_minor' => (int) round( $invoiced_fy * 100 ), 'received_minor' => (int) round( $collected_fy * 100 ), 'refunded_minor' => (int) $invoice_refunded_minor, 'net_cash_minor' => (int) round( $net_collected_fy * 100 ), 'outstanding_minor' => (int) round( $outstanding_total * 100 ) ),
            'customers' => $customers, 'payment_routes' => $routes, 'osm' => $osm_summary, 'html' => $html,
        ) );
    }

    private function find_booking( WP_REST_Request $request ) {
        $ref     = strtoupper( sanitize_text_field( $request->get_param( 'ref' ) ) );
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) {
            return new WP_Error( 'not_found', 'Booking not found', array( 'status' => 404 ) );
        }
        return $booking;
    }

    /**
     * Explicit allow-list prevents modification tokens and future private columns
     * from leaking into integrations when the database schema grows.
     */
    public function format_admin_booking( $booking ) {
        if ( ! $booking ) return null;

        $source = (array) $booking;
        $fields = array(
            'ref', 'status', 'name', 'organisation', 'email', 'phone', 'address',
            'space', 'kitchen', 'booking_date', 'booking_date_end', 'all_day', 'scout_use',
            'start_time', 'end_time', 'attendees', 'purpose', 'notes', 'amount', 'amount_paid',
            'deposit_paid', 'pricing_tier', 'invoice_number', 'series_id', 'admin_notes',
            'custom_fields', 'is_public', 'ha_notified', 'reminder_sent',
            'feedback_sent', 'chase_count', 'last_chased', 'access_sent', 'created_at', 'updated_at',
        );

        $safe = array_intersect_key( $source, array_flip( $fields ) );
        foreach ( array( 'kitchen', 'all_day', 'scout_use', 'is_public', 'ha_notified', 'reminder_sent', 'feedback_sent', 'access_sent' ) as $field ) {
            if ( array_key_exists( $field, $safe ) ) $safe[ $field ] = (bool) $safe[ $field ];
        }
        foreach ( array( 'amount', 'amount_paid', 'deposit_paid' ) as $field ) {
            if ( array_key_exists( $field, $safe ) ) $safe[ $field ] = (float) $safe[ $field ];
        }
        foreach ( array( 'attendees', 'chase_count' ) as $field ) {
            if ( array_key_exists( $field, $safe ) && $safe[ $field ] !== null ) $safe[ $field ] = (int) $safe[ $field ];
        }

        $series = ! empty( $booking->series_id ) ? MBS_Series::get( $booking->series_id ) : null;
        $series_managed = $series && in_array(
            MBS_Series::billing_treatment_for_booking( $booking ),
            array( 'manual_consolidated', 'invoice_managed', 'none' ),
            true
        );
        $document_id = (int) ( $booking->current_invoice_document_id ?? 0 );
        $safe['invoice_scope'] = $series_managed ? 'series' : ( (float) $booking->amount > 0 ? 'booking' : 'none' );
        $safe['invoice_document_id'] = ! $series_managed && $document_id ? $document_id : null;
        $safe['pdf_invoice_available'] = ! $series_managed && $document_id > 0;
        return $safe;
    }

    public function format_admin_series( $series ) {
        if ( ! $series ) return null;
        $source = (array) $series;
        $fields = array(
            'series_ref', 'status', 'version', 'contact_name', 'contact_organisation', 'contact_email', 'contact_phone', 'contact_address',
            'space', 'kitchen', 'all_day', 'scout_use', 'pricing_tier', 'start_time', 'end_time', 'attendees', 'purpose', 'notes',
            'start_date', 'repeat_until', 'recurrence_rule', 'price_per_booking', 'estimated_total', 'requested_count', 'accepted_count',
            'conflict_count', 'blocked_count', 'error_count', 'billing_mode', 'billing_treatment', 'deposit_policy', 'payment_method',
            'automatic_reminders', 'invoice_lead_days', 'payment_terms_days', 'confirmation_sent_at', 'metadata_incomplete', 'adopted_at',
            'adopted_by', 'adoption_state', 'adoption_version',
            'created_at', 'updated_at',
        );
        $safe = array_intersect_key( $source, array_flip( $fields ) );
        foreach ( array( 'kitchen', 'all_day', 'scout_use', 'automatic_reminders', 'metadata_incomplete' ) as $field ) if ( isset( $safe[ $field ] ) ) $safe[ $field ] = (bool) $safe[ $field ];
        foreach ( array( 'version', 'attendees', 'requested_count', 'accepted_count', 'conflict_count', 'blocked_count', 'error_count', 'invoice_lead_days', 'payment_terms_days' ) as $field ) if ( isset( $safe[ $field ] ) ) $safe[ $field ] = (int) $safe[ $field ];
        $safe['schedule'] = json_decode( (string) ( $series->schedule_json ?? '' ), true ) ?: array();
        $safe['billing_schedule'] = json_decode( (string) ( $series->billing_schedule_json ?? '' ), true ) ?: array();
        $safe['terms_accepted'] = ! empty( $series->terms_accepted_at );
        $safe['terms_accepted_at'] = $series->terms_accepted_at ?: null;
        $safe['series_kind'] = ! empty( $series->scout_use ) ? 'scout' : 'external';
        return $safe;
    }

    public function format_admin_invoice( $invoice ) {
        if ( ! $invoice ) return null;
        $source = (array) $invoice;
        $fields = array(
            'invoice_ref', 'document_type', 'series_ref', 'status', 'version', 'contact_name', 'contact_organisation', 'contact_email',
            'contact_address', 'billing_mode', 'period_start', 'period_end', 'currency', 'subtotal_minor', 'tax_minor', 'total_minor',
            'paid_minor', 'credited_minor', 'issued_at', 'issued_email_sent_at', 'due_at', 'voided_at', 'void_reason', 'reminder_count',
            'last_reminded_at', 'created_at', 'updated_at',
        );
        $safe = array_intersect_key( $source, array_flip( $fields ) );
        foreach ( array( 'version', 'subtotal_minor', 'tax_minor', 'total_minor', 'paid_minor', 'credited_minor', 'reminder_count' ) as $field ) if ( isset( $safe[ $field ] ) ) $safe[ $field ] = (int) $safe[ $field ];
        $safe['balance_minor'] = MBS_Billing_Ledger::balance_minor( $invoice );
        return $safe;
    }

    public function format_admin_invoice_item( $item ) {
        if ( ! $item ) return null;
        $source = (array) $item;
        $safe = array_intersect_key( $source, array_flip( array( 'item_ref', 'item_type', 'booking_ref', 'service_date', 'description', 'quantity_milli', 'unit_amount_minor', 'line_total_minor', 'created_at' ) ) );
        foreach ( array( 'quantity_milli', 'unit_amount_minor', 'line_total_minor' ) as $field ) if ( isset( $safe[ $field ] ) ) $safe[ $field ] = (int) $safe[ $field ];
        return $safe;
    }

    public function format_admin_transaction( $transaction ) {
        if ( ! $transaction ) return null;
        $source = (array) $transaction;
        $safe = array_intersect_key( $source, array_flip( array( 'transaction_ref', 'provider', 'provider_transaction_id', 'transaction_type', 'status', 'amount_minor', 'currency', 'occurred_at', 'receipt_sent_at', 'created_at' ) ) );
        if ( isset( $safe['amount_minor'] ) ) $safe['amount_minor'] = (int) $safe['amount_minor'];
        return $safe;
    }

    public function format_audit_entry( $entry ) {
        return array(
            'ref' => $entry->ref, 'action' => $entry->action, 'label' => MBS_Audit_Log::action_label( $entry->action ),
            'details' => $entry->details, 'user_name' => $entry->user_name, 'created_at' => $entry->created_at,
        );
    }

    private function get_safe_invoice_transactions( $invoice_id ) {
        return array_map( array( $this, 'format_admin_transaction' ), MBS_Hirer_Portal::invoice_transactions( $invoice_id ) );
    }

    private function is_valid_date( $value ) {
        $date = DateTime::createFromFormat( 'Y-m-d', $value );
        return $date && $date->format( 'Y-m-d' ) === $value;
    }

    private function is_valid_time( $value ) {
        return (bool) preg_match( '/^(?:[01]\\d|2[0-3]):[0-5]\\d(?::[0-5]\\d)?$/', $value );
    }

    // ── iCal endpoints ─────────────────────────────────────────────────────────

    public function get_ical( WP_REST_Request $request ) {
        $ref     = strtoupper( sanitize_text_field( $request->get_param( 'ref' ) ) );
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) {
            return new WP_Error( 'not_found', 'Booking not found', array( 'status' => 404 ) );
        }

        $ics = MBS_ICal::generate( $booking );

        $response = new WP_REST_Response( $ics );
        $response->header( 'Content-Type', 'text/calendar; charset=utf-8' );
        $response->header( 'Content-Disposition', 'attachment; filename="booking-' . $ref . '.ics"' );
        return $response;
    }

    public function get_ical_feed( WP_REST_Request $request ) {
        $ics = MBS_ICal::generate_feed();

        $response = new WP_REST_Response( $ics );
        $response->header( 'Content-Type', 'text/calendar; charset=utf-8' );
        $response->header( 'Content-Disposition', 'inline; filename="scout-hall-bookings.ics"' );
        return $response;
    }
}
