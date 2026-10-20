<?php
define('ABSPATH',__DIR__);
class WP_Error { private $m; function __construct($c,$m,$d=[]){$this->m=$m;} function get_error_message(){return $this->m;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($v){return json_encode($v);}
class MBS_Campsite_Policy { static function valid_date($d){return preg_match('/^\d{4}-\d{2}-\d{2}$/D',$d)&&date('Y-m-d',strtotime($d))===$d;} }
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-reviewed-park-consolidation.php';
$checks=0;function ok($v,$m){global $checks;$checks++;if(!$v)throw new RuntimeException($m);}
$spaces=['Whole Park'=>[]];$rows=[];foreach(['Bomb Hole','Cabin','Paddock','Plantation','Survival Area','Top Campfire Circle'] as $i=>$area){$spaces[$area]=['parent'=>'Whole Park'];$rows[]=['ref'=>'MBS-L'.(9000+$i).'-270410','space'=>$area,'status'=>'confirmed','booking_date'=>'2027-04-10','booking_date_end'=>'2027-04-11','all_day'=>1,'start_time'=>'00:01:01','end_time'=>'23:59:02','name'=>'Fictional','email'=>'fiction@example.invalid','organisation'=>'Estate','purpose'=>$i===5?'Wrong section':'Camp','notes'=>$area,'custom_fields'=>'{}'];}
$input=['refs'=>array_column($rows,'ref'),'purpose'=>'Camp','notes'=>'Whole Park camp','reason'=>'Owner verified these are one event; incorrect section on one source.','acknowledge_differences'=>true];
function plan($rows,$input=null){global $spaces;return MBS_Reviewed_Park_Consolidation::plan($rows,$spaces,$input??$GLOBALS['input'],'2026-10-20');}
$p=plan($rows);ok(!is_wp_error($p),'Reviewed identity difference accepted');ok(count($p['rows'])===6,'Every source retained');ok($p['rows'][5]['purpose']==='Wrong section','Before-image not rewritten');ok(plan(array_reverse($rows))['fingerprint']===$p['fingerprint'],'Input order independent');
foreach(['booking_date','booking_date_end','start_time','end_time','status','email','name','organisation','user_id','scout_use','kitchen','pricing_tier','is_public'] as $field){$changed=$rows;$changed[1][$field]='different';ok(is_wp_error(plan($changed)),'Reject drift in '.$field);}
foreach(['amount','amount_paid','deposit_paid','current_invoice_document_id','invoice_number','ha_notified','series_id'] as $field){$changed=$rows;$changed[1][$field]=1;ok(is_wp_error(plan($changed)),'Reject financial/integration/group '.$field);}
$changed=$rows;$changed[5]['space']='Paddock';ok(is_wp_error(plan($changed)),'Duplicate area rejected');
$bad=$input;array_pop($bad['refs']);ok(is_wp_error(plan($rows,$bad)),'Five areas cannot become Whole Park');
$bad=$input;$bad['refs'][5]=$bad['refs'][0];ok(is_wp_error(plan($rows,$bad)),'Duplicate source rejected');
$bad=$input;$bad['purpose']='Unreviewed invented identity';ok(is_wp_error(plan($rows,$bad)),'Canonical identity must be from sources');
$bad=$input;$bad['acknowledge_differences']=false;ok(is_wp_error(plan($rows,$bad)),'Acknowledgement required');
$changed=$rows;$changed[0]['notes']='new source detail';ok(plan($changed)['fingerprint']!==$p['fingerprint'],'Source notes frozen');
$bad=$input;$bad['reason']='A different reviewed explanation.';ok(plan($rows,$bad)['fingerprint']!==$p['fingerprint'],'Reason frozen');
echo "Reviewed park consolidation: $checks assertions passed.\n";
