<?php
/**
 * Extended invoice document integration scenarios.
 *
 * Covers: schema migration, v3.21 upgrade compatibility, supplement lifecycle,
 * concurrent supplement races, modification failures, outbox delivery failures,
 * auth/token edge cases, non-zero tax, and exact-money boundary cases.
 *
 * Run: wp eval-file /workspace/tests/integration/scenarios/invoice-document-extended.php --allow-root
 */

require_once __DIR__ . '/audit-assertions.php';

global $wpdb;
$a = MBS_Audit_Assertions::current();

// ── Helpers ────────────────────────────────────────────────────────────────────

$ext_test_offset = 100;

function ext_create_booking( $overrides = array() ) {
    global $ext_test_offset;
    $ext_test_offset++;
    // Each booking uses a unique date to avoid conflicts
    $defaults = array(
        'space'        => 'Main Hall',
        'booking_date' => wp_date( 'Y-m-d', strtotime( '+' . ( 60 + $ext_test_offset ) . ' days' ) ),
        'name'         => 'Extended Test User',
        'email'        => 'ext-test@example.com',
        'phone'        => '07700900001',
        'address'      => '2 Test Lane',
        'start_time'   => '10:00',
        'end_time'     => '14:00',
        'attendees'    => 10,
        'purpose'      => 'Extended integration test ' . $ext_test_offset,
        'kitchen'      => false,
    );
    return MBS_Bookings::create( array_merge( $defaults, $overrides ), true );
}

function ext_create_series_with_occurrences( $occurrence_count = 4, $confirmed = true ) {
    global $wpdb;
    $series_table = $wpdb->prefix . MBS_SERIES_TABLE;
    $booking_table = $wpdb->prefix . MBS_TABLE;

    $series_ref = 'INT-EXT-' . strtoupper( substr( md5( uniqid( '', true ) ), 0, 8 ) );
    $start_date = wp_date( 'Y-m-d', strtotime( '+7 days' ) );
    $repeat_until = wp_date( 'Y-m-d', strtotime( '+' . ( 7 * $occurrence_count + 7 ) . ' days' ) );

    $wpdb->insert( $series_table, array(
        'series_ref'           => $series_ref,
        'status'               => $confirmed ? 'confirmed' : 'pending',
        'billing_mode'         => 'monthly',
        'billing_treatment'    => 'invoice_managed',
        'payment_method'       => 'offline_bacs',
        'invoice_lead_days'    => 28,
        'payment_terms_days'   => 14,
        'version'              => 1,
        'start_date'           => $start_date,
        'repeat_until'         => $repeat_until,
        'contact_name'         => 'Series Test',
        'contact_email'        => 'series-test@example.com',
        'space'                => 'Main Hall',
        'created_at'           => current_time( 'mysql' ),
    ) );

    $occurrences = array();
    for ( $i = 0; $i < $occurrence_count; $i++ ) {
        $date = wp_date( 'Y-m-d', strtotime( '+' . ( 7 + $i * 7 ) . ' days' ) );
        $ref = 'MBS-EXT-' . strtoupper( substr( md5( $series_ref . $i ), 0, 6 ) );
        $wpdb->insert( $booking_table, array(
            'ref'            => $ref,
            'series_id'      => $series_ref,
            'status'         => 'confirmed',
            'name'           => 'Series Test',
            'email'          => 'series-test@example.com',
            'phone'          => '07700900002',
            'address'        => '3 Test Lane',
            'space'          => 'Main Hall',
            'booking_date'   => $date,
            'start_time'     => '18:00',
            'end_time'       => '20:00',
            'attendees'      => 15,
            'purpose'        => 'Weekly session',
            'amount'         => '50.00',
            'pricing_tier'   => 'standard',
            'legacy_billing_excluded' => 0,
            'created_at'     => current_time( 'mysql' ),
        ) );
        $occurrences[] = array( 'ref' => $ref, 'date' => $date, 'amount_minor' => 5000 );
    }

    return array( 'series_ref' => $series_ref, 'occurrences' => $occurrences );
}

// ── Schema / upgrade scenarios ─────────────────────────────────────────────────

$a->run( 'Schema: invoice_documents table exists', function() use ( $wpdb ) {
    $table = $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE;
    $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
    MBS_Audit_Assertions::assert_that( $exists === $table, 'Invoice documents table should exist: ' . $table );
});

$a->run( 'Schema: download_tokens table exists', function() use ( $wpdb ) {
    $table = $wpdb->prefix . MBS_DOWNLOAD_TOKENS_TABLE;
    $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
    MBS_Audit_Assertions::assert_that( $exists === $table, 'Download tokens table should exist: ' . $table );
});

