<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap nms-admin-wrap">
    <h1><?php echo MBS_Admin::brand_mark(); ?> Recurring Series</h1>

    <?php if ( ! empty( $ref ) && ! $series ) : ?>
        <div class="notice notice-error"><p>Recurring series not found.</p></div>
    <?php elseif ( ! $series ) : ?>
        <form method="get" style="display:flex;gap:8px;align-items:center;margin:16px 0;">
            <input type="hidden" name="page" value="mathlin-series">
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Reference, customer or email">
            <select name="status">
                <option value="">All statuses</option>
                <?php foreach ( array( 'pending', 'confirmed', 'paused', 'cancelled_future', 'cancelled' ) as $option ) : ?>
                    <option value="<?php echo esc_attr( $option ); ?>" <?php selected( $status, $option ); ?>><?php echo esc_html( ucfirst( str_replace( '_', ' ', $option ) ) ); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="button">Filter</button>
        </form>
        <table class="widefat striped">
            <thead><tr><th>Series</th><th>Customer</th><th>Schedule</th><th>Dates</th><th>Billing</th><th>Status</th></tr></thead>
            <tbody>
            <?php if ( ! $series_rows ) : ?><tr><td colspan="6">No first-class recurring series match this filter.</td></tr><?php endif; ?>
            <?php foreach ( $series_rows as $row ) : ?>
                <tr>
                    <td><a href="<?php echo esc_url( admin_url( 'admin.php?page=mathlin-series&ref=' . rawurlencode( $row->series_ref ) ) ); ?>"><strong><?php echo esc_html( $row->series_ref ); ?></strong></a><br><?php echo esc_html( $row->space ); ?></td>
                    <td><?php echo esc_html( $row->contact_name ); ?><br><small><?php echo esc_html( $row->contact_organisation ?: $row->contact_email ); ?></small></td>
                    <td>Weekly · <?php echo esc_html( $row->all_day ? 'all day' : substr( $row->start_time, 0, 5 ) . '–' . substr( $row->end_time, 0, 5 ) ); ?><br><small><?php echo esc_html( wp_date( 'j M Y', strtotime( $row->start_date ) ) . '–' . wp_date( 'j M Y', strtotime( $row->repeat_until ) ) ); ?></small></td>
                    <td><?php echo (int) $row->accepted_count; ?> accepted<br><small><?php echo (int) $row->requested_count - (int) $row->accepted_count; ?> skipped</small></td>
                    <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->billing_mode ) ) ); ?><br><small><?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->payment_method ) ) ); ?></small></td>
                    <td><strong><?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->status ) ) ); ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=mathlin-series' ) ); ?>">← All recurring series</a></p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;">
            <section class="postbox" style="padding:16px;">
                <h2 style="margin-top:0;"><?php echo esc_html( $series->series_ref ); ?> · <?php echo esc_html( ucfirst( str_replace( '_', ' ', $series->status ) ) ); ?></h2>
                <p><strong><?php echo esc_html( $series->contact_name ); ?></strong><?php echo $series->contact_organisation ? '<br>' . esc_html( $series->contact_organisation ) : ''; ?><br><a href="mailto:<?php echo esc_attr( $series->contact_email ); ?>"><?php echo esc_html( $series->contact_email ); ?></a><br><?php echo esc_html( $series->contact_phone ); ?></p>
                <p><strong><?php echo esc_html( $series->space ); ?></strong><?php echo $series->kitchen ? ' + kitchen' : ''; ?><br>Weekly, <?php echo esc_html( $series->all_day ? 'all day' : substr( $series->start_time, 0, 5 ) . '–' . substr( $series->end_time, 0, 5 ) ); ?><br><?php echo esc_html( wp_date( 'j M Y', strtotime( $series->start_date ) ) . '–' . wp_date( 'j M Y', strtotime( $series->repeat_until ) ) ); ?></p>
                <p><?php echo (int) $series->accepted_count; ?> accepted of <?php echo (int) $series->requested_count; ?> requested · £<?php echo esc_html( number_format( (float) $series->price_per_booking, 2 ) ); ?> per occurrence · estimated £<?php echo esc_html( number_format( (float) $series->estimated_total, 2 ) ); ?></p>
                <p><strong>Deposit:</strong> <?php echo esc_html( $series->deposit_policy === 'none' ? 'None' : ucfirst( str_replace( '_', ' ', $series->deposit_policy ) ) ); ?> · <strong>Terms accepted:</strong> <?php echo $series->terms_accepted_at ? esc_html( wp_date( 'j M Y H:i', strtotime( $series->terms_accepted_at ) ) ) : 'Not recorded'; ?></p>
                <?php if ( ! empty( $series->metadata_incomplete ) ) : ?><div class="notice notice-warning inline"><p>This series was registered from older occurrence records. Skipped dates, original terms acceptance and historic billing intent are unknown and have not been inferred.</p></div><?php endif; ?>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <?php if ( $series->status === 'pending' ) : ?><button class="button button-primary nms-btn-review-approve" data-series="<?php echo esc_attr( $series->series_ref ); ?>" data-expected-version="<?php echo (int) $series->version; ?>" data-scout="<?php echo $series->scout_use ? '1' : '0'; ?>">Review &amp; Approve</button><?php endif; ?>
                    <?php if ( $series->status === 'confirmed' ) : ?><button class="button nms-btn-series-pause" data-series="<?php echo esc_attr( $series->series_ref ); ?>" data-paused="1" data-expected-status="confirmed" data-expected-version="<?php echo (int) $series->version; ?>">Pause billing</button><?php endif; ?>
                    <?php if ( $series->status === 'paused' ) : ?><button class="button button-primary nms-btn-series-pause" data-series="<?php echo esc_attr( $series->series_ref ); ?>" data-paused="0" data-expected-status="paused" data-expected-version="<?php echo (int) $series->version; ?>">Resume billing</button><?php endif; ?>
                    <?php if ( $series->status === 'confirmed' ) : ?><button class="button nms-btn-resend-series" data-series="<?php echo esc_attr( $series->series_ref ); ?>">Resend confirmation</button><?php endif; ?>
                    <?php if ( ! in_array( $series->status, array( 'cancelled', 'cancelled_future' ), true ) ) : ?><button class="button nms-btn-series-status" data-series="<?php echo esc_attr( $series->series_ref ); ?>" data-status="cancelled" data-scope="future" data-expected-status="<?php echo esc_attr( $series->status ); ?>" data-expected-version="<?php echo (int) $series->version; ?>">Cancel future dates</button><?php endif; ?>
                    <?php if ( $series->status !== 'cancelled' ) : ?><button class="button nms-btn-series-status" data-series="<?php echo esc_attr( $series->series_ref ); ?>" data-status="cancelled" data-scope="all" data-expected-status="<?php echo esc_attr( $series->status ); ?>" data-expected-version="<?php echo (int) $series->version; ?>">Cancel entire series</button><?php endif; ?>
                </div>
                <?php if ( ! in_array( $series->status, array( 'cancelled', 'cancelled_future' ), true ) ) : ?><form class="nms-extend-external-series" style="margin-top:12px;display:flex;gap:8px;align-items:center;"><input type="hidden" name="series_ref" value="<?php echo esc_attr( $series->series_ref ); ?>"><input type="hidden" name="expected_version" value="<?php echo (int) $series->version; ?>"><label>Extend to <input type="date" name="repeat_until" min="<?php echo esc_attr( wp_date( 'Y-m-d', strtotime( $series->repeat_until . ' +1 day' ) ) ); ?>" max="<?php echo esc_attr( wp_date( 'Y-m-d', strtotime( $series->start_date . ' +1 year' ) ) ); ?>" required></label><button class="button">Extend series</button></form><?php endif; ?>
            </section>

            <section class="postbox" style="padding:16px;">
                <h2 style="margin-top:0;">Billing arrangement</h2>
                <?php if ( $series->billing_treatment === 'manual_consolidated' ) : ?><div class="notice notice-warning inline"><p>Automatic occurrence reminders are suspended. Switch to invoice managed when you are ready for consolidated invoices.</p></div><?php endif; ?>
                <form class="nms-series-billing-form">
                    <input type="hidden" name="series_ref" value="<?php echo esc_attr( $series->series_ref ); ?>">
                    <input type="hidden" name="expected_version" value="<?php echo (int) $series->version; ?>">
                    <table class="form-table"><tbody>
                    <tr><th>Frequency</th><td><select name="billing_mode"><?php foreach ( array( 'monthly' => 'Monthly in advance', 'termly' => 'Termly', 'upfront' => 'Whole series upfront', 'legacy_per_occurrence' => 'Per occurrence (legacy)', 'none' => 'No charge' ) as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $series->billing_mode, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Automation</th><td><select name="billing_treatment"><?php foreach ( array( 'invoice_managed' => 'Generate consolidated invoices', 'manual_consolidated' => 'Manage consolidated billing manually', 'legacy_per_occurrence' => 'Legacy occurrence billing', 'none' => 'No billing' ) as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $series->billing_treatment, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Payment</th><td><select name="payment_method"><option value="online" <?php selected( $series->payment_method, 'online' ); ?>>Online card payment</option><option value="offline_bacs" <?php selected( $series->payment_method, 'offline_bacs' ); ?>>Offline invoice (BACS/PO)</option><option value="none" <?php selected( $series->payment_method, 'none' ); ?>>No payment</option></select></td></tr>
                    <tr><th>Invoice lead time</th><td><input type="number" min="0" max="365" name="invoice_lead_days" value="<?php echo (int) $series->invoice_lead_days; ?>"> days</td></tr>
                    <tr><th>Payment terms</th><td><input type="number" min="0" max="365" name="payment_terms_days" value="<?php echo (int) $series->payment_terms_days; ?>"> days</td></tr>
                    <tr class="nms-term-schedule"><th>Term dates</th><td><textarea name="billing_schedule_json" rows="4" class="large-text code"><?php echo esc_textarea( $series->billing_schedule_json === '[]' ? '' : $series->billing_schedule_json ); ?></textarea><p class="description">Only required for termly billing. Store explicit named term start/end dates; dates are never guessed.</p></td></tr>
                    <?php if ( ! empty( $series->metadata_incomplete ) && $series->billing_treatment === 'legacy_per_occurrence' ) : ?><tr><th>Adopt legacy series</th><td><label><input type="checkbox" name="adopt_legacy" value="1"> I have reviewed the preview and want future unpaid occurrences moved to consolidated billing.</label><p class="description">Historic paid/deposit-paid occurrences stay in the legacy system and are never invoiced again.</p></td></tr><?php endif; ?>
                    </tbody></table>
                    <button class="button button-primary" type="submit">Save billing arrangement</button> <span class="nms-series-billing-message"></span>
                </form>
            </section>
        </div>

        <section class="postbox" style="padding:16px;">
            <h2 style="margin-top:0;">Invoice preview</h2>
            <?php if ( is_wp_error( $preview ) ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( $preview->get_error_message() ); ?></p></div>
            <?php elseif ( empty( $preview['periods'] ) ) : ?><p>No invoice periods apply.</p>
            <?php else : ?><table class="widefat striped"><thead><tr><th>Period</th><th>Issue</th><th>Due</th><th>Dates</th><th>Total</th></tr></thead><tbody><?php foreach ( $preview['periods'] as $period ) : ?><tr><td><?php echo esc_html( $period['label'] ); ?></td><td><?php echo esc_html( wp_date( 'j M Y', strtotime( $period['issue_on'] ) ) ); ?></td><td><?php echo esc_html( wp_date( 'j M Y', strtotime( $period['due_on'] ) ) ); ?></td><td><?php echo (int) $period['occurrence_count']; ?></td><td><?php echo esc_html( MBS_Money::format( $period['total_minor'] ) ); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
            <?php if ( $series->status === 'confirmed' && $series->billing_treatment === 'invoice_managed' ) : ?><p><button class="button nms-series-catch-up">Generate all invoices due today</button></p><?php endif; ?>
        </section>

        <section class="postbox" style="padding:16px;">
            <h2 style="margin-top:0;">Invoices &amp; payments</h2>
            <?php if ( ! $invoices ) : ?><p>No consolidated invoices yet.</p><?php endif; ?>
            <?php foreach ( $invoices as $invoice ) : $balance = MBS_Billing_Ledger::balance_minor( $invoice ); ?>
                <details style="border-top:1px solid #ddd;padding:10px 0;" <?php echo $balance > 0 ? 'open' : ''; ?>><summary><strong><?php echo esc_html( $invoice->invoice_ref ); ?></strong> · <?php echo esc_html( ucfirst( str_replace( '_', ' ', $invoice->status ) ) ); ?> · <?php echo esc_html( MBS_Money::format( $balance, $invoice->currency ) ); ?> outstanding</summary>
                    <p><?php echo esc_html( wp_date( 'j M Y', strtotime( $invoice->period_start ) ) . '–' . wp_date( 'j M Y', strtotime( $invoice->period_end ) ) ); ?> · Total <?php echo esc_html( MBS_Money::format( (int) $invoice->total_minor, $invoice->currency ) ); ?> · Paid <?php echo esc_html( MBS_Money::format( (int) $invoice->paid_minor, $invoice->currency ) ); ?></p>
                    <?php if ( $balance > 0 && in_array( $invoice->status, array( 'issued', 'part_paid', 'overdue' ), true ) ) : ?><form class="nms-manual-invoice-payment" style="display:flex;gap:8px;align-items:center;"><input type="hidden" name="invoice_ref" value="<?php echo esc_attr( $invoice->invoice_ref ); ?>"><input type="hidden" name="expected_version" value="<?php echo (int) $invoice->version; ?>"><label>Record offline payment £<input name="amount" inputmode="decimal" value="<?php echo esc_attr( MBS_Money::decimal( $balance ) ); ?>" size="8"></label><input name="note" placeholder="Bank/PO note"><button class="button">Record payment</button></form><?php endif; ?>
                </details>
            <?php endforeach; ?>
        </section>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;">
            <section class="postbox" style="padding:16px;"><h2 style="margin-top:0;">Occurrences &amp; exceptions</h2><details><summary><?php echo count( $occurrences ); ?> occurrence records</summary><ul><?php foreach ( $occurrences as $occurrence ) : ?><li><a href="<?php echo esc_url( admin_url( 'admin.php?page=mathlin-booking&action=view&ref=' . rawurlencode( $occurrence->ref ) ) ); ?>"><?php echo esc_html( wp_date( 'D j M Y', strtotime( $occurrence->booking_date ) ) . ' · ' . $occurrence->ref ); ?></a> — <?php echo esc_html( MBS_Bookings::status_label( $occurrence->status ) ); ?></li><?php endforeach; ?></ul></details><?php if ( $exceptions ) : ?><h3>Skipped dates</h3><ul><?php foreach ( $exceptions as $exception ) : ?><li><?php echo esc_html( wp_date( 'j M Y', strtotime( $exception['date'] ) ) . ' — ' . $exception['message'] ); ?></li><?php endforeach; ?></ul><?php endif; ?></section>
            <section class="postbox" style="padding:16px;"><h2 style="margin-top:0;">Audit history</h2><?php if ( ! $audit ) : ?><p>No series audit events yet.</p><?php else : ?><ul><?php foreach ( $audit as $event ) : ?><li><strong><?php echo esc_html( MBS_Audit_Log::action_label( $event->action ) ); ?></strong> · <?php echo esc_html( wp_date( 'j M Y H:i', strtotime( $event->created_at ) ) ); ?><br><small><?php echo esc_html( $event->details ); ?></small></li><?php endforeach; ?></ul><?php endif; ?></section>
        </div>
    <?php endif; ?>
</div>

<?php // ── Review & Approve Modal ───────────────────────────────────────── ?>
<?php if ( ! empty( $series ) && $series->status === 'pending' ) : ?>
<div id="mbs-approval-modal" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.5);overflow:auto;">
    <div style="max-width:700px;margin:60px auto;background:#fff;border-radius:12px;padding:32px;box-shadow:0 8px 32px rgba(0,0,0,.2);">
        <h2 style="color:#7413DC;margin-top:0;">Review &amp; Approve Series</h2>
        <p>Confirm billing configuration before approving <strong><?php echo esc_html( $series->series_ref ); ?></strong>.</p>
        <table class="form-table" style="margin:16px 0;">
            <tr><th>Space</th><td><?php echo esc_html( $series->space ); ?></td></tr>
            <tr><th>Price per occurrence</th><td>&pound;<?php echo esc_html( number_format( (float) $series->price_per_booking, 2 ) ); ?></td></tr>
            <tr><th>Estimated total</th><td>&pound;<?php echo esc_html( number_format( (float) $series->estimated_total, 2 ) ); ?></td></tr>
            <tr><th>Accepted dates</th><td><?php echo (int) $series->accepted_count; ?> of <?php echo (int) $series->requested_count; ?> requested</td></tr>
        </table>
        <form id="mbs-approval-form">
            <input type="hidden" name="series_ref" value="<?php echo esc_attr( $series->series_ref ); ?>">
            <input type="hidden" name="expected_version" value="<?php echo (int) $series->version; ?>">
            <table class="form-table"><tbody>
                <tr><th><label>Billing frequency</label></th><td><select name="billing_mode">
                    <option value="monthly" <?php selected( $series->billing_mode, 'monthly' ); ?>>Monthly in advance</option>
                    <option value="termly" <?php selected( $series->billing_mode, 'termly' ); ?>>Termly</option>
                    <option value="upfront" <?php selected( $series->billing_mode, 'upfront' ); ?>>Whole series upfront</option>
                    <option value="none" <?php selected( $series->billing_mode, 'none' ); ?>>No charge</option>
                </select></td></tr>
                <tr><th><label>Billing treatment</label></th><td><select name="billing_treatment">
                    <option value="invoice_managed" <?php selected( $series->billing_treatment, 'invoice_managed' ); ?>>Generate consolidated invoices automatically</option>
                    <option value="manual_consolidated" <?php selected( $series->billing_treatment, 'manual_consolidated' ); ?>>Manage billing manually</option>
                    <option value="none" <?php selected( $series->billing_treatment, 'none' ); ?>>No billing</option>
                </select></td></tr>
                <tr><th><label>Payment method</label></th><td><select name="payment_method">
                    <option value="online" <?php selected( $series->payment_method, 'online' ); ?>>Online card payment</option>
                    <option value="offline_bacs" <?php selected( $series->payment_method, 'offline_bacs' ); ?>>BACS / Purchase Order</option>
                    <option value="none" <?php selected( $series->payment_method, 'none' ); ?>>No payment</option>
                </select></td></tr>
                <tr><th>Invoice lead time</th><td><input type="number" name="invoice_lead_days" min="0" max="365" value="<?php echo (int) $series->invoice_lead_days; ?>"> days</td></tr>
                <tr><th>Payment terms</th><td><input type="number" name="payment_terms_days" min="0" max="365" value="<?php echo (int) $series->payment_terms_days; ?>"> days</td></tr>
                <tr class="mbs-term-dates-row" style="display:none;"><th>Term dates</th><td>
                    <div id="mbs-term-editor"><p class="description">Add named term periods.</p><div id="mbs-term-list"></div><button type="button" class="button" id="mbs-add-term">+ Add term</button></div>
                </td></tr>
            </tbody></table>
            <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:24px;">
                <button type="button" class="button" id="mbs-cancel-approval">Cancel</button>
                <button type="submit" class="button button-primary">Confirm &amp; Approve</button>
            </div>
            <p class="mbs-approval-message" style="margin-top:12px;"></p>
        </form>
    </div>
</div>
<?php endif; ?>
