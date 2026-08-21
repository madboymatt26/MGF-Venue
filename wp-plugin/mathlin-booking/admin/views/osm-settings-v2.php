<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$osm = MBS_OSM_Integration::get_settings();
$health = MBS_OSM_Integration::get_queue_health();
$secret_configured = $osm['client_secret'] !== '';
$route_labels = array( 'woopayments_payout' => 'WooPayments payout', 'bank_match' => 'Direct/BACS bank match', 'manual_review' => 'Manual review' );
?>
<style>
.mbs-osm-workflow{display:grid;grid-template-columns:repeat(5,minmax(130px,1fr));gap:10px;margin:18px 0}.mbs-osm-step{background:#fff;border:1px solid #dcdcde;border-top:4px solid #7413dc;border-radius:6px;padding:12px;position:relative}.mbs-osm-step strong{display:block;margin-bottom:4px}.mbs-osm-step:not(:last-child):after{content:'→';position:absolute;right:-10px;top:34%;z-index:2;color:#7413dc;font-weight:700}.mbs-osm-statuses{display:flex;gap:8px;flex-wrap:wrap}.mbs-osm-pill{display:inline-block;background:#f0f0f1;border-radius:999px;padding:5px 10px}.mbs-osm-detail{padding:12px 4px}.mbs-osm-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.mbs-osm-detail table{margin-top:8px}.mbs-osm-audit{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:12px}.mbs-osm-audit div{background:#f6f7f7;padding:8px;border-radius:4px}@media(max-width:900px){.mbs-osm-workflow,.mbs-osm-detail-grid{grid-template-columns:1fr}.mbs-osm-step:not(:last-child):after{display:none}.mbs-osm-audit{grid-template-columns:1fr}}
</style>
<div class="wrap">
    <h1>OSM Accountancy Integration</h1>
    <div class="notice notice-info inline"><p><strong>The Co-op bank import remains the source of truth.</strong> MGF Venue does not add bank transactions. It matches each WooPayments payout to one existing imported transaction, then splits it between venue hire, clothing/shop income, refunds and fees.</p></div>
    <?php if ( ! empty( $osm['upgrade_required'] ) ) : ?><div class="notice notice-warning inline"><p><strong>The previous OSM integration has been paused safely.</strong> Complete the new category/item mappings below, save with sandbox mode enabled, then test a real mixed payout before enabling live writes. Booking and payment processing remain available while it is paused.</p></div><?php endif; ?>
    <div class="notice notice-warning inline"><p>Keep sandbox mode on until the mappings below have been discovered and a mixed WooPayments payout has been checked on staging. Unmapped products, mismatched totals and ambiguous bank matches stop for review.</p></div>
    <div id="mbs-osm-msg" class="notice" style="display:none"><p></p></div>

    <h2>How reconciliation works</h2>
    <div class="mbs-osm-workflow" aria-label="OSM reconciliation workflow">
        <div class="mbs-osm-step"><strong>1. Payment received</strong><span>MGF records the exact venue payment or refund.</span></div>
        <div class="mbs-osm-step"><strong>2. Payout classified</strong><span>Venue, clothing, refunds and fees are proved to the payout net.</span></div>
        <div class="mbs-osm-step"><strong>3. Awaiting Co-op import</strong><span>The payout waits safely until its statement line exists in OSM.</span></div>
        <div class="mbs-osm-step"><strong>4. Bank line matched</strong><span>One exact, unclassified imported transaction is selected.</span></div>
        <div class="mbs-osm-step"><strong>5. Added to OSM</strong><span>One categorised cashbook record is linked to that existing line.</span></div>
    </div>

    <h2>Connection and safety</h2>
    <table class="form-table">
        <tr><th>Enable integration</th><td><label><input type="checkbox" id="osm_enabled" <?php checked( $osm['enabled'] ); ?>> Queue new finance events for reconciliation</label></td></tr>
        <tr><th>Sandbox mode</th><td><label><input type="checkbox" id="osm_sandbox_mode" <?php checked( $osm['sandbox_mode'] ); ?>> Match and preview, but do not write a cashbook record</label></td></tr>
        <tr><th>Authentication</th><td><select id="osm_auth_source"><option value="standalone" <?php selected( $osm['auth_source'], 'standalone' ); ?>>Dedicated MGF Venue OAuth client</option><option value="gilbertweb" <?php selected( $osm['auth_source'], 'gilbertweb' ); ?>>Existing GilbertWeb token (read-only compatibility)</option></select><p class="description">A dedicated client is recommended. MGF Venue never refreshes or changes GilbertWeb's private token.</p></td></tr>
        <tr><th>OAuth client ID</th><td><input id="osm_client_id" class="regular-text" value="<?php echo esc_attr( $osm['client_id'] ); ?>"></td></tr>
        <tr><th>OAuth client secret</th><td><input id="osm_client_secret" type="password" class="regular-text" value="" placeholder="<?php echo $secret_configured ? 'Configured — leave blank to preserve' : 'Not configured'; ?>"><p class="description">Leaving this blank preserves the existing secret.</p></td></tr>
        <tr><th>Connection</th><td><button class="button" id="mbs-osm-test">Test authentication and finance access</button></td></tr>
    </table>

    <h2>OSM mappings</h2>
    <p>Use OSM Accountancy's numeric IDs. Category identifies the income/expense heading; item identifies the linked ledger item.</p>
    <table class="form-table">
        <tr><th>Section ID</th><td><input id="osm_section_id" value="<?php echo esc_attr( $osm['section_id'] ); ?>"> <button class="button mbs-osm-discover" data-kind="sections">Discover sections</button></td></tr>
        <tr><th>Co-op bank account ID</th><td><input id="osm_bank_account_id" value="<?php echo esc_attr( $osm['bank_account_id'] ); ?>"> <button class="button mbs-osm-discover" data-kind="bank_accounts">Discover accounts</button></td></tr>
        <tr><th>Venue hire income</th><td>Category <input id="osm_venue_category_id" value="<?php echo esc_attr( $osm['venue_category_id'] ); ?>"> Item <input id="osm_venue_item_id" value="<?php echo esc_attr( $osm['venue_item_id'] ); ?>"></td></tr>
        <tr><th>Group clothing income</th><td>Category <input id="osm_clothing_category_id" value="<?php echo esc_attr( $osm['clothing_category_id'] ); ?>"> Item <input id="osm_clothing_item_id" value="<?php echo esc_attr( $osm['clothing_item_id'] ); ?>"></td></tr>
        <tr><th>Bank fees expense</th><td>Category <input id="osm_fees_category_id" value="<?php echo esc_attr( $osm['fees_category_id'] ); ?>"> Item <input id="osm_fees_item_id" value="<?php echo esc_attr( $osm['fees_item_id'] ); ?>"> <button class="button mbs-osm-discover" data-kind="categories">Discover categories</button> <button class="button mbs-osm-discover" data-kind="items">Discover items</button></td></tr>
        <tr><th>Bank date tolerance</th><td><input id="osm_match_days" type="number" min="0" max="7" value="<?php echo esc_attr( $osm['match_days'] ); ?>"> days</td></tr>
        <tr><th>Description</th><td><input id="osm_description_template" class="large-text" value="<?php echo esc_attr( $osm['description_tpl'] ); ?>"><p class="description">Placeholders: <code>{payout_id}</code>, <code>{date}</code>, <code>{amount}</code>.</p></td></tr>
        <tr><th>Additional shop mappings</th><td><textarea id="osm_product_mappings" class="large-text code" rows="7"><?php echo esc_textarea( wp_json_encode( $osm['product_mappings'], JSON_PRETTY_PRINT ) ); ?></textarea><p class="description">JSON rules may contain <code>label</code>, <code>product_ids</code>, <code>category_ids</code>, <code>mapping_keys</code>, <code>category_id</code> and <code>item_id</code>. Products/categories containing clothing, uniform, scarf, necker or neckie use the clothing mapping automatically.</p></td></tr>
    </table>
    <div id="mbs-osm-discovery" class="notice notice-info inline" style="display:none"><pre style="max-height:320px;overflow:auto;white-space:pre-wrap"></pre></div>
    <p><button class="button button-primary" id="mbs-osm-save">Save settings</button></p>

    <h2>WooPayments payout reconciliation</h2>
    <p>This reads recent paid WooPayments payouts and finds their matching, unclassified Co-op transaction in OSM. Entering exact IDs is useful when a match is outside the recent list or several bank lines share the same amount.</p>
    <p><label>Payout ID (optional) <input id="osm_payout_id" class="regular-text"></label> <label>OSM bank transaction ID (optional) <input id="osm_bank_transaction_match"></label> <button class="button button-secondary" id="mbs-osm-sync">Sync paid payouts</button></p>

    <h2>Queue health</h2>
    <div class="mbs-osm-statuses"><?php foreach ( $health['payouts'] as $status => $count ) : ?><span class="mbs-osm-pill"><strong><?php echo esc_html( MBS_OSM_Integration::status_label( $status ) ); ?>:</strong> <?php echo (int) $count; ?></span><?php endforeach; ?><?php if ( ! $health['payouts'] ) : ?><span class="mbs-osm-pill">No payouts recorded</span><?php endif; ?></div>
    <h3>Recent payouts</h3>
    <table class="widefat striped"><thead><tr><th>Payout</th><th>Date</th><th>Amount</th><th>OSM bank / cashbook</th><th>Status</th><th>Message</th></tr></thead><tbody>
    <?php if ( ! $health['recent_payouts'] ) : ?><tr><td colspan="6">No payouts have been processed.</td></tr><?php endif; ?>
    <?php foreach ( $health['recent_payouts'] as $row ) :
        $classification = json_decode( (string) $row['payload_json'], true ); $classification = is_array( $classification ) ? $classification : array();
        $categories = (array) ( $classification['categories'] ?? array() ); $components = (array) ( $classification['components'] ?? array() );
    ?>
        <tr><td><strong><?php echo esc_html( $row['payout_ref'] ); ?></strong></td><td><?php echo esc_html( $row['payout_date'] ); ?></td><td><?php echo esc_html( MBS_Money::format( (int) $row['amount_minor'] ) ); ?></td><td><?php echo esc_html( ( $row['bank_transaction_id'] ?: '—' ) . ' / ' . ( $row['cashbook_transaction_id'] ?: '—' ) ); ?></td><td><strong><?php echo esc_html( MBS_OSM_Integration::status_label( $row['status'] ) ); ?></strong><?php if ( in_array( $row['status'], array( 'awaiting_bank_import', 'manual_reconciliation', 'sandbox_preview' ), true ) ) : ?><br><button class="button button-small mbs-osm-retry" data-entity="payout" data-id="<?php echo (int) $row['id']; ?>">Retry</button><?php endif; ?><?php if ( $row['status'] === 'manual_reconciliation' ) : ?> <button class="button button-small mbs-osm-resolve" data-entity="payout" data-id="<?php echo (int) $row['id']; ?>">Resolve</button><?php endif; ?></td><td><?php echo esc_html( $row['last_error'] ); ?></td></tr>
        <tr><td colspan="6"><details><summary><strong>Expand calculation and audit trail</strong></summary><div class="mbs-osm-detail">
            <div class="mbs-osm-detail-grid">
                <div><strong>OSM category calculation</strong><table class="widefat striped"><thead><tr><th>Allocation</th><th>OSM category/item</th><th>Contribution</th></tr></thead><tbody>
                <?php foreach ( $categories as $line ) : $contribution = ( $line['kind'] ?? 'income' ) === 'expense' ? -(int) ( $line['amount'] ?? 0 ) : (int) ( $line['amount'] ?? 0 ); ?><tr><td><?php echo esc_html( $line['label'] ?? 'Unlabelled' ); ?></td><td><?php echo esc_html( (int) ( $line['category_id'] ?? 0 ) . ' / ' . (int) ( $line['item_id'] ?? 0 ) ); ?></td><td><?php echo esc_html( MBS_Money::format( $contribution ) ); ?></td></tr><?php endforeach; ?>
                <?php if ( ! $categories ) : ?><tr><td colspan="3">No stored category calculation.</td></tr><?php endif; ?>
                <tr><th colspan="2">Exact bank payout</th><th><?php echo esc_html( MBS_Money::format( (int) $row['amount_minor'] ) ); ?></th></tr></tbody></table></div>
                <div><strong>WooPayments components</strong><table class="widefat striped"><thead><tr><th>Order / transaction</th><th>Type</th><th>Gross</th><th>Fee</th><th>Net</th></tr></thead><tbody>
                <?php foreach ( $components as $component ) : ?><tr><td><?php if ( ! empty( $component['order_id'] ) ) : ?><a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $component['order_id'] . '&action=edit' ) ); ?>">Order #<?php echo (int) $component['order_id']; ?></a><?php else : ?><?php echo esc_html( $component['transaction_id'] ?? '—' ); ?><?php endif; ?></td><td><?php echo esc_html( $component['type'] ?? '—' ); ?></td><td><?php echo esc_html( MBS_Money::format( (int) ( $component['gross_minor'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( MBS_Money::format( -(int) ( $component['fee_minor'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( MBS_Money::format( (int) ( $component['net_minor'] ?? 0 ) ) ); ?></td></tr><?php endforeach; ?>
                <?php if ( ! $components ) : ?><tr><td colspan="5">This older record has an aggregate calculation but no component snapshot.</td></tr><?php endif; ?></tbody></table></div>
            </div>
            <div class="mbs-osm-audit"><div><strong>First classified</strong><br><?php echo esc_html( $row['created_at'] ); ?></div><div><strong>Last updated</strong><br><?php echo esc_html( $row['updated_at'] ); ?></div><div><strong>Delivered to OSM</strong><br><?php echo esc_html( $row['delivered_at'] ?: 'Not yet' ); ?></div><div><strong>Attempts</strong><br><?php echo (int) $row['attempts']; ?></div><div><strong>OSM bank transaction</strong><br><?php echo esc_html( $row['bank_transaction_id'] ?: 'Not matched' ); ?></div><div><strong>OSM cashbook transaction</strong><br><?php echo esc_html( $row['cashbook_transaction_id'] ?: 'Not created' ); ?></div><div><strong>Snapshot fingerprint</strong><br><code><?php echo esc_html( substr( (string) $row['payload_hash'], 0, 16 ) ); ?>…</code></div></div>
        </div></details></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <h3>Recent MGF finance events</h3>
    <table class="widefat striped"><thead><tr><th>Event</th><th>Invoice / order</th><th>Amount</th><th>Route</th><th>Status</th><th>Message</th></tr></thead><tbody>
    <?php if ( ! $health['recent_events'] ) : ?><tr><td colspan="6">No finance events have been queued.</td></tr><?php endif; ?>
    <?php foreach ( $health['recent_events'] as $row ) : ?><tr><td><?php echo esc_html( $row['event_ref'] ); ?></td><td><?php echo esc_html( $row['invoice_ref'] . ' / #' . $row['order_id'] ); ?></td><td><?php echo esc_html( MBS_Money::format( (int) $row['amount_minor'] ) ); ?></td><td><?php echo esc_html( $route_labels[$row['target_mode']] ?? $row['target_mode'] ); ?></td><td><strong><?php echo esc_html( MBS_OSM_Integration::status_label( $row['status'] ) ); ?></strong><?php if ( $row['target_mode'] === 'bank_match' && in_array( $row['status'], array( 'awaiting_bank_match', 'awaiting_bank_import', 'manual_reconciliation', 'sandbox_preview' ), true ) ) : ?><br><button class="button button-small mbs-osm-retry" data-entity="event" data-route="bank_match" data-id="<?php echo (int) $row['id']; ?>">Match bank line</button><?php endif; ?><?php if ( in_array( $row['status'], array( 'awaiting_bank_match', 'manual_reconciliation' ), true ) ) : ?> <button class="button button-small mbs-osm-resolve" data-entity="event" data-id="<?php echo (int) $row['id']; ?>">Resolve</button><?php endif; ?></td><td><?php echo esc_html( $row['last_error'] ); ?></td></tr><?php endforeach; ?>
    </tbody></table>
