<?php
/** Disposable WordPress only: invoice evidence + atomic HA rollback, no delivery. */
if(!defined('ABSPATH')||!class_exists('MBS_HomeAssistant'))throw new RuntimeException('Disposable WordPress required.');
global $wpdb;
$table=$wpdb->prefix.MBS_TABLE;$docs=$wpdb->prefix.MBS_INVOICE_DOCUMENTS_TABLE;
$ref='LAY-'.substr(hash('sha256',wp_generate_uuid4()),0,12);$key='mbs_ha_event_'.hash('sha256',$ref);$checks=0;
$check=static function($ok,$message)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($message);};
$saved=array();foreach(array('mbs_spaces','mbs_kitchen_price','mbs_campsite_mode','mbs_payments_enabled','mbs_ha_webhook_url')as$opt)$saved[$opt]=get_option($opt,null);
$filter=static function($sql){return strpos($sql,'INSERT INTO')!==false&&strpos($sql,'mbs_ha_event_')!==false?'INVALID SYNTHETIC HA INSERT':$sql;};
$prior_errors=$wpdb->suppress_errors(true);
try{
    update_option('mbs_spaces',array('Main Hall'=>array('rate_hourly'=>15,'rate_daily'=>100,'capacity'=>100)));update_option('mbs_kitchen_price',20);update_option('mbs_campsite_mode',0);update_option('mbs_payments_enabled',1);update_option('mbs_ha_webhook_url','https://ha.example.invalid/test');
    $b=(object)array('ref'=>$ref,'status'=>'pending','name'=>'Synthetic Hirer','email'=>'fixture@example.invalid','space'=>'Main Hall','booking_date'=>'2098-10-31','booking_date_end'=>'2098-10-31','start_time'=>'10:00:00','end_time'=>'17:00:00','all_day'=>0,'scout_use'=>0,'pricing_tier'=>'standard','kitchen'=>1,'attendees'=>10,'purpose'=>'Synthetic invoice layout','amount'=>'125.00','amount_paid'=>'105.00');
    $data=array_merge((array)$b,MBS_Bookings::with_pricing_evidence($b,array()));
    $check($wpdb->insert($table,$data)===1,'Fixture inserted');
    add_filter('query',$filter,PHP_INT_MAX);
    $failed=MBS_Invoice_Document_Service::confirm_and_issue($ref,array(),false);
    remove_filter('query',$filter,PHP_INT_MAX);
    $check(is_wp_error($failed)&&$failed->get_error_code()==='ha_intent_failed','HA failure aborts approval');
    $check(MBS_Bookings::get($ref)->status==='pending','Booking rolled back');
    $check((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$docs} WHERE booking_ref=%s",$ref))===0,'No issued invoice escaped rollback');
    $check($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key))===null,'No HA intent escaped rollback');
    $result=MBS_Invoice_Document_Service::confirm_and_issue($ref,array(),false);
    $check(!is_wp_error($result),'Confirmation commits with durable intent');
    $stored=$wpdb->get_var($wpdb->prepare("SELECT snapshot_json FROM {$docs} WHERE id=%d",$result['document_id']));
    $snapshot=MBS_Issued_Invoice_Snapshot::from_json($stored);
    $check(count($snapshot->line_items)===2&&$snapshot->line_items[0]['amount_minor']===10500,'Immutable hourly and kitchen breakdown');
    $check($snapshot->account_at_issue['payments_received_minor']===10500&&$snapshot->account_at_issue['outstanding_minor']===2000,'Only top-up outstanding at issue');
    $intent=maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key)));
    $check(is_array($intent)&&$intent['state']==='pending','HA intent committed without HTTP');
    $before=$stored;$html=MBS_HTML_Renderer::render(MBS_Invoice_Document_View_Model::issued($snapshot));
    $check(strpos($html,'Outstanding at issue')!==false&&strpos($html,'£20.00')!==false,'Revised invoice top-up rendered without altering the configured deadline');
    $check($before===$wpdb->get_var($wpdb->prepare("SELECT snapshot_json FROM {$docs} WHERE id=%d",$result['document_id'])),'Rendering never rewrites issued snapshot');
    echo "INVOICE_LAYOUT_RELIABILITY_OK: {$checks} real database assertions\n";
}finally{
    remove_filter('query',$filter,PHP_INT_MAX);$wpdb->query('ROLLBACK');
    $wpdb->delete($docs,array('booking_ref'=>$ref));$wpdb->delete($table,array('ref'=>$ref));$wpdb->delete($wpdb->options,array('option_name'=>$key));
    foreach($saved as$opt=>$value){if($value===null)delete_option($opt);else update_option($opt,$value,false);}
    $wpdb->suppress_errors($prior_errors);wp_cache_flush();
}
