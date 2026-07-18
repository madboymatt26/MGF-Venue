<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Consolidated invoice and payment ledger.
 *
 * Issued document totals/items are immutable. Settlement and credits are
 * additive transaction/document records, with denormalised paid/credited
 * counters maintained only for safe querying.
 */
class MBS_Billing_Ledger {

    public static function get_invoice( $invoice_ref ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_INVOICE_TABLE;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE invoice_ref = %s",
            sanitize_text_field( $invoice_ref )
        ) );
    }

    public static function create_draft_invoice( $data, $idempotency_key ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_INVOICE_TABLE;
        $key = self::idempotency_hash( $idempotency_key );
        if ( is_wp_error( $key ) ) return $key;

        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s", $key ) );
        if ( $existing ) {
            return array( 'invoice' => $existing, 'created' => false, 'idempotent_replay' => true );
        }

        $invoice_ref = self::generate_reference( 'INV', $table );
        $now = current_time( 'mysql' );
        $insert = array(
            'invoice_ref'          => $invoice_ref,
            'document_type'        => 'invoice',
            'series_ref'           => ! empty( $data['series_ref'] ) ? sanitize_text_field( $data['series_ref'] ) : null,
            'status'               => 'draft',
            'version'              => 1,
            'contact_name'         => sanitize_text_field( $data['contact_name'] ?? '' ),
            'contact_organisation' => sanitize_text_field( $data['contact_organisation'] ?? '' ),
            'contact_email'        => sanitize_email( $data['contact_email'] ?? '' ),
            'contact_address'      => sanitize_textarea_field( $data['contact_address'] ?? '' ),
            'billing_mode'         => sanitize_key( $data['billing_mode'] ?? 'monthly' ),
            'period_start'         => ! empty( $data['period_start'] ) ? sanitize_text_field( $data['period_start'] ) : null,
            'period_end'           => ! empty( $data['period_end'] ) ? sanitize_text_field( $data['period_end'] ) : null,
            'currency'             => self::currency( $data['currency'] ?? 'GBP' ),
            'idempotency_key'      => $key,
            'due_at'               => ! empty( $data['due_at'] ) ? sanitize_text_field( $data['due_at'] ) : null,
            'created_at'           => $now,
            'updated_at'           => $now,
        );
        if ( empty( $insert['contact_name'] ) || empty( $insert['contact_email'] ) || ! is_email( $insert['contact_email'] ) ) {
            return new WP_Error( 'invalid_invoice_contact', 'A valid invoice contact name and email are required.' );
        }
        if ( $wpdb->insert( $table, $insert ) === false ) {
            // A concurrent request may have won the idempotency race.
            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s", $key ) );
            if ( $existing ) {
                return array( 'invoice' => $existing, 'created' => false, 'idempotent_replay' => true );
            }
            return new WP_Error( 'invoice_create_failed', 'Could not create the draft invoice.' );
        }
        return array( 'invoice' => self::get_invoice( $invoice_ref ), 'created' => true, 'idempotent_replay' => false );
    }

    /** Add an item to a draft invoice and reserve its booking allocation. */
    public static function add_item( $invoice_ref, $item, $expected_version ) {
        global $wpdb;
        $invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
        $item_table = $wpdb->prefix . MBS_INVOICE_ITEM_TABLE;
        $allocation_table = $wpdb->prefix . MBS_BILLING_ALLOCATION_TABLE;

        $unit_minor = MBS_Money::minor( $item['unit_amount_minor'] ?? null );
        $quantity_milli = MBS_Money::minor( $item['quantity_milli'] ?? 1000 );
        if ( is_wp_error( $unit_minor ) ) return $unit_minor;
        if ( is_wp_error( $quantity_milli ) ) return $quantity_milli;
        $line_total = MBS_Money::line_total( $unit_minor, $quantity_milli );
        if ( is_wp_error( $line_total ) ) return $line_total;

        $wpdb->query( 'START TRANSACTION' );
        $invoice = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$invoice_table} WHERE invoice_ref = %s FOR UPDATE",
            sanitize_text_field( $invoice_ref )
        ) );
        if ( ! $invoice ) return self::rollback_error( 'invoice_not_found', 'Invoice not found.' );
        if ( $invoice->status !== 'draft' ) return self::rollback_error( 'issued_invoice_immutable', 'Items cannot be added after an invoice is issued.' );
        if ( (int) $invoice->version !== (int) $expected_version ) return self::rollback_error( 'invoice_precondition_failed', 'The invoice changed since it was loaded.' );

        $booking_ref = ! empty( $item['booking_ref'] ) ? sanitize_text_field( $item['booking_ref'] ) : null;
        if ( $booking_ref ) {
            $allocation = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$allocation_table} WHERE active_booking_ref = %s FOR UPDATE",
                $booking_ref
            ) );
            if ( $allocation && (int) $allocation->invoice_id !== (int) $invoice->id ) {
                return self::rollback_error( 'booking_already_allocated', 'This booking is already actively allocated to another invoice.' );
            }
        }

        $item_ref = self::generate_reference( 'ITEM', $item_table, 'item_ref' );
        $inserted = $wpdb->insert( $item_table, array(
            'item_ref'              => $item_ref,
            'invoice_id'            => (int) $invoice->id,
            'item_type'             => sanitize_key( $item['item_type'] ?? 'hire' ),
            'booking_ref'           => $booking_ref,
            'service_date'          => ! empty( $item['service_date'] ) ? sanitize_text_field( $item['service_date'] ) : null,
            'description'           => sanitize_text_field( $item['description'] ?? '' ),
            'quantity_milli'        => $quantity_milli,
            'unit_amount_minor'     => $unit_minor,
            'line_total_minor'      => $line_total,
            'pricing_snapshot_json' => wp_json_encode( $item['pricing_snapshot'] ?? array() ),
            'created_at'            => current_time( 'mysql' ),
        ) );
        if ( $inserted === false ) return self::rollback_error( 'invoice_item_create_failed', 'Could not create the invoice item.' );

        if ( $booking_ref && ! $allocation ) {
            $allocated = $wpdb->insert( $allocation_table, array(
                'invoice_id'         => (int) $invoice->id,
                'booking_ref'        => $booking_ref,
                'active_booking_ref' => $booking_ref,
                'allocated_minor'    => $line_total,
                'status'             => 'active',
                'created_at'         => current_time( 'mysql' ),
                'updated_at'         => current_time( 'mysql' ),
            ) );
            if ( $allocated === false ) return self::rollback_error( 'booking_allocation_failed', 'Could not reserve the booking for this invoice.' );
        } elseif ( $booking_ref ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$allocation_table} SET allocated_minor = allocated_minor + %d, updated_at = %s WHERE id = %d",
                $line_total, current_time( 'mysql' ), (int) $allocation->id
            ) );
        }

        $version_updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$invoice_table} SET version = version + 1, updated_at = %s WHERE id = %d AND status = 'draft' AND version = %d",
            current_time( 'mysql' ), (int) $invoice->id, (int) $expected_version
        ) );
        if ( $version_updated !== 1 ) return self::rollback_error( 'invoice_concurrent_update', 'The invoice was updated by another request.' );
        $wpdb->query( 'COMMIT' );
        return array( 'item_ref' => $item_ref, 'line_total_minor' => $line_total, 'invoice' => self::get_invoice( $invoice_ref ) );
    }

    /** Freeze item totals and issue a draft invoice. */
    public static function issue_invoice( $invoice_ref, $expected_version ) {
        global $wpdb;
        $invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
        $item_table = $wpdb->prefix . MBS_INVOICE_ITEM_TABLE;
        $wpdb->query( 'START TRANSACTION' );
        $invoice = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$invoice_table} WHERE invoice_ref = %s FOR UPDATE", sanitize_text_field( $invoice_ref ) ) );
        if ( ! $invoice ) return self::rollback_error( 'invoice_not_found', 'Invoice not found.' );
        if ( $invoice->status !== 'draft' ) {
            $wpdb->query( 'ROLLBACK' );
            return array( 'invoice' => $invoice, 'issued' => false, 'no_op' => true );
        }
        if ( (int) $invoice->version !== (int) $expected_version ) return self::rollback_error( 'invoice_precondition_failed', 'The invoice changed since it was loaded.' );
        $summary = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS item_count, COALESCE(SUM(line_total_minor), 0) AS subtotal_minor FROM {$item_table} WHERE invoice_id = %d",
            (int) $invoice->id
        ) );
        if ( ! $summary || (int) $summary->item_count < 1 ) return self::rollback_error( 'invoice_empty', 'An invoice must contain at least one item before issue.' );
        $subtotal = (int) $summary->subtotal_minor;
        $issued_at = current_time( 'mysql' );
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$invoice_table}
             SET status = 'issued', subtotal_minor = %d, total_minor = %d, issued_at = %s, version = version + 1, updated_at = %s
             WHERE id = %d AND status = 'draft' AND version = %d",
            $subtotal, $subtotal, $issued_at, $issued_at, (int) $invoice->id, (int) $expected_version
        ) );
        if ( $updated !== 1 ) return self::rollback_error( 'invoice_concurrent_update', 'The invoice was updated by another request.' );
        $wpdb->query( 'COMMIT' );
        return array( 'invoice' => self::get_invoice( $invoice_ref ), 'issued' => true, 'no_op' => false );
    }

    /** Void an unpaid issued invoice without deleting it. */
    public static function void_invoice( $invoice_ref, $reason, $expected_version ) {
        global $wpdb;
        $invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
        $allocation_table = $wpdb->prefix . MBS_BILLING_ALLOCATION_TABLE;
        $wpdb->query( 'START TRANSACTION' );
        $invoice = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$invoice_table} WHERE invoice_ref = %s FOR UPDATE", sanitize_text_field( $invoice_ref ) ) );
        if ( ! $invoice ) return self::rollback_error( 'invoice_not_found', 'Invoice not found.' );
        if ( $invoice->status === 'void' ) {
            $wpdb->query( 'ROLLBACK' );
            return array( 'invoice' => $invoice, 'voided' => false, 'no_op' => true );
        }
        if ( $invoice->document_type !== 'invoice' || ! in_array( $invoice->status, array( 'issued', 'part_paid' ), true ) ) return self::rollback_error( 'invoice_cannot_void', 'Only an issued invoice can be voided.' );
        if ( (int) $invoice->paid_minor !== 0 ) return self::rollback_error( 'paid_invoice_requires_credit', 'A paid invoice cannot be voided; create a credit/refund adjustment.' );
        if ( (int) $invoice->version !== (int) $expected_version ) return self::rollback_error( 'invoice_precondition_failed', 'The invoice changed since it was loaded.' );
        $now = current_time( 'mysql' );
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$invoice_table} SET status = 'void', voided_at = %s, void_reason = %s, version = version + 1, updated_at = %s WHERE id = %d AND version = %d",
            $now, sanitize_text_field( $reason ), $now, (int) $invoice->id, (int) $expected_version
        ) );
        if ( $updated !== 1 ) return self::rollback_error( 'invoice_concurrent_update', 'The invoice was updated by another request.' );
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$allocation_table} SET status = 'released', active_booking_ref = NULL, released_at = %s, updated_at = %s WHERE invoice_id = %d AND status = 'active'",
            $now, $now, (int) $invoice->id
        ) );
        $wpdb->query( 'COMMIT' );
        return array( 'invoice' => self::get_invoice( $invoice_ref ), 'voided' => true, 'no_op' => false );
    }

    /** Create an immutable issued credit note linked to an original invoice. */
    public static function create_credit_note( $invoice_ref, $amount_minor, $reason, $idempotency_key ) {
        global $wpdb;
        $amount = MBS_Money::minor( $amount_minor );
        if ( is_wp_error( $amount ) ) return $amount;
        if ( $amount <= 0 ) return new WP_Error( 'invalid_credit_amount', 'Credit amount must be greater than zero minor units.' );
        $key = self::idempotency_hash( $idempotency_key );
        if ( is_wp_error( $key ) ) return $key;
        $invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
        $item_table = $wpdb->prefix . MBS_INVOICE_ITEM_TABLE;

        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$invoice_table} WHERE idempotency_key = %s", $key ) );
        if ( $existing ) return array( 'credit_note' => $existing, 'created' => false, 'idempotent_replay' => true );

        $wpdb->query( 'START TRANSACTION' );
        $original = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$invoice_table} WHERE invoice_ref = %s FOR UPDATE", sanitize_text_field( $invoice_ref ) ) );
        if ( ! $original ) return self::rollback_error( 'invoice_not_found', 'Invoice not found.' );
        if ( $original->document_type !== 'invoice' || ! in_array( $original->status, array( 'issued', 'part_paid', 'paid', 'credited' ), true ) ) return self::rollback_error( 'invoice_not_creditable', 'This invoice cannot receive a credit note.' );
        if ( $amount > ( (int) $original->total_minor - (int) $original->credited_minor ) ) return self::rollback_error( 'credit_exceeds_invoice', 'Credit would exceed the uncredited invoice total.' );

        $credit_ref = self::generate_reference( 'CN', $invoice_table );
        $now = current_time( 'mysql' );
        $inserted = $wpdb->insert( $invoice_table, array(
            'invoice_ref' => $credit_ref, 'document_type' => 'credit_note', 'parent_invoice_id' => (int) $original->id,
            'series_ref' => $original->series_ref, 'status' => 'issued', 'version' => 1,
            'contact_name' => $original->contact_name, 'contact_organisation' => $original->contact_organisation,
            'contact_email' => $original->contact_email, 'contact_address' => $original->contact_address,
            'billing_mode' => $original->billing_mode, 'period_start' => $original->period_start, 'period_end' => $original->period_end,
            'currency' => $original->currency, 'subtotal_minor' => -$amount, 'total_minor' => -$amount,
            'idempotency_key' => $key, 'issued_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ) );
        if ( $inserted === false ) return self::rollback_error( 'credit_note_create_failed', 'Could not create the credit note.' );
        $credit_id = (int) $wpdb->insert_id;
        $item_inserted = $wpdb->insert( $item_table, array(
            'item_ref' => self::generate_reference( 'ITEM', $item_table, 'item_ref' ), 'invoice_id' => $credit_id,
            'item_type' => 'credit', 'description' => sanitize_text_field( $reason ), 'quantity_milli' => 1000,
            'unit_amount_minor' => -$amount, 'line_total_minor' => -$amount,
            'pricing_snapshot_json' => wp_json_encode( array( 'source_invoice_ref' => $original->invoice_ref ) ), 'created_at' => $now,
        ) );
        if ( $item_inserted === false ) return self::rollback_error( 'credit_item_create_failed', 'Could not create the credit-note item.' );
        $new_credited = (int) $original->credited_minor + $amount;
        $new_status = $new_credited >= (int) $original->total_minor ? 'credited' : $original->status;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$invoice_table} SET credited_minor = %d, status = %s, version = version + 1, updated_at = %s WHERE id = %d",
            $new_credited, $new_status, $now, (int) $original->id
        ) );
        $wpdb->query( 'COMMIT' );
        return array( 'credit_note' => self::get_invoice( $credit_ref ), 'created' => true, 'idempotent_replay' => false );
    }

    /** Record an idempotent payment/refund transaction and update settlement. */
    public static function record_transaction( $invoice_ref, $data ) {
        global $wpdb;
        $amount = MBS_Money::minor( $data['amount_minor'] ?? null );
        if ( is_wp_error( $amount ) ) return $amount;
        if ( $amount <= 0 ) return new WP_Error( 'invalid_transaction_amount', 'Transaction amount must be greater than zero minor units.' );
        $type = sanitize_key( $data['transaction_type'] ?? 'payment' );
        if ( ! in_array( $type, array( 'payment', 'refund' ), true ) ) return new WP_Error( 'invalid_transaction_type', 'Transaction type must be payment or refund.' );
        $status = sanitize_key( $data['status'] ?? 'completed' );
        if ( ! in_array( $status, array( 'pending', 'completed', 'failed' ), true ) ) return new WP_Error( 'invalid_transaction_status', 'Invalid transaction status.' );
        $key = self::idempotency_hash( $data['idempotency_key'] ?? '' );
        if ( is_wp_error( $key ) ) return $key;
        $invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
        $transaction_table = $wpdb->prefix . MBS_PAYMENT_TRANSACTION_TABLE;
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$transaction_table} WHERE idempotency_key = %s", $key ) );
        if ( $existing ) return array( 'transaction' => $existing, 'created' => false, 'idempotent_replay' => true );

        $wpdb->query( 'START TRANSACTION' );
        $invoice = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$invoice_table} WHERE invoice_ref = %s FOR UPDATE", sanitize_text_field( $invoice_ref ) ) );
        if ( ! $invoice ) return self::rollback_error( 'invoice_not_found', 'Invoice not found.' );
        if ( $invoice->document_type !== 'invoice' || in_array( $invoice->status, array( 'draft', 'void' ), true ) ) return self::rollback_error( 'invoice_not_payable', 'This invoice cannot accept a payment transaction.' );
        $transaction_ref = self::generate_reference( 'TXN', $transaction_table, 'transaction_ref' );
        $now = current_time( 'mysql' );
        $inserted = $wpdb->insert( $transaction_table, array(
            'transaction_ref' => $transaction_ref, 'invoice_id' => (int) $invoice->id,
            'provider' => sanitize_key( $data['provider'] ?? 'manual' ),
            'provider_transaction_id' => ! empty( $data['provider_transaction_id'] ) ? sanitize_text_field( $data['provider_transaction_id'] ) : null,
            'transaction_type' => $type, 'status' => $status, 'amount_minor' => $amount,
            'currency' => $invoice->currency, 'idempotency_key' => $key,
            'metadata_json' => wp_json_encode( $data['metadata'] ?? array() ),
            'occurred_at' => ! empty( $data['occurred_at'] ) ? sanitize_text_field( $data['occurred_at'] ) : $now,
            'created_at' => $now, 'updated_at' => $now,
        ) );
        if ( $inserted === false ) return self::rollback_error( 'transaction_create_failed', 'Could not record the payment transaction.' );
        $transaction_id = (int) $wpdb->insert_id;
        if ( $status === 'completed' ) {
            $paid = $type === 'payment'
                ? (int) $invoice->paid_minor + $amount
                : max( 0, (int) $invoice->paid_minor - $amount );
            $covered = $paid + (int) $invoice->credited_minor;
            $invoice_status = $covered >= (int) $invoice->total_minor ? 'paid' : ( $paid > 0 ? 'part_paid' : 'issued' );
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$invoice_table} SET paid_minor = %d, status = %s, version = version + 1, updated_at = %s WHERE id = %d",
                $paid, $invoice_status, $now, (int) $invoice->id
            ) );
        }
        $wpdb->query( 'COMMIT' );
        $transaction = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$transaction_table} WHERE id = %d", $transaction_id ) );
        return array( 'transaction' => $transaction, 'invoice' => self::get_invoice( $invoice_ref ), 'created' => true, 'idempotent_replay' => false );
    }

    public static function balance_minor( $invoice ) {
        return (int) $invoice->total_minor - (int) $invoice->paid_minor - (int) $invoice->credited_minor;
    }

    public static function get_items( $invoice_id ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_INVOICE_ITEM_TABLE;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE invoice_id = %d ORDER BY service_date ASC, id ASC",
            (int) $invoice_id
        ) );
    }

    public static function has_booking_item( $invoice_id, $booking_ref ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_INVOICE_ITEM_TABLE;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE invoice_id = %d AND booking_ref = %s LIMIT 1",
            (int) $invoice_id,
            sanitize_text_field( $booking_ref )
        ) );
    }

    public static function release_booking_allocation( $invoice_id, $booking_ref ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_BILLING_ALLOCATION_TABLE;
        $now = current_time( 'mysql' );
        return $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET status = 'released', active_booking_ref = NULL, released_at = %s, updated_at = %s
             WHERE invoice_id = %d AND active_booking_ref = %s AND status = 'active'",
            $now, $now, (int) $invoice_id, sanitize_text_field( $booking_ref )
        ) );
    }

    private static function rollback_error( $code, $message ) {
        global $wpdb;
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( $code, $message );
    }

    private static function idempotency_hash( $key ) {
        $key = trim( (string) $key );
        if ( $key === '' ) return new WP_Error( 'idempotency_required', 'An idempotency key is required for this financial write.' );
        return hash( 'sha256', $key );
    }

    private static function currency( $currency ) {
        $currency = strtoupper( sanitize_text_field( $currency ) );
        return preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'GBP';
    }

    private static function generate_reference( $prefix, $table, $column = 'invoice_ref' ) {
        global $wpdb;
        do {
            try {
                $suffix = strtoupper( bin2hex( random_bytes( 6 ) ) );
            } catch ( Exception $e ) {
                $suffix = strtoupper( wp_generate_password( 12, false, false ) );
            }
            $reference = $prefix . '-' . $suffix;
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE {$column} = %s LIMIT 1", $reference ) );
        } while ( $exists );
        return $reference;
    }
}
