<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Explicitly reviewed exceptions; never relax the original migration's matching rules. */
class MBS_Reviewed_Park_Consolidation {
    public function init() { add_action( 'rest_api_init', function() {
        register_rest_route( 'mathlin/v1', '/admin/reviewed-park-consolidation', array(
            'methods' => 'POST', 'callback' => array( $this, 'handle' ), 'permission_callback' => array( $this, 'allowed' ),
        ) );
    } ); }
    public function allowed() { return current_user_can( 'manage_options' ) && MBS_Campsite_Policy::enabled() && wp_parse_url( home_url(), PHP_URL_HOST ) === 'stowmarketscouts.org.uk'; }
    private static function fail( $message ) { return new WP_Error( 'reviewed_consolidation', $message, array( 'status' => 409 ) ); }

    /** Pure selection. Every physical child must be represented, with identical occupied time. */
    public static function plan( $rows, $spaces, $input, $today ) {
        $refs = $input['refs'] ?? array();
        if ( ! is_array( $refs ) || count( $refs ) !== 6 || count( array_unique( $refs ) ) !== 6 ) return self::fail( 'Select exactly six distinct original references.' );
        sort( $refs, SORT_STRING );
        $areas = array(); foreach ( $spaces as $name => $info ) if ( ( $info['parent'] ?? '' ) === 'Whole Park' ) $areas[] = $name;
        sort( $areas, SORT_STRING );
        if ( count( $areas ) !== 6 || ! isset( $spaces['Whole Park'] ) ) return self::fail( 'The configured Whole Park must contain exactly six areas.' );
        $selected = array(); foreach ( $rows as $row ) if ( in_array( $row['ref'], $refs, true ) ) $selected[] = $row;
        usort( $selected, static function( $a, $b ) { return strcmp( $a['ref'], $b['ref'] ); } );
        if ( count( $selected ) !== 6 ) return self::fail( 'A selected source is missing.' );
        $found = array_column( $selected, 'space' ); sort( $found, SORT_STRING );
        if ( $found !== $areas ) return self::fail( 'A Whole Park replacement requires all six different areas. Partial areas cannot be labelled Whole Park.' );
        $first = $selected[0];
        $reason = trim( (string) ( $input['reason'] ?? '' ) );
        if ( strlen( $reason ) < 12 || strlen( $reason ) > 2000 || empty( $input['acknowledge_differences'] ) ) return self::fail( 'Explicitly acknowledge the source differences and explain the reviewed event identity.' );
        foreach ( $selected as $row ) {
            if ( ! preg_match( '/^MBS-L\d+-\d{6}$/D', $row['ref'] ) || ! in_array( $row['status'], array( 'pending', 'confirmed' ), true ) || $row['booking_date'] < $today || ! empty( $row['series_id'] ) ) return self::fail( 'Only future, active, standalone legacy reservations may be consolidated here.' );
            foreach ( array( 'booking_date', 'booking_date_end', 'all_day', 'start_time', 'end_time', 'status', 'name', 'email', 'organisation', 'phone', 'address', 'attendees', 'user_id', 'pricing_tier', 'kitchen', 'scout_use', 'is_public', 'reminder_sent', 'access_sent', 'feedback_sent' ) as $field ) {
                if ( (string) ( $row[$field] ?? '' ) !== (string) ( $first[$field] ?? '' ) ) return self::fail( 'Source dates, exact times, approval, ownership and booking flags must match: ' . $field );
            }
            foreach ( array( 'amount', 'amount_paid', 'deposit_paid' ) as $field ) if ( (float) ( $row[$field] ?? 0 ) !== 0.0 ) return self::fail( 'Financial bookings require a separate financial workflow.' );
            if ( ! empty( $row['invoice_number'] ) || ! empty( $row['current_invoice_document_id'] ) || ! empty( $row['ha_notified'] ) ) return self::fail( 'A source has an invoice or active external integration.' );
        }
        $end = $first['booking_date_end'] ?: $first['booking_date'];
        if ( ! MBS_Campsite_Policy::valid_date( $end ) || $end < $first['booking_date'] || ( new DateTimeImmutable( $first['booking_date'] ) )->diff( new DateTimeImmutable( $end ) )->days > 366 ) return self::fail( 'Invalid or excessive date range.' );
        $purpose = trim( (string) ( $input['purpose'] ?? '' ) );
        if ( ! in_array( $purpose, array_column( $selected, 'purpose' ), true ) ) return self::fail( 'Choose a canonical purpose already present on a reviewed source.' );
        $notes = trim( (string) ( $input['notes'] ?? '' ) );
        if ( strlen( $notes ) > 4000 ) return self::fail( 'Replacement notes are too long.' );
        $target = 'MBS-W' . strtoupper( substr( hash( 'sha256', implode( '|', $refs ) ), 0, 12 ) );
        $plan = array( 'refs' => $refs, 'target_ref' => $target, 'purpose' => $purpose, 'notes' => $notes, 'reason' => $reason, 'rows' => $selected );
        $plan['fingerprint'] = hash( 'sha256', wp_json_encode( array( $plan, $spaces ) ) );
        return $plan;
    }

