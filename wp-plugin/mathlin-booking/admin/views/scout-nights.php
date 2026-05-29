<?php if ( ! defined( 'ABSPATH' ) ) exit;
$spaces = MBS_Bookings::get_spaces();
$bookings = MBS_Bookings::get_all( array(
    'exclude_archived' => true,
    'exclude_scout'    => false,
    'orderby'          => 'booking_date',
    'order'            => 'ASC',
    'limit'            => 500,
) );
// Filter to only scout_use bookings
$bookings = array_filter( $bookings, function( $b ) { return ! empty( $b->scout_use ); } );
$can_delete = current_user_can( 'manage_options' );
?>
<div class="wrap mbs-admin">
    <h1>⚜️ Scout Nights</h1>
    <p>Manage recurring scout section bookings. These block availability on the public calendar but don't appear in the main bookings list.</p>

    <!-- Create Recurring Form -->
    <div class="nms-card">
        <div class="nms-card-header"><h2>Create Recurring Scout Booking</h2></div>
        <div style="padding:1.5rem;">
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:12px;margin-bottom:16px;">
                <div>
                    <label class="nms-edit-label">Space</label>
                    <select id="scout-space" style="width:100%;">
                        <?php foreach ( $spaces as $name => $info ) : ?>
                            <option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="nms-edit-label">Day of Week</label>
                    <select id="scout-day" style="width:100%;">
                        <option value="1">Monday</option>
                        <option value="2">Tuesday</option>
                        <option value="3">Wednesday</option>
                        <option value="4">Thursday</option>
                        <option value="5">Friday</option>
                        <option value="6">Saturday</option>
                        <option value="7">Sunday</option>
                    </select>
                </div>
                <div>
                    <label class="nms-edit-label">Start Time</label>
                    <input type="time" id="scout-start" value="18:30" style="width:100%;">
                </div>
                <div>
                    <label class="nms-edit-label">End Time</label>
                    <input type="time" id="scout-end" value="20:00" style="width:100%;">
                </div>
                <div>
                    <label class="nms-edit-label">Section / Purpose</label>
                    <input type="text" id="scout-purpose" value="Scouts" placeholder="e.g. Beavers, Cubs, Scouts" style="width:100%;">
                </div>
                <div>
                    <label class="nms-edit-label">Start Date</label>
                    <input type="date" id="scout-date-from" value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" style="width:100%;">
                </div>
                <div>
                    <label class="nms-edit-label">End Date</label>
                    <input type="date" id="scout-date-to" value="<?php echo esc_attr( wp_date( 'Y-m-d', strtotime( '+1 year' ) ) ); ?>" style="width:100%;">
                </div>
            </div>
            <button id="nms-create-scout-recurring" class="button button-primary button-hero">⚜️ Create Recurring Scout Bookings</button>
            <span id="nms-scout-msg" style="margin-left:12px;"></span>
        </div>
    </div>

    <!-- Existing Scout Bookings -->
    <div class="nms-card" style="margin-top:1.5rem;">
        <div class="nms-card-header"><h2>Upcoming Scout Bookings (<?php echo count( $bookings ); ?>)</h2></div>
        <?php if ( empty( $bookings ) ) : ?>
            <p style="padding:1.5rem;color:#6b7280;">No scout bookings found.</p>
        <?php else : ?>
            <div style="overflow-x:auto;">
                <table class="widefat" style="border:none;">
                    <thead>
                        <tr>
                            <th>Ref</th>
                            <th>Section</th>
                            <th>Space</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Series</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $bookings as $b ) :
                            $is_cancelled = ( $b->status === 'cancelled' );
                        ?>
                        <tr<?php echo $is_cancelled ? ' style="opacity:0.55;"' : ''; ?>>
                            <td><?php echo esc_html( $b->ref ); ?></td>
                            <td><strong><?php echo esc_html( $b->purpose ); ?></strong></td>
                            <td><?php echo esc_html( $b->space ); ?></td>
                            <td><?php echo esc_html( wp_date( 'D j M Y', strtotime( $b->booking_date ) ) ); ?></td>
                            <td><?php echo $b->all_day ? 'All day' : esc_html( $b->start_time . ' – ' . $b->end_time ); ?></td>
                            <td><?php echo esc_html( $b->series_id ?: '—' ); ?></td>
                            <td>
                                <?php if ( $is_cancelled ) : ?>
                                    <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:#fde2e2;color:#991b1b;">Cancelled</span>
                                <?php else : ?>
                                    <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:#d1fae5;color:#065f46;"><?php echo esc_html( ucfirst( $b->status ) ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $is_cancelled ) : ?>
                                    <button class="button button-small nms-btn-reopen" data-ref="<?php echo esc_attr( $b->ref ); ?>" data-restore-status="confirmed" data-redirect="1">Reopen</button>
                                <?php else : ?>
                                    <button class="button button-small nms-btn-cancel" data-ref="<?php echo esc_attr( $b->ref ); ?>">Cancel</button>
                                <?php endif; ?>
                                <?php if ( ! empty( $b->series_id ) ) : ?>
                                <button class="button button-small nms-btn-edit-series"
                                        data-series="<?php echo esc_attr( $b->series_id ); ?>"
                                        data-space="<?php echo esc_attr( $b->space ); ?>"
                                        data-start="<?php echo esc_attr( substr( (string) $b->start_time, 0, 5 ) ); ?>"
                                        data-end="<?php echo esc_attr( substr( (string) $b->end_time, 0, 5 ) ); ?>"
                                        data-purpose="<?php echo esc_attr( $b->purpose ); ?>">Edit Series</button>
                                <?php if ( $is_cancelled ) : ?>
                                <button class="button button-small nms-btn-reopen-series" data-series="<?php echo esc_attr( $b->series_id ); ?>">Reopen Series</button>
                                <?php else : ?>
                                <button class="button button-small nms-btn-cancel-series" data-series="<?php echo esc_attr( $b->series_id ); ?>" style="background:#dc3232;border-color:#dc3232;color:#fff;">Cancel Series</button>
                                <?php endif; ?>
                                <?php if ( $can_delete ) : ?>
                                <button class="button button-small nms-btn-delete-series" data-series="<?php echo esc_attr( $b->series_id ); ?>" style="background:#7f1d1d;border-color:#7f1d1d;color:#fff;">Delete Series</button>
                                <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Edit Series Modal -->
