<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap mbs-admin">
    <h1>&#9884; Scout Bookings – Settings</h1>

    <div id="nms-settings-global-msg" class="nms-settings-msg" style="margin-bottom:16px;font-size:14px;"></div>

    <div class="nms-settings-layout">

        <!-- Bookable Spaces / Resources -->
        <div class="nms-card">
            <div class="nms-card-header">
                <h2>🏛️ Bookable Spaces &amp; Pricing</h2>
            </div>
            <p>Configure the spaces available for booking. You can add, remove, or change pricing at any time. Changes take effect immediately on the public booking form.</p>

            <?php $spaces = MBS_Bookings::get_spaces(); ?>
            <table class="widefat nms-spaces-table" id="nms-spaces-table">
                <thead>
                    <tr>
                        <th>Space Name</th>
                        <th>Hourly Rate (£)</th>
                        <th>Day Rate (£)</th>
                        <th>Capacity</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="nms-spaces-tbody">
                    <?php foreach ( $spaces as $name => $info ) : ?>
                    <tr class="nms-space-row">
                        <td><input type="text" class="nms-space-name regular-text" value="<?php echo esc_attr( $name ); ?>" placeholder="e.g. Main Hall"></td>
                        <td><input type="number" class="nms-space-rate-hourly" value="<?php echo esc_attr( $info['rate_hourly'] ?? $info['rate'] ?? 0 ); ?>" min="0" step="0.01" style="width:80px"></td>
                        <td><input type="number" class="nms-space-rate-daily" value="<?php echo esc_attr( $info['rate_daily'] ?? 0 ); ?>" min="0" step="0.01" style="width:80px"></td>
                        <td><input type="number" class="nms-space-capacity" value="<?php echo esc_attr( $info['capacity'] ?? '' ); ?>" min="1" style="width:70px" placeholder="—"></td>
                        <td><button type="button" class="button nms-remove-space" title="Remove space">&times;</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:12px;">
                <button type="button" class="button" id="nms-add-space">+ Add Space</button>
            </p>

            <h4 style="margin-top:1.5rem">Kitchen Add-on</h4>
            <table class="form-table">
                <tr>
                    <th><label for="kitchen_price">Kitchen add-on price (£)</label></th>
                    <td>
                        <input type="number" id="kitchen_price" name="kitchen_price"
                               value="<?php echo esc_attr( MBS_Bookings::get_kitchen_price() ); ?>"
                               min="0" step="0.01" style="width:80px">
                        <span class="description">per session</span>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Email & Notifications -->
        <div class="nms-card">
            <div class="nms-card-header">
                <h2>📧 Email &amp; Notifications</h2>
            </div>
            <p>Configure where booking notifications are sent and the "From" address on outgoing emails.</p>

            <table class="form-table">
                <tr>
                    <th><label for="admin_email">Admin / Notification Email</label></th>
                    <td>
                        <input type="email" id="admin_email" name="admin_email"
                               value="<?php echo esc_attr( MBS_Bookings::get_admin_email() ); ?>"
                               class="regular-text"
                               placeholder="bookings@needhamscouts.uk">
                        <p class="description">
                            Primary email for booking notifications and the "From" address on outgoing emails.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="additional_emails">Additional notification emails</label></th>
                    <td>
                        <input type="text" id="additional_emails" name="additional_emails"
                               value="<?php echo esc_attr( get_option( 'mbs_additional_emails', '' ) ); ?>"
                               class="regular-text"
                               placeholder="manager@example.com, treasurer@example.com">
                        <p class="description">
                            Comma-separated list of extra email addresses to receive new booking notifications.<br>
                            These people will get the same alert as the primary email above.
                        </p>
                    </td>
                </tr>
            </table>

            <p><strong>Note:</strong> When a booking is confirmed, the invoice is automatically attached to the confirmation email sent to the booker.</p>
        </div>

        <!-- Payment & Invoice Settings -->
        <div class="nms-card">
            <div class="nms-card-header">
                <h2>💳 Payment &amp; Invoice Settings</h2>
            </div>
            <p>Configure the bank details and payment terms shown on invoices and confirmation emails.</p>
            <table class="form-table">
                <tr>
                    <th><label for="bank_sort_code">Sort Code</label></th>
                    <td><input type="text" id="bank_sort_code" value="<?php echo esc_attr( get_option( 'mbs_bank_sort_code', '12-34-56' ) ); ?>" class="regular-text" placeholder="12-34-56"></td>
                </tr>
                <tr>
                    <th><label for="bank_account_number">Account Number</label></th>
                    <td><input type="text" id="bank_account_number" value="<?php echo esc_attr( get_option( 'mbs_bank_account_number', '12345678' ) ); ?>" class="regular-text" placeholder="12345678"></td>
                </tr>
                <tr>
                    <th><label for="bank_account_name">Account Name</label></th>
                    <td><input type="text" id="bank_account_name" value="<?php echo esc_attr( get_option( 'mbs_bank_account_name', 'Needham Market Scout Group' ) ); ?>" class="regular-text" placeholder="Needham Market Scout Group"></td>
                </tr>
                <tr>
                    <th><label for="payment_terms_days">Payment Terms</label></th>
                    <td><input type="number" id="payment_terms_days" value="<?php echo esc_attr( get_option( 'mbs_payment_terms_days', 14 ) ); ?>" min="1" max="90" style="width:80px"> days</td>
                </tr>
            </table>
        </div>

        <!-- Home Assistant Settings -->
        <div class="nms-card">
            <div class="nms-card-header">
                <h2>🏠 Home Assistant Integration</h2>
            </div>
            <p>When a booking is <strong>confirmed</strong>, WordPress will POST a JSON payload to your Home Assistant webhook URL. HA can then trigger automations (heating, lighting, door access, etc.).</p>

            <table class="form-table">
                <tr>
                    <th><label for="ha_webhook_url">HA Webhook URL</label></th>
                    <td>
                        <input type="url" id="ha_webhook_url" name="ha_webhook_url"
                               value="<?php echo esc_attr( get_option( 'mbs_ha_webhook_url', '' ) ); ?>"
                               class="regular-text"
                               placeholder="http://homeassistant.local:8123/api/webhook/mathlin_booking">
                        <p class="description">
                            In Home Assistant: <strong>Settings → Automations → Create Automation → Trigger: Webhook</strong>.<br>
                            Copy the webhook URL and paste it here.
                        </p>
                    </td>
                </tr>
            </table>

            <div class="nms-settings-actions">
                <button id="nms-test-ha" class="button">Send Test Webhook</button>
                <span id="nms-ha-msg" class="nms-settings-msg"></span>
            </div>
        </div>

        <!-- REST API Info -->
        <div class="nms-card">
            <div class="nms-card-header">
                <h2>📡 REST API Endpoints</h2>
            </div>
            <p>Home Assistant can also <strong>poll</strong> these endpoints to get booking data as sensors.</p>

            <table class="nms-api-table">
                <thead><tr><th>Endpoint</th><th>Auth</th><th>Description</th></tr></thead>
                <tbody>
                    <tr>
                        <td><code><?php echo esc_html( rest_url( 'mathlin/v1/bookings/upcoming' ) ); ?></code></td>
                        <td>None</td>
                        <td>Confirmed bookings for next 30 days</td>
                    </tr>
                    <tr>
                        <td><code><?php echo esc_html( rest_url( 'mathlin/v1/bookings/today' ) ); ?></code></td>
                        <td>None</td>
                        <td>Today's confirmed bookings</td>
                    </tr>
                    <tr>
                        <td><code><?php echo esc_html( rest_url( 'mathlin/v1/bookings/calendar?year=2026&month=5' ) ); ?></code></td>
                        <td>None</td>
                        <td>Booked dates in a given month</td>
                    </tr>
                    <tr>
                        <td><code><?php echo esc_html( rest_url( 'mathlin/v1/bookings' ) ); ?></code></td>
                        <td>WP Admin</td>
                        <td>All bookings (admin only)</td>
                    </tr>
                </tbody>
            </table>

            <h3 style="margin-top:1.5rem">Home Assistant configuration.yaml example</h3>
            <pre class="nms-code-block"># Poll once a day – today's bookings are loaded at midnight
