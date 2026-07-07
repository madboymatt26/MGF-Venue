<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$osm           = MBS_OSM_Integration::get_settings();
$gwc_available = MBS_OSM_Integration::gilbertweb_available();
$connected     = MBS_OSM_Integration::is_connected();
$redirect_uri  = MBS_OSM_Integration::redirect_uri();
$has_secret    = ! empty( $osm['client_secret'] );
$ledger        = MBS_OSM_Integration::get_ledger( 50 );
$ledger_stats  = MBS_OSM_Integration::get_ledger_stats();

// Handshake result notices (set by the admin-post OAuth handlers).
$notice_map = array(
    'connected'    => array( 'success', '✅ Connected to OSM successfully.' ),
    'disconnected' => array( 'success', 'Disconnected from OSM. Stored tokens removed.' ),
    'noclient'     => array( 'error',   'Enter your OSM OAuth Client ID (and Secret) and save before connecting.' ),
    'badstate'     => array( 'error',   'Connection aborted: security check failed (state mismatch). Please try again.' ),
    'denied'       => array( 'error',   'Authorization was declined at OSM.' ),
    'nocode'       => array( 'error',   'OSM did not return an authorization code. Please try again.' ),
    'exchangefail' => array( 'error',   'Could not exchange the authorization code for a token. Check your Client ID/Secret and redirect URI.' ),
);
$flash = isset( $_GET['mbs_osm'] ) ? sanitize_key( wp_unslash( $_GET['mbs_osm'] ) ) : '';
?>