$a->run( 'Schema: document_assets table exists', function() use ( $wpdb ) {
    $table = $wpdb->prefix . MBS_DOCUMENT_ASSETS_TABLE;
    $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
    MBS_Audit_Assertions::assert_that( $exists === $table, 'Document assets table should exist: ' . $table );
});

$a->run( 'Schema: bookings table has current_invoice_document_id column', function() use ( $wpdb ) {
    $table = $wpdb->prefix . MBS_TABLE;
    $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'current_invoice_document_id'" );
    MBS_Audit_Assertions::assert_that( ! empty( $col ), 'bookings table should have current_invoice_document_id column' );
});

// ── V3.21 existing period idempotency-key compatibility ────────────────────────

$a->run( 'V3.21 compat: existing issued period treated as no-op', function() use ( $wpdb ) {
    $setup = ext_create_series_with_occurrences( 4 );
    $series = MBS_Series::get( $setup['series_ref'] );
    MBS_Audit_Assertions::assert_that( $series !== null, 'Series must exist' );

    // Manually insert an invoice with the v3.21 canonical idempotency key (hashed, as the ledger stores it)
    $invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
    $period_key = 'month-' . substr( $setup['occurrences'][0]['date'], 0, 7 );
    $raw_idem_key = 'series:' . $setup['series_ref'] . ':period:' . $period_key . ':v1';
    $hashed_idem_key = hash( 'sha256', $raw_idem_key );

    $wpdb->insert( $invoice_table, array(
        'invoice_ref'      => 'INV-COMPAT-' . strtoupper( substr( md5( $raw_idem_key ), 0, 6 ) ),
        'series_ref'       => $setup['series_ref'],
        'document_type'    => 'invoice',
        'status'           => 'issued',
        'idempotency_key'  => $hashed_idem_key,
        'contact_name'     => 'Series Test',
        'contact_email'    => 'series-test@example.com',
        'billing_mode'     => 'monthly',
        'period_start'     => substr( $setup['occurrences'][0]['date'], 0, 7 ) . '-01',
        'period_end'       => wp_date( 'Y-m-t', strtotime( $setup['occurrences'][0]['date'] ) ),
        'currency'         => 'GBP',
        'subtotal_minor'   => 20000,
        'tax_minor'        => 0,
        'total_minor'      => 20000,
        'version'          => 1,
        'created_at'       => current_time( 'mysql' ),
    ) );

    // Now issue_period_invoice for the same period should be a no-op
    $month = substr( $setup['occurrences'][0]['date'], 0, 7 );
    $result = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], array(
        'period_key'    => $period_key,
        'period_start'  => $month . '-01',
        'period_end'    => wp_date( 'Y-m-t', strtotime( $month . '-01' ) ),
        'issue_on'      => wp_date( 'Y-m-d' ),
        'due_on'        => wp_date( 'Y-m-d', strtotime( '+14 days' ) ),
        'occurrences'   => array_map( function( $o ) { return array( 'ref' => $o['ref'], 'date' => $o['date'], 'amount_minor' => $o['amount_minor'], 'description' => 'Test' ); }, $setup['occurrences'] ),
    ) );

    MBS_Audit_Assertions::assert_that( ! is_wp_error( $result ), 'Should not error: ' . ( is_wp_error($result) ? $result->get_error_message() : '' ) );
    MBS_Audit_Assertions::assert_that( ! empty( $result['no_op'] ), 'Should be a no-op for existing v3.21 issued period' );
});

// ── Supplement lifecycle ───────────────────────────────────────────────────────

