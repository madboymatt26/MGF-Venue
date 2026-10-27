<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Local provenance only. Never exports legacy metadata or writes Google events. */
class MBS_Calendar_Reference_Links {
    /** Presentation evidence only. Never grants write ownership or selects a link. */
    public static function explain( $op ) {
        $sources = $op['original_sources'] ?? array();
        $events = $op['candidates'] ?? array();
        if ( ! $events ) $events = $op['linked_events'] ?? array();
        $reference_counts = array();
        foreach ( $events as $event ) { $original = self::event_legacy_id( $event ); if ( $original ) $reference_counts[$original] = ( $reference_counts[$original] ?? 0 ) + 1; }
        $rows = array();
        foreach ( $events as $event ) {
            $original = self::event_legacy_id( $event );
            $related = array();
            foreach ( $sources as $source ) {
                $source = (object) $source;
                $meta = json_decode( (string) ( $source->custom_fields ?? '' ), true );
                if ( $original !== '' && (string) ( $meta['_mbs_legacy']['booking_id'] ?? '' ) === $original ) $related[] = $source;
            }
            $recommended = in_array( $event['id'] ?? '', $op['recommendation']['ids'] ?? array(), true );
            $findings = array();
            $findings[] = ! $original ? 'No original booking reference was found in this Google record.' : ( $related ? 'Original reference #' . $original . ' matches the saved MGF import lineage.' : 'Original reference #' . $original . ' does not match this booking\'s saved import lineage.' );
            if ( ( $reference_counts[$original] ?? 0 ) > 1 ) $findings[] = 'Multiple Google records contain this original reference. Do not choose by title alone.';
            foreach ( $related as $source ) $findings[] = 'Source ' . $source->ref . ': ' . $source->space . ', ' . $source->booking_date . ' to ' . ( $source->booking_date_end ?: $source->booking_date ) . ( $source->all_day ? ', all day.' : ', ' . substr( (string) $source->start_time, 0, 5 ) . ' to ' . substr( (string) $source->end_time, 0, 5 ) . '.' );
            $findings[] = $recommended ? 'This record is in the complete, unique reference/date/time recommendation. Review before saving.' : 'Not in a complete verified recommendation: dates, times, source coverage or competing claims may differ. Compare this record with every source above.';
            $findings[] = 'Google titles and location labels are descriptive, not proof of the booked area. A visual duplicate can be a separate original area record.';
            $rows[] = array( 'event_id' => (string) ( $event['id'] ?? '' ), 'legacy_id' => $original, 'confidence' => $recommended ? 'verified_lineage_candidate' : ( $related ? 'reference_only_review_required' : 'overlap_only' ), 'findings' => $findings );
        }
        return array( 'action' => $op['action'] ?? 'review', 'summary' => $op['recommendation']['reason'] ?? $op['reason'] ?? 'Compare the original references, areas and dates.', 'candidates' => $rows, 'policy' => 'Explanations never select links. Saving a protected reference does not edit the estate event; unresolved overlaps remain blocked.' );
    }

