<?php
/** Real WordPress routing/capability acceptance; no booking or option writes. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( strtolower( (string) parse_url( home_url(), PHP_URL_HOST ) ), array( '127.0.0.1', 'localhost', 'wordpress' ), true ) ) throw new RuntimeException( 'Disposable runtime required.' );
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$keys = array( 'menu', 'submenu', 'admin_page_hooks', '_registered_pages', '_parent_pages', '_wp_menu_nopriv', '_wp_submenu_nopriv', '_wp_real_parent_file', 'pagenow', 'parent_file', 'plugin_page', 'typenow' );
$saved = array(); foreach ( $keys as $key ) $saved[$key] = $GLOBALS[$key] ?? null;
$user = get_current_user_id(); $checks = 0; $profile = 'admin'; $paid = true;
$assert = static function( $ok, $why ) use ( &$checks ) { $checks++; if ( ! $ok ) throw new RuntimeException( $why ); };
$cap_filter = static function( $caps ) use ( &$profile ) { $caps['manage_options'] = $profile === 'admin'; $caps['mbs_manage_bookings'] = $profile !== 'none'; return $caps; };
$option_filter = static function( $value, $option ) use ( &$paid ) { return $option === 'mbs_payments_enabled' ? ( $paid ? 1 : 0 ) : $value; };
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $admins ) throw new RuntimeException( 'Disposable administrator required.' );
wp_set_current_user( $admins[0] ); add_filter( 'user_has_cap', $cap_filter ); add_filter( 'pre_option', $option_filter, 10, 2 );
try {
    foreach ( array( array( 'admin', true ), array( 'admin', false ), array( 'manager', true ), array( 'manager', false ), array( 'none', false ) ) as $case ) {
        list( $profile, $paid ) = $case;
        foreach ( array_slice( $keys, 0, 8 ) as $key ) $GLOBALS[$key] = array();
        $GLOBALS['pagenow'] = 'admin.php'; $GLOBALS['parent_file'] = ''; $GLOBALS['typenow'] = ''; unset( $GLOBALS['plugin_page'] );
        ( new MBS_Admin() )->add_menu(); ( new MBS_Operator_Guide() )->menu(); ( new MBS_Additional_Charges() )->menu();
        foreach ( array( 'mathlin-add-booking' => 'mbs_manage_bookings', 'mathlin-google-calendar' => 'manage_options', 'mathlin-health' => 'manage_options' ) as $slug => $cap ) add_submenu_page( 'mathlin-booking', $slug, $slug, $cap, $slug, '__return_null' );
        $before = $GLOBALS['submenu']['mathlin-booking'] ?? array();
        $hooks = $GLOBALS['_registered_pages']; $parents = $GLOBALS['_parent_pages']; $denied = $GLOBALS['_wp_submenu_nopriv'];
        $nav = new MBS_Admin_Navigation(); $nav->group_menu();
        $assert( $hooks === $GLOBALS['_registered_pages'] && $parents === $GLOBALS['_parent_pages'] && $denied === $GLOBALS['_wp_submenu_nopriv'], 'Routing/denial registrations unchanged' );
        foreach ( $before as $row ) {
            $GLOBALS['plugin_page'] = $row[2]; $GLOBALS['parent_file'] = '';
            $assert( get_admin_page_parent() === 'mathlin-booking', 'Original parent retained: ' . $row[2] );
            $hook = get_plugin_page_hook( $row[2], 'admin.php' );
            $assert( $hook && isset( $hooks[$hook] ), 'Original callback hook retained: ' . $row[2] );
            $assert( user_can_access_admin_page() === current_user_can( $row[1] ), 'Original capability controls access: ' . $row[2] );
        }
        $GLOBALS['plugin_page'] = 'mathlin-health'; $GLOBALS['parent_file'] = '';
        $assert( user_can_access_admin_page() === ( $profile === 'admin' ), 'Health remains admin-only' );
        $GLOBALS['plugin_page'] = 'mathlin-add-booking'; $GLOBALS['parent_file'] = '';
        $assert( user_can_access_admin_page() === ( $profile !== 'none' ), 'Add booking retains capability' );
        $visible = array_values( array_filter( $GLOBALS['submenu']['mathlin-booking'], static function( $row ) { return current_user_can( $row[1] ) && strpos( $row[4] ?? '', 'mbs-secondary-menu' ) === false; } ) );
        if ( $profile !== 'none' ) {
            $assert( $visible[1][2] === 'mathlin-add-booking', 'Independent Add booking is second' );
            $assert( $visible[4][0] === ( $paid ? 'Finance' : 'Reports' ), 'Financial visibility follows site policy' );
        } else $assert( ! $visible, 'No permissions grants no navigation' );
    }
    echo "ADMIN_NAVIGATION_WORDPRESS_OK: {$checks} assertions\n";
} finally {
    remove_filter( 'pre_option', $option_filter, 10 ); remove_filter( 'user_has_cap', $cap_filter ); wp_set_current_user( $user );
    foreach ( $saved as $key => $value ) { if ( $value === null ) unset( $GLOBALS[$key] ); else $GLOBALS[$key] = $value; }
}