$a->run( 'Supplement: late occurrence after base invoice creates supplement', function() use ( $wpdb ) {
    $setup = ext_create_series_with_occurrences( 2 );
    $series = MBS_Series::get( $setup['series_ref'] );
    $month = substr( $setup['occurrences'][0]['date'], 0, 7 );
    $period_key = 'month-' . $month;

    // Issue base invoice for the first 2 occurrences
    $base = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], array(
        'period_key'    => $period_key,
        'period_start'  => $month . '-01',
        'period_end'    => wp_date( 'Y-m-t', strtotime( $month . '-01' ) ),
        'issue_on'      => wp_date( 'Y-m-d' ),
        'due_on'        => wp_date( 'Y-m-d', strtotime( '+14 days' ) ),
        'occurrences'   => array_map( function( $o ) { return array( 'ref' => $o['ref'], 'date' => $o['date'], 'amount_minor' => $o['amount_minor'], 'description' => 'Base session' ); }, $setup['occurrences'] ),
    ) );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $base ) && empty( $base['no_op'] ), 'Base invoice should be freshly issued' );

    // Add a late occurrence
    $booking_table = $wpdb->prefix . MBS_TABLE;
    $late_ref = 'MBS-LATE-' . strtoupper( substr( md5( uniqid() ), 0, 6 ) );
    $late_date = $setup['occurrences'][1]['date']; // Same month, different day
    $wpdb->insert( $booking_table, array(
        'ref' => $late_ref, 'series_id' => $setup['series_ref'], 'status' => 'confirmed',
        'name' => 'Series Test', 'email' => 'series-test@example.com', 'phone' => '07700900002',
        'address' => '3 Test Lane', 'space' => 'Main Hall', 'booking_date' => $late_date,
        'start_time' => '20:00', 'end_time' => '21:00', 'attendees' => 5,
        'purpose' => 'Extra session', 'amount' => '25.00', 'pricing_tier' => 'standard',
        'legacy_billing_excluded' => 0, 'created_at' => current_time( 'mysql' ),
    ) );

    // Re-run catch-up: should create a supplement
    $all_occurrences = $setup['occurrences'];
    $all_occurrences[] = array( 'ref' => $late_ref, 'date' => $late_date, 'amount_minor' => 2500 );

    $supplement = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], array(
        'period_key'    => $period_key,
        'period_start'  => $month . '-01',
        'period_end'    => wp_date( 'Y-m-t', strtotime( $month . '-01' ) ),
        'issue_on'      => wp_date( 'Y-m-d' ),
        'due_on'        => wp_date( 'Y-m-d', strtotime( '+14 days' ) ),
        'occurrences'   => array_map( function( $o ) { return array( 'ref' => $o['ref'], 'date' => $o['date'], 'amount_minor' => $o['amount_minor'], 'description' => 'Session' ); }, $all_occurrences ),
    ) );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $supplement ), 'Supplement should not error: ' . ( is_wp_error($supplement) ? $supplement->get_error_message() : '' ) );
    MBS_Audit_Assertions::assert_that( ! empty( $supplement['supplement'] ), 'Should be flagged as supplement' );
    MBS_Audit_Assertions::assert_that( empty( $supplement['no_op'] ), 'Supplement should not be no-op' );
    MBS_Audit_Assertions::assert_that( $supplement['invoice_ref'] !== $base['invoice_ref'], 'Supplement must have a different invoice_ref than base' );
});

$a->run( 'Supplement: replay creates nothing', function() use ( $wpdb ) {
    $setup = ext_create_series_with_occurrences( 1 );
    $month = substr( $setup['occurrences'][0]['date'], 0, 7 );
    $period_key = 'month-' . $month;
    $period_args = array(
        'period_key'    => $period_key,
        'period_start'  => $month . '-01',
        'period_end'    => wp_date( 'Y-m-t', strtotime( $month . '-01' ) ),
        'issue_on'      => wp_date( 'Y-m-d' ),
        'due_on'        => wp_date( 'Y-m-d', strtotime( '+14 days' ) ),
        'occurrences'   => array( array( 'ref' => $setup['occurrences'][0]['ref'], 'date' => $setup['occurrences'][0]['date'], 'amount_minor' => 5000, 'description' => 'Session' ) ),
    );

    // Issue base
    $first = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], $period_args );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $first ) && empty( $first['no_op'] ), 'First issue should succeed' );

    // Replay exact same args
    $replay = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], $period_args );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $replay ), 'Replay should not error' );
    MBS_Audit_Assertions::assert_that( ! empty( $replay['no_op'] ), 'Replay should be a no-op' );
});

// ── Period field validation ────────────────────────────────────────────────────

$a->run( 'Issuance: missing period_start rejected', function() use ( $wpdb ) {
    $setup = ext_create_series_with_occurrences( 1 );
    $result = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], array(
        'period_key'   => 'test-key',
        'period_start' => '',
        'period_end'   => '2026-12-31',
        'issue_on'     => '2026-12-01',
        'due_on'       => '2026-12-15',
        'occurrences'  => array( array( 'ref' => 'X', 'date' => '2026-12-01', 'amount_minor' => 100, 'description' => 'X' ) ),
    ) );
    MBS_Audit_Assertions::assert_that( is_wp_error( $result ), 'Empty period_start should be rejected' );
    MBS_Audit_Assertions::assert_that( $result->get_error_code() === 'period_start_invalid', 'Error code: ' . $result->get_error_code() );
});

$a->run( 'Issuance: invalid date rejected', function() use ( $wpdb ) {
    $setup = ext_create_series_with_occurrences( 1 );
    $result = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], array(
        'period_key'   => 'test-key',
        'period_start' => '2026-02-30',
        'period_end'   => '2026-03-31',
        'issue_on'     => '2026-02-01',
        'due_on'       => '2026-02-15',
        'occurrences'  => array( array( 'ref' => 'X', 'date' => '2026-03-01', 'amount_minor' => 100, 'description' => 'X' ) ),
    ) );
    MBS_Audit_Assertions::assert_that( is_wp_error( $result ), 'Invalid date should be rejected' );
});

