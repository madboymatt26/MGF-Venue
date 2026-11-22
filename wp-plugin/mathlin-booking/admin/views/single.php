<?php if ( ! defined( 'ABSPATH' ) ) exit;
$spaces            = MBS_Bookings::get_spaces();
$is_daily          = ! empty( $booking->all_day );
$time_str          = $is_daily ? 'All day' : ( $booking->start_time . ' – ' . $booking->end_time );
$date_str          = wp_date( 'l j F Y', strtotime( $booking->booking_date ) )
    . ( ! empty( $booking->booking_date_end ) && $booking->booking_date_end !== $booking->booking_date
        ? ' – ' . wp_date( 'l j F Y', strtotime( $booking->booking_date_end ) )
        : '' );
$date_short_str    = wp_date( 'D j M Y', strtotime( $booking->booking_date ) )
    . ( ! empty( $booking->booking_date_end ) && $booking->booking_date_end !== $booking->booking_date
        ? ' – ' . wp_date( 'D j M Y', strtotime( $booking->booking_date_end ) )
        : '' );
$pricing_tier      = MBS_Bookings::get_booking_tier( $booking );
$tier_multiplier   = MBS_Bookings::get_tier_multiplier( $pricing_tier );
$kitchen_price     = MBS_Bookings::get_tiered_kitchen_price( $pricing_tier );
$series_record     = ! empty( $booking->series_id ) ? MBS_Series::get( $booking->series_id ) : null;
$series_count      = ! empty( $booking->series_id ) ? MBS_Bookings::count_series( $booking->series_id ) : 0;
$billing_treatment = MBS_Series::billing_treatment_for_booking( $booking, $series_record );
$uses_consolidated_billing = ! empty( $booking->series_id )
    && $series_record
    && in_array( $billing_treatment, array( 'manual_consolidated', 'invoice_managed', 'none' ), true );
$series_admin_url = ! empty( $booking->series_id )
    ? admin_url( 'admin.php?page=mathlin-series&ref=' . rawurlencode( $booking->series_id ) )
    : '';
$invoice_document_id = ! $uses_consolidated_billing && ! empty( $booking->current_invoice_document_id )
    ? (int) $booking->current_invoice_document_id
    : 0;
$invoice_document_nonce = $invoice_document_id ? wp_create_nonce( 'mbs_invoice_document_nonce' ) : '';
$invoice_view_url = $invoice_document_id ? add_query_arg( array(
    'action' => 'mbs_invoice_document', 'document_id' => $invoice_document_id,
    'format' => 'html', 'mode' => 'issued', 'nonce' => $invoice_document_nonce,
), admin_url( 'admin-ajax.php' ) ) : '';
$invoice_pdf_url = $invoice_document_id ? add_query_arg( array(
    'action' => 'mbs_invoice_document', 'document_id' => $invoice_document_id,
    'format' => 'pdf', 'mode' => 'issued', 'nonce' => $invoice_document_nonce,
), admin_url( 'admin-ajax.php' ) ) : '';

$amount_paid = (float) ( $booking->amount_paid ?? 0 );
$balance     = (float) $booking->amount - $amount_paid;
$show_balance_alert = MBS_Payment_Features::enabled( 'manual' ) && $amount_paid > 0 && abs( $balance ) > 0.01;

$access_readiness = MBS_Access_Details::readiness( $booking );
$booking_mail     = class_exists( 'MBS_Email_Queue' )
    ? MBS_Email_Queue::get_booking_email_summary( (int) $booking->id )
    : array( 'failed_count' => 0, 'pending_count' => 0, 'latest_access' => null, 'latest_failed' => null );
$latest_access    = $booking_mail['latest_access'] ?? null;
$access_failed    = $latest_access
    && $latest_access->status === 'failed'
    && ! in_array( (string) ( $latest_access->failure_category ?? '' ), array( 'stale_source', 'policy_expired', 'dismissed' ), true );

$terms_evidence = MBS_Terms_Acceptance::evidence( $booking );
$terms_page_id  = (int) get_option( 'mbs_terms_page_id', 0 );
$terms_url      = '';
if ( $terms_page_id && get_post( $terms_page_id ) ) {
    $terms_url = get_permalink( $terms_page_id );
} else {
    $tp = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 's' => 'mathlin_terms', 'numberposts' => 1 ) );
    if ( ! empty( $tp ) ) {
        $terms_url = get_permalink( $tp[0]->ID );
    }
}

$audit_entries = MBS_Audit_Log::get_for_booking( $booking->ref );

$single_edit_config = array(
    'spaces'          => $spaces,
    'kitchen_price'   => (float) $kitchen_price,
    'pricing_tier'    => $pricing_tier,
    'tier_multiplier' => (float) $tier_multiplier,
    'original_amount' => (float) $booking->amount,
);

$is_active_oneoff_scout = ! empty( $booking->scout_use )
    && empty( $booking->series_id )
    && in_array( $booking->status, array( 'pending', 'confirmed', 'deposit_paid', 'paid' ), true );
