/* NM Scouts Booking – Admin JS */
jQuery(function ($) {

    // ── Confirm booking ────────────────────────────────────────────────────────
    $(document).on('click', '.nms-btn-confirm', function () {
        var $btn     = $(this);
        var ref      = $btn.data('ref');
        var redirect = $btn.data('redirect');
        if (!confirm('Confirm booking ' + ref + '? A confirmation email will be sent to the booker.')) return;
        nmsUpdateStatus(ref, 'confirmed', $btn, redirect);
    });

    // ── Cancel booking ─────────────────────────────────────────────────────────
    $(document).on('click', '.nms-btn-cancel', function () {
        var $btn     = $(this);
        var ref      = $btn.data('ref');
        var redirect = $btn.data('redirect');
        if (!confirm('Cancel booking ' + ref + '?')) return;
        nmsUpdateStatus(ref, 'cancelled', $btn, redirect);
    });

    // ── Delete booking ─────────────────────────────────────────────────────────
    $(document).on('click', '.nms-btn-delete', function () {
        var $btn = $(this);
        var ref  = $btn.data('ref');
        if (!confirm('Permanently delete booking ' + ref + '? This cannot be undone.')) return;

        $.post(MBS_Admin.ajax_url, {
            action: 'mbs_delete_booking',
            nonce:  MBS_Admin.nonce,
            ref:    ref
        }, function (res) {
            if (res.success) {
                window.location.href = '?page=mathlin-booking';
            }
        });
    });

    // ── Save settings ──────────────────────────────────────────────────────────
    $(document).on('click', '.nms-save-settings', function () {
        var $btn = $(this);
        var $msg = $('.nms-settings-msg');
        $btn.prop('disabled', true).text('Saving…');

        // Collect spaces data
        var spaces = [];
        $('#nms-spaces-tbody .nms-space-row').each(function () {
            var name     = $(this).find('.nms-space-name').val().trim();
            var rate     = $(this).find('.nms-space-rate').val();
            var unit     = $(this).find('.nms-space-unit').val();
            var capacity = $(this).find('.nms-space-capacity').val();
            if (name) {
                spaces.push({ name: name, rate: rate, unit: unit, capacity: capacity });
            }
        });

        $.post(MBS_Admin.ajax_url, {
            action:          'mbs_save_settings',
            nonce:           MBS_Admin.nonce,
            ha_webhook_url:  $('#ha_webhook_url').val(),
            min_notice_days: $('#min_notice_days').val(),
            github_token:    $('#github_token').val(),
            admin_email:     $('#admin_email').val(),
            kitchen_price:   $('#kitchen_price').val(),
            spaces:          spaces
        }, function (res) {
            $('.nms-save-settings').prop('disabled', false).text('Save All Settings');
            if (res.success) {
                $msg.text('✓ Settings saved').removeClass('error').addClass('success');
            } else {
                $msg.text('✗ Error saving').removeClass('success').addClass('error');
            }
            setTimeout(function () { $msg.text(''); }, 3000);
        });
    });

    // ── Add space row ──────────────────────────────────────────────────────────
    $('#nms-add-space').on('click', function () {
        var row = '<tr class="nms-space-row">' +
            '<td><input type="text" class="nms-space-name regular-text" value="" placeholder="e.g. Activity Room"></td>' +
            '<td><input type="number" class="nms-space-rate" value="0" min="0" step="0.01" style="width:80px"></td>' +
            '<td><select class="nms-space-unit"><option value="hr">per hour</option><option value="day">per day</option></select></td>' +
            '<td><input type="number" class="nms-space-capacity" value="" min="1" style="width:70px" placeholder="—"></td>' +
            '<td><button type="button" class="button nms-remove-space" title="Remove space">&times;</button></td>' +
            '</tr>';
        $('#nms-spaces-tbody').append(row);
    });

    // ── Remove space row ───────────────────────────────────────────────────────
    $(document).on('click', '.nms-remove-space', function () {
        var $row = $(this).closest('.nms-space-row');
        var name = $row.find('.nms-space-name').val();
        if (name && !confirm('Remove "' + name + '" from bookable spaces?')) return;
        $row.remove();
    });

    // ── Test HA webhook ────────────────────────────────────────────────────────
    $('#nms-test-ha').on('click', function () {
        var $btn = $(this);
        var $msg = $btn.siblings('.nms-settings-msg');
        $btn.prop('disabled', true).text('Sending…');

        $.post(MBS_Admin.ajax_url, {
            action: 'mbs_test_ha',
            nonce:  MBS_Admin.nonce
        }, function (res) {
            $btn.prop('disabled', false).text('Send Test Webhook');
            if (res.success) {
                $msg.text('✓ Webhook sent (HTTP ' + res.data.http_code + ')').removeClass('error').addClass('success');
            } else {
                $msg.text('✗ ' + (res.data || 'Failed')).removeClass('success').addClass('error');
            }
            setTimeout(function () { $msg.text(''); }, 4000);
        });
    });

    // ── Check for plugin updates ───────────────────────────────────────────────
    $('#nms-check-update').on('click', function () {
        var $btn = $(this);
        var $msg = $('#nms-update-msg');
        $btn.prop('disabled', true).text('Checking…');

        $.post(MBS_Admin.ajax_url, {
            action: 'mbs_check_update',
            nonce:  MBS_Admin.nonce
        }, function (res) {
            $btn.prop('disabled', false).text('Check for Updates Now');
            if (res.success) {
                if (res.data.update_available) {
                    $msg.text('Update available: v' + res.data.new_version + ' (current: v' + res.data.current_version + '). Go to Dashboard → Updates to install.')
                        .removeClass('error').addClass('success');
                } else {
                    $msg.text('✓ You are running the latest version (v' + res.data.current_version + ')')
                        .removeClass('error').addClass('success');
                }
            } else {
                $msg.text('✗ Could not check for updates. Is the GitHub token valid?')
                    .removeClass('success').addClass('error');
            }
            setTimeout(function () { $msg.text(''); }, 6000);
        });
    });

    // ── Helper: update status via AJAX ─────────────────────────────────────────
    function nmsUpdateStatus(ref, status, $btn, redirect) {
        $btn.prop('disabled', true);

        $.post(MBS_Admin.ajax_url, {
            action: 'mbs_update_status',
            nonce:  MBS_Admin.nonce,
            ref:    ref,
            status: status
        }, function (res) {
            if (res.success) {
                if (redirect) {
                    window.location.reload();
                } else {
                    // Update the row in the table
                    var $row    = $('#nms-row-' + ref);
                    var label   = status.charAt(0).toUpperCase() + status.slice(1);
                    var classes = { pending: 'nms-status-pending', confirmed: 'nms-status-confirmed', cancelled: 'nms-status-cancelled' };
                    $row.find('.nms-status')
                        .removeClass('nms-status-pending nms-status-confirmed nms-status-cancelled')
                        .addClass(classes[status])
                        .text(label);
                    // Remove action buttons that no longer apply
                    if (status === 'confirmed') $row.find('.nms-btn-confirm').remove();
                    if (status === 'cancelled') $row.find('.nms-btn-cancel, .nms-btn-confirm').remove();
                    $btn.prop('disabled', false);
                }
            } else {
                alert('Error updating booking status.');
                $btn.prop('disabled', false);
            }
        });
    }
});
