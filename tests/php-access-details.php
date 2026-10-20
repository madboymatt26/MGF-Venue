<?php
define('ABSPATH', __DIR__);
define('MBS_TABLE', 'mathlin_bookings');
$options=['mbs_payments_enabled'=>0,'mbs_access_enabled'=>1,'mbs_access_code'=>'SYNTHETIC-CODE','mbs_access_hours_before'=>24];
function get_option($k,$d=false){global $options;return $options[$k]??$d;}
function update_option($k,$v,$autoload=false){global $options;$options[$k]=$v;}
function wp_timezone(){return new DateTimeZone('Europe/London');}
function wp_date($f,$t=null){return (new DateTimeImmutable('@'.($t??time())))->setTimezone(wp_timezone())->format($f);}
function wp_json_encode($v){return json_encode($v);}
function sanitize_text_field($s){return trim(strip_tags($s));}
function sanitize_textarea_field($s){return trim(strip_tags($s));}
function wp_generate_uuid4(){return '938b201a-e2c3-43e9-a515-68731142a3d6';}
function add_action(...$args){}
function wp_get_scheduled_event($hook){return $GLOBALS['access_event']??false;}
function wp_clear_scheduled_hook($hook){$GLOBALS['access_cleared']=$hook;}
function wp_schedule_event($time,$schedule,$hook){$GLOBALS['access_scheduled']=compact('time','schedule','hook');}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_url($s){return esc_html($s);}
function wp_kses_post($s){return $s;}
class WP_Error {private $code;private $message;function __construct($c,$m){$this->code=$c;$this->message=$m;}function get_error_code(){return $this->code;}function get_error_message(){return $this->message;}}
function is_wp_error($v){return $v instanceof WP_Error;}
class MBS_Email_Templates {static function get_template($t){return ['subject'=>'Access test','body'=>'Synthetic instructions'];}static function replace_placeholders($s,$b){return $s;}static function get_org_settings(){return ['name'=>'Synthetic venue'];}static function get_logo_html(){return '';}}
class MBS_Email_Queue {
    static $rows=[];
    static function compute_payload_hash(...$v){return hash('sha256',serialize($v));}
    static function enqueue($to,$subject,$body,$headers,$key,$hash,$attachment,$context){self::$rows[$key]=compact('to','subject','body','headers','key','hash','context');return true;}
}
class MBS_Audit_Log {static function log(...$args){}}
class AccessDb {public $prefix='test_';public $rows=[];public $updates=0;function prepare($sql,...$args){return $sql;}function get_results($sql){return $this->rows;}function update(...$args){$this->updates++;}function get_row($sql){return $this->rows[0]??null;}}
$wpdb=new AccessDb();
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-campsite-policy.php';
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-payment-features.php';
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-bookings.php';
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-access-details.php';
$checks=0;
function expect_access($v,$m){global $checks;$checks++;if(!$v)throw new RuntimeException($m);}
$b=(object)['id'=>1,'ref'=>'ACCESS-SYNTHETIC','status'=>'confirmed','amount'=>0,'pricing_tier'=>'trusted','booking_date'=>'2100-01-02','booking_date_end'=>'2100-01-02','start_time'=>'18:00:00','end_time'=>'21:00:00','all_day'=>0,'space'=>'Cabin','email'=>'test@example.invalid'];
$options['mbs_pricing_tiers']=['trusted'=>['bypass_access_gate'=>true]];
foreach([0,1] as $payments){$options['mbs_payments_enabled']=$payments;foreach(['pending','confirmed','deposit_paid','paid','cancelled','archived'] as $status){$b->status=$status;$want=$payments?$status==='paid':in_array($status,['confirmed','deposit_paid','paid'],true);expect_access(MBS_Access_Details::is_eligible_for_access($b)===$want,"Payment/status matrix $payments/$status");}}
unset($options['mbs_payments_enabled']);$options['mbs_campsite_mode']=1;$b->status='confirmed';expect_access(MBS_Access_Details::is_eligible_for_access($b),'Campsite default payments off');
$options['mbs_campsite_mode']=0;expect_access(!MBS_Access_Details::is_eligible_for_access($b),'Ordinary venue defaults paid gate');$options['mbs_payments_enabled']=0;
$options['mbs_campsite_mode']=1;
foreach(['Estate','HP Business',' hp BUSINESS '] as $group){$b->organisation=$group;foreach([0,1] as $payments){$options['mbs_payments_enabled']=$payments;$b->status='paid';expect_access(!MBS_Access_Details::is_eligible_for_access($b),'Estate/HP Business excluded even if paid');}}
$b->organisation='Custom Estate Contact';$b->notes="Estate reservation entered by staff.\nSynthetic booking";expect_access(!MBS_Access_Details::is_eligible_for_access($b),'Staff estate entry excluded with custom organisation');
$b->notes='';$b->organisation='Estate Scouts';$b->status='confirmed';$options['mbs_payments_enabled']=0;expect_access(MBS_Access_Details::is_eligible_for_access($b),'No substring-based estate guess');$b->organisation='Synthetic Scout Group';
foreach(['2027-03-28','2027-10-31'] as $date){$b->booking_date=$date;$start=new DateTimeImmutable($date.' 18:00',wp_timezone());$due=$start->modify('-1 day')->setTime(8,0);expect_access(!MBS_Access_Details::is_due($b,$due->modify('-1 second')),'Not early across DST');expect_access(MBS_Access_Details::is_due($b,$due),'08:00 previous local day across DST');expect_access(!MBS_Access_Details::is_due($b,$start),'Never after start');}
$b->booking_date='2100-01-02';$b->all_day=1;expect_access(MBS_Access_Details::is_due($b,new DateTimeImmutable('2100-01-01 08:00',wp_timezone())),'All-day previous morning');
$b->all_day=0;$options['mbs_access_hours_before']=48;expect_access(MBS_Access_Details::is_due($b,new DateTimeImmutable('2099-12-31 18:00',wp_timezone())),'Alternative hours window');$options['mbs_access_hours_before']=24;
expect_access(MBS_Access_Details::queue($b)===true,'Queue accepted');MBS_Access_Details::queue($b);expect_access(count(MBS_Email_Queue::$rows)===1,'Repeated checks share key');$row=current(MBS_Email_Queue::$rows);expect_access(strpos(serialize($row),'SYNTHETIC-CODE')===false,'Code not stored in queued body');
$email=(object)['reference_id'=>1,'message_key'=>$row['key'],'message_type'=>'access_manual'];$wpdb->rows=[$b];
expect_access(strlen($row['key'])<=100,'Automatic key fits existing database column');
MBS_Access_Details::resend($b);$manual_row=end(MBS_Email_Queue::$rows);expect_access(strlen($manual_row['key'])<=100&&$manual_row['key']!==$row['key'],'Manual key fits existing column and differs from automatic');
$msg=MBS_Access_Details::queued_message($email);expect_access(!is_wp_error($msg)&&strpos($msg['body'],'SYNTHETIC-CODE')!==false,'Current code rendered at dispatch');
$options['mbs_access_code']='ROTATED-FAKE';$msg=MBS_Access_Details::queued_message($email);expect_access(strpos($msg['body'],'ROTATED-FAKE')!==false,'Rotated code used at retry');
$b->email='changed@example.invalid';expect_access(MBS_Access_Details::queued_message($email)->get_error_code()==='access_stale','Changed recipient blocks stale queue');$b->email='test@example.invalid';
$options['mbs_payments_enabled']=1;expect_access(is_wp_error(MBS_Access_Details::queued_message($email)),'Payments enabled after enqueue blocks unpaid');$options['mbs_payments_enabled']=0;
$b->status='cancelled';expect_access(MBS_Access_Details::queued_message($email)->get_error_code()==='access_inactive','Cancellation blocks queued delivery');expect_access(is_wp_error(MBS_Access_Details::resend($b)),'Manual send cannot bypass approval');$b->status='confirmed';
$email->message_type='access_details';expect_access(MBS_Access_Details::queued_message($email)->get_error_code()==='access_not_due','Automatic queued email cannot send early');
$before=$options;expect_access(is_wp_error(MBS_Access_Details::save_settings(['hours_before'=>999]))&&$before===$options,'Invalid patch atomic');
$result=MBS_Access_Details::save_settings(['enabled'=>true,'hours_before'=>24]);expect_access(!is_wp_error($result)&&$options['mbs_access_code']==='ROTATED-FAKE','Partial settings preserve secret');expect_access(strpos(serialize($result),'ROTATED-FAKE')===false,'Settings response never returns code');
expect_access(is_wp_error(MBS_Access_Details::save_settings(['enabled'=>true,'code'=>''])),'Cannot enable blank code');
expect_access($wpdb->updates===0,'No sent flags before transport acceptance');
$GLOBALS['access_event']=(object)['schedule'=>'daily'];(new MBS_Access_Details())->init();expect_access($GLOBALS['access_cleared']==='mbs_daily_access_details'&&$GLOBALS['access_scheduled']['schedule']==='hourly','Old daily UTC schedule replaced by hourly local due checks');
$GLOBALS['access_event']=(object)['schedule'=>'hourly'];unset($GLOBALS['access_scheduled']);(new MBS_Access_Details())->init();expect_access(!isset($GLOBALS['access_scheduled']),'Existing hourly event preserved');
echo "Access details: $checks checks passed.\n";
