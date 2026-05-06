<?php if ( ! defined( 'ABSPATH' ) ) exit;

$user    = wp_get_current_user();
$email   = $user->user_email;
$name    = get_user_meta( $user->ID, 'first_name', true ) ?: $user->display_name;
$stats   = MBS_Hirer_Portal::get_hirer_stats( $email );
$bookings = MBS_Hirer_Portal::get_bookings_for_email( $email );
$spaces  = MBS_Bookings::get_spaces();
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
            <div style="font-size:1.75rem;font-weight:800;color:#7413DC;">&pound;<?php echo number_format( $stats['total_spent'], 0 ); ?></div>
            <div style="font-size:0.8rem;color:#6b7280;">Total Spent</div>
        </div>
    </div>

    <!-- Bookings list -->
    <div class="nms-form-section">
        <h3>Your Bookings</h3>

        <?php if ( empty( $bookings ) ) : ?>
            <p class="nms-muted">You don't have any bookings yet.</p>
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
                        <?php foreach ( $bookings as $b ) :
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
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px 8px;">
                                <span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;text-transform:uppercase;<?php echo $badge_style; ?>">
                                    <?php echo esc_html( MBS_Bookings::status_label( $b->status ) ); ?>
                                </span>
                            </td>
                            <td style="padding:10px 8px;">
                                <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                    <?php if ( in_array( $b->status, array( 'confirmed', 'deposit_paid' ) ) && MBS_Woo_Payment::is_available() ) :
                                        $pay_url = MBS_Woo_Payment::generate_payment_url( $b );
                                        if ( $pay_url ) :
                                            // Determine button label based on amount_paid
                                            $balance_due = $total_amount - $amount_paid_val;
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
