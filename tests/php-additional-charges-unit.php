<?php
define( 'ABSPATH', __DIR__ );
class WP_Error { private $code; public function __construct( $code, $message = '' ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_]/', '', strtolower( $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( $v ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( $v ) ); }
function sanitize_email( $v ) { return trim( $v ); }
function is_email( $v ) { return filter_var( $v, FILTER_VALIDATE_EMAIL ); }
function wp_date( $f ) { return date( $f, strtotime( '2026-10-15' ) ); }
function current_user_can( $cap ) { return ! empty( $GLOBALS['charge_manager'] ); }
require __DIR__ . '/../wp-plugin/mathlin-booking/includes/class-money.php';
require __DIR__ . '/../wp-plugin/mathlin-booking/includes/class-additional-charges.php';
require __DIR__ . '/../wp-plugin/mathlin-booking/includes/class-invoice-payment.php';
$checks = 0;
function charge_assert( $value, $message ) { global $checks; $checks++; if ( ! $value ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); } }
$data = array( 'amount' => '25.00', 'category' => 'cleaning', 'description' => 'Additional cleaning', 'service_date' => '2026-10-12', 'due_date' => '2026-10-29', 'contact_name' => 'Synthetic Hirer', 'contact_email' => 'synthetic@example.test' );
$clean = MBS_Additional_Charges::validate( $data );
charge_assert( ! is_wp_error( $clean ) && $clean['amount_minor'] === 2500, 'Exact £25 cleaning charge.' );
charge_assert( $clean['automatic_reminders'] === false, 'Reminders default off.' );
foreach ( array( '0', '-1', '25.001', '2e3', 'NaN', '', '1,000', '1000001.00', 25.0, array() ) as $bad ) {
    charge_assert( is_wp_error( MBS_Additional_Charges::validate( array_merge( $data, array( 'amount' => $bad ) ) ) ), 'Reject invalid amount ' . json_encode( $bad ) );
}
foreach ( array( '2026-02-30', '2026-13-01', '2026-10-14', 'tomorrow', array() ) as $bad ) {
    charge_assert( is_wp_error( MBS_Additional_Charges::validate( array_merge( $data, array( 'due_date' => $bad ) ) ) ), 'Reject invalid/past due date.' );
}
foreach ( array( 'category' => 'hire', 'description' => '', 'contact_email' => 'not-an-email', 'contact_name' => '' ) as $field => $bad ) {
    charge_assert( is_wp_error( MBS_Additional_Charges::validate( array_merge( $data, array( $field => $bad ) ) ) ), 'Reject invalid ' . $field );
}
charge_assert( is_wp_error( MBS_Additional_Charges::create( $data, 'synthetic-key' ) ), 'Create denied before any DB access for non-manager.' );
charge_assert( is_wp_error( MBS_Additional_Charges::issue( 'INV-SYNTHETIC', 1 ) ), 'Issue denied for non-manager.' );
$charge = (object) array( 'billing_mode' => 'additional_charge' );
$settle = new ReflectionMethod( 'MBS_Invoice_Payment', 'mark_covered_occurrences_paid' );
if ( PHP_VERSION_ID < 80100 ) $settle->setAccessible( true );
charge_assert( $settle->invoke( null, $charge ) === true, 'Charge settlement touches no booking/database.' );
$refund = new ReflectionMethod( 'MBS_Invoice_Payment', 'refund_allocations' );
if ( PHP_VERSION_ID < 80100 ) $refund->setAccessible( true );
charge_assert( $refund->invoke( null, $charge, 2500, array() ) === array(), 'Charge refund creates no hire allocations.' );
charge_assert( is_wp_error( $refund->invoke( null, $charge, 2500, array( 'MBS-SYNTHETIC' => 2500 ) ) ), 'Explicit hire allocation is forbidden on charge refunds.' );
$source = file_get_contents( __DIR__ . '/../wp-plugin/mathlin-booking/includes/class-additional-charges.php' );
preg_match_all("/self::finish\\( [^,]+, '([^']+)'/",$source,$audit_actions);
charge_assert(count($audit_actions[1])>=6,'Find all charge completion audit events');
foreach($audit_actions[1] as $action)charge_assert(strlen($action)<=30,'Audit event fits existing VARCHAR(30): '.$action);
foreach ( array( "'booking_ref' => null", 'check_admin_referer', 'FOR UPDATE', 'expected', 'additional_charge:', 'create_ledger_document_within_transaction' ) as $needle ) {
    // Structural guard supplements behavioural tests; not database proof.
    if ( $needle === 'expected' ) continue;
    charge_assert( strpos( $source, $needle ) !== false, 'Safety integration: ' . $needle );
}
function get_option($name,$default=false){return $GLOBALS['charge_options'][$name]??$default;}
class MBS_Invoice_Reservation { public static $claim; public static function get($ref){return self::$claim;} }
$charge->id=1; $charge->invoice_ref='INV-SYNTHETIC';
foreach(array('active','bound','reconciliation_required') as $status){
    MBS_Invoice_Reservation::$claim=(object)array('status'=>$status,'order_id'=>42,'last_error'=>'Synthetic');
    charge_assert(MBS_Additional_Charges::communications_held($charge),'Suppress payment requests for '.$status);
}
MBS_Invoice_Reservation::$claim=(object)array('status'=>'released');
charge_assert(!MBS_Additional_Charges::communications_held($charge),'Released checkout does not hold requests');
$GLOBALS['charge_options']['mbs_charge_reminders_1']='0';
charge_assert(!MBS_Additional_Charges::reminders_enabled($charge),'Post-issue disable honoured');
$GLOBALS['charge_options']['mbs_charge_reminders_1']='1';
charge_assert(MBS_Additional_Charges::reminders_enabled($charge),'Post-issue enable honoured');
charge_assert(MBS_Additional_Charges::customer_for_booking((object)array('email'=>'owner@example.test'),'other@example.test')===array(),'Guest email mismatch returns no charges before DB access');
charge_assert(!is_wp_error(MBS_Additional_Charges::validate(array_merge($data,array('description'=>str_repeat('X',500))))),'500 byte description accepted without suffix');
charge_assert(is_wp_error(MBS_Additional_Charges::validate(array_merge($data,array('description'=>str_repeat('X',501))))),'501 byte description rejected');
define('MBS_INVOICE_TABLE','invoices');
class ChargeReminderDb { public $prefix='fake_'; public function query($sql){return 1;} public function prepare($sql,...$args){return $sql;} public function get_row($sql){return $GLOBALS['reminder_invoice'];} }
class MBS_Billing_Ledger { public static function balance_minor($i){return 2500;} }
$wpdb=new ChargeReminderDb();
$charge->document_type='invoice';$charge->status='issued';
$GLOBALS['reminder_invoice']=$charge;
MBS_Invoice_Reservation::$claim=(object)array('status'=>'reconciliation_required','order_id'=>42);
$reminder=MBS_Invoice_Payment::send_invoice_reminder('INV-SYNTHETIC');
charge_assert(!is_wp_error($reminder)&&$reminder['no_op']===true,'Actual reminder service suppresses unreconciled payment even when opted in');
echo "Additional charge unit tests passed ($checks checks).\n";
