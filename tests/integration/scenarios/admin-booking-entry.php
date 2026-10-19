<?php
/** Real WordPress integration; disposable integration site only. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'MBS_Admin_Booking_Entry' ) ) throw new RuntimeException( 'Integration runtime required.' );
if ( strpos( home_url(), 'localhost' ) === false && strpos( home_url(), 'wordpress' ) === false && strpos( home_url(), '127.0.0.1' ) === false ) throw new RuntimeException( 'Refusing non-test host.' );
function entry_ok( $value, $label ) { if ( ! $value ) throw new RuntimeException( $label ); }
$original = array();
foreach ( array( 'mbs_campsite_mode', 'mbs_payments_enabled', 'mbs_min_notice_days', 'mbs_min_duration_hours', 'mbs_spaces', 'mbs_kitchen_enabled' ) as $key ) $original[$key] = get_option( $key, null );
$admin = get_user_by( 'login', 'admin' ); wp_set_current_user( $admin->ID );
$actor = new MBS_Admin_Booking_Entry();
function entry_request( $method, $data ) { $r = new WP_REST_Request( 'POST' ); foreach ( $data as $k => $v ) $r->set_param( $k, $v ); if ( $method === 'preview' ) { $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $data ) ); } return $r; }
function entry_preview( $data ) { global $actor; $r = $actor->preview( entry_request( 'preview', $data ) ); entry_ok( ! is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : 'preview' ); return $r->get_data(); }
try {
    update_option( 'mbs_campsite_mode', 0 ); update_option( 'mbs_payments_enabled', 1 ); update_option( 'mbs_min_duration_hours', 2 );
    update_option( 'mbs_spaces', array( 'Entry Test' => array( 'capacity'=>100, 'rate_hourly'=>15, 'rate_daily'=>100 ), 'Entry Child' => array( 'capacity'=>20, 'rate_hourly'=>10, 'rate_daily'=>60, 'parent'=>'Entry Test' ) ) );
    $date = wp_date( 'Y-m-d', strtotime( '+180 days' ) );
    $data = array( 'purpose'=>'Disposable staff booking', 'space'=>'Entry Test', 'attendees'=>8, 'booking_date'=>$date, 'start_time'=>'18:00', 'end_time'=>'20:00', 'name'=>'Fictional Booker', 'email'=>'entry-contact@example.invalid', 'status'=>'pending' );
    $p = entry_preview( $data ); entry_ok( $p['plan']['estimated_total'] === 30.0, 'Normal paid estimate' );
    $denied = $actor->commit( entry_request( 'commit', array( 'token'=>$p['token'], 'confirm'=>false ) ) ); entry_ok( is_wp_error( $denied ), 'Explicit confirmation required' );
    $result = $actor->commit( entry_request( 'commit', array( 'token'=>$p['token'], 'confirm'=>true ) ) ); entry_ok( ! is_wp_error( $result ), 'Create paid pending booking' );
    $saved = $result->get_data(); $ref = $saved['booking']['ref']; $booking = MBS_Bookings::get( $ref );
    entry_ok( $booking->status === 'pending' && (int) $booking->user_id === 0 && (float) $booking->amount === 30.0, 'Staff is not hirer; paid pending retained' );
    $again = $actor->commit( entry_request( 'commit', array( 'token'=>$p['token'], 'confirm'=>true ) ) ); entry_ok( $again->get_data()['booking']['ref'] === $ref, 'Lost response retry returns same booking' );
    $conflict = MBS_Admin_Booking_Entry::plan( $data ); entry_ok( is_wp_error( $conflict ), 'Existing reservation blocks preview' );
    $child = $data; $child['space']='Entry Child'; entry_ok( is_wp_error( MBS_Admin_Booking_Entry::plan( $child ) ), 'Parent-child dependencies apply' );
    $changed = $data; $changed['booking_date'] = wp_date( 'Y-m-d', strtotime( $date . ' +2 days' ) ); $p = entry_preview( $changed );
    update_option( 'mbs_spaces', array( 'Entry Test'=>array( 'capacity'=>100,'rate_hourly'=>20,'rate_daily'=>100 ) ) );
    entry_ok( is_wp_error( $actor->commit( entry_request( 'commit', array( 'token'=>$p['token'], 'confirm'=>true ) ) ) ), 'Price changes invalidate preview' );
    $paid_series = $changed; $paid_series['recurrence_pattern']='weekly'; $paid_series['repeat_until']=wp_date( 'Y-m-d', strtotime( $changed['booking_date'] . ' +14 days' ) ); $paid_series['status']='confirmed';
    entry_ok( is_wp_error( MBS_Admin_Booking_Entry::plan( $paid_series ) ), 'Paid series needs billing approval, not direct confirm' );
    update_option( 'mbs_campsite_mode', 1 ); update_option( 'mbs_payments_enabled', 0 ); update_option( 'mbs_min_duration_hours', 0 ); update_option( 'mbs_kitchen_enabled', 0 );
    $estate = $changed; $estate['entry_type']='estate'; $estate['coordinator_contact']=true; $estate['campsite_exception']=true; $estate['exception_reason']='Test estate agreement'; $estate['recurrence_pattern']='fortnightly'; $estate['repeat_until']=wp_date( 'Y-m-d', strtotime( $estate['booking_date'] . ' +28 days' ) );
    $p = entry_preview( $estate ); entry_ok( count( $p['plan']['schedule'] ) === 3 && (float) $p['plan']['estimated_total'] === 0.0, 'Free fortnightly estate dates' );
    $r = $actor->commit( entry_request( 'commit', array( 'token'=>$p['token'], 'confirm'=>true ) ) ); entry_ok( ! is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : 'series saved' );
    $series = $r->get_data()['series']; entry_ok( $series['count'] === 3 && ! $series['skipped'], 'Correct series saved through normal API' );
    foreach ( MBS_Bookings::get_series( $series['series_id'] ) as $row ) entry_ok( $row->status === 'pending' && (float) $row->amount === 0.0 && ! $row->scout_use, 'Estate stays in general booking list, not Scout nights' );
    $estate['notify_hirer']=true; entry_ok( is_wp_error( MBS_Admin_Booking_Entry::plan( $estate ) ), 'Coordinator-only customer email forbidden' );
    wp_set_current_user( 0 ); entry_ok( ! $actor->allowed(), 'Anonymous callers denied' );
    echo "STAFF_BOOKING_ENTRY_INTEGRATION_OK\n";
} finally { foreach ( $original as $key=>$value ) { if ( $value === null ) delete_option( $key ); else update_option( $key, $value ); } wp_set_current_user( $admin->ID ); }
