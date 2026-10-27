<?php
if(!defined('WP_CLI')||!WP_CLI||!preg_match('/127\.0\.0\.1|localhost|wordpress/',home_url()))throw new RuntimeException('Disposable runtime required.');
global $wpdb;$bookings=$wpdb->prefix.MBS_TABLE;$requests=$wpdb->prefix.'mathlin_mod_requests';$queue=$wpdb->prefix.'mathlin_email_queue';$ids=array();$ref='';$fail='';$checks=0;$mail=0;$prior=$wpdb->suppress_errors(true);
$assert=static function($ok,$why)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($why);};
$filter=static function($sql)use(&$fail,$queue){if($fail==='queue'&&strpos($sql,'INSERT INTO')!==false&&strpos($sql,$queue)!==false)return'INVALID SYNTHETIC QUEUE INSERT';if($fail==='audit'&&strpos($sql,'INSERT INTO')!==false&&strpos($sql,'mathlin_audit_log')!==false)return'INVALID SYNTHETIC AUDIT INSERT';return$sql;};
$mail_guard=static function()use(&$mail){$mail++;return true;};
add_filter('query',$filter,PHP_INT_MAX);add_filter('pre_wp_mail',$mail_guard,PHP_INT_MAX);
try{
    $space=array_key_first(MBS_Bookings::get_spaces());
    $created=MBS_Bookings::create(array('name'=>'Synthetic rejection','email'=>'rejection@example.invalid','space'=>$space,'booking_date'=>'2098-09-17','booking_date_end'=>'2098-09-17','start_time'=>'10:00','end_time'=>'12:00','scout_use'=>true,'all_day'=>false,'attendees'=>1,'purpose'=>'Synthetic rejection test'),true);
    $assert(!is_wp_error($created),'Create fixture booking: '.(is_wp_error($created)?$created->get_error_message():''));$ref=$created['ref'];
    for($i=0;$i<2;$i++){
        $assert($wpdb->insert($requests,array('booking_ref'=>$ref,'request_type'=>'cancel','status'=>'pending','requested_data'=>'{}','notes'=>''))===1,'Create pending request');$id=(int)$wpdb->insert_id;$ids[]=$id;
        if($i===0){foreach(array('audit','queue')as$failure){$fail=$failure;$r=MBS_Modification::reject($id,'Synthetic decline');$fail='';$assert($r===false&&MBS_Modification::get_request($id)->status==='pending','Failed '.$failure.' rolls back decision');}}
        $r=MBS_Modification::reject($id,'Synthetic decline');$assert($r===true&&MBS_Modification::get_request($id)->status==='rejected','Decision committed');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$queue} WHERE message_type='request_rejected' AND reference_id=%d ORDER BY id DESC LIMIT 1",$id));$assert($row&&$row->status==='pending','Notification queued, not sent under lock');
        $assert(MBS_Modification::reject($id,'Repeated')===false,'Duplicate rejection does not notify twice');
        if($i===1)$wpdb->update($bookings,array('email'=>'changed@example.invalid'),array('ref'=>$ref));
        MBS_Email_Queue::dispatch_message((int)$row->id);
        $assert($mail===1,$i===0?'Worker sends the committed rejection':'Changed recipient suppresses obsolete rejection');
    }
    echo "REQUEST_REJECTION_ATOMICITY_OK: {$checks} assertions\n";
}finally{$fail='';remove_filter('query',$filter,PHP_INT_MAX);remove_filter('pre_wp_mail',$mail_guard,PHP_INT_MAX);$wpdb->query('ROLLBACK');foreach($ids as$id){$wpdb->delete($requests,array('id'=>$id));$wpdb->delete($queue,array('message_type'=>'request_rejected','reference_id'=>$id));}if($ref){$wpdb->delete($bookings,array('ref'=>$ref));$wpdb->delete($wpdb->prefix.'mathlin_audit_log',array('ref'=>$ref));}$wpdb->suppress_errors($prior);}
