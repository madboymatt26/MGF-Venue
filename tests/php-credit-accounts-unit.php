<?php
define( 'ABSPATH', __DIR__ );
class WP_Error { public function __construct( $code, $message ) {} }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_text_field( $v ) { return trim( strip_tags( $v ) ); }
function is_email( $v ) { return filter_var( $v, FILTER_VALIDATE_EMAIL ); }
function wp_timezone() { return new DateTimeZone( 'Europe/London' ); }
function wp_date( $format ) { return '2026-11-06'; }
function wp_json_encode( $v ) { return json_encode( $v ); }
require dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-credit-accounts.php';
$checks = 0;
$check = static function( $ok, $text ) use ( &$checks ) { $checks++; if ( ! $ok ) { fwrite( STDERR, "FAIL: $text\n" ); exit( 1 ); } };
$input = array( 'name' => 'Synthetic organisation', 'contacts' => 'First@example.invalid,first@example.invalid;second@example.invalid', 'terms_days' => '14', 'valid_until' => '2027-01-31' );
$g = MBS_Credit_Accounts::validate_agreement( $input );
$check( ! is_wp_error( $g ) && count( $g['contacts'] ) === 2, 'Exact normalised contacts, deduplicated' );
$link = (object) array( 'contact_email' => 'FIRST@example.invalid' );
$check( MBS_Credit_Accounts::permits( $g, $link, 'first@example.invalid', '2027-01-31', '2026-11-06' ), 'Inclusive last occupied date' );
$check( ! MBS_Credit_Accounts::permits( $g, $link, 'other@example.invalid', '2027-01-01', '2026-11-06' ), 'Same organisation/domain never grants another contact credit' );
$check( ! MBS_Credit_Accounts::permits( $g, (object) array( 'contact_email' => 'second@example.invalid' ), 'first@example.invalid', '2027-01-01', '2026-11-06' ), 'Assignment identity must also match' );
$check( ! MBS_Credit_Accounts::permits( $g, $link, 'first@example.invalid', '2027-02-01', '2026-11-06' ), 'Future hire beyond agreement denied' );
$check( ! MBS_Credit_Accounts::permits( $g, $link, 'first@example.invalid', '2027-01-31', '2027-02-01' ), 'Expired agreement denied' );
$check( ! MBS_Credit_Accounts::permits( $g, $link, 'first@example.invalid', '2027-01-01', '2026-11-06', true ), 'Unpaid access opt-in defaults off' );
$g['allow_unpaid_access'] = true;
$check( MBS_Credit_Accounts::permits( $g, $link, 'first@example.invalid', '2027-01-01', '2026-11-06', true ), 'Explicit unpaid access permission' );
foreach ( array( array( 'terms_days' => '0' ), array( 'terms_days' => '-14' ), array( 'terms_days' => '91' ), array( 'terms_days' => '1.5' ), array( 'terms_days' => 'abc' ), array( 'contacts' => 'bad-email' ), array( 'contacts' => '' ), array( 'valid_until' => '2027-02-30' ), array( 'valid_until' => '2026-11-05' ), array( 'name' => '' ) ) as $bad ) $check( is_wp_error( MBS_Credit_Accounts::validate_agreement( array_merge( $input, $bad ) ) ), 'Reject invalid agreement input' );
$b = (object) array( 'ref' => 'TEST', 'email' => 'first@example.invalid', 'status' => 'pending', 'amount' => '30.00' );
$fingerprint = MBS_Credit_Accounts::fingerprint( $b, 'booking' );
foreach ( array( 'email' => 'second@example.invalid', 'amount' => '31.00', 'status' => 'confirmed', 'current_invoice_document_id' => 1, 'series_id' => 'SERIES' ) as $field => $value ) { $changed = clone $b; $changed->$field = $value; $check( $fingerprint !== MBS_Credit_Accounts::fingerprint( $changed, 'booking' ), 'Financial/identity changes invalidate staff review: ' . $field ); }


foreach ( array( 'monthly','termly','upfront','legacy_per_occurrence' ) as $mode ) $check( MBS_Credit_Accounts::has_invoice_schedule( (object) array( 'billing_treatment'=>'invoice_managed','billing_mode'=>$mode,'payment_method'=>'offline_bacs' ) ), 'Supported credit invoice frequency: ' . $mode );
foreach ( array( array('billing_mode'=>'none'), array('payment_method'=>'none'), array('billing_treatment'=>'none') ) as $bad ) $check( ! MBS_Credit_Accounts::has_invoice_schedule( (object) array_merge( array( 'billing_treatment'=>'invoice_managed','billing_mode'=>'monthly','payment_method'=>'online' ), $bad ) ), 'Credit must retain an actual payable invoice schedule' );
echo "PASS: {$checks} total credit agreement and schedule assertions\n";
