<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Pure, versioned policy. This class never writes, sends mail or refunds money. */
class MBS_Payment_Expiry_Policy {
    const VERSION = 1;

    /** Money and timestamps are integer minor units and UTC epoch seconds. */
    public static function approve( $approved_at, $arrival_date, $total, $deposit, $eligible, $short_notice_ack = false, $timezone = null ) {
        if ( ! $eligible ) return null;
        if ( ! is_int( $approved_at ) || ! is_int( $total ) || ! is_int( $deposit ) || $approved_at < 1 || $total < 1 || $deposit < 0 || $deposit > $total ) {
            return new WP_Error( 'expiry_invalid_snapshot', 'Cannot establish a safe payment cancellation policy from these approval details.' );
        }
        $timezone = $timezone ?: new DateTimeZone( 'Europe/London' );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', (string) $arrival_date ) ) return new WP_Error( 'expiry_invalid_date', 'A valid arrival date is required.' );
        $arrival = DateTimeImmutable::createFromFormat( '!Y-m-d', $arrival_date, $timezone );
        if ( ! $arrival || $arrival->format( 'Y-m-d' ) !== $arrival_date ) return new WP_Error( 'expiry_invalid_date', 'A valid arrival date is required.' );
        $cutoff = $arrival->modify( '-1 day' )->setTime( 8, 0 )->getTimestamp();
        if ( $approved_at >= $cutoff ) return new WP_Error( 'expiry_manual_approval_required', 'The payment cancellation cutoff has already passed. This short-notice hire requires manual review; no automatic policy was enrolled.' );
        $short = $cutoff - $approved_at < 48 * 3600;
        if ( $short && ! $short_notice_ack ) return new WP_Error( 'expiry_short_notice_ack_required', 'Review and explicitly acknowledge the shortened full-payment deadline before approving this hire.' );
        return array(
            'version' => self::VERSION,
            'approved_at' => $approved_at,
            'arrival_date' => $arrival_date,
            'timezone' => $timezone->getName(),
            'total_minor' => $total,
            'deposit_minor' => $deposit,
            'deposit_due_at' => ! $short && $deposit > 0 ? $approved_at + 48 * 3600 : null,
            'balance_cutoff_at' => $cutoff,
            'short_notice' => $short,
            'exempt' => false,
            'extension_until' => null,
        );
    }

    /** Reject changed amounts/dates instead of silently applying an old policy. */
    public static function decision( $policy, $now, $paid, $status, $payment_clear, $total, $arrival_date ) {
        if ( ! self::valid( $policy ) || ! is_int( $now ) || ! is_int( $paid ) || $paid < 0 ) return array( 'action' => 'review', 'reason' => 'invalid_policy_or_payment' );
        if ( in_array( $status, array( 'cancelled', 'archived' ), true ) ) return array( 'action' => 'none', 'reason' => 'inactive_booking' );
        if ( $policy['exempt'] ) return array( 'action' => 'none', 'reason' => 'authorised_exemption' );
        if ( $total !== $policy['total_minor'] || $arrival_date !== $policy['arrival_date'] ) return array( 'action' => 'review', 'reason' => 'booking_changed' );
        if ( $paid >= $total ) return array( 'action' => 'none', 'reason' => 'settled' );
        if ( ! in_array( $status, array( 'confirmed', 'deposit_paid' ), true ) ) return array( 'action' => 'review', 'reason' => 'unexpected_status' );
        if ( ! $payment_clear ) return array( 'action' => 'review', 'reason' => 'payment_processing_or_ambiguous' );
        $deadline = self::next_deadline( $policy, $paid );
        $result = array( 'action' => 'wait', 'reason' => $deadline['kind'], 'deadline' => $deadline['at'], 'required_minor' => $deadline['required_minor'], 'outstanding_minor' => max( 0, $deadline['required_minor'] - $paid ) );
        if ( $now >= $deadline['at'] ) $result['action'] = 'cancel';
        elseif ( $now >= max( $policy['approved_at'], $deadline['at'] - 24 * 3600 ) ) $result['action'] = 'warn';
        return $result;
    }

