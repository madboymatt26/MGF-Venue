<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Reviewed, atomic replacement of six matching legacy area reservations. Never sends mail. */
class MBS_Campsite_Consolidation {
    const BATCH = 'whole-park-unambiguous-20261018-v1';

    public function init() { add_action( 'rest_api_init', array( $this, 'routes' ) ); }
    public function routes() {
        register_rest_route( 'mathlin/v1', '/admin/campsite-consolidation', array(
            array( 'methods' => 'GET', 'callback' => array( $this, 'preview' ), 'permission_callback' => array( $this, 'allowed' ) ),
            array( 'methods' => 'POST', 'callback' => array( $this, 'apply' ), 'permission_callback' => array( $this, 'allowed' ) ),
        ) );
    }
    public function allowed() { return current_user_can( 'manage_options' ) && MBS_Campsite_Policy::enabled() && wp_parse_url( home_url(), PHP_URL_HOST ) === 'stowmarketscouts.org.uk'; }

    public static function normalise_note( $note ) { return trim( preg_replace( '/\s+/', ' ', strtolower( str_replace( '-', ' ', html_entity_decode( (string) $note, ENT_QUOTES, 'UTF-8' ) ) ) ) ); }

    /** Pure selection: exactly one reservation in each area, same event and session. */
    public static function candidates( $rows, $areas, $today ) {
        $groups = array(); $areas = array_values( $areas ); sort( $areas );
        foreach ( $rows as $row ) {
            $b = (array) $row;
            if ( ! in_array( $b['status'], array( 'pending', 'confirmed' ), true ) || $b['booking_date'] < $today || ! preg_match( '/^MBS-L\d+-\d{6}$/D', $b['ref'] ) ) continue;
            if ( ( $b['booking_date_end'] ?: $b['booking_date'] ) !== $b['booking_date'] || (float) $b['amount'] !== 0.0 || (float) ( $b['amount_paid'] ?? 0 ) !== 0.0 || ! empty( $b['invoice_number'] ) ) continue;
            $key = json_encode( array( strtolower( $b['email'] ), $b['organisation'], $b['booking_date'], $b['booking_date_end'], (bool) $b['all_day'], $b['start_time'], $b['end_time'], $b['status'] ) );
            $groups[$key][] = $b;
        }
        $matches = array();
        foreach ( $groups as $group ) {
            $found = array_column( $group, 'space' ); sort( $found );
            if ( count( $group ) !== 6 || $found !== $areas || count( array_unique( array_column( $group, 'purpose' ) ) ) !== 1 || count( array_unique( array_map( static function( $b ) { return self::normalise_note( $b['notes'] ); }, $group ) ) ) !== 1 ) continue;
            usort( $group, static function( $a, $b ) { return strcmp( $a['ref'], $b['ref'] ); } );
            $refs = array_column( $group, 'ref' );
            $family = array_map( static function( $b ) { return $b['series_id'] ?: $b['ref']; }, $group ); sort( $family );
            $matches[] = array( 'date' => $group[0]['booking_date'], 'refs' => $refs, 'target_ref' => 'MBS-W' . strtoupper( substr( hash( 'sha256', implode( '|', $refs ) ), 0, 12 ) ), 'family' => hash( 'sha256', implode( '|', $family ) ), 'rows' => $group );
        }
        usort( $matches, static function( $a, $b ) { return strcmp( $a['date'] . $a['target_ref'], $b['date'] . $b['target_ref'] ); } );
        return $matches;
    }

    private static function plan( $rows ) {
        $spaces = MBS_Bookings::get_spaces(); $areas = array();
        foreach ( $spaces as $name => $config ) if ( ( $config['parent'] ?? '' ) === 'Whole Park' ) $areas[] = $name;
        if ( count( $areas ) !== 6 ) return new WP_Error( 'consolidation_spaces', 'Expected exactly the six reviewed Whole Park child areas.' );
        $matches = self::candidates( $rows, $areas, wp_date( 'Y-m-d' ) );
        return array( 'matches' => $matches, 'fingerprint' => hash( 'sha256', wp_json_encode( $matches ) ) );
    }

