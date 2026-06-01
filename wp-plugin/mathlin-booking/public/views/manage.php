<?php if ( ! defined( 'ABSPATH' ) ) exit;

// If user is already logged in, show the hirer dashboard directly
if ( is_user_logged_in() ) {
    include __DIR__ . '/hirer-dashboard.php';
    return;
}

// If this is a modification request URL, show the modification form
if ( isset( $_GET['mbs_modify'] ) && $_GET['mbs_modify'] === '1' ) {
    include __DIR__ . '/modification-form.php';
    return;
}
?>

<div class="nms-wrap nms-manage-wrap">
    <h2 class="nms-section-title">Manage Your Booking</h2>
    <p class="nms-section-sub">Log in to your account to view all your bookings, or look up a single booking with your reference number.</p>

    <div class="nms-manage-tabs">
        <button class="nms-manage-tab nms-manage-tab-active" data-tab="account">
            <span class="nms-manage-tab-icon">👤</span>
            <span class="nms-manage-tab-label">Account Login</span>
        </button>
        <button class="nms-manage-tab" data-tab="lookup">
            <span class="nms-manage-tab-icon">🔍</span>
            <span class="nms-manage-tab-label">Quick Lookup</span>
        </button>
    </div>

    <!-- Tab: Account Login / Register -->
    <div class="nms-manage-panel nms-manage-panel-active" id="nms-manage-account">
        <div class="nms-manage-cards">
            <!-- Login Card -->
            <div class="nms-manage-card">
                <h3>Sign In</h3>
                <p class="nms-muted">Access your full booking history, invoices, and make changes.</p>
                <form id="nms-manage-login" class="nms-form" novalidate>
                    <?php wp_nonce_field( 'mbs_public_nonce', 'nonce' ); ?>
                    <div class="nms-form-group">
                        <label for="nms-login-email">Email Address</label>
                        <input type="email" id="nms-login-email" name="email" placeholder="your@email.com" required>
                    </div>
                    <div class="nms-form-group">
                        <label for="nms-login-pass">Password</label>
                        <input type="password" id="nms-login-pass" name="password" placeholder="••••••••" required>
                    </div>
                    <div id="nms-login-msg" class="nms-manage-msg"></div>
                    <button type="submit" class="nms-btn nms-btn-primary" style="width:100%;">Sign In</button>
                </form>
            </div>

            <!-- Register Card -->
            <div class="nms-manage-card">
                <h3>Create Account</h3>
                <p class="nms-muted">New here? Create an account to manage all your bookings in one place.</p>
                <form id="nms-manage-register" class="nms-form" novalidate>
                    <?php wp_nonce_field( 'mbs_public_nonce', 'nonce' ); ?>
                    <div class="nms-form-group">
                        <label for="nms-reg-name">Full Name</label>
                        <input type="text" id="nms-reg-name" name="name" placeholder="Jane Smith" required>
                    </div>
                    <div class="nms-form-group">
                        <label for="nms-reg-email">Email Address</label>
                        <input type="email" id="nms-reg-email" name="email" placeholder="your@email.com" required>
                    </div>
                    <div class="nms-form-group">
                        <label for="nms-reg-phone">Phone Number</label>
                        <input type="tel" id="nms-reg-phone" name="phone" placeholder="07700 900000">
                    </div>
                    <div class="nms-form-group">
                        <label for="nms-reg-org">Organisation (optional)</label>
                        <input type="text" id="nms-reg-org" name="organisation" placeholder="e.g. Acme Ltd">
                    </div>
                    <div class="nms-form-group">
                        <label for="nms-reg-pass">Password (min 8 characters)</label>
                        <input type="password" id="nms-reg-pass" name="password" placeholder="••••••••" required minlength="8">
                    </div>
                    <div id="nms-register-msg" class="nms-manage-msg"></div>
                    <button type="submit" class="nms-btn nms-btn-primary" style="width:100%;">Create Account</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Tab: Guest Lookup -->
    <div class="nms-manage-panel" id="nms-manage-lookup">
        <div class="nms-manage-card nms-manage-card-single">
            <h3>Look Up Your Booking</h3>
            <p class="nms-muted">Don't have an account? No problem. Enter your booking reference and the email you used to check your booking status, view your invoice, or request changes.</p>
            <form id="nms-manage-lookup-form" class="nms-form" novalidate>
                <?php wp_nonce_field( 'mbs_public_nonce', 'nonce' ); ?>
                <div class="nms-form-group">
                    <label for="nms-lookup-ref">Booking Reference</label>
                    <input type="text" id="nms-lookup-ref" name="ref" placeholder="e.g. MBS-ABC123" required style="text-transform:uppercase;" pattern="[A-Za-z0-9\-]+">
                </div>
                <div class="nms-form-group">
                    <label for="nms-lookup-email">Email Address</label>
                    <input type="email" id="nms-lookup-email" name="email" placeholder="The email used when booking" required>
                </div>
                <div id="nms-lookup-msg" class="nms-manage-msg"></div>
                <button type="submit" class="nms-btn nms-btn-primary" style="width:100%;">Look Up Booking</button>
            </form>
        </div>
        <div id="nms-lookup-result" style="display:none;margin-top:1.5rem;"></div>
    </div>
</div>

