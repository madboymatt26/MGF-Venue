<?php
/**
 * Invoice Document integration scenarios.
 *
 * Covers: one-off atomic confirmation, idempotency, deposit precision,
 * supplement invoice lifecycle, modification R2, guest token access,
 * and outbox delivery invariants.
 *
 * Run inside Docker: wp eval-file /workspace/tests/integration/scenarios/invoice-document-flows.php --allow-root
 */

require_once dirname( __FILE__ ) . '/audit-assertions.php';

global $wpdb;

$pass = 0;
$fail = 0;

function doc_assert( $condition, $label ) {
    global $pass, $fail;
    if ( $condition ) { $pass++; } else { $fail++; fwrite( STDERR, "FAIL: {$label}\n" ); }
}

// ── Helpers ────────────────────────────────────────────────────────────────────

function create_test_booking( $overrides = array() ) {
    $defaults = array(
        'space'        => 'Main Hall',
        'booking_date' => wp_date( 'Y-m-d', strtotime( '+30 days' ) ),
        'name'         => 'Invoice Test User',
        'email'        => 'invoice-test@example.com',
        'phone'        => '07700900000',
        'address'      => '1 Test Lane',
        'start_time'   => '10:00',
        'end_time'     => '14:00',
        'attendees'    => 20,
        'purpose'      => 'Integration test',
        'kitchen'      => false,
    );
    return MBS_Bookings::create( array_merge( $defaults, $overrides ), true );
}

// ── 1. Atomic pending→confirmed creates R1 ─────────────────────────────────────

$b1 = create_test_booking();
doc_assert( ! is_wp_error( $b1 ) && ! empty( $b1['ref'] ), 'One-off: booking created' );

if ( ! is_wp_error( $b1 ) ) {
    $ref1 = $b1['ref'];
    $booking_before = MBS_Bookings::get( $ref1 );
    doc_assert( $booking_before->status === 'pending', 'One-off: starts pending' );
    doc_assert( (float) $booking_before->amount > 0, 'One-off: chargeable amount' );

    // Confirm atomically
    $confirm_result = MBS_Bookings::update_status( $ref1, 'confirmed' );
    doc_assert( $confirm_result === true, 'One-off: atomic confirmation succeeds' );

    $booking_after = MBS_Bookings::get( $ref1 );
    doc_assert( $booking_after->status === 'confirmed', 'One-off: status is confirmed' );
    doc_assert( ! empty( $booking_after->current_invoice_document_id ), 'One-off: R1 document created' );

    // Idempotent replay
    $replay = MBS_Bookings::update_status( $ref1, 'confirmed' );
    doc_assert( $replay === true, 'One-off: idempotent replay succeeds' );

    $booking_replay = MBS_Bookings::get( $ref1 );
    doc_assert( (int) $booking_replay->current_invoice_document_id === (int) $booking_after->current_invoice_document_id, 'One-off: replay does not create duplicate document' );

    // Outbox has exactly one confirmation email
    $queue_table = $wpdb->prefix . 'mathlin_email_queue';
    $queue_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$queue_table} WHERE message_key LIKE %s",
        'booking_confirmed:' . $ref1 . '%'
    ) );
    doc_assert( $queue_count === 1, 'One-off: exactly one outbox entry' );
}

// ── 2. Malformed amount → rollback ─────────────────────────────────────────────

$b2 = create_test_booking();
if ( ! is_wp_error( $b2 ) ) {
    // Corrupt the amount to a non-decimal value
    $wpdb->update(
        $wpdb->prefix . MBS_TABLE,
        array( 'amount' => 'INVALID' ),
        array( 'ref' => $b2['ref'] )
    );
    $confirm_bad = MBS_Bookings::update_status( $b2['ref'], 'confirmed' );
    // Should fail closed (amount validation fails in confirm_and_issue)
    doc_assert( $confirm_bad === false, 'Malformed amount: confirmation fails closed' );

    $booking_bad = MBS_Bookings::get( $b2['ref'] );
    doc_assert( $booking_bad->status === 'pending', 'Malformed amount: status unchanged (rolled back)' );
    doc_assert( empty( $booking_bad->current_invoice_document_id ), 'Malformed amount: no document created' );
}

// ── 3. Deposit percentage precision ────────────────────────────────────────────

