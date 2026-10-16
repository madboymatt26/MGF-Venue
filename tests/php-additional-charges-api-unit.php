<?php
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MBS_INVOICE_DOCUMENTS_TABLE', 'documents' );
define( 'MBS_INVOICE_TABLE', 'invoices' );
define( 'MBS_PAYMENT_TRANSACTION_TABLE', 'payments' );
class WP_Error { private $code; public function __construct( $code, $message = '', $data = array() ) { $this->code=$code; } public function get_error_code(){ return $this->code; } }
class WP_REST_Request {
    private $params; public function __construct($params){$this->params=$params;}
    public function get_json_params(){return $this->params;}
    public function get_param($name){return $this->params[$name]??null;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function current_user_can($cap){return !empty($GLOBALS['api_manager']);}
function get_current_user_id(){return 7;}
function sanitize_text_field($v){return is_scalar($v)?trim((string)$v):'';}
function sanitize_key($v){return preg_replace('/[^a-z0-9_]/','',strtolower((string)$v));}
function wp_json_encode($v){return json_encode($v);}
function rest_ensure_response($v){return $v;}
function get_transient($k){return $GLOBALS['api_transients'][$k]??false;}
function set_transient($k,$v,$ttl){$GLOBALS['api_transients'][$k]=$v;}
class ChargeFakeDb { public $prefix='fake_'; public function prepare($sql,...$args){return $sql;} public function get_results($sql,$mode=null){return array();} }
$wpdb=new ChargeFakeDb();
class MBS_Billing_Ledger {
    public static function get_items($id){return array();}
    public static function balance_minor($i){return 2500;}
}
class MBS_Hirer_Portal { public static function invoice_transactions($id){return array();} }
class MBS_Additional_Charges {
    public static $calls=array();
    public static function is_archived($i){return false;}
    public static function archive($r,$v){return self::result('archive');}
    public static function restore($r,$v){return self::result('restore');}
    public static function cancel($r,$v,$reason,$key){return self::result('cancel');}
    public static function payment_state($i){return array('status'=>'none');}
    public static function reminders_enabled($i){return false;}
    public static function delivery_history($i){return array();}
    public static function resend($r,$v,$k){return self::result('resend');}
    public static function send_credit($r,$v,$d,$k){return self::result('send_credit');}
    public static function set_reminders($r,$v,$enabled){return self::result('set_reminders');}
    public static function metadata($i){return array('related_ref'=>'MBS-TEST','category'=>'cleaning');}
    public static function result($op){self::$calls[]=$op;return (object)array('id'=>1,'invoice_ref'=>'INV-TEST','billing_mode'=>'additional_charge','total_minor'=>2500,'paid_minor'=>0,'credited_minor'=>0,'version'=>2);}
    public static function create($fields,$key){return self::result('create');}
    public static function update_draft($ref,$fields,$v){return self::result('edit');}
    public static function issue($ref,$v){return self::result('issue');}
    public static function send($ref,$v){return self::result('send');}
    public static function discard($ref,$v){return self::result('discard');}
    public static function credit($ref,$amount,$reason,$v,$key){return self::result('credit');}
}
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-money.php';
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-rest-api.php';
$checks=0;
function api_assert($value,$message){global $checks;$checks++;if(!$value){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
$api=new MBS_Rest_API();
$req=array('operation'=>'send','invoice_ref'=>'INV-TEST','expected_version'=>1,'idempotency_key'=>'send-test-key');
api_assert(is_wp_error($api->mutate_admin_additional_charge(new WP_REST_Request($req))),'Permission denied before mutation');
$GLOBALS['api_manager']=true;
api_assert($api->mutate_admin_additional_charge(new WP_REST_Request($req))->get_error_code()==='charge_send_confirmation_required','No implicit send');
api_assert(MBS_Additional_Charges::$calls===array(),'No service call after rejected send');
$req['confirm_send']=true;
$result=$api->mutate_admin_additional_charge(new WP_REST_Request($req));
api_assert(!is_wp_error($result)&&$result['email_queued']===true,'Explicit send queues only');
$repeat=$api->mutate_admin_additional_charge(new WP_REST_Request($req));
api_assert($repeat['idempotent_replay']===true&&MBS_Additional_Charges::$calls===array('send'),'Same request replays without another service call');
$req['operation']='discard';
api_assert($api->mutate_admin_additional_charge(new WP_REST_Request($req))->get_error_code()==='idempotency_conflict','Key cannot be reused for another action');
$req['idempotency_key']='discard-other-key';unset($req['expected_version']);
api_assert($api->mutate_admin_additional_charge(new WP_REST_Request($req))->get_error_code()==='charge_precondition_required','Mutation requires version');
$req['expected_version']=2;$req['extra_typo']=true;
api_assert($api->mutate_admin_additional_charge(new WP_REST_Request($req))->get_error_code()==='unknown_charge_fields','Unknown top-level fields rejected');
$edit=array('operation'=>'edit','invoice_ref'=>'INV-TEST','expected_version'=>2,'idempotency_key'=>'edit-other-key','fields'=>array('related_ref'=>'MBS-OTHER'));
api_assert($api->mutate_admin_additional_charge(new WP_REST_Request($edit))->get_error_code()==='immutable_charge_association','Cannot relink a draft');
$create=array('operation'=>'create','idempotency_key'=>'create-test-key','fields'=>array('automatic_reminders'=>'false'));
api_assert($api->mutate_admin_additional_charge(new WP_REST_Request($create))->get_error_code()==='invalid_reminder_flag','String false cannot opt in reminders');
foreach(array('issue','discard','credit','archive','restore','cancel') as $op){
    $data=array('operation'=>$op,'invoice_ref'=>'INV-TEST','expected_version'=>2,'idempotency_key'=>'test-action-'.$op,'amount_minor'=>'2500','reason'=>'Synthetic');
    $r=$api->mutate_admin_additional_charge(new WP_REST_Request($data));
    api_assert(!is_wp_error($r)&&$r['email_queued']===false,'No email from '.$op);
}
foreach(array('resend','send_credit') as $op){
    $r=array('operation'=>$op,'invoice_ref'=>'INV-TEST','expected_version'=>2,'idempotency_key'=>'new-action-'.$op,'document_ref'=>'CN-TEST');
    api_assert($api->mutate_admin_additional_charge(new WP_REST_Request($r))->get_error_code()==='charge_send_confirmation_required','Explicit confirmation for '.$op);
    $r['confirm_send']=true;
    api_assert($api->mutate_admin_additional_charge(new WP_REST_Request($r))['email_queued']===true,'Explicit '.$op.' queues email');
}
$r=array('operation'=>'set_reminders','invoice_ref'=>'INV-TEST','expected_version'=>2,'idempotency_key'=>'reminder-option-key','enabled'=>'false');
api_assert($api->mutate_admin_additional_charge(new WP_REST_Request($r))->get_error_code()==='invalid_reminder_flag','Post-issue flag strictly boolean');
$r['enabled']=false;
api_assert($api->mutate_admin_additional_charge(new WP_REST_Request($r))['email_queued']===false,'Reminder preference does not email');
echo "Additional charge API unit tests passed ($checks checks).\n";
