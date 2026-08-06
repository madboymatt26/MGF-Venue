<?php
global $wpdb;
require_once __DIR__ . '/audit-assertions.php';

function mbs_audit_case( $name, $callback ) {
    MBS_Audit_Assertions::current()->run( $name, $callback );
}

function mbs_audit_assert( $condition, $message ) {
    MBS_Audit_Assertions::assert_that( $condition, $message );
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
    $order->set_currency( $invoice->currency );
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

function mbs_audit_reconciled_mismatch( $key, $mutator ) {
    $booking = mbs_audit_booking( 'INT-A-' . preg_replace('/[^A-Z0-9]/','',strtoupper($key)), '10.00', 90 + strlen( $key ) );
    $invoice = mbs_audit_invoice( 'exact-' . $key, $booking );
    list( $order ) = mbs_audit_order( $invoice );
    $mutator( $order, $invoice );
    $order->save();
    mbs_audit_pay( $order );
    $fresh_invoice = MBS_Billing_Ledger::get_invoice( $invoice->invoice_ref );
    $fresh_order = wc_get_order( $order->get_id() );
    mbs_audit_assert(
        (int)$fresh_invoice->paid_minor === 0 && $fresh_order->get_meta('_mbs_invoice_reconciliation_required') === 'yes',
        $key . ' mismatch was silently accepted as an online invoice payment.'
    );
}

function mbs_audit_export_text($method_name,$records){
    $stream=fopen('php://temp','w+');$method=new ReflectionMethod('MBS_Accounting_Export',$method_name);$method->setAccessible(true);$method->invoke(null,$stream,$records);rewind($stream);$text=stream_get_contents($stream);fclose($stream);return $text;
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

mbs_audit_case( 'reservation storage remains UTC across GMT, BST, positive offset, and DST boundary instants', static function () {
    $old_timezone=get_option('timezone_string','');$old_offset=get_option('gmt_offset',0);
    $cases=array(
        array('UTC','2026-01-15 12:00:00','GMT'),
        array('Europe/London','2026-07-15 12:00:00','BST'),
        array('Asia/Kolkata','2026-07-15 12:00:00','POS'),
        array('Europe/London','2026-03-29 00:59:00','DSTPRE'),
        array('Europe/London','2026-03-29 01:01:00','DSTPOST'),
    );
    try{
        foreach($cases as $index=>$case){
            list($timezone,$utc,$suffix)=$case;update_option('timezone_string',$timezone);update_option('gmt_offset',0);
            $timestamp=strtotime($utc.' UTC');$clock=static function()use($timestamp){return $timestamp;};add_filter('mbs_invoice_reservation_utc_timestamp',$clock);
            try{
                $booking=mbs_audit_booking('INT-A-TZ-'.$suffix,'10.00',105+$index);
                $invoice=mbs_audit_invoice('timezone-'.strtolower($suffix),$booking);
                list($order,$claim)=mbs_audit_order($invoice);
                mbs_audit_assert($claim['created_at']===gmdate('Y-m-d H:i:s',$timestamp)&&$claim['expires_at']===gmdate('Y-m-d H:i:s',$timestamp+MBS_Invoice_Reservation::TTL),'Reservation timestamps were not stored from the injected UTC instant for '.$suffix.'.');
            }finally{remove_filter('mbs_invoice_reservation_utc_timestamp',$clock);}
        }
    }finally{update_option('timezone_string',$old_timezone);update_option('gmt_offset',$old_offset);}
} );

mbs_audit_case( 'over-reserved Woo total enters reconciliation', static function () {
    mbs_audit_reconciled_mismatch( 'OVER', static function ( $order ) { $order->set_total('11.00'); } );
} );

mbs_audit_case( 'coupon-adjusted invoice order enters reconciliation', static function () {
    mbs_audit_reconciled_mismatch( 'COUPON', static function ( $order ) {
        $coupon = new WC_Order_Item_Coupon();
        $coupon->set_code( 'audit-only' ); $coupon->set_discount( '1.00' ); $coupon->set_discount_tax( '0.00' );
        $order->add_item( $coupon ); $order->set_total( '9.00' );
    } );
} );

mbs_audit_case( 'positive invoice-order fee enters reconciliation', static function () {
    mbs_audit_reconciled_mismatch( 'POSFEE', static function ( $order ) {
        $fee=new WC_Order_Item_Fee();$fee->set_name('Audit fee');$fee->set_amount('1.00');$fee->set_total('1.00');$order->add_item($fee);$order->set_total('11.00');
    } );
} );

mbs_audit_case( 'negative invoice-order fee enters reconciliation', static function () {
    mbs_audit_reconciled_mismatch( 'NEG FEE', static function ( $order ) {
        $fee=new WC_Order_Item_Fee();$fee->set_name('Audit discount');$fee->set_amount('-1.00');$fee->set_total('-1.00');$order->add_item($fee);$order->set_total('9.00');
    } );
} );

mbs_audit_case( 'wrong-currency invoice order enters reconciliation', static function () {
    mbs_audit_reconciled_mismatch( 'CURRENCY', static function ( $order ) { $order->set_currency('USD'); } );
} );

mbs_audit_case( 'stale balance generation enters reconciliation', static function () {
    mbs_audit_reconciled_mismatch( 'STALE', static function ( $order, $invoice ) {
        global $wpdb;
        $wpdb->query($wpdb->prepare('UPDATE '.$wpdb->prefix.MBS_INVOICE_TABLE.' SET version=version+1 WHERE id=%d',(int)$invoice->id));
    } );
} );

mbs_audit_case( 'persisted invoice orders reject later value edits', static function () {
    $booking=mbs_audit_booking('INT-A-LOCKED-ORDER','10.00',114);$invoice=mbs_audit_invoice('locked-order',$booking);list($order)=mbs_audit_order($invoice);MBS_Woo_Payment::lock_invoice_order($order);
    $blocked=false;try{$order->set_total('9.00');MBS_Woo_Payment::guard_locked_invoice_order($order);$order->save();}catch(Throwable $error){$blocked=true;}
    mbs_audit_assert($blocked,'A persisted exact-value invoice order remained editable.');
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

mbs_audit_case( 'an altered Woo total is rejected instead of becoming a partial payment', static function () {
    $booking = mbs_audit_booking( 'INT-A-ALTERED', '10.00', 74 );
    $invoice = mbs_audit_invoice( 'altered-total', $booking );
    list( $order ) = mbs_audit_order( $invoice );
    foreach ( $order->get_items() as $item ) { $item->set_total('9.00'); $item->save(); }
    $order->set_total( '9.00' );
    $order->save();
    mbs_audit_pay( $order );
    $fresh = MBS_Billing_Ledger::get_invoice( $invoice->invoice_ref );
    $fresh_order = wc_get_order( $order->get_id() );
    mbs_audit_assert(
        (int) $fresh->paid_minor === 0 && $fresh_order->get_meta( '_mbs_invoice_reconciliation_required' ) === 'yes',
        'A £9 order against a £10 reservation was silently accepted as a partial online payment.'
    );
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
    $before_notes=count(wc_get_order_notes(array('order_id'=>$order->get_id(),'type'=>'internal')));
    $woo->on_order_refunded( $order->get_id(), $refund->get_id() );
    $notes = wc_get_order_notes( array('order_id'=>$order->get_id(),'type'=>'internal') );
    $errors = array_filter( $notes, static function($note){ return stripos($note->content,'could not be recorded') !== false; } );
    $conflict=MBS_Invoice_Payment::record_gateway_refund($invoice->invoice_ref,'10.00',$order->get_id(),$refund->get_id(),array($booking->ref=>999));
    mbs_audit_assert( mbs_audit_transaction_count($invoice,'refund') === 1 && !$errors && count($notes)===$before_notes && is_wp_error($conflict)&&$conflict->get_error_code()==='idempotency_payload_conflict', 'An idempotent refund replay was noisy or conflicting allocations were accepted.' );
} );

mbs_audit_case( 'cancellation credit supports cumulative cash refunds without double credit', static function () {
    global $wpdb;
    $booking=mbs_audit_booking('INT-A-CAN-CUM','10.00',116);$invoice=mbs_audit_invoice('cancel-cumulative',$booking);list($order)=mbs_audit_order($invoice);$order=mbs_audit_pay($order);
    $wpdb->update($wpdb->prefix.MBS_TABLE,array('status'=>'cancelled'),array('ref'=>$booking->ref));$credit=MBS_Billing_Engine::reconcile_occurrences(array($booking->ref),true);
    if(is_wp_error($credit))throw new RuntimeException($credit->get_error_message());
    mbs_audit_refund($order,'4.00','Cumulative one');mbs_audit_refund($order,'6.00','Cumulative two');
    $fresh=MBS_Billing_Ledger::get_invoice($invoice->invoice_ref);$allocation=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.MBS_BILLING_ALLOCATION_TABLE.' WHERE invoice_id=%d AND booking_ref=%s',(int)$invoice->id,$booking->ref));$cash=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount_minor),0) FROM {$wpdb->prefix}".MBS_PAYMENT_TRANSACTION_TABLE." WHERE invoice_id=%d AND transaction_type='refund'",(int)$invoice->id));
    mbs_audit_assert((int)$fresh->paid_minor===0&&(int)$fresh->credited_minor===1000&&$cash===1000&&(int)$allocation->refunded_minor===1000&&MBS_Bookings::get($booking->ref)->status==='cancelled','Cumulative cash refunds doubled or lost cancellation credit.');
} );

