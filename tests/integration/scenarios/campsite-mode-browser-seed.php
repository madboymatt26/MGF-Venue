<?php
/** Disposable WP/MariaDB only. Enables campsite mode and seeds synthetic
 * browser fixtures; campsite-mode-browser-cleanup.php restores venue mode. */
if ( ! defined('WP_CLI') || ! WP_CLI || ! in_array(parse_url(home_url(),PHP_URL_HOST),array('127.0.0.1','localhost','wordpress'),true) ) throw new RuntimeException('Disposable runtime required');
global $wpdb;
$table = $wpdb->prefix . MBS_TABLE;
$checks = 0; $check = static function($ok,$why)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($why);};

update_option( 'mbs_campsite_mode', 1 );
// A configured webhook proves the campsite gate, not missing configuration,
// is what stops Home Assistant work.
update_option( 'mbs_ha_webhook_url', 'https://ha.example.invalid/campsite-browser' );

$check( MBS_Campsite_Policy::enabled(), 'Campsite mode active for browser fixtures' );
$check( MBS_HomeAssistant::available() === false, 'HA integration unavailable in campsite mode' );
( new MBS_HomeAssistant() )->init();
$check( wp_get_scheduled_event( MBS_HomeAssistant::HOOK ) === false, 'Campsite init leaves no HA worker scheduled' );
$check( MBS_HomeAssistant::notify( (object) array( 'ref' => 'CMPS-UI-FREE', 'scout_use' => 1, 'series_id' => '', 'custom_fields' => '{}' ) ) === false, 'Campsite notify queues nothing' );
MBS_HomeAssistant::process();
$check( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'mbs_ha_event_%'" ) === '0', 'Campsite worker stores no intents' );
$retry = MBS_HomeAssistant::retry_failed( 'CMPS-UI-FREE', 'any-version' );
$check( is_wp_error( $retry ) && $retry->get_error_code() === 'ha_campsite_disabled', 'Campsite HA retry refused' );

foreach ( array(
    'FREE'  => array( 'scout_use' => 1, 'amount' => '0.00' ),
    'MONEY' => array( 'scout_use' => 0, 'amount' => '30.00' ),
) as $kind => $scope ) {
    $ref = 'CMPS-UI-' . $kind;
    if ( ! MBS_Bookings::get( $ref ) ) {
        $wpdb->insert( $table, array(
            'ref' => $ref, 'status' => 'confirmed', 'name' => 'Synthetic campsite UI', 'email' => 'campsite-ui@example.invalid',
            'space' => 'Main Scout Hall', 'booking_date' => '2098-11-13', 'booking_date_end' => '2098-11-13',
            'start_time' => '18:00:00', 'end_time' => '20:00:00', 'scout_use' => $scope['scout_use'], 'series_id' => '',
            'all_day' => 0, 'amount' => $scope['amount'], 'amount_paid' => '0.00', 'deposit_paid' => '0.00', 'custom_fields' => '{}',
        ) );
    }
    $check( (bool) MBS_Bookings::get( $ref ), 'Seeded ' . $ref );
}
echo "CAMPSITE_MODE_BROWSER_SEED_OK: {$checks} checks; campsite mode enabled, HA inert, fixtures ready\n";
