<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MBS_Admin {

    public function init() {
        add_action( 'admin_menu',            array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_mbs_update_status',  array( $this, 'ajax_update_status' ) );
        add_action( 'wp_ajax_mbs_mark_refunded',  array( $this, 'ajax_mark_refunded' ) );
        add_action( 'wp_ajax_mbs_mark_deposit_paid', array( $this, 'ajax_mark_deposit_paid' ) );
        add_action( 'wp_ajax_mbs_undo_deposit',  array( $this, 'ajax_undo_deposit' ) );
        add_action( 'wp_ajax_mbs_restore_booking', array( $this, 'ajax_restore_booking' ) );
        add_action( 'wp_ajax_mbs_resend_access',   array( $this, 'ajax_resend_access' ) );
        add_action( 'wp_ajax_mbs_create_scout_recurring', array( $this, 'ajax_create_scout_recurring' ) );
        add_action( 'wp_ajax_mbs_delete_booking', array( $this, 'ajax_delete_booking' ) );
        add_action( 'wp_ajax_mbs_get_invoice',    array( $this, 'ajax_get_invoice' ) );
        add_action( 'wp_ajax_mbs_save_settings',  array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_mbs_test_ha',        array( $this, 'ajax_test_ha' ) );
        add_action( 'wp_ajax_mbs_check_update',   array( $this, 'ajax_check_update' ) );
        add_action( 'wp_ajax_mbs_archive_past',   array( $this, 'ajax_archive_past' ) );
        add_action( 'wp_ajax_mbs_add_blocked',    array( $this, 'ajax_add_blocked' ) );
        add_action( 'wp_ajax_mbs_delete_blocked', array( $this, 'ajax_delete_blocked' ) );
        add_action( 'wp_ajax_mbs_clear_expired_blocks', array( $this, 'ajax_clear_expired_blocks' ) );
        add_action( 'wp_ajax_mbs_update_series_status', array( $this, 'ajax_update_series_status' ) );
        add_action( 'wp_ajax_mbs_cancel_scout_series', array( $this, 'ajax_cancel_scout_series' ) );
        add_action( 'wp_ajax_mbs_edit_scout_series', array( $this, 'ajax_edit_scout_series' ) );
        add_action( 'wp_ajax_mbs_extend_scout_series', array( $this, 'ajax_extend_scout_series' ) );
        add_action( 'wp_ajax_mbs_reopen_scout_series', array( $this, 'ajax_reopen_scout_series' ) );
        add_action( 'wp_ajax_mbs_delete_scout_series', array( $this, 'ajax_delete_scout_series' ) );
        add_action( 'wp_ajax_mbs_save_admin_notes', array( $this, 'ajax_save_admin_notes' ) );
        add_action( 'wp_ajax_mbs_chase_payment',  array( $this, 'ajax_chase_payment' ) );
        add_action( 'wp_ajax_mbs_save_email_settings', array( $this, 'ajax_save_email_settings' ) );
        add_action( 'wp_ajax_mbs_save_custom_fields', array( $this, 'ajax_save_custom_fields' ) );
        add_action( 'wp_ajax_mbs_edit_booking',      array( $this, 'ajax_edit_booking' ) );
        add_action( 'wp_ajax_mbs_approve_request',  array( $this, 'ajax_approve_request' ) );
        add_action( 'wp_ajax_mbs_reject_request',   array( $this, 'ajax_reject_request' ) );
        add_action( 'wp_ajax_mbs_bulk_action',       array( $this, 'ajax_bulk_action' ) );
    }

    // ── Menu ───────────────────────────────────────────────────────────────────
    public function add_menu() {
        // Booking management pages — accessible to Booking Managers + Admins
        $booking_cap = 'mbs_manage_bookings';
        // Settings/config pages — admin only
        $admin_cap   = 'manage_options';

        // Pending booking count for notification badges
        $pending_bookings = MBS_Bookings::get_pending_count();
        $bookings_label = 'All Bookings';
        if ( $pending_bookings > 0 ) {
            $bookings_label .= ' <span class="awaiting-mod count-' . $pending_bookings . '"><span class="pending-count">' . $pending_bookings . '</span></span>';
        }

        // Parent menu — show pending bookings count in the top-level menu item
        $menu_label = 'Scout Bookings';
        if ( $pending_bookings > 0 ) {
            $menu_label .= ' <span class="update-plugins count-' . $pending_bookings . '"><span class="plugin-count">' . $pending_bookings . '</span></span>';
        }

        add_menu_page(
            'Scout Bookings',
            $menu_label,
            $booking_cap,
            'mathlin-booking',
            array( $this, 'render_dashboard' ),
            'dashicons-calendar-alt',
            30
        );
        add_submenu_page( 'mathlin-booking', 'All Bookings', $bookings_label, $booking_cap, 'mathlin-booking', array( $this, 'render_dashboard' ) );
        add_submenu_page( 'mathlin-booking', 'Scout Nights', 'Scout Nights', $booking_cap, 'mathlin-scout-nights', array( $this, 'render_scout_nights' ) );
        add_submenu_page( 'mathlin-booking', 'Calendar', 'Calendar', $booking_cap, 'mathlin-calendar', array( $this, 'render_calendar' ) );
        add_submenu_page( 'mathlin-booking', 'Archived', 'Archived', $booking_cap, 'mathlin-archived', array( $this, 'render_archived' ) );
        add_submenu_page( 'mathlin-booking', 'Blocked Dates', 'Blocked Dates', $booking_cap, 'mathlin-blocked', array( $this, 'render_blocked' ) );
        // Settings pages — admin only
        add_submenu_page( 'mathlin-booking', 'Settings', 'Settings', $admin_cap, 'mathlin-settings', array( $this, 'render_settings' ) );
        add_submenu_page( 'mathlin-booking', 'Email Templates', 'Email Templates', $admin_cap, 'mathlin-emails', array( $this, 'render_email_templates' ) );
        add_submenu_page( 'mathlin-booking', 'Custom Fields', 'Custom Fields', $admin_cap, 'mathlin-custom-fields', array( $this, 'render_custom_fields' ) );
        add_submenu_page( 'mathlin-booking', 'OSM Integration', 'OSM Integration', $admin_cap, 'mathlin-osm', array( $this, 'render_osm_settings' ) );

        // Booking management pages — accessible to Booking Managers
        add_submenu_page( 'mathlin-booking', 'Analytics', 'Analytics', $booking_cap, 'mathlin-analytics', array( $this, 'render_analytics' ) );

        $pending_count = MBS_Modification::get_pending_count();
        $requests_label = 'Requests';
        if ( $pending_count > 0 ) {
            $requests_label .= ' <span class="awaiting-mod count-' . $pending_count . '"><span class="pending-count">' . $pending_count . '</span></span>';
        }
        add_submenu_page( 'mathlin-booking', 'Change Requests', $requests_label, $booking_cap, 'mathlin-requests', array( $this, 'render_requests' ) );
        add_submenu_page( 'mathlin-booking', 'Audit Log', 'Audit Log', $booking_cap, 'mathlin-audit-log', array( $this, 'render_audit_log' ) );
    }

    // ── Assets ─────────────────────────────────────────────────────────────────
    /**
     * Check if current user can manage bookings (admin or booking manager).
     */
    private static function can_manage_bookings() {
        return current_user_can( 'manage_options' ) || current_user_can( 'mbs_manage_bookings' );
    }

    /**
     * Check if current user can delete bookings (admin only).
     */
    private static function can_delete_bookings() {
        return current_user_can( 'manage_options' );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'mathlin' ) === false ) return;
        wp_enqueue_style(  'mbs-admin', MBS_PLUGIN_URL . 'admin/admin.css', array(), MBS_VERSION );
        wp_enqueue_script( 'mbs-admin', MBS_PLUGIN_URL . 'admin/admin.js',  array( 'jquery' ), MBS_VERSION, true );
        wp_localize_script( 'mbs-admin', 'MBS_Admin', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'mbs_admin_nonce' ),
        ) );
        // Enqueue media library for logo upload
        if ( strpos( $hook, 'mathlin-emails' ) !== false ) {
            wp_enqueue_media();
        }
    }

    // ── Dashboard ──────────────────────────────────────────────────────────────
    public function render_dashboard() {
        $action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'list';
        $ref    = isset( $_GET['ref'] )    ? sanitize_text_field( $_GET['ref'] )    : '';

        if ( $action === 'view' && $ref ) {
            $this->render_single( $ref );
            return;
        }
        if ( $action === 'invoice' && $ref ) {
            $this->render_invoice_page( $ref );
            return;
        }
        $this->render_list();
    }

    private function render_list() {
        $stats    = MBS_Bookings::get_stats( true ); // exclude internal Scout bookings from the counters
        $status   = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
        $search   = isset( $_GET['s'] )      ? sanitize_text_field( $_GET['s'] )      : '';
        $bookings = MBS_Bookings::get_all( array( 'status' => $status, 'search' => $search, 'orderby' => 'booking_date', 'order' => 'ASC', 'exclude_scout' => true ) );
        include MBS_PLUGIN_DIR . 'admin/views/list.php';
    }

    private function render_single( $ref ) {
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) {
            echo '<div class="notice notice-error"><p>Booking not found.</p></div>';
            return;
        }
        include MBS_PLUGIN_DIR . 'admin/views/single.php';
    }

    private function render_invoice_page( $ref ) {
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) {
            echo '<div class="notice notice-error"><p>Booking not found.</p></div>';
            return;
        }
        include MBS_PLUGIN_DIR . 'admin/views/invoice.php';
    }

    public function render_calendar() {
        include MBS_PLUGIN_DIR . 'admin/views/calendar.php';
    }

    public function render_settings() {
        include MBS_PLUGIN_DIR . 'admin/views/settings.php';
    }

    public function render_archived() {
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $bookings = MBS_Bookings::get_all( array( 'status' => 'archived', 'search' => $search, 'exclude_archived' => false, 'orderby' => 'booking_date', 'order' => 'DESC' ) );
        include MBS_PLUGIN_DIR . 'admin/views/archived.php';
    }

    public function render_scout_nights() {
        include MBS_PLUGIN_DIR . 'admin/views/scout-nights.php';
    }

    // ── AJAX handlers ──────────────────────────────────────────────────────────
    public function ajax_update_status() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $ref    = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $status = sanitize_text_field( $_POST['status'] ?? '' );
        $reason = sanitize_textarea_field( $_POST['reason'] ?? '' );

        $result = MBS_Bookings::update_status( $ref, $status );

        if ( $status === 'confirmed' ) {
            $booking = MBS_Bookings::get( $ref );
            if ( $booking ) MBS_Email::notify_confirmed( $booking );
        }

        if ( $status === 'cancelled' ) {
            $booking = MBS_Bookings::get( $ref );
            if ( $booking ) MBS_Email::notify_cancelled( $booking, $reason );
        }

        if ( $status === 'paid' ) {
            $booking = MBS_Bookings::get( $ref );
            if ( $booking ) {
                // Set amount_paid = amount when marking as fully paid
                global $wpdb;
                $table = $wpdb->prefix . MBS_TABLE;
                $wpdb->update( $table, array( 'amount_paid' => $booking->amount ), array( 'ref' => $ref ) );
                MBS_Email::notify_paid( $booking );
            }
        }

        wp_send_json_success( array( 'ref' => $ref, 'status' => $status ) );
    }

    public function ajax_delete_booking() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        // Hard delete restricted to administrators only
        if ( ! self::can_delete_bookings() ) wp_send_json_error( 'Only administrators can permanently delete bookings.', 403 );

        $ref    = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $result = MBS_Bookings::delete( $ref );
        wp_send_json_success( array( 'deleted' => $ref ) );
    }

    /**
     * Mark a refund/credit as processed after a modification reduced the cost.
     * Sets status to 'paid' without sending a payment confirmation email.
     */
    public function ajax_mark_refunded() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $ref = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) wp_send_json_error( 'Booking not found.' );

        // Set amount_paid = amount to balance the books (refund has been processed)
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        $wpdb->update( $table, array( 'amount_paid' => (float) $booking->amount ), array( 'ref' => $ref ) );

        MBS_Bookings::update_status( $ref, 'paid' );
        MBS_Audit_Log::log( $ref, 'refund_processed', 'Admin marked refund of £' . number_format( (float) $booking->amount_paid - (float) $booking->amount, 2 ) . ' as processed. Books balanced.' );

        wp_send_json_success( array( 'ref' => $ref, 'status' => 'paid' ) );
    }

    /**
     * Mark a booking's deposit as received (manual bank transfer).
     * Sets status to deposit_paid and records the deposit amount.
     */
    public function ajax_mark_deposit_paid() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $ref = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) wp_send_json_error( 'Booking not found.' );
        if ( $booking->status !== 'confirmed' ) wp_send_json_error( 'Booking must be in Confirmed status to mark deposit paid.' );

        $deposit_amount = MBS_Bookings::calculate_deposit( (float) $booking->amount );

        MBS_Bookings::update_status( $ref, 'deposit_paid' );

        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        $wpdb->update( $table, array( 'deposit_paid' => $deposit_amount, 'amount_paid' => $deposit_amount ), array( 'ref' => $ref ) );

        // Send deposit received confirmation email to booker
        $updated_booking = MBS_Bookings::get( $ref );
        if ( $updated_booking ) {
            MBS_Email::notify_deposit_received( $updated_booking, $deposit_amount );
        }

        MBS_Audit_Log::log( $ref, 'deposit_paid', 'Admin marked deposit of £' . number_format( $deposit_amount, 2 ) . ' as received (bank transfer). Balance of £' . number_format( (float) $booking->amount - $deposit_amount, 2 ) . ' outstanding.' );

        wp_send_json_success( array( 'ref' => $ref, 'status' => 'deposit_paid', 'deposit' => $deposit_amount ) );
    }

    /**
     * Undo deposit paid — revert to confirmed and clear deposit_paid amount.
     */
    public function ajax_undo_deposit() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $ref = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) wp_send_json_error( 'Booking not found.' );
        if ( $booking->status !== 'deposit_paid' ) wp_send_json_error( 'Booking is not in Deposit Paid status.' );

        MBS_Bookings::update_status( $ref, 'confirmed' );

        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        // H-3: Clear both deposit_paid AND amount_paid, otherwise a later online
        // payment would treat the (cleared) deposit as still paid and only charge the balance.
        $wpdb->update( $table, array( 'deposit_paid' => 0, 'amount_paid' => 0 ), array( 'ref' => $ref ) );

        MBS_Audit_Log::log( $ref, 'status_changed', 'Admin reverted Deposit Paid to Confirmed. Deposit and amount-paid records cleared.' );

        wp_send_json_success( array( 'ref' => $ref, 'status' => 'confirmed' ) );
    }

    /**
     * Restore an archived/cancelled booking to a given status WITHOUT sending emails.
     */
    public function ajax_restore_booking() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $ref    = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $status = sanitize_text_field( $_POST['status'] ?? 'confirmed' );
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) wp_send_json_error( 'Booking not found.' );

        // Only allow restoring from archived or cancelled
        if ( ! in_array( $booking->status, array( 'archived', 'cancelled' ) ) ) {
            wp_send_json_error( 'Booking must be archived or cancelled to restore.' );
        }

        // Only allow restoring to safe statuses
        $allowed = array( 'confirmed', 'deposit_paid', 'paid' );
        if ( ! in_array( $status, $allowed ) ) $status = 'confirmed';

        // Direct DB update — bypasses update_status() to avoid triggering emails/webhooks
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        $wpdb->update( $table, array( 'status' => $status ), array( 'ref' => $ref ) );

        MBS_Audit_Log::log( $ref, 'restored', 'Booking restored from ' . $booking->status . ' to ' . $status . ' (no notification sent).' );

        wp_send_json_success( array( 'ref' => $ref, 'status' => $status ) );
    }

    /**
     * Resend access details to a booker manually.
     */
    public function ajax_resend_access() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $ref = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) wp_send_json_error( 'Booking not found.' );

        if ( ! get_option( 'mbs_access_enabled', 0 ) || empty( get_option( 'mbs_access_code', '' ) ) ) {
            wp_send_json_error( 'Access details not configured. Set up the access code in Settings.' );
        }

        MBS_Access_Details::resend( $booking );

        // Mark as sent
        global $wpdb;
        $wpdb->update( $wpdb->prefix . MBS_TABLE, array( 'access_sent' => 1 ), array( 'ref' => $ref ) );

        wp_send_json_success( array( 'ref' => $ref ) );
    }

    /**
     * Create recurring scout night bookings from admin panel.
     */
    public function ajax_create_scout_recurring() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $space      = sanitize_text_field( $_POST['space'] ?? '' );
        $day_of_week = absint( $_POST['day_of_week'] ?? 1 ); // 1=Mon, 7=Sun
        $start_time = sanitize_text_field( $_POST['start_time'] ?? '' );
        $end_time   = sanitize_text_field( $_POST['end_time'] ?? '' );
        $purpose    = sanitize_text_field( $_POST['purpose'] ?? 'Scout Night' );
        $date_from  = sanitize_text_field( $_POST['date_from'] ?? '' );
        $date_to    = sanitize_text_field( $_POST['date_to'] ?? '' );

        if ( ! $space || ! $start_time || ! $end_time || ! $date_from || ! $date_to ) {
            wp_send_json_error( 'Please fill in all fields.' );
        }

        // Map day number to PHP day name for strtotime
        $days = array( 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday' );
        $day_name = $days[ $day_of_week ] ?? 'Monday';

        // Find the first occurrence of this day on or after date_from
        $current = strtotime( $date_from );
        $end     = strtotime( $date_to );
        $first_day = strtotime( "this {$day_name}", $current - 86400 );
        if ( $first_day < $current ) $first_day = strtotime( "next {$day_name}", $current );

        $series_id = MBS_Bookings::generate_series_id();
        $created   = 0;
        $skipped   = 0;

        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;

        for ( $d = $first_day; $d <= $end; $d = strtotime( '+1 week', $d ) ) {
            $date_str = wp_date( 'Y-m-d', $d );

            // M-1: Wrap conflict check + insert in a transaction with row locking to
            // prevent a TOCTOU race where a public booking lands between check and insert.
            $wpdb->query( 'START TRANSACTION' );
            $wpdb->query( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE space = %s AND booking_date = %s AND status NOT IN ('cancelled','archived') FOR UPDATE",
                $space, $date_str
            ) );

            // Check for conflicts (now with lock held)
            $conflicts = MBS_Bookings::check_conflicts( $space, $date_str, $start_time, $end_time, false );
            if ( ! empty( $conflicts ) ) {
                $wpdb->query( 'ROLLBACK' );
                $skipped++;
                continue;
            }

            // Check for blocked dates
            if ( MBS_Blocked_Dates::is_blocked( $date_str, $space ) ) {
                $wpdb->query( 'ROLLBACK' );
                $skipped++;
                continue;
            }

            $ref = MBS_Bookings::generate_ref();
            $wpdb->insert( $table, array(
                'ref'              => $ref,
                'status'           => 'confirmed',
                'name'             => $purpose,
                'organisation'     => get_option( 'mbs_org_name', '1st Needham Market Scout Group' ),
                'email'            => MBS_Bookings::get_admin_email(),
                'phone'            => '',
                'address'          => '',
                'space'            => $space,
                'kitchen'          => 0,
                'booking_date'     => $date_str,
                'booking_date_end' => $date_str,
                'all_day'          => 0,
                'scout_use'        => 1,
                'start_time'       => $start_time,
                'end_time'         => $end_time,
                'attendees'        => 0,
                'purpose'          => $purpose,
                'amount'           => 0,
                'amount_paid'      => 0,
                'invoice_number'   => '',
                'series_id'        => $series_id,
                'modification_token' => wp_generate_password( 32, false ),
            ) );
            $wpdb->query( 'COMMIT' );
            $created++;
        }

        if ( $created > 0 ) {
            MBS_Audit_Log::log( $series_id, 'created', "Scout recurring: {$created} x {$purpose} ({$day_name}s, {$start_time}–{$end_time}) in {$space}. Skipped: {$skipped}" );
        }

        wp_send_json_success( array(
            'created'   => $created,
            'skipped'   => $skipped,
            'series_id' => $series_id,
        ) );
    }

    public function ajax_get_invoice() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $ref     = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) wp_send_json_error( 'Not found' );

        wp_send_json_success( array( 'html' => MBS_Invoice::generate_html( $booking ) ) );
    }

    public function ajax_save_settings() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $webhook      = esc_url_raw( $_POST['ha_webhook_url'] ?? '' );
        $notice_days  = absint( $_POST['min_notice_days'] ?? 1 );
        $github_token = sanitize_text_field( $_POST['github_token'] ?? '' );
        $admin_email  = sanitize_email( $_POST['admin_email'] ?? '' );
        $kitchen_price = floatval( $_POST['kitchen_price'] ?? 10 );

        $bank_sort_code     = sanitize_text_field( $_POST['bank_sort_code'] ?? '' );
        $bank_account_number = sanitize_text_field( $_POST['bank_account_number'] ?? '' );
        $bank_account_name  = sanitize_text_field( $_POST['bank_account_name'] ?? '' );
        $payment_terms_days = absint( $_POST['payment_terms_days'] ?? 14 );
        $payment_terms_days = max( 1, min( 90, $payment_terms_days ) );

        // Clamp to a sensible range: 0 = same day allowed, 30 = max notice required
        $notice_days = max( 0, min( 30, $notice_days ) );

        // Clamp kitchen price
        $kitchen_price = max( 0, $kitchen_price );

        update_option( 'mbs_ha_webhook_url',  $webhook );
        update_option( 'mbs_min_notice_days', $notice_days );
        update_option( 'mbs_kitchen_price',   $kitchen_price );
        update_option( 'mbs_kitchen_enabled', absint( $_POST['kitchen_enabled'] ?? 1 ) );

        // Reminder hours
        $reminder_hours = absint( $_POST['reminder_hours'] ?? 24 );
        $reminder_hours = max( 0, min( 168, $reminder_hours ) );
        update_option( 'mbs_reminder_hours', $reminder_hours );

        // Terms & Conditions page
        $terms_page_id = absint( $_POST['terms_page_id'] ?? 0 );
        update_option( 'mbs_terms_page_id', $terms_page_id );

        // Auto-archive days
        $auto_archive_days = absint( $_POST['auto_archive_days'] ?? 7 );
        update_option( 'mbs_auto_archive_days', $auto_archive_days );

        // Additional notification emails
        $additional_emails = sanitize_text_field( $_POST['additional_emails'] ?? '' );
        update_option( 'mbs_additional_emails', $additional_emails );

        // Auto-chase
        $auto_chase = absint( $_POST['auto_chase_enabled'] ?? 1 );
        update_option( 'mbs_auto_chase_enabled', $auto_chase );

        // Scout volunteer emails
        $scout_emails = sanitize_textarea_field( $_POST['scout_volunteer_emails'] ?? '' );
        update_option( 'mbs_scout_volunteer_emails', $scout_emails );

        // Update user meta for scout volunteers
        $email_list = array_filter( array_map( 'trim', explode( "\n", $scout_emails ) ) );
        // Clear existing flags
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'mbs_scout_volunteer'" );
        // Set flags for listed emails
        foreach ( $email_list as $vol_email ) {
            $user = get_user_by( 'email', $vol_email );
            if ( $user ) {
                update_user_meta( $user->ID, 'mbs_scout_volunteer', 1 );
            }
        }

        // Save admin email if provided
        if ( ! empty( $admin_email ) ) {
            update_option( 'mbs_admin_email', $admin_email );
        }

        // Only update the token if a value was provided (don't blank it if the field wasn't sent)
        if ( ! empty( $github_token ) ) {
            update_option( 'mbs_github_token', $github_token );
        }

        if ( ! empty( $bank_sort_code ) ) update_option( 'mbs_bank_sort_code', $bank_sort_code );
        if ( ! empty( $bank_account_number ) ) update_option( 'mbs_bank_account_number', $bank_account_number );
        if ( ! empty( $bank_account_name ) ) update_option( 'mbs_bank_account_name', $bank_account_name );
        update_option( 'mbs_payment_terms_days', $payment_terms_days );

        // Save spaces if provided
        if ( isset( $_POST['spaces'] ) && is_array( $_POST['spaces'] ) ) {
            $spaces = array();
            foreach ( $_POST['spaces'] as $space_data ) {
                $name = sanitize_text_field( $space_data['name'] ?? '' );
                if ( empty( $name ) ) continue;

                $rate_hourly = floatval( $space_data['rate_hourly'] ?? 0 );
                $rate_daily  = floatval( $space_data['rate_daily'] ?? 0 );
                $capacity    = absint( $space_data['capacity'] ?? 0 );
                $parent      = sanitize_text_field( $space_data['parent'] ?? '' );

                $spaces[ $name ] = array(
                    'rate_hourly' => max( 0, $rate_hourly ),
                    'rate_daily'  => max( 0, $rate_daily ),
                    'capacity'    => $capacity > 0 ? $capacity : null,
                    'parent'      => $parent ?: null,
                );
            }
            if ( ! empty( $spaces ) ) {
                MBS_Bookings::save_spaces( $spaces );
            }
        }

        // Deposit settings
        update_option( 'mbs_deposit_enabled', absint( $_POST['deposit_enabled'] ?? 0 ) );
        $deposit_pct = floatval( $_POST['deposit_percentage'] ?? 25 );
        update_option( 'mbs_deposit_percentage', max( 1, min( 99, $deposit_pct ) ) );
        $deposit_days = absint( $_POST['deposit_balance_days'] ?? 7 );
        update_option( 'mbs_deposit_balance_days', max( 1, min( 90, $deposit_days ) ) );

        // Access details settings
        update_option( 'mbs_access_enabled', absint( $_POST['access_enabled'] ?? 0 ) );
        if ( isset( $_POST['access_code'] ) ) {
            update_option( 'mbs_access_code', sanitize_text_field( $_POST['access_code'] ) );
        }
        if ( isset( $_POST['access_instructions'] ) ) {
            update_option( 'mbs_access_instructions', sanitize_textarea_field( $_POST['access_instructions'] ) );
        }
        if ( isset( $_POST['access_health_safety'] ) ) {
            update_option( 'mbs_access_health_safety', sanitize_textarea_field( $_POST['access_health_safety'] ) );
        }
        $access_hours = absint( $_POST['access_hours_before'] ?? 24 );
        update_option( 'mbs_access_hours_before', max( 1, min( 168, $access_hours ) ) );

        // Pricing tiers
        if ( isset( $_POST['pricing_tiers'] ) && is_array( $_POST['pricing_tiers'] ) ) {
            $tiers = array();
            foreach ( $_POST['pricing_tiers'] as $tier_data ) {
                $key        = sanitize_key( $tier_data['key'] ?? '' );
                $label      = sanitize_text_field( $tier_data['label'] ?? '' );
                $multiplier = floatval( $tier_data['multiplier'] ?? 1.0 );
                if ( empty( $key ) || empty( $label ) ) continue;
                $tiers[ $key ] = array(
                    'label'      => $label,
                    'multiplier' => max( 0, $multiplier ),
                    'bypass_access_gate' => ! empty( $tier_data['bypass_access_gate'] ),
                    'offline_invoicing'  => ! empty( $tier_data['offline_invoicing'] ),
                );
            }
            if ( ! empty( $tiers ) ) {
                update_option( 'mbs_pricing_tiers', $tiers );
            }
        }

        // Venue & Legal settings
        $venue_capacity = absint( $_POST['venue_capacity'] ?? 100 );
        update_option( 'mbs_venue_capacity', max( 1, $venue_capacity ) );
        update_option( 'mbs_curfew_saturday', sanitize_text_field( $_POST['curfew_saturday'] ?? '11:00 PM' ) );
        update_option( 'mbs_curfew_sunday', sanitize_text_field( $_POST['curfew_sunday'] ?? '10:00 PM' ) );
        $payment_days_required = absint( $_POST['payment_days_required'] ?? 28 );
        update_option( 'mbs_payment_days_required', max( 1, min( 90, $payment_days_required ) ) );
        if ( isset( $_POST['terms_text'] ) ) {
            update_option( 'mbs_terms_text', wp_kses_post( $_POST['terms_text'] ) );
        }
        if ( isset( $_POST['booking_notice'] ) ) {
            update_option( 'mbs_booking_notice', wp_kses_post( $_POST['booking_notice'] ) );
        }
        if ( isset( $_POST['facilities_text'] ) ) {
            update_option( 'mbs_facilities_text', wp_kses_post( $_POST['facilities_text'] ) );
        }
        if ( isset( $_POST['offline_payment_instructions'] ) ) {
            update_option( 'mbs_offline_payment_instructions', wp_kses_post( $_POST['offline_payment_instructions'] ) );
        }

        // Post-booking feedback & reviews settings
        update_option( 'mbs_feedback_enabled', absint( $_POST['feedback_enabled'] ?? 0 ) );
        if ( isset( $_POST['feedback_review_url'] ) ) {
            update_option( 'mbs_feedback_review_url', esc_url_raw( $_POST['feedback_review_url'] ) );
        }
        if ( isset( $_POST['feedback_distribution_email'] ) ) {
            $dist = sanitize_email( $_POST['feedback_distribution_email'] );
            update_option( 'mbs_feedback_distribution_email', is_email( $dist ) ? $dist : '' );
        }
        if ( isset( $_POST['feedback_subject'] ) ) {
            update_option( 'mbs_feedback_subject', sanitize_text_field( $_POST['feedback_subject'] ) );
        }
        if ( isset( $_POST['feedback_body'] ) ) {
            update_option( 'mbs_feedback_body', wp_kses_post( $_POST['feedback_body'] ) );
        }

        wp_send_json_success( array( 'saved' => true, 'min_notice_days' => $notice_days ) );
    }

    public function ajax_test_ha() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $webhook_url = get_option( 'mbs_ha_webhook_url', '' );
        if ( empty( $webhook_url ) ) {
            wp_send_json_error( 'No webhook URL configured.' );
        }

        $payload = array(
            'event'        => 'test',
            'message'      => 'Test from Needham Market Scout Group booking system',
            'timestamp'    => current_time( 'c' ),
        );

        $response = wp_remote_post( $webhook_url, array(
            'method'  => 'POST',
            'timeout' => 10,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        wp_send_json_success( array( 'http_code' => $code ) );
    }

    public function ajax_check_update() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        // Clear the update transient so WordPress re-checks immediately
        delete_site_transient( 'update_plugins' );
        wp_update_plugins();

        $update_plugins = get_site_transient( 'update_plugins' );
        $plugin_basename = plugin_basename( MBS_PLUGIN_DIR . 'mathlin-booking.php' );

        if ( isset( $update_plugins->response[ $plugin_basename ] ) ) {
            $update = $update_plugins->response[ $plugin_basename ];
            wp_send_json_success( array(
                'update_available' => true,
                'new_version'      => $update->new_version,
                'current_version'  => MBS_VERSION,
            ) );
        } else {
            wp_send_json_success( array(
                'update_available' => false,
                'current_version'  => MBS_VERSION,
                'message'          => 'You are running the latest version.',
            ) );
        }
    }

    public function ajax_archive_past() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $count = MBS_Bookings::archive_past_bookings();
        wp_send_json_success( array( 'archived' => $count ) );
    }

    public function render_blocked() {
        $blocked = MBS_Blocked_Dates::get_all();
        $spaces  = MBS_Bookings::get_spaces();
        include MBS_PLUGIN_DIR . 'admin/views/blocked.php';
    }

    public function render_email_templates() {
        include MBS_PLUGIN_DIR . 'admin/views/email-templates.php';
    }

    public function render_analytics() {
        include MBS_PLUGIN_DIR . 'admin/views/analytics.php';
    }

    public function render_custom_fields() {
        include MBS_PLUGIN_DIR . 'admin/views/custom-fields.php';
    }

    public function render_osm_settings() {
        include MBS_PLUGIN_DIR . 'admin/views/osm-settings.php';
    }

    public function render_requests() {
        include MBS_PLUGIN_DIR . 'admin/views/requests.php';
    }

    public function render_audit_log() {
        $limit   = isset( $_GET['limit'] ) ? max( 20, min( 1000, absint( $_GET['limit'] ) ) ) : 200;
        $search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $entries = $search !== ''
            ? MBS_Audit_Log::search( $search, $limit )
            : MBS_Audit_Log::get_recent( $limit );
        include MBS_PLUGIN_DIR . 'admin/views/audit-log.php';
    }

    public function ajax_add_blocked() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $date_from = sanitize_text_field( $_POST['date_from'] ?? '' );
        $date_to   = sanitize_text_field( $_POST['date_to'] ?? '' );
        $space     = sanitize_text_field( $_POST['space'] ?? '' );
        $reason    = sanitize_text_field( $_POST['reason'] ?? '' );

        if ( ! $date_from || ! $date_to ) {
            wp_send_json_error( 'Please provide both start and end dates.' );
        }
        if ( strtotime( $date_to ) < strtotime( $date_from ) ) {
            wp_send_json_error( 'End date must be on or after start date.' );
        }

        MBS_Blocked_Dates::add( $date_from, $date_to, $space, $reason );
        wp_send_json_success( array( 'message' => 'Dates blocked successfully.' ) );
    }

    public function ajax_delete_blocked() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'Invalid ID.' );

        MBS_Blocked_Dates::delete( $id );
        wp_send_json_success( array( 'deleted' => $id ) );
    }

    public function ajax_clear_expired_blocks() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $count = MBS_Blocked_Dates::clear_expired();
        wp_send_json_success( array( 'cleared' => $count ) );
    }

    public function ajax_update_series_status() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $series_id = sanitize_text_field( $_POST['series_id'] ?? '' );
        $status    = sanitize_text_field( $_POST['status'] ?? '' );

        if ( ! $series_id ) wp_send_json_error( 'No series ID provided.' );

        $result = MBS_Bookings::update_series_status( $series_id, $status );

        if ( $status === 'confirmed' ) {
            $bookings = MBS_Bookings::get_series( $series_id );
            foreach ( $bookings as $booking ) {
                if ( $booking->status === 'confirmed' ) {
                    MBS_Email::notify_confirmed( $booking );
                }
            }
        }

        $count = count( MBS_Bookings::get_series( $series_id ) );
        wp_send_json_success( array( 'series_id' => $series_id, 'status' => $status, 'count' => $count ) );
    }

    /**
     * Bulk-cancel all future bookings in a Scout Nights series.
     * Past bookings are preserved for the historical record.
     */
    public function ajax_cancel_scout_series() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $series_id = sanitize_text_field( $_POST['series_id'] ?? '' );
        if ( ! $series_id ) wp_send_json_error( 'No series ID provided.' );

        $cancelled = MBS_Bookings::cancel_series_future( $series_id );
        if ( $cancelled === false ) {
            wp_send_json_error( 'Database error cancelling the series.' );
        }

        wp_send_json_success( array(
            'series_id' => $series_id,
            'cancelled' => $cancelled,
            'message'   => $cancelled . ' future booking(s) cancelled in series ' . $series_id . '.',
        ) );
    }

    /**
     * Bulk-edit all future bookings in a Scout Nights series (time/space/section).
     * Past bookings are preserved; conflicting dates are skipped and reported.
     */
    public function ajax_edit_scout_series() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $series_id = sanitize_text_field( $_POST['series_id'] ?? '' );
        if ( ! $series_id ) wp_send_json_error( 'No series ID provided.' );

        // Only pass through fields the admin actually filled in, so blank
        // inputs leave the existing value untouched.
        $fields = array();
        if ( isset( $_POST['space'] ) && $_POST['space'] !== '' )      $fields['space']      = $_POST['space'];
        if ( isset( $_POST['start_time'] ) && $_POST['start_time'] !== '' ) $fields['start_time'] = $_POST['start_time'];
        if ( isset( $_POST['end_time'] ) && $_POST['end_time'] !== '' ) $fields['end_time']   = $_POST['end_time'];
        if ( isset( $_POST['purpose'] ) && $_POST['purpose'] !== '' )  $fields['purpose']    = $_POST['purpose'];

        if ( empty( $fields ) ) wp_send_json_error( 'No changes were provided.' );

        $result = MBS_Bookings::update_series_future( $series_id, $fields );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $msg = $result['updated'] . ' future booking(s) updated in series ' . $series_id . '.';
        if ( ! empty( $result['skipped'] ) ) {
            $msg .= ' ' . count( $result['skipped'] ) . ' date(s) skipped due to conflicts: ' . implode( ', ', $result['skipped'] ) . '.';
        }

        wp_send_json_success( array(
            'series_id' => $series_id,
            'updated'   => $result['updated'],
            'skipped'   => $result['skipped'],
            'message'   => $msg,
        ) );
    }

    /**
     * Extend a Scout Nights series with further weekly occurrences up to a new
     * end date. Continues the existing cadence; capped at 52 weeks per call.
     */
    public function ajax_extend_scout_series() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $series_id = sanitize_text_field( $_POST['series_id'] ?? '' );
        $new_end   = sanitize_text_field( $_POST['extend_until'] ?? '' );
        if ( ! $series_id ) wp_send_json_error( 'No series ID provided.' );
        if ( ! $new_end )   wp_send_json_error( 'Please choose a date to extend until.' );

        $result = MBS_Bookings::extend_series( $series_id, $new_end );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $msg = $result['created'] . ' booking(s) added to series ' . $series_id . '.';
        if ( ! empty( $result['skipped'] ) ) {
            $msg .= ' ' . count( $result['skipped'] ) . ' date(s) skipped due to conflicts: ' . implode( ', ', $result['skipped'] ) . '.';
        }
        if ( ! empty( $result['cap_reached'] ) ) {
            $msg .= ' The 52-week limit was reached — run Extend again to continue further.';
        }

        wp_send_json_success( array(
            'series_id'   => $series_id,
            'created'     => $result['created'],
            'skipped'     => $result['skipped'],
            'cap_reached' => ! empty( $result['cap_reached'] ),
            'message'     => $msg,
        ) );
    }

    /**
     * Reopen all future cancelled bookings in a Scout Nights series.
     */
    public function ajax_reopen_scout_series() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $series_id = sanitize_text_field( $_POST['series_id'] ?? '' );
        if ( ! $series_id ) wp_send_json_error( 'No series ID provided.' );

        $reopened = MBS_Bookings::reopen_series_future( $series_id );
        if ( $reopened === false ) {
            wp_send_json_error( 'Database error reopening the series.' );
        }

        wp_send_json_success( array(
            'series_id' => $series_id,
            'reopened'  => $reopened,
            'message'   => $reopened > 0
                ? $reopened . ' future booking(s) reopened in series ' . $series_id . '.'
                : 'No cancelled future bookings to reopen in series ' . $series_id . '.',
        ) );
    }

    /**
     * Permanently delete bookings in a Scout Nights series.
     * Administrator only, since it destroys the historical record.
     * Scope: 'all' (past + future) or 'future' (today onwards).
     */
    public function ajax_delete_scout_series() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_delete_bookings() ) wp_send_json_error( 'Only administrators can permanently delete a series.', 403 );

        $series_id = sanitize_text_field( $_POST['series_id'] ?? '' );
        if ( ! $series_id ) wp_send_json_error( 'No series ID provided.' );

        $scope = ( ( $_POST['scope'] ?? 'all' ) === 'future' ) ? 'future' : 'all';

        $deleted = MBS_Bookings::delete_series( $series_id, $scope );
        if ( $deleted === false ) {
            wp_send_json_error( 'Database error deleting the series.' );
        }

        $where = ( $scope === 'future' ) ? 'future ' : '';
        wp_send_json_success( array(
            'series_id' => $series_id,
            'scope'     => $scope,
            'deleted'   => $deleted,
            'message'   => $deleted . ' ' . $where . 'booking(s) permanently deleted from series ' . $series_id . '.',
        ) );
    }

    public function ajax_save_admin_notes() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $ref   = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $notes = sanitize_textarea_field( $_POST['admin_notes'] ?? '' );

        MBS_Bookings::update_admin_notes( $ref, $notes );
        wp_send_json_success( array( 'ref' => $ref ) );
    }

    public function ajax_chase_payment() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $ref     = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $booking = MBS_Bookings::get( $ref );

        if ( ! $booking ) wp_send_json_error( 'Booking not found.' );
        if ( ! in_array( $booking->status, array( 'confirmed', 'deposit_paid' ) ) ) {
            wp_send_json_error( 'Can only chase payment for confirmed or deposit-paid bookings.' );
        }

        MBS_Payment_Chaser::send_chase( $booking, true );
        wp_send_json_success( array( 'ref' => $ref, 'chase_count' => ( $booking->chase_count ?? 0 ) + 1 ) );
    }

    public function ajax_save_email_settings() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        // Save organisation details
        MBS_Email_Templates::save_org_settings( array(
            'org_name'           => $_POST['org_name'] ?? '',
            'org_address'        => $_POST['org_address'] ?? '',
            'org_phone'          => $_POST['org_phone'] ?? '',
            'org_charity_number' => $_POST['org_charity_number'] ?? '',
            'org_logo_url'       => $_POST['org_logo_url'] ?? '',
        ) );

        // Save chase/cron settings
        MBS_Email_Templates::save_chase_settings( array(
            'max_chase_emails'    => $_POST['max_chase_emails'] ?? 3,
            'chase_interval_days' => $_POST['chase_interval_days'] ?? 3,
            'cron_time_reminders' => $_POST['cron_time_reminders'] ?? '07:00',
            'cron_time_chase'     => $_POST['cron_time_chase'] ?? '09:00',
            'cron_time_archive'   => $_POST['cron_time_archive'] ?? '02:00',
        ) );

        // Save email templates
        if ( isset( $_POST['templates'] ) && is_array( $_POST['templates'] ) ) {
            foreach ( $_POST['templates'] as $type => $tpl ) {
                MBS_Email_Templates::save_template(
                    sanitize_text_field( $type ),
                    $tpl['subject'] ?? '',
                    $tpl['body'] ?? ''
                );
            }
        }

        wp_send_json_success( array( 'saved' => true ) );
    }

    public function ajax_save_custom_fields() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $fields = $_POST['fields'] ?? array();
        if ( ! is_array( $fields ) ) $fields = array();

        MBS_Custom_Fields::save_fields( $fields );
        wp_send_json_success( array( 'saved' => true, 'count' => count( $fields ) ) );
    }

    public function ajax_edit_booking() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $ref = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) wp_send_json_error( 'Booking not found.' );

        $old_amount = (float) $booking->amount;

        // Recalculate cost with multi-day support
        $scout_use = ! empty( $_POST['scout_use'] );
        $all_day   = ! empty( $_POST['all_day'] );
        $date_from = sanitize_text_field( $_POST['booking_date'] );
        $date_to   = sanitize_text_field( $_POST['booking_date_end'] ?? $date_from );
        $num_days  = max( 1, (int) round( ( strtotime( $date_to ) - strtotime( $date_from ) ) / 86400 ) + 1 );

        $new_amount = MBS_Bookings::calculate_cost(
            sanitize_text_field( $_POST['space'] ),
            sanitize_text_field( $_POST['start_time'] ?? '' ),
            sanitize_text_field( $_POST['end_time'] ?? '' ),
            ! empty( $_POST['kitchen'] ),
            $all_day,
            $num_days,
            $scout_use
        );

        // QA-007: Custom price override
        $calculated_amount = $new_amount;
        $is_custom_price   = ! empty( $_POST['custom_price'] );
        if ( $is_custom_price ) {
            $new_amount = max( 0, round( floatval( $_POST['custom_amount'] ?? 0 ), 2 ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;

        // SEC-006: Check for conflicts when admin edits date/time/space
        $new_space = sanitize_text_field( $_POST['space'] );
        $new_date  = sanitize_text_field( $_POST['booking_date'] );
        $new_start = sanitize_text_field( $_POST['start_time'] ?? '' );
        $new_end   = sanitize_text_field( $_POST['end_time'] ?? '' );
        $new_allday = ! empty( $_POST['all_day'] );

        if ( $new_space !== $booking->space || $new_date !== $booking->booking_date ||
             $new_start !== $booking->start_time || $new_end !== $booking->end_time ) {
            $conflicts = MBS_Bookings::check_conflicts(
                $new_space, $new_date,
                $new_allday ? null : $new_start,
                $new_allday ? null : $new_end,
                $new_allday, $ref
            );
            if ( ! empty( $conflicts ) ) {
                wp_send_json_error( 'This change conflicts with an existing booking: ' . MBS_Bookings::format_conflict_message( $conflicts ) );
            }
        }

        $update = array(
            'name'         => sanitize_text_field( $_POST['name'] ),
            'organisation' => sanitize_text_field( $_POST['organisation'] ?? '' ),
            'email'        => sanitize_email( $_POST['email'] ),
            'phone'        => sanitize_text_field( $_POST['phone'] ),
            'space'        => sanitize_text_field( $_POST['space'] ),
            'booking_date' => sanitize_text_field( $_POST['booking_date'] ),
            'booking_date_end' => $date_to,
            'start_time'   => ! empty( $_POST['start_time'] ) ? sanitize_text_field( $_POST['start_time'] ) : null,
            'end_time'     => ! empty( $_POST['end_time'] )   ? sanitize_text_field( $_POST['end_time'] )   : null,
            'attendees'    => absint( $_POST['attendees'] ),
            'all_day'      => $all_day ? 1 : 0,
            'kitchen'      => ! empty( $_POST['kitchen'] ) ? 1 : 0,
            'scout_use'    => $scout_use ? 1 : 0,
            'purpose'      => sanitize_text_field( $_POST['purpose'] ),
            'notes'        => sanitize_textarea_field( $_POST['notes'] ?? '' ),
            'address'      => sanitize_textarea_field( $_POST['address'] ?? '' ),
            'amount'       => $new_amount,
        );

        $wpdb->update( $table, $update, array( 'ref' => $ref ) );

        // Auto-update status if cost changed and there's now a balance due
        $amount_paid_val = (float) ( $booking->amount_paid ?? 0 );
        if ( $new_amount > $amount_paid_val && $amount_paid_val > 0 && $booking->status === 'paid' ) {
            // Cost increased beyond what was paid — revert to confirmed
            $wpdb->update( $table, array( 'status' => 'confirmed' ), array( 'ref' => $ref ) );
            MBS_Audit_Log::log( $ref, 'status_changed', 'Status reverted to Confirmed: cost increased to £' . number_format( $new_amount, 2 ) . ' but only £' . number_format( $amount_paid_val, 2 ) . ' paid.' );
        } elseif ( $new_amount <= $amount_paid_val && $amount_paid_val > 0 && $booking->status === 'confirmed' ) {
            // Cost decreased to within what was paid — mark as paid
            $wpdb->update( $table, array( 'status' => 'paid' ), array( 'ref' => $ref ) );
            MBS_Audit_Log::log( $ref, 'status_changed', 'Status set to Paid: cost reduced to £' . number_format( $new_amount, 2 ) . ' (£' . number_format( $amount_paid_val, 2 ) . ' already paid).' );
        }

        // Build change summary for audit log
        $changes = array();
        if ( $booking->space !== $update['space'] ) $changes[] = 'space: ' . $booking->space . ' → ' . $update['space'];
        if ( $booking->booking_date !== $update['booking_date'] ) $changes[] = 'date: ' . $booking->booking_date . ' → ' . $update['booking_date'];
        if ( $booking->start_time !== $update['start_time'] ) $changes[] = 'start: ' . $booking->start_time . ' → ' . $update['start_time'];
        if ( $booking->end_time !== $update['end_time'] ) $changes[] = 'end: ' . $booking->end_time . ' → ' . $update['end_time'];
        if ( abs( $old_amount - $new_amount ) > 0.01 ) $changes[] = 'amount: £' . number_format( $old_amount, 2 ) . ' → £' . number_format( $new_amount, 2 );
        // QA-006: Note when admin enables/disables scout use
        if ( $scout_use && ! $booking->scout_use ) $changes[] = 'scout use: enabled by admin';
        if ( ! $scout_use && $booking->scout_use ) $changes[] = 'scout use: disabled by admin';
        if ( $is_custom_price ) $changes[] = 'CUSTOM PRICE: calculated £' . number_format( $calculated_amount, 2 ) . ' overridden to £' . number_format( $new_amount, 2 );

        $change_summary = ! empty( $changes ) ? implode( ', ', $changes ) : 'Details updated (no price change)';
        MBS_Audit_Log::log( $ref, 'edited', 'Booking edited by admin. ' . $change_summary );

        // Notify booker if requested
        if ( ! empty( $_POST['notify'] ) ) {
            $updated_booking = MBS_Bookings::get( $ref );
            self::send_edit_notification( $updated_booking, $old_amount, $new_amount );
        }

        wp_send_json_success( array( 'ref' => $ref, 'new_amount' => $new_amount ) );
    }

    /**
     * Send notification email when a booking is edited by admin.
     */
    private static function send_edit_notification( $booking, $old_amount, $new_amount ) {
        $tpl       = MBS_Email_Templates::get_template( 'booking_edited' );
        $subject   = MBS_Email_Templates::replace_placeholders( $tpl['subject'], $booking );
        $body_text = MBS_Email_Templates::replace_placeholders( $tpl['body'], $booking );

        $org         = MBS_Email_Templates::get_org_settings();
        $admin_email = MBS_Bookings::get_admin_email();
        $logo        = MBS_Email_Templates::get_logo_html();

        $body  = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:0 auto;">';
        $body .= '<div style="background:#7413DC;padding:24px 32px;border-radius:8px 8px 0 0;text-align:center;">';
        $body .= $logo;
        $body .= '<h1 style="color:#fff;margin:8px 0 0;font-size:20px;">' . esc_html( $org['name'] ) . '</h1>';
        $body .= '<p style="color:rgba(255,255,255,0.8);margin:4px 0 0;">Booking Update</p></div>';
        $body .= '<div style="background:#fff;padding:32px;border:1px solid #e0d0f0;border-top:none;border-radius:0 0 8px 8px;">';
        $body .= '<h2 style="color:#7413DC;">Booking Updated</h2>';
        $body .= nl2br( esc_html( $body_text ) );

        $time_str = ! empty( $booking->all_day ) ? 'All day' : ( $booking->start_time . ' – ' . $booking->end_time );

        $body .= '<table style="width:100%;border-collapse:collapse;margin:16px 0;">';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;width:35%;border-bottom:1px solid #e0d0f0;">Reference</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->ref ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Space</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->space ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Date</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( wp_date( 'l j F Y', strtotime( $booking->booking_date ) ) ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Time</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $time_str ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Amount</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;font-weight:bold;">&pound;' . number_format( $new_amount, 2 ) . '</td></tr>';
        $body .= '</table>';

        // Price change notice
        $diff = $new_amount - $old_amount;
        $amount_paid = (float) ( $booking->amount_paid ?? 0 );
        $balance_due = $new_amount - $amount_paid;

        if ( $balance_due > 0.01 ) {
            $body .= '<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:12px 16px;margin:16px 0;">';
            if ( $amount_paid > 0 ) {
                $body .= '<strong style="color:#991b1b;">Balance due: &pound;' . number_format( $balance_due, 2 ) . '</strong>';
                $body .= '<p style="margin:4px 0 0;font-size:0.85rem;color:#991b1b;">Already paid: &pound;' . number_format( $amount_paid, 2 ) . ' | New total: &pound;' . number_format( $new_amount, 2 ) . '</p>';
            } else {
                $body .= '<strong style="color:#991b1b;">Amount due: &pound;' . number_format( $balance_due, 2 ) . '</strong>';
            }
            $body .= '</div>';

            // Add Pay Now button if WooCommerce available and balance is due
            if ( MBS_Woo_Payment::is_available() ) {
                $updated_for_pay = MBS_Bookings::get( $booking->ref );
                if ( $updated_for_pay && in_array( $updated_for_pay->status, array( 'confirmed', 'deposit_paid' ) ) ) {
                    $pay_url = MBS_Woo_Payment::generate_payment_url( $updated_for_pay );
                    if ( $pay_url ) {
                        $body .= '<p style="text-align:center;margin:16px 0;"><a href="' . esc_url( $pay_url ) . '" style="background:#2ecc71;color:#fff;padding:14px 32px;border-radius:6px;text-decoration:none;font-weight:bold;font-size:16px;">💳 Pay Balance Now (&pound;' . number_format( $balance_due, 2 ) . ')</a></p>';
                        $body .= '<p style="text-align:center;font-size:13px;color:#666;">Or pay by bank transfer using the details on your invoice.</p>';
                    }
                }
            }
        } elseif ( $balance_due < -0.01 ) {
            $body .= '<div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:6px;padding:12px 16px;margin:16px 0;">';
            $body .= '<strong style="color:#065f46;">Refund due: &pound;' . number_format( abs( $balance_due ), 2 ) . '</strong>';
            $body .= '<p style="margin:4px 0 0;font-size:0.85rem;color:#065f46;">You have overpaid. We\'ll arrange a refund or credit this against your next booking.</p>';
            $body .= '</div>';
        }

        $body .= '<p>If you have any questions, contact us at <a href="mailto:' . esc_attr( $admin_email ) . '">' . esc_html( $admin_email ) . '</a>.</p>';
        $body .= '</div>';
        $body .= '<div style="text-align:center;padding:16px;color:#999;font-size:12px;">' . esc_html( $org['name'] ) . ' &bull; ' . esc_html( $org['address'] ) . '</div>';
        $body .= '</body></html>';

        // Attach updated invoice
        $attachments = MBS_Email::generate_invoice_attachment_for( $booking );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $org['name'] . ' <' . get_option( 'admin_email', $admin_email ) . '>',
            'Reply-To: ' . $admin_email,
        );
        MBS_Email_Queue::send( $booking->email, $subject, $body, $headers, $attachments );
    }

    public function ajax_approve_request() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'Invalid request ID.' );

        $result = MBS_Modification::approve( $id );
        if ( $result ) {
            wp_send_json_success( array( 'approved' => true ) );
        } else {
            wp_send_json_error( 'Could not approve this request.' );
        }
    }

    public function ajax_reject_request() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $id     = absint( $_POST['id'] ?? 0 );
        $reason = sanitize_textarea_field( $_POST['reason'] ?? '' );
        if ( ! $id ) wp_send_json_error( 'Invalid request ID.' );

        $result = MBS_Modification::reject( $id, $reason );
        if ( $result ) {
            wp_send_json_success( array( 'rejected' => true ) );
        } else {
            wp_send_json_error( 'Could not reject this request.' );
        }
    }

    public function ajax_bulk_action() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $action = sanitize_text_field( $_POST['bulk_action'] ?? '' );
        $refs   = $_POST['refs'] ?? array();

        if ( ! $action || empty( $refs ) || ! is_array( $refs ) ) {
            wp_send_json_error( 'Please select bookings and an action.' );
        }

        $allowed = array( 'confirmed', 'paid', 'cancelled', 'archived' );
        if ( ! in_array( $action, $allowed ) ) {
            wp_send_json_error( 'Invalid action.' );
        }

        // Define valid source statuses for each target
        $valid_transitions = array(
            'confirmed' => array( 'pending' ),
            'paid'      => array( 'confirmed' ),
            'cancelled' => array( 'pending', 'confirmed' ),
            'archived'  => array( 'confirmed', 'paid', 'cancelled' ),
        );

        $processed = 0;
        $skipped   = 0;

        foreach ( $refs as $ref ) {
            $ref     = strtoupper( sanitize_text_field( $ref ) );
            $booking = MBS_Bookings::get( $ref );
            if ( ! $booking ) { $skipped++; continue; }

            // Check valid transition
            if ( ! in_array( $booking->status, $valid_transitions[ $action ] ) ) {
                $skipped++;
                continue;
            }

            MBS_Bookings::update_status( $ref, $action );

            // Send appropriate emails
            if ( $action === 'confirmed' ) {
                $updated = MBS_Bookings::get( $ref );
                if ( $updated ) MBS_Email::notify_confirmed( $updated );
            }
            if ( $action === 'paid' ) {
                $updated = MBS_Bookings::get( $ref );
                if ( $updated ) MBS_Email::notify_paid( $updated );
            }

            $processed++;
        }

        MBS_Audit_Log::log( 'BULK', 'bulk_' . $action, "Bulk {$action}: {$processed} processed, {$skipped} skipped" );

        wp_send_json_success( array(
            'action'    => $action,
            'processed' => $processed,
            'skipped'   => $skipped,
            'total'     => count( $refs ),
        ) );
    }
}

// Note: approve/reject methods are added outside the class closing brace above
// because the class structure is complex. These are standalone functions registered via add_action.
