<?php
/** Disposable WP/MariaDB only. Removes the venue browser fixture and restores
 * every option the seed changed, so later scenarios see their prior state. */
if ( ! defined('WP_CLI') || ! WP_CLI || ! in_array(parse_url(home_url(),PHP_URL_HOST),array('127.0.0.1','localhost','wordpress'),true) ) throw new RuntimeException('Disposable runtime required');
global $wpdb;
$checks = 0; $check = static function($ok,$why)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($why);};

$wpdb->delete( MBS_Payment_Expiry::table(), array( 'booking_ref' => 'VENUE-UI' ) );
$wpdb->delete( $wpdb->prefix . MBS_TABLE, array( 'ref' => 'VENUE-UI' ) );

$saved = get_option( 'mbs_venue_browser_saved', array() );
if ( is_array( $saved ) ) {
    foreach ( $saved as $opt => $value ) {
        if ( $value === '__MBS_UNSET__' ) delete_option( $opt );
        else update_option( $opt, $value, false );
    }
}
delete_option( 'mbs_venue_browser_saved' );

$check( MBS_Bookings::get( 'VENUE-UI' ) === null, 'Venue fixture removed' );
$check( MBS_Payment_Expiry::get( 'VENUE-UI' ) === null, 'Venue fixture policy removed' );
$check( get_option( 'mbs_payment_expiry_enabled', null ) !== '1' && get_option( 'mbs_payment_expiry_enabled', null ) !== 1, 'Payment expiry flag restored' );
$check( get_option( 'mbs_venue_browser_saved', null ) === null || get_option( 'mbs_venue_browser_saved', null ) === false, 'Saved-state marker removed' );
echo "VENUE_MODE_BROWSER_CLEANUP_OK: {$checks} checks; fixture removed, options restored\n";
