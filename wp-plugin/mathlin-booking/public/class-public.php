<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MBS_Public {

    public function init() {
        add_shortcode( 'mathlin_booking', array( $this, 'shortcode_booking' ) );
        add_shortcode( 'mathlin_calendar', array( $this, 'shortcode_calendar' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_nopriv_mbs_submit_booking', array( $this, 'ajax_submit' ) );
        add_action( 'wp_ajax_mbs_submit_booking',        array( $this, 'ajax_submit' ) );
        add_action( 'wp_ajax_nopriv_mbs_get_calendar',   array( $this, 'ajax_calendar' ) );
        add_action( 'wp_ajax_mbs_get_calendar',          array( $this, 'ajax_calendar' ) );
        add_action( 'wp_ajax_nopriv_mbs_get_day',        array( $this, 'ajax_get_day' ) );
        add_action( 'wp_ajax_mbs_get_day',               array( $this, 'ajax_get_day' ) );
    }

    public function enqueue_assets() {
        global $post;
        // Only load on pages that use our shortcodes
        if ( is_a( $post, 'WP_Post' ) && (
            has_shortcode( $post->post_content, 'mathlin_booking' ) ||
            has_shortcode( $post->post_content, 'mathlin_calendar' )
        ) ) {
            wp_enqueue_style(  'mbs-public', MBS_PLUGIN_URL . 'public/public.css', array(), MBS_VERSION );
            wp_enqueue_script( 'mbs-public', MBS_PLUGIN_URL . 'public/public.js',  array( 'jquery' ), MBS_VERSION, true );
            $notice_days = (int) get_option( 'mbs_min_notice_days', 1 );
            wp_localize_script( 'mbs-public', 'NMS', array(
                'ajax_url'        => admin_url( 'admin-ajax.php' ),
                'nonce'           => wp_create_nonce( 'mbs_public_nonce' ),
                'spaces'          => MBS_Bookings::get_spaces(),
                'min_notice_days' => $notice_days,
                'min_date'        => date( 'Y-m-d', strtotime( "+{$notice_days} days" ) ),
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

        // Validate times for hourly spaces
        if ( $spaces[ $space ]['unit'] === 'hr' ) {
            $start = sanitize_text_field( $_POST['start_time'] ?? '' );
            $end   = sanitize_text_field( $_POST['end_time'] ?? '' );
            if ( ! $start || ! $end ) {
                wp_send_json_error( array( 'message' => 'Please enter start and end times.' ) );
            }
            if ( strtotime( $end ) <= strtotime( $start ) ) {
                wp_send_json_error( array( 'message' => 'End time must be after start time.' ) );
            }
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
            return array(
                'space'      => $b->space,
                'start_time' => $b->start_time,
                'end_time'   => $b->end_time,
                'all_day'    => $b->space === 'Outdoor Area',
            );
        }, $bookings );
        wp_send_json_success( $safe );
    }
}
