<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MBS_Invoice {

    public static function generate_html( $booking ) {
        $spaces      = MBS_Bookings::get_spaces();
        $space_info  = $spaces[ $booking->space ] ?? array( 'rate' => 0, 'unit' => 'hr' );
        $is_day_rate = $space_info['unit'] === 'day';

        if ( $is_day_rate ) {
            $qty_label  = '1 day';
            $unit_price = $space_info['rate'];
        } else {
            $start      = strtotime( $booking->start_time );
            $end        = strtotime( $booking->end_time );
            $hours      = $start && $end ? ceil( max( 0, ( $end - $start ) / 3600 ) ) : 0;
            $qty_label  = $hours . ' hour' . ( $hours !== 1 ? 's' : '' );
            $unit_price = $space_info['rate'];
        }

        $space_subtotal = $booking->amount - ( $booking->kitchen ? 10 : 0 );
        $issue_date     = date( 'j F Y', strtotime( $booking->created_at ) );
        $due_date       = date( 'j F Y', strtotime( $booking->created_at . ' +14 days' ) );
        $booking_date   = date( 'l j F Y', strtotime( $booking->booking_date ) );
        $time_str       = $is_day_rate ? 'Full day' : ( $booking->start_time . ' – ' . $booking->end_time );

        ob_start();
        ?>
        <div class="mbs-invoice" id="mbs-invoice-print">
            <div class="nms-inv-header">
                <div class="nms-inv-org">
                    <div class="nms-inv-logo">&#9884;</div>
                    <h2>Needham Market Scout Group</h2>
                    <p>Scout Hall, Crown St, Needham Market, IP6 8RY<br>
                    bookings@needhamscouts.uk &bull; 01449 797577<br>
                    Registered Charity No. 1038177</p>
                </div>
                <div class="nms-inv-meta">
                    <div class="nms-inv-number"><?php echo esc_html( $booking->invoice_number ); ?></div>
                    <p>Issue Date: <?php echo esc_html( $issue_date ); ?></p>
                    <p>Due Date: <strong><?php echo esc_html( $due_date ); ?></strong></p>
                    <p><span class="nms-status nms-status-<?php echo esc_attr( $booking->status ); ?>"><?php echo esc_html( ucfirst( $booking->status ) ); ?></span></p>
                </div>
            </div>

            <div class="nms-inv-parties">
                <div class="nms-inv-party">
                    <h4>From</h4>
                    <p><strong>Needham Market Scout Group</strong><br>Crown St<br>Needham Market<br>Suffolk, IP6 8RY</p>
                </div>
                <div class="nms-inv-party">
                    <h4>Bill To</h4>
                    <p><strong><?php echo esc_html( $booking->name ); ?></strong>
                    <?php if ( $booking->organisation ) : ?><br><?php echo esc_html( $booking->organisation ); ?><?php endif; ?>
                    <br><?php echo nl2br( esc_html( $booking->address ) ); ?>
                    <br><?php echo esc_html( $booking->email ); ?>
                    <br><?php echo esc_html( $booking->phone ); ?></p>
                </div>
            </div>

            <table class="nms-inv-table">
                <thead>
                    <tr><th>Description</th><th>Qty</th><th class="text-right">Unit Price</th><th class="text-right">Amount</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo esc_html( $booking->space ); ?> hire – <?php echo esc_html( $booking_date ); ?><br>
                            <small><?php echo esc_html( $time_str ); ?> &bull; <?php echo esc_html( $booking->purpose ); ?></small></td>
                        <td><?php echo esc_html( $qty_label ); ?></td>
                        <td class="text-right">&pound;<?php echo number_format( $unit_price, 2 ); ?></td>
                        <td class="text-right">&pound;<?php echo number_format( $space_subtotal, 2 ); ?></td>
                    </tr>
                    <?php if ( $booking->kitchen ) : ?>
                    <tr>
                        <td>Kitchen facilities add-on</td>
                        <td>1 session</td>
                        <td class="text-right">&pound;10.00</td>
                        <td class="text-right">&pound;10.00</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="nms-inv-totals">
                <div class="nms-inv-total-row"><span>Subtotal</span><span>&pound;<?php echo number_format( $booking->amount, 2 ); ?></span></div>
                <div class="nms-inv-total-row"><span>VAT (0% – Charity exempt)</span><span>&pound;0.00</span></div>
                <div class="nms-inv-total-row nms-inv-grand"><span>Total Due</span><span>&pound;<?php echo number_format( $booking->amount, 2 ); ?></span></div>
            </div>

            <div class="nms-inv-notes">
                <h5>Payment Details</h5>
                <p>Please make payment within 14 days quoting reference <strong><?php echo esc_html( $booking->invoice_number ); ?></strong>.<br>
                Bank Transfer: Sort Code <strong>12-34-56</strong> &bull; Account No. <strong>12345678</strong><br>
                Cheques payable to: <em>Needham Market Scout Group</em></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
