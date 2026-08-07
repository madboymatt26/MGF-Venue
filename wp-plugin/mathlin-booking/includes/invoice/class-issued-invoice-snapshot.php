<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Immutable value object representing an invoice document at issue time.
 *
 * Once created, this data never changes. It is stored as JSON in the
 * mathlin_invoice_documents.snapshot_json column and used as the sole
 * source of truth for rendering the Issued Invoice document.
 *
 * Tax rates use integer basis points (2000 = 20.00%). All monetary values
 * are integer minor units. No binary floating-point values.
 */
class MBS_Issued_Invoice_Snapshot {

    /** @var string Organisation name at issue time */
    public $issuer_name = '';

    /** @var string Organisation address at issue time */
    public $issuer_address = '';

    /** @var string Organisation email at issue time */
    public $issuer_email = '';

    /** @var string Organisation phone at issue time */
    public $issuer_phone = '';

    /** @var string|null Charity/company registration number */
    public $issuer_charity_number = null;

    /** @var int|null Logo asset ID (FK to mathlin_document_assets) */
    public $logo_asset_id = null;

    /** @var string|null Logo content SHA-256 hash for verification */
    public $logo_content_hash = null;

    /** @var string Recipient (hirer) name */
    public $recipient_name = '';

    /** @var string|null Recipient organisation */
    public $recipient_organisation = null;

    /** @var string Recipient email */
    public $recipient_email = '';

    /** @var string|null Recipient address */
    public $recipient_address = null;

    /** @var string Invoice reference number (e.g. INV-ABC123 or INV-ABC123-R2) */
    public $invoice_number = '';

    /** @var string|null Booking reference (for individual booking invoices) */
    public $booking_ref = null;

    /** @var string|null Series reference (for consolidated invoices) */
    public $series_ref = null;

    /** @var string Document type: invoice | credit_note | receipt */
    public $document_type = 'invoice';

    /** @var string Issue date Y-m-d */
    public $issue_date = '';

    /** @var string Due date Y-m-d */
    public $due_date = '';

    /** @var string|null Period start Y-m-d (consolidated invoices) */
    public $period_start = null;

    /** @var string|null Period end Y-m-d (consolidated invoices) */
    public $period_end = null;

    /** @var array Line items: [{date, space, description, amount_minor}] */
    public $line_items = array();

    /** @var string Currency code (e.g. 'GBP') */
    public $currency = 'GBP';

    /** @var int Subtotal in minor units */
    public $subtotal_minor = 0;

    /** @var int Tax rate in basis points (2000 = 20%, 0 = exempt) */
    public $tax_rate_bps = 0;

    /** @var string Tax label (e.g. "VAT" or "Charity exempt — not registered for VAT") */
    public $tax_label = '';

    /** @var int Tax amount in minor units */
    public $tax_amount_minor = 0;

    /** @var int Credits applied at issue time in minor units */
    public $credits_minor = 0;

    /** @var int Total in minor units */
    public $total_minor = 0;

    /** @var string Payment method: online | offline_bacs | none */
    public $payment_method = 'online';

    /** @var int Payment terms in days */
    public $payment_terms_days = 14;

    /** @var array|null Bank details for BACS: {account_name, sort_code, account_number} */
    public $bank_details = null;

    /** @var string Stable payment instruction text for issued document (not a URL) */
    public $online_payment_instruction = '';

    /** @var array|null Payment schedule: {deposit_minor, deposit_due_date, balance_minor, balance_due_date, immediate, no_charge} */
    public $payment_schedule = null;

    /** @var string Status at time of issue */
    public $status_at_issue = 'issued';

    /** @var int Revision number */
    public $revision = 1;

    /**
     * Serialize to JSON for storage.
     *
     * @return string JSON string.
     */
    public function to_json() {
        return wp_json_encode( get_object_vars( $this ), JSON_UNESCAPED_UNICODE );
    }

