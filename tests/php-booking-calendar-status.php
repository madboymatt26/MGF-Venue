<?php
// Isolated transport tests: no live Google, bookings, credentials or customer mail.
require __DIR__ . '/php-google-calendar-worker.php';
$calendar_admin = true;
function current_user_can( $cap ) { global $calendar_admin; return $calendar_admin; }
class WP_Error {
    private $message;
    public function __construct( $code, $message ) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function card_ready() {
    global $options;
    ready(); $options['mbs_google_calendar']['enabled'] = true;
    $options['mbs_google_calendar']['policy'] = 'protected-v1';
}
function card_reply( $data ) { return array( 'code' => 200, 'body' => json_encode( $data ) ); }
function card_calendar() { return card_reply( array( 'kind' => 'calendar#calendarList', 'items' => array( array( 'id' => 'target', 'accessRole' => 'owner' ) ) ) ); }
function card_events( $events = array() ) { return card_reply( array( 'kind' => 'calendar#events', 'items' => $events ) ); }
function card_preview( $events = array(), $ref = 'MBS-F1' ) {
    global $queue;
    $queue = array( card_calendar(), card_events( $events ) );
    return MBS_Google_Calendar::booking_sync( $ref );
}
card_ready();
$wpdb->rows[] = clone $wpdb->rows[0]; $wpdb->rows[1]->ref = 'MBS-F2';
$wpdb->rows[1]->booking_date = $wpdb->rows[1]->booking_date_end = '2026-10-26';
$view = card_preview();
check( $view['can_sync'] && count( $view['items'] ) === 1, 'Only selected booking is shown, not unrelated pending operations.' );
check( ! array_filter( $requests, function( $r ) { return $r[1]['method'] !== 'GET'; } ), 'Viewing booking never creates or updates Google.' );
$event = actual_ops()[0]['want']; $event['etag'] = '"saved"';
$event['htmlLink'] = 'https://www.google.com/calendar/event?eid=fixture';
$requests = array();
$queue = array( card_calendar(), card_events(), card_events(), card_reply( $event ), card_calendar(), card_events( array( $event ) ) );
$synced = MBS_Google_Calendar::booking_sync( 'MBS-F1', true, $view['fingerprint'], true );
check( ! ( $synced instanceof WP_Error ) && ! $synced['can_sync'] && $synced['items'][0]['state'] === 'matched', 'Explicit scoped sync returns fresh linked state.' );
$writes = array_values( array_filter( $requests, function( $r ) { return $r[1]['method'] !== 'GET'; } ) );
check( count( $writes ) === 1 && $writes[0][1]['method'] === 'POST', 'Exactly one booking creates one Google event.' );
check( count( $options['mbs_google_calendar']['links'] ) === 1 && $wpdb->rows[1]->ref === 'MBS-F2', 'Unrelated booking and its sync operation untouched.' );
check( $synced['events'][0]['url'] === $event['htmlLink'] && $synced['last_action'], 'Provider link and per-booking action exposed.' );
$before = count( $requests ); $queue = array( card_calendar(), card_events( array( $event ) ) );
$repeat = MBS_Google_Calendar::booking_sync( 'MBS-F1', true, $synced['fingerprint'], true );
check( $repeat instanceof WP_Error && count( $requests ) === $before + 2, 'Double click cannot recreate an already linked event.' );
card_ready(); $view = card_preview(); $wpdb->rows[0]->start_time = '18:00:00'; $requests = array();
$queue = array( card_calendar(), card_events() );
$stale = MBS_Google_Calendar::booking_sync( 'MBS-F1', true, $view['fingerprint'], true );
check( $stale instanceof WP_Error && count( $requests ) === 2, 'Changed source rejects stale fingerprint before writes.' );
card_ready(); $options['mbs_google_calendar']['enabled'] = false; $view = card_preview();
check( ! $view['can_sync'] && ! $view['enabled'], 'Paused connection never exposes sync action.' );
$queue = array( card_calendar(), card_events() );
check( MBS_Google_Calendar::booking_sync( 'MBS-F1', true, $view['fingerprint'], true ) instanceof WP_Error && ! $options['mbs_google_calendar']['enabled'], 'Scoped action cannot enable paused sync.' );
card_ready(); $overlap = actual_ops()[0]['want']; $overlap['id'] = 'estate'; unset( $overlap['extendedProperties'] );
$view = card_preview( array( $overlap ) );
check( ! $view['can_sync'] && $view['items'][0]['state'] === 'review' && ! $view['events'], 'Unresolved overlap is review-only, not a falsely linked event.' );
$op = actual_ops( array( $overlap ) )[0]; $one = $overlap; $two = $overlap; $two['id'] = 'estate-two';
$op['action'] = 'matched'; $op['event'] = $one; $op['linked_events'] = array( $one, $two );
$view = invoke( 'booking_view', 'MBS-F1', array( 'ops' => array( $op ) ) );
check( count( $view['events'] ) === 2 && ! $view['can_sync'], 'Multiple protected area events displayed once each, never mutated.' );
foreach ( array( 'javascript:alert(1)', 'https://evil.test/calendar/event', 'https://www.google.com.evil.test/calendar/event', 'https://www.google.com/url?q=evil', 'https://user@www.google.com/calendar/event' ) as $url ) check( MBS_Google_Calendar::event_url( array( 'htmlLink' => $url ) ) === '', 'Untrusted event URL rejected.' );
card_ready(); $calendar_admin = false; $requests = array();
check( MBS_Google_Calendar::booking_sync( 'MBS-F1' ) instanceof WP_Error && ! $requests, 'Nonadministrator denied before any Google access.' );
$calendar_admin = true; unset( $options['mbs_google_calendar']['refresh'] ); $requests = array();
check( ! MBS_Google_Calendar::booking_sync( 'MBS-F1' )['can_sync'] && ! $requests, 'Disconnected status is read-only and does not contact Google.' );
check( MBS_Google_Calendar::booking_sync( 'MBS-NOT-FOUND' ) instanceof WP_Error, 'Missing booking cannot be synced.' );
check( MBS_Google_Calendar::reviewed_sync( array( 'operation' => 'booking_preview', 'ref' => 'MBS-F1', 'choices' => array() ) ) instanceof WP_Error, 'MCP booking action cannot smuggle global matching choices.' );
check( ! isset( $options['mbs_gcal_lock'] ), 'Lease released after successful and failed actions.' );
card_ready(); $native_op = actual_ops()[0]; invoke( 'execute', array( $native_op ) );
$event = $native_op['want']; $event['etag'] = '"created"';
$options['mbs_google_calendar']['managed_updates'] = true;
$wpdb->rows[0]->start_time = '18:00:00'; $view = card_preview( array( $event ) );
check( $view['can_sync'] && $view['items'][0]['state'] === 'managed_update', 'Safe owned changes are available through the booking card.' );
$updated = $event; $updated['etag'] = '"updated"';
foreach ( array( 'summary', 'start', 'end', 'status', 'transparency', 'extendedProperties' ) as $field ) $updated[$field] = actual_ops( array( $event ) )[0]['want'][$field];
$requests = array(); $queue = array( card_calendar(), card_events( array( $event ) ), card_reply( $event ), card_events(), card_reply( $updated ), card_calendar(), card_events( array( $updated ) ) );
$result = MBS_Google_Calendar::booking_sync( 'MBS-F1', true, $view['fingerprint'], true );
$writes = array_values( array_filter( $requests, function( $r ) { return $r[1]['method'] !== 'GET'; } ) );
check( ! ( $result instanceof WP_Error ) && count( $writes ) === 1 && $writes[0][1]['method'] === 'PATCH', 'Scoped updates reuse the guarded lifecycle, not a duplicate insertion.' );
check( $writes[0][1]['headers']['If-Match'] === '"created"', 'Booking card preserves event-version protection.' );
define( 'MBS_PLUGIN_DIR', dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/' );
function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $value ) { return esc_attr( $value ); }
function wp_create_nonce( $action ) { return 'fixture-nonce'; }
function card_html() { ob_start(); MBS_Google_Calendar::render_booking_card( (object) array( 'ref' => 'MBS-F1' ) ); return ob_get_clean(); }
$options['mbs_google_calendar'] = array();
check( card_html() === '', 'Unconfigured sites such as Needham have no Google panel or automatic checks.' );
$options['mbs_google_calendar']['client_id'] = 'fixture.apps.googleusercontent.com';
$html = card_html();
check( strpos( $html, 'Google Calendar linkage' ) !== false && strpos( $html, 'Sync now</button>' ) !== false && strpos( $html, 'disabled' ) !== false, 'Configured site renders an accessible card with sync initially disabled.' );
$calendar_admin = false;
check( card_html() === '', 'Booking panel not exposed to nonadministrators.' );
$calendar_admin = true;
$service = file_get_contents( dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-google-calendar.php' );
check( strpos( $service, "check_ajax_referer( 'mbs_gcal_booking', 'nonce' )" ) !== false, 'Cookie action has dedicated CSRF nonce.' );
echo "Booking calendar status: $assertions total assertions passed.\n";
