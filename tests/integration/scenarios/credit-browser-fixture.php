<?php
if ( ! defined('WP_CLI') || ! WP_CLI || ! preg_match('/127\.0\.0\.1|localhost|wordpress/',home_url()) ) throw new RuntimeException('Disposable fixture only');
global $wpdb;
$ref='BROW-CREDIT';
if ( ($args[0] ?? '') === 'cleanup' ) {
    $b=MBS_Bookings::get($ref);
    if($b){$wpdb->delete($wpdb->prefix.'mathlin_email_queue',array('reference_type'=>'booking','reference_id'=>$b->id));$wpdb->delete($wpdb->prefix.MBS_INVOICE_DOCUMENTS_TABLE,array('booking_id'=>$b->id));$wpdb->delete($wpdb->prefix.MBS_TABLE,array('ref'=>$ref));}
    $wpdb->delete(MBS_Credit_Accounts::table(true),array('scope_kind'=>'booking','scope_ref'=>$ref));$wpdb->delete($wpdb->prefix.'mathlin_audit_log',array('ref'=>$ref));
    foreach($wpdb->get_results('SELECT * FROM '.MBS_Credit_Accounts::table())as$a){$g=json_decode($a->agreement_json,true);if(($g['name']??'')==='Synthetic browser credit organisation'){$wpdb->delete(MBS_Credit_Accounts::table(),array('id'=>$a->id));$wpdb->delete($wpdb->prefix.'mathlin_audit_log',array('ref'=>'CREDIT-'.$a->id));}}
    foreach((array)get_option('mbs_credit_browser_restore',array())as$k=>$v){if($v===null)delete_option($k);else update_option($k,$v);}delete_option('mbs_credit_browser_restore');WP_CLI::success('Credit browser fixture restored');return;
}
if(MBS_Bookings::get($ref)||get_option('mbs_credit_browser_restore'))throw new RuntimeException('Fixture already exists; inspect/clean previous run');
$restore=array();foreach(array('mbs_credit_accounts_enabled','mbs_campsite_mode','mbs_payments_enabled')as$k)$restore[$k]=get_option($k,null);update_option('mbs_credit_browser_restore',$restore,false);
update_option('mbs_credit_accounts_enabled',0);update_option('mbs_campsite_mode',0);update_option('mbs_payments_enabled',1);
$data=array('ref'=>$ref,'name'=>'Synthetic browser credit contact','email'=>'browser-credit@example.invalid','space'=>'Main Scout Hall','purpose'=>'Synthetic browser credit acceptance','booking_date'=>wp_date('Y-m-d',strtotime('+30 days')),'booking_date_end'=>wp_date('Y-m-d',strtotime('+30 days')),'start_time'=>'10:00:00','end_time'=>'12:00:00','attendees'=>1,'amount'=>'30.00','amount_paid'=>'0.00','status'=>'pending','pricing_tier'=>'standard','custom_fields'=>wp_json_encode(array('_mbs_credit_requested'=>true)));
if($wpdb->insert($wpdb->prefix.MBS_TABLE,$data)!==1)throw new RuntimeException('Seed failed');
WP_CLI::success('Credit browser fixture seeded');
