<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Booking Modification & Cancellation Requests
 *
 * Stores requests in wp_mathlin_mod_requests table.
 * Admin sees a queue with Approve/Reject buttons.
 * Approve auto-applies changes (or cancels) and emails the booker.
 * Reject sends a "sorry" email.
 */
class MBS_Modification {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'mathlin_mod_requests';
    }

    public function init() {
        add_action( 'wp_ajax_nopriv_mbs_submit_modification', array( $this, 'ajax_submit' ) );
        add_action( 'wp_ajax_mbs_submit_modification',        array( $this, 'ajax_submit' ) );
    }

    // ── Token & URL ────────────────────────────────────────────────────────────

    public static function get_modification_url( $booking ) {
        $token = $booking->modification_token;
        if ( empty( $token ) ) {
            $token = wp_generate_password( 32, false );
            global $wpdb;
            $wpdb->update( $wpdb->prefix . MBS_TABLE, array( 'modification_token' => $token ), array( 'ref' => $booking->ref ) );
        }
        $base_url = home_url();
        $pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 's' => 'mathlin_status', 'numberposts' => 1 ) );
        if ( ! empty( $pages ) ) $base_url = get_permalink( $pages[0]->ID );
        return add_query_arg( array( 'mbs_modify' => '1', 'ref' => $booking->ref, 'token' => $token ), $base_url );
    }

    public static function verify_token( $ref, $token ) {
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking || empty( $booking->modification_token ) ) return false;
        return hash_equals( $booking->modification_token, $token );
    }

    // ── CRUD ───────────────────────────────────────────────────────────────────

    public static function create_request( $data ) {
        global $wpdb;
        return $wpdb->insert( self::table(), array(
            'booking_ref'    => sanitize_text_field( $data['ref'] ),
            'request_type'   => sanitize_text_field( $data['type'] ), // 'modify' or 'cancel'
            'status'         => 'pending',
            'requested_data' => wp_json_encode( $data['changes'] ?? array() ),
            'notes'          => sanitize_textarea_field( $data['notes'] ?? '' ),
            'created_at'     => current_time( 'mysql' ),
        ) );
    }

    public static function get_pending() {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results(
            "SELECT r.*, b.name, b.email, b.space, b.booking_date, b.start_time, b.end_time, b.amount, b.status as booking_status
             FROM {$table} r
             LEFT JOIN {$wpdb->prefix}" . MBS_TABLE . " b ON r.booking_ref = b.ref
             WHERE r.status = 'pending'
             ORDER BY r.created_at DESC"
        );
    }

    public static function get_all_requests( $limit = 50 ) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, b.name, b.email, b.space, b.booking_date, b.amount, b.status as booking_status
             FROM {$table} r
             LEFT JOIN {$wpdb->prefix}" . MBS_TABLE . " b ON r.booking_ref = b.ref
             ORDER BY r.created_at DESC LIMIT %d",
            $limit
        ) );
    }

    public static function get_request( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d", $id ) );
    }

    public static function get_pending_count() {
        global $wpdb;
        $table = self::table();
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) return 0;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
    }

    public static function update_request_status( $id, $status, $admin_response = '' ) {
        global $wpdb;
        return $wpdb->update( self::table(), array(
            'status'         => $status,
            'admin_response' => sanitize_textarea_field( $admin_response ),
            'resolved_at'    => current_time( 'mysql' ),
            'resolved_by'    => get_current_user_id(),
        ), array( 'id' => $id ) );
    }

    // ── Approve ────────────────────────────────────────────────────────────────

    public static function approve( $request_id ) {
        if ( class_exists( 'MBS_Space_Selection' ) ) return MBS_Space_Selection::locked( static function() use ( $request_id ) { return self::approve_locked( $request_id ); } );
        return self::approve_locked( $request_id );
    }

    private static function approve_locked( $request_id ) {
        $request = self::get_request( $request_id );
        if ( ! $request || $request->status !== 'pending' ) return false;
        if ( MBS_Series_Request::is_series_request( $request ) ) {
            return new WP_Error( 'series_review_required', 'Review and apply this request using the series controls, then record the outcome in Change Requests. No automatic changes have been applied.' );
        }

	        $booking = MBS_Bookings::get( $request->booking_ref );
	        if ( ! $booking ) return false;
            if ( ! empty( $booking->series_id ) && MBS_Series::get( $booking->series_id ) ) {
                return new WP_Error( 'canonical_series_required', 'This is a recurring-series occurrence. Review it through the series workflow rather than applying an individual-booking request.' );
            }
	        $changes = $request->request_type === 'modify' ? ( json_decode( $request->requested_data, true ) ?: array() ) : array();
	        $financial_fields = array( 'space', 'date', 'date_end', 'start_time', 'end_time', 'kitchen', 'booking_type' );
	        $financial_change = (bool) array_intersect( $financial_fields, array_keys( $changes ) );
	        if ( MBS_Bookings::has_financial_history( $request->booking_ref ) && ( $request->request_type === 'cancel' || $financial_change ) ) {
            return new WP_Error( 'billed_occurrence_immutable', 'This request cannot be applied because the occurrence has financial history. Use the credit-and-replace workflow.' );
        }

        if ( $request->request_type === 'cancel' ) {
            $saved=MBS_Booking_Change_Service::apply($booking,array('status'=>'cancelled','access_sent'=>0),array('request'=>$request,'financial_change'=>true,'notify'=>'request_cancel','action'=>'cancelled','audit'=>'Cancellation request approved by admin; cancellation notice queued after commit.'));
            return is_wp_error($saved)?$saved:true;
        } else {
            // Approve modification — apply the requested changes
	            if ( ! empty( $changes ) ) {
                global $wpdb;
                $table  = $wpdb->prefix . MBS_TABLE;
                $update = array();

                if ( ! empty( $changes['space'] ) )        $update['space']        = sanitize_text_field( $changes['space'] );
                if ( ! empty( $changes['date'] ) )         $update['booking_date'] = sanitize_text_field( $changes['date'] );
                if ( ! empty( $changes['date_end'] ) )     $update['booking_date_end'] = sanitize_text_field( $changes['date_end'] );
                if ( ! empty( $changes['start_time'] ) )   $update['start_time']   = sanitize_text_field( $changes['start_time'] );
                if ( ! empty( $changes['end_time'] ) )     $update['end_time']     = sanitize_text_field( $changes['end_time'] );
                if ( isset( $changes['kitchen'] ) )        $update['kitchen']      = (int) $changes['kitchen'];
                if ( isset( $changes['attendees'] ) )      $update['attendees']    = absint( $changes['attendees'] );
                if ( isset( $changes['booking_type'] ) ) {
                    $update['all_day'] = $changes['booking_type'] === 'fullday' ? 1 : 0;
                }

                if ( ! empty( $update ) ) {
                    $update = MBS_Booking_Validation::delivery_update( $booking, $update );
                    if ( class_exists( 'MBS_Space_Selection' ) && isset( $update['space'] ) && $update['space'] !== $booking->space ) {
                        $selection = MBS_Space_Selection::normalize( $update['space'] );
                        if ( is_wp_error( $selection ) ) return $selection; $update['space'] = $selection;
                    }
                    if ( class_exists( 'MBS_Campsite_Policy' ) ) {
                        $valid = MBS_Campsite_Policy::validate_amendment( $booking, $update );
                        if ( is_wp_error( $valid ) ) return $valid;
                    }
                    // QA-004: Check for conflicts before applying changes
                    $check_space = $update['space'] ?? $booking->space;
                    $check_date  = $update['booking_date'] ?? $booking->booking_date;
                    $check_start = $update['start_time'] ?? $booking->start_time;
                    $check_end   = $update['end_time'] ?? $booking->end_time;
                    $check_allday = isset( $update['all_day'] ) ? (bool) $update['all_day'] : (bool) $booking->all_day;

                    if ( $check_space !== $booking->space || $check_date !== $booking->booking_date ||
                         $check_start !== $booking->start_time || $check_end !== $booking->end_time ) {
                        $conflicts = MBS_Bookings::check_conflicts(
                            $check_space, $check_date,
                            $check_allday ? null : $check_start,
                            $check_allday ? null : $check_end,
                            $check_allday, $request->booking_ref
                        );
                        if ( ! empty( $conflicts ) ) {
                            self::update_request_status( $request_id, 'rejected', 'Conflicts with existing booking: ' . MBS_Bookings::format_conflict_message( $conflicts ) );
                            self::notify_booker_rejected( $booking, 'modify', 'Your requested changes conflict with an existing booking.' );
                            MBS_Audit_Log::log( $request->booking_ref, 'modification_rejected', 'Auto-rejected: conflicts with existing booking' );
                            return false;
                        }

                        // Enforce minimum booking duration on the modified times,
                        // alongside the conflict check. A change request must not
                        // shorten a booking below the configured minimum.
                        $mod_date_to  = $update['booking_date_end'] ?? $booking->booking_date_end ?? $check_date;
                        $mod_num_days = max( 1, (int) round( ( strtotime( $mod_date_to ) - strtotime( $check_date ) ) / 86400 ) + 1 );
                        $dur_check    = MBS_Bookings::validate_min_duration(
                            $check_start, $check_end, $check_allday, $mod_num_days, (bool) $booking->scout_use
                        );
                        if ( is_wp_error( $dur_check ) ) {
                            self::update_request_status( $request_id, 'rejected', $dur_check->get_error_message() );
                            self::notify_booker_rejected( $booking, 'modify', $dur_check->get_error_message() );
                            MBS_Audit_Log::log( $request->booking_ref, 'modification_rejected', 'Auto-rejected: below minimum booking duration' );
                            return false;
                        }
                    }

	                    if ( $financial_change ) {
	                    // Recalculate cost
                    $space     = $update['space'] ?? $booking->space;
                    $start     = $update['start_time'] ?? $booking->start_time;
                    $end       = $update['end_time'] ?? $booking->end_time;
                    $kitchen   = isset( $update['kitchen'] ) ? $update['kitchen'] : $booking->kitchen;
                    $all_day   = isset( $update['all_day'] ) ? $update['all_day'] : $booking->all_day;
                    $date_from = $update['booking_date'] ?? $booking->booking_date;
                    $date_to   = $update['booking_date_end'] ?? $booking->booking_date_end ?? $date_from;
                    $num_days  = max( 1, (int) round( ( strtotime( $date_to ) - strtotime( $date_from ) ) / 86400 ) + 1 );

                    $new_amount = MBS_Bookings::calculate_cost( $space, $start, $end, (bool) $kitchen, (bool) $all_day, $num_days, (bool) $booking->scout_use, MBS_Bookings::get_booking_tier( $booking ) );
                    $update['amount'] = $new_amount;

                    // Auto-confirm: determine correct status based on amount_paid
                    // Do NOT change amount_paid — it stays at what was actually received
                    $amount_paid = (float) ( $booking->amount_paid ?? 0 );

                    if ( $amount_paid >= (float) $new_amount ) {
                        $update['status'] = 'paid';
                    } elseif ( $amount_paid > 0 && $amount_paid < (float) $new_amount ) {
                        $update['status'] = 'confirmed';
                    } else {
                        $update['status'] = 'confirmed';
                    }

	                    }

	                    // ── MATERIAL modification: one atomic transaction ───────────────
	                    // Includes: lock request → lock booking → apply changes → R2 →
	                    // audit → outbox → mark request approved → COMMIT
	                    if ( $financial_change && ! empty( $booking->current_invoice_document_id ) ) {
	                        $logo_ref = MBS_Logo_Asset::resolve_current_org_logo();
	                        $mod_table = self::table();

	                        if ( $wpdb->query( 'START TRANSACTION' ) === false ) {
	                            return new WP_Error( 'transaction_start_failed', 'Could not start the modification transaction.' );
	                        }

	                        // Lock modification request
	                        $locked_request = $wpdb->get_row( $wpdb->prepare(
	                            "SELECT * FROM {$mod_table} WHERE id = %d FOR UPDATE",
	                            (int) $request_id
	                        ) );
	                        if ( ! $locked_request || $locked_request->status !== 'pending' ) {
	                            $wpdb->query( 'ROLLBACK' );
	                            return new WP_Error( 'request_already_resolved', 'Modification request is no longer pending.' );
	                        }

	                        // Lock booking row and revalidate
	                        $locked_booking = $wpdb->get_row( $wpdb->prepare(
	                            "SELECT * FROM {$table} WHERE ref = %s FOR UPDATE",
	                            $request->booking_ref
	                        ) );
	                        if ( ! $locked_booking ) {
	                            $wpdb->query( 'ROLLBACK' );
	                            return new WP_Error( 'booking_lock_failed', 'Could not lock the booking for modification.' );
	                        }

	                        // Apply material booking changes
	                        // Re-derive all preconditions from LOCKED state
	                        $lk_changes = json_decode( $locked_request->requested_data, true ) ?: array();
	                        $update = array();
	                        if ( ! empty( $lk_changes['space'] ) )      $update['space']            = sanitize_text_field( $lk_changes['space'] );
	                        if ( ! empty( $lk_changes['date'] ) )       $update['booking_date']     = sanitize_text_field( $lk_changes['date'] );
	                        if ( ! empty( $lk_changes['date_end'] ) )   $update['booking_date_end'] = sanitize_text_field( $lk_changes['date_end'] );
	                        if ( ! empty( $lk_changes['start_time'] ) ) $update['start_time']       = sanitize_text_field( $lk_changes['start_time'] );
	                        if ( ! empty( $lk_changes['end_time'] ) )   $update['end_time']         = sanitize_text_field( $lk_changes['end_time'] );
	                        if ( isset( $lk_changes['kitchen'] ) )      $update['kitchen']          = (int) $lk_changes['kitchen'];
	                        if ( isset( $lk_changes['attendees'] ) )    $update['attendees']        = absint( $lk_changes['attendees'] );
	                        if ( isset( $lk_changes['booking_type'] ) ) $update['all_day']          = $lk_changes['booking_type'] === 'fullday' ? 1 : 0;
                            $update = MBS_Booking_Validation::delivery_update( $locked_booking, $update );

	                        if ( MBS_Bookings::has_financial_history( $locked_booking->ref ) ) {
	                            $wpdb->query( 'ROLLBACK' );
	                            return new WP_Error( 'billed_occurrence_immutable', 'Financial history appeared after lock.' );
	                        }

	                        $ck_space = $update['space'] ?? $locked_booking->space;
	                        $ck_date  = $update['booking_date'] ?? $locked_booking->booking_date;
	                        $ck_start = $update['start_time'] ?? $locked_booking->start_time;
	                        $ck_end   = $update['end_time'] ?? $locked_booking->end_time;
	                        $ck_allday = isset( $update['all_day'] ) ? (bool) $update['all_day'] : (bool) $locked_booking->all_day;
	                        if ( $ck_space !== $locked_booking->space || $ck_date !== $locked_booking->booking_date || $ck_start !== $locked_booking->start_time || $ck_end !== $locked_booking->end_time ) {
	                            $conflicts = MBS_Bookings::check_conflicts( $ck_space, $ck_date, $ck_allday ? null : $ck_start, $ck_allday ? null : $ck_end, $ck_allday, $locked_booking->ref );
	                            if ( ! empty( $conflicts ) ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'conflict_on_locked_state', 'Conflict detected after locking.' ); }
	                            $md_to = $update['booking_date_end'] ?? $locked_booking->booking_date_end ?? $ck_date;
	                            $md_days = max( 1, (int) round( ( strtotime( $md_to ) - strtotime( $ck_date ) ) / 86400 ) + 1 );
	                            $dur = MBS_Bookings::validate_min_duration( $ck_start, $ck_end, $ck_allday, $md_days, (bool) $locked_booking->scout_use );
	                            if ( is_wp_error( $dur ) ) { $wpdb->query( 'ROLLBACK' ); return $dur; }
	                        }

                            if ( class_exists( 'MBS_Campsite_Policy' ) ) {
                                $valid = MBS_Campsite_Policy::validate_amendment( $locked_booking, $update );
                                if ( is_wp_error( $valid ) ) { $wpdb->query( 'ROLLBACK' ); return $valid; }
                            }
	                        // Recalculate amount from locked state
	                        $rc_space = $update['space'] ?? $locked_booking->space;
	                        $rc_start = $update['start_time'] ?? $locked_booking->start_time;
	                        $rc_end   = $update['end_time'] ?? $locked_booking->end_time;
	                        $rc_kitchen = isset( $update['kitchen'] ) ? $update['kitchen'] : $locked_booking->kitchen;
	                        $rc_allday  = isset( $update['all_day'] ) ? $update['all_day'] : $locked_booking->all_day;
	                        $rc_from = $update['booking_date'] ?? $locked_booking->booking_date;
	                        $rc_to   = $update['booking_date_end'] ?? $locked_booking->booking_date_end ?? $rc_from;
	                        $rc_days = max( 1, (int) round( ( strtotime( $rc_to ) - strtotime( $rc_from ) ) / 86400 ) + 1 );
	                        $new_amount = MBS_Bookings::calculate_cost( $rc_space, $rc_start, $rc_end, (bool) $rc_kitchen, (bool) $rc_allday, $rc_days, (bool) $locked_booking->scout_use, MBS_Bookings::get_booking_tier( $locked_booking ) );
	                        $update['amount'] = $new_amount;
                            $update = MBS_Bookings::with_pricing_evidence( $locked_booking, $update );
	                        $lk_paid = (float) ( $locked_booking->amount_paid ?? 0 );
	                        $update['status'] = ( class_exists( 'MBS_Campsite_Policy' ) && MBS_Campsite_Policy::enabled() ) ? $locked_booking->status : ( ( $lk_paid >= (float) $new_amount ) ? 'paid' : 'confirmed' );

	                        $update = MBS_Booking_Validation::delivery_update( $locked_booking, $update );
	                        if ( class_exists( 'MBS_Space_Selection' ) && isset( $update['space'] ) ) MBS_Space_Selection::record_selection( $update['space'] );
	                        $booking_updated = $wpdb->update( $table, $update, array( 'ref' => $request->booking_ref ) );
	                        if ( $booking_updated === false ) {
	                            $wpdb->query( 'ROLLBACK' );
	                            return new WP_Error( 'modification_update_failed', 'The booking change could not be saved.' );
	                        }

	                        // Re-read updated row for snapshot
	                        $updated_locked = $wpdb->get_row( $wpdb->prepare(
	                            "SELECT * FROM {$table} WHERE ref = %s", $request->booking_ref
	                        ) );

	                        // Create R2
	                        $r2_id = MBS_Invoice_Document_Service::reissue_booking_document_within_transaction( $updated_locked, $logo_ref );
	                        if ( is_wp_error( $r2_id ) ) {
	                            $wpdb->query( 'ROLLBACK' );
	                            return $r2_id;
	                        }

	                        // Audit
	                        $audit_msg = sprintf( 'Modification approved: status set to %s (was %s, cost %s → %s)',
	                            $update['status'] ?? $locked_booking->status, $locked_booking->status,
	                            '£' . number_format( (float) $locked_booking->amount, 2 ),
	                            '£' . number_format( (float) ( $update['amount'] ?? $locked_booking->amount ), 2 )
	                        );
	                        $audit_ok = MBS_Audit_Log::log( $request->booking_ref, 'modification_approved', $audit_msg );
	                        if ( ! $audit_ok ) {
	                            $wpdb->query( 'ROLLBACK' );
	                            return new WP_Error( 'audit_failed', 'Could not record modification audit.' );
	                        }

	                        // Enqueue revised-invoice email referencing the actual R2 document
	                        $org = class_exists( 'MBS_Email_Templates' ) ? MBS_Email_Templates::get_org_settings() : array( 'name' => get_bloginfo( 'name' ) );
	                        $admin_email = MBS_Bookings::get_admin_email();
	                        $subject = 'Booking Updated — ' . $request->booking_ref;
	                        $body = '<p>Your booking ' . esc_html( $request->booking_ref ) . ' has been updated. A revised invoice is attached.</p>';
	                        $headers = array(
	                            'Content-Type: text/html; charset=UTF-8',
	                            'From: ' . ( $org['name'] ?? get_bloginfo( 'name' ) ) . ' <' . get_option( 'admin_email' ) . '>',
	                            'Reply-To: ' . $admin_email,
	                        );
	                        $attachment_meta = array( 'document_id' => (int) $r2_id, 'format' => 'pdf' );
	                        $message_key = 'modification_approved:' . $request->booking_ref . ':doc' . $r2_id;
	                        $payload_hash = MBS_Email_Queue::compute_payload_hash( $updated_locked->email, $subject, $body, $headers, $attachment_meta );

	                        $enqueued = MBS_Email_Queue::enqueue(
	                            $updated_locked->email, $subject, $body, $headers,
	                            $message_key, $payload_hash, $attachment_meta,
	                            array( 'message_type' => 'modification_approved', 'reference_type' => 'booking', 'reference_id' => (int) $updated_locked->id )
	                        );
	                        if ( is_wp_error( $enqueued ) ) {
	                            $wpdb->query( 'ROLLBACK' );
	                            return new WP_Error( 'outbox_failed', 'Could not queue the revised invoice email.' );
	                        }

	                        // Mark request approved (inside transaction)
	                        $req_updated = $wpdb->update( $mod_table, array(
	                            'status' => 'approved',
	                            'resolved_at' => current_time( 'mysql' ),
	                            'resolved_by' => get_current_user_id(),
	                        ), array( 'id' => (int) $request_id, 'status' => 'pending' ) );
	                        if ( $req_updated !== 1 ) {
	                            $wpdb->query( 'ROLLBACK' );
	                            return new WP_Error( 'request_status_failed', 'Could not mark the modification request as approved.' );
	                        }

                            if ( method_exists('MBS_HomeAssistant','stage_within_transaction') ) {
                                $ha = MBS_HomeAssistant::stage_within_transaction($updated_locked);
                                if(is_wp_error($ha)){ $wpdb->query('ROLLBACK'); return $ha; }
                            }
	                        if ( $wpdb->query( 'COMMIT' ) === false ) {
	                            $wpdb->query( 'ROLLBACK' );
	                            return new WP_Error( 'commit_failed', 'Could not commit the modification.' );
	                        }

	                        // Post-commit: non-critical HA notification
	                        $updated_booking = MBS_Bookings::get( $request->booking_ref );
	                        if ( $updated_booking && ! method_exists('MBS_HomeAssistant','stage_within_transaction') ) {
	                            MBS_HomeAssistant::notify( $updated_booking );
	                        }
                            if(method_exists('MBS_Email_Queue','wake'))MBS_Email_Queue::wake();
	                        return true;

	                    } else {
                            if($financial_change)$update=MBS_Bookings::with_pricing_evidence($booking,$update);
                            $saved=MBS_Booking_Change_Service::apply($booking,$update,array('request'=>$request,'financial_change'=>$financial_change,'notify'=>'request_change','action'=>'modification_approved','audit'=>'Approved booking change without rewriting issued financial history.'));
                            return is_wp_error($saved)?$saved:true;
	                    }
                }
            }

            // Non-material notification (no R2, no invoice attachment)
            $updated_booking = MBS_Bookings::get( $request->booking_ref );
            self::notify_booker_approved_simple( $updated_booking );
            MBS_Audit_Log::log( $request->booking_ref, 'edited', 'Modification request approved and applied by admin' );
        }

        if ( self::update_request_status( $request_id, 'approved' ) === false ) {
            return new WP_Error( 'modification_status_update_failed', 'The booking changed, but the request could not be marked approved.' );
        }
        return true;
    }

    // ── Reject ─────────────────────────────────────────────────────────────────

    public static function reject( $request_id, $reason = '' ) {
        $request = self::get_request( $request_id );
        if ( ! $request || $request->status !== 'pending' ) return false;
        if ( MBS_Series_Request::is_series_request( $request ) ) {
            return MBS_Series_Request::resolve( $request_id, $reason ?: 'We are unable to accept this request. Please contact the venue to discuss alternatives.', 'rejected' );
        }

        $booking = MBS_Bookings::get( $request->booking_ref );
        if ( ! $booking ) return false;

        self::update_request_status( $request_id, 'rejected', $reason );
        self::notify_booker_rejected( $booking, $request->request_type, $reason );
        MBS_Audit_Log::log( $request->booking_ref, 'modification_rejected', 'Request rejected by admin' . ( $reason ? ': ' . $reason : '' ) );
        return true;
    }

    // ── Public form submission ──────────────────────────────────────────────────

    public function ajax_submit() {
        check_ajax_referer( 'mbs_public_nonce', 'nonce' );

        $ref   = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $token = sanitize_text_field( $_POST['token'] ?? '' );

        if ( ! self::verify_token( $ref, $token ) ) {
            wp_send_json_error( array( 'message' => 'Invalid or expired modification link.' ) );
        }

        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking || in_array( $booking->status, array( 'cancelled', 'archived' ) ) ) {
            wp_send_json_error( array( 'message' => 'This booking cannot be modified.' ) );
        }
        if ( ! empty( $booking->series_id ) && MBS_Series::get( $booking->series_id ) ) {
            wp_send_json_error( array( 'message' => 'Please sign in to Manage Bookings to request changes to a recurring series, or contact the venue.' ), 400 );
        }

        $mod_action = sanitize_text_field( $_POST['mod_action'] ?? 'modify' );

        if ( $mod_action === 'cancel' ) {
            self::create_request( array(
                'ref'     => $ref,
                'type'    => 'cancel',
                'notes'   => sanitize_textarea_field( $_POST['cancel_reason'] ?? '' ),
                'changes' => array(),
            ) );
            MBS_Audit_Log::log( $ref, 'cancellation_requested', 'Booker requested cancellation', 0 );
        } else {
            $changes = array(
                'space'        => sanitize_text_field( $_POST['new_space'] ?? '' ),
                'date'         => sanitize_text_field( $_POST['new_date'] ?? '' ),
                'date_end'     => sanitize_text_field( $_POST['new_date_end'] ?? '' ),
                'start_time'   => sanitize_text_field( $_POST['new_start_time'] ?? '' ),
                'end_time'     => sanitize_text_field( $_POST['new_end_time'] ?? '' ),
                'booking_type' => sanitize_text_field( $_POST['new_booking_type'] ?? '' ),
                'kitchen'      => sanitize_text_field( $_POST['new_kitchen'] ?? '' ),
                'attendees'    => sanitize_text_field( $_POST['new_attendees'] ?? '' ),
            );
            self::create_request( array(
                'ref'     => $ref,
                'type'    => 'modify',
                'notes'   => sanitize_textarea_field( $_POST['changes'] ?? '' ),
                'changes' => $changes,
            ) );
            MBS_Audit_Log::log( $ref, 'modification_requested', 'Booker requested changes', 0 );
        }

        // Notify admin
        self::notify_admin_of_request( $booking, $mod_action );

        $msg = $mod_action === 'cancel'
            ? 'Your cancellation request has been submitted. We\'ll review it and get back to you shortly.'
            : 'Your modification request has been submitted. We\'ll review it and get back to you shortly.';

        wp_send_json_success( array( 'message' => $msg ) );
    }

    // ── Emails ─────────────────────────────────────────────────────────────────

    private static function notify_admin_of_request( $booking, $type ) {
        $tpl       = MBS_Email_Templates::get_template( 'admin_mod_request' );
        $subject   = MBS_Email_Templates::replace_placeholders( $tpl['subject'], $booking );
        $body_text = MBS_Email_Templates::replace_placeholders( $tpl['body'], $booking );

        $admin_email = MBS_Bookings::get_admin_email();
        $org         = MBS_Email_Templates::get_org_settings();
        $logo        = MBS_Email_Templates::get_logo_html();
        $pending     = self::get_pending_count();

        $body  = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:0 auto;">';
        $body .= '<div style="background:#f39c12;padding:24px 32px;border-radius:8px 8px 0 0;text-align:center;">' . $logo;
        $body .= '<h1 style="color:#fff;margin:8px 0 0;font-size:20px;">' . esc_html( $org['name'] ) . '</h1></div>';
        $body .= '<div style="background:#fff;padding:32px;border:1px solid #e0d0f0;border-top:none;border-radius:0 0 8px 8px;">';
        $body .= '<h2 style="color:#f39c12;">Change Request</h2>';
        $body .= nl2br( esc_html( $body_text ) );
        $body .= '<p style="margin-top:12px;">You have <strong>' . $pending . ' pending request(s)</strong>.</p>';
        $body .= '<p style="margin-top:24px;"><a href="' . admin_url( 'admin.php?page=mathlin-requests' ) . '" style="background:#7413DC;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;">Review Requests</a></p>';
        $body .= '</div></body></html>';

        MBS_Email_Queue::send( $admin_email, $subject, $body, array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $org['name'] . ' <' . get_option( 'admin_email', $admin_email ) . '>',
            'Reply-To: ' . $admin_email,
        ) );
    }

    /**
     * Simple notification for non-material modifications (no invoice attachment).
     */
    private static function notify_booker_approved_simple( $booking ) {
        if(!$booking)return;
        $message=self::build_booker_approved_simple($booking);
        MBS_Email_Queue::send($booking->email,$message['subject'],$message['body'],$message['headers']);
    }
    public static function build_booker_approved_simple( $booking ) {
        if ( ! $booking ) return;
        $tpl       = MBS_Email_Templates::get_template( 'modification_approved' );
        $subject   = MBS_Email_Templates::replace_placeholders( $tpl['subject'], $booking );
        $body_text = MBS_Email_Templates::replace_placeholders( $tpl['body'], $booking );

        $org         = MBS_Email_Templates::get_org_settings();
        $admin_email = MBS_Bookings::get_admin_email();
        $logo        = MBS_Email_Templates::get_logo_html();

        $body  = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:0 auto;">';
        $body .= '<div style="background:#2ecc71;padding:24px 32px;border-radius:8px 8px 0 0;text-align:center;">' . $logo;
        $body .= '<h1 style="color:#fff;margin:8px 0 0;font-size:20px;">' . esc_html( $org['name'] ) . '</h1></div>';
        $body .= '<div style="background:#fff;padding:32px;border:1px solid #e0d0f0;border-top:none;border-radius:0 0 8px 8px;">';
        $body .= '<h2 style="color:#2ecc71;">Change Approved</h2>';
        $body .= nl2br( esc_html( $body_text ) );
        $body .= '</div></body></html>';

        return array('subject'=>$subject,'body'=>$body,'headers'=>array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $org['name'] . ' <' . get_option( 'admin_email', $admin_email ) . '>',
            'Reply-To: ' . $admin_email,
        ) );
    }

    private static function notify_booker_rejected( $booking, $type, $reason ) {
        $tpl       = MBS_Email_Templates::get_template( 'modification_rejected' );
        $extra     = array( '{reason}' => $reason ? 'Reason: ' . $reason : '' );
        $subject   = MBS_Email_Templates::replace_placeholders( $tpl['subject'], $booking, $extra );
        $body_text = MBS_Email_Templates::replace_placeholders( $tpl['body'], $booking, $extra );

        $org         = MBS_Email_Templates::get_org_settings();
        $admin_email = MBS_Bookings::get_admin_email();
        $logo        = MBS_Email_Templates::get_logo_html();

        $body  = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:0 auto;">';
        $body .= '<div style="background:#e74c3c;padding:24px 32px;border-radius:8px 8px 0 0;text-align:center;">' . $logo;
        $body .= '<h1 style="color:#fff;margin:8px 0 0;font-size:20px;">' . esc_html( $org['name'] ) . '</h1></div>';
        $body .= '<div style="background:#fff;padding:32px;border:1px solid #e0d0f0;border-top:none;border-radius:0 0 8px 8px;">';
        $body .= '<h2 style="color:#e74c3c;">Request Declined</h2>';
        $body .= nl2br( esc_html( $body_text ) );
        $body .= '</div></body></html>';

        MBS_Email_Queue::send( $booking->email, $subject, $body, array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $org['name'] . ' <' . get_option( 'admin_email', $admin_email ) . '>',
            'Reply-To: ' . $admin_email,
        ) );
    }
}
