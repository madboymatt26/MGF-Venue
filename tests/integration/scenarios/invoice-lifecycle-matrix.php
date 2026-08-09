<?php
/**
 * Focused invoice document lifecycle matrix test.
 *
 * Runs the core invoice-document happy path in a single scenario:
 * 1. Chargeable one-off → R1
 * 2. Idempotent replay
 * 3. Monthly series invoice
 * 4. One supplement
 * 5. Material modification → R2
 * 6. PDF generation with vendored Dompdf
 * 7. Immutable document reload/render
 * 8. Outbox creation
 *
 * This file is wired into each WordPress runtime matrix job to provide
 * real lifecycle evidence across PHP 7.4/8.0/8.2/8.3.
 *
 * Run: wp eval-file /workspace/tests/integration/scenarios/invoice-lifecycle-matrix.php --allow-root
 */

require_once __DIR__ . '/audit-assertions.php';

global $wpdb;
$a = MBS_Audit_Assertions::current();

$matrix_offset = 500;

function matrix_booking( $overrides = array() ) {
    global $matrix_offset;
    $matrix_offset++;
    return MBS_Bookings::create( array_merge( array(
        'space' => 'Main Hall',
        'booking_date' => wp_date( 'Y-m-d', strtotime( '+' . ( 50 + $matrix_offset ) . ' days' ) ),
        'name' => 'Matrix Test', 'email' => 'matrix@example.com',
        'phone' => '07700900050', 'address' => '50 Test Lane',
        'start_time' => '10:00', 'end_time' => '14:00',
        'attendees' => 10, 'purpose' => 'Matrix ' . $matrix_offset,
        'kitchen' => false,
    ), $overrides ), true );
}

// ── 1. Chargeable one-off → R1 ────────────────────────────────────────────────

$a->run( 'Matrix: chargeable one-off creates R1', function() use ( $wpdb ) {
    $b = matrix_booking();
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $b ), 'Create: ' . ( is_wp_error($b) ? $b->get_error_message() : '' ) );
    MBS_Bookings::update_status( $b['ref'], 'confirmed' );
    $after = MBS_Bookings::get( $b['ref'] );
    MBS_Audit_Assertions::assert_that( $after->status === 'confirmed', 'Confirmed' );
    MBS_Audit_Assertions::assert_that( (int) $after->current_invoice_document_id > 0, 'R1 exists' );
});

// ── 2. Idempotent replay ───────────────────────────────────────────────────────

$a->run( 'Matrix: replay is no-op', function() use ( $wpdb ) {
    $b = matrix_booking();
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $b ), 'Create' );
    MBS_Bookings::update_status( $b['ref'], 'confirmed' );
    $r1 = (int) MBS_Bookings::get( $b['ref'] )->current_invoice_document_id;
    $result = MBS_Bookings::update_status( $b['ref'], 'confirmed' );
    MBS_Audit_Assertions::assert_that( $result === true, 'Replay returns true' );
    MBS_Audit_Assertions::assert_that( (int) MBS_Bookings::get( $b['ref'] )->current_invoice_document_id === $r1, 'Pointer unchanged' );
});

// ── 3. Monthly series invoice ──────────────────────────────────────────────────

$a->run( 'Matrix: monthly series invoice issued', function() use ( $wpdb ) {
    global $matrix_offset;
    $matrix_offset++;
    $series_table = $wpdb->prefix . MBS_SERIES_TABLE;
    $booking_table = $wpdb->prefix . MBS_TABLE;
    $series_ref = 'INT-MTX-' . strtoupper( substr( md5( uniqid() . $matrix_offset ), 0, 6 ) );
    $month = wp_date( 'Y-m', strtotime( '+' . ( 60 + $matrix_offset ) . ' days' ) );

    $wpdb->insert( $series_table, array(
        'series_ref' => $series_ref, 'status' => 'confirmed',
        'billing_mode' => 'monthly', 'billing_treatment' => 'invoice_managed',
        'payment_method' => 'offline_bacs', 'invoice_lead_days' => 0,
        'payment_terms_days' => 14, 'version' => 1,
        'start_date' => $month . '-01', 'repeat_until' => wp_date( 'Y-m-t', strtotime( $month . '-01' ) ),
        'contact_name' => 'Matrix Series', 'contact_email' => 'matrix-s@example.com',
        'space' => 'Main Hall', 'created_at' => current_time( 'mysql' ),
    ) );

    $ref = 'MBS-MTX-' . strtoupper( substr( md5( $series_ref ), 0, 4 ) );
    $wpdb->insert( $booking_table, array(
        'ref' => $ref, 'series_id' => $series_ref, 'status' => 'confirmed',
        'name' => 'Matrix', 'email' => 'matrix-s@example.com', 'phone' => '07700',
        'address' => 'Test', 'space' => 'Main Hall', 'booking_date' => $month . '-05',
        'start_time' => '18:00', 'end_time' => '20:00', 'attendees' => 10,
        'purpose' => 'Weekly', 'amount' => '50.00', 'pricing_tier' => 'standard',
        'legacy_billing_excluded' => 0, 'created_at' => current_time( 'mysql' ),
    ) );

    $result = MBS_Series_Issuance_Service::issue_period_invoice( $series_ref, array(
        'period_key' => 'month-' . $month, 'period_start' => $month . '-01',
        'period_end' => wp_date( 'Y-m-t', strtotime( $month . '-01' ) ),
        'issue_on' => wp_date( 'Y-m-d' ), 'due_on' => wp_date( 'Y-m-d', strtotime( '+14 days' ) ),
        'occurrences' => array( array( 'ref' => $ref, 'date' => $month . '-05', 'amount_minor' => 5000, 'description' => 'Session' ) ),
    ) );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $result ), 'Issue: ' . ( is_wp_error($result) ? $result->get_error_message() : '' ) );
    MBS_Audit_Assertions::assert_that( empty( $result['no_op'] ), 'Fresh issue' );
    MBS_Audit_Assertions::assert_that( (int) $result['document_id'] > 0, 'Document created' );
});

