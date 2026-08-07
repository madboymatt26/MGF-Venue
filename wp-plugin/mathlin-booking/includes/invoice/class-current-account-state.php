<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Live account state for an invoice — computed at render time.
 *
 * This is SEPARATE from the immutable snapshot. It represents the current
 * financial position and is displayed only in the Current Account View,
 * never in the canonical Issued Invoice document.
 */
class MBS_Current_Account_State {

    /** @var string Current ledger status (issued, part_paid, paid, overdue, credited, void) */
    public $current_status = 'issued';

    /** @var int Total payments received in minor units */
    public $payments_received_minor = 0;

    /** @var int Total refunded in minor units */
    public $refunded_minor = 0;

    /** @var int Credits applied in minor units */
    public $credits_applied_minor = 0;

    /** @var int Outstanding balance in minor units */
    public $outstanding_balance_minor = 0;

    /** @var bool Whether the invoice is past its due date with remaining balance */
    public $is_overdue = false;

    /** @var string|null Current Pay Now URL (freshly generated, never stored in snapshot) */
    public $pay_now_url = null;

    /** @var string|null Date of last payment Y-m-d H:i:s */
    public $last_payment_at = null;
}

/**
 * Builds account state from consolidated ledger invoices.
 */
class MBS_Ledger_Account_State_Builder {

    /**
     * Build account state from a ledger invoice row.
     *
     * @param object $invoice Row from mathlin_invoices.
     * @return MBS_Current_Account_State
     */
    public static function build( $invoice ) {
        $state = new MBS_Current_Account_State();

        $state->current_status = $invoice->status;
        $state->payments_received_minor = (int) $invoice->paid_minor;
        $state->credits_applied_minor = (int) $invoice->credited_minor;
        $state->outstanding_balance_minor = MBS_Billing_Ledger::balance_minor( $invoice );
        $state->refunded_minor = self::total_refunded( $invoice );

        // Overdue check
        $state->is_overdue = $state->outstanding_balance_minor > 0
            && ! empty( $invoice->due_at )
            && strtotime( $invoice->due_at ) < time();

        // Generate fresh payment URL (never stored)
        if ( $state->outstanding_balance_minor > 0 && MBS_Invoice_Payment::is_payable( $invoice ) ) {
            $state->pay_now_url = MBS_Invoice_Payment::generate_payment_url( $invoice );
        }

        // Last payment timestamp
        $state->last_payment_at = self::last_payment_date( $invoice );

        return $state;
    }

    /**
     * @param object $invoice
     * @return int Total refunded minor units.
     */
    private static function total_refunded( $invoice ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_PAYMENT_TRANSACTION_TABLE;
        $result = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount_minor), 0) FROM {$table}
             WHERE invoice_id = %d AND transaction_type = 'refund' AND status = 'completed'",
            (int) $invoice->id
        ) );
        return (int) $result;
    }

    /**
     * @param object $invoice
     * @return string|null
     */
    private static function last_payment_date( $invoice ) {
        global $wpdb;
        $table = $wpdb->prefix . MBS_PAYMENT_TRANSACTION_TABLE;
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(occurred_at) FROM {$table}
             WHERE invoice_id = %d AND transaction_type = 'payment' AND status = 'completed'",
            (int) $invoice->id
        ) );
    }
}

/**
 * Builds account state from individual booking payment fields.
 */
class MBS_Booking_Account_State_Builder {

    /**
     * Build account state from a booking row.
     *
     * @param object $booking Row from mathlin_bookings.
     * @return MBS_Current_Account_State
     */
    public static function build( $booking ) {
        $state = new MBS_Current_Account_State();

        $state->current_status = $booking->status;
        $state->payments_received_minor = (int) round( (float) $booking->amount_paid * 100 );
        $state->outstanding_balance_minor = max( 0,
            (int) round( (float) $booking->amount * 100 ) - $state->payments_received_minor
        );

        // Individual bookings: overdue if confirmed/deposit_paid and past payment terms
        $state->is_overdue = $state->outstanding_balance_minor > 0
            && in_array( $booking->status, array( 'confirmed', 'deposit_paid' ), true )
            && ! empty( $booking->created_at )
            && self::is_past_terms( $booking );

        // Generate payment URL if available and applicable
        if ( $state->outstanding_balance_minor > 0
            && in_array( $booking->status, array( 'confirmed', 'deposit_paid' ), true )
            && class_exists( 'MBS_Woo_Payment' ) ) {
            $url = MBS_Woo_Payment::generate_payment_url( $booking );
            if ( $url ) $state->pay_now_url = $url;
        }

        return $state;
    }

    /**
     * Check if a booking has exceeded its payment terms.
     *
     * @param object $booking
     * @return bool
     */
    private static function is_past_terms( $booking ) {
        $bank = MBS_Bookings::get_bank_details();
        $days = (int) $bank['payment_days'];
        if ( $days <= 0 ) return false;

        $created = strtotime( $booking->created_at );
        if ( ! $created ) return false;

        return time() > ( $created + ( $days * DAY_IN_SECONDS ) );
    }
}
