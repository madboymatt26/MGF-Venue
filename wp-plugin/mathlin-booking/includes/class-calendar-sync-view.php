<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Pure presentation groups. Never supplies choices to, or changes, the sync worker. */
class MBS_Calendar_Sync_View {
    private static function text_key( $text ) {
        return strtolower( trim( preg_replace( '/\s+/u', ' ', html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) ) ) );
    }
    private static function endpoint_key( $endpoint ) {
        if ( isset( $endpoint['date'] ) ) return array( 'date', $endpoint['date'] );
        if ( empty( $endpoint['dateTime'] ) ) return array( 'invalid', $endpoint );
        try { return array( 'time', ( new DateTimeImmutable( $endpoint['dateTime'], new DateTimeZone( $endpoint['timeZone'] ?? 'UTC' ) ) )->getTimestamp() ); }
        catch ( Throwable $e ) { return array( 'invalid', $endpoint ); }
    }
    public static function event_groups( $events ) {
        $groups = array();
        foreach ( $events as $event ) {
            $key = hash( 'sha256', json_encode( array( self::text_key( $event['summary'] ?? '' ), self::text_key( $event['location'] ?? '' ), self::endpoint_key( $event['start'] ?? array() ), self::endpoint_key( $event['end'] ?? array() ) ) ) );
            if ( ! isset( $groups[$key] ) ) $groups[$key] = array( 'event' => $event, 'records' => array() );
            // Retain every record, including separate IDs with identical visible fields.
            $groups[$key]['records'][] = $event;
        }
        return array_values( $groups );
    }
    public static function booking_groups( $ops ) {
        $groups = array();
        foreach ( $ops as $index => $op ) {
            $ids = array_column( $op['candidates'] ?? array(), 'id' ); sort( $ids, SORT_STRING );
            // Sharing candidates is not proof of a shared event. Exact ID sets only;
            // don't merge bookings merely because their times or labels overlap.
            $key = $op['action'] === 'review' && $ids ? 'candidates:' . hash( 'sha256', json_encode( $ids ) ) : 'single:' . $index;
            if ( ! isset( $groups[$key] ) ) $groups[$key] = array( 'items' => array(), 'candidates' => $op['candidates'] );
            $groups[$key]['items'][] = $op;
        }
        return array_values( $groups );
    }
    public static function google_url( $event ) {
        $url = $event['htmlLink'] ?? ''; $p = parse_url( $url );
        if ( ! $p || ( $p['scheme'] ?? '' ) !== 'https' || isset( $p['user'] ) || isset( $p['pass'] ) ) return '';
        if ( ( $p['host'] ?? '' ) === 'calendar.google.com' || ( ( $p['host'] ?? '' ) === 'www.google.com' && strpos( $p['path'] ?? '', '/calendar/' ) === 0 ) ) return $url;
        return '';
    }
    public static function record_label( $event ) {
        $id = (string) ( $event['id'] ?? '' );
        return 'ID …' . substr( $id, -12 ) . ' [' . substr( hash( 'sha256', $id ), 0, 8 ) . ']';
    }
}
