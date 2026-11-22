<?php
/** Disposable real WordPress/database tests. No real mail or gateway requests. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! preg_match( '/127\.0\.0\.1|localhost|wordpress/', home_url() ) ) throw new RuntimeException( 'Disposable fixture required.' );
global $wpdb;
$table = $wpdb->prefix . MBS_TABLE; $policy_table = MBS_Payment_Expiry::table(); $queue = $wpdb->prefix . 'mathlin_email_queue';
$refs = array(); $orders = array(); $checks = 0; $transport = 0;
$check = static function( $ok, $message ) use ( &$checks ) { $checks++; if ( ! $ok ) throw new RuntimeException( $message ); };
$mail = static function() use ( &$transport ) { $transport++; return true; };
$http = static function() { throw new RuntimeException( 'No HTTP allowed in expiry fixtures.' ); };
$options = array();
foreach ( array( 'mbs_additional_emails','mbs_payment_expiry_enabled','mbs_campsite_mode','mbs_payments_enabled','mbs_invoices_enabled','mbs_deposit_enabled','mbs_deposit_percentage','mbs_ha_webhook_url','timezone_string' ) as $key ) $options[$key] = get_option( $key, null );
$seed = static function( $days = 10 ) use ( &$refs, $wpdb, $table, $check ) {
    $ref = 'EXP-' . substr( hash( 'sha256', wp_generate_uuid4() ), 0, 12 ); $refs[] = $ref;
    $b = (object) array( 'ref'=>$ref,'status'=>'pending','name'=>'Synthetic expiry fixture','email'=>'fixture@example.invalid','phone'=>'','address'=>'','space'=>'Main Scout Hall','booking_date'=>wp_date('Y-m-d',strtotime('+' . $days . ' days')),'booking_date_end'=>wp_date('Y-m-d',strtotime('+' . $days . ' days')),'start_time'=>'10:00:00','end_time'=>'12:00:00','attendees'=>1,'purpose'=>'Synthetic payment expiry','amount'=>'30.00','amount_paid'=>'0.00','scout_use'=>0,'pricing_tier'=>'standard' );
    $check( $wpdb->insert( $table, array_merge( (array)$b, MBS_Bookings::with_pricing_evidence( $b, array() ) ) ) === 1, 'Seed pending hire' );
    return $ref;
};
$age_deposit = static function( $ref, $hours ) use ( $wpdb, $policy_table ) {
    $row = MBS_Payment_Expiry::get( $ref ); $p = json_decode( $row->policy_json, true );
    $p['approved_at'] = time() - $hours * 3600; $p['deposit_due_at'] = $p['approved_at'] + 172800;
    $wpdb->update( $policy_table, array( 'policy_json'=>wp_json_encode($p) ), array( 'booking_ref'=>$ref ) );
    return $p;
};
$fail = static function( $sql ) use ( $queue ) { return strpos($sql,'INSERT INTO') !== false && strpos($sql,$queue) !== false ? 'INVALID SYNTHETIC EXPIRY QUEUE INSERT' : $sql; };
try {
    add_filter( 'pre_wp_mail', $mail, PHP_INT_MAX, 2 ); add_filter( 'pre_http_request', $http, PHP_INT_MAX );
    update_option('timezone_string','Europe/London'); update_option('mbs_campsite_mode',0); update_option('mbs_payments_enabled',1); update_option('mbs_invoices_enabled',1); update_option('mbs_deposit_enabled',1); update_option('mbs_deposit_percentage',25); update_option('mbs_ha_webhook_url','');
    update_option('mbs_payment_expiry_enabled',0);
    $legacy = $seed(); $wpdb->update($table,array('status'=>'confirmed'),array('ref'=>$legacy));
    update_option('mbs_payment_expiry_enabled',1);
    $check(MBS_Payment_Expiry::get($legacy)===null,'Enable never backfills existing approvals');
    $silent=$seed(); $result=MBS_Invoice_Document_Service::confirm_and_issue($silent,array(),false);
    $check(is_wp_error($result)&&MBS_Bookings::get($silent)->status==='pending'&&!MBS_Payment_Expiry::get($silent),'Silent approval rejected and rolled back');
    $normal=$seed(); $result=MBS_Invoice_Document_Service::confirm_and_issue($normal,array(),true);
    $check(!is_wp_error($result),'Ordinary notified approval succeeds');
    $row=MBS_Payment_Expiry::get($normal); $p=json_decode($row->policy_json,true);
    $check(MBS_Payment_Expiry_Policy::valid($p)&&$p['deposit_minor']===750,'Policy enrolled atomically with frozen amounts');
    $p_before=$row->policy_json; $again=MBS_Invoice_Document_Service::confirm_and_issue($normal,array(),true);
    $check(!is_wp_error($again)&&MBS_Payment_Expiry::get($normal)->policy_json===$p_before,'Approval retry never resets 48-hour clock');
    $check(strpos(MBS_Email::build_confirmation_message(MBS_Bookings::get($normal))['body'],'48 hours of approval')!==false,'Approval email contains agreed warning');
    // Invoice/policy deadline consistency (council invoice regression): the issued
    // document must carry the exact deadline the cancellation worker enforces,
    // never the issue date.
    $doc_table=$wpdb->prefix.MBS_INVOICE_DOCUMENTS_TABLE;
    $expected_deadline=MBS_Payment_Expiry_Policy::format_deadline($p['deposit_due_at'],$p['timezone']);
    $snap_json=$wpdb->get_var($wpdb->prepare("SELECT snapshot_json FROM {$doc_table} WHERE id=%d",(int)MBS_Bookings::get($normal)->current_invoice_document_id));
    $issued=json_decode($snap_json,true);
    $check($issued['payment_schedule']['deposit_due_at']===$p['deposit_due_at'],'Invoice stores the exact policy deposit deadline');
    $check($issued['payment_schedule']['deposit_due_date']===wp_date('Y-m-d',$p['deposit_due_at'],new DateTimeZone($p['timezone'])),'Invoice deposit date is the policy deadline day');
    $check($issued['payment_schedule']['deposit_due_date']!==wp_date('Y-m-d'),'Invoice deposit date is never the issue date');
    $check($issued['due_date']===$issued['payment_schedule']['deposit_due_date'],'Invoice header due date matches the policy deadline');
    $check(MBS_Payment_Expiry_Policy::next_deadline($p,0)['at']===$issued['payment_schedule']['deposit_due_at'],'Printed deadline is the enforced cancellation deadline');
    $check(strpos(MBS_HTML_Renderer::render(MBS_Invoice_Document_View_Model::issued(MBS_Issued_Invoice_Snapshot::from_json($snap_json))),$expected_deadline)!==false,'Rendered invoice prints the saved deadline with its exact time');
    $conf_body=MBS_Email::build_confirmation_message(MBS_Bookings::get($normal))['body'];
    $check(strpos($conf_body,$expected_deadline)!==false&&strpos($conf_body,'Deposit due now')===false,'Confirmation email states the saved deposit deadline');
    $preview=MBS_Invoice::generate_html(MBS_Bookings::get($normal));
    $check(strpos($preview,$expected_deadline)!==false&&strpos($preview,'Immediately (deposit)')===false&&strpos($preview,'Deposit due immediately')===false,'Staff invoice preview shows the same saved deadline as the issued invoice');
    $legacy_preview=MBS_Invoice::generate_email_invoice(MBS_Bookings::get($normal));
    $check(strpos($legacy_preview,$expected_deadline)!==false&&strpos($legacy_preview,'Deposit due immediately')===false,'Legacy email invoice shows the same saved deadline');
    // Reissue after an agreed extension: the printed deadline follows the extension.
    $p_ext=$p;$p_ext['extension_until']=$p['deposit_due_at']+3600;
    $wpdb->update($policy_table,array('policy_json'=>wp_json_encode($p_ext)),array('booking_ref'=>$normal));
    $wpdb->query('START TRANSACTION');
    $locked=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE ref=%s FOR UPDATE",$normal));
    $reissue=MBS_Invoice_Document_Service::reissue_booking_document_within_transaction($locked);
    $wpdb->query('COMMIT');
    $check(!is_wp_error($reissue),'Reissue after extension succeeds');
    $reissue_json=$wpdb->get_var($wpdb->prepare("SELECT snapshot_json FROM {$doc_table} WHERE id=%d",(int)$reissue));
    $reissued=json_decode($reissue_json,true);
    $check($reissued['payment_schedule']['deposit_due_at']===$p_ext['extension_until'],'Reissued invoice honours the agreed extension');
    $check(strpos(MBS_HTML_Renderer::render(MBS_Invoice_Document_View_Model::issued(MBS_Issued_Invoice_Snapshot::from_json($reissue_json))),MBS_Payment_Expiry_Policy::format_deadline($p_ext['extension_until'],$p['timezone']))!==false,'Rendered reissue prints the extended deadline');
    $check(strpos(MBS_Invoice::generate_html(MBS_Bookings::get($normal)),MBS_Payment_Expiry_Policy::format_deadline($p_ext['extension_until'],$p['timezone']))!==false,'Preview honours the agreed extension');
    // Authorised exemption: deadlines no longer bind, invoice falls back safely.
    $p_ex=$p_ext;$p_ex['exempt']=true;
    $wpdb->update($policy_table,array('policy_json'=>wp_json_encode($p_ex)),array('booking_ref'=>$normal));
    $wpdb->query('START TRANSACTION');
    $locked=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE ref=%s FOR UPDATE",$normal));
    $reissue2=MBS_Invoice_Document_Service::reissue_booking_document_within_transaction($locked);
    $wpdb->query('COMMIT');
    $ex_doc=json_decode($wpdb->get_var($wpdb->prepare("SELECT snapshot_json FROM {$doc_table} WHERE id=%d",(int)$reissue2)),true);
    $check(!is_wp_error($reissue2)&&empty($ex_doc['payment_schedule']['deposit_due_at'])&&$ex_doc['payment_schedule']['deposit_due_date']===wp_date('Y-m-d'),'Exempted hire falls back to ordinary invoice terms');
    $check(strpos(MBS_Invoice::generate_html(MBS_Bookings::get($normal)),'Deposit due immediately')!==false,'Exempted hire preview falls back to ordinary terms');
    $wpdb->update($policy_table,array('policy_json'=>wp_json_encode($p)),array('booking_ref'=>$normal));
    // Chaser consistency: the deposit phase is owned by the policy worker;
    // balance chasing after a paid deposit continues per the printed terms.
    $check(MBS_Payment_Chaser::should_chase_occurrence(MBS_Bookings::get($normal))===false,'Policy-controlled deposit phase is never chased');
    $check(strpos(MBS_Payment_Chaser::chase_suppression_message(MBS_Bookings::get($normal)),'automatic payment policy')!==false,'Suppression explains the policy owns the deposit deadline');
    $wpdb->update($table,array('created_at'=>wp_date('Y-m-d H:i:s',time()-30*86400)),array('ref'=>$normal));
    $check(MBS_Payment_Chaser::should_chase_occurrence(MBS_Bookings::get($normal))===false,'Stale submission cannot chase inside the agreed window');
    $check(MBS_Payment_Chaser::send_chase(MBS_Bookings::get($normal))===false,'Deposit chase send is refused while the policy owns the deadline');
    $check((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue} WHERE reference_type='booking' AND reference_id=%d AND message_type='payment_chase'",MBS_Bookings::get($normal)->id))===0,'No chase email is queued for a policy-controlled deposit');
    $wpdb->update($table,array('amount_paid'=>'7.50','deposit_paid'=>'7.50','status'=>'deposit_paid'),array('ref'=>$normal));
    $check(MBS_Payment_Chaser::should_chase_occurrence(MBS_Bookings::get($normal))===true,'Balance chasing continues once the deposit is paid');
    $wpdb->update($table,array('amount_paid'=>'0.00','deposit_paid'=>'0.00','status'=>'confirmed'),array('ref'=>$normal));
    $short=$seed(2); $result=MBS_Invoice_Document_Service::confirm_and_issue($short,array(),true);
    $check(is_wp_error($result)&&!MBS_Payment_Expiry::get($short),'Short notice requires explicit acknowledgement');
    $result=MBS_Bookings::update_status($short,'confirmed',true,array('return_error'=>true));
    $check(is_wp_error($result)&&$result->get_error_code()==='expiry_short_notice_ack_required','Admin receives the exact acknowledgement rejection');
    $check(MBS_Bookings::update_status($short,'confirmed',true)===false,'Legacy boolean status contract retained');
    $result=MBS_Invoice_Document_Service::confirm_and_issue($short,array(),true,array('short_notice_ack'=>true));
    $check(!is_wp_error($result)&&json_decode(MBS_Payment_Expiry::get($short)->policy_json,true)['deposit_due_at']===null,'Acknowledged short notice uses full-payment cutoff');
    $short_doc=json_decode($wpdb->get_var($wpdb->prepare("SELECT snapshot_json FROM {$doc_table} WHERE id=%d",(int)MBS_Bookings::get($short)->current_invoice_document_id)),true);
    $check(!empty($short_doc['payment_schedule']['immediate'])&&empty($short_doc['payment_schedule']['deposit_due_at']),'Short-notice full-payment invoice invents no deposit deadline');
    $past=$seed(0); $result=MBS_Invoice_Document_Service::confirm_and_issue($past,array(),true,array('short_notice_ack'=>true));
    $check(is_wp_error($result),'Past cutoff cannot auto-enroll');
    $warn=$seed(); MBS_Invoice_Document_Service::confirm_and_issue($warn,array(),true); $age_deposit($warn,25);
    $check(!is_wp_error(MBS_Payment_Expiry::check($warn)),'Warning check succeeds');
    $warning=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$queue} WHERE message_key LIKE %s",'expiry_warning:'.$warn.':%'));
    $check($warning&&MBS_Payment_Expiry::email_current($warning),'Warning is durable/current');
    $render_queue=new ReflectionMethod('MBS_Payment_Expiry','queue');if(PHP_VERSION_ID<80100)$render_queue->setAccessible(true);
    $check($render_queue->invoke(null,MBS_Bookings::get($warn),'warning','A newer presentation must not rewrite this committed warning.',$warning->message_key)===true,'Formatting upgrade keeps committed warning idempotent');
    $check($wpdb->get_var($wpdb->prepare("SELECT body FROM {$queue} WHERE id=%d",$warning->id))===$warning->body,'Existing warning payload is not rewritten');
    MBS_Payment_Expiry::check($warn);
    $check((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue} WHERE message_key LIKE %s",'expiry_warning:'.$warn.':%'))===1,'Repeated worker does not duplicate warning');
    $wpdb->update($table,array('amount_paid'=>'7.50','status'=>'deposit_paid'),array('ref'=>$warn));
    $check(!MBS_Payment_Expiry::email_current($warning),'Deposit payment suppresses obsolete warning before transport');
    update_option('mbs_additional_emails','extra-admin@example.invalid, EXTRA-ADMIN@example.invalid, invalid, '.MBS_Bookings::get_admin_email());
    $due=$seed(); MBS_Invoice_Document_Service::confirm_and_issue($due,array(),true); $age_deposit($due,49);
    add_filter('query',$fail,PHP_INT_MAX); $result=MBS_Payment_Expiry::check($due); remove_filter('query',$fail,PHP_INT_MAX);
    $check(is_wp_error($result)&&MBS_Bookings::get($due)->status==='confirmed'&&MBS_Payment_Expiry::get($due)->state==='active','Failed outbox rolls cancellation and review back');
    $fail_extra=static function($sql)use($queue){return strpos($sql,'INSERT INTO')!==false&&strpos($sql,$queue)!==false&&strpos($sql,'extra-admin@example.invalid')!==false?'INVALID SYNTHETIC ADDITIONAL ADMIN INSERT':$sql;};
    add_filter('query',$fail_extra,PHP_INT_MAX);$result=MBS_Payment_Expiry::check($due);remove_filter('query',$fail_extra,PHP_INT_MAX);
    $check(is_wp_error($result)&&MBS_Bookings::get($due)->status==='confirmed'&&MBS_Payment_Expiry::get($due)->state==='active','Additional-recipient failure also rolls cancellation back');
    $check((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue} WHERE reference_id=%d AND message_type IN ('expiry_cancel','expiry_admin')",MBS_Bookings::get($due)->id))===0,'No partial hirer/admin intents escape rollback');
    $check(!is_wp_error(MBS_Payment_Expiry::check($due)),'Due unpaid deposit cancels after failure is removed');
    $check(MBS_Bookings::get($due)->status==='cancelled'&&MBS_Payment_Expiry::get($due)->state==='cancelled','No-money cancellation releases booking');
    MBS_Payment_Expiry::check($due);
    $check((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue} WHERE reference_type='booking' AND reference_id=%d AND message_type='expiry_admin'",MBS_Bookings::get($due)->id))===2,'Primary and deduplicated additional admin intent each occur once');
    $check((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue} WHERE message_key=%s",'expiry_cancel:'.$due))===1,'Hirer cancellation occurs once, separately from admin copies');
    $held=$seed(); MBS_Invoice_Document_Service::confirm_and_issue($held,array(),true);
    $wpdb->update($table,array('amount_paid'=>'7.50','deposit_paid'=>'7.50','status'=>'deposit_paid'),array('ref'=>$held));
    $row=MBS_Payment_Expiry::get($held); $p=json_decode($row->policy_json,true);
    $order=wc_create_order(); $orders[]=$order->get_id(); $item=new WC_Order_Item_Product(); $item->set_name('Synthetic original deposit'); $item->set_total('7.50'); $item->add_meta_data('_mbs_booking_ref',$held); $order->add_item($item); $order->set_total('7.50'); $order->set_currency('GBP'); $order->update_meta_data('_mbs_booking_ref',$held); $order->update_meta_data('_mbs_payment_processed','yes'); $order->set_status('completed'); $order->save();
    $check(count(MBS_Payment_Expiry::orders($held))===1,'Native order lookup finds exactly the associated order');
    $result=MBS_Payment_Expiry::check($held,0,$p['balance_cutoff_at']);
    $check(!is_wp_error($result)&&MBS_Bookings::get($held)->status==='cancelled','Unpaid balance cancels despite deposit');
    $check(MBS_Bookings::get($held)->amount_paid==='7.50'&&MBS_Payment_Expiry::get($held)->state==='money_review','Cancellation preserves receipt amount and flags review');
    $check(MBS_Payment_Expiry::review_balance(MBS_Payment_Expiry::get($held))['unresolved_minor']===750,'Money-held amount is explicit');
    $check(!MBS_Payment_Expiry::checkout_allowed($held),'Cancelled payment links/orders are not payable');
    $refund=wc_create_refund(array('order_id'=>$order->get_id(),'amount'=>'4.00','reason'=>'Synthetic partial refund','refund_payment'=>false,'restock_items'=>false));
    $check(!is_wp_error($refund),'Native synthetic partial refund recorded without gateway transfer');
    $check(MBS_Bookings::get($held)->status==='cancelled','Refund webhook cannot reopen cancelled hire');
    $balance=MBS_Payment_Expiry::review_balance(MBS_Payment_Expiry::get($held));
    $check(!is_wp_error($balance)&&$balance['refunded_minor']===400&&$balance['unresolved_minor']===350,'Partial refund leaves only the remainder unresolved');
    $check((bool)array_filter(MBS_Payment_Expiry::needs_attention(100),static function($x)use($held){return$x['ref']===$held;}),'Money-held review remains in dashboard attention');
    update_option('mbs_payment_expiry_enabled',0);
    $check(!MBS_Payment_Expiry::enabled(),'Policy can be suspended without changing records');
    $plain=$seed(); $plain_result=MBS_Invoice_Document_Service::confirm_and_issue($plain,array(),true);
    $check(!is_wp_error($plain_result)&&MBS_Payment_Expiry::get($plain)===null,'Suspended policy enrols nothing');
    $plain_doc=json_decode($wpdb->get_var($wpdb->prepare("SELECT snapshot_json FROM {$doc_table} WHERE id=%d",(int)MBS_Bookings::get($plain)->current_invoice_document_id)),true);
    $check(empty($plain_doc['payment_schedule']['deposit_due_at'])&&$plain_doc['payment_schedule']['deposit_due_date']===wp_date('Y-m-d'),'Suspended policy keeps legacy invoice terms');
    $check(strpos(MBS_Email::build_confirmation_message(MBS_Bookings::get($plain))['body'],'Deposit due now:')!==false,'Suspended policy keeps the existing email wording');
    $check(strpos(MBS_Invoice::generate_html(MBS_Bookings::get($plain)),'Immediately (deposit)')!==false,'Suspended policy keeps the existing preview wording');
    echo "PAYMENT_EXPIRY_OK: {$checks} assertions; all transport intercepted.\n";
} finally {
    remove_filter('query',$fail,PHP_INT_MAX); remove_filter('pre_wp_mail',$mail,PHP_INT_MAX); remove_filter('pre_http_request',$http,PHP_INT_MAX); $wpdb->query('ROLLBACK');
    foreach($orders as$id){$order=wc_get_order($id);if($order)$order->delete(true);}
    foreach($refs as$ref){$b=MBS_Bookings::get($ref);if($b)$wpdb->delete($queue,array('reference_type'=>'booking','reference_id'=>$b->id));$wpdb->delete($policy_table,array('booking_ref'=>$ref));$wpdb->delete($wpdb->prefix.MBS_INVOICE_DOCUMENTS_TABLE,array('booking_ref'=>$ref));$wpdb->delete($wpdb->prefix.'mathlin_audit_log',array('ref'=>$ref));$wpdb->delete($table,array('ref'=>$ref));}
    foreach($options as$key=>$value){if($value===null)delete_option($key);else update_option($key,$value);}
}
