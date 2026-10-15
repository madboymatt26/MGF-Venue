<?php
/** Run only on the disposable integration stack; no production credentials. */
if ( ! class_exists( 'MBS_Test_Gateway' ) || get_bloginfo( 'name' ) !== 'MGF Integration' ) {
    throw new RuntimeException( 'Additional-charge integration scenario requires the isolated test site.' );
}
global $wpdb;
wp_set_current_user( 1 );
add_filter( 'pre_wp_mail', '__return_true' ); // Never deliver real email from this scenario.
function extra_it_assert( $value, $message ) {
    if ( is_wp_error( $value ) ) throw new RuntimeException( $message . ': ' . $value->get_error_message() );
    if ( ! $value ) throw new RuntimeException( $message );
}
$key = wp_generate_uuid4();
$booking_ref = 'INT-EXTRA-' . strtoupper( substr( $key, 0, 8 ) );
$now = current_time( 'mysql' );
$date = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
$inserted = $wpdb->insert( $wpdb->prefix . MBS_TABLE, array(
    'ref' => $booking_ref, 'status' => 'archived', 'name' => 'Synthetic extra-charge hirer',
    'email' => 'extra-charge@example.invalid', 'space' => 'Main Scout Hall', 'booking_date' => $date,
    'booking_date_end' => $date, 'start_time' => '10:00:00', 'end_time' => '12:00:00',
    'attendees' => 1, 'purpose' => 'Synthetic fixture', 'amount' => '30.00', 'amount_paid' => '30.00',
    'deposit_paid' => '30.00', 'access_sent' => 1, 'created_at' => $now, 'updated_at' => $now,
) );
extra_it_assert( $inserted !== false, 'Seed archived paid hire' );
$before = MBS_Bookings::get( $booking_ref );
$data = array( 'related_ref' => $booking_ref, 'amount' => '25.00', 'category' => 'cleaning',
    'description' => 'Synthetic additional cleaning', 'service_date' => $date,
    'due_date' => wp_date( 'Y-m-d', time() + 14 * DAY_IN_SECONDS ),
    'contact_name' => 'Synthetic extra-charge hirer', 'contact_email' => 'extra-charge@example.invalid' );
