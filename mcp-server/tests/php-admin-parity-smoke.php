<?php
/**
 * Static smoke test for the MCP admin action allow-list.
 *
 * This intentionally runs without WordPress. It verifies that every mapped
 * class and method exists in the packaged plugin, catching renamed or missing
 * AJAX handlers before release.
 */

define( 'ABSPATH', __DIR__ . '/' );

$plugin = dirname( __DIR__, 2 ) . '/wp-plugin/mathlin-booking';

require_once $plugin . '/includes/class-rest-api.php';
require_once $plugin . '/admin/class-admin.php';
require_once $plugin . '/includes/class-osm-integration.php';
require_once $plugin . '/includes/class-csv-export.php';
require_once $plugin . '/includes/class-accounting-export.php';
require_once $plugin . '/includes/class-bookings.php';

$api        = new MBS_Rest_API();
$reflection = new ReflectionMethod( $api, 'get_admin_action_handlers' );
if ( PHP_VERSION_ID < 80100 ) {
    $reflection->setAccessible( true );
}
$handlers = $reflection->invoke( $api );

$errors = array();
foreach ( $handlers as $action => $handler ) {
    list( $class_name, $method_name ) = $handler;
    if ( ! class_exists( $class_name ) ) {
        $errors[] = $action . ': missing class ' . $class_name;
    } elseif ( ! method_exists( $class_name, $method_name ) ) {
        $errors[] = $action . ': missing method ' . $class_name . '::' . $method_name;
    }
}

if ( count( $handlers ) !== 45 ) {
    $errors[] = 'Expected 45 mapped admin actions; found ' . count( $handlers );
}

// Every privileged AJAX hook in the admin surfaces must remain represented in
// the closed REST allow-list. This catches future actions without relying on a
// brittle expected count alone.
$hook_files = array(
    $plugin . '/admin/class-admin.php',
    $plugin . '/includes/class-osm-integration.php',
    $plugin . '/includes/class-csv-export.php',
    $plugin . '/includes/class-accounting-export.php',
);
$hook_actions = array();
foreach ( $hook_files as $hook_file ) {
    $source = file_get_contents( $hook_file );
    preg_match_all( "/wp_ajax_mbs_([a-z_]+)/", $source, $matches );
    $hook_actions = array_merge( $hook_actions, $matches[1] );
}
$hook_actions = array_values( array_unique( $hook_actions ) );
sort( $hook_actions );
$mapped_actions = array_keys( $handlers );
sort( $mapped_actions );
foreach ( array_diff( $hook_actions, $mapped_actions ) as $missing_action ) {
    $errors[] = 'Privileged AJAX action missing from REST allow-list: ' . $missing_action;
}

foreach ( array( 'create_admin_booking', 'get_admin_global_audit', 'get_admin_dashboard' ) as $method_name ) {
    if ( ! method_exists( 'MBS_Rest_API', $method_name ) ) {
        $errors[] = 'Missing typed REST method MBS_Rest_API::' . $method_name;
    }
}

$create_method = new ReflectionMethod( 'MBS_Bookings', 'create' );
if ( $create_method->getNumberOfParameters() < 2 ) {
    $errors[] = 'MBS_Bookings::create must retain the explicit trusted-admin context parameter.';
}

if ( $errors ) {
    fwrite( STDERR, implode( PHP_EOL, $errors ) . PHP_EOL );
    exit( 1 );
}

echo 'ADMIN_PARITY_SMOKE_OK: ' . count( $handlers ) . ' handlers, ' . count( $hook_actions ) . ' privileged hooks' . PHP_EOL;
