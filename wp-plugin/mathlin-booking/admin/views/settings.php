<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap mbs-admin">
    <h1><?php echo MBS_Admin::brand_mark(); ?>MGF Venue – Settings</h1>

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
                        <th>Parent Space</th>
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
                        <td><input type="text" class="nms-space-parent" value="<?php echo esc_attr( $info['parent'] ?? '' ); ?>" style="width:120px;" placeholder="None"></td>
                        <td><button type="button" class="button nms-remove-space" title="Remove space">&times;</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:12px;">
                <button type="button" class="button" id="nms-add-space">+ Add Space</button>
            </p>
            <p class="description">
                <strong>Parent Space:</strong> For space bundling, enter the name of the parent space (e.g. "Whole Headquarters"). Booking a parent blocks all children, and booking a child blocks the parent.
            </p>

            <h4 style="margin-top:1.5rem">Kitchen Add-on</h4>
            <table class="form-table">
                <tr>
                    <th><label for="kitchen_enabled">Kitchen option</label></th>
                    <td>
                        <select id="kitchen_enabled">
                            <option value="1" <?php selected( get_option( 'mbs_kitchen_enabled', 1 ), 1 ); ?>>Enabled — show on booking form</option>
                            <option value="0" <?php selected( get_option( 'mbs_kitchen_enabled', 1 ), 0 ); ?>>Disabled — hide from booking form</option>
                        </select>
                    </td>
                </tr>
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
                               placeholder="bookings@example.com">
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
            <p>Configure the bank details and payment terms shown on invoices and confirmation emails. These bank details are also used automatically for B2B / offline-invoicing hirers (see <em>Purchase Order / Offline Notes</em> under Venue &amp; Legal).</p>
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
                    <td><input type="text" id="bank_account_name" value="<?php echo esc_attr( get_option( 'mbs_bank_account_name', '' ) ); ?>" class="regular-text" placeholder="Your Organisation Name"></td>
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
      - name: "Venue Today Booking Count"
        value_template: "{{ value_json | length }}"

      - name: "Venue First Booking Today"
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
                    <td><code>madboymatt26/MGF-Venue</code></td>
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

        <!-- Venue & Legal -->
        <div class="nms-card">
            <div class="nms-card-header"><h2>📋 Venue &amp; Legal</h2></div>
            <p>Configure venue details and terms & conditions. These are used by the <code>[mathlin_terms]</code> and <code>[mathlin_venue_info]</code> shortcodes.</p>
            <table class="form-table">
                <tr>
                    <th><label for="venue_capacity">Venue Capacity</label></th>
                    <td>
                        <input type="number" id="venue_capacity" value="<?php echo esc_attr( get_option( 'mbs_venue_capacity', 100 ) ); ?>" min="1" style="width:80px"> people
                        <p class="description">Maximum permitted number for the hall (seating capacity).</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="curfew_saturday">Curfew – Saturday</label></th>
                    <td>
                        <input type="text" id="curfew_saturday" value="<?php echo esc_attr( get_option( 'mbs_curfew_saturday', '11:00 PM' ) ); ?>" class="regular-text" placeholder="11:00 PM">
                        <p class="description">All events must end by this time on Saturdays.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="curfew_sunday">Curfew – Sunday to Friday</label></th>
                    <td>
                        <input type="text" id="curfew_sunday" value="<?php echo esc_attr( get_option( 'mbs_curfew_sunday', '10:00 PM' ) ); ?>" class="regular-text" placeholder="10:00 PM">
                        <p class="description">All events must end by this time on Sunday through Friday.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="payment_days_required">Payment Required Before Event</label></th>
                    <td>
                        <input type="number" id="payment_days_required" value="<?php echo esc_attr( get_option( 'mbs_payment_days_required', 28 ) ); ?>" min="1" max="90" style="width:80px"> days
                        <p class="description">Full payment must be received this many days before the event, or the booking may be cancelled.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="offline_payment_instructions">Purchase Order / Offline Notes <span style="font-weight:normal;color:#777;">(optional)</span></label></th>
                    <td>
                        <?php
                        wp_editor(
                            get_option( 'mbs_offline_payment_instructions', '' ),
                            'offline_payment_instructions',
                            array(
                                'textarea_name' => 'offline_payment_instructions',
                                'textarea_rows' => 6,
                                'media_buttons' => false,
                                'teeny'         => true,
                            )
                        );
                        ?>
                        <p class="description" style="margin-top:8px;">
                            Shown to B2B hirers (councils, businesses) on tiers with <strong>Offline Invoicing</strong> enabled, instead of the "Pay Now" card button.<br>
                            <strong>You don't need to repeat your bank details here</strong> — the Sort Code, Account Number and Account Name from <em>Payment &amp; Invoice Settings</em> above are added automatically, along with the payment reference. Use this box only for <em>extra</em> instructions, e.g. where to email purchase orders.<br>
                            Supports placeholders: <code>{invoice}</code>, <code>{ref}</code>, <code>{amount}</code>.<br>
                            Example: <em>Purchase orders can be emailed to accounts@example.com quoting reference {invoice}.</em>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="terms_text">Terms &amp; Conditions</label></th>
                    <td>
                        <?php
                        $terms_text = get_option( 'mbs_terms_text', '' );
                        if ( empty( $terms_text ) ) {
                            $terms_text = MBS_Bookings::get_default_terms();
                        }
                        wp_editor( $terms_text, 'terms_text', array(
                            'textarea_name' => 'terms_text',
                            'textarea_rows' => 15,
                            'media_buttons' => false,
                            'teeny'         => false,
                        ) );
                        ?>
                        <p class="description" style="margin-top:8px;">
                            Supports placeholders: <code>{org_name}</code>, <code>{admin_email}</code>, <code>{venue_capacity}</code>, <code>{curfew_saturday}</code>, <code>{curfew_sunday}</code>, <code>{payment_days_required}</code>, <code>{org_address}</code>, <code>{org_phone}</code>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="booking_notice">Booking Notice</label></th>
                    <td>
                        <textarea id="booking_notice" rows="3" class="large-text"><?php echo esc_textarea( get_option( 'mbs_booking_notice', '' ) ); ?></textarea>
                        <p class="description">Displayed prominently on the booking form and venue info page. Use for important restrictions (e.g. "We do not hire for adult parties that include alcohol").</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="facilities_text">Facilities</label></th>
                    <td>
                        <?php
                        wp_editor(
                            get_option( 'mbs_facilities_text', '' ),
                            'facilities_text',
                            array(
                                'textarea_name' => 'facilities_text',
                                'textarea_rows' => 8,
                                'media_buttons' => true,
                                'teeny'         => false,
                            )
                        );
                        ?>
                        <p class="description" style="margin-top:8px;">Describe your venue's facilities (parking, accessibility, rooms, equipment). Displayed on the <code>[mathlin_venue_info]</code> page. HTML and images supported.</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Access Details -->
        <div class="nms-card">
            <div class="nms-card-header"><h2>🔑 Access Details</h2></div>
            <p>Send keysafe/access code to paid bookers before their event. Admin team also receives a booking reminder.</p>
            <table class="form-table">
                <tr>
                    <th><label for="access_enabled">Access emails</label></th>
                    <td>
                        <select id="access_enabled">
                            <option value="0" <?php selected( get_option( 'mbs_access_enabled', 0 ), 0 ); ?>>Disabled</option>
                            <option value="1" <?php selected( get_option( 'mbs_access_enabled', 0 ), 1 ); ?>>Enabled</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="access_code">Access code</label></th>
                    <td>
                        <input type="text" id="access_code" value="<?php echo esc_attr( get_option( 'mbs_access_code', '' ) ); ?>" class="regular-text" placeholder="e.g. 4829">
                        <p class="description">The current keysafe/lock code. Update this whenever you change the physical code.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="access_instructions">Access instructions</label></th>
                    <td>
                        <textarea id="access_instructions" rows="4" class="large-text" placeholder="e.g. The keysafe is located on the wall to the right of the main entrance..."><?php echo esc_textarea( get_option( 'mbs_access_instructions', '' ) ); ?></textarea>
                        <p class="description">Directions for finding and using the keysafe. Sent with the access code.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="access_hours_before">Send</label></th>
                    <td>
                        <input type="number" id="access_hours_before" value="<?php echo esc_attr( get_option( 'mbs_access_hours_before', 24 ) ); ?>" min="1" max="168" style="width:80px"> hours before booking
                        <p class="description">Only sent to paid or deposit-paid bookings.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="access_health_safety">Health &amp; Safety</label></th>
                    <td>
                        <textarea id="access_health_safety" rows="5" class="large-text" placeholder="e.g. Fire exits are located at the front and rear of the building. First aid kit is in the kitchen..."><?php echo esc_textarea( get_option( 'mbs_access_health_safety', '' ) ); ?></textarea>
                        <p class="description">Important safety information sent with the access details. Include fire exits, first aid, emergency contacts, etc.</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Post-Booking Feedback & Reviews -->
        <div class="nms-card">
            <div class="nms-card-header"><h2>💬 Post-Booking Feedback &amp; Reviews</h2></div>
            <p>Automatically email hirers one day after their booking ends, asking for a Google review or private feedback. Scout/internal bookings are always excluded.</p>
            <table class="form-table">
                <tr>
                    <th><label for="feedback_enabled">Feedback emails</label></th>
                    <td>
                        <select id="feedback_enabled">
                            <option value="0" <?php selected( get_option( 'mbs_feedback_enabled', 0 ), 0 ); ?>>Disabled</option>
                            <option value="1" <?php selected( get_option( 'mbs_feedback_enabled', 0 ), 1 ); ?>>Enabled</option>
                        </select>
                        <p class="description">Master switch for the automated post-booking feedback request. Runs daily at 10am via WP-Cron.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="feedback_review_url">Google Review URL</label></th>
                    <td>
                        <input type="url" id="feedback_review_url" value="<?php echo esc_attr( get_option( 'mbs_feedback_review_url', '' ) ); ?>" class="regular-text" placeholder="https://g.page/r/…/review">
                        <p class="description">The public link where hirers can leave you a Google review. Used by the <code>{review_link}</code> tag.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="feedback_distribution_email">Distribution Email Address</label></th>
                    <td>
                        <input type="email" id="feedback_distribution_email" value="<?php echo esc_attr( get_option( 'mbs_feedback_distribution_email', '' ) ); ?>" class="regular-text" placeholder="feedback@example.com">
                        <p class="description">Where private feedback submissions are routed. Defaults to the admin email if left blank.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="feedback_subject">Email Subject</label></th>
                    <td>
                        <input type="text" id="feedback_subject" value="<?php echo esc_attr( get_option( 'mbs_feedback_subject', 'How was your time at {org_name}?' ) ); ?>" class="regular-text" style="width:100%;max-width:500px;">
                    </td>
                </tr>
                <tr>
                    <th><label for="feedback_body">Email Body</label></th>
                    <td>
                        <?php
                        wp_editor(
                            get_option( 'mbs_feedback_body', MBS_Feedback::default_body() ),
                            'feedback_body',
                            array(
                                'textarea_name' => 'feedback_body',
                                'textarea_rows' => 10,
                                'media_buttons' => false,
                                'teeny'         => true,
                            )
                        );
                        ?>
                        <p class="description" style="margin-top:8px;">
                            Supports placeholders: <code>{hirer_name}</code>, <code>{booking_date}</code>, <code>{review_link}</code> (Google review button), <code>{feedback_link}</code> (private feedback form button), <code>{space}</code>, <code>{ref}</code>, <code>{org_name}</code>.<br>
                            <strong>Important:</strong> Create a WordPress page containing the <code>[mathlin_feedback]</code> shortcode so the private feedback form has somewhere to live.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>Reliable scheduling</th>
                    <td>
                        <p class="description" style="margin-top:0;">
                            Feedback emails are sent by WP-Cron, which only runs when your site receives traffic. On a quiet site the daily job can run late. For dependable, on-time sending, disable WordPress's built-in pseudo-cron and use a real server cron:<br>
                            1. Add to <code>wp-config.php</code>: <code>define( 'DISABLE_WP_CRON', true );</code><br>
                            2. Add a server crontab entry (e.g. every 15 minutes):<br>
                            <code>*/15 * * * * wget -q -O - <?php echo esc_html( home_url( '/wp-cron.php?doing_wp_cron' ) ); ?> &gt;/dev/null 2&gt;&amp;1</code><br>
                            You can also use a free external service (e.g. cron-job.org) to hit that URL on a schedule.
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Booking Rules -->
        <div class="nms-card">
            <div class="nms-card-header"><h2>⚙️ Booking Rules</h2></div>
            <p>Control how far in advance people can book.</p>
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
                    <th>Scout Volunteer Emails</th>
                    <td>
                        <textarea id="scout_volunteer_emails" name="scout_volunteer_emails" rows="3" class="regular-text" style="width:100%;max-width:500px;"
                                  placeholder="leader1@example.com&#10;leader2@example.com"><?php echo esc_textarea( get_option( 'mbs_scout_volunteer_emails', '' ) ); ?></textarea>
                        <p class="description">
                            One email per line. Users with these email addresses will have "Scout Use" auto-selected on the booking form and their bookings will be free of charge.<br>
                            This also applies to hirer portal accounts registered with these emails.
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

        <!-- Deposit Management -->
        <div class="nms-card">
            <div class="nms-card-header"><h2>💰 Payment &amp; Deposits</h2></div>
            <p>Configure how and when payment is collected from hirers.</p>
            <table class="form-table">
                <tr>
                    <th><label for="deposit_enabled">Deposit System</label></th>
                    <td>
                        <select id="deposit_enabled">
                            <option value="0" <?php selected( get_option( 'mbs_deposit_enabled', 0 ), 0 ); ?>>Disabled — full payment required upfront</option>
                            <option value="1" <?php selected( get_option( 'mbs_deposit_enabled', 0 ), 1 ); ?>>Enabled — collect deposit then balance</option>
                        </select>
                    </td>
                </tr>
                <tr class="mbs-deposit-field" <?php if ( ! get_option( 'mbs_deposit_enabled', 0 ) ) echo 'style="display:none;"'; ?>>
                    <th><label for="deposit_percentage">Deposit amount</label></th>
                    <td>
                        <input type="number" id="deposit_percentage" value="<?php echo esc_attr( get_option( 'mbs_deposit_percentage', 25 ) ); ?>" min="1" max="99" style="width:80px">%
                        <p class="description">Percentage of total cost required as deposit at booking time.</p>
                    </td>
                </tr>
                <tr class="mbs-deposit-field" <?php if ( ! get_option( 'mbs_deposit_enabled', 0 ) ) echo 'style="display:none;"'; ?>>
                    <th><label for="deposit_balance_days">Balance due</label></th>
                    <td>
                        <input type="number" id="deposit_balance_days" value="<?php echo esc_attr( get_option( 'mbs_deposit_balance_days', 7 ) ); ?>" min="1" max="90" style="width:80px"> days before event
                        <p class="description">Remaining balance must be paid this many days before the event. If the booking is made within this window, 100% is due immediately.</p>
                    </td>
                </tr>
                <tr class="mbs-no-deposit-field" <?php if ( get_option( 'mbs_deposit_enabled', 0 ) ) echo 'style="display:none;"'; ?>>
                    <th><label for="payment_terms_days">Payment due within</label></th>
                    <td>
                        <input type="number" id="payment_terms_days" value="<?php echo esc_attr( get_option( 'mbs_payment_terms_days', 14 ) ); ?>" min="1" max="90" style="width:80px"> days of confirmation
                        <p class="description">When deposits are disabled, full payment is due within this many days of the booking being confirmed.</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Pricing Tiers -->
        <div class="nms-card">
            <div class="nms-card-header"><h2>🏷️ Pricing Tiers</h2></div>
            <p>Configure pricing multipliers for different customer types. Assign tiers to hirers via their WordPress user profile.</p>
            <?php $tiers = MBS_Bookings::get_pricing_tiers(); ?>
            <table class="widefat" id="nms-tiers-table">
                <thead>
                    <tr>
                        <th>Tier Key</th>
                        <th>Label</th>
                        <th>Rate Multiplier</th>
                        <th>Bypass Payment Gate for Access Codes</th>
                        <th>Offline Invoicing (BACS/PO)</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="nms-tiers-tbody">
                    <?php foreach ( $tiers as $key => $tier ) : ?>
                    <tr class="nms-tier-row">
                        <td><input type="text" class="nms-tier-key" value="<?php echo esc_attr( $key ); ?>" style="width:120px;" <?php echo $key === 'standard' ? 'readonly' : ''; ?>></td>
                        <td><input type="text" class="nms-tier-label" value="<?php echo esc_attr( $tier['label'] ); ?>" style="width:180px;"></td>
                        <td><input type="number" class="nms-tier-multiplier" value="<?php echo esc_attr( $tier['multiplier'] ); ?>" min="0" step="0.05" style="width:80px;"> ×</td>
                        <td style="text-align:center;"><input type="checkbox" class="nms-tier-bypass" <?php checked( ! empty( $tier['bypass_access_gate'] ) ); ?>></td>
                        <td style="text-align:center;"><input type="checkbox" class="nms-tier-offline" <?php checked( ! empty( $tier['offline_invoicing'] ) ); ?>></td>
                        <td><?php if ( $key !== 'standard' ) : ?><button type="button" class="button nms-remove-tier">&times;</button><?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:12px;">
                <button type="button" class="button" id="nms-add-tier">+ Add Tier</button>
            </p>
            <p class="description">Standard (1.0×) is the base rate. Community (0.75×) = 25% discount. Commercial (1.5×) = 50% surcharge.<br>You can also set tier-specific rates per space by adding <code>rate_hourly_[tier_key]</code> and <code>rate_daily_[tier_key]</code> fields to the space config.<br><strong>Bypass Payment Gate:</strong> trusted tiers (e.g. Council, Commercial PO customers) receive their access code 24h before the event once <em>confirmed</em>, without needing to have paid in full. Leave unticked for public hirers (strict full-payment required).<br><strong>Offline Invoicing (BACS/PO):</strong> tick for councils/businesses who pay by bank transfer or purchase order. Suppresses all "Pay Now" card buttons, shows your BACS / PO instructions instead, and replaces aggressive payment chasers with a gentle statement reminder.</p>
        </div>

        <!-- Single Save Button -->
        <div class="nms-card" style="text-align:center;padding:24px;">
            <button id="nms-save-all" class="button button-primary button-hero">💾 Save All Settings</button>
            <span id="nms-save-msg" class="nms-settings-msg" style="display:block;margin-top:12px;"></span>
        </div>

    </div>
</div>
