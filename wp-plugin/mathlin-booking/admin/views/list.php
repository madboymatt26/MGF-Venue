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
        <div class="nms-stat-card nms-stat-paid">
            <div class="nms-stat-val"><?php echo esc_html( $stats['paid'] ); ?></div>
            <div class="nms-stat-label">Paid</div>
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
                <option value="deposit_paid" <?php selected( $status, 'deposit_paid' ); ?>>Deposit Paid</option>
                <option value="paid" <?php selected( $status, 'paid' ); ?>>Paid</option>
                <option value="cancelled" <?php selected( $status, 'cancelled' ); ?>>Cancelled</option>
            </select>
            <button type="submit" class="button">Filter</button>
            <?php if ( $status || $search ) : ?>
                <a href="?page=mathlin-booking" class="button">Clear</a>
            <?php endif; ?>
        </form>
        <div class="nms-filters-right">
            <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=mbs_export_csv&nonce=' . wp_create_nonce( 'mbs_admin_nonce' ) .
                ( $status ? '&status=' . urlencode( $status ) : '' ) .
                ( $search ? '&search=' . urlencode( $search ) : '' )
            ) ); ?>" class="button" title="Download bookings as CSV spreadsheet">
                📊 Export CSV
            </a>
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

    <!-- Bulk actions bar -->
    <div class="nms-bulk-bar" style="display:flex;gap:8px;align-items:center;margin-bottom:12px;">
        <select id="nms-bulk-action" style="min-width:160px;">
            <option value="">Bulk Actions</option>
            <option value="confirmed">✓ Confirm Selected</option>
            <option value="paid">💰 Mark Paid</option>
            <option value="cancelled">✗ Cancel Selected</option>
            <option value="archived">📦 Archive Selected</option>
        </select>
        <button id="nms-bulk-apply" class="button">Apply</button>
        <span id="nms-bulk-count" class="nms-muted" style="font-size:0.85rem;"></span>
        <span id="nms-bulk-msg" class="nms-settings-msg" style="margin-left:8px;"></span>
    </div>

    <table class="wp-list-table widefat fixed striped nms-bookings-table">
        <thead>
            <tr>
                <th style="width:30px;"><input type="checkbox" id="nms-select-all" title="Select all"></th>
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
        <?php
        $spaces = MBS_Bookings::get_spaces();

        // Pre-group series bookings
        $series_shown = array();
        $grouped = array();
        foreach ( $bookings as $b ) {
            if ( ! empty( $b->series_id ) ) {
                $grouped[ $b->series_id ][] = $b;
            }
        }

        foreach ( $bookings as $b ) {
            $is_daily = ! empty( $b->all_day );

            // Series booking — skip if already shown
            if ( ! empty( $b->series_id ) && isset( $series_shown[ $b->series_id ] ) ) {
                continue;
            }

            // Series booking — show summary row
            if ( ! empty( $b->series_id ) ) {
                $series_shown[ $b->series_id ] = true;
                $series_bookings = $grouped[ $b->series_id ];
                $series_count    = count( $series_bookings );
                $series_total    = 0;
                foreach ( $series_bookings as $sb_calc ) {
                    $series_total += (float) $sb_calc->amount;
                }
                $first_date  = $series_bookings[0]->booking_date;
                $last_date   = $series_bookings[ $series_count - 1 ]->booking_date;
                ?>
                <tr id="nms-row-<?php echo esc_attr( $b->ref ); ?>" class="nms-series-row" style="background:#f9f7ff;">
                    <td><input type="checkbox" class="nms-bulk-check" value="<?php echo esc_attr( $b->ref ); ?>" data-series="<?php echo esc_attr( $b->series_id ); ?>"></td>
                    <td>
                        <strong><?php echo esc_html( $b->series_id ); ?></strong>
                        <br><small class="nms-muted"><?php echo $series_count; ?> bookings</small>
                    </td>
                    <td>
                        <?php echo esc_html( $b->name ); ?>
                        <?php if ( $b->organisation ) { ?><br><small class="nms-muted"><?php echo esc_html( $b->organisation ); ?></small><?php } ?>
                    </td>
                    <td><?php echo esc_html( $b->space ); ?></td>
                    <td>
                        <?php echo esc_html( date( 'j M', strtotime( $first_date ) ) ); ?> – <?php echo esc_html( date( 'j M Y', strtotime( $last_date ) ) ); ?>
                        <br><small class="nms-muted">Weekly × <?php echo $series_count; ?></small>
                    </td>
                    <td><?php echo $is_daily ? 'All day' : esc_html( $b->start_time . ' – ' . $b->end_time ); ?></td>
                    <td><?php echo esc_html( $b->attendees ); ?></td>
                    <td><strong>&pound;<?php echo number_format( $series_total, 2 ); ?></strong></td>
                    <td><span class="nms-status nms-status-<?php echo esc_attr( $b->status ); ?>"><?php echo esc_html( MBS_Bookings::status_label( $b->status ) ); ?></span></td>
                    <td>
                        <div class="nms-action-btns">
                            <button class="button button-small nms-toggle-series" data-series="<?php echo esc_attr( $b->series_id ); ?>">▶ Expand</button>
                            <a href="?page=mathlin-booking&action=view&ref=<?php echo esc_attr( $b->ref ); ?>" class="button button-small">View</a>
                            <?php if ( $b->status === 'pending' ) { ?>
                                <button class="button button-small button-primary nms-btn-series-status" data-series="<?php echo esc_attr( $b->series_id ); ?>" data-status="confirmed">Confirm All</button>
                            <?php } ?>
                            <button class="button button-small nms-btn-series-status" data-series="<?php echo esc_attr( $b->series_id ); ?>" data-status="cancelled">Cancel All</button>
                        </div>
                    </td>
                </tr>
                <?php foreach ( $series_bookings as $sb ) { ?>
                <tr class="nms-series-child nms-series-<?php echo esc_attr( $b->series_id ); ?>" style="display:none;background:#fdfcff;">
                    <td style="padding-left:24px;"><small><?php echo esc_html( $sb->ref ); ?></small></td>
                    <td></td>
                    <td></td>
                    <td><?php echo esc_html( date( 'D j M Y', strtotime( $sb->booking_date ) ) ); ?></td>
                    <td><?php echo ! empty( $sb->all_day ) ? 'All day' : esc_html( $sb->start_time . ' – ' . $sb->end_time ); ?></td>
                    <td></td>
                    <td>&pound;<?php echo number_format( $sb->amount, 2 ); ?></td>
                    <td><span class="nms-status nms-status-<?php echo esc_attr( $sb->status ); ?>"><?php echo esc_html( MBS_Bookings::status_label( $sb->status ) ); ?></span></td>
                    <td><a href="?page=mathlin-booking&action=view&ref=<?php echo esc_attr( $sb->ref ); ?>" class="button button-small">View</a></td>
                </tr>
                <?php } ?>
            <?php } else {
                // Regular (non-series) booking row
            ?>
                <tr id="nms-row-<?php echo esc_attr( $b->ref ); ?>">
                    <td><input type="checkbox" class="nms-bulk-check" value="<?php echo esc_attr( $b->ref ); ?>"></td>
                    <td><strong><?php echo esc_html( $b->ref ); ?></strong></td>
                    <td>
                        <?php echo esc_html( $b->name ); ?>
                        <?php if ( $b->organisation ) { ?>
                            <br><small class="nms-muted"><?php echo esc_html( $b->organisation ); ?></small>
                        <?php } ?>
                    </td>
                    <td><?php echo esc_html( $b->space ); ?></td>
                    <td><?php echo esc_html( date( 'D j M Y', strtotime( $b->booking_date ) ) ); ?></td>
                    <td><?php echo $is_daily ? 'All day' : esc_html( $b->start_time . ' – ' . $b->end_time ); ?></td>
                    <td><?php echo esc_html( $b->attendees ); ?></td>
                    <td><strong>&pound;<?php echo number_format( $b->amount, 2 ); ?></strong></td>
                    <td>
                        <span class="nms-status nms-status-<?php echo esc_attr( $b->status ); ?>">
                            <?php echo esc_html( MBS_Bookings::status_label( $b->status ) ); ?>
                        </span>
                    </td>
                    <td>
                        <div class="nms-action-btns">
                            <a href="?page=mathlin-booking&action=view&ref=<?php echo esc_attr( $b->ref ); ?>" class="button button-small">View</a>
                            <a href="?page=mathlin-booking&action=invoice&ref=<?php echo esc_attr( $b->ref ); ?>" class="button button-small">Invoice</a>
                            <?php if ( $b->status === 'pending' ) { ?>
                                <button class="button button-small button-primary nms-btn-confirm" data-ref="<?php echo esc_attr( $b->ref ); ?>">Confirm</button>
                            <?php } ?>
                            <?php if ( $b->status === 'confirmed' ) { ?>
                                <button class="button button-small nms-btn-paid" data-ref="<?php echo esc_attr( $b->ref ); ?>">Mark Paid</button>
                            <?php } ?>
                            <?php if ( $b->status === 'paid' ) { ?>
                                <button class="button button-small nms-btn-unpaid" data-ref="<?php echo esc_attr( $b->ref ); ?>">Undo Paid</button>
                            <?php } ?>
                            <?php if ( $b->status !== 'cancelled' && $b->status !== 'archived' && $b->status !== 'paid' ) { ?>
                                <button class="button button-small nms-btn-cancel" data-ref="<?php echo esc_attr( $b->ref ); ?>">Cancel</button>
                            <?php } ?>
                            <?php if ( $b->status === 'cancelled' ) { ?>
                                <button class="button button-small nms-btn-reopen" data-ref="<?php echo esc_attr( $b->ref ); ?>">Reopen</button>
                            <?php } ?>
                            <?php if ( in_array( $b->status, array( 'confirmed', 'paid', 'cancelled' ) ) ) { ?>
                                <button class="button button-small nms-btn-archive" data-ref="<?php echo esc_attr( $b->ref ); ?>">Archive</button>
                            <?php } ?>
                        </div>
                    </td>
                </tr>
            <?php } ?>
        <?php } ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