mbs_audit_case( 'partial cash refund before cancellation credit remains balanced', static function () {
    global $wpdb;
    $booking=mbs_audit_booking('INT-A-REF-CAN','10.00',117);$invoice=mbs_audit_invoice('refund-then-cancel',$booking);list($order)=mbs_audit_order($invoice);$order=mbs_audit_pay($order);
    mbs_audit_refund($order,'4.00','Cash before cancellation');$wpdb->update($wpdb->prefix.MBS_TABLE,array('status'=>'cancelled'),array('ref'=>$booking->ref));$credit=MBS_Billing_Engine::reconcile_occurrences(array($booking->ref),true);
    if(is_wp_error($credit))throw new RuntimeException($credit->get_error_message());mbs_audit_refund($order,'6.00','Cash after cancellation');
    $fresh=MBS_Billing_Ledger::get_invoice($invoice->invoice_ref);$allocation=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.MBS_BILLING_ALLOCATION_TABLE.' WHERE invoice_id=%d AND booking_ref=%s',(int)$invoice->id,$booking->ref));
    mbs_audit_assert((int)$fresh->paid_minor===0&&(int)$fresh->credited_minor<=1000&&(int)$allocation->refunded_minor===1000&&MBS_Bookings::get($booking->ref)->status==='cancelled','Reordered cash refund and cancellation credit overstated cash or credit.');
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