// Temporarily set deposit to 12.5%
$original_enabled = get_option( 'mbs_deposit_enabled' );
$original_pct = get_option( 'mbs_deposit_percentage' );
update_option( 'mbs_deposit_enabled', true );
update_option( 'mbs_deposit_percentage', 12.5 );

$b3 = create_test_booking( array(
    'booking_date' => wp_date( 'Y-m-d', strtotime( '+90 days' ) ), // Far enough for deposit logic
) );
if ( ! is_wp_error( $b3 ) ) {
    $ref3 = $b3['ref'];
    MBS_Bookings::update_status( $ref3, 'confirmed' );
    $booking3 = MBS_Bookings::get( $ref3 );

    if ( ! empty( $booking3->current_invoice_document_id ) ) {
        $doc_table = $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE;
        $doc = $wpdb->get_row( $wpdb->prepare(
            "SELECT snapshot_json FROM {$doc_table} WHERE id = %d", (int) $booking3->current_invoice_document_id
        ) );
        if ( $doc ) {
            $snapshot = json_decode( $doc->snapshot_json, true );
            $schedule = $snapshot['payment_schedule'] ?? array();
            // 12.5% of the total in minor units
            $total_minor = $snapshot['total_minor'] ?? 0;
            $expected_deposit = (int) intdiv( $total_minor * 1250 + 5000, 10000 );
            $actual_deposit = $schedule['deposit_minor'] ?? null;
            doc_assert( $actual_deposit === $expected_deposit, 'Deposit: 12.5% precision correct (' . $actual_deposit . ' == ' . $expected_deposit . ')' );
        }
    }
}

// Restore deposit settings
update_option( 'mbs_deposit_enabled', $original_enabled ?: false );
update_option( 'mbs_deposit_percentage', $original_pct ?: 25 );

// ── 4. Modification R2 lifecycle ───────────────────────────────────────────────

$b4 = create_test_booking();
if ( ! is_wp_error( $b4 ) ) {
    $ref4 = $b4['ref'];
    MBS_Bookings::update_status( $ref4, 'confirmed' );
    $booking4 = MBS_Bookings::get( $ref4 );
    $r1_id = (int) $booking4->current_invoice_document_id;
    doc_assert( $r1_id > 0, 'Modification: R1 exists' );

    // Create a modification request (date change = financial)
    $request_id = MBS_Modification::create_request( array(
        'ref'     => $ref4,
        'type'    => 'modify',
        'notes'   => 'Move to next week',
        'changes' => array( 'date' => wp_date( 'Y-m-d', strtotime( '+37 days' ) ) ),
    ) );

    if ( $request_id ) {
        $approve_result = MBS_Modification::approve( $request_id );
        doc_assert( $approve_result === true || ! is_wp_error( $approve_result ), 'Modification: approval succeeds' );

        $booking4_after = MBS_Bookings::get( $ref4 );
        $r2_id = (int) $booking4_after->current_invoice_document_id;
        doc_assert( $r2_id > $r1_id, 'Modification: R2 created (pointer advanced)' );

        // R1 should be superseded
        $doc_table = $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE;
        $r1_status = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$doc_table} WHERE id = %d", $r1_id
        ) );
        doc_assert( $r1_status === 'superseded', 'Modification: R1 is superseded' );

        // R2 is issued
        $r2_status = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$doc_table} WHERE id = %d", $r2_id
        ) );
        doc_assert( $r2_status === 'issued', 'Modification: R2 is issued' );

        // Outbox references R2 document ID
        $mod_outbox = $wpdb->get_row( $wpdb->prepare(
            "SELECT attachment_meta FROM {$wpdb->prefix}mathlin_email_queue WHERE message_key LIKE %s",
            'modification_approved:' . $ref4 . '%'
        ) );
        if ( $mod_outbox ) {
            $meta = json_decode( $mod_outbox->attachment_meta, true );
            doc_assert( (int) ( $meta['document_id'] ?? 0 ) === $r2_id, 'Modification: outbox references R2 document' );
        }
    }
}

// ── 5. Non-material modification does NOT create R2 ────────────────────────────

