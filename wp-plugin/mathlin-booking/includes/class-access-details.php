<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Access Details — sends access codes to eligible approved bookings.
 *
 * Runs via WP-Cron daily. Sends access details to bookers and a heads-up
 * notification to admin emails. Can also be triggered manually from admin.
 */
class MBS_Access_Details {

    public function init() {
        add_action( 'mbs_daily_access_details', array( $this, 'send_access_details' ) );

        $event = wp_get_scheduled_event( 'mbs_daily_access_details' );
        if ( $event && $event->schedule !== 'hourly' ) {
            wp_clear_scheduled_hook( 'mbs_daily_access_details' );
            $event = false;
        }
        if ( ! $event ) {
            wp_schedule_event( time() + 60, 'hourly', 'mbs_daily_access_details' );
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'mbs_daily_access_details' );
    }

    /**
     * Get access settings.
     */
    public static function get_settings() {
        return array(
            'enabled'      => (bool) get_option( 'mbs_access_enabled', false ),
            'code'         => get_option( 'mbs_access_code', '' ),
            'instructions' => get_option( 'mbs_access_instructions', '' ),
            'hours_before' => (int) get_option( 'mbs_access_hours_before', 24 ),
        );
    }

    /**
     * Daily cron: find paid bookings happening soon and send access details.
     */
    public function send_access_details() {
        $settings = self::get_settings();
        if ( ! $settings['enabled'] || empty( $settings['code'] ) ) return;

        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;

        $target_date = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+' . max( 24, $settings['hours_before'] ) . ' hours' )->format( 'Y-m-d' );
        $today       = wp_date( 'Y-m-d' );

        // Fetch all upcoming, unsent, non-cancelled bookings in the window.
        // We include 'confirmed' and 'deposit_paid' as CANDIDATES — the per-booking
        // tier gate below decides whether each is actually eligible.
        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE booking_date BETWEEN %s AND %s
             AND status IN ('paid', 'deposit_paid', 'confirmed')
             AND access_sent = 0
             ORDER BY booking_date ASC, start_time ASC",
            $today, $target_date
        ) );

        if ( empty( $bookings ) ) return;

        $sent_count = 0;
        foreach ( $bookings as $booking ) {
            if ( ! self::is_eligible_for_access( $booking ) || ! self::is_due( $booking ) ) {
                continue; // Not yet payment-cleared for this tier — skip (will re-check next run)
            }

            if ( ! is_wp_error( self::queue( $booking ) ) ) $sent_count++;
        }

        if ( $sent_count > 0 ) {
            error_log( '[MGF Venue] Queued access details for ' . $sent_count . ' booking(s).' );
        }
    }

    /**
     * Determine whether a booking is eligible to receive its access code now.
     *
     * Payments disabled: approved statuses qualify. Payments enabled: paid only.
     * Pricing tiers cannot bypass this rule.
     */
    public static function is_eligible_for_access( $booking ) {
        if ( ! in_array( $booking->status, array( 'confirmed', 'deposit_paid', 'paid' ), true ) ) return false;
        return ! MBS_Payment_Features::enabled( 'payments' ) || $booking->status === 'paid';
    }

    /** Local calendar day before at 08:00 for the normal 24-hour setting.
     * Hourly checks catch late approval/payment, but never send after start.
     */
    public static function is_due( $booking, $now = null ) {
        $now = $now ?: new DateTimeImmutable( 'now', wp_timezone() );
        $start = new DateTimeImmutable( $booking->booking_date . ' ' . ( ! empty( $booking->all_day ) ? '00:00:00' : $booking->start_time ), wp_timezone() );
        $hours = max( 1, (int) get_option( 'mbs_access_hours_before', 24 ) );
        $due = $hours === 24 ? $start->modify( '-1 day' )->setTime( 8, 0 ) : $start->modify( '-' . $hours . ' hours' );
        return $now >= $due && $now < $start;
    }

    /** Booking snapshot only; no code or private notes in the outbox key. */
    public static function source_hash( $booking ) {
        $values = array();
        foreach ( array( 'ref','booking_date','booking_date_end','start_time','end_time','all_day','space','email' ) as $field ) $values[$field] = $booking->$field ?? '';
        return hash( 'sha256', wp_json_encode( $values ) );
    }

    public static function queue( $booking, $manual = false ) {
        if ( ! self::is_eligible_for_access( $booking ) ) return new WP_Error( 'access_ineligible', 'Access requires an approved booking and full payment when payments are enabled.' );
        $settings = self::get_settings();
        if ( ! $settings['enabled'] || $settings['code'] === '' ) return new WP_Error( 'access_unconfigured', 'Access details are not configured.' );
        $snapshot = self::source_hash( $booking );
        // Existing queue keys are VARCHAR(100), including upgraded installs.
        $key = 'access:' . $snapshot . ( $manual ? ':' . substr( hash( 'sha256', wp_generate_uuid4() ), 0, 16 ) : '' );
        $subject = 'Access details — ' . $booking->ref;
        $body = '<p>Access details will be prepared from current settings at dispatch.</p>';
        return MBS_Email_Queue::enqueue( $booking->email, $subject, $body, array(), $key,
            MBS_Email_Queue::compute_payload_hash( $booking->email, $subject, $body, array(), null ), null,
            array( 'message_type' => $manual ? 'access_manual' : 'access_details', 'reference_type' => 'booking', 'reference_id' => (int) $booking->id ) );
    }

    /** Revalidate on every retry; never send stale dates, contact or access policy. */
    public static function queued_message( $email ) {
        $booking = MBS_Bookings::get_by_id( (int) $email->reference_id );
        $settings = self::get_settings();
        if ( ! $booking || ! in_array( $booking->status, array( 'confirmed','deposit_paid','paid' ), true ) ) return new WP_Error( 'access_inactive', 'Access source is no longer approved.' );
        $start = new DateTimeImmutable( $booking->booking_date . ' ' . ( ! empty( $booking->all_day ) ? '00:00:00' : $booking->start_time ), wp_timezone() );
        if ( $email->message_type === 'access_details' && new DateTimeImmutable( 'now', wp_timezone() ) >= $start ) return new WP_Error( 'access_expired', 'Booking has already started.' );
        if ( ! $booking || ! self::is_eligible_for_access( $booking ) || ! $settings['enabled'] || $settings['code'] === '' ) return new WP_Error( 'access_ineligible', 'Access delivery paused: current booking/settings are not eligible.' );
        if ( strpos( $email->message_key, 'access:' . self::source_hash( $booking ) ) !== 0 ) return new WP_Error( 'access_stale', 'Booking changed; access email snapshot is stale.' );
        if ( $email->message_type === 'access_details' && ! self::is_due( $booking ) ) return new WP_Error( 'access_not_due', 'Access email is not currently due.' );
        return self::build_message( $booking );
    }

    /** Narrow settings patch: never clears unrelated options or returns the code. */
    public static function save_settings( $patch ) {
        if ( ! is_array( $patch ) || array_diff( array_keys( $patch ), array( 'enabled','code','instructions','health_safety','hours_before' ) ) ) return new WP_Error( 'invalid_access_settings', 'Unknown access setting.' );
        if ( isset( $patch['enabled'] ) && ! in_array( $patch['enabled'], array( true,false,0,1,'0','1' ), true ) ) return new WP_Error( 'invalid_access_settings', 'Enabled must be boolean.' );
        if ( isset( $patch['hours_before'] ) && ( ! ctype_digit( (string) $patch['hours_before'] ) || (int) $patch['hours_before'] < 1 || (int) $patch['hours_before'] > 168 ) ) return new WP_Error( 'invalid_access_settings', 'Hours must be between 1 and 168.' );
        foreach ( array( 'code','instructions','health_safety' ) as $field ) if ( isset( $patch[$field] ) && ! is_string( $patch[$field] ) ) return new WP_Error( 'invalid_access_settings', 'Access text must be a string.' );
        $enabled = $patch['enabled'] ?? get_option( 'mbs_access_enabled', 0 );
        $code = isset( $patch['code'] ) ? sanitize_text_field( $patch['code'] ) : get_option( 'mbs_access_code', '' );
        if ( $enabled && $code === '' ) return new WP_Error( 'invalid_access_settings', 'A code is required before enabling access emails.' );
        foreach ( $patch as $field => $value ) {
            $value = in_array( $field, array( 'enabled','hours_before' ), true ) ? (int) $value : ( $field === 'code' ? sanitize_text_field( $value ) : sanitize_textarea_field( $value ) );
            update_option( 'mbs_access_' . $field, $value, false );
        }
        return array( 'enabled' => (bool) $enabled, 'code_configured' => $code !== '', 'hours_before' => (int) get_option( 'mbs_access_hours_before', 24 ) );
    }

    /**
     * Send access details email to the booker.
     */
    public static function build_message( $booking ) {
        $settings = self::get_settings();
        $tpl      = MBS_Email_Templates::get_template( 'access_details' );
        $subject  = MBS_Email_Templates::replace_placeholders( $tpl['subject'], $booking );
        $body_text = MBS_Email_Templates::replace_placeholders( $tpl['body'], $booking );

        $org         = MBS_Email_Templates::get_org_settings();
        $admin_email = MBS_Bookings::get_admin_email();

        $body  = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:0 auto;">';
        $body .= '<div style="background:#2ecc71;padding:24px 32px;border-radius:8px 8px 0 0;text-align:center;">';
        $body .= MBS_Email_Templates::get_logo_html();
        $body .= '<h1 style="color:#fff;margin:8px 0 0;font-size:20px;">' . esc_html( $org['name'] ) . '</h1>';
        $body .= '<p style="color:rgba(255,255,255,0.9);margin:4px 0 0;">Access Details</p>';
        $body .= '</div>';
        $body .= '<div style="background:#fff;padding:32px;border:1px solid #e0d0f0;border-top:none;border-radius:0 0 8px 8px;">';
        $body .= '<h2 style="color:#2ecc71;">Your Access Details</h2>';
        $body .= nl2br( esc_html( $body_text ) );

        // Access code box
        $body .= '<div style="background:#d1fae5;border:2px solid #2ecc71;border-radius:8px;padding:20px;margin:20px 0;text-align:center;">';
        $body .= '<p style="margin:0 0 8px;font-size:13px;color:#065f46;font-weight:600;">🔑 ACCESS CODE</p>';
        $body .= '<p style="margin:0;font-size:32px;font-weight:bold;letter-spacing:4px;color:#065f46;">' . esc_html( $settings['code'] ) . '</p>';
        $body .= '</div>';

        // Access instructions
        if ( ! empty( $settings['instructions'] ) ) {
            $body .= '<div style="background:#f5f0ff;border:1px solid #e0d0f0;border-radius:6px;padding:16px;margin:16px 0;">';
            $body .= '<strong>Access Instructions:</strong><br>';
            $body .= wp_kses_post( nl2br( $settings['instructions'] ) );
            $body .= '</div>';
        }

        // Health & Safety information
        $hs_info = get_option( 'mbs_access_health_safety', '' );
        if ( ! empty( $hs_info ) ) {
            $body .= '<div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:6px;padding:16px;margin:16px 0;">';
            $body .= '<strong>⚠️ Health &amp; Safety:</strong><br>';
            $body .= wp_kses_post( nl2br( $hs_info ) );
            $body .= '</div>';
        }

        // Booking summary
        $body .= '<table style="width:100%;border-collapse:collapse;margin:16px 0;">';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;width:35%;border-bottom:1px solid #e0d0f0;">Reference</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->ref ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Space</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->space ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Date</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( wp_date( 'l j F Y', strtotime( $booking->booking_date ) ) ) . '</td></tr>';
        $time_str = $booking->all_day ? 'All day' : ( $booking->start_time . ' – ' . $booking->end_time );
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Time</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $time_str ) . '</td></tr>';
        $body .= '</table>';

        // Terms & Conditions link
        $terms_page_id = (int) get_option( 'mbs_terms_page_id', 0 );
        if ( $terms_page_id ) {
            $terms_url = get_permalink( $terms_page_id );
            if ( $terms_url ) {
                $body .= '<p style="font-size:13px;color:#6b7280;margin-top:16px;padding:12px 16px;background:#f5f0ff;border-radius:6px;">';
                $body .= 'By using this venue you agree to our <a href="' . esc_url( $terms_url ) . '" style="color:#7413DC;font-weight:600;">Terms &amp; Conditions of Hire</a>. ';
                $body .= 'Please ensure all members of your party are aware of the conditions.';
                $body .= '</p>';
            }
        }

        $body .= '<p style="font-size:13px;color:#6b7280;margin-top:16px;"><em>Please do not share this code with anyone outside your booking party.</em></p>';
        $body .= '</div></body></html>';

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $org['name'] . ' <' . get_option( 'admin_email', $admin_email ) . '>',
            'Reply-To: ' . $admin_email,
        );
        return array( 'subject' => $subject, 'body' => $body, 'headers' => $headers );
    }

    public static function send_to_booker( $booking ) {
        return self::queue( $booking );
    }

    /**
     * Send admin notification that a booking is happening soon.
     */
    public static function send_admin_notification( $booking ) {
        $org         = MBS_Email_Templates::get_org_settings();
        $admin_email = MBS_Bookings::get_admin_email();
        $emails      = array( $admin_email );

        // Include additional notification emails
        $additional = get_option( 'mbs_additional_emails', '' );
        if ( ! empty( $additional ) ) {
            $extras = array_map( 'trim', explode( ',', $additional ) );
            foreach ( $extras as $email ) {
                if ( is_email( $email ) && $email !== $admin_email ) {
                    $emails[] = $email;
                }
            }
        }

        $time_str = $booking->all_day ? 'All day' : ( $booking->start_time . ' – ' . $booking->end_time );
        $subject  = '[Upcoming] ' . $booking->ref . ' – ' . $booking->name . ' – ' . wp_date( 'D j M', strtotime( $booking->booking_date ) );

        $body  = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:0 auto;">';
        $body .= '<div style="background:#3498db;padding:24px 32px;border-radius:8px 8px 0 0;text-align:center;">';
        $body .= MBS_Email_Templates::get_logo_html();
        $body .= '<h1 style="color:#fff;margin:8px 0 0;font-size:20px;">' . esc_html( $org['name'] ) . '</h1>';
        $body .= '<p style="color:rgba(255,255,255,0.9);margin:4px 0 0;">Upcoming Booking Reminder</p>';
        $body .= '</div>';
        $body .= '<div style="background:#fff;padding:32px;border:1px solid #e0d0f0;border-top:none;border-radius:0 0 8px 8px;">';
        $body .= '<h2 style="color:#3498db;">Booking Tomorrow</h2>';
        $body .= '<p>A booking is happening soon. The mail service has accepted the access email for the hirer. This does not independently confirm inbox delivery.</p>';

        $body .= '<table style="width:100%;border-collapse:collapse;margin:16px 0;">';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;width:35%;border-bottom:1px solid #e0d0f0;">Reference</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->ref ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Name</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->name ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Organisation</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->organisation ?: '—' ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Space</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->space ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Date</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( wp_date( 'l j F Y', strtotime( $booking->booking_date ) ) ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Time</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $time_str ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Attendees</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->attendees ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Purpose</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->purpose ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Phone</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->phone ) . '</td></tr>';
        $body .= '</table>';

        $body .= '<p style="margin-top:16px;"><a href="' . admin_url( 'admin.php?page=mathlin-booking&action=view&ref=' . $booking->ref ) . '" style="background:#7413DC;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;">View Booking</a></p>';
        $body .= '</div></body></html>';

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $org['name'] . ' <' . get_option( 'admin_email', $admin_email ) . '>',
        );

        foreach ( $emails as $to ) {
            MBS_Email_Queue::send( $to, $subject, $body, $headers );
        }
    }

    /**
     * Manually resend access details for a specific booking.
     */
    public static function resend( $booking ) {
        $result = self::queue( $booking, true );
        if ( ! is_wp_error( $result ) ) MBS_Audit_Log::log( $booking->ref, 'access_resent', 'Access details queued by admin; eligibility rechecked at dispatch.' );
        return $result;
    }
}
