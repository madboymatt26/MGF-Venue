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
        return self::send_document( $ref, $version, '', $key );
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

    private static function send_document( $ref, $version, $document_ref, $key ) {
        $invoice = self::lock( $ref, $version );
        if ( is_wp_error( $invoice ) ) return $invoice;
        $credit = $document_ref ? MBS_Billing_Ledger::get_invoice( $document_ref ) : null;
        if ( $document_ref && ( ! $credit || $credit->document_type !== 'credit_note' || (int) $credit->parent_invoice_id !== (int) $invoice->id ) ) return self::rollback( new WP_Error( 'wrong_charge_document', 'Credit note does not belong to this charge.' ) );
        if ( ! $credit && self::communications_held( $invoice ) ) return self::rollback( new WP_Error( 'charge_payment_under_review', 'Checkout/payment requires review; no payment request will be sent.' ) );
        if ( ! $credit && ! MBS_Invoice_Payment::is_payable( $invoice ) ) return self::rollback( new WP_Error( 'charge_not_payable', 'Only an outstanding issued charge can be sent.' ) );
        global $wpdb;
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
        $queued = MBS_Email_Queue::enqueue( $invoice->contact_email, $subject, $body, $headers,
            'additional_charge:' . hash( 'sha256', $ref . ':doc' . $doc . ':' . ( $key ?: 'version-' . $version ) ), $hash, $attachment,
            array( 'message_type' => $credit ? 'additional_charge_credit' : 'invoice_issued', 'reference_type' => 'invoice', 'reference_id' => (int) $invoice->id ) );
        if ( is_wp_error( $queued ) ) return self::rollback( $queued );
        return self::finish( $invoice, 'additional_charge_email_queued', 'Invoice delivery queued (not proof of delivery).' );
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
        elseif ( $op === 'credit' ) {
            $amount = MBS_Money::from_decimal_string( (string) ( $data['credit_amount'] ?? '' ) );
            $result = is_wp_error( $amount ) ? $amount : self::credit( $ref, $amount, sanitize_text_field( $data['reason'] ?? '' ), $version, $key );
        } elseif ( $op === 'manual_payment' ) {
            $invoice = MBS_Billing_Ledger::get_invoice( $ref );
            $amount = MBS_Money::from_decimal_string( (string) ( $data['payment_amount'] ?? '' ) );
            $result = ! self::is_charge( $invoice ) ? new WP_Error( 'charge_not_found', 'Charge not found.' ) : ( is_wp_error( $amount ) ? $amount : MBS_Invoice_Payment::record_manual_payment( $ref, $amount, 'additional-charge-manual:' . $key, $version, sanitize_text_field( $data['reason'] ?? '' ) ) );
        } else $result = new WP_Error( 'invalid_operation', 'Unknown charge action.' );
        if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ), 'Additional charge', array( 'back_link' => true ) );
        wp_safe_redirect( admin_url( 'admin.php?page=mathlin-additional-charges' ) ); exit;
    }

    private static function form_start( $invoice = null ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'mbs_additional_charge' );
        echo '<input type="hidden" name="action" value="mbs_additional_charge"><input type="hidden" name="idempotency_key" value="' . esc_attr( wp_generate_uuid4() ) . '">';
        if ( $invoice ) echo '<input type="hidden" name="invoice_ref" value="' . esc_attr( $invoice->invoice_ref ) . '"><input type="hidden" name="version" value="' . (int) $invoice->version . '">';
    }

    private static function field( $name, $label, $value = '', $type = 'text', $required = true ) {
        echo '<p><label>' . esc_html( $label ) . '<br><input style="width:32em;max-width:100%" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" ' . ( $required ? 'required' : '' ) . '></label></p>';
    }

    public function render() {
        global $wpdb;
        if ( ! current_user_can( 'mbs_manage_bookings' ) ) return;
        $related = sanitize_text_field( wp_unslash( $_GET['related_ref'] ?? '' ) );
        $booking = $related ? MBS_Bookings::get( $related ) : null;
        $series = $related && ! $booking ? MBS_Series::get( $related ) : null;
        echo '<div class="wrap"><h1>Additional charges</h1><p>Separate invoices for agreed extras. These never change hire payments or access. Do not enter CCTV, child information or unnecessary personal details.</p><h2>New draft</h2>';
        self::form_start();
        self::field( 'related_ref', 'Booking or series reference', $related );
        self::field( 'contact_name', 'Recipient name (confirm before issue)', $booking ? $booking->name : ( $series ? $series->contact_name : '' ) );
        self::field( 'contact_email', 'Recipient email', $booking ? $booking->email : ( $series ? $series->contact_email : '' ), 'email' );
        self::field( 'contact_organisation', 'Organisation (optional)', $booking ? $booking->organisation : ( $series ? $series->contact_organisation : '' ), 'text', false );
        self::field( 'contact_address', 'Billing address (optional)', $booking ? $booking->address : ( $series ? $series->contact_address : '' ), 'text', false );
        self::field( 'description', 'Customer-visible description' ); self::field( 'amount', 'Amount in GBP, e.g. 25.00' );
        self::field( 'service_date', 'Service date', $booking ? $booking->booking_date : wp_date( 'Y-m-d' ), 'date' );
        self::field( 'due_date', 'Due date', wp_date( 'Y-m-d', time() + 14 * DAY_IN_SECONDS ), 'date' );
        echo '<p><label>Category <select name="category"><option value="cleaning">Cleaning</option><option value="damage">Damage</option><option value="other">Other</option></select></label></p><p><label>Internal notes (not on invoice)<br><textarea name="notes"></textarea></label></p><p><label><input type="checkbox" name="automatic_reminders" value="1">Enable one automatic overdue reminder</label></p><button class="button button-primary" name="operation" value="create">Save draft only</button></form><hr>';
        $page = max( 1, absint( $_GET['charge_page'] ?? 1 ) );
        $invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
        $item_table = $wpdb->prefix . MBS_INVOICE_ITEM_TABLE;
        $link_filter = $related ? $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$item_table} ci WHERE ci.invoice_id=i.id AND ci.pricing_snapshot_json LIKE %s)", '%"related_ref":' . $wpdb->esc_like( wp_json_encode( $related ) ) . '%' ) : '';
        $search = sanitize_text_field( wp_unslash( $_GET['charge_search'] ?? '' ) );
        $filter_status = sanitize_key( $_GET['charge_status'] ?? '' );
        echo '<h2>Existing charges</h2><form method="get"><input type="hidden" name="page" value="mathlin-additional-charges"><input type="hidden" name="related_ref" value="' . esc_attr( $related ) . '"><label>Search reference/name/email <input name="charge_search" value="' . esc_attr( $search ) . '"></label> <label>Status <select name="charge_status"><option value="">All</option>';
        foreach ( array( 'draft','issued','part_paid','paid','credited','void','overdue','payment_review' ) as $filter ) echo '<option value="' . esc_attr( $filter ) . '" ' . selected( $filter_status, $filter, false ) . '>' . esc_html( $filter ) . '</option>';
        echo '</select></label> <button class="button">Filter</button></form>';
        if ( $search !== '' ) { $like = '%' . $wpdb->esc_like( $search ) . '%'; $link_filter .= $wpdb->prepare( ' AND (i.invoice_ref LIKE %s OR i.contact_name LIKE %s OR i.contact_email LIKE %s)', $like, $like, $like ); }
        if ( $filter_status === 'overdue' ) $link_filter .= $wpdb->prepare( " AND i.status IN ('issued','part_paid','overdue') AND i.total_minor>i.paid_minor+i.credited_minor AND i.due_at<%s", current_time( 'mysql' ) );
        elseif ( $filter_status === 'payment_review' ) $link_filter .= " AND EXISTS (SELECT 1 FROM " . $wpdb->prefix . MBS_PAYMENT_RESERVATION_TABLE . " cr WHERE cr.invoice_ref=i.invoice_ref AND cr.status IN ('bound','reconciliation_required'))";
        elseif ( in_array( $filter_status, array( 'draft','issued','part_paid','paid','credited','void' ), true ) ) $link_filter .= $wpdb->prepare( ' AND i.status=%s', $filter_status );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT i.* FROM {$invoice_table} i WHERE i.billing_mode=%s AND i.document_type='invoice' {$link_filter} ORDER BY i.id DESC LIMIT 50 OFFSET %d", self::MODE, ( $page - 1 ) * 50 ) );
        foreach ( $rows as $invoice ) {
            $meta = self::metadata( $invoice ); $items = MBS_Billing_Ledger::get_items( $invoice->id );
            $item = $items ? $items[0] : null;
            $balance = MBS_Billing_Ledger::balance_minor( $invoice );
            $status = MBS_Invoice_Payment::is_payable( $invoice ) && $invoice->due_at < current_time( 'mysql' ) ? 'overdue' : $invoice->status;
            echo '<section style="background:white;padding:16px;margin:16px 0"><h2>' . esc_html( $invoice->invoice_ref . ' - ' . $status ) . '</h2><p>' . esc_html( $item ? $item->description : 'Incomplete draft' ) . '</p><p>' . esc_html( $invoice->contact_name . ' <' . $invoice->contact_email . '>' ) . '</p><p>Total: ' . esc_html( MBS_Money::format( $invoice->status === 'draft' && $item ? (int) $item->line_total_minor : (int) $invoice->total_minor ) ) . ' | Paid: ' . esc_html( MBS_Money::format( (int) $invoice->paid_minor ) ) . ' | Balance: ' . esc_html( MBS_Money::format( $balance ) ) . ' | Due: ' . esc_html( $invoice->due_at ) . '</p>';
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
            if ( $invoice->status === 'draft' ) {
                self::form_start( $invoice );
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
                self::form_start( $invoice ); echo '<p>Send to ' . esc_html( $invoice->contact_email ) . ' with PDF and current outstanding balance/payment link.</p><button class="button" name="operation" value="send">Confirm and queue invoice email</button> <button class="button" name="operation" value="resend">Confirm explicit resend</button></form>';
                self::form_start( $invoice ); self::field( 'payment_amount', 'Offline payment received (GBP)' ); self::field( 'reason', 'Payment reference / note' ); echo '<button class="button" name="operation" value="manual_payment">Confirm money received</button></form>';
            }
            if ( in_array( $invoice->status, array( 'issued','part_paid','paid','overdue','credited' ), true ) && (int) $invoice->total_minor > (int) $invoice->credited_minor ) {
                self::form_start( $invoice ); self::field( 'credit_amount', 'Credit amount (GBP)' ); self::field( 'reason', 'Credit reason' ); echo '<p>A credit note is not a cash refund. Refund actual money separately through the original payment provider.</p><button class="button" name="operation" value="credit">Confirm credit note</button></form>';
            }
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
            echo '</details></section>';
        }
        if ( $page > 1 ) echo '<a class="button" href="' . esc_url( add_query_arg( 'charge_page', $page - 1 ) ) . '">Previous</a> ';
        if ( count( $rows ) === 50 ) echo '<a class="button" href="' . esc_url( add_query_arg( 'charge_page', $page + 1 ) ) . '">Next</a>';
        echo '</div>';
    }
}
