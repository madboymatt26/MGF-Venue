<?php
define( 'ABSPATH', __DIR__ );
$options = array(); $logged_in = false; $caps = array(); $roles = array(); $account_status = '';
class WP_Error { public $code; public $message; function __construct($c,$m){$this->code=$c;$this->message=$m;} function get_error_code(){return $this->code;} function get_error_message(){return $this->message;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function get_option($k,$d=false){global $options;return $options[$k]??$d;}
function update_option($k,$v){global $options;$options[$k]=$v;}
function is_user_logged_in(){global $logged_in;return $logged_in;}
function current_user_can($c){global $caps;return in_array($c,$caps,true);}
function wp_get_current_user(){global $roles;return (object)array('ID'=>7,'roles'=>$roles);}
function get_user_meta($id,$key,$single){global $account_status;return $account_status;}
function wp_roles(){return (object)array('roles'=>array('approved_scout'=>array('name'=>'Approved Scout'),'subscriber'=>array('name'=>'Subscriber')));}
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower($v));}
function wp_json_encode($v){return json_encode($v);}
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-campsite-policy.php';
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-hirer-identity.php';
$checks=0;
function expect($condition,$message){global $checks;$checks++;if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
expect(MBS_Campsite_Policy::can_book(),'Disabled policy preserves public venue flow');
$options['mbs_campsite_mode']=1;
expect(!MBS_Campsite_Policy::can_book(),'Guest rejected');
expect(is_wp_error(MBS_Campsite_Policy::validate(array('email'=>'approved@example.invalid'))),'Posted email grants no access');
$logged_in=true; $roles=array('subscriber');
expect(!MBS_Campsite_Policy::can_book(),'Logged-in subscriber not automatically eligible');
$options['mbs_campsite_roles']=array('approved_scout'); $roles=array('approved_scout');
expect(MBS_Campsite_Policy::can_book(),'Configured approved role allowed');
$account_status='awaiting_admin_review';
expect(!MBS_Campsite_Policy::can_book(),'Ultimate Member pending user rejected');
$account_status='rejected';expect(!MBS_Campsite_Policy::can_book(),'Rejected user denied');
$account_status='approved';expect(MBS_Campsite_Policy::can_book(),'Approved user allowed');
$caps=array('mbs_manage_bookings');$roles=array();expect(MBS_Campsite_Policy::can_book(),'Booking managers allowed without WordPress admin');
expect(is_wp_error(MBS_Campsite_Policy::validate(array('kitchen'=>1),true)),'Trusted admin cannot silently add kitchen charge');
expect(is_wp_error(MBS_Campsite_Policy::validate(array('custom_amount'=>25),true)),'Trusted admin cannot silently charge campsite hire');
$caps=array();$logged_in=false;
expect(MBS_Campsite_Policy::validate(array(),true)===true,'Explicit trusted backend may create on behalf of contact');
$before=$options;
expect(is_wp_error(MBS_Campsite_Policy::save_settings(array('campsite_roles'=>array('invented')))),'Unknown role rejected');
expect($before===$options,'Invalid settings patch makes no writes');
expect(is_wp_error(MBS_Campsite_Policy::save_settings(array('campsite_mode'=>1,'kitchen_enabled'=>1))),'Incompatible kitchen rejected');
expect(MBS_Campsite_Policy::save_settings(array('campsite_mode'=>1,'campsite_roles'=>'approved_scout','kitchen_enabled'=>0))===true,'Valid policy settings accepted');
expect($options['mbs_campsite_roles']===array('approved_scout'),'Roles stored precisely');
expect(MBS_Campsite_Policy::save_settings(array('kitchen_enabled'=>0))===true && MBS_Campsite_Policy::enabled(),'Omitted policy settings preserve mode');
echo "OK: $checks campsite policy checks\n";

// Exercise actual booking creation, not just source-text expectations.
define('MBS_TABLE','mathlin_bookings'); define('DAY_IN_SECONDS',86400);
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_email($v){return (string)$v;}
function sanitize_textarea_field($v){return trim((string)$v);}
function absint($v){return abs((int)$v);}
function get_current_user_id(){return 7;}
function wp_generate_password($n,$special){return str_repeat('x',$n);}
function wp_date($format,$timestamp=null){return date($format,$timestamp??strtotime('2026-10-18'));}
class Campsite_Test_DB {
    public $prefix='wp_'; public $insert_id=1; public $inserts=0;
    public $values=array(); public $bookings=array();
    function prepare($q,...$args){$this->values=count($args)===1 && is_array($args[0])?$args[0]:$args;return $q;}
    function get_var($q){return null;}
    function get_results($q){
        if (strpos($q,'SELECT * FROM')===false) return array();
        $space=$this->values[0]??'';
        return array_values(array_filter($this->bookings,static function($b)use($space){return $b->space===$space && !in_array($b->status,array('cancelled','archived'),true);}));
    }
    function query($q){return 1;}
    function insert($t,$d){$this->inserts++;return 1;}
}
class MBS_Blocked_Dates { static function is_blocked($d,$s){return false;} }
class MBS_Audit_Log { static function log(...$args){} }
$wpdb=new Campsite_Test_DB();
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-bookings.php';
$options['mbs_spaces']=array('Cabin'=>array('rate_hourly'=>25,'rate_daily'=>150,'parent'=>null));
$booking=array('name'=>'Test','email'=>'contact@example.invalid','space'=>'Cabin','booking_date'=>'2026-11-07','start_time'=>'09:00','end_time'=>'12:00','attendees'=>5,'purpose'=>'Test');
$logged_in=true; $roles=array('approved_scout');$account_status='approved';
$created=MBS_Bookings::create($booking);
expect(!is_wp_error($created) && $created['amount']===0,'Actual campsite creation has zero hire cost despite standard rate');
expect(MBS_Bookings::calculate_cost('Cabin','09:00','12:00')===0,'Amendment pricing also honours the site free-use policy');
expect($created['invoice_number']==='' && $created['status']==='pending','No invoice and no automatic approval');
expect($created['phone']==='' && $created['address']==='','Optional contact fields may be omitted');
expect($created['scout_use']===0,'Campsite bookings stay in normal booking list, not hidden Scout-night list');
$logged_in=false; $count=$wpdb->inserts;
expect(is_wp_error(MBS_Bookings::create($booking)) && $wpdb->inserts===$count,'Guest cannot reach database creation');
$options['mbs_campsite_mode']=0;
$created=MBS_Bookings::create($booking);
expect($created['amount']===75.0 && $created['invoice_number']!=='','Standard venue price and invoice unchanged by disabled mode');
echo "OK: $checks total campsite and booking-domain checks\n";

$options['mbs_spaces']=array('Whole Park'=>array('capacity'=>1000,'parent'=>null));
foreach(array('Bomb Hole','Paddock','Top Campfire Circle','Plantation','Survival Area','Cabin') as $area){$options['mbs_spaces'][$area]=array('capacity'=>100,'parent'=>'Whole Park');}
expect(count(MBS_Bookings::get_related_spaces('Whole Park'))===6,'Whole Park checks every child area');
expect(MBS_Bookings::get_related_spaces('Cabin')===array('Whole Park'),'A child only adds its parent, not sibling areas');
$wpdb->bookings=array((object)array('ref'=>'TEST-PENDING','space'=>'Cabin','status'=>'pending','all_day'=>1));
expect(count(MBS_Bookings::check_conflicts('Whole Park','2026-11-07',null,null,true))===1,'Pending area request blocks Whole Park');
expect(count(MBS_Bookings::check_conflicts('Paddock','2026-11-07',null,null,true))===0,'Area request does not block siblings');
$wpdb->bookings[0]->space='Whole Park';$wpdb->bookings[0]->status='confirmed';
foreach(array('Bomb Hole','Paddock','Top Campfire Circle','Plantation','Survival Area','Cabin') as $area){expect(count(MBS_Bookings::check_conflicts($area,'2026-11-07',null,null,true))===1,'Whole Park blocks '.$area);}
$wpdb->bookings[0]->status='cancelled';
expect(count(MBS_Bookings::check_conflicts('Cabin','2026-11-07',null,null,true))===0,'Cancelled parent no longer blocks a child');
echo "OK: $checks total checks including existing Whole Park dependency behaviour\n";

$options['mbs_campsite_mode']=1;$options['mbs_min_notice_days']=14;
$slot=array('booking_date'=>'2026-11-02','start_time'=>'19:00','end_time'=>'22:00','booking_span'=>'daily');
expect(MBS_Campsite_Policy::validate_schedule($slot)===true,'Allowed weekday evening slot');
$slot['start_time']='09:00';$slot['end_time']='12:00';
expect(is_wp_error(MBS_Campsite_Policy::validate_schedule($slot)),'Weekday morning refused');
$slot['campsite_exception']=1;
expect(is_wp_error(MBS_Campsite_Policy::validate_schedule($slot,false)),'Public cannot self-authorise an exception');
expect(MBS_Campsite_Policy::validate_schedule($slot,true)===true,'Trusted manager can record an agreed exception');
$slot=array('booking_date'=>'2026-11-06','booking_date_end'=>'2026-11-09','start_time'=>'17:00','end_time'=>'10:00','booking_span'=>'continuous');
expect(MBS_Campsite_Policy::validate_schedule($slot)===true,'Friday-to-Monday continuous camp allowed without treating Monday morning as a new arrival');
expect(MBS_Campsite_Policy::request_interval($slot,'2026-11-06')===array('17:00','24:00'),'Arrival-day interval clipped');
expect(MBS_Campsite_Policy::request_interval($slot,'2026-11-07')===array('00:00','24:00'),'Middle-day interval covers full day');
expect(MBS_Campsite_Policy::request_interval($slot,'2026-11-09')===array('00:00','10:00'),'Departure-day interval clipped');
$slot['booking_date']='2026-02-30';expect(is_wp_error(MBS_Campsite_Policy::validate_schedule($slot)),'Impossible calendar date rejected');
$slot['booking_date']='2026-10-19';$slot['booking_date_end']='2026-10-20';expect(is_wp_error(MBS_Campsite_Policy::validate_schedule($slot)),'Advance notice enforced in the booking domain');
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-campsite-migration.php';
$legacy=MBS_Campsite_Migration::normalise_dates(array(array('booking_date'=>'2027-04-03 00:01:01','approved'=>'1'),array('booking_date'=>'2027-04-03 23:59:02','approved'=>'1'),array('booking_date'=>'2027-04-04 00:01:01','approved'=>'1'),array('booking_date'=>'2027-04-04 23:59:02','approved'=>'1')));
expect(count($legacy)===2&&$legacy[0]['all_day']&&$legacy[1]['all_day'],'Legacy selected dates remain two independent day sessions');
$legacy=MBS_Campsite_Migration::normalise_dates(array(array('booking_date'=>'2026-11-07 12:00:01','approved'=>'0'),array('booking_date'=>'2026-11-07 14:49:02','approved'=>'0')));
expect($legacy[0]['end']==='14:49:02'&&$legacy[0]['status']==='pending','Original anomalous end time and pending state preserved exactly');
expect(is_wp_error(MBS_Campsite_Migration::normalise_dates(array(array('booking_date'=>'2026-11-07 12:00:01','approved'=>'0'),array('booking_date'=>'2026-11-07 14:49:02','approved'=>'1')))),'Mixed endpoint approvals fail closed');
expect(is_wp_error(MBS_Campsite_Migration::normalise_dates(array(array('booking_date'=>'2026-11-07 19:00:01','approved'=>'0')))),'Ambiguous lone timed endpoint fails closed');
echo "OK: $checks total policy, scheduling, dependency and legacy-normalisation checks\n";

class MBS_Email_Templates { static function get_org_settings(){return array('address'=>'Haughley Park');} }
function wp_timezone(){return new DateTimeZone('Europe/London');}
function wp_parse_url($url,$component){return parse_url($url,$component);}
function home_url(){return 'https://example.invalid';}
function get_bloginfo($key){return 'Test campsite';}
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-ical.php';
$ical_booking=(object)array('ref'=>'TEST-ICS','booking_date'=>'2027-03-26','booking_date_end'=>'2027-03-29','start_time'=>'17:00:00','end_time'=>'10:00:00','all_day'=>0,'space'=>'Cabin','purpose'=>'DST camp','status'=>'confirmed','custom_fields'=>'{"_mbs_campsite_span":"continuous"}');
$ical=MBS_ICal::generate($ical_booking);
expect(strpos($ical,'DTSTART:20270326T170000Z')!==false&&strpos($ical,'DTEND:20270329T090000Z')!==false,'Continuous ICS uses GMT arrival and BST departure across clocks change');
expect(substr_count($ical,'BEGIN:VEVENT')===1,'Continuous camp exports as one event');
$ical_booking->booking_date='2027-04-03';$ical_booking->booking_date_end='2027-04-04';$ical_booking->start_time='09:00';$ical_booking->end_time='12:00';$ical_booking->custom_fields='{"_mbs_campsite_span":"daily"}';
$ical=MBS_ICal::generate($ical_booking);
expect(substr_count($ical,'BEGIN:VEVENT')===2&&strpos($ical,'DTSTART:20270403T080000Z')!==false,'Daily selected sessions export separately at correct BST times');
echo "OK: $checks total checks including calendar DST exports\n";
