<?php
/** Runs real HA feed/queue/worker logic with synthetic adapters only. */
require __DIR__.'/php-review-reliability.php';
$start_checks=$checks;
$scout=clone$b;$scout->ref='SCOUT-ONE';$scout->scout_use=1;$scout->series_id='';$scout->custom_fields='';
check(!MBS_HomeAssistant::heating_required($scout),'Existing one-off Scout defaults off');
$scout->custom_fields=json_encode(array(MBS_HomeAssistant::HEATING_KEY=>true,'answer'=>array('label'=>'Question','value'=>'Preserved')));
check(MBS_HomeAssistant::heating_required($scout),'Explicit one-off opt-in accepted');
$series=clone$scout;$series->ref='SCOUT-SERIES';$series->series_id='SERIES';
check(!MBS_HomeAssistant::heating_required($series),'Series always off, including copied opt-in metadata');
foreach(array(false,1,'1','true',array('value'=>true))as$value){$invalid=clone$scout;$invalid->custom_fields=json_encode(array(MBS_HomeAssistant::HEATING_KEY=>$value));check(!MBS_HomeAssistant::heating_required($invalid),'Only staff-stored JSON boolean true enables Scout heating');}
$invalid->ref='SCOUT-INVALID';$invalid->custom_fields='{broken';check(!MBS_HomeAssistant::heating_required($invalid),'Malformed Scout metadata fails closed');
MBS_Bookings::$rows=array($b,$scout,$series,$invalid);
check(array_column(MBS_HomeAssistant::get_todays_bookings(),'ref')===array('FIXTURE','SCOUT-ONE'),'Today heating feed excludes series and opt-out');
check(array_column(MBS_HomeAssistant::get_upcoming_for_ha(366),'ref')===array('FIXTURE','SCOUT-ONE'),'Upcoming heating feed uses same policy');
check(count(MBS_Booking_Intervals::approved('2027-07-11'))===4,'Occupancy source retains all Scout bookings');
$payload=MBS_HomeAssistant::payload($scout);
check($payload['scout_use']===true&&$payload['heating_required']===true&&$payload['booking_status']==='paid','Contract explicitly carries heating preference and actual status');
$version=MBS_HomeAssistant::heating_version($scout);$scout->notes='Changed';
check($version!==MBS_HomeAssistant::heating_version($scout),'CAS fingerprint detects unrelated changes');
$before=$http;check(MBS_HomeAssistant::notify($scout)===true&&$http===$before,'Enabled one-off queues without immediate HTTP');
MBS_HomeAssistant::process();check($GLOBALS['last_ha_payload']['event']==='booking_confirmed','Enabled approved one-off delivered');
$scout->ha_notified=1;$scout->custom_fields=json_encode(array(MBS_HomeAssistant::HEATING_KEY=>false));
check(MBS_HomeAssistant::notify($scout)===true,'Previously notified opt-out retains removal intent');
MBS_HomeAssistant::process();
check($GLOBALS['last_ha_payload']['event']==='heating_request_removed'&&$GLOBALS['last_ha_payload']['booking_status']==='paid','Removal never claims actual booking cancelled');
check($scout->status==='paid'&&$scout->amount===$b->amount,'Heating change leaves status and price unchanged');
$scout->ha_notified=0;check(MBS_HomeAssistant::notify($scout)===false,'Never-notified opt-out does not create a request');
echo 'PASS '.($checks-$start_checks)." Scout heating assertions\n";
