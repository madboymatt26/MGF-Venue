<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MBS_Admin {

    public function init() {
        add_action( 'admin_menu',            array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_head',            array( $this, 'menu_icon_css' ) );
        add_action( 'wp_ajax_mbs_update_status',  array( $this, 'ajax_update_status' ) );
        add_action( 'wp_ajax_mbs_mark_refunded',  array( $this, 'ajax_mark_refunded' ) );
        add_action( 'wp_ajax_mbs_mark_deposit_paid', array( $this, 'ajax_mark_deposit_paid' ) );
        add_action( 'wp_ajax_mbs_undo_deposit',  array( $this, 'ajax_undo_deposit' ) );
        add_action( 'wp_ajax_mbs_restore_booking', array( $this, 'ajax_restore_booking' ) );
        add_action( 'wp_ajax_mbs_resend_access',   array( $this, 'ajax_resend_access' ) );
        add_action( 'wp_ajax_mbs_send_feedback_request', array( $this, 'ajax_send_feedback_request' ) );
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
        add_action( 'wp_ajax_mbs_resend_series_confirmation', array( $this, 'ajax_resend_series_confirmation' ) );
        add_action( 'wp_ajax_mbs_record_invoice_manual_payment', array( $this, 'ajax_record_invoice_manual_payment' ) );
        add_action( 'wp_ajax_mbs_resolve_invoice_reconciliation', array( $this, 'ajax_resolve_invoice_reconciliation' ) );
        add_action( 'wp_ajax_mbs_configure_series_billing', array( $this, 'ajax_configure_series_billing' ) );
        add_action( 'wp_ajax_mbs_approve_series_with_billing', array( $this, 'ajax_approve_series_with_billing' ) );
        add_action( 'wp_ajax_mbs_get_series_for_approval', array( $this, 'ajax_get_series_for_approval' ) );
        add_action( 'wp_ajax_mbs_billing_preview', array( $this, 'ajax_billing_preview' ) );
        add_action( 'wp_ajax_mbs_pause_series', array( $this, 'ajax_pause_series' ) );
        add_action( 'wp_ajax_mbs_catch_up_series_billing', array( $this, 'ajax_catch_up_series_billing' ) );
        add_action( 'wp_ajax_mbs_extend_external_series', array( $this, 'ajax_extend_external_series' ) );
        add_action( 'wp_ajax_mbs_delete_archive_series', array( $this, 'ajax_delete_archive_series' ) );
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

        // Parent menu — show pending bookings count in the top-level menu item.
        // "MGF Venue" is the operator/product brand (admin-only). Customer-facing
        // surfaces (emails, invoices, public pages) use the configurable Scout
        // Group org name + logo instead — never the MGF brand.
        $menu_label = 'MGF Venue';
        if ( $pending_bookings > 0 ) {
            $menu_label .= ' <span class="update-plugins count-' . $pending_bookings . '"><span class="plugin-count">' . $pending_bookings . '</span></span>';
        }

        add_menu_page(
            'MGF Venue',
            $menu_label,
            $booking_cap,
            'mathlin-booking',
            array( $this, 'render_dashboard' ),
            MBS_PLUGIN_URL . 'assets/mgf-venue-icon.png',
            30
        );
        add_submenu_page( 'mathlin-booking', 'All Bookings', $bookings_label, $booking_cap, 'mathlin-booking', array( $this, 'render_dashboard' ) );
        add_submenu_page( 'mathlin-booking', 'Recurring Series', 'Recurring Series', $booking_cap, 'mathlin-series', array( $this, 'render_series' ) );
        // Scout Nights: only show if the feature is enabled in Settings
        if ( get_option( 'mbs_scout_nights_enabled', 1 ) ) {
            add_submenu_page( 'mathlin-booking', 'Scout Nights', 'Scout Nights', $booking_cap, 'mathlin-scout-nights', array( $this, 'render_scout_nights' ) );
        }
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

    /**
     * Constrain the custom top-level menu icon.
     *
     * WordPress renders a URL-based menu icon as an unconstrained <img>, so a
     * high-res source would otherwise display at full size in the admin sidebar.
     * This runs on admin_head (every admin page) because the menu is global.
     */
    public function menu_icon_css() {
        echo '<style>
            #adminmenu #toplevel_page_mathlin-booking .wp-menu-image img {
                width: 20px;
                height: 20px;
                object-fit: contain;
                padding: 7px 0 0 !important;
                opacity: 0.85;
            }
            #adminmenu #toplevel_page_mathlin-booking:hover .wp-menu-image img,
            #adminmenu #toplevel_page_mathlin-booking.current .wp-menu-image img {
                opacity: 1;
            }
        </style>';
    }

    /**
     * Return an <img> tag for the MGF Venue compass mark, sized for an admin
     * page <h1>. Centralised so all admin headings stay consistent.
     */
    public static function brand_mark() {
        return '<img src="' . esc_url( MBS_PLUGIN_URL . 'assets/mgf-venue-mark.png' ) . '" alt="MGF Venue" '
             . 'style="height:1.1em;width:auto;vertical-align:-0.18em;margin-right:6px;">';
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

    private static function invoice_manages_occurrence( $booking ) {
        return $booking && in_array( MBS_Series::billing_treatment_for_booking( $booking ), array( 'manual_consolidated', 'invoice_managed', 'none' ), true );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'mathlin' ) === false ) return;
        wp_enqueue_style(  'mbs-admin', MBS_PLUGIN_URL . 'admin/admin.css', array(), MBS_VERSION );
        wp_enqueue_script( 'mbs-admin', MBS_PLUGIN_URL . 'admin/admin.js',  array( 'jquery' ), MBS_VERSION, true );
        wp_localize_script( 'mbs-admin', 'MBS_Admin', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'mbs_admin_nonce' ),
            'doc_nonce' => wp_create_nonce( 'mbs_invoice_document_nonce' ),
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

    public function render_series() {
        $ref = sanitize_text_field( $_GET['ref'] ?? '' );
        $status = sanitize_key( $_GET['status'] ?? '' );
        $search = sanitize_text_field( $_GET['s'] ?? '' );
        $series = $ref ? MBS_Series::get( $ref ) : null;
        $series_rows = $ref ? array() : MBS_Series::get_all( array( 'status' => $status, 'search' => $search ) );
        $occurrences = $series ? MBS_Series::occurrences( $series->series_ref ) : array();
        $exceptions = $series ? MBS_Series::exceptions( $series ) : array();
        $invoices = $series ? MBS_Series::invoices( $series->series_ref ) : array();
        // Map each ledger invoice to its current immutable issued document in
        // one query, avoiding a query per invoice in the admin view.
        $invoice_documents = array();
        if ( $invoices ) {
            global $wpdb;
            $doc_table = $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE;
            $invoice_ids = array_map( function( $invoice ) { return (int) $invoice->id; }, $invoices );
            $placeholders = implode( ',', array_fill( 0, count( $invoice_ids ), '%d' ) );
            $document_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT invoice_id, id AS document_id FROM {$doc_table} WHERE invoice_id IN ({$placeholders}) AND status = 'issued' ORDER BY revision DESC",
                $invoice_ids
            ) );
            foreach ( $document_rows as $document_row ) {
                $invoice_id = (int) $document_row->invoice_id;
                if ( ! isset( $invoice_documents[ $invoice_id ] ) ) {
                    $invoice_documents[ $invoice_id ] = (int) $document_row->document_id;
                }
            }
        }
        $preview = $series ? MBS_Billing_Engine::preview( $series->series_ref ) : array();
        $audit = $series ? MBS_Audit_Log::get_for_booking( $series->series_ref ) : array();
        include MBS_PLUGIN_DIR . 'admin/views/series.php';
    }

    // ── AJAX handlers ──────────────────────────────────────────────────────────
    public function ajax_update_status() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $ref    = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $status = sanitize_text_field( $_POST['status'] ?? '' );
        $reason = sanitize_textarea_field( $_POST['reason'] ?? '' );

        $current = MBS_Bookings::get( $ref );
        if ( ! $current ) wp_send_json_error( 'Booking not found.' );
        if ( ! empty( $current->series_id ) && MBS_Series::get( $current->series_id ) ) {
            wp_send_json_error( 'Change first-class recurring occurrences through the versioned series screen.', 409 );
        }
        if ( in_array( $status, array( 'paid', 'deposit_paid' ), true ) && self::invoice_manages_occurrence( $current ) ) {
            wp_send_json_error( 'Record payment against the consolidated invoice, not an individual occurrence.', 409 );
        }

        $result = MBS_Bookings::update_status( $ref, $status );
        if ( $result === false ) wp_send_json_error( 'This booking cannot be changed because it has financial history.', 409 );

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
        if ( self::invoice_manages_occurrence( $booking ) ) wp_send_json_error( 'Record credits or refunds through the consolidated invoice ledger.', 409 );

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
        if ( self::invoice_manages_occurrence( $booking ) ) wp_send_json_error( 'Recurring consolidated series do not use per-occurrence deposits.', 409 );
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
        if ( self::invoice_manages_occurrence( $booking ) ) wp_send_json_error( 'Recurring consolidated series do not use per-occurrence deposits.', 409 );
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
        if ( MBS_Bookings::has_financial_history( $ref ) ) wp_send_json_error( 'A booking with financial history cannot be restored directly. Use credit and replacement records.', 409 );
        if ( self::invoice_manages_occurrence( $booking ) && in_array( $status, array( 'deposit_paid', 'paid' ), true ) ) {
            wp_send_json_error( 'Restore consolidated-series payment state through the invoice ledger.', 409 );
        }

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
     * Manually send a post-booking feedback request to a hirer.
     * Trusts the admin: ignores the date window and the feedback_sent flag.
     */
    public function ajax_send_feedback_request() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );

        $ref = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) wp_send_json_error( 'Booking not found.' );

        if ( ! get_option( 'mbs_feedback_enabled', 0 ) ) {
            wp_send_json_error( 'Feedback emails are disabled. Enable them in Settings → Post-Booking Feedback & Reviews.' );
        }

        MBS_Feedback::resend( $booking );

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
            if ( $wpdb->query( 'START TRANSACTION' ) === false ) wp_send_json_error( 'Could not start recurring booking creation.', 500 );
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
                'organisation'     => get_option( 'mbs_org_name', get_bloginfo( 'name' ) ),
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
            if ( $wpdb->query( 'COMMIT' ) === false ) { $wpdb->query( 'ROLLBACK' ); wp_send_json_error( 'Could not commit recurring booking creation.', 500 ); }
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

        // Minimum booking duration (hours). 0 = feature disabled. Clamp to a
        // sane 0–24h range; accepts fractional values (e.g. 1.5).
        $min_duration = floatval( $_POST['min_duration_hours'] ?? 0 );
        $min_duration = max( 0, min( 24, $min_duration ) );

        update_option( 'mbs_ha_webhook_url',  $webhook );
        update_option( 'mbs_min_notice_days', $notice_days );
        update_option( 'mbs_min_duration_hours', $min_duration );
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

        // Scout Nights feature toggle
        update_option( 'mbs_scout_nights_enabled', absint( $_POST['scout_nights_enabled'] ?? 1 ) );

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

        wp_send_json_success( array( 'saved' => true, 'min_notice_days' => $notice_days, 'min_duration_hours' => $min_duration ) );
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
            'message'      => 'Test webhook from ' . get_option( 'mbs_org_name', get_bloginfo( 'name' ) ) . ' booking system',
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

        $series = MBS_Series::get( $series_id );
        if ( $series ) {
            $expected_status  = sanitize_text_field( $_POST['expected_status'] ?? '' );
            $expected_version = absint( $_POST['expected_version'] ?? 0 );
            if ( $expected_status === '' || $expected_version < 1 ) {
                wp_send_json_error( 'Series status and version preconditions are required. Refresh and try again.', 409 );
            }

            if ( $status === 'confirmed' ) {
                $result = MBS_Series::approve( $series_id, $expected_status, $expected_version, true );
                $count  = is_wp_error( $result ) ? 0 : (int) $result['updated'];
            } elseif ( $status === 'cancelled' ) {
                $scope  = sanitize_key( $_POST['scope'] ?? 'all' );
                $result = MBS_Series::cancel( $series_id, $scope, $expected_status, $expected_version, true );
                $count  = is_wp_error( $result ) ? 0 : (int) $result['cancelled'];
            } else {
                wp_send_json_error( 'First-class series support only approval or cancellation through this action.' );
            }
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( $result->get_error_message(), $result->get_error_code() === 'series_precondition_failed' ? 409 : 400 );
            }
            wp_send_json_success( array(
                'series_id'   => $series_id,
                'status'      => $status,
                'count'       => $count,
                'no_op'       => ! empty( $result['no_op'] ),
                'email_sent'  => $result['email_sent'] ?? null,
                'new_version' => isset( $result['series']->version ) ? (int) $result['series']->version : $expected_version,
            ) );
        }

        // Legacy series retain their historical per-occurrence behaviour until
        // they are deliberately adopted into first-class series metadata.
        $result = MBS_Bookings::update_series_status( $series_id, $status );
        if ( $status === 'confirmed' ) {
            foreach ( MBS_Bookings::get_series( $series_id ) as $booking ) {
                if ( $booking->status === 'confirmed' ) {
                    MBS_Email::notify_confirmed( $booking );
                }
            }
        }
        $count = count( MBS_Bookings::get_series( $series_id ) );
        wp_send_json_success( array( 'series_id' => $series_id, 'status' => $status, 'count' => $count, 'legacy' => true ) );
    }

    public function ajax_resend_series_confirmation() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to perform this action.', 403 );
        $series_id = sanitize_text_field( $_POST['series_id'] ?? '' );
        $result = MBS_Series::resend_confirmation( $series_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( $result );
    }

    public function ajax_record_invoice_manual_payment() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to record invoice payments.', 403 );
        $invoice_ref = sanitize_text_field( $_POST['invoice_ref'] ?? '' );
        $amount_minor = sanitize_text_field( $_POST['amount_minor'] ?? '' );
        $idempotency_key = sanitize_text_field( $_POST['idempotency_key'] ?? '' );
        $expected_version = absint( $_POST['expected_version'] ?? 0 );
        if ( ! $invoice_ref || $amount_minor === '' || ! $idempotency_key || $expected_version < 1 ) {
            wp_send_json_error( 'Invoice reference, amount in minor units, idempotency key and expected version are required.' );
        }
        $result = MBS_Invoice_Payment::record_manual_payment(
            $invoice_ref, $amount_minor, $idempotency_key, $expected_version,
            sanitize_text_field( $_POST['note'] ?? '' )
        );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message(), $result->get_error_code() === 'invoice_precondition_failed' ? 409 : 400 );
        }
        wp_send_json_success( array(
            'invoice_ref' => $result['invoice']->invoice_ref,
            'status' => $result['invoice']->status,
            'version' => (int) $result['invoice']->version,
            'balance_minor' => MBS_Billing_Ledger::balance_minor( $result['invoice'] ),
            'idempotent_replay' => ! empty( $result['idempotent_replay'] ),
        ) );
    }

    /** Resolve a captured-payment exception only after an administrator verifies the ledger or refund. */
    public function ajax_resolve_invoice_reconciliation() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Only an administrator can resolve payment reconciliation.', 403 );

        $invoice_ref = sanitize_text_field( $_POST['invoice_ref'] ?? '' );
        $reservation_ref = sanitize_text_field( $_POST['reservation_ref'] ?? '' );
        $order_id = absint( $_POST['order_id'] ?? 0 );
        $resolution = sanitize_key( $_POST['resolution'] ?? '' );
        if ( ! $invoice_ref || ! $reservation_ref || ! $order_id ) wp_send_json_error( 'Invoice, reservation and order are required.', 400 );

        $requested_resolution = $resolution;
        if ( $resolution === 'record_payment' ) {
            $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
            if ( ! $order ) wp_send_json_error( 'The WooCommerce order no longer exists.', 400 );
            $recorded = MBS_Invoice_Payment::record_gateway_payment( $invoice_ref, $order->get_total(), $order_id, $reservation_ref );
            if ( is_wp_error( $recorded ) ) wp_send_json_error( $recorded->get_error_message(), 409 );
            $resolution = 'ledger_recorded';
        }

        $resolved = MBS_Invoice_Reservation::resolve( $invoice_ref, $reservation_ref, $order_id, $resolution );
        if ( is_wp_error( $resolved ) ) wp_send_json_error( $resolved->get_error_message(), 400 );
        if ( ! $resolved ) wp_send_json_error( 'The reconciliation state changed; refresh and verify it again.', 409 );

        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
        if ( $order ) {
            $order->delete_meta_data( '_mbs_invoice_reconciliation_required' );
            $order->update_meta_data( '_mbs_invoice_reconciliation_resolution', $resolution );
            $order->save();
            $order->add_order_note( 'Invoice payment reconciliation resolved by administrator: ' . $resolution . '.' );
        }
        MBS_Audit_Log::log( $invoice_ref, 'payment_reconciliation_resolved', 'Order #' . $order_id . ' resolved as ' . $resolution . ' by administrator.' );
        wp_send_json_success( array( 'invoice_ref' => $invoice_ref, 'order_id' => $order_id, 'status' => $requested_resolution ) );
    }

    /**
     * AJAX: Return the canonical billing period preview for a series.
     * Uses the same period calculator as the billing engine.
     */
    public function ajax_billing_preview() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'Permission denied.', 403 );

        $series_ref = sanitize_text_field( $_POST['series_ref'] ?? '' );
        if ( ! $series_ref ) wp_send_json_error( 'Series reference required.', 400 );

        // Build overrides from the proposed billing config
        $overrides = array();
        if ( ! empty( $_POST['billing_mode'] ) ) $overrides['billing_mode'] = sanitize_key( $_POST['billing_mode'] );
        if ( ! empty( $_POST['invoice_lead_days'] ) ) $overrides['invoice_lead_days'] = absint( $_POST['invoice_lead_days'] );
        if ( ! empty( $_POST['payment_terms_days'] ) ) $overrides['payment_terms_days'] = absint( $_POST['payment_terms_days'] );
        if ( ! empty( $_POST['billing_schedule'] ) ) {
            $schedule = json_decode( wp_unslash( $_POST['billing_schedule'] ), true );
            if ( is_array( $schedule ) ) $overrides['billing_schedule_json'] = wp_json_encode( $schedule );
        }

        // For pending series, include pending occurrences in preview
        $series = MBS_Series::get( $series_ref );
        if ( $series && $series->status === 'pending' ) {
            $overrides['_include_pending'] = true;
        }

        $preview = MBS_Billing_Engine::preview( $series_ref, $overrides );
        if ( is_wp_error( $preview ) ) wp_send_json_error( $preview->get_error_message(), 400 );

        // Format for the admin UI
        $formatted_periods = array();
        foreach ( $preview['periods'] ?? array() as $period ) {
            $formatted_periods[] = array(
                'period'     => $period['label'] ?? $period['period_key'] ?? '',
                'issue_date' => ! empty( $period['issue_on'] ) ? wp_date( 'j M Y', strtotime( $period['issue_on'] ) ) : '',
                'due_date'   => ! empty( $period['due_on'] ) ? wp_date( 'j M Y', strtotime( $period['due_on'] ) ) : '',
                'sessions'   => (int) ( $period['occurrence_count'] ?? count( $period['items'] ?? array() ) ),
                'total'      => MBS_Money::format( (int) ( $period['total_minor'] ?? 0 ) ),
            );
        }

        wp_send_json_success( array( 'periods' => $formatted_periods ) );
    }

    public function ajax_approve_series_with_billing() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to approve series.', 403 );

        $series_ref = sanitize_text_field( $_POST['series_ref'] ?? '' );
        $expected_version = absint( $_POST['expected_version'] ?? 0 );
        if ( ! $series_ref || $expected_version < 1 ) wp_send_json_error( 'Series reference and version are required.', 400 );

        $billing_schedule = array();
        $schedule_raw = wp_unslash( $_POST['billing_schedule'] ?? '' );
        if ( $schedule_raw ) {
            $billing_schedule = json_decode( $schedule_raw, true );
            if ( ! is_array( $billing_schedule ) ) $billing_schedule = array();
        }

        $billing_config = array(
            'billing_mode'      => sanitize_key( $_POST['billing_mode'] ?? '' ),
            'billing_treatment' => sanitize_key( $_POST['billing_treatment'] ?? '' ),
            'payment_method'    => sanitize_key( $_POST['payment_method'] ?? '' ),
            'invoice_lead_days' => absint( $_POST['invoice_lead_days'] ?? 28 ),
            'payment_terms_days' => absint( $_POST['payment_terms_days'] ?? 14 ),
            'billing_schedule'  => $billing_schedule,
        );

        $result = MBS_Series::approve( $series_ref, 'pending', $expected_version, true, $billing_config );
        if ( is_wp_error( $result ) ) {
            $status = $result->get_error_code() === 'series_precondition_failed' ? 409 : 400;
            wp_send_json_error( $result->get_error_message(), $status );
        }
        wp_send_json_success( array( 'series_ref' => $series_ref, 'status' => 'confirmed' ) );
    }

    /**
     * Return series billing defaults for the approval modal.
     * Used when opening the Review & Approve modal from the list or single-booking pages.
     */
    public function ajax_get_series_for_approval() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'Permission denied.', 403 );

        $series_ref = sanitize_text_field( $_POST['series_ref'] ?? '' );
        if ( ! $series_ref ) wp_send_json_error( 'Series reference is required.', 400 );

        $series = MBS_Series::get( $series_ref );
        if ( ! $series ) wp_send_json_error( 'Series not found.', 404 );

        wp_send_json_success( array(
            'series_ref'        => $series->series_ref,
            'version'           => (int) $series->version,
            'status'            => $series->status,
            'space'             => $series->space,
            'price_per_booking' => (float) $series->price_per_booking,
            'estimated_total'   => (float) $series->estimated_total,
            'accepted_count'    => (int) $series->accepted_count,
            'requested_count'   => (int) $series->requested_count,
            'scout_use'         => (bool) $series->scout_use,
            'billing_mode'      => $series->billing_mode,
            'billing_treatment' => $series->billing_treatment,
            'payment_method'    => $series->payment_method,
            'invoice_lead_days' => (int) $series->invoice_lead_days,
            'payment_terms_days' => (int) $series->payment_terms_days,
        ) );
    }

    /**
     * Delete & Archive a cancelled series.
     * - Past bookings → archived
     * - Future bookings → deleted
     * - Series record → deleted
     * - Invoices/credit notes remain (financial history preserved)
     */
    public function ajax_delete_archive_series() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'Permission denied.', 403 );

        $series_ref = sanitize_text_field( $_POST['series_ref'] ?? '' );
        if ( ! $series_ref ) wp_send_json_error( 'Series reference is required.', 400 );

        $series = MBS_Series::get( $series_ref );
        if ( ! $series ) wp_send_json_error( 'Series not found.', 404 );
        if ( $series->status !== 'cancelled' ) wp_send_json_error( 'Only cancelled series can be deleted and archived.', 400 );

        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        $series_table = $wpdb->prefix . MBS_SERIES_TABLE;
        $today = current_time( 'Y-m-d' );

        // Archive past bookings (set status to 'archived')
        $archived = (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'archived', updated_at = NOW() WHERE series_id = %s AND booking_date < %s",
            $series_ref, $today
        ) );

        // Delete future bookings
        $deleted = (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE series_id = %s AND booking_date >= %s",
            $series_ref, $today
        ) );

        // Delete the series record
        $wpdb->delete( $series_table, array( 'series_ref' => $series_ref ) );

        MBS_Audit_Log::log( $series_ref, 'series_deleted', "Delete & Archive: archived {$archived} past booking(s), deleted {$deleted} future booking(s), removed series record." );

        wp_send_json_success( array(
            'series_ref' => $series_ref,
            'archived'   => $archived,
            'deleted'    => $deleted,
        ) );
    }

    public function ajax_configure_series_billing() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to change series billing.', 403 );
        $series_ref = sanitize_text_field( $_POST['series_ref'] ?? '' );
        $expected_version = absint( $_POST['expected_version'] ?? 0 );
        if ( ! $series_ref || $expected_version < 1 ) wp_send_json_error( 'Series reference and current version are required.', 400 );

        $schedule = array();
        $schedule_json = wp_unslash( $_POST['billing_schedule_json'] ?? '' );
        if ( $schedule_json !== '' ) {
            $schedule = json_decode( $schedule_json, true );
            if ( ! is_array( $schedule ) ) wp_send_json_error( 'The term schedule is not valid JSON.', 400 );
        }
        $result = MBS_Billing_Engine::configure_series( $series_ref, array(
            'billing_mode' => sanitize_key( $_POST['billing_mode'] ?? '' ),
            'billing_treatment' => sanitize_key( $_POST['billing_treatment'] ?? '' ),
            'payment_method' => sanitize_key( $_POST['payment_method'] ?? '' ),
            'deposit_policy' => 'none',
            'invoice_lead_days' => absint( $_POST['invoice_lead_days'] ?? 28 ),
            'payment_terms_days' => absint( $_POST['payment_terms_days'] ?? 14 ),
            'billing_schedule' => $schedule,
            'adopt_legacy' => ! empty( $_POST['adopt_legacy'] ),
        ), $expected_version );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message(), $result->get_error_code() === 'series_precondition_failed' ? 409 : 400 );
        MBS_Audit_Log::log( $series_ref, 'series_billing_changed', 'Billing changed to ' . $result->billing_mode . ' / ' . $result->billing_treatment . ' using ' . $result->payment_method . '.' );
        if ( in_array( $result->status, array( 'confirmed', 'paused' ), true ) ) {
            MBS_Email::notify_series_changed( $result, MBS_Series::active_occurrences( $series_ref ) );
        }
        wp_send_json_success( array( 'series_ref' => $series_ref, 'version' => (int) $result->version ) );
    }

    public function ajax_pause_series() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to pause series billing.', 403 );
        $result = MBS_Series::set_paused(
            sanitize_text_field( $_POST['series_ref'] ?? '' ),
            ! empty( $_POST['paused'] ),
            sanitize_key( $_POST['expected_status'] ?? '' ),
            absint( $_POST['expected_version'] ?? 0 )
        );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message(), $result->get_error_code() === 'series_precondition_failed' ? 409 : 400 );
        wp_send_json_success( array( 'status' => $result['series']->status, 'version' => (int) $result['series']->version, 'no_op' => ! empty( $result['no_op'] ) ) );
    }

    public function ajax_catch_up_series_billing() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to generate invoices.', 403 );
        $result = MBS_Billing_Engine::catch_up( wp_date( 'Y-m-d' ) );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message(), 400 );
        wp_send_json_success( $result );
    }

    public function ajax_extend_external_series() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! self::can_manage_bookings() ) wp_send_json_error( 'You do not have permission to extend series.', 403 );
        $result = MBS_Series::extend(
            sanitize_text_field( $_POST['series_ref'] ?? '' ),
            sanitize_text_field( $_POST['repeat_until'] ?? '' ),
            absint( $_POST['expected_version'] ?? 0 ),
            true
        );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message(), $result->get_error_code() === 'series_precondition_failed' ? 409 : 400 );
        wp_send_json_success( array( 'series_ref' => $result['series']->series_ref, 'version' => (int) $result['series']->version, 'created' => count( $result['created'] ), 'skipped' => (int) $result['skipped'] ) );
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
        if ( is_wp_error( $cancelled ) ) {
            wp_send_json_error( $cancelled->get_error_message(), 409 );
        } elseif ( $cancelled === false ) {
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
        if ( is_wp_error( $reopened ) ) {
            wp_send_json_error( $reopened->get_error_message(), 409 );
        } elseif ( $reopened === false ) {
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
        if ( is_wp_error( $deleted ) ) {
            wp_send_json_error( $deleted->get_error_message(), 409 );
        } elseif ( $deleted === false ) {
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

        if ( ! MBS_Payment_Chaser::should_chase_occurrence( $booking ) ) {
            wp_send_json_error( MBS_Payment_Chaser::chase_suppression_message( $booking ) );
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
            $scout_use,
            MBS_Bookings::get_booking_tier( $booking )
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

        $billing_fields_changed =
            $new_space !== $booking->space ||
            $new_date !== $booking->booking_date ||
            $date_to !== ( $booking->booking_date_end ?: $booking->booking_date ) ||
            $new_start !== (string) $booking->start_time ||
            $new_end !== (string) $booking->end_time ||
            $new_allday !== (bool) $booking->all_day ||
            ( ! empty( $_POST['kitchen'] ) ) !== (bool) $booking->kitchen ||
            $scout_use !== (bool) $booking->scout_use ||
            abs( $old_amount - $new_amount ) > 0.009;
        if ( $billing_fields_changed ) {
        if ( MBS_Bookings::has_financial_history( $ref ) ) {
                wp_send_json_error(
                    sprintf(
                        'This occurrence is already included on invoice %s. Cancel it through the recurring-series controls so the invoice is credited, then add a replacement occurrence; issued invoice details cannot be overwritten.',
                        $allocation->invoice_ref
                    ),
                    409
                );
            }
        }

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
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message(), 409 );
        } elseif ( $result ) {
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

            if ( MBS_Bookings::update_status( $ref, $action ) === false ) { $skipped++; continue; }

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
