<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Invoice-specific payment tokens, settlement and reminder orchestration. */
class MBS_Invoice_Payment {

    /** The single authoritative rule used by links, reminders, portal and checkout. */
    public static function is_payable( $invoice ) {
        return $invoice && $invoice->document_type === 'invoice'
            && in_array( $invoice->status, array( 'issued', 'part_paid', 'overdue' ), true )
            && MBS_Billing_Ledger::balance_minor( $invoice ) > 0;
    }

    public static function generate_payment_url( $invoice ) {
        if ( ! MBS_Woo_Payment::is_available() ) return '';
        if ( ! self::is_payable( $invoice ) ) return '';
        $series = ! empty( $invoice->series_ref ) ? MBS_Series::get( $invoice->series_ref ) : null;
        if ( $series && $series->payment_method === 'offline_bacs' ) return '';

        $token = self::token_for_invoice( $invoice );
        if ( is_wp_error( $token ) ) return '';
        return add_query_arg( array(
            'mbs_invoice_pay' => '1',
            'invoice_ref'     => $invoice->invoice_ref,
            'invoice_token'   => $token,
        ), wc_get_checkout_url() );
    }

    public static function verify_token( $invoice, $token ) {
        if ( empty( $invoice->payment_token_hash ) || empty( $token ) ) return false;
        return hash_equals( $invoice->payment_token_hash, hash( 'sha256', (string) $token ) );
    }

    /** Dedicated deterministic bearer token; only its hash is persisted. */
    private static function token_for_invoice( $invoice ) {
        global $wpdb;
        if ( empty( $invoice->issued_at ) ) return new WP_Error( 'invoice_not_issued', 'Only an issued invoice can have a payment link.' );
        $material = 'mgf-invoice-payment|' . $invoice->invoice_ref . '|' . $invoice->issued_at . '|' . (int) $invoice->id;
        $token = hash_hmac( 'sha256', $material, wp_salt( 'auth' ) );
        $token_hash = hash( 'sha256', $token );
        if ( ! hash_equals( (string) ( $invoice->payment_token_hash ?? '' ), $token_hash ) ) {
            $updated = $wpdb->update(
                $wpdb->prefix . MBS_INVOICE_TABLE,
                array( 'payment_token_hash' => $token_hash, 'payment_token_created_at' => current_time( 'mysql' ) ),
                array( 'id' => (int) $invoice->id )
            );
            if ( $updated === false ) return new WP_Error( 'invoice_token_store_failed', 'Could not store the invoice payment-token hash.' );
            $invoice->payment_token_hash = $token_hash;
        }
        return $token;
    }

    public static function record_gateway_payment( $invoice_ref, $amount_decimal, $order_id, $reservation_ref = '' ) {
        $minor = self::gateway_decimal_to_minor( $amount_decimal );
        if ( is_wp_error( $minor ) ) return $minor;
        $result = MBS_Billing_Ledger::record_transaction( $invoice_ref, array(
            'provider' => 'woocommerce', 'provider_transaction_id' => (string) $order_id,
            'transaction_type' => 'payment', 'status' => 'completed', 'amount_minor' => $minor,
            'idempotency_key' => 'woo-order:' . $order_id . ':invoice:' . $invoice_ref . ':payment',
            'metadata' => array( 'woocommerce_order_id' => (int) $order_id, 'reservation_ref' => sanitize_text_field( $reservation_ref ) ),
        ) );
        if ( ! is_wp_error( $result ) && MBS_Billing_Ledger::balance_minor( $result['invoice'] ) <= 0 ) {
            $settled = self::mark_covered_occurrences_paid( $result['invoice'] );
            if ( is_wp_error( $settled ) ) return $settled;
        }
        if ( ! is_wp_error( $result ) ) self::send_payment_receipt_if_needed( $result );
        return $result;
    }

