<?php
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code; private $message; private $data;
    public function __construct( $code, $message, $data = array() ) { $this->code=$code;$this->message=$message;$this->data=$data; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
require_once dirname( __DIR__ ) . '/wp-plugin/mathlin-booking/includes/class-billing-ledger.php';
$method = new ReflectionMethod( 'MBS_Billing_Ledger', 'idempotency_conflict' );
if ( PHP_VERSION_ID < 80100 ) $method->setAccessible( true );
$legacy = $method->invoke( null, (object) array( 'idempotency_request_hash'=>null ), hash('sha256','new-request') );
$same_hash = hash( 'sha256', 'same' );
$same = $method->invoke( null, (object) array( 'idempotency_request_hash'=>$same_hash ), $same_hash );
$different = $method->invoke( null, (object) array( 'idempotency_request_hash'=>$same_hash ), hash('sha256','different') );
if ( !($legacy instanceof WP_Error) || $legacy->get_error_code() !== 'legacy_idempotency_unverifiable' || $legacy->get_error_data()['status'] !== 409 || $same !== null || !($different instanceof WP_Error) || $different->get_error_code() !== 'idempotency_conflict' ) {
    fwrite( STDERR, "FAIL: legacy idempotency behavior is not fail-closed and explicit.\n" ); exit(1);
}
echo "OK: 3 behavioural idempotency assertions passed (legacy 409, exact replay, conflicting replay).\n";
