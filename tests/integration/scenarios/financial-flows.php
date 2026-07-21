<?php
global $wpdb;

function mbs_it_assert( $condition, $message ) {
    if ( ! $condition ) throw new RuntimeException( $message );
}

function mbs_it_booking( $ref, $amount, $date_offset = 7 ) {
    global $wpdb;
    $date = wp_date( 'Y-m-d', strtotime( '+' . (int) $date_offset . ' days' ) );
    $ok = $wpdb->insert( $wpdb->prefix . MBS_TABLE, array(
        'ref'=>$ref,'status'=>'confirmed','name'=>'Financial Integration','organisation'=>'Integration',
        'email'=>'financial@example.invalid','phone'=>'000','address'=>'Disposable test','space'=>'Hall','kitchen'=>0,
        'booking_date'=>$date,'booking_date_end'=>$date,'all_day'=>0,'scout_use'=>0,'pricing_tier'=>'standard',
        'start_time'=>'19:00:00','end_time'=>'21:00:00','attendees'=>10,'purpose'=>'Financial integration',
        'amount'=>$amount,'deposit_paid'=>'0.00','amount_paid'=>'0.00','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql'),
    ) );
    if ( $ok === false ) throw new RuntimeException( $wpdb->last_error );
    return MBS_Bookings::get( $ref );
}

function mbs_it_invoice( $key, $bookings ) {
    $draft = MBS_Billing_Ledger::create_draft_invoice( array(
        'contact_name'=>'Financial Integration','contact_organisation'=>'Integration','contact_email'=>'financial@example.invalid',
        'contact_address'=>'Disposable test','billing_mode'=>'monthly','currency'=>'GBP',
        'period_start'=>wp_date('Y-m-01'),'period_end'=>wp_date('Y-m-t'),'due_at'=>wp_date('Y-m-d H:i:s',strtotime('+14 days')),
    ), 'integration-finance-' . $key );
    if ( is_wp_error( $draft ) ) throw new RuntimeException( $draft->get_error_message() );
    $invoice = $draft['invoice'];
    foreach ( $bookings as $booking ) {
        $minor = MBS_Money::from_decimal_string( (string) $booking->amount );
        $added = MBS_Billing_Ledger::add_item( $invoice->invoice_ref, array(
            'item_type'=>'hire','booking_ref'=>$booking->ref,'service_date'=>$booking->booking_date,
            'description'=>'Integration hire ' . $booking->ref,'quantity_milli'=>1000,'unit_amount_minor'=>$minor,
            'pricing_snapshot'=>array('integration'=>true),
        ), (int) $invoice->version );
        if ( is_wp_error( $added ) ) throw new RuntimeException( $added->get_error_message() );
        $invoice = $added['invoice'];
    }
    $issued = MBS_Billing_Ledger::issue_invoice( $invoice->invoice_ref, (int) $invoice->version );
    if ( is_wp_error( $issued ) ) throw new RuntimeException( $issued->get_error_message() );
    return $issued['invoice'];
}

function mbs_it_order( $invoice ) {
    $claim = MBS_Invoice_Reservation::acquire( $invoice );
    if ( is_wp_error( $claim ) ) throw new RuntimeException( $claim->get_error_message() );
    $order = wc_create_order();
    if ( is_wp_error( $order ) ) throw new RuntimeException( $order->get_error_message() );
    $order->set_billing_email( 'financial@example.invalid' );
    $order->set_payment_method( 'mbs_test' );
    $order->set_currency( $invoice->currency );
    $order->save();
    $minor = (int) $claim['amount_minor'];
    $decimal = MBS_Money::decimal( $minor );
    $item = new WC_Order_Item_Product();
    $item->set_product( wc_get_product( MBS_Woo_Payment::get_payment_product_id() ) );
    $item->set_quantity( 1 );
    $item->set_subtotal( $decimal );
    $item->set_total( $decimal );
    MBS_Woo_Payment::save_order_meta( $item, 'integration', array(
        'mbs_invoice_ref'=>$invoice->invoice_ref,
        'mbs_invoice_reservation_ref'=>$claim['reservation_ref'],
        'mbs_invoice_amount_minor'=>$minor,
    ), $order );
    $order->add_item( $item );
    $order->set_total( $decimal );
    $order->save();
    return array( wc_get_order( $order->get_id() ), $claim );
}

