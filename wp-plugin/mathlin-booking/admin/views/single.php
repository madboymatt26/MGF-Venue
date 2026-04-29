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
                <?php if ( $booking->status !== 'cancelled' ) : ?>
                    <button class="button nms-btn-cancel" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-redirect="1">
                        ✗ Cancel Booking
                    </button>
                <?php endif; ?>
                <a href="?page=mathlin-booking&action=invoice&ref=<?php echo esc_attr( $booking->ref ); ?>" class="button">
                    🧾 View Invoice
                </a>
                <button class="button nms-btn-delete" data-ref="<?php echo esc_attr( $booking->ref ); ?>">
                    🗑 Delete Booking
                </button>
            </div>
        </div>
    </div>
</div>
