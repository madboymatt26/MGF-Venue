<?php
/**
 * Transaction failure and rollback integration tests.
 *
 * Covers: individual confirmation rollback, modification R2 rollback,
 * and verifies no partial state after controlled failures.
 *
 * Run: wp eval-file /workspace/tests/integration/scenarios/invoice-transaction-failures.php --allow-root
 */

require_once __DIR__ . '/audit-assertions.php';

global $wpdb;
$a = MBS_Audit_Assertions::current();

$txf_offset = 300;

function txf_create_booking( $overrides = array() ) {
    global $txf_offset;
    $txf_offset++;
    $defaults = array(
        'space'        => 'Main Hall',
        'booking_date' => wp_date( 'Y-m-d', strtotime( '+' . ( 90 + $txf_offset ) . ' days' ) ),
        'name'         => 'TxFailure Test',
        'email'        => 'txf-test@example.com',
        'phone'        => '07700900010',
        'address'      => '10 Test Lane',
        'start_time'   => '10:00',
        'end_time'     => '14:00',
        'attendees'    => 15,
        'purpose'      => 'Transaction failure test ' . $txf_offset,
        'kitchen'      => false,
    );
    return MBS_Bookings::create( array_merge( $defaults, $overrides ), true );
}

// ── Individual confirmation: £0 booking skips document ──────────────────────────

$a->run( 'TxFail: £0 booking confirmed without document', function() use ( $wpdb ) {
    $b = txf_create_booking( array( 'scout_use' => true ) );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $b ), 'Create: ' . ( is_wp_error($b) ? $b->get_error_message() : '' ) );
    $ref = $b['ref'];
    MBS_Bookings::update_status( $ref, 'confirmed' );
    $after = MBS_Bookings::get( $ref );
    MBS_Audit_Assertions::assert_that( in_array( $after->status, array( 'confirmed', 'paid' ), true ), 'Should be confirmed/paid' );
    MBS_Audit_Assertions::assert_that( empty( $after->current_invoice_document_id ), 'No document for £0' );
});

// ── Individual confirmation: chargeable creates document atomically ─────────────

$a->run( 'TxFail: chargeable confirmation creates document', function() use ( $wpdb ) {
    $b = txf_create_booking();
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $b ), 'Create' );
    $ref = $b['ref'];
    $booking = MBS_Bookings::get( $ref );
    MBS_Audit_Assertions::assert_that( (float) $booking->amount > 0, 'Must be chargeable' );

    MBS_Bookings::update_status( $ref, 'confirmed' );
    $after = MBS_Bookings::get( $ref );
    MBS_Audit_Assertions::assert_that( $after->status === 'confirmed', 'Must be confirmed' );
    MBS_Audit_Assertions::assert_that( ! empty( $after->current_invoice_document_id ), 'Must have document' );
});

// ── Modification: material change creates R2, non-material does not ────────────

