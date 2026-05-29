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
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $bookings as $b ) : ?>
                        <tr>
                            <td><?php echo esc_html( $b->ref ); ?></td>
                            <td><strong><?php echo esc_html( $b->purpose ); ?></strong></td>
                            <td><?php echo esc_html( $b->space ); ?></td>
                            <td><?php echo esc_html( wp_date( 'D j M Y', strtotime( $b->booking_date ) ) ); ?></td>
                            <td><?php echo $b->all_day ? 'All day' : esc_html( $b->start_time . ' – ' . $b->end_time ); ?></td>
                            <td><?php echo esc_html( $b->series_id ?: '—' ); ?></td>
                            <td>
                                <button class="button button-small nms-btn-cancel" data-ref="<?php echo esc_attr( $b->ref ); ?>">Cancel</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
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
});
</script>
