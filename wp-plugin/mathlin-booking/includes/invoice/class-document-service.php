<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Invoice Document Service — manages the append-only document lifecycle.
 *
 * Owns the individual-booking issuance transaction.
 * All monetary values use MBS_Money integer minor-unit conversion.
 * No binary floating-point arithmetic in the invoice/document domain.
 */
class MBS_Invoice_Document_Service {

    /**
     * Confirm a booking and issue its immutable invoice document atomically.
     *
     * Transaction scope:
     *   lock booking → validate → confirm → assign invoice number →
     *   determine revision → assemble snapshot from locked row →
     *   insert document → supersede previous → update pointer →
     *   audit → enqueue confirmation email → COMMIT
     *
     * Pre-transaction: logo asset resolution (only file I/O).
     * Post-commit: HA notification, optional cron spawn.
     *
     * @param string $ref           Booking reference.
     * @param array  $logo_ref      Pre-resolved logo {asset_id, content_hash} or null.
     * @param bool   $notify_hirer  Queue confirmation email.
     * @return array|WP_Error  {document_id, booking} on success.
     */
    public static function confirm_and_issue( $ref, $logo_ref = null, $notify_hirer = true ) {
        global $wpdb;
        $booking_table = $wpdb->prefix . MBS_TABLE;
        $doc_table = $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE;

        // Pre-transaction: resolve logo
        if ( $logo_ref === null ) {
            $logo_ref = MBS_Logo_Asset::resolve_current_org_logo();
        }

        // BEGIN TRANSACTION
        if ( $wpdb->query( 'START TRANSACTION' ) === false ) {
            return new WP_Error( 'transaction_start_failed', 'Could not start the booking confirmation transaction.' );
        }

        // Lock the booking row
        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$booking_table} WHERE ref = %s FOR UPDATE",
            sanitize_text_field( $ref )
        ) );

        if ( ! $booking ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'booking_not_found', 'Booking not found.' );
        }

        // Validate status transition
        if ( $booking->status === 'confirmed' && $booking->current_invoice_document_id ) {
            // Idempotent: already confirmed with document
            $wpdb->query( 'ROLLBACK' );
            return array( 'document_id' => (int) $booking->current_invoice_document_id, 'booking' => $booking, 'no_op' => true );
        }

        if ( ! in_array( $booking->status, array( 'pending', 'confirmed' ), true ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'invalid_status', 'Booking cannot be confirmed from status: ' . $booking->status );
        }

        // Confirm the booking
        $confirmed = $wpdb->update( $booking_table,
            array( 'status' => 'confirmed' ),
            array( 'id' => (int) $booking->id, 'status' => $booking->status )
        );
        if ( $confirmed === false ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'confirm_failed', 'Could not confirm the booking.' );
        }

        // Determine next revision
        $max_revision = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(revision), 0) FROM {$doc_table} WHERE booking_id = %d",
            (int) $booking->id
        ) );
        $next_revision = $max_revision + 1;

        // Invoice number with revision
        $base_invoice_number = $booking->invoice_number ?: ( 'INV-' . $booking->ref );
        $invoice_number = $next_revision > 1 ? $base_invoice_number . '-R' . $next_revision : $base_invoice_number;

        // Build snapshot FROM THE LOCKED ROW (integer minor units only)
        $snapshot = self::build_booking_snapshot( $booking, $logo_ref, $invoice_number, $next_revision );

        // Insert immutable document
        $inserted = $wpdb->insert( $doc_table, array(
            'booking_id'             => (int) $booking->id,
            'invoice_id'             => null,
            'booking_ref'            => $booking->ref,
            'invoice_number'         => $invoice_number,
            'revision'               => $next_revision,
            'document_type'          => 'invoice',
            'snapshot_json'          => $snapshot->to_json(),
            'snapshot_version'       => 1,
            'logo_asset_id'          => $logo_ref ? (int) $logo_ref['asset_id'] : null,
            'logo_content_hash'      => $logo_ref ? $logo_ref['content_hash'] : null,
            'status'                 => 'issued',
            'supersedes_document_id' => $booking->current_invoice_document_id ?: null,
            'issued_at'              => current_time( 'mysql' ),
            'created_at'             => current_time( 'mysql' ),
        ) );

        if ( $inserted === false ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'document_insert_failed', 'Could not create the invoice document.' );
        }

        $document_id = (int) $wpdb->insert_id;

        // Supersede previous document
        if ( $booking->current_invoice_document_id ) {
            $wpdb->update( $doc_table,
                array( 'status' => 'superseded' ),
                array( 'id' => (int) $booking->current_invoice_document_id, 'status' => 'issued' )
            );
        }

        // Update booking pointer
        $ptr_updated = $wpdb->update( $booking_table,
            array( 'current_invoice_document_id' => $document_id ),
            array( 'id' => (int) $booking->id )
        );
        if ( $ptr_updated === false ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'pointer_update_failed', 'Could not link the document to the booking.' );
        }

        // Audit (fail-closed)
        $audit_ok = MBS_Audit_Log::log( $booking->ref, 'invoice_document_issued', 'Invoice document ' . $invoice_number . ' revision ' . $next_revision . ' issued.' );
        if ( ! $audit_ok ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'audit_failed', 'Could not record the issuance audit entry.' );
        }

        // Enqueue confirmation email (if applicable and chargeable)
        if ( $notify_hirer && (float) $booking->amount > 0 ) {
            $message_key = 'booking_confirmed:' . $booking->ref . ':doc' . $document_id;
            $email_body = self::build_confirmation_placeholder( $booking );
            $subject = 'Booking Confirmed — ' . $booking->ref;
            $headers = self::email_headers();
            $attachment_meta = array( 'document_id' => $document_id, 'format' => 'pdf' );
            $payload_hash = MBS_Email_Queue::compute_payload_hash( $booking->email, $subject, $email_body, $headers, $attachment_meta );

            $enqueued = MBS_Email_Queue::enqueue(
                $booking->email, $subject, $email_body, $headers,
                $message_key, $payload_hash, $attachment_meta,
                array( 'message_type' => 'booking_confirmation', 'reference_type' => 'booking', 'reference_id' => (int) $booking->id )
            );
            if ( is_wp_error( $enqueued ) ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'queue_failed', 'Could not queue the confirmation email.' );
            }
        }

        // COMMIT
        if ( $wpdb->query( 'COMMIT' ) === false ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'commit_failed', 'Could not commit the booking confirmation.' );
        }

        // Post-commit: HA notification
        $booking->status = 'confirmed';
        MBS_HomeAssistant::notify( $booking );
        $wpdb->update( $booking_table, array( 'ha_notified' => 1 ), array( 'id' => (int) $booking->id ) );

        // Auto-promote £0 bookings to paid
        $amount_minor = MBS_Money::from_decimal_string( (string) $booking->amount );
        if ( ! is_wp_error( $amount_minor ) && $amount_minor <= 0 ) {
            $wpdb->update( $booking_table, array( 'status' => 'paid' ), array( 'id' => (int) $booking->id ) );
            MBS_Audit_Log::log( $booking->ref, 'paid', 'Auto-marked as Paid (£0 booking — no payment required)' );
        }

        if ( function_exists( 'spawn_cron' ) ) spawn_cron();

        return array( 'document_id' => $document_id, 'booking' => MBS_Bookings::get( $ref ), 'no_op' => false );
    }

    /**
     * Reissue an individual invoice after modification (price/date change).
     * Creates a new document revision; old snapshot remains intact.
     *
     * @param string $ref       Booking reference.
     * @param array  $logo_ref  Pre-resolved logo or null.
     * @return int|WP_Error  New document ID on success.
     */
    public static function reissue_booking_document( $ref, $logo_ref = null ) {
        global $wpdb;
        $booking_table = $wpdb->prefix . MBS_TABLE;
        $doc_table = $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE;

        if ( $logo_ref === null ) {
            $logo_ref = MBS_Logo_Asset::resolve_current_org_logo();
        }

        if ( $wpdb->query( 'START TRANSACTION' ) === false ) {
            return new WP_Error( 'transaction_start_failed', 'Could not start the reissue transaction.' );
        }

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$booking_table} WHERE ref = %s FOR UPDATE",
            sanitize_text_field( $ref )
        ) );
        if ( ! $booking ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'booking_not_found', 'Booking not found.' ); }

        $max_revision = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(revision), 0) FROM {$doc_table} WHERE booking_id = %d",
            (int) $booking->id
        ) );
        $next_revision = $max_revision + 1;

        $base_invoice_number = $booking->invoice_number ?: ( 'INV-' . $booking->ref );
        $invoice_number = $base_invoice_number . '-R' . $next_revision;

        $snapshot = self::build_booking_snapshot( $booking, $logo_ref, $invoice_number, $next_revision );

        $inserted = $wpdb->insert( $doc_table, array(
            'booking_id'             => (int) $booking->id,
            'invoice_id'             => null,
            'booking_ref'            => $booking->ref,
            'invoice_number'         => $invoice_number,
            'revision'               => $next_revision,
            'document_type'          => 'invoice',
            'snapshot_json'          => $snapshot->to_json(),
            'snapshot_version'       => 1,
            'logo_asset_id'          => $logo_ref ? (int) $logo_ref['asset_id'] : null,
            'logo_content_hash'      => $logo_ref ? $logo_ref['content_hash'] : null,
            'status'                 => 'issued',
            'supersedes_document_id' => $booking->current_invoice_document_id ?: null,
            'issued_at'              => current_time( 'mysql' ),
            'created_at'             => current_time( 'mysql' ),
        ) );

        if ( $inserted === false ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'document_insert_failed', 'Could not create the reissued document.' ); }
        $document_id = (int) $wpdb->insert_id;

        if ( $booking->current_invoice_document_id ) {
            $wpdb->update( $doc_table, array( 'status' => 'superseded' ), array( 'id' => (int) $booking->current_invoice_document_id, 'status' => 'issued' ) );
        }
        $wpdb->update( $booking_table, array( 'current_invoice_document_id' => $document_id ), array( 'id' => (int) $booking->id ) );

        $audit_ok = MBS_Audit_Log::log( $booking->ref, 'invoice_document_reissued', 'Reissued as ' . $invoice_number );
        if ( ! $audit_ok ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'audit_failed', 'Could not record reissue audit.' ); }

        if ( $wpdb->query( 'COMMIT' ) === false ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'commit_failed', 'Could not commit the reissue.' ); }

        return $document_id;
    }

    /**
     * Create an immutable document for a ledger invoice.
     * Called WITHIN an existing transaction (Approval_Service or issuance service).
     * Does NOT own a transaction — caller must provide one.
     *
     * @param object $invoice   Locked invoice row.
     * @param array  $items     Invoice items.
     * @param object $series    Series row.
     * @param array  $logo_ref  Pre-resolved logo.
     * @return int|WP_Error  Document ID on success.
     */
    public static function create_ledger_document_within_transaction( $invoice, $items, $series, $logo_ref = null ) {
        global $wpdb;
        $doc_table = $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE;

        $max_revision = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(revision), 0) FROM {$doc_table} WHERE invoice_id = %d",
            (int) $invoice->id
        ) );
        $next_revision = $max_revision + 1;

        $invoice_number = $invoice->invoice_ref;
        if ( $next_revision > 1 ) $invoice_number .= '-R' . $next_revision;

        $snapshot = self::build_ledger_snapshot( $invoice, $items, $series, $logo_ref, $invoice_number, $next_revision );

        $inserted = $wpdb->insert( $doc_table, array(
            'booking_id'             => null,
            'invoice_id'             => (int) $invoice->id,
            'booking_ref'            => null,
            'invoice_number'         => $invoice_number,
            'revision'               => $next_revision,
            'document_type'          => $invoice->document_type,
            'snapshot_json'          => $snapshot->to_json(),
            'snapshot_version'       => 1,
            'logo_asset_id'          => $logo_ref ? (int) $logo_ref['asset_id'] : null,
            'logo_content_hash'      => $logo_ref ? $logo_ref['content_hash'] : null,
            'status'                 => 'issued',
            'supersedes_document_id' => null,
            'issued_at'              => current_time( 'mysql' ),
            'created_at'             => current_time( 'mysql' ),
        ) );

        if ( $inserted === false ) {
            return new WP_Error( 'document_insert_failed', 'Could not create the invoice document.' );
        }
        return (int) $wpdb->insert_id;
    }

    // ── Snapshot Builders (integer minor units only) ────────────────────────────

    private static function build_booking_snapshot( $booking, $logo_ref, $invoice_number, $revision ) {
        $bank = MBS_Bookings::get_bank_details();
        $is_offline = MBS_Bookings::booking_is_offline( $booking );
        $deposit_settings = MBS_Bookings::get_deposit_settings();

        // Convert booking amount to minor units using MBS_Money (no floats)
        $total_minor = MBS_Money::from_decimal_string( (string) $booking->amount );
        if ( is_wp_error( $total_minor ) ) $total_minor = 0;

        $kitchen_price_decimal = $booking->kitchen
            ? (string) MBS_Bookings::get_tiered_kitchen_price( MBS_Bookings::get_booking_tier( $booking ) )
            : '0';
        $kitchen_minor = MBS_Money::from_decimal_string( $kitchen_price_decimal );
        if ( is_wp_error( $kitchen_minor ) ) $kitchen_minor = 0;

        $space_minor = $total_minor - $kitchen_minor;

        $line_items = array();
        $line_items[] = array(
            'date'         => $booking->booking_date,
            'space'        => $booking->space,
            'description'  => $booking->space . ' hire' . ( $booking->purpose ? ' — ' . $booking->purpose : '' ),
            'amount_minor' => (int) $space_minor,
        );
        if ( $booking->kitchen && $kitchen_minor > 0 ) {
            $line_items[] = array(
                'date'         => $booking->booking_date,
                'space'        => '',
                'description'  => 'Kitchen facilities add-on',
                'amount_minor' => (int) $kitchen_minor,
            );
        }

        // Tax calculation (integer arithmetic)
        $tax_rate_bps = (int) get_option( 'mbs_tax_rate_bps', 0 );
        $tax_label = get_option( 'mbs_tax_label', 'Charity exempt — not registered for VAT' );

        // Prices assumed tax-inclusive at 0% (charity default). For non-zero rates,
        // subtotal = total / (1 + rate), tax = total - subtotal.
        // At 0%: subtotal = total, tax = 0.
        $subtotal_minor = $total_minor;
        $tax_amount_minor = 0;
        if ( $tax_rate_bps > 0 && $total_minor > 0 ) {
            // Tax-inclusive calculation: total = subtotal + tax
            // subtotal = total * 10000 / (10000 + rate_bps)
            $subtotal_minor = (int) intdiv( $total_minor * 10000, 10000 + $tax_rate_bps );
            $tax_amount_minor = $total_minor - $subtotal_minor;
        }

        // Payment schedule (complete — never null for chargeable bookings)
        $payment_schedule = self::compute_payment_schedule( $booking, $total_minor, $deposit_settings, $bank );

        // Due date
        $due_date = wp_date( 'Y-m-d', strtotime( '+' . $bank['payment_days'] . ' days' ) );
        if ( ! empty( $payment_schedule['immediate'] ) ) {
            $due_date = wp_date( 'Y-m-d' );
        } elseif ( ! empty( $payment_schedule['deposit_due_date'] ) ) {
            $due_date = $payment_schedule['deposit_due_date'];
        }

        return MBS_Issued_Invoice_Snapshot::build( array(
            'logo_asset_id'          => $logo_ref ? $logo_ref['asset_id'] : null,
            'logo_content_hash'      => $logo_ref ? $logo_ref['content_hash'] : null,
            'recipient_name'         => $booking->name,
            'recipient_organisation' => $booking->organisation ?: null,
            'recipient_email'        => $booking->email,
            'recipient_address'      => $booking->address ?: null,
            'invoice_number'         => $invoice_number,
            'booking_ref'            => $booking->ref,
            'document_type'          => 'invoice',
            'revision'               => $revision,
            'issue_date'             => wp_date( 'Y-m-d' ),
            'due_date'               => $due_date,
            'line_items'             => $line_items,
            'currency'               => 'GBP',
            'subtotal_minor'         => (int) $subtotal_minor,
            'tax_rate_bps'           => $tax_rate_bps,
            'tax_label'              => $tax_label,
            'tax_amount_minor'       => (int) $tax_amount_minor,
            'total_minor'            => (int) $total_minor,
            'payment_method'         => $is_offline ? 'offline_bacs' : 'online',
            'payment_terms_days'     => (int) $bank['payment_days'],
            'bank_details'           => ( ! empty( $bank['sort_code'] ) || ! empty( $bank['account_number'] ) ) ? $bank : null,
            'payment_schedule'       => $payment_schedule,
            'status_at_issue'        => 'confirmed',
        ) );
    }

    /**
     * Compute a complete payment schedule (never returns null for chargeable bookings).
     */
    private static function compute_payment_schedule( $booking, $total_minor, $deposit_settings, $bank ) {
        if ( $total_minor <= 0 ) {
            return array( 'no_charge' => true );
        }
        if ( $deposit_settings['enabled'] && MBS_Bookings::requires_full_payment( $booking->booking_date ) ) {
            return array( 'immediate' => true );
        }
        if ( $deposit_settings['enabled'] ) {
            $deposit_decimal = (string) MBS_Bookings::calculate_deposit( (float) $booking->amount );
            $deposit_minor = MBS_Money::from_decimal_string( $deposit_decimal );
            if ( is_wp_error( $deposit_minor ) ) $deposit_minor = $total_minor;
            $balance_minor = $total_minor - $deposit_minor;
            $balance_days = $deposit_settings['balance_days'];
            return array(
                'deposit_minor'    => (int) $deposit_minor,
                'deposit_due_date' => wp_date( 'Y-m-d' ),
                'balance_minor'    => (int) $balance_minor,
                'balance_due_date' => wp_date( 'Y-m-d', strtotime( $booking->booking_date . " -{$balance_days} days" ) ),
            );
        }
        // Standard terms
        return array(
            'standard_terms' => true,
            'terms_days'     => (int) $bank['payment_days'],
            'due_date'       => wp_date( 'Y-m-d', strtotime( '+' . $bank['payment_days'] . ' days' ) ),
        );
    }

    private static function build_ledger_snapshot( $invoice, $items, $series, $logo_ref, $invoice_number, $revision ) {
        $bank = MBS_Bookings::get_bank_details();
        $tax_rate_bps = (int) get_option( 'mbs_tax_rate_bps', 0 );
        $tax_label = get_option( 'mbs_tax_label', 'Charity exempt — not registered for VAT' );

        $line_items = array();
        foreach ( $items as $item ) {
            $line_items[] = array(
                'date'         => $item->service_date,
                'space'        => $series ? $series->space : '',
                'description'  => $item->description,
                'amount_minor' => (int) $item->line_total_minor,
            );
        }

        // Tax (ledger invoices store subtotal_minor directly as integer)
        $subtotal_minor = (int) $invoice->subtotal_minor;
        $tax_amount_minor = (int) $invoice->tax_minor;
        $total_minor = (int) $invoice->total_minor;

        return MBS_Issued_Invoice_Snapshot::build( array(
            'logo_asset_id'          => $logo_ref ? $logo_ref['asset_id'] : null,
            'logo_content_hash'      => $logo_ref ? $logo_ref['content_hash'] : null,
            'recipient_name'         => $invoice->contact_name,
            'recipient_organisation' => $invoice->contact_organisation ?: null,
            'recipient_email'        => $invoice->contact_email,
            'recipient_address'      => $invoice->contact_address ?: null,
            'invoice_number'         => $invoice_number,
            'series_ref'             => $invoice->series_ref,
            'document_type'          => $invoice->document_type,
            'revision'               => $revision,
            'issue_date'             => wp_date( 'Y-m-d' ),
            'due_date'               => $invoice->due_at ? wp_date( 'Y-m-d', strtotime( $invoice->due_at ) ) : '',
            'period_start'           => $invoice->period_start,
            'period_end'             => $invoice->period_end,
            'line_items'             => $line_items,
            'currency'               => $invoice->currency,
            'subtotal_minor'         => $subtotal_minor,
            'tax_rate_bps'           => $tax_rate_bps,
            'tax_label'              => $tax_label,
            'tax_amount_minor'       => $tax_amount_minor,
            'total_minor'            => $total_minor,
            'credits_minor'          => (int) $invoice->credited_minor,
            'payment_method'         => $series ? $series->payment_method : 'online',
            'payment_terms_days'     => $series ? (int) $series->payment_terms_days : 14,
            'bank_details'           => ( $series && $series->payment_method === 'offline_bacs' && ( ! empty( $bank['sort_code'] ) || ! empty( $bank['account_number'] ) ) ) ? $bank : null,
            'status_at_issue'        => $invoice->status,
        ) );
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private static function build_confirmation_placeholder( $booking ) {
        // The full confirmation email body is rendered by the queue worker using the template system.
        // This is just the fallback body if the worker cannot load templates.
        return '<p>Your booking ' . esc_html( $booking->ref ) . ' has been confirmed.</p>';
    }

    private static function email_headers() {
        $org = class_exists( 'MBS_Email_Templates' ) ? MBS_Email_Templates::get_org_settings() : array();
        $from_email = get_option( 'admin_email' );
        $org_name = $org['name'] ?: get_bloginfo( 'name' );
        return array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $org_name . ' <' . $from_email . '>',
            'Reply-To: ' . MBS_Bookings::get_admin_email(),
        );
    }

    /**
     * Simple document issuance for already-confirmed bookings (legacy path).
     * Used when update_status('confirmed') is called outside the full
     * confirm_and_issue() flow. Creates the document without re-confirming.
     *
     * @param object $booking  Already-confirmed booking row.
     * @return int|WP_Error  Document ID.
     */
    public static function issue_booking_document( $booking ) {
        global $wpdb;
        $doc_table = $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE;
        $booking_table = $wpdb->prefix . MBS_TABLE;

        // Skip if already has a document
        if ( ! empty( $booking->current_invoice_document_id ) ) {
            return (int) $booking->current_invoice_document_id;
        }

        $logo_ref = MBS_Logo_Asset::resolve_current_org_logo();

        if ( $wpdb->query( 'START TRANSACTION' ) === false ) {
            return new WP_Error( 'transaction_start_failed', 'Could not start document issuance.' );
        }

        // Lock the booking
        $locked = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$booking_table} WHERE id = %d FOR UPDATE",
            (int) $booking->id
        ) );
        if ( ! $locked ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'lock_failed', 'Could not lock booking.' ); }

        // Double-check no document was created by a concurrent request
        if ( ! empty( $locked->current_invoice_document_id ) ) {
            $wpdb->query( 'ROLLBACK' );
            return (int) $locked->current_invoice_document_id;
        }

        $max_revision = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(revision), 0) FROM {$doc_table} WHERE booking_id = %d", (int) $booking->id
        ) );
        $next_revision = $max_revision + 1;
        $invoice_number = $booking->invoice_number ?: ( 'INV-' . $booking->ref );
        if ( $next_revision > 1 ) $invoice_number .= '-R' . $next_revision;

        $snapshot = self::build_booking_snapshot( $locked, $logo_ref, $invoice_number, $next_revision );

        $inserted = $wpdb->insert( $doc_table, array(
            'booking_id' => (int) $booking->id, 'invoice_id' => null,
            'booking_ref' => $booking->ref, 'invoice_number' => $invoice_number,
            'revision' => $next_revision, 'document_type' => 'invoice',
            'snapshot_json' => $snapshot->to_json(), 'snapshot_version' => 1,
            'logo_asset_id' => $logo_ref ? (int) $logo_ref['asset_id'] : null,
            'logo_content_hash' => $logo_ref ? $logo_ref['content_hash'] : null,
            'status' => 'issued', 'supersedes_document_id' => null,
            'issued_at' => current_time( 'mysql' ), 'created_at' => current_time( 'mysql' ),
        ) );
        if ( $inserted === false ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'insert_failed', 'Could not create document.' ); }

        $document_id = (int) $wpdb->insert_id;
        $wpdb->update( $booking_table, array( 'current_invoice_document_id' => $document_id ), array( 'id' => (int) $booking->id ) );
        MBS_Audit_Log::log( $booking->ref, 'invoice_document_issued', 'Document ' . $invoice_number . ' issued.' );

        if ( $wpdb->query( 'COMMIT' ) === false ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'commit_failed', 'Could not commit.' ); }
        return $document_id;
    }
}
