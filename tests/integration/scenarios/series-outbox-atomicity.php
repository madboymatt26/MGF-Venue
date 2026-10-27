<?php
/** Paid-venue internal and free-campsite profiles; no real mail or HTTP. */
if(!defined('WP_CLI')||!WP_CLI||!preg_match('/127\.0\.0\.1|localhost|wordpress/',home_url()))throw new RuntimeException('Disposable fixture required.');
global $wpdb;$bookings=$wpdb->prefix.MBS_TABLE;$series_table=$wpdb->prefix.MBS_SERIES_TABLE;$queue=$wpdb->prefix.'mathlin_email_queue';
$saved=array();foreach(array('mbs_spaces','mbs_campsite_mode','mbs_payments_enabled','mbs_ha_webhook_url','mbs_min_duration_hours')as$opt)$saved[$opt]=get_option($opt,null);
$refs=array();$series_refs=array();$mail=0;$http=0;$checks=0;$fail_target='';$prior_errors=$wpdb->suppress_errors(true);
$check=static function($ok,$why)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($why);};
$filter=static function($sql)use(&$fail_target,$queue){if($fail_target==='queue'&&strpos($sql,'INSERT INTO')!==false&&strpos($sql,$queue)!==false)return'INVALID SYNTHETIC OUTBOX INSERT';if($fail_target==='ha'&&strpos($sql,'INSERT INTO')!==false&&strpos($sql,'mbs_ha_event_')!==false)return'INVALID SYNTHETIC HA INSERT';return$sql;};
$mail_guard=static function($pre)use(&$mail){$mail++;return true;};$http_guard=static function()use(&$http){$http++;throw new RuntimeException('Unexpected HTTP under a series writer.');};
$user=get_user_by('login','admin');wp_set_current_user($user->ID);
add_filter('pre_wp_mail',$mail_guard,PHP_INT_MAX);add_filter('pre_http_request',$http_guard,PHP_INT_MAX);add_filter('query',$filter,PHP_INT_MAX);
try{
    update_option('mbs_spaces',array('Outbox Test'=>array('rate_hourly'=>15,'rate_daily'=>100,'capacity'=>100)));update_option('mbs_min_duration_hours',0);
    foreach(array('venue_scout','campsite')as$profile){
        update_option('mbs_campsite_mode',$profile==='campsite'?1:0);update_option('mbs_payments_enabled',$profile==='campsite'?0:1);update_option('mbs_ha_webhook_url','https://ha.example.invalid/test');
        $date=$profile==='campsite'?'2098-12-15':'2098-12-01';$end=(new DateTimeImmutable($date))->modify('+7 days')->format('Y-m-d');
        $data=array('name'=>'Synthetic series','organisation'=>'Synthetic Scout Group','email'=>'series-fixture@example.invalid','phone'=>'','address'=>'','space'=>'Outbox Test','booking_date'=>$date,'booking_date_end'=>$date,'all_day'=>true,'scout_use'=>$profile==='venue_scout','start_time'=>'','end_time'=>'','attendees'=>10,'purpose'=>'Synthetic outbox acceptance','campsite_exception'=>true);
        $created=MBS_Bookings::create_recurring($data,$end,true);$check(!is_wp_error($created),'Create '.$profile.' recurring request');
        $ref=$created['series_id'];$refs=array_merge($refs,$created['refs']);$series_refs[]=$ref;$source=MBS_Series::get($ref);
        $fail_target=$profile==='campsite'?'ha':'queue';$result=MBS_Series::approve($ref,'pending',(int)$source->version,true);$fail_target='';
        $check(is_wp_error($result),'Failed intent aborts '.$profile.' approval');
        $after=MBS_Series::get($ref);$check($after->status==='pending'&&(int)$after->version===(int)$source->version,'Series version/state rolled back');
        foreach(MBS_Bookings::get_series($ref)as$b)$check($b->status==='pending','Every occurrence rolled back');
        $result=MBS_Series::approve($ref,'pending',(int)$source->version,true);$check(!is_wp_error($result),'Approval succeeds with durable intents');
        $after=MBS_Series::get($ref);$check(empty($after->confirmation_sent_at),'Queue acceptance does not mark confirmation sent');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$queue} WHERE reference_type='series' AND reference_id=%d AND message_type='series_confirmation' ORDER BY id DESC LIMIT 1",$after->id));$check($row&&$row->status==='pending','Approval email awaits worker');
        $before_mail=$mail;
        if($profile==='venue_scout'){
            MBS_Email_Queue::dispatch_message((int)$row->id);$check($mail===$before_mail+1&&!empty(MBS_Series::get($ref)->confirmation_sent_at),'Worker alone marks transport acceptance');
            $resend=MBS_Series::resend_confirmation($ref);$check(!is_wp_error($resend)&&$resend['queued']&&!$resend['sent'],'Explicit resend reports queued, not delivered');
            $resend_row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$queue} WHERE reference_type='series' AND reference_id=%d ORDER BY id DESC LIMIT 1",$after->id));
            $wpdb->query($wpdb->prepare("UPDATE {$series_table} SET version=version+1 WHERE series_ref=%s",$ref));
            MBS_Email_Queue::dispatch_message((int)$resend_row->id);$check($mail===$before_mail+1&&$wpdb->get_var($wpdb->prepare("SELECT status FROM {$queue} WHERE id=%d",$resend_row->id))==='failed','Later series version supersedes queued approval resend');
        }else{
            $fail_target='queue';$cancel=MBS_Series::cancel($ref,'future','confirmed',(int)$after->version,true);$fail_target='';
            $check(is_wp_error($cancel)&&MBS_Series::get($ref)->status==='confirmed','Cancellation rolls back if customer intent fails');
            foreach(MBS_Bookings::get_series($ref)as$b)$check($b->status==='confirmed','Cancellation failure retains every occurrence');
            $cancel=MBS_Series::cancel($ref,'future','confirmed',(int)$after->version,true);$check(!is_wp_error($cancel),'Campsite cancellation commits with notification');
            MBS_Email_Queue::dispatch_message((int)$row->id);$check($mail===$before_mail,'An approval superseded by cancellation is not sent');
            $notice=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$queue} WHERE reference_type='series' AND reference_id=%d AND message_type='series_state' ORDER BY id DESC LIMIT 1",$after->id));$check((bool)$notice,'Campsite cancellation has a durable state notice');
            MBS_Email_Queue::dispatch_message((int)$notice->id);$check($mail===$before_mail+1,'Cancellation notice accepted by intercepted transport');
        }
    }
    $check($http===0,'All series writers and controlled workers stayed offline');
    echo "SERIES_OUTBOX_ATOMICITY_OK: {$checks} assertions\n";
}finally{
    $fail_target='';remove_filter('query',$filter,PHP_INT_MAX);remove_filter('pre_wp_mail',$mail_guard,PHP_INT_MAX);remove_filter('pre_http_request',$http_guard,PHP_INT_MAX);$wpdb->query('ROLLBACK');
    foreach($series_refs as$ref){$id=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$series_table} WHERE series_ref=%s",$ref));if($id)$wpdb->delete($queue,array('reference_type'=>'series','reference_id'=>$id));$wpdb->delete($bookings,array('series_id'=>$ref));$wpdb->delete($series_table,array('series_ref'=>$ref));}
    foreach($refs as$ref)$wpdb->delete($wpdb->options,array('option_name'=>'mbs_ha_event_'.hash('sha256',$ref)));
    foreach($saved as$opt=>$value){if($value===null)delete_option($opt);else update_option($opt,$value,false);}$wpdb->suppress_errors($prior_errors);wp_cache_flush();
}
