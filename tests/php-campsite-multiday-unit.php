<?php
define('ABSPATH',__DIR__);
function wp_json_encode($v){return json_encode($v);}
function wp_timezone(){return new DateTimeZone('Europe/London');}
function wp_strip_all_tags($s){return strip_tags($s);}
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-campsite-policy.php';
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-campsite-multiday.php';
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-google-calendar.php';
$checks=0;
function ok($v,$label){global $checks;$checks++;if(!$v)throw new RuntimeException($label);}
$rows=[];
foreach(['2026-10-24','2026-10-25','2026-10-26'] as $i=>$date)$rows[]=['ref'=>'MBS-L100-'.substr(str_replace('-','',$date),2),'series_id'=>'SER-L100','booking_date'=>$date,'booking_date_end'=>$date,'status'=>'confirmed','space'=>'Cabin','name'=>'Test','email'=>'test@example.invalid','organisation'=>'Scouts','purpose'=>'Camp','notes'=>'Same camp','all_day'=>1,'start_time'=>'00:01:01','end_time'=>'23:59:02','amount'=>0,'custom_fields'=>json_encode(['_mbs_legacy'=>['source_hash'=>(string)$i],'_mbs_campsite_span'=>'daily'])];
function select($r){return MBS_Campsite_Multiday::candidates($r,'2026-10-19')['matches'];}
$m=select($rows);ok(count($m)===1,'Consecutive full-day original request is one stay');
ok($m[0]['from']==='2026-10-24'&&$m[0]['to']==='2026-10-26','Inclusive dates retained');
ok(select(array_reverse($rows))===$m,'Stable plan ordering and surviving reference');
foreach(['status'=>'pending','space'=>'Paddock','email'=>'another@example.invalid','purpose'=>'Other camp','notes'=>'Different','amount'=>25,'invoice_number'=>'INV-1','amount_paid'=>25,'ha_notified'=>1,'all_day'=>0,'start_time'=>'18:00:01','organisation'=>'Other group','user_id'=>42,'admin_notes'=>'Distinct instruction','custom_fields'=>'{}'] as $key=>$value){$r=$rows;$r[1][$key]=$value;ok(!select($r),'Reject mismatched '.$key);}
ok(!select([$rows[0],$rows[2]]),'Gapped dates not made into a camp');
$r=$rows;$r[1]['booking_date']=$r[1]['booking_date_end']=$r[0]['booking_date'];ok(!select($r),'Duplicate day rejected');
$r=$rows;foreach($r as &$b)$b['all_day']=0;unset($b);ok(!select($r),'Repeated timed sessions never converted');
$r=$rows;foreach($r as &$b)$b['series_id']='SER-NATIVE';unset($b);ok(!select($r),'Native recurrence untouched');
$r=$rows;$r[0]['booking_date']=$r[0]['booking_date_end']='2026-10-18';ok(!select($r),'Partly past group untouched');
$r=$rows;foreach($r as &$b)$b['status']='pending';unset($b);ok(select($r)[0]['status']==='pending','Pending stays never approved by merge');
$r=$rows;$r[]=$rows[0];$r[3]['booking_date']=$r[3]['booking_date_end']='2026-11-01';$r[3]['all_day']=0;ok(!select($r),'Mixed parent is not partially rewritten');
$merged=$rows[0];$merged['booking_date_end']='2026-10-26';$merged['custom_fields']=json_encode(['_mbs_campsite_span'=>'continuous']);
foreach(['2026-10-24','2026-10-25','2026-10-26'] as $day)ok(MBS_Campsite_Policy::interval($merged,$day)===['00:00','24:00'],'Every occupied day preserved across DST: '.$day);
$projection=MBS_Google_Calendar::project([(object)$merged],false,'site','calendar',wp_timezone(),'2026-10-19');
ok(count($projection['desired'])===1,'Google projects one event, not three');$event=reset($projection['desired']);
ok($event['start']['date']==='2026-10-24'&&$event['end']['date']==='2026-10-27','Google exclusive end correct');
ok(strpos(json_encode($event),'test@example')===false,'No customer contact exported');
$r=$rows;foreach($r as $i=>&$b){$b['ref']='MBS-W'.strtoupper(substr(hash('sha256',(string)$i),0,12));$b['series_id']='SER-WFAMILY';$b['custom_fields']=json_encode(['_mbs_whole_park_sources'=>['source-'.$i],'_mbs_whole_park_batch'=>'batch']);$b['space']='Whole Park';$b['admin_notes']='Migrated from Booking Calendar #1. Original approval and local time endpoints preserved.' . "\nWhole Park consolidation: MBS-L".$i.'-261024. Original records archived, approvals/times retained; no customer notification.';}unset($b);
ok(count(select($r))===1,'Previously consolidated Whole Park days can become one stay');
$r[1]['admin_notes'].="\nSeparate instruction";ok(!select($r),'Coordinator notes must not be lost');
echo "OK: $checks multiday repair assertions passed\n";
