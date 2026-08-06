<?php
global $wpdb;
$invoice_ref=isset($args[0])?sanitize_text_field($args[0]):'';$order_id=isset($args[1])?absint($args[1]):0;
$barrier=$wpdb->prefix.'mbs_test_barriers';
if($wpdb->query($wpdb->prepare("UPDATE {$barrier} SET arrived=arrived+1 WHERE barrier_key=%s",$invoice_ref))!==1)exit(31);
$deadline=microtime(true)+30;do{$arrived=(int)$wpdb->get_var($wpdb->prepare("SELECT arrived FROM {$barrier} WHERE barrier_key=%s",$invoice_ref));if($arrived>=2)break;usleep(50000);}while(microtime(true)<$deadline);if($arrived<2)exit(31);
if($wpdb->query($wpdb->prepare("UPDATE {$barrier} SET critical_arrived=critical_arrived+1 WHERE barrier_key=%s",$invoice_ref))!==1)exit(32);
$deadline=microtime(true)+30;do{$inside=(int)$wpdb->get_var($wpdb->prepare("SELECT critical_arrived FROM {$barrier} WHERE barrier_key=%s",$invoice_ref));if($inside>=2)break;usleep(50000);}while(microtime(true)<$deadline);if($inside<2)exit(32);
$invoice=MBS_Billing_Ledger::get_invoice($invoice_ref);$now=gmdate('Y-m-d H:i:s');
$inserted=$wpdb->insert($wpdb->prefix.MBS_PAYMENT_RESERVATION_TABLE,array('reservation_ref'=>'UNSAFE-'.$order_id,'invoice_id'=>(int)$invoice->id,'invoice_ref'=>$invoice_ref,'order_id'=>$order_id,'amount_minor'=>1000,'status'=>'bound','version'=>1,'balance_version'=>(int)$invoice->version,'expires_at'=>null,'created_at'=>$now,'updated_at'=>$now));
if($inserted===false){fwrite(STDERR,$wpdb->last_error."\n");exit(33);}echo "unsafe_guard_bypass_inserted\n";