$draft = MBS_Additional_Charges::create( $data, $key ); extra_it_assert( $draft, 'Create charge draft' );
$replay = MBS_Additional_Charges::create( $data, $key ); extra_it_assert( $replay, 'Replay creation' );
extra_it_assert( $draft->invoice_ref === $replay->invoice_ref, 'Creation must be idempotent' );
$changed = $data; $changed['amount'] = '26.00';
extra_it_assert( is_wp_error( MBS_Additional_Charges::create( $changed, $key ) ), 'Same key/different amount must fail' );
extra_it_assert( ! in_array( $draft->invoice_ref, wp_list_pluck( MBS_Hirer_Portal::get_invoices_for_email( $data['contact_email'] ), 'invoice_ref' ), true ), 'Draft is private to staff' );
$issued = MBS_Additional_Charges::issue( $draft->invoice_ref, $draft->version ); extra_it_assert( $issued, 'Issue extra invoice' );
extra_it_assert( (int) $issued->total_minor === 2500 && empty( $issued->series_ref ), 'Standalone exact charge, not monthly hire' );
extra_it_assert( ! MBS_Billing_Ledger::get_active_booking_allocation( $booking_ref ), 'No hire allocation created' );
$doc = MBS_Invoice_Document_Service::get_current_ledger_document_id( $issued->id );
extra_it_assert( $doc > 0, 'Immutable PDF source issued' );
extra_it_assert( is_wp_error( MBS_Additional_Charges::update_draft( $issued->invoice_ref, $changed, $issued->version ) ), 'Issued charge is immutable' );
$reminder = MBS_Invoice_Payment::send_invoice_reminder( $issued->invoice_ref );
extra_it_assert( ! is_wp_error( $reminder ) && $reminder['reason'] === 'additional_charge_reminders_disabled', 'No reminder without opt-in' );
$reservation = MBS_Invoice_Reservation::acquire( $issued ); extra_it_assert( $reservation, 'Reserve exact charge checkout' );
$held_send = MBS_Additional_Charges::send( $issued->invoice_ref, $issued->version );
extra_it_assert( is_wp_error( $held_send ) && $held_send->get_error_code() === 'charge_payment_under_review', 'No invoice payment request during checkout' );
$offline_blocked = MBS_Invoice_Payment::record_manual_payment( $issued->invoice_ref, 2500, wp_generate_uuid4(), $issued->version );
extra_it_assert( is_wp_error( $offline_blocked ), 'Offline settlement must not race an active checkout' );
$blocked = MBS_Additional_Charges::credit( $issued->invoice_ref, 100, 'Synthetic test', $issued->version, wp_generate_uuid4() );
extra_it_assert( is_wp_error( $blocked ) && $blocked->get_error_code() === 'charge_checkout_active', 'Credit blocked while checkout is active' );
extra_it_assert( MBS_Invoice_Reservation::release( $issued->invoice_ref, $reservation['reservation_ref'] ), 'Release synthetic checkout' );
$sent = MBS_Additional_Charges::send( $issued->invoice_ref, $issued->version ); extra_it_assert( $sent, 'Explicitly queue synthetic email' );
$sent_twice = MBS_Additional_Charges::send( $issued->invoice_ref, $issued->version ); extra_it_assert( $sent_twice, 'Repeated send is idempotent' );
$queued = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'mathlin_email_queue WHERE message_key=%s', 'additional_charge:' . hash( 'sha256', $issued->invoice_ref . ':doc' . $doc . ':version-' . $issued->version ) ) );
extra_it_assert( $queued === 1, 'Only one invoice email may be queued' );
$partial = MBS_Invoice_Payment::record_manual_payment( $issued->invoice_ref, 500, wp_generate_uuid4(), $issued->version, 'Synthetic partial receipt' ); extra_it_assert( $partial, 'Record partial offline receipt' );
$issued = $partial['invoice'];
$resend_key = wp_generate_uuid4();
extra_it_assert( MBS_Additional_Charges::resend( $issued->invoice_ref, $issued->version, $resend_key ), 'Explicit resend after partial payment' );
extra_it_assert( MBS_Additional_Charges::resend( $issued->invoice_ref, $issued->version, $resend_key ), 'Resend retry deduplicated' );
$resend_row = $wpdb->get_row( $wpdb->prepare( 'SELECT body FROM ' . $wpdb->prefix . 'mathlin_email_queue WHERE message_key=%s', 'additional_charge:' . hash( 'sha256', $issued->invoice_ref . ':doc' . $doc . ':' . $resend_key ) ) );
extra_it_assert( strpos( $resend_row->body, '20.00' ) !== false, 'Resend states current outstanding balance' );
$preference = MBS_Additional_Charges::set_reminders( $issued->invoice_ref, $issued->version, true ); extra_it_assert( $preference, 'Enable reminders after issue' );
extra_it_assert( MBS_Additional_Charges::reminders_enabled( $preference ), 'Operational reminder preference available' );
$preference = MBS_Additional_Charges::set_reminders( $preference->invoice_ref, $preference->version, false ); extra_it_assert( $preference, 'Disable reminders after issue' );
extra_it_assert( ! MBS_Additional_Charges::reminders_enabled( $preference ), 'Reminder override disabled' );
$issued = $preference;
$paid = MBS_Invoice_Payment::record_gateway_payment( $issued->invoice_ref, '20.00', 99900001 ); extra_it_assert( $paid, 'Record synthetic provider payment' );
$again = MBS_Invoice_Payment::record_gateway_payment( $issued->invoice_ref, '20.00', 99900001 ); extra_it_assert( $again, 'Replay synthetic callback' );
extra_it_assert( MBS_Billing_Ledger::balance_minor( $again['invoice'] ) === 0 && (int) $again['invoice']->paid_minor === 2500, 'Payment replay does not duplicate money' );
$credit = MBS_Additional_Charges::credit( $issued->invoice_ref, 2500, 'Synthetic credit', $again['invoice']->version, wp_generate_uuid4() ); extra_it_assert( $credit, 'Issue independent credit' );
$documents = MBS_Additional_Charges::documents( $credit );
extra_it_assert( count( array_filter( $documents, function($d){return $d['type']==='credit_note';} ) ) === 1, 'Portal discovers issued credit note' );
$cn = $wpdb->get_var( $wpdb->prepare( 'SELECT invoice_ref FROM ' . $wpdb->prefix . MBS_INVOICE_TABLE . ' WHERE parent_invoice_id=%d AND document_type=%s', $credit->id, 'credit_note' ) );
extra_it_assert( MBS_Additional_Charges::send_credit( $credit->invoice_ref, $credit->version, $cn, wp_generate_uuid4() ), 'Explicit credit email queued' );
$refund = MBS_Invoice_Payment::record_gateway_refund( $issued->invoice_ref, '20.00', 99900001, 99900002 ); extra_it_assert( $refund, 'Refund without hire allocations' );
$refund_replay = MBS_Invoice_Payment::record_gateway_refund( $issued->invoice_ref, '20.00', 99900001, 99900002 ); extra_it_assert( $refund_replay, 'Replay refund' );
extra_it_assert( (int) $refund_replay['invoice']->paid_minor === 500, 'Refund replay does not duplicate refund; offline receipt remains for separate review' );
$after = MBS_Bookings::get( $booking_ref );
foreach ( array( 'status','amount','amount_paid','deposit_paid','access_sent','series_id','booking_date','updated_at' ) as $field ) {
    extra_it_assert( $before->$field === $after->$field, 'Original hire changed: ' . $field );
}
extra_it_assert( ! MBS_Billing_Ledger::get_active_booking_allocation( $booking_ref ), 'No financial allocation after refund' );
echo "OK: independent extra-charge create/issue/payment/credit/refund lifecycle; original archived paid hire untouched.\n";

