<?php
/** Real service + AJAX handlers, disposable database only. No live mail or gateways. */
if ( ! defined('WP_CLI') || ! WP_CLI || ! in_array(strtolower((string)parse_url(home_url(),PHP_URL_HOST)),array('127.0.0.1','localhost','wordpress'),true) ) throw new RuntimeException('Disposable runtime required.');
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;
$checks=0;$refs=array();$mail=0;
$assert=static function($ok,$why)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($why);};
add_filter('pre_wp_mail',static function()use(&$mail){$mail++;return true;},PHP_INT_MAX);
add_filter('wp_doing_ajax','__return_true');
add_filter('wp_die_ajax_handler',static function(){return static function(){throw new RuntimeException('__guard_ajax__');};});
$admin=get_users(array('role'=>'administrator','number'=>1))[0]->ID;wp_set_current_user($admin);
$tag='IP'.wp_rand(100000,999999);$table=$wpdb->prefix.MBS_TABLE;
$space_key='Inactive guard '.$tag;$spaces=get_option('mbs_spaces',array());$old_spaces=$spaces;
$spaces[$space_key]=array('rate_hourly'=>15,'rate_daily'=>150,'capacity'=>100,'parent'=>null);update_option('mbs_spaces',$spaces);
$old_ha=get_option('mbs_ha_webhook_url','');update_option('mbs_ha_webhook_url','');
$make=static function($key,$status)use(&$refs,$wpdb,$table,$tag,$space_key){
 $ref=$tag.'-'.$key;$refs[]=$ref;
 $row=array('ref'=>$ref,'status'=>$status,'name'=>'Synthetic inactive payment','email'=>'guard@example.invalid','space'=>$space_key,'booking_date'=>'2098-07-14','booking_date_end'=>'2098-07-14','start_time'=>'10:00','end_time'=>'12:00','attendees'=>1,'purpose'=>'Synthetic guard regression','amount'=>'30.00','amount_paid'=>'7.50','deposit_paid'=>'7.50','access_sent'=>0);
 if(!$wpdb->insert($table,$row))throw new RuntimeException($wpdb->last_error);return $ref;
};
$snapshot=static function($ref)use($wpdb,$table,&$mail){return array('booking'=>$wpdb->get_row($wpdb->prepare("SELECT status,amount,amount_paid,deposit_paid,access_sent FROM {$table} WHERE ref=%s",$ref),ARRAY_A),'policy'=>MBS_Payment_Expiry::get($ref),'queue'=>(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->prefix.'mathlin_email_queue'),'audit'=>(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->prefix.'mathlin_audit_log'),'mail'=>$mail);};
$call=static function($method,$ref,$status)use($admin){
 wp_set_current_user($admin);$_POST=array('ref'=>$ref,'status'=>$status,'nonce'=>wp_create_nonce('mbs_admin_nonce'));$_REQUEST=$_POST;
 ob_start();try{(new MBS_Admin())->$method();}catch(RuntimeException $e){if($e->getMessage()!=='__guard_ajax__'){ob_end_clean();throw $e;}}
 return json_decode(ob_get_clean(),true);
};
try{
 foreach(array('cancelled','archived')as$inactive){
  foreach(array('legacy','money_review','cancelled','resolved')as$policy){
   $ref=$make(substr($inactive,0,1).substr($policy,0,2),$inactive);
   if($policy!=='legacy')$wpdb->insert(MBS_Payment_Expiry::table(),array('booking_ref'=>$ref,'state'=>$policy,'policy_json'=>'{}','review_json'=>'{"received_minor":750,"retained_minor":0}','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')));
   foreach(array('paid','deposit_paid')as$target){
    $before=$snapshot($ref);
    $r=MBS_Bookings::update_status($ref,$target,false,array('return_error'=>true));
    $assert(is_wp_error($r)&&$r->get_error_code()==='inactive_payment_status','Service rejects '.$inactive.'/'.$policy.' -> '.$target);
    $assert(MBS_Bookings::update_status($ref,$target,false)===false,'Legacy boolean API refuses inactive payment');
    $r=$call('ajax_update_status',$ref,$target);
    $assert(is_array($r)&&empty($r['success'])&&strpos($r['data'],'explicit restore')!==false,'Real AJAX handler rejects inactive payment');
    $assert($snapshot($ref)==$before,'Status, money, policy, access, mail, audit unchanged after rejected requests');
   }
  }
 }
 // Active payment recording is still supported by the same real AJAX endpoint.
 $active=$make('ACTIVE','confirmed');$r=$call('ajax_update_status',$active,'paid');
 $assert(!empty($r['success'])&&MBS_Bookings::get($active)->status==='paid','Confirmed -> paid still supported');
 $assert((float)MBS_Bookings::get($active)->amount_paid===30.0,'Supported payment still records full receipt');
 $wpdb->delete($table,array('ref'=>$active));
 // Restore is a deliberate, separately checked workflow, not an incidental payment write.
 $restored=$make('RESTORE','archived');$r=$call('ajax_restore_booking',$restored,'paid');
 $assert(!empty($r['success'])&&MBS_Bookings::get($restored)->status==='paid','Explicit checked legacy restore still supported');
 $assert((float)MBS_Bookings::get($restored)->amount_paid===7.5,'Restore never fabricates a payment receipt');
 echo "INACTIVE_PAYMENT_GUARD_OK: {$checks} real service/AJAX checks\n";
}finally{
 foreach($refs as$ref){$wpdb->delete(MBS_Payment_Expiry::table(),array('booking_ref'=>$ref));$wpdb->delete($table,array('ref'=>$ref));}
 update_option('mbs_spaces',$old_spaces);update_option('mbs_ha_webhook_url',$old_ha);
}
