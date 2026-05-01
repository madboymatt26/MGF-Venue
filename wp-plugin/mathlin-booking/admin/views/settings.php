<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap mbs-admin">
    <h1>&#9884; Scout Bookings – Settings</h1>

    <div class="nms-settings-layout">

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
                <button id="nms-save-settings" class="button button-primary">Save Settings</button>
                <button id="nms-test-ha" class="button">Send Test Webhook</button>
                <span id="nms-settings-msg" class="nms-settings-msg"></span>
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
            <p>Because bookings require a minimum notice period, HA only needs to poll <strong>once a day at midnight</strong> to load that day's schedule. This is much more efficient than polling every few minutes.</p>
            <pre class="nms-code-block"># Poll once a day – today's bookings are loaded at midnight
rest:
  - resource: <?php echo esc_html( rest_url( 'mathlin/v1/bookings/today' ) ); ?>

    scan_interval: 86400   # 86400 seconds = once every 24 hours
    sensor:
      - name: "Scout Hall Today Booking Count"
        value_template: "{{ value_json | length }}"

      # First booking of the day
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

            <h3 style="margin-top:1.5rem">Trigger the poll at midnight (automations.yaml)</h3>
            <pre class="nms-code-block">automation:
  - alias: "Scout Hall – Load today's bookings at midnight"
    trigger:
      - platform: time
        at: "00:00:00"
    action:
      # Force HA to refresh the REST sensor immediately
      - service: homeassistant.update_entity
        target:
          entity_id:
            - sensor.scout_hall_today_booking_count
            - sensor.scout_hall_first_booking_today

  - alias: "Scout Hall – Pre-heat before each booking"
    trigger:
      # Fires when the sensor updates (i.e. at midnight with fresh data)
      - platform: state
        entity_id: sensor.scout_hall_first_booking_today
    condition:
      - condition: template
        value_template: >
          {{ states('sensor.scout_hall_today_booking_count') | int > 0 }}
    action:
      # Schedule heating to come on 1 hour before the first booking
      - service: climate.set_temperature
        target:
          entity_id: climate.scout_hall
        data:
          temperature: 19
      - service: notify.mobile_app_your_phone
        data:
          title: "Scout Hall booked today"
          message: >
            {{ state_attr('sensor.scout_hall_first_booking_today', 'space') }}
            at {{ state_attr('sensor.scout_hall_first_booking_today', 'start_time') }}
            ({{ state_attr('sensor.scout_hall_first_booking_today', 'attendees') }} people)</pre>
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
                        <input type="password" id="github_token" name="github_token"
                               value="<?php echo esc_attr( get_option( 'mbs_github_token', '' ) ); ?>"
                               class="regular-text"
                               placeholder="ghp_xxxxxxxxxxxxxxxxxxxx"
                               autocomplete="off">
                        <p class="description">
                            Required because the repository is <strong>private</strong>.<br>
                            Create a token at <a href="https://github.com/settings/tokens" target="_blank">github.com/settings/tokens</a> with <code>repo</code> scope.<br>
                            The token is stored in the WordPress database and only used to check for releases and download updates.
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

            <h4 style="margin-top:1.5rem">How to release an update</h4>
            <ol>
                <li>Update the version number in <code>mathlin-booking.php</code> (both the header and <code>MBS_VERSION</code>)</li>
                <li>Push your changes to the <code>main</code> branch on GitHub</li>
                <li>Go to <a href="https://github.com/madboymatt26/mathlin-booking/releases/new" target="_blank">GitHub → Releases → Create new release</a></li>
                <li>Set the tag to the version number (e.g. <code>1.0.1</code> or <code>v1.0.1</code>)</li>
                <li>Publish the release</li>
                <li>WordPress will detect the update within 12 hours, or check manually at <strong>Dashboard → Updates</strong></li>
            </ol>

            <div class="nms-settings-actions">
                <button id="nms-check-update" class="button">Check for Updates Now</button>
                <span id="nms-update-msg" class="nms-settings-msg"></span>
            </div>
        </div>

        <!-- General Settings -->
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
                            <strong>7</strong> = must book at least a week ahead.<br>
                            The booking form and calendar will automatically block out dates that are too soon.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>Admin Email</th>
                    <td><code>bookings@needhamscouts.uk</code> <span class="description">(edit in class-email.php to change)</span></td>
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
            <div class="nms-settings-actions">
                <button id="nms-save-settings" class="button button-primary">Save All Settings</button>
                <span id="nms-settings-msg" class="nms-settings-msg"></span>
            </div>
        </div>

    </div>
</div>
