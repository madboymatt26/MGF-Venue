<?php
/** Disposable WordPress/MariaDB acceptance. Every domain write is rolled back. */
if(!defined('ABSPATH')||!class_exists('MBS_Booking_Intervals'))throw new RuntimeException('Load the review build in disposable WordPress.');
global $wpdb;$table=$wpdb->prefix.MBS_TABLE;$checks=0;
$assert=static function($ok,$label)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($label);};
$wpdb->query('START TRANSACTION');
try{
    update_option('timezone_string','Europe/London');update_option('mbs_campsite_mode',0);update_option('mbs_buffers_enabled',0);
    $email='review-'.substr(wp_generate_uuid4(),0,8).'@example.invalid';$refs=array();
    for($i=0;$i<250;$i++){
        $ref='REV-'.substr(hash('sha256',$email.'|'.$i),0,12);$refs[]=$ref;
        $ok=$wpdb->insert($table,array('ref'=>$ref,'status'=>'paid','name'=>'Synthetic review','email'=>$email,'space'=>'Main Hall','booking_date'=>'2027-07-10','booking_date_end'=>'2027-07-11','start_time'=>'19:00:00','end_time'=>'21:00:00','all_day'=>0,'scout_use'=>0,'attendees'=>10,'purpose'=>'Synthetic private review','amount'=>0));
        $assert($ok===1,'Synthetic booking inserted');
    }
    $export=array();for($page=1;$page<=3;$page++){$result=MBS_Bookings::gdpr_export_personal_data($email,$page);$assert($result['done']===($page===3),'Correct privacy completion');foreach($result['data']as$item)$export[]=$item['item_id'];}
    $assert(count($export)===250&&count(array_unique($export))===250,'Complete real database privacy export');
    $b=MBS_Bookings::get($refs[0]);$ics=MBS_ICal::generate($b);
    $assert(strpos($ics,'DTSTART:20270710T180000Z')!==false&&substr_count($ics,'BEGIN:VEVENT')===2,'Real venue export preserves local summer daily sessions');
    $assert(count(array_filter(MBS_Booking_Intervals::approved('2027-07-11'),static function($row)use($email){return$row->email===$email;}))===250,'All approved database pages returned');
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like('mbs_ha_event_').'%'));
    update_option('mbs_ha_cursor',0);update_option('mbs_ha_webhook_url','https://ha.example.invalid/review');
    $code=500;$calls=0;
    $transport=static function($pre,$args,$url)use(&$code,&$calls){$calls++;return array('headers'=>array(),'body'=>'{}','response'=>array('code'=>$code,'message'=>'Synthetic'),'cookies'=>array());};
    add_filter('pre_http_request',$transport,PHP_INT_MAX,3);
    $assert(MBS_HomeAssistant::notify($b)===true&&$calls===0,'Booking writer never sends HTTP');
    MBS_HomeAssistant::process();$key='mbs_ha_event_'.hash('sha256',$b->ref);$intent=get_option($key);
    $assert($intent['attempts']===1&&(int)MBS_Bookings::get($b->ref)->ha_notified===0,'HTTP500 retained for retry without sent flag');
    $intent['next']=0;update_option($key,$intent,false);$code=200;MBS_HomeAssistant::process();
    $assert(get_option($key)===false&&(int)MBS_Bookings::get($b->ref)->ha_notified===1,'Successful exact intent accepted');
    remove_filter('pre_http_request',$transport,PHP_INT_MAX);
    for($i=0;$i<7;$i++)$assert(MBS_Hirer_Identity::throttle('review',$email,3)===($i<3),'Real conditional SQL throttle cap');
    require_once MBS_PLUGIN_DIR.'includes/class-usage-report.php';
    $spaces=array('Whole Park'=>array('parent'=>null),'A'=>array('parent'=>'Whole Park'),'B'=>array('parent'=>'Whole Park'));
    update_option('mbs_spaces',$spaces);update_option('mbs_campsite_mode',1);
    $whole=clone$b;$whole->space='Whole Park';$whole->all_day=1;$whole->booking_date_end='2027-07-12';
    $usage=MBS_Usage_Report::calculate(array($whole),$spaces,'2027-07-10','2027-07-12');
    $assert($usage['rows'][1]['resource_days']===3&&$usage['rows'][1]['hours']===72.0,'Campsite three-day parent occupancy correct');
    $wpdb->update($table,array('space'=>'A','booking_date_end'=>'2027-07-10','start_time'=>'10:00:00','end_time'=>'11:00:00'),array('ref'=>$refs[0]));
    update_option('mbs_resource_buffers',array('A'=>array('before'=>15,'after'=>30)));
    $assert(!MBS_Bookings::check_conflicts('A','2027-07-10','11:00','12:00'),'Disabled buffers preserve exact adjoining times');
    update_option('mbs_buffers_enabled',1);
    $assert(count(MBS_Bookings::check_conflicts('A','2027-07-10','11:00','12:00'))===1,'Enabled buffer occupies adjoining time');
    $assert(!MBS_Bookings::check_conflicts('B','2027-07-10','11:00','12:00'),'Buffer does not occupy sibling area');
    $assert(count(MBS_Bookings::check_conflicts('Whole Park','2027-07-10','11:00','12:00'))===1,'Whole park still conflicts with child buffer');
    $assert(!MBS_Bookings::check_conflicts('A','2027-07-10','11:45','12:00'),'Exclusive buffered endpoint permits adjacent allocation');
    $assert(!MBS_Bookings::check_conflicts('A','2027-07-10','10:00','11:00',false,$refs[0]),'Edit excludes original booking');
    $attention=MBS_Attention::summary(2);
    $assert($attention['read_only']===true&&!$attention['partial_failures'],'Attention summary queries real schema without errors');
    $health=MBS_Integration_Health::summary();
    $assert($health['home_assistant']['available']===true,'Durable integration health is readable');
    $intent=array('ref'=>$b->ref,'version'=>'review-version','at'=>time(),'next'=>0,'attempts'=>5,'state'=>'failed');
    update_option($key,$intent,false);$before_calls=$calls;
    $assert(is_wp_error(MBS_HomeAssistant::retry_failed($b->ref,'stale-version')),'Stale HA retry rejected');
    $assert(MBS_HomeAssistant::retry_failed($b->ref,'review-version')===true&&get_option($key)['attempts']===0&&$calls===$before_calls,'Reviewed retry queues without immediate HTTP');
    update_option('mbs_ha_webhook_url','');MBS_HomeAssistant::process();
    $assert(get_option($key)['state']==='pending','Disabled endpoint preserves pending intent');
    echo "REVIEW_RELIABILITY_WP_OK: {$checks} assertions; domain mutations rolled back\n";
}finally{$wpdb->query('ROLLBACK');wp_cache_flush();}
