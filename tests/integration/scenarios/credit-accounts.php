<?php
/** Disposable SQL acceptance. Never run on a customer website. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! preg_match( '/127\.0\.0\.1|localhost|wordpress/', home_url() ) ) throw new RuntimeException( 'Disposable fixture required.' );
global $wpdb;
$checks = 0; $refs = array(); $accounts = array(); $series_refs = array(); $mail_count = 0;
$check = static function( $ok, $message ) use ( &$checks ) { $checks++; if ( ! $ok ) throw new RuntimeException( $message ); };
$options = array();
foreach ( array( 'mbs_credit_accounts_enabled','mbs_payment_expiry_enabled','mbs_campsite_mode','mbs_payments_enabled','mbs_invoices_enabled','mbs_deposit_enabled','mbs_deposit_percentage','mbs_ha_webhook_url','timezone_string' ) as $key ) $options[$key] = get_option( $key, null );
$user = get_current_user_id();
$mail = static function() use ( &$mail_count ) { $mail_count++; return true; };
$http = static function() { throw new RuntimeException( 'No external HTTP allowed in credit fixtures.' ); };
$seed = static function( $status = 'pending', $email = 'credit-fixture@example.invalid' ) use ( &$refs, $wpdb, $check ) {
    $ref = 'CRD-' . substr( hash( 'sha256', wp_generate_uuid4() ), 0, 12 ); $refs[] = $ref;
    $b = (object) array( 'ref' => $ref, 'status' => $status, 'name' => 'Synthetic credit fixture', 'organisation' => 'Synthetic shared name', 'email' => $email, 'phone' => '', 'address' => '', 'space' => 'Main Scout Hall', 'booking_date' => wp_date( 'Y-m-d', strtotime( '+30 days' ) ), 'booking_date_end' => wp_date( 'Y-m-d', strtotime( '+30 days' ) ), 'start_time' => '10:00:00', 'end_time' => '12:00:00', 'attendees' => 1, 'purpose' => 'Synthetic credit acceptance', 'amount' => '30.00', 'amount_paid' => '0.00', 'scout_use' => 0, 'pricing_tier' => 'standard' );
    $b->all_day = 0; $b->kitchen = 0;
    $check( $wpdb->insert( $wpdb->prefix . MBS_TABLE, array_merge( (array) $b, MBS_Bookings::with_pricing_evidence( $b, array() ) ) ) === 1, 'Seed synthetic credit hire' );
    return MBS_Bookings::get( $ref );
};
$assign = static function( $b, $id, $extra = array() ) {
    $kind = ! empty( $b->series_ref ) ? 'series' : 'booking'; $ref = $kind === 'series' ? $b->series_ref : $b->ref;
    $account = MBS_Credit_Accounts::account( $id ); $link = MBS_Credit_Accounts::assignment( $kind, $ref );
    return MBS_Credit_Accounts::decide( array_merge( array( 'op' => 'assign', 'scope_kind' => $kind, 'scope_ref' => $ref, 'account_id' => $id, 'account_version' => $account->version, 'assignment_version' => $link->version ?? 0, 'fingerprint' => MBS_Credit_Accounts::fingerprint( $b, $kind ), 'reason' => 'Synthetic evidence and explicit staff consent', 'verified' => 1 ), $extra ) );
};
try {
    add_filter( 'pre_wp_mail', $mail, PHP_INT_MAX, 2 ); add_filter( 'pre_http_request', $http, PHP_INT_MAX );
    wp_set_current_user( get_user_by( 'login', 'admin' )->ID );
    update_option( 'timezone_string', 'Europe/London' ); update_option( 'mbs_campsite_mode', 0 ); update_option( 'mbs_payments_enabled', 1 ); update_option( 'mbs_invoices_enabled', 1 ); update_option( 'mbs_deposit_enabled', 1 ); update_option( 'mbs_deposit_percentage', 25 ); update_option( 'mbs_payment_expiry_enabled', 1 ); update_option( 'mbs_ha_webhook_url', '' );
    update_option( 'mbs_credit_accounts_enabled', 0 );
    $agreement = array( 'op' => 'create', 'name' => 'Synthetic credit organisation', 'contacts' => 'credit-fixture@example.invalid', 'terms_days' => '14', 'valid_until' => wp_date( 'Y-m-d', strtotime( '+365 days' ) ), 'verified' => 1 );
    $check( is_wp_error( MBS_Credit_Accounts::decide( $agreement ) ), 'Disabled module refuses grants' );
    update_option( 'mbs_credit_accounts_enabled', 1 );
    wp_set_current_user( 0 );
    $check( is_wp_error( MBS_Credit_Accounts::decide( $agreement ) ), 'Anonymous caller cannot create agreement' );
    wp_set_current_user( get_user_by( 'login', 'admin' )->ID );
    $check( MBS_Credit_Accounts::decide( $agreement ) === true, 'Administrator can create verified agreement' );
    $id = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . MBS_Credit_Accounts::table() ); $accounts[] = $id;
    $b = $seed();
    $check( is_wp_error( $assign( $b, $id, array( 'verified' => 0 ) ) ), 'Explicit verification required' );
    $check( is_wp_error( $assign( $b, $id, array( 'account_version' => 999 ) ) ), 'Stale agreement refused' );
    $check( is_wp_error( $assign( $b, $id, array( 'fingerprint' => 'stale' ) ) ), 'Stale booking fingerprint refused' );
    $other = $seed( 'pending', 'other@example.invalid' );
    $check( is_wp_error( $assign( $other, $id ) ) && ! MBS_Credit_Accounts::for_booking( $other ), 'Same organisation name grants no credit to another email' );
    $fail_audit = static function( $sql ) use ( $wpdb ) { return strpos( $sql, 'INSERT INTO' ) !== false && strpos( $sql, $wpdb->prefix . 'mathlin_audit_log' ) !== false ? 'INVALID SYNTHETIC CREDIT AUDIT' : $sql; };
    $wpdb->suppress_errors( true ); add_filter( 'query', $fail_audit );
    $failed = $assign( $b, $id ); remove_filter( 'query', $fail_audit ); $wpdb->suppress_errors( false );
    $check( is_wp_error( $failed ) && ! MBS_Credit_Accounts::for_booking( $b ), 'Assignment rolls back when audit fails' );
    $check( $assign( $b, $id ) === true, 'Explicit exact-scope assignment succeeds' );
    $check( ! MBS_Payment_Expiry::eligible( $b ) && ! MBS_Payment_Expiry::get( $b->ref ), 'Credit excluded without creating expiry policy' );
    $check( MBS_Credit_Accounts::context( $b ) !== null && ! MBS_Access_Details::is_eligible_for_access( $b ), 'Pending credit is never access eligible' );
    $check( ! is_wp_error( MBS_Invoice_Document_Service::confirm_and_issue( $b->ref, array(), true ) ), 'Credit approval issues immutable invoice without deposit enrolment' );
    $b = MBS_Bookings::get( $b->ref ); $invoice = MBS_Credit_Accounts::invoice_info( $b );
    $check( $invoice && (int) $invoice['payment_terms_days'] === 14 && $invoice['due_date'] === wp_date( 'Y-m-d', strtotime( '+14 days' ) ) && ! empty( $invoice['payment_schedule']['standard_terms'] ), 'New credit invoice has reviewed terms, not deposit due today' );
    $check( $b->status === 'confirmed' && (float) $b->amount_paid === 0.0 && ! MBS_Access_Details::is_eligible_for_access( $b ), 'Assignment does not fake payment; access opt-in defaults off' );
    $check( ! MBS_Payment_Chaser::should_chase_occurrence( $b ), 'Credit is not chased before issued due date' );
    $html = MBS_Email::build_confirmation_message( $b )['body'];
    $check( strpos( $html, 'Approved invoice-account treatment' ) !== false && strpos( $html, 'Deposit due now:' ) === false && strpos( $html, '48 hours of approval' ) === false && strpos( $html, 'Pay Deposit Now' ) === false, 'Confirmation has account treatment without ordinary deposit/cancellation demands' );
    $original_document = $wpdb->get_var( $wpdb->prepare( 'SELECT snapshot_json FROM ' . $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE . ' WHERE id=%d', $b->current_invoice_document_id ) );
    $agreement['allow_unpaid_access'] = 1;
    $check( MBS_Credit_Accounts::decide( $agreement ) === true, 'New immutable agreement explicitly permits unpaid access' );
    $access_id = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . MBS_Credit_Accounts::table() ); $accounts[] = $access_id;
    $check( $assign( $b, $access_id ) === true && MBS_Access_Details::is_eligible_for_access( $b ), 'Reassignment explicitly permits unpaid approved hire access' );
    $check( $original_document === $wpdb->get_var( $wpdb->prepare( 'SELECT snapshot_json FROM ' . $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE . ' WHERE id=%d', $b->current_invoice_document_id ) ), 'Reassignment does not rewrite issued invoice' );
    $new_contact = clone $b; $new_contact->email = 'other@example.invalid';
    $check( MBS_Credit_Accounts::context( $new_contact, true ) === null, 'Changed contact invalidates unpaid access' );
    $cancelled = clone $b; $cancelled->status = 'cancelled';
    $check( ! MBS_Access_Details::is_eligible_for_access( $cancelled ) && is_wp_error( $assign( $seed( 'cancelled' ), $access_id ) ), 'Credit cannot restore a cancelled booking' );
    $account = MBS_Credit_Accounts::account( $access_id );
    $check( MBS_Credit_Accounts::decide( array( 'op' => 'status', 'account_id' => $access_id, 'account_version' => $account->version, 'status' => 'suspended', 'reason' => 'Synthetic suspension review' ) ) === true && ! MBS_Access_Details::is_eligible_for_access( $b ), 'Suspension removes unpaid access' );
    $check( count( MBS_Credit_Accounts::attention( 20 ) ) > 0, 'Suspended account is flagged for manual review' );
    $pending = $seed(); $check( is_wp_error( $assign( $pending, $access_id ) ), 'Suspended agreement cannot receive new assignments' );
    $check( $assign( $pending, $id ) === true, 'Pending grant under active agreement' );
    update_option( 'mbs_credit_accounts_enabled', 0 );
    $result = MBS_Invoice_Document_Service::confirm_and_issue( $pending->ref, array(), true );
    $check( is_wp_error( $result ) && MBS_Bookings::get( $pending->ref )->status === 'pending' && ! MBS_Payment_Expiry::eligible( $pending ), 'Disabling credit blocks approval but never rearms ordinary expiry' );
    $paid = clone $b; $paid->status = 'paid'; $check( MBS_Access_Details::is_eligible_for_access( $paid ), 'Actually paid booking retains ordinary access eligibility' );
    update_option( 'mbs_credit_accounts_enabled', 1 );
    $legacy = $seed( 'confirmed' );
    $check( MBS_Payment_Expiry::enroll( $legacy, true ) === true, 'Seed previously enrolled ordinary policy' );
    $old_policy = json_decode( MBS_Payment_Expiry::get( $legacy->ref )->policy_json, true );
    $check( $assign( $legacy, $id ) === true, 'Existing active policy explicitly converted under shared occupancy lock' );
    $new_policy = json_decode( MBS_Payment_Expiry::get( $legacy->ref )->policy_json, true ); $expected = $old_policy; $expected['exempt'] = true;
    $check( $new_policy === $expected, 'Conversion preserves all frozen deadlines and amounts, only setting exemption' );
    MBS_Payment_Expiry::check( $legacy->ref, 0, strtotime( '+400 days' ) );
    $check( MBS_Bookings::get( $legacy->ref )->status === 'confirmed', 'Exempt credit is never auto-cancelled even after original deadlines' );
    $wpdb->update( MBS_Payment_Expiry::table(), array( 'state' => 'money_review' ), array( 'booking_ref' => $legacy->ref ) );
    $check( is_wp_error( $assign( $legacy, $access_id ) ) && ! MBS_Access_Details::is_eligible_for_access( $legacy ), 'Funds review cannot be bypassed by credit' );
    $series_ref = 'CRS-' . substr( hash( 'sha256', wp_generate_uuid4() ), 0, 12 ); $series_refs[] = $series_ref;
    $check( $wpdb->insert( $wpdb->prefix . MBS_SERIES_TABLE, array( 'series_ref' => $series_ref, 'status' => 'confirmed', 'contact_name' => 'Synthetic series', 'contact_email' => 'credit-fixture@example.invalid', 'space' => 'Main Scout Hall', 'start_date' => wp_date( 'Y-m-d', strtotime( '+30 days' ) ), 'repeat_until' => wp_date( 'Y-m-d', strtotime( '+60 days' ) ), 'billing_mode' => 'monthly', 'billing_treatment' => 'invoice_managed', 'payment_terms_days' => 14, 'price_per_booking' => '30.00' ) ) === 1, 'Seed monthly invoice-managed series' );
    // Reinstate with fresh version; it does not create or approve bookings.
    $account = MBS_Credit_Accounts::account( $access_id ); MBS_Credit_Accounts::decide( array( 'op' => 'status', 'account_id' => $access_id, 'account_version' => $account->version, 'status' => 'active', 'reason' => 'Synthetic reviewed reinstatement' ) );
    $series = MBS_Series::get( $series_ref );
    $check( $assign( $series, $access_id ) === true, 'Explicit series assignment preserves invoice-managed schedule' );
    $occurrence = $seed( 'confirmed' ); $wpdb->update( $wpdb->prefix . MBS_TABLE, array( 'series_id' => $series_ref ), array( 'ref' => $occurrence->ref ) ); $occurrence = MBS_Bookings::get( $occurrence->ref );
    $check( MBS_Access_Details::is_eligible_for_access( $occurrence ) && ! MBS_Payment_Chaser::should_chase_occurrence( $occurrence ), 'Own series occurrence gets explicit access but consolidated chasing stays at invoice level' );
    $unrelated = $seed( 'confirmed' ); $check( ! MBS_Access_Details::is_eligible_for_access( $unrelated ), 'Series grant never leaks to another booking by same customer' );
    $result = MBS_Billing_Engine::configure_series( $series_ref, array( 'billing_mode' => 'monthly', 'billing_treatment' => 'invoice_managed', 'payment_method' => 'online', 'payment_terms_days' => 30 ), $series->version );
    $check( is_wp_error( $result ), 'Series billing edit cannot contradict the explicit credit agreement' );
    update_option( 'mbs_campsite_mode', 1 );
    $check( ! MBS_Credit_Accounts::enabled() && is_wp_error( MBS_Credit_Accounts::decide( $agreement ) ), 'Free campsite profile cannot enable or grant credit' );
    $check( $mail_count === 0, 'Acceptance queued no live/customer mail transport' );
    update_option( 'mbs_campsite_mode', 0 );
    // Age only a disposable synthetic invoice to exercise overdue delivery.
    $overdue_invoice = json_decode( $original_document, true ); $overdue_invoice['due_date'] = wp_date( 'Y-m-d', strtotime( '-1 day' ) );
    $wpdb->update( $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE, array( 'snapshot_json' => wp_json_encode( $overdue_invoice ) ), array( 'id' => $b->current_invoice_document_id ) );
    $b = MBS_Bookings::get( $b->ref );
    $check( MBS_Payment_Chaser::should_chase_occurrence( $b ), 'Overdue credit invoice is eligible for chasing: ' . wp_json_encode( MBS_Credit_Accounts::invoice_info( $b ) ) );
    $check( MBS_Credit_Accounts::queue_reminder( $b->ref ) && MBS_Credit_Accounts::queue_reminder( $b->ref ), 'Overdue credit intent can be queued idempotently' );
    $reminder = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mathlin_email_queue WHERE message_key=%s', MBS_Credit_Accounts::reminder_key( $b ) ) );
    $check( $reminder && MBS_Credit_Accounts::reminder_current( $reminder ) && (int) $b->chase_count === 0, 'Queued reminder is current; counter not incremented before delivery' );
    $check( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'mathlin_email_queue WHERE message_key=%s', $reminder->message_key ) ) === 1, 'Exactly one reminder intent despite duplicate request' );
    $wpdb->update( $wpdb->prefix . MBS_TABLE, array( 'amount_paid' => '30.00', 'status' => 'paid' ), array( 'ref' => $b->ref ) );
    $check( ! MBS_Credit_Accounts::reminder_current( $reminder ), 'Payment arriving before dispatch invalidates stale reminder' );
    $wpdb->update( $wpdb->prefix . MBS_TABLE, array( 'amount_paid' => '0.00', 'status' => 'confirmed' ), array( 'ref' => $b->ref ) );
    MBS_Credit_Accounts::reminder_delivered( $reminder ); MBS_Credit_Accounts::reminder_delivered( $reminder );
    $check( (int) MBS_Bookings::get( $b->ref )->chase_count === 1, 'Transport bookkeeping is idempotent and does not mark payment' );
    WP_CLI::success( "{$checks} real SQL credit-account assertions passed." );
} finally {
    remove_filter( 'query', $fail_audit ?? '__return_false' ); remove_filter( 'pre_wp_mail', $mail, PHP_INT_MAX ); remove_filter( 'pre_http_request', $http, PHP_INT_MAX );
    foreach ( $refs as $ref ) {
        $b = MBS_Bookings::get( $ref );
        if ( $b ) { $wpdb->delete( $wpdb->prefix . 'mathlin_email_queue', array( 'reference_type' => 'booking', 'reference_id' => $b->id ) ); $wpdb->delete( $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE, array( 'booking_id' => $b->id ) ); }
        $wpdb->delete( MBS_Credit_Accounts::table( true ), array( 'scope_kind' => 'booking', 'scope_ref' => $ref ) ); $wpdb->delete( MBS_Payment_Expiry::table(), array( 'booking_ref' => $ref ) ); $wpdb->delete( $wpdb->prefix . 'mathlin_audit_log', array( 'ref' => $ref ) ); $wpdb->delete( $wpdb->prefix . MBS_TABLE, array( 'ref' => $ref ) );
    }
    foreach ( $series_refs as $ref ) { $wpdb->delete( MBS_Credit_Accounts::table( true ), array( 'scope_kind' => 'series', 'scope_ref' => $ref ) ); $wpdb->delete( $wpdb->prefix . MBS_SERIES_TABLE, array( 'series_ref' => $ref ) ); $wpdb->delete( $wpdb->prefix . 'mathlin_audit_log', array( 'ref' => $ref ) ); }
    foreach ( $accounts as $id ) { $wpdb->delete( MBS_Credit_Accounts::table(), array( 'id' => $id ) ); $wpdb->delete( $wpdb->prefix . 'mathlin_audit_log', array( 'ref' => 'CREDIT-' . $id ) ); }
    foreach ( $options as $key => $value ) { if ( $value === null ) delete_option( $key ); else update_option( $key, $value ); }
    wp_set_current_user( $user );
}
