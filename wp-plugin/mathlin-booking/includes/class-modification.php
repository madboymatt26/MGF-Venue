<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Booking Modification & Cancellation Requests
 *
 * Stores requests in wp_mathlin_mod_requests table.
 * Admin sees a queue with Approve/Reject buttons.
 * Approve auto-applies changes (or cancels) and emails the booker.
 * Reject sends a "sorry" email.
 */
class MBS_Modification {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'mathlin_mod_requests';
    }

    public function init() {
        add_action( 'wp_ajax_nopriv_mbs_submit_modification', array( $this, 'ajax_submit' ) );
        add_action( 'wp_ajax_mbs_submit_modification',        array( $this, 'ajax_submit' ) );
    }

    // ── Token & URL ────────────────────────────────────────────────────────────

    public static function get_modification_url( $booking ) {
        $token = $booking->modification_token;
        if ( empty( $token ) ) {
            $token = wp_generate_password( 32, false );
            global $wpdb;
            $wpdb->update( $wpdb->prefix . MBS_TABLE, array( 'modification_token' => $token ), array( 'ref' => $booking->ref ) );
        }
        $base_url = home_url();
        $pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 's' => 'mathlin_status', 'numberposts' => 1 ) );
        if ( ! empty( $pages ) ) $base_url = get_permalink( $pages[0]->ID );
        return add_query_arg( array( 'mbs_modify' => '1', 'ref' => $booking->ref, 'token' => $token ), $base_url );
    }

    public static function verify_token( $ref, $token ) {
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking || empty( $booking->modification_token ) ) return false;
        return hash_equals( $booking->modification_token, $token );
    }

    // ── CRUD ───────────────────────────────────────────────────────────────────

    public static function create_request( $data ) {
        global $wpdb;
        return $wpdb->insert( self::table(), array(
            'booking_ref'    => sanitize_text_field( $data['ref'] ),
            'request_type'   => sanitize_text_field( $data['type'] ), // 'modify' or 'cancel'
            'status'         => 'pending',
            'requested_data' => wp_json_encode( $data['changes'] ?? array() ),
            'notes'          => sanitize_textarea_field( $data['notes'] ?? '' ),
            'created_at'     => current_time( 'mysql' ),
        ) );
    }

    public static function get_pending() {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results(
            "SELECT r.*, b.name, b.email, b.space, b.booking_date, b.start_time, b.end_time, b.amount, b.status as booking_status
             FROM {$table} r
             LEFT JOIN {$wpdb->prefix}" . MBS_TABLE . " b ON r.booking_ref = b.ref
             WHERE r.status = 'pending'
             ORDER BY r.created_at DESC"
        );
    }

    public static function get_all_requests( $limit = 50 ) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, b.name, b.email, b.space, b.booking_date, b.amount, b.status as booking_status
             FROM {$table} r
             LEFT JOIN {$wpdb->prefix}" . MBS_TABLE . " b ON r.booking_ref = b.ref
             ORDER BY r.created_at DESC LIMIT %d",
            $limit
        ) );
    }

    public static function get_request( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d", $id ) );
    }

    public static function get_pending_count() {
        global $wpdb;
        $table = self::table();
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) return 0;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
    }

    public static function update_request_status( $id, $status, $admin_response = '' ) {
        global $wpdb;
        $wpdb->update( self::table(), array(
            'status'         => $status,
            'admin_response' => sanitize_textarea_field( $admin_response ),
            'resolved_at'    => current_time( 'mysql' ),
            'resolved_by'    => get_current_user_id(),
        ), array( 'id' => $id ) );
    }

    // ── Approve ────────────────────────────────────────────────────────────────

    public static function approve( $request_id ) {
        $request = self::get_request( $request_id );
        if ( ! $request || $request->status !== 'pending' ) return false;

        $booking = MBS_Bookings::get( $request->booking_ref );
        if ( ! $booking ) return false;

        if ( $request->request_type === 'cancel' ) {
            // Approve cancellation
            MBS_Bookings::update_status( $request->booking_ref, 'cancelled' );
            MBS_Email::notify_cancelled( $booking, 'Your cancellation request has been approved.' );
            MBS_Audit_Log::log( $request->booking_ref, 'cancelled', 'Cancellation request approved by admin' );
        } else {
            // Approve modification — apply the requested changes
            $changes = json_decode( $request->requested_data, true ) ?: array();
            if ( ! empty( $changes ) ) {
                global $wpdb;
                $table  = $wpdb->prefix . MBS_TABLE;
                $update = array();

                if ( ! empty( $changes['space'] ) )        $update['space']        = sanitize_text_field( $changes['space'] );
                if ( ! empty( $changes['date'] ) )         $update['booking_date'] = sanitize_text_field( $changes['date'] );
                if ( ! empty( $changes['date_end'] ) )     $update['booking_date_end'] = sanitize_text_field( $changes['date_end'] );
                if ( ! empty( $changes['start_time'] ) )   $update['start_time']   = sanitize_text_field( $changes['start_time'] );
                if ( ! empty( $changes['end_time'] ) )     $update['end_time']     = sanitize_text_field( $changes['end_time'] );
                if ( isset( $changes['kitchen'] ) )        $update['kitchen']      = (int) $changes['kitchen'];
                if ( isset( $changes['attendees'] ) )      $update['attendees']    = absint( $changes['attendees'] );
                if ( isset( $changes['booking_type'] ) ) {
                    $update['all_day'] = $changes['booking_type'] === 'fullday' ? 1 : 0;
                }

                if ( ! empty( $update ) ) {
                    // QA-004: Check for conflicts before applying changes
                    $check_space = $update['space'] ?? $booking->space;
                    $check_date  = $update['booking_date'] ?? $booking->booking_date;
                    $check_start = $update['start_time'] ?? $booking->start_time;
                    $check_end   = $update['end_time'] ?? $booking->end_time;
                    $check_allday = isset( $update['all_day'] ) ? (bool) $update['all_day'] : (bool) $booking->all_day;

                    if ( $check_space !== $booking->space || $check_date !== $booking->booking_date ||
                         $check_start !== $booking->start_time || $check_end !== $booking->end_time ) {
                        $conflicts = MBS_Bookings::check_conflicts(
                            $check_space, $check_date,
                            $check_allday ? null : $check_start,
                            $check_allday ? null : $check_end,
                            $check_allday, $request->booking_ref
                        );
                        if ( ! empty( $conflicts ) ) {
                            self::update_request_status( $request_id, 'rejected', 'Conflicts with existing booking: ' . MBS_Bookings::format_conflict_message( $conflicts ) );
                            self::notify_booker_rejected( $booking, 'modify', 'Your requested changes conflict with an existing booking.' );
                            MBS_Audit_Log::log( $request->booking_ref, 'modification_rejected', 'Auto-rejected: conflicts with existing booking' );
                            return false;
                        }
                    }

                    // Recalculate cost
                    $space     = $update['space'] ?? $booking->space;
                    $start     = $update['start_time'] ?? $booking->start_time;
                    $end       = $update['end_time'] ?? $booking->end_time;
                    $kitchen   = isset( $update['kitchen'] ) ? $update['kitchen'] : $booking->kitchen;
                    $all_day   = isset( $update['all_day'] ) ? $update['all_day'] : $booking->all_day;
                    $date_from = $update['booking_date'] ?? $booking->booking_date;
                    $date_to   = $update['booking_date_end'] ?? $booking->booking_date_end ?? $date_from;
                    $num_days  = max( 1, (int) round( ( strtotime( $date_to ) - strtotime( $date_from ) ) / 86400 ) + 1 );

                    $new_amount = MBS_Bookings::calculate_cost( $space, $start, $end, (bool) $kitchen, (bool) $all_day, $num_days, (bool) $booking->scout_use );
                    $update['amount'] = $new_amount;

                    // Auto-confirm: determine correct status based on payment
                    // If previously paid and new cost <= old cost, keep as paid
                    // If new cost > what was paid, set to confirmed (payment outstanding)
                    $old_amount = (float) $booking->amount;
                    $was_paid   = ( $booking->status === 'paid' );

                    if ( $was_paid && (float) $new_amount <= $old_amount ) {
                        $update['status'] = 'paid';
                    } else {
                        $update['status'] = 'confirmed';
                    }

                    $wpdb->update( $table, $update, array( 'ref' => $request->booking_ref ) );

                    MBS_Audit_Log::log( $request->booking_ref, 'status_changed',
                        sprintf( 'Modification approved: status set to %s (was %s, cost %s → %s)',
                            $update['status'], $booking->status,
                            '£' . number_format( $old_amount, 2 ),
                            '£' . number_format( (float) $new_amount, 2 )
                        )
                    );
                }
            }

            // Notify booker (with pay button if additional amount due)
            $updated_booking = MBS_Bookings::get( $request->booking_ref );
            self::notify_booker_approved( $updated_booking, $booking->amount );
            MBS_Audit_Log::log( $request->booking_ref, 'edited', 'Modification request approved and applied by admin' );
        }

        self::update_request_status( $request_id, 'approved' );
        return true;
    }

    // ── Reject ─────────────────────────────────────────────────────────────────

    public static function reject( $request_id, $reason = '' ) {
        $request = self::get_request( $request_id );
        if ( ! $request || $request->status !== 'pending' ) return false;

        $booking = MBS_Bookings::get( $request->booking_ref );
        if ( ! $booking ) return false;

        self::update_request_status( $request_id, 'rejected', $reason );
        self::notify_booker_rejected( $booking, $request->request_type, $reason );
        MBS_Audit_Log::log( $request->booking_ref, 'modification_rejected', 'Request rejected by admin' . ( $reason ? ': ' . $reason : '' ) );
        return true;
    }

    // ── Public form submission ──────────────────────────────────────────────────

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

        $mod_action = sanitize_text_field( $_POST['mod_action'] ?? 'modify' );

        if ( $mod_action === 'cancel' ) {
            self::create_request( array(
                'ref'     => $ref,
                'type'    => 'cancel',
                'notes'   => sanitize_textarea_field( $_POST['cancel_reason'] ?? '' ),
                'changes' => array(),
            ) );
            MBS_Audit_Log::log( $ref, 'cancellation_requested', 'Booker requested cancellation', 0 );
        } else {
            $changes = array(
                'space'        => sanitize_text_field( $_POST['new_space'] ?? '' ),
                'date'         => sanitize_text_field( $_POST['new_date'] ?? '' ),
                'date_end'     => sanitize_text_field( $_POST['new_date_end'] ?? '' ),
                'start_time'   => sanitize_text_field( $_POST['new_start_time'] ?? '' ),
                'end_time'     => sanitize_text_field( $_POST['new_end_time'] ?? '' ),
                'booking_type' => sanitize_text_field( $_POST['new_booking_type'] ?? '' ),
                'kitchen'      => sanitize_text_field( $_POST['new_kitchen'] ?? '' ),
                'attendees'    => sanitize_text_field( $_POST['new_attendees'] ?? '' ),
            );
            self::create_request( array(
                'ref'     => $ref,
                'type'    => 'modify',
                'notes'   => sanitize_textarea_field( $_POST['changes'] ?? '' ),
                'changes' => $changes,
            ) );
            MBS_Audit_Log::log( $ref, 'modification_requested', 'Booker requested changes', 0 );
        }

        // Notify admin
        self::notify_admin_of_request( $booking, $mod_action );

        $msg = $mod_action === 'cancel'
            ? 'Your cancellation request has been submitted. We\'ll review it and get back to you shortly.'
            : 'Your modification request has been submitted. We\'ll review it and get back to you shortly.';

        wp_send_json_success( array( 'message' => $msg ) );
    }

    // ── Emails ─────────────────────────────────────────────────────────────────

    private static function notify_admin_of_request( $booking, $type ) {
        $tpl       = MBS_Email_Templates::get_template( 'admin_mod_request' );
        $subject   = MBS_Email_Templates::replace_placeholders( $tpl['subject'], $booking );
        $body_text = MBS_Email_Templates::replace_placeholders( $tpl['body'], $booking );

        $admin_email = MBS_Bookings::get_admin_email();
        $org         = MBS_Email_Templates::get_org_settings();
        $logo        = MBS_Email_Templates::get_logo_html();
        $pending     = self::get_pending_count();

        $body  = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:0 auto;">';
        $body .= '<div style="background:#f39c12;padding:24px 32px;border-radius:8px 8px 0 0;text-align:center;">' . $logo;
        $body .= '<h1 style="color:#fff;margin:8px 0 0;font-size:20px;">' . esc_html( $org['name'] ) . '</h1></div>';
        $body .= '<div style="background:#fff;padding:32px;border:1px solid #e0d0f0;border-top:none;border-radius:0 0 8px 8px;">';
        $body .= '<h2 style="color:#f39c12;">Change Request</h2>';
        $body .= nl2br( esc_html( $body_text ) );
        $body .= '<p style="margin-top:12px;">You have <strong>' . $pending . ' pending request(s)</strong>.</p>';
        $body .= '<p style="margin-top:24px;"><a href="' . admin_url( 'admin.php?page=mathlin-requests' ) . '" style="background:#7413DC;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;">Review Requests</a></p>';
        $body .= '</div></body></html>';

        MBS_Email_Queue::send( $admin_email, $subject, $body, array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $org['name'] . ' <' . get_option( 'admin_email', $admin_email ) . '>',
            'Reply-To: ' . $admin_email,
        ) );
    }

    private static function notify_booker_approved( $booking, $old_amount ) {
        $tpl       = MBS_Email_Templates::get_template( 'modification_approved' );
        $subject   = MBS_Email_Templates::replace_placeholders( $tpl['subject'], $booking );
        $body_text = MBS_Email_Templates::replace_placeholders( $tpl['body'], $booking );

        $org         = MBS_Email_Templates::get_org_settings();
        $admin_email = MBS_Bookings::get_admin_email();
        $logo        = MBS_Email_Templates::get_logo_html();
        $new_amount  = (float) $booking->amount;
        $bank        = MBS_Bookings::get_bank_details();

        $body  = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:0 auto;">';
        $body .= '<div style="background:#2ecc71;padding:24px 32px;border-radius:8px 8px 0 0;text-align:center;">' . $logo;
        $body .= '<h1 style="color:#fff;margin:8px 0 0;font-size:20px;">' . esc_html( $org['name'] ) . '</h1></div>';
        $body .= '<div style="background:#fff;padding:32px;border:1px solid #e0d0f0;border-top:none;border-radius:0 0 8px 8px;">';
        $body .= '<h2 style="color:#2ecc71;">Change Approved</h2>';
        $body .= nl2br( esc_html( $body_text ) );

        // Updated invoice number for reference
        $body .= '<p style="margin-top:12px;"><strong>Invoice Number:</strong> ' . esc_html( $booking->invoice_number ) . '</p>';

        $diff = $new_amount - (float) $old_amount;
        if ( abs( $diff ) > 0.01 ) {
            if ( $diff > 0 ) {
                $body .= '<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:12px;margin:12px 0;color:#991b1b;"><strong>Additional amount due: &pound;' . number_format( $diff, 2 ) . '</strong></div>';

                // BACS payment details
                if ( ! empty( $bank['sort_code'] ) && ! empty( $bank['account_number'] ) ) {
                    $body .= '<div style="background:#f5f0ff;border:1px solid #e0d0f0;border-radius:6px;padding:12px;margin:12px 0;">';
                    $body .= '<strong>Payment details:</strong><br>';
                    $body .= 'Sort Code: ' . esc_html( $bank['sort_code'] ) . '<br>';
                    $body .= 'Account: ' . esc_html( $bank['account_number'] ) . '<br>';
                    $body .= 'Reference: ' . esc_html( $booking->invoice_number );
                    $body .= '</div>';
                }

                // Add Pay Now button if WooCommerce is available
                if ( MBS_Woo_Payment::is_available() ) {
                    $pay_url = MBS_Woo_Payment::generate_payment_url( $booking );
                    if ( $pay_url ) {
                        $body .= '<p style="text-align:center;margin:24px 0;">';
                        $body .= '<a href="' . esc_url( $pay_url ) . '" style="background:#2ecc71;color:#fff;padding:14px 32px;border-radius:6px;text-decoration:none;font-weight:bold;font-size:16px;display:inline-block;">💳 Pay Now Online</a>';
                        $body .= '</p>';
                        $body .= '<p style="text-align:center;font-size:13px;color:#666;">Or pay by bank transfer using the details above.</p>';
                    }
                }
            } else {
                $body .= '<div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:6px;padding:12px;margin:12px 0;color:#065f46;"><strong>Credit of &pound;' . number_format( abs( $diff ), 2 ) . ' — we will arrange a refund.</strong></div>';
            }
        }

        $body .= '</div></body></html>';

        // Generate updated invoice attachment (reflects new amount)
        $attachments = MBS_Email::generate_invoice_attachment_for( $booking );

        MBS_Email_Queue::send( $booking->email, $subject, $body, array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $org['name'] . ' <' . get_option( 'admin_email', $admin_email ) . '>',
            'Reply-To: ' . $admin_email,
        ), $attachments );
    }

    private static function notify_booker_rejected( $booking, $type, $reason ) {
        $tpl       = MBS_Email_Templates::get_template( 'modification_rejected' );
        $extra     = array( '{reason}' => $reason ? 'Reason: ' . $reason : '' );
        $subject   = MBS_Email_Templates::replace_placeholders( $tpl['subject'], $booking, $extra );
        $body_text = MBS_Email_Templates::replace_placeholders( $tpl['body'], $booking, $extra );

        $org         = MBS_Email_Templates::get_org_settings();
        $admin_email = MBS_Bookings::get_admin_email();
        $logo        = MBS_Email_Templates::get_logo_html();

        $body  = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:0 auto;">';
        $body .= '<div style="background:#e74c3c;padding:24px 32px;border-radius:8px 8px 0 0;text-align:center;">' . $logo;
        $body .= '<h1 style="color:#fff;margin:8px 0 0;font-size:20px;">' . esc_html( $org['name'] ) . '</h1></div>';
        $body .= '<div style="background:#fff;padding:32px;border:1px solid #e0d0f0;border-top:none;border-radius:0 0 8px 8px;">';
        $body .= '<h2 style="color:#e74c3c;">Request Declined</h2>';
        $body .= nl2br( esc_html( $body_text ) );
        $body .= '</div></body></html>';

        MBS_Email_Queue::send( $booking->email, $subject, $body, array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $org['name'] . ' <' . get_option( 'admin_email', $admin_email ) . '>',
            'Reply-To: ' . $admin_email,
        ) );
    }
}
