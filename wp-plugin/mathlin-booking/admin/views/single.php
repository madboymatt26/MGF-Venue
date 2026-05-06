<?php if ( ! defined( 'ABSPATH' ) ) exit;
$spaces = MBS_Bookings::get_spaces();
$is_daily = ! empty( $booking->all_day );
$time_str = $is_daily ? 'All day' : ( $booking->start_time . ' – ' . $booking->end_time );
$kitchen_price = MBS_Bookings::get_kitchen_price();
?>
<div class="wrap mbs-admin">
    <h1>
        <a href="?page=mathlin-booking" class="nms-back-link">&#8592; All Bookings</a>
        &nbsp; Booking <?php echo esc_html( $booking->ref ); ?>
    </h1>

    <div class="nms-single-layout">
        <!-- Details card -->
        <div class="nms-card">
            <div class="nms-card-header">
                <h2>Booking Details</h2>
                <div style="display:flex;gap:8px;align-items:center;">
                    <span class="nms-status nms-status-<?php echo esc_attr( $booking->status ); ?>"><?php echo esc_html( MBS_Bookings::status_label( $booking->status ) ); ?></span>
                    <button class="button button-small" id="nms-toggle-edit" style="background:rgba(255,255,255,0.2);color:#fff;border-color:rgba(255,255,255,0.4);">✏️ Edit</button>
                </div>
            </div>

            <!-- View mode -->
            <?php
            // Financial balance indicator — show if booking has been modified and payment status differs
            $amount_paid = 0;
            if ( MBS_Woo_Payment::is_available() ) {
                // Look up completed WooCommerce orders for this booking
                $woo_orders = wc_get_orders( array(
                    'meta_key'   => '_mbs_booking_ref',
                    'meta_value' => $booking->ref,
                    'status'     => array( 'wc-completed', 'wc-processing' ),
                    'limit'      => -1,
                ) );
                foreach ( $woo_orders as $woo_order ) {
                    $amount_paid += (float) $woo_order->get_total();
                    // Subtract any refunds
                    $amount_paid -= (float) $woo_order->get_total_refunded();
                }
            }
            // Also consider status-based payment: if status is 'paid' but no WooCommerce orders found,
            // assume the full original amount was paid (e.g. bank transfer marked manually)
            if ( $amount_paid <= 0 && $booking->status === 'paid' ) {
                $amount_paid = (float) $booking->amount;
            }

            $balance = (float) $booking->amount - $amount_paid;
            if ( $amount_paid > 0 && abs( $balance ) > 0.01 ) :
                if ( $balance > 0 ) : ?>
                    <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:12px 16px;margin:0 1.5rem 1rem;color:#991b1b;font-weight:bold;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <span>
                            ⚠️ Balance Due: &pound;<?php echo number_format( $balance, 2 ); ?>
                            <span style="font-weight:normal;font-size:13px;margin-left:8px;">(Paid: &pound;<?php echo number_format( $amount_paid, 2 ); ?> / Total: &pound;<?php echo number_format( $booking->amount, 2 ); ?>)</span>
                        </span>
                        <button class="button button-small nms-btn-mark-balance-paid" data-ref="<?php echo esc_attr( $booking->ref ); ?>" style="background:#2ecc71;color:#fff;border-color:#27ae60;">✓ Mark Balance Paid</button>
                    </div>
                <?php else : ?>
                    <div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:6px;padding:12px 16px;margin:0 1.5rem 1rem;color:#065f46;font-weight:bold;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <span>
                            💰 Refund / Credit Due: &pound;<?php echo number_format( abs( $balance ), 2 ); ?>
                            <span style="font-weight:normal;font-size:13px;margin-left:8px;">(Paid: &pound;<?php echo number_format( $amount_paid, 2 ); ?> / Total: &pound;<?php echo number_format( $booking->amount, 2 ); ?>)</span>
                        </span>
                        <button class="button button-small nms-btn-mark-refunded" data-ref="<?php echo esc_attr( $booking->ref ); ?>" style="background:#3498db;color:#fff;border-color:#2980b9;">✓ Mark Refunded</button>
                    </div>
                <?php endif;
            endif;
            ?>
            <div id="nms-view-mode" class="nms-detail-grid">
                <div class="nms-detail-item"><label>Reference</label><span><?php echo esc_html( $booking->ref ); ?></span></div>
                <div class="nms-detail-item"><label>Invoice No.</label><span><?php echo esc_html( $booking->invoice_number ); ?></span></div>
                <div class="nms-detail-item"><label>Name</label><span><?php echo esc_html( $booking->name ); ?></span></div>
                <div class="nms-detail-item"><label>Organisation</label><span><?php echo esc_html( $booking->organisation ?: '—' ); ?></span></div>
                <div class="nms-detail-item"><label>Email</label><span><a href="mailto:<?php echo esc_attr( $booking->email ); ?>"><?php echo esc_html( $booking->email ); ?></a></span></div>
                <div class="nms-detail-item"><label>Phone</label><span><?php echo esc_html( $booking->phone ); ?></span></div>
                <div class="nms-detail-item"><label>Space</label><span><?php echo esc_html( $booking->space ); ?></span></div>
                <div class="nms-detail-item"><label>Date</label><span><?php echo esc_html( date( 'l j F Y', strtotime( $booking->booking_date ) ) ); ?></span></div>
                <div class="nms-detail-item"><label>Time</label><span><?php echo esc_html( $time_str ); ?></span></div>
                <div class="nms-detail-item"><label>Attendees</label><span><?php echo esc_html( $booking->attendees ); ?></span></div>
                <div class="nms-detail-item"><label>Kitchen</label><span><?php echo $booking->kitchen ? 'Yes' : 'No'; ?></span></div>
                <div class="nms-detail-item"><label>Scout Use</label><span><?php echo ! empty( $booking->scout_use ) ? '⚜️ Yes (no charge)' : 'No (external hire)'; ?></span></div>
                <div class="nms-detail-item"><label>Amount</label><span><strong>&pound;<?php echo number_format( $booking->amount, 2 ); ?></strong></span></div>
                <div class="nms-detail-item nms-detail-full"><label>Purpose</label><span><?php echo esc_html( $booking->purpose ); ?></span></div>
                <?php if ( $booking->notes ) : ?>
                <div class="nms-detail-item nms-detail-full"><label>Notes</label><span><?php echo nl2br( esc_html( $booking->notes ) ); ?></span></div>
                <?php endif; ?>
                <div class="nms-detail-item nms-detail-full"><label>Billing Address</label><span><?php echo nl2br( esc_html( $booking->address ) ); ?></span></div>
                <?php MBS_Custom_Fields::render_admin_display( $booking ); ?>
                <div class="nms-detail-item"><label>Submitted</label><span><?php echo esc_html( date( 'j F Y H:i', strtotime( $booking->created_at ) ) ); ?></span></div>
                <div class="nms-detail-item"><label>HA Notified</label><span><?php echo $booking->ha_notified ? '✅ Yes' : '—'; ?></span></div>
                <?php if ( ! empty( $booking->series_id ) ) : ?>
                <div class="nms-detail-item"><label>Series</label><span><?php echo esc_html( $booking->series_id ); ?> (<?php echo count( MBS_Bookings::get_series( $booking->series_id ) ); ?> bookings)</span></div>
                <?php endif; ?>
            </div>

            <!-- Edit mode (hidden by default) -->
            <div id="nms-edit-mode" style="display:none;padding:1rem 1.5rem;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div><label class="nms-edit-label">Name</label><input type="text" id="nms-edit-name" value="<?php echo esc_attr( $booking->name ); ?>" class="regular-text" style="width:100%;"></div>
                    <div><label class="nms-edit-label">Organisation</label><input type="text" id="nms-edit-org" value="<?php echo esc_attr( $booking->organisation ); ?>" class="regular-text" style="width:100%;"></div>
                    <div><label class="nms-edit-label">Email</label><input type="email" id="nms-edit-email" value="<?php echo esc_attr( $booking->email ); ?>" class="regular-text" style="width:100%;"></div>
                    <div><label class="nms-edit-label">Phone</label><input type="text" id="nms-edit-phone" value="<?php echo esc_attr( $booking->phone ); ?>" class="regular-text" style="width:100%;"></div>
                    <div>
                        <label class="nms-edit-label">Space</label>
                        <select id="nms-edit-space" style="width:100%;">
                            <?php foreach ( $spaces as $name => $info ) : ?>
                            <option value="<?php echo esc_attr( $name ); ?>" <?php selected( $booking->space, $name ); ?>><?php echo esc_html( $name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label class="nms-edit-label">Start Date</label><input type="date" id="nms-edit-date" value="<?php echo esc_attr( $booking->booking_date ); ?>" style="width:100%;"></div>
                    <div><label class="nms-edit-label">End Date</label><input type="date" id="nms-edit-date-end" value="<?php echo esc_attr( $booking->booking_date_end ?: $booking->booking_date ); ?>" style="width:100%;"><small class="nms-muted">Same as start for single day</small></div>
                    <div><label class="nms-edit-label">Start Time</label><input type="time" id="nms-edit-start" value="<?php echo esc_attr( $booking->start_time ); ?>" style="width:100%;"></div>
                    <div><label class="nms-edit-label">End Time</label><input type="time" id="nms-edit-end" value="<?php echo esc_attr( $booking->end_time ); ?>" style="width:100%;"></div>
                    <div><label class="nms-edit-label">Attendees</label><input type="number" id="nms-edit-attendees" value="<?php echo esc_attr( $booking->attendees ); ?>" min="1" style="width:100%;"></div>
                    <div>
                        <label class="nms-edit-label">Booking Type</label>
                        <select id="nms-edit-allday" style="width:100%;">
                            <option value="0" <?php selected( $booking->all_day, 0 ); ?>>Specific hours</option>
                            <option value="1" <?php selected( $booking->all_day, 1 ); ?>>Full day</option>
                        </select>
                    </div>
                    <div>
                        <label class="nms-edit-label">Kitchen</label>
                        <select id="nms-edit-kitchen" style="width:100%;">
                            <option value="0" <?php selected( $booking->kitchen, 0 ); ?>>No</option>
                            <option value="1" <?php selected( $booking->kitchen, 1 ); ?>>Yes (+&pound;<?php echo number_format( $kitchen_price, 0 ); ?>)</option>
                        </select>
                    </div>
                    <div>
                        <label class="nms-edit-label">Scout Use</label>
                        <select id="nms-edit-scout" style="width:100%;">
                            <option value="0" <?php selected( $booking->scout_use ?? 0, 0 ); ?>>External hire (charged)</option>
                            <option value="1" <?php selected( $booking->scout_use ?? 0, 1 ); ?>>Scout use (free)</option>
                        </select>
                    </div>
                    <div class="nms-detail-full"><label class="nms-edit-label">Purpose</label><input type="text" id="nms-edit-purpose" value="<?php echo esc_attr( $booking->purpose ); ?>" class="regular-text" style="width:100%;"></div>
                    <div class="nms-detail-full"><label class="nms-edit-label">Booker Notes</label><textarea id="nms-edit-notes" rows="2" style="width:100%;"><?php echo esc_textarea( $booking->notes ); ?></textarea></div>
                    <div class="nms-detail-full"><label class="nms-edit-label">Billing Address</label><textarea id="nms-edit-address" rows="2" style="width:100%;"><?php echo esc_textarea( $booking->address ); ?></textarea></div>
                </div>

                <!-- Cost preview -->
                <div id="nms-edit-cost-preview" style="margin-top:16px;padding:12px 16px;background:#f9f7ff;border-radius:8px;border:1px solid #e0d0f0;">
                    <div style="display:flex;justify-content:space-between;font-size:0.9rem;">
                        <span>Original amount:</span>
                        <span>&pound;<?php echo number_format( $booking->amount, 2 ); ?></span>
                    </div>
                    <div id="nms-edit-calc-row" style="display:flex;justify-content:space-between;font-size:0.9rem;margin-top:4px;">
                        <span>Calculated amount:</span>
                        <strong id="nms-edit-new-amount">&pound;<?php echo number_format( $booking->amount, 2 ); ?></strong>
                    </div>
                    <div id="nms-edit-cost-diff" style="display:none;margin-top:8px;padding:8px 12px;border-radius:6px;font-size:0.85rem;font-weight:600;"></div>

                    <!-- Custom price override -->
                    <div style="margin-top:12px;padding-top:12px;border-top:1px solid #e0d0f0;">
                        <label style="display:flex;align-items:center;gap:6px;font-size:0.85rem;cursor:pointer;">
                            <input type="checkbox" id="nms-edit-custom-price"> <strong>Override price manually</strong>
                        </label>
                        <div id="nms-custom-price-row" style="display:none;margin-top:8px;">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span style="font-size:1.1rem;font-weight:600;">&pound;</span>
                                <input type="number" id="nms-edit-custom-amount" step="0.01" min="0" value="<?php echo number_format( $booking->amount, 2, '.', '' ); ?>" style="width:120px;padding:6px 10px;border:1.5px solid #7413DC;border-radius:6px;font-size:1rem;font-weight:700;">
                            </div>
                            <p style="font-size:0.75rem;color:#6b7280;margin:4px 0 0;">Enter the exact amount to charge. This overrides the calculated rate.</p>
                        </div>
                    </div>
                </div>

                <!-- Payment warning -->
                <?php if ( $booking->status === 'paid' ) : ?>
                <div style="margin-top:8px;padding:10px 14px;background:#fff3cd;border-radius:6px;border:1px solid #ffc107;font-size:0.85rem;color:#856404;">
                    ⚠️ <strong>This booking has been paid.</strong> If the price changes, you'll need to collect or refund the difference manually.
                </div>
                <?php endif; ?>

                <div style="margin-top:16px;display:flex;gap:8px;">
                    <button class="button button-primary" id="nms-save-edit" data-ref="<?php echo esc_attr( $booking->ref ); ?>">💾 Save Changes</button>
                    <button class="button" id="nms-cancel-edit">Cancel</button>
                    <label style="display:flex;align-items:center;gap:6px;font-size:0.85rem;margin-left:auto;">
                        <input type="checkbox" id="nms-edit-notify" checked> Notify booker of changes
                    </label>
                </div>
                <span id="nms-edit-msg" class="nms-settings-msg" style="display:block;margin-top:8px;"></span>
            </div>

            <!-- Admin Notes -->
            <div style="padding:0 1.5rem 1.5rem;">
                <label style="display:block;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);margin-bottom:4px;">Admin Notes (private)</label>
                <textarea id="nms-admin-notes" rows="3" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:6px;font-family:inherit;font-size:0.875rem;" placeholder="Internal notes — not visible to the booker"><?php echo esc_textarea( $booking->admin_notes ?? '' ); ?></textarea>
                <button class="button button-small" id="nms-save-notes" data-ref="<?php echo esc_attr( $booking->ref ); ?>" style="margin-top:6px;">Save Notes</button>
                <span id="nms-notes-msg" class="nms-settings-msg" style="margin-left:8px;"></span>
            </div>
        </div>

        <!-- Actions card -->
        <div class="nms-card nms-actions-card">
            <div class="nms-card-header"><h2>Actions</h2></div>
            <div class="nms-action-list">
                <?php if ( $booking->status === 'pending' ) : ?>
                    <button class="button button-primary nms-btn-confirm" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-redirect="1">✓ Confirm Booking</button>
                <?php endif; ?>
                <?php if ( $booking->status === 'confirmed' || $booking->status === 'deposit_paid' ) : ?>
                    <button class="button button-primary nms-btn-paid" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-redirect="1">💰 Mark as Paid</button>
                    <button class="button nms-btn-chase" data-ref="<?php echo esc_attr( $booking->ref ); ?>">📧 Chase Payment</button>
                    <?php if ( $booking->chase_count > 0 ) : ?>
                        <small class="nms-muted" style="display:block;margin-top:4px;"><?php echo esc_html( $booking->chase_count ); ?> chase(s) sent<?php if ( $booking->last_chased ) echo ' — last: ' . esc_html( date( 'j M H:i', strtotime( $booking->last_chased ) ) ); ?></small>
                    <?php endif; ?>
                    <?php if ( $booking->status === 'deposit_paid' ) : ?>
                        <small class="nms-muted" style="display:block;margin-top:4px;">Deposit received: &pound;<?php echo number_format( (float) $booking->deposit_paid, 2 ); ?> — Balance outstanding: &pound;<?php echo number_format( (float) $booking->amount - (float) $booking->deposit_paid, 2 ); ?></small>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ( $booking->status === 'paid' ) : ?>
                    <button class="button nms-btn-unpaid" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-redirect="1">↩ Undo Paid</button>
                <?php endif; ?>
                <?php if ( ! in_array( $booking->status, array( 'cancelled', 'archived', 'paid', 'deposit_paid' ) ) ) : ?>
                    <button class="button nms-btn-cancel" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-redirect="1">✗ Cancel Booking</button>
                <?php endif; ?>
                <?php if ( $booking->status === 'cancelled' ) : ?>
                    <button class="button nms-btn-reopen" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-redirect="1">↩ Reopen Booking</button>
                <?php endif; ?>
                <?php if ( in_array( $booking->status, array( 'confirmed', 'deposit_paid', 'paid', 'cancelled' ) ) ) : ?>
                    <button class="button nms-btn-archive" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-redirect="1">📦 Archive</button>
                <?php endif; ?>
                <a href="?page=mathlin-booking&action=invoice&ref=<?php echo esc_attr( $booking->ref ); ?>" class="button">🧾 View Invoice</a>
                <?php if ( current_user_can( 'manage_options' ) ) : ?>
                <button class="button nms-btn-delete" data-ref="<?php echo esc_attr( $booking->ref ); ?>">🗑 Delete Booking</button>
                <?php endif; ?>
                <?php if ( ! empty( $booking->series_id ) ) : ?>
                <hr style="margin:0.5rem 0;border:none;border-top:1px solid var(--border);">
                <p style="font-size:0.8rem;color:var(--text-muted);margin:0 0 0.5rem;">Series (<?php echo count( MBS_Bookings::get_series( $booking->series_id ) ); ?> bookings):</p>
                <?php if ( $booking->status === 'pending' ) : ?>
                    <button class="button button-primary nms-btn-series-status" data-series="<?php echo esc_attr( $booking->series_id ); ?>" data-status="confirmed">✓ Confirm Series</button>
                <?php endif; ?>
                <button class="button nms-btn-series-status" data-series="<?php echo esc_attr( $booking->series_id ); ?>" data-status="cancelled">✗ Cancel Series</button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Terms & Conditions reference -->
        <?php
        $terms_page_id = (int) get_option( 'mbs_terms_page_id', 0 );
        $terms_url = '';
        if ( $terms_page_id && get_post( $terms_page_id ) ) {
            $terms_url = get_permalink( $terms_page_id );
        } else {
            $tp = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 's' => 'mathlin_terms', 'numberposts' => 1 ) );
            if ( ! empty( $tp ) ) $terms_url = get_permalink( $tp[0]->ID );
        }
        if ( $terms_url ) : ?>
        <div class="nms-card" style="margin-top:0;">
            <div style="padding:12px 1.5rem;background:#f5f0ff;font-size:0.85rem;">
                📋 <a href="<?php echo esc_url( $terms_url ); ?>" target="_blank" style="color:#7413DC;font-weight:600;">View Terms &amp; Conditions of Hire</a>
                <span class="nms-muted"> — applies to this booking</span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Audit Log -->
    <?php $audit_entries = MBS_Audit_Log::get_for_booking( $booking->ref ); ?>
    <?php if ( ! empty( $audit_entries ) ) : ?>
    <div class="nms-card" style="margin-top:0;">
        <div class="nms-card-header"><h2>📋 Audit Log</h2></div>
        <div style="padding:1rem 1.5rem;max-height:300px;overflow-y:auto;">
            <?php foreach ( $audit_entries as $entry ) : ?>
            <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:6px 0;border-bottom:1px solid var(--border);font-size:0.8rem;">
                <div>
                    <strong><?php echo MBS_Audit_Log::action_label( $entry->action ); ?></strong>
                    <?php if ( $entry->details ) : ?><br><span style="color:var(--text-muted);"><?php echo esc_html( $entry->details ); ?></span><?php endif; ?>
                </div>
                <div style="text-align:right;white-space:nowrap;color:var(--text-muted);font-size:0.75rem;">
                    <?php echo esc_html( $entry->user_name ); ?><br>
                    <?php echo esc_html( date( 'j M Y H:i', strtotime( $entry->created_at ) ) ); ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.nms-edit-label { display:block;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);margin-bottom:3px; }