    /**
     * Restore from stored JSON.
     *
     * @param string $json Stored JSON string.
     * @return self|WP_Error
     */
    public static function from_json( $json ) {
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'snapshot_invalid_json', 'Could not decode invoice snapshot JSON.' );
        }

        $snapshot = new self();
        foreach ( $data as $key => $value ) {
            if ( property_exists( $snapshot, $key ) ) {
                $snapshot->$key = $value;
            }
        }
        return $snapshot;
    }

    /**
     * Build a snapshot from current organisation settings and invoice/booking data.
     * Call this inside the approval/issuance transaction with locked data.
     *
     * @param array $params Required keys vary by document source.
     * @return self
     */
    public static function build( $params ) {
        $snapshot = new self();

        // Issuer (from Org_Settings — read inside transaction but no I/O)
        $org = class_exists( 'MBS_Email_Templates' ) ? MBS_Email_Templates::get_org_settings() : array();
        $snapshot->issuer_name = $org['name'] ?: get_bloginfo( 'name' );
        $snapshot->issuer_address = $org['address'] ?? '';
        $snapshot->issuer_email = MBS_Bookings::get_admin_email();
        $snapshot->issuer_phone = $org['phone'] ?? '';
        $snapshot->issuer_charity_number = ! empty( $org['charity_number'] ) ? $org['charity_number'] : null;

        // Logo (pre-resolved asset reference — no file I/O here)
        if ( ! empty( $params['logo_asset_id'] ) ) {
            $snapshot->logo_asset_id = (int) $params['logo_asset_id'];
            $snapshot->logo_content_hash = $params['logo_content_hash'] ?? null;
        }

        // Recipient
        $snapshot->recipient_name = $params['recipient_name'] ?? '';
        $snapshot->recipient_organisation = $params['recipient_organisation'] ?? null;
        $snapshot->recipient_email = $params['recipient_email'] ?? '';
        $snapshot->recipient_address = $params['recipient_address'] ?? null;

        // References
        $snapshot->invoice_number = $params['invoice_number'] ?? '';
        $snapshot->booking_ref = $params['booking_ref'] ?? null;
        $snapshot->series_ref = $params['series_ref'] ?? null;
        $snapshot->document_type = $params['document_type'] ?? 'invoice';
        $snapshot->revision = (int) ( $params['revision'] ?? 1 );

        // Dates
        $snapshot->issue_date = $params['issue_date'] ?? wp_date( 'Y-m-d' );
        $snapshot->due_date = $params['due_date'] ?? '';
        $snapshot->period_start = $params['period_start'] ?? null;
        $snapshot->period_end = $params['period_end'] ?? null;

        // Line items
        $snapshot->line_items = $params['line_items'] ?? array();

        // Financials (all integer minor units)
        $snapshot->currency = $params['currency'] ?? 'GBP';
        $snapshot->subtotal_minor = (int) ( $params['subtotal_minor'] ?? 0 );
        $snapshot->total_minor = (int) ( $params['total_minor'] ?? 0 );
        $snapshot->credits_minor = (int) ( $params['credits_minor'] ?? 0 );

        // Tax (integer basis points — NOT float)
        $snapshot->tax_rate_bps = (int) ( $params['tax_rate_bps'] ?? (int) get_option( 'mbs_tax_rate_bps', 0 ) );
        $snapshot->tax_label = $params['tax_label'] ?? get_option( 'mbs_tax_label', 'Charity exempt — not registered for VAT' );
        $snapshot->tax_amount_minor = (int) ( $params['tax_amount_minor'] ?? 0 );

        // Payment
        $snapshot->payment_method = $params['payment_method'] ?? 'online';
        $snapshot->payment_terms_days = (int) ( $params['payment_terms_days'] ?? 14 );
        $snapshot->bank_details = $params['bank_details'] ?? null;
        $snapshot->online_payment_instruction = $params['online_payment_instruction']
            ?? get_option( 'mbs_online_payment_instruction', 'Pay securely through your venue booking account.' );
        $snapshot->payment_schedule = $params['payment_schedule'] ?? null;

        // Status
        $snapshot->status_at_issue = $params['status_at_issue'] ?? 'issued';

        return $snapshot;
    }
}
