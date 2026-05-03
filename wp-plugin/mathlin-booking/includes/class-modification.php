<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Booking Modification Requests
 *
 * Allows bookers to request changes to their booking via a secure link
 * in their confirmation email. The request is submitted to the admin
 * for approval — the booking is NOT changed automatically.
 *
 * Flow:
 * 1. Booker clicks "Request a Change" link in their email
 * 2. They see a form pre-filled with their booking details
 * 3. They describe what they want to change
 * 4. Admin receives a notification and can approve/deny
 */
class MBS_Modification {

    public function init() {
        add_action( 'wp_ajax_nopriv_mbs_submit_modification', array( $this, 'ajax_submit' ) );
        add_action( 'wp_ajax_mbs_submit_modification',        array( $this, 'ajax_submit' ) );
    }

    /**
     * Generate a secure modification URL for a booking.
     */
    public static function get_modification_url( $booking ) {
        $token = $booking->modification_token;

        // Generate token if missing (for bookings created before v2.0.0)
        if ( empty( $token ) ) {
            $token = wp_generate_password( 32, false );
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . MBS_TABLE,
                array( 'modification_token' => $token ),
                array( 'ref' => $booking->ref )
            );
        }

        // Find a page with the [mathlin_status] shortcode to use as the base URL
        $base_url = home_url();
        $pages = get_posts( array(
            'post_type'  => 'page',
            'post_status' => 'publish',
            's'          => 'mathlin_status',
            'numberposts' => 1,
        ) );
        if ( ! empty( $pages ) ) {
            $base_url = get_permalink( $pages[0]->ID );
        }

