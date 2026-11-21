<?php
/** Disposable DB only; reserved 2098 dates, invalid mail, wp_mail short-circuited. Exercises the real bulk endpoint. */
if(!defined('WP_CLI')||!WP_CLI||!in_array(strtolower((string)parse_url(home_url(),PHP_URL_HOST)),array('127.0.0.1','localhost','wordpress'),true))throw new RuntimeException('Disposable runtime required.');
global $wpdb;$table=$wpdb->prefix.MBS_TABLE;$checks=0;$refs=array();$mails=0;
$assert=static function($ok,$why)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($why);};
add_filter('pre_wp_mail',static function()use(&$mails){$mails++;return true;});
add_filter('wp_doing_ajax','__return_true');
add_filter('wp_die_ajax_handler',static function(){return static function($m,$t='',$a=array()){throw new RuntimeException('__ajax_done__');};});
$call=static function($user_id,$action,$list)use(&$assert){
    wp_set_current_user($user_id);
    $_POST=array('nonce'=>wp_create_nonce('mbs_admin_nonce'),'bulk_action'=>$action,'refs'=>$list);$_REQUEST=$_POST;
    ob_start();try{(new MBS_Admin())->ajax_bulk_action();}catch(RuntimeException $e){if($e->getMessage()!=='__ajax_done__'){ob_end_clean();throw $e;}}
    return json_decode(ob_get_clean(),true);
};
$mk=static function($i,$day)use(&$refs,$assert){
    $d=array('name'=>'Synthetic Bulk '.$i,'email'=>'bulk-test@example.invalid','space'=>array_key_first((array)get_option('mbs_spaces',array('Main Hall'=>1)))?:'Main Hall','booking_date'=>$day,'booking_date_end'=>$day,'start_time'=>'10:00','end_time'=>'12:00','attendees'=>5,'purpose'=>'Synthetic');
    $r=MBS_Bookings::create($d,true);$assert(!is_wp_error($r),'Create fixture '.$i);$refs[$i]=$r['ref'];return$r['ref'];
};
$status=static function($ref){global $wpdb;return$wpdb->get_var($wpdb->prepare('SELECT status FROM `'.$wpdb->prefix.MBS_TABLE.'` WHERE ref=%s',$ref));};
$admin=get_users(array('role'=>'administrator','number'=>1))[0]->ID;
$sub=wp_insert_user(array('user_login'=>'bulk_sub_'.wp_rand(),'user_pass'=>wp_generate_password(),'role'=>'subscriber'));
try{
    $a=$mk('pending','2098-04-01');$b=$mk('confirmed','2098-04-02');$c=$mk('cancelled','2098-04-03');
    MBS_Bookings::update_status($b,'confirmed',false);MBS_Bookings::update_status($c,'cancelled',false);
    $assert($status($a)==='pending'&&$status($b)==='confirmed'&&$status($c)==='cancelled','Fixture statuses');
    $before=array($status($a),$status($b),$status($c));
    // Permission: a subscriber is refused and nothing changes
    $r=$call($sub,'cancelled',array($a,$b));$assert(is_array($r)&&empty($r['success']),'Subscriber refused');
    $assert(array($status($a),$status($b),$status($c))===$before,'Refused request changes nothing');
    // Invalid action name is rejected, nothing changes
    $r=$call($admin,'deleted',array($a));$assert(empty($r['success'])&&$status($a)==='pending','Unknown action rejected');
    // Confirm: only pending moves; confirmed and cancelled are skipped
    $r=$call($admin,'confirmed',array($a,$b,$c));$assert(!empty($r['success'])&&$r['data']['processed']===1&&$r['data']['skipped']===2&&$r['data']['total']===3,'Confirm counts');
    $assert($status($a)==='confirmed'&&$status($b)==='confirmed'&&$status($c)==='cancelled','Confirm moves only the pending booking');
    // Archive: cancelled and confirmed may archive
    $r=$call($admin,'archived',array($c));$assert($r['data']['processed']===1&&$status($c)==='archived','Archive a cancelled booking');
    // Cancel: archived cannot be cancelled; unknown ref skipped
    $r=$call($admin,'cancelled',array($c,'ZZZ-NOPE'));$assert($r['data']['processed']===0&&$r['data']['skipped']===2&&$status($c)==='archived','Cancel skips archived and unknown refs');
    // Cancel confirmed
    $r=$call($admin,'cancelled',array($b));$assert($r['data']['processed']===1&&$status($b)==='cancelled','Cancel a confirmed booking');
    // Paid is only valid from confirmed
    $r=$call($admin,'paid',array($b));$assert($r['data']['processed']===0&&$status($b)==='cancelled','Mark paid skips non-confirmed');
    // Empty selection rejected
    $r=$call($admin,'confirmed',array());$assert(empty($r['success']),'Empty selection rejected');
    echo "PASS $checks bulk endpoint checks\n";
}finally{
    foreach($refs as $ref)$wpdb->delete($table,array('ref'=>$ref));
    if(!is_wp_error($sub))wp_delete_user($sub);
}
