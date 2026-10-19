<?php
define('ABSPATH', __DIR__);
function wp_timezone(){return new DateTimeZone('Europe/London');}
function wp_strip_all_tags($s){return strip_tags($s);}
function wp_json_encode($a){return json_encode($a);}
function wp_salt($s){return 'test-only-salt';}
if(!function_exists('mb_substr')){function mb_substr($s,$a,$b){return substr($s,$a,$b);}}
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-google-calendar.php';
$count=0;
function ok($v,$label){global $count;$count++;if(!$v)throw new RuntimeException($label);}
function booking($over=[]){return (object)array_merge(['ref'=>'MBS-T1','status'=>'confirmed','booking_date'=>'2026-10-24','booking_date_end'=>'2026-10-24','all_day'=>0,'start_time'=>'19:00:01','end_time'=>'21:59:02','space'=>'Cabin','organisation'=>'Scouts','name'=>'DO NOT EXPORT NAME','email'=>'private@example.test','phone'=>'SECRET PHONE','purpose'=>'PRIVATE PURPOSE','notes'=>'PRIVATE NOTES','custom_fields'=>'{}'],$over);}
function projection($rows,$pending=false){return MBS_Google_Calendar::project($rows,$pending,'site','calendar',wp_timezone(),'2026-10-18');}
function plan($p,$e=[]){return MBS_Google_Calendar::plan($p,$e,'site','2026-10-18');}
$p=projection([booking()]);$key=array_key_first($p['desired']);$event=$p['desired'][$key];$event['etag']='"v1"';
ok(count($p['desired'])===1,'one occurrence');
ok($event['start']['dateTime']==='2026-10-24T19:00:01+01:00','BST exact seconds');
ok($event['end']['dateTime']==='2026-10-24T21:59:02+01:00','end seconds');
ok((bool)preg_match('/^[0-9a-v]{5,1024}$/',$event['id']),'Google event ID alphabet');
ok(strpos(json_encode($p['desired']),'PRIVATE')===false && strpos(json_encode($p['desired']),'private@example')===false && strpos(json_encode($p['desired']),'SECRET PHONE')===false && strpos(json_encode($p['desired']),'DO NOT EXPORT')===false,'minimal privacy-safe fields');
ok(plan($p)[0]['action']==='create','create');
ok(plan($p,[$event])[0]['action']==='matched','idempotent linked event');
$utc=$event;$utc['start']=['dateTime'=>'2026-10-24T18:00:01Z'];$utc['end']=['dateTime'=>'2026-10-24T20:59:02Z'];
ok(plan($p,[$utc])[0]['action']==='matched','equivalent UTC endpoints do not churn');
$changed=$event;$changed['summary']='Changed';ok(plan($p,[$changed])[0]['action']==='difference','never overwrite changed estate event');
$manual=$event;unset($manual['extendedProperties']);$manual['id']='manual';
ok(plan($p,[$manual])[0]['action']==='review','manual overlap blocked');
$unrelated=$manual;$unrelated['start']=['date'=>'2027-01-01'];$unrelated['end']=['date'=>'2027-01-02'];
ok(plan($p,[$unrelated])[0]['action']==='create','unrelated event retained');
ok(plan(projection([]),[$unrelated])===[],'never delete unmanaged events');
ok(plan(projection([booking(['status'=>'cancelled'])]),[$event])[0]['action']==='retained','cancel never removes Google event');
ok(plan(projection([booking(['status'=>'archived'])]),[$event])[0]['action']==='retained','archive never removes Google event');
ok(plan(projection([]),[$event])[0]['action']==='retained','missing source retained for review');
$guest=$event;$guest['attendees']=[['email'=>'guest@example.test']];
ok(plan($p,[$guest])[0]['action']==='blocked','guest event not changed');
ok(plan(projection([booking(['status'=>'cancelled'])]),[$guest])[0]['action']==='retained','guest event not deleted');
$repeat=$event;$repeat['recurringEventId']='repeat';ok(plan($p,[$repeat])[0]['action']==='blocked','recurring linked event not changed');
ok(plan($p,[$event,$event])[0]['action']==='blocked','duplicate linked events blocked');
$other=$event;$other['extendedProperties']['private']['mgf_site']='another-site';
ok(plan(projection([]),[$other])===[],'another site events untouched');
ok(count(projection([booking(['status'=>'pending'])])['desired'])===0,'pending off');
$pending=projection([booking(['status'=>'pending'])],true);$pe=reset($pending['desired']);
ok($pe['status']==='tentative' && strpos($pe['summary'],'Provisional')===0,'pending labelled');
$days=projection([booking(['booking_date_end'=>'2026-10-26'])]);ok(count($days['desired'])===3,'daily selected date range');
$values=array_values($days['desired']);ok($values[2]['start']['dateTime']==='2026-10-26T19:00:01+00:00','GMT after DST');
$stay=projection([booking(['booking_date_end'=>'2026-10-26','custom_fields'=>'{"_mbs_campsite_span":"continuous"}'])]);$se=reset($stay['desired']);
ok(count($stay['desired'])===1 && $se['end']['dateTime']==='2026-10-26T21:59:02+00:00','continuous camp spans DST correctly');
$all=projection([booking(['all_day'=>1,'booking_date_end'=>'2026-10-26'])]);$ae=reset($all['desired']);ok(count($all['desired'])===1 && $ae['end']['date']==='2026-10-27','all-day end exclusive');
$night=projection([booking(['start_time'=>'22:00','end_time'=>'07:00'])]);$ne=reset($night['desired']);ok($ne['end']['dateTime']==='2026-10-25T07:00:00+00:00','overnight DST');
ok(projection([booking(['all_day'=>1,'booking_date'=>'2026-10-17','booking_date_end'=>'2026-10-17'])])['desired']===[],'ended at midnight excluded');
try{projection([booking(['booking_date'=>'2026-02-30'])]);ok(false,'invalid date');}catch(RuntimeException $e){ok(true,'invalid date fails closed');}
try{projection([booking(),booking()]);ok(false,'duplicate ref');}catch(RuntimeException $e){ok(true,'duplicate ref rejected');}
try{MBS_Google_Calendar::project([booking(['booking_date'=>'2027-03-28','booking_date_end'=>'2027-03-28','start_time'=>'01:30:00'])],false,'site','calendar',wp_timezone(),'2027-01-01');ok(false,'DST gap');}catch(RuntimeException $e){ok(true,'DST gap rejected');}
if(function_exists('openssl_encrypt')){
    $sealed=MBS_Google_Calendar::seal('secret-value');ok($sealed!=='secret-value' && MBS_Google_Calendar::unseal($sealed)==='secret-value','encrypted secret roundtrip');
    try{MBS_Google_Calendar::unseal(base64_encode(str_repeat('x',40)));ok(false,'tampering');}catch(RuntimeException $e){ok(true,'credential tampering fails closed');}
}else{try{MBS_Google_Calendar::seal('secret');ok(false,'no openssl');}catch(RuntimeException $e){ok(true,'no OpenSSL fails closed');}}
echo "Google Calendar: $count assertions passed.\n";
