<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap mbs-admin">
    <h1>
        <a href="?page=mathlin-booking&action=view&ref=<?php echo esc_attr( $booking->ref ); ?>" class="nms-back-link">&#8592; Back to Booking</a>
        &nbsp; Invoice <?php echo esc_html( $booking->invoice_number ); ?>
    </h1>

    <div class="nms-invoice-actions no-print">
        <button onclick="window.print()" class="button button-primary">🖨️ Print / Save as PDF</button>
        <a href="?page=mathlin-booking" class="button">Back to All Bookings</a>
    </div>

    <?php echo MBS_Invoice::generate_html( $booking ); ?>
</div>
