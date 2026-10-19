<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** One-off, reversible repair of imported full-day stays. No mail or Google writes. */
class MBS_Campsite_Multiday {
    const OPTION = 'mbs_campsite_multiday_batch';
    const BATCH = 'legacy-full-day-stays-v1';

    public function init() { add_action( 'rest_api_init', array( $this, 'routes' ) ); }
    public function routes() {
        register_rest_route( 'mathlin/v1', '/admin/campsite-multiday', array(
            array( 'methods' => 'GET', 'callback' => array( $this, 'preview' ), 'permission_callback' => array( $this, 'allowed' ) ),
            array( 'methods' => 'POST', 'callback' => array( $this, 'apply' ), 'permission_callback' => array( $this, 'allowed' ) ),
        ) );
    }
    public function allowed() { return current_user_can( 'manage_options' ) && MBS_Campsite_Policy::enabled() && wp_parse_url( home_url(), PHP_URL_HOST ) === 'stowmarketscouts.org.uk'; }

    /** Only complete, consecutive, identical legacy groups; never infer camps from timed sessions. */
    public static function candidates( $rows, $today ) {
        $groups = array(); $matches = array(); $excluded = array();
        foreach ( $rows as $row ) {
            $b = (array) $row;
            if ( ! empty( $b['series_id'] ) && in_array( $b['status'], array( 'pending', 'confirmed' ), true ) ) $groups[$b['series_id']][] = $b;
        }
        ksort( $groups );
        foreach ( $groups as $series => $members ) {
            if ( count( $members ) < 2 || ! preg_match( '/^SER-[LW]/', $series ) ) continue;
            usort( $members, static function( $a, $b ) { return strcmp( $a['booking_date'] . $a['ref'], $b['booking_date'] . $b['ref'] ); } );
            $signature = null; $previous = null; $reason = '';
            foreach ( $members as $b ) {
                if ( ! preg_match( '/^MBS-(L\d+-\d{6}|W[A-F0-9]{12})$/D', $b['ref'] ) || ! MBS_Campsite_Policy::valid_date( $b['booking_date'] ) || $b['booking_date'] < $today || ( $b['booking_date_end'] ?: $b['booking_date'] ) !== $b['booking_date'] || empty( $b['all_day'] ) ) { $reason = 'Not an entirely future, full-day legacy group.'; break; }
                if ( (float) $b['amount'] !== 0.0 || (float) ( $b['amount_paid'] ?? 0 ) !== 0.0 || ! empty( $b['invoice_number'] ) || ! empty( $b['current_invoice_document_id'] ) || ! empty( $b['ha_notified'] ) ) { $reason = 'Financial or external integration history requires review.'; break; }
                $custom = json_decode( (string) $b['custom_fields'], true );
                if ( ! is_array( $custom ) || ( empty( $custom['_mbs_legacy'] ) && empty( $custom['_mbs_whole_park_sources'] ) ) ) { $reason = 'Missing verified legacy provenance.'; break; }
                // Per-day source hashes and source references differ by design. Other answers must match.
                unset( $custom['_mbs_legacy'], $custom['_mbs_whole_park_sources'], $custom['_mbs_whole_park_batch'] );
                ksort( $custom );
                $same = array( $custom );
                foreach ( array( 'status', 'space', 'name', 'email', 'phone', 'organisation', 'address', 'purpose', 'notes', 'start_time', 'end_time', 'attendees', 'kitchen', 'scout_use', 'pricing_tier', 'user_id', 'is_public', 'admin_notes' ) as $field ) {
                    $value = (string) ( $b[$field] ?? '' );
                    // Strip only our generated provenance line, never a coordinator's added instructions.
                    if ( $field === 'admin_notes' && ! empty( json_decode( $b['custom_fields'], true )['_mbs_whole_park_sources'] ) ) $value = trim( preg_replace( '/^Whole Park consolidation: MBS-[^\r\n]+\. Original records archived, approvals\/times retained; no customer notification\.$/m', '', $value ) );
                    $same[] = $value;
                }
                if ( $signature !== null && $signature !== $same ) { $reason = 'Details, approval or answers differ between dates.'; break; }
                if ( $previous !== null && ( new DateTimeImmutable( $previous ) )->modify( '+1 day' )->format( 'Y-m-d' ) !== $b['booking_date'] ) { $reason = 'Separate selected dates, not one consecutive stay.'; break; }
                $signature = $same; $previous = $b['booking_date'];
            }
            if ( $reason ) { $excluded[] = array( 'series' => $series, 'reason' => $reason ); continue; }
            $matches[] = array( 'series' => $series, 'ref' => $members[0]['ref'], 'from' => $members[0]['booking_date'], 'to' => $previous, 'space' => $members[0]['space'], 'status' => $members[0]['status'], 'refs' => array_column( $members, 'ref' ), 'rows' => $members );
        }
        return array( 'matches' => $matches, 'excluded' => $excluded, 'fingerprint' => hash( 'sha256', wp_json_encode( $rows ) ) );
    }