rest:
  - resource: <?php echo esc_html( rest_url( 'mathlin/v1/bookings/today' ) ); ?>

    scan_interval: 86400
    sensor:
      - name: "Scout Hall Today Booking Count"
        value_template: "{{ value_json | length }}"

      - name: "Scout Hall First Booking Today"
        value_template: >
          {% if value_json | length > 0 %}
            {{ value_json[0].space }} at {{ value_json[0].start_time }}
          {% else %}
            No bookings today
          {% endif %}
        json_attributes_path: "$[0]"
        json_attributes:
          - ref
          - space
          - start_time
          - end_time
          - attendees
          - purpose
          - kitchen</pre>
        </div>

        <!-- GitHub Auto-Update Settings -->
        <div class="nms-card">
            <div class="nms-card-header">
                <h2>🔄 Plugin Auto-Update (GitHub)</h2>
            </div>
            <p>This plugin can update itself from GitHub releases. When you push a new version and create a <strong>GitHub Release</strong>, WordPress will detect it and offer a one-click update in <strong>Dashboard → Updates</strong>.</p>

            <table class="form-table">
                <tr>
                    <th><label for="github_token">GitHub Personal Access Token</label></th>
                    <td>
                        <input type="text" id="github_token" name="github_token"
                               value="<?php echo esc_attr( get_option( 'mbs_github_token', '' ) ); ?>"
                               class="regular-text"
                               placeholder="ghp_xxxxxxxxxxxxxxxxxxxx"
                               autocomplete="off">
                        <p class="description">
                            Required because the repository is <strong>private</strong>.<br>
                            Create a token at <a href="https://github.com/settings/tokens" target="_blank">github.com/settings/tokens</a> with <code>repo</code> scope.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>Repository</th>
                    <td><code>madboymatt26/mathlin-booking</code></td>
                </tr>
                <tr>
                    <th>Installed Version</th>
                    <td><code><?php echo esc_html( MBS_VERSION ); ?></code></td>
                </tr>
            </table>

            <div class="nms-settings-actions">
                <button id="nms-check-update" class="button">Check for Updates Now</button>
                <span id="nms-update-msg" class="nms-settings-msg"></span>
            </div>
        </div>

        <!-- Booking Rules -->
        <div class="nms-card">
            <div class="nms-card-header"><h2>⚙️ Booking Rules</h2></div>
            <p>Control how far in advance people can book.</p>
            <table class="form-table">
                <tr>
                    <th><label for="min_notice_days">Minimum notice required</label></th>
                    <td>
                        <input type="number" id="min_notice_days" name="min_notice_days"
                               value="<?php echo esc_attr( get_option( 'mbs_min_notice_days', 1 ) ); ?>"
                               min="0" max="30" style="width:80px"> days
                        <p class="description">
                            How many days notice is required before a booking can be made.<br>
                            <strong>0</strong> = same-day bookings allowed &bull;
                            <strong>1</strong> = must book at least 1 day ahead &bull;
                            <strong>7</strong> = must book at least a week ahead.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="reminder_hours">Email reminder</label></th>
                    <td>
                        <input type="number" id="reminder_hours" name="reminder_hours"
                               value="<?php echo esc_attr( get_option( 'mbs_reminder_hours', 24 ) ); ?>"
                               min="0" max="168" style="width:80px"> hours before
                        <p class="description">
                            Send a reminder email to the booker this many hours before their booking.<br>
                            <strong>0</strong> = reminders disabled &bull;
                            <strong>24</strong> = 1 day before &bull;
                            <strong>48</strong> = 2 days before.
                            Runs daily at 7am via WP-Cron.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="terms_page_id">Terms &amp; Conditions page</label></th>
                    <td>
                        <?php
                        wp_dropdown_pages( array(
                            'name'              => 'terms_page_id',
                            'id'                => 'terms_page_id',
                            'selected'          => get_option( 'mbs_terms_page_id', 0 ),
                            'show_option_none'  => '— No T&Cs required —',
                            'option_none_value' => '0',
                        ) );
                        ?>
                        <p class="description">
                            If set, a "I agree to the Terms &amp; Conditions" checkbox will appear on the booking form.<br>
                            Create a WordPress page with your T&amp;Cs and select it here.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="auto_archive_days">Auto-archive after</label></th>
                    <td>
                        <input type="number" id="auto_archive_days" name="auto_archive_days"
                               value="<?php echo esc_attr( get_option( 'mbs_auto_archive_days', 7 ) ); ?>"
                               min="0" max="365" style="width:80px"> days
                        <p class="description">
                            Automatically archive bookings this many days after they've passed.<br>
                            <strong>0</strong> = disabled (manual archive only) &bull;
                            <strong>7</strong> = archive 1 week after the event.
                            Runs daily at 2am via WP-Cron.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="auto_chase_enabled">Auto-chase overdue payments</label></th>
                    <td>
                        <select id="auto_chase_enabled" name="auto_chase_enabled">
                            <option value="1" <?php selected( get_option( 'mbs_auto_chase_enabled', 1 ), 1 ); ?>>Enabled</option>
                            <option value="0" <?php selected( get_option( 'mbs_auto_chase_enabled', 1 ), 0 ); ?>>Disabled</option>
                        </select>
                        <p class="description">
                            Automatically send payment reminder emails when invoices are overdue.<br>
                            Sends up to 3 reminders with increasing urgency, spaced 3 days apart.<br>
                            Runs daily at 9am. You can also manually chase from the booking detail page.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>iCal Feed URL</th>
                    <td>
                        <code><?php echo esc_html( rest_url( 'mathlin/v1/bookings/ical' ) ); ?></code>
                        <p class="description">
                            Subscribe to this URL in Google Calendar, Apple Calendar, or Outlook to see all confirmed bookings.<br>
                            <strong>Google Calendar:</strong> Other calendars → From URL → paste the URL above.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>Database Table</th>
                    <td><code><?php global $wpdb; echo esc_html( $wpdb->prefix . MBS_TABLE ); ?></code></td>
                </tr>
                <tr>
                    <th>Plugin Version</th>
                    <td><?php echo esc_html( MBS_VERSION ); ?></td>
                </tr>
            </table>
        </div>

        <!-- Single Save Button -->
        <div class="nms-card" style="text-align:center;padding:24px;">
            <button id="nms-save-all" class="button button-primary button-hero">💾 Save All Settings</button>
            <span id="nms-save-msg" class="nms-settings-msg" style="display:block;margin-top:12px;"></span>
        </div>

    </div>
</div>
