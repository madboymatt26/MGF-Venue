<?php
// Transport-level fixture tests. No network, production tokens or bookings are used.
define('ABSPATH',__DIR__); define('MBS_TABLE','mathlin_bookings');
$options=[];$requests=[];$queue=[];$assertions=0;
function get_option($k,$d=[]){global $options;return $options[$k]??$d;}
function update_option($k,$v,$auto=false){global $options;$options[$k]=$v;return true;}
function add_option($k,$v,$unused='',$auto=false){global $options;if(isset($options[$k]))return false;$options[$k]=$v;return true;}
function delete_option($k){global $options;unset($options[$k]);}
function wp_generate_password($n,$s=false){return str_repeat('x',$n);}
function wp_timezone(){return new DateTimeZone('Europe/London');}
function wp_date($f){return '2026-10-18';}
function wp_salt($s){return 'fixture';}
function admin_url($s,$scheme='admin'){return 'https://fixture.test/wp-admin/'.$s;}
function wp_strip_all_tags($s){return strip_tags($s);}
function wp_json_encode($v){return json_encode($v);}
function wp_remote_request($url,$args){global $requests,$queue;$requests[]=[$url,$args];if($queue)return array_shift($queue);if($args['method']==='GET'&&strpos($url,'singleEvents=true')!==false)return ['code'=>200,'body'=>'{"kind":"calendar#events","items":[]}'];$data=json_decode($args['body']??'{}',true);$data['etag']='"created"';return ['code'=>200,'body'=>json_encode($data)];}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
function is_wp_error($r){return false;}
// Minimal PHP distribution has no crypto extension. Mock decryption only for transport tests.
if(!function_exists('openssl_decrypt')){function openssl_decrypt(...$args){return 'fake-fixture-token';}if(!defined('OPENSSL_RAW_DATA'))define('OPENSSL_RAW_DATA',1);}
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-google-calendar.php';
class FixtureDB{public $prefix='wp_';public $last_error='';public $rows=[];public function get_results($sql){return $this->rows;}}
$wpdb=new FixtureDB();
function invoke($name,...$args){$r=new ReflectionMethod(MBS_Google_Calendar::class,$name);return $r->invokeArgs(null,$args);}
function check($v,$msg){global $assertions;$assertions++;if(!$v)throw new RuntimeException($msg);}
function fixture(){return (object)['ref'=>'MBS-F1','status'=>'confirmed','booking_date'=>'2026-10-24','booking_date_end'=>'2026-10-24','all_day'=>0,'start_time'=>'19:00:00','end_time'=>'21:00:00','space'=>'Cabin','organisation'=>'Group','custom_fields'=>'{}'];}
function reset_fixture(){global $wpdb,$options,$requests,$queue;$wpdb->rows=[fixture()];$wpdb->last_error='';$requests=[];$queue=[];$options=['mbs_google_calendar'=>['calendar'=>'target','access'=>base64_encode(str_repeat('x',40)),'refresh'=>'fixture','expires'=>time()+9999]];}
// If real OpenSSL is loaded, use real encryption for fixture access tokens too.
function ready(){reset_fixture();if(function_exists('openssl_encrypt')){$s=get_option('mbs_google_calendar');$s['access']=MBS_Google_Calendar::seal('fake-fixture-token');update_option('mbs_google_calendar',$s);}}
function projection(){global $wpdb;return MBS_Google_Calendar::project($wpdb->rows,false,'site','target',wp_timezone(),'2026-10-18');}
function ops($events=[]){return MBS_Google_Calendar::plan(projection(),$events,'site','2026-10-18');}
// Private execute revalidates the real site identity, so fixture plan uses that identity.
function home_url($x='/'){return 'https://fixture.test/';}
function untrailingslashit($s){return rtrim($s,'/');}
function actual_ops($events=[]){global $wpdb;$site=hash('sha256','https://fixture.test');$p=MBS_Google_Calendar::project($wpdb->rows,false,$site,'target',wp_timezone(),'2026-10-18');return MBS_Google_Calendar::plan($p,$events,$site,'2026-10-18');}
ready();$plan=actual_ops();$r=invoke('execute',$plan);
check($r['completed']===1,'one create');check(count($requests)===2&&$requests[0][1]['method']==='GET','target interval rechecked before insert');
check($requests[1][0]==='https://www.googleapis.com/calendar/v3/calendars/target/events?sendUpdates=none','fixed Google origin and no invitations');
$body=json_decode($requests[1][1]['body'],true);check($body['reminders']['useDefault']===false,'no default reminder email');check(empty($body['attendees']),'no attendees');check($requests[1][1]['redirection']===0,'no credential redirects');check(isset(get_option('mbs_google_calendar')['links'][$plan[0]['key']]),'durable linked ID');
ready();$desired=actual_ops()[0]['want'];$old=$desired;$old['id']='manual-id';$old['etag']='"version1"';$old['summary']='Old title';$old['description']='Estate private notes';$plan=actual_ops([$old]);invoke('execute',$plan);
check(count($requests)===0,'changed own event is not patched');check($plan[0]['action']==='difference','difference needs manual review');
ready();$old=actual_ops()[0]['want'];$old['id']='linked';$old['etag']='"v2"';$wpdb->rows[0]->status='cancelled';invoke('execute',actual_ops([$old]));check(count($requests)===0,'cancel retains Google event');
ready();$old=actual_ops()[0]['want'];unset($old['extendedProperties']);$old['id']='manual';$old['etag']='"v3"';$plan=actual_ops([$old]);$r=invoke('execute',$plan);check($r['needs_review']===1&&count($requests)===0,'unresolved manual overlap does not write');
invoke('execute',$plan,[$plan[0]['hash']=>'adopt:manual']);check(count($requests)===0,'old adoption choice cannot write');
$queue[]=['code'=>200,'body'=>json_encode($old)];invoke('execute',$plan,[$plan[0]['hash']=>'protect:manual'],false);check(count($requests)===1&&$requests[0][1]['method']==='GET','reference match only reads Google');
$link=get_option('mbs_google_calendar')['links'][$plan[0]['key']];check($link['origin']==='estate','estate origin recorded locally');check(!isset($old['extendedProperties']),'no ownership tags added to estate');
$s=get_option('mbs_google_calendar');$p=MBS_Google_Calendar::project($wpdb->rows,false,hash('sha256','https://fixture.test'),'target',wp_timezone(),'2026-10-18');
$linked=MBS_Google_Calendar::plan($p,[$old],hash('sha256','https://fixture.test'),'2026-10-18',$s['links']);check($linked[0]['action']==='matched','protected local reference is idempotent');
$estateChanged=$old;$estateChanged['summary']='Estate revised title';$linked=MBS_Google_Calendar::plan($p,[$estateChanged],hash('sha256','https://fixture.test'),'2026-10-18',$s['links']);check($linked[0]['action']==='difference','estate edits surfaced, not overwritten');
$linked=MBS_Google_Calendar::plan($p,[],hash('sha256','https://fixture.test'),'2026-10-18',$s['links']);check($linked[0]['action']==='blocked','missing protected reference never duplicated');
foreach(['DELETE','PATCH','PUT'] as $method){$before=count($requests);try{invoke('api',$method,'calendars/target/events/manual');check(false,'forbidden transport');}catch(RuntimeException $e){check(count($requests)===$before,'transport blocks '.$method.' even for forged/stale operations');}}
ready();$plan=actual_ops();$result=invoke('execute',$plan,[],false);check(count($requests)===0,'save matches cannot create a missing event');
ready();$plan=actual_ops();$queue[]=['code'=>200,'body'=>json_encode(['kind'=>'calendar#events','items'=>[$plan[0]['want']]])];try{invoke('execute',$plan);check(false,'new estate overlap');}catch(RuntimeException $e){check(count($requests)===1&&$requests[0][1]['method']==='GET','new estate overlap prevents insert');}
foreach(['remove','update'] as $unsafe){ready();$plan=actual_ops();$plan[0]['action']=$unsafe;invoke('execute',$plan);check(count($requests)===0,'stale '.$unsafe.' operation never writes');}
ready();$s=get_option('mbs_google_calendar');$s['enabled']=true;update_option('mbs_google_calendar',$s);(new MBS_Google_Calendar())->tick();check(count($requests)===0&&!MBS_Google_Calendar::status()['enabled'],'old enabled setting cannot bypass protected-policy approval');
ready();$plan=actual_ops();$wpdb->rows[0]->start_time='18:00:00';try{invoke('execute',$plan);check(false,'stale booking');}catch(RuntimeException $e){check(count($requests)===0,'changed source before write fails closed');}
ready();$plan=actual_ops();$queue[]=['code'=>412,'body'=>'{"error":"private remote body"}'];try{invoke('execute',$plan);check(false,'412');}catch(RuntimeException $e){check(strpos($e->getMessage(),'412')!==false&&strpos($e->getMessage(),'private remote body')===false,'HTTP failure redacted and surfaced');}
ready();$wpdb->last_error='failure';try{invoke('bookings');check(false,'database error');}catch(RuntimeException $e){check(count($requests)===0,'DB error blocks writes');}
ready();invoke('lock');try{invoke('lock');check(false,'overlap');}catch(RuntimeException $e){check(true,'atomic lock blocks simultaneous worker');}invoke('unlock');invoke('lock');invoke('unlock');check(true,'lock can be released');
ready();$queue[]=['code'=>200,'body'=>'{"kind":"calendar#events","items":[],"nextPageToken":"again"}'];$queue[]=['code'=>200,'body'=>'{"kind":"calendar#events","items":[],"nextPageToken":"again"}'];try{invoke('listing','calendars/target/events');check(false,'pagination loop');}catch(RuntimeException $e){check(count($requests)===2,'incomplete pagination fails closed');}
ready();$s=MBS_Google_Calendar::status();check(!isset($s['access'])&&!isset($s['refresh'])&&!isset($s['secret']),'status never exposes secrets');
echo "Google Calendar worker: $assertions assertions passed.\n";