mbs_audit_case( 'accounting boundaries, financial-year credits, and non-GBP exports remain exact', static function () {
    global $wpdb;
    $booking=mbs_audit_booking('INT-A-EXPORT-USD','12.00',118);
    $draft=MBS_Billing_Ledger::create_draft_invoice(array('contact_name'=>'USD Export','contact_email'=>'usd@example.invalid','contact_address'=>'Test','billing_mode'=>'monthly','currency'=>'USD','period_start'=>'2025-03-31','period_end'=>'2025-03-31','due_at'=>'2025-04-14 12:00:00'),'audit-usd-export');
    if(is_wp_error($draft))throw new RuntimeException($draft->get_error_message());
    $added=MBS_Billing_Ledger::add_item($draft['invoice']->invoice_ref,array('item_type'=>'hire','booking_ref'=>$booking->ref,'service_date'=>'2025-03-31','description'=>'USD boundary hire','quantity_milli'=>1000,'unit_amount_minor'=>1200,'pricing_snapshot'=>array('audit'=>true)),(int)$draft['invoice']->version);if(is_wp_error($added))throw new RuntimeException($added->get_error_message());
    $issued=MBS_Billing_Ledger::issue_invoice($added['invoice']->invoice_ref,(int)$added['invoice']->version);if(is_wp_error($issued))throw new RuntimeException($issued->get_error_message());
    $credit=MBS_Billing_Ledger::create_credit_note($issued['invoice']->invoice_ref,1200,'Year boundary credit','audit-usd-year-credit');if(is_wp_error($credit))throw new RuntimeException($credit->get_error_message());
    $wpdb->update($wpdb->prefix.MBS_INVOICE_TABLE,array('issued_at'=>'2025-03-31 12:00:00','created_at'=>'2025-03-31 12:00:00'),array('id'=>(int)$issued['invoice']->id));
    $wpdb->update($wpdb->prefix.MBS_INVOICE_TABLE,array('issued_at'=>'2025-04-01 09:00:00','created_at'=>'2025-04-01 09:00:00'),array('id'=>(int)$credit['credit_note']->id));
    $normalise=new ReflectionMethod('MBS_Accounting_Export','normalise_records');$normalise->setAccessible(true);
    $march=$normalise->invoke(null,array(),'2025-03-31','2025-03-31');$april=$normalise->invoke(null,array(),'2025-04-01','2025-04-01');$outside=$normalise->invoke(null,array(),'2025-04-02','2025-04-02');
    $march_refs=array_map(static function($r){return$r->invoice_number;},$march);$april_refs=array_map(static function($r){return$r->invoice_number;},$april);$outside_refs=array_map(static function($r){return$r->invoice_number;},$outside);
    $credit_records=array_values(array_filter($april,static function($r)use($credit){return$r->invoice_number===$credit['credit_note']->invoice_ref;}));
    $xero=mbs_audit_export_text('export_xero',$credit_records);$sage=mbs_audit_export_text('export_sage',$credit_records);$quickbooks=mbs_audit_export_text('export_quickbooks',$credit_records);
    mbs_audit_assert(in_array($issued['invoice']->invoice_ref,$march_refs,true)&&!in_array($credit['credit_note']->invoice_ref,$march_refs,true)&&in_array($credit['credit_note']->invoice_ref,$april_refs,true)&&!in_array($credit['credit_note']->invoice_ref,$outside_refs,true),'Accounting inclusion/exclusion boundaries used the wrong document date.');
    mbs_audit_assert(strpos($xero,'USD')!==false&&strpos($xero,'-12.00')!==false&&strpos($sage,'SC')!==false&&strpos($quickbooks,'Credit Memo')!==false,'Non-GBP credit export lost currency, type, or sign semantics.');
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
    $kinds=$wpdb->get_col($wpdb->prepare('SELECT reversal_kind FROM '.$wpdb->prefix.MBS_OSM_OUTBOX_TABLE.' WHERE booking_ref=%s ORDER BY id ASC',$booking->ref));
    mbs_audit_assert( $reversals === 2 && $kinds===array('partial','full'), 'OSM income was not offset by distinct partial and full durable reversals.' );
} );

