<?php
// Behavioural service test with in-memory adapters; not a real database/SMTP test.
define('ABSPATH',__DIR__); define('ARRAY_A','ARRAY_A'); define('MBS_INVOICE_TABLE','invoices'); define('MBS_INVOICE_DOCUMENTS_TABLE','documents');
class WP_Error { private $code; public function __construct($code,$message=''){ $this->code=$code; } public function get_error_code(){return $this->code;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function current_user_can($cap){return $GLOBALS['manager'];}
function sanitize_text_field($v){return trim(strip_tags($v));}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_url($v){return esc_html($v);}
class DeliveryDb { public $prefix='fake_'; public $queries=0; public function query($sql){$this->queries++;return 1;} public function prepare($sql,...$args){return $sql;} public function get_row($sql){return $GLOBALS['invoice'];} public function get_var($sql){return 8;} }
$wpdb=new DeliveryDb();
class MBS_Billing_Ledger {
    public static function get_invoice($ref){return $ref==='CN-OTHER'?$GLOBALS['foreign_credit']:($ref==='CN-TEST'?$GLOBALS['credit']:$GLOBALS['invoice']);}
    public static function get_items($id){return array((object)array('description'=>'Cleaning <unsafe>'));}
    public static function balance_minor($i){return $i->total_minor-$i->paid_minor;}
}
class MBS_Money { public static function format($minor){return 'GBP '.number_format($minor/100,2,'.','');} }
class MBS_Invoice_Reservation { public static $status='released'; public static function get($ref){return (object)array('status'=>self::$status);} }
class MBS_Invoice_Payment { public static function is_payable($i){return $i->status==='issued'&&MBS_Billing_Ledger::balance_minor($i)>0;} public static function generate_payment_url($i){return 'https://example.test/pay';} }
class MBS_Invoice_Document_Service { public static function get_current_ledger_document_id($id){return 7;} }
class MBS_Email_Templates { public static function get_template($type){return array('subject'=>'{invoice} {credit}','body'=>'{name} {description} {balance} {credit_amount}');} public static function get_org_settings(){return array('name'=>'Synthetic');} }
class MBS_Bookings { public static function get_admin_email(){return 'staff@example.test';} }
class MBS_Audit_Log { public static function log(...$args){return true;} }
class MBS_Email_Queue {
    public static $rows=array();
    public static $dispatches=array();
    public static function dispatch_message($id){self::$dispatches[]=$id;return array('sent'=>1,'failed'=>0);}
    public static function compute_payload_hash(...$args){return hash('sha256',serialize($args));}
    public static function enqueue($to,$subject,$body,$headers,$key,$hash,$attachment,$context){
        if(isset(self::$rows[$key])&&self::$rows[$key]['hash']!==$hash)return new WP_Error('payload_conflict');
        self::$rows[$key]=compact('to','subject','body','key','hash','attachment','context');return true;
    }
}
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-additional-charges.php';
$GLOBALS['manager']=true;
$GLOBALS['invoice']=(object)array('id'=>1,'invoice_ref'=>'INV-TEST','billing_mode'=>'additional_charge','document_type'=>'invoice','version'=>1,'status'=>'issued','total_minor'=>2500,'paid_minor'=>0,'contact_name'=>'Synthetic','contact_email'=>'hirer@example.test','due_at'=>'2026-10-29 23:59:59');
$GLOBALS['credit']=(object)array('id'=>2,'invoice_ref'=>'CN-TEST','document_type'=>'credit_note','parent_invoice_id'=>1,'total_minor'=>-500);
$GLOBALS['foreign_credit']=(object)array('id'=>3,'invoice_ref'=>'CN-OTHER','document_type'=>'credit_note','parent_invoice_id'=>99,'total_minor'=>-500);
$checks=0;
function check_delivery($ok,$message){global $checks;$checks++;if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
foreach(array('active','bound','reconciliation_required') as $status){
    MBS_Invoice_Reservation::$status=$status;
    check_delivery(MBS_Additional_Charges::send('INV-TEST',1)->get_error_code()==='charge_payment_under_review','Service blocks send for '.$status);
}
check_delivery(count(MBS_Email_Queue::$rows)===0,'Held payment creates no queued email');
MBS_Invoice_Reservation::$status='released';
check_delivery(!is_wp_error(MBS_Additional_Charges::send('INV-TEST',1,'first-send-key')),'Explicit invoice send works');
check_delivery(count(MBS_Email_Queue::$dispatches)===1,'Explicit send dispatches its committed row immediately');
check_delivery(!is_wp_error(MBS_Additional_Charges::send('INV-TEST',1,'first-send-key'))&&count(MBS_Email_Queue::$rows)===1,'Identical send deduplicates');
$GLOBALS['invoice']->paid_minor=500;
check_delivery(!is_wp_error(MBS_Additional_Charges::resend('INV-TEST',1,'explicit-resend-key')),'Explicit partial-payment resend works');
$row=end(MBS_Email_Queue::$rows);
check_delivery(strpos($row['body'],'GBP 20.00')!==false,'Resend includes current balance');
check_delivery(strpos($row['body'],'Cleaning &lt;unsafe&gt;')!==false,'Customer content escaped');
check_delivery(strlen($row['key'])<=100,'Message key fits database column');
check_delivery($row['attachment']['document_id']===7,'Existing issued PDF attached');
check_delivery(MBS_Additional_Charges::send_credit('INV-TEST',1,'CN-OTHER','foreign-credit-key')->get_error_code()==='wrong_charge_document','Cannot send another charge credit note');
check_delivery(MBS_Additional_Charges::send_credit('INV-TEST',1,'','missing-credit-key')->get_error_code()==='credit_reference_required','Missing credit reference cannot fall back to invoice email');
check_delivery(!is_wp_error(MBS_Additional_Charges::send_credit('INV-TEST',1,'CN-TEST','own-credit-email')),'Own credit email explicit');
$row=end(MBS_Email_Queue::$rows);
check_delivery($row['attachment']['document_id']===8&&strpos($row['body'],'does not itself refund cash')!==false,'Credit PDF and no-cash-refund notice');
check_delivery(strpos($row['body'],'Pay this additional charge')===false,'Credit email does not demand payment');
check_delivery(MBS_Additional_Charges::queued_delivery_error($GLOBALS['invoice'],array('charge_balance_minor'=>2500))!=='','Old queued balance held after partial payment');
check_delivery(MBS_Additional_Charges::queued_delivery_error($GLOBALS['invoice'],array('charge_balance_minor'=>2000))==='','Current queued balance can dispatch');
MBS_Invoice_Reservation::$status='reconciliation_required';
check_delivery(MBS_Additional_Charges::queued_delivery_error($GLOBALS['invoice'],array('charge_balance_minor'=>2000))!=='','Already queued request held during reconciliation');
MBS_Invoice_Reservation::$status='released';$GLOBALS['invoice']->paid_minor=2500;
check_delivery(MBS_Additional_Charges::queued_delivery_error($GLOBALS['invoice'],array())!=='','Already paid invoice cannot dispatch payment demand');
$GLOBALS['manager']=false;
$before=$wpdb->queries;
check_delivery(MBS_Additional_Charges::resend('INV-TEST',1,'unauthorized-key')->get_error_code()==='forbidden'&&$wpdb->queries===$before,'No database mutation without manager permission');
echo "Additional charge delivery service tests passed ($checks checks).\n";
