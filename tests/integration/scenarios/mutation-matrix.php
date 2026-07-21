<?php
global $wpdb;
$series_ref='INT-MUT';
$series_table=$wpdb->prefix.MBS_SERIES_TABLE;
$booking_table=$wpdb->prefix.MBS_TABLE;
$date=wp_date('Y-m-d',strtotime('+45 days'));
$now=current_time('mysql');

$wpdb->insert($series_table,array(
    'series_ref'=>$series_ref,'status'=>'confirmed','version'=>1,'contact_name'=>'Mutation Integration','contact_email'=>'mutation@example.invalid',
    'space'=>'Hall','attendees'=>1,'purpose'=>'Mutation matrix','start_date'=>$date,'repeat_until'=>$date,'recurrence_rule'=>'FREQ=WEEKLY;INTERVAL=1',
    'schedule_json'=>wp_json_encode(array()),'price_per_booking'=>'10.00','estimated_total'=>'50.00','requested_count'=>5,'accepted_count'=>5,
    'billing_mode'=>'monthly','billing_treatment'=>'invoice_managed','deposit_policy'=>'none','payment_method'=>'online','invoice_lead_days'=>365,
    'payment_terms_days'=>14,'billing_schedule_json'=>wp_json_encode(array()),'created_at'=>$now,'updated_at'=>$now,
));
if($wpdb->last_error)throw new RuntimeException($wpdb->last_error);

function mbs_mut_booking($ref){
    global $wpdb,$booking_table,$series_ref,$date,$now;
    $ok=$wpdb->insert($booking_table,array(
        'ref'=>$ref,'status'=>'confirmed','name'=>'Mutation Integration','email'=>'mutation@example.invalid','phone'=>'000','address'=>'Test','space'=>'Hall',
        'booking_date'=>$date,'booking_date_end'=>$date,'attendees'=>1,'purpose'=>'Mutation matrix','amount'=>'10.00','series_id'=>$series_ref,
        'created_at'=>$now,'updated_at'=>$now,
    ));
    if($ok===false)throw new RuntimeException($wpdb->last_error);
    return MBS_Bookings::get($ref);
}
function mbs_mut_invoice($key,$booking){
    $draft=MBS_Billing_Ledger::create_draft_invoice(array(
        'series_ref'=>'INT-MUT','contact_name'=>'Mutation Integration','contact_email'=>'mutation@example.invalid','billing_mode'=>'monthly','currency'=>'GBP',
    ),'mutation-'.$key);
    if(is_wp_error($draft))throw new RuntimeException($draft->get_error_message());
    $invoice=$draft['invoice'];
    $added=MBS_Billing_Ledger::add_item($invoice->invoice_ref,array('booking_ref'=>$booking->ref,'service_date'=>$booking->booking_date,'description'=>$key,'quantity_milli'=>1000,'unit_amount_minor'=>1000),(int)$invoice->version);
    if(is_wp_error($added))throw new RuntimeException($added->get_error_message());
    $issued=MBS_Billing_Ledger::issue_invoice($invoice->invoice_ref,(int)$added['invoice']->version);
    if(is_wp_error($issued))throw new RuntimeException($issued->get_error_message());
    return $issued['invoice'];
}

$unbilled=mbs_mut_booking('INT-M-UNBILLED');
$invoiced_booking=mbs_mut_booking('INT-M-INVOICED');
$paid_booking=mbs_mut_booking('INT-M-PAID');
$credited_booking=mbs_mut_booking('INT-M-CREDIT');
$refunded_booking=mbs_mut_booking('INT-M-REFUND');
$invoiced=mbs_mut_invoice('invoiced',$invoiced_booking);
$paid_invoice=mbs_mut_invoice('paid',$paid_booking);
$credited_invoice=mbs_mut_invoice('credited',$credited_booking);
$refunded_invoice=mbs_mut_invoice('refunded',$refunded_booking);
$paid=MBS_Invoice_Payment::record_manual_payment($paid_invoice->invoice_ref,1000,'mutation-paid',(int)$paid_invoice->version,'Mutation paid state');
$credited=MBS_Billing_Engine::reconcile_occurrences(array($credited_booking->ref),true);
$refunded_payment=MBS_Invoice_Payment::record_manual_payment($refunded_invoice->invoice_ref,1000,'mutation-refunded-payment',(int)$refunded_invoice->version,'Mutation refunded state');
if(is_wp_error($paid)||is_wp_error($credited)||is_wp_error($refunded_payment))throw new RuntimeException('Could not establish paid and credited mutation states.');
$refund=MBS_Billing_Ledger::record_transaction($refunded_invoice->invoice_ref,array(
    'provider'=>'manual','provider_transaction_id'=>'mutation-refund','transaction_type'=>'refund','status'=>'completed','amount_minor'=>1000,
    'parent_transaction_id'=>(int)$refunded_payment['transaction']->id,'idempotency_key'=>'mutation-refund','metadata'=>array('integration'=>true),
));
if(is_wp_error($refund))throw new RuntimeException('Could not establish refunded mutation state.');

$states=array(
    'unbilled'=>$unbilled->ref,'invoiced'=>$invoiced_booking->ref,'paid'=>$paid_booking->ref,
    'credited'=>$credited_booking->ref,'refunded'=>$refunded_booking->ref,
);
$operations=array(
    'update_status'=>static function(){return MBS_Bookings::update_series_status('INT-MUT','confirmed');},
    'cancel_future'=>static function(){return MBS_Bookings::cancel_series_future('INT-MUT');},
    'edit_future'=>static function(){return MBS_Bookings::update_series_future('INT-MUT',array('purpose'=>'bypass'));},
    'extend'=>static function(){return MBS_Bookings::extend_series('INT-MUT',wp_date('Y-m-d',strtotime('+60 days')));},
    'reopen'=>static function(){return MBS_Bookings::reopen_series_future('INT-MUT');},
    'delete'=>static function(){return MBS_Bookings::delete_series('INT-MUT','all');},
);
foreach($operations as $name=>$operation){$result=$operation();if(!is_wp_error($result)||$result->get_error_code()!=='canonical_series_required')throw new RuntimeException($name.' bypassed the canonical first-class series service.');}

$api=new MBS_Rest_API();
foreach($states as $state=>$ref){
    $request=new WP_REST_Request('POST','/mathlin/v1/bookings/'.$ref.'/status');
    $request->set_param('ref',$ref);$request->set_param('status','cancelled');
    $result=$api->update_status($request);
    if(!is_wp_error($result)||$result->get_error_code()!=='series_operation_required'||MBS_Bookings::get($ref)->status==='cancelled')throw new RuntimeException('Public REST mutated the '.$state.' first-class occurrence.');
}
foreach(array($invoiced_booking->ref,$paid_booking->ref,$credited_booking->ref,$refunded_booking->ref) as $ref){
    if(MBS_Bookings::update_status($ref,'cancelled')!==false||MBS_Bookings::delete($ref)!==false)throw new RuntimeException('Direct one-off service mutated a financially historical occurrence '.$ref.'.');
}
echo "OK: direct, legacy/Scout, and public REST mutation routes protected unbilled, invoiced, paid, credited, and refunded first-class occurrences.\n";
