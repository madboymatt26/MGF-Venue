<?php
define('ABSPATH',__DIR__);
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-calendar-sync-view.php';
$checks=0;
function verify($v,$m){global $checks;$checks++;if(!$v)throw new RuntimeException($m);}
$event=['id'=>'a','summary'=>'Example camp','location'=>'Whole park','start'=>['date'=>'2027-04-10'],'end'=>['date'=>'2027-04-12']];
$other=array_merge($event,['id'=>'b']);
$groups=MBS_Calendar_Sync_View::event_groups([$event,$other]);
verify(count($groups)===1&&array_column($groups[0]['records'],'id')===['a','b'],'Same visible details retain distinct IDs');
verify(count(MBS_Calendar_Sync_View::event_groups([$event,array_merge($other,['location'=>'Cabin'])]))===2,'Different areas stay separate');
$timed=array_merge($event,['start'=>['dateTime'=>'2027-04-10T09:00:00+01:00'],'end'=>['dateTime'=>'2027-04-10T10:00:00+01:00']]);
$utc=array_merge($timed,['id'=>'utc','start'=>['dateTime'=>'2027-04-10T08:00:00Z'],'end'=>['dateTime'=>'2027-04-10T09:00:00Z']]);
verify(count(MBS_Calendar_Sync_View::event_groups([$timed,$utc]))===1,'Equivalent offsets group');
verify(count(MBS_Calendar_Sync_View::event_groups([$event,$timed]))===2,'All-day and timed events remain distinct');
$later=$utc;$later['end']['dateTime']='2027-04-10T09:00:01Z';
verify(count(MBS_Calendar_Sync_View::event_groups([$timed,$later]))===2,'One-second differences remain visible');
$op=['action'=>'review','hash'=>'a','candidates'=>[$event,$other]];$reverse=$op;$reverse['hash']='b';$reverse['candidates']=[$other,$event];
$bookings=MBS_Calendar_Sync_View::booking_groups([$op,$reverse]);
verify(count($bookings)===1&&array_column($bookings[0]['items'],'hash')===['a','b'],'Same exact ID sets group without changing choices');
$different=$op;$different['candidates']=[array_merge($event,['id'=>'c']),$other];
verify(count(MBS_Calendar_Sync_View::booking_groups([$op,$different]))===2,'Same labels with different candidate IDs do not group bookings');
$empty=$op;$empty['candidates']=[];
verify(count(MBS_Calendar_Sync_View::booking_groups([$empty,$empty]))===2,'No-candidate rows never group');
$matched=$op;$matched['action']='matched';
verify(count(MBS_Calendar_Sync_View::booking_groups([$matched,$matched]))===2,'Linked rows remain individual');
foreach(['javascript:alert(1)','http://calendar.google.com/x','https://calendar.google.com.evil.test/x','https://user:pass@calendar.google.com/x','https://www.google.com/search?q=x'] as $url)verify(MBS_Calendar_Sync_View::google_url(['htmlLink'=>$url])==='','Unsafe event URL rejected');
foreach(['https://calendar.google.com/calendar/event?eid=example','https://www.google.com/calendar/event?eid=example'] as $url)verify(MBS_Calendar_Sync_View::google_url(['htmlLink'=>$url])===$url,'Official event URL retained');
verify(MBS_Calendar_Sync_View::record_label(['id'=>'prefix-a-samesuffix12'])!==MBS_Calendar_Sync_View::record_label(['id'=>'prefix-b-samesuffix12']),'Short labels disambiguate matching suffixes');
echo "Calendar Sync groups: $checks assertions passed.\n";
