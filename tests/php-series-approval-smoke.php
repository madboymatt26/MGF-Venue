<?php
$root = dirname( __DIR__ );
$series = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-series.php' );
$email  = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-email.php' );
$admin  = file_get_contents( $root . '/wp-plugin/mathlin-booking/admin/class-admin.php' );

$checks = 0;
function has_source( $source, $needle, $message ) {
    global $checks;
    $checks++;
    if ( strpos( $source, $needle ) === false ) {
        fwrite( STDERR, "FAIL: {$message}\nMissing: {$needle}\n" );
        exit( 1 );
    }
}

has_source( $series, "if ( \$series->status === 'confirmed' )", 'Confirmed retries are explicit no-ops.' );
has_source( $series, "status = 'pending' FOR UPDATE", 'Only pending occurrences are locked for approval.' );
has_source( $series, "WHERE series_id = %s AND status = 'pending'", 'Approval only changes pending occurrences.' );
has_source( $series, 'series_precondition_failed', 'Approval and cancellation enforce optimistic preconditions.' );
has_source( $series, 'version = version + 1', 'Real transitions advance the version.' );
has_source( $series, 'MBS_HomeAssistant::notify( $booking )', 'Changed occurrences retain the existing HA payload path.' );
has_source( $series, 'series_confirmed', 'Approval writes one series-level audit action.' );
has_source( $series, "\$scope === 'future'", 'Cancellation supports future-only scope.' );
if ( ! preg_match( '/\$eligible_sql\s*=\s*\$scope\s*===\s*[\'\"]future[\'\"]\s*\?\s*[\'\"]booking_date\s*>=\s*%s/s', $series ) ) {
    fwrite( STDERR, "FAIL: Future cancellation must constrain booking_date to today or later.\n" );
    exit( 1 );
}
$checks++;
has_source( $series, 'resend_confirmation', 'Approval email resend is explicit.' );
has_source( $admin, 'expected_status', 'Admin approval supplies expected status.' );
has_source( $admin, 'expected_version', 'Admin approval supplies expected version.' );

$email_start = strpos( $email, 'public static function notify_series_confirmed' );
$email_end = strpos( $email, 'public static function notify_invoice_reminder', $email_start );
$approval_email = substr( $email, $email_start, $email_end - $email_start );
if ( preg_match( '/generate_invoice|generate_payment_url|pay_url|Pay Now/i', $approval_email ) ) {
    fwrite( STDERR, "FAIL: Consolidated approval email must not create or advertise occurrence invoices/payment links.\n" );
    exit( 1 );
}
$checks++;

$notify_pos = strpos( $series, 'MBS_Email::notify_series_confirmed' );
$sent_pos = strpos( $series, "array( 'confirmation_sent_at'", $notify_pos );
if ( $notify_pos === false || $sent_pos === false || $sent_pos < $notify_pos ) {
    fwrite( STDERR, "FAIL: confirmation_sent_at must only be written after the send attempt.\n" );
    exit( 1 );
}
$checks++;

echo "OK: {$checks} series approval assertions passed.\n";
