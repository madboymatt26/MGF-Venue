<?php
$root = dirname( __DIR__ );
$portal = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-hirer-portal.php' );
$manage_view = file_get_contents( $root . '/wp-plugin/mathlin-booking/public/views/manage.php' );
$legacy_view = file_get_contents( $root . '/wp-plugin/mathlin-booking/public/views/hirer-login.php' );

$checks = 0;
function portal_login_has( $source, $needle, $message ) {
    global $checks;
    $checks++;
    if ( strpos( $source, $needle ) === false ) {
        fwrite( STDERR, "FAIL: {$message}\nMissing: {$needle}\n" );
        exit( 1 );
    }
}

portal_login_has( $portal, 'wp_signon(', 'Portal login uses the standard WordPress sign-in flow.' );
portal_login_has( $portal, "'user_login'    => \$login", 'Portal passes a username-or-email identity to WordPress.' );
portal_login_has( $portal, "\$_POST['login'] ?? \$_POST['email']", 'Older cached forms using the email field remain compatible.' );
portal_login_has( $portal, 'is_ssl()', 'Authentication cookie security follows the current request scheme.' );
portal_login_has( $manage_view, 'Email Address or Username', 'Manage Bookings labels the accepted account identifiers.' );
portal_login_has( $manage_view, 'name="login" autocomplete="username"', 'Manage Bookings submits the username-or-email field.' );
portal_login_has( $manage_view, "login:    \$('#nms-login-identity').val()", 'Manage Bookings JavaScript posts the new login field.' );
portal_login_has( $legacy_view, 'name="login" autocomplete="username"', 'The legacy hirer login stays consistent with Manage Bookings.' );

if ( strpos( $portal, '$user = wp_authenticate( $email, $pass )' ) !== false ) {
    fwrite( STDERR, "FAIL: Portal still uses the divergent email-only authentication path.\n" );
    exit( 1 );
}
$checks++;

echo "OK: {$checks} portal login compatibility assertions passed.\n";
