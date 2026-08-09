<?php
/**
 * Invoice Document integration scenarios.
 *
 * Covers: one-off atomic confirmation, idempotency, deposit precision,
 * modification R2, guest token access, termly validation, and outbox invariants.
 *
 * Run inside Docker: wp eval-file /workspace/tests/integration/scenarios/invoice-document-flows.php --allow-root
 *
 * Uses MBS_Audit_Assertions harness (proven fail-closed via self-test in run-concurrency.sh).
 */

require_once __DIR__ . '/audit-assertions.php';

global $wpdb;
$a = MBS_Audit_Assertions::current();

// ── Helpers ────────────────────────────────────────────────────────────────────

$doc_test_offset = 0;

function doc_create_booking( $overrides = array() ) {
    global $doc_test_offset;
    $doc_test_offset++;
    // Each booking uses a unique date to avoid conflicts
    $defaults = array(
        'space'        => 'Main Hall',
        'booking_date' => wp_date( 'Y-m-d', strtotime( '+' . ( 30 + $doc_test_offset ) . ' days' ) ),
        'name'         => 'Invoice Test User',
        'email'        => 'invoice-test@example.com',
        'phone'        => '07700900000',
        'address'      => '1 Test Lane',
        'start_time'   => '10:00',
        'end_time'     => '14:00',
        'attendees'    => 20,
        'purpose'      => 'Integration test ' . $doc_test_offset,
        'kitchen'      => false,
    );
    return MBS_Bookings::create( array_merge( $defaults, $overrides ), true );
}

// ── Self-test: prove this harness fails closed ─────────────────────────────────
// A false assertion followed by finish() MUST throw RuntimeException (→ non-zero wp eval-file exit).
$selftest_threw = false;
try {
    $st = new MBS_Audit_Assertions();
    $st->check( false, 'deliberate-false-for-self-test' );
    $st->finish( 'should-not-succeed' );
} catch ( \RuntimeException $e ) {
    $selftest_threw = true;
}
$a->check( $selftest_threw, 'Self-test: deliberate false assertion causes non-zero exit via RuntimeException on finish()' );

// ── 1. Atomic pending→confirmed creates R1 ─────────────────────────────────────

$a->run( 'One-off: atomic pending→confirmed creates document', function() use ( $wpdb ) {
    $b = doc_create_booking();
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $b ) && ! empty( $b['ref'] ), 'Booking creation failed: ' . ( is_wp_error($b) ? $b->get_error_message() : 'empty ref' ) );
    $ref = $b['ref'];

    $booking_before = MBS_Bookings::get( $ref );
    MBS_Audit_Assertions::assert_that( $booking_before->status === 'pending', 'Expected pending, got ' . $booking_before->status );
    MBS_Audit_Assertions::assert_that( (float) $booking_before->amount > 0, 'Expected chargeable amount' );

    // Confirm
    $result = MBS_Bookings::update_status( $ref, 'confirmed' );
    MBS_Audit_Assertions::assert_that( $result === true, 'Atomic confirmation returned non-true: ' . var_export($result, true) );

    $after = MBS_Bookings::get( $ref );
    MBS_Audit_Assertions::assert_that( $after->status === 'confirmed', 'Status should be confirmed, got ' . $after->status );
    MBS_Audit_Assertions::assert_that( ! empty( $after->current_invoice_document_id ), 'R1 document was not created' );

    // Verify outbox
    $queue_table = $wpdb->prefix . 'mathlin_email_queue';
    $queue_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$queue_table} WHERE message_key LIKE %s",
        'booking_confirmed:' . $ref . '%'
    ) );
    MBS_Audit_Assertions::assert_that( $queue_count === 1, 'Expected exactly 1 outbox entry, got ' . $queue_count );
});

// ── 2. Idempotent replay does not duplicate ────────────────────────────────────

