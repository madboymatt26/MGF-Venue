<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap mbs-admin">
    <h1 class="wp-heading-inline">&#9884; Scout Bookings</h1>
    <hr class="wp-header-end">

    <!-- Stats -->
    <div class="nms-stats-row">
        <div class="nms-stat-card">
            <div class="nms-stat-val"><?php echo esc_html( $stats['total'] ); ?></div>
            <div class="nms-stat-label">Active Bookings</div>
        </div>
        <div class="nms-stat-card nms-stat-pending">
            <div class="nms-stat-val"><?php echo esc_html( $stats['pending'] ); ?></div>
            <div class="nms-stat-label">Pending</div>
        </div>
        <div class="nms-stat-card nms-stat-confirmed">
            <div class="nms-stat-val"><?php echo esc_html( $stats['confirmed'] ); ?></div>
            <div class="nms-stat-label">Confirmed</div>
        </div>
        <div class="nms-stat-card nms-stat-revenue">
            <div class="nms-stat-val">&pound;<?php echo number_format( $stats['revenue_fy'], 2 ); ?></div>
            <div class="nms-stat-label">Revenue FY <?php echo esc_html( $stats['fy_label'] ); ?></div>
        </div>
    </div>

    <!-- Filters -->
    <div class="nms-filters">
        <form method="get" action="">
            <input type="hidden" name="page" value="mathlin-booking">
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search bookings…" class="nms-search-input">
            <select name="status" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <option value="pending"   <?php selected( $status, 'pending' ); ?>>Pending</option>
                <option value="confirmed" <?php selected( $status, 'confirmed' ); ?>>Confirmed</option>
                <option value="cancelled" <?php selected( $status, 'cancelled' ); ?>>Cancelled</option>
                <option value="archived"  <?php selected( $status, 'archived' ); ?>>Archived</option>
            </select>
            <button type="submit" class="button">Filter</button>
            <?php if ( $status || $search ) : ?>
                <a href="?page=mathlin-booking" class="button">Clear</a>
            <?php endif; ?>
        </form>
        <div class="nms-filters-right">
            <button id="nms-archive-past" class="button" title="Move all past confirmed/cancelled bookings to archive">
                📦 Archive Past Bookings
            </button>
            <?php if ( $stats['archived'] > 0 ) : ?>
                <span class="nms-muted" style="margin-left:8px;">(<?php echo esc_html( $stats['archived'] ); ?> archived)</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Table -->
    <?php if ( empty( $bookings ) ) : ?>
        <div class="nms-empty">
            <span class="dashicons dashicons-calendar-alt" style="font-size:48px;color:#ccc;"></span>
            <p>No bookings found.</p>
        </div>
    <?php else : ?>
    <table class="wp-list-table widefat fixed striped nms-bookings-table">
        <thead>
            <tr>
                <th>Ref</th>
                <th>Name / Org</th>
                <th>Space</th>
                <th>Date</th>
                <th>Time</th>
                <th>Attendees</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $bookings as $b ) :
            $spaces   = MBS_Bookings::get_spaces();
            $is_daily = isset( $spaces[ $b->space ] ) && $spaces[ $b->space ]['unit'] === 'day';
        ?>
            <tr id="nms-row-<?php echo esc_attr( $b->ref ); ?>">
                <td><strong><?php echo esc_html( $b->ref ); ?></strong></td>
                <td>
                    <?php echo esc_html( $b->name ); ?>
                    <?php if ( $b->organisation ) : ?>
                        <br><small class="nms-muted"><?php echo esc_html( $b->organisation ); ?></small>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html( $b->space ); ?></td>
                <td><?php echo esc_html( date( 'D j M Y', strtotime( $b->booking_date ) ) ); ?></td>
                <td><?php echo $is_daily ? 'All day' : esc_html( $b->start_time . ' – ' . $b->end_time ); ?></td>
                <td><?php echo esc_html( $b->attendees ); ?></td>
                <td><strong>&pound;<?php echo number_format( $b->amount, 2 ); ?></strong></td>
                <td>
                    <span class="nms-status nms-status-<?php echo esc_attr( $b->status ); ?>">
                        <?php echo esc_html( ucfirst( $b->status ) ); ?>
                    </span>
                </td>
                <td>
                    <div class="nms-action-btns">
                        <a href="?page=mathlin-booking&action=view&ref=<?php echo esc_attr( $b->ref ); ?>" class="button button-small">View</a>
                        <a href="?page=mathlin-booking&action=invoice&ref=<?php echo esc_attr( $b->ref ); ?>" class="button button-small">Invoice</a>
                        <?php if ( $b->status === 'pending' ) : ?>
                            <button class="button button-small button-primary nms-btn-confirm" data-ref="<?php echo esc_attr( $b->ref ); ?>">Confirm</button>
                        <?php endif; ?>
                        <?php if ( $b->status !== 'cancelled' && $b->status !== 'archived' ) : ?>
                            <button class="button button-small nms-btn-cancel" data-ref="<?php echo esc_attr( $b->ref ); ?>">Cancel</button>
                        <?php endif; ?>
                        <?php if ( $b->status === 'cancelled' ) : ?>
                            <button class="button button-small nms-btn-reopen" data-ref="<?php echo esc_attr( $b->ref ); ?>">Reopen</button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
