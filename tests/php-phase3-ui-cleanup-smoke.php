<?php
/**
 * Phase 3 regression & smoke tests:
 *  - Single Booking layout overhaul & browser-test guardrails
 *  - Cross-admin CSS, mobile tables, WCAG AA contrast & modal accessibility
 *  - Dead code & orphaned asset removal
 */

$root   = dirname( __DIR__ );
$plugin = $root . '/wp-plugin/mathlin-booking';
$checks = 0;

function p3_assert( $cond, $msg ) {
    global $checks;
    $checks++;
    if ( ! $cond ) {
        fwrite( STDERR, "FAIL: {$msg}\n" );
        exit( 1 );
    }
}

$single = file_get_contents( $plugin . '/admin/views/single.php' );
p3_assert( $single !== false, 'single.php readable' );

// 1. Single Booking layout hierarchy & guardrails
p3_assert( strpos( $single, 'class="mbs-booking-hero"' ) !== false, 'Hero header bar present' );
p3_assert( strpos( $single, 'class="mbs-mobile-jump-bar"' ) !== false, 'Sticky mobile jump bar present' );
p3_assert( strpos( $single, 'class="nms-single-layout mbs-single-grid"' ) !== false, 'Two-column responsive grid present' );
p3_assert( strpos( $single, '<dl class="mbs-dl-grid">' ) !== false, 'Booking Details uses semantic <dl class="mbs-dl-grid">' );

$pos_details = strpos( $single, 'id="mbs-section-details"' );
$pos_expiry  = strpos( $single, 'MBS_Payment_Expiry::render_booking' );
$pos_charges = strpos( $single, 'MBS_Additional_Charges::render_related' );
$pos_heating = strpos( $single, 'id="mbs-heating-heading"' );
$pos_access  = strpos( $single, 'id="mbs-access-readiness-heading"' );
p3_assert( $pos_details !== false && $pos_expiry !== false && $pos_details < $pos_expiry, 'Booking Details renders before Payment Deadlines' );
p3_assert( $pos_charges !== false && $pos_details < $pos_charges, 'Booking Details renders before Additional Charges' );
p3_assert( $pos_heating !== false && $pos_details < $pos_heating, 'Booking Details renders before Home Assistant Heating' );
p3_assert( $pos_access !== false && $pos_details < $pos_access, 'Booking Details renders before Access Email Readiness' );

// Exactly one .nms-btn-confirm button in single.php (payment-expiry-browser.cjs contract)
p3_assert( substr_count( $single, 'nms-btn-confirm' ) === 1, 'Exactly one .nms-btn-confirm button in single.php' );

// Preserved Phase 2 email diagnostics triggers & Scout heating controls
p3_assert( strpos( $single, 'mbs-open-email-diagnostics' ) !== false, 'Phase 2 email diagnostics triggers preserved in single.php' );
p3_assert( strpos( $single, 'id="mbs-booking-heating"' ) !== false, 'Heating form ID preserved' );
p3_assert( strpos( $single, 'id="mbs-heating-choice"' ) !== false, 'Heating checkbox ID preserved' );
p3_assert( strpos( $single, 'Save heating preference' ) !== false, 'Heating submit button text preserved' );

// Zero inline <style> or <script> blocks in single.php
p3_assert( strpos( $single, '<style' ) === false, 'No inline <style> block in single.php' );
p3_assert( strpos( $single, '<script' ) === false, 'No inline <script> block in single.php' );

// Duplicate queries & duplicate restore branch removed
p3_assert( strpos( $single, 'MBS_Bookings::get_series(' ) === false, 'No SELECT * get_series() calls in single.php' );
p3_assert( strpos( $single, 'MBS_Bookings::count_series(' ) !== false, 'Uses lightweight count_series() in single.php' );
p3_assert( substr_count( $single, 'nms-btn-reopen' ) === 1, 'Duplicate cancelled/archived restore branch consolidated' );

// 2. payment-features.js selectors updated
$pay_js = file_get_contents( $plugin . '/admin/payment-features.js' );
p3_assert( strpos( $pay_js, '[data-payment-field="1"]' ) !== false, 'payment-features.js hides [data-payment-field="1"]' );
p3_assert( strpos( $pay_js, '#nms-edit-custom-price' ) !== false, 'payment-features.js targets #nms-edit-custom-price' );
p3_assert( strpos( $pay_js, '#nms-edit-use-custom' ) === false, 'Dead #nms-edit-use-custom selector removed' );

