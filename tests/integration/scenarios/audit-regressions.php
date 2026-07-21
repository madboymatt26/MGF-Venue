<?php
global $wpdb;

$mbs_audit_failures = array();

function mbs_audit_case( $name, $callback ) {
    global $mbs_audit_failures;
    try {
        $callback();
        echo "OK: {$name}.\n";
    } catch ( Throwable $error ) {
        $mbs_audit_failures[] = $name . ': ' . $error->getMessage();
        fwrite( STDERR, "AUDIT REGRESSION: {$name}: {$error->getMessage()}\n" );
    }
}

function mbs_audit_assert( $condition, $message ) {
    if ( ! $condition ) throw new RuntimeException( $message );
}

function mbs_audit_booking( $ref, $amount = '10.00', $offset = 70 ) {
    global $wpdb;
    $date = wp_date( 'Y-m-d', strtotime( '+' . (int) $offset . ' days' ) );
    $inserted = $wpdb->insert( $wpdb->prefix . MBS_TABLE, array(
        'ref'=>$ref, 'status'=>'confirmed', 'name'=>'Adversarial Integration', 'organisation'=>'Integration',
        'email'=>'audit@example.invalid', 'phone'=>'000', 'address'=>'Disposable test', 'space'=>'Hall',
        'booking_date'=>$date, 'booking_date_end'=>$date, 'start_time'=>'19:00:00', 'end_time'=>'21:00:00',
        'attendees'=>10, 'purpose'=>'Audit regression', 'amount'=>$amount, 'deposit_paid'=>'0.00',
        'amount_paid'=>'0.00', 'created_at'=>current_time('mysql'), 'updated_at'=>current_time('mysql'),
    ) );
    if ( $inserted === false ) throw new RuntimeException( $wpdb->last_error );
    return MBS_Bookings::get( $ref );
}

function mbs_audit_invoice( $key, $booking ) {
    $draft = MBS_Billing_Ledger::create_draft_invoice( array(
        'contact_name'=>'Adversarial Integration', 'contact_email'=>'audit@example.invalid',
        'contact_address'=>'Disposable test', 'billing_mode'=>'monthly', 'currency'=>'GBP',
        'period_start'=>$booking->booking_date, 'period_end'=>$booking->booking_date,
        'due_at'=>wp_date('Y-m-d H:i:s', strtotime('+14 days')),
    ), 'audit-regression-' . $key );
    if ( is_wp_error( $draft ) ) throw new RuntimeException( $draft->get_error_message() );
    $added = MBS_Billing_Ledger::add_item( $draft['invoice']->invoice_ref, array(
        'item_type'=>'hire', 'booking_ref'=>$booking->ref, 'service_date'=>$booking->booking_date,
        'description'=>'Audit hire ' . $booking->ref, 'quantity_milli'=>1000,
        'unit_amount_minor'=>MBS_Money::from_decimal_string((string)$booking->amount),
        'pricing_snapshot'=>array('audit_regression'=>true),
    ), (int) $draft['invoice']->version );
    if ( is_wp_error( $added ) ) throw new RuntimeException( $added->get_error_message() );
    $issued = MBS_Billing_Ledger::issue_invoice( $added['invoice']->invoice_ref, (int) $added['invoice']->version );
    if ( is_wp_error( $issued ) ) throw new RuntimeException( $issued->get_error_message() );
    return $issued['invoice'];
}

function mbs_audit_order( $invoice ) {
    $claim = MBS_Invoice_Reservation::acquire( $invoice );
    if ( is_wp_error( $claim ) ) throw new RuntimeException( $claim->get_error_code() . ': ' . $claim->get_error_message() );
    $order = wc_create_order();
    if ( is_wp_error( $order ) ) throw new RuntimeException( $order->get_error_message() );
    $order->set_billing_email( 'audit@example.invalid' );
    $order->set_payment_method( 'mbs_test' );
    $order->save();
    $decimal = MBS_Money::decimal( (int) $claim['amount_minor'] );
    $item = new WC_Order_Item_Product();
    $item->set_product( wc_get_product( MBS_Woo_Payment::get_payment_product_id() ) );
    $item->set_quantity( 1 );
    $item->set_subtotal( $decimal );
    $item->set_total( $decimal );
    MBS_Woo_Payment::save_order_meta( $item, 'audit', array(
        'mbs_invoice_ref'=>$invoice->invoice_ref,
        'mbs_invoice_reservation_ref'=>$claim['reservation_ref'],
        'mbs_invoice_amount_minor'=>(int)$claim['amount_minor'],
    ), $order );
    $order->add_item( $item );
    $order->set_total( $decimal );
    $order->save();
    return array( wc_get_order($order->get_id()), $claim );
}

function mbs_audit_pay( $order ) {
    update_option( 'mbs_test_gateway_mode', 'success' );
    $gateway = WC()->payment_gateways()->payment_gateways()['mbs_test'];
    $result = $gateway->process_payment( $order->get_id() );
    if ( is_wp_error( $result ) ) throw new RuntimeException( $result->get_error_message() );
    return wc_get_order( $order->get_id() );
}

