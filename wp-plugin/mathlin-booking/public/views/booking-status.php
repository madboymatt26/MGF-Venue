<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<?php
// If this is a modification request, show the modification form instead
if ( isset( $_GET['mbs_modify'] ) && $_GET['mbs_modify'] === '1' ) {
    include __DIR__ . '/modification-form.php';
    return;
}
?>

<div class="nms-wrap" id="nms-status-wrap">
    <h2 class="nms-section-title">Check Your Booking</h2>
    <p class="nms-section-sub">Enter your booking reference to see the current status and details.</p>

    <div class="nms-form-section" style="max-width:500px;">
        <form id="nms-status-form" class="nms-form" style="gap:1rem;">
            <?php wp_nonce_field( 'mbs_public_nonce', 'nonce' ); ?>
            <div class="nms-form-row" style="grid-template-columns:1fr auto;">
                <div class="nms-form-group">
                    <label for="nms-status-ref">Booking Reference</label>
                    <input type="text" id="nms-status-ref" name="ref" placeholder="e.g. MBS-ABC123" required
                           style="text-transform:uppercase;" pattern="[A-Za-z0-9\-]+">
                </div>
                <div class="nms-form-group" style="justify-content:flex-end;">
                    <button type="submit" class="nms-btn nms-btn-primary" id="nms-status-btn" style="margin-top:1.5rem;">
                        Look Up
                    </button>
                </div>
            </div>
        </form>
    </div>

    <div id="nms-status-result" style="display:none;margin-top:1.5rem;"></div>
</div>

<script>
jQuery(function($) {
    $('#nms-status-form').on('submit', function(e) {
        e.preventDefault();
        var ref  = $('#nms-status-ref').val().trim().toUpperCase();
        var $btn = $('#nms-status-btn');
        var $res = $('#nms-status-result');

        if (!ref) return;

        $btn.prop('disabled', true).text('Looking up…');
        $res.hide();

        $.post(NMS.ajax_url, {
            action: 'mbs_lookup_booking',
            nonce:  NMS.nonce,
            ref:    ref
        }, function(res) {
            $btn.prop('disabled', false).text('Look Up');
            if (res.success) {
                var b = res.data;
                var statusColors = {
                    pending:   'background:#fff3cd;color:#856404',
                    confirmed: 'background:#d1fae5;color:#065f46',
                    paid:      'background:#dbeafe;color:#1e40af',
                    cancelled: 'background:#fee2e2;color:#991b1b',
                    archived:  'background:#f3f4f6;color:#6b7280'
                };
                var statusStyle = statusColors[b.status] || '';

                var html = '<div class="nms-form-section">';
                html += '<h3>Booking ' + escHtml(b.ref) + '</h3>';
                html += '<div style="margin-bottom:1rem;"><span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:0.8rem;font-weight:700;text-transform:uppercase;' + statusStyle + '">' + escHtml(b.status) + '</span></div>';

                html += '<table style="width:100%;font-size:0.9rem;">';
                html += row('Space', b.space);
                html += row('Date', b.date_formatted);
                html += row('Time', b.time);
                html += row('Attendees', b.attendees);
                html += row('Purpose', b.purpose);
                html += row('Amount', '£' + b.amount);
                html += row('Invoice', b.invoice_number);
                html += '</table>';

                if (b.payment_url && b.status === 'confirmed') {
                    html += '<div style="margin-top:1rem;text-align:center;">';
                    html += '<a href="' + b.payment_url + '" class="nms-btn nms-btn-primary nms-btn-lg">💳 Pay Now</a>';
                    html += '</div>';
                }

                if (b.ical_url) {
                    html += '<div style="margin-top:0.75rem;text-align:center;">';
                    html += '<a href="' + b.ical_url + '" class="nms-btn nms-btn-sm" style="background:#f5f0ff;color:#7413DC;border-color:#e0d0f0;">📅 Add to Calendar</a>';
                    html += '</div>';
                }

                if (b.modify_url && b.status !== 'cancelled' && b.status !== 'archived') {
                    html += '<div style="margin-top:0.75rem;text-align:center;">';
                    html += '<a href="' + b.modify_url + '" style="color:#7413DC;font-size:0.85rem;">Need to change something? Request a modification</a>';
                    html += '</div>';
                }

                html += '</div>';
                $res.html(html).show();
            } else {
                $res.html('<div class="nms-alert nms-alert-error">' + (res.data.message || 'Booking not found.') + '</div>').show();
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Look Up');
            $res.html('<div class="nms-alert nms-alert-error">A network error occurred. Please try again.</div>').show();
        });
    });

    function row(label, value) {
        return '<tr><td style="padding:6px 0;font-weight:600;color:#6b7280;width:35%;">' + label + '</td><td style="padding:6px 0;">' + escHtml(value || '—') + '</td></tr>';
    }

    function escHtml(str) {
        return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
});
</script>
