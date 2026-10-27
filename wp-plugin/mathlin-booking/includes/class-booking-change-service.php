<?php
if (!defined('ABSPATH')) exit;

/** Internal application service. Callers hold the occupancy lock and validate
 * authority, dates, capacity, price and availability before entering this service.
 * SQL-only transaction: booking, revision, audit, request and delivery intents.
 */
class MBS_Booking_Change_Service {
    public static function apply($expected, $update, $options = array()) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;
        if ($wpdb->query('START TRANSACTION') === false) return new WP_Error('change_start_failed', 'Could not start the booking change.');
        try {
            $request = $options['request'] ?? null;
            if ($request) {
                $requests = $wpdb->prefix . 'mathlin_mod_requests';
                $locked_request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$requests} WHERE id=%d FOR UPDATE", $request->id));
                if (!$locked_request || $locked_request->status !== 'pending' || $locked_request->booking_ref !== $expected->ref || $locked_request->request_type !== $request->request_type || $locked_request->requested_data !== $request->requested_data) throw new RuntimeException('The request changed. Refresh before deciding.');
            }
            $locked = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE ref=%s FOR UPDATE", $expected->ref));
            if (!$locked || !hash_equals(hash('sha256', wp_json_encode((array)$expected)), hash('sha256', wp_json_encode((array)$locked)))) throw new RuntimeException('The booking changed. Refresh before saving.');
            if (!empty($options['financial_change']) && MBS_Bookings::has_financial_history($locked->ref)) throw new RuntimeException('This occurrence has ledger history. Use credit and replace instead.');
            if ($update && $wpdb->update($table, $update, array('ref'=>$locked->ref)) === false) throw new RuntimeException('Could not save the booking change.');
            $fresh = MBS_Bookings::get($locked->ref);
            if (!$fresh) throw new RuntimeException('Could not verify the changed booking.');
            $document_id = 0;
            if (!empty($options['reissue']) && !empty($locked->current_invoice_document_id)) {
                if(!MBS_Payment_Features::enabled('invoices'))throw new RuntimeException('Invoice features must be enabled to revise this already-invoiced booking. Existing issued records were not changed.');
                // Revisions retain the previous immutable logo; no file/network
                // resolution or PDF rendering occurs while the booking is locked.
                $prior = $wpdb->get_row($wpdb->prepare('SELECT snapshot_json FROM '.$wpdb->prefix.MBS_INVOICE_DOCUMENTS_TABLE.' WHERE id=%d', $locked->current_invoice_document_id));
                $snapshot = $prior ? MBS_Issued_Invoice_Snapshot::from_json($prior->snapshot_json) : null;
                if (!$snapshot || is_wp_error($snapshot)) throw new RuntimeException('The previous invoice snapshot could not be verified.');
                $logo = $snapshot->logo_asset_id ? array('asset_id'=>$snapshot->logo_asset_id, 'content_hash'=>$snapshot->logo_content_hash) : null;
                $document_id = MBS_Invoice_Document_Service::reissue_booking_document_within_transaction($fresh, $logo);
                if (is_wp_error($document_id)) throw new RuntimeException($document_id->get_error_message());
                $fresh = MBS_Bookings::get($locked->ref);
                if(!$fresh)throw new RuntimeException('Could not verify the revised invoice link.');
            }
            $ha = MBS_HomeAssistant::stage_within_transaction($fresh,$locked->status);
            if (is_wp_error($ha)) throw new RuntimeException($ha->get_error_message());
            if (!MBS_Audit_Log::log($locked->ref, $options['action'] ?? 'edited', $options['audit'] ?? 'Booking changed by an authorised manager.')) throw new RuntimeException('Could not record the booking-change audit.');
            if ($request && $wpdb->update($requests, array('status'=>'approved', 'resolved_at'=>current_time('mysql'), 'resolved_by'=>get_current_user_id()), array('id'=>(int)$request->id, 'status'=>'pending')) !== 1) throw new RuntimeException('Could not resolve the booking request.');
            if (!empty($options['notify'])) {
                $queued = MBS_Email::enqueue_booking_change($fresh, $options['notify'], $document_id);
                if (is_wp_error($queued)) throw new RuntimeException($queued->get_error_message());
            }
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('Could not commit the booking change.');
            if(!empty($options['notify']))MBS_Email_Queue::wake();
            return array('booking'=>$fresh, 'document_id'=>$document_id);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('booking_change_failed', $e->getMessage());
        }
    }
}
