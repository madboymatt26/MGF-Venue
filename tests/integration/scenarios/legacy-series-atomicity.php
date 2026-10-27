<?php
/** Disposable DB only; every fixture uses reserved 2098 dates and invalid mail. */
if(!defined('WP_CLI')||!WP_CLI||!preg_match('/127\.0\.0\.1|localhost|wordpress/',home_url()))throw new RuntimeException('Disposable runtime required.');
global $wpdb;$table=$wpdb->prefix.MBS_TABLE;$parents=$wpdb->prefix.MBS_SERIES_TABLE;$checks=0;$ref='';$refs=array();$fail='';$updates=0;$prior=$wpdb->suppress_errors(true);
$saved=array();foreach(array('mbs_spaces','mbs_campsite_mode','mbs_min_duration_hours')as$key)$saved[$key]=get_option($key,null);
$assert=static function($ok,$why)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($why);};
$filter=static function($sql)use(&$fail,&$updates,$table,$parents){
    if($fail==='second_update'&&strpos($sql,'UPDATE `'.$table.'`')!==false&&++$updates===2)return'INVALID SYNTHETIC SECOND UPDATE';
    if($fail==='audit'&&strpos($sql,'INSERT INTO')!==false&&strpos($sql,'mathlin_audit')!==false)return'INVALID SYNTHETIC AUDIT INSERT';
    if($fail==='financial'&&strpos($sql,'SELECT 1 FROM')!==false&&strpos($sql,'INNER JOIN '.$table)!==false)return'INVALID SYNTHETIC FINANCIAL READ';
    return$sql;
};
add_filter('query',$filter,PHP_INT_MAX);
try{
    update_option('mbs_spaces',array('Legacy Test'=>array('rate_hourly'=>0,'rate_daily'=>0,'capacity'=>100)));update_option('mbs_campsite_mode',0);update_option('mbs_min_duration_hours',0);
    $data=array('name'=>'Synthetic Scouts','email'=>'legacy-test@example.invalid','space'=>'Legacy Test','booking_date'=>'2098-03-03','booking_date_end'=>'2098-03-03','start_time'=>'10:00','end_time'=>'12:00','scout_use'=>true,'all_day'=>false,'purpose'=>'Synthetic legacy atomicity','attendees'=>1);
    $created=MBS_Bookings::create_recurring($data,'2098-03-17',true);$assert(!is_wp_error($created),'Create series fixture');$ref=$created['series_id'];$refs=$created['refs'];
    $series=MBS_Series::get($ref);$approved=MBS_Series::approve($ref,'pending',(int)$series->version,false);$assert(!is_wp_error($approved),'Approve fixture without mail');
    $before=wp_json_encode(MBS_Bookings::get_series($ref));
    $fail='second_update';$r=MBS_Bookings::update_series_future($ref,array('purpose'=>'Changed'));$fail='';$assert(is_wp_error($r)&&wp_json_encode(MBS_Bookings::get_series($ref))===$before,'Second-row failure rolls back first-row edit');
    $fail='audit';$r=MBS_Bookings::cancel_series_future($ref);$fail='';$assert(is_wp_error($r)&&wp_json_encode(MBS_Bookings::get_series($ref))===$before,'Audit failure rolls back bulk cancellation');
    $r=MBS_Bookings::update_series_future($ref,array('start_time'=>'25:00'));$assert(is_wp_error($r),'Invalid times fail before mutation');
    $r=MBS_Bookings::cancel_series_future($ref);$assert(!is_wp_error($r)&&$r===3,'Complete cancellation commits');
    $block=MBS_Bookings::create($data,true);$assert(!is_wp_error($block),'Create conflicting pending fixture');$refs[]=$block['ref'];
    $r=MBS_Bookings::reopen_series_future($ref);$assert(is_wp_error($r),'Reopen refuses a newly occupied date');
    foreach(MBS_Bookings::get_series($ref)as$b)$assert($b->status==='cancelled','Failed reopen leaves every occurrence cancelled');
    $wpdb->delete($table,array('ref'=>$block['ref']));$r=MBS_Bookings::reopen_series_future($ref);$assert(!is_wp_error($r)&&$r===3,'Conflict-free reopen commits');
    $fail='audit';$r=MBS_Bookings::extend_series($ref,'2098-03-31');$fail='';$assert(is_wp_error($r)&&count(MBS_Bookings::get_series($ref))===3,'Audit failure rolls back every extended occurrence');
    $r=MBS_Bookings::extend_series($ref,'2098-02-31');$assert(is_wp_error($r),'Invalid calendar extension date refused');
    $fail='financial';$r=MBS_Bookings::delete_series($ref,'all');$fail='';$assert(is_wp_error($r)&&count(MBS_Bookings::get_series($ref))===3,'Financial-read failure cannot authorise deletion');
    $fail='audit';$r=MBS_Bookings::delete_series($ref,'all');$fail='';$assert(is_wp_error($r)&&count(MBS_Bookings::get_series($ref))===3,'Audit failure restores deleted occurrences and parent');
    $r=MBS_Bookings::delete_series($ref,'typo');$assert(is_wp_error($r),'Unknown delete scope is not silently entire series');
    $r=MBS_Bookings::delete_series($ref,'all');$assert(!is_wp_error($r)&&$r===3&&!MBS_Series::get($ref),'Explicit complete deletion commits');
    echo "LEGACY_SERIES_ATOMICITY_OK: {$checks} assertions\n";
}finally{
    $fail='';remove_filter('query',$filter,PHP_INT_MAX);$wpdb->query('ROLLBACK');foreach($refs as$booking_ref){$wpdb->delete($table,array('ref'=>$booking_ref));$wpdb->delete($wpdb->prefix.'mathlin_audit_log',array('ref'=>$booking_ref));}if($ref){$wpdb->delete($parents,array('series_ref'=>$ref));$wpdb->delete($wpdb->prefix.'mathlin_audit_log',array('ref'=>$ref));}foreach($saved as$key=>$value){if($value===null)delete_option($key);else update_option($key,$value);}$wpdb->suppress_errors($prior);
}
