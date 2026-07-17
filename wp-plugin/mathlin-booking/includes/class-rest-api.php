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

        register_rest_route( self::API_NAMESPACE, '/admin/requests', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_admin_requests' ),
            'permission_callback' => array( $this, 'booking_manager_permission' ),
            'args'                => array(
                'status' => array( 'default' => 'pending', 'sanitize_callback' => 'sanitize_key' ),
                'limit'  => array( 'default' => 100, 'sanitize_callback' => 'absint' ),
            ),
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
            return rest_ensure_response( array(
                'success'        => true,
                'changed'        => false,
                'notified_hirer' => false,
                'booking'        => $this->format_admin_booking( $booking ),
            ) );
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

        if ( MBS_Bookings::update_status( $booking->ref, $status ) === false ) {
            return new WP_Error( 'update_failed', 'Could not update status.', array( 'status' => 500 ) );
        }

        $updated = MBS_Bookings::get( $booking->ref );
        if ( $notify_hirer && $updated ) {
            if ( $status === 'confirmed' ) {
                MBS_Email::notify_confirmed( $updated );
            } elseif ( $status === 'cancelled' ) {
                MBS_Email::notify_cancelled( $updated, $reason );
            }
        }

        return rest_ensure_response( array(
            'success'        => true,
            'changed'        => true,
            'notified_hirer' => (bool) ( $notify_hirer && in_array( $status, array( 'confirmed', 'cancelled' ), true ) ),
            'booking'        => $this->format_admin_booking( $updated ),
        ) );
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
            'export_csv'            => array( 'MBS_CSV_Export', 'handle_export' ),
            'export_accounting'     => array( 'MBS_Accounting_Export', 'handle_export' ),
        );
    }

    public function get_admin_capabilities() {
        return rest_ensure_response( array(
            'plugin_version' => MBS_VERSION,
            'role'           => current_user_can( 'manage_options' ) ? 'administrator' : 'booking_manager',
            'actions'        => array_keys( $this->get_admin_action_handlers() ),
            'reads'          => array(
                'bookings', 'booking', 'availability', 'audit', 'blocked_dates',
                'series', 'requests', 'configuration', 'email_configuration',
                'custom_fields', 'osm_configuration', 'analytics',
            ),
        ) );
    }

    public function get_admin_blocked_dates() {
        return rest_ensure_response( array( 'items' => MBS_Blocked_Dates::get_all() ) );
    }

    public function get_admin_series( WP_REST_Request $request ) {
        $series_id = strtoupper( sanitize_text_field( $request->get_param( 'series_id' ) ) );
        $items     = MBS_Bookings::get_series( $series_id );
        if ( empty( $items ) ) {
            return new WP_Error( 'not_found', 'Booking series not found.', array( 'status' => 404 ) );
        }
        return rest_ensure_response( array(
            'series_id' => $series_id,
            'count'     => count( $items ),
            'items'     => array_map( array( $this, 'format_admin_booking' ), $items ),
        ) );
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
        return rest_ensure_response( $settings );
    }

    /**
     * Render the existing analytics view so the integration sees exactly the
     * same metrics and date basis as the WordPress admin page. The HTML is kept
     * intact because the page contains a broad set of derived charts and tables.
     */
    public function get_admin_analytics() {
        ob_start();
        include MBS_PLUGIN_DIR . 'admin/views/analytics.php';
        $html = ob_get_clean();
        return rest_ensure_response( array( 'html' => $html ) );
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
        return $safe;
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
