<?php
if ( ! defined( 'ABSPATH' ) ) exit( 1 );

function mbs_scout_assert( $condition, $message ) {
    if ( ! $condition ) throw new RuntimeException( $message );
}

$start = ( new DateTimeImmutable( '+90 days', wp_timezone() ) )->modify( 'next monday' );
$start_date = $start->format( 'Y-m-d' );
$repeat_until = $start->modify( '+14 days' )->format( 'Y-m-d' );
$data = array(
    'name' => 'Integration Test Scouts', 'organisation' => 'MGF Venue Test',
    'email' => get_option( 'admin_email' ), 'phone' => '', 'address' => '',
    'space' => 'Main Hall', 'kitchen' => false,
    'booking_date' => $start_date, 'booking_date_end' => $start_date,
    'all_day' => false, 'scout_use' => true, 'pricing_tier' => 'standard',
    'start_time' => '06:00', 'end_time' => '07:00', 'attendees' => 0,
    'purpose' => 'Integration Test Scouts', 'notes' => '', 'is_public' => false,
    'accept_terms' => true,
);

$created = MBS_Bookings::create_recurring( $data, $repeat_until, true );
mbs_scout_assert( ! is_wp_error( $created ), 'Scout recurring creation failed: ' . ( is_wp_error( $created ) ? $created->get_error_message() : '' ) );
$series_ref = $created['series_id'];
$one_off_ref = '';

try {
    $series = MBS_Series::get( $series_ref );
    mbs_scout_assert( $series && ! empty( $series->scout_use ), 'Scout parent series was not created.' );
    mbs_scout_assert( $series->billing_treatment === 'none' && $series->payment_method === 'none', 'Scout parent series is not explicitly no-charge.' );

    $approved = MBS_Series::approve( $series_ref, 'pending', (int) $series->version, false );
    mbs_scout_assert( ! is_wp_error( $approved ), 'Scout series approval failed.' );

    $scout_rows = MBS_Series::get_all( array( 'scout_use' => 1, 'search' => $series_ref ) );
    $external_rows = MBS_Series::get_all( array( 'scout_use' => 0, 'search' => $series_ref ) );
    mbs_scout_assert( count( $scout_rows ) === 1 && count( $external_rows ) === 0, 'Scout/external series filtering failed.' );

    // The Scout Nights one-off tab must query individual Scout bookings without
    // leaking series occurrences or external hires, including after archiving.
    $one_off_data = array_merge( $data, array(
        'booking_date' => $start->modify( '+1 day' )->format( 'Y-m-d' ),
        'booking_date_end' => $start->modify( '+1 day' )->format( 'Y-m-d' ),
        'purpose' => 'One-off Scout UI regression',
    ) );
    $one_off = MBS_Bookings::create( $one_off_data, true );
    mbs_scout_assert( ! is_wp_error( $one_off ), 'One-off Scout fixture could not be created.' );
    $one_off_ref = $one_off['ref'];
    $one_off_args = array( 'scout_only' => true, 'one_off_only' => true, 'exclude_archived' => false, 'date_scope' => 'current', 'search' => $one_off_ref );
    $individual = MBS_Bookings::get_all( $one_off_args );
    mbs_scout_assert( count( $individual ) === 1 && $individual[0]->ref === $one_off_ref && (float) $individual[0]->amount === 0.0, 'Free one-off Scout booking is missing from the tab query.' );
    mbs_scout_assert( count( MBS_Bookings::get_all( array_merge( $one_off_args, array( 'search' => $created['refs'][0] ) ) ) ) === 0, 'A recurring Scout occurrence leaked into the one-off list.' );
    mbs_scout_assert( count( MBS_Bookings::get_all( array_merge( $one_off_args, array( 'date_scope' => 'ended' ) ) ) ) === 0, 'Future booking appeared in past bookings.' );
    MBS_Bookings::update_status( $one_off_ref, 'archived', false );
    mbs_scout_assert( count( MBS_Bookings::get_all( array_merge( $one_off_args, array( 'status' => 'archived', 'date_scope' => 'all' ) ) ) ) === 1, 'Archived Scout booking is hidden from the archived/all-dates filter.' );

    $edited = MBS_Bookings::update_series_future( $series_ref, array( 'purpose' => 'Updated Integration Scouts' ) );
    mbs_scout_assert( ! is_wp_error( $edited ) && $edited['updated'] === 3, 'Future Scout series edit failed.' );
    mbs_scout_assert( MBS_Series::get( $series_ref )->purpose === 'Updated Integration Scouts', 'Scout parent purpose did not synchronise.' );

    $extended_until = $start->modify( '+21 days' )->format( 'Y-m-d' );
    $extended = MBS_Bookings::extend_series( $series_ref, $extended_until );
    mbs_scout_assert( ! is_wp_error( $extended ) && $extended['created'] === 1, 'Scout series extension failed.' );
    mbs_scout_assert( MBS_Series::get( $series_ref )->repeat_until === $extended_until, 'Scout parent end date did not synchronise.' );

    $cancelled = MBS_Bookings::cancel_series_future( $series_ref );
    mbs_scout_assert( ! is_wp_error( $cancelled ) && $cancelled === 4, 'Scout future cancellation failed.' );
    mbs_scout_assert( MBS_Series::get( $series_ref )->status === 'cancelled', 'Scout parent cancellation status did not synchronise.' );

    $reopened = MBS_Bookings::reopen_series_future( $series_ref );
    mbs_scout_assert( ! is_wp_error( $reopened ) && $reopened === 4, 'Scout future reopening failed.' );
    mbs_scout_assert( MBS_Series::get( $series_ref )->status === 'confirmed', 'Scout parent reopen status did not synchronise.' );

    $deleted = MBS_Bookings::delete_series( $series_ref, 'all' );
    mbs_scout_assert( ! is_wp_error( $deleted ) && $deleted === 4, 'Scout series deletion failed.' );
    mbs_scout_assert( ! MBS_Series::get( $series_ref ), 'Empty Scout parent remained after full deletion.' );
    $series_ref = '';
} finally {
    if ( $one_off_ref ) MBS_Bookings::delete( $one_off_ref );
    if ( $series_ref ) MBS_Bookings::delete_series( $series_ref, 'all' );
}

echo "SCOUT_SERIES_ADMIN_OK\n";
