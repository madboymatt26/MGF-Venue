<?php
define('ABSPATH',__DIR__);
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-campsite-consolidation.php';
$checks=0;
function verify($condition,$label){global $checks;$checks++;if(!$condition){fwrite(STDERR,'FAIL: '.$label."\n");exit(1);}}
$areas=array('Bomb Hole','Cabin','Paddock','Plantation','Survival Area','Top Campfire Circle');
$rows=array();
foreach($areas as $i=>$area)$rows[]=array('ref'=>'MBS-L'.(2100+$i).'-261120','status'=>'confirmed','name'=>'Contact','email'=>'contact@example.invalid','organisation'=>'Group','booking_date'=>'2026-11-20','booking_date_end'=>'2026-11-20','all_day'=>true,'start_time'=>'00:01:01','end_time'=>'23:59:02','amount'=>0,'amount_paid'=>0,'invoice_number'=>'','space'=>$area,'purpose'=>'Same event','notes'=>$i%2?'all the site - AMWA':'All the site AMWA','series_id'=>'SER-L'.(2100+$i));
$select=static function($values)use($areas){return MBS_Campsite_Consolidation::candidates($values,$areas,'2026-10-18');};
$match=$select($rows);verify(count($match)===1,'All six matching areas selected');
verify(count($match[0]['refs'])===6&&strlen($match[0]['target_ref'])<=20,'Stable target within booking reference width');
verify($select(array_reverse($rows))===$match,'Selection and references independent of source ordering');
verify(!$select(array_slice($rows,0,5)),'Partial-area use not expanded to whole park');
foreach(array('notes'=>'Other event','purpose'=>'Other purpose','space'=>'Cabin','start_time'=>'09:00:01','end_time'=>'11:59:02','status'=>'pending','email'=>'other@example.invalid','organisation'=>'Different group','amount'=>25,'amount_paid'=>25,'invoice_number'=>'INV-1','booking_date_end'=>'2026-11-21') as $field=>$value){$changed=$rows;$changed[0][$field]=$value;verify(!$select($changed),'Mismatch rejected: '.$field);}
$past=$rows;foreach($past as &$r)$r['booking_date']=$r['booking_date_end']='2026-10-01';unset($r);verify(!$select($past),'Past bookings untouched');
$second=$rows;foreach($second as &$r){$r['booking_date']=$r['booking_date_end']='2026-11-21';$r['ref']=str_replace('261120','261121',$r['ref']);}unset($r);
$both=$select(array_merge($rows,$second));verify(count($both)===2&&$both[0]['family']===$both[1]['family'],'Same original six request groups retain selected-date grouping');
$mixed=$rows;$mixed[0]['notes']='Paddock Wellingborough Scouts';verify(!$select($mixed),'Explicit different-group exception excluded');
echo "OK: {$checks} Whole Park consolidation selection checks\n";
