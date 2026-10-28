<?php
define( 'ABSPATH', __DIR__ );
$caps = array( 'mbs_manage_bookings' => true, 'manage_options' => true );
$paid = true;
function current_user_can( $cap ) { global $caps; return ! empty( $caps[$cap] ); }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return esc_html( $s ); }
function admin_url( $s ) { return 'https://example.invalid/wp-admin/' . $s; }
class MBS_Payment_Features { static function enabled( $f ) { global $paid; return $paid; } }
require __DIR__ . '/../wp-plugin/mathlin-booking/includes/class-admin-navigation.php';
$checks = 0;
function expect_nav( $ok, $message ) { global $checks; $checks++; if ( ! $ok ) throw new RuntimeException( $message ); }
function fixture_nav( $scouts = true ) {
    global $paid;
    $rows = array();
    foreach ( array( 'booking', 'series', 'scout-nights', 'calendar', 'archived', 'blocked', 'settings', 'emails', 'custom-fields', 'osm', 'analytics', 'requests', 'audit-log', 'add-booking', 'google-calendar', 'additional-charges', 'health', 'guide', 'extension' ) as $name ) {
        if ( ! $scouts && $name === 'scout-nights' || ! $paid && in_array( $name, array( 'osm', 'additional-charges' ), true ) ) continue;
        $cap = in_array( $name, array( 'settings', 'emails', 'custom-fields', 'osm', 'google-calendar', 'health' ), true ) ? 'manage_options' : 'mbs_manage_bookings';
        $label = $name === 'booking' ? 'All Bookings <span class="awaiting-mod">2</span>' : ( $name === 'requests' ? 'Requests <span class="awaiting-mod">3</span>' : $name );
        $rows[] = array( $label, $cap, 'mathlin-' . $name, 'Original page title' );
    }
    return $rows;
}
function visible_nav() { global $submenu; return array_values( array_filter( $submenu['mathlin-booking'], function( $row ) { return current_user_can( $row[1] ) && strpos( $row[4] ?? '', 'mbs-secondary-menu' ) === false; } ) ); }
function tab_html( $nav, $page ) { global $plugin_page; $plugin_page = $page; ob_start(); $nav->render_tabs(); return ob_get_clean(); }
$nav = new MBS_Admin_Navigation();
$submenu = array( 'mathlin-booking' => fixture_nav() ); $before = $submenu['mathlin-booking']; $nav->group_menu();
expect_nav( count( visible_nav() ) === 8, 'Seven core links plus unknown extension' );
$visible = visible_nav();
expect_nav( array_column( $visible, 2 ) === array( 'mathlin-booking', 'mathlin-add-booking', 'mathlin-calendar', 'mathlin-requests', 'mathlin-analytics', 'mathlin-settings', 'mathlin-guide', 'mathlin-extension' ), 'Independent Add booking second; useful ordering' );
expect_nav( strpos( $visible[0][0], 'Bookings <span' ) === 0 && strpos( $visible[3][0], '>3</span>' ) !== false, 'Pending badges retained' );
expect_nav( $visible[4][0] === 'Finance' && $visible[6][0] === 'Help & health', 'Paid labels' );
expect_nav( count( $before ) === count( $submenu['mathlin-booking'] ), 'No original route removed' );
foreach ( $before as $row ) { $after = array_values( array_filter( $submenu['mathlin-booking'], function( $r ) use ( $row ) { return $r[2] === $row[2]; } ) ); expect_nav( count( $after ) === 1 && $after[0][1] === $row[1] && $after[0][3] === $row[3], 'Original capability/title preserved: ' . $row[2] ); }
$html = tab_html( $nav, 'mathlin-google-calendar' );
expect_nav( strpos( $html, 'Google Calendar sync</a>' ) !== false && substr_count( $html, 'aria-current="page"' ) === 1, 'Accessible settings tabs/current page' );
expect_nav( $nav->active_menu( 'original' ) === 'mathlin-settings', 'Grouped active menu on old URL' );
expect_nav( strpos( $html, 'page=mathlin-emails' ) !== false && strpos( $html, 'nonce' ) === false, 'Direct existing GET links, no actions' );
expect_nav( tab_html( $nav, 'mathlin-add-booking' ) === '', 'Standalone Add booking has no misleading section tabs' );
expect_nav( tab_html( $nav, 'other-plugin' ) === '' && $nav->active_menu( 'original' ) === 'original', 'No navigation on another plugin' );
$caps['manage_options'] = false; $submenu['mathlin-booking'] = fixture_nav(); $nav->group_menu();
expect_nav( count( visible_nav() ) === 7, 'Manager has six core links plus extension' );
expect_nav( tab_html( $nav, 'mathlin-settings' ) === '', 'Manager cannot see admin settings navigation' );
expect_nav( strpos( tab_html( $nav, 'mathlin-guide' ), 'Integration health' ) === false, 'Manager cannot see health link' );
expect_nav( strpos( tab_html( $nav, 'mathlin-analytics' ), 'OSM integration' ) === false, 'Manager cannot see OSM' );
$caps['manage_options'] = true; $paid = false; $submenu['mathlin-booking'] = fixture_nav( false ); $nav->group_menu();
expect_nav( visible_nav()[4][0] === 'Reports', 'Free campsite retains usage reports' );
expect_nav( tab_html( $nav, 'mathlin-analytics' ) === '', 'Disabled financial links not reintroduced' );
expect_nav( strpos( tab_html( $nav, 'mathlin-booking' ), 'Scout nights' ) === false, 'Disabled Scout nights not reintroduced' );
expect_nav( strpos( tab_html( $nav, 'mathlin-settings' ), 'Google Calendar sync' ) !== false, 'Google settings available on free campsite' );
$caps = array(); $submenu['mathlin-booking'] = fixture_nav(); $nav->group_menu();
expect_nav( count( visible_nav() ) === 0 && tab_html( $nav, 'mathlin-booking' ) === '', 'No permissions grants no navigation' );
echo "ADMIN_NAVIGATION_OK: {$checks} assertions\n";
