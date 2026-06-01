<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MBS_Invoice {

    /**
     * Generate invoice HTML for display in the admin dashboard.
     */
    public static function generate_html( $booking ) {
        $kitchen_price = MBS_Bookings::get_kitchen_price();
        $is_all_day    = ! empty( $booking->all_day );
        $space_subtotal = (float) $booking->amount - ( $booking->kitchen ? $kitchen_price : 0 );

        $issue_date     = wp_date( 'j F Y', strtotime( $booking->created_at ) );
        $bank           = MBS_Bookings::get_bank_details();
        $deposit_settings = MBS_Bookings::get_deposit_settings();

        if ( $deposit_settings['enabled'] && (float) $booking->amount > 0 ) {
            $due_date = 'Immediately (deposit)';
            if ( MBS_Bookings::requires_full_payment( $booking->booking_date ) ) {
                $due_date = 'Immediately';
            }
        } else {
            $due_date = wp_date( 'j F Y', strtotime( $booking->created_at . ' +' . $bank['payment_days'] . ' days' ) );
        }

        $booking_date = wp_date( 'l j F Y', strtotime( $booking->booking_date ) );
        $time_str     = $is_all_day ? 'Full day' : ( $booking->start_time . ' – ' . $booking->end_time );

        // Multi-day label
        $date_label = $booking_date;
        if ( ! empty( $booking->booking_date_end ) && $booking->booking_date_end !== $booking->booking_date ) {
            $date_label = wp_date( 'j M Y', strtotime( $booking->booking_date ) ) . ' – ' . wp_date( 'j M Y', strtotime( $booking->booking_date_end ) );
        }

        $org            = MBS_Email_Templates::get_org_settings();
        $org_name       = $org['name'] ?: get_bloginfo( 'name' );
        $org_address    = $org['address'] ?? '';
        $org_phone      = $org['phone'] ?? '';
        $org_charity    = $org['charity_number'] ?? '';
        $admin_email    = MBS_Bookings::get_admin_email();
        $address_parts  = array_filter( array_map( 'trim', preg_split( '/[,\n]/', $org_address ) ) );

        ob_start();
        ?>
        <div class="mbs-invoice" id="mbs-invoice-print">
            <div class="nms-inv-header">
                <div class="nms-inv-org">
                    <?php if ( ! empty( $org['logo_url'] ) ) : ?>
                    <div class="nms-inv-logo"><img src="<?php echo esc_url( $org['logo_url'] ); ?>" alt="<?php echo esc_attr( $org_name ); ?>" style="max-height:60px;max-width:200px;height:auto;"></div>
                    <?php else : ?>
                    <div class="nms-inv-logo">&#9884;</div>
                    <?php endif; ?>
                    <h2><?php echo esc_html( $org_name ); ?></h2>
                    <p><?php echo esc_html( $org_address ); ?><br>
                    <?php echo esc_html( $admin_email ); ?><?php if ( $org_phone ) : ?> &bull; <?php echo esc_html( $org_phone ); ?><?php endif; ?>
                    <?php if ( $org_charity ) : ?><br>Registered Charity No. <?php echo esc_html( $org_charity ); ?><?php endif; ?></p>
                </div>
                <div class="nms-inv-meta">
                    <div class="nms-inv-number"><?php echo esc_html( $booking->invoice_number ); ?></div>
                    <p>Issue Date: <?php echo esc_html( $issue_date ); ?></p>
                    <p>Due Date: <strong><?php echo esc_html( $due_date ); ?></strong></p>
                    <p><span class="nms-status nms-status-<?php echo esc_attr( $booking->status ); ?>"><?php echo esc_html( MBS_Bookings::status_label( $booking->status ) ); ?></span></p>
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
                    <tr><th>Description</th><th class="text-right">Amount</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $booking->space ); ?> hire</strong><br>
                            <small><?php echo esc_html( $date_label ); ?> &bull; <?php echo esc_html( $time_str ); ?></small><br>
                            <small>Purpose: <?php echo esc_html( $booking->purpose ); ?></small>
                        </td>
                        <td class="text-right">&pound;<?php echo number_format( $space_subtotal, 2 ); ?></td>
                    </tr>
                    <?php if ( $booking->kitchen ) : ?>
                    <tr>
                        <td>Kitchen facilities add-on</td>
                        <td class="text-right">&pound;<?php echo number_format( $kitchen_price, 2 ); ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="nms-inv-totals">
                <div class="nms-inv-total-row"><span>Subtotal</span><span>&pound;<?php echo number_format( $booking->amount, 2 ); ?></span></div>
                <div class="nms-inv-total-row"><span>VAT (0% – Charity exempt)</span><span>&pound;0.00</span></div>
                <div class="nms-inv-total-row nms-inv-grand"><span>Total</span><span>&pound;<?php echo number_format( $booking->amount, 2 ); ?></span></div>
                <?php
                $inv_amount_paid = (float) ( $booking->amount_paid ?? 0 );
                if ( $inv_amount_paid > 0 ) :
                    $inv_balance_due = (float) $booking->amount - $inv_amount_paid;
                ?>
                <div class="nms-inv-total-row"><span>Amount Paid</span><span style="color:#065f46;">&pound;<?php echo number_format( $inv_amount_paid, 2 ); ?></span></div>
                <div class="nms-inv-total-row nms-inv-grand"><span>Balance Due</span><span>&pound;<?php echo number_format( max( 0, $inv_balance_due ), 2 ); ?></span></div>
                <?php endif; ?>
            </div>

            <?php
            $deposit_amount = MBS_Bookings::calculate_deposit( (float) $booking->amount );
            $balance_amount = (float) $booking->amount - $deposit_amount;
            $requires_full  = MBS_Bookings::requires_full_payment( $booking->booking_date );
            $balance_days   = $deposit_settings['balance_days'];
            ?>

            <div class="nms-inv-notes">
                <h5>Payment Terms</h5>
                <?php if ( $deposit_settings['enabled'] && (float) $booking->amount > 0 && ! $requires_full ) : ?>
                <p>
                    <strong>Deposit due immediately:</strong> &pound;<?php echo number_format( $deposit_amount, 2 ); ?> (<?php echo (int) $deposit_settings['percentage']; ?>% of total)<br>
                    <strong>Final balance due:</strong> &pound;<?php echo number_format( $balance_amount, 2 ); ?> — payable at least <?php echo $balance_days; ?> days before your event (by <?php echo wp_date( 'j F Y', strtotime( $booking->booking_date . " -{$balance_days} days" ) ); ?>)<br><br>
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
     */
    public static function generate_email_invoice( $booking ) {
        $kitchen_price = MBS_Bookings::get_kitchen_price();
        $is_all_day    = ! empty( $booking->all_day );
        $space_subtotal = (float) $booking->amount - ( $booking->kitchen ? $kitchen_price : 0 );

        $issue_date     = wp_date( 'j F Y', strtotime( $booking->created_at ) );
        $bank           = MBS_Bookings::get_bank_details();
        $deposit_settings = MBS_Bookings::get_deposit_settings();

        if ( $deposit_settings['enabled'] && (float) $booking->amount > 0 ) {
            $due_date = 'Immediately (deposit)';
            if ( MBS_Bookings::requires_full_payment( $booking->booking_date ) ) {
                $due_date = 'Immediately';
            }
        } else {
            $due_date = wp_date( 'j F Y', strtotime( $booking->created_at . ' +' . $bank['payment_days'] . ' days' ) );
        }

        $booking_date = wp_date( 'l j F Y', strtotime( $booking->booking_date ) );
        $time_str     = $is_all_day ? 'Full day' : ( $booking->start_time . ' – ' . $booking->end_time );

        $date_label = $booking_date;
        if ( ! empty( $booking->booking_date_end ) && $booking->booking_date_end !== $booking->booking_date ) {
            $date_label = wp_date( 'j M Y', strtotime( $booking->booking_date ) ) . ' – ' . wp_date( 'j M Y', strtotime( $booking->booking_date_end ) );
        }

        $admin_email    = MBS_Bookings::get_admin_email();
        $org            = MBS_Email_Templates::get_org_settings();
        $org_name       = $org['name'] ?: get_bloginfo( 'name' );
        $org_address    = $org['address'] ?? '';
        $org_phone      = $org['phone'] ?? '';
        $org_charity    = $org['charity_number'] ?? '';
        $address_parts  = array_filter( array_map( 'trim', preg_split( '/[,\n]/', $org_address ) ) );

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
            <?php if ( ! empty( $org['logo_url'] ) ) : ?>
            <img src="<?php echo esc_url( $org['logo_url'] ); ?>" alt="<?php echo esc_attr( $org_name ); ?>" style="max-height:60px;max-width:200px;height:auto;margin-bottom:8px;"><br>
            <h2 style="margin-top:4px;"><?php echo esc_html( $org_name ); ?></h2>
            <?php else : ?>
            <h2>&#9884; <?php echo esc_html( $org_name ); ?></h2>
            <?php endif; ?>
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
            <tr><th>Description</th><th class="text-right">Amount</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <strong><?php echo esc_html( $booking->space ); ?> hire</strong><br>
                    <small><?php echo esc_html( $date_label ); ?> &bull; <?php echo esc_html( $time_str ); ?></small><br>
                    <small>Purpose: <?php echo esc_html( $booking->purpose ); ?></small>
                </td>
                <td class="text-right">&pound;<?php echo number_format( $space_subtotal, 2 ); ?></td>
            </tr>
            <?php if ( $booking->kitchen ) : ?>
            <tr>
                <td>Kitchen facilities add-on</td>
                <td class="text-right">&pound;<?php echo number_format( $kitchen_price, 2 ); ?></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="totals">
        <div class="total-row"><span>Subtotal</span><span>&pound;<?php echo number_format( $booking->amount, 2 ); ?></span></div>
        <div class="total-row"><span>VAT (0% – Charity exempt)</span><span>&pound;0.00</span></div>
        <div class="total-row grand"><span>Total</span><span>&pound;<?php echo number_format( $booking->amount, 2 ); ?></span></div>
        <?php
        $inv_amount_paid = (float) ( $booking->amount_paid ?? 0 );
        if ( $inv_amount_paid > 0 ) :
            $inv_balance_due = (float) $booking->amount - $inv_amount_paid;
        ?>
        <div class="total-row"><span>Amount Paid</span><span style="color:#065f46;">&pound;<?php echo number_format( $inv_amount_paid, 2 ); ?></span></div>
        <div class="total-row grand"><span>Balance Due</span><span>&pound;<?php echo number_format( max( 0, $inv_balance_due ), 2 ); ?></span></div>
        <?php endif; ?>
    </div>

    <?php
    $deposit_amount = MBS_Bookings::calculate_deposit( (float) $booking->amount );
    $balance_amount = (float) $booking->amount - $deposit_amount;
    $requires_full  = MBS_Bookings::requires_full_payment( $booking->booking_date );
    $balance_days   = $deposit_settings['balance_days'];
    ?>

    <div class="notes">
        <h5>Payment Terms</h5>
        <?php if ( $deposit_settings['enabled'] && (float) $booking->amount > 0 && ! $requires_full ) : ?>
        <p>
            <strong>Deposit due immediately:</strong> &pound;<?php echo number_format( $deposit_amount, 2 ); ?> (<?php echo (int) $deposit_settings['percentage']; ?>% of total)<br>
            <strong>Final balance due:</strong> &pound;<?php echo number_format( $balance_amount, 2 ); ?> — payable at least <?php echo $balance_days; ?> days before your event (by <?php echo wp_date( 'j F Y', strtotime( $booking->booking_date . " -{$balance_days} days" ) ); ?>)<br><br>
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
