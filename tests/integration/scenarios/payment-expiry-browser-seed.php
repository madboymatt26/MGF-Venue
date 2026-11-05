<?php
// Synthetic localhost only; never run against a customer site.
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local' || strpos(home_url(), '127.0.0.1:8879') === false) throw new RuntimeException('Local browser fixture only');
global $wpdb;
wp_set_current_user(1);
wp_set_password('Synthetic-expiry-browser-2026!',1);
update_option('timezone_string','Europe/London');
foreach (array('mbs_payment_expiry_enabled'=>1,'mbs_campsite_mode'=>0,'mbs_payments_enabled'=>1,'mbs_invoices_enabled'=>1,'mbs_deposit_enabled'=>1,'mbs_deposit_percentage'=>25,'mbs_ha_webhook_url'=>'') as $k=>$v) update_option($k,$v);
$spaces=get_option('mbs_spaces',array()); $spaces['Expiry test hall']=array('rate_hourly'=>15,'rate_daily'=>150,'capacity'=>100,'parent'=>null); update_option('mbs_spaces',$spaces);
foreach(array('BROW-NORMAL'=>40,'BROW-SHORT'=>2,'BROW-PAST'=>0,'BROW-HELD'=>45) as $ref=>$days){
 foreach ((array)MBS_Payment_Expiry::orders($ref) as $old) if (is_object($old) && method_exists($old,'delete')) $old->delete(true);
 $wpdb->delete(MBS_Payment_Expiry::table(),array('booking_ref'=>$ref));
 $wpdb->delete($wpdb->prefix.MBS_INVOICE_DOCUMENTS_TABLE,array('booking_ref'=>$ref));
 $wpdb->delete($wpdb->prefix.MBS_TABLE,array('ref'=>$ref));
 $b=(object)array('ref'=>$ref,'status'=>'pending','name'=>'Synthetic browser fixture','email'=>'fixture@example.invalid','phone'=>'','address'=>'','space'=>'Expiry test hall','booking_date'=>wp_date('Y-m-d',strtotime('+'.$days.' days')),'booking_date_end'=>wp_date('Y-m-d',strtotime('+'.$days.' days')),'start_time'=>'10:00:00','end_time'=>'12:00:00','attendees'=>1,'purpose'=>'Synthetic browser acceptance','amount'=>'30.00','amount_paid'=>'0.00','scout_use'=>0,'pricing_tier'=>'standard');
 $b->all_day=0; $b->kitchen=0;
 if(!$wpdb->insert($wpdb->prefix.MBS_TABLE,array_merge((array)$b,MBS_Bookings::with_pricing_evidence($b,array())))) throw new RuntimeException($wpdb->last_error);
 if($ref==='BROW-HELD'){
  $result=MBS_Invoice_Document_Service::confirm_and_issue($ref,array(),true); if(is_wp_error($result))throw new RuntimeException($result->get_error_message());
  $wpdb->update($wpdb->prefix.MBS_TABLE,array('amount_paid'=>'7.50','deposit_paid'=>'7.50','status'=>'deposit_paid'),array('ref'=>$ref));
  $order=wc_create_order(); $item=new WC_Order_Item_Product(); $item->set_name('Synthetic browser deposit');$item->set_total('7.50');$item->add_meta_data('_mbs_booking_ref',$ref);$order->add_item($item);$order->set_total('7.50');$order->set_currency('GBP');$order->update_meta_data('_mbs_booking_ref',$ref);$order->update_meta_data('_mbs_payment_processed','yes');$order->set_status('completed');$order->save();
  $p=json_decode(MBS_Payment_Expiry::get($ref)->policy_json,true);$result=MBS_Payment_Expiry::check($ref,0,$p['balance_cutoff_at']);if(is_wp_error($result))throw new RuntimeException($result->get_error_message());
 }
}
echo "BROWSER_FIXTURES_READY\n";
