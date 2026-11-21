<?php
// Verifies OSM standalone credentials are authenticated-encrypted at rest with
// deterministic legacy-plaintext migration, matching the Google Calendar seal.
define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
    private $code; private $message; private $data;
    public function __construct( $code, $message, $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_salt( $scheme = 'auth' ) { return 'test-only-osm-salt-' . $scheme; }
function wp_json_encode( $value ) { return json_encode( $value ); }
$GLOBALS['__filters'] = array();
function apply_filters( $hook, $value ) { return isset( $GLOBALS['__filters'][ $hook ] ) ? call_user_func( $GLOBALS['__filters'][ $hook ], $value ) : $value; }
function add_test_filter( $hook, $cb ) { $GLOBALS['__filters'][ $hook ] = $cb; }
function wp_cache_delete( $key, $group = '' ) {}

$GLOBALS['__options'] = array();
function get_option( $key, $default = false ) { return $GLOBALS['__options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['__options'][ $key ] = $value; return true; }

// Minimal $wpdb stub whose conditional UPDATE honours the WHERE option_value clause.
class Osm_Sec_Test_WPDB {
    public $options = 'wp_options';
    public function prepare( $query, ...$args ) { $this->__args = $args; $this->__query = $query; return $query; }
    public function query( $query ) {
        $args = $this->__args;
        if ( ( $GLOBALS['__options']['mbs_osm_client_secret'] ?? null ) === $args[1] ) {
            $GLOBALS['__options']['mbs_osm_client_secret'] = $args[0];
            return 1;
        }
        return 0;
    }
}

require_once dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-osm-accounting-v2.php';

function osm_sec_assert( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}
function osm_sec_call( $method, ...$args ) {
    $ref = new ReflectionMethod( 'MBS_OSM_Integration', $method );
    if ( PHP_VERSION_ID < 80100 ) $ref->setAccessible( true );
    return $ref->invoke( null, ...$args );
}

// 1. seal/unseal round-trip and marker format.
$secret = 'osm-client-secret-fixture-0123456789';
$sealed = osm_sec_call( 'seal', $secret );
osm_sec_assert( is_string( $sealed ) && strpos( $sealed, 'gcm1:' ) === 0, 'Sealed secret lacks the gcm1 marker.' );
osm_sec_assert( strpos( $sealed, $secret ) === false, 'Sealed secret contains plaintext.' );
osm_sec_assert( osm_sec_call( 'unseal', $sealed ) === $secret, 'Sealed secret did not round-trip.' );
osm_sec_assert( osm_sec_call( 'read_secret', $sealed ) === $secret, 'read_secret did not unseal a sealed value.' );

// 2. Legacy plaintext values stay readable.
osm_sec_assert( osm_sec_call( 'read_secret', 'legacy-plain-secret' ) === 'legacy-plain-secret', 'Legacy plaintext secret was not readable.' );
osm_sec_assert( osm_sec_call( 'is_sealed', 'legacy-plain-secret' ) === false, 'Plaintext was misdetected as sealed.' );

// 3. Tampered or foreign-AAD ciphertext fails closed, never returning plaintext.
$tampered = 'gcm1:' . substr( base64_encode( random_bytes( 40 ) ), 0, 40 );
osm_sec_assert( osm_sec_call( 'unseal', $tampered ) === '', 'Tampered ciphertext did not fail closed.' );
$iv = random_bytes( 12 ); $tag = '';
$foreign = openssl_encrypt( $secret, 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, $iv, $tag, 'MGF Google Calendar' );
osm_sec_assert( osm_sec_call( 'unseal', 'gcm1:' . base64_encode( $iv . $tag . $foreign ) ) === '', 'Ciphertext sealed with a different AAD did not fail closed.' );

// 4. Sealed JSON token payloads round-trip; legacy array payloads pass through; corrupt sealed payloads fail closed.
$payload = array( 'access_token' => 'token-abc', 'expires_in' => 3600 );
$sealed_json = osm_sec_call( 'seal', wp_json_encode( $payload ) );
osm_sec_assert( osm_sec_call( 'token_from_value', $sealed_json ) === 'token-abc', 'Sealed token payload did not yield its access token.' );
osm_sec_assert( osm_sec_call( 'token_from_value', $payload ) === 'token-abc', 'Legacy array token payload was not readable.' );
osm_sec_assert( osm_sec_call( 'token_from_value', $tampered ) === '', 'Corrupt sealed token payload did not fail closed.' );

// 5. get_settings migrates a legacy plaintext client secret to sealed storage once.
$GLOBALS['__options'] = array( 'mbs_osm_client_secret' => 'legacy-secret-to-migrate' );
$settings = MBS_OSM_Integration::get_settings();
osm_sec_assert( $settings['client_secret'] === 'legacy-secret-to-migrate', 'get_settings lost the legacy secret during migration.' );
$stored = get_option( 'mbs_osm_client_secret', '' );
osm_sec_assert( is_string( $stored ) && strpos( $stored, 'gcm1:' ) === 0 && strpos( $stored, 'legacy-secret-to-migrate' ) === false, 'Legacy secret was not re-sealed in storage.' );
$again = MBS_OSM_Integration::get_settings();
osm_sec_assert( $again['client_secret'] === 'legacy-secret-to-migrate', 'Migrated sealed secret did not read back.' );

// 6. get_settings reads a pre-sealed secret without change.
$pre = osm_sec_call( 'seal', 'already-sealed-secret' );
$GLOBALS['__options'] = array( 'mbs_osm_client_secret' => $pre );
osm_sec_assert( MBS_OSM_Integration::get_settings()['client_secret'] === 'already-sealed-secret', 'Pre-sealed secret did not read back.' );
osm_sec_assert( get_option( 'mbs_osm_client_secret' ) === $pre, 'Pre-sealed secret was rewritten unexpectedly.' );

// 8. Migration uses a conditional update: a concurrent newer value is never overwritten by the stale one.
$GLOBALS['__filters'] = array();
$GLOBALS['wpdb'] = new Osm_Sec_Test_WPDB();
$GLOBALS['__options'] = array( 'mbs_osm_client_secret' => 'race-plain-secret' );
// Simulate a concurrent save landing between this request's read and its migration write.
$GLOBALS['__concurrent'] = true;
class Osm_Sec_Race_WPDB extends Osm_Sec_Test_WPDB {
    public function query( $query ) {
        if ( ! empty( $GLOBALS['__concurrent'] ) ) { $GLOBALS['__options']['mbs_osm_client_secret'] = 'gcm1:newer-concurrent-value'; $GLOBALS['__concurrent'] = false; }
        return parent::query( $query );
    }
}
$GLOBALS['wpdb'] = new Osm_Sec_Race_WPDB();
$settings = MBS_OSM_Integration::get_settings();
osm_sec_assert( get_option( 'mbs_osm_client_secret' ) === 'gcm1:newer-concurrent-value', 'Migration overwrote a concurrently saved newer secret.' );
$GLOBALS['wpdb'] = new Osm_Sec_Test_WPDB();
$GLOBALS['__options'] = array( 'mbs_osm_client_secret' => 'race-plain-secret-2' );
MBS_OSM_Integration::get_settings();
osm_sec_assert( strpos( (string) get_option( 'mbs_osm_client_secret' ), 'gcm1:' ) === 0, 'Conditional migration did not seal an unchanged legacy secret.' );
unset( $GLOBALS['wpdb'] );

// 9. Without OpenSSL: seal is a plaintext passthrough, migration is skipped, and an already-sealed install fails closed without fatal or plaintext.
add_test_filter( 'mbs_osm_seal_available', function( $v ) { return false; } );
osm_sec_assert( osm_sec_call( 'seal', 'no-openssl-secret' ) === 'no-openssl-secret', 'Without OpenSSL seal must pass plaintext through (previous behaviour).' );
osm_sec_assert( osm_sec_call( 'unseal', 'gcm1:whatever' ) === '', 'Without OpenSSL unseal must fail closed.' );
$GLOBALS['__options'] = array( 'mbs_osm_client_secret' => 'unmigrated-plain' );
$settings = MBS_OSM_Integration::get_settings();
osm_sec_assert( get_option( 'mbs_osm_client_secret' ) === 'unmigrated-plain', 'Migration must not run without OpenSSL.' );
osm_sec_assert( $settings['client_secret'] === 'unmigrated-plain', 'Plaintext secret must remain usable without OpenSSL.' );
$GLOBALS['__options'] = array( 'mbs_osm_client_secret' => $pre );
$settings = MBS_OSM_Integration::get_settings();
osm_sec_assert( $settings['client_secret'] === '', 'Sealed secret on an OpenSSL-less install must read as unconfigured, not garbage.' );
$GLOBALS['__filters'] = array();

// 7. A wp-config constant bypasses storage entirely. Runs last: constants cannot be undefined.
define( 'MBS_OSM_CLIENT_SECRET', 'constant-secret' );
$GLOBALS['__options'] = array( 'mbs_osm_client_secret' => 'stored-secret-that-should-be-ignored' );
osm_sec_assert( MBS_OSM_Integration::get_settings()['client_secret'] === 'constant-secret', 'wp-config constant did not take precedence.' );
osm_sec_assert( get_option( 'mbs_osm_client_secret' ) === 'stored-secret-that-should-be-ignored', 'Constant path must not rewrite stored options.' );

echo "OK: 9 OSM secret-storage groups passed (round-trip, legacy fallback, tamper/AAD fail-closed, token payloads, lazy migration, constant precedence, concurrent-save race, no-OpenSSL behaviour).\n";
