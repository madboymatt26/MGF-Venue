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
    $('#nms-save-settings').on('click', function () {
        var $btn = $(this);
        var $msg = $('#nms-settings-msg');
        $btn.prop('disabled', true).text('Saving…');

        $.post(MBS_Admin.ajax_url, {
            action:          'mbs_save_settings',
            nonce:           MBS_Admin.nonce,
            ha_webhook_url:  $('#ha_webhook_url').val(),
            min_notice_days: $('#min_notice_days').val()
        }, function (res) {
            $btn.prop('disabled', false).text('Save Settings');
            if (res.success) {
                $msg.text('✓ Settings saved').removeClass('error').addClass('success');
            } else {
                $msg.text('✗ Error saving').removeClass('success').addClass('error');
            }
            setTimeout(function () { $msg.text(''); }, 3000);
        });
    });

    // ── Test HA webhook ────────────────────────────────────────────────────────
    $('#nms-test-ha').on('click', function () {
        var $btn = $(this);
        var $msg = $('#nms-settings-msg');
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
