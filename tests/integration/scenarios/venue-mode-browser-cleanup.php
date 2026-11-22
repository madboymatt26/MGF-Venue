<?php
/** Disposable WP/MariaDB only. Removes the venue browser fixture so later
 * scenarios see no seeded state; restores the neutral HA configuration. */
if ( ! defined('WP_CLI') || ! WP_CLI || ! in_array(parse_url(home_url(),PHP_URL_HOST),array('127.0.0.1','localhost','wordpress'),true) ) throw new RuntimeException('Disposable runtime required');
global $wpdb;
$checks = 0; $check = static function($ok,$why)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($why);};

$wpdb->delete( MBS_Payment_Expiry::table(), array( 'booking_ref' => 'VENUE-UI' ) );
$wpdb->delete( $wpdb->prefix . MBS_TABLE, array( 'ref' => 'VENUE-UI' ) );
update_option( 'mbs_ha_webhook_url', '' );

$check( MBS_Bookings::get( 'VENUE-UI' ) === null, 'Venue fixture removed' );
$check( MBS_Payment_Expiry::get( 'VENUE-UI' ) === null, 'Venue fixture policy removed' );
$check( MBS_Campsite_Policy::enabled() === false, 'Venue mode retained after browser acceptance' );
echo "VENUE_MODE_BROWSER_CLEANUP_OK: {$checks} checks; fixture removed\n";