<div id="nms-edit-series-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:100000;">
    <div style="background:#fff;max-width:480px;margin:8vh auto;border-radius:8px;padding:24px;box-shadow:0 10px 40px rgba(0,0,0,0.3);">
        <h2 style="margin-top:0;">⚜️ Edit Scout Series <span id="nms-edit-series-id" style="color:#7413DC;"></span></h2>
        <p style="color:#6b7280;font-size:13px;">Changes apply to <strong>all future bookings</strong> in this series (today onwards). Past bookings are left unchanged. Any date where the new time clashes with another booking is skipped.</p>
        <input type="hidden" id="nms-edit-series-series">
        <div style="display:grid;gap:12px;margin:16px 0;">
            <div>
                <label class="nms-edit-label">Space</label>
                <select id="nms-edit-series-space" style="width:100%;">
                    <?php foreach ( $spaces as $name => $info ) : ?>
                        <option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <label class="nms-edit-label">Start Time</label>
                    <input type="time" id="nms-edit-series-start" style="width:100%;">
                </div>
                <div>
                    <label class="nms-edit-label">End Time</label>
                    <input type="time" id="nms-edit-series-end" style="width:100%;">
                </div>
            </div>
            <div>
                <label class="nms-edit-label">Section / Purpose</label>
                <input type="text" id="nms-edit-series-purpose" style="width:100%;">
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px;">
            <button class="button" id="nms-edit-series-cancel">Cancel</button>
            <button class="button button-primary" id="nms-edit-series-save">Save Changes to Series</button>
        </div>
        <span id="nms-edit-series-msg" style="display:block;margin-top:12px;"></span>

        <hr style="margin:20px 0;border:none;border-top:1px solid #e5e7eb;">

        <h3 style="margin:0 0 4px;">📅 Extend Series</h3>
        <p style="color:#6b7280;font-size:13px;margin-top:0;">Add more weekly bookings, continuing on the same day and time. Up to 52 weeks can be added at a time — run again for more. Conflicting or blocked dates are skipped.</p>
        <div style="display:flex;gap:8px;align-items:flex-end;">
            <div style="flex:1;">
                <label class="nms-edit-label">Extend until</label>
                <input type="date" id="nms-edit-series-extend-until" style="width:100%;">
            </div>
            <button class="button button-secondary" id="nms-edit-series-extend">Extend</button>
        </div>
        <span id="nms-extend-series-msg" style="display:block;margin-top:12px;"></span>
    </div>
