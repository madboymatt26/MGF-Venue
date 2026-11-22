<?php
/** Disposable WP/MariaDB only. Venue mode with payments and a configured HA
 * webhook: seeds a synthetic policy-controlled booking so the browser test can
 * prove billing chrome, payment actions, heating card and the staff invoice
 * preview's policy deadline all render in venue mode. */
if ( ! defined('WP_CLI') || ! WP_CLI || ! in_array(parse_url(home_url(),PHP_URL_HOST),array('127.0.0.1','localhost','wordpress'),true) ) throw new RuntimeException('Disposable runtime required');
global $wpdb;
$table = $wpdb->prefix . MBS_TABLE;
$checks = 0; $check = static function($ok,$why)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($why);};

update_option( 'mbs_campsite_mode', 0 );
update_option( 'mbs_payments_enabled', 1 );
update_option( 'mbs_invoices_enabled', 1 );
update_option( 'mbs_deposit_enabled', 1 );
update_option( 'mbs_deposit_percentage', 25 );
update_option( 'mbs_payment_expiry_enabled', 1 );
update_option( 'timezone_string', 'Europe/London' );
update_option( 'mbs_ha_webhook_url', 'https://ha.example.invalid/venue-browser' );

$check( ! MBS_Campsite_Policy::enabled(), 'Venue mode active for browser fixtures' );
$check( MBS_Payment_Features::enabled(), 'Payment features on in venue mode' );
$check( MBS_HomeAssistant::available() === true, 'HA integration available in venue mode' );
( new MBS_HomeAssistant() )->init();
$check( wp_get_scheduled_event( MBS_HomeAssistant::HOOK ) !== false, 'Venue init schedules the HA worker' );

$ref = 'VENUE-UI';
if ( ! MBS_Bookings::get( $ref ) ) {
    $wpdb->insert( $table, array(
        'ref' => $ref, 'status' => 'confirmed', 'name' => 'Synthetic venue UI', 'email' => 'venue-ui@example.invalid',
        'space' => 'Main Scout Hall', 'booking_date' => '2098-11-13', 'booking_date_end' => '2098-11-13',
        'start_time' => '18:00:00', 'end_time' => '20:00:00', 'scout_use' => 0, 'series_id' => '',
        'all_day' => 0, 'amount' => '30.00', 'amount_paid' => '0.00', 'deposit_paid' => '0.00', 'custom_fields' => '{}',
    ) );
}
$booking = MBS_Bookings::get( $ref );
$check( (bool) $booking, 'Seeded VENUE-UI' );
$check( MBS_Payment_Features::booking_billing_visible( $booking ) === true, 'Billing chrome visible for venue booking' );

// Enroll a deterministic payment policy so the staff invoice preview must
// print the enforced deposit deadline, not legacy terms.
if ( ! MBS_Payment_Expiry::get( $ref ) ) {
    $approved = ( new DateTimeImmutable( '2026-11-20 12:00:00', new DateTimeZone( 'Europe/London' ) ) )->getTimestamp();
    $policy = MBS_Payment_Expiry_Policy::approve( $approved, '2098-11-13', 3000, 750, true );
    $check( is_array( $policy ), 'Policy enrols for the venue fixture' );
    $ok = $wpdb->insert( MBS_Payment_Expiry::table(), array(
        'booking_ref' => $ref, 'state' => 'active', 'policy_json' => wp_json_encode( $policy ),
        'review_json' => wp_json_encode( array() ), 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
    ) );
    $check( $ok === 1, 'Policy row recorded' );
}
$policy = json_decode( MBS_Payment_Expiry::get( $ref )->policy_json, true );
$expected = array(
    'deposit_deadline' => MBS_Payment_Expiry_Policy::format_deadline( $policy['deposit_due_at'], $policy['timezone'] ),
    'balance_cutoff'   => MBS_Payment_Expiry_Policy::format_deadline( $policy['balance_cutoff_at'], $policy['timezone'] ),
);

// Server-side proof before the browser leg: the preview carries the deadline.
$preview = MBS_Invoice::generate_html( $booking );
$check( strpos( $preview, $expected['deposit_deadline'] ) !== false, 'Staff invoice preview prints the enforced deposit deadline' );
$check( strpos( $preview, 'Deposit due immediately' ) === false, 'Preview has no legacy immediate-deposit wording' );

if ( ! is_dir( '/workspace/browser-artifacts' ) ) mkdir( '/workspace/browser-artifacts', 0777, true );
file_put_contents( '/workspace/browser-artifacts/venue-expected.json', wp_json_encode( $expected ) );
echo "VENUE_MODE_BROWSER_SEED_OK: {$checks} checks; venue mode, HA scheduled, policy fixture ready\n";