function mbs_it_gateway_pay( $order, $mode = 'success' ) {
    update_option( 'mbs_test_gateway_mode', $mode );
    $gateways = WC()->payment_gateways()->payment_gateways();
    $result = $gateways['mbs_test']->process_payment( $order->get_id() );
    if ( is_wp_error( $result ) ) throw new RuntimeException( $result->get_error_message() );
    return wc_get_order( $order->get_id() );
}

function mbs_it_refund( $order_id, $amount, $reason ) {
    $refund = wc_create_refund( array(
        'amount'=>$amount,'reason'=>$reason,'order_id'=>$order_id,'refund_payment'=>false,'restock_items'=>false,
    ) );
    if ( is_wp_error( $refund ) ) throw new RuntimeException( $refund->get_error_message() );
    return $refund;
}

function mbs_it_transaction_count( $invoice, $type ) {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare(
        'SELECT COUNT(*) FROM ' . $wpdb->prefix . MBS_PAYMENT_TRANSACTION_TABLE . ' WHERE invoice_id=%d AND transaction_type=%s',
        (int) $invoice->id, $type
    ) );
}

// Successful capture, duplicate callbacks, and two-order exclusion.
$booking = mbs_it_booking( 'INT-F-PAY', '10.00' );
$invoice = mbs_it_invoice( 'pay', array( $booking ) );
list( $order, $claim ) = mbs_it_order( $invoice );
$second_owner = MBS_Invoice_Reservation::acquire( $invoice );
mbs_it_assert( is_wp_error( $second_owner ) && $second_owner->get_error_code() === 'invoice_payment_reserved', 'A second order could target the bound invoice.' );
$order = mbs_it_gateway_pay( $order );
do_action( 'woocommerce_payment_complete', $order->get_id() );
do_action( 'woocommerce_order_status_processing', $order->get_id() );
$paid = MBS_Billing_Ledger::get_invoice( $invoice->invoice_ref );
$paid_booking = MBS_Bookings::get( $booking->ref );
$captured = MBS_Invoice_Reservation::get( $invoice->invoice_ref );
mbs_it_assert( $paid->status === 'paid' && (int)$paid->paid_minor === 1000, 'Successful Woo capture did not settle the invoice.' );
mbs_it_assert( $paid_booking->status === 'paid' && (string)$paid_booking->amount_paid === '10.00', 'Successful Woo capture did not settle its occurrence.' );
mbs_it_assert( $captured->status === 'captured' && (int)$captured->order_id === $order->get_id(), 'Successful Woo capture did not finalise ownership.' );
mbs_it_assert( mbs_it_transaction_count( $paid, 'payment' ) === 1, 'Duplicate payment callbacks duplicated the ledger transaction.' );

// Full refund and duplicate callback through real Woo refund objects/hooks.
$full_refund = mbs_it_refund( $order->get_id(), '10.00', 'Full integration refund' );
do_action( 'woocommerce_order_refunded', $order->get_id(), $full_refund->get_id() );
$full = MBS_Billing_Ledger::get_invoice( $invoice->invoice_ref );
$full_booking = MBS_Bookings::get( $booking->ref );
mbs_it_assert( (int)$full->paid_minor === 0 && $full->status === 'issued', 'Full refund did not reopen the invoice.' );
mbs_it_assert( $full_booking->status === 'confirmed' && (string)$full_booking->amount_paid === '0.00' && (int)$full_booking->access_sent === 0, 'Full refund did not restore the occurrence and access state.' );
mbs_it_assert( mbs_it_transaction_count( $full, 'refund' ) === 1, 'Duplicate full-refund callback duplicated the ledger refund.' );