    public function preview() {
        global $wpdb;
        $done = get_option( self::OPTION, array() );
        if ( $done ) return rest_ensure_response( array( 'completed' => true, 'result' => $done['result'] ) );
        $rows = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . MBS_TABLE . ' ORDER BY ref', ARRAY_A );
        if ( ! is_array( $rows ) || $wpdb->last_error ) return new WP_Error( 'multiday_read', 'Could not read the full booking state.' );
        $plan = self::candidates( $rows, wp_date( 'Y-m-d' ) );
        foreach ( $plan['matches'] as &$match ) unset( $match['rows'] );
        unset( $match );
        $plan['batch'] = self::BATCH;
        return rest_ensure_response( $plan );
    }

    public function apply( $request ) {
        global $wpdb;
        $done = get_option( self::OPTION, array() );
        if ( $done ) return rest_ensure_response( array( 'no_op' => true, 'result' => $done['result'] ) );
        if ( $request->get_param( 'batch' ) !== self::BATCH ) return new WP_Error( 'multiday_batch', 'Supply the reviewed batch identifier.' );
        // Never orphan or duplicate existing Google links. First-sync review must follow this repair.
        $google = (array) get_option( 'mbs_google_calendar', array() );
        if ( ! empty( $google['enabled'] ) || ! empty( $google['calendar'] ) ) return new WP_Error( 'multiday_google', 'Pause and review existing Google links before repairing records.' );
        $table = $wpdb->prefix . MBS_TABLE; $series_table = $wpdb->prefix . MBS_SERIES_TABLE;
        if ( (int) $wpdb->get_var( "SELECT GET_LOCK('mbs_campsite_consolidation',10)" ) !== 1 ) return new WP_Error( 'multiday_busy', 'A campsite repair is already running.' );
        try {
            foreach ( array( $table, $series_table, $wpdb->options ) as $t ) {
                if ( strtoupper( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $t ) ) ) !== 'INNODB' ) throw new RuntimeException( 'Transactional storage is required.' );
            }
            if ( $wpdb->query( 'START TRANSACTION' ) === false ) throw new RuntimeException( 'Cannot start transaction.' );
            // Recheck after the lock: another worker may have completed while this request waited.
            if ( $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE", self::OPTION ) ) ) throw new RuntimeException( 'Repair already completed. Refresh the preview.' );
            $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY ref FOR UPDATE", ARRAY_A );
            if ( ! is_array( $rows ) || $wpdb->last_error ) throw new RuntimeException( 'Could not lock the complete booking state.' );
            $plan = self::candidates( $rows, wp_date( 'Y-m-d' ) );
            $actual = array_column( $plan['matches'], 'refs' );
            if ( ! $actual || ! hash_equals( $plan['fingerprint'], (string) $request->get_param( 'fingerprint' ) ) || $request->get_param( 'approved_matches' ) !== $actual ) throw new RuntimeException( 'The reviewed booking plan changed. Nothing was merged.' );
            $sources = array(); $parents = array(); $targets = array(); $changed = array();
            foreach ( $plan['matches'] as $m ) {
                $parent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$series_table} WHERE series_ref=%s FOR UPDATE", $m['series'] ), ARRAY_A );
                if ( ! $parent || $parent['billing_treatment'] !== 'none' || $parent['status'] !== $m['status'] ) throw new RuntimeException( 'A parent group changed or has billing history.' );
                $parents[$m['series']] = $parent;
                $related = array_merge( $m['refs'], array( $m['series'] ) );
                foreach ( $related as $ref ) {
                    if ( MBS_Bookings::has_financial_history( $ref ) ) throw new RuntimeException( 'A source has financial history.' );
                    if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}mathlin_mod_requests WHERE booking_ref=%s AND status='pending' LIMIT 1", $ref ) ) ) throw new RuntimeException( 'A source has an outstanding change request.' );
                    if ( $wpdb->last_error ) throw new RuntimeException( 'Cannot verify outstanding requests.' );
                    $like = '%"related_ref":' . $wpdb->esc_like( wp_json_encode( $ref ) ) . '%';
                    if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . MBS_INVOICE_ITEM_TABLE . ' WHERE pricing_snapshot_json LIKE %s LIMIT 1', $like ) ) ) throw new RuntimeException( 'A source has a linked charge.' );
                    if ( $wpdb->last_error ) throw new RuntimeException( 'Cannot verify linked charges.' );
                }
                foreach ( $m['rows'] as $b ) {
                    $sources[$b['ref']] = $b; $changed[$b['ref']] = true;
                    $metadata = json_decode( $b['custom_fields'], true );
                    if ( $b['ref'] === $m['ref'] ) {
                        $metadata['_mbs_multiday_sources'] = $m['refs'];
                        $metadata['_mbs_campsite_span'] = 'continuous';
                        $update = array( 'booking_date_end' => $m['to'], 'series_id' => null );
                    } else {
                        $metadata['_mbs_multiday_replacement'] = $m['ref'];
                        $update = array( 'status' => 'archived' );
                    }
                    $update['custom_fields'] = wp_json_encode( $metadata );
                    $update['admin_notes'] = trim( (string) ( $b['admin_notes'] ?? '' ) . "\nFull-day stay repair: " . $m['ref'] . ', ' . $m['from'] . ' to ' . $m['to'] . '. Original records retained in private recovery snapshot; no notification.' );
                    if ( $wpdb->update( $table, $update, array( 'ref' => $b['ref'], 'status' => $b['status'] ) ) !== 1 ) throw new RuntimeException( 'A source changed during repair.' );
                }
                $schedule = json_decode( $parent['schedule_json'], true ) ?: array(); $schedule['multiday_replacement'] = $m['ref'];
                if ( $wpdb->update( $series_table, array( 'status' => 'archived', 'schedule_json' => wp_json_encode( $schedule ), 'version' => (int) $parent['version'] + 1 ), array( 'series_ref' => $m['series'], 'version' => $parent['version'] ) ) !== 1 ) throw new RuntimeException( 'Could not archive the superseded group.' );
                $targets[] = array_diff_key( $m, array( 'rows' => true ) );
            }
            // Compare each day's conflicts before/after. We preserve existing overlaps rather than inventing availability.
            foreach ( $targets as $m ) {
                $target = MBS_Bookings::get( $m['ref'] );
                if ( ! $target || $target->booking_date_end !== $m['to'] || $target->status !== $m['status'] || $target->series_id ) throw new RuntimeException( 'Replacement verification failed.' );
                foreach ( $m['refs'] as $old_ref ) {
                    $b = $sources[$old_ref]; $expected = array();
                    // Other-source overlaps are retained; the merged reservation itself is excluded below.
                    foreach ( $rows as $other ) {
                        if ( in_array( $other['ref'], $m['refs'], true ) || in_array( $other['status'], array( 'archived', 'cancelled' ), true ) ) continue;
                        if ( $other['space'] !== $m['space'] && $other['space'] !== 'Whole Park' && $m['space'] !== 'Whole Park' ) continue;
                        if ( $other['booking_date'] <= $b['booking_date'] && ( $other['booking_date_end'] ?: $other['booking_date'] ) >= $b['booking_date'] ) $expected[] = $other['ref'];
                    }
                    $after = wp_list_pluck( MBS_Bookings::check_conflicts( $m['space'], $b['booking_date'], null, null, true, $m['ref'] ), 'ref' );
                    // References of OTHER merged stays are mapped to their surviving reference.
                    foreach ( $targets as $t ) foreach ( $expected as &$e ) if ( in_array( $e, $t['refs'], true ) ) $e = $t['ref'];
                    unset( $e ); $expected = array_values( array_unique( $expected ) ); sort( $expected ); sort( $after );
                    if ( $expected !== $after ) throw new RuntimeException( 'Occupied-day coverage changed; rolling back.' );
                }
            }
            foreach ( $rows as $before ) if ( ! isset( $changed[$before['ref']] ) ) {
                if ( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE ref=%s", $before['ref'] ), ARRAY_A ) !== $before ) throw new RuntimeException( 'An excluded booking changed.' );
            }
            $result = array( 'stays' => count( $targets ), 'sources' => count( $sources ), 'archived_daily_records' => count( $sources ) - count( $targets ), 'targets' => $targets, 'notifications_sent' => 0 );
            $backup = array( 'sources' => $sources, 'parents' => $parents, 'result' => $result );
            if ( $wpdb->insert( $wpdb->options, array( 'option_name' => self::OPTION, 'option_value' => maybe_serialize( $backup ), 'autoload' => 'off' ) ) === false ) throw new RuntimeException( 'Cannot save recovery snapshot.' );
            $dry = $request->get_param( 'dry_run' ) === true;
            if ( $wpdb->query( $dry ? 'ROLLBACK' : 'COMMIT' ) === false ) throw new RuntimeException( 'Could not finish transaction.' );
            $result['committed'] = ! $dry;
            if ( ! $dry ) {
                wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( 'notoptions', 'options' );
                foreach ( $targets as $m ) MBS_Audit_Log::log( $m['ref'], 'multiday_consolidated', 'Combined full-day records ' . implode( ', ', $m['refs'] ) . ' into one stay. Approval and occupied days retained; no email.' );
            }
            return rest_ensure_response( $result );
        } catch ( Throwable $error ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'multiday_failed', $error->getMessage(), array( 'status' => 409 ) ); }
        finally { $wpdb->get_var( "SELECT RELEASE_LOCK('mbs_campsite_consolidation')" ); }
    }
}
