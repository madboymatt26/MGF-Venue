<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Presentation only: keep the original page registrations, hooks and capabilities. */
class MBS_Admin_Navigation {
    private $groups = array();

    public function init() {
        // Modules register their own pages first (including the optional Staff guide).
        add_action( 'admin_menu', array( $this, 'group_menu' ), 999 );
        add_filter( 'submenu_file', array( $this, 'active_menu' ) );
        add_action( 'in_admin_header', array( $this, 'render_tabs' ) );
        add_action( 'admin_head', array( $this, 'sidebar_css' ) );
    }

    public function group_menu() {
        global $submenu;
        $registered = $submenu['mathlin-booking'] ?? array();
        $pages = array();
        foreach ( $registered as $row ) {
            if ( isset( $row[2], $row[1] ) && current_user_can( $row[1] ) ) $pages[ $row[2] ] = $row;
        }
        $definitions = array(
            array( 'Bookings', array( 'mathlin-booking' => 'All bookings', 'mathlin-series' => 'Recurring series', 'mathlin-scout-nights' => 'Scout nights', 'mathlin-archived' => 'Archived' ) ),
            array( 'Add booking', array( 'mathlin-add-booking' => 'Add booking' ) ),
            array( 'Calendar', array( 'mathlin-calendar' => 'Calendar', 'mathlin-blocked' => 'Blocked dates' ) ),
            array( 'Requests', array( 'mathlin-requests' => 'Change requests' ) ),
            array( MBS_Payment_Features::enabled( 'payments' ) && ( MBS_Payment_Features::enabled( 'reports' ) || MBS_Payment_Features::enabled( 'additional_charges' ) ) ? 'Finance' : 'Reports', array( 'mathlin-analytics' => 'Reports', 'mathlin-additional-charges' => 'Additional charges', 'mathlin-osm' => 'OSM integration' ) ),
            array( 'Settings', array( 'mathlin-settings' => 'General', 'mathlin-emails' => 'Email templates', 'mathlin-custom-fields' => 'Custom fields', 'mathlin-google-calendar' => 'Google Calendar sync' ) ),
            array( 'Help & health', array( 'mathlin-guide' => 'Staff guide', 'mathlin-health' => 'Integration health', 'mathlin-audit-log' => 'Audit log' ) ),
        );
        $this->groups = array();
        $visible = array();
        $known = array();
        $roots = array();
        foreach ( $definitions as $definition ) {
            list( $label, $children ) = $definition;
            $tabs = array();
            foreach ( $children as $slug => $title ) {
                $known[ $slug ] = true;
                // Feature-disabled pages were never registered; never resurrect them.
                if ( isset( $pages[ $slug ] ) ) $tabs[ $slug ] = array( 'label' => $title, 'capability' => $pages[ $slug ][1] );
            }
            if ( ! $tabs ) continue;
            $root = key( $tabs );
            $row = $pages[ $root ];
            // Retain WordPress's count markup on Bookings and Requests.
            if ( $root === 'mathlin-booking' ) $row[0] = str_replace( 'All Bookings', 'Bookings', $row[0] );
            elseif ( $root !== 'mathlin-requests' ) $row[0] = $label;
            $visible[] = $row;
            $roots[ $root ] = true;
            $this->groups[] = array( 'label' => $label, 'root' => $root, 'tabs' => $tabs );
        }
        foreach ( $registered as $row ) {
            $slug = $row[2] ?? '';
            if ( isset( $roots[ $slug ] ) ) continue;
            // Keep secondary entries in WordPress's routing/permission table. Removing
            // them breaks get_admin_page_parent() and bookmarked page-hook resolution.
            // Only hide their sidebar presentation; the tabs provide the visible link.
            if ( isset( $known[ $slug ] ) ) $row[4] = trim( ( $row[4] ?? '' ) . ' mbs-secondary-menu' );
            // Unknown extension pages retain their normal visibility.
            $visible[] = $row;
        }
        $submenu['mathlin-booking'] = $visible;
    }

    public function sidebar_css() {
        // The sidebar appears on *all* WordPress admin screens, not only MGF pages.
        echo '<style>#adminmenu #toplevel_page_mathlin-booking .mbs-secondary-menu{display:none!important}#adminmenu #toplevel_page_mathlin-booking a[href="admin.php?page=mathlin-add-booking"]{font-weight:600}</style>';
    }

    private function current_group() {
        global $plugin_page;
        if ( ! is_string( $plugin_page ) ) return null;
        foreach ( $this->groups as $group ) {
            if ( isset( $group['tabs'][ $plugin_page ] ) && current_user_can( $group['tabs'][ $plugin_page ]['capability'] ) ) return $group;
        }
        return null;
    }

    public function active_menu( $file ) {
        $group = $this->current_group();
        return $group ? $group['root'] : $file;
    }

    public function render_tabs() {
        global $plugin_page;
        $group = $this->current_group();
        if ( ! $group || count( $group['tabs'] ) < 2 ) return;
        echo '<div class="wrap mbs-section-navigation"><nav class="nav-tab-wrapper" aria-label="' . esc_attr( 'MGF Venue — ' . $group['label'] ) . '">';
        foreach ( $group['tabs'] as $slug => $tab ) {
            if ( ! current_user_can( $tab['capability'] ) ) continue;
            $active = $slug === $plugin_page;
            echo '<a class="nav-tab' . ( $active ? ' nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '>' . esc_html( $tab['label'] ) . '</a>';
        }
        echo '</nav></div>';
    }
}