</style>

<script>
jQuery(function($) {
    var spacesData = <?php echo wp_json_encode( $spaces ); ?>;
    var kitchenPrice = <?php echo (float) $kitchen_price; ?>;
    var originalAmount = <?php echo (float) $booking->amount; ?>;

    // Toggle edit mode
    $('#nms-toggle-edit').on('click', function() {
        $('#nms-view-mode').hide();
        $('#nms-edit-mode').show();
        $(this).hide();
        recalcEditCost();
    });
    $('#nms-cancel-edit').on('click', function() {
        $('#nms-view-mode').show();
        $('#nms-edit-mode').hide();
        $('#nms-toggle-edit').show();
    });

    // Custom price override toggle
    $('#nms-edit-custom-price').on('change', function() {
        var isCustom = $(this).is(':checked');
        $('#nms-custom-price-row').toggle(isCustom);
        $('#nms-edit-calc-row').css('opacity', isCustom ? 0.4 : 1);
        updateCostDiff();
    });
    $('#nms-edit-custom-amount').on('input', updateCostDiff);

    function updateCostDiff() {
        var isCustom = $('#nms-edit-custom-price').is(':checked');
        var finalAmount = isCustom ? parseFloat($('#nms-edit-custom-amount').val()) || 0 : parseFloat($('#nms-edit-new-amount').text().replace('£', '')) || 0;
        var diff = finalAmount - originalAmount;
        var $diffEl = $('#nms-edit-cost-diff');
        if (Math.abs(diff) > 0.01) {
            $diffEl.show();
            if (diff > 0) {
                $diffEl.css({ background: '#fee2e2', color: '#991b1b' }).text('⬆ Price increased by £' + diff.toFixed(2));
            } else {
                $diffEl.css({ background: '#d1fae5', color: '#065f46' }).text('⬇ Price decreased by £' + Math.abs(diff).toFixed(2));
            }
        } else {
            $diffEl.hide();
        }
    }

    // Live cost recalculation
    $('#nms-edit-space, #nms-edit-start, #nms-edit-end, #nms-edit-kitchen, #nms-edit-allday, #nms-edit-scout, #nms-edit-date, #nms-edit-date-end').on('change', recalcEditCost);

    function recalcEditCost() {
        var space   = $('#nms-edit-space').val();
        var start   = $('#nms-edit-start').val();
        var end     = $('#nms-edit-end').val();
        var kitchen = $('#nms-edit-kitchen').val() === '1';
        var allDay  = $('#nms-edit-allday').val() === '1';
        var scout   = $('#nms-edit-scout').val() === '1';
        var info    = spacesData[space];

        // Calculate number of days for multi-day bookings
        var dateFrom = $('#nms-edit-date').val();
        var dateTo   = $('#nms-edit-date-end').val() || dateFrom;
        var numDays  = 1;
        if (dateFrom && dateTo) {
            var diff = (new Date(dateTo + 'T00:00:00') - new Date(dateFrom + 'T00:00:00')) / 86400000;
            numDays = Math.max(1, Math.round(diff) + 1);
        }

        var cost = 0;
        if (scout) {
            cost = 0;
        } else if (info) {
            var rateHourly = parseFloat(info.rate_hourly || 0);
            var rateDaily  = parseFloat(info.rate_daily || 0);
            if (allDay) {
                cost = rateDaily * numDays;
            } else if (start && end) {
                var sh = parseInt(start.split(':')[0]) * 60 + parseInt(start.split(':')[1]);
                var eh = parseInt(end.split(':')[0]) * 60 + parseInt(end.split(':')[1]);
                var mins = eh - sh;
                if (mins <= 0) mins += 1440; // QA-001: midnight spanning
                var hrs = Math.ceil(Math.max(0, mins / 60));
                // QA-003 + overnight fix: single continuous block if overnight + 2-day span
                var effectiveDays = numDays;
                if (mins !== (eh - sh) && numDays === 2) effectiveDays = 1;
                cost = hrs * rateHourly * effectiveDays; // QA-003: multi-day
            }
            if (kitchen) cost += kitchenPrice;
        }

        // Toggle time fields based on all-day
        if (allDay) {
            $('#nms-edit-start, #nms-edit-end').css('opacity', '0.4').prop('disabled', true);
        } else {
            $('#nms-edit-start, #nms-edit-end').css('opacity', '1').prop('disabled', false);
        }

        $('#nms-edit-new-amount').text('£' + cost.toFixed(2));
        updateCostDiff();
    }

    // Save edits
    $('#nms-save-edit').on('click', function() {
        var $btn = $(this);
        var $msg = $('#nms-edit-msg');
        $btn.prop('disabled', true).text('Saving…');

        $.post(MBS_Admin.ajax_url, {
            action:       'mbs_edit_booking',
            nonce:        MBS_Admin.nonce,
            ref:          $btn.data('ref'),
            name:         $('#nms-edit-name').val(),
            organisation: $('#nms-edit-org').val(),
            email:        $('#nms-edit-email').val(),
            phone:        $('#nms-edit-phone').val(),
            space:        $('#nms-edit-space').val(),
            booking_date: $('#nms-edit-date').val(),
            booking_date_end: $('#nms-edit-date-end').val(),
            start_time:   $('#nms-edit-start').val(),
            end_time:     $('#nms-edit-end').val(),
            attendees:    $('#nms-edit-attendees').val(),
            all_day:      $('#nms-edit-allday').val(),
            kitchen:      $('#nms-edit-kitchen').val(),
            scout_use:    $('#nms-edit-scout').val(),
            purpose:      $('#nms-edit-purpose').val(),
            notes:        $('#nms-edit-notes').val(),
            address:      $('#nms-edit-address').val(),
            notify:       $('#nms-edit-notify').is(':checked') ? 1 : 0,
            custom_price: $('#nms-edit-custom-price').is(':checked') ? 1 : 0,
            custom_amount: $('#nms-edit-custom-amount').val()
        }, function(res) {
            $btn.prop('disabled', false).text('💾 Save Changes');
            if (res.success) {
                $msg.text('✓ Booking updated').css('color', '#46b450');
                setTimeout(function() { location.reload(); }, 1000);
            } else {
                $msg.text('✗ ' + (res.data || 'Error saving')).css('color', '#dc3232');
            }
        });
    });
});
</script>
