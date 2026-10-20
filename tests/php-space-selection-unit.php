<?php
define('ABSPATH',__DIR__); define('MBS_TABLE','mathlin_bookings');
class WP_Error { public $message; function __construct($c,$m,$data=null){$this->message=$m;} }
function is_wp_error($v){return $v instanceof WP_Error;}
$opts=[];function get_option($k,$default=false){global $opts;return $opts[$k]??$default;}
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-bookings.php';
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-campsite-policy.php';
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-space-selection.php';
require dirname(__DIR__).'/wp-plugin/mathlin-booking/includes/class-blocked-dates.php';
$n=0;function selection_ok($v,$label){global $n;$n++;if(!$v)throw new RuntimeException($label);}
$opts['mbs_spaces']=['Whole Centre'=>['capacity'=>200],'Hall'=>['parent'=>'Whole Centre','capacity'=>100],'Kitchen'=>['parent'=>'Whole Centre','capacity'=>20],'Other Parent'=>['capacity'=>50],'Other'=>['parent'=>'Other Parent','capacity'=>30]];
selection_ok(!MBS_Space_Selection::enabled(),'Default off');
selection_ok(MBS_Space_Selection::normalize('Whole Centre')==='Whole Centre','Existing whole centre unchanged');
selection_ok(MBS_Space_Selection::normalize('Hall')==='Hall','Existing paid room unchanged');
selection_ok(is_wp_error(MBS_Space_Selection::normalize(['Hall','Kitchen'])),'Paid multi disabled');
$opts['mbs_multi_space_enabled']=1;selection_ok(!MBS_Space_Selection::enabled(),'Flag alone does not enable on paid site');
$opts['mbs_campsite_mode']=1;selection_ok(MBS_Space_Selection::enabled(),'Explicit campsite enable');
selection_ok(MBS_Space_Selection::normalize(['Kitchen','Hall','Hall'])==='Hall + Kitchen','Canonical ordered set');
selection_ok(MBS_Space_Selection::normalize('Kitchen + Hall')==='Hall + Kitchen','String round trip');
selection_ok(MBS_Space_Selection::normalize(['Whole Centre'])==='Whole Centre','Single whole selection');
foreach([[],['Whole Centre','Hall'],['Hall','Other'],['Hall','Unknown'],['Hall',[]],42]as$bad)selection_ok(is_wp_error(MBS_Space_Selection::normalize($bad)),'Invalid set rejected');
selection_ok(MBS_Space_Selection::info('Hall + Kitchen')['capacity']===20,'Capacity not summed');
selection_ok(MBS_Space_Selection::overlaps('Hall + Kitchen','Hall'),'Selected member conflict');
selection_ok(MBS_Space_Selection::overlaps('Whole Centre','Hall + Kitchen'),'Parent conflict');
selection_ok(!MBS_Space_Selection::overlaps('Hall','Kitchen'),'Sibling independence');
selection_ok(!MBS_Space_Selection::overlaps('Hall + Kitchen','Other'),'Unselected area independent');
selection_ok(MBS_Space_Selection::overlaps('Hall + Kitchen','Kitchen + Other'),'Intersecting allocation conflict');
$opts['mbs_multi_space_enabled']=0;selection_ok(is_wp_error(MBS_Space_Selection::normalize('Hall + Kitchen')),'Disabled blocks new sets');
selection_ok(MBS_Space_Selection::normalize('Hall + Kitchen',true)==='Hall + Kitchen','Read-only check remains available');
$opts['mbs_campsite_mode']=0;$opts['mbs_multi_space_ever_used']=1;
selection_ok(MBS_Space_Selection::protect_allocations(),'Existing occupancy survives both switches off');
selection_ok(MBS_Space_Selection::info('Hall + Kitchen')['capacity']===20,'Historical selection remains readable');
class Selection_DB {
 public $prefix='wp_',$rows=[],$blocks=[],$last_error='',$locks=0,$released=0,$allow_lock=true;
 function prepare($q,...$args){if(count($args)===1&&is_array($args[0]))$args=$args[0];foreach($args as$a)$q=preg_replace('/%s/',"'".$a."'",$q,1);return$q;}
 function get_results($sql){return strpos($sql,'mathlin_blocked_dates')!==false?$this->blocks:$this->rows;}
 function get_var($q){if(strpos($q,'GET_LOCK')!==false){$this->locks++;return$this->allow_lock?1:0;}$this->released++;return 1;}
 function esc_like($v){return$v;}
}
$wpdb=new Selection_DB();$wpdb->rows=[(object)['space'=>'Hall + Kitchen','ref'=>'SET','all_day'=>1,'booking_date'=>'2027-05-28','custom_fields'=>'{}']];
selection_ok(count(MBS_Bookings::check_conflicts('Hall','2027-05-28',null,null,true))===1,'Actual domain sees composite from single area');
selection_ok(count(MBS_Bookings::check_conflicts('Whole Centre','2027-05-28',null,null,true))===1,'Actual domain parent sees set exactly once');
selection_ok(!MBS_Bookings::check_conflicts('Other','2027-05-28',null,null,true),'Unselected physical area free');
$wpdb->blocks=[(object)['space'=>'Kitchen','reason'=>'Work']];
selection_ok((bool)MBS_Blocked_Dates::is_blocked('2027-05-28','Hall + Kitchen'),'Member block protects set');
selection_ok((bool)MBS_Blocked_Dates::is_blocked('2027-05-28','Whole Centre'),'Child block protects whole');
selection_ok(!MBS_Blocked_Dates::is_blocked('2027-05-28','Hall'),'Block does not occupy sibling');
$wpdb->blocks=[(object)['space'=>'Whole Centre','reason'=>'Work']];selection_ok((bool)MBS_Blocked_Dates::is_blocked('2027-05-28','Hall + Kitchen'),'Parent block protects set');
$wpdb->last_error='simulated';selection_ok((bool)MBS_Bookings::check_conflicts('Other','2027-05-28',null,null,true),'Availability error fails closed');selection_ok((bool)MBS_Blocked_Dates::is_blocked('2027-05-28','Hall'),'Block error fails closed');$wpdb->last_error='';
$result=MBS_Space_Selection::locked(function(){return MBS_Space_Selection::locked(function(){return 'saved';});});selection_ok($result==='saved'&&$wpdb->locks===1&&$wpdb->released===1,'Nested writer lock held until outer completion');
try{MBS_Space_Selection::locked(function(){throw new RuntimeException('test');});}catch(RuntimeException$e){}selection_ok($wpdb->released===2,'Exception releases lock');
$wpdb->allow_lock=false;selection_ok(is_wp_error(MBS_Space_Selection::locked(function(){throw new RuntimeException('Must not run');})),'Busy lock prevents write');
echo "Space selection: $n assertions passed.\n";