        return add_query_arg( array(
            'mbs_modify' => '1',
            'ref'        => $booking->ref,
            'token'      => $token,
        ), $base_url );
    }

    /**
     * Verify a modification token.
     */
    public static function verify_token( $ref, $token ) {
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) return false;
        if ( empty( $booking->modification_token ) ) return false;
        return hash_equals( $booking->modification_token, $token );
    }

    /**
     * Handle modification request submission.
     */
    public function ajax_submit() {
        check_ajax_referer( 'mbs_public_nonce', 'nonce' );

        $ref   = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $token = sanitize_text_field( $_POST['token'] ?? '' );

        if ( ! self::verify_token( $ref, $token ) ) {
            wp_send_json_error( array( 'message' => 'Invalid or expired modification link.' ) );
        }

        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking || in_array( $booking->status, array( 'cancelled', 'archived' ) ) ) {
            wp_send_json_error( array( 'message' => 'This booking cannot be modified.' ) );
        }

        $requested_changes = sanitize_textarea_field( $_POST['changes'] ?? '' );
        $mod_action        = sanitize_text_field( $_POST['mod_action'] ?? 'modify' );

        // Handle cancellation request
        if ( $mod_action === 'cancel' ) {
            $cancel_reason = sanitize_textarea_field( $_POST['cancel_reason'] ?? '' );
            $summary = "CANCELLATION REQUEST by {$booking->name} ({$booking->email})";
            if ( $cancel_reason ) $summary .= "\nReason: {$cancel_reason}";

            $existing_notes = $booking->admin_notes ?? '';
            $timestamp      = current_time( 'j M Y H:i' );
            $new_notes      = trim( $existing_notes . "\n\n--- Cancellation Request ({$timestamp}) ---\n{$summary}" );
            MBS_Bookings::update_admin_notes( $ref, $new_notes );

            MBS_Audit_Log::log( $ref, 'cancellation_requested', 'Booker requested cancellation' . ( $cancel_reason ? ': ' . substr( $cancel_reason, 0, 100 ) : '' ), 0 );
            self::notify_admin_of_request( $booking, $summary );

            wp_send_json_success( array(
                'message' => 'Your cancellation request has been submitted. We\'ll review it and get back to you shortly.',
            ) );
        }

        // Handle modification request
        $new_date     = sanitize_text_field( $_POST['new_date'] ?? '' );
        $new_date_end = sanitize_text_field( $_POST['new_date_end'] ?? '' );
        $new_start    = sanitize_text_field( $_POST['new_start_time'] ?? '' );
        $new_end      = sanitize_text_field( $_POST['new_end_time'] ?? '' );
        $new_space    = sanitize_text_field( $_POST['new_space'] ?? '' );
        $new_type     = sanitize_text_field( $_POST['new_booking_type'] ?? '' );
        $new_kitchen  = sanitize_text_field( $_POST['new_kitchen'] ?? '' );
        $new_attendees = sanitize_text_field( $_POST['new_attendees'] ?? '' );

        // Build a summary of requested changes
        $summary = "Modification requested by {$booking->name} ({$booking->email}):\n\n";
        if ( $new_space && $new_space !== $booking->space ) $summary .= "Space: {$booking->space} → {$new_space}\n";
        if ( $new_date && $new_date !== $booking->booking_date ) $summary .= "Date: {$booking->booking_date} → {$new_date}\n";
        if ( $new_date_end ) $summary .= "End date: {$new_date_end}\n";
        if ( $new_type ) $summary .= "Booking type: {$new_type}\n";
        if ( $new_start && $new_end ) $summary .= "Time: {$new_start} – {$new_end}\n";
        if ( $new_kitchen !== '' ) $summary .= "Kitchen: " . ( $new_kitchen ? 'Yes' : 'No' ) . "\n";
        if ( $new_attendees ) $summary .= "Attendees: {$new_attendees}\n";
        if ( $requested_changes ) $summary .= "Notes: {$requested_changes}\n";
        if ( $requested_changes ) $summary .= "Details: {$requested_changes}\n";

        // Add to admin notes
        $existing_notes = $booking->admin_notes ?? '';
        $timestamp      = current_time( 'j M Y H:i' );
        $new_notes      = trim( $existing_notes . "\n\n--- Modification Request ({$timestamp}) ---\n{$summary}" );
        MBS_Bookings::update_admin_notes( $ref, $new_notes );

        // Audit log
        MBS_Audit_Log::log( $ref, 'modification_requested', 'Booker requested changes: ' . substr( $requested_changes ?: $new_date, 0, 100 ), 0 );

        // Notify admin
        self::notify_admin_of_request( $booking, $summary );

        wp_send_json_success( array(
            'message' => 'Your modification request has been submitted. We\'ll review it and get back to you shortly.',
        ) );
    }

    /**
     * Send notification to admin about a modification request.
     */
    private static function notify_admin_of_request( $booking, $summary ) {
        $admin_email = MBS_Bookings::get_admin_email();
        $org         = MBS_Email_Templates::get_org_settings();

        $subject = '[Modification Request] ' . $booking->ref . ' – ' . $booking->name;

        $body  = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:0 auto;">';
        $body .= '<div style="background:#f39c12;padding:24px 32px;border-radius:8px 8px 0 0;">';
        $body .= '<h1 style="color:#fff;margin:0;font-size:20px;">&#9884; ' . esc_html( $org['name'] ) . '</h1>';
        $body .= '<p style="color:rgba(255,255,255,0.9);margin:4px 0 0;">Modification Request</p>';
        $body .= '</div>';
        $body .= '<div style="background:#fff;padding:32px;border:1px solid #e0d0f0;border-top:none;border-radius:0 0 8px 8px;">';
        $body .= '<h2 style="color:#f39c12;">Booking Modification Requested</h2>';
        $body .= '<p>A booker has requested changes to their booking:</p>';
        $body .= '<pre style="background:#f9f7ff;padding:16px;border-radius:6px;font-size:14px;white-space:pre-wrap;">' . esc_html( $summary ) . '</pre>';
        $body .= '<p style="margin-top:24px;"><a href="' . admin_url( 'admin.php?page=mathlin-booking&action=view&ref=' . $booking->ref ) . '" style="background:#7413DC;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;">Review Booking</a></p>';
        $body .= '</div></body></html>';

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $org['name'] . ' <' . $admin_email . '>',
        );
        MBS_Email_Queue::send( $admin_email, $subject, $body, $headers );
    }
}
