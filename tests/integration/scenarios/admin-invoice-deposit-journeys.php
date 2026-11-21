<?php
/** Loopback disposable WP only. Shared runtime/browser fixtures; never run against a real site. */
if ( ! defined('WP_CLI') || ! WP_CLI || ! in_array(strtolower((string)parse_url(home_url(), PHP_URL_HOST)), array('localhost','127.0.0.1','wordpress'), true) ) throw new RuntimeException('Disposable runtime required.');
global $wpdb;
require_once ABSPATH . 'wp-admin/includes/user.php';
$checks=0;
$assert=static function($ok,$why)use(&$checks){$checks++; if(!$ok)throw new RuntimeException($why);};
$admin=get_users(array('role'=>'administrator','number'=>1))[0]->ID;
wp_set_current_user($admin);
update_option('mbs_payments_enabled',1); update_option('mbs_payment_feature_manual',1);update_option('mbs_payment_feature_reports',1);update_option('mbs_deposit_enabled',1);update_option('mbs_deposit_percentage',25);
update_option('mbs_ha_webhook_url','');update_option('mbs_google_calendar_enabled',0);
$tag='JNY'.wp_rand(10000,99999);
$refs=array();
$make=static function($key,$overrides=array())use($tag,&$refs,$wpdb){
 $ref=$tag.'-'.$key;
 $row=array_merge(array('ref'=>$ref,'name'=>'Synthetic Journey '.$key,'email'=>'journey@example.invalid','space'=>'Main Hall','booking_date'=>'2098-06-14','booking_date_end'=>'2098-06-14','start_time'=>'10:00','end_time'=>'12:00','amount'=>'100.00','amount_paid'=>'0.00','deposit_paid'=>'0.00','status'=>'confirmed','attendees'=>10,'purpose'=>'Synthetic journey only'),$overrides);
 if(!$wpdb->insert($wpdb->prefix.MBS_TABLE,$row))throw new RuntimeException('Fixture insert: '.$wpdb->last_error);$refs[$key]=$ref;return $ref;
};
foreach(array('DESKTOP','MOBILE','ONCE','PARTIAL','ZERO','CANCEL','LEDGER','RACE','PROCESSRACE','SINGLE','AUDITFAIL')as$key)$make($key,$key==='PARTIAL'?array('amount_paid'=>'12.00'):($key==='ZERO'?array('amount'=>'0.00'):($key==='CANCEL'?array('status'=>'cancelled'):array())));
$invoice_ref=$tag.'-INV';
$wpdb->insert($wpdb->prefix.MBS_INVOICE_TABLE,array('invoice_ref'=>$invoice_ref,'status'=>'part_paid','contact_name'=>'Synthetic Hirer <script>alert(1)</script>','contact_email'=>'journey@example.invalid','contact_organisation'=>'Example Volunteer Group','billing_mode'=>'monthly','currency'=>'GBP','total_minor'=>10000,'subtotal_minor'=>10000,'paid_minor'=>2500,'credited_minor'=>1000,'idempotency_key'=>hash('sha256',$tag),'issued_at'=>'2026-01-01 12:00:00','due_at'=>'2026-01-15 12:00:00'));
$invoice_id=(int)$wpdb->insert_id;
$assert($invoice_id>0,'Invoice fixture inserted');
$wpdb->insert($wpdb->prefix.MBS_INVOICE_ITEM_TABLE,array('item_ref'=>$tag.'-ITEM','invoice_id'=>$invoice_id,'booking_ref'=>$refs['LEDGER'],'description'=>'Hall hire, synthetic booking','service_date'=>'2098-06-14','unit_amount_minor'=>10000,'line_total_minor'=>10000));
$wpdb->insert($wpdb->prefix.MBS_PAYMENT_TRANSACTION_TABLE,array('transaction_ref'=>$tag.'-PAY','invoice_id'=>$invoice_id,'provider'=>'manual','transaction_type'=>'payment','status'=>'completed','amount_minor'=>2500,'currency'=>'GBP','idempotency_key'=>$tag.'-PAY','occurred_at'=>'2026-01-10 10:00:00','metadata_json'=>wp_json_encode(array('note'=>'Synthetic initial payment'))));
// Isolate mail for every browser request, not merely the CLI process.
$mu=ABSPATH.'wp-content/mu-plugins/mbs-journey-test-mail.php';wp_mkdir_p(dirname($mu));
file_put_contents($mu, '<?php if(in_array(parse_url(home_url(),PHP_URL_HOST),array("127.0.0.1","localhost","wordpress"),true)){add_filter("pre_wp_mail",static function($return,$mail){$log=get_option("mbs_journey_test_mail",array());$log[]=array("to"=>$mail["to"],"subject"=>$mail["subject"]);update_option("mbs_journey_test_mail",$log,false);return true;},10,2);}' );
$mails=0;add_filter('pre_wp_mail',static function()use(&$mails){$mails++;return true;});
add_filter('wp_doing_ajax','__return_true');add_filter('wp_die_ajax_handler',static function(){return static function(){throw new RuntimeException('__ajax_done__');};});add_filter('wp_die_handler',static function(){return static function(){throw new RuntimeException('__denied__');};});
$call=static function($user,$action,$list,$nonce=true){wp_set_current_user($user);$_POST=array('nonce'=>$nonce?wp_create_nonce('mbs_admin_nonce'):'invalid','bulk_action'=>$action,'refs'=>$list);$_REQUEST=$_POST;ob_start();try{(new MBS_Admin())->ajax_bulk_action();}catch(RuntimeException $e){if($e->getMessage()!=='__ajax_done__'){ob_end_clean();throw $e;}}return json_decode(ob_get_clean(),true);};
$manager=wp_insert_user(array('user_login'=>$tag.'manager','user_pass'=>'integration-only','role'=>'mbs_booking_manager'));
$sub=wp_insert_user(array('user_login'=>$tag.'sub','user_pass'=>'integration-only','role'=>'subscriber'));
// Let this synthetic subscriber reach wp-admin past WooCommerce's customer redirect,
// without granting any booking capability, so HTTP tests exercise the invoice gate.
(new WP_User($sub))->add_cap('view_admin_dashboard');
$r=$call($sub,'deposit_paid',array($refs['ONCE']));$assert(empty($r['success']),'Subscriber deposit refused');
$r=$call($admin,'deposit_paid',array($refs['ONCE']),false);$assert(empty($r['success']),'Invalid nonce refused');
$r=$call($admin,'deposit_paid',array($refs['ONCE'],$refs['ONCE'],$refs['PARTIAL'],$refs['ZERO'],$refs['CANCEL'],$refs['LEDGER'],'MISSING'));
$assert($r['success']&&$r['data']['processed']===1&&$r['data']['skipped']===5,'Mixed selection processed one and skipped unsafe records, deduplicated');
$b=MBS_Bookings::get($refs['ONCE']);$assert($b->status==='deposit_paid'&&(float)$b->amount_paid===25.0&&(float)$b->deposit_paid===25.0,'Configured 25 deposit leaves 75 balance');
$assert((float)MBS_Bookings::get($refs['PARTIAL'])->amount_paid===12.0,'Existing partial payment preserved');
$assert(MBS_Bookings::get($refs['LEDGER'])->status==='confirmed','Ledger booking not bypassed');
$assert($mails===1,'Exactly one deposit receipt');
$r=$call($admin,'deposit_paid',array($refs['ONCE']));$assert($r['data']['processed']===0&&$mails===1,'Repeat produces no money or duplicate receipt');
foreach(array(0,100,-1)as$percentage){update_option('mbs_deposit_percentage',$percentage);$r=$call($admin,'deposit_paid',array($refs['DESKTOP']));$assert($r['data']['processed']===0,'Invalid/full configured deposit refused');}
update_option('mbs_deposit_percentage',25);update_option('mbs_deposit_enabled',0);$r=$call($admin,'deposit_paid',array($refs['DESKTOP']));$assert($r['data']['processed']===0,'Disabled deposits refused');update_option('mbs_deposit_enabled',1);
update_option('mbs_payment_feature_manual',0);$r=$call($admin,'deposit_paid',array($refs['DESKTOP']));$assert($r['data']['processed']===0,'Manual feature disabled refused');update_option('mbs_payment_feature_manual',1);
wp_set_current_user($admin);
$items=MBS_Attention::summary(100)['items'];$matching=array_values(array_filter($items,static function($i)use($invoice_ref){return $i['ref']===$invoice_ref;}));$assert(count($matching)===1&&strpos($matching[0]['url'],'page=mathlin-invoice')!==false&&strpos($matching[0]['url'],rawurlencode($invoice_ref))!==false,'Attention links to exact invoice');
$render=static function($ref) {$_GET=array('ref'=>$ref);ob_start();(new MBS_Admin())->render_invoice_review();return ob_get_clean();};
MBS_Audit_Log::log($invoice_ref,'journey_review','Synthetic invoice audit entry');
$html=$render($invoice_ref);$assert(strpos($html,'Synthetic invoice audit entry')!==false,'Invoice-scoped audit history visible');$assert(strpos($html,'£65.00')!==false,'Review shows total less paid less credits');$assert(strpos($html,'Synthetic initial payment')!==false&&strpos($html,$refs['LEDGER'])!==false,'Review contains scoped history and linked booking');$assert(strpos($html,'<script>alert(1)</script>')===false&&strpos($html,'&lt;script&gt;')!==false,'Hirer escaped');$assert(strpos($render(''),'Open an invoice from Needs attention')!==false,'Empty reference state');$assert(strpos($render('MISSING'),'Invoice not found')!==false,'Missing reference state');
wp_set_current_user($sub);ob_start();try{$render($invoice_ref);$assert(false,'Denied review');}catch(RuntimeException $e){$assert(in_array($e->getMessage(),array('__denied__','__ajax_done__'),true),'Unauthorised review denied before data');}ob_end_clean();wp_set_current_user($admin);
$before=MBS_Billing_Ledger::get_invoice($invoice_ref);$over=MBS_Invoice_Payment::record_manual_payment($invoice_ref,6501,$tag.'over',(int)$before->version);$assert(is_wp_error($over),'Invoice overpayment rejected');$assert((int)MBS_Billing_Ledger::get_invoice($invoice_ref)->paid_minor===2500,'Rejected payment leaves ledger unchanged');
$payment=MBS_Invoice_Payment::record_manual_payment($invoice_ref,500,$tag.'valid',(int)$before->version,'Synthetic runtime payment');$assert(!is_wp_error($payment),'Valid partial invoice payment');$repeat=MBS_Invoice_Payment::record_manual_payment($invoice_ref,500,$tag.'valid',(int)$before->version,'Synthetic runtime payment');$assert(!is_wp_error($repeat)&&!empty($repeat['idempotent_replay']),'Invoice payment retry idempotent');$assert((int)MBS_Billing_Ledger::get_invoice($invoice_ref)->paid_minor===3000,'Invoice counted once');
$assert(strpos($render($invoice_ref),'Synthetic runtime payment')!==false,'Recorded transaction visible');
// Single action uses the same guarded implementation.
wp_set_current_user($admin);$_POST=array('ref'=>$refs['SINGLE'],'nonce'=>wp_create_nonce('mbs_admin_nonce'));$_REQUEST=$_POST;
$single=static function(){ob_start();try{(new MBS_Admin())->ajax_mark_deposit_paid();}catch(RuntimeException $e){if($e->getMessage()!=='__ajax_done__'){ob_end_clean();throw $e;}}return json_decode(ob_get_clean(),true);};
$single_result=$single();$assert($single_result['success']&&(float)MBS_Bookings::get($refs['SINGLE'])->amount_paid===25.0,'Single deposit uses shared safe implementation');
$single_result=$single();$assert(empty($single_result['success']),'Single repeat refused without overwriting');
// Failure between money write and audit must roll back both fields and status.
$fail=static function($sql){return preg_match('/^INSERT INTO.*mathlin_audit_log/i',$sql)?'INVALID_SYNTHETIC_AUDIT_QUERY':$sql;};
$wpdb->suppress_errors(true);add_filter('query',$fail,999);$mail_before=$mails;$r=$call($admin,'deposit_paid',array($refs['AUDITFAIL']));remove_filter('query',$fail,999);$wpdb->suppress_errors(false);
$assert($r['data']['processed']===0&&MBS_Bookings::get($refs['AUDITFAIL'])->status==='confirmed'&&(float)MBS_Bookings::get($refs['AUDITFAIL'])->amount_paid===0.0&&$mails===$mail_before,'Audit failure rolls back deposit with no receipt');
// Long sections page independently and keep every field invoice-scoped.
for($n=0;$n<52;$n++){
 $wpdb->insert($wpdb->prefix.MBS_INVOICE_ITEM_TABLE,array('item_ref'=>$tag.'-PAGE-'.$n,'invoice_id'=>$invoice_id,'description'=>'Pagination test '.$n,'line_total_minor'=>0));
 $wpdb->insert($wpdb->prefix.MBS_PAYMENT_TRANSACTION_TABLE,array('transaction_ref'=>$tag.'-PAGE-'.$n,'invoice_id'=>$invoice_id,'provider'=>'manual','transaction_type'=>'payment','status'=>'failed','amount_minor'=>1,'idempotency_key'=>$tag.'-PAGE-'.$n));
}
$page1=$render($invoice_ref);$assert(substr_count($page1,'data-label="Service date"')===50&&substr_count($page1,'data-label="Amount"')===50,'Long sections limited to 50 visible records');
$_GET=array('ref'=>$invoice_ref,'items_page'=>2,'payments_page'=>2);ob_start();(new MBS_Admin())->render_invoice_review();$page2=ob_get_clean();$assert(strpos($page2,'Pagination test 51')!==false&&substr_count($page2,'data-label="Amount"')===4,'Remaining item and payment pages rendered');
$wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.MBS_INVOICE_ITEM_TABLE.' WHERE item_ref LIKE %s',$wpdb->esc_like($tag.'-PAGE-').'%'));
$wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.MBS_PAYMENT_TRANSACTION_TABLE.' WHERE transaction_ref LIKE %s',$wpdb->esc_like($tag.'-PAGE-').'%'));
$wpdb->insert($wpdb->prefix.MBS_PAYMENT_RESERVATION_TABLE,array('reservation_ref'=>$tag.'-CLAIM','invoice_id'=>$invoice_id,'invoice_ref'=>$invoice_ref,'amount_minor'=>6000,'status'=>'reconciliation_required','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')));
$reconcile=$render($invoice_ref);$assert(strpos($reconcile,'Online checkout needs review')!==false&&strpos($reconcile,'class="nms-manual-invoice-payment')===false,'Unresolved checkout blocks offline recording UI');
$wpdb->delete($wpdb->prefix.MBS_PAYMENT_RESERVATION_TABLE,array('invoice_id'=>$invoice_id));
update_option('mbs_payment_feature_reports',0);ob_start();try{$render($invoice_ref);$assert(false,'Feature denied');}catch(RuntimeException $e){$assert(in_array($e->getMessage(),array('__ajax_done__','__denied__'),true),'Reports feature off denies review');}ob_end_clean();update_option('mbs_payment_feature_reports',1);
$wpdb->query('START TRANSACTION');$wpdb->delete($wpdb->prefix.MBS_INVOICE_ITEM_TABLE,array('invoice_id'=>$invoice_id));$wpdb->delete($wpdb->prefix.MBS_PAYMENT_TRANSACTION_TABLE,array('invoice_id'=>$invoice_id));$empty=$render($invoice_ref);$assert(strpos($empty,'No item lines recorded')!==false&&strpos($empty,'No payment transactions recorded')!==false,'Empty item and payment history states');$wpdb->query('ROLLBACK');
$doc=MBS_Invoice_Document_Service::create_ledger_document_within_transaction(MBS_Billing_Ledger::get_invoice($invoice_ref),MBS_Billing_Ledger::get_items($invoice_id),null);
$assert(!is_wp_error($doc)&&$doc>0,'Issued document fixture created using real snapshot builder');
$assert(strpos($render($invoice_ref),'mbs-view-invoice-btn')!==false,'Review offers existing document viewer');
wp_set_current_user($manager);$assert(strpos($render($invoice_ref),$invoice_ref)!==false,'Booking manager can review invoice');wp_set_current_user($admin);
for($n=0;$n<52;$n++)$make('PAGEBOOK-'.$n);
// Fresh browser expectations: opening is read-only, next payment starts at balance 60.
update_option('mbs_journey_test_mail',array());
update_option('mbs_journey_fixture',array('tag'=>$tag,'refs'=>$refs,'invoice_ref'=>$invoice_ref,'invoice_id'=>$invoice_id,'subscriber'=>$sub,'manager'=>$manager,'document_id'=>$doc));
echo "PASS $checks invoice/deposit journey runtime checks\n";