// 3. approval-modal.php & admin.js accessibility
$modal = file_get_contents( $plugin . '/admin/views/partials/approval-modal.php' );
p3_assert( strpos( $modal, 'role="dialog"' ) !== false, 'approval-modal has role="dialog"' );
p3_assert( strpos( $modal, 'aria-modal="true"' ) !== false, 'approval-modal has aria-modal="true"' );
p3_assert( strpos( $modal, 'aria-labelledby="mbs-approval-modal-title"' ) !== false, 'approval-modal has aria-labelledby' );
p3_assert( strpos( $modal, 'role="status" aria-live="polite"' ) !== false, 'approval-modal has aria-live feedback' );

$admin_js = file_get_contents( $plugin . '/admin/admin.js' );
p3_assert( strpos( $admin_js, 'openApprovalModal' ) !== false && strpos( $admin_js, 'closeApprovalModal' ) !== false, 'admin.js manages approval modal open/close & focus' );
p3_assert( strpos( $admin_js, 'data-single-edit-config' ) !== false, 'Single booking edit controller moved into admin.js' );
p3_assert( strpos( $admin_js, '#mbs-booking-heating' ) !== false, 'Home Assistant heating handler moved into admin.js' );

// 4. admin.css fixes & contrast
$css = file_get_contents( $plugin . '/admin/admin.css' );
p3_assert( strpos( $css, '.nms-admin' ) === false, 'Dead .nms-admin selector removed from admin.css' );
p3_assert( strpos( $css, '.mbs-admin {' ) !== false, '.mbs-admin wrapper selector present' );
p3_assert( strpos( $css, '#2ecc71' ) === false, 'Low-contrast #2ecc71 removed from admin.css' );
p3_assert( strpos( $css, 'color: #999' ) === false, 'Low-contrast #999 removed from admin.css' );
p3_assert( strpos( $css, '.nms-bulk-bar' ) !== false, 'Mobile CSS hides .nms-bulk-bar' );
p3_assert( strpos( $css, 'td.nms-bulk-cell' ) !== false, 'Mobile CSS hides checkbox column td.nms-bulk-cell' );
p3_assert( strpos( $css, '.mbs-booking-hero' ) !== false && strpos( $css, '.mbs-single-grid' ) !== false, 'Single booking redesign CSS present' );

// 5. Mobile table scroll wrappers & labels
foreach ( array( 'requests.php', 'blocked.php', 'series.php', 'analytics.php', 'custom-fields.php', 'osm-settings-v2.php' ) as $view ) {
    $html = file_get_contents( $plugin . '/admin/views/' . $view );
    p3_assert( strpos( $html, 'mbs-table-scroll' ) !== false, "{$view} wraps wide tables in .mbs-table-scroll" );
}
$list = file_get_contents( $plugin . '/admin/views/list.php' );
p3_assert( strpos( $list, 'class="nms-bulk-cell"' ) !== false, 'list.php marks checkbox cells with .nms-bulk-cell' );
p3_assert( strpos( $list, 'for="nms-booking-search"' ) !== false, 'list.php adds screen-reader label for search' );
$archived = file_get_contents( $plugin . '/admin/views/archived.php' );
p3_assert( strpos( $archived, 'data-label="Ref"' ) !== false, 'archived.php adds mobile data-label attributes' );
p3_assert( strpos( $archived, "['unit'] === 'day'" ) === false, 'Legacy unit===day check removed from archived.php' );

// 6. Dead methods & orphaned logo binaries removed
$dead_methods = array(
    'includes/class-database.php'                     => 'verify_schema_legacy',
    'includes/invoice/class-delivery-endpoint.php'    => 'refund_token_use',
    'includes/class-series-approval-service.php'      => 'build_invoice_email_body',
    'includes/invoice/class-document-service.php'     => 'issue_booking_document',
    'includes/invoice/class-document-security.php'    => 'validate_document_request',
    'includes/class-bookings.php'                     => 'tier_bypasses_access_gate',
    'includes/class-access-details.php'               => 'send_to_booker',
    'includes/class-email-templates.php'              => 'reset_template',
    'includes/class-email.php'                        => 'generate_invoice_attachment_for',
    'includes/class-ical.php'                         => 'get_feed_url',
);
foreach ( $dead_methods as $rel => $fn ) {
    $src = file_get_contents( $plugin . '/' . $rel );
    p3_assert( strpos( $src, 'function ' . $fn ) === false, "Dead method {$fn}() removed from {$rel}" );
}
p3_assert( ! file_exists( $plugin . '/assets/mgf-venue-logo.png' ), 'Orphaned mgf-venue-logo.png removed' );
p3_assert( ! file_exists( $plugin . '/assets/mgf-venue-logo.webp' ), 'Orphaned mgf-venue-logo.webp removed' );

echo "PHASE3_UI_CLEANUP_OK: {$checks} checks passed\n";
