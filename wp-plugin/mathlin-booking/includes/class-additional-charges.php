<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Additional charges are standalone ledger invoices, never hire allocations. */
class MBS_Additional_Charges {
    const MODE = 'additional_charge';

    public function init() {
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_post_mbs_additional_charge', array( $this, 'handle_post' ) );
    }

    public static function is_charge( $invoice ) {
        return $invoice && ( $invoice->billing_mode ?? '' ) === self::MODE;
    }

    public static function payment_state( $invoice ) {
        $claim = class_exists( 'MBS_Invoice_Reservation' ) ? MBS_Invoice_Reservation::get( $invoice->invoice_ref ) : null;
        return $claim ? array( 'status' => $claim->status, 'order_id' => (int) ( $claim->order_id ?? 0 ), 'last_error' => (string) ( $claim->last_error ?? '' ) ) : array( 'status' => 'none', 'order_id' => 0, 'last_error' => '' );
    }

    public static function delivery_history( $invoice ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( 'SELECT id,status,attempts,last_error,created_at,accepted_at FROM ' . $wpdb->prefix . 'mathlin_email_queue WHERE reference_type=%s AND reference_id=%d ORDER BY id DESC LIMIT 50', 'invoice', $invoice->id ), ARRAY_A );
    }

    public static function communications_held( $invoice ) {
        return self::is_charge( $invoice ) && in_array( self::payment_state( $invoice )['status'], array( 'active','bound','reconciliation_required' ), true );
    }

    /** Recheck at dispatch too: a queue entry may predate a payment or credit. */
    public static function queued_delivery_error( $invoice, $meta ) {
        if ( ! MBS_Invoice_Payment::is_payable( $invoice ) ) return 'Additional charge no longer outstanding; review before resending.';
        if ( self::communications_held( $invoice ) ) return 'Additional-charge payment/checkout requires reconciliation; payment request held.';
        if ( isset( $meta['charge_balance_minor'] ) && (int) $meta['charge_balance_minor'] !== MBS_Billing_Ledger::balance_minor( $invoice ) ) return 'Additional-charge balance changed since queueing; explicitly resend the current balance.';
        return '';
    }

    public static function reminders_enabled( $invoice ) {
        $override = get_option( 'mbs_charge_reminders_' . (int) $invoice->id, null );
        return $override !== null ? (string) $override === '1' : ! empty( self::metadata( $invoice )['automatic_reminders'] );
    }

    public static function is_archived( $invoice ) {
        return (string) get_option( 'mbs_charge_archived_' . (int) $invoice->id, '0' ) === '1';
    }

    public static function can_archive( $invoice ) {
        return self::is_charge( $invoice ) && in_array( $invoice->status, array( 'void','paid','credited' ), true )
            && MBS_Billing_Ledger::balance_minor( $invoice ) === 0 && ! self::communications_held( $invoice );
    }

    /** Staff-list preference only: never delete financial or customer history. */
    public static function set_archived( $ref, $version, $archived ) {
        global $wpdb;
        $invoice = self::lock( $ref, $version );
        if ( is_wp_error( $invoice ) ) return $invoice;
        if ( $archived && ! self::can_archive( $invoice ) ) return self::rollback( new WP_Error( 'charge_archive_blocked', 'Discard drafts or cancel outstanding charges first. Resolve any checkout or cash-refund review before archiving.' ) );
        $option = 'mbs_charge_archived_' . (int) $invoice->id;
        $stored = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)", $option, $archived ? '1' : '0' ) );
        $changed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . $wpdb->prefix . MBS_INVOICE_TABLE . ' SET version=version+1,updated_at=%s WHERE id=%d AND version=%d', current_time( 'mysql' ), $invoice->id, $version ) );
        if ( $stored === false || $changed !== 1 ) return self::rollback( new WP_Error( 'charge_archive_failed', 'Could not change archive preference.' ) );
        $result = self::finish( $invoice, $archived ? 'additional_charge_archived' : 'additional_charge_restored', $archived ? 'Hidden from active staff list; invoice, customer records and money unchanged.' : 'Restored to active staff list; money unchanged.' );
        wp_cache_delete( $option, 'options' ); wp_cache_delete( 'notoptions', 'options' );
        return $result;
    }

    public static function archive( $ref, $version ) { return self::set_archived( $ref, $version, true ); }
    public static function restore( $ref, $version ) { return self::set_archived( $ref, $version, false ); }

    /** Full cancellation uses the existing version-locked credit-note service. */
    public static function cancel( $ref, $version, $reason, $key ) {
        if ( ! current_user_can( 'mbs_manage_bookings' ) ) return new WP_Error( 'forbidden', 'Booking-manager access required.' );
        $invoice = MBS_Billing_Ledger::get_invoice( $ref );
        if ( ! self::is_charge( $invoice ) || ! in_array( $invoice->status, array( 'issued','part_paid','paid','overdue' ), true ) ) return new WP_Error( 'charge_not_cancellable', 'Only an issued, not fully credited charge can be cancelled.' );
        return self::credit( $ref, (int) $invoice->total_minor - (int) $invoice->credited_minor, $reason, $version, $key );
    }

    public static function archive_filter_sql( $view = 'active' ) {
        global $wpdb;
        $exists = "EXISTS (SELECT 1 FROM {$wpdb->options} ca WHERE ca.option_name=CONCAT('mbs_charge_archived_',i.id) AND ca.option_value='1')";
        return $view === 'all' ? '1=1' : ( $view === 'archived' ? $exists : 'NOT ' . $exists );
    }

    /** Operational preference only; immutable invoice money/documents stay untouched. */
    public static function set_reminders( $ref, $version, $enabled ) {
        global $wpdb;
        if ( ! is_bool( $enabled ) ) return new WP_Error( 'invalid_reminder_flag', 'Reminder preference must be boolean.' );
        $invoice = self::lock( $ref, $version );
        if ( is_wp_error( $invoice ) ) return $invoice;
        if ( in_array( $invoice->status, array( 'draft','void' ), true ) ) return self::rollback( new WP_Error( 'charge_unissued', 'Use draft edits for unissued charges; discarded charges cannot send reminders.' ) );
        $option = 'mbs_charge_reminders_' . (int) $invoice->id;
        $stored = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)", $option, $enabled ? '1' : '0' ) );
        $changed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . $wpdb->prefix . MBS_INVOICE_TABLE . ' SET version=version+1,updated_at=%s WHERE id=%d AND version=%d', current_time( 'mysql' ), $invoice->id, $version ) );
        if ( $stored === false || $changed !== 1 ) return self::rollback( new WP_Error( 'reminder_update_failed', 'Could not change reminder preference.' ) );
        $result = self::finish( $invoice, 'additional_charge_reminders', $enabled ? 'Automatic overdue reminder enabled.' : 'Automatic overdue reminder disabled.' );
        wp_cache_delete( $option, 'options' ); wp_cache_delete( 'notoptions', 'options' );
        return $result;
    }

    public static function documents( $invoice, $guest = false ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT d.id,d.invoice_number,d.document_type,i.total_minor FROM ' . $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE . ' d INNER JOIN ' . $wpdb->prefix . MBS_INVOICE_TABLE . " i ON i.id=d.invoice_id WHERE (i.id=%d OR i.parent_invoice_id=%d) AND d.status='issued' ORDER BY d.id", $invoice->id, $invoice->id ) );
        $documents = array();
        foreach ( $rows as $row ) {
            $url = $guest ? MBS_Invoice_Delivery_Endpoint::guest_pdf_url( $row->id, 15 * MINUTE_IN_SECONDS, 3 ) : MBS_Invoice_Delivery_Endpoint::authenticated_pdf_url( $row->id );
            if ( is_wp_error( $url ) ) continue;
            $documents[] = array( 'reference' => $row->invoice_number, 'type' => $row->document_type, 'amount_minor' => (int) $row->total_minor, 'pdf_url' => $url );
        }
        return $documents;
    }

    /** Invoked only after reference+email verification, and independently rechecked here. */
    public static function customer_for_booking( $booking, $email ) {
        global $wpdb;
        if ( ! $booking || strcasecmp( $booking->email, $email ) !== 0 ) return array();
        $refs = array( $booking->ref ); if ( ! empty( $booking->series_id ) ) $refs[] = $booking->series_id;
        $clauses = array(); $params = array( $email );
        foreach ( $refs as $ref ) { $clauses[] = 'ci.pricing_snapshot_json LIKE %s'; $params[] = '%"related_ref":' . $wpdb->esc_like( wp_json_encode( $ref ) ) . '%'; }
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT i.* FROM ' . $wpdb->prefix . MBS_INVOICE_TABLE . ' i WHERE i.contact_email=%s AND i.billing_mode=\'additional_charge\' AND i.document_type=\'invoice\' AND i.status NOT IN (\'draft\',\'void\') AND EXISTS (SELECT 1 FROM ' . $wpdb->prefix . MBS_INVOICE_ITEM_TABLE . ' ci WHERE ci.invoice_id=i.id AND (' . implode( ' OR ', $clauses ) . ')) ORDER BY i.id DESC LIMIT 50', $params ) );
        $result = array();
        foreach ( $rows as $invoice ) {
            $items = MBS_Billing_Ledger::get_items( $invoice->id );
            $state = self::payment_state( $invoice );
            $result[] = array( 'invoice_ref' => $invoice->invoice_ref, 'description' => $items ? $items[0]->description : '', 'status' => $invoice->status,
                'total' => MBS_Money::format( (int) $invoice->total_minor ), 'balance' => MBS_Money::format( MBS_Billing_Ledger::balance_minor( $invoice ) ),
                'due_date' => substr( $invoice->due_at, 0, 10 ), 'payment_under_review' => in_array( $state['status'], array( 'bound','reconciliation_required' ), true ),
                'documents' => self::documents( $invoice, true ), 'payment_url' => in_array( $state['status'], array( 'bound','reconciliation_required' ), true ) ? '' : MBS_Invoice_Payment::generate_payment_url( $invoice ) );
        }
        return $result;
    }

    public static function metadata( $invoice ) {
        if ( ! self::is_charge( $invoice ) ) return array();
        $items = MBS_Billing_Ledger::get_items( $invoice->id );
        if ( count( $items ) !== 1 || ! empty( $items[0]->booking_ref ) ) return array();
        $data = json_decode( (string) $items[0]->pricing_snapshot_json, true );
        return is_array( $data ) ? $data : array();
    }

    public static function validate( $data ) {
        foreach ( array( 'amount','category','description','service_date','due_date','contact_name','contact_email' ) as $field ) {
            if ( ! isset( $data[$field] ) || ! is_string( $data[$field] ) ) return new WP_Error( 'invalid_charge_input', 'Charge fields must be plain text; money must be an exact decimal string.' );
        }
        $amount = MBS_Money::from_decimal_string( $data['amount'] );
        if ( is_wp_error( $amount ) || $amount <= 0 || $amount > 100000000 ) {
            return new WP_Error( 'invalid_charge_amount', 'Enter a positive GBP amount with at most two decimal places (maximum £1,000,000).' );
        }
        $category = sanitize_key( $data['category'] ?? '' );
        if ( ! in_array( $category, array( 'cleaning', 'damage', 'other' ), true ) ) return new WP_Error( 'invalid_charge_category', 'Choose a charge category.' );
        $description = sanitize_text_field( $data['description'] ?? '' );
        if ( $description === '' || strlen( $description ) > 500 ) return new WP_Error( 'invalid_charge_description', 'Enter a description of no more than 500 bytes.' );
        foreach ( array( 'service_date', 'due_date' ) as $field ) {
            $value = (string) ( $data[$field] ?? '' );
            $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
            if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) return new WP_Error( 'invalid_charge_date', 'Enter valid service and due dates.' );
        }
        if ( $data['due_date'] < wp_date( 'Y-m-d' ) ) return new WP_Error( 'past_charge_due_date', 'The due date cannot be in the past when saving a draft.' );
        $name = sanitize_text_field( $data['contact_name'] ?? '' );
        $email = sanitize_email( $data['contact_email'] ?? '' );
        if ( ! $name || ! is_email( $email ) ) return new WP_Error( 'invalid_charge_contact', 'Confirm the recipient name and email.' );
        return array(
            'amount_minor' => $amount, 'category' => $category, 'description' => $description,
            'service_date' => $data['service_date'], 'due_date' => $data['due_date'],
            'contact_name' => $name, 'contact_email' => $email,
            'contact_organisation' => sanitize_text_field( $data['contact_organisation'] ?? '' ),
            'contact_address' => sanitize_textarea_field( $data['contact_address'] ?? '' ),
            'notes' => sanitize_textarea_field( $data['notes'] ?? '' ),
            'automatic_reminders' => ! empty( $data['automatic_reminders'] ),
        );
    }

    /** No current pricing/availability rules apply to an independently agreed extra. */
    public static function create( $data, $key ) {
        global $wpdb;
        if ( ! current_user_can( 'mbs_manage_bookings' ) ) return new WP_Error( 'forbidden', 'Booking-manager access required.' );
        if ( ! is_string( $key ) || strlen( $key ) < 8 || strlen( $key ) > 128 ) return new WP_Error( 'invalid_charge_key', 'A stable 8-128 character request key is required.' );
        $clean = self::validate( $data );
        if ( is_wp_error( $clean ) ) return $clean;
        $ref = sanitize_text_field( $data['related_ref'] ?? '' );
        $booking = MBS_Bookings::get( $ref );
        $series = $booking ? null : MBS_Series::get( $ref );
        if ( ! $booking && ! $series ) return new WP_Error( 'charge_link_not_found', 'Choose an existing booking or series.' );
        $meta = array( 'related_ref' => $ref, 'related_kind' => $booking ? 'booking' : 'series',
            'category' => $clean['category'], 'notes' => $clean['notes'], 'automatic_reminders' => $clean['automatic_reminders'] );
        $invoice_data = array_merge( $clean, array( 'billing_mode' => self::MODE, 'currency' => 'GBP',
            'period_start' => $clean['service_date'], 'period_end' => $clean['service_date'],
            'due_at' => $clean['due_date'] . ' 23:59:59', 'charge_metadata' => $meta ) );
        if ( $wpdb->query( 'START TRANSACTION' ) === false ) return new WP_Error( 'transaction_start_failed', 'Could not start charge creation.' );
        $created = MBS_Billing_Ledger::create_draft_invoice( $invoice_data, 'additional-charge:' . $key );
        if ( is_wp_error( $created ) ) return self::rollback( $created );
        if ( ! $created['created'] ) {
            $wpdb->query( 'ROLLBACK' );
            return $created['invoice'];
        }
        $invoice = $created['invoice'];
        $item = MBS_Billing_Ledger::add_item( $invoice->invoice_ref, array(
            'item_type' => 'additional_charge', 'booking_ref' => null,
            'service_date' => $clean['service_date'],
            'description' => $clean['description'],
            'unit_amount_minor' => $clean['amount_minor'], 'quantity_milli' => 1000, 'pricing_snapshot' => $meta,
        ), $invoice->version, false );
        if ( is_wp_error( $item ) ) return self::rollback( $item );
        if ( ! MBS_Audit_Log::log( $invoice->invoice_ref, 'additional_charge_created', 'Additional charge draft linked to ' . $ref . '.' ) ) return self::rollback( new WP_Error( 'audit_failed', 'Could not audit charge creation.' ) );
        if ( $wpdb->query( 'COMMIT' ) === false ) return self::rollback( new WP_Error( 'commit_failed', 'Could not commit charge creation.' ) );
        return MBS_Billing_Ledger::get_invoice( $invoice->invoice_ref );
    }

    /** Draft edits and issuance serialize on the canonical invoice row. */
    public static function update_draft( $ref, $data, $version ) {
        global $wpdb;
        if ( ! current_user_can( 'mbs_manage_bookings' ) ) return new WP_Error( 'forbidden', 'Booking-manager access required.' );
        $clean = self::validate( $data );
        if ( is_wp_error( $clean ) ) return $clean;
        $invoice = self::lock( $ref, $version );
        if ( is_wp_error( $invoice ) ) return $invoice;
        if ( $invoice->status !== 'draft' ) return self::rollback( new WP_Error( 'immutable_charge', 'Only a draft can be edited.' ) );
        foreach ( array( 'contact_organisation', 'contact_address' ) as $field ) {
            if ( ! array_key_exists( $field, $data ) ) $clean[$field] = $invoice->$field;
        }
        $meta = self::metadata( $invoice );
        if ( empty( $meta['related_ref'] ) ) return self::rollback( new WP_Error( 'charge_metadata_missing', 'Charge association is missing.' ) );
        $meta['category'] = $clean['category']; $meta['notes'] = $clean['notes']; $meta['automatic_reminders'] = $clean['automatic_reminders'];
        $items = MBS_Billing_Ledger::get_items( $invoice->id );
        $updated = $wpdb->update( $wpdb->prefix . MBS_INVOICE_ITEM_TABLE, array(
            'description' => $clean['description'],
            'service_date' => $clean['service_date'], 'unit_amount_minor' => $clean['amount_minor'],
            'line_total_minor' => $clean['amount_minor'], 'pricing_snapshot_json' => wp_json_encode( $meta ),
        ), array( 'id' => $items[0]->id, 'invoice_id' => $invoice->id ) );
        if ( $updated === false ) return self::rollback( new WP_Error( 'charge_edit_failed', 'Could not update draft item.' ) );
        $fields = array_intersect_key( $clean, array_flip( array( 'contact_name', 'contact_email', 'contact_address', 'contact_organisation' ) ) );
        $fields = array_merge( $fields, array( 'period_start' => $clean['service_date'], 'period_end' => $clean['service_date'],
            'due_at' => $clean['due_date'] . ' 23:59:59', 'version' => (int) $invoice->version + 1, 'updated_at' => current_time( 'mysql' ) ) );
        if ( $wpdb->update( $wpdb->prefix . MBS_INVOICE_TABLE, $fields, array( 'id' => $invoice->id, 'version' => $version ) ) !== 1 ) return self::rollback( new WP_Error( 'charge_edit_failed', 'Could not update draft invoice.' ) );
        return self::finish( $invoice, 'additional_charge_edited', 'Draft updated.' );
    }

    public static function issue( $ref, $version ) {
        if ( ! current_user_can( 'mbs_manage_bookings' ) ) return new WP_Error( 'forbidden', 'Booking-manager access required.' );
        $logo = MBS_Logo_Asset::resolve_current_org_logo();
        $invoice = self::lock( $ref, $version );
        if ( is_wp_error( $invoice ) ) return $invoice;
        if ( $invoice->status !== 'draft' ) return self::rollback( new WP_Error( 'charge_not_draft', 'This charge is already issued or discarded.' ) );
        $items = MBS_Billing_Ledger::get_items( $invoice->id );
        if ( ! self::metadata( $invoice ) || (int) $items[0]->line_total_minor <= 0 ) return self::rollback( new WP_Error( 'invalid_charge', 'Charge is incomplete.' ) );
        $result = MBS_Billing_Ledger::issue_invoice( $ref, $version, false );
        if ( is_wp_error( $result ) ) return self::rollback( $result );
        $doc = MBS_Invoice_Document_Service::create_ledger_document_within_transaction( $result['invoice'], $items, null, $logo );
        if ( is_wp_error( $doc ) ) return self::rollback( $doc );
        return self::finish( $invoice, 'additional_charge_issued', 'Separate additional-charge invoice issued. No customer email sent.' );
    }

    public static function discard( $ref, $version ) {
        global $wpdb;
        $invoice = self::lock( $ref, $version );
        if ( is_wp_error( $invoice ) ) return $invoice;
        if ( $invoice->status !== 'draft' ) return self::rollback( new WP_Error( 'charge_not_draft', 'Issued charges require a credit note.' ) );
        if ( $wpdb->update( $wpdb->prefix . MBS_INVOICE_TABLE, array( 'status' => 'void', 'void_reason' => 'Draft discarded',
            'voided_at' => current_time( 'mysql' ), 'version' => $version + 1, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $invoice->id ) ) !== 1 ) return self::rollback( new WP_Error( 'discard_failed', 'Could not discard draft.' ) );
        return self::finish( $invoice, 'additional_charge_discarded', 'Unissued draft discarded; financial record retained.' );
    }

    /** Credit is not a cash refund. Refuse checkout/capture ambiguity. */
    public static function credit( $ref, $amount, $reason, $version, $key ) {
        if ( ! current_user_can( 'mbs_manage_bookings' ) ) return new WP_Error( 'forbidden', 'Booking-manager access required.' );
        if ( ! is_string( $key ) || strlen( $key ) < 8 || strlen( $key ) > 128 ) return new WP_Error( 'invalid_charge_key', 'A stable request key is required.' );
        $logo = MBS_Logo_Asset::resolve_current_org_logo();
        $invoice = self::lock( $ref, $version );
        if ( is_wp_error( $invoice ) ) return $invoice;
        $reservation = MBS_Invoice_Reservation::get( $ref );
        if ( $reservation && in_array( $reservation->status, array( 'active', 'bound', 'reconciliation_required' ), true ) ) return self::rollback( new WP_Error( 'charge_checkout_active', 'Resolve or expire the outstanding checkout before crediting this charge.' ) );
        if ( trim( $reason ) === '' ) return self::rollback( new WP_Error( 'credit_reason_required', 'Enter a credit reason.' ) );
        $result = MBS_Billing_Ledger::create_credit_note( $ref, $amount, $reason, 'additional-charge-credit:' . $key, false );
        if ( is_wp_error( $result ) ) return self::rollback( $result );
        if ( ! empty( $result['created'] ) ) {
            $cn = $result['credit_note'];
            $doc = MBS_Invoice_Document_Service::create_ledger_document_within_transaction( $cn, MBS_Billing_Ledger::get_items( $cn->id ), null, $logo );
            if ( is_wp_error( $doc ) ) return self::rollback( $doc );
        }
        return self::finish( $invoice, 'additional_charge_credited', 'Charge credited. No cash refund or booking change performed.' );
    }

    /** Explicit, idempotent delivery; issuance itself never sends. */
    public static function send( $ref, $version, $key = '' ) {
        return self::send_document( $ref, $version, '', $key, true );
    }

    public static function resend( $ref, $version, $key ) {
        if ( ! is_string( $key ) || strlen( $key ) < 8 || strlen( $key ) > 128 ) return new WP_Error( 'invalid_request_key', 'Resending requires a stable request key.' );
        return self::send_document( $ref, $version, '', $key );
    }

    public static function send_credit( $ref, $version, $document_ref, $key ) {
        if ( ! is_string( $document_ref ) || trim( $document_ref ) === '' ) return new WP_Error( 'credit_reference_required', 'Choose a credit-note reference.' );
        if ( ! is_string( $key ) || strlen( $key ) < 8 || strlen( $key ) > 128 ) return new WP_Error( 'invalid_request_key', 'Credit email requires a stable request key.' );
        return self::send_document( $ref, $version, $document_ref, $key );
    }

    private static function send_document( $ref, $version, $document_ref, $key, $reuse_pending = false ) {
        $invoice = self::lock( $ref, $version );
        if ( is_wp_error( $invoice ) ) return $invoice;
        $credit = $document_ref ? MBS_Billing_Ledger::get_invoice( $document_ref ) : null;
        if ( $document_ref && ( ! $credit || $credit->document_type !== 'credit_note' || (int) $credit->parent_invoice_id !== (int) $invoice->id ) ) return self::rollback( new WP_Error( 'wrong_charge_document', 'Credit note does not belong to this charge.' ) );
        if ( ! $credit && self::communications_held( $invoice ) ) return self::rollback( new WP_Error( 'charge_payment_under_review', 'Checkout/payment requires review; no payment request will be sent.' ) );
        if ( ! $credit && ! MBS_Invoice_Payment::is_payable( $invoice ) ) return self::rollback( new WP_Error( 'charge_not_payable', 'Only an outstanding issued charge can be sent.' ) );
        global $wpdb;
        // An explicit Send on an already queued invoice dispatches that same row.
        // Resend remains a separate, deliberately new message operation.
        if ( ! $credit && $reuse_pending ) {
            $pending = $wpdb->get_row( $wpdb->prepare( 'SELECT id,status,to_email,attachment_meta FROM ' . $wpdb->prefix . "mathlin_email_queue WHERE reference_type='invoice' AND reference_id=%d AND message_type='invoice_issued' AND status IN ('pending','processing','sent') ORDER BY id DESC LIMIT 1 FOR UPDATE", $invoice->id ) );
            $pending_meta = $pending && ! empty( $pending->attachment_meta ) ? json_decode( $pending->attachment_meta, true ) : array();
            if ( $pending && isset( $pending->to_email, $pending_meta['charge_balance_minor'] ) && strcasecmp( $pending->to_email, $invoice->contact_email ) === 0 && (int) $pending_meta['charge_balance_minor'] === MBS_Billing_Ledger::balance_minor( $invoice ) ) {
                $committed = self::finish( $invoice, 'additional_charge_email_queued', 'Existing invoice delivery retained; pending delivery is attempted now, accepted/processing delivery is not duplicated.' );
                if ( is_wp_error( $committed ) ) return $committed;
                MBS_Email_Queue::dispatch_message( (int) $pending->id );
                return MBS_Billing_Ledger::get_invoice( $ref );
            }
        }
        $doc = $credit ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE . " WHERE invoice_id=%d AND document_type='credit_note' AND status='issued' ORDER BY id DESC LIMIT 1", $credit->id ) ) : MBS_Invoice_Document_Service::get_current_ledger_document_id( $invoice->id );
        $url = $credit ? '' : MBS_Invoice_Payment::generate_payment_url( $invoice );
        if ( ! $doc || ( ! $credit && ! $url ) ) return self::rollback( new WP_Error( 'charge_delivery_unavailable', 'PDF or online payment is unavailable; nothing sent.' ) );
        $items = MBS_Billing_Ledger::get_items( $invoice->id );
        $template = MBS_Email_Templates::get_template( $credit ? 'additional_charge_credit' : 'additional_charge_invoice' );
        $values = array( '{name}' => $invoice->contact_name, '{invoice}' => $ref, '{credit}' => $credit ? $credit->invoice_ref : '', '{description}' => $items[0]->description,
            '{balance}' => MBS_Money::format( MBS_Billing_Ledger::balance_minor( $invoice ) ), '{credit_amount}' => $credit ? MBS_Money::format( abs( (int) $credit->total_minor ) ) : '',
            '{due_date}' => substr( $invoice->due_at, 0, 10 ), '{org_name}' => MBS_Email_Templates::get_org_settings()['name'] );
        $subject = sanitize_text_field( strtr( $template['subject'], $values ) );
        $body = '<div>' . nl2br( esc_html( strtr( $template['body'], $values ) ) ) . '</div>';
        // The mandatory factual footer is not editable away with a template.
        $body .= '<p>Invoice ' . esc_html( $ref ) . '. Balance: ' . esc_html( $values['{balance}'] ) . '. ' . ( $credit ? 'Credit ' . esc_html( $credit->invoice_ref . ': ' . $values['{credit_amount}'] ) . '. A credit note does not itself refund cash.' : 'Due: ' . esc_html( $values['{due_date}'] ) . '. <a href="' . esc_url( $url ) . '">Pay this additional charge</a>' ) . '</p><p>Your original hire invoice and booking remain unchanged.</p>';
        $headers = array( 'Content-Type: text/html; charset=UTF-8', 'Reply-To: ' . MBS_Bookings::get_admin_email() );
        $attachment = array( 'document_id' => $doc, 'format' => 'pdf' );
        if ( ! $credit ) $attachment['charge_balance_minor'] = MBS_Billing_Ledger::balance_minor( $invoice );
        $hash = MBS_Email_Queue::compute_payload_hash( $invoice->contact_email, $subject, $body, $headers, $attachment );
        $message_key = 'additional_charge:' . hash( 'sha256', $ref . ':doc' . $doc . ':' . ( $key ?: 'version-' . $version ) );
        $queued = MBS_Email_Queue::enqueue( $invoice->contact_email, $subject, $body, $headers,
            $message_key, $hash, $attachment,
            array( 'message_type' => $credit ? 'additional_charge_credit' : 'invoice_issued', 'reference_type' => 'invoice', 'reference_id' => (int) $invoice->id ) );
        if ( is_wp_error( $queued ) ) return self::rollback( $queued );
        $committed = self::finish( $invoice, 'additional_charge_email_queued', 'Invoice queued for immediate dispatch after commit; delivery status is recorded separately.' );
        if ( is_wp_error( $committed ) ) return $committed;
        $message_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'mathlin_email_queue WHERE message_key=%s', $message_key ) );
        if ( $message_id ) MBS_Email_Queue::dispatch_message( $message_id );
        return MBS_Billing_Ledger::get_invoice( $ref );
    }

    private static function lock( $ref, $version ) {
        global $wpdb;
        if ( ! current_user_can( 'mbs_manage_bookings' ) ) return new WP_Error( 'forbidden', 'Booking-manager access required.' );
        if ( $wpdb->query( 'START TRANSACTION' ) === false ) return new WP_Error( 'transaction_start_failed', 'Could not start charge operation.' );
        $invoice = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . MBS_INVOICE_TABLE . ' WHERE invoice_ref=%s FOR UPDATE', $ref ) );
        if ( ! self::is_charge( $invoice ) || $invoice->document_type !== 'invoice' ) return self::rollback( new WP_Error( 'charge_not_found', 'Additional charge not found.' ) );
        if ( (int) $invoice->version !== (int) $version ) return self::rollback( new WP_Error( 'stale_charge', 'The charge changed. Reload and review before trying again.' ) );
        return $invoice;
    }

    private static function rollback( $error ) { global $wpdb; $wpdb->query( 'ROLLBACK' ); return $error; }
    private static function finish( $invoice, $action, $message ) {
        global $wpdb;
        if ( ! MBS_Audit_Log::log( $invoice->invoice_ref, $action, $message ) ) return self::rollback( new WP_Error( 'audit_failed', 'Could not audit charge operation.' ) );
        if ( $wpdb->query( 'COMMIT' ) === false ) return self::rollback( new WP_Error( 'commit_failed', 'Could not commit charge operation.' ) );
        return MBS_Billing_Ledger::get_invoice( $invoice->invoice_ref );
    }

    public function menu() {
        add_submenu_page( 'mathlin-booking', 'Additional charges', 'Additional charges', 'mbs_manage_bookings', 'mathlin-additional-charges', array( $this, 'render' ) );
    }

    public function handle_post() {
        if ( ! current_user_can( 'mbs_manage_bookings' ) ) wp_die( 'Forbidden', '', array( 'response' => 403 ) );
        check_admin_referer( 'mbs_additional_charge' );
        $data = wp_unslash( $_POST );
        $op = sanitize_key( $data['operation'] ?? '' );
        $ref = sanitize_text_field( $data['invoice_ref'] ?? '' );
        $version = absint( $data['version'] ?? 0 );
        $key = sanitize_text_field( $data['idempotency_key'] ?? '' );
        if ( $op === 'create' ) $result = self::create( $data, $key );
        elseif ( $op === 'edit' ) $result = self::update_draft( $ref, $data, $version );
        elseif ( $op === 'issue' ) $result = self::issue( $ref, $version );
        elseif ( $op === 'send' ) $result = self::send( $ref, $version, $key );
        elseif ( $op === 'resend' ) $result = self::resend( $ref, $version, $key );
        elseif ( $op === 'send_credit' ) $result = self::send_credit( $ref, $version, sanitize_text_field( $data['document_ref'] ?? '' ), $key );
        elseif ( $op === 'set_reminders' ) $result = self::set_reminders( $ref, $version, ! empty( $data['enabled'] ) );
        elseif ( $op === 'discard' ) $result = self::discard( $ref, $version );
        elseif ( $op === 'archive' || $op === 'restore' ) $result = self::$op( $ref, $version );
        elseif ( $op === 'cancel' ) $result = self::cancel( $ref, $version, sanitize_text_field( $data['reason'] ?? '' ), $key );
        elseif ( $op === 'credit' ) {
            $amount = MBS_Money::from_decimal_string( (string) ( $data['credit_amount'] ?? '' ) );
            $result = is_wp_error( $amount ) ? $amount : self::credit( $ref, $amount, sanitize_text_field( $data['reason'] ?? '' ), $version, $key );
        } elseif ( $op === 'manual_payment' ) {
            $invoice = MBS_Billing_Ledger::get_invoice( $ref );
            $amount = MBS_Money::from_decimal_string( (string) ( $data['payment_amount'] ?? '' ) );
            $result = ! self::is_charge( $invoice ) ? new WP_Error( 'charge_not_found', 'Charge not found.' ) : ( is_wp_error( $amount ) ? $amount : MBS_Invoice_Payment::record_manual_payment( $ref, $amount, 'additional-charge-manual:' . $key, $version, sanitize_text_field( $data['reason'] ?? '' ) ) );
        } else $result = new WP_Error( 'invalid_operation', 'Unknown charge action.' );
        if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ), 'Additional charge', array( 'back_link' => true ) );
        $result_ref = is_object( $result ) ? ( $result->invoice_ref ?? '' ) : ( $result['invoice']->invoice_ref ?? $ref );
        wp_safe_redirect( self::admin_url( array( 'invoice_ref' => $result_ref ) ) ); exit;
    }

    public static function admin_url( $args = array() ) {
        return add_query_arg( array_merge( array( 'page' => 'mathlin-additional-charges' ), $args ), admin_url( 'admin.php' ) );
    }

    /** Information-only association; never change the booking's financial ledger. */
    public static function related_context( $ref ) {
        $booking = $ref ? MBS_Bookings::get( $ref ) : null;
        if ( $booking ) return array( 'url' => add_query_arg( array( 'page'=>'mathlin-booking','action'=>'view','ref'=>$ref ), admin_url('admin.php') ), 'label'=>$ref,
            'summary'=>trim( ( $booking->purpose ?? $booking->name ) . ' · ' . ( $booking->booking_date ?? '' ) . ' · ' . ( $booking->space ?? '' ), ' ·' ) );
        $series = $ref ? MBS_Series::get( $ref ) : null;
        if ( $series ) return array( 'url'=>add_query_arg( array( 'page'=>empty($series->scout_use)?'mathlin-series':'mathlin-scout-nights','ref'=>$ref ),admin_url('admin.php') ),'label'=>$ref,
            'summary'=>trim( ( $series->purpose ?? $series->contact_name ) . ' · Recurring series · ' . ( $series->space ?? '' ), ' ·' ) );
        return array('url'=>'','label'=>$ref ?: 'Association missing','summary'=>'Original booking unavailable; invoice retained.');
    }

    private static function list_rows( $related, $search = '', $status = '', $page = 1, $limit = 50, $archive_view = 'active' ) {
        global $wpdb;
        $table=$wpdb->prefix.MBS_INVOICE_TABLE; $items=$wpdb->prefix.MBS_INVOICE_ITEM_TABLE;
        $where=array("i.billing_mode='additional_charge'","i.document_type='invoice'");$args=array();
        $where[]=self::archive_filter_sql($archive_view);
        if($related){$where[]="EXISTS (SELECT 1 FROM {$items} ci WHERE ci.invoice_id=i.id AND ci.pricing_snapshot_json LIKE %s)";$args[]='%"related_ref":'.$wpdb->esc_like(wp_json_encode($related)).'%';}
        if($search!==''){$where[]='(i.invoice_ref LIKE %s OR i.contact_name LIKE %s OR i.contact_email LIKE %s OR EXISTS (SELECT 1 FROM '.$items.' si WHERE si.invoice_id=i.id AND (si.description LIKE %s OR si.pricing_snapshot_json LIKE %s)))';$like='%'.$wpdb->esc_like($search).'%';for($n=0;$n<5;$n++)$args[]=$like;}
        if($status==='overdue'){$where[]="i.status IN ('issued','part_paid','overdue') AND i.total_minor>i.paid_minor+i.credited_minor AND i.due_at<%s";$args[]=current_time('mysql');}
        elseif($status==='payment_review')$where[]="EXISTS (SELECT 1 FROM ".$wpdb->prefix.MBS_PAYMENT_RESERVATION_TABLE." pr WHERE pr.invoice_ref=i.invoice_ref AND pr.status IN ('bound','reconciliation_required'))";
        elseif(in_array($status,array('draft','issued','part_paid','paid','credited','void'),true)){$where[]='i.status=%s';$args[]=$status;}
        $args[]=$limit+1;$args[]=($page-1)*$limit;
        return $wpdb->get_results($wpdb->prepare("SELECT i.* FROM {$table} i WHERE ".implode(' AND ',$where).' ORDER BY i.id DESC LIMIT %d OFFSET %d',$args));
    }

    public static function status_label( $invoice ) {
        $status=MBS_Invoice_Payment::is_payable($invoice)&&$invoice->due_at<current_time('mysql')?'overdue':$invoice->status;
        $labels=array('draft'=>'Draft','issued'=>'Awaiting payment','part_paid'=>'Part paid','paid'=>'Paid','credited'=>'Credited','void'=>'Discarded','overdue'=>'Overdue');
        return $labels[$status]??ucfirst(str_replace('_',' ',$status));
    }

    public static function render_related( $ref ) {
        if(!current_user_can('mbs_manage_bookings'))return;
        $rows=self::list_rows($ref,'','',1,10,'all');
        echo '<section class="nms-card mbs-related-charges"><div class="nms-card-header"><h2>Additional charges</h2></div><div class="mbs-charge-card-body"><p class="nms-muted">Separate from the original hire invoice and access arrangements.</p><p><a class="button button-primary" href="'.esc_url(self::admin_url(array('view'=>'create','related_ref'=>$ref))).'">Add additional charge</a> <a class="button" href="'.esc_url(self::admin_url(array('related_ref'=>$ref))).'">View all linked charges</a></p>';
        if(!$rows)echo '<p>No additional charges for this booking.</p>';
        else{echo '<div class="mbs-series-table-wrap"><table class="widefat striped"><thead><tr><th>Invoice</th><th>Description</th><th>Amount</th><th>Balance</th><th>Status</th></tr></thead><tbody>';
            foreach(array_slice($rows,0,10) as $invoice){$items=MBS_Billing_Ledger::get_items($invoice->id);$amount=$invoice->status==='draft'&&$items?(int)$items[0]->line_total_minor:(int)$invoice->total_minor;
                echo '<tr><td><a href="'.esc_url(self::admin_url(array('invoice_ref'=>$invoice->invoice_ref))).'">'.esc_html($invoice->invoice_ref).'</a></td><td>'.esc_html($items?$items[0]->description:'Incomplete draft').'</td><td>'.esc_html(MBS_Money::format($amount)).'</td><td>'.esc_html($invoice->status==='draft'?'—':MBS_Money::format(MBS_Billing_Ledger::balance_minor($invoice))).'</td><td>'.esc_html(self::status_label($invoice)).'</td></tr>';
            }echo '</tbody></table></div>';
        }echo '</div></section>';
    }

    private static function form_start( $invoice = null, $class = '' ) {
        echo '<form class="'.esc_attr($class).'" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'mbs_additional_charge' );
        echo '<input type="hidden" name="action" value="mbs_additional_charge"><input type="hidden" name="idempotency_key" value="' . esc_attr( wp_generate_uuid4() ) . '">';
        if ( $invoice ) echo '<input type="hidden" name="invoice_ref" value="' . esc_attr( $invoice->invoice_ref ) . '"><input type="hidden" name="version" value="' . (int) $invoice->version . '">';
    }

    private static function field( $name, $label, $value = '', $type = 'text', $required = true ) {
        echo '<p class="mbs-charge-field"><label>' . esc_html( $label ) . '<br><input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" ' . ( $required ? 'required' : '' ) . '></label></p>';
    }

    public function render() {
        global $wpdb;
        if ( ! current_user_can( 'mbs_manage_bookings' ) ) return;
        $related = sanitize_text_field( wp_unslash( $_GET['related_ref'] ?? '' ) );
        $booking = $related ? MBS_Bookings::get( $related ) : null;
        $series = $related && ! $booking ? MBS_Series::get( $related ) : null;
        $view=sanitize_key($_GET['view']??'');
        $selected_ref=sanitize_text_field(wp_unslash($_GET['invoice_ref']??''));
        echo '<div class="wrap mbs-admin mbs-charges-admin"><h1 class="wp-heading-inline">'.MBS_Admin::brand_mark().'Additional charges</h1> <a class="page-title-action" href="'.esc_url(self::admin_url(array('view'=>'create','related_ref'=>$related))).'">Add charge</a><hr class="wp-header-end"><p class="nms-muted">Separate invoices for agreed extras, linked to the original booking. Hire payments and access remain unchanged.</p><nav class="mbs-charge-breadcrumbs"><a href="'.esc_url(admin_url('admin.php?page=mathlin-booking')).'">All bookings</a> <span> / </span> <a href="'.esc_url(self::admin_url()).'">Additional charges</a></nav>';
        if($related){$context=self::related_context($related);echo '<p class="mbs-charge-context">For '.($context['url']?'<a href="'.esc_url($context['url']).'">'.esc_html($context['label']).'</a>':esc_html($context['label'])).' — '.esc_html($context['summary']).'</p>';}
        if($view!=='create'&&!$selected_ref){
            $search=sanitize_text_field(wp_unslash($_GET['charge_search']??''));$filter_status=sanitize_key($_GET['charge_status']??'');$page=max(1,absint($_GET['charge_page']??1));
            $archive_view=sanitize_key($_GET['archive_view']??'active');if(!in_array($archive_view,array('active','archived','all'),true))$archive_view='active';
            $rows=self::list_rows($related,$search,$filter_status,$page,50,$archive_view);$has_next=count($rows)>50;$rows=array_slice($rows,0,50);
            include MBS_PLUGIN_DIR.'admin/views/additional-charges.php';echo '</div>';return;
        }
        if($view==='create'){
        echo '<section class="nms-card"><div class="nms-card-header"><h2>New additional charge</h2></div><div class="mbs-charge-card-body mbs-charge-editor">';
        self::form_start();
        self::field( 'related_ref', 'Booking or series reference', $related );
        self::field( 'contact_name', 'Recipient name (confirm before issue)', $booking ? $booking->name : ( $series ? $series->contact_name : '' ) );
        self::field( 'contact_email', 'Recipient email', $booking ? $booking->email : ( $series ? $series->contact_email : '' ), 'email' );
        self::field( 'contact_organisation', 'Organisation (optional)', $booking ? $booking->organisation : ( $series ? $series->contact_organisation : '' ), 'text', false );
        self::field( 'contact_address', 'Billing address (optional)', $booking ? $booking->address : ( $series ? $series->contact_address : '' ), 'text', false );
        self::field( 'description', 'Customer-visible description' ); self::field( 'amount', 'Amount in GBP, e.g. 25.00' );
        self::field( 'service_date', 'Service date', $booking ? $booking->booking_date : wp_date( 'Y-m-d' ), 'date' );
        self::field( 'due_date', 'Due date', wp_date( 'Y-m-d', time() + 14 * DAY_IN_SECONDS ), 'date' );
        echo '<p><label>Category <select name="category"><option value="cleaning">Cleaning</option><option value="damage">Damage</option><option value="other">Other</option></select></label></p><p><label>Internal notes (not on invoice)<br><textarea name="notes"></textarea></label></p><p><label><input type="checkbox" name="automatic_reminders" value="1">Enable one automatic overdue reminder</label></p><p class="mbs-charge-editor-footer"><button class="button button-primary" name="operation" value="create">Save draft only</button> <a class="button" href="'.esc_url(self::admin_url(array('related_ref'=>$related))).'">Cancel</a></p></form></div></section></div>';return;
        }
        $invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$invoice_table} WHERE billing_mode=%s AND document_type='invoice' AND invoice_ref=%s LIMIT 1", self::MODE, $selected_ref ) );
        foreach ( $rows as $invoice ) {
            $meta = self::metadata( $invoice ); $items = MBS_Billing_Ledger::get_items( $invoice->id );
            $item = $items ? $items[0] : null;
            $balance = MBS_Billing_Ledger::balance_minor( $invoice );
            $status = MBS_Invoice_Payment::is_payable( $invoice ) && $invoice->due_at < current_time( 'mysql' ) ? 'overdue' : $invoice->status;
            $context=self::related_context($meta['related_ref']??'');
            echo '<section class="nms-card mbs-charge-detail"><div class="nms-card-header"><h2>' . esc_html( $invoice->invoice_ref . ' · ' . self::status_label($invoice) ) . '</h2></div><div class="mbs-charge-card-body"><p><strong>' . esc_html( $item ? $item->description : 'Incomplete draft' ) . '</strong></p><p>' . esc_html( $invoice->contact_name . ' <' . $invoice->contact_email . '>' ) . '</p><p>Original booking: '.($context['url']?'<a href="'.esc_url($context['url']).'">'.esc_html($context['label']).'</a>':esc_html($context['label'])).'<br><span class="nms-muted">'.esc_html($context['summary']).'</span></p><p>Total: ' . esc_html( MBS_Money::format( $invoice->status === 'draft' && $item ? (int) $item->line_total_minor : (int) $invoice->total_minor ) ) . ' | Paid: ' . esc_html( MBS_Money::format( (int) $invoice->paid_minor ) ) . ' | Balance: ' . esc_html( MBS_Money::format( $balance ) ) . ' | Due: ' . esc_html( $invoice->due_at ) . '</p>';
            $doc = MBS_Invoice_Document_Service::get_current_ledger_document_id( $invoice->id );
            if ( $doc ) echo '<p><a href="' . esc_url( MBS_Invoice_Delivery_Endpoint::authenticated_pdf_url( $doc ) ) . '">Download invoice PDF</a></p>';
            $credits = $wpdb->get_results( $wpdb->prepare( "SELECT id,invoice_ref,total_minor FROM {$invoice_table} WHERE parent_invoice_id=%d AND document_type='credit_note' ORDER BY id", $invoice->id ) );
            foreach ( $credits as $credit ) {
                $credit_doc = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE . " WHERE invoice_id=%d AND document_type='credit_note' AND status='issued' ORDER BY id DESC LIMIT 1", $credit->id ) );
                echo '<p>Credit: ' . esc_html( $credit->invoice_ref . ' ' . MBS_Money::format( (int) $credit->total_minor ) );
                if ( $credit_doc ) echo ' <a href="' . esc_url( MBS_Invoice_Delivery_Endpoint::authenticated_pdf_url( $credit_doc ) ) . '">Download credit PDF</a>';
                echo '</p>';
                if ( $credit_doc ) { self::form_start( $invoice ); echo '<input type="hidden" name="document_ref" value="' . esc_attr( $credit->invoice_ref ) . '"><button class="button" name="operation" value="send_credit">Confirm and queue credit-note email</button></form>'; }
            }
            if ( (int) $invoice->paid_minor + (int) $invoice->credited_minor > (int) $invoice->total_minor ) echo '<p><strong>Cash refund review required: ' . esc_html( MBS_Money::format( (int) $invoice->paid_minor + (int) $invoice->credited_minor - (int) $invoice->total_minor ) ) . '.</strong> Credit alone does not return money.</p>';
            echo '<div class="mbs-charge-action-grid">';
            if(self::is_archived($invoice)){
                self::form_start($invoice);echo '<p>Archived from staff lists only. Customer invoice records are retained.</p><button class="button" name="operation" value="restore">Restore charge</button></form>';
            }elseif(self::can_archive($invoice)){
                self::form_start($invoice);echo '<p>Hide from the active staff list without deleting invoice history or changing money.</p><button class="button" name="operation" value="archive">Archive charge</button></form>';
            }else{echo '<p>To archive, discard a draft or cancel the outstanding charge first. Resolve any checkout or cash-refund review.</p>';}
            if ( $invoice->status === 'draft' ) {
                self::form_start( $invoice, 'mbs-charge-edit-form' );
                foreach ( array( 'contact_name','contact_email' ) as $field ) self::field( $field, $field, $invoice->$field, $field === 'contact_email' ? 'email' : 'text' );
                self::field( 'contact_organisation', 'Organisation', $invoice->contact_organisation, 'text', false );
                self::field( 'contact_address', 'Billing address', $invoice->contact_address, 'text', false );
                $description = $item ? $item->description : '';
                self::field( 'description', 'Description', $description ); self::field( 'amount', 'GBP amount', $item ? MBS_Money::decimal( (int) $item->line_total_minor ) : '' );
                self::field( 'service_date', 'Service date', $invoice->period_start, 'date' ); self::field( 'due_date', 'Due date', substr( $invoice->due_at, 0, 10 ), 'date' );
                echo '<p>Category <select name="category">';
                foreach ( array( 'cleaning','damage','other' ) as $category ) echo '<option value="' . esc_attr( $category ) . '" ' . selected( $meta['category'] ?? 'other', $category, false ) . '>' . esc_html( ucfirst( $category ) ) . '</option>';
                echo '</select></p><textarea name="notes">' . esc_textarea( $meta['notes'] ?? '' ) . '</textarea><p><label><input type="checkbox" name="automatic_reminders" value="1" ' . checked( ! empty( $meta['automatic_reminders'] ), true, false ) . '>Enable overdue reminder</label></p><button class="button" name="operation" value="edit">Save draft edits</button></form>';
                self::form_start( $invoice ); echo '<p>Issue freezes this invoice and makes it visible to its signed-in recipient. It does not email it.</p><button class="button button-primary" name="operation" value="issue">Confirm and issue</button> <button class="button" name="operation" value="discard">Discard draft</button></form>';
            } elseif ( MBS_Invoice_Payment::is_payable( $invoice ) && ! self::communications_held( $invoice ) ) {
                self::form_start( $invoice ); echo '<p>Send to ' . esc_html( $invoice->contact_email ) . ' with PDF and current outstanding balance/payment link. Sending attempts delivery immediately; failures remain queued for retry.</p><button class="button" name="operation" value="send">Confirm and send invoice now</button> <button class="button" name="operation" value="resend">Confirm explicit resend</button></form>';
                self::form_start( $invoice ); self::field( 'payment_amount', 'Offline payment received (GBP)' ); self::field( 'reason', 'Payment reference / note' ); echo '<button class="button" name="operation" value="manual_payment">Confirm money received</button></form>';
            }
            if ( in_array( $invoice->status, array( 'issued','part_paid','paid','overdue','credited' ), true ) && (int) $invoice->total_minor > (int) $invoice->credited_minor ) {
                self::form_start( $invoice ); self::field( 'reason', 'Cancellation reason' ); echo '<p>Cancel the full remaining charge by issuing a credit note for '.esc_html(MBS_Money::format((int)$invoice->total_minor-(int)$invoice->credited_minor)).'. This retains the original invoice and does not send an email or refund money. Any money already paid needs a separate refund review.</p><button class="button" name="operation" value="cancel">Cancel charge — issue full credit note</button></form>';
                self::form_start( $invoice ); self::field( 'credit_amount', 'Partial credit amount (GBP)' ); self::field( 'reason', 'Credit reason' ); echo '<p>Reduce only part of the charge. A credit note is not a cash refund.</p><button class="button" name="operation" value="credit">Issue partial credit note</button></form>';
            }
            echo '</div>';
            $state = self::payment_state( $invoice );
            echo '<p>Payment processing: ' . esc_html( $state['status'] ) . ( $state['order_id'] ? ' — WooCommerce order ' . (int) $state['order_id'] : '' ) . '</p>';
            if ( self::communications_held( $invoice ) ) echo '<p><strong>Payment/checkout held for review. Resolve the WooCommerce order and invoice reconciliation before requesting more money. Automatic reminders are suppressed.</strong></p>';
            if ( $invoice->status !== 'draft' && $invoice->status !== 'void' ) {
                self::form_start( $invoice ); echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked( self::reminders_enabled( $invoice ), true, false ) . '>Enable one automatic overdue reminder</label> (already sent: ' . (int) $invoice->reminder_count . '). Changing this setting does not reset the one-reminder limit.</p><button class="button" name="operation" value="set_reminders">Save reminder preference</button></form>';
            }
            echo '<details><summary>Staff history and association</summary><p>Related: ' . esc_html( $meta['related_ref'] ?? '' ) . '</p><p>Internal notes: ' . esc_html( $meta['notes'] ?? '' ) . '</p>';
            foreach ( MBS_Hirer_Portal::invoice_transactions( $invoice->id ) as $tx ) echo '<p>' . esc_html( $tx->occurred_at . ' — ' . $tx->transaction_type . ' ' . MBS_Money::format( (int) $tx->amount_minor ) ) . '</p>';
            foreach ( self::delivery_history( $invoice ) as $delivery ) echo '<p>Delivery: ' . esc_html( $delivery['status'] . ' — attempts ' . $delivery['attempts'] . ' — queued ' . $delivery['created_at'] . ' — accepted ' . $delivery['accepted_at'] . ' ' . $delivery['last_error'] ) . '</p>';
            $audit = $wpdb->get_results( $wpdb->prepare( 'SELECT action,details,user_name,created_at FROM ' . $wpdb->prefix . 'mathlin_audit_log WHERE ref=%s ORDER BY id DESC LIMIT 50', $invoice->invoice_ref ) );
            foreach ( $audit as $event ) echo '<p>' . esc_html( $event->created_at . ' — ' . $event->user_name . ' — ' . $event->action . ': ' . $event->details ) . '</p>';
            echo '</details></div></section>';
        }
        if(!$rows)echo '<div class="notice notice-error"><p>Additional charge not found. <a href="'.esc_url(self::admin_url()).'">Return to charges</a></p></div>';
        echo '</div>';
    }
}
