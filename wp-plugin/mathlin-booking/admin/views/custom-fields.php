<?php if ( ! defined( 'ABSPATH' ) ) exit;
$fields = MBS_Custom_Fields::get_fields();
?>
<div class="wrap mbs-admin">
    <h1><?php echo MBS_Admin::brand_mark(); ?>MGF Venue – Custom Fields</h1>
    <p>Add custom questions to the booking form. These appear in an "Additional Information" section below the standard booking fields.</p>

    <div class="nms-card">
        <div class="nms-card-header"><h2>📝 Custom Form Fields</h2></div>
        <div style="padding:1.5rem;">
            <div class="mbs-table-scroll">
            <table class="wp-list-table widefat" id="mbs-cf-table">
                <thead>
                    <tr>
                        <th style="width:30%;">Label</th>
                        <th style="width:15%;">Type</th>
                        <th style="width:25%;">Options (for dropdowns)</th>
                        <th style="width:10%;">Required</th>
                        <th style="width:10%;"></th>
                    </tr>
                </thead>
                <tbody id="mbs-cf-tbody">
                    <?php foreach ( $fields as $field ) : ?>
                    <tr class="mbs-cf-row">
                        <td><input type="text" class="mbs-cf-label regular-text" value="<?php echo esc_attr( $field['label'] ); ?>" placeholder="e.g. Will you be serving alcohol?" style="width:100%;"></td>
                        <td>
                            <select class="mbs-cf-type">
                                <option value="text" <?php selected( $field['type'], 'text' ); ?>>Text</option>
                                <option value="textarea" <?php selected( $field['type'], 'textarea' ); ?>>Text Area</option>
                                <option value="select" <?php selected( $field['type'], 'select' ); ?>>Dropdown</option>
                                <option value="checkbox" <?php selected( $field['type'], 'checkbox' ); ?>>Checkbox</option>
                            </select>
                        </td>
                        <td><input type="text" class="mbs-cf-options regular-text" value="<?php echo esc_attr( $field['options'] ); ?>" placeholder="Option 1, Option 2, Option 3" style="width:100%;"></td>
                        <td style="text-align:center;"><input type="checkbox" class="mbs-cf-required" <?php checked( $field['required'] ); ?>></td>
                        <td><button type="button" class="button mbs-cf-remove">&times;</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <p style="margin-top:12px;">
                <button type="button" class="button" id="mbs-cf-add">+ Add Field</button>
            </p>

            <p class="nms-muted" style="margin-top:12px;font-size:0.85rem;">
                <strong>Types:</strong> Text = single line, Text Area = multi-line, Dropdown = select from options (comma-separated), Checkbox = yes/no.<br>
                <strong>Options:</strong> Only used for Dropdown type. Enter comma-separated values (e.g. "Yes, No, Maybe").<br>
                <strong>Note:</strong> Responses are stored with each booking and shown in the admin booking detail page.
            </p>
        </div>

        <div style="padding:0 1.5rem 1.5rem;text-align:center;">
            <button id="mbs-cf-save" class="button button-primary button-hero">💾 Save Custom Fields</button>
            <span id="mbs-cf-msg" class="nms-settings-msg" style="display:block;margin-top:12px;"></span>
        </div>
    </div>
</div>

<script>
jQuery(function($) {
    // Add field row
    $('#mbs-cf-add').on('click', function() {
        var row = '<tr class="mbs-cf-row">' +
            '<td><input type="text" class="mbs-cf-label regular-text" value="" placeholder="e.g. Do you need parking cones?" style="width:100%;"></td>' +
            '<td><select class="mbs-cf-type"><option value="text">Text</option><option value="textarea">Text Area</option><option value="select">Dropdown</option><option value="checkbox">Checkbox</option></select></td>' +
            '<td><input type="text" class="mbs-cf-options regular-text" value="" placeholder="Option 1, Option 2" style="width:100%;"></td>' +
            '<td style="text-align:center;"><input type="checkbox" class="mbs-cf-required"></td>' +
            '<td><button type="button" class="button mbs-cf-remove">&times;</button></td>' +
            '</tr>';
        $('#mbs-cf-tbody').append(row);
    });

    // Remove field row
    $(document).on('click', '.mbs-cf-remove', function() {
        $(this).closest('.mbs-cf-row').remove();
    });

    // Save
    $('#mbs-cf-save').on('click', function() {
        var $btn = $(this);
        var $msg = $('#mbs-cf-msg');
        $btn.prop('disabled', true).text('Saving…');

        var fields = [];
        $('#mbs-cf-tbody .mbs-cf-row').each(function() {
            var label = $(this).find('.mbs-cf-label').val().trim();
            if (!label) return;
            fields.push({
                id:       '',
                label:    label,
                type:     $(this).find('.mbs-cf-type').val(),
                options:  $(this).find('.mbs-cf-options').val(),
                required: $(this).find('.mbs-cf-required').is(':checked') ? 1 : 0
            });
        });

        $.post(MBS_Admin.ajax_url, {
            action: 'mbs_save_custom_fields',
            nonce:  MBS_Admin.nonce,
            fields: fields
        }, function(res) {
            $btn.prop('disabled', false).text('💾 Save Custom Fields');
            if (res.success) {
                $msg.text('✓ ' + res.data.count + ' field(s) saved').css('color', '#46b450').show();
            } else {
                $msg.text('✗ Error saving').css('color', '#dc3232').show();
            }
            setTimeout(function() { $msg.text('').hide(); }, 4000);
        });
    });
});
</script>