<div class="wrap">
    <h1>OSM Integration</h1>
    <p class="description">Record booking income in Online Scout Manager's finance tools. Each payment received (deposit, balance, refund) is posted as a separate finance record for the amount actually taken — reconciled through the sync ledger below.</p>

    <?php if ( $flash && isset( $notice_map[ $flash ] ) ) : ?>
        <div class="notice notice-<?php echo esc_attr( $notice_map[ $flash ][0] ); ?> is-dismissible">
            <p><?php echo esc_html( $notice_map[ $flash ][1] ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( ! $osm['sandbox_mode'] && $osm['enabled'] ) : ?>
        <div class="notice notice-warning">
            <p><strong>Live mode is on.</strong> The OSM finance endpoint/payload are unverified against a live section — keep Sandbox Mode on until you have confirmed finance-write API access with OSM and captured one real request/response.</p>
        </div>
    <?php endif; ?>

    <div id="mbs-osm-msg" class="notice" style="display:none;"></div>

    <table class="form-table">
        <tr>
            <th>Enable OSM Integration</th>
            <td>
                <label>
                    <input type="checkbox" id="osm_enabled" <?php checked( $osm['enabled'] ); ?>>
                    Record payments in OSM's finance area as they are received
                </label>
            </td>
        </tr>

        <tr>
            <th>🧪 Sandbox Mode</th>
            <td>
                <label>
                    <input type="checkbox" id="osm_sandbox_mode" <?php checked( $osm['sandbox_mode'] ); ?>>
                    Log payloads to <code>error_log</code> and mark ledger rows synced, without calling OSM
                </label>
                <p class="description">Leave this on until OSM has confirmed live finance-write access. The whole ledger/idempotency/retry flow is fully exercised in sandbox.</p>
            </td>
        </tr>

        <tr>
            <th colspan="2"><hr><h2 style="margin:0;">Authentication</h2></th>
        </tr>

        <tr>
            <th>Auth Source</th>
            <td>
                <select id="osm_auth_source">
                    <option value="gilbertweb" <?php selected( $osm['auth_source'], 'gilbertweb' ); ?>>
                        GilbertWeb Connector (shared tokens)<?php echo $gwc_available ? ' ✅' : ' ⚠️ Not connected'; ?>
                    </option>
                    <option value="standalone" <?php selected( $osm['auth_source'], 'standalone' ); ?>>
                        Standalone (own credentials)
                    </option>
                </select>
                <?php if ( $osm['auth_source'] === 'gilbertweb' ) : ?>
                    <?php if ( $gwc_available ) : ?>
                        <p class="description" style="color:#065f46;">✅ GilbertWeb Connector tokens detected — no additional credentials needed.</p>
                    <?php else : ?>
                        <p class="description" style="color:#dc3232;">⚠️ GilbertWeb Connector not authenticated. Switch to "Standalone" and connect with your own OSM OAuth app, or authenticate the GilbertWeb Connector plugin first.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>

        <tr class="mbs-osm-standalone" style="<?php echo $osm['auth_source'] === 'standalone' ? '' : 'display:none;'; ?>">
            <th>OSM OAuth Client ID</th>
            <td><input type="text" id="osm_client_id" class="regular-text" value="<?php echo esc_attr( $osm['client_id'] ); ?>"></td>
        </tr>

        <tr class="mbs-osm-standalone" style="<?php echo $osm['auth_source'] === 'standalone' ? '' : 'display:none;'; ?>">
            <th>OSM OAuth Client Secret</th>
            <td>
                <input type="password" id="osm_client_secret" class="regular-text" value="" autocomplete="new-password"
                       placeholder="<?php echo $has_secret ? '•••••••••• (saved — leave blank to keep)' : 'Paste your OAuth client secret'; ?>">
                <p class="description">Stored securely and never shown again. Leave blank to keep the existing secret.</p>
            </td>
        </tr>

        <tr class="mbs-osm-standalone" style="<?php echo $osm['auth_source'] === 'standalone' ? '' : 'display:none;'; ?>">
            <th>Redirect URI</th>
            <td>
                <input type="text" class="large-text code" readonly onclick="this.select();" value="<?php echo esc_attr( $redirect_uri ); ?>">
                <p class="description">Add this exact URL as an authorized redirect URI in your OSM OAuth application.</p>
            </td>
        </tr>

        <tr class="mbs-osm-standalone" style="<?php echo $osm['auth_source'] === 'standalone' ? '' : 'display:none;'; ?>">
            <th>Connection</th>
            <td>
                <?php if ( $connected ) : ?>
                    <span style="color:#065f46;font-weight:600;">✅ Connected</span>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:10px;">
                        <input type="hidden" name="action" value="mbs_osm_disconnect">
                        <?php wp_nonce_field( 'mbs_osm_disconnect' ); ?>
                        <button type="submit" class="button">Disconnect</button>
                    </form>
                <?php else : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="mbs_osm_connect">
                        <?php wp_nonce_field( 'mbs_osm_connect' ); ?>
                        <button type="submit" class="button button-primary">🔗 Connect to OSM</button>
                    </form>
                    <p class="description">Save your Client ID/Secret first, then connect to authorize finance access.</p>
                <?php endif; ?>
            </td>
        </tr>

        <tr>
            <th>Connection Test</th>
            <td>
                <button type="button" id="mbs-osm-test" class="button">🔌 Test Connection</button>
                <span id="mbs-osm-test-msg" style="margin-left:10px;"></span>
            </td>
        </tr>

        <tr>
            <th colspan="2"><hr><h2 style="margin:0;">OSM Mapping</h2></th>
        </tr>

        <tr>
            <th>Section ID</th>
            <td>
                <input type="text" id="osm_section_id" class="regular-text" value="<?php echo esc_attr( $osm['section_id'] ); ?>" placeholder="e.g. 40703">
                <button type="button" id="mbs-osm-load-sections" class="button button-small">Load Sections</button>
                <p class="description">The OSM section to post financial records to. Click "Load Sections" after connecting.</p>
                <div id="mbs-osm-sections-list" style="margin-top:8px;"></div>
            </td>
        </tr>

        <tr>
            <th>Finance Category ID</th>
            <td>
                <input type="text" id="osm_category_id" class="regular-text" value="<?php echo esc_attr( $osm['category_id'] ); ?>" placeholder="e.g. hall_hire">
                <p class="description">The finance category in OSM to assign payments to (e.g. "Hall Hire", "Venue Income").</p>
            </td>
        </tr>

        <tr>
            <th>Account ID</th>
            <td>
                <input type="text" id="osm_account_id" class="regular-text" value="<?php echo esc_attr( $osm['account_id'] ); ?>" placeholder="Optional">
                <p class="description">Optional: specific OSM account to post to.</p>
            </td>
        </tr>

        <tr>
            <th>Description Template</th>
            <td>
                <input type="text" id="osm_description_template" class="large-text" value="<?php echo esc_attr( $osm['description_tpl'] ); ?>">
                <p class="description">
                    Placeholders: <code>{ref}</code> <code>{name}</code> <code>{space}</code> <code>{date}</code> <code>{purpose}</code> <code>{organisation}</code>. The payment type and invoice reference are appended automatically.
                </p>
            </td>
        </tr>
    </table>

    <p>
        <button type="button" id="mbs-osm-save" class="button button-primary button-hero">💾 Save OSM Settings</button>
    </p>

    <hr>
    <h2>Sync Ledger &amp; Reconciliation</h2>
    <p class="description">
        Synced: <strong style="color:#065f46;"><?php echo (int) $ledger_stats['synced']; ?></strong> &bull;
        Pending: <strong style="color:#92400e;"><?php echo (int) $ledger_stats['pending']; ?></strong> &bull;
        Failed: <strong style="color:#dc3232;"><?php echo (int) $ledger_stats['failed']; ?></strong>
    </p>

    <?php if ( empty( $ledger ) ) : ?>
        <p>No finance events recorded yet. They appear here as payments are received.</p>
    <?php else : ?>
    <table class="widefat striped" style="max-width:960px;">
        <thead>
            <tr>
                <th>When</th><th>Booking</th><th>Type</th><th>Amount</th><th>Status</th><th>OSM Record</th><th>Detail</th><th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $ledger as $row ) :
                $status_colour = array(
                    'synced'  => '#065f46',
                    'pending' => '#92400e',
                    'failed'  => '#dc3232',
                    'skipped' => '#6b7280',
                )[ $row->status ] ?? '#6b7280';
            ?>
            <tr>
                <td><?php echo esc_html( $row->created_at ); ?></td>
                <td><a href="<?php echo esc_url( admin_url( 'admin.php?page=mathlin-booking&action=view&ref=' . $row->booking_ref ) ); ?>"><?php echo esc_html( $row->booking_ref ); ?></a></td>
                <td><?php echo esc_html( ucfirst( $row->type ) ); ?></td>
                <td>&pound;<?php echo esc_html( number_format( (float) $row->amount, 2 ) ); ?></td>
                <td><span style="color:<?php echo esc_attr( $status_colour ); ?>;font-weight:600;"><?php echo esc_html( ucfirst( $row->status ) ); ?></span></td>
                <td><?php echo esc_html( $row->osm_record_id ?: '—' ); ?></td>
                <td style="max-width:220px;font-size:12px;color:#6b7280;"><?php echo esc_html( $row->last_error ?: '' ); ?></td>
                <td>
                    <?php if ( in_array( $row->status, array( 'failed', 'pending' ), true ) ) : ?>
                        <button type="button" class="button button-small mbs-osm-retry" data-id="<?php echo (int) $row->id; ?>">Retry</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script>
