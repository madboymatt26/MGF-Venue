<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Custom Fields — admin-configurable questions on the booking form.
 *
 * Fields are stored as a JSON array in wp_options.
 * Each field has: id, label, type (text/textarea/select/checkbox), required, options (for select).
 * Responses are stored as JSON in the booking's custom_fields column.
 */
class MBS_Custom_Fields {

    /**
     * Get all configured custom fields.
     *
     * @return array  Array of field definitions
     */
    public static function get_fields() {
        $fields = get_option( 'mbs_custom_fields', array() );
        if ( ! is_array( $fields ) ) return array();
        return $fields;
    }

    /**
     * Save custom field definitions.
     */
    public static function save_fields( $fields ) {
        $clean = array();
        foreach ( $fields as $field ) {
            $id = sanitize_key( $field['id'] ?? '' );
            if ( empty( $id ) ) {
                $id = 'cf_' . substr( md5( uniqid() ), 0, 8 );
            }
            $clean[] = array(
                'id'       => $id,
                'label'    => sanitize_text_field( $field['label'] ?? '' ),
                'type'     => in_array( $field['type'] ?? '', array( 'text', 'textarea', 'select', 'checkbox' ) ) ? $field['type'] : 'text',
                'required' => ! empty( $field['required'] ),
                'options'  => sanitize_text_field( $field['options'] ?? '' ), // comma-separated for select
            );
        }
        update_option( 'mbs_custom_fields', $clean );
    }

    /**
     * Render custom fields in the public booking form.
     */
    public static function render_form_fields() {
        $fields = self::get_fields();
        if ( empty( $fields ) ) return;

        echo '<div class="nms-form-section"><h3>Additional Information</h3>';

        foreach ( $fields as $field ) {
            $id       = esc_attr( $field['id'] );
            $label    = esc_html( $field['label'] );
            $required = $field['required'] ? ' required' : '';
            $req_star = $field['required'] ? ' <span class="nms-req">*</span>' : '';

            echo '<div class="nms-form-group">';
            echo '<label for="nms-cf-' . $id . '">' . $label . $req_star . '</label>';

            switch ( $field['type'] ) {
                case 'textarea':
                    echo '<textarea id="nms-cf-' . $id . '" name="custom_fields[' . $id . ']" rows="3"' . $required . '></textarea>';
                    break;

                case 'select':
                    $options = array_map( 'trim', explode( ',', $field['options'] ) );
                    echo '<select id="nms-cf-' . $id . '" name="custom_fields[' . $id . ']"' . $required . '>';
                    echo '<option value="">— Select —</option>';
                    foreach ( $options as $opt ) {
                        echo '<option value="' . esc_attr( $opt ) . '">' . esc_html( $opt ) . '</option>';
                    }
                    echo '</select>';
                    break;

                case 'checkbox':
                    echo '<label style="display:flex;align-items:center;gap:0.5rem;font-weight:normal;cursor:pointer;">';
                    echo '<input type="checkbox" id="nms-cf-' . $id . '" name="custom_fields[' . $id . ']" value="Yes"' . $required . ' style="width:18px;height:18px;">';
                    echo esc_html( $field['label'] );
                    echo '</label>';
                    break;

                default: // text
                    echo '<input type="text" id="nms-cf-' . $id . '" name="custom_fields[' . $id . ']"' . $required . '>';
                    break;
            }

            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Validate and extract custom field responses from POST data.
     *
     * @return array|WP_Error  Cleaned responses or error
     */
    public static function validate_submission( $post_data ) {
        $fields    = self::get_fields();
        $responses = array();

        if ( empty( $fields ) ) return $responses;

        $submitted = $post_data['custom_fields'] ?? array();

        foreach ( $fields as $field ) {
            $value = sanitize_text_field( $submitted[ $field['id'] ] ?? '' );

            if ( $field['required'] && empty( $value ) ) {
                return new WP_Error( 'custom_field_required', 'Please fill in: ' . $field['label'] );
            }

            if ( strlen( $value ) > 1000 ) {
                return new WP_Error( 'custom_field_too_long', 'Response too long for: ' . $field['label'] . ' (max 1000 characters)' );
            }

            if ( ! empty( $value ) ) {
                $responses[ $field['id'] ] = array(
                    'label' => $field['label'],
                    'value' => $value,
                );
            }
        }

        return $responses;
    }

    /**
     * Display custom field responses in admin (booking detail page).
     */
    public static function render_admin_display( $booking ) {
        $responses = json_decode( $booking->custom_fields ?? '{}', true );
        if ( ! is_array( $responses ) ) return;
        // Migration and consolidation metadata shares this JSON column. It is
        // not a customer answer and must never be indexed as label/value data.
        $responses = array_filter( $responses, static function( $data, $id ) {
            return strpos( (string) $id, '_' ) !== 0 && is_array( $data )
                && isset( $data['label'], $data['value'] ) && is_scalar( $data['label'] ) && is_scalar( $data['value'] );
        }, ARRAY_FILTER_USE_BOTH );
        if ( ! $responses ) return;

        echo '<div class="nms-detail-item nms-detail-full" style="margin-top:0.5rem;">';
        echo '<label>Additional Information</label>';
        echo '<div style="margin-top:4px;">';
        foreach ( $responses as $id => $data ) {
            echo '<div style="margin-bottom:4px;"><strong>' . esc_html( $data['label'] ) . ':</strong> ' . esc_html( $data['value'] ) . '</div>';
        }
        echo '</div></div>';
    }
}
