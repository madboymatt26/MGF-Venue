<?php
/** Disposable SQL acceptance; never send mail or connect to a real HA. */
if(!defined('WP_CLI')||!WP_CLI||!preg_match('/localhost|127\.0\.0\.1|wordpress/',home_url()))throw new RuntimeException('Disposable host required.');
global $wpdb;
$table=$wpdb->prefix.MBS_TABLE;$ref='HEAT-'.substr(wp_generate_uuid4(),0,8);$key='mbs_ha_event_'.hash('sha256',$ref);$checks=0;
$check=static function($ok,$label)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($label);};
$previous=get_option('mbs_ha_webhook_url',null);$errors=$wpdb->suppress_errors(true);
$fail=static function($sql){return strpos($sql,'INSERT INTO')!==false&&strpos($sql,'mbs_ha_event_')!==false?'INVALID SYNTHETIC HEATING INTENT':$sql;};
$change=static function($expected,$choice){return MBS_Space_Selection::locked(static function()use($expected,$choice){return MBS_Booking_Change_Service::apply($expected,array('custom_fields'=>wp_json_encode(array('answer'=>'preserved',MBS_HomeAssistant::HEATING_KEY=>$choice))),array('action'=>'heating_changed','audit'=>'Synthetic heating acceptance'));});};
try{
 update_option('mbs_ha_webhook_url','https://ha.example.invalid/never-dispatch');
 $check($wpdb->insert($table,array('ref'=>$ref,'status'=>'paid','name'=>'Synthetic Scout','email'=>'heating@example.invalid','space'=>'Whole Centre','booking_date'=>'2098-11-12','booking_date_end'=>'2098-11-12','start_time'=>'18:00:00','end_time'=>'20:00:00','scout_use'=>1,'all_day'=>0,'amount'=>0,'custom_fields'=>'{"answer":"preserved"}'))===1,'Insert fixture');
 $before=MBS_Bookings::get($ref);$check(!MBS_HomeAssistant::heating_required($before),'Existing Scout defaults off');
 $check(!is_wp_error($change($before,true)),'One-off opt-in commits');
 $enabled=MBS_Bookings::get($ref);$check(MBS_HomeAssistant::heating_required($enabled),'Explicit choice retained');
 $intent=maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key)));
 $check($intent['event']==='booking_confirmed','Enable intent committed');
 $check(is_wp_error($change($before,false)),'Stale expected booking rejected');
 add_filter('query',$fail,PHP_INT_MAX);$failed=$change($enabled,false);remove_filter('query',$fail,PHP_INT_MAX);
 $check(is_wp_error($failed),'Outbox failure fails change');
 $check((array)MBS_Bookings::get($ref)===(array)$enabled,'All booking fields rolled back');
 $check(maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key)))===$intent,'Previous durable intent restored');
 $check(!is_wp_error($change($enabled,false)),'Opt-out commits');
 $disabled=MBS_Bookings::get($ref);
 $off=maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key)));
 $check($off['event']==='heating_request_removed'&&!MBS_HomeAssistant::heating_required($disabled),'Removal is not booking cancellation');
 foreach(array('status','amount','amount_paid','deposit_paid','access_sent','confirmation_sent','space','booking_date','booking_date_end','start_time','end_time')as$field)if(property_exists($before,$field))$check($before->$field===$disabled->$field,'Preserve '.$field);
 $check(json_decode($disabled->custom_fields,true)['answer']==='preserved','Other metadata preserved');
 $series=clone$enabled;$series->series_id='SYNTHETIC';$check(!MBS_HomeAssistant::heating_required($series),'Series policy overrides opt-in');
 echo "SCOUT_HEATING_SQL_OK: {$checks} assertions\n";
}finally{
 remove_filter('query',$fail,PHP_INT_MAX);$wpdb->query('ROLLBACK');
 $wpdb->delete($table,array('ref'=>$ref));$wpdb->delete($wpdb->prefix.'mathlin_audit_log',array('ref'=>$ref));$wpdb->delete($wpdb->options,array('option_name'=>$key));
 if($previous===null)delete_option('mbs_ha_webhook_url');else update_option('mbs_ha_webhook_url',$previous,false);
 $wpdb->suppress_errors($errors);wp_cache_flush();
}