// Partial, item-specific, and multiple cumulative refunds.
$b1 = mbs_it_booking( 'INT-F-R1', '10.00', 8 );
$b2 = mbs_it_booking( 'INT-F-R2', '10.00', 9 );
$multi_invoice = mbs_it_invoice( 'multi-refund', array( $b1, $b2 ) );
list( $multi_order, $multi_claim ) = mbs_it_order( $multi_invoice );
$multi_order = mbs_it_gateway_pay( $multi_order );
$r1 = mbs_it_refund( $multi_order->get_id(), '5.00', 'Partial item refund' );
$after_r1_b1 = MBS_Bookings::get( $b1->ref );
$after_r1_b2 = MBS_Bookings::get( $b2->ref );
mbs_it_assert( $after_r1_b1->status === 'paid' && $after_r1_b2->status === 'deposit_paid' && (string)$after_r1_b2->amount_paid === '5.00', 'Latest-item partial refund changed the wrong occurrence.' );
do_action( 'woocommerce_order_refunded', $multi_order->get_id(), $r1->get_id() );
$r2 = mbs_it_refund( $multi_order->get_id(), '5.00', 'Complete second item refund' );
$r3 = mbs_it_refund( $multi_order->get_id(), '10.00', 'Complete first item refund' );
$multi_fresh = MBS_Billing_Ledger::get_invoice( $multi_invoice->invoice_ref );
mbs_it_assert( (int)$multi_fresh->paid_minor === 0 && mbs_it_transaction_count( $multi_fresh, 'refund' ) === 3, 'Cumulative refunds did not reconcile exactly once each.' );
mbs_it_assert( MBS_Bookings::get($b1->ref)->status === 'confirmed' && MBS_Bookings::get($b2->ref)->status === 'confirmed', 'Cumulative item refunds did not reopen both occurrences.' );
$over = MBS_Invoice_Payment::record_gateway_refund( $multi_invoice->invoice_ref, '0.01', $multi_order->get_id(), 999999 );
mbs_it_assert( is_wp_error( $over ) && in_array( $over->get_error_code(), array('refund_exceeds_payment','refund_exceeds_paid'), true ), 'Over-refund was accepted.' );

// Refund-before-payment ordering queues and replays after the payment ledger row exists.
$before_booking = mbs_it_booking( 'INT-F-BEFORE', '10.00', 10 );
$before_invoice = mbs_it_invoice( 'refund-before', array( $before_booking ) );
list( $before_order, $before_claim ) = mbs_it_order( $before_invoice );
$early_refund = mbs_it_refund( $before_order->get_id(), '5.00', 'Out of order refund' );
$before_order = wc_get_order( $before_order->get_id() );
mbs_it_assert( in_array( $early_refund->get_id(), (array)$before_order->get_meta('_mbs_pending_invoice_refunds',true), true ), 'Refund-before-payment was not queued.' );
$before_order = mbs_it_gateway_pay( $before_order );
$before_fresh = MBS_Billing_Ledger::get_invoice( $before_invoice->invoice_ref );
mbs_it_assert( (int)$before_fresh->paid_minor === 500 && mbs_it_transaction_count($before_fresh,'payment') === 1 && mbs_it_transaction_count($before_fresh,'refund') === 1, 'Queued refund was not replayed after payment.' );

// Delayed callbacks remain authoritative after the original reservation lifetime.
$delay_booking = mbs_it_booking( 'INT-F-DELAY', '10.00', 11 );
$delay_invoice = mbs_it_invoice( 'delay', array( $delay_booking ) );
list( $delay_order, $delay_claim ) = mbs_it_order( $delay_invoice );
$wpdb->update( $wpdb->prefix.MBS_PAYMENT_RESERVATION_TABLE, array('expires_at'=>'2000-01-01 00:00:00'), array('invoice_ref'=>$delay_invoice->invoice_ref) );
$delay_order = mbs_it_gateway_pay( $delay_order );
mbs_it_assert( MBS_Billing_Ledger::get_invoice($delay_invoice->invoice_ref)->status === 'paid', 'Delayed bound callback was rejected after reservation lifetime.' );

