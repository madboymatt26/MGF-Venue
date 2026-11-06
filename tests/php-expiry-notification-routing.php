<?php
define('ABSPATH',__DIR__);
class WP_Error {public $code;public function __construct($code,$message){$this->code=$code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL);}
function esc_html($v){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
function esc_url($v){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
function admin_url($v){return 'https://venue.example.invalid/wp-admin/'.$v;}
$options=array('mbs_additional_emails'=>' matt@example.invalid, MATT@example.invalid, invalid, PRIMARY@example.invalid, second@example.invalid ');
function get_option($key,$default=''){global$options;return$options[$key]??$default;}
class MBS_Bookings {public static $primary='Primary@example.invalid';public static function get_admin_email(){return self::$primary;}public static function get_spaces(){return array();}}
class MBS_Hirer_Identity {public static function manage_url(){return 'https://venue.example.invalid/manage/';}}
class MBS_Email_Templates {public static function get_org_settings(){return array('name'=>'Synthetic Scout Centre','address'=>'Synthetic address','phone'=>'01234 000000','charity_number'=>'1234567');}public static function get_logo_html(){return '<div style="color:#fff;font-size:24px;">Scout Centre</div>';}}
class SyntheticExpiryWpdb {public $prefix='wp_';public $last_error='';public function prepare($sql,$key){return$key;}public function get_row($key){$r=MBS_Email_Queue::$rows[$key]??null;return$r?(object)array_merge(array('to_email'=>$r['to']),$r['context']):null;}}
$wpdb=new SyntheticExpiryWpdb();
class MBS_Email_Queue {
 public static $rows=array(); public static $fail='';
 public static function compute_payload_hash($to,$subject,$body,$headers){return hash('sha256',json_encode(func_get_args()));}
 public static function enqueue($to,$subject,$body,$headers,$key,$hash,$meta,$context){
  if($to===self::$fail)return new WP_Error('synthetic_failure','Synthetic enqueue failure');
  if(isset(self::$rows[$key])&&self::$rows[$key]['hash']!==$hash)return new WP_Error('conflict','Payload conflict');
  self::$rows[$key]=array('to'=>$to,'hash'=>$hash,'context'=>$context,'body'=>$body,'subject'=>$subject);return true;
 }
}
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-email.php';
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-money.php';
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-payment-expiry.php';
$checks=0;$check=static function($ok,$label)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($label);};
$check(MBS_Email::get_notification_emails()===array('primary@example.invalid','matt@example.invalid','second@example.invalid'),'Primary plus valid extras, trim/case dedup');
$method=new ReflectionMethod('MBS_Payment_Expiry','queue');if(PHP_VERSION_ID<80100)$method->setAccessible(true);
$b=(object)array('ref'=>'SYNTHETIC','id'=>1,'email'=>'customer@example.invalid','name'=>'Synthetic hirer','status'=>'cancelled','amount'=>'30.00','amount_paid'=>'7.50','space'=>'Main hall','booking_date'=>'2026-11-20','booking_date_end'=>'2026-11-20','start_time'=>'10:00','end_time'=>'12:00','attendees'=>20,'purpose'=>'Synthetic hire');
$queue=static function($admin=true,$key='expiry_admin:SYNTHETIC')use($method,$b){return$method->invoke(null,$b,$admin?'admin':'cancel','Synthetic alert',$key,$admin);};
$check($queue()===true,'Admin fanout queued');$check(count(MBS_Email_Queue::$rows)===3,'Separate recipient outbox rows');
$check(MBS_Email_Queue::$rows['expiry_admin:SYNTHETIC']['to']==='primary@example.invalid','Original primary idempotency key preserved');
$check($queue()===true&&count(MBS_Email_Queue::$rows)===3,'Replay does not duplicate recipients');
$old=MBS_Email_Queue::$rows['expiry_admin:SYNTHETIC']['body'];MBS_Email_Queue::$rows['expiry_admin:SYNTHETIC']['body']='Legacy-format committed intent';
$check($queue()===true&&MBS_Email_Queue::$rows['expiry_admin:SYNTHETIC']['body']==='Legacy-format committed intent','Formatting upgrade preserves existing payload without conflict or resend');
$check($queue(false,'expiry_cancel:SYNTHETIC')===true,'Customer cancellation unchanged');
$check(MBS_Email_Queue::$rows['expiry_cancel:SYNTHETIC']['to']==='customer@example.invalid','Customer not replaced by admin list');
foreach(array_keys(MBS_Email_Queue::$rows)as$key)$check(strlen($key)<=100,'Keys fit database limit');
MBS_Email_Queue::$fail='second@example.invalid';$check(is_wp_error($queue(true,'expiry_admin:FAIL')),'Any recipient enqueue failure propagates for transaction rollback');
$options['mbs_additional_emails']='bad';MBS_Bookings::$primary='invalid';$check(MBS_Email::get_notification_emails()===array(),'Invalid primary/extras rejected');
$check(is_wp_error($queue(true,'expiry_admin:EMPTY')),'No valid administrator fails closed');
MBS_Bookings::$primary='primary@example.invalid';
$text="Hi Synthetic hirer,\n\nFull payment must be received by Sunday 8 November 2026 at 08:00 GMT. <script>alert(1)</script>";
$b->status='confirmed';$warning=MBS_Email::build_payment_expiry_message($b,'warning',$text);
$check(strpos($warning['body'],'background:#7413DC')!==false,'Shared purple brand header');
$check(strpos($warning['body'],'Registered Charity No. 1234567')!==false,'Shared charity footer');
$check(strpos($warning['body'],'Sunday 8 November 2026 at 08:00 GMT')!==false,'Exact supplied deadline retained');
$check(strpos($warning['body'],'<script>')===false&&strpos($warning['body'],'&lt;script&gt;')!==false,'Message text escaped');
$check(strpos($warning['body'],'Sign in to your booking account')!==false,'Warning has account action, not generated payment token');
$check(strpos($warning['body'],'7.50')!==false&&strpos($warning['body'],'22.50')!==false,'Recorded payment and remaining balance clear');
$b->status='cancelled';$cancel=MBS_Email::build_payment_expiry_message($b,'cancel','Your booking has been cancelled due to non-payment.');
$check(strpos($cancel['body'],'Sign in to your booking account')===false,'Cancelled message does not demand checkout');
$check(strpos($cancel['body'],'Unpaid amount at cancellation')!==false&&strpos($cancel['body'],'Amount Due')===false,'Cancelled amount not labelled a current payment demand');
$check(strpos($cancel['body'],'not a new payment request')!==false,'Cancelled financial history disclaimer');
$admin=MBS_Email::build_payment_expiry_message($b,'admin','Review the held payment. '.admin_url('admin.php?page=mathlin-booking&action=view&ref=SYNTHETIC'),true);
$check(strpos($admin['body'],'Review booking and payment')!==false,'Admin review action');
$check(strpos($admin['body'],'Sign in to your booking account')===false,'Admin does not get customer action');
$check(strpos(implode('|',$admin['headers']),'Reply-To: primary@example.invalid')!==false,'Existing reply-to headers reused');
$b->purpose='<img src=x onerror=alert(1)>';$escaped=MBS_Email::build_payment_expiry_message($b,'cancel','Cancelled');
$check(strpos($escaped['body'],'onerror=alert(1)>')===false,'Booking details escaped');
$b->amount_paid='unknown';$check(is_wp_error(MBS_Email::build_payment_expiry_message($b,'cancel','Cancelled')),'Invalid payment display fails closed');
if(getenv('MBS_EMAIL_PREVIEW_DIR')){
 $b->purpose='Community activity';$b->amount_paid='7.50';$b->status='confirmed';
 $warning=MBS_Email::build_payment_expiry_message($b,'warning',"Hi Synthetic hirer,\n\nYour remaining balance of £22.50 must be paid by Sunday 8 November 2026 at 08:00 GMT. If payment is not received by this deadline, your booking will unfortunately be cancelled to make the venue available for others again. No access code will be issued for a cancelled booking.");
 foreach(array('warning'=>$warning,'cancel'=>$cancel,'admin'=>$admin)as$name=>$message)file_put_contents(getenv('MBS_EMAIL_PREVIEW_DIR').'/expiry-'.$name.'.html',$message['body']);
}
echo "EXPIRY_NOTIFICATION_ROUTING_OK: {$checks} checks; no email sent.\n";
