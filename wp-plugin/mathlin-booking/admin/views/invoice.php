<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$billing_treatment = MBS_Series::billing_treatment_for_booking( $booking );
$uses_consolidated_billing = ! empty( $booking->series_id )
    && MBS_Series::get( $booking->series_id )
    && in_array( $billing_treatment, array( 'manual_consolidated', 'invoice_managed', 'none' ), true );
$series_admin_url = ! empty( $booking->series_id )
    ? admin_url( 'admin.php?page=mathlin-series&ref=' . rawurlencode( $booking->series_id ) )
    : '';
$document_id = ! $uses_consolidated_billing && ! empty( $booking->current_invoice_document_id )
    ? (int) $booking->current_invoice_document_id
    : 0;
$document_nonce = $document_id ? wp_create_nonce( 'mbs_invoice_document_nonce' ) : '';
$document_view_url = $document_id ? add_query_arg( array(
    'action' => 'mbs_invoice_document', 'document_id' => $document_id,
    'format' => 'html', 'mode' => 'issued', 'nonce' => $document_nonce,
), admin_url( 'admin-ajax.php' ) ) : '';
$document_pdf_url = $document_id ? add_query_arg( array(
    'action' => 'mbs_invoice_document', 'document_id' => $document_id,
    'format' => 'pdf', 'mode' => 'issued', 'nonce' => $document_nonce,
), admin_url( 'admin-ajax.php' ) ) : '';
?>
<div class="wrap mbs-admin">
    <h1>
        <a href="?page=mathlin-booking&action=view&ref=<?php echo esc_attr( $booking->ref ); ?>" class="nms-back-link">&#8592; Back to Booking</a>
        &nbsp; <?php echo $uses_consolidated_billing ? 'Series Billing' : ( 'Invoice ' . esc_html( $booking->invoice_number ) ); ?>
    </h1>

    <div class="nms-invoice-actions no-print">
        <?php if ( $document_id ) : ?>
            <a href="<?php echo esc_url( $document_view_url ); ?>" class="button button-primary" target="_blank" rel="noopener">🧾 Open issued invoice</a>
            <a href="<?php echo esc_url( $document_pdf_url ); ?>" class="button" target="_blank" rel="noopener">📄 Download PDF</a>
        <?php elseif ( ! $uses_consolidated_billing && (float) $booking->amount > 0 ) : ?>
            <button onclick="window.print()" class="button">🖨️ Print legacy preview</button>
        <?php endif; ?>
        <a href="?page=mathlin-booking" class="button">Back to All Bookings</a>
    </div>

    <?php if ( $uses_consolidated_billing ) : ?>
        <div class="notice notice-warning inline" style="margin:20px 0;padding:12px 16px;">
            <p><strong>This occurrence does not have an individual invoice.</strong></p>
            <p>Charges and payments are managed by the recurring series, so showing <?php echo esc_html( $booking->invoice_number ); ?> as an invoice would be misleading.</p>
            <?php if ( $series_admin_url ) : ?><p><a href="<?php echo esc_url( $series_admin_url ); ?>" class="button button-primary">Manage consolidated invoices</a></p><?php endif; ?>
        </div>
    <?php elseif ( $document_id ) : ?>
        <div class="notice notice-success inline" style="margin:20px 0;padding:12px 16px;">
            <p><strong>Immutable issued invoice document.</strong> This is the same document used to generate the PDF sent to the hirer.</p>
        </div>
        <iframe src="<?php echo esc_url( $document_view_url ); ?>" title="Issued invoice <?php echo esc_attr( $booking->invoice_number ); ?>" style="width:100%;min-height:1050px;border:1px solid #dcdcde;background:#fff;border-radius:6px;"></iframe>
    <?php elseif ( (float) $booking->amount <= 0 ) : ?>
        <div class="notice notice-info inline" style="margin:20px 0;padding:12px 16px;"><p>No invoice is required for this free booking.</p></div>
    <?php elseif ( $booking->status === 'pending' ) : ?>
        <div class="notice notice-info inline" style="margin:20px 0;padding:12px 16px;"><p>The immutable PDF invoice will be created when this booking is confirmed.</p></div>
    <?php else : ?>
        <div class="notice notice-warning inline" style="margin:20px 0;padding:12px 16px;">
            <p><strong>Historical legacy preview.</strong> This booking predates immutable PDF documents, so the original issued PDF is unavailable. The preview below is reconstructed from the booking's current data.</p>
        </div>
        <?php echo MBS_Invoice::generate_html( $booking ); ?>
    <?php endif; ?>
</div>
