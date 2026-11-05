<?php
if(!defined('WP_CLI')||!WP_CLI||!preg_match('/127\.0\.0\.1|localhost|wordpress/',home_url()))throw new RuntimeException('Disposable fixture required.');
global $wpdb;
$mode=$args[0]??'';$ref='INT-EXPIRY-LATE';$table=$wpdb->prefix.MBS_TABLE;$policies=MBS_Payment_Expiry::table();$barrier=$wpdb->prefix.'mbs_expiry_test_barrier';$queue=$wpdb->prefix.'mathlin_email_queue';
add_filter('pre_wp_mail',static function(){return true;},PHP_INT_MAX,2);
add_filter('pre_http_request',static function(){throw new RuntimeException('No HTTP allowed in expiry race.');},PHP_INT_MAX);
$assert=static function($ok,$message){if(!$ok)throw new RuntimeException($message);};
if($mode==='seed'){
    $saved=array();foreach(array('mbs_payment_expiry_enabled','mbs_payments_enabled','mbs_campsite_mode','mbs_deposit_enabled','mbs_deposit_percentage','mbs_ha_webhook_url','timezone_string')as$key)$saved[$key]=get_option($key,null);
    update_option('mbs_test_expiry_saved_options',$saved,false);
    update_option('mbs_payment_expiry_enabled',1);update_option('mbs_payments_enabled',1);update_option('mbs_campsite_mode',0);update_option('mbs_deposit_enabled',1);update_option('mbs_deposit_percentage',25);update_option('mbs_ha_webhook_url','');update_option('timezone_string','Europe/London');
    $wpdb->query("DROP TABLE IF EXISTS {$barrier}");$assert($wpdb->query("CREATE TABLE {$barrier} (id INT PRIMARY KEY, arrived INT NOT NULL DEFAULT 0) ENGINE=MyISAM")!==false,'Create fixture barrier');$wpdb->insert($barrier,array('id'=>1));
    $wpdb->delete($policies,array('booking_ref'=>$ref));$wpdb->delete($table,array('ref'=>$ref));
    $date=wp_date('Y-m-d',strtotime('+10 days'));
    $assert($wpdb->insert($table,array('ref'=>$ref,'status'=>'confirmed','name'=>'Synthetic race hire','email'=>'fixture@example.invalid','phone'=>'','address'=>'','space'=>'Main Scout Hall','booking_date'=>$date,'booking_date_end'=>$date,'start_time'=>'10:00:00','end_time'=>'12:00:00','attendees'=>1,'purpose'=>'Synthetic expiry race','amount'=>'30.00','amount_paid'=>'0.00','pricing_tier'=>'standard'))===1,'Seed race booking');
    $p=MBS_Payment_Expiry_Policy::approve(time()-49*3600,$date,3000,750,true);
    $assert(!is_wp_error($p),'Seed valid expired deposit policy');
    $assert($wpdb->insert($policies,array('booking_ref'=>$ref,'state'=>'active','policy_json'=>wp_json_encode($p),'review_json'=>'{}','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')))===1,'Seed race policy');
    echo "EXPIRY_RACE_SEEDED\n";return;
}
if(in_array($mode,array('expire','pay'),true)){
    $assert($wpdb->query("UPDATE {$barrier} SET arrived=arrived+1 WHERE id=1")===1,'Race barrier exists');$until=microtime(true)+30;
    do{$arrived=(int)$wpdb->get_var("SELECT arrived FROM {$barrier} WHERE id=1");if($arrived>=2)break;usleep(50000);}while(microtime(true)<$until);
    $assert($arrived>=2,'Both separate-process workers reached barrier');
    if($mode==='expire')$result=MBS_Payment_Expiry::check($ref);
    else $result=MBS_Space_Selection::locked(static function()use($ref){
        $order=wc_create_order();$order->set_currency('GBP');$item=new WC_Order_Item_Product();$item->set_name('Synthetic late deposit');$item->set_total('7.50');$item->add_meta_data('_mbs_booking_ref',$ref);$order->add_item($item);$order->set_total('7.50');$order->set_date_paid(time());$order->update_meta_data('_mbs_booking_ref',$ref);$order->set_status('completed');$order->save();
        (new MBS_Woo_Payment())->on_order_completed($order->get_id());
        return true;
    });
    $assert(!is_wp_error($result),'Race worker completed without losing payment review');echo "EXPIRY_RACE_{$mode}_OK\n";return;
}
if($mode==='assert'){
    try{
        $b=MBS_Bookings::get($ref);$row=MBS_Payment_Expiry::get($ref);$review=$row?json_decode($row->review_json,true):array();
        $assert($b&&$b->status==='cancelled','Late payment must never reopen released slot');
        $assert($row&&$row->state==='money_review'&&$review['received_minor']===750,'Exactly one late receipt is held for review');
        $assert(count($review['late_orders'])===1,'Duplicate gateway callbacks do not count money twice');
        $assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue} WHERE message_key=%s",'expiry_cancel:'.$ref))===1,'Exactly one customer cancellation intent');
        $assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue} WHERE message_key=%s",'expiry_admin:'.$ref))===1,'Exactly one original admin cancellation intent');
        $wpdb->update($table,array('status'=>'archived'),array('ref'=>$ref));
        $assert((bool)array_filter(MBS_Payment_Expiry::needs_attention(100),static function($i)use($ref){return$i['ref']===$ref;}),'Archiving cannot hide unresolved money-held review');
        echo "EXPIRY_RACE_OK: concurrent expiry/capture and duplicate callback retained one cancellation and exact money review.\n";
    }finally{
        $orders=MBS_Payment_Expiry::orders($ref);if(!is_wp_error($orders))foreach($orders as$order)$order->delete(true);
        $b=MBS_Bookings::get($ref);if($b)$wpdb->delete($queue,array('reference_type'=>'booking','reference_id'=>$b->id));$wpdb->delete($policies,array('booking_ref'=>$ref));$wpdb->delete($table,array('ref'=>$ref));$wpdb->delete($wpdb->prefix.'mathlin_audit_log',array('ref'=>$ref));$wpdb->query("DROP TABLE IF EXISTS {$barrier}");
        foreach((array)get_option('mbs_test_expiry_saved_options',array())as$key=>$value){if($value===null)delete_option($key);else update_option($key,$value);}delete_option('mbs_test_expiry_saved_options');
    }
}
