<?php
if ( ! defined( 'ABSPATH' ) ) exit;
require_once MBS_PLUGIN_DIR . 'includes/class-calendar-sync-view.php';
require_once MBS_PLUGIN_DIR . 'includes/class-calendar-reference-links.php';
$tabs = array( 'review' => 'Needs review', 'linked' => 'Linked entries', 'estate' => 'Estate-only entries', 'history' => 'History', 'settings' => 'Connection settings' );
$tab = sanitize_key( $_GET['sync_view'] ?? 'review' ); if ( ! isset( $tabs[$tab] ) ) $tab = 'review';
$snapshot = $preview['snapshot'] ?? array( 'ops' => array(), 'events' => array() );
$linked = array_values( array_filter( $snapshot['ops'], static function( $r ) { return $r['action'] === 'matched'; } ) );
$review = array_values( array_filter( $snapshot['ops'], static function( $r ) { return $r['action'] !== 'matched'; } ) );
$estate = self::estate_only( $snapshot );
$items = $tab === 'linked' ? MBS_Calendar_Sync_View::booking_groups( $linked ) : ( $tab === 'estate' ? MBS_Calendar_Sync_View::event_groups( $estate ) : ( $tab === 'history' ? array_reverse( self::history() ) : MBS_Calendar_Sync_View::booking_groups( $review ) ) );
$pages = max( 1, (int) ceil( count( $items ) / 25 ) ); $page_number = min( max( 1, absint( $_GET['sync_paged'] ?? 1 ) ), $pages );
$display = array_slice( $items, ( $page_number - 1 ) * 25, 25 );
$date_label = static function( $e ) {
    if ( ! $e ) return 'Not linked';
    try {
        if ( ! empty( $e['start']['date'] ) ) {
            $a = new DateTimeImmutable( $e['start']['date'], wp_timezone() ); $z = ( new DateTimeImmutable( $e['end']['date'], wp_timezone() ) )->modify( '-1 day' );
            return $a->format( 'j M Y' ) . ( $z > $a ? ' – ' . $z->format( 'j M Y' ) : '' ) . ' · All day';
        }
        $a = ( new DateTimeImmutable( $e['start']['dateTime'] ) )->setTimezone( wp_timezone() ); $z = ( new DateTimeImmutable( $e['end']['dateTime'] ) )->setTimezone( wp_timezone() );
        return $a->format( 'j M Y H:i' ) . ' – ' . $z->format( 'j M Y H:i' );
    } catch ( Throwable $error ) { return 'Date unavailable — review required'; }
};
$print_event = static function( $e ) use ( $date_label ) {
    if ( ! $e ) { echo '<span class="description">Not linked yet</span>'; return; }
    echo '<strong>' . esc_html( $e['summary'] ?? '(untitled)' ) . '</strong><br>' . esc_html( $date_label( $e ) );
    if ( ! empty( $e['location'] ) ) echo '<br><span class="description">' . esc_html( $e['location'] ) . '</span>';
    if ( isset( $e['colorId'] ) ) echo '<br><span class="sync-badge">Colour: ' . esc_html( MBS_Google_Calendar::colour_palette()[$e['colorId']] ?? 'Calendar default' ) . '</span>';
};
$print_groups = static function( $events ) use ( $print_event ) {
    foreach ( MBS_Calendar_Sync_View::event_groups( $events ) as $group ) {
        echo '<div class="sync-event-group">'; $print_event( $group['event'] );
        $count = count( $group['records'] );
        echo '<p><span class="sync-badge">' . (int) $count . ' Google record' . ( $count === 1 ? '' : 's with the same visible details' ) . '</span></p>';
        echo '<details><summary>Inspect ' . (int) $count . ' individual Google record' . ( $count === 1 ? '' : 's' ) . '</summary><ul class="sync-records">';
        foreach ( $group['records'] as $i => $record ) {
            echo '<li><strong>Record ' . (int) ( $i + 1 ) . '</strong> · <code>' . esc_html( $record['id'] ?? 'ID unavailable' ) . '</code>';
            $original = MBS_Calendar_Reference_Links::event_legacy_id( $record );
            if ( $original ) echo '<br>Original booking: <strong>' . esc_html( $original ) . '</strong>';
            $url = MBS_Calendar_Sync_View::google_url( $record );
            if ( $url ) echo ' · <a target="_blank" rel="noopener noreferrer" href="' . esc_url( $url ) . '">Open in Google</a>';
            echo '</li>';
        }
        echo '</ul></details></div>';
    }
};
?>
<div class="wrap mbs-admin mbs-calendar-sync">
<style>
.mbs-calendar-sync .sync-toolbar,.mbs-calendar-sync .sync-stats{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:18px 0}.mbs-calendar-sync .sync-stat{background:white;border:1px solid #ddd;border-radius:8px;padding:16px;min-width:135px}.mbs-calendar-sync .sync-stat b{display:block;font-size:24px;color:#6512b7}.mbs-calendar-sync .sync-table{overflow-x:auto}.mbs-calendar-sync td{vertical-align:top;min-width:130px}.mbs-calendar-sync select{max-width:320px;width:100%}.mbs-calendar-sync .sync-settings{max-width:850px}.mbs-calendar-sync .sync-settings section{background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin:16px 0}.mbs-calendar-sync .sync-badge{display:inline-block;background:#eee8f7;padding:4px 8px;border-radius:4px}.mbs-calendar-sync .sync-pager{margin:16px 0;display:flex;gap:12px;align-items:center}.mbs-calendar-sync .sync-safety{border-left:4px solid #47833c;background:#f1f8ef;padding:12px 16px}.mbs-calendar-sync .nav-tab-wrapper{margin-bottom:18px}
.mbs-calendar-sync .sync-event-group{padding:12px;border:1px solid #dcdcde;border-radius:6px;background:#fff;margin-bottom:10px}.mbs-calendar-sync .sync-records code{overflow-wrap:anywhere}.mbs-calendar-sync details{margin:10px 0}.mbs-calendar-sync summary{cursor:pointer;font-weight:600}.mbs-calendar-sync .sync-choice{border-bottom:1px solid #ddd;padding:12px 0}.mbs-calendar-sync .sync-bookings{padding-left:18px}.mbs-calendar-sync .sync-bookings li{margin-bottom:12px}.mbs-calendar-sync .sync-table table{table-layout:fixed;min-width:760px}.mbs-calendar-sync .sync-table td{overflow-wrap:anywhere}.mbs-calendar-sync optgroup{font-weight:600}
</style>
<h1><?php echo MBS_Admin::brand_mark(); ?>MGF Venue — Calendar Sync</h1>
<?php foreach ( array( array( $notice, 'info' ), array( $problem, 'error' ) ) as $n ) if ( $n[0] ) : ?><div class="notice notice-<?php echo esc_attr( $n[1] ); ?>"><p><?php echo esc_html( $n[0] ); ?></p></div><?php endif; ?>
<div class="sync-safety"><strong>Protected estate calendar — estate-created Google entries are never edited or deleted.</strong><br>Estate matches are references only. <?php echo ! empty( $status['managed_updates'] ) ? 'MGF-created events follow booking changes; cancellations remain labelled and non-blocking. Independent Google edits stop automatic updates for review.' : 'MGF adds missing bookings only. Enable MGF-created lifecycle updates in Connection settings to sync later changes and cancellations.'; ?> Google edits never change MGF bookings.</div>
<div class="sync-stats">
<?php foreach ( array( array( $status['enabled'] ? 'On' : 'Paused', ! empty( $status['managed_updates'] ) ? 'Managed MGF sync' : 'Add-only sync' ), array( count( $review ), 'Need review / adding' ), array( count( $linked ), 'Linked entries' ), array( count( $estate ), 'Estate-only / not linked' ) ) as $stat ) : ?><div class="sync-stat"><b><?php echo esc_html( $stat[0] ); ?></b><?php echo esc_html( $stat[1] ); ?></div><?php endforeach; ?>
</div>
<p><strong>Calendar:</strong> <?php echo esc_html( $status['calendar'] ?: 'Not selected' ); ?> · <strong>Connection:</strong> <?php echo $status['connected'] ? 'Connected' : 'Not connected'; ?><br><strong>Last result:</strong> <?php echo esc_html( $status['last_result'] ); ?><br><strong>Last successful run:</strong> <?php echo esc_html( $status['last_success'] ?: 'Not yet run' ); ?></p>
<div class="sync-toolbar">
<?php if ( $status['connected'] && $status['calendar_id'] ) : ?><?php self::form_start( 'preview' ); ?><button class="button button-primary">Refresh read-only comparison</button></form><?php endif; ?>
<?php if ( $status['connected'] ) : ?><?php self::form_start( 'pause' ); ?><button class="button">Pause sync</button></form><?php endif; ?>
<span class="description">Comparison lasts 15 minutes. Dates use <?php echo esc_html( wp_timezone()->getName() ); ?>. Full-day end dates are inclusive.</span>
</div>
<nav class="nav-tab-wrapper" aria-label="Calendar sync views"><?php foreach ( $tabs as $key => $label ) : ?><a class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'mathlin-google-calendar', 'sync_view' => $key ), admin_url( 'admin.php' ) ) ); ?>" <?php if ( $key === $tab ) echo 'aria-current="page"'; ?>><?php echo esc_html( $label ); ?></a><?php endforeach; ?></nav>
<?php if ( $tab === 'settings' ) : ?>
<div class="sync-settings"><section><h2>Google connection</h2>
<p>Use an account with edit permission on the existing calendar. No replacement calendar is created. Tokens are encrypted with WordPress salts. Calendar API must be enabled and OAuth configured for ongoing offline use. Changing WordPress salts requires reconnecting.</p>
<?php if ( ! $status['secure_storage_ready'] ) : ?><p>Secure token storage unavailable. Configure OpenSSL AES-256-GCM before connecting.</p><?php endif; ?>
<details <?php if ( ! $status['configured'] ) echo 'open'; ?>><summary>OAuth client settings</summary><p>Create a Web application client with this redirect URI:</p><p><code><?php echo esc_html( $status['redirect_uri'] ); ?></code></p>
<?php self::form_start( 'credentials' ); ?><p><label>Client ID<br><input class="large-text" name="client_id" value="<?php echo esc_attr( $s['client_id'] ?? '' ); ?>" required autocomplete="off"></label></p><p><label>Client secret<br><input type="password" name="client_secret" value="" autocomplete="new-password"></label><br>Leave blank to retain the saved secret. Saving disconnects and pauses sync.</p><button class="button">Save secure connection settings</button></form></details>
<?php if ( $status['configured'] ) : ?><?php self::form_start( 'connect' ); ?><p><button class="button">Connect / reconnect Google</button></p></form><?php endif; ?>
<?php if ( $status['connected'] ) : ?><?php self::form_start( 'disconnect' ); ?><p><button class="button">Disconnect Google — keep all events</button></p></form><?php endif; ?>
<?php foreach ( array( 'haughley-park-calendar-privacy' => 'Calendar connection privacy notice', 'haughley-park-calendar-connection' => 'About this connection' ) as $path => $label ) : $p = get_page_by_path( $path ); if ( $p && $p->post_status === 'publish' ) : ?><p><a href="<?php echo esc_url( get_permalink( $p ) ); ?>"><?php echo esc_html( $label ); ?></a></p><?php endif; endforeach; ?>
</section><?php if ( $status['connected'] && ! $problem ) : ?><section><h2>Target calendar</h2><?php self::form_start( 'target' ); ?><p><label>Existing shared calendar<br><select name="calendar" required><option value="">Select a calendar</option><?php foreach ( $calendars as $c ) : ?><option value="<?php echo esc_attr( $c['id'] ); ?>" <?php selected( $s['calendar'] ?? '', $c['id'] ); ?>><?php echo esc_html( ( $c['summary'] ?? $c['id'] ) . ' (' . $c['accessRole'] . ')' ); ?></option><?php endforeach; ?></select></label></p><p><label><input type="checkbox" name="pending" value="1" <?php checked( ! empty( $s['pending'] ) ); ?>> Include pending bookings as Provisional</label></p><button class="button">Save target and pause for review</button></form></section><?php endif; ?>
<section><h2>Changes and cancellations</h2>
<?php self::form_start( 'lifecycle' ); ?><p><label><input type="checkbox" name="managed_updates" value="1" <?php checked( ! empty( $s['managed_updates'] ) ); ?>> Automatically update events created by MGF when bookings change or are cancelled</label></p>
<p>Cancellation keeps a labelled, non-blocking history entry. Reapproval restores it. Existing estate references, shared legacy events and unrelated entries remain untouched. Manually edited Google events, guests, recurring Google events and uncertain ownership stop for review. Saving pauses sync for a fresh comparison.</p><button class="button">Save lifecycle policy and pause</button></form></section>
<section><h2>Colours for new events</h2>
<p>Applies only when MGF adds a new event. Existing Google entries keep their current colours, including manually linked entries. Saving pauses sync and requires a fresh comparison.</p>
<?php $colour_rules = self::colour_settings( $s ); self::form_start( 'colours' ); ?>
<p><label><input type="checkbox" name="colours[enabled]" value="1" <?php checked( $colour_rules['enabled'] ); ?>> Use booking-type colours for new Google events</label></p>
<?php foreach ( array( 'estate' => 'Estate bookings', 'scout' => 'Scout bookings' ) as $kind => $label ) : ?><p><label><?php echo esc_html( $label ); ?><br><select name="colours[<?php echo esc_attr( $kind ); ?>]"><?php foreach ( self::colour_palette() as $id => $name ) : ?><option value="<?php echo esc_attr( $id ); ?>" <?php selected( $colour_rules[$kind], (string) $id ); ?>><?php echo esc_html( $name ); ?></option><?php endforeach; ?></select></label></p><?php endforeach; ?>
<p><label>Estate organisation labels — one exact label per line<br><textarea name="colours[estate_groups]" rows="3" class="large-text"><?php echo esc_html( implode( "\n", $colour_rules['estate_groups'] ) ); ?></textarea></label></p>
<p class="description">In campsite mode, staff estate entries and these exact organisation labels use the estate colour (case-insensitive). Other campsite bookings use the Scout colour; internal Scout-use bookings also use it. Ordinary paid venue hires retain the calendar default. This changes no booking type, price or access permission.</p>
<button class="button">Save new-event colours and pause</button></form></section>
<p>Checks run every five minutes when WordPress cron runs. Use server cron for reliable timing. No contact details, notes, purpose, attendees or invitations are exported.</p></div>
<?php elseif ( $tab === 'history' ) : ?>
<table class="widefat striped"><thead><tr><th>UTC time</th><th>Action</th><th>Booking</th><th>Result</th></tr></thead><tbody><?php foreach ( $display as $row ) : ?><tr><?php foreach ( array( 'at', 'action', 'ref', 'result' ) as $field ) : ?><td><?php echo esc_html( $row[$field] ); ?></td><?php endforeach; ?></tr><?php endforeach; ?><?php if ( ! $items ) : ?><tr><td colspan="4">No sync actions recorded. Read-only comparisons do not change Google.</td></tr><?php endif; ?></tbody></table>
<?php elseif ( ! $preview ) : ?><p>No current comparison. Refresh above, or connect a calendar in Connection settings.</p>
<?php elseif ( $tab === 'estate' ) : ?>
<p>Google entries without a saved MGF link. Identical-looking records are grouped for display only. Nothing in this list will be removed or rewritten.</p>
<?php foreach ( $display as $group ) $print_groups( $group['records'] ); ?>
<?php if ( ! $items ) : ?><p>No unlinked estate entries in this comparison window.</p><?php endif; ?>
<?php else : ?>
<p><?php echo $tab === 'linked' ? 'Protected references. One MGF reservation may link to several original Google area events. Differences appear under Needs review; all linked events remain unchanged.' : 'Select all Google area records belonging to each MGF reservation. Original booking references distinguish repeated-looking records. Saving references makes no Google edits and leaves sync paused.'; ?></p>
<?php if ( $tab === 'review' ) : ?><p class="sync-safety">Recommendations require unique original references and matching dates/times for every imported area reservation. Differences and uncertain matches stay unresolved. Imported bookings are not automatically added again.</p><?php endif; ?>
<?php if ( $tab === 'review' ) self::form_start( 'reconcile' ); ?>
<div class="sync-table"><table class="widefat striped"><thead><tr><th>MGF reservations</th><th>Google candidate groups</th><th>Review / individual references</th></tr></thead><tbody>
<?php foreach ( $display as $booking_group ) : $first = $booking_group['items'][0]; $group_count = count( $booking_group['items'] ); ?><tr>
<td>
<?php if ( $group_count > 1 ) : ?><strong><?php echo (int) $group_count; ?> MGF reservations share these candidates</strong><p><?php echo esc_html( $date_label( $first['want'] ) ); ?><br>First reservation shown; expand to compare all dates and areas.</p><p>They are not automatically treated as one event.</p><details><summary>Show all <?php echo (int) $group_count; ?> reservations and dates</summary><?php endif; ?>
<ul class="sync-bookings"><?php foreach ( $booking_group['items'] as $item ) : ?><li><a href="<?php echo esc_url( admin_url( 'admin.php?page=mathlin-booking&s=' . rawurlencode( $item['ref'] ) ) ); ?>"><?php echo esc_html( $item['ref'] ); ?></a><br><?php $print_event( $item['want'] ); ?></li><?php endforeach; ?></ul>
<?php if ( $group_count > 1 ) : ?></details><?php endif; ?>
</td>
<td><?php if ( $booking_group['candidates'] ) { $print_groups( $booking_group['candidates'] ); } elseif ( ! empty( $first['linked_events'] ) ) { $print_groups( $first['linked_events'] ); } else { $print_event( $first['event'] ); } ?></td>
<td><span class="sync-badge"><?php echo $group_count > 1 ? 'Shared candidates — review required' : esc_html( ucfirst( $first['action'] ) ); ?></span>
<?php if ( $first['action'] === 'review' ) : ?><p>Not linked yet. An overlap is not proof of identity. Compare names, areas and dates; keep any differences visible.</p><?php else : ?><p><?php echo esc_html( $first['reason'] ); ?></p><?php endif; ?>
<?php if ( $group_count > 1 ) : ?><details><summary>Review links for each reservation</summary><p>Select each reservation's original area records, not every overlapping event.</p><?php endif; ?>
<?php foreach ( $booking_group['items'] as $item ) : ?><div class="sync-choice">
<?php if ( $item['action'] === 'review' || ( $item['action'] === 'difference' && $item['want'] ) ) : ?>
<strong><?php echo esc_html( $item['ref'] ); ?> — Google area links</strong>
<p><?php echo esc_html( $item['recommendation']['reason'] ?? 'Review changes to every linked Google record; Google remains unchanged.' ); ?></p>
<?php if ( ! empty( $item['original_sources'] ) ) : ?><details><summary>Original area booking evidence (<?php echo count( $item['original_sources'] ); ?>)</summary><ul><?php foreach ( $item['original_sources'] as $original_source ) : ?><li><?php echo esc_html( $original_source->ref . ' · ' . $original_source->space . ' · ' . $original_source->booking_date . ' – ' . ( $original_source->booking_date_end ?: $original_source->booking_date ) . ( $original_source->all_day ? ' · All day' : ' · ' . $original_source->start_time . ' – ' . $original_source->end_time ) ); ?></li><?php endforeach; ?></ul></details><?php endif; ?>
<?php $options = $item['action'] === 'difference' ? ( $item['linked_events'] ?? array_filter( array( $item['event'] ) ) ) : $item['candidates']; foreach ( $options as $candidate ) : $original = MBS_Calendar_Reference_Links::event_legacy_id( $candidate ); ?>
<p><label><input type="checkbox" name="choice[<?php echo esc_attr( $item['hash'] ); ?>][]" value="<?php echo esc_attr( 'protect:' . $candidate['id'] ); ?>">
<?php echo esc_html( ( $original ? 'Original #' . $original . ' · ' : '' ) . MBS_Calendar_Sync_View::record_label( $candidate ) . ' · ' . ( $candidate['summary'] ?? '(untitled)' ) . ' · ' . $date_label( $candidate ) ); ?>
<?php if ( in_array( $candidate['id'], $item['recommendation']['ids'] ?? array(), true ) ) : ?><span class="sync-badge">Recommended</span><?php endif; ?></label></p>
<?php endforeach; ?><p class="description">Nothing selected = leave unresolved. Select all corresponding area records; every Google event is preserved.</p>
<?php elseif ( $item['action'] === 'create' ) : ?>Missing in Google. A new entry can be added only when sync is separately enabled.<?php else : ?>No Google changes.<?php endif; ?></div><?php endforeach; ?>
<?php if ( $group_count > 1 ) : ?></details><?php endif; ?></td></tr><?php endforeach; ?>
<?php if ( ! $items ) : ?><tr><td colspan="3">No items in this view. Estate entries remain protected.</td></tr><?php endif; ?></tbody></table></div>
<?php if ( $tab === 'review' ) : ?><p><label><input type="checkbox" name="confirm" value="1" required> I reviewed this page. Save references only; leave Google unchanged and sync paused.</label></p><button class="button button-primary">Save reviewed matches — no Google edits</button></form>
<?php self::form_start( 'apply' ); ?><p><label><input type="checkbox" name="confirm" value="1" required> Enable protected sync with the selected lifecycle policy. Add missing bookings; unresolved overlaps stay blocked. Estate-created entries remain untouched; no history is deleted.</label></p><button class="button">Enable protected sync</button></form><?php endif; ?>
<?php endif; ?>
<?php if ( $tab !== 'settings' ) : ?><nav class="sync-pager" aria-label="Sync result pages"><?php if ( $pages > 1 ) for ( $i = 1; $i <= $pages; $i++ ) : ?><a class="button <?php echo $i === $page_number ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'mathlin-google-calendar', 'sync_view' => $tab, 'sync_paged' => $i ), admin_url( 'admin.php' ) ) ); ?>"><?php echo $i; ?></a><?php endfor; ?><span><?php echo count( $items ); ?> <?php echo $tab === 'history' ? 'history items' : 'display groups'; ?> · 25 per page. Original booking and Google record totals are shown above.</span></nav><?php endif; ?>
</div>
