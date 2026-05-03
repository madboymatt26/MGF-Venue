<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$ref   = sanitize_text_field( $_GET['ref'] ?? '' );
$token = sanitize_text_field( $_GET['token'] ?? '' );

if ( ! $ref || ! $token || ! MBS_Modification::verify_token( $ref, $token ) ) {
    echo '<div class="nms-alert nms-alert-error">Invalid or expired modification link. Please contact us directly.</div>';
    return;
}

$booking = MBS_Bookings::get( $ref );
if ( ! $booking || in_array( $booking->status, array( 'cancelled', 'archived' ) ) ) {
    echo '<div class="nms-alert nms-alert-error">This booking cannot be modified.</div>';
    return;
}

$all_spaces    = MBS_Bookings::get_spaces();
$is_daily      = ! empty( $booking->all_day );
$time_str      = $is_daily ? 'All day' : ( $booking->start_time . ' – ' . $booking->end_time );
$kitchen_price = MBS_Bookings::get_kitchen_price();
?>

<div class="nms-wrap">
    <h2 class="nms-section-title">Request a Booking Change</h2>
    <p class="nms-section-sub">Use this form to request changes to your booking or request a cancellation. Changes are subject to availability and admin approval.</p>

    <div id="nms-mod-success" class="nms-alert nms-alert-success" style="display:none"></div>
    <div id="nms-mod-error" class="nms-alert nms-alert-error" style="display:none"></div>

    <!-- Current booking details -->
    <div class="nms-form-section">
        <h3>Current Booking</h3>
        <table style="width:100%;font-size:0.9rem;">
            <tr><td style="padding:6px 0;font-weight:600;color:#6b7280;width:35%;">Reference</td><td><?php echo esc_html( $booking->ref ); ?></td></tr>
            <tr><td style="padding:6px 0;font-weight:600;color:#6b7280;">Space</td><td><?php echo esc_html( $booking->space ); ?></td></tr>
            <tr><td style="padding:6px 0;font-weight:600;color:#6b7280;">Date</td><td><?php echo esc_html( date( 'l j F Y', strtotime( $booking->booking_date ) ) ); ?></td></tr>
            <tr><td style="padding:6px 0;font-weight:600;color:#6b7280;">Time</td><td><?php echo esc_html( $time_str ); ?></td></tr>
            <tr><td style="padding:6px 0;font-weight:600;color:#6b7280;">Amount</td><td>&pound;<?php echo number_format( $booking->amount, 2 ); ?></td></tr>
            <tr><td style="padding:6px 0;font-weight:600;color:#6b7280;">Status</td><td><?php echo esc_html( ucfirst( $booking->status ) ); ?></td></tr>
        </table>
    </div>

    <!-- Modification form -->
    <form id="nms-modification-form" class="nms-form" style="margin-top:1rem;">
        <?php wp_nonce_field( 'mbs_public_nonce', 'nonce' ); ?>
        <input type="hidden" name="ref" value="<?php echo esc_attr( $ref ); ?>">
        <input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">

        <div class="nms-form-section">
            <h3>What would you like to do?</h3>
            <div class="nms-form-group">
                <select id="nms-mod-action" name="mod_action" style="font-size:1rem;padding:10px;">
                    <option value="modify">✏️ Modify this booking</option>
                    <option value="cancel">❌ Request cancellation</option>
                </select>
            </div>
        </div>

        <!-- Cancellation section (hidden by default) -->
        <div id="nms-cancel-section" style="display:none;">
            <div class="nms-form-section">
                <h3>Cancellation Request</h3>
                <div class="nms-form-group">
                    <label for="nms-mod-cancel-reason">Reason for cancellation</label>
                    <textarea id="nms-mod-cancel-reason" name="cancel_reason" rows="3" placeholder="Please let us know why you need to cancel..."></textarea>
                </div>
            </div>
        </div>

        <!-- Modification section -->
        <div id="nms-modify-section">
            <div class="nms-form-section">
                <h3>Requested Changes</h3>
                <p style="font-size:0.85rem;color:#6b7280;margin-bottom:1rem;">Change any fields below. The cost estimate will update automatically.</p>

                <div class="nms-form-row">
                    <div class="nms-form-group">
                        <label for="nms-mod-space">Space</label>
                        <select id="nms-mod-space" name="new_space">
                            <?php foreach ( $all_spaces as $sname => $sinfo ) : ?>
                            <option value="<?php echo esc_attr( $sname ); ?>" <?php selected( $booking->space, $sname ); ?>><?php echo esc_html( $sname ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="nms-form-group">
                        <label for="nms-mod-kitchen">Kitchen</label>
                        <select id="nms-mod-kitchen" name="new_kitchen">
                            <option value="0" <?php selected( $booking->kitchen, 0 ); ?>>No</option>
                            <option value="1" <?php selected( $booking->kitchen, 1 ); ?>>Yes (+&pound;<?php echo number_format( $kitchen_price, 0 ); ?>)</option>
                        </select>
                    </div>
                </div>
                <div class="nms-form-row">
                    <div class="nms-form-group">
                        <label for="nms-mod-type">Booking Type</label>
                        <select id="nms-mod-type" name="new_booking_type">
                            <option value="hourly" <?php echo ! $is_daily ? 'selected' : ''; ?>>Specific hours</option>
                            <option value="fullday" <?php echo $is_daily ? 'selected' : ''; ?>>Full day(s)</option>
                        </select>
                    </div>
                    <div class="nms-form-group">
                        <label for="nms-mod-attendees">Attendees</label>
                        <input type="number" id="nms-mod-attendees" name="new_attendees" value="<?php echo esc_attr( $booking->attendees ); ?>" min="1">
                    </div>
                </div>
                <div class="nms-form-row">
                    <div class="nms-form-group">
                        <label for="nms-mod-date">Start Date</label>
                        <input type="date" id="nms-mod-date" name="new_date" value="<?php echo esc_attr( $booking->booking_date ); ?>"
                               min="<?php echo esc_attr( date( 'Y-m-d', strtotime( '+1 day' ) ) ); ?>">
                    </div>
                    <div class="nms-form-group" id="nms-mod-enddate-group">
                        <label for="nms-mod-date-end">End Date</label>
                        <input type="date" id="nms-mod-date-end" name="new_date_end" value="<?php echo esc_attr( $booking->booking_date_end ?: $booking->booking_date ); ?>"
                               min="<?php echo esc_attr( date( 'Y-m-d', strtotime( '+1 day' ) ) ); ?>">
                        <p class="nms-field-hint">Same as start for single day</p>
                    </div>
                </div>
                <div class="nms-form-row" id="nms-mod-time-row">
                    <div class="nms-form-group">
                        <label for="nms-mod-start">Start Time</label>
                        <input type="time" id="nms-mod-start" name="new_start_time" value="<?php echo esc_attr( $booking->start_time ); ?>">
                    </div>
                    <div class="nms-form-group">
                        <label for="nms-mod-end">End Time</label>
                        <input type="time" id="nms-mod-end" name="new_end_time" value="<?php echo esc_attr( $booking->end_time ); ?>">
                    </div>
                </div>

                <!-- Cost preview -->
                <div style="margin-top:12px;padding:12px 16px;background:#f9f7ff;border-radius:8px;border:1px solid #e0d0f0;">
                    <div style="display:flex;justify-content:space-between;font-size:0.9rem;">
                        <span>Current amount:</span>
                        <span>&pound;<?php echo number_format( $booking->amount, 2 ); ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:0.9rem;margin-top:4px;">
                        <span>Estimated new amount:</span>
                        <strong id="nms-mod-new-cost">&pound;<?php echo number_format( $booking->amount, 2 ); ?></strong>
                    </div>
                    <div id="nms-mod-cost-diff" style="display:none;margin-top:8px;padding:6px 10px;border-radius:4px;font-size:0.8rem;font-weight:600;"></div>
                    <p style="font-size:0.75rem;color:#6b7280;margin:6px 0 0;">* Final amount confirmed by admin after review.</p>
                </div>
            </div>

            <div class="nms-form-section">
                <div class="nms-form-group">
                    <label for="nms-mod-changes">Additional notes</label>
                    <textarea id="nms-mod-changes" name="changes" rows="3" placeholder="Any other details about your change request..."></textarea>
                </div>
            </div>
        </div>

        <div class="nms-form-actions">
            <button type="submit" class="nms-btn nms-btn-primary nms-btn-lg" id="nms-mod-submit">
                Submit Request
            </button>
        </div>
    </form>
</div>

<script>
jQuery(function($) {
    var spacesData = <?php echo wp_json_encode( $all_spaces ); ?>;
    var kitchenPrice = <?php echo (float) $kitchen_price; ?>;
    var originalAmount = <?php echo (float) $booking->amount; ?>;

    // Toggle modify vs cancel
    $('#nms-mod-action').on('change', function() {
        if ($(this).val() === 'cancel') {
            $('#nms-modify-section').hide();
            $('#nms-cancel-section').show();
            $('#nms-mod-submit').text('Submit Cancellation Request');
        } else {
            $('#nms-modify-section').show();
            $('#nms-cancel-section').hide();
            $('#nms-mod-submit').text('Submit Change Request');
        }
    });

    // Toggle time fields based on booking type
    $('#nms-mod-type').on('change', function() {
        var isFullDay = $(this).val() === 'fullday';
        $('#nms-mod-time-row').css({ opacity: isFullDay ? 0.4 : 1, pointerEvents: isFullDay ? 'none' : 'auto' });
        recalcModCost();
    });

    // Sync end date min with start date
    $('#nms-mod-date').on('change', function() {
        var val = $(this).val();
        if (val) {
            $('#nms-mod-date-end').attr('min', val);
            if ($('#nms-mod-date-end').val() < val) $('#nms-mod-date-end').val(val);
        }
        recalcModCost();
    });

    // Live cost recalculation
    $('#nms-mod-space, #nms-mod-start, #nms-mod-end, #nms-mod-kitchen, #nms-mod-type, #nms-mod-date, #nms-mod-date-end').on('change', recalcModCost);

    function recalcModCost() {
        var space   = $('#nms-mod-space').val();
        var start   = $('#nms-mod-start').val();
        var end     = $('#nms-mod-end').val();
        var kitchen = $('#nms-mod-kitchen').val() === '1';
        var isFullDay = $('#nms-mod-type').val() === 'fullday';
        var info    = spacesData[space];

        // Calculate number of days
        var dateFrom = $('#nms-mod-date').val();
        var dateTo   = $('#nms-mod-date-end').val() || dateFrom;
        var numDays  = 1;
        if (dateFrom && dateTo) {
            var diff = (new Date(dateTo + 'T00:00:00') - new Date(dateFrom + 'T00:00:00')) / 86400000;
            numDays = Math.max(1, Math.round(diff) + 1);
        }

        var cost = 0;
        if (info) {
            var rateHourly = parseFloat(info.rate_hourly || 0);
            var rateDaily  = parseFloat(info.rate_daily || 0);

            if (isFullDay) {
                cost = rateDaily * numDays;
            } else if (start && end) {
                var sh = parseInt(start.split(':')[0]) * 60 + parseInt(start.split(':')[1]);
                var eh = parseInt(end.split(':')[0]) * 60 + parseInt(end.split(':')[1]);
                var hrs = Math.ceil(Math.max(0, (eh - sh) / 60));
                cost = hrs * rateHourly;
            }
            if (kitchen) cost += kitchenPrice;
        }

        $('#nms-mod-new-cost').text('£' + cost.toFixed(2));

        var priceDiff = cost - originalAmount;
        var $diffEl = $('#nms-mod-cost-diff');
        if (Math.abs(priceDiff) > 0.01) {
            $diffEl.show();
            if (priceDiff > 0) {
                $diffEl.css({ background: '#fee2e2', color: '#991b1b' }).text('Price would increase by £' + priceDiff.toFixed(2));
            } else {
                $diffEl.css({ background: '#d1fae5', color: '#065f46' }).text('Price would decrease by £' + Math.abs(priceDiff).toFixed(2));
            }
        } else {
            $diffEl.hide();
        }
    }

    // Run on page load to show correct initial cost
    recalcModCost();

    // Init booking type toggle
    $('#nms-mod-type').trigger('change');

    // Form submit
    $('#nms-modification-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $('#nms-mod-submit');
        var $err = $('#nms-mod-error');
        var $ok  = $('#nms-mod-success');
        $err.hide(); $ok.hide();

        $btn.prop('disabled', true).text('Submitting…');

        var data = $(this).serializeArray();
        data.push({ name: 'action', value: 'mbs_submit_modification' });

        $.post(NMS.ajax_url, data, function(res) {
            $btn.prop('disabled', false).text('Submit Request');
            if (res.success) {
                $ok.text(res.data.message).show();
                $('html, body').animate({ scrollTop: $ok.offset().top - 80 }, 400);
            } else {
                $err.text(res.data.message || 'An error occurred.').show();
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Submit Request');
            $err.text('A network error occurred.').show();
        });
    });
});
</script>
