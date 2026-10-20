<?php
/** Real queue/database, intercepted transport; disposable WordPress only. */
if (!defined('WP_CLI') || !WP_CLI || !preg_match('/localhost|127\.0\.0\.1|wordpress/',home_url())) throw new RuntimeException('Disposable integration host required.');
function access_ok($v,$m){if(!$v)throw new RuntimeException($m);}
global $wpdb;
$keys=['mbs_spaces','mbs_campsite_mode','mbs_payments_enabled','mbs_access_enabled','mbs_access_code','mbs_access_hours_before','mbs_min_notice_days','mbs_min_duration_hours'];
$original=[];foreach($keys as $key)$original[$key]=get_option($key,null);
$mail=[];$intercept=static function($result,$args)use(&$mail){$mail[]=$args;return true;};add_filter('pre_wp_mail',$intercept,PHP_INT_MAX,2);
$ref='';
try {
    update_option('mbs_spaces',['Access Test Area'=>['rate_hourly'=>0,'rate_daily'=>0,'capacity'=>10,'parent'=>null]]);
    update_option('mbs_campsite_mode',0);update_option('mbs_payments_enabled',0);update_option('mbs_min_notice_days',0);update_option('mbs_min_duration_hours',0);
    access_ok(!is_wp_error(MBS_Access_Details::save_settings(['enabled'=>true,'code'=>'INTEGRATION-FAKE','hours_before'=>24])),'Configure synthetic access settings');
    $created=MBS_Bookings::create(['name'=>'Synthetic Access Test','email'=>'access@example.invalid','space'=>'Access Test Area','booking_date'=>wp_date('Y-m-d',time()+40*DAY_IN_SECONDS),'start_time'=>'18:00','end_time'=>'21:00','purpose'=>'Isolated integration test','attendees'=>1,'all_day'=>false,'scout_use'=>false,'kitchen'=>false],true);
    access_ok(!is_wp_error($created),is_wp_error($created)?$created->get_error_message():'Create fixture');$ref=$created['ref'];
    $wpdb->update($wpdb->prefix.MBS_TABLE,['status'=>'confirmed'],['ref'=>$ref]);$booking=MBS_Bookings::get($ref);
    access_ok(MBS_Access_Details::queue($booking)===true,'Queue approved free booking');MBS_Access_Details::queue($booking);
    $table=$wpdb->prefix.'mathlin_email_queue';
    access_ok((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE reference_id=%d AND message_type='access_details'",$booking->id))===1,'Automatic duplicate checks create one durable row');
    access_ok(MBS_Access_Details::resend($booking)===true,'Explicit manual queue');
    $id=(int)$wpdb->get_var($wpdb->prepare("SELECT MAX(id) FROM $table WHERE reference_id=%d AND message_type='access_manual'",$booking->id));
    $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id));
    access_ok(strpos($row->body,'INTEGRATION-FAKE')===false,'No secret in durable placeholder');
    update_option('mbs_payments_enabled',1);$result=MBS_Email_Queue::dispatch_message($id);
    $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id));
    access_ok($row->status==='pending' && (int)$row->attempts===0 && !$mail,'Payment switch pauses delivery without using retries');
    update_option('mbs_payments_enabled',0);update_option('mbs_access_code','ROTATED-INTEGRATION-FAKE');
    $wpdb->update($table,['next_retry'=>current_time('mysql')],['id'=>$id]);$result=MBS_Email_Queue::dispatch_message($id);
    access_ok($result['sent']===1 && count($mail)===1 && strpos($mail[0]['message'],'ROTATED-INTEGRATION-FAKE')!==false,'Actual worker renders current code and submits once');
    access_ok((bool)MBS_Bookings::get($ref)->access_sent,'Sent flag only after acceptance');
    MBS_Email_Queue::dispatch_message($id);access_ok(count($mail)===1,'Accepted message is not resent by another dispatch');
    $wpdb->update($wpdb->prefix.MBS_TABLE,['status'=>'cancelled'],['ref'=>$ref]);
    $automatic_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE reference_id=%d AND message_type='access_details'",$booking->id));
    MBS_Email_Queue::dispatch_message($automatic_id);access_ok(count($mail)===1,'Cancelled source blocks queued automatic email');
    access_ok($wpdb->get_var($wpdb->prepare("SELECT status FROM $table WHERE id=%d",$automatic_id))==='failed','Inactive queued message terminally blocked');
    echo "ACCESS_DETAILS_INTEGRATION_OK\n";
} finally {
    remove_filter('pre_wp_mail',$intercept,PHP_INT_MAX);
    if($ref)$wpdb->delete($wpdb->prefix.MBS_TABLE,['ref'=>$ref]);
    foreach($original as $key=>$value){if($value===null)delete_option($key);else update_option($key,$value);}
}
