<?php
/** Synthetic layout fixtures. Never run against a production host. */
if (!defined('WP_CLI') || !WP_CLI || !in_array(parse_url(home_url(),PHP_URL_HOST),array('127.0.0.1','localhost','wordpress'),true)) throw new RuntimeException('Disposable runtime required');
$mode = getenv('MBS_LAYOUT_MODE') ?: 'venue';
$keys = array('mbs_campsite_mode','mbs_payments_enabled','mbs_invoices_enabled','mbs_deposit_enabled');
if (get_option('mbs_layout_saved',false) === false) {
    $saved=array();foreach($keys as $key)$saved[$key]=array('value'=>get_option($key),'exists'=>get_option($key,'__MISSING__')!=='__MISSING__');
    update_option('mbs_layout_saved',$saved,false);
}
global $wpdb;
if ($mode === 'cleanup') {
    foreach((array)get_option('mbs_layout_saved',array()) as $key=>$saved){if($saved['exists'])update_option($key,$saved['value']);else delete_option($key);}
    delete_option('mbs_layout_saved');
    for($i=1;$i<=8;$i++)$wpdb->delete($wpdb->prefix.MBS_TABLE,array('ref'=>sprintf('LAYOUT-%02d',$i)));
    echo "LAYOUT_FIXTURE_CLEANED\n";return;
}
$camp=$mode==='campsite';
update_option('mbs_campsite_mode',$camp?1:0);
foreach(array_slice($keys,1) as $key)update_option($key,$camp?0:1);
for($i=1;$i<=8;$i++){
    $ref=sprintf('LAYOUT-%02d',$i);
    $data=array('ref'=>$ref,'status'=>$i===2?'pending':'confirmed','name'=>'Synthetic layout booking '.$i,'organisation'=>'Layout test group','email'=>'layout@example.invalid','space'=>'Test hall','booking_date'=>'2098-12-'.sprintf('%02d',$i),'booking_date_end'=>'2098-12-'.sprintf('%02d',$i),'start_time'=>'18:00:00','end_time'=>'20:00:00','all_day'=>0,'attendees'=>25,'amount'=>$camp?'0.00':'30.00','amount_paid'=>'0.00','deposit_paid'=>'0.00','custom_fields'=>'{}');
    if(MBS_Bookings::get($ref))$wpdb->update($wpdb->prefix.MBS_TABLE,$data,array('ref'=>$ref));else $wpdb->insert($wpdb->prefix.MBS_TABLE,$data);
    if(!MBS_Bookings::get($ref))throw new RuntimeException('Layout fixture missing');
}
echo 'LAYOUT_FIXTURE_READY: '.$mode."\n";
