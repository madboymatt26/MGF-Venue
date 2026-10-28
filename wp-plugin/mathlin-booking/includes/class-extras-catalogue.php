<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Optional configuration, never an authority to issue invoices or take payment. */
class MBS_Extras_Catalogue {
    const OPTION = 'mbs_extras_catalogue_v1';

    public static function allowed() {
        return current_user_can( 'manage_options' );
    }

    public static function usable() {
        return MBS_Payment_Features::enabled( 'additional_charges' ) && self::state()['enabled'];
    }

    private static function error( $code, $message, $status = 400 ) {
        return new WP_Error( $code, $message, array( 'status' => $status ) );
    }

    public static function state() {
        $state = get_option( self::OPTION, null );
        if ( ! is_array( $state ) || ! isset( $state['revision'], $state['items'], $state['enabled'] ) ) {
            return array( 'revision' => 0, 'enabled' => false, 'items' => array() );
        }
        return $state;
    }

    public static function validate_item( $input ) {
        if ( ! is_array( $input ) ) return self::error( 'extra_invalid', 'Enter catalogue details.' );
        foreach ( array( 'description', 'unit', 'price', 'category', 'account_code', 'tax_code' ) as $field ) {
            if ( ! isset( $input[$field] ) || ! is_string( $input[$field] ) ) return self::error( 'extra_invalid', 'Catalogue fields must be plain text; price must be an exact decimal string.' );
        }
        $description = sanitize_text_field( $input['description'] );
        $unit = sanitize_text_field( $input['unit'] );
        if ( $description === '' || strlen( $description ) > 300 || $unit === '' || strlen( $unit ) > 40 ) return self::error( 'extra_invalid', 'Enter a description (up to 300 bytes) and unit (up to 40 bytes).' );
        // Bound input length before conversion: oversized decimals must not overflow integer money.
        if ( ! preg_match( '/^\d{1,7}(?:\.\d{1,2})?$/D', $input['price'] ) ) return self::error( 'extra_invalid', 'Enter a positive GBP unit price with at most two decimal places.' );
        $price = MBS_Money::from_decimal_string( $input['price'] );
        if ( is_wp_error( $price ) || $price < 1 || $price > 100000000 ) return self::error( 'extra_invalid', 'Unit price must be between £0.01 and £1,000,000.' );
        if ( ! in_array( $input['category'], array( 'cleaning', 'damage', 'other' ), true ) ) return self::error( 'extra_invalid', 'Choose Cleaning, Damage or Other.' );
        foreach ( array( 'account_code', 'tax_code' ) as $field ) {
            if ( ! preg_match( '/^[A-Za-z0-9_.:\/ -]{0,40}$/D', $input[$field] ) ) return self::error( 'extra_invalid', 'Accounting codes must be at most 40 letters, numbers, spaces or safe separators.' );
        }
        return array( 'description' => $description, 'unit' => $unit, 'unit_amount_minor' => $price,
            'category' => $input['category'], 'account_code' => $input['account_code'], 'tax_code' => $input['tax_code'] );
    }