jQuery(function($) {
    $('#osm_auth_source').on('change', function() {
        $('.mbs-osm-standalone').toggle($(this).val() === 'standalone');
    });

    $('#mbs-osm-save').on('click', function() {
        var $btn = $(this);
        var $msg = $('#mbs-osm-msg');
        $btn.prop('disabled', true).text('Saving…');

        $.post(MBS_Admin.ajax_url, {
            action:                   'mbs_save_osm_settings',
            nonce:                    MBS_Admin.nonce,
            osm_enabled:              $('#osm_enabled').is(':checked') ? 1 : 0,
            osm_sandbox_mode:         $('#osm_sandbox_mode').is(':checked') ? 1 : 0,
            osm_auth_source:          $('#osm_auth_source').val(),
            osm_client_id:            $('#osm_client_id').val(),
            osm_client_secret:        $('#osm_client_secret').val(), // blank = keep existing
            osm_section_id:           $('#osm_section_id').val(),
            osm_category_id:          $('#osm_category_id').val(),
            osm_account_id:           $('#osm_account_id').val(),
            osm_description_template: $('#osm_description_template').val()
        }, function(res) {
            $btn.prop('disabled', false).text('💾 Save OSM Settings');
            if (res.success) {
                $('#osm_client_secret').val(''); // never keep the secret in the DOM
                $msg.removeClass('notice-error').addClass('notice-success').html('<p>✓ OSM settings saved.</p>').show();
            } else {
                $msg.removeClass('notice-success').addClass('notice-error').html('<p>✗ Error saving settings.</p>').show();
            }
            setTimeout(function() { $msg.hide(); }, 4000);
        }).fail(function() {
            $btn.prop('disabled', false).text('💾 Save OSM Settings');
            $msg.removeClass('notice-success').addClass('notice-error').html('<p>✗ Network error.</p>').show();
        });
    });

    $('#mbs-osm-test').on('click', function() {
        var $btn = $(this);
        var $msg = $('#mbs-osm-test-msg');
        $btn.prop('disabled', true).text('Testing…');

        $.post(MBS_Admin.ajax_url, { action: 'mbs_test_osm_connection', nonce: MBS_Admin.nonce }, function(res) {
            $btn.prop('disabled', false).text('🔌 Test Connection');
            if (res.success) {
                $msg.text('✅ ' + res.data.message).css('color', '#065f46');
            } else {
                $msg.text('❌ ' + (res.data && res.data.message ? res.data.message : res.data)).css('color', '#dc3232');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('🔌 Test Connection');
            $msg.text('❌ Network error').css('color', '#dc3232');
        });
    });

    $('#mbs-osm-load-sections').on('click', function() {
        var $btn = $(this);
        var $list = $('#mbs-osm-sections-list');
        $btn.prop('disabled', true).text('Loading…');

        $.post(MBS_Admin.ajax_url, { action: 'mbs_osm_get_sections', nonce: MBS_Admin.nonce }, function(res) {
            $btn.prop('disabled', false).text('Load Sections');
            if (res.success && res.data) {
                var html = '<strong>Available sections:</strong><ul style="margin:4px 0 0 16px;">';
                var sections = res.data;
                var render = function(id, name) {
                    html += '<li><code>' + id + '</code> — ' + $('<span>').text(name).html() +
                            ' <button type="button" class="button button-small mbs-osm-pick-section" data-id="' + id + '">Use</button></li>';
                };
                if (Array.isArray(sections)) {
                    sections.forEach(function(s) {
                        render(s.sectionid || s.section_id || s.id || '', s.sectionname || s.section_name || s.name || 'Unknown');
                    });
                } else if (typeof sections === 'object') {
                    for (var key in sections) {
                        if (sections[key] && typeof sections[key] === 'object') {
                            var s = sections[key];
                            render(s.sectionid || s.section_id || key, s.sectionname || s.section_name || s.name || key);
                        }
                    }
                }
                html += '</ul>';
                $list.html(html);
            } else {
                $list.html('<span style="color:#dc3232;">Could not load sections. ' + (res.data || '') + '</span>');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Load Sections');
            $list.html('<span style="color:#dc3232;">Network error.</span>');
        });
    });

    $(document).on('click', '.mbs-osm-pick-section', function() {
        $('#osm_section_id').val($(this).data('id'));
    });

    $(document).on('click', '.mbs-osm-retry', function() {
        var $btn = $(this);
        var id = $btn.data('id');
        $btn.prop('disabled', true).text('Retrying…');
        $.post(MBS_Admin.ajax_url, { action: 'mbs_osm_retry_row', nonce: MBS_Admin.nonce, id: id }, function(res) {
            if (res.success) {
                $btn.closest('tr').find('td').eq(4).html('<span style="font-weight:600;">' + (res.data.status.charAt(0).toUpperCase() + res.data.status.slice(1)) + '</span>');
                $btn.text('Retry').prop('disabled', false);
            } else {
                $btn.text('Retry').prop('disabled', false);
            }
        }).fail(function() {
            $btn.text('Retry').prop('disabled', false);
        });
    });
});
</script>
