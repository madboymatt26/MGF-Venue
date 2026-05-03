<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MBS_Admin {

    public function init() {
        add_action( 'admin_menu',            array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_mbs_update_status',  array( $this, 'ajax_update_status' ) );
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
        add_action( 'wp_ajax_mbs_save_admin_notes', array( $this, 'ajax_save_admin_notes' ) );
        add_action( 'wp_ajax_mbs_chase_payment',  array( $this, 'ajax_chase_payment' ) );
        add_action( 'wp_ajax_mbs_save_email_settings', array( $this, 'ajax_save_email_settings' ) );
        add_action( 'wp_ajax_mbs_save_custom_fields', array( $this, 'ajax_save_custom_fields' ) );
        add_action( 'wp_ajax_mbs_edit_booking',      array( $this, 'ajax_edit_booking' ) );
        add_action( 'wp_ajax_mbs_approve_request',  array( $this, 'ajax_approve_request' ) );
        add_action( 'wp_ajax_mbs_reject_request',   array( $this, 'ajax_reject_request' ) );
    }

    // ── Menu ───────────────────────────────────────────────────────────────────
    public function add_menu() {
        add_menu_page(
            'Scout Bookings',
            'Scout Bookings',
            'manage_options',
            'mathlin-booking',
            array( $this, 'render_dashboard' ),
            'dashicons-calendar-alt',
            30
        );
        add_submenu_page(
            'mathlin-booking',
            'All Bookings',
            'All Bookings',
            'manage_options',
            'mathlin-booking',
            array( $this, 'render_dashboard' )
        );
        add_submenu_page(
            'mathlin-booking',
            'Calendar',
            'Calendar',
            'manage_options',
            'mathlin-calendar',
            array( $this, 'render_calendar' )
        );
        add_submenu_page(
            'mathlin-booking',
            'Settings',
            'Settings',
            'manage_options',
            'mathlin-settings',
            array( $this, 'render_settings' )
        );
        add_submenu_page(
            'mathlin-booking',
            'Archived',
            'Archived',
            'manage_options',
            'mathlin-archived',
            array( $this, 'render_archived' )
        );
        add_submenu_page(
            'mathlin-booking',
            'Blocked Dates',
            'Blocked Dates',
            'manage_options',
            'mathlin-blocked',
            array( $this, 'render_blocked' )
        );
        add_submenu_page(
            'mathlin-booking',
            'Email Templates',
            'Email Templates',
            'manage_options',
            'mathlin-emails',
            array( $this, 'render_email_templates' )
        );
        add_submenu_page(
            'mathlin-booking',
            'Analytics',
            'Analytics',
            'manage_options',
            'mathlin-analytics',
            array( $this, 'render_analytics' )
        );
        add_submenu_page(
            'mathlin-booking',
            'Custom Fields',
            'Custom Fields',
            'manage_options',
            'mathlin-custom-fields',
            array( $this, 'render_custom_fields' )
        );
        $pending_count = MBS_Modification::get_pending_count();
        $requests_label = 'Requests';
        if ( $pending_count > 0 ) {
            $requests_label .= ' <span class="awaiting-mod count-' . $pending_count . '"><span class="pending-count">' . $pending_count . '</span></span>';
        }
        add_submenu_page(
            'mathlin-booking',
            'Change Requests',
            $requests_label,
            'manage_options',
            'mathlin-requests',
            array( $this, 'render_requests' )
        );
    }

    // ── Assets ─────────────────────────────────────────────────────────────────
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
        $stats    = MBS_Bookings::get_stats();
        $status   = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
        $search   = isset( $_GET['s'] )      ? sanitize_text_field( $_GET['s'] )      : '';
        $bookings = MBS_Bookings::get_all( array( 'status' => $status, 'search' => $search, 'orderby' => 'booking_date', 'order' => 'ASC' ) );
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

    // ── AJAX handlers ──────────────────────────────────────────────────────────
    public function ajax_update_status() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

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
            if ( $booking ) MBS_Email::notify_paid( $booking );
        }

        wp_send_json_success( array( 'ref' => $ref, 'status' => $status ) );
    }

    public function ajax_delete_booking() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $ref    = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $result = MBS_Bookings::delete( $ref );
        wp_send_json_success( array( 'deleted' => $ref ) );
    }

    public function ajax_get_invoice() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $ref     = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) wp_send_json_error( 'Not found' );

        wp_send_json_success( array( 'html' => MBS_Invoice::generate_html( $booking ) ) );
    }

    public function ajax_save_settings() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

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

                $spaces[ $name ] = array(
                    'rate_hourly' => max( 0, $rate_hourly ),
                    'rate_daily'  => max( 0, $rate_daily ),
                    'capacity'    => $capacity > 0 ? $capacity : null,
                );
            }
            if ( ! empty( $spaces ) ) {
                MBS_Bookings::save_spaces( $spaces );
            }
        }

        wp_send_json_success( array( 'saved' => true, 'min_notice_days' => $notice_days ) );
    }

    public function ajax_test_ha() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

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
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

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
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

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

    public function render_requests() {
        include MBS_PLUGIN_DIR . 'admin/views/requests.php';
    }

    public function ajax_add_blocked() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

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
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'Invalid ID.' );

        MBS_Blocked_Dates::delete( $id );
        wp_send_json_success( array( 'deleted' => $id ) );
    }

    public function ajax_clear_expired_blocks() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $count = MBS_Blocked_Dates::clear_expired();
        wp_send_json_success( array( 'cleared' => $count ) );
    }

    public function ajax_update_series_status() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

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

    public function ajax_save_admin_notes() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $ref   = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $notes = sanitize_textarea_field( $_POST['admin_notes'] ?? '' );

        MBS_Bookings::update_admin_notes( $ref, $notes );
        wp_send_json_success( array( 'ref' => $ref ) );
    }

    public function ajax_chase_payment() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $ref     = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $booking = MBS_Bookings::get( $ref );

        if ( ! $booking ) wp_send_json_error( 'Booking not found.' );
        if ( $booking->status !== 'confirmed' ) wp_send_json_error( 'Can only chase payment for confirmed bookings.' );

        MBS_Payment_Chaser::send_chase( $booking, true );
        wp_send_json_success( array( 'ref' => $ref, 'chase_count' => ( $booking->chase_count ?? 0 ) + 1 ) );
    }

    public function ajax_save_email_settings() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

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
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $fields = $_POST['fields'] ?? array();
        if ( ! is_array( $fields ) ) $fields = array();

        MBS_Custom_Fields::save_fields( $fields );
        wp_send_json_success( array( 'saved' => true, 'count' => count( $fields ) ) );
    }

    public function ajax_edit_booking() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

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

        $body .= '<table style="width:100%;border-collapse:collapse;margin:16px 0;">';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;width:35%;border-bottom:1px solid #e0d0f0;">Reference</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->ref ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Space</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->space ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Date</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( date( 'l j F Y', strtotime( $booking->booking_date ) ) ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Time</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $time_str ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Amount</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;font-weight:bold;">&pound;' . number_format( $new_amount, 2 ) . '</td></tr>';
        $body .= '</table>';

        // Price change notice
        $diff = $new_amount - $old_amount;
        if ( abs( $diff ) > 0.01 ) {
            if ( $diff > 0 ) {
                $body .= '<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:12px 16px;margin:16px 0;">';
                $body .= '<strong style="color:#991b1b;">Additional amount due: &pound;' . number_format( $diff, 2 ) . '</strong>';
                $body .= '<p style="margin:4px 0 0;font-size:0.85rem;color:#991b1b;">Please arrange payment for the additional amount.</p>';
                $body .= '</div>';

                // Add Pay Now button if WooCommerce available
                if ( MBS_Woo_Payment::is_available() && $booking->status === 'confirmed' ) {
                    $pay_url = MBS_Woo_Payment::generate_payment_url( $booking );
                    if ( $pay_url ) {
                        $body .= '<p style="text-align:center;margin:16px 0;"><a href="' . esc_url( $pay_url ) . '" style="background:#2ecc71;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;">💳 Pay Now</a></p>';
                    }
                }
            } else {
                $body .= '<div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:6px;padding:12px 16px;margin:16px 0;">';
                $body .= '<strong style="color:#065f46;">Credit of &pound;' . number_format( abs( $diff ), 2 ) . '</strong>';
                $body .= '<p style="margin:4px 0 0;font-size:0.85rem;color:#065f46;">We\'ll arrange a refund or credit this against your next booking.</p>';
                $body .= '</div>';
            }
        }

        $body .= '<p>If you have any questions, contact us at <a href="mailto:' . esc_attr( $admin_email ) . '">' . esc_html( $admin_email ) . '</a>.</p>';
        $body .= '</div>';
        $body .= '<div style="text-align:center;padding:16px;color:#999;font-size:12px;">' . esc_html( $org['name'] ) . ' &bull; ' . esc_html( $org['address'] ) . '</div>';
        $body .= '</body></html>';

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $org['name'] . ' <' . $admin_email . '>',
        );
        MBS_Email_Queue::send( $booking->email, $subject, $body, $headers );
    }

    public function ajax_approve_request() {
        check_ajax_referer( 'mbs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

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
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

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
}

// Note: approve/reject methods are added outside the class closing brace above
// because the class structure is complex. These are standalone functions registered via add_action.
