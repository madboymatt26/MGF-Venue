<?php
if (!class_exists('MBS_Test_Gateway') || get_bloginfo('name')!=='MGF Integration') throw new RuntimeException('Disposable integration stack only.');
global $wpdb;
wp_set_current_user(1);add_filter('pre_wp_mail','__return_true');
$ref=$args[0]??'';$mode=$args[1]??'';
$invoice=MBS_Billing_Ledger::get_invoice($ref);
if(!MBS_Additional_Charges::is_charge($invoice))throw new RuntimeException('Expected synthetic additional charge.');
if($mode==='assert'){
    $claim=MBS_Invoice_Reservation::get($ref);
    $paid=(int)$invoice->paid_minor===1000;
    $reserved=$claim&&$claim->status==='active';
    if($paid===$reserved)throw new RuntimeException('Expected exactly one of offline settlement or active checkout.');
    if(!$paid&&(int)$invoice->paid_minor!==0)throw new RuntimeException('Unexpected partial settlement.');
    echo "OK: additional-charge checkout/offline settlement race has one winner.\n";return;
}
$barrier=$wpdb->prefix.'mbs_test_barriers';
if($wpdb->query($wpdb->prepare("UPDATE {$barrier} SET arrived=arrived+1 WHERE barrier_key=%s",$ref))!==1)throw new RuntimeException('Missing barrier.');
$deadline=microtime(true)+30;
while((int)$wpdb->get_var($wpdb->prepare("SELECT arrived FROM {$barrier} WHERE barrier_key=%s",$ref))<2){if(microtime(true)>$deadline)throw new RuntimeException('Race barrier timed out.');usleep(50000);}
$result=$mode==='checkout'?MBS_Invoice_Reservation::acquire($invoice):MBS_Invoice_Payment::record_manual_payment($ref,1000,'race-offline-'.$ref,(int)$invoice->version,'Synthetic race');
if(is_wp_error($result)){fwrite(STDERR,$result->get_error_code()."\n");exit(17);}
echo "OK: {$mode} won.\n";