    public static function next_deadline( $policy, $paid ) {
        $deposit = $policy['deposit_due_at'] !== null && $paid < $policy['deposit_minor'];
        $at = $deposit ? $policy['deposit_due_at'] : $policy['balance_cutoff_at'];
        if ( $policy['extension_until'] !== null ) $at = max( $at, $policy['extension_until'] );
        return array( 'kind' => $deposit ? 'unpaid_deposit' : 'unpaid_balance', 'at' => $at, 'required_minor' => $deposit ? $policy['deposit_minor'] : $policy['total_minor'] );
    }

    public static function valid( $p ) {
        if ( ! is_array( $p ) || ( $p['version'] ?? null ) !== self::VERSION ) return false;
        foreach ( array( 'approved_at', 'total_minor', 'deposit_minor', 'balance_cutoff_at' ) as $key ) if ( ! isset( $p[$key] ) || ! is_int( $p[$key] ) ) return false;
        if ( ! array_key_exists( 'deposit_due_at', $p ) || ! array_key_exists( 'extension_until', $p ) || ! isset( $p['arrival_date'], $p['timezone'], $p['short_notice'], $p['exempt'] ) ) return false;
        if ( ! is_bool( $p['short_notice'] ) || ! is_bool( $p['exempt'] ) || ! is_string( $p['arrival_date'] ) || ! is_string( $p['timezone'] ) ) return false;
        if ( $p['approved_at'] < 1 || $p['total_minor'] < 1 || $p['deposit_minor'] < 0 || $p['deposit_minor'] > $p['total_minor'] || $p['balance_cutoff_at'] <= $p['approved_at'] ) return false;
        if ( $p['deposit_due_at'] !== null && ( ! is_int( $p['deposit_due_at'] ) || $p['deposit_due_at'] !== $p['approved_at'] + 48 * 3600 || $p['deposit_due_at'] > $p['balance_cutoff_at'] ) ) return false;
        if ( $p['extension_until'] !== null && ( ! is_int( $p['extension_until'] ) || $p['extension_until'] <= $p['approved_at'] ) ) return false;
        try {
            $arrival = DateTimeImmutable::createFromFormat( '!Y-m-d', $p['arrival_date'], new DateTimeZone( $p['timezone'] ) );
            if ( ! $arrival || $arrival->format( 'Y-m-d' ) !== $p['arrival_date'] || $arrival->modify( '-1 day' )->setTime( 8, 0 )->getTimestamp() !== $p['balance_cutoff_at'] ) return false;
        } catch ( Throwable $e ) { return false; }
        $short = $p['balance_cutoff_at'] - $p['approved_at'] < 48 * 3600;
        return $p['short_notice'] === $short && ( $p['deposit_due_at'] !== null ) === ( ! $short && $p['deposit_minor'] > 0 );
    }

    public static function warning_text( $policy, $paid ) {
        if ( ! self::valid( $policy ) || ! is_int( $paid ) || $paid < 0 || $paid >= $policy['total_minor'] || $policy['exempt'] ) return '';
        $d = self::next_deadline( $policy, $paid );
        $date = ( new DateTimeImmutable( '@' . $d['at'] ) )->setTimezone( new DateTimeZone( $policy['timezone'] ) )->format( 'j F Y \a\t H:i T' );
        $amount = '£' . number_format( max( 0, $d['required_minor'] - $paid ) / 100, 2, '.', '' );
        return ( $d['kind'] === 'unpaid_deposit' ? 'Your outstanding deposit of ' : 'Your outstanding balance of ' ) . $amount . ' must be paid by ' . $date . '. If payment is not received by this deadline, your booking will unfortunately be cancelled to make the venue available for others again. No access code will be issued for a cancelled booking.';
    }
}
