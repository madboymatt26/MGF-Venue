<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
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
                <span class="nms-status nms-status-<?php echo esc_attr( $booking->status ); ?>"><?php echo esc_html( ucfirst( $booking->status ) ); ?></span>
            </div>
            <div class="nms-detail-grid">
                <div class="nms-detail-item"><label>Reference</label><span><?php echo esc_html( $booking->ref ); ?></span></div>
                <div class="nms-detail-item"><label>Invoice No.</label><span><?php echo esc_html( $booking->invoice_number ); ?></span></div>
                <div class="nms-detail-item"><label>Name</label><span><?php echo esc_html( $booking->name ); ?></span></div>
                <div class="nms-detail-item"><label>Organisation</label><span><?php echo esc_html( $booking->organisation ?: '—' ); ?></span></div>
                <div class="nms-detail-item"><label>Email</label><span><a href="mailto:<?php echo esc_attr( $booking->email ); ?>"><?php echo esc_html( $booking->email ); ?></a></span></div>
                <div class="nms-detail-item"><label>Phone</label><span><?php echo esc_html( $booking->phone ); ?></span></div>
                <div class="nms-detail-item"><label>Space</label><span><?php echo esc_html( $booking->space ); ?></span></div>
                <div class="nms-detail-item"><label>Date</label><span><?php echo esc_html( date( 'l j F Y', strtotime( $booking->booking_date ) ) ); ?></span></div>
                <div class="nms-detail-item"><label>Time</label><span><?php echo $booking->space === 'Outdoor Area' ? 'All day' : esc_html( $booking->start_time . ' – ' . $booking->end_time ); ?></span></div>
                <div class="nms-detail-item"><label>Attendees</label><span><?php echo esc_html( $booking->attendees ); ?></span></div>
                <div class="nms-detail-item"><label>Kitchen</label><span><?php echo $booking->kitchen ? 'Yes' : 'No'; ?></span></div>
                <div class="nms-detail-item"><label>Amount</label><span><strong>&pound;<?php echo number_format( $booking->amount, 2 ); ?></strong></span></div>
                <div class="nms-detail-item nms-detail-full"><label>Purpose</label><span><?php echo esc_html( $booking->purpose ); ?></span></div>
                <?php if ( $booking->notes ) : ?>
                <div class="nms-detail-item nms-detail-full"><label>Notes</label><span><?php echo nl2br( esc_html( $booking->notes ) ); ?></span></div>
                <?php endif; ?>
                <div class="nms-detail-item nms-detail-full"><label>Billing Address</label><span><?php echo nl2br( esc_html( $booking->address ) ); ?></span></div>
                <div class="nms-detail-item"><label>Submitted</label><span><?php echo esc_html( date( 'j F Y H:i', strtotime( $booking->created_at ) ) ); ?></span></div>
                <div class="nms-detail-item"><label>HA Notified</label><span><?php echo $booking->ha_notified ? '✅ Yes' : '—'; ?></span></div>
                <?php if ( ! empty( $booking->series_id ) ) : ?>
                <div class="nms-detail-item"><label>Series</label><span>
                    <?php echo esc_html( $booking->series_id ); ?>
                    <?php
                    $series = MBS_Bookings::get_series( $booking->series_id );
                    echo ' (' . count( $series ) . ' bookings in series)';
                    ?>
                </span></div>
                <?php endif; ?>
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
                    <button class="button button-primary nms-btn-confirm" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-redirect="1">
                        ✓ Confirm Booking
                    </button>
                <?php endif; ?>
                <?php if ( $booking->status === 'confirmed' ) : ?>
                    <button class="button button-primary nms-btn-paid" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-redirect="1">
                        💰 Mark as Paid
                    </button>
                <?php endif; ?>
                <?php if ( $booking->status === 'paid' ) : ?>
                    <button class="button nms-btn-unpaid" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-redirect="1">
                        ↩ Undo Paid
                    </button>
                <?php endif; ?>
                <?php if ( $booking->status !== 'cancelled' && $booking->status !== 'archived' && $booking->status !== 'paid' ) : ?>
                    <button class="button nms-btn-cancel" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-redirect="1">
                        ✗ Cancel Booking
                    </button>
                <?php endif; ?>
                <?php if ( $booking->status === 'cancelled' ) : ?>
                    <button class="button nms-btn-reopen" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-redirect="1">
                        ↩ Reopen Booking
                    </button>
                <?php endif; ?>
                <?php if ( in_array( $booking->status, array( 'confirmed', 'paid', 'cancelled' ) ) ) : ?>
                    <button class="button nms-btn-archive" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-redirect="1">
                        📦 Archive
                    </button>
                <?php endif; ?>
                <a href="?page=mathlin-booking&action=invoice&ref=<?php echo esc_attr( $booking->ref ); ?>" class="button">
                    🧾 View Invoice
                </a>
                <button class="button nms-btn-delete" data-ref="<?php echo esc_attr( $booking->ref ); ?>">
                    🗑 Delete Booking
                </button>
                <?php if ( ! empty( $booking->series_id ) ) : ?>
                <hr style="margin:0.5rem 0;border:none;border-top:1px solid var(--border);">
                <p style="font-size:0.8rem;color:var(--text-muted);margin:0 0 0.5rem;">Series Actions (affects all <?php echo count( MBS_Bookings::get_series( $booking->series_id ) ); ?> bookings):</p>
                <?php if ( $booking->status === 'pending' ) : ?>
                    <button class="button button-primary nms-btn-series-status" data-series="<?php echo esc_attr( $booking->series_id ); ?>" data-status="confirmed">
                        ✓ Confirm Entire Series
                    </button>
                <?php endif; ?>
                <button class="button nms-btn-series-status" data-series="<?php echo esc_attr( $booking->series_id ); ?>" data-status="cancelled">
                    ✗ Cancel Entire Series
                </button>
                <?php endif; ?>
            </div>
        </div>
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
                    <?php if ( $entry->details ) : ?>
                        <br><span style="color:var(--text-muted);"><?php echo esc_html( $entry->details ); ?></span>
                    <?php endif; ?>
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