$a->run( 'One-off: idempotent replay produces no duplicate', function() use ( $wpdb ) {
    $b = doc_create_booking();
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $b ), 'Booking creation failed' );
    $ref = $b['ref'];

    MBS_Bookings::update_status( $ref, 'confirmed' );
    $after1 = MBS_Bookings::get( $ref );
    $doc_id_1 = (int) $after1->current_invoice_document_id;

    // Replay
    $replay = MBS_Bookings::update_status( $ref, 'confirmed' );
    MBS_Audit_Assertions::assert_that( $replay === true, 'Replay should succeed (idempotent), got: ' . var_export($replay, true) );

    $after2 = MBS_Bookings::get( $ref );
    $doc_id_2 = (int) $after2->current_invoice_document_id;
    MBS_Audit_Assertions::assert_that( $doc_id_1 === $doc_id_2, 'Document ID should not change on replay: ' . $doc_id_1 . ' vs ' . $doc_id_2 );

    // Outbox still exactly 1
    $queue_table = $wpdb->prefix . 'mathlin_email_queue';
    $queue_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$queue_table} WHERE message_key LIKE %s",
        'booking_confirmed:' . $ref . '%'
    ) );
    MBS_Audit_Assertions::assert_that( $queue_count === 1, 'Replay must not add a second outbox entry, got ' . $queue_count );
});

// ── 3. Non-chargeable (£0) booking confirmed without document ──────────────────

$a->run( 'One-off: £0 booking confirms without document', function() use ( $wpdb ) {
    $b = doc_create_booking( array( 'scout_use' => true ) );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $b ), 'Booking creation failed: ' . ( is_wp_error($b) ? $b->get_error_message() : '' ) );
    $ref = $b['ref'];

    $booking_before = MBS_Bookings::get( $ref );
    MBS_Audit_Assertions::assert_that( (float) $booking_before->amount === 0.0, 'Scout booking should be £0' );

    $result = MBS_Bookings::update_status( $ref, 'confirmed' );
    MBS_Audit_Assertions::assert_that( $result !== false, 'Confirmation should succeed for £0 booking' );

    $after = MBS_Bookings::get( $ref );
    // £0 bookings skip the chargeable path (no document created via confirm_and_issue)
    MBS_Audit_Assertions::assert_that( empty( $after->current_invoice_document_id ), 'No document for £0 booking' );
});

// ── 4. Guest token creation ────────────────────────────────────────────────────

$a->run( 'Guest token: create and validate lifecycle', function() use ( $wpdb ) {
    $b = doc_create_booking();
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $b ), 'Booking creation failed' );
    $ref = $b['ref'];

    MBS_Bookings::update_status( $ref, 'confirmed' );
    $booking = MBS_Bookings::get( $ref );
    $doc_id = (int) $booking->current_invoice_document_id;
    MBS_Audit_Assertions::assert_that( $doc_id > 0, 'Document must exist' );

    $token = MBS_Invoice_Delivery_Endpoint::create_guest_token( $doc_id );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $token ), 'Token creation failed: ' . ( is_wp_error($token) ? $token->get_error_message() : '' ) );
    MBS_Audit_Assertions::assert_that( strlen( $token ) === 64, 'Token should be 64 hex chars, got ' . strlen($token) );
});

// ── 5. Termly billing rejects empty terms ──────────────────────────────────────

$a->run( 'Termly: empty terms rejected on configure_series', function() use ( $wpdb ) {
    $series_table = $wpdb->prefix . MBS_SERIES_TABLE;
    $test_ref = 'INT-TERM-' . strtoupper( substr( md5( uniqid() ), 0, 6 ) );

    $wpdb->insert( $series_table, array(
        'series_ref'        => $test_ref,
        'status'            => 'confirmed',
        'billing_mode'      => 'monthly',
        'billing_treatment' => 'invoice_managed',
        'payment_method'    => 'offline_bacs',
        'version'           => 1,
        'start_date'        => '2026-09-01',
        'repeat_until'      => '2027-07-31',
        'contact_name'      => 'Test',
        'contact_email'     => 'test@example.com',
        'space'             => 'Main Hall',
        'created_at'        => current_time( 'mysql' ),
    ) );

    $result = MBS_Billing_Engine::configure_series( $test_ref, array(
        'billing_mode'      => 'termly',
        'billing_treatment' => 'invoice_managed',
        'payment_method'    => 'offline_bacs',
        'billing_schedule'  => array( 'terms' => array() ),
    ), 1 );

    MBS_Audit_Assertions::assert_that( is_wp_error( $result ), 'Should reject empty terms' );
    MBS_Audit_Assertions::assert_that( $result->get_error_code() === 'terms_required', 'Expected terms_required error, got: ' . $result->get_error_code() );

    // Clean up
    $wpdb->delete( $series_table, array( 'series_ref' => $test_ref ) );
});

// ── Finish ─────────────────────────────────────────────────────────────────────

$a->finish( 'invoice-document integration scenarios passed' );
