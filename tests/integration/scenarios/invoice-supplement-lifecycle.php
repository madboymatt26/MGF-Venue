<?php
/**
 * Supplement invoice lifecycle integration tests.
 *
 * Covers: base monthly issuance, late occurrence supplement, parent linkage,
 * document creation, outbox, replay idempotency, successive supplements,
 * concurrent duplication protection.
 *
 * Run: wp eval-file /workspace/tests/integration/scenarios/invoice-supplement-lifecycle.php --allow-root
 */

require_once __DIR__ . '/audit-assertions.php';

global $wpdb;
$a = MBS_Audit_Assertions::current();

// ── Helpers ────────────────────────────────────────────────────────────────────

$supp_offset = 200;

function supp_create_series( $occurrence_count = 4 ) {
    global $wpdb, $supp_offset;
    $supp_offset++;
    $series_table = $wpdb->prefix . MBS_SERIES_TABLE;
    $booking_table = $wpdb->prefix . MBS_TABLE;

    $series_ref = 'INT-SUPP-' . strtoupper( substr( md5( uniqid( '', true ) . $supp_offset ), 0, 8 ) );
    // All occurrences in the same month for clean single-period testing
    $base_month = wp_date( 'Y-m', strtotime( '+' . $supp_offset . ' days' ) );
    $start_date = $base_month . '-01';
    $end_date = wp_date( 'Y-m-t', strtotime( $start_date ) );

    $wpdb->insert( $series_table, array(
        'series_ref'        => $series_ref,
        'status'            => 'confirmed',
        'billing_mode'      => 'monthly',
        'billing_treatment' => 'invoice_managed',
        'payment_method'    => 'offline_bacs',
        'invoice_lead_days' => 0,
        'payment_terms_days'=> 14,
        'version'           => 1,
        'start_date'        => $start_date,
        'repeat_until'      => $end_date,
        'contact_name'      => 'Supplement Test',
        'contact_email'     => 'supp-test@example.com',
        'space'             => 'Main Hall',
        'created_at'        => current_time( 'mysql' ),
    ) );

    $occurrences = array();
    for ( $i = 0; $i < $occurrence_count; $i++ ) {
        $day = str_pad( $i + 2, 2, '0', STR_PAD_LEFT );
        $date = $base_month . '-' . $day;
        $ref = 'MBS-SP' . $supp_offset . '-' . strtoupper( substr( md5( $series_ref . $i ), 0, 4 ) );
        $wpdb->insert( $booking_table, array(
            'ref' => $ref, 'series_id' => $series_ref, 'status' => 'confirmed',
            'name' => 'Supplement Test', 'email' => 'supp-test@example.com',
            'phone' => '07700900099', 'address' => 'Test Lane',
            'space' => 'Main Hall', 'booking_date' => $date,
            'start_time' => '18:00', 'end_time' => '20:00',
            'attendees' => 10, 'purpose' => 'Weekly session',
            'amount' => '50.00', 'pricing_tier' => 'standard',
            'legacy_billing_excluded' => 0, 'created_at' => current_time( 'mysql' ),
        ) );
        $occurrences[] = array( 'ref' => $ref, 'date' => $date, 'amount_minor' => 5000 );
    }

    return array( 'series_ref' => $series_ref, 'occurrences' => $occurrences, 'month' => $base_month );
}

function supp_add_late_occurrence( $series_ref, $month, $suffix = 'LATE' ) {
    global $wpdb, $supp_offset;
    $booking_table = $wpdb->prefix . MBS_TABLE;
    $ref = 'MBS-' . $suffix . '-' . strtoupper( substr( md5( uniqid() ), 0, 6 ) );
    $date = $month . '-20'; // Later in the month
    $wpdb->insert( $booking_table, array(
        'ref' => $ref, 'series_id' => $series_ref, 'status' => 'confirmed',
        'name' => 'Supplement Test', 'email' => 'supp-test@example.com',
        'phone' => '07700900099', 'address' => 'Test Lane',
        'space' => 'Main Hall', 'booking_date' => $date,
        'start_time' => '20:00', 'end_time' => '21:00',
        'attendees' => 5, 'purpose' => 'Extra session',
        'amount' => '25.00', 'pricing_tier' => 'standard',
        'legacy_billing_excluded' => 0, 'created_at' => current_time( 'mysql' ),
    ) );
    return array( 'ref' => $ref, 'date' => $date, 'amount_minor' => 2500 );
}

function supp_period_args( $series_ref, $month, $occurrences ) {
    $period_key = 'month-' . $month;
    return array(
        'period_key'   => $period_key,
        'period_start' => $month . '-01',
        'period_end'   => wp_date( 'Y-m-t', strtotime( $month . '-01' ) ),
        'issue_on'     => wp_date( 'Y-m-d' ),
        'due_on'       => wp_date( 'Y-m-d', strtotime( '+14 days' ) ),
        'occurrences'  => array_map( function( $o ) {
            return array( 'ref' => $o['ref'], 'date' => $o['date'], 'amount_minor' => $o['amount_minor'], 'description' => 'Session hire' );
        }, $occurrences ),
    );
}

