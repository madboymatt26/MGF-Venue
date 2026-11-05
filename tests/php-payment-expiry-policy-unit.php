<?php
define( 'ABSPATH', __DIR__ );
class WP_Error { public $code; public function __construct( $code, $message ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
require dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-payment-expiry-policy.php';
require dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-money-held-review.php';
$checks = 0;
function expiry_check( $ok, $message ) { global $checks; $checks++; if ( ! $ok ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); } }
function epoch( $date ) { return ( new DateTimeImmutable( $date, new DateTimeZone( 'Europe/London' ) ) )->getTimestamp(); }
$approved = epoch( '2026-11-05 12:00:00' );
$p = MBS_Payment_Expiry_Policy::approve( $approved, '2026-11-20', 10000, 2500, true );
expiry_check( MBS_Payment_Expiry_Policy::valid( $p ), 'Approval creates a valid frozen snapshot.' );
expiry_check( $p['deposit_due_at'] === $approved + 172800, 'Deposit has 48 elapsed hours from approval.' );
expiry_check( $p['balance_cutoff_at'] === epoch( '2026-11-19 08:00:00' ), 'Final cutoff is day-before local 08:00.' );
foreach ( array( 0, 1, 2499, 2500, 9999, 10000, 11000 ) as $paid ) {
    $d = MBS_Payment_Expiry_Policy::decision( $p, $p['deposit_due_at'], $paid, 'confirmed', true, 10000, '2026-11-20' );
    expiry_check( $d['action'] === ( $paid < 2500 ? 'cancel' : ( $paid >= 10000 ? 'none' : 'wait' ) ), 'Exact deposit threshold and full/overpayment handled.' );
}
expiry_check( MBS_Payment_Expiry_Policy::decision( $p, $p['deposit_due_at'] - 1, 0, 'confirmed', true, 10000, '2026-11-20' )['action'] === 'warn', 'No cancellation one second early.' );
expiry_check( MBS_Payment_Expiry_Policy::decision( $p, $approved + 100, 0, 'confirmed', true, 10000, '2026-11-20' )['action'] === 'wait', 'No premature warning.' );
expiry_check( MBS_Payment_Expiry_Policy::decision( $p, $p['balance_cutoff_at'], 2500, 'deposit_paid', true, 10000, '2026-11-20' )['action'] === 'cancel', 'Deposit does not prevent unpaid balance cancellation.' );
expiry_check( MBS_Payment_Expiry_Policy::decision( $p, $p['balance_cutoff_at'], 2500, 'deposit_paid', false, 10000, '2026-11-20' )['action'] === 'review', 'Ambiguous/processing payments block automatic cancellation.' );
expiry_check( MBS_Payment_Expiry_Policy::decision( $p, $p['balance_cutoff_at'], 2500, 'deposit_paid', true, 11000, '2026-11-20' )['reason'] === 'booking_changed', 'Price changes require policy review.' );
expiry_check( MBS_Payment_Expiry_Policy::decision( $p, $p['balance_cutoff_at'], 2500, 'deposit_paid', true, 10000, '2026-11-21' )['reason'] === 'booking_changed', 'Date changes do not retain a stale cutoff.' );
foreach ( array( 'cancelled', 'archived' ) as $status ) expiry_check( MBS_Payment_Expiry_Policy::decision( $p, $p['balance_cutoff_at'], 0, $status, true, 10000, '2026-11-20' )['action'] === 'none', 'Inactive bookings are not cancelled or warned again.' );
$exempt = $p; $exempt['exempt'] = true;
expiry_check( MBS_Payment_Expiry_Policy::decision( $exempt, $p['balance_cutoff_at'], 0, 'confirmed', true, 10000, '2026-11-20' )['reason'] === 'authorised_exemption', 'Explicit exemption respected.' );
$extension = $p; $extension['extension_until'] = $p['deposit_due_at'] + 3600;
expiry_check( MBS_Payment_Expiry_Policy::decision( $extension, $p['deposit_due_at'], 0, 'confirmed', true, 10000, '2026-11-20' )['action'] === 'warn', 'Extension defers cancellation.' );
expiry_check( MBS_Payment_Expiry_Policy::approve( $approved, '2026-11-20', 10000, 2500, false ) === null, 'Free/internal/offline/series exclusion never enrolls.' );
foreach ( array( '2026-02-30', 'invalid', '2026-1-01' ) as $date ) expiry_check( is_wp_error( MBS_Payment_Expiry_Policy::approve( $approved, $date, 10000, 2500, true ) ), 'Invalid dates fail closed.' );
$cutoff = epoch( '2026-11-19 08:00:00' );
expiry_check( MBS_Payment_Expiry_Policy::approve( $cutoff - 48 * 3600, '2026-11-20', 10000, 2500, true )['deposit_due_at'] === $cutoff, 'Exactly 48 hours allows full deposit window.' );
expiry_check( MBS_Payment_Expiry_Policy::approve( $cutoff - 1, '2026-11-20', 10000, 2500, true )->get_error_code() === 'expiry_short_notice_ack_required', 'Shortened approval requires explicit acknowledgement.' );
$short = MBS_Payment_Expiry_Policy::approve( $cutoff - 3600, '2026-11-20', 10000, 2500, true, true );
expiry_check( $short['short_notice'] && $short['deposit_due_at'] === null, 'Short notice demands full payment at final cutoff.' );
expiry_check( MBS_Payment_Expiry_Policy::decision( $short, $cutoff, 2500, 'deposit_paid', true, 10000, '2026-11-20' )['reason'] === 'unpaid_balance', 'A short-notice deposit alone is insufficient.' );
expiry_check( MBS_Payment_Expiry_Policy::approve( $cutoff, '2026-11-20', 10000, 2500, true, true )->get_error_code() === 'expiry_manual_approval_required', 'Approval at/after cutoff cannot auto-enroll.' );
foreach ( array( array( '2027-03-27 12:00:00', '2027-04-10' ), array( '2026-10-24 12:00:00', '2026-11-10' ) ) as $case ) {
    $a = epoch( $case[0] ); $dst = MBS_Payment_Expiry_Policy::approve( $a, $case[1], 10000, 2500, true );
    expiry_check( $dst['deposit_due_at'] - $a === 172800 && MBS_Payment_Expiry_Policy::valid( $dst ), 'DST does not shorten/lengthen the deposit window.' );
}
expiry_check( strpos( MBS_Payment_Expiry_Policy::warning_text( $p, 500 ), '£20.00' ) !== false, 'Warning deducts partial deposit payment.' );
expiry_check( MBS_Payment_Expiry_Policy::warning_text( $p, 10000 ) === '', 'Settled booking has no warning.' );
$bad = $p; $bad['balance_cutoff_at']++;
expiry_check( ! MBS_Payment_Expiry_Policy::valid( $bad ), 'Altered cutoff cannot silently pass validation.' );
expiry_check( is_wp_error( MBS_Money_Held_Review::balance( 2500, 2501, 0 ) ), 'Refund cannot exceed money received.' );
expiry_check( is_wp_error( MBS_Money_Held_Review::balance( 2500, 1000, 1600 ) ), 'Retention cannot exceed the remaining amount.' );
expiry_check( MBS_Money_Held_Review::balance( 2500, 1000, 0 )['unresolved_minor'] === 1500, 'Partial refund remains flagged.' );
expiry_check( MBS_Money_Held_Review::balance( 2500, 1000, 1500 )['state'] === 'resolved', 'Mixed evidenced refund and retention resolves the review.' );
expiry_check( is_wp_error( MBS_Money_Held_Review::bank_refund( 1000, 2500, '2026-11-06', 'BACS-1', 'Agreed refund', 1, '2026-11-05' ) ), 'Future bank refund cannot be recorded as completed.' );
expiry_check( is_wp_error( MBS_Money_Held_Review::bank_refund( 1000, 2500, '2026-11-05', '', 'Agreed refund', 1, '2026-11-05' ) ), 'Transfer evidence required.' );
expiry_check( MBS_Money_Held_Review::bank_refund( 1000, 2500, '2026-11-05', 'BACS-1', 'Agreed refund', 1, '2026-11-05' )['kind'] === 'completed_bank_refund', 'Bank refund records completed transfer, not a request to send money.' );
echo "OK: $checks expiry and money-held policy assertions passed; no state mutations.\n";