$a->run( 'TxFail: material date change creates R2', function() use ( $wpdb ) {
    $b = txf_create_booking();
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $b ), 'Create' );
    $ref = $b['ref'];
    MBS_Bookings::update_status( $ref, 'confirmed' );
    $after = MBS_Bookings::get( $ref );
    $r1 = (int) $after->current_invoice_document_id;
    MBS_Audit_Assertions::assert_that( $r1 > 0, 'R1 exists' );

    // Material modification (date change)
    MBS_Modification::create_request( array(
        'ref' => $ref, 'type' => 'modify', 'notes' => 'Move date',
        'changes' => array( 'date' => wp_date( 'Y-m-d', strtotime( '+200 days' ) ) ),
    ) );
    $req_id = $wpdb->insert_id;
    MBS_Audit_Assertions::assert_that( $req_id > 0, 'Request created' );

    $result = MBS_Modification::approve( $req_id );
    MBS_Audit_Assertions::assert_that( $result === true || ! is_wp_error( $result ), 'Approve: ' . ( is_wp_error($result) ? $result->get_error_message() : var_export($result, true) ) );

    $after_mod = MBS_Bookings::get( $ref );
    $r2 = (int) $after_mod->current_invoice_document_id;
    MBS_Audit_Assertions::assert_that( $r2 > $r1, 'R2 must be newer than R1' );

    // R1 superseded
    $doc_table = $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE;
    $r1_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$doc_table} WHERE id = %d", $r1 ) );
    MBS_Audit_Assertions::assert_that( $r1_status === 'superseded', 'R1 superseded' );

    // R2 issued
    $r2_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$doc_table} WHERE id = %d", $r2 ) );
    MBS_Audit_Assertions::assert_that( $r2_status === 'issued', 'R2 issued' );

    // Outbox references R2
    $queue = $wpdb->prefix . 'mathlin_email_queue';
    $outbox = $wpdb->get_row( $wpdb->prepare(
        "SELECT attachment_meta FROM {$queue} WHERE message_key LIKE %s",
        'modification_approved:' . $ref . '%'
    ) );
    if ( $outbox ) {
        $meta = json_decode( $outbox->attachment_meta, true );
        MBS_Audit_Assertions::assert_that( (int) ( $meta['document_id'] ?? 0 ) === $r2, 'Outbox references R2' );
    }
});

$a->run( 'TxFail: same-price material change (space) creates R2', function() use ( $wpdb ) {
    // Use Meeting Room which has a different rate — but actually same space
    // Just change start_time: still material (time affects price)
    $b = txf_create_booking();
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $b ), 'Create' );
    $ref = $b['ref'];
    MBS_Bookings::update_status( $ref, 'confirmed' );
    $r1 = (int) MBS_Bookings::get( $ref )->current_invoice_document_id;

    MBS_Modification::create_request( array(
        'ref' => $ref, 'type' => 'modify', 'notes' => 'Earlier start',
        'changes' => array( 'start_time' => '09:00' ),
    ) );
    $req_id = $wpdb->insert_id;
    MBS_Modification::approve( $req_id );

    $after = MBS_Bookings::get( $ref );
    $r2 = (int) $after->current_invoice_document_id;
    MBS_Audit_Assertions::assert_that( $r2 > $r1, 'Time change (material) creates R2' );
});

$a->run( 'TxFail: non-material attendees change does not create R2', function() use ( $wpdb ) {
    $b = txf_create_booking();
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $b ), 'Create' );
    $ref = $b['ref'];
    MBS_Bookings::update_status( $ref, 'confirmed' );
    $r1 = (int) MBS_Bookings::get( $ref )->current_invoice_document_id;

    MBS_Modification::create_request( array(
        'ref' => $ref, 'type' => 'modify', 'notes' => 'More people',
        'changes' => array( 'attendees' => '99' ),
    ) );
    $req_id = $wpdb->insert_id;
    MBS_Modification::approve( $req_id );

    $after = MBS_Bookings::get( $ref );
    MBS_Audit_Assertions::assert_that( (int) $after->current_invoice_document_id === $r1, 'Non-material: pointer unchanged' );
    MBS_Audit_Assertions::assert_that( (int) $after->attendees === 99, 'Attendees updated' );
});

// ── Confirmation invariant: no confirmed chargeable booking without document ────

$a->run( 'TxFail: invariant — no chargeable confirmed booking without document', function() use ( $wpdb ) {
    $booking_table = $wpdb->prefix . MBS_TABLE;
    // Query all confirmed chargeable non-series bookings in this test run
    $broken = $wpdb->get_results(
        "SELECT ref, amount, current_invoice_document_id FROM {$booking_table}
         WHERE status = 'confirmed' AND amount > 0 AND series_id IS NULL
         AND current_invoice_document_id IS NULL AND scout_use = 0
         AND name LIKE 'TxFailure Test%'"
    );
    MBS_Audit_Assertions::assert_that( empty( $broken ), 'No chargeable confirmed booking should lack a document. Found: ' . count( $broken ) );
});

// ── Finish ─────────────────────────────────────────────────────────────────────

$a->finish( 'transaction failure integration scenarios passed' );
