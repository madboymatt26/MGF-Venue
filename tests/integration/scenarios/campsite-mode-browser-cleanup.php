<?php
/** Restores venue mode after campsite-mode-browser acceptance and removes its
 * synthetic fixtures. Disposable runtime only. */
if ( ! defined('WP_CLI') || ! WP_CLI || ! in_array(parse_url(home_url(),PHP_URL_HOST),array('127.0.0.1','localhost','wordpress'),true) ) throw new RuntimeException('Disposable runtime required');
global $wpdb;
$checks = 0; $check = static function($ok,$why)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($why);};

update_option( 'mbs_campsite_mode', 0 );
update_option( 'mbs_ha_webhook_url', '' );
$wpdb->query( "DELETE FROM {$wpdb->prefix}" . MBS_TABLE . " WHERE ref LIKE 'CMPS-UI-%'" );

$check( ! MBS_Campsite_Policy::enabled(), 'Venue mode restored' );
$check( MBS_HomeAssistant::available() === true, 'HA integration available again in venue mode' );
( new MBS_HomeAssistant() )->init();
$check( wp_get_scheduled_event( MBS_HomeAssistant::HOOK ) !== false, 'Venue init reschedules the HA worker' );
$check( ! MBS_Bookings::get( 'CMPS-UI-FREE' ) && ! MBS_Bookings::get( 'CMPS-UI-MONEY' ), 'Campsite browser fixtures removed' );
echo "CAMPSITE_MODE_BROWSER_CLEANUP_OK: {$checks} checks; venue mode restored\n";