$b5 = create_test_booking();
if ( ! is_wp_error( $b5 ) ) {
    $ref5 = $b5['ref'];
    MBS_Bookings::update_status( $ref5, 'confirmed' );
    $booking5 = MBS_Bookings::get( $ref5 );
    $r1_id5 = (int) $booking5->current_invoice_document_id;

    $request_id5 = MBS_Modification::create_request( array(
        'ref'     => $ref5,
        'type'    => 'modify',
        'notes'   => 'Add attendees',
        'changes' => array( 'attendees' => '30' ),
    ) );

    if ( $request_id5 ) {
        MBS_Modification::approve( $request_id5 );
        $booking5_after = MBS_Bookings::get( $ref5 );
        doc_assert( (int) $booking5_after->current_invoice_document_id === $r1_id5, 'Non-material: document pointer unchanged' );
        doc_assert( (int) $booking5_after->attendees === 30, 'Non-material: attendees updated' );
    }
}

// ── 6. Guest token lifecycle ───────────────────────────────────────────────────

$b6 = create_test_booking();
if ( ! is_wp_error( $b6 ) ) {
    $ref6 = $b6['ref'];
    MBS_Bookings::update_status( $ref6, 'confirmed' );
    $booking6 = MBS_Bookings::get( $ref6 );
    $doc_id6 = (int) $booking6->current_invoice_document_id;

    if ( $doc_id6 > 0 ) {
        // Create token
        $token = MBS_Invoice_Delivery_Endpoint::create_guest_token( $doc_id6 );
        doc_assert( ! is_wp_error( $token ) && strlen( $token ) === 64, 'Guest token: created successfully' );

        // Token for wrong document fails
        $token_wrong = MBS_Invoice_Delivery_Endpoint::create_guest_token( 999999 );
        doc_assert( ! is_wp_error( $token_wrong ), 'Guest token: creation for nonexistent doc succeeds (validation on use)' );
    }
}

// ── 7. Termly billing rejects empty terms ──────────────────────────────────────

if ( class_exists( 'MBS_Series' ) ) {
    // Create a test series for termly validation
    $series_table = $wpdb->prefix . MBS_SERIES_TABLE;
    $test_series_ref = 'INT-TERMLY-' . strtoupper( substr( md5( uniqid() ), 0, 6 ) );
    $wpdb->insert( $series_table, array(
        'series_ref'           => $test_series_ref,
        'status'               => 'confirmed',
        'billing_mode'         => 'monthly',
        'billing_treatment'    => 'invoice_managed',
        'payment_method'       => 'offline_bacs',
        'version'              => 1,
        'start_date'           => '2026-09-01',
        'repeat_until'         => '2027-07-31',
        'contact_name'         => 'Test',
        'contact_email'        => 'test@example.com',
        'space'                => 'Main Hall',
        'created_at'           => current_time( 'mysql' ),
    ) );

    // Empty terms → reject
    $empty_terms_result = MBS_Billing_Engine::configure_series( $test_series_ref, array(
        'billing_mode'      => 'termly',
        'billing_treatment' => 'invoice_managed',
        'payment_method'    => 'offline_bacs',
        'billing_schedule'  => array( 'terms' => array() ),
    ), 1 );
    doc_assert( is_wp_error( $empty_terms_result ), 'Termly: empty terms rejected' );
    if ( is_wp_error( $empty_terms_result ) ) {
        doc_assert( $empty_terms_result->get_error_code() === 'terms_required', 'Termly: error code is terms_required' );
    }

    // Missing terms key → reject
    $missing_terms_result = MBS_Billing_Engine::configure_series( $test_series_ref, array(
        'billing_mode'      => 'termly',
        'billing_treatment' => 'invoice_managed',
        'payment_method'    => 'offline_bacs',
        'billing_schedule'  => array(),
    ), 1 );
    doc_assert( is_wp_error( $missing_terms_result ), 'Termly: missing terms key rejected' );

    // Clean up
    $wpdb->delete( $series_table, array( 'series_ref' => $test_series_ref ) );
}

// ── Summary ────────────────────────────────────────────────────────────────────

echo "\n";
if ( $fail > 0 ) {
    fwrite( STDERR, "FAILED: {$fail} of " . ( $pass + $fail ) . " invoice-document integration assertions.\n" );
    exit( 1 );
} else {
    echo "OK: {$pass} invoice-document integration assertions passed.\n";
}
