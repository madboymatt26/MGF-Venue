<?php
/** Real WordPress/DB policy tests. Disposable harness ONLY; all transport intercepted. */
if (!defined('WP_CLI') || !WP_CLI || !preg_match('/localhost|127\.0\.0\.1|wordpress/',home_url())) throw new RuntimeException('Disposable integration host required.');
function hardening_ok($v,$m){if(!$v)throw new RuntimeException($m);}
global $wpdb;
$original_user=get_current_user_id();
$keys=array('mbs_spaces','mbs_campsite_mode','mbs_payments_enabled','mbs_multi_space_enabled','mbs_min_notice_days','mbs_min_duration_hours','mbs_scout_volunteer_emails','mbs_auto_archive_days');
$before=array();foreach($keys as$key)$before[$key]=get_option($key,null);
$refs=array();$users=array();$queue_ids=array();$proof_options=array();
$transport=static function(){throw new RuntimeException('No transport is allowed during these hardening tests.');};
add_filter('pre_wp_mail',$transport,PHP_INT_MAX);
$sql_contract=static function($fn,$message){if($fn==='wpdb::prepare')throw new RuntimeException(strip_tags($message));};
add_action('doing_it_wrong_run',$sql_contract,10,2);
try{
    $admin=get_user_by('login','admin');wp_set_current_user($admin->ID);
    update_option('mbs_spaces',array('Small'=>array('capacity'=>15,'rate_hourly'=>10,'rate_daily'=>100,'parent'=>'Whole Centre'),'Hall'=>array('capacity'=>100,'rate_hourly'=>15,'rate_daily'=>150,'parent'=>'Whole Centre'),'Whole Centre'=>array('capacity'=>115,'rate_hourly'=>20,'rate_daily'=>200),'Area A'=>array('capacity'=>100,'rate_hourly'=>0,'rate_daily'=>0,'parent'=>'Whole Park'),'Area B'=>array('capacity'=>100,'rate_hourly'=>0,'rate_daily'=>0,'parent'=>'Whole Park'),'Whole Park'=>array('capacity'=>1000,'rate_hourly'=>0,'rate_daily'=>0)));
    update_option('mbs_campsite_mode',0);update_option('mbs_payments_enabled',1);update_option('mbs_min_notice_days',0);update_option('mbs_min_duration_hours',0);
    $day=wp_date('Y-m-d',time()+60*DAY_IN_SECONDS);
    $data=array('name'=>'Synthetic hardening test','email'=>'hardening-owner@example.invalid','space'=>'Small','booking_date'=>$day,'start_time'=>'18:00','end_time'=>'20:00','attendees'=>15,'purpose'=>'Isolated security acceptance','all_day'=>false);
    $too_many=MBS_Bookings::create(array_merge($data,array('attendees'=>16)),true);
    hardening_ok(is_wp_error($too_many),'Paid capacity must be enforced in core creation.');
    $a=MBS_Bookings::create($data,true);hardening_ok(!is_wp_error($a),'Create paid fixture.');$refs[]=$a['ref'];
    $wpdb->update($wpdb->prefix.MBS_TABLE,array('status'=>'cancelled'),array('ref'=>$a['ref']));
    $b=MBS_Bookings::create($data,true);hardening_ok(!is_wp_error($b),'Create replacement occupancy.');$refs[]=$b['ref'];
    hardening_ok(MBS_Bookings::update_status($a['ref'],'confirmed',false)===false,'Restore cannot overlap replacement booking in paid mode.');
    $wpdb->update($wpdb->prefix.MBS_TABLE,array('status'=>'cancelled'),array('ref'=>$b['ref']));
    $saved_errors=$wpdb->suppress_errors(true);
    $inject=static function($sql)use($wpdb){return strpos($sql,'SELECT * FROM '.$wpdb->prefix.MBS_TABLE.' WHERE space =')===0?'SELECT * FROM deliberately_missing_hardening_fixture_table':$sql;};
    add_filter('query',$inject,PHP_INT_MAX);
    try{hardening_ok(count(MBS_Bookings::check_conflicts('Small',$day,'18:00','20:00',false))>0,'Failed availability read must not mean available.');}finally{remove_filter('query',$inject,PHP_INT_MAX);$wpdb->suppress_errors($saved_errors);}
    $reader=wp_insert_user(array('user_login'=>'hardening-reader-'.wp_generate_password(6,false),'user_email'=>'hardening-reader@example.invalid','user_pass'=>wp_generate_password(32),'role'=>'mbs_booking_reader'));hardening_ok(!is_wp_error($reader),'Create reader.');$users[]=$reader;wp_set_current_user($reader);
    $api=new MBS_Rest_API();$read=new WP_REST_Request('GET','/mathlin/v1/admin/bookings');$write=new WP_REST_Request('POST','/mathlin/v1/admin/bookings/create');
    hardening_ok($api->booking_manager_permission($read),'Dedicated reader can list bookings.');hardening_ok(!$api->booking_manager_permission($write),'Reader cannot write.');
    hardening_ok(!$api->booking_manager_permission(new WP_REST_Request('GET','/mathlin/v1/admin/invoices/INV-TEST/payment-url')),'Reader cannot mint payment links.');
    $owner=wp_insert_user(array('user_login'=>'hardening-owner-'.wp_generate_password(6,false),'user_email'=>$data['email'],'user_pass'=>wp_generate_password(32),'role'=>'mbs_hirer'));hardening_ok(!is_wp_error($owner),'Create hirer.');$users[]=$owner;wp_set_current_user($owner);
    hardening_ok(!MBS_Hirer_Identity::verified(),'Existing unproved role does not imply mailbox ownership.');
    $calendar_request=new WP_REST_Request('GET','/mathlin/v1/bookings/'.$a['ref'].'/ical');$calendar_request->set_param('ref',$a['ref']);
    hardening_ok(is_wp_error($api->get_ical($calendar_request)),'Reference alone cannot download a detailed calendar.');
    (new MBS_Hirer_Portal())->link_existing_bookings($owner);
    hardening_ok((int)MBS_Bookings::get($a['ref'])->user_id!==$owner,'Unverified account cannot claim existing bookings.');
    update_user_meta($owner,'mbs_verified_email',hash('sha256',strtolower($data['email'])));
    hardening_ok(MBS_Hirer_Identity::booking_access(MBS_Bookings::get($a['ref'])),'Proved owner can access own booking.');
    hardening_ok(!MBS_Hirer_Identity::booking_access((object)array('ref'=>'MBS-OTHER','email'=>'other@example.invalid','modification_token'=>'synthetic')),'Proved owner cannot access another email.');
    $old_queue=(array)$wpdb->get_col('SELECT id FROM '.$wpdb->prefix.'mathlin_email_queue');
    $old_proofs=(array)$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like('mbs_mailproof_').'%'));
    hardening_ok(MBS_Hirer_Identity::send_link($data['email'],0,MBS_Bookings::get($a['ref'])),'Queue secure guest link without sending.');
    $queue_ids=array_diff((array)$wpdb->get_col('SELECT id FROM '.$wpdb->prefix.'mathlin_email_queue'),$old_queue);
    $proof_options=array_diff((array)$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like('mbs_mailproof_').'%')),$old_proofs);
    hardening_ok(count($proof_options)===1,'One one-use mailbox proof persisted.');
    update_option('mbs_scout_volunteer_emails','listed-volunteer@example.invalid');wp_set_current_user(0);
    $forged=MBS_Bookings::create(array_merge($data,array('email'=>'listed-volunteer@example.invalid','scout_use'=>1,'booking_date'=>wp_date('Y-m-d',time()+61*DAY_IN_SECONDS))),false);
    hardening_ok(!is_wp_error($forged),'Ordinary forged-flag request remains ordinary, not a free privilege.');$refs[]=$forged['ref'];$f=MBS_Bookings::get($forged['ref']);hardening_ok(!$f->scout_use&&(float)$f->amount>0,'Typed volunteer email cannot grant free internal pricing.');
    wp_set_current_user($admin->ID);update_option('mbs_campsite_mode',1);update_option('mbs_payments_enabled',0);update_option('mbs_multi_space_enabled',1);
    $park=MBS_Bookings::create(array_merge($data,array('space'=>'Whole Park','attendees'=>1000,'all_day'=>1,'campsite_exception'=>1)),true);hardening_ok(!is_wp_error($park),'Free Whole Park permits configured 1000 capacity.');$refs[]=$park['ref'];hardening_ok((float)MBS_Bookings::get($park['ref'])->amount===0.0,'Free mode remains free.');
    $conflict=MBS_Bookings::create(array_merge($data,array('space'=>'Area A','attendees'=>10,'all_day'=>1,'campsite_exception'=>1)),true);hardening_ok(is_wp_error($conflict),'Whole Park still excludes child occupancy.');
    echo "CROSS_SITE_HARDENING_RUNTIME_OK\n";
}finally{
    remove_action('doing_it_wrong_run',$sql_contract,10);
    remove_filter('pre_wp_mail',$transport,PHP_INT_MAX);wp_set_current_user($original_user);
    foreach($refs as$ref){$wpdb->delete($wpdb->prefix.MBS_TABLE,array('ref'=>$ref));$wpdb->delete($wpdb->prefix.'mathlin_audit_log',array('ref'=>$ref));}
    foreach($queue_ids as$id)$wpdb->delete($wpdb->prefix.'mathlin_email_queue',array('id'=>$id));
    foreach($proof_options as$key)delete_option($key);
    require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as$id)wp_delete_user($id);
    foreach($before as$key=>$value){if($value===null)delete_option($key);else update_option($key,$value,false);}
}
