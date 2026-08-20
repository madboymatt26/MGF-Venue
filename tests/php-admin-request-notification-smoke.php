<?php
$root = dirname( __DIR__ );
$email = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-email.php' );
$modification = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-modification.php' );
$audit = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-audit-log.php' );

$checks = 0;
function admin_request_notification_has( $source, $needle, $message ) {
    global $checks;
    $checks++;
    if ( strpos( $source, $needle ) === false ) {
        fwrite( STDERR, "FAIL: {$message}\nMissing: {$needle}\n" );
        exit( 1 );
    }
}

admin_request_notification_has(
    $email,
    'public static function notification_emails()',
    'The shared administrator recipient list is available to request notifications.'
);
admin_request_notification_has(
    $email,
    "get_option( 'mbs_additional_emails', '' )",
    'The shared recipient list includes configured additional addresses.'
);
admin_request_notification_has(
    $modification,
    'foreach ( MBS_Email::notification_emails() as $email )',
    'Change and cancellation request alerts use every configured administrator recipient.'
);
admin_request_notification_has(
    $modification,
    "'admin_request_notification'",
    'Request notification delivery is written to the booking audit.'
);
admin_request_notification_has(
    $modification,
    '%d sent immediately, %d queued for retry.',
    'The audit distinguishes immediate delivery acceptance from queued retries.'
);
admin_request_notification_has(
    $audit,
    "'admin_request_notification' => '📧 Admin Request Notification'",
    'The new audit action has a readable label.'
);

if ( preg_match( '/MBS_Email_Queue::send\(\s*\$admin_email,\s*\$subject,\s*\$body/s', $modification ) ) {
    fwrite( STDERR, "FAIL: Request notifications still send only to the primary administrator address.\n" );
    exit( 1 );
}
$checks++;

echo "OK: {$checks} admin request notification assertions passed.\n";