    /** Compare-and-swap the entire small catalogue; stale tabs cannot overwrite newer prices. */
    public static function save( $input ) {
        global $wpdb;
        if ( ! self::allowed() ) return self::error( 'extra_forbidden', 'Only site administrators can configure extras.', 403 );
        if ( ! MBS_Payment_Features::enabled( 'additional_charges' ) ) return MBS_Payment_Features::error( 'additional_charges' );
        if ( ! is_array( $input ) || ! isset( $input['revision'] ) || ! ( is_int( $input['revision'] ) || is_string( $input['revision'] ) ) || ! preg_match( '/^\d{1,9}$/D', (string) $input['revision'] ) ) return self::error( 'extra_revision', 'Supply the current catalogue revision.' );
        $before = self::state();
        if ( (int) $input['revision'] !== (int) $before['revision'] ) return self::error( 'extra_stale', 'The catalogue changed. Reload before saving.', 409 );
        $next = $before;
        $op = $input['operation'] ?? '';
        if ( $op === 'enable' ) {
            if ( ! isset( $input['enabled'] ) || ! is_bool( $input['enabled'] ) ) return self::error( 'extra_invalid', 'Enabled must be boolean.' );
            $next['enabled'] = $input['enabled'];
        } elseif ( $op === 'save' ) {
            $item = self::validate_item( $input );
            if ( is_wp_error( $item ) ) return $item;
            $id = $input['id'] ?? '';
            if ( ! is_string( $id ) || ( $id !== '' && ! isset( $before['items'][$id] ) ) ) return self::error( 'extra_missing', 'Catalogue item not found.', 404 );
            if ( $id === '' ) {
                if ( count( $before['items'] ) >= 500 ) return self::error( 'extra_limit', 'The catalogue is limited to 500 retained items; edit or restore an existing item.' );
                $id = wp_generate_uuid4();
            }
            $previous = $before['items'][$id] ?? array();
            $next['items'][$id] = array_merge( $item, array( 'id' => $id, 'version' => (int) ( $previous['version'] ?? 0 ) + 1,
                'archived' => ! empty( $previous['archived'] ) ) );
        } elseif ( $op === 'archive' || $op === 'restore' ) {
            $id = $input['id'] ?? '';
            if ( ! is_string( $id ) || ! isset( $before['items'][$id] ) ) return self::error( 'extra_missing', 'Catalogue item not found.', 404 );
            $next['items'][$id]['archived'] = $op === 'archive';
            $next['items'][$id]['version']++;
        } else return self::error( 'extra_operation', 'Choose a supported catalogue operation.' );
        $next['revision']++;
        $next['modified_at'] = gmdate( DATE_RFC3339 );
        $next['modified_by'] = get_current_user_id();
        // No get_option cache is trusted as a write lock. The persisted bytes must still match.
        if ( $before['revision'] === 0 ) {
            $written = add_option( self::OPTION, $next, '', false );
        } else {
            $written = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s", maybe_serialize( $next ), self::OPTION, maybe_serialize( $before ) ) );
        }
        wp_cache_delete( self::OPTION, 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        if ( $written === false || $written === 0 ) return self::error( 'extra_save_failed', 'Catalogue not saved. It may have changed in another tab; reload and retry.', 409 );
        MBS_Audit_Log::log( 'extras-catalogue', 'extra_' . $op, 'Revision ' . $next['revision'] . ( isset( $id ) ? '; item ' . $id : '' ) . '. Existing invoices unchanged.' );
        return $next;
    }

    /** Build all charge money and provenance server-side; ignore caller-supplied prices/snapshots. */
    public static function quote( $id, $version, $quantity ) {
        if ( ! self::usable() ) return self::error( 'extra_disabled', 'Reusable extras are disabled.', 409 );
        if ( ! is_string( $id ) || ! ( is_int( $version ) || is_string( $version ) ) || ! preg_match( '/^[1-9]\d{0,8}$/D', (string) $version ) ||
            ! ( is_string( $quantity ) || is_int( $quantity ) ) || ! preg_match( '/^[1-9]\d{0,3}$/D', (string) $quantity ) ) return self::error( 'extra_invalid', 'Choose an extra, its current version and a whole quantity between 1 and 9999.' );
        $item = self::state()['items'][$id] ?? null;
        if ( ! $item || $item['archived'] ) return self::error( 'extra_missing', 'That extra is no longer available; reload the catalogue.', 409 );
        if ( (int) $version !== $item['version'] ) return self::error( 'extra_stale', 'The selected extra changed. Reload and confirm its current price.', 409 );
        $total = $item['unit_amount_minor'] * (int) $quantity;
        if ( $total > 100000000 ) return self::error( 'extra_amount', 'Total charge cannot exceed £1,000,000.' );
        return array( 'description' => $item['description'] . ' (' . $quantity . ' × ' . $item['unit'] . ')',
            'category' => $item['category'], 'amount' => MBS_Money::decimal( $total ), 'snapshot' => array_merge( $item,
                array( 'quantity' => (int) $quantity, 'currency' => 'GBP' ) ) );
    }

    public function init() {
        add_action( 'rest_api_init', function() {
            register_rest_route( 'mathlin/v1', '/admin/extras-catalogue', array(
                array( 'methods' => 'GET', 'permission_callback' => function() { return current_user_can( 'mbs_manage_bookings' ) || self::allowed(); }, 'callback' => function() { return self::state(); } ),
                array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'allowed' ), 'callback' => function( $request ) { return self::save( $request->get_json_params() ); } ),
            ) );
        } );
        // Configuration lives alongside additional charges, not a separate application.
        add_action( 'mbs_additional_charge_catalogue', array( $this, 'render_settings' ) );
        add_action( 'mbs_additional_charge_picker', array( $this, 'render_picker' ) );
        add_action( 'admin_post_mbs_extras_catalogue', array( $this, 'handle_post' ) );
    }

    public function handle_post() {
        if ( ! self::allowed() ) wp_die( 'Administrator permission required.', '', array( 'response' => 403 ) );
        check_admin_referer( 'mbs_extras_catalogue' );
        $input = wp_unslash( $_POST );
        if ( ( $input['operation'] ?? '' ) === 'enable' ) $input['enabled'] = isset( $input['enabled'] ) && $input['enabled'] === '1';
        $result = self::save( $input );
        if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ), 'Catalogue not saved', array( 'response' => (int) ( $result->get_error_data()['status'] ?? 400 ), 'back_link' => true ) );
        wp_safe_redirect( admin_url( 'admin.php?page=mathlin-additional-charges' ) ); exit;
    }

    private static function start_form( $state, $operation, $id = '' ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'mbs_extras_catalogue' );
        echo '<input type="hidden" name="action" value="mbs_extras_catalogue"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="revision" value="' . (int) $state['revision'] . '"><input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
    }

    public function render_settings() {
        if ( ! self::allowed() || ! MBS_Payment_Features::enabled( 'additional_charges' ) ) return;
        $state = self::state();
        echo '<details class="nms-card mbs-disclosure mbs-extras-settings"><summary class="nms-card-header"><h2>Reusable extras catalogue</h2></summary><div class="mbs-card-body"><p>Optional shortcuts for agreed cleaning, equipment and other extras. Saving a catalogue item never bills or emails anyone. Existing invoices keep their saved price and description. Accounting codes are stored as reference mappings, not posted automatically.</p>';
        self::start_form( $state, 'enable' );
        echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked( $state['enabled'], true, false ) . '>Enable reusable extras on this site</label> <button class="button">Save preference</button></p></form>';
        echo '<div class="mbs-table-scroll" role="region" aria-label="Reusable extras" tabindex="0"><table class="widefat striped mbs-panel-table"><thead><tr><th scope="col">Extra</th><th scope="col">Unit price</th><th scope="col">Mapping (reference only)</th><th scope="col">Status / action</th></tr></thead><tbody>';
        foreach ( $state['items'] as $item ) {
            echo '<tr><td>' . esc_html( $item['description'] ) . '</td><td>' . esc_html( MBS_Money::format( $item['unit_amount_minor'] ) . ' / ' . $item['unit'] ) . '</td><td>' . esc_html( $item['account_code'] . ' / ' . $item['tax_code'] ) . '</td><td>' . ( $item['archived'] ? 'Archived' : 'Available' );
            self::start_form( $state, $item['archived'] ? 'restore' : 'archive', $item['id'] );
            echo '<button class="button">' . ( $item['archived'] ? 'Restore' : 'Archive' ) . '</button></form><details><summary>Edit extra</summary>';
            self::item_form( $state, $item ); echo '</details></td></tr>';
        }
        if ( ! $state['items'] ) echo '<tr><td colspan="4">No extras configured.</td></tr>';
        echo '</tbody></table></div><details class="mbs-inline-disclosure"><summary>Add an extra</summary>'; self::item_form( $state, array() ); echo '</details></div></details>';
    }

    private static function item_form( $state, $item ) {
        self::start_form( $state, 'save', $item['id'] ?? '' );
        $values = array_merge( array( 'description' => '', 'unit' => 'item', 'price' => '', 'account_code' => '', 'tax_code' => '' ), $item );
        if ( isset( $item['unit_amount_minor'] ) ) $values['price'] = MBS_Money::decimal( $item['unit_amount_minor'] );
        foreach ( array( 'description' => 'Customer-visible description', 'unit' => 'Unit (visit, hour, item)', 'price' => 'Unit price (GBP)', 'account_code' => 'Account code (optional)', 'tax_code' => 'Tax code (optional; does not calculate tax)' ) as $key => $label ) {
            echo '<p><label>' . esc_html( $label ) . '<br><input class="regular-text" name="' . esc_attr( $key ) . '" value="' . esc_attr( $values[$key] ) . '" ' . ( in_array( $key, array( 'account_code', 'tax_code' ), true ) ? '' : 'required' ) . '></label></p>';
        }
        echo '<p><label>Category <select name="category">';
        foreach ( array( 'cleaning', 'damage', 'other' ) as $category ) echo '<option value="' . esc_attr( $category ) . '" ' . selected( $item['category'] ?? 'other', $category, false ) . '>' . esc_html( ucfirst( $category ) ) . '</option>';
        echo '</select></label></p><p><button class="button button-primary">Save catalogue item</button></p></form>';
    }

    public function render_picker() {
        if ( ! self::usable() ) return;
        $items = array_values( array_filter( self::state()['items'], function( $item ) { return ! $item['archived']; } ) );
        if ( ! $items ) return;
        wp_enqueue_script( 'mbs-extras-picker', MBS_PLUGIN_URL . 'admin/extras-picker.js', array(), MBS_VERSION, true );
        wp_localize_script( 'mbs-extras-picker', 'MBS_Extras', array( 'items' => $items ) );
        echo '<fieldset class="mbs-charge-field"><legend>Agreed extra</legend><label>Choose an extra <select name="catalogue_id" id="mbs-extra-picker"><option value="">Custom / ad-hoc charge</option>';
        foreach ( $items as $item ) echo '<option value="' . esc_attr( $item['id'] ) . '">' . esc_html( $item['description'] . ' — ' . MBS_Money::format( $item['unit_amount_minor'] ) . ' / ' . $item['unit'] ) . '</option>';
        echo '</select></label><input type="hidden" name="catalogue_version" value=""><p><label>Quantity <input type="number" min="1" max="9999" step="1" name="catalogue_quantity" value="1"></label></p><p role="status" id="mbs-extra-total">Custom charges use the description and amount below.</p></fieldset>';
    }
}