function mbs_audit_refund( $order, $amount, $reason ) {
    $refund = wc_create_refund( array(
        'amount'=>$amount, 'reason'=>$reason, 'order_id'=>$order->get_id(),
        'refund_payment'=>false, 'restock_items'=>false,
    ) );
    if ( is_wp_error( $refund ) ) throw new RuntimeException( $refund->get_error_message() );
    return $refund;
}

function mbs_audit_transaction_count( $invoice, $type ) {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare(
        'SELECT COUNT(*) FROM ' . $wpdb->prefix . MBS_PAYMENT_TRANSACTION_TABLE . ' WHERE invoice_id=%d AND transaction_type=%s',
        (int)$invoice->id, $type
    ) );
}

mbs_audit_case( 'Europe/London BST reservations bind before expiry', static function () {
    $old_timezone = get_option( 'timezone_string', '' );
    $old_offset = get_option( 'gmt_offset', 0 );
    update_option( 'timezone_string', 'Europe/London' );
    try {
        $booking = mbs_audit_booking( 'INT-A-BST', '10.00', 71 );
        $invoice = mbs_audit_invoice( 'bst', $booking );
        list( $order, $claim ) = mbs_audit_order( $invoice );
        $bound = MBS_Invoice_Reservation::bind_order( $invoice->invoice_ref, $claim['reservation_ref'], $order->get_id() );
        mbs_audit_assert( ! is_wp_error($bound), 'A newly acquired reservation was already expired in BST.' );
    } finally {
        update_option( 'timezone_string', $old_timezone );
        update_option( 'gmt_offset', $old_offset );
    }
} );

mbs_audit_case( 'a full refund opens a new payable balance generation', static function () {
    $booking = mbs_audit_booking( 'INT-A-REPAY-FULL', '10.00', 72 );
    $invoice = mbs_audit_invoice( 'repay-full', $booking );
    list( $order ) = mbs_audit_order( $invoice );
    $order = mbs_audit_pay( $order );
    mbs_audit_refund( $order, '10.00', 'Audit full refund' );
    $reopened = MBS_Billing_Ledger::get_invoice( $invoice->invoice_ref );
    $next = MBS_Invoice_Reservation::acquire( $reopened );
    mbs_audit_assert( ! is_wp_error($next) && (int)$next['amount_minor'] === 1000, 'Refund-reopened invoice cannot start a replacement checkout.' );
} );

mbs_audit_case( 'a partial refund opens a new payable balance generation', static function () {
    $booking = mbs_audit_booking( 'INT-A-REPAY-PART', '10.00', 73 );
    $invoice = mbs_audit_invoice( 'repay-part', $booking );
    list( $order ) = mbs_audit_order( $invoice );
    $order = mbs_audit_pay( $order );
    mbs_audit_refund( $order, '4.00', 'Audit partial refund' );
    $reopened = MBS_Billing_Ledger::get_invoice( $invoice->invoice_ref );
    $next = MBS_Invoice_Reservation::acquire( $reopened );
    mbs_audit_assert( ! is_wp_error($next) && (int)$next['amount_minor'] === 400, 'Part-refund balance cannot start a replacement checkout.' );
} );

mbs_audit_case( 'an altered Woo total cannot terminally own the remaining balance', static function () {
    $booking = mbs_audit_booking( 'INT-A-ALTERED', '10.00', 74 );
    $invoice = mbs_audit_invoice( 'altered-total', $booking );
    list( $order ) = mbs_audit_order( $invoice );
    foreach ( $order->get_items() as $item ) { $item->set_total('9.00'); $item->save(); }
    $order->set_total( '9.00' );
    $order->save();
    mbs_audit_pay( $order );
    $fresh = MBS_Billing_Ledger::get_invoice( $invoice->invoice_ref );
    $next = MBS_Invoice_Reservation::acquire( $fresh );
    mbs_audit_assert( (int)$fresh->paid_minor === 900 && !is_wp_error($next) && (int)$next['amount_minor'] === 100, 'A discounted/altered order terminally captured a partially paid invoice.' );
} );

mbs_audit_case( 'cancellation credit followed by cash refund reconciles the ledger', static function () {
    $booking = mbs_audit_booking( 'INT-A-CANCEL-REFUND', '10.00', 75 );
    $invoice = mbs_audit_invoice( 'cancel-refund', $booking );
    list( $order ) = mbs_audit_order( $invoice );
    $order = mbs_audit_pay( $order );
    global $wpdb;
    $wpdb->update( $wpdb->prefix.MBS_TABLE, array('status'=>'cancelled'), array('ref'=>$booking->ref) );
    $credit = MBS_Billing_Engine::reconcile_occurrences( array($booking->ref), true );
    mbs_audit_assert( !is_wp_error($credit), 'Could not establish the cancellation credit.' );
    $refund = mbs_audit_refund( $order, '10.00', 'Cash returned after cancellation credit' );
    $fresh = MBS_Billing_Ledger::get_invoice( $invoice->invoice_ref );
    $pending = (array) wc_get_order($order->get_id())->get_meta('_mbs_pending_invoice_refunds', true);
    mbs_audit_assert( (int)$fresh->paid_minor === 0 && mbs_audit_transaction_count($fresh,'refund') === 1 && !in_array($refund->get_id(),$pending,true), 'Cash left WooCommerce without a matching ledger refund.' );
} );

