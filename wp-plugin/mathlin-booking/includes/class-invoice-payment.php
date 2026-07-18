<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Invoice-specific payment tokens, settlement and reminder orchestration. */
class MBS_Invoice_Payment {

    public static function generate_payment_url( $invoice ) {
        if ( ! MBS_Woo_Payment::is_available() ) return '';
        if ( ! in_array( $invoice->status, array( 'issued', 'part_paid' ), true ) ) return '';
        if ( MBS_Billing_Ledger::balance_minor( $invoice ) <= 0 ) return '';
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

    public static function record_gateway_payment( $invoice_ref, $amount_decimal, $order_id ) {
        $minor = self::gateway_decimal_to_minor( $amount_decimal );
        if ( is_wp_error( $minor ) ) return $minor;
        $result = MBS_Billing_Ledger::record_transaction( $invoice_ref, array(
            'provider' => 'woocommerce', 'provider_transaction_id' => (string) $order_id,
            'transaction_type' => 'payment', 'status' => 'completed', 'amount_minor' => $minor,
            'idempotency_key' => 'woo-order:' . $order_id . ':invoice:' . $invoice_ref . ':payment',
            'metadata' => array( 'woocommerce_order_id' => (int) $order_id ),
        ) );
        if ( ! is_wp_error( $result ) && MBS_Billing_Ledger::balance_minor( $result['invoice'] ) <= 0 ) {
            self::mark_covered_occurrences_paid( $result['invoice'] );
        }
        if ( ! is_wp_error( $result ) ) self::send_payment_receipt_if_needed( $result );
        return $result;
    }

    public static function record_gateway_refund( $invoice_ref, $amount_decimal, $order_id, $refund_id ) {
        $minor = self::gateway_decimal_to_minor( $amount_decimal );
        if ( is_wp_error( $minor ) ) return $minor;
        $result = MBS_Billing_Ledger::record_transaction( $invoice_ref, array(
            'provider' => 'woocommerce', 'provider_transaction_id' => 'refund-' . $refund_id,
            'transaction_type' => 'refund', 'status' => 'completed', 'amount_minor' => $minor,
            'idempotency_key' => 'woo-refund:' . $refund_id . ':invoice:' . $invoice_ref,
            'metadata' => array( 'woocommerce_order_id' => (int) $order_id, 'woocommerce_refund_id' => (int) $refund_id ),
        ) );
        if ( ! is_wp_error( $result ) && MBS_Billing_Ledger::balance_minor( $result['invoice'] ) > 0 ) {
            self::reopen_covered_occurrences( $result['invoice'] );
        }
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
        if ( ! is_wp_error( $result ) && ! empty( $result['created'] ) ) {
            if ( MBS_Billing_Ledger::balance_minor( $result['invoice'] ) <= 0 ) self::mark_covered_occurrences_paid( $result['invoice'] );
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
        $wpdb->query( 'START TRANSACTION' );
        $invoice = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$invoice_table} WHERE invoice_ref = %s FOR UPDATE", sanitize_text_field( $invoice_ref ) ) );
        if ( ! $invoice ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'invoice_not_found', 'Invoice not found.' );
        }
        if ( ! in_array( $invoice->status, array( 'issued', 'part_paid' ), true ) || MBS_Billing_Ledger::balance_minor( $invoice ) <= 0 ) {
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
            "UPDATE {$invoice_table} SET reminder_count = 1, last_reminded_at = %s, updated_at = %s WHERE id = %d AND reminder_count = 0",
            $now, $now, (int) $invoice->id
        ) );
        if ( $claimed !== 1 ) {
            $wpdb->query( 'ROLLBACK' );
            return array( 'sent' => false, 'no_op' => true, 'reason' => 'concurrent_reminder' );
        }
        $wpdb->query( 'COMMIT' );
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
             WHERE i.document_type = 'invoice' AND i.status IN ('issued','part_paid')
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
        $wpdb->query( 'START TRANSACTION' );
        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.* FROM {$allocation_table} a INNER JOIN {$booking_table} b ON b.ref = a.booking_ref
             WHERE a.invoice_id = %d AND a.status = 'active' AND b.status IN ('confirmed','deposit_paid') FOR UPDATE",
            (int) $invoice->id
        ) );
        $paid_refs = array();
        foreach ( $bookings as $booking ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$booking_table} SET status = 'paid', amount_paid = amount WHERE ref = %s AND status IN ('confirmed','deposit_paid')",
                $booking->ref
            ) );
            $paid_refs[] = $booking->ref;
        }
        $wpdb->query( 'COMMIT' );
        foreach ( $paid_refs as $ref ) {
            $fresh = MBS_Bookings::get( $ref );
            MBS_Audit_Log::log( $ref, 'paid', 'Covered occurrence marked paid by consolidated invoice ' . $invoice->invoice_ref . '.', 0 );
            if ( $fresh ) do_action( 'mbs_booking_paid', $fresh, 0 );
        }
        return true;
    }

    private static function reopen_covered_occurrences( $invoice ) {
        global $wpdb;
        $allocation_table = $wpdb->prefix . MBS_BILLING_ALLOCATION_TABLE;
        $booking_table = $wpdb->prefix . MBS_TABLE;
        $wpdb->query( 'START TRANSACTION' );
        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.* FROM {$allocation_table} a INNER JOIN {$booking_table} b ON b.ref = a.booking_ref
             WHERE a.invoice_id = %d AND a.status = 'active' AND b.status = 'paid' FOR UPDATE",
            (int) $invoice->id
        ) );
        $reopened_refs = array();
        foreach ( $bookings as $booking ) {
            $wpdb->update( $booking_table, array( 'status' => 'confirmed', 'amount_paid' => 0, 'access_sent' => 0 ), array( 'ref' => $booking->ref ) );
            $reopened_refs[] = $booking->ref;
        }
        $wpdb->query( 'COMMIT' );
        foreach ( $reopened_refs as $ref ) {
            MBS_Audit_Log::log( $ref, 'status_changed', 'Reverted to Confirmed after refund against consolidated invoice ' . $invoice->invoice_ref . '; access flag reset.', 0 );
        }
        return true;
    }

    private static function gateway_decimal_to_minor( $value ) {
        if ( is_float( $value ) ) $value = number_format( $value, 2, '.', '' );
        return MBS_Money::from_decimal_string( (string) $value );
    }
}