<script>
jQuery(function($) {
    // Tab switching
    $('.nms-manage-tab').on('click', function() {
        var tab = $(this).data('tab');
        $('.nms-manage-tab').removeClass('nms-manage-tab-active');
        $(this).addClass('nms-manage-tab-active');
        $('.nms-manage-panel').removeClass('nms-manage-panel-active');
        $('#nms-manage-' + tab).addClass('nms-manage-panel-active');
    });

    // Login
    $('#nms-manage-login').on('submit', function(e) {
        e.preventDefault();
        var $btn = $(this).find('button[type=submit]');
        var $msg = $('#nms-login-msg');
        $btn.prop('disabled', true).text('Signing in…');
        $msg.html('');

        $.post(NMS.ajax_url, {
            action:   'mbs_hirer_login',
            nonce:    $(this).find('[name=nonce]').val(),
            email:    $('#nms-login-email').val(),
            password: $('#nms-login-pass').val()
        }, function(res) {
            if (res.success) {
                $msg.html('<span class="nms-msg-success">✓ ' + res.data.message + '</span>');
                setTimeout(function() { window.location.reload(); }, 800);
            } else {
                $msg.html('<span class="nms-msg-error">' + (res.data.message || 'Login failed.') + '</span>');
                $btn.prop('disabled', false).text('Sign In');
            }
        }).fail(function() {
            $msg.html('<span class="nms-msg-error">Network error. Please try again.</span>');
            $btn.prop('disabled', false).text('Sign In');
        });
    });

    // Register
    $('#nms-manage-register').on('submit', function(e) {
        e.preventDefault();
        var $btn = $(this).find('button[type=submit]');
        var $msg = $('#nms-register-msg');
        $btn.prop('disabled', true).text('Creating account…');
        $msg.html('');

        $.post(NMS.ajax_url, {
            action:       'mbs_hirer_register',
            nonce:        $(this).find('[name=nonce]').val(),
            name:         $('#nms-reg-name').val(),
            email:        $('#nms-reg-email').val(),
            phone:        $('#nms-reg-phone').val(),
            organisation: $('#nms-reg-org').val(),
            password:     $('#nms-reg-pass').val()
        }, function(res) {
            if (res.success) {
                $msg.html('<span class="nms-msg-success">✓ ' + res.data.message + '</span>');
                setTimeout(function() { window.location.reload(); }, 800);
            } else {
                $msg.html('<span class="nms-msg-error">' + (res.data.message || 'Registration failed.') + '</span>');
                $btn.prop('disabled', false).text('Create Account');
            }
        }).fail(function() {
            $msg.html('<span class="nms-msg-error">Network error. Please try again.</span>');
            $btn.prop('disabled', false).text('Create Account');
        });
    });

    // Guest Lookup
    $('#nms-manage-lookup-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $(this).find('button[type=submit]');
        var $msg = $('#nms-lookup-msg');
        var $res = $('#nms-lookup-result');
        $btn.prop('disabled', true).text('Looking up…');
        $msg.html('');
        $res.hide();

        $.post(NMS.ajax_url, {
            action: 'mbs_lookup_booking',
            nonce:  $(this).find('[name=nonce]').val(),
            ref:    $('#nms-lookup-ref').val().trim().toUpperCase(),
            email:  $('#nms-lookup-email').val().trim()
        }, function(res) {
            $btn.prop('disabled', false).text('Look Up Booking');
            if (res.success) {
                var b = res.data;
                var statusColors = {
                    pending:   'background:#fff3cd;color:#856404',
                    confirmed: 'background:#d1fae5;color:#065f46',
                    paid:      'background:#dbeafe;color:#1e40af',
                    cancelled: 'background:#fee2e2;color:#991b1b',
                    archived:  'background:#f3f4f6;color:#6b7280'
                };
                var sc = statusColors[b.status] || '';

                var html = '<div class="nms-manage-card nms-manage-card-single">';
                html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">';
                html += '<h3 style="margin:0;">Booking ' + escHtml(b.ref) + '</h3>';
                html += '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:0.75rem;font-weight:700;text-transform:uppercase;' + sc + '">' + escHtml(b.status) + '</span>';
                html += '</div>';

                html += '<table style="width:100%;font-size:0.9rem;border-collapse:collapse;">';
                html += row('Space', b.space);
                html += row('Date', b.date_formatted);
                html += row('Time', b.time);
                html += row('Attendees', b.attendees);
                html += row('Purpose', b.purpose);
                html += row('Amount', '£' + b.amount);
                html += row('Invoice', b.invoice_number);
                html += '</table>';

                html += '<div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:1rem;">';
                if (b.payment_url) {
                    html += '<a href="' + b.payment_url + '" class="nms-btn nms-btn-primary nms-btn-sm">💳 Pay Now</a>';
                }
                if (b.modify_url) {
                    html += '<a href="' + b.modify_url + '" class="nms-btn nms-btn-sm" style="background:#f3f4f6;color:#374151;border-color:#d1d5db;">✏️ Request Changes</a>';
                }
                if (b.ical_url) {
                    html += '<a href="' + b.ical_url + '" class="nms-btn nms-btn-sm" style="background:#f3f4f6;color:#374151;border-color:#d1d5db;">📅 Add to Calendar</a>';
                }
                html += '</div>';
                html += '</div>';

                $res.html(html).show();
            } else {
                $msg.html('<span class="nms-msg-error">' + (res.data.message || 'Booking not found.') + '</span>');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Look Up Booking');
            $msg.html('<span class="nms-msg-error">Network error. Please try again.</span>');
        });
    });

    function row(label, value) {
        return '<tr><td style="padding:8px 0;font-weight:600;color:#6b7280;width:35%;border-bottom:1px solid #f3f4f6;">' + label + '</td><td style="padding:8px 0;border-bottom:1px solid #f3f4f6;">' + escHtml(value || '—') + '</td></tr>';
    }
    function escHtml(s) { return $('<span>').text(s || '').html(); }
});
</script>
