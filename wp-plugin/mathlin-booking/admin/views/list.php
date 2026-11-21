<?php if ( ! defined( 'ABSPATH' ) ) exit; $free_campsite = class_exists( 'MBS_Campsite_Policy' ) && MBS_Campsite_Policy::enabled(); ?>
<div class="wrap mbs-admin">
    <h1 class="wp-heading-inline"><?php echo MBS_Admin::brand_mark(); ?>MGF Venue</h1>
    <a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=mathlin-add-booking' ) ); ?>">Add booking</a>
    <?php if ( MBS_Payment_Features::enabled( 'additional_charges' ) ) : ?><a class="page-title-action" href="<?php echo esc_url(MBS_Additional_Charges::admin_url()); ?>">Additional charges</a><?php endif; ?>
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
        <?php if ( MBS_Payment_Features::enabled( 'reports' ) ) : ?><div class="nms-stat-card nms-stat-paid">
            <div class="nms-stat-val"><?php echo esc_html( $stats['paid'] ); ?></div>
            <div class="nms-stat-label">Paid</div>
        </div>
        <div class="nms-stat-card nms-stat-revenue">
            <div class="nms-stat-val">&pound;<?php echo number_format( $stats['revenue_fy'], 2 ); ?></div>
            <div class="nms-stat-label">Revenue FY <?php echo esc_html( $stats['fy_label'] ); ?></div>
        </div>
        <?php endif; ?>
    </div>

    <?php MBS_Attention::render(); ?>

    <!-- Filters -->
    <div class="nms-filters">
        <form method="get" action="">
            <input type="hidden" name="page" value="mathlin-booking">
            <label class="screen-reader-text" for="nms-booking-search">Search bookings</label>
            <input type="search" id="nms-booking-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search bookings…" class="nms-search-input">
            <label class="screen-reader-text" for="nms-status-filter">Filter by booking status</label>
            <select id="nms-status-filter" name="status" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <option value="pending"   <?php selected( $status, 'pending' ); ?>>Pending</option>
                <option value="confirmed" <?php selected( $status, 'confirmed' ); ?>>Confirmed</option>
                <?php if ( MBS_Payment_Features::enabled() ) : ?><option value="deposit_paid" <?php selected( $status, 'deposit_paid' ); ?>>Deposit Paid</option>
                <option value="paid" <?php selected( $status, 'paid' ); ?>>Paid</option><?php endif; ?>
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
                <span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span> Export CSV
            </a>
            <button id="nms-archive-past" class="button" title="Move all past confirmed/cancelled bookings to archive">
                <span class="dashicons dashicons-archive" aria-hidden="true"></span> Archive Past Bookings
            </button>
            <?php if ( $stats['archived'] > 0 ) : ?>
                <span class="nms-muted" style="margin-left:8px;">(<?php echo esc_html( $stats['archived'] ); ?> archived)</span>
            <?php endif; ?>
        </div>
    </div>

    <nav class="mbs-list-pagination" aria-label="Booking pages">
        <?php if ( ( $bookings_page ?? 1 ) > 1 ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'mathlin-booking', 's' => $search, 'status' => $status, 'paged' => $bookings_page - 1 ), admin_url( 'admin.php' ) ) ); ?>">Previous page</a><?php endif; ?>
        <span>Page <?php echo (int) ( $bookings_page ?? 1 ); ?> · up to 50 reservations per page</span>
        <?php if ( ! empty( $bookings_has_next ) ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'mathlin-booking', 's' => $search, 'status' => $status, 'paged' => ( $bookings_page ?? 1 ) + 1 ), admin_url( 'admin.php' ) ) ); ?>">Next page</a><?php endif; ?>
    </nav>

    <!-- Table -->
    <?php if ( empty( $bookings ) ) : ?>
        <div class="nms-empty">
            <span class="dashicons dashicons-calendar-alt" style="font-size:48px;color:#ccc;"></span>
            <p>No bookings found.</p>
        </div>
    <?php else : ?>

    <?php $series_present = (bool) array_filter( (array) $bookings, function ( $row ) { return ! empty( $row->series_id ); } ); ?>
    <!-- Bulk actions bar -->
    <div class="nms-bulk-bar" role="group" aria-label="Bulk actions for selected bookings">
        <label class="nms-bulk-selectall" for="nms-select-all-bar"><input type="checkbox" id="nms-select-all-bar"> Select all on this page</label>
        <label class="screen-reader-text" for="nms-bulk-action">Bulk action</label>
        <select id="nms-bulk-action" class="nms-bulk-select">
            <option value="">Bulk actions</option>
            <option value="confirmed" data-from="pending">&#10003; Confirm selected</option>
            <?php if ( MBS_Payment_Features::enabled( 'manual' ) ) : ?><option value="paid" data-from="confirmed">&pound; Mark paid</option><?php endif; ?>
            <option value="cancelled" data-from="pending,confirmed">&#10007; Cancel selected</option>
            <option value="archived" data-from="confirmed,paid,cancelled">Archive selected</option>
        </select>
        <button id="nms-bulk-apply" class="button button-primary">Apply</button>
        <span id="nms-bulk-count" class="nms-bulk-count nms-muted" role="status" aria-live="polite"></span>
        <?php if ( $series_present ) : ?><span class="nms-muted nms-bulk-seriesnote">Recurring series have no checkbox. Use the buttons on each series row.</span><?php endif; ?>
        <span id="nms-bulk-msg" class="nms-settings-msg" role="status" aria-live="polite"></span>
    </div>

    <table class="wp-list-table widefat fixed striped nms-bookings-table">
        <thead>
            <tr>
                <th class="nms-bulk-col" style="width:30px;"><label class="screen-reader-text" for="nms-select-all">Select all bookings</label><input type="checkbox" id="nms-select-all" title="Select all"></th>
                <th>Ref</th>
                <th>Name / Org</th>
                <th>Space</th>
                <th>Date</th>
                <th>Time</th>
                <th>Attendees</th>
                <?php if ( MBS_Payment_Features::enabled() ) : ?><th>Amount</th><th>Paid</th><?php endif; ?>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php
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
                $series_record = MBS_Series::get( $b->series_id );
                $series_status = $series_record ? $series_record->status : $b->status;
                $first_date  = $series_bookings[0]->booking_date;
                $last_date   = $series_bookings[ $series_count - 1 ]->booking_date;
                ?>
                <tr id="nms-row-<?php echo esc_attr( $b->ref ); ?>" class="nms-series-row" style="background:#f9f7ff;">
                    <td class="nms-bulk-cell nms-bulk-cell--series" title="Series are managed with their own buttons, not bulk actions"></td>
                    <td data-label="Ref">
                        <strong><?php echo esc_html( $b->series_id ); ?></strong>
                        <br><small class="nms-muted"><?php echo $series_count; ?> bookings</small>
                    </td>
                    <td data-label="Name">
                        <?php echo esc_html( $b->name ); ?>
                        <?php if ( $b->organisation ) { ?><br><small class="nms-muted"><?php echo esc_html( $b->organisation ); ?></small><?php } ?>
                    </td>
                    <td data-label="Space"><?php echo esc_html( $b->space ); ?></td>
                    <td data-label="Date">
                        <?php echo esc_html( date( 'j M', strtotime( $first_date ) ) ); ?> – <?php echo esc_html( date( 'j M Y', strtotime( $last_date ) ) ); ?>
                        <br><small class="nms-muted"><?php echo $free_campsite ? 'Grouped dates × ' : 'Weekly × '; ?><?php echo $series_count; ?></small>
                    </td>
                    <td data-label="Time"><?php echo $is_daily ? 'All day' : esc_html( $b->start_time . ' – ' . $b->end_time ); ?></td>
                    <td data-label="Guests"><?php echo esc_html( $b->attendees ); ?></td>
                    <?php if ( MBS_Payment_Features::enabled() ) : ?><td data-label="Amount"><strong>&pound;<?php echo number_format( $series_total, 2 ); ?></strong></td><td data-label="Paid">—</td><?php endif; ?>
                    <td data-label="Status"><span class="nms-status nms-status-<?php echo esc_attr( $series_status ); ?>"><?php echo esc_html( MBS_Bookings::status_label( $series_status ) ); ?></span></td>
                    <td data-label="Actions">
                        <div class="nms-action-btns">
                            <button class="button button-small nms-toggle-series" data-series="<?php echo esc_attr( $b->series_id ); ?>">Expand</button>
                            <?php if ( $series_record ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mathlin-series&ref=' . rawurlencode( $b->series_id ) ) ); ?>" class="button button-small">View series</a>
                            <?php else : ?>
                                <a href="?page=mathlin-booking&action=view&ref=<?php echo esc_attr( $b->ref ); ?>" class="button button-small">View legacy series</a>
                            <?php endif; ?>
                            <?php if ( $series_status === 'pending' ) { ?>
                                <button class="button button-small button-primary nms-btn-series-status" data-series="<?php echo esc_attr( $b->series_id ); ?>" data-status="confirmed" data-expected-status="<?php echo esc_attr( $series_status ); ?>" data-expected-version="<?php echo esc_attr( $series_record ? $series_record->version : 0 ); ?>">Review &amp; Approve</button>
                            <?php } ?>
                            <?php if ( $series_record && $series_status === 'confirmed' ) { ?>
                                <button class="button button-small nms-btn-resend-series" data-series="<?php echo esc_attr( $b->series_id ); ?>">Resend Approval</button>
                            <?php } ?>
                            <?php if ( $series_record ) { ?>
                                <button class="button button-small nms-btn-series-status" data-series="<?php echo esc_attr( $b->series_id ); ?>" data-status="cancelled" data-scope="future" data-expected-status="<?php echo esc_attr( $series_status ); ?>" data-expected-version="<?php echo esc_attr( $series_record->version ); ?>">Cancel Future</button>
                                <button class="button button-small nms-btn-series-status" data-series="<?php echo esc_attr( $b->series_id ); ?>" data-status="cancelled" data-scope="all" data-expected-status="<?php echo esc_attr( $series_status ); ?>" data-expected-version="<?php echo esc_attr( $series_record->version ); ?>">Cancel All</button>
                                <?php if ( $series_status === 'cancelled' ) { ?>
                                    <button class="button button-small nms-btn-delete-archive-series" data-series="<?php echo esc_attr( $b->series_id ); ?>" style="color:#b91c1c;">Delete &amp; Archive</button>
                                <?php } ?>
                            <?php } else { ?>
                                <button class="button button-small nms-btn-series-status" data-series="<?php echo esc_attr( $b->series_id ); ?>" data-status="cancelled">Cancel All</button>
                            <?php } ?>
                        </div>
                    </td>
                </tr>
                <?php foreach ( $series_bookings as $sb ) { ?>
                <tr class="nms-series-child nms-series-<?php echo esc_attr( $b->series_id ); ?>" style="display:none;background:#fdfcff;">
                    <td class="nms-bulk-cell"></td>
                    <td data-label="Ref" style="padding-left:24px;"><small><?php echo esc_html( $sb->ref ); ?></small></td>
                    <td data-label="Name"></td>
                    <td data-label="Space"></td>
                    <td data-label="Date"><?php echo esc_html( wp_date( 'D j M Y', strtotime( $sb->booking_date ) ) . ( ! empty( $sb->booking_date_end ) && $sb->booking_date_end !== $sb->booking_date ? ' – ' . wp_date( 'D j M Y', strtotime( $sb->booking_date_end ) ) : '' ) ); ?></td>
                    <td data-label="Time"><?php echo ! empty( $sb->all_day ) ? 'All day' : esc_html( $sb->start_time . ' – ' . $sb->end_time ); ?></td>
                    <td data-label="Guests"></td>
                    <?php if ( MBS_Payment_Features::enabled() ) : ?><td data-label="Amount">&pound;<?php echo number_format( $sb->amount, 2 ); ?></td>
                    <td data-label="Paid">
                        <?php
                        $sb_paid = (float) ( $sb->amount_paid ?? 0 );
                        $sb_balance = (float) $sb->amount - $sb_paid;
                        if ( $sb_paid > 0 && $sb_balance < -0.01 ) : ?>
                            <span style="font-size:0.7rem;color:#1e40af;font-weight:600;">Refund: &pound;<?php echo number_format( abs( $sb_balance ), 2 ); ?></span>
                        <?php elseif ( $sb_paid > 0 ) : ?>
                            <span style="color:#065f46;font-size:0.8rem;">&pound;<?php echo number_format( $sb_paid, 2 ); ?></span>
                        <?php else : ?>
                            <span style="color:#595959;">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?><td data-label="Status"><span class="nms-status nms-status-<?php echo esc_attr( $sb->status ); ?>"><?php echo esc_html( MBS_Bookings::status_label( $sb->status ) ); ?></span></td>
                    <td data-label="Actions"><a href="?page=mathlin-booking&action=view&ref=<?php echo esc_attr( $sb->ref ); ?>" class="button button-small">View occurrence</a></td>
                </tr>
                <?php } ?>
            <?php } else {
                // Regular (non-series) booking row
            ?>
                <tr id="nms-row-<?php echo esc_attr( $b->ref ); ?>">
                    <td class="nms-bulk-cell"><input type="checkbox" class="nms-bulk-check" value="<?php echo esc_attr( $b->ref ); ?>" data-status="<?php echo esc_attr( $b->status ); ?>" aria-label="<?php echo esc_attr( 'Select booking ' . $b->ref ); ?>"></td>
                    <td data-label="Ref"><strong><?php echo esc_html( $b->ref ); ?></strong></td>
                    <td data-label="Name">
                        <?php echo esc_html( $b->name ); ?>
                        <?php if ( $b->organisation ) { ?>
                            <br><small class="nms-muted"><?php echo esc_html( $b->organisation ); ?></small>
                        <?php } ?>
                    </td>
                    <td data-label="Space"><?php echo esc_html( $b->space ); ?></td>
                    <td data-label="Date"><?php echo esc_html( wp_date( 'D j M Y', strtotime( $b->booking_date ) ) . ( ! empty( $b->booking_date_end ) && $b->booking_date_end !== $b->booking_date ? ' – ' . wp_date( 'D j M Y', strtotime( $b->booking_date_end ) ) : '' ) ); ?></td>
                    <td data-label="Time"><?php echo $is_daily ? 'All day' : esc_html( $b->start_time . ' – ' . $b->end_time ); ?></td>
                    <td data-label="Guests"><?php echo esc_html( $b->attendees ); ?></td>
                    <?php if ( MBS_Payment_Features::enabled() ) : ?><td data-label="Amount"><strong>&pound;<?php echo number_format( $b->amount, 2 ); ?></strong></td>
                    <td data-label="Paid">
                        <?php
                        $paid = (float) ( $b->amount_paid ?? 0 );
                        $balance = (float) $b->amount - $paid;
                        if ( $paid > 0 && $balance > 0.01 ) : ?>
                            <span style="color:#065f46;">&pound;<?php echo number_format( $paid, 2 ); ?></span><br>
                            <span style="font-size:0.7rem;color:#991b1b;font-weight:600;">Due: &pound;<?php echo number_format( $balance, 2 ); ?></span>
                        <?php elseif ( $paid > 0 && $balance < -0.01 ) : ?>
                            <span style="color:#065f46;">&pound;<?php echo number_format( $paid, 2 ); ?></span><br>
                            <span style="font-size:0.7rem;color:#1e40af;font-weight:600;">Refund: &pound;<?php echo number_format( abs( $balance ), 2 ); ?></span>
                        <?php elseif ( $paid > 0 ) : ?>
                            <span style="color:#065f46;">&pound;<?php echo number_format( $paid, 2 ); ?> ✓</span>
                        <?php else : ?>
                            <span style="color:#6b7280;">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?><td data-label="Status">
                        <span class="nms-status nms-status-<?php echo esc_attr( $b->status ); ?>">
                            <?php echo esc_html( MBS_Bookings::status_label( $b->status ) ); ?>
                        </span>
                    </td>
                    <td data-label="Actions">
                        <div class="nms-action-btns">
                            <a href="?page=mathlin-booking&action=view&ref=<?php echo esc_attr( $b->ref ); ?>" class="button button-small">View</a>
                            <?php if ( MBS_Payment_Features::enabled( 'invoices' ) && ! $free_campsite ) : ?><a href="?page=mathlin-booking&action=invoice&ref=<?php echo esc_attr( $b->ref ); ?>" class="button button-small">Invoice</a><?php endif; ?>
                            <?php if ( $b->status === 'pending' ) { ?>
                                <button class="button button-small button-primary nms-btn-confirm" data-ref="<?php echo esc_attr( $b->ref ); ?>">Confirm</button>
                            <?php } ?>
                            <?php
                            $list_dep_settings = MBS_Bookings::get_deposit_settings();
                            if ( MBS_Payment_Features::enabled( 'manual' ) && ! $free_campsite && $b->status === 'confirmed' && $list_dep_settings['enabled'] && ! MBS_Bookings::requires_full_payment( $b->booking_date ) ) { ?>
                                <button class="button button-small nms-btn-deposit-paid" data-ref="<?php echo esc_attr( $b->ref ); ?>" style="background:#f59e0b;color:#fff;border-color:#d97706;">Deposit Paid</button>
                            <?php } ?>
                            <?php if ( MBS_Payment_Features::enabled( 'manual' ) && ! $free_campsite && ( $b->status === 'confirmed' || $b->status === 'deposit_paid' ) ) { ?>
                                <button class="button button-small nms-btn-paid" data-ref="<?php echo esc_attr( $b->ref ); ?>">Fully Paid</button>
                            <?php } ?>
                            <?php if ( MBS_Payment_Features::enabled( 'manual' ) && $b->status === 'paid' ) { ?>
                                <button class="button button-small nms-btn-unpaid" data-ref="<?php echo esc_attr( $b->ref ); ?>">Undo Paid</button>
                            <?php } ?>
                            <?php if ( MBS_Payment_Features::enabled( 'manual' ) && $b->status === 'deposit_paid' ) { ?>
                                <button class="button button-small nms-btn-undo-deposit" data-ref="<?php echo esc_attr( $b->ref ); ?>">Undo Deposit</button>
                            <?php } ?>
                            <?php if ( $b->status !== 'cancelled' && $b->status !== 'archived' && $b->status !== 'paid' && $b->status !== 'deposit_paid' ) { ?>
                                <button class="button button-small nms-btn-cancel" data-ref="<?php echo esc_attr( $b->ref ); ?>">Cancel</button>
                            <?php } ?>
                            <?php if ( $b->status === 'cancelled' ) { ?>
                                <button class="button button-small nms-btn-reopen" data-ref="<?php echo esc_attr( $b->ref ); ?>">Reopen</button>
                            <?php } ?>
                            <?php if ( in_array( $b->status, array( 'confirmed', 'deposit_paid', 'paid', 'cancelled' ) ) ) { ?>
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
<?php include __DIR__ . '/partials/approval-modal.php'; ?>
