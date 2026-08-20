<?php
$root = dirname( __DIR__ );
$endpoint = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/invoice/class-delivery-endpoint.php' );
$documents = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/invoice/class-document-service.php' );
$public = file_get_contents( $root . '/wp-plugin/mathlin-booking/public/class-public.php' );
$dashboard = file_get_contents( $root . '/wp-plugin/mathlin-booking/public/views/hirer-dashboard.php' );
$manage = file_get_contents( $root . '/wp-plugin/mathlin-booking/public/views/manage.php' );

$checks = 0;
function hirer_pdf_has( $source, $needle, $message ) {
    global $checks;
    $checks++;
    if ( strpos( $source, $needle ) === false ) {
        fwrite( STDERR, "FAIL: {$message}\nMissing: {$needle}\n" );
        exit( 1 );
    }
}

hirer_pdf_has( $endpoint, 'authenticated_pdf_url', 'Signed-in hirers use the authenticated document endpoint.' );
hirer_pdf_has( $endpoint, "wp_create_nonce( 'mbs_invoice_document_nonce' )", 'Signed-in PDF links carry the endpoint nonce.' );
hirer_pdf_has( $endpoint, 'guest_pdf_url', 'Quick Lookup can issue an opaque guest PDF link.' );
hirer_pdf_has( $endpoint, '$max_uses = max( 1, min( 20', 'Guest credentials have a bounded use count.' );
hirer_pdf_has( $endpoint, 'DELETE FROM {$table} WHERE expires_at', 'Expired/exhausted download credentials are cleaned up.' );
hirer_pdf_has( $documents, 'get_current_ledger_document_id', 'Ledger invoices resolve their latest issued immutable document.' );
hirer_pdf_has( $documents, "document_type = 'invoice' AND status = 'issued'", 'Customer invoice links cannot select drafts, credit notes or superseded documents.' );

hirer_pdf_has( $dashboard, 'Download PDF', 'Recurring invoice cards expose a PDF download.' );
hirer_pdf_has( $dashboard, 'Invoice PDF', 'One-off booking actions expose a PDF download.' );
hirer_pdf_has( $dashboard, '! empty( $b->current_invoice_document_id )', 'One-off PDF controls only render when an immutable document exists.' );

hirer_pdf_has( $public, "'mbs_lookup_' . md5", 'Quick Lookup is rate-limited before minting guest credentials.' );
hirer_pdf_has( $public, 'get_active_booking_allocation', 'Recurring Quick Lookup resolves the consolidated allocation.' );
hirer_pdf_has( $public, 'MBS_Invoice_Payment::generate_payment_url( $ledger_invoice )', 'Recurring Quick Lookup pays the consolidated invoice.' );
hirer_pdf_has( $public, 'empty( $booking->series_id ) && $booking->status', 'Recurring occurrences cannot fall through to per-booking checkout.' );
hirer_pdf_has( $public, 'guest_pdf_url( $document_id, 15 * MINUTE_IN_SECONDS, 3 )', 'Quick Lookup credentials expire after 15 minutes and allow only three downloads.' );

$email_check = strpos( $public, 'strtolower( $booking->email ) !== strtolower( $email )' );
$token_creation = strpos( $public, 'MBS_Invoice_Delivery_Endpoint::guest_pdf_url' );
$checks++;
if ( $email_check === false || $token_creation === false || $email_check > $token_creation ) {
    fwrite( STDERR, "FAIL: A guest PDF credential can be minted before booking-reference and email verification.\n" );
    exit( 1 );
}

hirer_pdf_has( $manage, 'Download Invoice PDF', 'Quick Lookup displays the protected PDF action.' );
hirer_pdf_has( $manage, 'escAttr(b.invoice_pdf_url)', 'Quick Lookup escapes the generated URL in attribute context.' );
hirer_pdf_has( $manage, 'escAttr(b.payment_url)', 'Existing dynamic action URLs are also escaped.' );

echo "OK: {$checks} hirer invoice PDF assertions passed.\n";
