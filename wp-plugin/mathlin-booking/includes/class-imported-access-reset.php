<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Explicit, fingerprinted flag repair. Never sends mail or changes occupancy. */
class MBS_Imported_Access_Reset {
    private static function fail( $message ) { return new WP_Error( 'imported_access_reset', $message ); }

    public static function lineage( $booking, $resolver, $depth = 0 ) {
        if ( $depth > 3 ) return array();
        $meta = json_decode( (string) ( $booking->custom_fields ?? '' ), true ) ?: array();
        $imported = ( $meta['_mbs_legacy']['plugin'] ?? '' ) === 'wpbc';
        $sources = $meta['_mbs_whole_park_sources'] ?? $meta['_mbs_multiday_sources'] ?? array();
        $lineage = array( $booking->ref => $booking );
        if ( is_array( $sources ) && $sources ) {
            foreach ( $sources as $ref ) {
                if ( ! is_string( $ref ) ) return array();
                if ( $ref === $booking->ref ) continue;
                $source = call_user_func( $resolver, $ref );
                $ancestors = $source ? self::lineage( $source, $resolver, $depth + 1 ) : array();
                if ( ! $ancestors ) return array();
                $lineage += $ancestors;
            }
            $imported = true;
        }
        return $imported ? $lineage : array();
    }

    /** Pure reviewed selection; genuine prior delivery/queue evidence is protected. */
    public static function plan( $rows, $protected, $today, $resolver ) {
        $eligible = array(); $skipped = array();
        foreach ( $rows as $booking ) {
            $reason = '';
            $lineage = self::lineage( $booking, $resolver );
            if ( $booking->booking_date <= $today ) $reason = 'not_future';
            elseif ( ! in_array( $booking->status, array( 'confirmed','deposit_paid','paid' ), true ) ) $reason = 'not_approved';
            elseif ( MBS_Access_Details::is_estate_booking( $booking ) ) $reason = 'estate_booking';
            elseif ( ! $lineage ) $reason = 'not_verified_import';
            elseif ( empty( $booking->access_sent ) ) $reason = 'already_unsent';
            else foreach ( $lineage as $ref => $source ) if ( ! empty( $protected[$ref] ) ) { $reason = 'access_delivery_or_queue_exists'; break; }
            if ( $reason ) $skipped[$booking->ref] = $reason;
            else $eligible[] = $booking->ref;
        }
        sort( $eligible, SORT_STRING ); ksort( $skipped, SORT_STRING );
        return array( 'refs' => $eligible, 'count' => count( $eligible ), 'skipped' => $skipped,
            'fingerprint' => hash( 'sha256', wp_json_encode( array( $today, $rows, $protected, $eligible, $skipped ) ) ) );
    }

    public static function handle( $input ) {
        if ( ! current_user_can( 'manage_options' ) || ! MBS_Campsite_Policy::enabled() ) return self::fail( 'Administrator campsite access required.' );
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'operation','refs','fingerprint','confirm' ) ) ) return self::fail( 'Unknown repair input.' );
        $operation = $input['operation'] ?? 'preview'; $refs = $input['refs'] ?? array();
        if ( ! in_array( $operation, array( 'preview','commit' ), true ) || ! is_array( $refs ) || ! $refs || count( $refs ) > 200 ) return self::fail( 'Select 1–200 explicit booking references.' );
        foreach ( $refs as $ref ) if ( ! is_string( $ref ) || ! preg_match( '/^MBS-[A-Z0-9-]{1,60}$/D', $ref ) ) return self::fail( 'Invalid booking reference.' );
        if ( count( $refs ) !== count( array_unique( $refs ) ) ) return self::fail( 'Duplicate references.' );
        sort( $refs, SORT_STRING );
        if ( $operation === 'commit' && ( ( $input['confirm'] ?? null ) !== true || ! is_string( $input['fingerprint'] ?? null ) ) ) return self::fail( 'Commit requires explicit confirmation and preview fingerprint.' );
        return MBS_Space_Selection::locked( static function() use ( $input, $operation, $refs ) {
            global $wpdb;
            if ( $wpdb->query( 'START TRANSACTION' ) === false ) return self::fail( 'Could not start repair transaction.' );
            try {
                $placeholders = implode( ',', array_fill( 0, count( $refs ), '%s' ) );
                $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . MBS_TABLE . " WHERE ref IN ($placeholders) ORDER BY ref FOR UPDATE", $refs ) );
                if ( $wpdb->last_error || ! is_array( $rows ) || count( $rows ) !== count( $refs ) ) throw new RuntimeException( 'A selected record is missing or unreadable.' );
                $resolver = static function( $ref ) { return MBS_Bookings::get( $ref ); };
                $ancestors = array(); foreach ( $rows as $row ) $ancestors += self::lineage( $row, $resolver );
                $protected = array();
                foreach ( $ancestors as $ref => $row ) {
                    $audit = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . "mathlin_audit_log WHERE ref=%s AND action IN ('access_sent','access_resent')", $ref ) );
                    if ( $wpdb->last_error ) throw new RuntimeException( 'Access audit evidence could not be read.' );
                    $queue = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . "mathlin_email_queue WHERE (reference_type='booking' AND reference_id=%d AND message_type IN ('access_details','access_manual')) OR (subject LIKE %s AND subject LIKE '%%Access%%')", $row->id, '%' . $wpdb->esc_like( $ref ) . '%' ) );
                    if ( $wpdb->last_error ) throw new RuntimeException( 'Access queue evidence could not be read.' );
                    $protected[$ref] = (int) $audit > 0 || (int) $queue > 0;
                }
                ksort( $protected, SORT_STRING );
                $plan = self::plan( $rows, $protected, wp_date( 'Y-m-d' ), $resolver );
                if ( $operation === 'commit' ) {
                    if ( ! hash_equals( $plan['fingerprint'], $input['fingerprint'] ) ) throw new RuntimeException( 'Source or delivery evidence changed; preview again.' );
                    foreach ( $plan['refs'] as $ref ) {
                        if ( $wpdb->update( $wpdb->prefix . MBS_TABLE, array( 'access_sent' => 0 ), array( 'ref' => $ref, 'access_sent' => 1 ) ) !== 1 ) throw new RuntimeException( 'A selected flag changed during repair.' );
                        if ( ! MBS_Audit_Log::log( $ref, 'imported_access_reset', 'Reviewed migration-set access flag cleared for future approved Scout booking. No immediate email; normal day-before eligibility applies.' ) ) throw new RuntimeException( 'Repair audit could not be recorded.' );
                    }
                }
                if ( $wpdb->query( 'COMMIT' ) === false ) throw new RuntimeException( 'Repair transaction could not commit.' );
                $plan['changed'] = $operation === 'commit' ? $plan['count'] : 0;
                return $plan;
            } catch ( Throwable $error ) {
                $wpdb->query( 'ROLLBACK' );
                return self::fail( $error->getMessage() );
            }
        } );
    }
}
