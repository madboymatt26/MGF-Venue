<?php
/**
 * Unit and smoke assertions for Phase 1 security (SEC-H1..SEC-M3) and P0/P1 performance fixes.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'MBS_PLUGIN_DIR', dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/' );
define( 'MBS_VERSION', '3.47.2' );
define( 'MBS_TABLE', 'mathlin_bookings' );
define( 'MBS_AUDIT_TABLE', 'mathlin_audit' );
define( 'MBS_OSM_OUTBOX_TABLE', 'mathlin_osm_outbox' );
define( 'MBS_OSM_PAYOUT_TABLE', 'mathlin_osm_payouts' );

$GLOBALS['mbs_test_options']    = array();
$GLOBALS['mbs_test_transients'] = array();
$GLOBALS['mbs_test_hooks']      = array();
$GLOBALS['mbs_test_upload_dir'] = sys_get_temp_dir() . '/mbs_phase1_test_' . bin2hex( random_bytes( 4 ) );

class WP_Error {
    public $code;
    public $message;
    public function __construct( $code = '', $message = '' ) {
        $this->code    = (string) $code;
        $this->message = (string) $message;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['mbs_test_options'] ) ? $GLOBALS['mbs_test_options'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['mbs_test_options'][ $k ] = $v; return true; }
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['mbs_test_transients'] ) ? $GLOBALS['mbs_test_transients'][ $k ] : false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['mbs_test_transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['mbs_test_transients'][ $k ] ); return true; }
function plugin_basename( $p ) { return 'mathlin-booking/mathlin-booking.php'; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function add_action( $tag, $cb ) { $GLOBALS['mbs_test_hooks'][] = array( 'action', $tag ); }
function add_filter( $tag, $cb ) { $GLOBALS['mbs_test_hooks'][] = array( 'filter', $tag ); }
function wp_get_scheduled_event( $h ) { return false; }
function wp_schedule_event( $t, $r, $h ) { return true; }
function wp_clear_scheduled_hook( $h ) { return true; }
function current_user_can( $cap ) { return true; }
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['mbs_test_upload_dir'] ); }
function wp_remote_get( $url, $args ) { return array( 'code' => 200, 'body' => json_encode( $GLOBALS['mbs_test_release'] ) ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function download_url( $url ) { return $GLOBALS['mbs_test_download'] ?? new WP_Error( 'download_failed', 'no fixture' ); }
function wp_delete_file( $file ) { if ( file_exists( $file ) ) unlink( $file ); }

class Phase1TestWpdb {
    public $prefix = 'wp_';
    public $queries = 0;
    public function get_var( $sql ) { $this->queries++; return 0; }
    public function query( $sql ) { $this->queries++; return 0; }
    public function prepare( $sql ) { return $sql; }
}
$wpdb = new Phase1TestWpdb();

require_once MBS_PLUGIN_DIR . 'includes/class-money.php';
require_once MBS_PLUGIN_DIR . 'includes/class-bookings.php';
require_once MBS_PLUGIN_DIR . 'includes/class-email.php';
require_once MBS_PLUGIN_DIR . 'includes/class-woo-payment.php';
require_once MBS_PLUGIN_DIR . 'includes/class-updater.php';
require_once MBS_PLUGIN_DIR . 'includes/class-osm-accounting-v2.php';
require_once MBS_PLUGIN_DIR . 'includes/class-legacy-read-only.php';

$checks = 0;
function p1_assert( $cond, $msg ) {
    global $checks;
    $checks++;
    if ( ! $cond ) {
        fwrite( STDERR, "FAIL: {$msg}\n" );
        exit( 1 );
    }
}

// 1. SEC-H1: Cryptographic booking ref generation + legacy HTML invoice cleanup
$refs = array();
for ( $i = 0; $i < 20; $i++ ) {
    $r = MBS_Bookings::generate_ref();
    p1_assert( preg_match( '/^MBS-[A-F0-9]{8}$/', $r ) === 1, "Generated ref format valid: {$r}" );
    $refs[ $r ] = true;
}
p1_assert( count( $refs ) === 20, '20 generated booking refs are unique.' );
$bookings_src = file_get_contents( MBS_PLUGIN_DIR . 'includes/class-bookings.php' );
p1_assert( strpos( $bookings_src, 'uniqid(' ) === false, 'uniqid() removed from class-bookings.php.' );

$inv_dir = $GLOBALS['mbs_test_upload_dir'] . '/mbs-invoices';
mkdir( $inv_dir, 0700, true );
file_put_contents( $inv_dir . '/invoice-MBS-TEST1.html', '<html>PII</html>' );
file_put_contents( $inv_dir . '/keep.txt', 'keep' );
$outside = $GLOBALS['mbs_test_upload_dir'] . '/outside.html';
file_put_contents( $outside, 'keep outside' );
symlink( $outside, $inv_dir . '/link.html' );
try {
    $deleted = MBS_Email::cleanup_legacy_invoice_files();
    p1_assert( $deleted === 1, 'cleanup_legacy_invoice_files() deleted exactly 1 static HTML file.' );
    p1_assert( ! file_exists( $inv_dir . '/invoice-MBS-TEST1.html' ), 'Legacy static HTML invoice removed.' );
    p1_assert( file_exists( $inv_dir . '/keep.txt' ), 'Non-HTML file preserved.' );
    p1_assert( file_exists( $outside ), 'Symlink target outside mbs-invoices preserved.' );
} finally {
    if ( is_link( $inv_dir . '/link.html' ) ) unlink( $inv_dir . '/link.html' );
    if ( file_exists( $inv_dir . '/keep.txt' ) ) unlink( $inv_dir . '/keep.txt' );
    if ( file_exists( $outside ) ) unlink( $outside );
    if ( is_dir( $inv_dir ) ) rmdir( $inv_dir );
    if ( is_dir( $GLOBALS['mbs_test_upload_dir'] ) ) rmdir( $GLOBALS['mbs_test_upload_dir'] );
}

// 2. SEC-H2: WooCommerce coupon exclusion for payment product + legacy checkout guards
class Phase1MockProduct {
    private $slug;
    public function __construct( $slug ) { $this->slug = $slug; }
    public function get_slug() { return $this->slug; }
}
$woo = new MBS_Woo_Payment();
p1_assert( $woo->exclude_payment_product_from_coupons( true, new Phase1MockProduct( 'mbs-booking-payment' ), null, array() ) === false, 'Coupons excluded for mbs-booking-payment product.' );
p1_assert( $woo->exclude_payment_product_from_coupons( true, new Phase1MockProduct( 'other-product' ), null, array( 'mbs_booking_ref' => 'MBS-12345678' ) ) === false, 'Coupons excluded for mbs_booking_ref cart item.' );
p1_assert( $woo->exclude_payment_product_from_coupons( true, new Phase1MockProduct( 'other-product' ), null, array( 'mbs_invoice_ref' => 'INV-2026-0001' ) ) === false, 'Coupons excluded for mbs_invoice_ref cart item.' );
p1_assert( $woo->exclude_payment_product_from_coupons( true, new Phase1MockProduct( 'other-product' ), null, array() ) === true, 'Coupons still allowed for unrelated shop products.' );

$woo_src = file_get_contents( MBS_PLUGIN_DIR . 'includes/class-woo-payment.php' );
p1_assert( strpos( $woo_src, 'woocommerce_coupon_is_valid_for_product' ) !== false, 'Coupon exclusion filter registered.' );
p1_assert( strpos( $woo_src, 'validate_captured_booking_order' ) !== false, 'Captured booking order validation wired into completion.' );
p1_assert( strpos( $woo_src, 'require_booking_checkout_intent' ) !== false, 'Legacy payment redirect requires POST confirmation + nonce.' );
p1_assert( strpos( $woo_src, 'wp_safe_redirect' ) !== false, 'Legacy payment redirect uses wp_safe_redirect.' );
p1_assert( file_exists( MBS_PLUGIN_DIR . 'public/views/booking-payment-confirm.php' ), 'booking-payment-confirm.php view exists.' );

// 3. SEC-H3: Series deletion capability + REST API admin_only_actions + transactional lock
$admin_src = file_get_contents( MBS_PLUGIN_DIR . 'admin/class-admin.php' );
$rest_src  = file_get_contents( MBS_PLUGIN_DIR . 'includes/class-rest-api.php' );
p1_assert( strpos( $rest_src, "'delete_archive_series'" ) !== false, 'delete_archive_series added to REST API admin-only actions.' );
p1_assert( preg_match( '/function ajax_delete_archive_series\(\).*?can_delete_bookings\(\)/s', $admin_src ) === 1, 'ajax_delete_archive_series enforces manage_options via can_delete_bookings().' );
p1_assert( strpos( $admin_src, 'MBS_Space_Selection::locked' ) !== false, 'Series deletion acquires occupancy lock.' );

// 4. SEC-M1: Purpose-scoped HMAC tokens + legacy fallback
$booking = (object) array( 'ref' => 'MBS-AABB1122', 'modification_token' => str_repeat( 'a', 64 ) );
$pay_tok = MBS_Bookings::scoped_token( $booking, 'pay' );
$mod_tok = MBS_Bookings::scoped_token( $booking, 'modify' );
$inv_tok = MBS_Bookings::scoped_token( $booking, 'invoice' );
p1_assert( strpos( $pay_tok, 'v1.' ) === 0, 'Scoped token uses v1 format.' );
p1_assert( $pay_tok !== $mod_tok && $pay_tok !== $inv_tok, 'Different purposes produce distinct tokens.' );
p1_assert( MBS_Bookings::verify_scoped_token( $booking, 'pay', $pay_tok ) === true, 'Pay token verifies for pay purpose.' );
p1_assert( MBS_Bookings::verify_scoped_token( $booking, 'modify', $pay_tok ) === false, 'Pay token rejected for modify purpose.' );
p1_assert( MBS_Bookings::verify_scoped_token( $booking, 'invoice', $mod_tok ) === false, 'Modify token rejected for invoice purpose.' );
p1_assert( MBS_Bookings::verify_scoped_token( $booking, 'pay', $booking->modification_token ) === true, 'Legacy raw modification_token accepted as backward-compatible fallback.' );
p1_assert( MBS_Bookings::verify_scoped_token( $booking, 'pay', 'invalid-token' ) === false, 'Invalid token rejected.' );
$past_exp    = time() - 60;
$expired_sig = hash_hmac( 'sha256', 'mbs_v1|pay|' . $booking->ref . '|' . $past_exp, $booking->modification_token . '|' . wp_salt( 'auth' ) );
$expired_tok = 'v1.' . $past_exp . '.' . $expired_sig;
p1_assert( MBS_Bookings::verify_scoped_token( $booking, 'pay', $expired_tok ) === false, 'Expired scoped token rejected.' );
$future_tok  = MBS_Bookings::scoped_token( $booking, 'pay', 300 );
p1_assert( MBS_Bookings::verify_scoped_token( $booking, 'pay', $future_tok ) === true, 'Non-expired TTL scoped token accepted.' );

// 5. SEC-M2: Updater rejects zipball_url / untrusted remote sources and omits zipball fallback
$GLOBALS['mbs_test_release'] = array(
    'tag_name'    => 'v3.48.0',
    'assets'      => array(),
    'zipball_url' => 'https://api.github.com/repos/madboymatt26/MGF-Venue/zipball/v3.48.0',
);
$updater = new MBS_Updater();
$args    = array( 'plugin' => 'mathlin-booking/mathlin-booking.php' );
$res     = $updater->verify_release_download( false, $GLOBALS['mbs_test_release']['zipball_url'], null, $args );
p1_assert( is_wp_error( $res ) && $res->get_error_code() === 'mgf_release_untrusted_source', 'Updater rejects zipball_url download.' );
$tr = (object) array( 'checked' => array( 'mathlin-booking/mathlin-booking.php' => '3.47.2' ), 'response' => array() );
$tr = $updater->check_update( $tr );
p1_assert( empty( $tr->response['mathlin-booking/mathlin-booking.php'] ), 'Updater does not advertise update without named release asset.' );

// 6. SEC-M3: DOM escaping checks
p1_assert( strpos( file_get_contents( MBS_PLUGIN_DIR . 'includes/class-feedback.php' ), 'form.innerHTML' ) === false, 'form.innerHTML removed from class-feedback.php.' );
p1_assert( strpos( file_get_contents( MBS_PLUGIN_DIR . 'public/public.js' ), '$ok.html(msg)' ) === false, '$ok.html(msg) removed from public.js.' );
p1_assert( strpos( file_get_contents( MBS_PLUGIN_DIR . 'public/views/manage.php' ), '$msg.html(' ) === false, '$msg.html() removed from manage.php.' );
p1_assert( strpos( file_get_contents( MBS_PLUGIN_DIR . 'public/views/booking-status.php' ), 'safeUrl(' ) !== false, 'booking-status.php validates dynamic URLs with safeUrl().' );

// 7. P0/P1 Performance: OSM cron + disabled short-circuit, admin menu cap gate, legacy read-only fast-path, migration gating
$GLOBALS['mbs_test_hooks'] = array();
$osm = new MBS_OSM_Integration();
$osm->init();
$init_hooks = array_filter( $GLOBALS['mbs_test_hooks'], static function ( $h ) { return $h[0] === 'action' && $h[1] === 'init'; } );
p1_assert( empty( $init_hooks ), 'OSM does not hook recover_stale_claims() onto init.' );
$wpdb->queries = 0;
MBS_OSM_Integration::outbox_health_notice();
p1_assert( $wpdb->queries === 0, 'OSM outbox_health_notice() issues 0 DB queries when OSM is disabled.' );

p1_assert( MBS_Legacy_Read_Only::is_legacy_write( 'SELECT * FROM wp_posts WHERE ID=1', 'wp_' ) === false, 'Legacy read-only fast-paths unrelated SELECT.' );
p1_assert( MBS_Legacy_Read_Only::is_legacy_write( 'UPDATE wp_posts SET post_status="publish" WHERE ID=1', 'wp_' ) === false, 'Legacy read-only fast-paths unrelated UPDATE.' );
p1_assert( MBS_Legacy_Read_Only::is_legacy_write( 'UPDATE wp_booking SET trash=1 WHERE booking_id=5', 'wp_' ) === true, 'Legacy read-only still blocks legacy wp_booking writes.' );

$main_src = file_get_contents( MBS_PLUGIN_DIR . 'mathlin-booking.php' );
p1_assert( strpos( $main_src, 'MBS_ENABLE_LEGACY_MIGRATION' ) !== false, 'Legacy migration runners gated behind MBS_ENABLE_LEGACY_MIGRATION / WP_CLI.' );
p1_assert( strpos( $main_src, 'mbs_legacy_campsite_redirect' ) !== false, 'Lightweight legacy redirect stub registered in mathlin-booking.php.' );

$pub_src = file_get_contents( MBS_PLUGIN_DIR . 'public/class-public.php' );
p1_assert( strpos( $pub_src, "'mathlin_terms'" ) !== false && strpos( $pub_src, "'mathlin_venue_info'" ) !== false, 'Public assets scoped for mathlin_terms and mathlin_venue_info.' );

echo "PHASE1_SECURITY_PERF_OK ({$checks} checks passed)\n";
