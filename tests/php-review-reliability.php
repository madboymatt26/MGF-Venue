<?php
/** Executes production calendar/read-model/HA methods with isolated adapters. */
define( 'ABSPATH', __DIR__ ); define( 'MBS_TABLE', 'bookings' );
date_default_timezone_set( 'UTC' );
$options = array(); $http = 0; $http_status = 500; $checks = 0;
function check( $ok, $message ) { global $checks; $checks++; if ( ! $ok ) throw new RuntimeException( $message ); }
function wp_timezone() { return new DateTimeZone( 'Europe/London' ); }
function wp_date( $format ) { return ( new DateTimeImmutable( '2027-07-10', wp_timezone() ) )->format( $format ); }
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['options'][$key] = $value; return true; }
function wp_generate_uuid4() { static $id=0; return 'test-uuid-' . ++$id; }
function wp_next_scheduled( $hook ) { return false; }
function wp_schedule_single_event( $time, $hook ) {}
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_remote_post( $url, $args ) { $GLOBALS['http']++; if ( isset( $GLOBALS['during_http'] ) ) { $callback=$GLOBALS['during_http']; unset($GLOBALS['during_http']); $callback(); } return array( 'response'=>array('code'=>$GLOBALS['http_status']) ); }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function is_wp_error( $v ) { return false; }
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function home_url() { return 'https://example.test'; }
function get_bloginfo( $key ) { return 'Fixture venue'; }
function wp_salt( $context ) { return 'fixture-salt'; }
function maybe_serialize( $value ) { return serialize( $value ); }
function maybe_unserialize( $value ) { return unserialize( $value ); }
function wp_cache_delete( $key, $group ) {}
class MBS_Email_Templates { public static function get_org_settings() { return array('name'=>'Fixture','address'=>'Fixture address'); } }
class MBS_Campsite_Policy { public static $enabled=false; public static function enabled(){return self::$enabled;} public static function span($b){return $b->span??'daily';} }
class MBS_Audit_Log { public static function log(...$args){} }
class MBS_Bookings {
    public static $rows=array();
    public static function get_all($args){ $rows=array_values(array_filter(self::$rows,static function($b)use($args){return $b->status===$args['status'] && ($b->booking_date_end?:$b->booking_date)>='2027-07-10' && (!$args['date_to']||$b->booking_date<=$args['date_to']);}));return array_slice($rows,$args['offset'],$args['limit']); }
    public static function get($ref){foreach(self::$rows as$b)if($b->ref===$ref)return$b;return null;}
    public static function get_by_date($date){return self::$rows;}
}
class FixtureDB {
    public $prefix='fixture_';public $options='options';public $prepared=array();public $updates=0;
    public function prepare($sql,...$args){$key='query-'.count($this->prepared);$this->prepared[$key]=array($sql,$args);return$key;}
    public function get_var($q){return 1;}
    public function esc_like($v){return$v;}
    public function get_results($q){$rows=array();foreach($GLOBALS['options']as$key=>$value)if(strpos($key,'mbs_ha_event_')===0)$rows[]=(object)array('option_id'=>count($rows)+1,'option_name'=>$key,'option_value'=>serialize($value));return$rows;}
    public function query($q){list($sql,$args)=$this->prepared[$q];if(strpos($sql,'DELETE')===0){list($key,$expected)=$args;if(serialize(get_option($key))!==$expected)return 0;unset($GLOBALS['options'][$key]);return 1;}list($value,$key,$expected)=$args;if(serialize(get_option($key))!==$expected)return 0;$GLOBALS['options'][$key]=unserialize($value);return 1;}
    public function update(...$args){$this->updates++;return 1;}
}
$wpdb=new FixtureDB();
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-ical.php';
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-homeassistant.php';
$b=(object)array('id'=>1,'ref'=>'FIXTURE','space'=>'Hall','booking_date'=>'2027-07-10','booking_date_end'=>'2027-07-11','start_time'=>'19:00:00','end_time'=>'21:00:00','all_day'=>0,'attendees'=>2,'purpose'=>'Private purpose','notes'=>'Secret notes','amount'=>10,'kitchen'=>0,'scout_use'=>0,'status'=>'paid','is_public'=>0);
$ics=MBS_ICal::generate($b);
check(strpos($ics,'DTSTART:20270710T180000Z')!==false,'summer local time converted to UTC');
check(strpos($ics,'DTEND:20270711T200000Z')!==false,'daily sessions include final day');
check(substr_count($ics,'BEGIN:VEVENT')===2,'daily range emits two events');
check(strpos($ics,'UID:FIXTURE@example.test')!==false,'first venue UID preserved');
$night=clone$b;$night->start_time='22:00:00';$night->end_time='06:00:00';
$night_intervals=MBS_Booking_Intervals::intervals($night);
check(count($night_intervals)===1&&$night_intervals[0]['end']->format('Y-m-d H:i')==='2027-07-11 06:00','two-date overnight is one stay');
$night->booking_date_end=$night->booking_date;
check(MBS_Booking_Intervals::intervals($night)[0]['end']->format('Y-m-d H:i')==='2027-07-11 06:00','single-date overnight ends next morning');
$b->status='cancelled';check(strpos(MBS_ICal::generate($b),'STATUS:CANCELLED')!==false,'cancelled ICS is not confirmed');$b->status='paid';
MBS_Campsite_Policy::$enabled=true;$b->span='continuous';
check(substr_count(MBS_ICal::generate($b),'BEGIN:VEVENT')===1,'continuous campsite span stays one event');
check(strpos(MBS_ICal::generate($b),'UID:FIXTURE-20270710@example.test')!==false,'campsite UID preserved');
MBS_Campsite_Policy::$enabled=false;$b->span='daily';
MBS_Bookings::$rows=array();for($i=0;$i<205;$i++){$copy=clone$b;$copy->ref='FIXTURE-'.$i;MBS_Bookings::$rows[]=$copy;}
$ongoing=clone$b;$ongoing->ref='ONGOING';$ongoing->booking_date='2027-07-09';$ongoing->status='deposit_paid';MBS_Bookings::$rows[]=$ongoing;
$feed=str_replace("\r\n ",'',MBS_ICal::generate_feed());
check(substr_count($feed,'BEGIN:VEVENT')===413,'all pages, deposit-paid and ongoing stay exported');
check(strpos($feed,'Private purpose')===false&&strpos($feed,'Secret notes')===false,'public feed remains redacted');
check(strpos($feed,'FIXTURE-0@')===false,'public identifiers remain unusable as booking credentials');
check(count(MBS_HomeAssistant::get_upcoming_for_ha(366))===206,'HA includes every approved status and page');
$pending=clone$b;$pending->status='pending';MBS_Bookings::$rows[]=$pending;
check(count(MBS_HomeAssistant::get_todays_bookings())===206,'HA today excludes pending');
MBS_Bookings::$rows=array($b);update_option('mbs_ha_webhook_url','https://ha.example.test/hook');
check(MBS_HomeAssistant::notify($b)===true&&$http===0,'writer persists intent without network I/O');
MBS_HomeAssistant::process();$key='mbs_ha_event_'.hash('sha256',$b->ref);
check(get_option($key)['attempts']===1&&$wpdb->updates===0,'HTTP500 retries without sent flag');
$intent=get_option($key);$intent['next']=0;update_option($key,$intent);$http_status=200;
$GLOBALS['during_http']=static function()use($b){MBS_HomeAssistant::notify($b);};
MBS_HomeAssistant::process();check(get_option($key)!==false&&$wpdb->updates===0,'concurrent replacement intent is not lost or marked accepted');
MBS_HomeAssistant::process();check(get_option($key)===false&&$wpdb->updates===1,'HTTP200 marks only exact delivered intent');
echo "PASS {$checks} review reliability assertions\n";
