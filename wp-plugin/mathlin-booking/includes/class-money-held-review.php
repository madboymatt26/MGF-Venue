<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Pure reconciliation: receipt history stays intact; no gateway writes here. */
class MBS_Money_Held_Review {
    public static function balance( $received, $verified_refunds, $retained ) {
        foreach ( array( $received, $verified_refunds, $retained ) as $amount ) if ( ! is_int( $amount ) || $amount < 0 ) return new WP_Error( 'review_invalid_money', 'Review amounts must be non-negative integer minor units.' );
        if ( $verified_refunds > $received || $retained > $received - $verified_refunds ) return new WP_Error( 'review_exceeds_receipts', 'Refunds and retention decisions exceed the money received. Review the evidence before closing.' );
        $unresolved = $received - $verified_refunds - $retained;
        return array( 'received_minor' => $received, 'refunded_minor' => $verified_refunds, 'retained_minor' => $retained, 'unresolved_minor' => $unresolved, 'state' => $unresolved > 0 ? 'review_required' : 'resolved' );
    }

    /** Bank records are evidence of an already completed transfer, not a refund API. */
    public static function bank_refund( $amount, $available, $date, $reference, $reason, $reviewer, $today ) {
        if ( ! is_int( $amount ) || ! is_int( $available ) || $amount < 1 || $amount > $available ) return new WP_Error( 'refund_invalid_amount', 'Refund must be positive and not exceed the unresolved money held.' );
        $parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date );
        if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date || $date > $today ) return new WP_Error( 'refund_invalid_date', 'Enter the date of the completed bank refund, not a future date.' );
        if ( ! is_string( $reference ) || trim( $reference ) === '' || ! is_string( $reason ) || trim( $reason ) === '' || ! is_int( $reviewer ) || $reviewer < 1 ) return new WP_Error( 'refund_evidence_required', 'A transfer reference, reason and authorised reviewer are required.' );
        return array( 'kind' => 'completed_bank_refund', 'amount_minor' => $amount, 'date' => $date, 'reference' => trim( $reference ), 'reason' => trim( $reason ), 'reviewer' => $reviewer );
    }
}
