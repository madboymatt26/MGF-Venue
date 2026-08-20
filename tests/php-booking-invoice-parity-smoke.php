<?php
$root = dirname( __DIR__ );
$admin = file_get_contents( $root . '/wp-plugin/mathlin-booking/admin/class-admin.php' );
$single = file_get_contents( $root . '/wp-plugin/mathlin-booking/admin/views/single.php' );
$invoice = file_get_contents( $root . '/wp-plugin/mathlin-booking/admin/views/invoice.php' );
$archived = file_get_contents( $root . '/wp-plugin/mathlin-booking/admin/views/archived.php' );
$list = file_get_contents( $root . '/wp-plugin/mathlin-booking/admin/views/list.php' );
$bookings = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-bookings.php' );
$email = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-email.php' );
$email_queue = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-email-queue.php' );
$documents = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/invoice/class-document-service.php' );
$rest = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-rest-api.php' );

$checks = 0;
function booking_invoice_has( $source, $needle, $message ) {
    global $checks;
    $checks++;
    if ( strpos( $source, $needle ) === false ) {
        fwrite( STDERR, "FAIL: {$message}\nMissing: {$needle}\n" );
        exit( 1 );
    }
}

booking_invoice_has( $single, 'View issued invoice', 'One-off booking details expose the immutable issued invoice.' );
booking_invoice_has( $single, 'Download PDF', 'One-off booking details expose a PDF download.' );
booking_invoice_has( $single, 'Consolidated at series level', 'Occurrence details replace the individual invoice number with series billing.' );
booking_invoice_has( $single, 'Manage consolidated invoices', 'Occurrence details link to the consolidated series invoices.' );
booking_invoice_has( $single, 'This historical booking has no immutable PDF snapshot.', 'Historical one-offs are labelled instead of being presented as issued PDFs.' );

booking_invoice_has( $invoice, 'This occurrence does not have an individual invoice.', 'Direct occurrence invoice pages refuse to render a per-occurrence invoice.' );
booking_invoice_has( $invoice, 'Immutable issued invoice document.', 'One-off invoice pages identify immutable issued documents.' );
booking_invoice_has( $invoice, 'mbs_invoice_document', 'One-off invoice pages use the authenticated document endpoint.' );
booking_invoice_has( $invoice, 'Historical legacy preview.', 'Legacy reconstructions are explicitly labelled.' );
booking_invoice_has( $archived, 'Series invoices', 'Archived occurrences route invoice access to the series.' );
booking_invoice_has( $list, '>View series</a>', 'The grouped series action opens the dedicated series screen.' );
booking_invoice_has( $list, '>View occurrence</a>', 'Expanded dates retain an explicitly operational occurrence link.' );

booking_invoice_has( $admin, "'managed_by_series' => true", 'Admin invoice resource reports series-managed occurrences.' );
booking_invoice_has( $admin, 'MBS_Invoice_Document_Builder::build_from_document', 'Admin invoice resource renders immutable documents.' );
booking_invoice_has( $admin, "'pdf_available'     => true", 'Admin invoice resource advertises PDF availability.' );
booking_invoice_has( $admin, 'empty( $booking->current_invoice_document_id )', 'Admin confirmation avoids a second legacy invoice email.' );

booking_invoice_has( $bookings, '$notify_hirer = true', 'Status transitions retain an explicit notification policy.' );
booking_invoice_has( $bookings, 'confirm_and_issue( $ref, null, (bool) $notify_hirer )', 'Atomic one-off confirmation honours the notification policy.' );
booking_invoice_has( $email, 'enqueue_confirmed_document', 'Confirmation emails support immutable document attachments.' );
booking_invoice_has( $email, "'format' => 'pdf'", 'Confirmation document attachments request PDF rendering.' );
booking_invoice_has( $email, 'queue worker hydrates', 'Transactional confirmation enqueueing defers rendering side effects.' );
booking_invoice_has( $email_queue, "\$email->message_type === 'booking_confirmation'", 'The queue worker recognises booking-confirmation outbox messages.' );
booking_invoice_has( $email_queue, 'MBS_Email::build_confirmation_message', 'The queue worker hydrates the complete branded email after commit.' );
booking_invoice_has( $documents, 'MBS_Email::enqueue_confirmed_document', 'The atomic document service uses the central idempotent notifier.' );
booking_invoice_has( $rest, "'confirmed', (bool) \$notify", 'MCP/API creation honours notify_hirer when issuing a PDF.' );
booking_invoice_has( $rest, "\$status === 'confirmed' && \$notify_hirer", 'MCP/API status changes honour notify_hirer.' );
booking_invoice_has( $rest, "'invoice_scope'", 'Admin booking responses identify booking-level versus series-level invoices.' );
booking_invoice_has( $rest, "'pdf_invoice_available'", 'Admin booking responses expose one-off PDF availability.' );

echo "OK: {$checks} one-off/series invoice parity assertions passed.\n";
