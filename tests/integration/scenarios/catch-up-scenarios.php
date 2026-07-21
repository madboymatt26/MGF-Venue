<?php
global $wpdb;
$series_table=$wpdb->prefix.MBS_SERIES_TABLE;
$booking_table=$wpdb->prefix.MBS_TABLE;
$invoice_table=$wpdb->prefix.MBS_INVOICE_TABLE;
$item_table=$wpdb->prefix.MBS_INVOICE_ITEM_TABLE;
$allocation_table=$wpdb->prefix.MBS_BILLING_ALLOCATION_TABLE;
$created='2026-01-01 00:00:00';
$service=wp_date('Y-m-d',strtotime('+30 days'));

function mbs_catchup_seed($series_ref,$booking_ref,$mode='monthly'){
    global $wpdb;
    $series_table=$wpdb->prefix.MBS_SERIES_TABLE;
    $booking_table=$wpdb->prefix.MBS_TABLE;
    $created='2026-01-01 00:00:00';
    $service=wp_date('Y-m-d',strtotime('+30 days'));
    $series_ok=$wpdb->insert($series_table,array(
        'series_ref'=>$series_ref,'status'=>'confirmed','version'=>1,'contact_name'=>'Catch-up Integration','contact_email'=>'catchup@example.invalid',
        'space'=>'Hall','attendees'=>1,'purpose'=>'Catch-up integration','start_date'=>$service,'repeat_until'=>$service,
        'recurrence_rule'=>'FREQ=WEEKLY;INTERVAL=1','schedule_json'=>wp_json_encode(array()),'price_per_booking'=>'1.00','estimated_total'=>'1.00',
        'requested_count'=>1,'accepted_count'=>1,'billing_mode'=>$mode,'billing_treatment'=>'invoice_managed','deposit_policy'=>'none','payment_method'=>'online',
        'invoice_lead_days'=>365,'payment_terms_days'=>14,'billing_schedule_json'=>wp_json_encode(array()),'created_at'=>$created,'updated_at'=>$created,
    ));
    if($series_ok===false)throw new RuntimeException($wpdb->last_error);
    $booking_ok=$wpdb->insert($booking_table,array(
        'ref'=>$booking_ref,'status'=>'confirmed','name'=>'Catch-up Integration','email'=>'catchup@example.invalid','phone'=>'000','address'=>'Test','space'=>'Hall',
        'booking_date'=>$service,'booking_date_end'=>$service,'attendees'=>1,'purpose'=>'Catch-up integration','amount'=>'1.00','series_id'=>$series_ref,
        'created_at'=>$created,'updated_at'=>$created,
    ));
    if($booking_ok===false)throw new RuntimeException($wpdb->last_error);
}

for($i=1;$i<=105;$i++){
    $suffix=str_pad((string)$i,3,'0',STR_PAD_LEFT);
    mbs_catchup_seed('INT-CU-'.$suffix,'INT-CUB-'.$suffix,$i===50?'termly':'monthly');
}
$result=MBS_Billing_Engine::catch_up(wp_date('Y-m-d'));
if(is_wp_error($result))throw new RuntimeException($result->get_error_message());
$errors=array_values(array_filter($result['periods'],static function($row){
    return ($row['status']??'')==='error' && strpos((string)($row['series_ref']??''),'INT-CU-')===0;
}));
if(count($errors)!==1||($errors[0]['series_ref']??'')!=='INT-CU-050')throw new RuntimeException('Middle-of-batch failure was not isolated to the invalid series.');
$invoice_count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$invoice_table} WHERE series_ref LIKE 'INT-CU-%'");
$last_billed=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$invoice_table} WHERE series_ref='INT-CU-105'");
if($invoice_count!==104||$last_billed!==1)throw new RuntimeException('The >100-series cursor skipped, duplicated, or starved rows after a middle failure.');

mbs_catchup_seed('INT-CU-R1','INT-CUB-R1');
mbs_catchup_seed('INT-CU-R2','INT-CUB-R2');
$barrier=$wpdb->prefix.'mbs_test_catchup_barrier';
$wpdb->query("DROP TABLE IF EXISTS {$barrier}");
$wpdb->query("CREATE TABLE {$barrier} (id INT NOT NULL PRIMARY KEY, arrived INT NOT NULL DEFAULT 0) ENGINE=InnoDB");
$wpdb->insert($barrier,array('id'=>1,'arrived'=>0));
echo "OK: 105 identical-timestamp series crossed the cursor with one isolated middle failure and no starvation.\n";
