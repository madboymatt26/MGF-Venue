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

if ( count( $handlers ) !== 36 ) {
    $errors[] = 'Expected 36 mapped admin actions; found ' . count( $handlers );
}

if ( $errors ) {
    fwrite( STDERR, implode( PHP_EOL, $errors ) . PHP_EOL );
    exit( 1 );
}

echo 'ADMIN_PARITY_SMOKE_OK: ' . count( $handlers ) . ' handlers' . PHP_EOL;
