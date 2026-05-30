<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MGF Venue – REST API
 *
 * Base URL: https://needhamscouts.uk/wp-json/mathlin/v1/
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
            'callback'            => array( $this, 'update_status' ),
            'permission_callback' => array( $this, 'admin_permission' ),
            'args'                => array(
                'status' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function( $v ) {
                        return in_array( $v, array( 'pending', 'confirmed', 'cancelled' ) );
                    },
                ),
            ),
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
        $result = MBS_Bookings::update_status( $ref, $status );
        if ( $result === false ) {
            return new WP_Error( 'update_failed', 'Could not update status', array( 'status' => 500 ) );
        }
        return rest_ensure_response( array( 'success' => true, 'ref' => $ref, 'status' => $status ) );
    }

    public function admin_permission() {
        return current_user_can( 'manage_options' );
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
