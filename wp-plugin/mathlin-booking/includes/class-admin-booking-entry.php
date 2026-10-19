<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Staff UI adapter: previews are read-only; writes delegate to the existing admin API. */
class MBS_Admin_Booking_Entry {
    public function init() {
        add_action( 'rest_api_init', function() {
            foreach ( array( 'preview', 'commit' ) as $operation ) register_rest_route( 'mathlin/v1', '/admin/booking-entry/' . $operation, array(
                'methods' => 'POST', 'callback' => array( $this, $operation ),
                'permission_callback' => array( $this, 'allowed' ),
            ) );
        } );
        add_action( 'admin_menu', function() { add_submenu_page( 'mathlin-booking', 'Add booking', 'Add booking', 'mbs_manage_bookings', 'mathlin-add-booking', array( $this, 'render' ) ); } );
    }
    public function allowed() { return current_user_can( 'manage_options' ) || current_user_can( 'mbs_manage_bookings' ); }
    public function render() {
        if ( ! $this->allowed() ) wp_die( 'Booking management permission required.' );
        wp_enqueue_script( 'mbs-booking-entry', MBS_PLUGIN_URL . 'admin/booking-entry.js', array(), MBS_VERSION, true );
        wp_localize_script( 'mbs-booking-entry', 'MBS_Entry', array( 'url' => rest_url( 'mathlin/v1/admin/booking-entry/' ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
        include MBS_PLUGIN_DIR . 'admin/views/add-booking.php';
    }
    private static function fail( $message ) { return new WP_Error( 'booking_entry_invalid', $message, array( 'status' => 409 ) ); }
    public static function plan( $input ) {
        if ( ! is_array( $input ) ) return self::fail( 'Enter booking details.' );
        $campsite = MBS_Campsite_Policy::enabled();
        $type = sanitize_key( $input['entry_type'] ?? 'hire' );
        if ( ! in_array( $type, array( 'hire', 'scout', 'estate' ), true ) || ( $type === 'estate' && ! $campsite ) || ( $type === 'scout' && ! get_option( 'mbs_scout_nights_enabled', 1 ) ) ) return self::fail( 'This booking type is not enabled here.' );
        $d = array();
        foreach ( array( 'name', 'organisation', 'phone', 'space', 'booking_date', 'booking_date_end', 'start_time', 'end_time', 'purpose', 'repeat_until', 'pricing_tier', 'booking_span', 'recurrence_pattern' ) as $key ) $d[$key] = sanitize_text_field( $input[$key] ?? '' );
        foreach ( array( 'all_day', 'kitchen', 'notify_hirer', 'campsite_exception' ) as $key ) $d[$key] = rest_sanitize_boolean( $input[$key] ?? false );
        $d['email'] = sanitize_email( $input['email'] ?? '' );
        $d['address'] = sanitize_textarea_field( $input['address'] ?? '' );
        $d['notes'] = sanitize_textarea_field( $input['notes'] ?? '' );
        $d['status'] = sanitize_key( $input['status'] ?? 'pending' );
        $d['scout_use'] = $type === 'scout'; $d['is_public'] = false;
        $d['attendees'] = absint( $input['attendees'] ?? 0 );
        $d['custom_fields'] = is_array( $input['custom_fields'] ?? null ) ? $input['custom_fields'] : array();
        $d['booking_date_end'] = $d['booking_date_end'] ?: $d['booking_date'];
        $d['pricing_tier'] = $d['pricing_tier'] ?: 'standard';
        $d['booking_span'] = $d['booking_span'] ?: ( $campsite ? 'continuous' : 'daily' );
        if ( ! $d['purpose'] || ! in_array( $d['status'], array( 'pending', 'confirmed' ), true ) ) return self::fail( 'Enter a title and choose Pending or Confirmed.' );
        if ( ! MBS_Campsite_Policy::valid_date( $d['booking_date'] ) || ! MBS_Campsite_Policy::valid_date( $d['booking_date_end'] ) || $d['booking_date'] < wp_date( 'Y-m-d' ) || $d['booking_date_end'] < $d['booking_date'] ) return self::fail( 'Select valid future dates in order.' );
        $days = ( new DateTimeImmutable( $d['booking_date'] ) )->diff( new DateTimeImmutable( $d['booking_date_end'] ) )->days + 1;
        if ( $days > 367 ) return self::fail( 'A booking may cover no more than one year.' );
        $spaces = MBS_Bookings::get_spaces();
        if ( ! isset( $spaces[$d['space']] ) ) return self::fail( 'Select a configured space.' );
        if ( $d['attendees'] < 1 || ( ! empty( $spaces[$d['space']]['capacity'] ) && $d['attendees'] > (int) $spaces[$d['space']]['capacity'] ) ) return self::fail( 'Enter attendance within the space capacity.' );
        if ( ! isset( MBS_Bookings::get_pricing_tiers()[$d['pricing_tier']] ) ) return self::fail( 'Select a configured pricing tier.' );
        if ( $d['kitchen'] && ! get_option( 'mbs_kitchen_enabled', 1 ) ) return self::fail( 'Kitchen hire is disabled.' );
        if ( ! $d['all_day'] ) {
            foreach ( array( 'start_time', 'end_time' ) as $key ) if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $d[$key] ) ) return self::fail( 'Enter valid start and end times.' );
            if ( $d['end_time'] <= $d['start_time'] && ! ( $campsite && $days > 1 && $d['booking_span'] === 'continuous' ) ) return self::fail( 'End time must follow start time. Overnight camps need a later departure date.' );
        }
        if ( $d['all_day'] ) $d['start_time'] = $d['end_time'] = '';
        if ( ! in_array( $d['booking_span'], array( 'daily', 'continuous' ), true ) || ( ! $campsite && $d['booking_span'] !== 'daily' ) ) return self::fail( 'Invalid booking span.' );
        $coordinator = $type === 'estate' && rest_sanitize_boolean( $input['coordinator_contact'] ?? false );
        if ( $coordinator ) {
            if ( $d['notify_hirer'] ) return self::fail( 'Coordinator-only estate bookings cannot send a customer email.' );
            $d['name'] = $d['purpose']; $d['email'] = MBS_Bookings::get_admin_email();
        }
        if ( ! $d['name'] || ! is_email( $d['email'] ) ) return self::fail( 'Enter the contact name and valid email, or explicitly select the estate coordinator.' );
        if ( $d['campsite_exception'] ) {
            $reason = sanitize_textarea_field( $input['exception_reason'] ?? '' );
            if ( ! $campsite || ! $reason ) return self::fail( 'An agreed campsite exception needs an explanation.' );
            $d['notes'] .= "\nAgreed scheduling exception: " . $reason;
        }
        if ( $type === 'estate' ) {
            $d['notes'] = "Estate reservation entered by staff.\n" . $d['notes'];
            if ( ! $d['organisation'] ) $d['organisation'] = 'Estate';
        }
        $custom = MBS_Custom_Fields::validate_submission( $d ); if ( is_wp_error( $custom ) ) return $custom;
        $policy = MBS_Campsite_Policy::validate( $d, true ); if ( is_wp_error( $policy ) ) return $policy;
        $minimum = MBS_Bookings::validate_min_duration( $d['start_time'], $d['end_time'], $d['all_day'], $days, $d['scout_use'] ); if ( is_wp_error( $minimum ) ) return $minimum;
        $recurring = $d['recurrence_pattern'] !== '';
        if ( ! $recurring ) $d['repeat_until'] = '';
        if ( $recurring ) {
            $d['recurrence_dates'] = sanitize_textarea_field( $input['recurrence_dates'] ?? '' );
            $dates = MBS_Recurrence::dates( $d, $d['repeat_until'] ); if ( is_wp_error( $dates ) ) return $dates;
            if ( ! $dates ) return self::fail( 'Select at least one occurrence.' );
        } else $dates = array( $d['booking_date'] );
        $amount = MBS_Bookings::calculate_cost( $d['space'], $d['start_time'], $d['end_time'], $d['kitchen'], $d['all_day'], $days, $d['scout_use'], $d['pricing_tier'] );
        if ( $recurring && $amount > 0 && $d['status'] !== 'pending' ) return self::fail( 'Create paid series as Pending, then configure billing and approve in Recurring Series.' );
        if ( ! $recurring && $amount > 0 && $d['status'] === 'confirmed' && ! MBS_Payment_Features::enabled( 'invoices' ) ) return self::fail( 'Invoicing is disabled. Save as Pending rather than confirming a chargeable booking.' );
        $schedule = array();
        foreach ( $dates as $date ) {
            $occurrence = $d; $occurrence['booking_date'] = $date;
            if ( $recurring ) $occurrence['booking_date_end'] = $date;
            $valid = MBS_Campsite_Policy::validate_schedule( $occurrence, true ); if ( is_wp_error( $valid ) ) return $valid;
            for ( $day = new DateTimeImmutable( $date ); $day->format( 'Y-m-d' ) <= $occurrence['booking_date_end']; $day = $day->modify( '+1 day' ) ) {
                $ymd = $day->format( 'Y-m-d' );
                $interval = $campsite ? MBS_Campsite_Policy::request_interval( $occurrence, $ymd ) : array( $d['start_time'], $d['end_time'] );
                if ( MBS_Blocked_Dates::is_blocked( $ymd, $d['space'] ) || MBS_Bookings::check_conflicts( $d['space'], $ymd, $interval[0], $interval[1], $d['all_day'] ) ) return self::fail( 'Occupied or blocked: ' . $ymd . '. Change the schedule; no dates were created.' );
            }
            $schedule[] = array( 'from' => $date, 'to' => $occurrence['booking_date_end'] );
        }
        return array( 'data' => $d, 'schedule' => $schedule, 'amount_per_booking' => $amount, 'estimated_total' => round( $amount * count( $dates ), 2 ), 'payment_features' => MBS_Payment_Features::state(), 'terms_hash' => hash( 'sha256', (string) get_option( 'mbs_terms_text', '' ) ) );
    }
    public function preview( $request ) {
        $input = $request->get_json_params(); $plan = self::plan( $input ); if ( is_wp_error( $plan ) ) return $plan;
        $token = wp_generate_uuid4();
        set_transient( 'mbs_entry_' . get_current_user_id() . '_' . $token, array( 'input' => $input, 'plan' => $plan ), 900 );
        return rest_ensure_response( array( 'token' => $token, 'plan' => $plan ) );
    }
    public function commit( $request ) {
        if ( ! rest_sanitize_boolean( $request->get_param( 'confirm' ) ) ) return self::fail( 'Confirm the reviewed booking first.' );
        $token = sanitize_text_field( $request->get_param( 'token' ) ?? '' );
        if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $token ) ) return self::fail( 'Refresh the preview.' );
        $key = 'mbs_entry_' . get_current_user_id() . '_' . $token;
        $result = get_transient( $key . '_result' ); if ( $result ) return $result;
        $preview = get_transient( $key ); if ( ! $preview ) return self::fail( 'Preview expired. Review again.' );
        // Never steal a possibly active/failed write lock: an uncertain result needs investigation.
        if ( ! add_option( $key . '_lock', time(), '', false ) ) return self::fail( 'This submission is processing or needs review. Check All Bookings before retrying; do not create another copy.' );
        try {
            $plan = self::plan( $preview['input'] );
            if ( is_wp_error( $plan ) ) { delete_option( $key . '_lock' ); return $plan; }
            if ( wp_json_encode( $plan ) !== wp_json_encode( $preview['plan'] ) ) { delete_option( $key . '_lock' ); return self::fail( 'Booking rules or price changed. Refresh and review again.' ); }
            $payload = $plan['data']; $payload['idempotency_key'] = 'staff-' . $token;
            $api_request = new WP_REST_Request( 'POST', '/mathlin/v1/admin/bookings' );
            foreach ( $payload as $k => $v ) $api_request->set_param( $k, $v );
            $result = ( new MBS_REST_API() )->create_admin_booking( $api_request );
            if ( is_wp_error( $result ) ) {
                $result = new WP_Error( 'booking_entry_review', $result->get_error_message() . ' Check All Bookings/Recurring Series before creating another request.', array( 'status' => 409, 'outcome' => $result->get_error_data() ) );
            }
            set_transient( $key . '_result', $result, DAY_IN_SECONDS );
            delete_transient( $key ); delete_option( $key . '_lock' );
            return $result;
        } catch ( Throwable $e ) {
            return self::fail( 'Submission stopped with an uncertain outcome. Check All Bookings/Recurring Series before trying again. The submission lock has been retained.' );
        }
    }
}