</div>

<script>
jQuery(function($) {
    $('#nms-create-scout-recurring').on('click', function() {
        var $btn = $(this);
        var $msg = $('#nms-scout-msg');
        $btn.prop('disabled', true).text('Creating…');
        $msg.text('');

        $.post(MBS_Admin.ajax_url, {
            action:     'mbs_create_scout_recurring',
            nonce:      MBS_Admin.nonce,
            space:      $('#scout-space').val(),
            day_of_week: $('#scout-day').val(),
            start_time: $('#scout-start').val(),
            end_time:   $('#scout-end').val(),
            purpose:    $('#scout-purpose').val(),
            date_from:  $('#scout-date-from').val(),
            date_to:    $('#scout-date-to').val()
        }, function(res) {
            $btn.prop('disabled', false).text('⚜️ Create Recurring Scout Bookings');
            if (res.success) {
                $msg.css('color', '#2ecc71').text('✓ Created ' + res.data.created + ' booking(s)' + (res.data.skipped > 0 ? ' (' + res.data.skipped + ' skipped due to conflicts)' : ''));
                setTimeout(function() { window.location.reload(); }, 2000);
            } else {
                $msg.css('color', '#dc3232').text('✗ ' + (res.data || 'Error creating bookings'));
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('⚜️ Create Recurring Scout Bookings');
            $msg.css('color', '#dc3232').text('✗ Network error');
        });
    });

    // ── Cancel an entire scout series (future bookings only) ────────────────────
    $(document).on('click', '.nms-btn-cancel-series', function() {
        var $btn     = $(this);
        var seriesId = $btn.data('series');
        if (!confirm('Are you sure? This will cancel all future bookings in this series.')) return;

        $btn.prop('disabled', true).text('Cancelling…');
        $.post(MBS_Admin.ajax_url, {
            action:    'mbs_cancel_scout_series',
            nonce:     MBS_Admin.nonce,
            series_id: seriesId
        }, function(res) {
            if (res.success) {
                alert(res.data.message);
                window.location.reload();
            } else {
                $btn.prop('disabled', false).text('Cancel Series');
                alert('Error: ' + (res.data || 'Unknown error'));
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Cancel Series');
            alert('Network error cancelling series.');
        });
    });

    // ── Reopen an entire scout series (future cancelled bookings) ───────────────
    $(document).on('click', '.nms-btn-reopen-series', function() {
        var $btn     = $(this);
        var seriesId = $btn.data('series');
        if (!confirm('Reopen all future cancelled bookings in series ' + seriesId + '?\n\nThey will be set back to Confirmed (no emails are sent).')) return;

        $btn.prop('disabled', true).text('Reopening…');
        $.post(MBS_Admin.ajax_url, {
            action:    'mbs_reopen_scout_series',
            nonce:     MBS_Admin.nonce,
            series_id: seriesId
        }, function(res) {
            if (res.success) {
                alert(res.data.message);
                window.location.reload();
            } else {
                $btn.prop('disabled', false).text('Reopen Series');
                alert('Error: ' + (res.data || 'Unknown error'));
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Reopen Series');
            alert('Network error reopening series.');
        });
    });

    // ── Permanently delete an entire scout series (past + future) ───────────────
    $(document).on('click', '.nms-btn-delete-series', function() {
        var $btn     = $(this);
        var seriesId = $btn.data('series');
        if (!confirm('⚠️ PERMANENTLY DELETE the entire series ' + seriesId + '?\n\nThis removes ALL bookings in the series — past and future — and cannot be undone.')) return;
        if (!confirm('Are you absolutely sure? This is your last chance to cancel.')) return;

        $btn.prop('disabled', true).text('Deleting…');
        $.post(MBS_Admin.ajax_url, {
            action:    'mbs_delete_scout_series',
            nonce:     MBS_Admin.nonce,
            series_id: seriesId
        }, function(res) {
            if (res.success) {
                alert(res.data.message);
                window.location.reload();
            } else {
                $btn.prop('disabled', false).text('Delete Series');
                alert('Error: ' + (res.data || 'Unknown error'));
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Delete Series');
            alert('Network error deleting series.');
        });
    });

    // ── Edit an entire scout series (future bookings only) ──────────────────────
    $(document).on('click', '.nms-btn-edit-series', function() {
        var $btn = $(this);
        $('#nms-edit-series-series').val($btn.data('series'));
        $('#nms-edit-series-id').text($btn.data('series'));
        $('#nms-edit-series-space').val($btn.data('space'));
        $('#nms-edit-series-start').val($btn.data('start'));
        $('#nms-edit-series-end').val($btn.data('end'));
        $('#nms-edit-series-purpose').val($btn.data('purpose'));
        $('#nms-edit-series-msg').text('');
        $('#nms-extend-series-msg').text('');
        $('#nms-edit-series-extend-until').val('');
        $('#nms-edit-series-modal').css('display', 'block');
    });

    $('#nms-edit-series-cancel').on('click', function() {
        $('#nms-edit-series-modal').hide();
    });

    $('#nms-edit-series-save').on('click', function() {
        var $btn = $(this);
        var $msg = $('#nms-edit-series-msg');
        var seriesId = $('#nms-edit-series-series').val();
        var start = $('#nms-edit-series-start').val();
        var end   = $('#nms-edit-series-end').val();

        if (start && end && end <= start) {
            $msg.css('color', '#dc3232').text('✗ End time must be after start time.');
            return;
        }
        if (!confirm('Apply these changes to all future bookings in series ' + seriesId + '?')) return;

        $btn.prop('disabled', true).text('Saving…');
        $.post(MBS_Admin.ajax_url, {
            action:     'mbs_edit_scout_series',
            nonce:      MBS_Admin.nonce,
            series_id:  seriesId,
            space:      $('#nms-edit-series-space').val(),
            start_time: start,
            end_time:   end,
            purpose:    $('#nms-edit-series-purpose').val()
        }, function(res) {
            $btn.prop('disabled', false).text('Save Changes to Series');
            if (res.success) {
                alert(res.data.message);
                window.location.reload();
            } else {
                $msg.css('color', '#dc3232').text('✗ ' + (res.data || 'Error updating series'));
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Save Changes to Series');
            $msg.css('color', '#dc3232').text('✗ Network error');
        });
    });

    $('#nms-edit-series-extend').on('click', function() {
        var $btn = $(this);
        var $msg = $('#nms-extend-series-msg');
        var seriesId = $('#nms-edit-series-series').val();
        var until = $('#nms-edit-series-extend-until').val();

        if (!until) {
            $msg.css('color', '#dc3232').text('✗ Please choose a date to extend until.');
            return;
        }
        if (!confirm('Add weekly bookings to series ' + seriesId + ' up to ' + until + '?')) return;

        $btn.prop('disabled', true).text('Extending…');
        $.post(MBS_Admin.ajax_url, {
            action:       'mbs_extend_scout_series',
            nonce:        MBS_Admin.nonce,
            series_id:    seriesId,
            extend_until: until
        }, function(res) {
            $btn.prop('disabled', false).text('Extend');
            if (res.success) {
                alert(res.data.message);
                window.location.reload();
            } else {
                $msg.css('color', '#dc3232').text('✗ ' + (res.data || 'Error extending series'));
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Extend');
            $msg.css('color', '#dc3232').text('✗ Network error');
        });
    });
});
</script>
