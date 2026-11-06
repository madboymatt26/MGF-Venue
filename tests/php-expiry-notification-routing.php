<?php
define('ABSPATH',__DIR__);
class WP_Error {public $code;public function __construct($code,$message){$this->code=$code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL);}
function esc_html($v){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
$options=array('mbs_additional_emails'=>' matt@example.invalid, MATT@example.invalid, invalid, PRIMARY@example.invalid, second@example.invalid ');
function get_option($key,$default=''){global$options;return$options[$key]??$default;}
class MBS_Bookings {public static $primary='Primary@example.invalid';public static function get_admin_email(){return self::$primary;}}
class MBS_Email_Templates {public static function get_org_settings(){return array('name'=>'Synthetic venue');}}
class MBS_Email_Queue {
 public static $rows=array(); public static $fail='';
 public static function compute_payload_hash($to,$subject,$body,$headers){return hash('sha256',json_encode(func_get_args()));}
 public static function enqueue($to,$subject,$body,$headers,$key,$hash,$meta,$context){
  if($to===self::$fail)return new WP_Error('synthetic_failure','Synthetic enqueue failure');
  if(isset(self::$rows[$key])&&self::$rows[$key]['hash']!==$hash)return new WP_Error('conflict','Payload conflict');
  self::$rows[$key]=array('to'=>$to,'hash'=>$hash,'context'=>$context);return true;
 }
}
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-email.php';
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-payment-expiry.php';
$checks=0;$check=static function($ok,$label)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($label);};
$check(MBS_Email::get_notification_emails()===array('primary@example.invalid','matt@example.invalid','second@example.invalid'),'Primary plus valid extras, trim/case dedup');
$method=new ReflectionMethod('MBS_Payment_Expiry','queue');if(PHP_VERSION_ID<80100)$method->setAccessible(true);
$b=(object)array('ref'=>'SYNTHETIC','id'=>1,'email'=>'customer@example.invalid');
$queue=static function($admin=true,$key='expiry_admin:SYNTHETIC')use($method,$b){return$method->invoke(null,$b,$admin?'admin':'cancel','Synthetic alert',$key,$admin);};
$check($queue()===true,'Admin fanout queued');$check(count(MBS_Email_Queue::$rows)===3,'Separate recipient outbox rows');
$check(MBS_Email_Queue::$rows['expiry_admin:SYNTHETIC']['to']==='primary@example.invalid','Original primary idempotency key preserved');
$check($queue()===true&&count(MBS_Email_Queue::$rows)===3,'Replay does not duplicate recipients');
$check($queue(false,'expiry_cancel:SYNTHETIC')===true,'Customer cancellation unchanged');
$check(MBS_Email_Queue::$rows['expiry_cancel:SYNTHETIC']['to']==='customer@example.invalid','Customer not replaced by admin list');
foreach(array_keys(MBS_Email_Queue::$rows)as$key)$check(strlen($key)<=100,'Keys fit database limit');
MBS_Email_Queue::$fail='second@example.invalid';$check(is_wp_error($queue(true,'expiry_admin:FAIL')),'Any recipient enqueue failure propagates for transaction rollback');
$options['mbs_additional_emails']='bad';MBS_Bookings::$primary='invalid';$check(MBS_Email::get_notification_emails()===array(),'Invalid primary/extras rejected');
$check(is_wp_error($queue(true,'expiry_admin:EMPTY')),'No valid administrator fails closed');
echo "EXPIRY_NOTIFICATION_ROUTING_OK: {$checks} checks; no email sent.\n";