mbs_audit_case( 'duplicate refund callback is a clean successful replay', static function () {
    $booking = mbs_audit_booking( 'INT-A-DUP-REFUND', '10.00', 76 );
    $invoice = mbs_audit_invoice( 'duplicate-refund', $booking );
    list( $order ) = mbs_audit_order( $invoice );
    $order = mbs_audit_pay( $order );
    $refund = mbs_audit_refund( $order, '10.00', 'Duplicate replay' );
    $woo = new MBS_Woo_Payment();
    $woo->on_order_refunded( $order->get_id(), $refund->get_id() );
    $notes = wc_get_order_notes( array('order_id'=>$order->get_id(),'type'=>'internal') );
    $errors = array_filter( $notes, static function($note){ return stripos($note->content,'could not be recorded') !== false; } );
    mbs_audit_assert( mbs_audit_transaction_count($invoice,'refund') === 1 && !$errors, 'An idempotent refund replay emitted a reconciliation error.' );
} );

mbs_audit_case( 'credit notes remain in date-range accounting records', static function () {
    $booking = mbs_audit_booking( 'INT-A-EXPORT-CREDIT', '10.00', 77 );
    $invoice = mbs_audit_invoice( 'export-credit', $booking );
    $credit = MBS_Billing_Ledger::create_credit_note( $invoice->invoice_ref, 1000, 'Audit date-range credit', 'audit-export-credit' );
    if ( is_wp_error($credit) ) throw new RuntimeException($credit->get_error_message());
    $method = new ReflectionMethod( 'MBS_Accounting_Export', 'normalise_records' );
    $method->setAccessible( true );
    $today = wp_date('Y-m-d');
    $records = $method->invoke( null, array(), $today, $today );
    $refs = array_map( static function($record){return $record->invoice_number;}, $records );
    mbs_audit_assert( in_array($credit['credit_note']->invoice_ref,$refs,true), 'The issued credit note disappeared when a date range was supplied.' );
} );

mbs_audit_case( 'OSM receives partial and full refund reversals', static function () {
    global $wpdb;
    update_option('mbs_osm_enabled',true); update_option('mbs_osm_sandbox_mode',true);
    update_option('mbs_osm_section_id','audit-section'); update_option('mbs_osm_category_id','audit-category'); update_option('mbs_osm_account_id','audit-account');
    $booking = mbs_audit_booking( 'INT-A-OSM-REFUND', '10.00', 78 );
    $invoice = mbs_audit_invoice( 'osm-refund', $booking );
    list( $order ) = mbs_audit_order( $invoice );
    $order = mbs_audit_pay( $order );
    mbs_audit_refund( $order, '4.00', 'OSM partial reversal' );
    mbs_audit_refund( $order, '6.00', 'OSM full reversal' );
    $reversals = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}mathlin_audit_log WHERE ref=%s AND action='osm_sandbox_refund'",$booking->ref));
    mbs_audit_assert( $reversals === 2, 'OSM income was not offset by both refund events.' );
} );

mbs_audit_case( 'safe non-financial modification preserves issued history', static function () {
    global $wpdb;
    $booking = mbs_audit_booking( 'INT-A-SAFE-MOD', '10.00', 79 );
    $invoice = mbs_audit_invoice( 'safe-mod', $booking );
    $created = MBS_Modification::create_request(array('ref'=>$booking->ref,'type'=>'modify','changes'=>array('attendees'=>22)));
    mbs_audit_assert($created!==false,'Could not create modification request.');
    $request_id=(int)$wpdb->insert_id;
    $before_items=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix.MBS_INVOICE_ITEM_TABLE.' WHERE invoice_id=%d',(int)$invoice->id));
    $approved=MBS_Modification::approve($request_id);
    $fresh=MBS_Bookings::get($booking->ref);
    $after_items=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix.MBS_INVOICE_ITEM_TABLE.' WHERE invoice_id=%d',(int)$invoice->id));
    mbs_audit_assert(!is_wp_error($approved)&&(int)$fresh->attendees===22&&$before_items===$after_items&&(string)$fresh->amount==='10.00','A non-financial attendee edit was blocked or rewrote financial history.');
} );

if ( $mbs_audit_failures ) {
    throw new RuntimeException( count($mbs_audit_failures) . " adversarial regression(s) failed:\n- " . implode("\n- ",$mbs_audit_failures) );
}

echo "OK: all adversarial runtime regressions passed.\n";
