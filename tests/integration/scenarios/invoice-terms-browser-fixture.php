<?php
/** Generate actual synthetic email HTML for the loopback browser tests only. */
if(!defined('WP_CLI')||!WP_CLI||!in_array(parse_url(home_url(),PHP_URL_HOST),array('localhost','127.0.0.1','wordpress'),true))throw new RuntimeException('Disposable site required');
global $wpdb;
$upload=wp_upload_dir();$dir=$upload['basedir'].'/invoice-terms-fixture';
if(($args[0]??'')==='cleanup'){
 foreach(glob($dir.'/*.html')?:array()as$file)unlink($file);if(is_dir($dir))rmdir($dir);
 foreach((array)get_option('mbs_terms_browser_restore',array())as$k=>$v){if($v===null)delete_option($k);else update_option($k,$v);}delete_option('mbs_terms_browser_restore');delete_option('mbs_terms_browser_fixture');return;
}
if(get_option('mbs_terms_browser_restore'))throw new RuntimeException('Fixture already exists');
$restore=array();foreach(array('mbs_credit_accounts_enabled','mbs_payment_expiry_enabled','mbs_payment_feature_invoices','mbs_admin_email')as$k)$restore[$k]=get_option($k,null);update_option('mbs_terms_browser_restore',$restore,false);
update_option('mbs_credit_accounts_enabled',1);update_option('mbs_payment_feature_invoices',1);update_option('mbs_payment_expiry_enabled',1);update_option('mbs_admin_email','coordinator@example.invalid');
$reader=get_user_by('login','expiry_reader');if(!$reader)wp_create_user('expiry_reader','synthetic-reader-only','reader@example.invalid');
wp_set_password('synthetic-reader-only',get_user_by('login','expiry_reader')->ID);
$b=MBS_Bookings::get('BROW-CREDIT');if(!$b)throw new RuntimeException('Seed credit browser fixture first');
$b=clone$b;$b->status='confirmed';$b->amount_paid='0.00';$b->amount='30.00';
// Rendering only: avoid Woo order creation in the normal approval renderer.
$online=get_option('mbs_payment_feature_online',null);update_option('mbs_payment_feature_online',0);
$messages=array('approval'=>MBS_Email::build_confirmation_message($b),'warning'=>MBS_Email::build_payment_expiry_message($b,'warning','Deposit of £7.50 is due by 24 November 2026 at 10:00 GMT. If unpaid, your booking will be cancelled.'),'reminder'=>MBS_Email::build_reminder_message($b));
$b->status='cancelled';$messages['cancelled']=MBS_Email::build_payment_expiry_message($b,'cancel','This synthetic booking was cancelled because its deposit was not paid by the deadline.');
if($online===null)delete_option('mbs_payment_feature_online');else update_option('mbs_payment_feature_online',$online);
wp_mkdir_p($dir);$urls=array();foreach($messages as$key=>$message){$html=$message['body'];file_put_contents($dir.'/'.$key.'.html',$html);$urls[$key]=$upload['baseurl'].'/invoice-terms-fixture/'.$key.'.html';}
update_option('mbs_terms_browser_fixture',$urls,false);update_option('mbs_credit_accounts_enabled',0);
WP_CLI::success('Synthetic invoice terms browser messages generated');
