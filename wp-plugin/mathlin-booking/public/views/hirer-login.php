<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<div class="nms-wrap">
    <h2 class="nms-section-title">Hirer Portal</h2>
    <p class="nms-section-sub">Log in to view your bookings, invoices, and make new bookings.</p>

    <div id="nms-portal-msg" class="nms-alert" style="display:none"></div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:2rem;max-width:800px;">

        <!-- Login -->
        <div class="nms-form-section">
            <h3>Log In</h3>
            <form id="nms-hirer-login">
                <?php wp_nonce_field( 'mbs_public_nonce', 'nonce' ); ?>
                <div class="nms-form-group">
                    <label for="nms-login-identity">Email Address or Username</label>
                    <input type="text" id="nms-login-identity" name="login" autocomplete="username" required>
                </div>
                <div class="nms-form-group">
                    <label for="nms-login-pass">Password</label>
                    <input type="password" id="nms-login-pass" name="password" autocomplete="current-password" required>
                </div>
                <div class="nms-form-group" style="margin-top:0.5rem;">
                    <button type="submit" class="nms-btn nms-btn-primary" style="width:100%;">Log In</button>
                </div>
                <p style="text-align:center;margin-top:0.5rem;"><a href="<?php echo esc_url( wp_lostpassword_url() ); ?>" style="color:#7413DC;font-size:0.85rem;">Forgot your password?</a></p>
            </form>
        </div>

        <!-- Register -->
        <div class="nms-form-section">
            <h3>Create an Account</h3>
            <p style="font-size:0.85rem;color:#6b7280;margin-bottom:1rem;">Create an account to manage your bookings, view invoices, and book faster.</p>
            <form id="nms-hirer-register">
                <?php wp_nonce_field( 'mbs_public_nonce', 'nonce' ); ?>
                <div class="nms-form-group">
                    <label for="nms-reg-name">Full Name <span class="nms-req">*</span></label>
                    <input type="text" id="nms-reg-name" name="name" required>
                </div>
                <div class="nms-form-group">
                    <label for="nms-reg-org">Organisation / Group</label>
                    <input type="text" id="nms-reg-org" name="organisation">
                </div>
                <div class="nms-form-group">
                    <label for="nms-reg-email">Email Address <span class="nms-req">*</span></label>
                    <input type="email" id="nms-reg-email" name="email" required>
                </div>
                <div class="nms-form-group">
                    <label for="nms-reg-phone">Phone Number</label>
                    <input type="tel" id="nms-reg-phone" name="phone">
                </div>
                <div class="nms-form-group">
                    <label for="nms-reg-pass">Password <span class="nms-req">*</span></label>
                    <input type="password" id="nms-reg-pass" name="password" required minlength="8">
                    <p class="nms-field-hint">At least 8 characters</p>
                </div>
                <div class="nms-form-group" style="margin-top:0.5rem;">
                    <button type="submit" class="nms-btn nms-btn-primary" style="width:100%;">Create Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
jQuery(function($) {
    var $msg = $('#nms-portal-msg');

    function showMsg(text, type) {
        $msg.text(text).removeClass('nms-alert-success nms-alert-error').addClass('nms-alert-' + type).show();
    }

    $('#nms-hirer-login').on('submit', function(e) {
        e.preventDefault();
        var data = $(this).serializeArray();
        data.push({ name: 'action', value: 'mbs_hirer_login' });
        $.post(NMS.ajax_url, data, function(res) {
            if (res.success) {
                showMsg(res.data.message, 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showMsg(res.data.message, 'error');
            }
        });
    });

    $('#nms-hirer-register').on('submit', function(e) {
        e.preventDefault();
        var data = $(this).serializeArray();
        data.push({ name: 'action', value: 'mbs_hirer_register' });
        $.post(NMS.ajax_url, data, function(res) {
            if (res.success) {
                showMsg(res.data.message, 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showMsg(res.data.message, 'error');
            }
        });
    });
});
</script>
