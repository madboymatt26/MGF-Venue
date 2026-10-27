<?php
/** Real SQL failure injection. All transport is intercepted in this disposable fixture. */
if(!defined('WP_CLI')||!WP_CLI||!preg_match('/127\.0\.0\.1|localhost|wordpress/',home_url()))throw new RuntimeException('Disposable fixture required.');
global $wpdb;$table=$wpdb->prefix.MBS_TABLE;$documents=$wpdb->prefix.MBS_INVOICE_DOCUMENTS_TABLE;$queue=$wpdb->prefix.'mathlin_email_queue';
$ref='CHG-'.substr(hash('sha256',wp_generate_uuid4()),0,12);$checks=0;$mail=0;$http=0;$seen=array();
$check=static function($ok,$message)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($message);};
$saved=array();foreach(array('mbs_spaces','mbs_kitchen_price','mbs_campsite_mode','mbs_payments_enabled','mbs_invoices_enabled','mbs_ha_webhook_url')as$opt)$saved[$opt]=get_option($opt,null);
$block_http=static function()use(&$http){$http++;throw new RuntimeException('No network is allowed in booking writers.');};
$capture_mail=static function($pre,$args)use(&$mail,&$seen){$mail++;$seen=$args;return true;};
$fail_target='';$fail=static function($sql)use(&$fail_target,$queue){
    if($fail_target==='ha'&&strpos($sql,'INSERT INTO')!==false&&strpos($sql,'mbs_ha_event_')!==false)return'INVALID SYNTHETIC HA INSERT';
    if($fail_target==='queue'&&strpos($sql,'INSERT INTO')!==false&&strpos($sql,$queue)!==false)return'INVALID SYNTHETIC QUEUE INSERT';
    return$sql;
};
$prior_errors=$wpdb->suppress_errors(true);
try{
    update_option('mbs_spaces',array('Main Hall'=>array('rate_hourly'=>15,'rate_daily'=>100,'capacity'=>100)));update_option('mbs_kitchen_price',20);update_option('mbs_campsite_mode',0);update_option('mbs_payments_enabled',1);update_option('mbs_invoices_enabled',1);update_option('mbs_ha_webhook_url','');
    $booking=(object)array('ref'=>$ref,'status'=>'pending','name'=>'Synthetic Change','email'=>'change@example.invalid','space'=>'Main Hall','booking_date'=>'2098-11-01','booking_date_end'=>'2098-11-01','start_time'=>'10:00:00','end_time'=>'17:00:00','all_day'=>0,'scout_use'=>0,'pricing_tier'=>'standard','kitchen'=>0,'attendees'=>10,'purpose'=>'Synthetic atomic change','amount'=>'105.00','amount_paid'=>'105.00');
    $check($wpdb->insert($table,array_merge((array)$booking,MBS_Bookings::with_pricing_evidence($booking,array())))===1,'Seed synthetic change');
    $issued=MBS_Invoice_Document_Service::confirm_and_issue($ref,array(),false);$check(!is_wp_error($issued),'Create original invoice');
    $original=MBS_Bookings::get($ref);$original_json=$wpdb->get_var($wpdb->prepare("SELECT snapshot_json FROM {$documents} WHERE id=%d",$original->current_invoice_document_id));
    $update=MBS_Bookings::with_pricing_evidence($original,array('kitchen'=>1,'amount'=>'125.00'));
    $options=array('financial_change'=>true,'reissue'=>true,'notify'=>'booking_edit','audit'=>'Synthetic reviewed booking edit');
    update_option('mbs_ha_webhook_url','https://ha.example.invalid/test');
    add_filter('pre_http_request',$block_http,PHP_INT_MAX);add_filter('pre_wp_mail',$capture_mail,PHP_INT_MAX,2);add_filter('query',$fail,PHP_INT_MAX);
    foreach(array('ha','queue')as$target){
        $fail_target=$target;$result=MBS_Booking_Change_Service::apply($original,$update,$options);
        $check(is_wp_error($result),'Failed '.$target.' intent must abort change');
        $after=MBS_Bookings::get($ref);$check($after->amount==='105.00'&&(int)$after->kitchen===0&&(int)$after->current_invoice_document_id===(int)$original->current_invoice_document_id,'Booking and pointer rolled back after '.$target.' failure');
        $check((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$documents} WHERE booking_ref=%s",$ref))===1,'No extra revision survived '.$target.' failure');
        $check((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue} WHERE reference_type='booking' AND reference_id=%d",$original->id))===0,'No customer intent survived '.$target.' failure');
    }
    $fail_target='';$result=MBS_Booking_Change_Service::apply($original,$update,$options);
    $check(!is_wp_error($result),'Reviewed edit commits');
    $check($mail===0&&$http===0,'Writer did not send mail, HTTP or render attachments');
    $fresh=MBS_Bookings::get($ref);$check($fresh->amount==='125.00'&&(int)$fresh->kitchen===1,'Updated booking verified');
    $check($original_json===$wpdb->get_var($wpdb->prepare("SELECT snapshot_json FROM {$documents} WHERE id=%d",$original->current_invoice_document_id)),'Original financial snapshot not overwritten');
    $new=MBS_Issued_Invoice_Snapshot::from_json($wpdb->get_var($wpdb->prepare("SELECT snapshot_json FROM {$documents} WHERE id=%d",$fresh->current_invoice_document_id)));
    $check($new->account_at_issue['outstanding_minor']===2000&&count($new->line_items)===2,'Revised invoice captures precise top-up and components');
    $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$queue} WHERE reference_type='booking' AND reference_id=%d ORDER BY id DESC LIMIT 1",$fresh->id));
    $meta=json_decode($row->attachment_meta,true);$check($meta['document_id']===(int)$fresh->current_invoice_document_id&&$row->attachments==='[]','PDF is a deferred immutable document reference, not a rendered file');
    $stale=MBS_Booking_Change_Service::apply($original,$update,$options);$check(is_wp_error($stale),'Stale admin form cannot overwrite newer edit');
    remove_filter('pre_http_request',$block_http,PHP_INT_MAX);
    MBS_Email_Queue::dispatch_message((int)$row->id);
    $check($mail===1&&strpos($seen['message'],'20.00')!==false,'Worker sent current top-up after commit, through intercepted transport only');
    $check($wpdb->get_var($wpdb->prepare("SELECT status FROM {$queue} WHERE id=%d",$row->id))==='sent','Transport acceptance recorded');
    echo "BOOKING_CHANGE_ATOMICITY_OK: {$checks} assertions\n";
}finally{
    $fail_target='';remove_filter('query',$fail,PHP_INT_MAX);remove_filter('pre_http_request',$block_http,PHP_INT_MAX);remove_filter('pre_wp_mail',$capture_mail,PHP_INT_MAX);$wpdb->query('ROLLBACK');
    $id=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE ref=%s",$ref));if($id)$wpdb->delete($queue,array('reference_type'=>'booking','reference_id'=>$id));$wpdb->delete($documents,array('booking_ref'=>$ref));$wpdb->delete($table,array('ref'=>$ref));$wpdb->delete($wpdb->options,array('option_name'=>'mbs_ha_event_'.hash('sha256',$ref)));
    foreach($saved as$opt=>$value){if($value===null)delete_option($opt);else update_option($opt,$value,false);}$wpdb->suppress_errors($prior_errors);wp_cache_flush();
}