    public static function record_gateway_refund( $invoice_ref, $amount_decimal, $order_id, $refund_id, $requested_allocations = array() ) {
        global $wpdb;
        $minor = self::gateway_decimal_to_minor( $amount_decimal );
        if ( is_wp_error( $minor ) ) return $minor;
        $invoice = MBS_Billing_Ledger::get_invoice( $invoice_ref );
        if ( ! $invoice ) return new WP_Error( 'invoice_not_found', 'Invoice not found.' );
        if ( $wpdb->query( 'START TRANSACTION' ) === false ) return new WP_Error( 'refund_transaction_start_failed', 'Could not start refund reconciliation.' );
        $transaction_table = $wpdb->prefix . MBS_PAYMENT_TRANSACTION_TABLE;
	        $payment = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$transaction_table} WHERE invoice_id = %d AND provider = 'woocommerce'
             AND provider_transaction_id = %s AND transaction_type = 'payment' AND status = 'completed' FOR UPDATE",
            (int) $invoice->id, (string) $order_id
	        ) );
	        if ( ! $payment ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'refund_payment_not_recorded', 'The payment callback has not been recorded yet; retry this refund after payment reconciliation.' ); }
	        $existing_refund=$wpdb->get_row($wpdb->prepare(
	            "SELECT * FROM {$transaction_table} WHERE invoice_id=%d AND provider='woocommerce' AND provider_transaction_id=%s AND transaction_type='refund' FOR UPDATE",
	            (int)$invoice->id,'refund-'.(int)$refund_id
	        ));
	        if($existing_refund){
	            if((int)$existing_refund->amount_minor!==$minor||(int)$existing_refund->parent_transaction_id!==(int)$payment->id){$wpdb->query('ROLLBACK');return new WP_Error('idempotency_payload_conflict','This WooCommerce refund identifier was already used with a different amount or payment.');}
	            if ( $requested_allocations ) {
	                $metadata = json_decode( (string)$existing_refund->metadata_json, true );
	                $existing_allocations = is_array($metadata) && isset($metadata['booking_allocations']) ? $metadata['booking_allocations'] : array();
	                $requested_normalised = $requested_allocations;
	                ksort( $requested_normalised ); ksort( $existing_allocations );
	                if ( $requested_normalised !== $existing_allocations ) { $wpdb->query('ROLLBACK'); return new WP_Error('idempotency_payload_conflict','This WooCommerce refund identifier was already used with different booking allocations.'); }
	            }
	            if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('refund_transaction_commit_failed','Could not finish idempotent refund reconciliation.');}
	            return array('transaction'=>$existing_refund,'invoice'=>MBS_Billing_Ledger::get_invoice($invoice_ref),'created'=>false,'idempotent_replay'=>true);
	        }
	        if ( $minor > ( (int) $payment->amount_minor - (int) $payment->refunded_minor ) ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'refund_exceeds_payment', 'Refund exceeds the remaining amount on this WooCommerce payment.' ); }
        $allocations = self::refund_allocations( $invoice, $minor, $requested_allocations );
        if ( is_wp_error( $allocations ) ) { $wpdb->query( 'ROLLBACK' ); return $allocations; }
        $result = MBS_Billing_Ledger::record_transaction( $invoice_ref, array(
            'provider' => 'woocommerce', 'provider_transaction_id' => 'refund-' . $refund_id,
            'transaction_type' => 'refund', 'status' => 'completed', 'amount_minor' => $minor,
            'parent_transaction_id' => (int) $payment->id,
            'idempotency_key' => 'woo-refund:' . $refund_id . ':invoice:' . $invoice_ref,
            'metadata' => array( 'woocommerce_order_id' => (int) $order_id, 'woocommerce_refund_id' => (int) $refund_id, 'booking_allocations' => $allocations ),
        ), false );
        if ( is_wp_error( $result ) ) { $wpdb->query( 'ROLLBACK' ); return $result; }
	        $refund_events=array();
	        if ( ! is_wp_error( $result ) && ! empty( $result['created'] ) ) {
	            $refund_events = self::apply_refund_allocations( $result['invoice'], $allocations, false );
	            if ( is_wp_error( $refund_events ) ) { $wpdb->query( 'ROLLBACK' ); return $refund_events; }
	        }
	        $outbox_ids = array();
	        foreach ( $refund_events as $event ) {
	            $booking = MBS_Bookings::get( $event['booking_ref'] );
	            if ( ! $booking ) continue;
	            $queued = MBS_OSM_Integration::queue_refund_reversal( $booking, $invoice_ref, (int)$event['amount_minor'], (int)$order_id, (int)$refund_id, $event['reversal_kind'] ?? 'partial' );
	            if ( is_wp_error( $queued ) ) { $wpdb->query('ROLLBACK'); return $queued; }
	            if ( $queued ) $outbox_ids[] = (int)$queued;
	        }
	        if ( $wpdb->query( 'COMMIT' ) === false ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'refund_transaction_commit_failed', 'Could not commit refund reconciliation.' ); }
	        foreach ( array_unique( $outbox_ids ) as $outbox_id ) MBS_OSM_Integration::deliver_outbox_event( $outbox_id );
	        $result['osm_outbox_ids'] = $outbox_ids;
	        return $result;
    }

    /** Capability is enforced by the caller; version and idempotency here. */
    public static function record_manual_payment( $invoice_ref, $amount_minor, $idempotency_key, $expected_version, $note = '' ) {
        $invoice = MBS_Billing_Ledger::get_invoice( $invoice_ref );
        if ( ! $invoice ) return new WP_Error( 'invoice_not_found', 'Invoice not found.' );
        $amount = MBS_Money::minor( $amount_minor );
        if ( is_wp_error( $amount ) ) return $amount;
        if ( $amount <= 0 ) return new WP_Error( 'invalid_manual_amount', 'Manual payment must be positive.' );
        $result = MBS_Billing_Ledger::record_transaction( $invoice_ref, array(
            'provider' => 'manual', 'transaction_type' => 'payment', 'status' => 'completed',
            'amount_minor' => $amount, 'idempotency_key' => $idempotency_key,
            'expected_version' => (int) $expected_version,
            'metadata' => array( 'note' => sanitize_text_field( $note ), 'recorded_by' => get_current_user_id() ),
        ) );
        if ( ! is_wp_error( $result ) ) {
            if ( MBS_Billing_Ledger::balance_minor( $result['invoice'] ) <= 0 ) {
                $settled = self::mark_covered_occurrences_paid( $result['invoice'] );
                if ( is_wp_error( $settled ) ) return $settled;
            }
        }
        if ( ! is_wp_error( $result ) && ! empty( $result['created'] ) ) {
            MBS_Audit_Log::log( $invoice_ref, 'invoice_manual_payment', 'Manual invoice payment recorded: ' . MBS_Money::format( $amount ) . '.' );
        }
        if ( ! is_wp_error( $result ) ) self::send_payment_receipt_if_needed( $result );
        return $result;
    }

    /** Claim one receipt per completed payment transaction, including partial payments. */
    private static function send_payment_receipt_if_needed( $result ) {
        global $wpdb;
        if ( empty( $result['created'] ) || empty( $result['transaction'] ) || empty( $result['invoice'] ) ) return false;
        $transaction = $result['transaction'];
        if ( $transaction->transaction_type !== 'payment' || $transaction->status !== 'completed' || ! empty( $transaction->receipt_sent_at ) ) return false;
        $now = current_time( 'mysql' );
        $table = $wpdb->prefix . MBS_PAYMENT_TRANSACTION_TABLE;
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET receipt_sent_at = %s, updated_at = %s WHERE id = %d AND receipt_sent_at IS NULL",
            $now, $now, (int) $transaction->id
        ) );
        if ( $claimed !== 1 ) return false;
        $transaction->receipt_sent_at = $now;
        $series = ! empty( $result['invoice']->series_ref ) ? MBS_Series::get( $result['invoice']->series_ref ) : null;
        $sent = MBS_Email::notify_invoice_payment_received( $result['invoice'], $series, $transaction );
        MBS_Audit_Log::log( $result['invoice']->invoice_ref, 'invoice_payment_receipt', $sent ? 'Invoice payment receipt sent.' : 'Invoice payment receipt queued after immediate send failure.' );
        return true;
    }

    /** Claim and send the single allowed reminder for an invoice. */
    public static function send_invoice_reminder( $invoice_ref ) {
        global $wpdb;
        $invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
        if ( $wpdb->query( 'START TRANSACTION' ) === false ) return new WP_Error( 'transaction_start_failed', 'Could not start the reminder-claim transaction.' );
        $invoice = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$invoice_table} WHERE invoice_ref = %s FOR UPDATE", sanitize_text_field( $invoice_ref ) ) );
        if ( ! $invoice ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'invoice_not_found', 'Invoice not found.' );
        }
        if ( ! self::is_payable( $invoice ) ) {
            $wpdb->query( 'ROLLBACK' );
            return array( 'sent' => false, 'no_op' => true, 'reason' => 'not_outstanding' );
        }
        if ( (int) $invoice->reminder_count >= 1 ) {
            $wpdb->query( 'ROLLBACK' );
            return array( 'sent' => false, 'no_op' => true, 'reason' => 'already_reminded' );
        }
        $series = $invoice->series_ref ? MBS_Series::get( $invoice->series_ref ) : null;
        if ( $series && empty( $series->automatic_reminders ) ) {
            $wpdb->query( 'ROLLBACK' );
            return array( 'sent' => false, 'no_op' => true, 'reason' => 'automatic_reminders_disabled' );
        }
        $now = current_time( 'mysql' );
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$invoice_table} SET reminder_count = 1, status = 'overdue', last_reminded_at = %s, updated_at = %s WHERE id = %d AND reminder_count = 0",
            $now, $now, (int) $invoice->id
        ) );
        if ( $claimed !== 1 ) {
            $wpdb->query( 'ROLLBACK' );
            return array( 'sent' => false, 'no_op' => true, 'reason' => 'concurrent_reminder' );
        }
        if ( $wpdb->query( 'COMMIT' ) === false ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'transaction_commit_failed', 'Could not commit the reminder claim.' ); }
        $sent = MBS_Email::notify_invoice_reminder( $invoice, $series, MBS_Billing_Ledger::get_items( $invoice->id ) );
        MBS_Audit_Log::log( $invoice_ref, 'invoice_reminder', $sent ? 'Single invoice reminder sent.' : 'Single invoice reminder queued after immediate send failure.' );
        return array( 'sent' => $sent, 'queued' => ! $sent, 'no_op' => false );
    }

    public static function send_due_reminders() {
        global $wpdb;
        $invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
        $series_table = $wpdb->prefix . MBS_SERIES_TABLE;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.invoice_ref FROM {$invoice_table} i
             LEFT JOIN {$series_table} s ON s.series_ref = i.series_ref
             WHERE i.document_type = 'invoice' AND i.status IN ('issued','part_paid','overdue')
             AND i.due_at <= %s AND i.reminder_count = 0
             AND (s.series_ref IS NULL OR s.automatic_reminders = 1)
             ORDER BY i.due_at ASC LIMIT 20",
            current_time( 'mysql' )
        ) );
        $results = array();
        foreach ( $rows as $row ) $results[ $row->invoice_ref ] = self::send_invoice_reminder( $row->invoice_ref );
        return $results;
    }

    private static function mark_covered_occurrences_paid( $invoice ) {
        global $wpdb;
        if ( MBS_Billing_Ledger::balance_minor( $invoice ) > 0 ) return false;
        $allocation_table = $wpdb->prefix . MBS_BILLING_ALLOCATION_TABLE;
        $booking_table = $wpdb->prefix . MBS_TABLE;
        if ( $wpdb->query( 'START TRANSACTION' ) === false ) return new WP_Error( 'transaction_start_failed', 'Could not start occurrence settlement.' );
        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.* FROM {$allocation_table} a INNER JOIN {$booking_table} b ON b.ref = a.booking_ref
             WHERE a.invoice_id = %d AND a.status = 'active' AND b.status IN ('confirmed','deposit_paid') FOR UPDATE",
            (int) $invoice->id
        ) );
        $paid_refs = array();
        foreach ( $bookings as $booking ) {
            $updated = $wpdb->query( $wpdb->prepare(
                "UPDATE {$booking_table} SET status = 'paid', amount_paid = amount WHERE ref = %s AND status IN ('confirmed','deposit_paid')",
                $booking->ref
            ) );
            if ( $updated !== 1 ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'occurrence_settlement_failed', 'Could not settle every covered occurrence.' ); }
            $paid_refs[] = $booking->ref;
        }
        if ( $wpdb->query( 'COMMIT' ) === false ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'transaction_commit_failed', 'Could not commit occurrence settlement.' ); }
        foreach ( $paid_refs as $ref ) {
            $fresh = MBS_Bookings::get( $ref );
            MBS_Audit_Log::log( $ref, 'paid', 'Covered occurrence marked paid by consolidated invoice ' . $invoice->invoice_ref . '.', 0 );
            if ( $fresh ) do_action( 'mbs_booking_paid', $fresh, 0 );
        }
        return true;
    }

    /** Allocate a Woo refund to invoice items, latest service date first unless explicitly supplied. */
    private static function refund_allocations( $invoice, $amount_minor, $requested ) {
        global $wpdb;
        $remaining = (int) $amount_minor;
        $result = array();
        if ( is_array( $requested ) && $requested ) {
            foreach ( $requested as $booking_ref => $value ) {
                $minor = MBS_Money::minor( $value );
                if ( is_wp_error( $minor ) || $minor <= 0 || $minor > $remaining ) return new WP_Error( 'invalid_refund_allocation', 'Refund allocations must be positive minor units and equal the refund.' );
                $result[ sanitize_text_field( $booking_ref ) ] = $minor;
                $remaining -= $minor;
            }
            if ( $remaining !== 0 ) return new WP_Error( 'invalid_refund_allocation_total', 'Refund allocations must equal the refund amount.' );
            return $result;
        }
        $allocation_table = $wpdb->prefix . MBS_BILLING_ALLOCATION_TABLE;
        $item_table = $wpdb->prefix . MBS_INVOICE_ITEM_TABLE;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.booking_ref, a.allocated_minor, a.refunded_minor FROM {$allocation_table} a
             LEFT JOIN {$item_table} ii ON ii.invoice_id = a.invoice_id AND ii.booking_ref = a.booking_ref
	             WHERE a.invoice_id = %d AND a.status IN ('active','released')
             ORDER BY ii.service_date DESC, a.id DESC FOR UPDATE",
            (int) $invoice->id
        ) );
        foreach ( $rows as $row ) {
            if ( $remaining <= 0 ) break;
            $available = max( 0, (int) $row->allocated_minor - (int) $row->refunded_minor );
            $allocated = min( $remaining, $available );
            if ( $allocated > 0 ) $result[ $row->booking_ref ] = $allocated;
            $remaining -= $allocated;
        }
        if ( $remaining > 0 ) return new WP_Error( 'refund_allocation_failed', 'The refund could not be allocated to invoice items.' );
        return $result;
    }

    /** Reopen only occurrences whose own invoice allocation was refunded. */
    private static function apply_refund_allocations( $invoice, $allocations, $manage_transaction = true ) {
        global $wpdb;
        $allocation_table = $wpdb->prefix . MBS_BILLING_ALLOCATION_TABLE;
        $booking_table = $wpdb->prefix . MBS_TABLE;
        if ( $manage_transaction && $wpdb->query( 'START TRANSACTION' ) === false ) return new WP_Error( 'refund_allocation_start_failed', 'Could not start allocation reconciliation.' );
	        $reopened_refs = array();$refund_events=array();
	        foreach ( array_keys( $allocations ) as $booking_ref ) {
	            $allocation = $wpdb->get_row( $wpdb->prepare(
	                "SELECT id,status FROM {$allocation_table} WHERE invoice_id = %d AND booking_ref = %s AND status IN ('active','released') FOR UPDATE",
	                (int) $invoice->id, sanitize_text_field( $booking_ref )
	            ) );
	            if ( ! $allocation ) { if($manage_transaction)$wpdb->query( 'ROLLBACK' ); return new WP_Error('refund_allocation_missing','A refund allocation no longer exists.'); }
            $amount = (int) $allocations[ $booking_ref ];
            $changed = $wpdb->query( $wpdb->prepare(
                "UPDATE {$allocation_table} SET refunded_minor = refunded_minor + %d, updated_at = %s WHERE id = %d AND refunded_minor + %d <= allocated_minor",
	                $amount, current_time('mysql'), (int)$allocation->id, $amount
	            ) );
            if ( $changed !== 1 ) { if($manage_transaction)$wpdb->query('ROLLBACK'); return new WP_Error('refund_allocation_exceeded','Refund exceeds the remaining booking allocation.'); }
	            $net_minor = (int) $wpdb->get_var( $wpdb->prepare( "SELECT allocated_minor - refunded_minor FROM {$allocation_table} WHERE id = %d", (int)$allocation->id ) );
            $net_decimal = MBS_Money::decimal( $net_minor );
            if ( is_wp_error( $net_decimal ) ) { if($manage_transaction)$wpdb->query('ROLLBACK'); return $net_decimal; }
	            $updated=0;
	            if($allocation->status==='active')$updated = $wpdb->query( $wpdb->prepare(
	                "UPDATE {$booking_table} SET status = %s, amount_paid = %s, access_sent = 0 WHERE ref = %s AND status IN ('paid','deposit_paid','confirmed')",
	                $net_minor > 0 ? 'deposit_paid' : 'confirmed', $net_decimal, sanitize_text_field( $booking_ref )
	            ) );
	            if ( $updated === false ) { if($manage_transaction)$wpdb->query( 'ROLLBACK' ); return new WP_Error('refund_booking_update_failed','Could not reconcile the refunded occurrence.'); }
	            if ( $updated ) $reopened_refs[] = sanitize_text_field( $booking_ref );
	            $refund_events[]=array('booking_ref'=>sanitize_text_field($booking_ref),'amount_minor'=>$amount,'reversal_kind'=>$net_minor===0?'full':'partial');
        }
        if ( $manage_transaction && $wpdb->query( 'COMMIT' ) === false ) return new WP_Error('refund_allocation_commit_failed','Could not commit allocation reconciliation.');
        foreach ( $reopened_refs as $ref ) {
            MBS_Audit_Log::log( $ref, 'status_changed', 'This occurrence was reopened after its allocation on consolidated invoice ' . $invoice->invoice_ref . ' was refunded; unaffected occurrences remain settled.', 0 );
        }
	        return $refund_events;
    }

    private static function gateway_decimal_to_minor( $value ) {
        if ( is_float( $value ) ) $value = number_format( $value, 2, '.', '' );
        return MBS_Money::from_decimal_string( (string) $value );
    }
}
