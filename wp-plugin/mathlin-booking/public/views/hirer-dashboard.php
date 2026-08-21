<?php if ( ! defined( 'ABSPATH' ) ) exit;

$user    = wp_get_current_user();
$email   = $user->user_email;
$name    = get_user_meta( $user->ID, 'first_name', true ) ?: $user->display_name;
$stats   = MBS_Hirer_Portal::get_hirer_stats( $email );
$bookings = MBS_Hirer_Portal::get_bookings_for_email( $email );
$series   = MBS_Hirer_Portal::get_series_for_email( $email );
$invoices = MBS_Hirer_Portal::get_invoices_for_email( $email );
$spaces  = MBS_Bookings::get_spaces();
$series_refs = array();
foreach ( $series as $series_row ) $series_refs[ $series_row->series_ref ] = true;
$legacy_bookings = array_values( array_filter( $bookings, static function ( $booking ) use ( $series_refs ) {
    return empty( $booking->series_id ) || ! isset( $series_refs[ $booking->series_id ] );
} ) );
$invoices_by_series = array();
foreach ( $invoices as $invoice ) $invoices_by_series[ $invoice->series_ref ][] = $invoice;
?>

<div class="nms-wrap">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div>
            <h2 class="nms-section-title" style="margin-bottom:0;">Welcome, <?php echo esc_html( $name ); ?></h2>
            <p class="nms-muted"><?php echo esc_html( $email ); ?></p>
        </div>
        <div style="display:flex;gap:0.5rem;">
            <?php
                $bp = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 's' => 'mathlin_booking', 'numberposts' => 1 ) );
                if ( $bp ) :
            ?>
            <a href="<?php echo esc_url( get_permalink( $bp[0]->ID ) ); ?>" class="nms-btn nms-btn-primary">+ New Booking</a>
            <?php endif; ?>
            <a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>" class="nms-btn nms-btn-sm" style="background:#f3f4f6;color:#6b7280;border-color:#e5e7eb;">Log Out</a>
        </div>
    </div>

    <!-- Stats -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:1rem;margin-bottom:2rem;">
        <div class="nms-form-section" style="text-align:center;padding:1rem;">
            <div style="font-size:1.75rem;font-weight:800;color:#7413DC;"><?php echo $stats['upcoming']; ?></div>
            <div style="font-size:0.8rem;color:#6b7280;">Upcoming</div>
        </div>
        <div class="nms-form-section" style="text-align:center;padding:1rem;">
            <div style="font-size:1.75rem;font-weight:800;color:#f39c12;"><?php echo $stats['pending']; ?></div>
            <div style="font-size:0.8rem;color:#6b7280;">Pending</div>
        </div>
        <div class="nms-form-section" style="text-align:center;padding:1rem;">
            <div style="font-size:1.75rem;font-weight:800;color:#2ecc71;"><?php echo $stats['total']; ?></div>
            <div style="font-size:0.8rem;color:#6b7280;">Total Bookings</div>
        </div>
        <div class="nms-form-section" style="text-align:center;padding:1rem;">
            <div style="font-size:1.75rem;font-weight:800;color:#7413DC;"><?php echo esc_html( MBS_Money::format( $stats['total_spent_minor'] ) ); ?></div>
            <div style="font-size:0.8rem;color:#6b7280;">Actually Paid</div>
        </div>
    </div>

    <?php if ( $series ) : ?>
    <div class="nms-form-section" style="margin-bottom:1.5rem;">
        <h3>Your Recurring Bookings</h3>
        <p class="nms-muted">Each series is grouped here. Billing and payment are handled per invoice, not per date.</p>
        <?php foreach ( $series as $s ) :
            $occurrences = MBS_Series::occurrences( $s->series_ref, false );
            $series_invoices = $invoices_by_series[ $s->series_ref ] ?? array();
            $next_occurrence = null;
            foreach ( $occurrences as $occurrence ) {
                if ( $occurrence->booking_date >= wp_date( 'Y-m-d' ) && ! in_array( $occurrence->status, array( 'cancelled', 'archived' ), true ) ) { $next_occurrence = $occurrence; break; }
            }
            $outstanding = 0;
            $next_invoice = null;
            foreach ( $series_invoices as $invoice ) {
                $balance = MBS_Billing_Ledger::balance_minor( $invoice );
                if ( $balance > 0 && in_array( $invoice->status, array( 'issued', 'part_paid', 'overdue' ), true ) ) {
                    $outstanding += $balance;
                    if ( ! $next_invoice || $invoice->due_at < $next_invoice->due_at ) $next_invoice = $invoice;
                }
            }
        ?>
        <article style="border:1px solid #e0d0f0;border-radius:8px;padding:1rem;margin:1rem 0;">
            <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                <div><strong><?php echo esc_html( $s->space ); ?></strong><br><span class="nms-muted"><?php echo esc_html( $s->series_ref ); ?> · <?php echo esc_html( ucfirst( str_replace( '_', ' ', $s->status ) ) ); ?></span></div>
                <div style="text-align:right;"><strong><?php echo esc_html( MBS_Money::format( MBS_Money::from_decimal_string( (string) $s->price_per_booking ) ) ); ?></strong> per booking<br><span class="nms-muted"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $s->billing_mode ) ) ); ?> billing</span></div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:0.75rem;margin-top:1rem;">
                <div><span class="nms-muted">Schedule</span><br>Weekly, <?php echo esc_html( $s->all_day ? 'all day' : substr( $s->start_time, 0, 5 ) . '–' . substr( $s->end_time, 0, 5 ) ); ?></div>
                <div><span class="nms-muted">Next date</span><br><?php echo $next_occurrence ? esc_html( wp_date( 'j M Y', strtotime( $next_occurrence->booking_date ) ) ) : 'No future date'; ?></div>
                <div><span class="nms-muted">Estimated series value</span><br><?php echo esc_html( '£' . number_format( (float) $s->estimated_total, 2 ) ); ?></div>
                <div><span class="nms-muted">Outstanding</span><br><?php echo esc_html( MBS_Money::format( $outstanding ) ); ?></div>
                <div><span class="nms-muted">Next invoice</span><br><?php echo $next_invoice ? esc_html( $next_invoice->invoice_ref . ' · due ' . wp_date( 'j M Y', strtotime( $next_invoice->due_at ) ) ) : 'None outstanding'; ?></div>
            </div>
            <details style="margin-top:1rem;">
                <summary style="cursor:pointer;font-weight:600;">View <?php echo (int) count( $occurrences ); ?> booking dates</summary>
                <ul style="columns:2;min-width:260px;">
                    <?php foreach ( $occurrences as $occurrence ) : ?>
                    <li><?php echo esc_html( wp_date( 'D j M Y', strtotime( $occurrence->booking_date ) ) . ' — ' . MBS_Bookings::status_label( $occurrence->status ) ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ( $invoices ) : ?>
    <div class="nms-form-section" style="margin-bottom:1.5rem;">
        <h3>Invoices &amp; Payments</h3>
        <?php foreach ( $invoices as $invoice ) :
            $balance = MBS_Billing_Ledger::balance_minor( $invoice );
            $invoice_series = isset( $series_refs[ $invoice->series_ref ] ) ? MBS_Series::get( $invoice->series_ref ) : null;
            $pay_url = $balance > 0 ? MBS_Invoice_Payment::generate_payment_url( $invoice ) : '';
            $transactions = MBS_Hirer_Portal::invoice_transactions( $invoice->id );
            $invoice_document_id = MBS_Invoice_Document_Service::get_current_ledger_document_id( $invoice->id );
            $invoice_pdf_url = $invoice_document_id ? MBS_Invoice_Delivery_Endpoint::authenticated_pdf_url( $invoice_document_id ) : '';
        ?>
        <div style="border-top:1px solid #e5e7eb;padding:1rem 0;display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
            <div><strong><?php echo esc_html( $invoice->invoice_ref ); ?></strong> · <?php echo esc_html( ucfirst( str_replace( '_', ' ', $invoice->status ) ) ); ?><br><span class="nms-muted"><?php echo esc_html( wp_date( 'j M Y', strtotime( $invoice->period_start ) ) . '–' . wp_date( 'j M Y', strtotime( $invoice->period_end ) ) ); ?> · Total <?php echo esc_html( MBS_Money::format( (int) $invoice->total_minor, $invoice->currency ) ); ?> · Paid <?php echo esc_html( MBS_Money::format( (int) $invoice->paid_minor, $invoice->currency ) ); ?></span>
                <?php if ( $transactions ) : ?><details><summary>Payment history</summary><ul><?php foreach ( $transactions as $transaction ) : ?><li><?php echo esc_html( wp_date( 'j M Y', strtotime( $transaction->occurred_at ) ) . ' · ' . ucfirst( $transaction->transaction_type ) . ' ' . MBS_Money::format( (int) $transaction->amount_minor, $transaction->currency ) ); ?></li><?php endforeach; ?></ul></details><?php endif; ?>
            </div>
            <div style="text-align:right;"><strong>Balance <?php echo esc_html( MBS_Money::format( $balance, $invoice->currency ) ); ?></strong><br>
                <?php if ( $invoice_pdf_url ) : ?><a href="<?php echo esc_url( $invoice_pdf_url ); ?>" class="nms-btn nms-btn-sm" style="background:#f5f0ff;color:#7413DC;border-color:#e0d0f0;">Download PDF</a><?php endif; ?>
                <?php if ( $pay_url ) : ?><a href="<?php echo esc_url( $pay_url ); ?>" class="nms-btn nms-btn-sm" style="background:#2ecc71;color:#fff;border-color:#2ecc71;">Pay this invoice</a>
                <?php elseif ( $balance > 0 && $invoice_series && $invoice_series->payment_method === 'offline_bacs' ) : ?><span class="nms-muted">Pay by BACS using <?php echo esc_html( $invoice->invoice_ref ); ?></span><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Bookings list -->
    <div class="nms-form-section">
        <h3>One-off Bookings</h3>

        <?php if ( empty( $legacy_bookings ) ) : ?>
            <p class="nms-muted">You don't have any separate one-off bookings.</p>
        <?php else : ?>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.875rem;">
                    <thead>
                        <tr style="border-bottom:2px solid #e5e7eb;">
                            <th style="padding:8px;text-align:left;color:#6b7280;font-size:0.75rem;text-transform:uppercase;">Ref</th>
                            <th style="padding:8px;text-align:left;color:#6b7280;font-size:0.75rem;text-transform:uppercase;">Space</th>
                            <th style="padding:8px;text-align:left;color:#6b7280;font-size:0.75rem;text-transform:uppercase;">Date</th>
                            <th style="padding:8px;text-align:left;color:#6b7280;font-size:0.75rem;text-transform:uppercase;">Time</th>
                            <th style="padding:8px;text-align:left;color:#6b7280;font-size:0.75rem;text-transform:uppercase;">Amount</th>
                            <th style="padding:8px;text-align:left;color:#6b7280;font-size:0.75rem;text-transform:uppercase;">Status</th>
                            <th style="padding:8px;text-align:left;color:#6b7280;font-size:0.75rem;text-transform:uppercase;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $legacy_bookings as $b ) :
                            $is_daily = ! empty( $b->all_day );
                            $time_str = $is_daily ? 'All day' : ( substr( $b->start_time, 0, 5 ) . '–' . substr( $b->end_time, 0, 5 ) );
                            $is_past  = strtotime( $b->booking_date ) < strtotime( 'today' );

                            $status_styles = array(
                                'pending'      => 'background:#fff3cd;color:#856404',
                                'confirmed'    => 'background:#d1fae5;color:#065f46',
                                'deposit_paid' => 'background:#fef3c7;color:#92400e',
                                'paid'         => 'background:#dbeafe;color:#1e40af',
                                'cancelled'    => 'background:#fee2e2;color:#991b1b',
                            );
                            $badge_style = $status_styles[ $b->status ] ?? 'background:#f3f4f6;color:#6b7280';

                            // Determine payment context for display
                            $deposit_settings = MBS_Bookings::get_deposit_settings();
                            $total_amount     = (float) $b->amount;
                            $amount_paid_val  = (float) ( $b->amount_paid ?? 0 );
                            $deposit_amount   = MBS_Bookings::calculate_deposit( $total_amount );
                            $is_deposit_mode  = $deposit_settings['enabled'] && $total_amount > 0;
                        ?>
                        <tr style="border-bottom:1px solid #f0f0f0;<?php echo $is_past ? 'opacity:0.6;' : ''; ?>">
                            <td style="padding:10px 8px;font-weight:600;"><?php echo esc_html( $b->ref ); ?></td>
                            <td style="padding:10px 8px;"><?php echo esc_html( $b->space ); ?></td>
                            <td style="padding:10px 8px;"><?php echo esc_html( date( 'D j M Y', strtotime( $b->booking_date ) ) ); ?></td>
                            <td style="padding:10px 8px;"><?php echo esc_html( $time_str ); ?></td>
                            <td style="padding:10px 8px;">
                                &pound;<?php echo number_format( $b->amount, 2 ); ?>
                                <?php if ( $amount_paid_val > 0 && $amount_paid_val < $total_amount ) : ?>
                                    <br><span style="font-size:0.65rem;color:#92400e;">Paid: &pound;<?php echo number_format( $amount_paid_val, 2 ); ?> | Due: &pound;<?php echo number_format( $total_amount - $amount_paid_val, 2 ); ?></span>
                                <?php elseif ( $amount_paid_val > $total_amount + 0.01 ) : ?>
                                    <br><span style="font-size:0.65rem;color:#1e40af;font-weight:600;">Refund due: &pound;<?php echo number_format( $amount_paid_val - $total_amount, 2 ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px 8px;">
                                <span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;text-transform:uppercase;<?php echo $badge_style; ?>">
                                    <?php echo esc_html( MBS_Bookings::status_label( $b->status ) ); ?>
                                </span>
                            </td>
                            <td style="padding:10px 8px;">
                                <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                    <?php
                                    $balance_due = $total_amount - $amount_paid_val;
                                    $show_pay = ( $balance_due > 0.01 ) && MBS_Woo_Payment::is_available() && ! in_array( $b->status, array( 'cancelled', 'archived' ) );
                                    if ( $show_pay ) :
                                        $pay_url = MBS_Woo_Payment::generate_payment_url( $b );
                                        if ( $pay_url ) :
                                            // Determine button label based on amount_paid
                                            if ( $amount_paid_val > 0 ) {
                                                $pay_label = 'Pay Balance (£' . number_format( $balance_due, 2 ) . ')';
                                            } elseif ( $is_deposit_mode && ! MBS_Bookings::requires_full_payment( $b->booking_date ) ) {
                                                $pay_label = 'Pay Deposit (£' . number_format( $deposit_amount, 2 ) . ')';
                                            } else {
                                                $pay_label = 'Pay (£' . number_format( $total_amount, 2 ) . ')';
                                            }
                                    ?>
                                        <a href="<?php echo esc_url( $pay_url ); ?>" class="nms-btn nms-btn-sm" style="background:#2ecc71;color:#fff;border-color:#2ecc71;font-size:0.7rem;"><?php echo esc_html( $pay_label ); ?></a>
                                    <?php endif; endif; ?>
                                    <?php if ( ! empty( $b->current_invoice_document_id ) ) :
                                        $booking_pdf_url = MBS_Invoice_Delivery_Endpoint::authenticated_pdf_url( (int) $b->current_invoice_document_id );
                                    ?>
                                        <a href="<?php echo esc_url( $booking_pdf_url ); ?>" class="nms-btn nms-btn-sm" style="background:#f5f0ff;color:#7413DC;border-color:#e0d0f0;font-size:0.7rem;">Invoice PDF</a>
                                    <?php endif; ?>
                                    <a href="<?php echo esc_url( rest_url( 'mathlin/v1/bookings/' . $b->ref . '/ical' ) ); ?>" class="nms-btn nms-btn-sm" style="background:#f5f0ff;color:#7413DC;border-color:#e0d0f0;font-size:0.7rem;">📅</a>
                                    <?php
                                    $mod_url = MBS_Modification::get_modification_url( $b );
                                    if ( $mod_url && ! in_array( $b->status, array( 'cancelled' ) ) && ! $is_past ) :
                                    ?>
                                        <a href="<?php echo esc_url( $mod_url ); ?>" class="nms-btn nms-btn-sm" style="font-size:0.7rem;">Change</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
