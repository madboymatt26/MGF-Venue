<?php
define('ABSPATH',__DIR__);
function wp_json_encode($v){return json_encode($v);}
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-calendar-reference-links.php';
$source=(object)array('ref'=>'MBS-OLD','space'=>'Cabin','booking_date'=>'2027-04-10','booking_date_end'=>'2027-04-11','all_day'=>1,'custom_fields'=>json_encode(array('_mbs_legacy'=>array('booking_id'=>22))));
$event=array('id'=>'google-1','description'=>'Booking ID: 22','summary'=>'Same visible title');
$op=array('action'=>'review','original_sources'=>array($source),'candidates'=>array($event),'recommendation'=>array('ids'=>array('google-1'),'reason'=>'Verified original lineage and dates.'));
$original=serialize($op);$e=MBS_Calendar_Reference_Links::explain($op);
if($e['candidates'][0]['confidence']!=='verified_lineage_candidate'||strpos(implode(' ',$e['candidates'][0]['findings']),'Cabin')===false)throw new RuntimeException('Verified original area evidence missing');
if(serialize($op)!==$original)throw new RuntimeException('Explanation mutated a decision or candidate');
$op['recommendation']['ids']=array();$op['candidates'][]=array_merge($event,array('id'=>'google-2'));
$e=MBS_Calendar_Reference_Links::explain($op);
if($e['candidates'][0]['confidence']!=='reference_only_review_required'||strpos(implode(' ',$e['candidates'][0]['findings']),'Multiple Google')===false)throw new RuntimeException('Duplicate references must remain explicit');
$op['candidates'][0]['description']='Unknown';$e=MBS_Calendar_Reference_Links::explain($op);
if($e['candidates'][0]['confidence']!=='overlap_only')throw new RuntimeException('An overlap/title must not imply identity');
$op['candidates']=array();$op['original_sources']=array();$e=MBS_Calendar_Reference_Links::explain($op);
if($e['candidates']!==array())throw new RuntimeException('Missing lineage must not invent evidence');
echo "CALENDAR_EXPLANATIONS_OK: exact references, ambiguity, no lineage, no mutation\n";