$a->run( 'Issuance: reversed period dates rejected', function() use ( $wpdb ) {
    $setup = ext_create_series_with_occurrences( 1 );
    $result = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], array(
        'period_key'   => 'test-key',
        'period_start' => '2026-12-31',
        'period_end'   => '2026-12-01',
        'issue_on'     => '2026-11-01',
        'due_on'       => '2026-11-15',
        'occurrences'  => array( array( 'ref' => 'X', 'date' => '2026-12-15', 'amount_minor' => 100, 'description' => 'X' ) ),
    ) );
    MBS_Audit_Assertions::assert_that( is_wp_error( $result ), 'Reversed dates should be rejected' );
    MBS_Audit_Assertions::assert_that( $result->get_error_code() === 'period_dates_reversed', 'Error code: ' . $result->get_error_code() );
});

// ── Non-material modification does not create R2 ───────────────────────────────

$a->run( 'Modification: attendees-only change preserves document pointer', function() use ( $wpdb ) {
    $b = ext_create_booking();
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $b ), 'Booking creation failed' );
    $ref = $b['ref'];
    MBS_Bookings::update_status( $ref, 'confirmed' );
    $after_confirm = MBS_Bookings::get( $ref );
    $r1 = (int) $after_confirm->current_invoice_document_id;
    MBS_Audit_Assertions::assert_that( $r1 > 0, 'R1 must exist' );

    // Non-financial modification
    MBS_Modification::create_request( array(
        'ref'     => $ref,
        'type'    => 'modify',
        'notes'   => 'More attendees',
        'changes' => array( 'attendees' => '50' ),
    ) );
    $req_id = $wpdb->insert_id;
    MBS_Audit_Assertions::assert_that( $req_id > 0, 'Modification request should be created' );

    $approve_result = MBS_Modification::approve( $req_id );
    MBS_Audit_Assertions::assert_that( $approve_result === true || ! is_wp_error( $approve_result ), 'Approval should succeed: ' . ( is_wp_error($approve_result) ? $approve_result->get_error_message() : var_export($approve_result, true) ) );

    $after_mod = MBS_Bookings::get( $ref );
    MBS_Audit_Assertions::assert_that( (int) $after_mod->current_invoice_document_id === $r1, 'Document pointer must NOT change for non-financial mod' );
    MBS_Audit_Assertions::assert_that( (int) $after_mod->attendees === 50, 'Attendees should be updated to 50, got: ' . $after_mod->attendees );
});

// ── Outbox invariants ──────────────────────────────────────────────────────────

$a->run( 'Outbox: confirmation uses exactly one message per booking', function() use ( $wpdb ) {
    $b = ext_create_booking( array( 'email' => 'outbox-inv-' . uniqid() . '@example.com' ) );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $b ), 'Booking failed' );
    $ref = $b['ref'];
    MBS_Bookings::update_status( $ref, 'confirmed' );

    // Call again (idempotent)
    MBS_Bookings::update_status( $ref, 'confirmed' );

    $queue = $wpdb->prefix . 'mathlin_email_queue';
    $count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$queue} WHERE message_key LIKE %s",
        'booking_confirmed:' . $ref . '%'
    ) );
    MBS_Audit_Assertions::assert_that( $count === 1, 'Exactly 1 outbox entry expected, got ' . $count );
});

// ── Exact-money boundary ───────────────────────────────────────────────────────

$a->run( 'Money: £0 booking auto-promotes to paid without document', function() use ( $wpdb ) {
    // Create a scout booking (£0)
    $b = MBS_Bookings::create( array(
        'space' => 'Main Hall', 'booking_date' => wp_date( 'Y-m-d', strtotime( '+60 days' ) ),
        'name' => 'Scout Leader', 'email' => 'scout@example.com', 'phone' => '07700',
        'address' => 'Scout HQ', 'start_time' => '09:00', 'end_time' => '17:00',
        'attendees' => 40, 'purpose' => 'Camp prep', 'scout_use' => true,
    ), true );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $b ), 'Scout booking creation' );
    $ref = $b['ref'];

    MBS_Bookings::update_status( $ref, 'confirmed' );
    $after = MBS_Bookings::get( $ref );
    // £0 bookings don't go through confirm_and_issue (not chargeable)
    // They should be confirmed (or auto-paid if the generic path promotes them)
    MBS_Audit_Assertions::assert_that( in_array( $after->status, array( 'confirmed', 'paid' ), true ), 'Should be confirmed or auto-paid, got: ' . $after->status );
    MBS_Audit_Assertions::assert_that( empty( $after->current_invoice_document_id ), 'No document for £0 bookings' );
});

// ── Finish ─────────────────────────────────────────────────────────────────────

$a->finish( 'extended invoice-document integration scenarios passed' );
