<?php
// Actual policy methods, synthetic identities/resources only. No WP, mail or network.
define('ABSPATH',__DIR__); define('MBS_PLUGIN_DIR',__DIR__.'/../wp-plugin/mathlin-booking/'); define('MBS_VERSION','test'); define('MBS_TABLE','bookings');
class WP_Error { private $message; function __construct($code,$message,$data=array()){$this->message=$message;} function get_error_message(){return $this->message;} }
function is_wp_error($v){return $v instanceof WP_Error;}
$options=array('mbs_github_token'=>'synthetic-token'); $meta=array(); $user=(object)array('ID'=>7,'user_email'=>'owner@example.invalid','roles'=>array('mbs_hirer'));
function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;}
function get_user_meta($id,$k,$single=true){return $GLOBALS['meta'][$id][$k]??'';}
function wp_get_current_user(){return $GLOBALS['user'];}
function current_user_can($cap){return false;}
function wp_salt($scheme){return 'synthetic-salt';}
function is_user_logged_in(){return !empty($GLOBALS['user']->ID);}
function wp_create_nonce($v){return 'synthetic-nonce';}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
function rest_url($path){return 'https://example.invalid/wp-json/'.$path;}
function wp_timezone(){return new DateTimeZone('Europe/London');}
function plugin_basename($v){return $v;}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function wp_remote_request($url,$args){$GLOBALS['http'][]=array($url,$args); return count($GLOBALS['http'])===1?array('code'=>302,'headers'=>array('location'=>'https://release-assets.githubusercontent.com/synthetic')):array('code'=>200,'headers'=>array());}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_header($r,$k){return $r['headers'][$k]??'';}
class WP_Http {static function make_absolute_url($url,$base){return $url;}}
class MBS_Bookings {static function get_spaces(){return array('Meeting Room'=>array('capacity'=>15,'parent'=>'Whole Centre'),'Hall'=>array('capacity'=>100,'parent'=>'Whole Centre'),'Whole Centre'=>array('capacity'=>115),'Bomb Hole'=>array('capacity'=>100,'parent'=>'Whole Park'),'Paddock'=>array('capacity'=>100,'parent'=>'Whole Park'),'Whole Park'=>array('capacity'=>1000));} static function get_related_spaces($v){return array();}}
require MBS_PLUGIN_DIR.'includes/class-campsite-policy.php';
require MBS_PLUGIN_DIR.'includes/class-space-selection.php';
require MBS_PLUGIN_DIR.'includes/class-booking-validation.php';
require MBS_PLUGIN_DIR.'includes/class-hirer-identity.php';
require MBS_PLUGIN_DIR.'includes/class-csv-export.php';
require MBS_PLUGIN_DIR.'includes/class-updater.php';
require MBS_PLUGIN_DIR.'includes/class-local-schedule.php';
$checks=0;
function check($v,$label){global $checks;if(!$v)throw new RuntimeException($label);$checks++;}
$data=array('space'=>'Meeting Room','booking_date'=>'2027-02-01','booking_date_end'=>'2027-02-01','start_time'=>'10:00','end_time'=>'12:00','attendees'=>15);
check(MBS_Booking_Validation::validate($data)===true,'paid valid meeting room');
check(is_wp_error(MBS_Booking_Validation::validate(array_merge($data,array('attendees'=>0)))),'public requests require attendance');
check(MBS_Booking_Validation::validate(array_merge($data,array('attendees'=>0)),true)===true,'staff retain explicit unknown legacy attendance');
check(is_wp_error(MBS_Booking_Validation::validate(array_merge($data,array('attendees'=>16)),true)),'staff unknown policy never bypasses capacity');
$changed=MBS_Booking_Validation::delivery_update((object)$data,array('booking_date'=>'2027-02-02'));
check(isset($changed['access_sent'],$changed['reminder_sent'])&&$changed['access_sent']===0,'schedule change resets delivery eligibility');
check($changed['booking_date_end']==='2027-02-02','one-day move retains one-day duration');
check(!isset(MBS_Booking_Validation::delivery_update((object)array_merge($data,array('booking_date_end'=>'2027-02-03')),array('booking_date'=>'2027-02-02'))['booking_date_end']),'multi-day end remains explicit');
check(!isset(MBS_Booking_Validation::delivery_update((object)$data,array('purpose'=>'Updated description'))['access_sent']),'description-only edit does not resend gate details');
foreach(array(array('attendees'=>16),array('attendees'=>-1),array('attendees'=>'2.5'),array('booking_date'=>'2027-02-30'),array('end_time'=>'25:00'),array('end_time'=>'09:00'),array('booking_date_end'=>'2030-01-01'))as$bad)check(is_wp_error(MBS_Booking_Validation::validate(array_merge($data,$bad))),'invalid/capacity rejected');
check(MBS_Booking_Validation::validate(array_merge($data,array('space'=>'Whole Centre','attendees'=>115)))===true,'whole centre 115');
$options['mbs_campsite_mode']=1;$options['mbs_multi_space_enabled']=1;
check(MBS_Booking_Validation::validate(array_merge($data,array('space'=>'Whole Park','attendees'=>1000)))===true,'whole park 1000');
check(is_wp_error(MBS_Booking_Validation::validate(array_merge($data,array('space'=>'Bomb Hole + Paddock','attendees'=>101)))),'multi-area lowest capacity');
check(MBS_Booking_Validation::validate(array_merge($data,array('space'=>'Paddock','booking_date_end'=>'2027-02-03','booking_span'=>'continuous','start_time'=>'17:00','end_time'=>'10:00')))===true,'continuous overnight retained');
check(!MBS_Hirer_Identity::verified(),'email not verified by role alone');
$meta[7]['mbs_verified_email']=hash('sha256','owner@example.invalid');
check(MBS_Hirer_Identity::verified(),'verified hash matches address');
check(!MBS_Hirer_Identity::owns_email('other@example.invalid'),'other email not owned');
$booking=(object)array('ref'=>'MBS-SYNTHETIC','email'=>'owner@example.invalid','modification_token'=>'synthetic-private-key');
$url=MBS_Hirer_Identity::calendar_url($booking);parse_str(parse_url($url,PHP_URL_QUERY),$args);
$user->ID=0;
check(MBS_Hirer_Identity::calendar_access($booking,$args['calendar_token']),'scoped calendar token works');
check(!MBS_Hirer_Identity::calendar_access($booking,$args['calendar_token'].'x'),'altered token fails');
$booking->modification_token='rotated';
check(!MBS_Hirer_Identity::calendar_access($booking,$args['calendar_token']),'rotation revokes scoped token');
$user->ID=7;$user->user_email='changed@example.invalid';check(!MBS_Hirer_Identity::verified(),'email change revokes verification');
foreach(array('=1+1',"\t=1+1",'+SUM(A1:A2)','@SUM(A1)','-SUM(A1)')as$cell)check(substr(MBS_CSV_Export::safe_fields(array($cell))[0],0,1)==="'",'formula neutralized');
check(MBS_CSV_Export::safe_fields(array('25.00','-25.00',25))[0]==='25.00','numeric amounts retained');
$updater=new MBS_Updater();
foreach(array('https://attacker.invalid/github.com/madboymatt26/MGF-Venue','http://github.com/madboymatt26/MGF-Venue','https://github.com.evil.invalid/madboymatt26/MGF-Venue','https://x@github.com/madboymatt26/MGF-Venue','https://github.com/madboymatt26/MGF-Venue-other')as$url)check(empty($updater->add_auth_header(array('headers'=>array()),$url)['headers']['Authorization']),'wrong origin/path denied');
$http=array();$result=$updater->follow_authenticated_download(false,array('method'=>'GET','headers'=>array()),'https://github.com/madboymatt26/MGF-Venue/releases/download/v1/test.zip');
check($result['code']===200&&count($http)===2,'updater download redirects still work');
check(!empty($http[0][1]['headers']['Authorization'])&&empty($http[1][1]['headers']['Authorization']),'download origin change strips credential');
$options['mbs_github_token']='';$http=array();$updater=new MBS_Updater();
$result=$updater->follow_authenticated_download(false,array('method'=>'GET','headers'=>array()),'https://github.com/madboymatt26/MGF-Venue/releases/download/v1/test.zip');
check($result['code']===200&&count($http)===2&&empty($http[0][1]['headers']['Authorization']),'public updates without a GitHub token still follow approved redirects');
$before=new DateTimeImmutable('2026-10-24 08:00',wp_timezone());$next=MBS_Local_Schedule::next_timestamp('07:00',$before);
check((new DateTimeImmutable('@'.$next))->setTimezone(wp_timezone())->format('Y-m-d H:i')==='2026-10-25 07:00','local scheduling crosses DST correctly');
echo 'SECURITY_HARDENING_OK ('.$checks.' actual policy checks)'.PHP_EOL;