// ── 1. Base monthly invoice ────────────────────────────────────────────────────

$a->run( 'Supplement: base monthly invoice issued correctly', function() use ( $wpdb ) {
    $setup = supp_create_series( 3 );
    $args = supp_period_args( $setup['series_ref'], $setup['month'], $setup['occurrences'] );

    $result = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], $args );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $result ), 'Base issue: ' . ( is_wp_error($result) ? $result->get_error_message() : '' ) );
    MBS_Audit_Assertions::assert_that( empty( $result['no_op'] ), 'Should be fresh issue' );
    MBS_Audit_Assertions::assert_that( ! empty( $result['invoice_ref'] ), 'Must have invoice_ref' );
    MBS_Audit_Assertions::assert_that( (int) $result['document_id'] > 0, 'Must have document_id' );

    // Verify allocations exist
    foreach ( $setup['occurrences'] as $occ ) {
        $alloc = MBS_Billing_Ledger::get_active_booking_allocation( $occ['ref'] );
        MBS_Audit_Assertions::assert_that( $alloc !== null, 'Allocation must exist for ' . $occ['ref'] );
    }

    // Verify outbox
    $queue = $wpdb->prefix . 'mathlin_email_queue';
    $count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$queue} WHERE message_key LIKE %s",
        'invoice_issued:' . $result['invoice_ref'] . '%'
    ) );
    MBS_Audit_Assertions::assert_that( $count === 1, 'Exactly 1 outbox entry for base invoice' );
});

// ── 2. Replay of base is no-op ────────────────────────────────────────────────

$a->run( 'Supplement: replay of issued base period is no-op', function() use ( $wpdb ) {
    $setup = supp_create_series( 2 );
    $args = supp_period_args( $setup['series_ref'], $setup['month'], $setup['occurrences'] );

    $first = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], $args );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $first ) && empty( $first['no_op'] ), 'First issue must succeed' );

    $replay = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], $args );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $replay ), 'Replay must not error' );
    MBS_Audit_Assertions::assert_that( ! empty( $replay['no_op'] ), 'Replay must be no-op' );
    MBS_Audit_Assertions::assert_that( $replay['invoice_ref'] === $first['invoice_ref'], 'Same invoice_ref on replay' );
});

// ── 3. Late occurrence creates exactly one supplement ──────────────────────────

$a->run( 'Supplement: late occurrence creates supplement with correct parent', function() use ( $wpdb ) {
    $setup = supp_create_series( 2 );
    $args = supp_period_args( $setup['series_ref'], $setup['month'], $setup['occurrences'] );

    $base = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], $args );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $base ) && empty( $base['no_op'] ), 'Base issue must succeed' );

    // Add a late occurrence
    $late = supp_add_late_occurrence( $setup['series_ref'], $setup['month'] );
    $all_occ = array_merge( $setup['occurrences'], array( $late ) );
    $args_with_late = supp_period_args( $setup['series_ref'], $setup['month'], $all_occ );

    $supplement = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], $args_with_late );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $supplement ), 'Supplement: ' . ( is_wp_error($supplement) ? $supplement->get_error_message() : '' ) );
    MBS_Audit_Assertions::assert_that( ! empty( $supplement['supplement'] ), 'Must be flagged as supplement' );
    MBS_Audit_Assertions::assert_that( empty( $supplement['no_op'] ), 'Must not be no-op' );
    MBS_Audit_Assertions::assert_that( $supplement['invoice_ref'] !== $base['invoice_ref'], 'Different invoice_ref' );

    // Verify parent linkage
    $invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
    $supp_invoice = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$invoice_table} WHERE invoice_ref = %s", $supplement['invoice_ref']
    ) );
    $base_invoice = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$invoice_table} WHERE invoice_ref = %s", $base['invoice_ref']
    ) );
    MBS_Audit_Assertions::assert_that( (int) $supp_invoice->parent_invoice_id === (int) $base_invoice->id, 'parent_invoice_id must reference base' );

    // Supplement contains only the late occurrence
    $items = MBS_Billing_Ledger::get_items( $supp_invoice->id );
    MBS_Audit_Assertions::assert_that( count( $items ) === 1, 'Supplement should have 1 item, got ' . count( $items ) );
    MBS_Audit_Assertions::assert_that( $items[0]->booking_ref === $late['ref'], 'Item must be the late occurrence' );

    // Document created for supplement
    MBS_Audit_Assertions::assert_that( (int) $supplement['document_id'] > 0, 'Supplement must have a document' );

    // Outbox entry for supplement
    $queue = $wpdb->prefix . 'mathlin_email_queue';
    $supp_outbox = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$queue} WHERE message_key LIKE %s",
        'invoice_issued:' . $supplement['invoice_ref'] . '%'
    ) );
    MBS_Audit_Assertions::assert_that( $supp_outbox === 1, 'Exactly 1 outbox for supplement' );
});

