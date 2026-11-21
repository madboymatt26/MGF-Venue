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
function apply_filters( $hook, $value ) { return $value; }

$GLOBALS['__options'] = array();
function get_option( $key, $default = false ) { return $GLOBALS['__options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['__options'][ $key ] = $value; return true; }

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

// 7. A wp-config constant bypasses storage entirely.
define( 'MBS_OSM_CLIENT_SECRET', 'constant-secret' );
$GLOBALS['__options'] = array( 'mbs_osm_client_secret' => 'stored-secret-that-should-be-ignored' );
osm_sec_assert( MBS_OSM_Integration::get_settings()['client_secret'] === 'constant-secret', 'wp-config constant did not take precedence.' );
osm_sec_assert( get_option( 'mbs_osm_client_secret' ) === 'stored-secret-that-should-be-ignored', 'Constant path must not rewrite stored options.' );

echo "OK: 7 OSM secret-storage groups passed (round-trip, legacy fallback, tamper/AAD fail-closed, token payloads, lazy migration, constant precedence).\n";
