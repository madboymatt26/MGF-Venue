<?php
if(!defined('WP_CLI')||!WP_CLI||!class_exists('MBS_Reviewed_Park_Consolidation'))throw new RuntimeException('Integration runtime required.');
if(!preg_match('/localhost|wordpress|127\.0\.0\.1/',home_url()))throw new RuntimeException('Disposable test host required.');
function park_ok($v,$m){if(!$v)throw new RuntimeException($m);}
class Park_Test_Actor extends MBS_Reviewed_Park_Consolidation { public function allowed(){return current_user_can('manage_options')&&MBS_Campsite_Policy::enabled();} }
function park_request($input){$r=new WP_REST_Request('POST');$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($input));return $r;}
global $wpdb;$table=$wpdb->prefix.MBS_TABLE;
$original=[];foreach(['mbs_spaces','mbs_campsite_mode','mbs_google_calendar'] as $key)$original[$key]=get_option($key,null);
$admin=get_user_by('login','admin');wp_set_current_user($admin->ID);$actor=new Park_Test_Actor();
$mail=0;$http=0;$mail_guard=function()use(&$mail){$mail++;return false;};$http_guard=function($pre)use(&$http){$http++;return $pre;};
add_filter('pre_wp_mail',$mail_guard);add_filter('pre_http_request',$http_guard);
try {
 $areas=['Park Test A','Park Test B','Park Test C','Park Test D','Park Test E','Park Test F'];$spaces=['Whole Park'=>['capacity'=>1000]];foreach($areas as $area)$spaces[$area]=['parent'=>'Whole Park','capacity'=>100];
 update_option('mbs_spaces',$spaces);update_option('mbs_campsite_mode',1);update_option('mbs_google_calendar',[]);
 park_ok(!(new MBS_Reviewed_Park_Consolidation())->allowed(),'Production route refuses test host');
 $date=wp_date('Y-m-d',strtotime('+330 days'));$end=wp_date('Y-m-d',strtotime($date.' +1 day'));$refs=[];
 foreach($areas as $i=>$area){$ref='MBS-L'.(99100+$i).'-'.date('ymd',strtotime($date));$refs[]=$ref;park_ok($wpdb->insert($table,['ref'=>$ref,'status'=>'confirmed','name'=>'Fictional estate coordinator','email'=>'park@example.invalid','organisation'=>'Estate','space'=>$area,'booking_date'=>$date,'booking_date_end'=>$end,'all_day'=>1,'start_time'=>'00:01:01','end_time'=>'23:59:02','purpose'=>$i===5?'Wrong legacy section':'Camp','amount'=>0,'notes'=>$area,'custom_fields'=>'{"_mbs_campsite_span":"continuous"}','modification_token'=>'fictional-test-only'])===1,'Seed source');}
 $input=['operation'=>'preview','refs'=>$refs,'purpose'=>'Camp','notes'=>'Whole Park camp','reason'=>'Owner verified one event and confirmed wrong legacy section.','acknowledge_differences'=>true];
 $p=$actor->handle(park_request($input));park_ok(!is_wp_error($p),is_wp_error($p)?$p->get_error_message():'preview');$preview=$p->get_data();$target=$preview['target_ref'];
 $before=$wpdb->get_results("SELECT * FROM {$table} ORDER BY ref",ARRAY_A);$input['operation']='commit';$input['fingerprint']=$preview['fingerprint'];$input['confirm']=true;$input['dry_run']=true;
 $r=$actor->handle(park_request($input));park_ok(!is_wp_error($r),is_wp_error($r)?$r->get_error_message():'rehearsal');park_ok($before===$wpdb->get_results("SELECT * FROM {$table} ORDER BY ref",ARRAY_A),'Rehearsal restores every row');
 $bad=$input;$bad['fingerprint']='stale';park_ok(is_wp_error($actor->handle(park_request($bad))),'Stale preview rejected');
 $input['dry_run']=false;
 $failure=function($q)use($wpdb){return strpos($q,'INSERT INTO `'.$wpdb->options.'`')===0&&strpos($q,'mbs_reviewed_park_')!==false?'INVALID REVIEWED PARK TEST SQL':$q;};
 $wpdb->suppress_errors(true);add_filter('query',$failure);$r=$actor->handle(park_request($input));remove_filter('query',$failure);$wpdb->suppress_errors(false);
 park_ok(is_wp_error($r),'Snapshot failure fails closed');park_ok($before===$wpdb->get_results("SELECT * FROM {$table} ORDER BY ref",ARRAY_A),'Snapshot failure rolls back all rows');
 $r=$actor->handle(park_request($input));park_ok(!is_wp_error($r),is_wp_error($r)?$r->get_error_message():'commit');$new=MBS_Bookings::get($target);park_ok($new&&$new->space==='Whole Park'&&$new->status==='confirmed'&&$new->booking_date_end===$end,'Approval and full range preserved');
 park_ok($new->start_time==='00:01:01'&&$new->end_time==='23:59:02','Original seconds preserved');
 foreach($refs as $ref){$row=MBS_Bookings::get($ref);park_ok($row->status==='archived','Source archived');park_ok(json_decode($row->custom_fields,true)['_mbs_whole_park_replacement']===$target,'Recovery pointer retained');}
 $retry=$actor->handle(park_request($input));park_ok(!is_wp_error($retry)&&$retry->get_data()['no_op'],'Retry idempotent');park_ok($mail===0&&$http===0,'No mail or external calls');
 wp_set_current_user(0);park_ok(is_wp_error($actor->handle(park_request($input))),'Anonymous denied');echo "REVIEWED_PARK_CONSOLIDATION_OK\n";
} finally {remove_filter('pre_wp_mail',$mail_guard);remove_filter('pre_http_request',$http_guard);foreach($original as $key=>$value){if($value===null)delete_option($key);else update_option($key,$value);}wp_set_current_user($admin->ID);}
