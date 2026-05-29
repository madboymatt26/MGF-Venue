<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WordPress Dashboard Widget — shows today's bookings and pending approvals
 * on the main wp-admin dashboard.
 */
class MBS_Dashboard_Widget {

    public function init() {
        add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
    }

    public function register_widget() {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'mbs_manage_bookings' ) ) return;

        wp_add_dashboard_widget(
            'mbs_dashboard_widget',
            '⚜️ Scout Hall Bookings',
            array( $this, 'render_widget' )
        );
    }

    public function render_widget() {
        $today    = wp_date( 'Y-m-d' );
        $tomorrow = wp_date( 'Y-m-d', strtotime( '+1 day' ) );

        // Today's bookings
        $todays = MBS_Bookings::get_by_date( $today );

        // Tomorrow's bookings
        $tomorrows = MBS_Bookings::get_by_date( $tomorrow );

        // Pending bookings
        $pending = MBS_Bookings::get_all( array(
            'status'  => 'pending',
            'orderby' => 'created_at',
            'order'   => 'DESC',
            'limit'   => 10,
        ) );

        // Stats — exclude internal Scout Night bookings so the Pending/Confirmed
        // figures reflect public / revenue-generating hires only.
        $stats = MBS_Bookings::get_stats( true );

        ?>
        <style>
            .mbs-dw-section { margin-bottom: 16px; }
            .mbs-dw-section h4 { margin: 0 0 8px; color: #7413DC; font-size: 13px; }
            .mbs-dw-item { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
            .mbs-dw-item:last-child { border-bottom: none; }
            .mbs-dw-space { font-weight: 600; color: #1d2327; }
            .mbs-dw-time { color: #666; font-size: 12px; }
            .mbs-dw-name { color: #666; font-size: 12px; }
            .mbs-dw-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; }
            .mbs-dw-pending { background: #fff3cd; color: #856404; }
            .mbs-dw-confirmed { background: #d1fae5; color: #065f46; }
            .mbs-dw-paid { background: #dbeafe; color: #1e40af; }
            .mbs-dw-stats { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
            .mbs-dw-stat { text-align: center; flex: 1; min-width: 60px; }
            .mbs-dw-stat-val { font-size: 20px; font-weight: 800; color: #7413DC; }
            .mbs-dw-stat-label { font-size: 11px; color: #666; }
            .mbs-dw-empty { color: #999; font-style: italic; font-size: 13px; padding: 4px 0; }
            .mbs-dw-links { margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap; }
            .mbs-dw-links a { font-size: 12px; }
        </style>

        <!-- Alerts -->
        <?php
        $email_stats    = MBS_Email_Queue::get_stats();
        $pending_reqs   = MBS_Modification::get_pending_count();
        if ( $email_stats['failed'] > 0 ) : ?>
        <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:8px 12px;margin-bottom:12px;font-size:0.85rem;color:#991b1b;">
            ⚠️ <strong><?php echo $email_stats['failed']; ?> email(s) failed to send</strong> after 3 retries.
            <a href="<?php echo admin_url( 'admin.php?page=mathlin-analytics' ); ?>" style="color:#991b1b;">View details →</a>
        </div>
        <?php endif; ?>
        <?php if ( $email_stats['pending'] > 0 ) : ?>
        <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:8px 12px;margin-bottom:12px;font-size:0.85rem;color:#856404;">
            📧 <?php echo $email_stats['pending']; ?> email(s) queued for retry.
        </div>
        <?php endif; ?>
        <?php if ( $pending_reqs > 0 ) : ?>
        <div style="background:#f5f0ff;border:1px solid #e0d0f0;border-radius:6px;padding:8px 12px;margin-bottom:12px;font-size:0.85rem;color:#7413DC;">
            📝 <strong><?php echo $pending_reqs; ?> change request(s)</strong> awaiting review.
            <a href="<?php echo admin_url( 'admin.php?page=mathlin-requests' ); ?>" style="color:#7413DC;">Review →</a>
        </div>
        <?php endif; ?>

        <!-- Quick stats -->
        <div class="mbs-dw-stats">
            <div class="mbs-dw-stat">
                <div class="mbs-dw-stat-val"><?php echo esc_html( $stats['pending'] ); ?></div>
                <div class="mbs-dw-stat-label">Pending</div>
            </div>
            <div class="mbs-dw-stat">
                <div class="mbs-dw-stat-val"><?php echo esc_html( $stats['confirmed'] ); ?></div>
                <div class="mbs-dw-stat-label">Confirmed</div>
            </div>
            <div class="mbs-dw-stat">
                <div class="mbs-dw-stat-val"><?php echo esc_html( count( $todays ) ); ?></div>
                <div class="mbs-dw-stat-label">Today</div>
            </div>
            <div class="mbs-dw-stat">
                <div class="mbs-dw-stat-val">&pound;<?php echo number_format( $stats['revenue_fy'], 0 ); ?></div>
                <div class="mbs-dw-stat-label">Revenue FY</div>
            </div>
        </div>

        <!-- Today's bookings -->
        <div class="mbs-dw-section">
            <h4>📅 Today (<?php echo esc_html( date( 'l j M' ) ); ?>)</h4>
            <?php if ( empty( $todays ) ) : ?>
                <div class="mbs-dw-empty">No bookings today.</div>
            <?php else : ?>
                <?php foreach ( $todays as $b ) :
                    $time = $b->all_day ? 'All day' : ( substr( $b->start_time, 0, 5 ) . '–' . substr( $b->end_time, 0, 5 ) );
                ?>
                <div class="mbs-dw-item">
                    <div>
                        <span class="mbs-dw-space"><?php echo esc_html( $b->space ); ?></span>
                        <span class="mbs-dw-time"><?php echo esc_html( $time ); ?></span>
                        <br><span class="mbs-dw-name"><?php echo esc_html( $b->name ); ?> — <?php echo esc_html( $b->purpose ); ?></span>
                    </div>
                    <span class="mbs-dw-badge mbs-dw-<?php echo esc_attr( $b->status ); ?>"><?php echo esc_html( ucfirst( $b->status ) ); ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Tomorrow's bookings -->
        <div class="mbs-dw-section">
            <h4>📅 Tomorrow (<?php echo esc_html( date( 'l j M', strtotime( '+1 day' ) ) ); ?>)</h4>
            <?php if ( empty( $tomorrows ) ) : ?>
                <div class="mbs-dw-empty">No bookings tomorrow.</div>
            <?php else : ?>
                <?php foreach ( $tomorrows as $b ) :
                    $time = $b->all_day ? 'All day' : ( substr( $b->start_time, 0, 5 ) . '–' . substr( $b->end_time, 0, 5 ) );
                ?>
                <div class="mbs-dw-item">
                    <div>
                        <span class="mbs-dw-space"><?php echo esc_html( $b->space ); ?></span>
                        <span class="mbs-dw-time"><?php echo esc_html( $time ); ?></span>
                        <br><span class="mbs-dw-name"><?php echo esc_html( $b->name ); ?></span>
                    </div>
                    <span class="mbs-dw-badge mbs-dw-<?php echo esc_attr( $b->status ); ?>"><?php echo esc_html( ucfirst( $b->status ) ); ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Pending approvals -->
        <?php if ( ! empty( $pending ) ) : ?>
        <div class="mbs-dw-section">
            <h4>⏳ Pending Approval (<?php echo count( $pending ); ?>)</h4>
            <?php foreach ( array_slice( $pending, 0, 5 ) as $b ) : ?>
            <div class="mbs-dw-item">
                <div>
                    <span class="mbs-dw-space"><?php echo esc_html( $b->name ); ?></span>
                    <br><span class="mbs-dw-name"><?php echo esc_html( $b->space ); ?> — <?php echo esc_html( date( 'j M', strtotime( $b->booking_date ) ) ); ?></span>
                </div>
                <a href="<?php echo admin_url( 'admin.php?page=mathlin-booking&action=view&ref=' . $b->ref ); ?>" class="button button-small button-primary" style="font-size:11px;">Review</a>
            </div>
            <?php endforeach; ?>
            <?php if ( count( $pending ) > 5 ) : ?>
                <div class="mbs-dw-empty">+ <?php echo count( $pending ) - 5; ?> more pending…</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Quick links -->
        <div class="mbs-dw-links">
            <a href="<?php echo admin_url( 'admin.php?page=mathlin-booking' ); ?>" class="button button-small">All Bookings</a>
            <a href="<?php echo admin_url( 'admin.php?page=mathlin-calendar' ); ?>" class="button button-small">Calendar</a>
            <a href="<?php echo admin_url( 'admin.php?page=mathlin-settings' ); ?>" class="button button-small">Settings</a>
        </div>
        <?php
    }
}
