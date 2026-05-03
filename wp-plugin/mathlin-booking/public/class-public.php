<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MBS_Public {

    public function init() {
        add_shortcode( 'mathlin_booking', array( $this, 'shortcode_booking' ) );
        add_shortcode( 'mathlin_calendar', array( $this, 'shortcode_calendar' ) );
        add_shortcode( 'mathlin_status', array( $this, 'shortcode_status' ) );
        add_shortcode( 'mathlin_modify', array( $this, 'shortcode_modify' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_nopriv_mbs_submit_booking', array( $this, 'ajax_submit' ) );
        add_action( 'wp_ajax_mbs_submit_booking',        array( $this, 'ajax_submit' ) );
        add_action( 'wp_ajax_nopriv_mbs_get_calendar',   array( $this, 'ajax_calendar' ) );
        add_action( 'wp_ajax_mbs_get_calendar',          array( $this, 'ajax_calendar' ) );
        add_action( 'wp_ajax_nopriv_mbs_get_day',        array( $this, 'ajax_get_day' ) );
        add_action( 'wp_ajax_mbs_get_day',               array( $this, 'ajax_get_day' ) );
        add_action( 'wp_ajax_nopriv_mbs_lookup_booking', array( $this, 'ajax_lookup_booking' ) );
        add_action( 'wp_ajax_mbs_lookup_booking',        array( $this, 'ajax_lookup_booking' ) );
    }

    public function enqueue_assets() {
        global $post;
        // Only load on pages that use our shortcodes
        if ( is_a( $post, 'WP_Post' ) && (
            has_shortcode( $post->post_content, 'mathlin_booking' ) ||
            has_shortcode( $post->post_content, 'mathlin_calendar' ) ||
            has_shortcode( $post->post_content, 'mathlin_status' ) ||
            has_shortcode( $post->post_content, 'mathlin_modify' ) ||
            has_shortcode( $post->post_content, 'mathlin_portal' ) ||
            isset( $_GET['mbs_modify'] )
        ) ) {
            wp_enqueue_style(  'mbs-public', MBS_PLUGIN_URL . 'public/public.css', array(), MBS_VERSION );
            wp_enqueue_script( 'mbs-public', MBS_PLUGIN_URL . 'public/public.js',  array( 'jquery' ), MBS_VERSION, true );
            $notice_days = (int) get_option( 'mbs_min_notice_days', 1 );

            // Find portal page URL
            $portal_url = '';
            $portal_pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 's' => 'mathlin_portal', 'numberposts' => 1 ) );
            if ( ! empty( $portal_pages ) ) $portal_url = get_permalink( $portal_pages[0]->ID );

            wp_localize_script( 'mbs-public', 'NMS', array(
                'ajax_url'        => admin_url( 'admin-ajax.php' ),
                'nonce'           => wp_create_nonce( 'mbs_public_nonce' ),
                'spaces'          => MBS_Bookings::get_spaces(),
                'kitchen_price'   => MBS_Bookings::get_kitchen_price(),
                'min_notice_days' => $notice_days,
                'min_date'        => date( 'Y-m-d', strtotime( "+{$notice_days} days" ) ),
                'blocked_dates'   => self::get_blocked_dates_for_frontend(),
                'is_logged_in'    => is_user_logged_in(),
                'portal_url'      => $portal_url,
            ) );
        }
    }

    // ── Shortcode: full booking form ───────────────────────────────────────────
    public function shortcode_booking( $atts ) {
        ob_start();
        include MBS_PLUGIN_DIR . 'public/views/booking-form.php';
        return ob_get_clean();
    }

    // ── Shortcode: calendar only ───────────────────────────────────────────────
    public function shortcode_calendar( $atts ) {
        ob_start();
        include MBS_PLUGIN_DIR . 'public/views/calendar.php';
        return ob_get_clean();
    }

    // ── Shortcode: booking status lookup ───────────────────────────────────────
    public function shortcode_status( $atts ) {
        ob_start();
        include MBS_PLUGIN_DIR . 'public/views/booking-status.php';
        return ob_get_clean();
    }

    public function shortcode_modify( $atts ) {
        ob_start();
        include MBS_PLUGIN_DIR . 'public/views/modification-form.php';
        return ob_get_clean();
    }

    // ── AJAX: submit booking ───────────────────────────────────────────────────
    public function ajax_submit() {
        check_ajax_referer( 'mbs_public_nonce', 'nonce' );

        $required = array( 'name', 'email', 'phone', 'address', 'space', 'booking_date', 'attendees', 'purpose' );
        foreach ( $required as $field ) {
            if ( empty( $_POST[ $field ] ) ) {
                wp_send_json_error( array( 'message' => 'Please fill in all required fields.' ) );
            }
        }

        // Validate email
        if ( ! is_email( $_POST['email'] ) ) {
            wp_send_json_error( array( 'message' => 'Please enter a valid email address.' ) );
        }

        // Validate T&Cs acceptance
        $terms_page_id = (int) get_option( 'mbs_terms_page_id', 0 );
        if ( $terms_page_id && get_post( $terms_page_id ) && empty( $_POST['accept_terms'] ) ) {
            wp_send_json_error( array( 'message' => 'You must agree to the Terms & Conditions to make a booking.' ) );
        }

        // Validate date — must be at least min_notice_days from today
        $date         = sanitize_text_field( $_POST['booking_date'] );
        $notice_days  = (int) get_option( 'mbs_min_notice_days', 1 );
        $min_date     = date( 'Y-m-d', strtotime( "+{$notice_days} days" ) );

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            wp_send_json_error( array( 'message' => 'Please select a valid date.' ) );
        }
        if ( strtotime( $date ) < strtotime( $min_date ) ) {
            if ( $notice_days === 0 ) {
                $msg = 'Please select today or a future date.';
            } elseif ( $notice_days === 1 ) {
                $msg = 'Bookings must be made at least 1 day in advance. The earliest available date is ' . date( 'j F Y', strtotime( $min_date ) ) . '.';
            } else {
                $msg = "Bookings must be made at least {$notice_days} days in advance. The earliest available date is " . date( 'j F Y', strtotime( $min_date ) ) . '.';
            }
            wp_send_json_error( array( 'message' => $msg ) );
        }

        // Validate space
        $spaces = MBS_Bookings::get_spaces();
        $space  = sanitize_text_field( $_POST['space'] );
        if ( ! isset( $spaces[ $space ] ) ) {
            wp_send_json_error( array( 'message' => 'Invalid space selected.' ) );
        }

        // Validate times for hourly bookings (not all-day)
        $all_day = ! empty( $_POST['all_day'] );
        if ( ! $all_day ) {
            $start = sanitize_text_field( $_POST['start_time'] ?? '' );
            $end   = sanitize_text_field( $_POST['end_time'] ?? '' );
            if ( ! $start || ! $end ) {
                wp_send_json_error( array( 'message' => 'Please enter start and end times for hourly bookings.' ) );
            }
            if ( ! preg_match( '/^\d{2}:\d{2}$/', $start ) || ! preg_match( '/^\d{2}:\d{2}$/', $end ) ) {
                wp_send_json_error( array( 'message' => 'Invalid time format. Please use HH:MM.' ) );
            }
            if ( strtotime( $end ) <= strtotime( $start ) ) {
                wp_send_json_error( array( 'message' => 'End time must be after start time.' ) );
            }
        }

        // Validate end date if multi-day
        $date_end = sanitize_text_field( $_POST['booking_date_end'] ?? '' );
        if ( $date_end && strtotime( $date_end ) < strtotime( $date ) ) {
            wp_send_json_error( array( 'message' => 'End date must be on or after start date.' ) );
        }

        // Check blocked dates for entire range
        $check_end = $date_end ?: $date;
        $check_date = strtotime( $date );
        $check_end_ts = strtotime( $check_end );
        while ( $check_date <= $check_end_ts ) {
            $check_str = date( 'Y-m-d', $check_date );
            $blocked = MBS_Blocked_Dates::is_blocked( $check_str, $space );
            if ( $blocked ) {
                $reason_msg = $blocked->reason ? ' Reason: ' . $blocked->reason : '';
                wp_send_json_error( array( 'message' => 'Sorry, ' . date( 'j M Y', $check_date ) . ' is unavailable for booking.' . $reason_msg ) );
            }
            $check_date += 86400;
        }

        // Check for booking conflicts
        $check_end = $date_end ?: $date;
        $check_date = strtotime( $date );
        $check_end_ts = strtotime( $check_end );
        while ( $check_date <= $check_end_ts ) {
            $check_str = date( 'Y-m-d', $check_date );
            $conflicts = MBS_Bookings::check_conflicts(
                $space,
                $check_str,
                $all_day ? null : sanitize_text_field( $_POST['start_time'] ?? '' ),
                $all_day ? null : sanitize_text_field( $_POST['end_time'] ?? '' ),
                $all_day
            );
            if ( ! empty( $conflicts ) ) {
                wp_send_json_error( array( 'message' => MBS_Bookings::format_conflict_message( $conflicts ) ) );
            }
            $check_date += 86400;
        }

        // Validate custom fields
        $custom_responses = MBS_Custom_Fields::validate_submission( $_POST );
        if ( is_wp_error( $custom_responses ) ) {
            wp_send_json_error( array( 'message' => $custom_responses->get_error_message() ) );
        }

        // Handle recurring bookings
        $repeat_until = sanitize_text_field( $_POST['repeat_until'] ?? '' );
        if ( $repeat_until ) {
            $result = MBS_Bookings::create_recurring( $_POST, $repeat_until );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            // Send emails for the first booking in the series
            $first_booking_data = $_POST;
            $first_booking_data['ref'] = $result['refs'][0];
            MBS_Email::notify_admin( array_merge( $first_booking_data, array(
                'ref' => $result['refs'][0],
                'amount' => MBS_Bookings::calculate_cost( $space, $_POST['start_time'] ?? '', $_POST['end_time'] ?? '', ! empty( $_POST['kitchen'] ), $all_day ),
            ) ) );

            $msg = 'Recurring booking submitted! ' . $result['created'] . ' booking(s) created (reference series: ' . $result['series_id'] . ').';
            if ( ! empty( $result['skipped'] ) ) {
                $msg .= ' ' . count( $result['skipped'] ) . ' date(s) were skipped due to conflicts or blocked dates.';
            }

            wp_send_json_success( array(
                'ref'       => $result['series_id'],
                'message'   => $msg,
                'recurring' => true,
                'created'   => $result['created'],
                'skipped'   => count( $result['skipped'] ),
            ) );
        }

        $result = MBS_Bookings::create( $_POST );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        // Send emails
        MBS_Email::notify_admin( $result );
        MBS_Email::notify_booker( $result );

        wp_send_json_success( array(
            'ref'     => $result['ref'],
            'message' => 'Your booking request has been submitted! Reference: ' . $result['ref'],
            'account_exists' => email_exists( sanitize_email( $_POST['email'] ) ) ? true : false,
        ) );
    }

    // ── AJAX: get calendar data for a month ────────────────────────────────────
    public function ajax_calendar() {
        check_ajax_referer( 'mbs_public_nonce', 'nonce' );
        $year  = absint( $_POST['year']  ?? date('Y') );
        $month = absint( $_POST['month'] ?? date('n') );
        $dates = MBS_Bookings::get_booked_dates( $year, $month );
        wp_send_json_success( $dates );
    }

    // ── AJAX: get bookings for a specific day ──────────────────────────────────
    public function ajax_get_day() {
        check_ajax_referer( 'mbs_public_nonce', 'nonce' );
        $date     = sanitize_text_field( $_POST['date'] ?? '' );
        $bookings = MBS_Bookings::get_by_date( $date );
        // Return only non-sensitive info for public display
        $safe = array_map( function( $b ) {
            $data = array(
                'space'      => $b->space,
                'start_time' => $b->start_time,
                'end_time'   => $b->end_time,
                'all_day'    => ! empty( $b->all_day ),
                'is_public'  => ! empty( $b->is_public ),
            );
            // Show event details for public bookings
            if ( ! empty( $b->is_public ) ) {
                $data['purpose'] = $b->purpose;
                $data['name']    = $b->organisation ?: $b->name;
            }
            return $data;
        }, $bookings );
        wp_send_json_success( $safe );
    }

    // ── AJAX: lookup booking by reference ──────────────────────────────────────
    public function ajax_lookup_booking() {
        check_ajax_referer( 'mbs_public_nonce', 'nonce' );
        $ref = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );

        if ( ! $ref ) {
            wp_send_json_error( array( 'message' => 'Please enter a booking reference.' ) );
        }

        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) {
            wp_send_json_error( array( 'message' => 'No booking found with reference ' . $ref . '. Please check and try again.' ) );
        }

        $spaces   = MBS_Bookings::get_spaces();
        $is_daily = ! empty( $booking->all_day );
        $time_str = $is_daily ? 'All day' : ( $booking->start_time . ' – ' . $booking->end_time );

        $data = array(
            'ref'             => $booking->ref,
            'status'          => $booking->status,
            'space'           => $booking->space,
            'date_formatted'  => date( 'l j F Y', strtotime( $booking->booking_date ) ),
            'time'            => $time_str,
            'attendees'       => $booking->attendees,
            'purpose'         => $booking->purpose,
            'amount'          => number_format( $booking->amount, 2 ),
            'invoice_number'  => $booking->invoice_number,
            'ical_url'        => rest_url( 'mathlin/v1/bookings/' . $booking->ref . '/ical' ),
            'payment_url'     => '',
        );

        // Add payment URL if WooCommerce is available and booking is confirmed (not yet paid)
        if ( $booking->status === 'confirmed' && MBS_Woo_Payment::is_available() ) {
            $data['payment_url'] = MBS_Woo_Payment::generate_payment_url( $booking );
        }

        // Add modification URL
        if ( ! in_array( $booking->status, array( 'cancelled', 'archived' ) ) ) {
            $data['modify_url'] = MBS_Modification::get_modification_url( $booking );
        }

        wp_send_json_success( $data );
    }

    /**
     * Get blocked dates for the next 6 months for the frontend calendar.
     * Returns a flat array of blocked date strings for "all spaces" blocks.
     */
    private static function get_blocked_dates_for_frontend() {
        $from = date( 'Y-m-d' );
        $to   = date( 'Y-m-d', strtotime( '+6 months' ) );

        $entries = MBS_Blocked_Dates::get_for_range( $from, $to );
        $blocked = array();

        foreach ( $entries as $entry ) {
            $start = max( strtotime( $from ), strtotime( $entry->date_from ) );
            $end   = min( strtotime( $to ), strtotime( $entry->date_to ) );

            for ( $d = $start; $d <= $end; $d += 86400 ) {
                $date_str = date( 'Y-m-d', $d );
                if ( ! isset( $blocked[ $date_str ] ) ) {
                    $blocked[ $date_str ] = array();
                }
                $blocked[ $date_str ][] = $entry->space ?: '__all__';
            }
        }

        return $blocked;
    }
}
