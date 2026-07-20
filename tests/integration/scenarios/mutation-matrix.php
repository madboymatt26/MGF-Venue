<?php
$series_ref = 'INT-LEGACY';
$operations = array(
    'update_status' => function(){ return MBS_Bookings::update_series_status( 'INT-LEGACY', 'confirmed' ); },
    'cancel_future' => function(){ return MBS_Bookings::cancel_series_future( 'INT-LEGACY' ); },
    'edit_future' => function(){ return MBS_Bookings::update_series_future( 'INT-LEGACY', array('purpose'=>'bypass') ); },
    'extend' => function(){ return MBS_Bookings::extend_series( 'INT-LEGACY', wp_date('Y-m-d',strtotime('+60 days')) ); },
    'reopen' => function(){ return MBS_Bookings::reopen_series_future( 'INT-LEGACY' ); },
    'delete' => function(){ return MBS_Bookings::delete_series( 'INT-LEGACY', 'all' ); },
);
foreach ( $operations as $name=>$operation ) {
    $result = $operation();
    if ( ! is_wp_error($result) || $result->get_error_code() !== 'canonical_series_required' ) throw new RuntimeException( $name . ' bypassed the canonical first-class series service.' );
}
echo "OK: six direct compatibility mutation entry points rejected a first-class invoiced series.\n";