mbs_audit_case( 'OSM reversal failure is durably recoverable', static function () {
    global $wpdb;
    update_option( 'mbs_osm_enabled', true );
    update_option( 'mbs_osm_sandbox_mode', false );
    update_option( 'mbs_osm_section_id', 'audit-section' );
    update_option( 'mbs_osm_category_id', 'audit-category' );
    update_option( 'mbs_osm_account_id', 'audit-account' );
    $booking = mbs_audit_booking( 'INT-A-OSM-RETRY', '10.00', 80 );
    $invoice = mbs_audit_invoice( 'osm-retry', $booking );
    list( $order ) = mbs_audit_order( $invoice );
    $order = mbs_audit_pay( $order );
    $fail_http = static function () { return new WP_Error( 'audit_osm_down', 'Controlled OSM outage.' ); };
    add_filter( 'pre_http_request', $fail_http );
    try {
        mbs_audit_refund( $order, '10.00', 'OSM durable retry' );
    } finally {
        remove_filter( 'pre_http_request', $fail_http );
    }
    $outbox_table = $wpdb->prefix . 'mathlin_osm_outbox';
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $outbox_table ) ) === $outbox_table;
    $pending = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$outbox_table} WHERE status IN ('pending','retry','manual_reconciliation')" ) : 0;
    mbs_audit_assert( $exists && $pending === 1, 'Failed OSM reversal was not retained in a durable retry/reconciliation outbox.' );
} );