?>
<div class="wrap mbs-admin mbs-single-page">
    <!-- 1. Booking Hero Header Bar -->
    <header class="mbs-booking-hero">
        <div class="mbs-booking-hero__nav">
            <?php if ( ! empty( $booking->scout_use ) && empty( $booking->series_id ) ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mathlin-scout-nights&tab=one-off&scope=all' ) ); ?>" class="nms-back-link">&#8592; One-off Scout bookings</a>
            <?php else : ?>
                <a href="?page=mathlin-booking" class="nms-back-link">&#8592; All Bookings</a>
            <?php endif; ?>
        </div>
        <div class="mbs-booking-hero__main">
            <div class="mbs-booking-hero__title-row">
                <h1>Booking <?php echo esc_html( $booking->ref ); ?></h1>
                <span class="nms-status nms-status-<?php echo esc_attr( $booking->status ); ?>"><?php echo esc_html( MBS_Bookings::status_label( $booking->status ) ); ?></span>
                <?php if ( ! empty( $booking->scout_use ) ) : ?>
                    <span class="mbs-series-badge mbs-series-badge--scout">Scout use</span>
                <?php endif; ?>
            </div>
            <p class="mbs-booking-hero__meta">
                <span><strong><?php echo esc_html( $booking->space ); ?></strong></span>
                <span aria-hidden="true">&middot;</span>
                <span><?php echo esc_html( $date_short_str ); ?></span>
                <span aria-hidden="true">&middot;</span>
                <span><?php echo esc_html( $time_str ); ?></span>
                <span data-payment-field="1"><span aria-hidden="true">&middot;</span> <strong>&pound;<?php echo number_format( (float) $booking->amount, 2 ); ?></strong></span>
            </p>
        </div>
        <div class="mbs-booking-hero__actions">
            <button type="button" class="button button-secondary" id="nms-toggle-edit">Edit booking</button>
        </div>
    </header>

    <!-- 2. Conditional High-Priority Alert Strip -->
    <?php if ( $show_balance_alert ) : ?>
        <?php if ( $balance > 0 ) : ?>
            <div class="mbs-alert-strip mbs-alert-strip--danger" role="status">
                <div class="mbs-alert-strip__text">
                    <strong>Balance Due: &pound;<?php echo number_format( $balance, 2 ); ?></strong>
                    <span class="mbs-alert-strip__detail">(Paid: &pound;<?php echo number_format( $amount_paid, 2 ); ?> / Total: &pound;<?php echo number_format( (float) $booking->amount, 2 ); ?>)</span>
                </div>
                <button type="button" class="button button-small mbs-btn-success nms-btn-mark-balance-paid" data-ref="<?php echo esc_attr( $booking->ref ); ?>">Mark Fully Paid (&pound;<?php echo number_format( $balance, 2 ); ?>)</button>
            </div>
        <?php else : ?>
            <div class="mbs-alert-strip mbs-alert-strip--info" role="status">
                <div class="mbs-alert-strip__text">
                    <strong>Refund / Credit Due: &pound;<?php echo number_format( abs( $balance ), 2 ); ?></strong>
                    <span class="mbs-alert-strip__detail">(Paid: &pound;<?php echo number_format( $amount_paid, 2 ); ?> / Total: &pound;<?php echo number_format( (float) $booking->amount, 2 ); ?>)</span>
                </div>
                <button type="button" class="button button-small mbs-btn-info nms-btn-mark-refunded" data-ref="<?php echo esc_attr( $booking->ref ); ?>">Mark Refunded</button>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- 3. Sticky Mobile Section Jump Bar (<= 1024px) -->
    <nav class="mbs-mobile-jump-bar" aria-label="Jump to booking section">
        <a href="#mbs-section-details">Details</a>
        <a href="#mbs-section-actions">Actions</a>
        <a href="#mbs-section-operations">Operations</a>
        <a href="#mbs-section-billing">Billing</a>
        <?php if ( ! empty( $audit_entries ) ) : ?>
            <a href="#mbs-section-audit">Audit</a>
        <?php endif; ?>
    </nav>

    <!-- 4. Two-Column Responsive Grid -->
    <div class="nms-single-layout mbs-single-grid">
        <!-- 4A. Main Column -->
        <div class="mbs-single-main">
            <!-- Booking Details Card -->
            <details id="mbs-section-details" class="nms-card mbs-disclosure" open aria-labelledby="mbs-details-heading">
                <summary class="nms-card-header">
                    <h2 id="mbs-details-heading">Booking Details</h2>
                    <span class="mbs-card-badge"><?php echo esc_html( $booking->ref ); ?></span>
                </summary>

                <!-- View mode -->
                <div id="nms-view-mode" class="mbs-card-body">
                    <dl class="mbs-dl-grid">
                        <div class="mbs-dl-item">
                            <dt>Reference</dt>
                            <dd><?php echo esc_html( $booking->ref ); ?></dd>
                        </div>
                        <?php if ( $uses_consolidated_billing && $billing_treatment === 'none' ) : ?>
                        <div class="mbs-dl-item">
                            <dt>Schedule</dt>
                            <dd><a href="<?php echo esc_url( $series_admin_url ); ?>">View grouped dates</a></dd>
                        </div>
                        <?php elseif ( $uses_consolidated_billing ) : ?>
                        <div class="mbs-dl-item" data-payment-field="1">
                            <dt>Billing</dt>
                            <dd>Consolidated at series level<?php if ( $series_admin_url ) : ?> — <a href="<?php echo esc_url( $series_admin_url ); ?>">view series invoices</a><?php endif; ?></dd>
                        </div>
                        <?php else : ?>
                        <div class="mbs-dl-item" data-payment-field="1">
                            <dt>Invoice No.</dt>
                            <dd><?php echo esc_html( $booking->invoice_number ?: '—' ); ?></dd>
                        </div>
                        <?php endif; ?>
                        <div class="mbs-dl-item">
                            <dt>Space</dt>
                            <dd><?php echo esc_html( $booking->space ); ?></dd>
                        </div>
                        <div class="mbs-dl-item">
                            <dt>Date</dt>
                            <dd><?php echo esc_html( $date_str ); ?></dd>
                        </div>
                        <div class="mbs-dl-item">
                            <dt>Time</dt>
                            <dd><?php echo esc_html( $time_str ); ?></dd>
                        </div>
                        <div class="mbs-dl-item">
                            <dt>Attendees</dt>
                            <dd><?php echo esc_html( $booking->attendees ); ?></dd>
                        </div>
                        <div class="mbs-dl-item">
                            <dt>Kitchen</dt>
                            <dd><?php echo $booking->kitchen ? 'Yes' : 'No'; ?></dd>
                        </div>
                        <div class="mbs-dl-item">
                            <dt>Scout Use</dt>
                            <dd><?php echo ! empty( $booking->scout_use ) ? 'Yes (no charge)' : 'No (external hire)'; ?></dd>
                        </div>
                        <div class="mbs-dl-item" data-payment-field="1">
                            <dt>Amount</dt>
                            <dd><strong>&pound;<?php echo number_format( (float) $booking->amount, 2 ); ?></strong></dd>
                        </div>
                        <div class="mbs-dl-item">
                            <dt>Name</dt>
                            <dd><?php echo esc_html( $booking->name ); ?></dd>
                        </div>
                        <div class="mbs-dl-item">
                            <dt>Organisation</dt>
                            <dd><?php echo esc_html( $booking->organisation ?: '—' ); ?></dd>
                        </div>
                        <div class="mbs-dl-item">
                            <dt>Email</dt>
                            <dd><a href="mailto:<?php echo esc_attr( $booking->email ); ?>"><?php echo esc_html( $booking->email ); ?></a></dd>
                        </div>
                        <div class="mbs-dl-item">
                            <dt>Phone</dt>
                            <dd><?php echo esc_html( $booking->phone ?: '—' ); ?></dd>
                        </div>
                        <div class="mbs-dl-item mbs-dl-item--full">
                            <dt>Purpose</dt>
                            <dd><?php echo esc_html( $booking->purpose ); ?></dd>
                        </div>
                        <?php if ( $booking->notes ) : ?>
                        <div class="mbs-dl-item mbs-dl-item--full">
                            <dt>Booker Notes</dt>
                            <dd><?php echo nl2br( esc_html( $booking->notes ) ); ?></dd>
                        </div>
                        <?php endif; ?>
                        <div class="mbs-dl-item mbs-dl-item--full" data-payment-field="1">
                            <dt>Billing Address</dt>
                            <dd><?php echo $booking->address ? nl2br( esc_html( $booking->address ) ) : '—'; ?></dd>
                        </div>
                        <?php
                        // Show WooCommerce payment billing address if available (for mismatch detection)
                        if ( class_exists( 'WooCommerce' ) ) {
                            $woo_orders = wc_get_orders( array(
                                'meta_key'   => '_mbs_booking_ref',
                                'meta_value' => $booking->ref,
                                'limit'      => 1,
                                'orderby'    => 'date',
                                'order'      => 'DESC',
                            ) );
                            if ( ! empty( $woo_orders ) ) {
                                $woo_order = $woo_orders[0];
                                $woo_addr  = trim( $woo_order->get_billing_address_1() . "\n" . $woo_order->get_billing_address_2() . "\n" . $woo_order->get_billing_city() . "\n" . $woo_order->get_billing_postcode() );
                                $woo_addr  = preg_replace( '/\n{2,}/', "\n", $woo_addr );
                                $booking_addr_norm = strtolower( preg_replace( '/[\s,]+/', ' ', trim( (string) $booking->address ) ) );
                                $woo_addr_norm     = strtolower( preg_replace( '/[\s,]+/', ' ', trim( $woo_addr ) ) );
                                if ( $booking_addr_norm !== $woo_addr_norm ) : ?>
                        <div class="mbs-dl-item mbs-dl-item--full" data-payment-field="1">
                            <dt>Payment Billing Address</dt>
                            <dd class="mbs-address-mismatch">
                                <?php echo nl2br( esc_html( $woo_addr ) ); ?>
                                <br><span class="mbs-mismatch-badge">ADDRESS MISMATCH</span>
                            </dd>
                        </div>
                                <?php endif;
                            }
                        }
                        ?>
                        <?php MBS_Custom_Fields::render_admin_display( $booking ); ?>
                        <div class="mbs-dl-item">
                            <dt>Submitted</dt>
                            <dd><?php echo esc_html( date( 'j F Y H:i', strtotime( $booking->created_at ) ) ); ?></dd>
                        </div>
                        <div class="mbs-dl-item">
                            <dt>HA Notified</dt>
                            <dd><?php echo $booking->ha_notified ? 'Yes' : '—'; ?></dd>
                        </div>
                        <?php if ( ! empty( $booking->series_id ) ) : ?>
                        <div class="mbs-dl-item">
                            <dt>Series</dt>
                            <dd><?php echo esc_html( $booking->series_id ); ?> (<?php echo (int) $series_count; ?> bookings)</dd>
                        </div>
                        <?php endif; ?>
                    </dl>
                </div>

                <!-- Edit mode (hidden by default) -->
                <div id="nms-edit-mode" class="mbs-card-body mbs-edit-mode" style="display:none;" data-single-edit-config="<?php echo esc_attr( wp_json_encode( $single_edit_config ) ); ?>">
                    <div class="mbs-edit-grid">
                        <div class="mbs-edit-field">
                            <label class="nms-edit-label" for="nms-edit-name">Name</label>
                            <input type="text" id="nms-edit-name" value="<?php echo esc_attr( $booking->name ); ?>" class="regular-text">
                        </div>
                        <div class="mbs-edit-field">
                            <label class="nms-edit-label" for="nms-edit-org">Organisation</label>
                            <input type="text" id="nms-edit-org" value="<?php echo esc_attr( $booking->organisation ); ?>" class="regular-text">
                        </div>
                        <div class="mbs-edit-field">
                            <label class="nms-edit-label" for="nms-edit-email">Email</label>
                            <input type="email" id="nms-edit-email" value="<?php echo esc_attr( $booking->email ); ?>" class="regular-text">
                        </div>
                        <div class="mbs-edit-field">
                            <label class="nms-edit-label" for="nms-edit-phone">Phone</label>
                            <input type="text" id="nms-edit-phone" value="<?php echo esc_attr( $booking->phone ); ?>" class="regular-text">
                        </div>
                        <div class="mbs-edit-field">
                            <label class="nms-edit-label" for="nms-edit-space">Space</label>
                            <?php if ( class_exists( 'MBS_Space_Selection' ) ) MBS_Space_Selection::selector( 'nms-edit-space', $booking->space ); ?>
                            <select id="nms-edit-space">
                                <?php if ( ! isset( $spaces[ $booking->space ] ) ) : ?><option value="<?php echo esc_attr( $booking->space ); ?>" selected><?php echo esc_html( $booking->space ); ?></option><?php endif; ?>
                                <?php foreach ( $spaces as $name => $info ) : ?>
                                <option value="<?php echo esc_attr( $name ); ?>" <?php selected( $booking->space, $name ); ?>><?php echo esc_html( $name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mbs-edit-field">
                            <label class="nms-edit-label" for="nms-edit-date">Start Date</label>
                            <input type="date" id="nms-edit-date" value="<?php echo esc_attr( $booking->booking_date ); ?>">
                        </div>
                        <div class="mbs-edit-field">
                            <label class="nms-edit-label" for="nms-edit-date-end">End Date</label>
                            <input type="date" id="nms-edit-date-end" value="<?php echo esc_attr( $booking->booking_date_end ?: $booking->booking_date ); ?>">
                            <small class="nms-muted">Same as start for single day</small>
                        </div>
                        <div class="mbs-edit-field">
                            <label class="nms-edit-label" for="nms-edit-start">Start Time</label>
                            <input type="time" id="nms-edit-start" value="<?php echo esc_attr( $booking->start_time ); ?>">
                        </div>
                        <div class="mbs-edit-field">
                            <label class="nms-edit-label" for="nms-edit-end">End Time</label>
                            <input type="time" id="nms-edit-end" value="<?php echo esc_attr( $booking->end_time ); ?>">
                        </div>
                        <div class="mbs-edit-field">
                            <label class="nms-edit-label" for="nms-edit-attendees">Attendees</label>
                            <input type="number" id="nms-edit-attendees" value="<?php echo esc_attr( $booking->attendees ); ?>" min="1">
                        </div>
                        <div class="mbs-edit-field">
                            <label class="nms-edit-label" for="nms-edit-allday">Booking Type</label>
                            <select id="nms-edit-allday">
                                <option value="0" <?php selected( $booking->all_day, 0 ); ?>>Specific hours</option>
                                <option value="1" <?php selected( $booking->all_day, 1 ); ?>>Full day</option>
                            </select>
                        </div>
                        <div class="mbs-edit-field">
                            <label class="nms-edit-label" for="nms-edit-kitchen">Kitchen</label>
                            <select id="nms-edit-kitchen">
                                <option value="0" <?php selected( $booking->kitchen, 0 ); ?>>No</option>
                                <option value="1" <?php selected( $booking->kitchen, 1 ); ?>>Yes (+&pound;<?php echo number_format( $kitchen_price, 2 ); ?>)</option>
                            </select>
                        </div>
                        <div class="mbs-edit-field">
                            <label class="nms-edit-label" for="nms-edit-scout">Scout Use</label>
                            <select id="nms-edit-scout">
                                <option value="0" <?php selected( $booking->scout_use ?? 0, 0 ); ?>>External hire (charged)</option>
                                <option value="1" <?php selected( $booking->scout_use ?? 0, 1 ); ?>>Scout use (free)</option>
                            </select>
                        </div>
                        <div class="mbs-edit-field mbs-edit-field--full">
                            <label class="nms-edit-label" for="nms-edit-purpose">Purpose</label>
                            <input type="text" id="nms-edit-purpose" value="<?php echo esc_attr( $booking->purpose ); ?>" class="regular-text">
                        </div>
                        <div class="mbs-edit-field mbs-edit-field--full">
                            <label class="nms-edit-label" for="nms-edit-notes">Booker Notes</label>
                            <textarea id="nms-edit-notes" rows="2"><?php echo esc_textarea( $booking->notes ); ?></textarea>
                        </div>
                        <div class="mbs-edit-field mbs-edit-field--full" data-payment-field="1">
                            <label class="nms-edit-label" for="nms-edit-address">Billing Address</label>
                            <textarea id="nms-edit-address" rows="2"><?php echo esc_textarea( $booking->address ); ?></textarea>
                        </div>
                    </div>

                    <!-- Cost preview -->
                    <div id="nms-edit-cost-preview" class="mbs-cost-preview" data-payment-field="1" <?php if ( ! MBS_Payment_Features::enabled() ) echo 'hidden'; ?>>
                        <div class="mbs-cost-preview__row">
                            <span>Original amount:</span>
                            <span>&pound;<?php echo number_format( (float) $booking->amount, 2 ); ?></span>
                        </div>
                        <div id="nms-edit-calc-row" class="mbs-cost-preview__row">
                            <span>Calculated amount:</span>
                            <strong id="nms-edit-new-amount">&pound;<?php echo number_format( (float) $booking->amount, 2 ); ?></strong>
                        </div>
                        <div id="nms-edit-cost-diff" class="mbs-cost-preview__diff" style="display:none;"></div>

                        <div class="mbs-cost-preview__override">
                            <label class="mbs-checkbox-label" for="nms-edit-custom-price">
                                <input type="checkbox" id="nms-edit-custom-price"> <strong>Override price manually</strong>
                            </label>
                            <div id="nms-custom-price-row" class="mbs-custom-price-row" style="display:none;">
                                <div class="mbs-custom-price-input">
                                    <span class="mbs-currency-prefix">&pound;</span>
                                    <label class="screen-reader-text" for="nms-edit-custom-amount">Custom amount in GBP</label>
                                    <input type="number" id="nms-edit-custom-amount" step="0.01" min="0" value="<?php echo number_format( (float) $booking->amount, 2, '.', '' ); ?>">
                                </div>
                                <p class="nms-muted mbs-help-text">Enter the exact amount to charge. This overrides the calculated rate.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Payment warning -->
                    <?php if ( $booking->status === 'paid' ) : ?>
                    <div class="mbs-edit-warning">
                        <strong>This booking has been paid.</strong> If the price changes, you'll need to collect or refund the difference manually.
                    </div>
                    <?php endif; ?>

                    <div class="mbs-edit-actions">
                        <button type="button" class="button button-primary" id="nms-save-edit" data-ref="<?php echo esc_attr( $booking->ref ); ?>">Save Changes</button>
                        <button type="button" class="button" id="nms-cancel-edit">Cancel</button>
                        <label class="mbs-checkbox-label mbs-edit-notify-label" for="nms-edit-notify">
                            <input type="checkbox" id="nms-edit-notify" checked> Notify booker of changes
                        </label>
                    </div>
                    <span id="nms-edit-msg" class="nms-settings-msg mbs-inline-status" role="status" aria-live="polite"></span>
                </div>
            </details>

            <!-- Payment Deadlines & Money Review (expanded when present) -->
            <?php if ( class_exists( 'MBS_Payment_Expiry' ) ) MBS_Payment_Expiry::render_booking( $booking ); ?>

            <!-- Admin Notes (Private) -->
            <details class="nms-card mbs-disclosure" open aria-labelledby="mbs-admin-notes-heading">
                <summary class="nms-card-header">
                    <h2 id="mbs-admin-notes-heading">Admin Notes (Private)</h2>
                </summary>
                <div class="mbs-card-body">
                    <label class="screen-reader-text" for="nms-admin-notes">Admin Notes (private)</label>
                    <textarea id="nms-admin-notes" class="mbs-admin-notes-input" rows="3" placeholder="Internal notes — not visible to the booker"><?php echo esc_textarea( $booking->admin_notes ?? '' ); ?></textarea>
                    <div class="mbs-panel-actions">
                        <button type="button" class="button button-small" id="nms-save-notes" data-ref="<?php echo esc_attr( $booking->ref ); ?>">Save Notes</button>
                        <span id="nms-notes-msg" class="nms-settings-msg" role="status" aria-live="polite"></span>
                    </div>
                </div>
            </details>

            <!-- Billing & Extras -->
            <div id="mbs-section-billing" class="mbs-billing-section">
                <?php MBS_Additional_Charges::render_related( $booking->ref ); ?>
                <?php if ( class_exists( 'MBS_Credit_Accounts' ) ) MBS_Credit_Accounts::render_booking( $booking ); ?>
            </div>

            <!-- Audit Log (<details> disclosure) -->
            <?php if ( ! empty( $audit_entries ) ) : ?>
            <details id="mbs-section-audit" class="nms-card mbs-disclosure">
                <summary class="nms-card-header">
                    <h2>Audit Log</h2>
                    <span class="mbs-card-badge"><?php echo count( $audit_entries ); ?></span>
                </summary>
                <div class="mbs-card-body mbs-audit-list">
                    <?php foreach ( $audit_entries as $entry ) : ?>
                    <div class="mbs-audit-row">
                        <div class="mbs-audit-row__main">
                            <strong><?php echo MBS_Audit_Log::action_label( $entry->action ); ?></strong>
                            <?php if ( $entry->details ) : ?><br><span class="nms-muted"><?php echo esc_html( $entry->details ); ?></span><?php endif; ?>
                        </div>
                        <div class="mbs-audit-row__meta nms-muted">
                            <?php echo esc_html( $entry->user_name ); ?><br>
                            <?php echo esc_html( date( 'j M Y H:i', strtotime( $entry->created_at ) ) ); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>
        </div>

        <!-- 4B. Operational Sidebar -->
        <aside class="mbs-single-sidebar" aria-label="Booking actions and operational status">
            <!-- Actions Card (Grouped) -->
            <details id="mbs-section-actions" class="nms-card mbs-disclosure nms-actions-card" open aria-labelledby="mbs-actions-heading">
                <summary class="nms-card-header"><h2 id="mbs-actions-heading">Actions</h2></summary>
                <div class="nms-action-list mbs-action-groups">
                    <!-- Workflow -->
                    <div class="mbs-action-group">
                        <h3 class="mbs-action-group__title">Workflow</h3>
                        <?php if ( $booking->status === 'pending' ) : ?>
                            <button type="button" class="button button-primary nms-btn-confirm" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-redirect="1">Confirm Booking</button>
                        <?php endif; ?>
                        <?php if ( in_array( $booking->status, array( 'cancelled', 'archived' ), true ) ) : ?>
                            <button type="button" class="button nms-btn-reopen" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-redirect="1" data-restore-status="confirmed">Restore to Confirmed</button>
                        <?php endif; ?>
                        <?php if ( ! in_array( $booking->status, array( 'cancelled', 'archived', 'paid', 'deposit_paid' ), true ) ) : ?>
                            <button type="button" class="button nms-btn-cancel" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-redirect="1">Cancel Booking</button>
                        <?php endif; ?>
                        <?php if ( in_array( $booking->status, array( 'confirmed', 'deposit_paid', 'paid', 'cancelled' ), true ) ) : ?>
                            <button type="button" class="button nms-btn-archive" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-redirect="1">Archive</button>
                        <?php endif; ?>
                    </div>

                    <!-- Billing & PDFs -->
                    <div class="mbs-action-group">
                        <h3 class="mbs-action-group__title">Billing &amp; Invoices</h3>
                        <?php if ( ( MBS_Payment_Features::enabled( 'manual' ) || MBS_Payment_Features::enabled( 'reminders' ) ) && ( $booking->status === 'confirmed' || $booking->status === 'deposit_paid' ) ) : ?>
                            <?php
                            $dep_settings = MBS_Bookings::get_deposit_settings();
                            $dep_amount   = MBS_Bookings::calculate_deposit( (float) $booking->amount );
                            $bal_amount   = (float) $booking->amount - (float) ( $booking->deposit_paid ?? 0 );
                            ?>
                            <?php if ( $uses_consolidated_billing ) : ?>
                                <div class="notice notice-warning inline"><p>Occurrence payment actions and reminders are suspended for this series. <?php if ( ! empty( $booking->series_id ) ) : ?><a href="<?php echo esc_url( $series_admin_url ); ?>">Manage its consolidated invoices</a>.<?php endif; ?></p></div>
                            <?php else : ?>
                                <?php if ( $booking->status === 'confirmed' && $dep_settings['enabled'] && ! MBS_Bookings::requires_full_payment( $booking->booking_date ) ) : ?>
                                    <button type="button" class="button mbs-btn-warning nms-btn-deposit-paid" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-redirect="1">Mark Deposit Paid (&pound;<?php echo number_format( $dep_amount, 2 ); ?>)</button>
                                <?php endif; ?>
                                <button type="button" class="button button-primary nms-btn-paid" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-redirect="1">Mark Fully Paid (&pound;<?php echo number_format( $bal_amount, 2 ); ?>)</button>
                                <?php if ( MBS_Payment_Features::enabled( 'reminders' ) ) : ?>
                                    <button type="button" class="button nms-btn-chase" data-ref="<?php echo esc_attr( $booking->ref ); ?>">Chase Payment</button>
                                <?php endif; ?>
                                <?php if ( $booking->chase_count > 0 ) : ?>
                                    <small class="nms-muted mbs-action-note"><?php echo esc_html( $booking->chase_count ); ?> chase(s) sent<?php if ( $booking->last_chased ) echo ' — last: ' . esc_html( date( 'j M H:i', strtotime( $booking->last_chased ) ) ); ?></small>
                                <?php endif; ?>
                                <?php if ( $booking->status === 'deposit_paid' ) : ?>
                                    <small class="nms-muted mbs-action-note">Deposit received: &pound;<?php echo number_format( (float) $booking->deposit_paid, 2 ); ?> — Balance outstanding: &pound;<?php echo number_format( (float) $booking->amount - (float) $booking->deposit_paid, 2 ); ?></small>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ( MBS_Payment_Features::enabled( 'manual' ) && $booking->status === 'paid' && ! $uses_consolidated_billing ) : ?>
                            <button type="button" class="button nms-btn-unpaid" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-redirect="1">Undo Paid</button>
                        <?php endif; ?>
                        <?php if ( MBS_Payment_Features::enabled( 'manual' ) && $booking->status === 'deposit_paid' && ! $uses_consolidated_billing ) : ?>
                            <button type="button" class="button nms-btn-undo-deposit" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-redirect="1">Undo Deposit</button>
                        <?php endif; ?>
                        <?php if ( $uses_consolidated_billing && MBS_Payment_Features::enabled( 'invoices' ) && $billing_treatment !== 'none' ) : ?>
                            <?php if ( $series_admin_url ) : ?><a href="<?php echo esc_url( $series_admin_url ); ?>" class="button">Manage consolidated invoices</a><?php endif; ?>
                        <?php elseif ( $invoice_document_id ) : ?>
                            <a href="<?php echo esc_url( $invoice_view_url ); ?>" class="button" target="_blank" rel="noopener">View issued invoice</a>
                            <a href="<?php echo esc_url( $invoice_pdf_url ); ?>" class="button" target="_blank" rel="noopener">Download PDF</a>
                        <?php elseif ( (float) $booking->amount <= 0 ) : ?>
                            <div class="notice notice-info inline"><p>No invoice is required for this free booking.</p></div>
                        <?php elseif ( $booking->status === 'pending' ) : ?>
                            <div class="notice notice-info inline"><p>The PDF invoice will be issued when this booking is confirmed.</p></div>
                        <?php else : ?>
                            <a href="?page=mathlin-booking&action=invoice&ref=<?php echo esc_attr( $booking->ref ); ?>" class="button">View legacy invoice preview</a>
                            <small class="nms-muted mbs-action-note">This historical booking has no immutable PDF snapshot.</small>
                        <?php endif; ?>
                    </div>

                    <!-- Communications -->
                    <?php
                    $can_send_access   = get_option( 'mbs_access_enabled', 0 ) && MBS_Access_Details::is_eligible_for_access( $booking );
                    $can_send_feedback = get_option( 'mbs_feedback_enabled', 0 ) && empty( $booking->scout_use ) && in_array( $booking->status, array( 'confirmed', 'deposit_paid', 'paid' ), true );
                    if ( $can_send_access || $can_send_feedback ) :
                    ?>
                    <div class="mbs-action-group">
                        <h3 class="mbs-action-group__title">Communications</h3>
                        <?php if ( $can_send_access ) : ?>
                            <button type="button" class="button nms-btn-resend-access" data-ref="<?php echo esc_attr( $booking->ref ); ?>">Send Access Details</button>
                            <?php if ( $booking->access_sent ) : ?>
                                <small class="nms-muted mbs-action-note">Access details previously sent</small>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ( $can_send_feedback ) : ?>
                            <button type="button" class="button nms-btn-send-feedback" data-ref="<?php echo esc_attr( $booking->ref ); ?>">Send Feedback Request</button>
                            <?php if ( ! empty( $booking->feedback_sent ) ) : ?>
                                <small class="nms-muted mbs-action-note">Feedback request previously sent</small>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Series -->
                    <?php if ( ! empty( $booking->series_id ) ) : ?>
                    <div class="mbs-action-group">
                        <h3 class="mbs-action-group__title">Series (<?php echo (int) $series_count; ?> bookings)</h3>
                        <?php if ( $booking->status === 'pending' ) : ?>
                            <button type="button" class="button button-primary nms-btn-series-status" data-series="<?php echo esc_attr( $booking->series_id ); ?>" data-status="confirmed">Review &amp; Approve</button>
                        <?php endif; ?>
                        <button type="button" class="button nms-btn-series-status" data-series="<?php echo esc_attr( $booking->series_id ); ?>" data-status="cancelled">Cancel Series</button>
                    </div>
                    <?php endif; ?>

                    <!-- Danger Zone -->
                    <?php if ( current_user_can( 'manage_options' ) ) : ?>
                    <div class="mbs-action-group mbs-action-group--danger">
                        <h3 class="mbs-action-group__title">Danger Zone</h3>
                        <button type="button" class="button nms-btn-delete" data-ref="<?php echo esc_attr( $booking->ref ); ?>">Delete Booking</button>
                    </div>
                    <?php endif; ?>
                </div>
            </details>

            <!-- Operations Anchor -->
            <div id="mbs-section-operations" class="mbs-operations-stack">
                <!-- Access Email Readiness (preserving Phase 2 diagnostics triggers) -->
                <details class="nms-card mbs-disclosure" open aria-labelledby="mbs-access-readiness-heading">
                    <summary class="nms-card-header">
                        <h2 id="mbs-access-readiness-heading">Access Email Readiness</h2>
                        <span class="mbs-card-badge"><?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $access_readiness['state'] ) ) ); ?></span>
                    </summary>
                    <div class="mbs-card-body">
                        <p><strong><?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $access_readiness['state'] ) ) ); ?></strong> — <?php echo esc_html( $access_readiness['action'] ); ?></p>
                        <?php if ( ! empty( $access_readiness['due_at'] ) ) : ?>
                            <p class="nms-muted">Delivery window opens: <?php echo esc_html( $access_readiness['due_at'] ); ?></p>
                        <?php endif; ?>
                        <div class="mbs-email-badge-group mbs-panel-actions">
                            <?php if ( $access_failed ) : ?>
                                <button type="button" class="mbs-email-badge mbs-email-badge--failed mbs-open-email-diagnostics"
                                        data-tab="transport" data-reference-type="booking" data-reference-id="<?php echo (int) $booking->id; ?>"
                                        data-context-label="<?php echo esc_attr( 'Booking ' . $booking->ref ); ?>">
                                    Access email failed (<?php echo (int) $latest_access->attempts; ?>/<?php echo (int) MBS_Email_Queue::MAX_ATTEMPTS; ?> attempts) — Inspect error &amp; resend
                                </button>
                            <?php elseif ( ! empty( $booking_mail['failed_count'] ) ) : ?>
                                <button type="button" class="mbs-email-badge mbs-email-badge--failed mbs-open-email-diagnostics"
                                        data-tab="transport" data-reference-type="booking" data-reference-id="<?php echo (int) $booking->id; ?>"
                                        data-context-label="<?php echo esc_attr( 'Booking ' . $booking->ref ); ?>">
                                    <?php echo (int) $booking_mail['failed_count']; ?> email failure(s) — Inspect error &amp; resend
                                </button>
                            <?php endif; ?>
                            <?php if ( ! empty( $booking_mail['pending_count'] ) ) : ?>
                                <button type="button" class="mbs-email-badge mbs-email-badge--pending mbs-open-email-diagnostics"
                                        data-tab="pending" data-reference-type="booking" data-reference-id="<?php echo (int) $booking->id; ?>"
                                        data-context-label="<?php echo esc_attr( 'Booking ' . $booking->ref ); ?>">
                                    <?php echo (int) $booking_mail['pending_count']; ?> email(s) queued — View queue
                                </button>
                            <?php endif; ?>
                            <button type="button" class="button button-small mbs-open-email-diagnostics"
                                    data-tab="<?php echo ! empty( $booking_mail['failed_count'] ) ? 'transport' : ( ! empty( $booking_mail['pending_count'] ) ? 'pending' : 'sent' ); ?>"
                                    data-reference-type="booking" data-reference-id="<?php echo (int) $booking->id; ?>"
                                    data-context-label="<?php echo esc_attr( 'Booking ' . $booking->ref ); ?>">
                                Email delivery log
                            </button>
                        </div>
                    </div>
                </details>

                <!-- Home Assistant Heating (expanded for active one-off Scout bookings) -->
                <details class="nms-card mbs-disclosure" open aria-labelledby="mbs-heating-heading">
                    <summary class="nms-card-header">
                        <h2 id="mbs-heating-heading">Home Assistant heating</h2>
                        <?php if ( $is_active_oneoff_scout ) : ?>
                            <span class="mbs-card-badge"><?php echo MBS_HomeAssistant::heating_required( $booking ) ? 'Opted in' : 'Off'; ?></span>
                        <?php endif; ?>
                    </summary>
                    <div class="mbs-card-body">
                        <?php if ( $is_active_oneoff_scout ) : ?>
                        <form id="mbs-booking-heating" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" method="post">
                            <input type="hidden" name="action" value="mbs_set_booking_heating">
                            <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'mbs_admin_nonce' ) ); ?>">
                            <input type="hidden" name="ref" value="<?php echo esc_attr( $booking->ref ); ?>">
                            <input type="hidden" name="expected_version" value="<?php echo esc_attr( MBS_HomeAssistant::heating_version( $booking ) ); ?>">
                            <label class="mbs-checkbox-label" for="mbs-heating-choice">
                                <input type="checkbox" id="mbs-heating-choice" <?php checked( MBS_HomeAssistant::heating_required( $booking ) ); ?> aria-describedby="mbs-heating-help">
                                <span>Heating required for this one-off Scout booking</span>
                            </label>
                            <p id="mbs-heating-help" class="nms-muted mbs-help-text">Off by default. Enabling requests heating only while the booking is approved. It does not change charges, access emails or existing Scout heating schedules. Saving is not proof of physical heating.</p>
                            <div class="mbs-panel-actions">
                                <button class="button button-primary" type="submit">Save heating preference</button>
                            </div>
                            <p id="mbs-heating-result" class="mbs-inline-status" role="status" aria-live="polite"></p>
                        </form>
                        <?php elseif ( ! empty( $booking->scout_use ) && ! empty( $booking->series_id ) ) : ?>
                            <p>Off — Scout series use their separately configured Home Assistant schedules. Individual one-off Scout bookings can opt in.</p>
                        <?php else : ?>
                            <p><?php echo empty( $booking->scout_use ) ? 'External hire — existing heating policy applies while approved.' : 'Inactive Scout booking — no heating request.'; ?></p>
                        <?php endif; ?>
                    </div>
                </details>

                <!-- Unified Hire Terms & Acceptance Card -->
                <details class="nms-card mbs-disclosure" open aria-labelledby="mbs-terms-heading">
                    <summary class="nms-card-header">
                        <h2 id="mbs-terms-heading">Hire Terms &amp; Acceptance</h2>
                        <span class="mbs-card-badge"><?php echo $terms_evidence ? 'Accepted' : 'Not recorded'; ?></span>
                    </summary>
                    <div class="mbs-card-body">
                        <?php MBS_Terms_Acceptance::render( $booking ); ?>
                        <?php if ( $terms_url ) : ?>
                        <p class="mbs-terms-link-row">
                            <a href="<?php echo esc_url( $terms_url ); ?>" target="_blank" rel="noopener" class="mbs-terms-link">View Terms &amp; Conditions of Hire</a>
                            <span class="nms-muted"> — applies to this booking</span>
                        </p>
                        <?php endif; ?>
                    </div>
                </details>

                <!-- Google Calendar Linkage -->
                <?php if ( class_exists( 'MBS_Google_Calendar' ) ) MBS_Google_Calendar::render_booking_card( $booking ); ?>
            </div>
        </aside>
    </div>
</div>
<?php include __DIR__ . '/partials/approval-modal.php'; ?>
