<?php
/** Real WordPress/MariaDB invoice reconciliation regression; disposable test data only. */
global $wpdb;
$ref = 'INT-CUTOFF';
$early = wp_date('Y-m-d', strtotime('+10 days'));
$cutoff = wp_date('Y-m-d', strtotime('+20 days'));
$late = wp_date('Y-m-d', strtotime('+30 days'));
$now = current_time('mysql');
function cutoff_it_assert($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
$ok = $wpdb->insert($wpdb->prefix.MBS_SERIES_TABLE, array(
    'series_ref'=>$ref, 'status'=>'confirmed', 'version'=>1, 'contact_name'=>'Cutoff Integration', 'contact_email'=>'cutoff@example.invalid',
    'space'=>'Hall', 'attendees'=>1, 'purpose'=>'Cutoff regression', 'start_date'=>$early, 'repeat_until'=>$late,
    'recurrence_rule'=>'FREQ=WEEKLY;INTERVAL=1', 'schedule_json'=>wp_json_encode(array()),
    'price_per_booking'=>'10.00', 'estimated_total'=>'30.00', 'requested_count'=>3, 'accepted_count'=>3,
    'billing_mode'=>'monthly', 'billing_treatment'=>'invoice_managed', 'deposit_policy'=>'none', 'payment_method'=>'online',
    'invoice_lead_days'=>28, 'payment_terms_days'=>14, 'billing_schedule_json'=>wp_json_encode(array()), 'created_at'=>$now, 'updated_at'=>$now,
));
cutoff_it_assert($ok!==false, 'Could not seed cutoff series: '.$wpdb->last_error);
$bookings = array();
foreach (array($early,$cutoff,$late) as $index=>$date) {
    $booking_ref = 'INT-CUTOFF-'.$index;
    $ok = $wpdb->insert($wpdb->prefix.MBS_TABLE, array(
        'ref'=>$booking_ref, 'series_id'=>$ref, 'status'=>'confirmed', 'name'=>'Cutoff Integration', 'email'=>'cutoff@example.invalid',
        'phone'=>'000', 'address'=>'Disposable fixture', 'space'=>'Hall', 'booking_date'=>$date, 'booking_date_end'=>$date,
        'start_time'=>'10:00:00', 'end_time'=>'12:00:00', 'attendees'=>1, 'purpose'=>'Cutoff regression', 'amount'=>'10.00',
        'created_at'=>$now, 'updated_at'=>$now,
    ));
    cutoff_it_assert($ok!==false, 'Could not seed cutoff occurrence: '.$wpdb->last_error);
    $bookings[] = MBS_Bookings::get($booking_ref);
}
$draft = MBS_Billing_Ledger::create_draft_invoice(array(
    'series_ref'=>$ref, 'contact_name'=>'Cutoff Integration', 'contact_email'=>'cutoff@example.invalid', 'billing_mode'=>'monthly', 'currency'=>'GBP',
), 'integration-cutoff-invoice');
cutoff_it_assert(!is_wp_error($draft), 'Could not create cutoff invoice.');
$invoice = $draft['invoice'];
foreach (array_slice($bookings,0,2) as $booking) {
    $added = MBS_Billing_Ledger::add_item($invoice->invoice_ref, array(
        'booking_ref'=>$booking->ref, 'service_date'=>$booking->booking_date, 'description'=>'Cutoff fixture hire', 'quantity_milli'=>1000, 'unit_amount_minor'=>1000,
    ), (int)$invoice->version);
    cutoff_it_assert(!is_wp_error($added), 'Could not add cutoff invoice item.');
    $invoice = $added['invoice'];
}
$issued = MBS_Billing_Ledger::issue_invoice($invoice->invoice_ref,(int)$invoice->version);
cutoff_it_assert(!is_wp_error($issued), 'Could not issue cutoff invoice.');
$result = MBS_Series::cancel($ref,'from_date','confirmed',1,false,$cutoff);
cutoff_it_assert(!is_wp_error($result) && $result['cancelled']===2, 'Inclusive cutoff did not cancel exactly two sessions.');
cutoff_it_assert(MBS_Bookings::get($bookings[0]->ref)->status==='confirmed', 'Earlier occurrence was changed.');
cutoff_it_assert(MBS_Bookings::get($bookings[1]->ref)->status==='cancelled' && MBS_Bookings::get($bookings[2]->ref)->status==='cancelled', 'Cutoff/later occurrences remain active.');
cutoff_it_assert(MBS_Series::get($ref)->status==='confirmed', 'Retained sessions lost their series lifecycle.');
$invoice = MBS_Billing_Ledger::get_invoice($invoice->invoice_ref);
cutoff_it_assert(MBS_Billing_Ledger::balance_minor($invoice)===1000, 'Only the cancelled invoice line should be credited.');
$preview = MBS_Billing_Engine::preview($ref);
cutoff_it_assert(!is_wp_error($preview) && strpos(wp_json_encode($preview['periods']), $bookings[2]->ref)===false, 'Unissued cancelled session remains in billing preview.');
$repeat = MBS_Series::cancel($ref,'from_date','confirmed',2,false,$cutoff);
cutoff_it_assert(!is_wp_error($repeat) && $repeat['no_op'], 'Retry did not become a no-op.');
cutoff_it_assert(MBS_Billing_Ledger::balance_minor(MBS_Billing_Ledger::get_invoice($invoice->invoice_ref))===1000, 'Retry duplicated credits.');
echo "OK: date cancellation retained earlier sessions, reconciled a mixed invoice and excluded unissued sessions.\n";
