<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Owner-authenticated requests, deliberately separate from booking mutations. */
class MBS_Series_Request {
    public function init() {
        add_action( 'wp_ajax_mbs_series_request', array( $this, 'ajax_submit' ) );
        add_action( 'wp_ajax_mbs_resolve_series_request', array( $this, 'ajax_resolve' ) );
        add_action( 'wp_ajax_mbs_approve_series_cancellation', array( $this, 'ajax_approve_cancellation' ) );
    }

    public static function is_series_request( $request ) {
        return $request && in_array( $request->request_type, array( 'series_modify', 'series_cancel' ), true );
    }

    public static function owned_series( $ref ) {
        if ( ! is_user_logged_in() || ! MBS_Hirer_Identity::verified() || ! MBS_Campsite_Policy::can_book() ) return false;
        $user = wp_get_current_user();
        $series = MBS_Series::get( $ref );
        return $series && empty( $series->scout_use ) && $user->user_email !== '' &&
            strcasecmp( $series->contact_email, $user->user_email ) === 0 ? $series : false;
    }

    /** Freeze exact occurrence references/dates; never expand the scope at approval time. */
    public static function select_targets( $occurrences, $scope, $refs, $from_date, $today ) {
        if ( ! in_array( $scope, array( 'one', 'selected', 'from_date' ), true ) || ! is_array( $refs ) ) {
            return new WP_Error( 'invalid_scope', 'Choose one session, selected sessions, or sessions from a date.' );
        }
        if ( $scope === 'from_date' ) {
            $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $from_date );
            if ( ! $date || $date->format( 'Y-m-d' ) !== $from_date || $from_date <= $today ) {
                return new WP_Error( 'invalid_cutoff', 'Choose a future date. For today’s session, please contact the venue.' );
            }
        }
        foreach ( $refs as $ref ) {
            if ( ! is_string( $ref ) ) return new WP_Error( 'invalid_selection', 'Invalid session selection.' );
        }
        $refs = array_values( array_unique( array_map( 'sanitize_text_field', $refs ) ) );
        if ( $scope !== 'from_date' && ( ! $refs || ( $scope === 'one' && count( $refs ) !== 1 ) ) ) {
            return new WP_Error( 'invalid_selection', 'Select the session(s) for this request.' );
        }
        $targets = array();
        foreach ( (array) $occurrences as $booking ) {
            if ( $booking->booking_date <= $today || ! in_array( $booking->status, array( 'pending', 'confirmed', 'paid', 'deposit_paid' ), true ) ) continue;
            if ( $scope === 'from_date' ? $booking->booking_date >= $from_date : in_array( $booking->ref, $refs, true ) ) {
                $targets[ $booking->ref ] = $booking->booking_date;
            }
        }
        if ( ! $targets || ( $scope !== 'from_date' && count( $targets ) !== count( $refs ) ) ) {
            return new WP_Error( 'invalid_selection', 'Only active future sessions in this series can be requested. Refresh the page and check your selection.' );
        }
        return $targets;
    }

    public static function history( $series_ref ) {
        if ( ! self::owned_series( $series_ref ) ) return array();
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.* FROM {$wpdb->prefix}mathlin_mod_requests r INNER JOIN {$wpdb->prefix}" . MBS_TABLE . " b ON b.ref = r.booking_ref WHERE b.series_id = %s AND r.request_type IN ('series_modify','series_cancel') ORDER BY r.id DESC LIMIT 30", $series_ref
        ) );
        return is_array( $rows ) ? $rows : array();
    }

    public function ajax_submit() {
        check_ajax_referer( 'mbs_public_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( array( 'message' => 'Please sign in again.' ), 403 );
        $input = wp_unslash( $_POST );
        $result = self::submit( $input );
        if ( is_wp_error( $result ) ) wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        wp_send_json_success( array( 'message' => 'Request received for review. Your bookings and invoices have not changed. Refresh this page to see the request status.' ) );
    }

    public static function submit( $input ) {
        if ( class_exists( 'MBS_Campsite_Policy' ) && ! MBS_Campsite_Policy::can_book() ) return MBS_Campsite_Policy::access_error();
        global $wpdb;
        foreach ( array( 'series_ref', 'kind', 'scope', 'from_date', 'notes' ) as $key ) {
            if ( isset( $input[ $key ] ) && ! is_scalar( $input[ $key ] ) ) return new WP_Error( 'invalid_input', 'Invalid request details.' );
        }
        $series_ref = sanitize_text_field( $input['series_ref'] ?? '' );
        $kind = $input['kind'] ?? '';
        $scope = $input['scope'] ?? '';
        $notes = sanitize_textarea_field( $input['notes'] ?? '' );
        if ( ! in_array( $kind, array( 'modify', 'cancel' ), true ) || strlen( $notes ) < 3 || strlen( $notes ) > 4000 ) {
            return new WP_Error( 'invalid_details', 'Provide your requested changes or cancellation reason (3–4,000 characters).' );
        }
        $table = $wpdb->prefix . MBS_SERIES_TABLE;
        if ( $wpdb->query( 'START TRANSACTION' ) === false ) return new WP_Error( 'request_failed', 'Unable to save your request. Please try again.' );
        // Serialize submissions per series, including duplicate clicks from different tabs.
        $locked = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE series_ref = %s FOR UPDATE", $series_ref ) );
        $series = self::owned_series( $series_ref );
        if ( ! $locked || ! $series ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'not_owner', 'This series is not available to your account.' );
        }
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}" . MBS_TABLE . ' WHERE series_id = %s ORDER BY booking_date, ref FOR UPDATE', $series_ref ) );
        if ( ! is_array( $rows ) || $wpdb->last_error ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'request_failed', 'Unable to check the sessions. Please try again.' );
        }
        $targets = self::select_targets( $rows, $scope, $input['refs'] ?? array(), (string) ( $input['from_date'] ?? '' ), wp_date( 'Y-m-d' ) );
        if ( is_wp_error( $targets ) ) { $wpdb->query( 'ROLLBACK' ); return $targets; }
        if ( count( $targets ) > 500 ) {
            $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'too_many_sessions', 'Please contact the venue for requests covering more than 500 sessions.' );
        }
        $pending = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.* FROM {$wpdb->prefix}mathlin_mod_requests r INNER JOIN {$wpdb->prefix}" . MBS_TABLE . " b ON b.ref = r.booking_ref WHERE b.series_id = %s AND r.status = 'pending' AND r.request_type IN ('series_modify','series_cancel') FOR UPDATE", $series_ref
        ) );
        if ( ! is_array( $pending ) || $wpdb->last_error ) {
            $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'request_failed', 'Unable to check existing requests. Please try again.' );
        }
        foreach ( $pending as $request ) {
            $data = json_decode( $request->requested_data, true );
            if ( ! is_array( $data ) || array_intersect( array_keys( $targets ), array_keys( $data['targets'] ?? array() ) ) ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'already_pending', 'A request is already pending for one or more of these sessions. Please contact the venue if you need to update it.' );
            }
        }
        $data = array( 'series_ref' => $series_ref, 'series_version' => (int) $locked->version, 'scope' => $scope,
            'from_date' => $scope === 'from_date' ? $input['from_date'] : '', 'targets' => $targets );
        $saved = MBS_Modification::create_request( array( 'ref' => key( $targets ), 'type' => 'series_' . $kind, 'notes' => $notes, 'changes' => $data ) );
        $id = (int) $wpdb->insert_id;
        if ( ! $saved || $wpdb->query( 'COMMIT' ) === false ) {
            $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'request_failed', 'Unable to save your request. Please try again.' );
        }
        MBS_Audit_Log::log( $series_ref, 'series_request_submitted', 'Series ' . $kind . ' request #' . $id . '; ' . count( $targets ) . ' session(s); no booking or financial changes.' );
        $request = MBS_Modification::get_request( $id );
        self::notify( $request, MBS_Bookings::get_admin_email(), 'New series request', 'Review this request in Change Requests: ' . admin_url( 'admin.php?page=mathlin-requests' ) );
        return $id;
    }

    public static function summary( $request ) {
        $data = json_decode( $request->requested_data, true ) ?: array();
        $text = ( $data['series_ref'] ?? '' ) . ' — ' . ( $request->request_type === 'series_cancel' ? 'Cancellation' : 'Change' ) . ' request';
        $text .= "\nScope: " . str_replace( '_', ' ', $data['scope'] ?? '' );
        if ( ! empty( $data['from_date'] ) ) $text .= "\nFrom (inclusive): " . $data['from_date'];
        foreach ( $data['targets'] ?? array() as $ref => $date ) $text .= "\n" . $date . ' (' . $ref . ')';
        return $text;
    }

    public function ajax_approve_cancellation() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'mbs_manage_bookings' ) ) wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
        $request = MBS_Modification::get_request( absint( $_POST['id'] ?? 0 ) );
        $data = $request ? json_decode( $request->requested_data, true ) : array();
        $series = MBS_Series::get( $data['series_ref'] ?? '' );
        if ( ! $request || $request->request_type !== 'series_cancel' || $request->status !== 'pending' || ! $series || empty( $data['targets'] ) || ! is_array( $data['targets'] ) ) {
            wp_send_json_error( array( 'message' => 'The request is not a pending series cancellation.' ), 409 );
        }
        $version = absint( $_POST['version'] ?? 0 );
        $result = MBS_Series::cancel( $series->series_ref, 'selected', $series->status, $version, true, '', array_keys( $data['targets'] ), (int) $request->id );
        if ( is_wp_error( $result ) ) wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
        if ( ! empty( $result['no_op'] ) ) wp_send_json_error( array( 'message' => 'No cancellation was applied. Refresh and review the current state.' ), 409 );
        wp_send_json_success();
    }

    /** Staff resolution records an outcome, never pretends to execute a booking change. */
    public function ajax_resolve() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'mbs_manage_bookings' ) ) wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
        $result = self::resolve( absint( $_POST['id'] ?? 0 ), sanitize_textarea_field( wp_unslash( $_POST['response'] ?? '' ) ) );
        if ( is_wp_error( $result ) ) wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
        wp_send_json_success();
    }

    public static function resolve( $id, $response, $status = 'handled' ) {
        global $wpdb;
        if ( ! in_array( $status, array( 'handled', 'rejected' ), true ) || strlen( trim( $response ) ) < 3 || strlen( $response ) > 4000 ) {
            return new WP_Error( 'outcome_required', 'Enter an outcome for the hirer (3–4,000 characters).' );
        }
        $request = MBS_Modification::get_request( $id );
        if ( ! self::is_series_request( $request ) || $request->status !== 'pending' ) return new WP_Error( 'resolved', 'This request is no longer pending.' );
        $data = json_decode( $request->requested_data, true ) ?: array();
        if ( $status === 'handled' && $request->request_type === 'series_cancel' ) {
            foreach ( $data['targets'] ?? array() as $ref => $date ) {
                $booking = MBS_Bookings::get( $ref );
                if ( ! $booking || $booking->series_id !== ( $data['series_ref'] ?? '' ) || $booking->status !== 'cancelled' ) {
                    return new WP_Error( 'not_cancelled', 'Apply the cancellation through the series controls first, checking every requested date and invoice/credit. This button only records the outcome.' );
                }
            }
            if ( empty( $data['targets'] ) ) return new WP_Error( 'invalid_targets', 'This request has no valid sessions.' );
        }
        $updated = $wpdb->update( $wpdb->prefix . 'mathlin_mod_requests', array( 'status' => $status,
            'admin_response' => sanitize_textarea_field( $response ), 'resolved_at' => current_time( 'mysql' ), 'resolved_by' => get_current_user_id() ), array( 'id' => $id, 'status' => 'pending' ) );
        if ( $updated !== 1 ) return new WP_Error( 'resolved', 'Unable to resolve this request; refresh and check its status.' );
        MBS_Audit_Log::log( $data['series_ref'] ?? $request->booking_ref, 'series_request_' . $status, 'Series request #' . $id . ' ' . $status . '; outcome recorded by staff.' );
        $series = MBS_Series::get( $data['series_ref'] ?? '' );
        if ( $series ) self::notify( $request, $series->contact_email, 'Series request ' . $status, $response );
        return true;
    }

    private static function notify( $request, $to, $title, $message ) {
        if ( ! $request ) return;
        $body = self::summary( $request ) . "\n\nRequest details:\n" . $request->notes . "\n\n" . $message;
        MBS_Email_Queue::send( $to, $title . ' #' . (int) $request->id, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
    }
}
