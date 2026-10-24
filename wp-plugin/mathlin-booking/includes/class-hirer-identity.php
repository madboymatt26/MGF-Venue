<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Mailbox proof, separate from login/password/2FA and from staff permissions. */
class MBS_Hirer_Identity {
    public static function cleanup() {
        global $wpdb;
        $counters = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 500", $wpdb->esc_like( 'mbs_security_counter_' ) . '%' ) );
        foreach ( (array) $counters as $key ) if ( preg_match( '/^mbs_security_counter_(\d+)_/', $key, $match ) && (int) $match[1] < time() ) delete_option( $key );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 500", $wpdb->esc_like( 'mbs_mailproof_' ) . '%' ) );
        foreach ( (array) $rows as $row ) { $value = maybe_unserialize( $row->option_value ); if ( ! is_array( $value ) || (int) ( $value['expires'] ?? 0 ) < time() ) delete_option( $row->option_name ); }
    }
    public function init() {
        add_action( 'template_redirect', array( $this, 'consume_link' ), 1 );
        add_action( 'wp_ajax_mbs_verify_email', array( $this, 'request_verification' ) );
    }

    public static function verified( $user = null ) {
        $user = $user ?: wp_get_current_user();
        if ( empty( $user->ID ) || empty( $user->user_email ) ) return false;
        return hash_equals( hash( 'sha256', strtolower( $user->user_email ) ), (string) get_user_meta( $user->ID, 'mbs_verified_email', true ) );
    }

    public static function owns_email( $email ) {
        $user = wp_get_current_user();
        return self::verified( $user ) && strcasecmp( $user->user_email, (string) $email ) === 0;
    }

    public static function throttle( $scope, $identity, $limit = 5, $seconds = 900 ) {
        global $wpdb;
        $seconds = max( 1, (int) $seconds ); $limit = max( 1, (int) $limit );
        $expiry = ( (int) floor( time() / $seconds ) + 1 ) * $seconds;
        $key = 'mbs_security_counter_' . $expiry . '_' . hash( 'sha256', $scope . '|' . $identity );
        add_option( $key, '0', '', false );
        // The conditional increment is one database operation, independent of
        // object-cache consistency and safe under simultaneous PHP workers.
        return $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=CAST(option_value AS UNSIGNED)+1 WHERE option_name=%s AND CAST(option_value AS UNSIGNED)<%d", $key, $limit ) ) === 1;
    }

    public static function send_link( $email, $user_id = 0, $booking = null ) {
        if ( ! self::throttle( 'mailbox', strtolower( $email ), 3 ) ) return false;
        $token = bin2hex( random_bytes( 32 ) );
        $key = 'mbs_mailproof_' . hash( 'sha256', $token );
        $value = array( 'expires' => time() + 900, 'email' => strtolower( $email ), 'user' => (int) $user_id, 'ref' => $booking ? $booking->ref : '' );
        if ( ! add_option( $key, $value, '', false ) ) return false;
        $url = add_query_arg( 'mbs_mailproof', $token, home_url( '/' ) );
        $body = 'Confirm your email address to access your venue booking records. This link expires in 15 minutes and can be used once.' . "\n\n" . $url . "\n\nIf you did not request this, ignore this email. No booking has been changed.";
        $org = MBS_Email_Templates::get_org_settings();
        $headers = array( 'Content-Type: text/plain; charset=UTF-8', 'From: ' . sanitize_text_field($org['name']) . ' <' . sanitize_email(get_option('admin_email')) . '>', 'Reply-To: ' . sanitize_email(MBS_Bookings::get_admin_email()) );
        $result = MBS_Email_Queue::enqueue( $email, 'Verify your venue booking email', $body, $headers, 'proof:' . hash( 'sha256', $token ), MBS_Email_Queue::compute_payload_hash( $email, 'Verify your venue booking email', $body, $headers, null ), null, array( 'message_type' => 'mailbox_verification' ) );
        return ! is_wp_error( $result );
    }

    public function request_verification() {
        check_ajax_referer( 'mbs_public_nonce', 'nonce' );
        $user = wp_get_current_user();
        if ( empty( $user->ID ) ) wp_send_json_error( array( 'message' => 'Sign in first.' ), 403 );
        self::send_link( $user->user_email, $user->ID );
        wp_send_json_success( array( 'message' => 'If eligible, a verification link will arrive shortly. Please check your inbox and junk folder.' ) );
    }

    public static function verification_notice() {
        return '<div class="nms-wrap"><h2>Verify your booking email</h2><p>Before showing private records, confirm that you can receive email at your account address. Existing venue-hirer accounts will then securely reset their password once to protect against earlier unverified claims. Your bookings are unchanged.</p><form id="mbs-verify-email-form" method="post" action="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"><input type="hidden" name="action" value="mbs_verify_email"><input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce( 'mbs_public_nonce' ) ) . '"><button class="nms-btn nms-btn-primary">Email verification link</button><p class="mbs-verification-status" role="status" aria-live="polite"></p></form></div>';
    }

