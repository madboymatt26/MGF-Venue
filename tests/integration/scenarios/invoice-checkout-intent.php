<?php
/** Disposable integration fixture/probe. Refuse all real venue sites. */
if (!class_exists('MBS_Test_Gateway') || get_bloginfo('name')!=='MGF Integration' || strpos(home_url(),'http://localhost:8088')!==0) throw new RuntimeException('Isolated local integration stack required');
global $wpdb;
$mode=$args[0]??'seed';
if($mode==='seed'){
 wp_set_current_user(1); add_filter('pre_wp_mail','__return_true');
 WC_Install::create_pages();
 $now=current_time('mysql');$date=wp_date('Y-m-d',time()-DAY_IN_SECONDS);$booking='INT-INTENT-'.strtoupper(substr(wp_generate_uuid4(),0,8));
 if($wpdb->insert($wpdb->prefix.MBS_TABLE,array('ref'=>$booking,'status'=>'archived','name'=>'Synthetic payment intent','email'=>'intent@example.invalid','space'=>'Main Scout Hall','booking_date'=>$date,'booking_date_end'=>$date,'amount'=>30,'amount_paid'=>30,'created_at'=>$now,'updated_at'=>$now))===false)throw new RuntimeException('Seed failed');
 $draft=MBS_Additional_Charges::create(array('related_ref'=>$booking,'amount'=>'25.00','category'=>'damage','description'=>'Synthetic intent test only','service_date'=>$date,'due_date'=>wp_date('Y-m-d',time()+14*DAY_IN_SECONDS),'contact_name'=>'Synthetic payment intent','contact_email'=>'intent@example.invalid'),wp_generate_uuid4());
 if(is_wp_error($draft))throw new RuntimeException($draft->get_error_message());
 $invoice=MBS_Additional_Charges::issue($draft->invoice_ref,$draft->version);
 if(is_wp_error($invoice))throw new RuntimeException($invoice->get_error_message());
 $url=MBS_Invoice_Payment::generate_payment_url($invoice);
 $fixture=array('invoice_ref'=>$invoice->invoice_ref,'payment_url'=>$url,'booking_ref'=>$booking,'booking_hash'=>hash('sha256',wp_json_encode(MBS_Bookings::get($booking))),'invoice_version'=>(int)$invoice->version,'email_count'=>(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->prefix.'mathlin_email_queue'));
 update_option('mbs_invoice_intent_fixture',$fixture,false);
 echo wp_json_encode(array('invoice_ref'=>$invoice->invoice_ref,'payment_url'=>$url)).PHP_EOL;return;
}
$fixture=get_option('mbs_invoice_intent_fixture');if(!$fixture)throw new RuntimeException('No fixture');
$invoice=MBS_Billing_Ledger::get_invoice($fixture['invoice_ref']);$claim=MBS_Invoice_Reservation::get($invoice->invoice_ref);
if($mode==='release'){
 if(!$claim || !MBS_Invoice_Reservation::release($invoice->invoice_ref,$claim->reservation_ref,'synthetic-test-release'))throw new RuntimeException('Release failed');
}elseif($mode==='stale'){
 $paid=MBS_Invoice_Payment::record_manual_payment($invoice->invoice_ref,500,wp_generate_uuid4(),$invoice->version,'Synthetic stale confirmation');
 if(is_wp_error($paid))throw new RuntimeException($paid->get_error_message());
}elseif($mode==='pristine'){
 if($claim || (int)$invoice->version!==$fixture['invoice_version'] || (int)$invoice->paid_minor!==0)throw new RuntimeException('Read/invalid HTTP request changed invoice or reservation');
}elseif($mode==='active'){
 if(!$claim || $claim->status!=='active' || $claim->order_id!==null || (int)$claim->amount_minor!==2500 || (int)$invoice->paid_minor!==0)throw new RuntimeException('Expected one active unbound claim and no payment');
 if((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix.MBS_PAYMENT_RESERVATION_TABLE.' WHERE invoice_ref=%s',$invoice->invoice_ref))!==1)throw new RuntimeException('Duplicate claim');
}elseif($mode==='unchanged-release'){
 if(!$claim || $claim->status!=='released' || (int)$invoice->paid_minor!==500)throw new RuntimeException('Stale confirmation acquired a claim');
}else throw new RuntimeException('Unknown mode');
if(hash('sha256',wp_json_encode(MBS_Bookings::get($fixture['booking_ref'])))!==$fixture['booking_hash'])throw new RuntimeException('Original booking changed');
if(in_array($mode,array('pristine','active'),true) && (int)$wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->prefix.'mathlin_email_queue')!==$fixture['email_count'])throw new RuntimeException('HTTP confirmation sent/queued mail');
echo 'OK: invoice intent '.$mode.PHP_EOL;
