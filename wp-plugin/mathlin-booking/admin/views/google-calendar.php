<?php
if ( ! defined( 'ABSPATH' ) ) exit;
require_once MBS_PLUGIN_DIR . 'includes/class-calendar-sync-view.php';
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
};
$print_groups = static function( $events ) use ( $print_event ) {
    foreach ( MBS_Calendar_Sync_View::event_groups( $events ) as $group ) {
        echo '<div class="sync-event-group">'; $print_event( $group['event'] );
        $count = count( $group['records'] );
        echo '<p><span class="sync-badge">' . (int) $count . ' Google record' . ( $count === 1 ? '' : 's with the same visible details' ) . '</span></p>';
        echo '<details><summary>Inspect ' . (int) $count . ' individual Google record' . ( $count === 1 ? '' : 's' ) . '</summary><ul class="sync-records">';
        foreach ( $group['records'] as $i => $record ) {
            echo '<li><strong>Record ' . (int) ( $i + 1 ) . '</strong> · <code>' . esc_html( $record['id'] ?? 'ID unavailable' ) . '</code>';
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
<div class="sync-safety"><strong>Protected estate calendar — existing Google entries are never edited or deleted.</strong><br>Matches are references only. MGF can add missing bookings when enabled; later changes and cancellations require estate review. Google edits never change MGF bookings.</div>
<div class="sync-stats">
<?php foreach ( array( array( $status['enabled'] ? 'On' : 'Paused', 'Add-only sync' ), array( count( $review ), 'Need review / adding' ), array( count( $linked ), 'Linked entries' ), array( count( $estate ), 'Estate-only / not linked' ) ) as $stat ) : ?><div class="sync-stat"><b><?php echo esc_html( $stat[0] ); ?></b><?php echo esc_html( $stat[1] ); ?></div><?php endforeach; ?>
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
<p>Checks run every five minutes when WordPress cron runs. Use server cron for reliable timing. No contact details, notes, purpose, attendees or invitations are exported.</p></div>
<?php elseif ( $tab === 'history' ) : ?>
<table class="widefat striped"><thead><tr><th>UTC time</th><th>Action</th><th>Booking</th><th>Result</th></tr></thead><tbody><?php foreach ( $display as $row ) : ?><tr><?php foreach ( array( 'at', 'action', 'ref', 'result' ) as $field ) : ?><td><?php echo esc_html( $row[$field] ); ?></td><?php endforeach; ?></tr><?php endforeach; ?><?php if ( ! $items ) : ?><tr><td colspan="4">No sync actions recorded. Read-only comparisons do not change Google.</td></tr><?php endif; ?></tbody></table>
<?php elseif ( ! $preview ) : ?><p>No current comparison. Refresh above, or connect a calendar in Connection settings.</p>
<?php elseif ( $tab === 'estate' ) : ?>
<p>Google entries without a saved MGF link. Identical-looking records are grouped for display only. Nothing in this list will be removed or rewritten.</p>
<?php foreach ( $display as $group ) $print_groups( $group['records'] ); ?>
<?php if ( ! $items ) : ?><p>No unlinked estate entries in this comparison window.</p><?php endif; ?>
<?php else : ?>
<p><?php echo $tab === 'linked' ? 'Protected references. Differences appear under Needs review; linked entries are never overwritten.' : 'Bookings sharing the same Google candidate records are shown together. This is a display group, not a confirmed event match. Saving individual references makes no Google edits and leaves sync paused.'; ?></p>
<?php if ( $tab === 'review' ) : ?><p class="sync-safety">Repeated-looking Google records are not interchangeable. Inspect their IDs and Google details before choosing an individual reference. If identity is uncertain, leave the group unresolved. Group-wide or many-to-many linking is not performed here.</p><?php endif; ?>
<?php if ( $tab === 'review' ) self::form_start( 'reconcile' ); ?>
<div class="sync-table"><table class="widefat striped"><thead><tr><th>MGF reservations</th><th>Google candidate groups</th><th>Review / individual references</th></tr></thead><tbody>
<?php foreach ( $display as $booking_group ) : $first = $booking_group['items'][0]; $group_count = count( $booking_group['items'] ); ?><tr>
<td>
<?php if ( $group_count > 1 ) : ?><strong><?php echo (int) $group_count; ?> MGF reservations share these candidates</strong><p><?php echo esc_html( $date_label( $first['want'] ) ); ?><br>First reservation shown; expand to compare all dates and areas.</p><p>They are not automatically treated as one event.</p><details><summary>Show all <?php echo (int) $group_count; ?> reservations and dates</summary><?php endif; ?>
<ul class="sync-bookings"><?php foreach ( $booking_group['items'] as $item ) : ?><li><a href="<?php echo esc_url( admin_url( 'admin.php?page=mathlin-booking&s=' . rawurlencode( $item['ref'] ) ) ); ?>"><?php echo esc_html( $item['ref'] ); ?></a><br><?php $print_event( $item['want'] ); ?></li><?php endforeach; ?></ul>
<?php if ( $group_count > 1 ) : ?></details><?php endif; ?>
</td>
<td><?php if ( $booking_group['candidates'] ) { $print_groups( $booking_group['candidates'] ); } else { $print_event( $first['event'] ); } ?></td>
<td><span class="sync-badge"><?php echo $group_count > 1 ? 'Shared candidates — review required' : esc_html( ucfirst( $first['action'] ) ); ?></span>
<?php if ( $first['action'] === 'review' ) : ?><p>Not linked yet. An overlap is not proof of identity. Compare names, areas and dates; keep any differences visible.</p><?php else : ?><p><?php echo esc_html( $first['reason'] ); ?></p><?php endif; ?>
<?php if ( $group_count > 1 ) : ?><details><summary>Review individual links (advanced)</summary><p>No group-wide link will be saved. Leave unresolved unless a specific record is justified.</p><?php endif; ?>
<?php foreach ( $booking_group['items'] as $item ) : ?><div class="sync-choice">
<?php if ( $item['action'] === 'review' ) : ?><label><?php echo esc_html( $item['ref'] ); ?> — individual reference<select aria-label="<?php echo esc_attr( $item['ref'] . ' individual reference' ); ?>" name="choice[<?php echo esc_attr( $item['hash'] ); ?>]"><option value="">Leave unresolved — preserve Google</option>
<?php foreach ( MBS_Calendar_Sync_View::event_groups( $item['candidates'] ) as $option_group ) : ?><optgroup label="<?php echo esc_attr( ( $option_group['event']['summary'] ?? '(untitled)' ) . ' · ' . $date_label( $option_group['event'] ) . ' · ' . ( $option_group['event']['location'] ?? 'No location' ) . ' · ' . count( $option_group['records'] ) . ' record(s)' ); ?>">
<?php foreach ( $option_group['records'] as $i => $candidate ) : ?><option value="<?php echo esc_attr( 'protect:' . $candidate['id'] ); ?>"><?php echo esc_html( 'Record ' . ( $i + 1 ) . ' · ' . MBS_Calendar_Sync_View::record_label( $candidate ) . ' · ' . ( $candidate['summary'] ?? '(untitled)' ) ); ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></label>
<?php elseif ( $item['action'] === 'difference' && $item['event'] && $item['want'] ) : ?><label><input type="checkbox" name="choice[<?php echo esc_attr( $item['hash'] ); ?>]" value="<?php echo esc_attr( 'protect:' . $item['event']['id'] ); ?>"> Acknowledge difference; keep Google exactly as it is</label>
<?php elseif ( $item['action'] === 'create' ) : ?>Missing in Google. A new entry can be added only when sync is separately enabled.<?php else : ?>No Google changes.<?php endif; ?></div><?php endforeach; ?>
<?php if ( $group_count > 1 ) : ?></details><?php endif; ?></td></tr><?php endforeach; ?>
<?php if ( ! $items ) : ?><tr><td colspan="3">No items in this view. Estate entries remain protected.</td></tr><?php endif; ?></tbody></table></div>
<?php if ( $tab === 'review' ) : ?><p><label><input type="checkbox" name="confirm" value="1" required> I reviewed this page. Save references only; leave Google unchanged and sync paused.</label></p><button class="button button-primary">Save reviewed matches — no Google edits</button></form>
<?php self::form_start( 'apply' ); ?><p><label><input type="checkbox" name="confirm" value="1" required> Enable protected add-only sync. Add missing bookings; unresolved overlaps stay blocked. Existing Google entries, changes and cancellations are never overwritten or deleted.</label></p><button class="button">Enable protected add-only sync</button></form><?php endif; ?>
<?php endif; ?>
<?php if ( $tab !== 'settings' ) : ?><nav class="sync-pager" aria-label="Sync result pages"><?php if ( $pages > 1 ) for ( $i = 1; $i <= $pages; $i++ ) : ?><a class="button <?php echo $i === $page_number ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'mathlin-google-calendar', 'sync_view' => $tab, 'sync_paged' => $i ), admin_url( 'admin.php' ) ) ); ?>"><?php echo $i; ?></a><?php endfor; ?><span><?php echo count( $items ); ?> <?php echo $tab === 'history' ? 'history items' : 'display groups'; ?> · 25 per page. Original booking and Google record totals are shown above.</span></nav><?php endif; ?>
</div>
