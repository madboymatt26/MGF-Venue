/* NM Scouts Booking – Admin JS */
jQuery(function ($) {

    // ── Confirm booking ────────────────────────────────────────────────────────
    $(document).on('click', '.nms-btn-confirm', function () {
        var $btn     = $(this);
        var ref      = $btn.data('ref');
        var redirect = $btn.data('redirect');
        if (!confirm('Confirm booking ' + ref + '? A confirmation email will be sent to the booker.')) return;
        nmsUpdateStatus(ref, 'confirmed', $btn, redirect || true);
    });

    // ── Cancel booking ─────────────────────────────────────────────────────────
    $(document).on('click', '.nms-btn-cancel', function () {
        var $btn     = $(this);
        var ref      = $btn.data('ref');
        var redirect = $btn.data('redirect');
        var reason   = prompt('Cancel booking ' + ref + '?\n\nOptionally enter a reason (this will be sent to the booker):');
        if (reason === null) return; // User clicked Cancel on the prompt
        nmsUpdateStatus(ref, 'cancelled', $btn, redirect, reason);
    });

    // ── Reopen cancelled booking ───────────────────────────────────────────────
    $(document).on('click', '.nms-btn-reopen', function () {
        var $btn     = $(this);
        var ref      = $btn.data('ref');
        var redirect = $btn.data('redirect');
        if (!confirm('Reopen booking ' + ref + '? It will be set back to Pending status.')) return;
        nmsUpdateStatus(ref, 'pending', $btn, redirect || true);
    });

    // ── Mark as paid ───────────────────────────────────────────────────────────
    $(document).on('click', '.nms-btn-paid', function () {
        var $btn     = $(this);
        var ref      = $btn.data('ref');
        var redirect = $btn.data('redirect');
        if (!confirm('Mark booking ' + ref + ' as paid? A payment confirmation email will be sent to the booker.')) return;
        nmsUpdateStatus(ref, 'paid', $btn, redirect || true);
    });

    // ── Undo paid (back to confirmed) ──────────────────────────────────────────
    $(document).on('click', '.nms-btn-unpaid', function () {
        var $btn     = $(this);
        var ref      = $btn.data('ref');
        var redirect = $btn.data('redirect');
        if (!confirm('Undo paid status for ' + ref + '? It will be set back to Confirmed.')) return;
        nmsUpdateStatus(ref, 'confirmed', $btn, redirect || true);
    });

    // ── Undo deposit (back to confirmed) ───────────────────────────────────────
    $(document).on('click', '.nms-btn-undo-deposit', function () {
        var $btn     = $(this);
        var ref      = $btn.data('ref');
        var redirect = $btn.data('redirect');
        if (!confirm('Undo deposit paid status for ' + ref + '? It will be set back to Confirmed and the deposit record cleared.')) return;
        $btn.prop('disabled', true);
        $.post(MBS_Admin.ajax_url, {
            action: 'mbs_undo_deposit',
            nonce:  MBS_Admin.nonce,
            ref:    ref
        }, function (res) {
            if (res.success) {
                window.location.reload();
            } else {
                alert('Error: ' + (res.data || 'Could not update booking.'));
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            alert('Network error — please try again.');
            $btn.prop('disabled', false);
        });
    });

    // ── Mark balance paid (after modification increased cost) ───────────────────
    $(document).on('click', '.nms-btn-mark-balance-paid', function () {
        var $btn = $(this);
        var ref  = $btn.data('ref');
        if (!confirm('Mark the outstanding balance for ' + ref + ' as paid?\nThis will set the booking status to Paid.')) return;
        nmsUpdateStatus(ref, 'paid', $btn, true);
    });

    // ── Mark deposit paid (admin manually records deposit received) ─────────────
    $(document).on('click', '.nms-btn-deposit-paid', function () {
        var $btn = $(this);
        var ref  = $btn.data('ref');
        if (!confirm('Mark the deposit for ' + ref + ' as received?\nStatus will change to Deposit Paid. The balance will be chased automatically before the event.')) return;
        $btn.prop('disabled', true);
        $.post(MBS_Admin.ajax_url, {
            action: 'mbs_mark_deposit_paid',
            nonce:  MBS_Admin.nonce,
            ref:    ref
        }, function (res) {
            if (res.success) {
                window.location.reload();
            } else {
                alert('Error: ' + (res.data || 'Could not update booking.'));
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            alert('Network error — please try again.');
            $btn.prop('disabled', false);
        });
    });

    // ── Mark refund processed (after modification decreased cost) ───────────────
    $(document).on('click', '.nms-btn-mark-refunded', function () {
        var $btn = $(this);
        var ref  = $btn.data('ref');
        if (!confirm('Confirm that the refund/credit for ' + ref + ' has been processed?\nThe balance alert will be cleared.')) return;
        $btn.prop('disabled', true);
        $.post(MBS_Admin.ajax_url, {
            action: 'mbs_mark_refunded',
            nonce:  MBS_Admin.nonce,
            ref:    ref
        }, function (res) {
            if (res.success) {
                window.location.reload();
            } else {
                alert('Error: ' + (res.data || 'Could not update booking.'));
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            alert('Network error — please try again.');
            $btn.prop('disabled', false);
        });
    });

    // ── Archive single booking ─────────────────────────────────────────────────
    $(document).on('click', '.nms-btn-archive', function () {
        var $btn     = $(this);
        var ref      = $btn.data('ref');
        var redirect = $btn.data('redirect');
        if (!confirm('Archive booking ' + ref + '?')) return;
        nmsUpdateStatus(ref, 'archived', $btn, redirect || true);
    });

    // ── Archive past bookings ──────────────────────────────────────────────────
    $('#nms-archive-past').on('click', function () {
        if (!confirm('Archive all past bookings (confirmed and cancelled)?\n\nThis moves them out of the main list. You can still view them by filtering for "Archived" status.')) return;
        var $btn = $(this);
        $btn.prop('disabled', true).text('Archiving…');

        $.post(MBS_Admin.ajax_url, {
            action: 'mbs_archive_past',
            nonce:  MBS_Admin.nonce
        }, function (res) {
            $btn.prop('disabled', false).text('📦 Archive Past Bookings');
            if (res.success) {
                var count = res.data.archived;
                alert(count + ' booking' + (count !== 1 ? 's' : '') + ' archived.');
                window.location.reload();
            }
        });
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

    // ── Blocked dates ──────────────────────────────────────────────────────────
    $('#nms-add-block').on('click', function () {
        var $btn  = $(this);
        var $msg  = $('#nms-block-msg');
        var from  = $('#nms-block-from').val();
        var to    = $('#nms-block-to').val();
        var space = $('#nms-block-space').val();
        var reason = $('#nms-block-reason').val();

        if (!from || !to) {
            $msg.text('Please select both From and To dates.').css('color', '#dc3232');
            return;
        }

        $btn.prop('disabled', true).text('Blocking…');

        $.post(MBS_Admin.ajax_url, {
            action:    'mbs_add_blocked',
            nonce:     MBS_Admin.nonce,
            date_from: from,
            date_to:   to,
            space:     space,
            reason:    reason
        }, function (res) {
            $btn.prop('disabled', false).text('Block Dates');
            if (res.success) {
                $msg.text('✓ Dates blocked').css('color', '#46b450');
                setTimeout(function () { window.location.reload(); }, 800);
            } else {
                $msg.text('✗ ' + (res.data || 'Error')).css('color', '#dc3232');
            }
        });
    });

    $(document).on('click', '.nms-delete-block', function () {
        var $btn = $(this);
        var id   = $btn.data('id');
        if (!confirm('Remove this blocked date entry?')) return;

        $.post(MBS_Admin.ajax_url, {
            action: 'mbs_delete_blocked',
            nonce:  MBS_Admin.nonce,
            id:     id
        }, function (res) {
            if (res.success) {
                $('#nms-block-row-' + id).fadeOut(300, function () { $(this).remove(); });
            }
        });
    });

    // ── Clear expired blocks ───────────────────────────────────────────────────
    $('#nms-clear-expired').on('click', function () {
        if (!confirm('Remove all expired blocked dates?')) return;
        var $btn = $(this);
        $btn.prop('disabled', true).text('Removing…');

        $.post(MBS_Admin.ajax_url, {
            action: 'mbs_clear_expired_blocks',
            nonce:  MBS_Admin.nonce
        }, function (res) {
            $btn.prop('disabled', false).text('🗑 Remove All Expired');
            if (res.success) {
                window.location.reload();
            }
        });
    });

    // ── Save settings ──────────────────────────────────────────────────────────
    $('#nms-save-all').on('click', function () {
        var $btn = $(this);
        var $msg = $('#nms-save-msg');
        $btn.prop('disabled', true).text('Saving…');

        // Sync all TinyMCE editors back to their textareas before reading values
        if (typeof tinyMCE !== 'undefined') {
            tinyMCE.triggerSave();
        }

        // Collect spaces data
        var spaces = [];
        $('#nms-spaces-tbody .nms-space-row').each(function () {
            var name        = $(this).find('.nms-space-name').val().trim();
            var rateHourly  = $(this).find('.nms-space-rate-hourly').val();
            var rateDaily   = $(this).find('.nms-space-rate-daily').val();
            var capacity    = $(this).find('.nms-space-capacity').val();
            var parent      = $(this).find('.nms-space-parent').val() || '';
            if (name) {
                spaces.push({ name: name, rate_hourly: rateHourly, rate_daily: rateDaily, capacity: capacity, parent: parent });
            }
        });

        // Collect pricing tiers
        var tiers = [];
        $('#nms-tiers-tbody .nms-tier-row').each(function () {
            var key        = $(this).find('.nms-tier-key').val().trim();
            var label      = $(this).find('.nms-tier-label').val().trim();
            var multiplier = $(this).find('.nms-tier-multiplier').val();
            if (key && label) {
                tiers.push({ key: key, label: label, multiplier: multiplier });
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
            bank_sort_code:     $('#bank_sort_code').val(),
            bank_account_number: $('#bank_account_number').val(),
            bank_account_name:  $('#bank_account_name').val(),
            payment_terms_days: $('#payment_terms_days').val(),
            reminder_hours:  $('#reminder_hours').val(),
            terms_page_id:      $('#terms_page_id').val(),
            auto_archive_days:   $('#auto_archive_days').val(),
            auto_chase_enabled:      $('#auto_chase_enabled').val(),
            scout_volunteer_emails:  $('#scout_volunteer_emails').val(),
            additional_emails:       $('#additional_emails').val(),
            venue_capacity:       $('#venue_capacity').val(),
            curfew_saturday:      $('#curfew_saturday').val(),
            curfew_sunday:        $('#curfew_sunday').val(),
            payment_days_required: $('#payment_days_required').val(),
            booking_notice:       $('#booking_notice').val(),
            facilities_text:      $('#facilities_text').val(),
            terms_text:           $('#terms_text').val(),
            deposit_enabled:      $('#deposit_enabled').val(),
            deposit_percentage:   $('#deposit_percentage').val(),
            deposit_balance_days: $('#deposit_balance_days').val(),
            pricing_tiers:        tiers,
            spaces:              spaces
        }, function (res) {
            $btn.prop('disabled', false).text('💾 Save All Settings');
            if (res.success) {
                $msg.text('✓ All settings saved successfully').css('color', '#46b450').show();
            } else {
                $msg.text('✗ Error saving settings').css('color', '#dc3232').show();
            }
            setTimeout(function () { $msg.text('').hide(); }, 4000);
        }).fail(function () {
            $btn.prop('disabled', false).text('💾 Save All Settings');
            $msg.text('✗ Network error – please try again').css('color', '#dc3232').show();
        });
    });

    // ── Add space row ──────────────────────────────────────────────────────────
    $('#nms-add-space').on('click', function () {
        var row = '<tr class="nms-space-row">' +
            '<td><input type="text" class="nms-space-name regular-text" value="" placeholder="e.g. Activity Room"></td>' +
            '<td><input type="number" class="nms-space-rate-hourly" value="0" min="0" step="0.01" style="width:80px"></td>' +
            '<td><input type="number" class="nms-space-rate-daily" value="0" min="0" step="0.01" style="width:80px"></td>' +
            '<td><input type="number" class="nms-space-capacity" value="" min="1" style="width:70px" placeholder="—"></td>' +
            '<td><input type="text" class="nms-space-parent" value="" placeholder="None" style="width:120px;"></td>' +
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

    // ── Deposit toggle (show/hide fields based on enabled state) ──────────────
    $('#deposit_enabled').on('change', function () {
        var enabled = $(this).val() === '1';
        $('.mbs-deposit-field').toggle(enabled);
        $('.mbs-no-deposit-field').toggle(!enabled);
    });

    // ── Add pricing tier row ───────────────────────────────────────────────────
    $('#nms-add-tier').on('click', function () {
        var row = '<tr class="nms-tier-row">' +
            '<td><input type="text" class="nms-tier-key" value="" style="width:120px;" placeholder="e.g. nonprofit"></td>' +
            '<td><input type="text" class="nms-tier-label" value="" style="width:180px;" placeholder="e.g. Non-Profit"></td>' +
            '<td><input type="number" class="nms-tier-multiplier" value="1.0" min="0" step="0.05" style="width:80px;"> ×</td>' +
            '<td><button type="button" class="button nms-remove-tier">&times;</button></td>' +
            '</tr>';
        $('#nms-tiers-tbody').append(row);
    });

    // ── Remove pricing tier row ────────────────────────────────────────────────
    $(document).on('click', '.nms-remove-tier', function () {
        var $row = $(this).closest('.nms-tier-row');
        var key = $row.find('.nms-tier-key').val();
        if (key === 'standard') { alert('Cannot remove the Standard tier.'); return; }
        if (key && !confirm('Remove pricing tier "' + key + '"?')) return;
        $row.remove();
    });

    // ── Test HA webhook ────────────────────────────────────────────────────────
    $('#nms-test-ha').on('click', function () {
        var $btn = $(this);
        var $msg = $('#nms-ha-msg');
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

    // ── Bulk actions ───────────────────────────────────────────────────────────
    $('#nms-select-all').on('change', function() {
        $('.nms-bulk-check').prop('checked', $(this).is(':checked'));
        updateBulkCount();
    });
    $(document).on('change', '.nms-bulk-check', updateBulkCount);

    function updateBulkCount() {
        var count = $('.nms-bulk-check:checked').length;
        $('#nms-bulk-count').text(count > 0 ? count + ' selected' : '');
    }

    $('#nms-bulk-apply').on('click', function() {
        var action = $('#nms-bulk-action').val();
        var refs   = [];
        var $msg   = $('#nms-bulk-msg');

        $('.nms-bulk-check:checked').each(function() {
            var ref = $(this).val();
            // For series rows, add all bookings in the series
            var series = $(this).data('series');
            if (series) {
                $('.nms-series-' + series).find('.nms-bulk-check').each(function() {
                    refs.push($(this).val());
                });
            }
            refs.push(ref);
        });

        // Deduplicate
        refs = refs.filter(function(v, i, a) { return a.indexOf(v) === i; });

        if (!action) { $msg.text('Please select an action.').css('color', '#dc3232'); return; }
        if (refs.length === 0) { $msg.text('Please select at least one booking.').css('color', '#dc3232'); return; }

        var labels = { confirmed: 'confirm', paid: 'mark as paid', cancelled: 'cancel', archived: 'archive' };
        if (!confirm('Are you sure you want to ' + (labels[action] || action) + ' ' + refs.length + ' booking(s)?\n\nInvalid transitions will be skipped.')) return;

        var $btn = $(this);
        $btn.prop('disabled', true).text('Processing…');

        $.post(MBS_Admin.ajax_url, {
            action:      'mbs_bulk_action',
            nonce:       MBS_Admin.nonce,
            bulk_action: action,
            refs:        refs
        }, function(res) {
            $btn.prop('disabled', false).text('Apply');
            if (res.success) {
                var d = res.data;
                $msg.text('✓ ' + d.processed + ' processed, ' + d.skipped + ' skipped').css('color', '#46b450');
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                $msg.text('✗ ' + (res.data && res.data.message ? res.data.message : 'Error')).css('color', '#dc3232');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Apply');
            $msg.text('✗ Network error — please try again.').css('color', '#dc3232');
        });
    });

    // ── Approve/reject modification requests ────────────────────────────────────
    $(document).on('click', '.nms-approve-request', function () {
        var $btn = $(this);
        var id   = $btn.data('id');
        if (!confirm('Approve this request? Changes will be applied automatically and the booker will be notified.')) return;
        $btn.prop('disabled', true).text('Approving…');

        $.post(MBS_Admin.ajax_url, {
            action: 'mbs_approve_request',
            nonce:  MBS_Admin.nonce,
            id:     id
        }, function (res) {
            if (res.success) {
                window.location.reload();
            } else {
                alert('Error: ' + (res.data && res.data.message ? res.data.message : 'Unknown error'));
                $btn.prop('disabled', false).text('✓ Approve');
            }
        }).fail(function () {
            alert('Network error — please try again.');
            $btn.prop('disabled', false).text('✓ Approve');
        });
    });

    $(document).on('click', '.nms-reject-request', function () {
        var $btn   = $(this);
        var id     = $btn.data('id');
        var type   = $btn.data('type');
        var reason = prompt('Reject this ' + (type === 'cancel' ? 'cancellation' : 'modification') + ' request?\n\nOptionally enter a reason (this will be sent to the booker):');
        if (reason === null) return;
        $btn.prop('disabled', true).text('Rejecting…');

        $.post(MBS_Admin.ajax_url, {
            action: 'mbs_reject_request',
            nonce:  MBS_Admin.nonce,
            id:     id,
            reason: reason
        }, function (res) {
            if (res.success) {
                window.location.reload();
            } else {
                alert('Error: ' + (res.data && res.data.message ? res.data.message : 'Unknown error'));
                $btn.prop('disabled', false).text('✗ Reject');
            }
        }).fail(function () {
            alert('Network error — please try again.');
            $btn.prop('disabled', false).text('✗ Reject');
        });
    });

    // ── Toggle series expansion ────────────────────────────────────────────────
    $(document).on('click', '.nms-toggle-series', function () {
        var $btn   = $(this);
        var series = $btn.data('series');
        var $rows  = $('.nms-series-' + series);
        var visible = $rows.first().is(':visible');

        if (visible) {
            $rows.hide();
            $btn.text('▶ Expand');
        } else {
            $rows.show();
            $btn.text('▼ Collapse');
        }
    });

    // ── Chase payment ──────────────────────────────────────────────────────────
    $(document).on('click', '.nms-btn-chase', function () {
        var $btn = $(this);
        var ref  = $btn.data('ref');
        if (!confirm('Send a payment reminder email to the booker for ' + ref + '?')) return;
        $btn.prop('disabled', true).text('Sending…');

        $.post(MBS_Admin.ajax_url, {
            action: 'mbs_chase_payment',
            nonce:  MBS_Admin.nonce,
            ref:    ref
        }, function (res) {
            $btn.prop('disabled', false).text('📧 Chase Payment');
            if (res.success) {
                alert('Payment reminder sent (chase #' + res.data.chase_count + ').');
                window.location.reload();
            } else {
                alert('Error: ' + (res.data && res.data.message ? res.data.message : 'Unknown error'));
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('📧 Chase Payment');
            alert('Network error — please try again.');
        });
    });

    // ── Series status update ───────────────────────────────────────────────────
    $(document).on('click', '.nms-btn-series-status', function () {
        var $btn     = $(this);
        var seriesId = $btn.data('series');
        var status   = $btn.data('status');
        var label    = status.charAt(0).toUpperCase() + status.slice(1);

        if (!confirm(label + ' ALL bookings in series ' + seriesId + '?')) return;
        $btn.prop('disabled', true);

        $.post(MBS_Admin.ajax_url, {
            action:    'mbs_update_series_status',
            nonce:     MBS_Admin.nonce,
            series_id: seriesId,
            status:    status
        }, function (res) {
            $btn.prop('disabled', false);
            if (res.success) {
                alert(res.data.count + ' booking(s) ' + status + '.');
                window.location.reload();
            } else {
                alert('Error: ' + (res.data || 'Unknown error'));
            }
        });
    });

    // ── Save admin notes ───────────────────────────────────────────────────────
    $(document).on('click', '#nms-save-notes', function () {
        var $btn = $(this);
        var $msg = $('#nms-notes-msg');
        var ref  = $btn.data('ref');
        $btn.prop('disabled', true).text('Saving…');

        $.post(MBS_Admin.ajax_url, {
            action:      'mbs_save_admin_notes',
            nonce:       MBS_Admin.nonce,
            ref:         ref,
            admin_notes: $('#nms-admin-notes').val()
        }, function (res) {
            $btn.prop('disabled', false).text('Save Notes');
            if (res.success) {
                $msg.text('✓ Saved').css('color', '#46b450');
            } else {
                $msg.text('✗ Error').css('color', '#dc3232');
            }
            setTimeout(function () { $msg.text(''); }, 3000);
        });
    });

    // ── Upload logo via WordPress media library ───────────────────────────────
    $('#mbs-upload-logo').on('click', function(e) {
        e.preventDefault();
        var frame = wp.media({ title: 'Select Logo', multiple: false, library: { type: 'image' } });
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#org_logo_url').val(attachment.url);
        });
        frame.open();
    });

    // ── Save email settings ────────────────────────────────────────────────────
    $('#mbs-save-email-settings').on('click', function () {
        var $btn = $(this);
        var $msg = $('#mbs-email-save-msg');
        $btn.prop('disabled', true).text('Saving…');

        // Collect templates
        var templates = {};
        $('.mbs-tpl-subject').each(function () {
            var type = $(this).data('type');
            templates[type] = {
                subject: $(this).val(),
                body:    $('.mbs-tpl-body[data-type="' + type + '"]').val()
            };
        });

        $.post(MBS_Admin.ajax_url, {
            action:              'mbs_save_email_settings',
            nonce:               MBS_Admin.nonce,
            org_name:            $('#org_name').val(),
            org_address:         $('#org_address').val(),
            org_phone:           $('#org_phone').val(),
            org_charity_number:  $('#org_charity_number').val(),
            org_logo_url:        $('#org_logo_url').val(),
            max_chase_emails:    $('#max_chase_emails').val(),
            chase_interval_days: $('#chase_interval_days').val(),
            cron_time_reminders: $('#cron_time_reminders').val(),
            cron_time_chase:     $('#cron_time_chase').val(),
            cron_time_archive:   $('#cron_time_archive').val(),
            templates:           templates
        }, function (res) {
            $btn.prop('disabled', false).text('💾 Save Email Settings');
            if (res.success) {
                $msg.text('✓ Email settings saved').css('color', '#46b450').show();
            } else {
                $msg.text('✗ Error saving').css('color', '#dc3232').show();
            }
            setTimeout(function () { $msg.text('').hide(); }, 4000);
        });
    });

    // ── Reset email template to default ────────────────────────────────────────
    $(document).on('click', '.mbs-tpl-reset', function () {
        var type = $(this).data('type');
        if (!confirm('Reset this email template to its default?')) return;
        $('.mbs-tpl-subject[data-type="' + type + '"]').val($(this).data('default-subject'));
        $('.mbs-tpl-body[data-type="' + type + '"]').val($(this).data('default-body'));
    });

    // ── Helper: update status via AJAX ─────────────────────────────────────────
    function nmsUpdateStatus(ref, status, $btn, redirect, reason) {
        var origText = $btn.text();
        $btn.prop('disabled', true);

        var data = {
            action: 'mbs_update_status',
            nonce:  MBS_Admin.nonce,
            ref:    ref,
            status: status
        };
        if (reason) data.reason = reason;

        $.post(MBS_Admin.ajax_url, data, function (res) {
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
                alert('Error: ' + (res.data && res.data.message ? res.data.message : 'Could not update booking status.'));
                $btn.prop('disabled', false).text(origText);
            }
        }).fail(function () {
            alert('Network error — please try again.');
            $btn.prop('disabled', false).text(origText);
        });
    }
});