// Exercise the actual typed routes used by the MCP, not just PHP services.
function extra_rest($method,$path,$body=array()) {
    $request = new WP_REST_Request($method,'/mathlin/v1/admin/additional-charges'.$path);
    if ($method==='POST') { $request->set_header('content-type','application/json'); $request->set_body(wp_json_encode($body)); }
    return rest_do_request($request);
}
$api_key=wp_generate_uuid4();
$response=extra_rest('POST','',array('operation'=>'create','idempotency_key'=>$api_key,'fields'=>$data));
extra_it_assert($response->get_status()<400,'REST create must succeed');
$api_draft=$response->get_data()['invoice'];
$issue_body=array('operation'=>'issue','invoice_ref'=>$api_draft['invoice_ref'],'expected_version'=>$api_draft['version'],'idempotency_key'=>wp_generate_uuid4());
$response=extra_rest('POST','',$issue_body);extra_it_assert($response->get_status()<400,'REST issue must succeed');
$api_issued=$response->get_data()['invoice'];
$replay=extra_rest('POST','',$issue_body);extra_it_assert($replay->get_status()<400&&$replay->get_data()['idempotent_replay'],'REST issue retry is a replay');
$send_body=array('operation'=>'send','invoice_ref'=>$api_issued['invoice_ref'],'expected_version'=>$api_issued['version'],'idempotency_key'=>wp_generate_uuid4());
extra_it_assert(extra_rest('POST','',$send_body)->get_status()===400,'REST send without confirmation is rejected');
$details=extra_rest('GET','/'.$api_issued['invoice_ref']);extra_it_assert($details->get_status()<400,'REST details available');
extra_it_assert(strpos(wp_json_encode($details->get_data()),'payment_token_hash')===false&&strpos(wp_json_encode($details->get_data()),'ip_address')===false,'REST details omit tokens and stored IP addresses');
$html=extra_rest('GET','/'.$api_issued['invoice_ref'].'/document');extra_it_assert($html->get_status()<400&&strpos($html->get_data()['html'],'Synthetic additional cleaning')!==false,'REST immutable HTML is available');
$pdf_request=new WP_REST_Request('GET','/mathlin/v1/admin/additional-charges/'.$api_issued['invoice_ref'].'/document');$pdf_request->set_param('format','pdf');
$pdf_response=rest_do_request($pdf_request);extra_it_assert($pdf_response->get_status()<400&&substr(base64_decode($pdf_response->get_data()['pdf_base64']),0,5)==='%PDF-','REST issued PDF rendering');
wp_set_current_user(0);
extra_it_assert(extra_rest('GET','/'.$api_issued['invoice_ref'])->get_status()>=400,'Unauthenticated charge read denied');
extra_it_assert(extra_rest('POST','',$issue_body)->get_status()>=400,'Unauthenticated charge mutation denied');
wp_set_current_user(1);
echo "OK: typed REST creation, issue/replay, explicit-send guard, safe detail/HTML/PDF reads and permission gates.\n";