    public static function leaves( $row, $rows, $seen = array() ) {
        $row = (object) $row;
        if ( isset( $seen[$row->ref] ) || count( $seen ) > 8 ) return array();
        $seen[$row->ref] = true;
        $meta = json_decode( (string) ( $row->custom_fields ?? '{}' ), true ) ?: array();
        $refs = array_unique( array_merge( (array) ( $meta['_mbs_whole_park_sources'] ?? array() ), (array) ( $meta['_mbs_multiday_sources'] ?? array() ) ) );
        $out = array();
        foreach ( $refs as $ref ) {
            if ( $ref === $row->ref ) continue;
            if ( ! is_string( $ref ) || ! isset( $rows[$ref] ) ) return array();
            $children = self::leaves( $rows[$ref], $rows, $seen );
            if ( ! $children ) return array();
            $out += $children;
        }
        if ( ( $meta['_mbs_legacy']['plugin'] ?? '' ) === 'wpbc' && ! empty( $meta['_mbs_legacy']['booking_id'] ) ) {
            $leaf = new stdClass();
            foreach ( array( 'ref','space','booking_date','booking_date_end','all_day','start_time','end_time' ) as $field ) $leaf->$field = $row->$field ?? '';
            $leaf->custom_fields = wp_json_encode( array( '_mbs_legacy' => array( 'plugin' => 'wpbc', 'booking_id' => $meta['_mbs_legacy']['booking_id'] ) ) );
            $out[$row->ref] = $leaf;
        }
        return $out;
    }
    public static function event_legacy_id( $event ) {
        $text = trim( html_entity_decode( strip_tags( preg_replace( '/<br\s*\/?>/i', "\n", $event['description'] ?? '' ) ), ENT_QUOTES, 'UTF-8' ) );
        // The old calendar ends Additional Information with its original numeric booking ID.
        if ( preg_match( '/Additional Information\s*:[\s\S]*?\s(\d{1,10})\s*$/i', $text, $m ) ) return $m[1];
        if ( preg_match( '/(?:Booking (?:ID|Reference)|WPBC)\s*[:#]\s*(\d{1,10})\s*$/i', $text, $m ) ) return $m[1];
        return '';
    }
    public static function records( $link ) {
        return isset( $link['events'] ) && is_array( $link['events'] ) ? $link['events'] : ( ! empty( $link['id'] ) ? array( $link['id'] => $link['google_hash'] ?? '' ) : array() );
    }
    public static function descriptor( $op ) {
        $originals = array();
        foreach ( $op['original_sources'] ?? array() as $source ) {
            $source = (object) $source; $meta = json_decode( $source->custom_fields, true );
            $originals[] = (string) ( $meta['_mbs_legacy']['booking_id'] ?? '' );
        }
        return array( 'key' => $op['key'], 'want' => $op['want'], 'originals' => array_values( array_unique( $originals ) ) );
    }
    /** One historical Google span can represent separate non-overlapping MGF date slices. */
    public static function can_share( $a, $b, $event ) {
        if ( ( $a['key'] ?? '' ) === ( $b['key'] ?? '' ) ) return true;
        $id = self::event_legacy_id( $event );
        if ( ! $id || ! in_array( $id, $a['originals'] ?? array(), true ) || ! in_array( $id, $b['originals'] ?? array(), true ) ) return false;
        foreach ( array( $a, $b ) as $slice ) {
            if ( empty( $slice['want']['start']['date'] ) || empty( $slice['want']['end']['date'] ) || empty( $event['start']['date'] ) || empty( $event['end']['date'] ) ) return false;
            if ( $event['start']['date'] > $slice['want']['start']['date'] || $event['end']['date'] < $slice['want']['end']['date'] ) return false;
        }
        return $a['want']['end']['date'] <= $b['want']['start']['date'] || $b['want']['end']['date'] <= $a['want']['start']['date'];
    }
    /** Recommendations require unique original IDs AND exact endpoints. Differences remain explicit. */
    public static function recommend( $sources, $events, $want ) {
        if ( ! $sources ) return array( 'ids' => array(), 'reason' => 'No verified original booking lineage.' );
        $ids = array();
        foreach ( $sources as $source ) {
            $source = (object) $source;
            $meta = json_decode( (string) $source->custom_fields, true );
            $original = (string) $meta['_mbs_legacy']['booking_id'];
            $matches = array();
            foreach ( $events as $e ) {
                if ( self::event_legacy_id( $e ) !== $original || ( $e['status'] ?? '' ) === 'cancelled' ) continue;
                $a = $e['start']['date'] ?? substr( $e['start']['dateTime'] ?? '', 0, 10 );
                $z = $e['end']['date'] ?? substr( $e['end']['dateTime'] ?? '', 0, 10 );
                if ( isset( $e['end']['date'] ) ) $z = ( new DateTimeImmutable( $z ) )->modify( '-1 day' )->format( 'Y-m-d' );
                // Daily imported sources may be covered by a single continuous Google entry.
                if ( $a > $source->booking_date || $z < ( $source->booking_date_end ?: $source->booking_date ) ) continue;
                if ( ! empty( $source->all_day ) !== isset( $e['start']['date'] ) ) continue;
                if ( empty( $source->all_day ) ) {
                    $start = ( new DateTimeImmutable( $e['start']['dateTime'] ) )->setTimezone( wp_timezone() );
                    $end = ( new DateTimeImmutable( $e['end']['dateTime'] ) )->setTimezone( wp_timezone() );
                    if ( $start->format( 'H:i:s' ) !== $source->start_time || $end->format( 'H:i:s' ) !== $source->end_time ) continue;
                }
                $matches[] = $e['id'];
            }
            if ( count( $matches ) !== 1 ) return array( 'ids' => array(), 'reason' => 'Original reference ' . $original . ' (' . $source->space . ') has missing, ambiguous or different Google dates/times. Review individual records.' );
            $ids[] = $matches[0];
        }
        $ids = array_values( array_unique( $ids ) ); sort( $ids, SORT_STRING );
        return array( 'ids' => $ids, 'reason' => 'Unique original booking references and matching area-source dates/times cover all imported reservations. Google remains unchanged.' );
    }
}
