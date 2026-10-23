<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Keep the original Booking Calendar interface active, protecting its records. */
class MBS_Legacy_Read_Only {
    private static $enabled = false;
    private static $prefix = '';
    public static function init() {
        global $wpdb;
        self::$enabled = MBS_Campsite_Policy::enabled() && wp_parse_url( home_url(), PHP_URL_HOST ) === 'stowmarketscouts.org.uk';
        self::$prefix = $wpdb->prefix;
        if ( ! self::$enabled ) return;
        add_filter( 'query', array( __CLASS__, 'guard_query' ), 999 );
        add_filter( 'pre_wp_mail', array( __CLASS__, 'guard_mail' ), 999, 2 );
        add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
        add_action( 'admin_init', array( __CLASS__, 'guard_admin_post' ), 1 );
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
    }
    public static function enabled() { return self::$enabled; }
    public static function is_legacy_write( $sql, $prefix ) {
        // Strip SQL string values so audit text mentioning a table is not mistaken
        // for a mutation of that table. Preserve quoted identifiers (backticks).
        $sql = preg_replace( "~'(?:''|\\\\.|[^'\\\\])*'~s", "''", $sql );
        $sql = preg_replace( '~/\*.*?\*/|--[^\r\n]*~s', ' ', $sql );
        if ( preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql ) ) return false;
        return (bool) preg_match( '/(?<![a-z0-9_])' . preg_quote( $prefix, '/' ) . 'booking[a-z0-9_]*(?![a-z0-9_])/i', $sql );
    }
    public static function guard_query( $sql ) {
        // wpdb::query() returns false for an empty filtered query. Fail closed
        // for the legacy mutation without terminating the surrounding WordPress
        // request: activation/deactivation and background schema checks may run
        // on unrelated admin pages, not just inside the legacy booking screen.
        if ( self::$enabled && self::is_legacy_write( $sql, self::$prefix ) ) return '';
        return $sql;
    }
    public static function deny() {
        $message = 'The original Booking Calendar is read-only for comparison. Use MGF Venue to create, approve, edit or cancel bookings.';
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) wp_send_json_error( array( 'message' => $message ), 409 );
        wp_die( esc_html( $message ), 'Booking Calendar — read only', array( 'response' => 409 ) );
    }
    public static function guard_admin_post() {
        $page = sanitize_key( $_REQUEST['page'] ?? '' );
        if ( strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'GET' && strpos( $page, 'wpbc' ) === 0 ) self::deny();
    }
    public static function legacy_stack( $trace ) {
        foreach ( $trace as $frame ) if ( ! empty( $frame['file'] ) && preg_match( '#/plugins/(?:booking|booking\.personal\.10\.8)/#i', str_replace( '\\', '/', $frame['file'] ) ) ) return true;
        return false;
    }
    public static function guard_mail( $return, $atts ) {
        // Do not let the reactivated reference system send duplicate confirmations
        // or scheduled messages. MGF and every other plugin retain their mail path.
        return self::legacy_stack( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) ) ? false : $return;
    }
    public static function notice() {
        if ( strpos( sanitize_key( $_GET['page'] ?? '' ), 'wpbc' ) !== 0 ) return;
        echo '<div class="notice notice-warning"><p><strong>Original Booking Calendar — read-only comparison.</strong> Existing booking data is protected and its emails are suppressed. Use MGF Venue for all live bookings. The two systems are not synchronised.</p></div>';
    }
    public static function allowed() { return self::$enabled && current_user_can( 'manage_options' ); }
    public static function routes() {
        register_rest_route( 'mathlin/v1', '/admin/legacy-plugin', array(
            array( 'methods'=>'GET', 'permission_callback'=>array(__CLASS__,'allowed'), 'callback'=>array(__CLASS__,'status') ),
            array( 'methods'=>'POST', 'permission_callback'=>array(__CLASS__,'allowed'), 'callback'=>array(__CLASS__,'activate') ),
        ) );
    }
    public static function files() { return array( 'booking/wpdev-booking.php', 'booking.personal.10.8/wpdev-booking.php' ); }
    public static function status() {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $installed = get_plugins(); $plugins = array();
        foreach ( self::files() as $file ) $plugins[] = array( 'file'=>$file, 'installed'=>isset($installed[$file]), 'active'=>is_plugin_active($file) );
        return rest_ensure_response( array( 'read_only'=>self::$enabled, 'original_plugins'=>$plugins, 'original_admin_url'=>admin_url('admin.php?page=wpbc') ) );
    }
    public static function activate( $request ) {
        if ( $request->get_param('confirm_original_read_only') !== true ) return new WP_Error('confirmation_required','Explicit original-plugin read-only confirmation required.',array('status'=>400));
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $installed = get_plugins();
        foreach ( self::files() as $file ) if ( ! isset($installed[$file]) ) return new WP_Error('original_plugin_missing','An original Booking Calendar plugin is missing; nothing was activated.',array('status'=>409));
        // Reactivate the exact already-installed files without rerunning installer
        // hooks over the preserved database. The read-only guards are already on.
        foreach ( self::files() as $file ) { $result=activate_plugin($file,'',false,true); if(is_wp_error($result))return $result; }
        return self::status();
    }
}
