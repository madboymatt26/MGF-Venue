<?php
require __DIR__ . '/php-google-calendar-worker.php';
function current_user_can( $cap ) { return true; }
class WP_Error { public $message; public function __construct( $code, $message ) { $this->message = $message; } }
function ref_state_ready( $enabled, $policy = 'protected-v1' ) {
    global $options;
    ready(); $options['mbs_google_calendar']['enabled'] = $enabled;
    $options['mbs_google_calendar']['policy'] = $policy;
    $options['mbs_google_calendar']['managed_updates'] = true;
}
function ref_state_queue() {
    global $queue;
    $queue = array(
        array( 'code' => 200, 'body' => '{"kind":"calendar#calendarList","items":[{"id":"target","accessRole":"owner"}]}' ),
        array( 'code' => 200, 'body' => '{"kind":"calendar#events","items":[]}' ),
    );
}
foreach ( array( true, false ) as $enabled ) {
    ref_state_ready( $enabled ); ref_state_queue();
    $preview = MBS_Google_Calendar::reviewed_sync( array( 'operation' => 'preview' ) );
    check( ! ( $preview instanceof WP_Error ), 'Read-only comparison succeeds.' );
    ref_state_queue(); $requests = array();
    $saved = MBS_Google_Calendar::reviewed_sync( array( 'operation' => 'reconcile', 'fingerprint' => $preview['fingerprint'], 'confirm' => true, 'choices' => array() ) );
    check( ! ( $saved instanceof WP_Error ) && $saved['status']['enabled'] === $enabled, 'MCP reference-only save preserves effective sync state.' );
    check( count( $requests ) === 2 && ! array_filter( $requests, function( $r ) { return $r[1]['method'] !== 'GET'; } ), 'Saving references never creates or updates Google.' );
    check( ! empty( $options['mbs_google_calendar']['managed_updates'] ) && empty( $options['mbs_google_calendar']['choices'] ), 'Lifecycle policy preserved and stale choices cleared.' );
    $last = end( $options['mbs_gcal_history'] );
    check( $last['action'] === 'references_saved' && strpos( $last['result'], $enabled ? 'remains enabled' : 'remains paused' ) !== false, 'History explicitly records preserved state.' );
}
ref_state_ready( true, 'legacy-unreviewed' );
invoke( 'commit_review', array( 'ops' => actual_ops() ), array(), false );
check( ! MBS_Google_Calendar::status()['enabled'], 'Raw old enabled flag cannot silently activate an unreviewed connection.' );
ref_state_ready( true ); $ops = actual_ops(); $wpdb->rows[0]->start_time = '18:00:00';
// Stale references would fail before applying, without disabling an active worker.
$manual = $ops[0]['want']; $manual['id'] = 'estate'; unset( $manual['extendedProperties'] );
$review = $ops[0]; $review['action'] = 'review'; $review['candidates'] = array( $manual );
try { invoke( 'commit_review', array( 'ops' => array( $review ) ), array( $review['hash'] => array( 'protect:estate' ) ), false ); check( false, 'Stale source must fail.' ); }
catch ( RuntimeException $e ) { check( MBS_Google_Calendar::status()['enabled'], 'Failed reference-only save does not silently pause active sync.' ); }
$source = file_get_contents( dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-google-calendar.php' );
check( substr_count( $source, 'self::commit_review( $snapshot, $choices,' ) === 2, 'Website and MCP use the same state-preserving commit.' );
$view = file_get_contents( dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/admin/views/google-calendar.php' );
check( strpos( $view, 'keep the current automatic-sync state' ) !== false && strpos( $view, 'leaves sync paused' ) === false, 'Review wording matches the new behaviour.' );
echo "Reference sync state: $assertions total assertions passed.\n";