// ── 4. Supplement replay creates nothing ───────────────────────────────────────

$a->run( 'Supplement: replay creates nothing', function() use ( $wpdb ) {
    $setup = supp_create_series( 2 );
    $args = supp_period_args( $setup['series_ref'], $setup['month'], $setup['occurrences'] );

    $base = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], $args );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $base ), 'Base issue' );

    $late = supp_add_late_occurrence( $setup['series_ref'], $setup['month'] );
    $all_occ = array_merge( $setup['occurrences'], array( $late ) );
    $args_with_late = supp_period_args( $setup['series_ref'], $setup['month'], $all_occ );

    $supp1 = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], $args_with_late );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $supp1 ) && ! empty( $supp1['supplement'] ), 'First supplement created' );

    // Replay with same args
    $replay = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], $args_with_late );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $replay ), 'Replay must not error' );
    MBS_Audit_Assertions::assert_that( ! empty( $replay['no_op'] ), 'Replay must be no-op' );
});

// ── 5. Second distinct late addition creates next supplement ───────────────────

$a->run( 'Supplement: second late addition produces second supplement', function() use ( $wpdb ) {
    $setup = supp_create_series( 2 );
    $args = supp_period_args( $setup['series_ref'], $setup['month'], $setup['occurrences'] );

    $base = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], $args );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $base ), 'Base issue' );

    // First late addition
    $late1 = supp_add_late_occurrence( $setup['series_ref'], $setup['month'], 'LT1' );
    $occ_with_late1 = array_merge( $setup['occurrences'], array( $late1 ) );
    $args1 = supp_period_args( $setup['series_ref'], $setup['month'], $occ_with_late1 );
    $supp1 = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], $args1 );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $supp1 ) && ! empty( $supp1['supplement'] ), 'First supplement' );

    // Second late addition
    $late2 = supp_add_late_occurrence( $setup['series_ref'], $setup['month'], 'LT2' );
    $occ_with_both = array_merge( $occ_with_late1, array( $late2 ) );
    $args2 = supp_period_args( $setup['series_ref'], $setup['month'], $occ_with_both );
    $supp2 = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], $args2 );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $supp2 ), 'Second supplement: ' . ( is_wp_error($supp2) ? $supp2->get_error_message() : '' ) );
    MBS_Audit_Assertions::assert_that( ! empty( $supp2['supplement'] ), 'Must be flagged as supplement' );
    MBS_Audit_Assertions::assert_that( $supp2['invoice_ref'] !== $supp1['invoice_ref'], 'Different from first supplement' );

    // Subsequent replay creates nothing
    $replay2 = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], $args2 );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $replay2 ) && ! empty( $replay2['no_op'] ), 'Second replay is no-op' );
});

// ── 6. Concurrent supplement cannot duplicate ──────────────────────────────────
// This test verifies idempotency key uniqueness prevents duplication.
// In a real concurrent race, only one worker can insert the idempotency key.
// We test this by running the supplement flow twice in the same process —
// the idempotency key collision prevents a second distinct insert.

$a->run( 'Supplement: idempotency prevents duplicate supplement', function() use ( $wpdb ) {
    $setup = supp_create_series( 2 );
    $args = supp_period_args( $setup['series_ref'], $setup['month'], $setup['occurrences'] );
    $base = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], $args );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $base ), 'Base' );

    $late = supp_add_late_occurrence( $setup['series_ref'], $setup['month'] );
    $all_occ = array_merge( $setup['occurrences'], array( $late ) );
    $args_late = supp_period_args( $setup['series_ref'], $setup['month'], $all_occ );

    // Issue supplement
    $s1 = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], $args_late );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $s1 ) && ! empty( $s1['supplement'] ), 'Supplement issued' );

    // "Concurrent" attempt with same args
    $s2 = MBS_Series_Issuance_Service::issue_period_invoice( $setup['series_ref'], $args_late );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $s2 ), 'Second attempt must not error' );
    MBS_Audit_Assertions::assert_that( ! empty( $s2['no_op'] ), 'Second attempt must be no-op (idempotent)' );

    // Count invoices for this series/period — must be exactly 2 (base + supplement)
    $invoice_table = $wpdb->prefix . MBS_INVOICE_TABLE;
    $count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$invoice_table} WHERE series_ref = %s AND period_start = %s AND document_type = 'invoice'",
        $setup['series_ref'], $setup['month'] . '-01'
    ) );
    MBS_Audit_Assertions::assert_that( $count === 2, 'Exactly 2 invoices (base + supplement), got ' . $count );
});

// ── Finish ─────────────────────────────────────────────────────────────────────

$a->finish( 'supplement lifecycle integration scenarios passed' );
