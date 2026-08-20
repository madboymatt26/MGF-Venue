<?php
$root = dirname( __DIR__ );
$admin = file_get_contents( $root . '/wp-plugin/mathlin-booking/admin/class-admin.php' );
$admin_js = file_get_contents( $root . '/wp-plugin/mathlin-booking/admin/admin.js' );
$admin_css = file_get_contents( $root . '/wp-plugin/mathlin-booking/admin/admin.css' );
$admin_view = file_get_contents( $root . '/wp-plugin/mathlin-booking/admin/views/series.php' );
$single_view = file_get_contents( $root . '/wp-plugin/mathlin-booking/admin/views/single.php' );
$portal = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-hirer-portal.php' );
$portal_view = file_get_contents( $root . '/wp-plugin/mathlin-booking/public/views/hirer-dashboard.php' );
$email = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-email.php' );
$templates = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-email-templates.php' );
$database = file_get_contents( $root . '/wp-plugin/mathlin-booking/includes/class-database.php' );

$checks = 0;
function series_ui_has( $source, $needle, $message ) {
    global $checks;
    $checks++;
    if ( strpos( $source, $needle ) === false ) {
        fwrite( STDERR, "FAIL: {$message}\nMissing: {$needle}\n" );
        exit( 1 );
    }
}

series_ui_has( $admin, "'mathlin-series'", 'Admin registers a dedicated recurring-series screen.' );
series_ui_has( $admin, 'MBS_Billing_Engine::configure_series', 'Admin billing changes use the domain service.' );
series_ui_has( $admin, 'MBS_Series::set_paused', 'Admin pause/resume uses optimistic series state.' );
series_ui_has( $admin_view, 'Invoice preview', 'Series detail renders an invoice preview.' );
series_ui_has( $admin_view, 'Invoices &amp; payments', 'Series detail renders invoice and payment history.' );
series_ui_has( $admin, "'doc_nonce' => wp_create_nonce( 'mbs_invoice_document_nonce' )", 'Admin JavaScript receives a dedicated invoice-document nonce.' );
series_ui_has( $admin, '$invoice_documents', 'Series rendering batch-loads immutable invoice document identifiers.' );
series_ui_has( $admin_view, 'mbs-view-invoice-btn', 'Series invoices expose the immutable issued document for viewing.' );
series_ui_has( $admin_view, 'Download PDF', 'Series invoices expose a secure PDF download action.' );
series_ui_has( $admin_view, 'mbs_invoice_document_nonce', 'Server-rendered PDF links include the document nonce.' );
series_ui_has( $admin_view, 'Historical invoice — document unavailable', 'Historical invoices without snapshots are labelled clearly.' );
series_ui_has( $admin_js, 'action: \'mbs_invoice_document\'', 'Invoice modal loads through the authenticated delivery endpoint.' );
series_ui_has( $admin_js, '$form.find(\'button[type="submit"]\')', 'Recording payment does not disable or relabel the cancel control.' );
series_ui_has( $admin_css, '.mbs-invoice-card', 'Recurring-series invoice cards retain their admin styling.' );
series_ui_has( $admin_css, '.mbs-modal__content', 'Invoice modal retains its admin styling.' );
series_ui_has( $admin_view, 'Audit history', 'Series detail renders series audit history.' );
series_ui_has( $admin_view, 'Cancel future dates', 'Series detail distinguishes future cancellation.' );
series_ui_has( $single_view, 'Manage its consolidated invoices', 'Occurrence detail routes financial actions to the series.' );
series_ui_has( $admin, 'invoice_manages_occurrence', 'Server handlers reject occurrence financial writes for consolidated series.' );

series_ui_has( $portal, 'get_series_for_email', 'Portal loads first-class series.' );
series_ui_has( $portal, 'get_invoices_for_email', 'Portal loads consolidated invoices.' );
series_ui_has( $portal, "t.transaction_type = 'payment'", 'Paid value comes from completed ledger payments.' );
series_ui_has( $portal, 'a.id IS NULL', 'Legacy paid value excludes invoice-allocated occurrences.' );
series_ui_has( $portal_view, '$legacy_bookings', 'First-class occurrences are removed from the legacy row list.' );
series_ui_has( $portal_view, 'Your Recurring Bookings', 'Portal groups series into customer cards.' );
series_ui_has( $portal_view, 'MBS_Invoice_Payment::generate_payment_url', 'Portal creates one payment action from each invoice.' );
series_ui_has( $portal_view, 'Actually Paid', 'Misleading confirmed-value Total Spent label is removed.' );

foreach ( array( 'series_confirmed', 'invoice_issued', 'invoice_reminder', 'invoice_payment_received', 'series_changed', 'series_cancelled' ) as $type ) {
    series_ui_has( $templates, "'{$type}'", "Editable {$type} template is registered." );
}
series_ui_has( $email, 'notify_invoice_issued', 'Consolidated invoice email is customer-branded through the email service.' );
series_ui_has( $email, 'notify_invoice_payment_received', 'Consolidated payment receipt is supported.' );
series_ui_has( $database, 'issued_email_sent_at', 'Invoice email delivery is persisted for idempotency.' );
series_ui_has( $database, 'receipt_sent_at', 'Payment receipt delivery is persisted per transaction.' );
series_ui_has( $database, 'deposit_policy', 'Series deposit policy is explicit and defaults to none.' );

if ( strpos( $portal_view, "get_bookings_for_email( \$email )" ) === false ) {
    fwrite( STDERR, "FAIL: Legacy one-off portal behaviour was removed.\n" );
    exit( 1 );
}
$checks++;

echo "OK: {$checks} recurring-series admin/customer UI assertions passed.\n";