    /** Confirmation POST avoids mail-security scanners consuming a GET link. */
    public function consume_link() {
        if ( empty( $_GET['mbs_mailproof'] ) ) return;
        nocache_headers(); header( 'Referrer-Policy: no-referrer' );
        $token = (string) wp_unslash( $_GET['mbs_mailproof'] );
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) wp_die( 'Invalid verification link.' );
        $key = 'mbs_mailproof_' . hash( 'sha256', $token );
        $value = get_option( $key );
        if ( ! is_array( $value ) || $value['expires'] < time() ) wp_die( 'This link expired. Please request a new link.' );
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
            wp_die( '<h2>Confirm email access</h2><p>Continue only if you requested access to your venue records.</p><form method="post"><input type="hidden" name="proof_nonce" value="' . esc_attr( wp_create_nonce( 'mbs_proof_' . $token ) ) . '"><button>Confirm email address</button></form>', 'Verify booking email', array( 'response' => 200 ) );
        }
        check_admin_referer( 'mbs_proof_' . $token, 'proof_nonce' );
        global $wpdb;
        // Atomic consumption: two browsers cannot redeem the same link.
        $consumed = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $key, maybe_serialize( $value ) ) );
        wp_cache_delete( $key, 'options' ); wp_cache_delete( 'notoptions', 'options' );
        if ( $consumed !== 1 ) wp_die( 'This link has already been used. Request another link.' );
        if ( $value['user'] ) {
            $user = get_userdata( $value['user'] );
            if ( ! $user || strcasecmp( $user->user_email, $value['email'] ) !== 0 ) wp_die( 'Account email changed. Request a new verification link.' );
            $hirer = in_array( MBS_Hirer_Portal::ROLE, (array) $user->roles, true );
            if ( $hirer ) {
                // Invalidate a password an unverified pre-registrant might know.
                wp_set_password( wp_generate_password( 40, true, true ), $user->ID );
                WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
            }
            update_user_meta( $user->ID, 'mbs_verified_email', hash( 'sha256', strtolower( $user->user_email ) ) );
            ( new MBS_Hirer_Portal() )->link_existing_bookings( $user->ID );
            if ( $hirer ) {
                $reset = get_password_reset_key( get_userdata( $user->ID ) );
                if ( is_wp_error( $reset ) ) wp_die( 'Email verified. Use Forgot Password to securely set your password.' );
                $url = network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $reset ) . '&login=' . rawurlencode( $user->user_login ), 'login' );
                wp_die( '<p>Email verified. Set your password now to protect against someone registering your address before you.</p><p><a href="' . esc_url( $url ) . '">Set your account password</a></p>', 'Email verified', array( 'response'=>200 ) );
            }
            wp_die( '<p>Email verified. Sign in using your normal account security checks.</p><p><a href="' . esc_url( wp_login_url( self::manage_url() ) ) . '">Sign in to manage bookings</a></p>', 'Email verified', array( 'response' => 200 ) );
        }
        $booking = MBS_Bookings::get( $value['ref'] );
        if ( ! $booking || strcasecmp( $booking->email, $value['email'] ) !== 0 ) wp_die( 'Booking contact changed. Request another link.' );
        $expires = time() + 900;
        $payload = $booking->ref . '|' . $expires;
        $grant = $payload . '|' . self::signature( $booking, $payload );
        setcookie( 'mbs_booking_grant', $grant, array( 'expires' => $expires, 'path' => '/', 'secure' => wp_parse_url(home_url(),PHP_URL_SCHEME)==='https', 'httponly' => true, 'samesite' => 'Lax' ) );
        wp_safe_redirect( add_query_arg( 'mbs_lookup_ref', $booking->ref, self::manage_url() ) ); exit;
    }

    public static function manage_url() {
        foreach ( array( 'mathlin_manage', 'mathlin_status', 'mathlin_portal' ) as $shortcode ) {
            $pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 's' => $shortcode, 'numberposts' => 1 ) );
            if ( $pages ) return get_permalink( $pages[0]->ID );
        }
        return home_url( '/' );
    }

    private static function signature( $booking, $payload ) {
        return hash_hmac( 'sha256', $payload . '|' . strtolower( $booking->email ) . '|' . $booking->modification_token, wp_salt( 'auth' ) );
    }

    public static function booking_access( $booking ) {
        if ( ! $booking ) return false;
        if ( current_user_can( 'mbs_manage_bookings' ) || current_user_can( 'manage_options' ) || self::owns_email( $booking->email ) ) return true;
        $parts = explode( '|', (string) ( $_COOKIE['mbs_booking_grant'] ?? '' ) );
        return count( $parts ) === 3 && $parts[0] === $booking->ref && ctype_digit( $parts[1] ) && (int) $parts[1] >= time() && (int) $parts[1] <= time() + 900 && hash_equals( self::signature( $booking, $parts[0] . '|' . $parts[1] ), $parts[2] );
    }

    public static function calendar_url( $booking ) {
        $payload = $booking->ref . '|' . ( time() + 900 );
        $args = array( 'calendar_token'=>$payload . '|' . self::signature( $booking, 'calendar|' . $payload ) );
        if ( is_user_logged_in() ) $args['_wpnonce'] = wp_create_nonce( 'wp_rest' );
        return add_query_arg( $args, rest_url( 'mathlin/v1/bookings/' . $booking->ref . '/ical' ) );
    }

    public static function calendar_access( $booking, $token ) {
        if ( self::booking_access( $booking ) ) return true;
        $parts = explode( '|', (string) $token );
        return $booking && count( $parts ) === 3 && $parts[0] === $booking->ref && ctype_digit( $parts[1] ) && (int) $parts[1] >= time() && (int) $parts[1] <= time() + 900 && hash_equals( self::signature( $booking, 'calendar|' . $parts[0] . '|' . $parts[1] ), $parts[2] );
    }
}