</div>
<script>
jQuery(function($){
    function show(ok,message,data){var $m=$('#mbs-osm-msg').removeClass('notice-success notice-error').addClass(ok?'notice-success':'notice-error').show();$m.find('p').text(message+(data?' '+JSON.stringify(data):''));}
    $('#mbs-osm-save').on('click',function(){var $b=$(this).prop('disabled',true);$.post(MBS_Admin.ajax_url,{action:'mbs_save_osm_settings',nonce:MBS_Admin.nonce,osm_enabled:$('#osm_enabled').is(':checked')?1:0,osm_sandbox_mode:$('#osm_sandbox_mode').is(':checked')?1:0,osm_auth_source:$('#osm_auth_source').val(),osm_client_id:$('#osm_client_id').val(),osm_client_secret:$('#osm_client_secret').val(),osm_section_id:$('#osm_section_id').val(),osm_bank_account_id:$('#osm_bank_account_id').val(),osm_venue_category_id:$('#osm_venue_category_id').val(),osm_venue_item_id:$('#osm_venue_item_id').val(),osm_clothing_category_id:$('#osm_clothing_category_id').val(),osm_clothing_item_id:$('#osm_clothing_item_id').val(),osm_fees_category_id:$('#osm_fees_category_id').val(),osm_fees_item_id:$('#osm_fees_item_id').val(),osm_match_days:$('#osm_match_days').val(),osm_description_template:$('#osm_description_template').val(),osm_product_mappings:$('#osm_product_mappings').val()},function(r){show(r.success,r.success?r.data.message:(r.data&&r.data.message?r.data.message:'Could not save settings.'));}).always(function(){$b.prop('disabled',false);});});
    $('#mbs-osm-test').on('click',function(){var $b=$(this).prop('disabled',true);$.post(MBS_Admin.ajax_url,{action:'mbs_test_osm_connection',nonce:MBS_Admin.nonce},function(r){show(r.success,r.success?r.data.message:(r.data&&r.data.message?r.data.message:'Connection failed.'));}).always(function(){$b.prop('disabled',false);});});
    $('.mbs-osm-discover').on('click',function(){var $b=$(this).prop('disabled',true);$.post(MBS_Admin.ajax_url,{action:'mbs_osm_discover',nonce:MBS_Admin.nonce,kind:$b.data('kind'),section_id:$('#osm_section_id').val()},function(r){if(r.success){$('#mbs-osm-discovery').show().find('pre').text(JSON.stringify(r.data,null,2));}else show(false,r.data&&r.data.message?r.data.message:'Discovery failed.');}).always(function(){$b.prop('disabled',false);});});
    $('#mbs-osm-sync').on('click',function(){var $b=$(this).prop('disabled',true);$.post(MBS_Admin.ajax_url,{action:'mbs_osm_sync_woopayments',nonce:MBS_Admin.nonce,payout_id:$('#osm_payout_id').val(),bank_transaction_id:$('#osm_bank_transaction_match').val()},function(r){show(r.success,r.success?'Payout sync completed.':(r.data&&r.data.message?r.data.message:'Payout sync failed.'),r.success?r.data:null);if(r.success)setTimeout(function(){location.reload();},1500);}).always(function(){$b.prop('disabled',false);});});
    $(document).on('click','.mbs-osm-retry',function(){var $b=$(this),bankId='';if($b.data('route')==='bank_match'){bankId=window.prompt('OSM bank transaction ID (leave blank to use an unambiguous reference match):','');if(bankId===null)return;}$b.prop('disabled',true);$.post(MBS_Admin.ajax_url,{action:'mbs_osm_retry_event',nonce:MBS_Admin.nonce,entity_type:$b.data('entity'),event_id:$b.data('id'),bank_transaction_id:bankId},function(r){show(r.success,r.success?'Reconciliation retried.':(r.data&&r.data.message?r.data.message:'Retry failed.'),r.success?r.data:null);if(r.success)setTimeout(function(){location.reload();},1200);}).always(function(){$b.prop('disabled',false);});});
    $(document).on('click','.mbs-osm-resolve',function(){var $b=$(this),note=window.prompt('What did you verify in WooPayments and OSM?');if(!note)return;$b.prop('disabled',true);$.post(MBS_Admin.ajax_url,{action:'mbs_osm_resolve_event',nonce:MBS_Admin.nonce,entity_type:$b.data('entity'),event_id:$b.data('id'),note:note},function(r){show(r.success,r.success?'Reconciliation resolved.':(r.data&&r.data.message?r.data.message:'Resolution failed.'));if(r.success)setTimeout(function(){location.reload();},1200);}).always(function(){$b.prop('disabled',false);});});
});
</script>
