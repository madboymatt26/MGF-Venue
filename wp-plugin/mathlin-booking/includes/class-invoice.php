<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MBS_Invoice {

    /**
     * Generate invoice HTML for display in the admin dashboard.
     */
    public static function generate_html( $booking ) {
        $spaces      = MBS_Bookings::get_spaces();
        $space_info  = $spaces[ $booking->space ] ?? array( 'rate' => 0, 'unit' => 'hr' );
        $is_day_rate = $space_info['unit'] === 'day';
        $kitchen_price = MBS_Bookings::get_kitchen_price();

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

        $space_subtotal = $booking->amount - ( $booking->kitchen ? $kitchen_price : 0 );
        $issue_date     = date( 'j F Y', strtotime( $booking->created_at ) );
        $bank           = MBS_Bookings::get_bank_details();
        $due_date       = date( 'j F Y', strtotime( $booking->created_at . ' +' . $bank['payment_days'] . ' days' ) );
        $booking_date   = date( 'l j F Y', strtotime( $booking->booking_date ) );
        $time_str       = $is_day_rate ? 'Full day' : ( $booking->start_time . ' – ' . $booking->end_time );

        $org            = MBS_Email_Templates::get_org_settings();
        $org_name       = $org['name'] ?? 'Needham Market Scout Group';
        $org_address    = $org['address'] ?? '';
        $org_phone      = $org['phone'] ?? '';
        $org_charity    = $org['charity_number'] ?? '';
        $admin_email    = MBS_Bookings::get_admin_email();

        // Split address into lines for the FROM section
        $address_parts = array_filter( array_map( 'trim', preg_split( '/[,\n]/', $org_address ) ) );

        ob_start();
        ?>
        <div class="mbs-invoice" id="mbs-invoice-print">
            <div class="nms-inv-header">
                <div class="nms-inv-org">
                    <div class="nms-inv-logo">&#9884;</div>
                    <h2><?php echo esc_html( $org_name ); ?></h2>
                    <p><?php echo esc_html( $org_address ); ?><br>
                    <?php echo esc_html( $admin_email ); ?><?php if ( $org_phone ) : ?> &bull; <?php echo esc_html( $org_phone ); ?><?php endif; ?>
                    <?php if ( $org_charity ) : ?><br>Registered Charity No. <?php echo esc_html( $org_charity ); ?><?php endif; ?></p>
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
                    <p><strong><?php echo esc_html( $org_name ); ?></strong><br><?php echo implode( '<br>', array_map( 'esc_html', $address_parts ) ); ?></p>
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
                        <td class="text-right">&pound;<?php echo number_format( $kitchen_price, 2 ); ?></td>
                        <td class="text-right">&pound;<?php echo number_format( $kitchen_price, 2 ); ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="nms-inv-totals">
                <div class="nms-inv-total-row"><span>Subtotal</span><span>&pound;<?php echo number_format( $booking->amount, 2 ); ?></span></div>
                <div class="nms-inv-total-row"><span>VAT (0% – Charity exempt)</span><span>&pound;0.00</span></div>
                <div class="nms-inv-total-row nms-inv-grand"><span>Total Due</span><span>&pound;<?php echo number_format( $booking->amount, 2 ); ?></span></div>
            </div>

            <?php
            $deposit_settings = MBS_Bookings::get_deposit_settings();
            $deposit_amount   = MBS_Bookings::calculate_deposit( (float) $booking->amount );
            $balance_amount   = (float) $booking->amount - $deposit_amount;
            $requires_full    = MBS_Bookings::requires_full_payment( $booking->booking_date );
            $balance_days     = $deposit_settings['balance_days'];
            ?>

            <div class="nms-inv-notes">
                <h5>Payment Terms</h5>
                <?php if ( $deposit_settings['enabled'] && (float) $booking->amount > 0 && ! $requires_full ) : ?>
                <p>
                    <strong>Deposit due immediately:</strong> &pound;<?php echo number_format( $deposit_amount, 2 ); ?> (<?php echo (int) $deposit_settings['percentage']; ?>% of total)<br>
                    <strong>Final balance due:</strong> &pound;<?php echo number_format( $balance_amount, 2 ); ?> — payable at least <?php echo $balance_days; ?> days before your event (by <?php echo date( 'j F Y', strtotime( $booking->booking_date . " -{$balance_days} days" ) ); ?>)<br><br>
                    <em>If a booking is made less than <?php echo $balance_days; ?> days before the event, the full amount is due immediately.</em>
                </p>
                <?php elseif ( $deposit_settings['enabled'] && (float) $booking->amount > 0 ) : ?>
                <p>Full payment of &pound;<?php echo number_format( $booking->amount, 2 ); ?> is due immediately (event within <?php echo $balance_days; ?> days).</p>
                <?php else : ?>
                <p>Please make payment within <strong><?php echo esc_html( $bank['payment_days'] ); ?> days</strong> of confirmation.</p>
                <?php endif; ?>

                <h5>Payment Methods</h5>
                <p>Please quote reference <strong><?php echo esc_html( $booking->invoice_number ); ?></strong> with all payments.<br>
                Bank Transfer: Sort Code <strong><?php echo esc_html( $bank['sort_code'] ); ?></strong> &bull; Account No. <strong><?php echo esc_html( $bank['account_number'] ); ?></strong><br>
                Cheques payable to: <em><?php echo esc_html( $bank['account_name'] ); ?></em></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Generate a standalone HTML invoice suitable for email attachment.
     * This is a complete HTML document with inline styles.
     */
    public static function generate_email_invoice( $booking ) {
        $spaces      = MBS_Bookings::get_spaces();
        $space_info  = $spaces[ $booking->space ] ?? array( 'rate' => 0, 'unit' => 'hr' );
        $is_day_rate = $space_info['unit'] === 'day';
        $kitchen_price = MBS_Bookings::get_kitchen_price();

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

        $space_subtotal = $booking->amount - ( $booking->kitchen ? $kitchen_price : 0 );
        $issue_date     = date( 'j F Y', strtotime( $booking->created_at ) );
        $bank           = MBS_Bookings::get_bank_details();
        $due_date       = date( 'j F Y', strtotime( $booking->created_at . ' +' . $bank['payment_days'] . ' days' ) );
        $booking_date   = date( 'l j F Y', strtotime( $booking->booking_date ) );
        $time_str       = $is_day_rate ? 'Full day' : ( $booking->start_time . ' – ' . $booking->end_time );
        $admin_email    = MBS_Bookings::get_admin_email();

        $org            = MBS_Email_Templates::get_org_settings();
        $org_name       = $org['name'] ?? 'Needham Market Scout Group';
        $org_address    = $org['address'] ?? '';
        $org_phone      = $org['phone'] ?? '';
        $org_charity    = $org['charity_number'] ?? '';

        // Split address into lines for the FROM section
        $address_parts = array_filter( array_map( 'trim', preg_split( '/[,\n]/', $org_address ) ) );

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice <?php echo esc_html( $booking->invoice_number ); ?></title>
    <style>
        body { font-family: Arial, sans-serif; color: #1a1a2e; max-width: 800px; margin: 0 auto; padding: 40px 20px; }
        .inv-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 32px; border-bottom: 3px solid #7413DC; padding-bottom: 24px; }
        .inv-org h2 { color: #7413DC; margin: 0 0 8px; }
        .inv-org p { margin: 0; color: #666; font-size: 14px; line-height: 1.6; }
        .inv-meta { text-align: right; }
        .inv-number { font-size: 24px; font-weight: bold; color: #7413DC; margin-bottom: 8px; }
        .inv-meta p { margin: 4px 0; font-size: 14px; }
        .inv-parties { display: flex; gap: 40px; margin-bottom: 32px; }
        .inv-party { flex: 1; }
        .inv-party h4 { color: #7413DC; margin: 0 0 8px; font-size: 13px; text-transform: uppercase; letter-spacing: 1px; }
        .inv-party p { margin: 0; line-height: 1.6; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        thead th { background: #7413DC; color: #fff; padding: 12px; text-align: left; font-size: 13px; }
        thead th.text-right { text-align: right; }
        tbody td { padding: 12px; border-bottom: 1px solid #e0d0f0; }
        tbody td.text-right { text-align: right; }
        tbody td small { color: #666; }
        .totals { margin-left: auto; width: 280px; }
        .total-row { display: flex; justify-content: space-between; padding: 8px 12px; border-bottom: 1px solid #eee; }
        .total-row.grand { background: #f5f0ff; font-weight: bold; font-size: 18px; border: 2px solid #7413DC; border-radius: 4px; margin-top: 8px; }
        .notes { margin-top: 32px; padding: 20px; background: #f9f7ff; border-radius: 8px; border: 1px solid #e0d0f0; }
        .notes h5 { margin: 0 0 8px; color: #7413DC; }
        .notes p { margin: 0; line-height: 1.8; }
        @media print { body { padding: 0; } }
    </style>
</head>
<body>
    <div class="inv-header">
        <div class="inv-org">
            <h2>&#9884; <?php echo esc_html( $org_name ); ?></h2>
            <p><?php echo esc_html( $org_address ); ?><br>
            <?php echo esc_html( $admin_email ); ?><?php if ( $org_phone ) : ?> &bull; <?php echo esc_html( $org_phone ); ?><?php endif; ?>
            <?php if ( $org_charity ) : ?><br>Registered Charity No. <?php echo esc_html( $org_charity ); ?><?php endif; ?></p>
        </div>
        <div class="inv-meta">
            <div class="inv-number"><?php echo esc_html( $booking->invoice_number ); ?></div>
            <p>Issue Date: <?php echo esc_html( $issue_date ); ?></p>
            <p>Due Date: <strong><?php echo esc_html( $due_date ); ?></strong></p>
        </div>
    </div>

    <div class="inv-parties">
        <div class="inv-party">
            <h4>From</h4>
            <p><strong><?php echo esc_html( $org_name ); ?></strong><br><?php echo implode( '<br>', array_map( 'esc_html', $address_parts ) ); ?></p>
        </div>
        <div class="inv-party">
            <h4>Bill To</h4>
            <p><strong><?php echo esc_html( $booking->name ); ?></strong>
            <?php if ( $booking->organisation ) : ?><br><?php echo esc_html( $booking->organisation ); ?><?php endif; ?>
            <br><?php echo nl2br( esc_html( $booking->address ) ); ?>
            <br><?php echo esc_html( $booking->email ); ?>
            <br><?php echo esc_html( $booking->phone ); ?></p>
        </div>
    </div>

    <table>
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
                <td class="text-right">&pound;<?php echo number_format( $kitchen_price, 2 ); ?></td>
                <td class="text-right">&pound;<?php echo number_format( $kitchen_price, 2 ); ?></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="totals">
        <div class="total-row"><span>Subtotal</span><span>&pound;<?php echo number_format( $booking->amount, 2 ); ?></span></div>
        <div class="total-row"><span>VAT (0% – Charity exempt)</span><span>&pound;0.00</span></div>
        <div class="total-row grand"><span>Total Due</span><span>&pound;<?php echo number_format( $booking->amount, 2 ); ?></span></div>
    </div>

    <?php
    $deposit_settings = MBS_Bookings::get_deposit_settings();
    $deposit_amount   = MBS_Bookings::calculate_deposit( (float) $booking->amount );
    $balance_amount   = (float) $booking->amount - $deposit_amount;
    $requires_full    = MBS_Bookings::requires_full_payment( $booking->booking_date );
    $balance_days     = $deposit_settings['balance_days'];
    ?>

    <div class="notes">
        <h5>Payment Terms</h5>
        <?php if ( $deposit_settings['enabled'] && (float) $booking->amount > 0 && ! $requires_full ) : ?>
        <p>
            <strong>Deposit due immediately:</strong> &pound;<?php echo number_format( $deposit_amount, 2 ); ?> (<?php echo (int) $deposit_settings['percentage']; ?>% of total)<br>
            <strong>Final balance due:</strong> &pound;<?php echo number_format( $balance_amount, 2 ); ?> — payable at least <?php echo $balance_days; ?> days before your event (by <?php echo date( 'j F Y', strtotime( $booking->booking_date . " -{$balance_days} days" ) ); ?>)<br><br>
            <em>If a booking is made less than <?php echo $balance_days; ?> days before the event, the full amount is due immediately.</em>
        </p>
        <?php elseif ( $deposit_settings['enabled'] && (float) $booking->amount > 0 ) : ?>
        <p>Full payment of &pound;<?php echo number_format( $booking->amount, 2 ); ?> is due immediately (event within <?php echo $balance_days; ?> days).</p>
        <?php else : ?>
        <p>Please make payment within <strong><?php echo esc_html( $bank['payment_days'] ); ?> days</strong> of confirmation.</p>
        <?php endif; ?>

        <h5>Payment Methods</h5>
        <p>Please quote reference <strong><?php echo esc_html( $booking->invoice_number ); ?></strong> with all payments.<br>
        Bank Transfer: Sort Code <strong><?php echo esc_html( $bank['sort_code'] ); ?></strong> &bull; Account No. <strong><?php echo esc_html( $bank['account_number'] ); ?></strong><br>
        Cheques payable to: <em><?php echo esc_html( $bank['account_name'] ); ?></em></p>
    </div>
</body>
</html>
        <?php
        return ob_get_clean();
    }
}