// Bound-order cancellation releases ownership and permits a new checkout.
$cancel_booking = mbs_it_booking( 'INT-F-CANCEL', '10.00', 12 );
$cancel_invoice = mbs_it_invoice( 'cancel', array( $cancel_booking ) );
list( $cancel_order, $cancel_claim ) = mbs_it_order( $cancel_invoice );
$cancel_order->update_status( 'cancelled', 'Integration cancellation during checkout.' );
$cancelled_claim = MBS_Invoice_Reservation::get( $cancel_invoice->invoice_ref );
$replacement_claim = MBS_Invoice_Reservation::acquire( $cancel_invoice );
if ( $cancelled_claim->status !== 'released' || is_wp_error($replacement_claim) || $replacement_claim['reservation_ref'] === $cancel_claim['reservation_ref'] ) {
    $fresh_order=wc_get_order($cancel_order->get_id());
    throw new RuntimeException(sprintf(
        'Cancellation did not release and replace checkout ownership (order_status=%s order_invoice=%s order_reservation=%s claim_status=%s claim_order=%d replacement=%s).',
        $fresh_order?$fresh_order->get_status():'missing',
        $fresh_order?(string)$fresh_order->get_meta('_mbs_invoice_ref'):'',
        $fresh_order?(string)$fresh_order->get_meta('_mbs_invoice_reservation_ref'):'',
        $cancelled_claim?(string)$cancelled_claim->status:'missing',
        $cancelled_claim?(int)$cancelled_claim->order_id:0,
        is_wp_error($replacement_claim)?$replacement_claim->get_error_code():(string)$replacement_claim['reservation_ref']
    ));
}

// Captured-but-unrecorded reconciliation and verified administrator resolution.
$rec_booking = mbs_it_booking( 'INT-F-RECON', '10.00', 13 );
$rec_invoice = mbs_it_invoice( 'reconcile', array( $rec_booking ) );
list( $rec_order, $rec_claim ) = mbs_it_order( $rec_invoice );
$rec_order->update_meta_data( '_mbs_test_force_ledger_failure', 'yes' );
$rec_order->save();
$rec_order->payment_complete( 'mbs-test-reconciliation' );
$recon = MBS_Invoice_Reservation::get( $rec_invoice->invoice_ref );
mbs_it_assert( $recon->status === 'reconciliation_required' && mbs_it_transaction_count($rec_invoice,'payment') === 0, 'Captured ledger failure was not quarantined.' );
$recorded = MBS_Invoice_Payment::record_gateway_payment( $rec_invoice->invoice_ref, '10.00', $rec_order->get_id(), $rec_claim['reservation_ref'] );
mbs_it_assert( !is_wp_error($recorded), 'Administrator could not safely record the captured payment.' );
$resolved = MBS_Invoice_Reservation::resolve( $rec_invoice->invoice_ref, $rec_claim['reservation_ref'], $rec_order->get_id(), 'ledger_recorded' );
mbs_it_assert( $resolved === true && MBS_Invoice_Reservation::get($rec_invoice->invoice_ref)->status === 'captured', 'Verified administrator reconciliation did not resolve ownership.' );

// A pre-existing partial payment reserves and captures only the outstanding balance.
$partial_booking = mbs_it_booking( 'INT-F-PART', '10.00', 14 );
$partial_invoice = mbs_it_invoice( 'partial', array( $partial_booking ) );
$manual = MBS_Invoice_Payment::record_manual_payment( $partial_invoice->invoice_ref, 400, 'integration-partial-manual', (int)$partial_invoice->version, 'Integration part payment' );
mbs_it_assert( !is_wp_error($manual), 'Could not create partial outstanding balance.' );
list( $partial_order, $partial_claim ) = mbs_it_order( $manual['invoice'] );
mbs_it_assert( (int)$partial_claim['amount_minor'] === 600 && (string)$partial_order->get_total() === '6.00', 'Checkout did not use the partial outstanding balance.' );
mbs_it_gateway_pay( $partial_order );
$partial_fresh = MBS_Billing_Ledger::get_invoice( $partial_invoice->invoice_ref );
mbs_it_assert( $partial_fresh->status === 'paid' && (int)$partial_fresh->paid_minor === 1000 && mbs_it_transaction_count($partial_fresh,'payment') === 2, 'Partial balance was not settled exactly.' );

echo "OK: real Woo payment ownership, callback ordering, reconciliation, partial balance, and refund state machines passed.\n";