mbs_audit_case( 'OSM outbox handles retry, permanent failure, ambiguity, replay, and restart recovery', static function () {
    global $wpdb;
    update_option('mbs_osm_enabled',true);update_option('mbs_osm_sandbox_mode',false);update_option('mbs_osm_auth_source','standalone');
    update_option('mbs_osm_access_token_data',(object)array('access_token'=>'integration-only'));update_option('mbs_osm_access_token_expiry',time()+3600);
    update_option('mbs_osm_section_id','audit-section');update_option('mbs_osm_category_id','audit-category');update_option('mbs_osm_account_id','audit-account');
    $booking=mbs_audit_booking('INT-A-OSM-DELIVERY','10.00',119);$invoice=mbs_audit_invoice('osm-delivery',$booking);list($order)=mbs_audit_order($invoice);$order=mbs_audit_pay($order);
    $response=static function($code){return array('headers'=>array(),'body'=>'{}','response'=>array('code'=>$code,'message'=>'audit'),'cookies'=>array(),'filename'=>null);};
    $server_error=static function()use($response){return $response(500);};add_filter('pre_http_request',$server_error,10,3);
    $retry_refund=mbs_audit_refund($order,'2.00','OSM retry');remove_filter('pre_http_request',$server_error,10);
    $table=$wpdb->prefix.MBS_OSM_OUTBOX_TABLE;$retry_event=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE refund_id=%d",$retry_refund->get_id()));
    mbs_audit_assert($retry_event&&$retry_event->status==='retry','Definite OSM server failure was not retained for bounded retry.');
    $success=static function()use($response){return $response(200);};add_filter('pre_http_request',$success,10,3);MBS_OSM_Integration::deliver_outbox_event((int)$retry_event->id);remove_filter('pre_http_request',$success,10);
    mbs_audit_assert($wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE id=%d",(int)$retry_event->id))==='delivered','OSM retry did not record eventual delivery.');
    $permanent=static function()use($response){return $response(400);};add_filter('pre_http_request',$permanent,10,3);$permanent_refund=mbs_audit_refund($order,'2.00','OSM permanent');remove_filter('pre_http_request',$permanent,10);
    mbs_audit_assert($wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE refund_id=%d",$permanent_refund->get_id()))==='manual_reconciliation','Permanent OSM failure did not become an administrator task.');
    $timeout=static function(){return new WP_Error('http_request_failed','Controlled ambiguous timeout.');};add_filter('pre_http_request',$timeout,10,3);$timeout_refund=mbs_audit_refund($order,'2.00','OSM timeout');remove_filter('pre_http_request',$timeout,10);
    mbs_audit_assert($wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE refund_id=%d",$timeout_refund->get_id()))==='manual_reconciliation','Ambiguous OSM response was retried blindly or lost.');
    $before_events=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE invoice_ref=%s",$invoice->invoice_ref));
    $before_notes=count(wc_get_order_notes(array('order_id'=>$order->get_id(),'type'=>'internal')));(new MBS_Woo_Payment())->on_order_refunded($order->get_id(),$retry_refund->get_id());
    $after_events=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE invoice_ref=%s",$invoice->invoice_ref));$after_notes=count(wc_get_order_notes(array('order_id'=>$order->get_id(),'type'=>'internal')));
    mbs_audit_assert($before_events===$after_events&&$before_notes===$after_notes,'Duplicate refund replay created another OSM event or success note.');
    $crash_id=MBS_OSM_Integration::queue_refund_reversal($booking,$invoice->invoice_ref,100,$order->get_id(),999999,'partial');
    mbs_audit_assert(!is_wp_error($crash_id)&&$wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE id=%d",(int)$crash_id))==='pending','Crash-window reversal was not durable before HTTP delivery.');
    add_filter('pre_http_request',$success,10,3);MBS_OSM_Integration::deliver_outbox_event((int)$crash_id);remove_filter('pre_http_request',$success,10);
    mbs_audit_assert($wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE id=%d",(int)$crash_id))==='delivered','Pending crash-window reversal was not recoverable after restart.');
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

mbs_audit_case( 'failed safe modification remains pending and retries cleanly', static function () {
    global $wpdb;
    $booking=mbs_audit_booking('INT-A-SAFE-MOD-RETRY','10.00',120);$invoice=mbs_audit_invoice('safe-mod-retry',$booking);
    $created=MBS_Modification::create_request(array('ref'=>$booking->ref,'type'=>'modify','changes'=>array('attendees'=>33)));if($created===false)throw new RuntimeException('Could not create retry modification.');$request_id=(int)$wpdb->insert_id;
    $block=static function($query)use($wpdb){if(stripos($query,'UPDATE')!==false&&stripos($query,$wpdb->prefix.MBS_TABLE)!==false&&stripos($query,'attendees')!==false)return 'UPDATE missing_modification_fixture SET broken=1';return $query;};
    add_filter('query',$block);$failed=MBS_Modification::approve($request_id);remove_filter('query',$block);
    $request=MBS_Modification::get_request($request_id);$unchanged=MBS_Bookings::get($booking->ref);
    mbs_audit_assert(is_wp_error($failed)&&$request->status==='pending'&&(int)$unchanged->attendees===10,'Failed permitted-field write was incorrectly marked approved.');
    $retried=MBS_Modification::approve($request_id);$request=MBS_Modification::get_request($request_id);$changed=MBS_Bookings::get($booking->ref);
    mbs_audit_assert(!is_wp_error($retried)&&$retried===true&&$request->status==='approved'&&(int)$changed->attendees===33&&(string)$changed->amount==='10.00','Safe modification did not retry idempotently after the database fault cleared.');
} );

MBS_Audit_Assertions::current()->finish( 'all adversarial runtime regressions passed' );