    public function preview() {
        global $wpdb;
        $done = get_option( 'mbs_campsite_whole_park_batch', array() );
        if ( $done ) return rest_ensure_response( array( 'completed' => true, 'result' => $done['result'] ) );
        $plan = self::plan( $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . MBS_TABLE . ' ORDER BY ref', ARRAY_A ) );
        if ( is_wp_error( $plan ) ) return $plan;
        $summary = array_map( static function( $m ) { unset( $m['rows'], $m['family'] ); return $m; }, $plan['matches'] );
        return rest_ensure_response( array( 'batch' => self::BATCH, 'dates' => count( $summary ), 'source_rows' => count( $summary ) * 6, 'matches' => $summary, 'fingerprint' => $plan['fingerprint'], 'notifications_sent' => 0 ) );
    }

    public function apply( $request ) {
        if ( class_exists( 'MBS_Space_Selection' ) ) return MBS_Space_Selection::locked( function() use ( $request ) { return $this->apply_locked( $request ); } );
        return $this->apply_locked( $request );
    }

    public function apply_locked( $request ) {
        global $wpdb;
        $done = get_option( 'mbs_campsite_whole_park_batch', array() );
        if ( $done ) return rest_ensure_response( array( 'no_op' => true, 'result' => $done['result'] ) );
        if ( $request->get_param( 'batch' ) !== self::BATCH ) return new WP_Error( 'consolidation_batch', 'Supply the reviewed batch identifier.' );
        $table = $wpdb->prefix . MBS_TABLE; $series_table = $wpdb->prefix . MBS_SERIES_TABLE;
        if ( (int) $wpdb->get_var( "SELECT GET_LOCK('mbs_campsite_consolidation',10)" ) !== 1 ) return new WP_Error( 'consolidation_busy', 'Another consolidation is running.' );
        try {
            foreach ( array( $table, $series_table, $wpdb->options ) as $transaction_table ) {
                $engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $transaction_table ) );
                if ( strtoupper( (string) $engine ) !== 'INNODB' ) throw new RuntimeException( 'Transactional storage is required for ' . $transaction_table );
            }
            if ( $wpdb->query( 'START TRANSACTION' ) === false ) throw new RuntimeException( 'Could not start the consolidation transaction.' );
            $all = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY ref FOR UPDATE", ARRAY_A );
            if ( ! is_array( $all ) || $wpdb->last_error ) throw new RuntimeException( 'Could not lock the current booking state.' );
            $plan = self::plan( $all ); if ( is_wp_error( $plan ) ) throw new RuntimeException( $plan->get_error_message() );
            $approved = $request->get_param( 'approved_matches' );
            $actual = array_map( static function( $m ) { return array( 'date' => $m['date'], 'refs' => $m['refs'] ); }, $plan['matches'] );
            if ( count( $actual ) !== 26 || ! hash_equals( $plan['fingerprint'], (string) $request->get_param( 'fingerprint' ) ) || $actual !== $approved ) throw new RuntimeException( 'The reviewed 26-date plan changed. Nothing was consolidated.' );
            $sources = array(); $old_series = array(); $families = array(); $targets = array();
            foreach ( $plan['matches'] as $match ) {
                $first = $match['rows'][0];
                if ( MBS_Bookings::get( $match['target_ref'] ) ) throw new RuntimeException( 'A replacement reference already exists without a completed batch.' );
                foreach ( $match['rows'] as $row ) {
                    if ( MBS_Bookings::has_financial_history( $row['ref'] ) || ! empty( $row['current_invoice_document_id'] ) ) throw new RuntimeException( 'A source booking has financial history; review manually.' );
                    if ( ! empty( $row['ha_notified'] ) ) throw new RuntimeException( 'A source has an active external integration; review manually.' );
                    $sources[$row['ref']] = $row;
                    if ( ! empty( $row['series_id'] ) ) $old_series[$row['series_id']] = true;
                }
                // Whole Park may only replace these six conflicts, never an unrelated reservation.
                $conflicts = MBS_Bookings::check_conflicts( 'Whole Park', $first['booking_date'], $first['start_time'], $first['end_time'], (bool) $first['all_day'] );
                $conflict_refs = wp_list_pluck( $conflicts, 'ref' ); sort( $conflict_refs );
                if ( $conflict_refs !== $match['refs'] || MBS_Blocked_Dates::is_blocked( $first['booking_date'], 'Whole Park' ) ) throw new RuntimeException( 'An unrelated conflict or block exists on ' . $first['booking_date'] );
                $families[$match['family']][] = $match;
            }
            $parents = array();
            foreach ( array_keys( $old_series ) as $series_ref ) {
                $parent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$series_table} WHERE series_ref=%s FOR UPDATE", $series_ref ), ARRAY_A );
                if ( ! $parent || $parent['billing_treatment'] !== 'none' ) throw new RuntimeException( 'A legacy group changed its billing state.' );
                $parents[$series_ref] = $parent;
            }
            $refs = array_keys( $sources ); $related = array_merge( $refs, array_keys( $parents ) );
            $placeholders = implode( ',', array_fill( 0, count( $refs ), '%s' ) );
            if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mathlin_mod_requests WHERE booking_ref IN ({$placeholders}) AND status='pending'", $refs ) ) ) throw new RuntimeException( 'A source has an outstanding change request.' );
            foreach ( $related as $ref ) {
                $like = '%"related_ref":' . $wpdb->esc_like( wp_json_encode( $ref ) ) . '%';
                if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . MBS_INVOICE_ITEM_TABLE . ' WHERE pricing_snapshot_json LIKE %s LIMIT 1', $like ) ) ) throw new RuntimeException( 'A source has a linked additional charge.' );
            }
            $untouched = array(); foreach ( $all as $row ) if ( ! isset( $sources[$row['ref']] ) ) $untouched[$row['ref']] = $row;
            $new_series = array();
            foreach ( $families as $family => $matches ) {
                $series_ref = count( $matches ) > 1 ? 'SER-W' . strtoupper( substr( $family, 0, 12 ) ) : null;
                $family_refs = array(); $dates = array(); $occurrences = array();
                if ( $series_ref && MBS_Series::get( $series_ref ) ) throw new RuntimeException( 'A replacement series reference already exists.' );
                foreach ( $matches as $match ) {
                    $first = $match['rows'][0]; $new = $first;
                    unset( $new['id'], $new['created_at'] );
                    $custom = json_decode( (string) $first['custom_fields'], true ) ?: array();
                    $custom['_mbs_whole_park_sources'] = $match['refs']; $custom['_mbs_whole_park_batch'] = self::BATCH;
                    // A replacement is linked to all sources, not just the first area's legacy identity.
                    unset( $custom['_mbs_legacy'] );
                    $new['ref'] = $match['target_ref']; $new['space'] = 'Whole Park'; $new['series_id'] = $series_ref;
                    $new['custom_fields'] = wp_json_encode( $custom ); $new['modification_token'] = wp_generate_password( 32, false );
                    $new['admin_notes'] = trim( (string) ( $first['admin_notes'] ?? '' ) . "\nWhole Park consolidation: " . implode( ', ', $match['refs'] ) . '. Original records archived, approvals/times retained; no customer notification.' );
                    if ( $wpdb->insert( $table, $new ) === false ) throw new RuntimeException( 'Could not save a Whole Park replacement.' );
                    $targets[$new['ref']] = $match['refs']; $family_refs[] = $new['ref']; $dates[] = $new['booking_date'];
                    $occurrences[] = array( 'date' => $new['booking_date'], 'status' => 'accepted', 'ref' => $new['ref'] );
                    foreach ( $match['rows'] as $source ) {
                        $metadata = json_decode( (string) $source['custom_fields'], true ) ?: array();
                        $metadata['_mbs_whole_park_replacement'] = $new['ref'];
                        if ( $wpdb->update( $table, array( 'status' => 'archived', 'custom_fields' => wp_json_encode( $metadata ), 'admin_notes' => trim( (string) ( $source['admin_notes'] ?? '' ) . "\nConsolidated into " . $new['ref'] . '; archived, not cancelled. Original approval: ' . $source['status'] ) ), array( 'ref' => $source['ref'], 'status' => $source['status'] ) ) !== 1 ) throw new RuntimeException( 'A source changed during consolidation.' );
                    }
                }
                if ( $series_ref ) {
                    $data = (array) MBS_Bookings::get( $family_refs[0] ); $data['recurrence_pattern'] = 'selected'; $data['recurrence_dates'] = $dates;
                    $series = MBS_Series::create_from_request( $series_ref, $data, max( $dates ), $occurrences, $family_refs );
                    if ( is_wp_error( $series ) ) throw new RuntimeException( $series->get_error_message() );
                    if ( $wpdb->update( $series_table, array( 'status' => $data['status'], 'billing_mode' => 'none', 'billing_treatment' => 'none', 'payment_method' => 'none', 'automatic_reminders' => 0 ), array( 'series_ref' => $series_ref ) ) === false ) throw new RuntimeException( 'Could not retain the replacement group approval.' );
                    $new_series[] = $series_ref;
                }
            }
            $archived_parents = array();
            foreach ( $parents as $series_ref => $parent ) {
                $remaining = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE series_id=%s AND status NOT IN ('archived','cancelled') ORDER BY booking_date", $series_ref ), ARRAY_A );
                // Only fully superseded parents leave the main lists. Partial exceptions remain unchanged.
                if ( ! $remaining ) {
                    $schedule = json_decode( (string) $parent['schedule_json'], true ) ?: array(); $schedule['whole_park_consolidated_batch'] = self::BATCH;
                    if ( $wpdb->update( $series_table, array( 'status' => 'archived', 'schedule_json' => wp_json_encode( $schedule ), 'version' => (int) $parent['version'] + 1 ), array( 'series_ref' => $series_ref, 'version' => $parent['version'] ) ) !== 1 ) throw new RuntimeException( 'Could not retain a superseded parent safely.' );
                    $archived_parents[] = $series_ref;
                }
            }
            foreach ( $untouched as $ref => $before ) {
                $after = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE ref=%s", $ref ), ARRAY_A );
                if ( $before !== $after ) throw new RuntimeException( 'An excluded booking changed; rolling back the batch.' );
            }
            foreach ( $targets as $ref => $original_refs ) {
                $target = MBS_Bookings::get( $ref );
                $conflicts = MBS_Bookings::check_conflicts( 'Whole Park', $target->booking_date, $target->start_time, $target->end_time, (bool) $target->all_day, $ref );
                if ( $conflicts || (float) $target->amount !== 0.0 ) throw new RuntimeException( 'Replacement verification failed.' );
            }
            $result = array( 'batch' => self::BATCH, 'dates_consolidated' => count( $targets ), 'originals_archived' => count( $sources ), 'new_series' => $new_series, 'archived_series' => $archived_parents, 'unchanged_bookings_verified' => count( $untouched ), 'replacements' => $targets, 'notifications_sent' => 0, 'completed_at' => current_time( 'mysql' ) );
            // Same transactional database: before-images and completion marker commit with the records.
            $backup = array( 'sources' => $sources, 'parents' => $parents, 'result' => $result );
            $option = 'mbs_campsite_whole_park_batch';
            if ( $wpdb->insert( $wpdb->options, array( 'option_name' => $option, 'option_value' => maybe_serialize( $backup ), 'autoload' => 'off' ) ) === false ) throw new RuntimeException( 'Could not save the recovery snapshot.' );
            if ( $request->get_param( 'dry_run' ) === true ) {
                if ( $wpdb->query( 'ROLLBACK' ) === false ) throw new RuntimeException( 'Could not roll back the rehearsal.' );
                $result['dry_run'] = true; $result['committed'] = false;
                return rest_ensure_response( $result );
            }
            if ( $wpdb->query( 'COMMIT' ) === false ) throw new RuntimeException( 'Could not commit consolidation.' );
            wp_cache_delete( $option, 'options' ); wp_cache_delete( 'notoptions', 'options' );
            MBS_Audit_Log::log( 'WHOLE-PARK-MERGE', 'whole_park_consolidated', 'Replaced ' . count( $sources ) . ' matching legacy area records with ' . count( $targets ) . ' Whole Park dates. Originals archived; all excluded booking rows unchanged; no customer email.' );
            foreach ( $targets as $ref => $original_refs ) MBS_Audit_Log::log( $ref, 'whole_park_consolidated', 'Replaces archived sources: ' . implode( ', ', $original_refs ) );
            return rest_ensure_response( $result );
        } catch ( Throwable $error ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'consolidation_failed', $error->getMessage(), array( 'status' => 409 ) ); }
        finally { $wpdb->get_var( "SELECT RELEASE_LOCK('mbs_campsite_consolidation')" ); }
    }
}