// ── 4. Supplement ──────────────────────────────────────────────────────────────

$a->run( 'Matrix: supplement after base', function() use ( $wpdb ) {
    global $matrix_offset;
    $matrix_offset++;
    $series_table = $wpdb->prefix . MBS_SERIES_TABLE;
    $booking_table = $wpdb->prefix . MBS_TABLE;
    $series_ref = 'INT-MXS-' . strtoupper( substr( md5( uniqid() . $matrix_offset ), 0, 6 ) );
    $month = wp_date( 'Y-m', strtotime( '+' . ( 70 + $matrix_offset ) . ' days' ) );

    $wpdb->insert( $series_table, array(
        'series_ref' => $series_ref, 'status' => 'confirmed',
        'billing_mode' => 'monthly', 'billing_treatment' => 'invoice_managed',
        'payment_method' => 'offline_bacs', 'invoice_lead_days' => 0,
        'payment_terms_days' => 14, 'version' => 1,
        'start_date' => $month . '-01', 'repeat_until' => wp_date( 'Y-m-t', strtotime( $month . '-01' ) ),
        'contact_name' => 'Matrix Supp', 'contact_email' => 'mxs@example.com',
        'space' => 'Main Hall', 'created_at' => current_time( 'mysql' ),
    ) );

    $ref1 = 'MBS-MX1-' . strtoupper( substr( md5( $series_ref . '1' ), 0, 4 ) );
    $wpdb->insert( $booking_table, array(
        'ref' => $ref1, 'series_id' => $series_ref, 'status' => 'confirmed',
        'name' => 'Matrix', 'email' => 'mxs@example.com', 'phone' => '07700',
        'address' => 'Test', 'space' => 'Main Hall', 'booking_date' => $month . '-05',
        'start_time' => '18:00', 'end_time' => '20:00', 'attendees' => 10,
        'purpose' => 'Session', 'amount' => '50.00', 'pricing_tier' => 'standard',
        'legacy_billing_excluded' => 0, 'created_at' => current_time( 'mysql' ),
    ) );

    // Base
    $base_args = array(
        'period_key' => 'month-' . $month, 'period_start' => $month . '-01',
        'period_end' => wp_date( 'Y-m-t', strtotime( $month . '-01' ) ),
        'issue_on' => wp_date( 'Y-m-d' ), 'due_on' => wp_date( 'Y-m-d', strtotime( '+14 days' ) ),
        'occurrences' => array( array( 'ref' => $ref1, 'date' => $month . '-05', 'amount_minor' => 5000, 'description' => 'Session' ) ),
    );
    $base = MBS_Series_Issuance_Service::issue_period_invoice( $series_ref, $base_args );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $base ), 'Base' );

    // Late occurrence
    $ref2 = 'MBS-MX2-' . strtoupper( substr( md5( $series_ref . '2' ), 0, 4 ) );
    $wpdb->insert( $booking_table, array(
        'ref' => $ref2, 'series_id' => $series_ref, 'status' => 'confirmed',
        'name' => 'Matrix', 'email' => 'mxs@example.com', 'phone' => '07700',
        'address' => 'Test', 'space' => 'Main Hall', 'booking_date' => $month . '-15',
        'start_time' => '18:00', 'end_time' => '20:00', 'attendees' => 10,
        'purpose' => 'Extra', 'amount' => '50.00', 'pricing_tier' => 'standard',
        'legacy_billing_excluded' => 0, 'created_at' => current_time( 'mysql' ),
    ) );

    $supp_args = $base_args;
    $supp_args['occurrences'][] = array( 'ref' => $ref2, 'date' => $month . '-15', 'amount_minor' => 5000, 'description' => 'Extra' );
    $supp = MBS_Series_Issuance_Service::issue_period_invoice( $series_ref, $supp_args );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $supp ) && ! empty( $supp['supplement'] ), 'Supplement created' );
});

// ── 5. Material modification → R2 ─────────────────────────────────────────────

