<?php
/** Disposable DB only; synthetic past bookings in the current financial year. Archiving must not remove real revenue from the FY figure. */
if(!defined('WP_CLI')||!WP_CLI||!in_array(strtolower((string)parse_url(home_url(),PHP_URL_HOST)),array('127.0.0.1','localhost','wordpress'),true))throw new RuntimeException('Disposable runtime required.');
global $wpdb;$table=$wpdb->prefix.MBS_TABLE;$audit=$wpdb->prefix.'mathlin_audit_log';$checks=0;$refs=array();
$assert=static function($ok,$why)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($why);};
add_filter('pre_wp_mail',static function(){return true;});
$m=(int)gmdate('n');$y=(int)gmdate('Y');$fy_start=($m>=4?$y:$y-1).'-04-01';$fy_end=(($m>=4?$y:$y-1)+1).'-03-31';
$day=static function($n)use($fy_start){return gmdate('Y-m-d',strtotime($fy_start.' +'.(9+$n).' days'));};
$mk=static function($i,$n,$amount)use(&$refs,$assert,$wpdb,$table,$day){
    $d=array('name'=>'Synthetic Revenue '.$i,'email'=>'revenue-test@example.invalid','space'=>'Main Hall','booking_date'=>'2098-05-'.sprintf('%02d',$n),'booking_date_end'=>'2098-05-'.sprintf('%02d',$n),'start_time'=>'10:00','end_time'=>'12:00','attendees'=>5,'purpose'=>'Synthetic');
    $r=MBS_Bookings::create($d,true);$assert(!is_wp_error($r),'Create fixture '.$i);$refs[$i]=$r['ref'];
    $wpdb->update($table,array('amount'=>$amount,'scout_use'=>0,'booking_date'=>$day($n),'booking_date_end'=>$day($n)),array('ref'=>$r['ref']));return$r['ref'];
};
$st=static function($ref)use($wpdb,$table){return$wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE ref=%s",$ref));};
$counted=static function()use($wpdb,$table,&$refs,$fy_start,$fy_end){
    $in="'".implode("','",array_map('esc_sql',array_values($refs)))."'";
    return(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$table} WHERE ref IN ({$in}) AND ".MBS_Bookings::revenue_status_sql($table)." AND booking_date BETWEEN %s AND %s",$fy_start,$fy_end));
};
try{
    $bs=MBS_Bookings::get_stats();$base=(float)$bs['revenue_fy'];$base_unv=(float)$bs['revenue_fy_unverified'];$base_unv_n=(int)$bs['unverified_count'];
    // Counted live: 100 confirmed, 200 paid. Not revenue: 400 cancelled. Archived afterwards, individually.
    $a=$mk('conf',1,100);$b=$mk('paid',2,200);$c=$mk('canc',3,400);$d=$mk('reopened',4,800);
    // Confirm and pay directly so no invoice is issued (invoiced bookings cannot change status).
    $wpdb->update($table,array('status'=>'confirmed'),array('ref'=>$a));$wpdb->update($table,array('status'=>'paid'),array('ref'=>$b));
    $assert((bool)MBS_Bookings::update_status($c,'cancelled',false),'Cancel fixture');
    $wpdb->update($table,array('status'=>'confirmed'),array('ref'=>$d));
    $assert((bool)MBS_Bookings::update_status($d,'cancelled',false)&&(bool)MBS_Bookings::update_status($d,'confirmed',false),'Cancel then reopen fixture');
    $assert(abs((float)MBS_Bookings::get_stats()['revenue_fy']-$base-1100)<0.001,'Live FY revenue before archiving');
    foreach(array($a,$b,$c,$d)as $r)$assert((bool)MBS_Bookings::update_status($r,'archived',false),'Archive '.$r);
    $assert($st($a)==='archived'&&$st($c)==='archived','Fixtures archived');
    $assert(abs((float)MBS_Bookings::get_stats()['revenue_fy']-$base-1100)<0.001,'FY revenue unchanged by archiving; cancelled booking still excluded');
    // Consolidated source (replacement carries the revenue) must not double count.
    $e=$mk('consolidated',5,1600);$wpdb->update($table,array('status'=>'confirmed'),array('ref'=>$e));
    $wpdb->update($table,array('status'=>'archived','custom_fields'=>wp_json_encode(array('_mbs_whole_park_replacement'=>'ZZZ-REPL'))),array('ref'=>$e));
    $assert($counted()===1100.0,'Superseded consolidation source is not counted');
    // Older archived rows: an earlier cancellation excludes; no recorded status is unverified (not counted, reported separately).
    $f=$mk('legacy',6,3200);$g=$mk('legacycancel',7,6400);
    $wpdb->update($table,array('status'=>'archived'),array('ref'=>$f));$wpdb->update($table,array('status'=>'archived'),array('ref'=>$g));
    MBS_Audit_Log::log($g,'cancelled','Synthetic earlier cancellation');
    $assert($counted()===1100.0,'Archived rows with no recorded status are not counted as revenue');
    // Archived while confirmed per the older audit log only (no archive note) counts.
    $k=$mk('legacyconf',10,102400);$wpdb->update($table,array('status'=>'archived'),array('ref'=>$k));MBS_Audit_Log::log($k,'confirmed','Synthetic earlier confirmation');
    $assert($counted()===103500.0,'Archived row last confirmed in the audit log counts');
    // A series bulk cancel covering the booking's date excludes it, even though the audit entry is on the series.
    $j=$mk('series',11,51200);$wpdb->update($table,array('status'=>'archived','series_id'=>'SER-ARCH-TEST'),array('ref'=>$j));
    MBS_Audit_Log::log('SER-ARCH-TEST','series_bulk_cancel','Bulk-cancelled 1 future booking(s) in series (from '.gmdate('Y-m-d',strtotime($fy_start)).')');
    $assert($counted()===103500.0,'Series bulk-cancelled archived booking is not counted');
    $stt=MBS_Bookings::get_stats();
    $assert(abs((float)$stt['revenue_fy']-$base-103500)<0.001,'Stats revenue includes archived revenue only');
    $assert(abs((float)$stt['revenue_fy_unverified']-$base_unv-3200)<0.001&&(int)$stt['unverified_count']-$base_unv_n===1,'Stats report the unverified archived amount separately');
    // Bulk and automatic archive paths record the earlier status.
    $h=$mk('bulk_conf',8,12800);$i=$mk('bulk_canc',9,25600);
    $wpdb->update($table,array('status'=>'confirmed'),array('ref'=>$h));$assert((bool)MBS_Bookings::update_status($i,'cancelled',false),'Cancel bulk fixture');
    $before=$counted();
    MBS_Bookings::archive_past_bookings();
    $assert($st($h)==='archived'&&$st($i)==='archived','Bulk archive moved past bookings');
    $assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$audit} WHERE ref=%s AND action='archived' AND details LIKE %s",$i,'%(was cancelled)%'))===1,'Bulk archive records earlier status');
    $assert(abs($counted()-$before)<0.001&&$before===116300.0,'Bulk archive keeps confirmed revenue and drops cancelled');
    echo "PASS $checks archived revenue checks\n";
}finally{
    foreach($refs as $ref){$wpdb->delete($table,array('ref'=>$ref));$wpdb->delete($audit,array('ref'=>$ref));}
    $wpdb->delete($audit,array('ref'=>'SER-ARCH-TEST'));
}