    public function handle( $request ) {
        if ( ! $this->allowed() ) return self::fail( 'Administrator access to the Stowmarket campsite is required.' );
        global $wpdb;
        $input = $request->get_json_params();
        if ( ! is_array( $input ) || ! in_array( $input['operation'] ?? '', array( 'preview', 'commit' ), true ) ) return self::fail( 'Choose preview or commit.' );
        $table = $wpdb->prefix . MBS_TABLE;
        $locked = false; $transaction = false;
        try {
            if ( $input['operation'] === 'commit' ) {
                if ( ( $input['confirm'] ?? false ) !== true ) throw new RuntimeException( 'Explicit confirmation is required.' );
                if ( (int) $wpdb->get_var( "SELECT GET_LOCK('mbs_campsite_consolidation',10)" ) !== 1 ) throw new RuntimeException( 'Another consolidation is running.' );
                $locked = true;
                foreach ( array( $table, $wpdb->options ) as $t ) if ( strtoupper( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $t ) ) ) !== 'INNODB' ) throw new RuntimeException( 'Transactional storage is required.' );
                if ( $wpdb->query( 'START TRANSACTION' ) === false ) throw new RuntimeException( 'Cannot start transaction.' );
                $transaction = true;
            }
            $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY ref" . ( $transaction ? ' FOR UPDATE' : '' ), ARRAY_A );
            if ( ! is_array( $rows ) || $wpdb->last_error ) throw new RuntimeException( 'Cannot read current reservations.' );
            // Read committed completion directly: do not trust a request-local option cache.
            $refs = $input['refs'] ?? array();
            if ( ! is_array( $refs ) || count( $refs ) !== 6 || count( array_filter( $refs, 'is_string' ) ) !== 6 ) throw new RuntimeException( 'Six reference strings required.' );
            sort( $refs, SORT_STRING );
            $option = 'mbs_reviewed_park_' . substr( hash( 'sha256', implode( '|', $refs ) ), 0, 32 );
            $done = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $option ) );
            if ( $done ) {
                $done = maybe_unserialize( $done );
                if ( ! hash_equals( $done['fingerprint'], (string) ( $input['fingerprint'] ?? '' ) ) ) throw new RuntimeException( 'This source set was already consolidated; use its replacement.' );
                if ( $transaction ) { $wpdb->query( 'ROLLBACK' ); $transaction = false; }
                return rest_ensure_response( array( 'no_op' => true, 'result' => $done['result'] ) );
            }
            $plan = self::plan( $rows, MBS_Bookings::get_spaces(), $input, wp_date( 'Y-m-d' ) );
            if ( is_wp_error( $plan ) ) throw new RuntimeException( $plan->get_error_message() );
            $first = $plan['rows'][0];
            $google = (array) get_option( 'mbs_google_calendar', array() );
            if ( ! empty( $google['enabled'] ) ) throw new RuntimeException( 'Pause Google sync before consolidation.' );
            foreach ( (array) ( $google['links'] ?? array() ) as $link ) if ( in_array( $link['ref'] ?? '', $refs, true ) ) throw new RuntimeException( 'A source has a saved Google link; review that linkage separately.' );
            foreach ( $plan['rows'] as $row ) {
                if ( MBS_Bookings::has_financial_history( $row['ref'] ) || $wpdb->last_error ) throw new RuntimeException( 'A source has financial history or its check failed.' );
                if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'mathlin_mod_requests WHERE booking_ref=%s AND status=%s LIMIT 1', $row['ref'], 'pending' ) ) || $wpdb->last_error ) throw new RuntimeException( 'A source has a pending change request or its check failed.' );
                $like = '%"related_ref":' . $wpdb->esc_like( wp_json_encode( $row['ref'] ) ) . '%';
                if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . MBS_INVOICE_ITEM_TABLE . ' WHERE pricing_snapshot_json LIKE %s LIMIT 1', $like ) ) || $wpdb->last_error ) throw new RuntimeException( 'A source has a linked charge or its check failed.' );
            }
            $date = new DateTimeImmutable( $first['booking_date'] ); $end = new DateTimeImmutable( $first['booking_date_end'] ?: $first['booking_date'] );
            for ( ; $date <= $end; $date = $date->modify( '+1 day' ) ) {
                $day = $date->format( 'Y-m-d' );
                foreach ( array_merge( array( 'Whole Park' ), array_column( $plan['rows'], 'space' ) ) as $area ) if ( MBS_Blocked_Dates::is_blocked( $day, $area ) || $wpdb->last_error ) throw new RuntimeException( 'A blocked date or failed check affects ' . $day );
                $conflicts = wp_list_pluck( MBS_Bookings::check_conflicts( 'Whole Park', $day, $first['start_time'], $first['end_time'], (bool) $first['all_day'] ), 'ref' ); sort( $conflicts, SORT_STRING );
                if ( $conflicts !== $refs ) throw new RuntimeException( 'Unrelated conflict or source coverage difference on ' . $day );
            }
            $summary = array( 'refs' => $refs, 'target_ref' => $plan['target_ref'], 'space' => 'Whole Park', 'date_from' => $first['booking_date'], 'date_to' => $first['booking_date_end'], 'all_day' => (bool) $first['all_day'], 'start_time' => $first['start_time'], 'end_time' => $first['end_time'], 'status' => $first['status'], 'purpose' => $plan['purpose'], 'notes' => $plan['notes'], 'reason' => $plan['reason'], 'fingerprint' => $plan['fingerprint'], 'notifications_sent' => 0, 'google_writes' => 0 );
            if ( $input['operation'] === 'preview' ) return rest_ensure_response( $summary );
            if ( ! hash_equals( $plan['fingerprint'], (string) ( $input['fingerprint'] ?? '' ) ) ) throw new RuntimeException( 'The reviewed sources or settings changed; preview again.' );
            if ( MBS_Bookings::get( $plan['target_ref'] ) ) throw new RuntimeException( 'A replacement already exists without a completion snapshot.' );
            $new = $first; unset( $new['id'] );
            $metadata = json_decode( (string) $first['custom_fields'], true ) ?: array();
            unset( $metadata['_mbs_legacy'], $metadata['_mbs_multiday_sources'] );
            $metadata['_mbs_whole_park_sources'] = $refs;
            $metadata['_mbs_whole_park_batch'] = $option;
            $new['ref'] = $plan['target_ref']; $new['space'] = 'Whole Park'; $new['purpose'] = $plan['purpose']; $new['notes'] = $plan['notes'];
            $new['custom_fields'] = wp_json_encode( $metadata ); $new['modification_token'] = wp_generate_password( 32, false );
            $new['created_at'] = $new['updated_at'] = current_time( 'mysql', true );
            $new['admin_notes'] = 'Reviewed Whole Park consolidation: ' . implode( ', ', $refs ) . '. ' . $plan['reason'] . ' Originals archived with private before-images; no mail or Google changes.';
            if ( $wpdb->insert( $table, $new ) !== 1 ) throw new RuntimeException( 'Cannot save replacement.' );
            foreach ( $plan['rows'] as $source ) {
                $meta = json_decode( (string) $source['custom_fields'], true ) ?: array(); $meta['_mbs_whole_park_replacement'] = $new['ref'];
                if ( $wpdb->update( $table, array( 'status' => 'archived', 'custom_fields' => wp_json_encode( $meta ), 'updated_at' => current_time( 'mysql', true ) ), array( 'ref' => $source['ref'], 'status' => $source['status'] ) ) !== 1 ) throw new RuntimeException( 'Cannot archive a source.' );
            }
            foreach ( $rows as $before ) if ( ! in_array( $before['ref'], $refs, true ) ) {
                $after = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE ref=%s", $before['ref'] ), ARRAY_A );
                if ( $after !== $before ) throw new RuntimeException( 'An excluded booking changed.' );
            }
            for ( $date = new DateTimeImmutable( $first['booking_date'] ); $date <= $end; $date = $date->modify( '+1 day' ) ) {
                if ( MBS_Bookings::check_conflicts( 'Whole Park', $date->format( 'Y-m-d' ), $first['start_time'], $first['end_time'], (bool) $first['all_day'], $new['ref'] ) || $wpdb->last_error ) throw new RuntimeException( 'Replacement conflict verification failed.' );
            }
            $backup = array( 'fingerprint' => $plan['fingerprint'], 'sources' => $plan['rows'], 'actor' => get_current_user_id(), 'result' => $summary );
            if ( $wpdb->insert( $wpdb->options, array( 'option_name' => $option, 'option_value' => maybe_serialize( $backup ), 'autoload' => 'off' ) ) !== 1 ) throw new RuntimeException( 'Cannot save recovery snapshot.' );
            if ( ( $input['dry_run'] ?? false ) === true ) {
                if ( $wpdb->query( 'ROLLBACK' ) === false ) throw new RuntimeException( 'Cannot roll back rehearsal.' );
                $transaction = false; $summary['dry_run'] = true; return rest_ensure_response( $summary );
            }
            if ( $wpdb->query( 'COMMIT' ) === false ) throw new RuntimeException( 'Cannot commit replacement.' );
            $transaction = false;
            wp_cache_delete( $option, 'options' ); wp_cache_delete( 'notoptions', 'options' );
            MBS_Audit_Log::log( $new['ref'], 'whole_park_consolidated', 'Reviewed replacement of ' . implode( ', ', $refs ) . '. ' . $plan['reason'] . ' No customer email or Google write.' );
            return rest_ensure_response( $summary );
        } catch ( Throwable $e ) { if ( $transaction ) $wpdb->query( 'ROLLBACK' ); return self::fail( $e->getMessage() ); }
        finally { if ( $locked ) $wpdb->get_var( "SELECT RELEASE_LOCK('mbs_campsite_consolidation')" ); }
    }
}
