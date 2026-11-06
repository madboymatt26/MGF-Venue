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
    $short=$seed(2); $result=MBS_Invoice_Document_Service::confirm_and_issue($short,array(),true);
    $check(is_wp_error($result)&&!MBS_Payment_Expiry::get($short),'Short notice requires explicit acknowledgement');
    $result=MBS_Bookings::update_status($short,'confirmed',true,array('return_error'=>true));
    $check(is_wp_error($result)&&$result->get_error_code()==='expiry_short_notice_ack_required','Admin receives the exact acknowledgement rejection');
    $check(MBS_Bookings::update_status($short,'confirmed',true)===false,'Legacy boolean status contract retained');
    $result=MBS_Invoice_Document_Service::confirm_and_issue($short,array(),true,array('short_notice_ack'=>true));
    $check(!is_wp_error($result)&&json_decode(MBS_Payment_Expiry::get($short)->policy_json,true)['deposit_due_at']===null,'Acknowledged short notice uses full-payment cutoff');
    $past=$seed(0); $result=MBS_Invoice_Document_Service::confirm_and_issue($past,array(),true,array('short_notice_ack'=>true));
    $check(is_wp_error($result),'Past cutoff cannot auto-enroll');
    $warn=$seed(); MBS_Invoice_Document_Service::confirm_and_issue($warn,array(),true); $age_deposit($warn,25);
    $check(!is_wp_error(MBS_Payment_Expiry::check($warn)),'Warning check succeeds');
    $warning=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$queue} WHERE message_key LIKE %s",'expiry_warning:'.$warn.':%'));
    $check($warning&&MBS_Payment_Expiry::email_current($warning),'Warning is durable/current');
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
    echo "PAYMENT_EXPIRY_OK: {$checks} assertions; all transport intercepted.\n";
} finally {
    remove_filter('query',$fail,PHP_INT_MAX); remove_filter('pre_wp_mail',$mail,PHP_INT_MAX); remove_filter('pre_http_request',$http,PHP_INT_MAX); $wpdb->query('ROLLBACK');
    foreach($orders as$id){$order=wc_get_order($id);if($order)$order->delete(true);}
    foreach($refs as$ref){$b=MBS_Bookings::get($ref);if($b)$wpdb->delete($queue,array('reference_type'=>'booking','reference_id'=>$b->id));$wpdb->delete($policy_table,array('booking_ref'=>$ref));$wpdb->delete($wpdb->prefix.MBS_INVOICE_DOCUMENTS_TABLE,array('booking_ref'=>$ref));$wpdb->delete($wpdb->prefix.'mathlin_audit_log',array('ref'=>$ref));$wpdb->delete($table,array('ref'=>$ref));}
    foreach($options as$key=>$value){if($value===null)delete_option($key);else update_option($key,$value);}
}
