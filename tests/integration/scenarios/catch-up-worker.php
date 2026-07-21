<?php
global $wpdb;
$barrier=$wpdb->prefix.'mbs_test_catchup_barrier';
if($wpdb->query("UPDATE {$barrier} SET arrived=arrived+1 WHERE id=1")!==1)throw new RuntimeException('Catch-up barrier missing.');
$deadline=microtime(true)+30;
do{if((int)$wpdb->get_var("SELECT arrived FROM {$barrier} WHERE id=1")>=2)break;usleep(50000);}while(microtime(true)<$deadline);
if((int)$wpdb->get_var("SELECT arrived FROM {$barrier} WHERE id=1")<2)throw new RuntimeException('Catch-up barrier timed out.');
$result=MBS_Billing_Engine::catch_up(wp_date('Y-m-d'));
if(is_wp_error($result))throw new RuntimeException($result->get_error_message());
echo wp_json_encode(array('worker'=>isset($args[0])?$args[0]:'unknown','period_results'=>count($result['periods'])))."\n";
