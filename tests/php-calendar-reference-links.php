<?php
require __DIR__.'/php-google-calendar-worker.php';
ready();
$a=fixture();$a->ref='MBS-L1';$a->custom_fields=json_encode(['_mbs_legacy'=>['plugin'=>'wpbc','booking_id'=>1]]);
$b=clone $a;$b->ref='MBS-L2';$b->space='Paddock';$b->custom_fields=json_encode(['_mbs_legacy'=>['plugin'=>'wpbc','booking_id'=>2]]);
$whole=clone $a;$whole->ref='MBS-WHOLE';$whole->space='Whole Park';$whole->custom_fields=json_encode(['_mbs_whole_park_sources'=>[$a->ref,$b->ref]]);
$a->status=$b->status='archived';$wpdb->rows=[$a,$b,$whole];
$p=actual_ops();$e1=$p[0]['want'];unset($e1['extendedProperties']);$e1['id']='area-one';$e1['etag']='one';$e1['description']='Additional Information : example 1';
$e2=$e1;$e2['id']='area-two';$e2['etag']='two';$e2['description']='Additional Information : example 2';
$p=actual_ops([$e1,$e2]);check($p[0]['recommendation']['ids']===['area-one','area-two'],'recommend complete original area set');
$queue=[['code'=>200,'body'=>json_encode($e1)],['code'=>200,'body'=>json_encode($e2)]];
$r=invoke('execute',$p,[$p[0]['hash']=>['protect:area-one','protect:area-two']],false);
check($r['completed']===1&&count($requests)===2,'one booking links two Google events with GET only');
$s=get_option('mbs_google_calendar');$site=hash('sha256','https://fixture.test');$projection=MBS_Google_Calendar::project($wpdb->rows,false,$site,'target',wp_timezone(),'2026-10-18');
$linked=MBS_Google_Calendar::plan($projection,[$e1,$e2],$site,'2026-10-18',$s['links']);
check($linked[0]['action']==='matched'&&count($linked[0]['linked_events'])===2,'whole area set stays linked');
$before_requests=count($requests);invoke('execute',$linked);check(count($requests)===$before_requests,'replay never inserts duplicates');
check(MBS_Google_Calendar::estate_only(['ops'=>$linked,'events'=>[$e1,$e2]])===[],'neither linked area appears as estate-only');
$changed=$e2;$changed['summary']='Estate updated';$diff=MBS_Google_Calendar::plan($projection,[$e1,$changed],$site,'2026-10-18',$s['links']);check($diff[0]['action']==='difference','change to non-primary area is surfaced');
$gone=MBS_Google_Calendar::plan($projection,[$e1],$site,'2026-10-18',$s['links']);check($gone[0]['action']==='blocked','missing non-primary never causes duplicate');
$events=[$e1,$e2];$events[1]['start']['dateTime']='2026-10-24T18:00:00+01:00';
check(MBS_Calendar_Reference_Links::recommend($projection['legacy'][$p[0]['key']],$events,$p[0]['want'])['ids']===[],'time discrepancy not recommended');
$dup=$e1;$dup['id']='area-one-duplicate';check(MBS_Calendar_Reference_Links::recommend($projection['legacy'][$p[0]['key']],[$e1,$dup,$e2],$p[0]['want'])['ids']===[],'ambiguous same original reference rejected');
check(MBS_Calendar_Reference_Links::event_legacy_id(['description'=>'Phone: 123456789'])==='','phone digits are not original IDs');
check(MBS_Calendar_Reference_Links::event_legacy_id(['description'=>'Additional Information : text 2352'])==='2352','actual legacy trailing reference recognized');
try{invoke('reviewed_choices',$diff,[$diff[0]['hash']=>['protect:area-one']]);check(false,'partial acknowledgement');}catch(RuntimeException $e){check(true,'cannot silently drop other area links');}
$other=$p[0];$other['key']='MBS-OTHER|stay';$other['hash']='different-hash';
try{invoke('reviewed_choices',[$p[0],$other],[$p[0]['hash']=>['protect:area-one'],$other['hash']=>['protect:area-one']]);check(false,'duplicate cross booking link');}catch(RuntimeException $e){check(true,'cross booking reassignment rejected');}
$saved_links=$options['mbs_google_calendar']['links'];unset($options['mbs_google_calendar']['links']);$requests=[];
$queue=[['code'=>200,'body'=>json_encode($e1)],['code'=>200,'body'=>json_encode($changed)]];
try{invoke('execute',$p,[$p[0]['hash']=>['protect:area-one','protect:area-two']],false);check(false,'changed second event');}catch(RuntimeException $e){check(empty($options['mbs_google_calendar']['links']),'changed second event saves no partial link set');}
$options['mbs_google_calendar']['links']=$saved_links;$whole->status='cancelled';
$cancelled=MBS_Google_Calendar::project($wpdb->rows,false,$site,'target',wp_timezone(),'2026-10-18');
$retained=MBS_Google_Calendar::plan($cancelled,[$e1,$e2],$site,'2026-10-18',$saved_links);check(count($retained[0]['linked_events'])===2&&$retained[0]['action']==='retained','cancel retains every Google area reference');
$native=fixture();$native->ref='MBS-NATIVE';$wpdb->rows=[$native];check(actual_ops()[0]['action']==='create','native new bookings still add');
$native->custom_fields=json_encode(['_mbs_legacy'=>['plugin'=>'wpbc','booking_id'=>8]]);check(actual_ops()[0]['action']==='review','unmatched import blocked even with no overlap');
echo "Calendar multiple references: passed ($assertions total checks).\n";
