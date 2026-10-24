<?php
define( 'ABSPATH', __DIR__ );
class WP_Error { public $code; public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['caps'][$cap] ); }
function get_current_user_id() { return 42; }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function get_option( $name, $default = false ) { return $GLOBALS['options'][$name] ?? $default; }
function add_option( $name, $value, $deprecated = '', $autoload = true ) {
    if ( isset( $GLOBALS['options'][$name] ) ) return false;
    $GLOBALS['options'][$name] = $value; $GLOBALS['autoload'] = $autoload; return true;
}
function maybe_serialize( $value ) { return serialize( $value ); }
function wp_cache_delete( $key, $group ) {}
function wp_generate_uuid4() { static $n = 0; return 'synthetic-' . ++$n; }
class MBS_Audit_Log { public static function log( $ref, $action, $details ) { $GLOBALS['audit'][] = compact( 'ref', 'action', 'details' ); return true; } }
class MBS_Payment_Features {
    public static function enabled( $feature ) { return ! empty( $GLOBALS['payments'] ); }
    public static function error( $feature ) { return new WP_Error( 'payments_disabled' ); }
}
class ExtrasFakeDb {
    public $options = 'wp_options'; public $fail = false; public $race = false;
    public function prepare( $sql, ...$args ) { return $args; }
    public function query( $args ) {
        if ( $this->fail ) return false;
        if ( $this->race ) return 0;
        list( $new, $name, $old ) = $args;
        if ( serialize( $GLOBALS['options'][$name] ) !== $old ) return 0;
        $GLOBALS['options'][$name] = unserialize( $new ); return 1;
    }
}
require __DIR__ . '/../wp-plugin/mathlin-booking/includes/class-money.php';
require __DIR__ . '/../wp-plugin/mathlin-booking/includes/class-extras-catalogue.php';
$checks = 0;
function extra_ok( $value, $message ) { global $checks; ++$checks; if ( ! $value ) throw new RuntimeException( $message ); }
$wpdb = new ExtrasFakeDb();
$GLOBALS['caps'] = array( 'manage_options' => true ); $GLOBALS['payments'] = true;
$item = array( 'revision' => 0, 'operation' => 'save', 'description' => 'Additional cleaning', 'unit' => 'visit', 'price' => '25.00', 'category' => 'cleaning', 'account_code' => '4000', 'tax_code' => '' );
extra_ok( ! MBS_Extras_Catalogue::usable(), 'Default is off even on a paid venue.' );
foreach ( array( true, 25.0, '1e5', '0', '-1.00', '25.001', '1000000.01', str_repeat( '9', 300 ), array() ) as $price ) extra_ok( is_wp_error( MBS_Extras_Catalogue::validate_item( array_merge( $item, array( 'price' => $price ) ) ) ), 'Reject malformed/overflow unit price.' );
foreach ( array( 'description' => '', 'unit' => str_repeat( 'x', 41 ), 'account_code' => '=CMD()', 'tax_code' => '<script>', 'category' => 'hire' ) as $key => $bad ) extra_ok( is_wp_error( MBS_Extras_Catalogue::validate_item( array_merge( $item, array( $key => $bad ) ) ) ), 'Reject invalid catalogue field.' );
$state = MBS_Extras_Catalogue::save( $item );
extra_ok( ! is_wp_error( $state ) && $state['revision'] === 1 && count( $state['items'] ) === 1, 'Create one retained item.' );
extra_ok( $GLOBALS['autoload'] === false, 'Do not autoload the whole catalogue on every page.' );
$id = key( $state['items'] );
extra_ok( is_wp_error( MBS_Extras_Catalogue::save( $item ) ), 'Stale tab cannot overwrite item.' );
extra_ok( is_wp_error( MBS_Extras_Catalogue::quote( $id, 1, 1 ) ), 'Disabled catalogue cannot quote.' );
$state = MBS_Extras_Catalogue::save( array( 'revision' => 1, 'operation' => 'enable', 'enabled' => true ) );
extra_ok( MBS_Extras_Catalogue::usable(), 'Explicitly enabled with parent feature.' );
foreach ( array( 0, -1, 1.5, true, '1e2', '1.0', '10000', array() ) as $quantity ) extra_ok( is_wp_error( MBS_Extras_Catalogue::quote( $id, 1, $quantity ) ), 'Whole bounded quantity only.' );
extra_ok( is_wp_error( MBS_Extras_Catalogue::quote( $id, true, 1 ) ), 'Boolean version is not an integer version.' );
extra_ok( is_wp_error( MBS_Extras_Catalogue::save( array( 'revision' => array(), 'operation' => 'enable', 'enabled' => true ) ) ), 'Reject nested version without a PHP warning.' );
$quote = MBS_Extras_Catalogue::quote( $id, 1, '3' );
extra_ok( $quote['amount'] === '75.00' && $quote['snapshot']['quantity'] === 3 && $quote['snapshot']['unit_amount_minor'] === 2500, 'Exact quantity and unit price snapshot.' );
extra_ok( $quote['snapshot']['account_code'] === '4000', 'Accounting mapping is snapshotted, not posted.' );
$old_quote = $quote;
$state = MBS_Extras_Catalogue::save( array_merge( $item, array( 'id' => $id, 'revision' => 2, 'price' => '30.00' ) ) );
extra_ok( $state['items'][$id]['version'] === 2, 'Item edit advances its version.' );
extra_ok( is_wp_error( MBS_Extras_Catalogue::quote( $id, 1, 3 ) ), 'Old selected price requires reconfirmation.' );
extra_ok( $old_quote['amount'] === '75.00', 'Catalogue edit cannot rewrite already captured snapshots.' );
extra_ok( MBS_Extras_Catalogue::quote( $id, 2, 3 )['amount'] === '90.00', 'New quote uses the new price.' );
$GLOBALS['payments'] = false;
extra_ok( ! MBS_Extras_Catalogue::usable() && is_wp_error( MBS_Extras_Catalogue::quote( $id, 2, 3 ) ), 'Free-site parent gate suppresses quote even if optional flag remains on.' );
extra_ok( is_wp_error( MBS_Extras_Catalogue::save( array( 'revision' => 3, 'operation' => 'enable', 'enabled' => true ) ) ), 'Cannot enable financial module with parent payments disabled.' );
$GLOBALS['payments'] = true;
$GLOBALS['caps'] = array( 'mbs_manage_bookings' => true );
extra_ok( is_wp_error( MBS_Extras_Catalogue::save( array_merge( $item, array( 'revision' => 3 ) ) ) ), 'Booking manager cannot change pricing configuration.' );
extra_ok( ! is_wp_error( MBS_Extras_Catalogue::quote( $id, 2, 2 ) ), 'Booking manager can use an approved catalogue.' );
$GLOBALS['caps'] = array( 'manage_options' => true );
$wpdb->race = true;
extra_ok( is_wp_error( MBS_Extras_Catalogue::save( array( 'revision' => 3, 'operation' => 'archive', 'id' => $id ) ) ), 'Persisted compare-and-swap rejects a concurrent edit after read.' );
extra_ok( ! MBS_Extras_Catalogue::state()['items'][$id]['archived'], 'Failed CAS makes no configuration change.' );
$wpdb->race = false; $wpdb->fail = true;
extra_ok( is_wp_error( MBS_Extras_Catalogue::save( array( 'revision' => 3, 'operation' => 'archive', 'id' => $id ) ) ), 'Database write failure is not success.' );
$wpdb->fail = false;
$state = MBS_Extras_Catalogue::save( array( 'revision' => 3, 'operation' => 'archive', 'id' => $id ) );
extra_ok( isset( $state['items'][$id] ) && $state['items'][$id]['archived'], 'Archive retains the stable item identity.' );
extra_ok( is_wp_error( MBS_Extras_Catalogue::quote( $id, 3, 1 ) ), 'Archived item cannot create a charge.' );
$state = MBS_Extras_Catalogue::save( array( 'revision' => 4, 'operation' => 'restore', 'id' => $id ) );
extra_ok( ! is_wp_error( MBS_Extras_Catalogue::quote( $id, 4, 1 ) ), 'Restore requires the new item version.' );
extra_ok( count( $GLOBALS['audit'] ) === 5, 'Only successful mutations create action audit records.' );
echo "EXTRAS_CATALOGUE_OK ($checks checks)\n";
