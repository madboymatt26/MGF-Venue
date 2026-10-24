<?php
if ( ! defined( 'ABSPATH' ) ) exit;
require_once __DIR__ . '/class-booking-intervals.php';

/** Durable coalescing intents; HTTP runs in a worker, never a booking writer. */
class MBS_HomeAssistant {
    const HOOK = 'mbs_ha_outbox';
    public function init() {
        add_action( self::HOOK, array( __CLASS__, 'process' ) );
        add_filter( 'cron_schedules', static function($s){$s['mbs_ha_five_minutes']=array('interval'=>300,'display'=>'MGF Home Assistant retries');return$s;} );
        $event=wp_get_scheduled_event(self::HOOK);
        if($event&&$event->schedule!=='mbs_ha_five_minutes'){wp_clear_scheduled_hook(self::HOOK);$event=false;}
        if ( ! $event ) wp_schedule_event( time() + 60, 'mbs_ha_five_minutes', self::HOOK );
    }
    public static function deactivate() { wp_clear_scheduled_hook( self::HOOK ); }
    public static function health() {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 20001",$wpdb->esc_like('mbs_ha_event_').'%'));
        if(!is_array($rows)||$wpdb->last_error||count($rows)>20000)return array('available'=>false);
        $out=array('available'=>true,'pending'=>0,'failed'=>0,'oldest_pending_at'=>null);
        foreach($rows as$row){$value=maybe_unserialize($row->option_value);$state=$value['state']??'';if(isset($out[$state]))$out[$state]++;if($state==='pending'&&isset($value['at']))$out['oldest_pending_at']=$out['oldest_pending_at']===null?(int)$value['at']:min($out['oldest_pending_at'],(int)$value['at']);}
        return$out;
    }
    public static function notify( $booking ) { return self::queue( $booking, 'booking_confirmed' ); }
    public static function failed_intents() {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT 20001", $wpdb->esc_like( 'mbs_ha_event_' ) . '%' ) );
        if ( ! is_array( $rows ) || $wpdb->last_error || count( $rows ) > 20000 ) return new WP_Error( 'ha_read_failed', 'Home Assistant intents could not be read completely.' );
        $out = array();
        foreach ( $rows as $row ) {
            $intent = maybe_unserialize( $row->option_value );
            if ( is_array( $intent ) && ( $intent['state'] ?? '' ) === 'failed' ) $out[] = array_intersect_key( $intent, array_flip( array( 'ref','version','at','attempts' ) ) );
        }
        return $out;
    }
    public static function retry_failed( $ref, $expected ) {
        global $wpdb;
        if ( ! get_option( 'mbs_ha_webhook_url', '' ) ) return new WP_Error( 'ha_disabled', 'Configure Home Assistant before retrying.' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name=%s", 'mbs_ha_event_' . hash( 'sha256', $ref ) ) );
        $intent = $row ? maybe_unserialize( $row->option_value ) : null;
        if ( ! is_array( $intent ) || ( $intent['state'] ?? '' ) !== 'failed' || ! is_string( $expected ) || ! hash_equals( (string) $intent['version'], $expected ) ) return new WP_Error( 'ha_stale', 'This intent changed. Reload Integration health before retrying.' );
        $intent['state'] = 'pending'; $intent['attempts'] = 0; $intent['next'] = time();
        if ( ! self::replace( $row, $intent ) ) return new WP_Error( 'ha_stale', 'This intent changed before it could be queued.' );
        MBS_Audit_Log::log( $ref, 'ha_retry_queued', 'Reviewed Home Assistant intent queued; no immediate HTTP request or customer email.' );
        return true;
    }
    public static function notify_cancelled( $booking ) { return self::queue( $booking, 'booking_cancelled' ); }
    private static function queue( $booking, $event ) {
        if ( ! get_option( 'mbs_ha_webhook_url', '' ) || ! empty( $booking->scout_use ) ) return false;
        $key = 'mbs_ha_event_' . hash( 'sha256', $booking->ref );
        $intent = array( 'ref' => $booking->ref, 'event' => $event, 'version' => wp_generate_uuid4(), 'at' => time(), 'next' => time(), 'attempts' => 0, 'state' => 'pending' );
        update_option( $key, $intent, false );
        if ( ! wp_next_scheduled( self::HOOK ) || wp_next_scheduled( self::HOOK ) > time() + 60 ) wp_schedule_single_event( time() + 60, self::HOOK );
        return get_option( $key ) === $intent;
    }
    public static function payload( $b, $event = 'booking_confirmed', $version = '' ) {
        $intervals = MBS_Booking_Intervals::intervals( $b );
        return array( 'event' => $event, 'event_id' => hash( 'sha256', home_url() . '|' . $b->ref . '|' . $version ), 'version' => $version,
            'ref' => $b->ref, 'space' => $b->space, 'spaces' => class_exists( 'MBS_Space_Selection' ) ? MBS_Space_Selection::members( $b->space ) : array( $b->space ),
            'booking_date' => $b->booking_date, 'booking_date_end' => $b->booking_date_end ?: $b->booking_date,
            'start_time' => $b->start_time, 'end_time' => $b->end_time, 'all_day' => (bool) $b->all_day,
            'attendees' => (int) $b->attendees, 'purpose' => $b->purpose, 'kitchen' => (bool) $b->kitchen, 'amount' => (float) $b->amount,
            'intervals' => array_map( static function( $i ) { return array( 'start' => $i['start']->format( DATE_RFC3339 ), 'end' => $i['end']->format( DATE_RFC3339 ) ); }, $intervals ) );
    }
    public static function process() {
        global $wpdb;
        $lock = 'mbs_ha_' . substr( hash( 'sha256', $wpdb->prefix . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) ), 0, 32 );
        if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) !== 1 ) return;
        try {
            $cursor = (int) get_option( 'mbs_ha_cursor', 0 );
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id,option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_id > %d ORDER BY option_id ASC LIMIT 20", $wpdb->esc_like( 'mbs_ha_event_' ) . '%', $cursor ) );
            foreach ( (array) $rows as $row ) {
                $cursor = (int) $row->option_id;
                $intent = maybe_unserialize( $row->option_value );
                if ( ! is_array( $intent ) || ( $intent['state'] ?? '' ) !== 'pending' || (int) $intent['next'] > time() ) continue;
                $booking = MBS_Bookings::get( $intent['ref'] );
                $url = get_option( 'mbs_ha_webhook_url', '' );
                if ( ! $booking || ! empty( $booking->scout_use ) ) { self::replace( $row, null ); continue; }
                if ( ! $url ) continue; // Configuration pause must not discard an intent.
                $event = in_array( $booking->status, array( 'confirmed','deposit_paid','paid' ), true ) ? 'booking_confirmed' : 'booking_cancelled';
                try {
                    $payload = self::payload( $booking, $event, $intent['version'] );
                    $response = wp_remote_post( $url, array( 'timeout' => 10, 'redirection' => 0, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $payload ) ) );
                    $ok = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) >= 200 && wp_remote_retrieve_response_code( $response ) < 300;
                } catch ( Throwable $e ) { $ok = false; }
                if ( $ok ) {
                    if ( self::replace( $row, null ) ) {
                        if ( $event === 'booking_confirmed' ) $wpdb->update( $wpdb->prefix . MBS_TABLE, array( 'ha_notified' => 1 ), array( 'ref' => $booking->ref ) );
                        MBS_Audit_Log::log( $booking->ref, 'ha_accepted', 'Home Assistant endpoint accepted the current booking notification.' );
                    }
                } else {
                    $intent['attempts']++;
                    $intent['next'] = time() + min( 3600, 60 * ( 2 ** min( 6, $intent['attempts'] ) ) );
                    if ( $intent['attempts'] >= 5 ) $intent['state'] = 'failed';
                    self::replace( $row, $intent );
                }
            }
            update_option( 'mbs_ha_cursor', count( (array) $rows ) < 20 ? 0 : $cursor, false );
            if ( $rows && ! wp_next_scheduled( self::HOOK ) ) wp_schedule_single_event( time() + 60, self::HOOK );
        } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }
    private static function replace( $row, $value ) {
        global $wpdb;
        $sql = $value === null ? $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $row->option_name, $row->option_value ) : $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s", maybe_serialize( $value ), $row->option_name, $row->option_value );
        $changed = $wpdb->query( $sql ) === 1;
        if ( $changed ) wp_cache_delete( $row->option_name, 'options' );
        return $changed;
    }
    public static function get_upcoming_for_ha( $days_ahead = 30 ) {
        $through = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+' . max( 0, min( 366, (int) $days_ahead ) ) . ' days' )->format( 'Y-m-d' );
        return array_map( array( __CLASS__, 'payload' ), MBS_Booking_Intervals::approved( $through ) );
    }
    public static function get_todays_bookings() {
        $rows = MBS_Bookings::get_by_date( wp_date( 'Y-m-d' ) );
        if ( ! is_array( $rows ) ) throw new RuntimeException( 'Today occupancy is unavailable.' );
        return array_values( array_map( array( __CLASS__, 'payload' ), array_filter( $rows, static function( $b ) { return in_array( $b->status, array( 'confirmed','deposit_paid','paid' ), true ); } ) ) );
    }
}
