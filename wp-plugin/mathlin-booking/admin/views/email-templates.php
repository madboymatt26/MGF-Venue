<?php if ( ! defined( 'ABSPATH' ) ) exit;
$templates = MBS_Email_Templates::get_template_types();
$org       = MBS_Email_Templates::get_org_settings();
$chase     = MBS_Email_Templates::get_chase_settings();
?>
<div class="wrap mbs-admin">
    <h1>&#9884; Scout Bookings – Email Settings</h1>

    <div class="nms-settings-layout">

        <!-- Organisation Details -->
        <div class="nms-card">
            <div class="nms-card-header"><h2>🏛️ Organisation Details</h2></div>
            <p>These details appear in email headers, footers, and invoices.</p>
            <table class="form-table">
                <tr>
                    <th><label for="org_name">Organisation Name</label></th>
                    <td><input type="text" id="org_name" value="<?php echo esc_attr( $org['name'] ); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="org_address">Address</label></th>
                    <td><input type="text" id="org_address" value="<?php echo esc_attr( $org['address'] ); ?>" class="regular-text" style="width:100%;max-width:500px;"></td>
                </tr>
                <tr>
                    <th><label for="org_phone">Phone Number</label></th>
                    <td><input type="text" id="org_phone" value="<?php echo esc_attr( $org['phone'] ); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="org_charity_number">Charity Number</label></th>
                    <td><input type="text" id="org_charity_number" value="<?php echo esc_attr( $org['charity_number'] ); ?>" class="regular-text"></td>
                </tr>
            </table>
        </div>

        <!-- Chase / Cron Settings -->
        <div class="nms-card">
            <div class="nms-card-header"><h2>⏰ Automation Timing</h2></div>
            <p>Configure when automated emails run and how payment chasing works.</p>
            <table class="form-table">
                <tr>
                    <th><label for="max_chase_emails">Max chase emails per booking</label></th>
                    <td><input type="number" id="max_chase_emails" value="<?php echo esc_attr( $chase['max_chases'] ); ?>" min="1" max="10" style="width:70px"></td>
                </tr>
                <tr>
                    <th><label for="chase_interval_days">Days between chase emails</label></th>
                    <td><input type="number" id="chase_interval_days" value="<?php echo esc_attr( $chase['chase_interval'] ); ?>" min="1" max="14" style="width:70px"></td>
                </tr>
                <tr>
                    <th><label for="cron_time_reminders">Booking reminders run at</label></th>
                    <td><input type="time" id="cron_time_reminders" value="<?php echo esc_attr( $chase['cron_reminders'] ); ?>"> <span class="description">Daily</span></td>
                </tr>
                <tr>
                    <th><label for="cron_time_chase">Payment chase runs at</label></th>
                    <td><input type="time" id="cron_time_chase" value="<?php echo esc_attr( $chase['cron_chase'] ); ?>"> <span class="description">Daily</span></td>
                </tr>
                <tr>
                    <th><label for="cron_time_archive">Auto-archive runs at</label></th>
                    <td><input type="time" id="cron_time_archive" value="<?php echo esc_attr( $chase['cron_archive'] ); ?>"> <span class="description">Daily</span></td>
                </tr>
            </table>
        </div>

        <!-- Email Templates -->
        <div class="nms-card">
            <div class="nms-card-header"><h2>📧 Email Templates</h2></div>
            <p>Customise the subject and body of each email type. Use the placeholder tags below — they'll be replaced with actual booking data when the email is sent.</p>

            <div style="padding:0 1.5rem 1rem;">
                <details style="margin-bottom:1rem;">
                    <summary style="cursor:pointer;font-weight:600;color:#7413DC;">📋 Available Placeholders (click to expand)</summary>
                    <div style="margin-top:0.5rem;font-size:0.85rem;background:#f9f7ff;padding:12px;border-radius:6px;columns:2;column-gap:2rem;">
                        <code>{name}</code> — Booker's name<br>
                        <code>{organisation}</code> — Organisation<br>
                        <code>{ref}</code> — Booking reference<br>
                        <code>{space}</code> — Space name<br>
                        <code>{date}</code> — Booking date<br>
                        <code>{time}</code> — Time range<br>
                        <code>{attendees}</code> — Attendee count<br>
                        <code>{purpose}</code> — Purpose<br>
                        <code>{amount}</code> — Amount (£)<br>
                        <code>{invoice}</code> — Invoice number<br>
                        <code>{admin_email}</code> — Contact email<br>
                        <code>{phone}</code> — Contact phone<br>
                        <code>{org_name}</code> — Organisation name<br>
                        <code>{org_address}</code> — Address<br>
                        <code>{charity_number}</code> — Charity no.<br>
                        <code>{bank_details}</code> — Bank transfer info<br>
                        <code>{pay_url}</code> — Online payment link<br>
                        <code>{reason}</code> — Cancellation reason<br>
                    </div>
                </details>
            </div>

            <?php foreach ( $templates as $type => $default ) :
                $current = MBS_Email_Templates::get_template( $type );
            ?>
            <div style="padding:0 1.5rem 1.5rem;border-bottom:1px solid var(--border);">
                <h4 style="color:#7413DC;margin:1rem 0 0.5rem;"><?php echo esc_html( $default['label'] ); ?></h4>
                <div style="margin-bottom:0.5rem;">
                    <label style="font-size:0.8rem;font-weight:600;">Subject Line</label>
                    <input type="text" class="mbs-tpl-subject regular-text" data-type="<?php echo esc_attr( $type ); ?>"
                           value="<?php echo esc_attr( $current['subject'] ); ?>" style="width:100%;max-width:600px;">
                </div>
                <div>
                    <label style="font-size:0.8rem;font-weight:600;">Email Body</label>
                    <textarea class="mbs-tpl-body" data-type="<?php echo esc_attr( $type ); ?>"
                              rows="6" style="width:100%;max-width:600px;font-family:monospace;font-size:0.85rem;"><?php echo esc_textarea( $current['body'] ); ?></textarea>
                </div>
                <button class="button button-small mbs-tpl-reset" data-type="<?php echo esc_attr( $type ); ?>"
                        data-default-subject="<?php echo esc_attr( $default['subject'] ); ?>"
                        data-default-body="<?php echo esc_attr( $default['body'] ); ?>"
                        style="margin-top:4px;">Reset to Default</button>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Save -->
        <div class="nms-card" style="text-align:center;padding:24px;">
            <button id="mbs-save-email-settings" class="button button-primary button-hero">💾 Save Email Settings</button>
            <span id="mbs-email-save-msg" class="nms-settings-msg" style="display:block;margin-top:12px;"></span>
        </div>

    </div>
</div>
