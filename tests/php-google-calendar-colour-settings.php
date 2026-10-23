<?php
require __DIR__ . '/php-google-calendar-worker.php';
class ColourFixtureRedirect extends RuntimeException {}
$colour_admin = true; $colour_nonce = true; $colour_transients = array();
function current_user_can( $cap ) { global $colour_admin; return $colour_admin; }
function wp_die( $message, ...$rest ) { throw new RuntimeException( $message ); }
function check_admin_referer( $action ) { global $colour_nonce; if ( ! $colour_nonce ) throw new RuntimeException( 'Bad nonce' ); }
function get_current_user_id() { return 7; }
function set_transient( $key, $value, $ttl ) { global $colour_transients; $colour_transients[$key] = $value; }
function delete_transient( $key ) { global $colour_transients; unset( $colour_transients[$key] ); }
function wp_unslash( $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function wp_safe_redirect( $url ) { throw new ColourFixtureRedirect( $url ); }
function colour_save( $fields ) {
    $_POST = array( 'operation' => 'colours', 'colours' => $fields );
    try { ( new MBS_Google_Calendar() )->handle(); } catch ( ColourFixtureRedirect $e ) { return; }
}
ready(); $options['mbs_google_calendar']['enabled'] = true;
$options['mbs_google_calendar']['choices'] = array( 'old' => 'protect:fixture' );
$options['mbs_google_calendar']['links'] = array( 'keep' => array( 'id' => 'existing' ) );
$colour_transients['mbs_gcal_preview_7'] = array( 'old snapshot' );
colour_save( array( 'enabled' => '1', 'estate' => '10', 'scout' => '9', 'estate_groups' => "HP Business\nEstate" ) );
$saved = $options['mbs_google_calendar'];
check( $saved['colours']['enabled'] && $saved['colours']['estate'] === '10' && $saved['colours']['scout'] === '9', 'real settings handler saves palette IDs' );
check( $saved['enabled'], 'cosmetic preferences preserve enabled sync' );
check( ! isset( $colour_transients['mbs_gcal_preview_7'] ), 'operator preview invalidated' );
check( $saved['links'] === array( 'keep' => array( 'id' => 'existing' ) ), 'existing protected links preserved' );
check( $saved['refresh'] === 'fixture' && $saved['calendar'] === 'target', 'connection and target preserved' );
check( count( $requests ) === 0 && ! isset( $options['mbs_gcal_lock'] ), 'no Google request and lock released' );
$before = $options;
colour_save( array( 'enabled' => '1', 'estate' => 'invalid', 'scout' => '9' ) );
check( $options === $before, 'invalid ID does not change settings' );
$colour_admin = false;
try { colour_save( array() ); check( false, 'non administrator' ); } catch ( RuntimeException $e ) { check( $options === $before, 'administrator required' ); }
$colour_admin = true; $colour_nonce = false;
try { colour_save( array() ); check( false, 'bad nonce' ); } catch ( RuntimeException $e ) { check( $options === $before, 'nonce required' ); }
$colour_nonce = true;
colour_save( array( 'estate' => '', 'scout' => '' ) );
check( ! $options['mbs_google_calendar']['colours']['enabled'], 'unchecked checkbox disables rule' );
echo "Google colour settings: $assertions total assertions passed.\n";