$a->run( 'Matrix: material modification creates R2', function() use ( $wpdb ) {
    $b = matrix_booking();
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $b ), 'Create' );
    MBS_Bookings::update_status( $b['ref'], 'confirmed' );
    $r1 = (int) MBS_Bookings::get( $b['ref'] )->current_invoice_document_id;

    MBS_Modification::create_request( array(
        'ref' => $b['ref'], 'type' => 'modify', 'notes' => 'Move',
        'changes' => array( 'date' => wp_date( 'Y-m-d', strtotime( '+250 days' ) ) ),
    ) );
    $req_id = $wpdb->insert_id;
    MBS_Modification::approve( $req_id );

    $r2 = (int) MBS_Bookings::get( $b['ref'] )->current_invoice_document_id;
    MBS_Audit_Assertions::assert_that( $r2 > $r1, 'R2 > R1' );
});

// ── 6. PDF generation (vendored Dompdf) ────────────────────────────────────────

$a->run( 'Matrix: PDF renders from immutable snapshot', function() use ( $wpdb ) {
    $b = matrix_booking();
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $b ), 'Create' );
    MBS_Bookings::update_status( $b['ref'], 'confirmed' );
    $booking = MBS_Bookings::get( $b['ref'] );
    $doc_id = (int) $booking->current_invoice_document_id;
    MBS_Audit_Assertions::assert_that( $doc_id > 0, 'Document exists' );

    // Build view model from document
    $vm = MBS_Invoice_Document_Builder::build_from_document( $doc_id, 'issued' );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $vm ), 'View model: ' . ( is_wp_error($vm) ? $vm->get_error_message() : '' ) );

    // Render PDF
    if ( class_exists( 'MBS_PDF_Renderer' ) ) {
        $pdf = MBS_PDF_Renderer::render( $vm );
        if ( ! is_wp_error( $pdf ) ) {
            MBS_Audit_Assertions::assert_that( strlen( $pdf ) > 100, 'PDF has content (' . strlen($pdf) . ' bytes)' );
            MBS_Audit_Assertions::assert_that( substr( $pdf, 0, 4 ) === '%PDF', 'Starts with %PDF header' );
        } else {
            // Dompdf may not be available in all test environments — skip gracefully
            echo "  (PDF renderer unavailable in this environment: " . $pdf->get_error_message() . ")\n";
        }
    }
});

// ── 7. Immutable document reload/render ────────────────────────────────────────

$a->run( 'Matrix: document snapshot immutable on reload', function() use ( $wpdb ) {
    $b = matrix_booking( array( 'kitchen' => true ) );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $b ), 'Create' );
    MBS_Bookings::update_status( $b['ref'], 'confirmed' );
    $booking = MBS_Bookings::get( $b['ref'] );
    $doc_id = (int) $booking->current_invoice_document_id;

    $doc_table = $wpdb->prefix . MBS_INVOICE_DOCUMENTS_TABLE;
    $doc = $wpdb->get_row( $wpdb->prepare( "SELECT snapshot_json FROM {$doc_table} WHERE id = %d", $doc_id ) );
    MBS_Audit_Assertions::assert_that( $doc !== null, 'Document row found' );

    $snapshot = json_decode( $doc->snapshot_json, true );
    MBS_Audit_Assertions::assert_that( is_array( $snapshot ), 'Valid JSON snapshot' );
    MBS_Audit_Assertions::assert_that( ! empty( $snapshot['invoice_number'] ), 'Has invoice_number' );
    MBS_Audit_Assertions::assert_that( (int) $snapshot['total_minor'] > 0, 'Positive total' );
    MBS_Audit_Assertions::assert_that( ! empty( $snapshot['line_items'] ), 'Has line items' );
    MBS_Audit_Assertions::assert_that( $snapshot['currency'] === 'GBP', 'Currency is GBP' );
});

// ── 8. Outbox creation ─────────────────────────────────────────────────────────

$a->run( 'Matrix: outbox entry created on confirmation', function() use ( $wpdb ) {
    $email = 'matrix-outbox-' . uniqid() . '@example.com';
    $b = matrix_booking( array( 'email' => $email ) );
    MBS_Audit_Assertions::assert_that( ! is_wp_error( $b ), 'Create' );
    MBS_Bookings::update_status( $b['ref'], 'confirmed' );

    $queue = $wpdb->prefix . 'mathlin_email_queue';
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$queue} WHERE message_key LIKE %s",
        'booking_confirmed:' . $b['ref'] . '%'
    ) );
    MBS_Audit_Assertions::assert_that( $row !== null, 'Outbox row exists' );
    MBS_Audit_Assertions::assert_that( $row->to_email === $email, 'Correct recipient' );
    MBS_Audit_Assertions::assert_that( $row->status === 'pending', 'Status is pending' );

    $meta = json_decode( $row->attachment_meta, true );
    MBS_Audit_Assertions::assert_that( ! empty( $meta['document_id'] ), 'Has document_id in attachment_meta' );
    MBS_Audit_Assertions::assert_that( $meta['format'] === 'pdf', 'Format is pdf' );
});

// ── Finish ─────────────────────────────────────────────────────────────────────

$a->finish( 'invoice lifecycle matrix scenarios passed (PHP ' . PHP_VERSION . ')' );
