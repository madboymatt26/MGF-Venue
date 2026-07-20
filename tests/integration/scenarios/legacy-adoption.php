<?php
global $wpdb;
$series_ref = 'INT-LEGACY';
$booking_table = $wpdb->prefix . MBS_TABLE;
$series_table = $wpdb->prefix . MBS_SERIES_TABLE;
$invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
$item_table = $wpdb->prefix . MBS_INVOICE_ITEM_TABLE;
$allocation_table = $wpdb->prefix . MBS_BILLING_ALLOCATION_TABLE;
$transaction_table = $wpdb->prefix . MBS_PAYMENT_TRANSACTION_TABLE;
$invoice_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$invoice_table} WHERE series_ref = %s", $series_ref ) );
if ( $invoice_ids ) {
    $ids = implode( ',', array_map( 'absint', $invoice_ids ) );
    $wpdb->query( "DELETE FROM {$transaction_table} WHERE invoice_id IN ({$ids})" );
    $wpdb->query( "DELETE FROM {$allocation_table} WHERE invoice_id IN ({$ids})" );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$item_table} WHERE booking_ref LIKE %s", 'INT-L-%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$invoice_table} WHERE series_ref = %s", $series_ref ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$series_table} WHERE series_ref = %s", $series_ref ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$booking_table} WHERE series_id = %s", $series_ref ) );

$today = new DateTimeImmutable( 'today', wp_timezone() );
$cases = array(
    array( 'INT-L-PAID', 'paid', -14, '100.00', '0.00' ),
    array( 'INT-L-DEPOSIT', 'deposit_paid', -7, '25.00', '25.00' ),
    array( 'INT-L-FUTURE', 'confirmed', 14, '0.00', '0.00' ),
    array( 'INT-L-CANCEL', 'cancelled', 21, '0.00', '0.00' ),
    array( 'INT-L-ARCHIVE', 'archived', -30, '0.00', '0.00' ),
);
foreach ( $cases as $case ) {
    $date = $today->modify( ( $case[2] >= 0 ? '+' : '' ) . $case[2] . ' days' )->format( 'Y-m-d' );
    $inserted = $wpdb->insert( $booking_table, array(
        'ref'=>$case[0],'status'=>$case[1],'name'=>'Legacy Integration','organisation'=>'Integration','email'=>'legacy@example.invalid',
        'phone'=>'000','address'=>'Test only','space'=>'Hall','kitchen'=>0,'booking_date'=>$date,'booking_date_end'=>$date,
        'all_day'=>0,'scout_use'=>0,'pricing_tier'=>'standard','start_time'=>'19:00:00','end_time'=>'21:00:00',
        'attendees'=>10,'purpose'=>'Integration test','amount'=>'100.00','deposit_paid'=>$case[4],'amount_paid'=>$case[3],
        'invoice_number'=>'LEG-' . $case[0],'series_id'=>$series_ref,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql'),
    ) );
    if ( $inserted === false ) throw new RuntimeException( $wpdb->last_error );
}

$registered = MBS_Series::register_legacy_groups();
if ( is_wp_error( $registered ) ) throw new RuntimeException( $registered->get_error_message() );
$excluded = $wpdb->get_col( $wpdb->prepare( "SELECT ref FROM {$booking_table} WHERE series_id=%s AND legacy_billing_excluded=1 ORDER BY ref", $series_ref ) );
if ( $excluded !== array( 'INT-L-ARCHIVE','INT-L-CANCEL','INT-L-DEPOSIT','INT-L-PAID' ) ) throw new RuntimeException( 'Historical baseline was not permanent and complete.' );

$series = MBS_Series::get( $series_ref );
$adopted = MBS_Billing_Engine::configure_series( $series_ref, array(
    'billing_mode'=>'monthly','billing_treatment'=>'invoice_managed','payment_method'=>'online','deposit_policy'=>'none',
    'invoice_lead_days'=>365,'payment_terms_days'=>14,'billing_schedule'=>array(),'adopt_legacy'=>true,
), (int)$series->version );
if ( is_wp_error( $adopted ) || ! empty( $adopted->metadata_incomplete ) ) throw new RuntimeException( 'Legacy adoption failed.' );

$method = new ReflectionMethod( 'MBS_Billing_Engine', 'billable_occurrences' );
if ( PHP_VERSION_ID < 80100 ) $method->setAccessible( true );
$billable = $method->invoke( null, $series_ref );
if ( count($billable)!==1 || $billable[0]->ref!=='INT-L-FUTURE' ) throw new RuntimeException( 'Adoption made historical occurrences billable.' );

$catch_up = MBS_Billing_Engine::catch_up( $today->format('Y-m-d') );
if ( is_wp_error( $catch_up ) ) throw new RuntimeException( $catch_up->get_error_message() );
$billed_refs = $wpdb->get_col( $wpdb->prepare( "SELECT ii.booking_ref FROM {$item_table} ii INNER JOIN {$invoice_table} i ON i.id=ii.invoice_id WHERE i.series_ref=%s ORDER BY ii.booking_ref", $series_ref ) );
if ( $billed_refs !== array('INT-L-FUTURE') ) throw new RuntimeException( 'Catch-up billed historical legacy occurrences.' );

$fresh = MBS_Series::get( $series_ref );
$repeated = MBS_Billing_Engine::configure_series( $series_ref, array(
    'billing_mode'=>'monthly','billing_treatment'=>'invoice_managed','payment_method'=>'online','deposit_policy'=>'none',
    'invoice_lead_days'=>365,'payment_terms_days'=>14,'billing_schedule'=>array(),'adopt_legacy'=>true,
), (int)$fresh->version );
if ( is_wp_error($repeated) || (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$booking_table} WHERE series_id=%s AND legacy_billing_excluded=1",$series_ref))!==4 ) throw new RuntimeException('Repeated adoption changed the historical baseline.');
echo "OK: real registration/adoption/catch-up/repeated-adoption sequence billed only the eligible future occurrence.\n";
