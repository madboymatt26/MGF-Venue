<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$money = static function( $amount ) use ( $invoice ) { return MBS_Money::format( (int) $amount, $invoice->currency ); };
$date = static function( $value ) { return $value ? mysql2date( 'j M Y H:i', $value ) : 'Not recorded'; };
$pagination = static function( $name, $page, $has_next ) {
    if ( $page === 1 && ! $has_next ) return;
    echo '<nav class="mbs-list-pagination" aria-label="' . esc_attr( $name === 'items_page' ? 'Invoice item pages' : ( $name === 'audit_page' ? 'Audit history pages' : 'Payment history pages' ) ) . '">';
    if ( $page > 1 ) echo '<a class="button" href="' . esc_url( add_query_arg( $name, $page - 1 ) ) . '">Previous</a>';
    echo '<span>Page ' . (int) $page . '</span>';
    if ( $has_next ) echo '<a class="button" href="' . esc_url( add_query_arg( $name, $page + 1 ) ) . '">Next</a>';
    echo '</nav>';
};
?>
<div class="wrap mbs-admin mbs-invoice-review">
    <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=mathlin-booking' ) ); ?>">&larr; Back to Bookings</a></p>
    <h1>Invoice review <span><?php echo esc_html( $invoice->invoice_ref ); ?></span></h1>
    <p class="mbs-review-status"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $invoice->status ) ) ); ?><?php if ( $overdue ) : ?> <strong>Outstanding and overdue</strong><?php endif; ?></p>
    <section class="mbs-review-balances" aria-label="Current invoice balance">
        <?php foreach ( array( 'Invoice total' => $invoice->total_minor, 'Net payments' => $invoice->paid_minor, 'Credits applied' => $invoice->credited_minor, 'Outstanding' => $balance ) as $label => $amount ) : ?>
            <div><span><?php echo esc_html( $label ); ?></span><strong data-balance-label="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $money( $amount ) ); ?></strong></div>
        <?php endforeach; ?>
    </section>
    <p class="description">Current ledger balance, separate from the original issued document. Net payments include completed payments less completed refunds. Opening this page changes nothing and sends no email.</p>
    <div class="mbs-review-grid">
        <section class="postbox mbs-review-section"><h2>Invoice details</h2>
            <dl><dt>Hirer</dt><dd><?php echo esc_html( $invoice->contact_name ); ?></dd>
                <?php if ( $invoice->contact_organisation ) : ?><dt>Organisation</dt><dd><?php echo esc_html( $invoice->contact_organisation ); ?></dd><?php endif; ?>
                <dt>Email</dt><dd><?php echo esc_html( $invoice->contact_email ); ?></dd>
                <dt>Issued</dt><dd><?php echo esc_html( $date( $invoice->issued_at ) ); ?></dd>
                <dt>Due</dt><dd><?php echo esc_html( $date( $invoice->due_at ) ); ?></dd>
                <?php if ( $invoice->period_start && $invoice->period_end ) : ?><dt>Period</dt><dd><?php echo esc_html( mysql2date( 'j M Y', $invoice->period_start ) . ' to ' . mysql2date( 'j M Y', $invoice->period_end ) ); ?></dd><?php endif; ?>
            </dl>
            <?php if ( $invoice->series_ref ) : ?><p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mathlin-series&ref=' . rawurlencode( $invoice->series_ref ) ) ); ?>">Review series</a></p><?php endif; ?>
            <?php if ( $document_id ) : $doc_url = add_query_arg( array( 'action' => 'mbs_invoice_document', 'document_id' => (int) $document_id, 'mode' => 'issued', 'nonce' => wp_create_nonce( 'mbs_invoice_document_nonce' ) ), admin_url( 'admin-ajax.php' ) ); ?>
                <p class="mbs-review-actions"><button type="button" class="button mbs-view-invoice-btn" data-document-id="<?php echo (int) $document_id; ?>" data-invoice-ref="<?php echo esc_attr( $invoice->invoice_ref ); ?>">Open issued invoice</button><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( add_query_arg( 'format', 'pdf', $doc_url ) ); ?>">Download PDF</a></p>
            <?php else : ?><p class="description">No issued document is available for this historical invoice.</p><?php endif; ?>
        </section>
        <section class="postbox mbs-review-section"><h2>Review before recording money</h2>
            <p>Compare the bank statement and payment history below. Do not record a payment again because this invoice appears overdue.</p>
            <?php if ( $claim_error || $transactions_error ) : ?><p role="alert">Payment or checkout history could not be read. Recording is unavailable until this can be checked.</p>
            <?php elseif ( $checkout_pending ) : ?><p role="alert"><strong>Online checkout needs review:</strong> <?php echo esc_html( str_replace( '_', ' ', $claim->status ) ); ?>. Check the associated online order before recording any offline money.</p>
            <?php elseif ( $can_record ) : ?>
                <details><summary>Record a received bank-transfer payment</summary>
                    <p>This records money already received. It does not take a payment. A payment receipt will be emailed to the hirer.</p>
                    <form class="nms-manual-invoice-payment mbs-payment-form">
                        <input type="hidden" name="invoice_ref" value="<?php echo esc_attr( $invoice->invoice_ref ); ?>">
                        <input type="hidden" name="expected_version" value="<?php echo (int) $invoice->version; ?>">
                        <label>Amount received (&pound;) <input name="amount" inputmode="decimal" required placeholder="0.00" autocomplete="off"></label>
                        <label>Bank reference / note <input name="note" maxlength="255"></label>
                        <button type="submit" class="button button-primary">Record payment</button>
                    </form>
                </details>
            <?php else : ?><p>No offline payment can be recorded here for this invoice's current balance, status, currency or payment settings.</p><?php endif; ?>
            <p class="description">Cancellations, credits and refunds must use their existing booking, series or charge workflow. This page does not cancel bookings, issue refunds or send chasers.</p>
        </section>
    </div>
    <section class="postbox mbs-review-section"><h2>Invoice items and bookings</h2>
        <?php if ( $items_error ) : ?><p role="alert">Invoice items could not be read. Refresh before relying on this review.</p>
        <?php elseif ( ! $items ) : ?><p>No item lines recorded for this invoice.</p>
        <?php else : ?><div class="mbs-review-table"><table class="widefat striped"><thead><tr><th>Service date</th><th>Description / booking</th><th>Line total</th></tr></thead><tbody>
            <?php foreach ( array_slice( $items, 0, 50 ) as $item ) : ?><tr><td data-label="Service date"><?php echo esc_html( $item->service_date ? mysql2date( 'j M Y', $item->service_date ) : 'Not recorded' ); ?></td><td data-label="Description / booking"><?php echo esc_html( $item->description ); ?><?php if ( $item->booking_ref ) : ?><br><a href="<?php echo esc_url( admin_url( 'admin.php?page=mathlin-booking&action=view&ref=' . rawurlencode( $item->booking_ref ) ) ); ?>"><?php echo esc_html( $item->booking_ref ); ?></a><?php endif; ?></td><td data-label="Line total"><?php echo esc_html( $money( $item->line_total_minor ) ); ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
        <?php $pagination( 'items_page', $items_page, count( (array) $items ) > 50 ); ?>
    </section>
    <section class="postbox mbs-review-section"><h2>Payment history</h2>
        <?php if ( $transactions_error ) : ?><p role="alert">Payment history could not be read. Do not assume there are no payments.</p>
        <?php elseif ( ! $transactions ) : ?><p>No payment transactions recorded for this invoice. Check the bank statement before chasing or recording money.</p>
        <?php else : ?><div class="mbs-review-table"><table class="widefat striped"><thead><tr><th>Date / reference</th><th>Type / status</th><th>Method / note</th><th>Amount</th></tr></thead><tbody>
            <?php foreach ( array_slice( $transactions, 0, 50 ) as $transaction ) : $meta = json_decode( (string) $transaction->metadata_json, true ); ?><tr><td data-label="Date / reference"><?php echo esc_html( $date( $transaction->occurred_at ?: $transaction->created_at ) ); ?><br><small><?php echo esc_html( $transaction->transaction_ref ); ?></small></td><td data-label="Type / status"><?php echo esc_html( ucfirst( $transaction->transaction_type ) . ' / ' . $transaction->status ); ?></td><td data-label="Method / note"><?php echo esc_html( $transaction->provider ); ?><?php if ( ! empty( $meta['note'] ) && is_scalar( $meta['note'] ) ) : ?><br><?php echo esc_html( $meta['note'] ); ?><?php endif; ?></td><td data-label="Amount"><?php echo esc_html( MBS_Money::format( (int) $transaction->amount_minor, $transaction->currency ) ); ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
        <?php $pagination( 'payments_page', $payments_page, count( (array) $transactions ) > 50 ); ?>
    </section>
    <section class="postbox mbs-review-section"><h2>Invoice audit history</h2>
        <?php if ( $audit_error ) : ?><p role="alert">Audit history could not be read.</p>
        <?php elseif ( ! $audit ) : ?><p>No invoice-level audit entries recorded. Related booking and series actions remain in their own histories.</p>
        <?php else : ?><div class="mbs-review-table"><table class="widefat striped"><thead><tr><th>Date / person</th><th>Action</th><th>Details</th></tr></thead><tbody>
            <?php foreach ( array_slice( $audit, 0, 50 ) as $entry ) : ?><tr><td data-label="Date / person"><?php echo esc_html( $date( $entry->created_at ) ); ?><br><?php echo esc_html( $entry->user_name ); ?></td><td data-label="Action"><?php echo esc_html( MBS_Audit_Log::action_label( $entry->action ) ); ?></td><td data-label="Details"><?php echo esc_html( $entry->details ); ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
        <?php $pagination( 'audit_page', $audit_page, count( (array) $audit ) > 50 ); ?>
    </section>
</div>

<?php include __DIR__ . '/partials/invoice-modal.php'; ?>
