<?php
define('ABSPATH',__DIR__);
function wp_json_encode($v){return json_encode($v);}
function current_user_can($cap){return false;}
class WP_Error{public $message;function __construct($c,$m){$this->message=$m;}}
function is_wp_error($v){return $v instanceof WP_Error;}
class MBS_Campsite_Policy{static function enabled(){return true;}}
class MBS_Access_Details{static function is_estate_booking($b){return in_array(strtolower(trim($b->organisation)),['estate','hp business'],true)||strpos($b->notes,"Estate reservation entered by staff.\n")===0;}}
require __DIR__.'/../wp-plugin/mathlin-booking/includes/class-imported-access-reset.php';
$checks=0;function reset_ok($v,$m){global $checks;$checks++;if(!$v)throw new RuntimeException($m);}
function fixture($ref,$fields=[]){return(object)array_merge(['id'=>1,'ref'=>$ref,'booking_date'=>'2100-01-02','status'=>'confirmed','organisation'=>'Scout Group','notes'=>'','access_sent'=>1,'custom_fields'=>json_encode(['_mbs_legacy'=>['plugin'=>'wpbc']])],$fields);}
$original=fixture('MBS-ORIGINAL');$resolve=static function($ref)use($original){return $ref===$original->ref?$original:null;};
$rows=[fixture('MBS-SCOUT'),fixture('MBS-ESTATE',['organisation'=>'Estate']),fixture('MBS-HP',['organisation'=>'HP Business']),fixture('MBS-STAFF',['notes'=>"Estate reservation entered by staff.\n"]),fixture('MBS-PENDING',['status'=>'pending']),fixture('MBS-CANCELLED',['status'=>'cancelled']),fixture('MBS-PAST',['booking_date'=>'2000-01-01']),fixture('MBS-UNSENT',['access_sent'=>0]),fixture('MBS-NATIVE',['custom_fields'=>'{}']),fixture('MBS-MAILED'),fixture('MBS-CONSOLIDATED',['custom_fields'=>json_encode(['_mbs_whole_park_sources'=>['MBS-ORIGINAL']])])];
$p=MBS_Imported_Access_Reset::plan($rows,['MBS-MAILED'=>true],'2099-01-01',$resolve);
reset_ok($p['refs']===['MBS-CONSOLIDATED','MBS-SCOUT'],'Only future approved imported Scouts selected');
foreach(['MBS-ESTATE','MBS-HP','MBS-STAFF']as$ref)reset_ok($p['skipped'][$ref]==='estate_booking','Estate excluded');
reset_ok($p['skipped']['MBS-PAST']==='not_future','Past excluded');reset_ok($p['skipped']['MBS-PENDING']==='not_approved','Pending excluded');reset_ok($p['skipped']['MBS-CANCELLED']==='not_approved','Cancelled excluded');reset_ok($p['skipped']['MBS-UNSENT']==='already_unsent','Unsent untouched');reset_ok($p['skipped']['MBS-NATIVE']==='not_verified_import','Native sent flag protected');reset_ok($p['skipped']['MBS-MAILED']==='access_delivery_or_queue_exists','Genuine access history protected');
$p2=MBS_Imported_Access_Reset::plan($rows,['MBS-MAILED'=>true,'MBS-ORIGINAL'=>true],'2099-01-01',$resolve);reset_ok(!in_array('MBS-CONSOLIDATED',$p2['refs'],true),'Delivery evidence on original protects consolidated replacement');reset_ok($p2['fingerprint']!==$p['fingerprint'],'Delivery evidence changes fingerprint');
$rows[0]->booking_date='2100-02-01';$p3=MBS_Imported_Access_Reset::plan($rows,['MBS-MAILED'=>true],'2099-01-01',$resolve);reset_ok($p3['fingerprint']!==$p['fingerprint'],'Booking changes invalidate preview');
$broken=fixture('MBS-BROKEN',['custom_fields'=>json_encode(['_mbs_whole_park_sources'=>['MBS-MISSING']])]);reset_ok(!MBS_Imported_Access_Reset::lineage($broken,$resolve),'Missing origin fails closed');reset_ok(is_wp_error(MBS_Imported_Access_Reset::handle(['operation'=>'preview','refs'=>['MBS-SCOUT']])),'Administrator permission enforced');
$today_rows=[fixture('MBS-LATER',['booking_date'=>'2099-01-01','start_time'=>'18:00:00']),fixture('MBS-STARTED',['booking_date'=>'2099-01-01','start_time'=>'08:00:00']),fixture('MBS-ALLDAY',['booking_date'=>'2099-01-01','all_day'=>1])];$today_plan=MBS_Imported_Access_Reset::plan($today_rows,[],'2099-01-01',$resolve,'12:00:00');reset_ok($today_plan['refs']===['MBS-LATER'],'Not-yet-started timed bookings today qualify, past and started all-day do not');
echo "Imported access reset: $checks checks passed.\n";
