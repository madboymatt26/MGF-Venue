<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap mbs-admin mbs-archive-admin">
    <h1 class="wp-heading-inline"><?php echo MBS_Admin::brand_mark(); ?>MGF Venue – Archived</h1>
    <hr class="wp-header-end">

    <p>These bookings have been archived. They are kept for record-keeping but no longer appear in the main booking list.</p>

    <!-- Search -->
    <div class="nms-filters">
        <form method="get" action="">
            <input type="hidden" name="page" value="mathlin-archived">
            <label class="screen-reader-text" for="nms-archived-search">Search archived bookings</label>
            <input type="search" id="nms-archived-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search archived bookings…" class="nms-search-input">
            <label>Booking date <select name="archive_range">
                <?php foreach(array('all'=>'All dates','this_year'=>'This year','last_year'=>'Last year','custom'=>'Custom range') as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($range,$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
            </select></label>
            <label>From <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>"></label>
            <label>To <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>"></label>
            <label>Space <select name="archive_space"><option value="">All spaces</option><?php foreach($archive_spaces as $space_name=>$space): ?><option <?php selected($archive_space,$space_name); ?> value="<?php echo esc_attr($space_name); ?>"><?php echo esc_html($space_name); ?></option><?php endforeach; ?></select></label>
            <button type="submit" class="button button-primary">Apply filters</button>
            <p class="description">From and To apply to Custom range. Includes stays overlapping the booking dates.</p>
            <?php if ( $search || $range !== 'all' || $archive_space ) : ?>
                <a href="?page=mathlin-archived" class="button">Reset filters</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if($archive_error): ?><div class="notice notice-error"><p><?php echo esc_html($archive_error); ?></p></div><?php endif; ?>
    <p class="nms-muted">Booking dates: <?php echo esc_html($range==='all'?'All dates':(($date_from?:'Any start').' to '.($date_to?:'Any end'))); ?>. Newest first.</p>
    <?php $archive_pager(); ?>
    <?php if ( empty( $bookings ) ) : ?>
        <div class="nms-empty">
            <span class="dashicons dashicons-archive" style="font-size:48px;color:#ccc;"></span>
            <p>No archived bookings found.</p>
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
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $bookings as $b ) :
            $is_daily = ! empty( $b->all_day );
            $archive_series = ! empty( $b->series_id ) ? MBS_Series::get( $b->series_id ) : null;
            $archive_series_billing = $archive_series && in_array( MBS_Series::billing_treatment_for_booking( $b, $archive_series ), array( 'manual_consolidated', 'invoice_managed', 'none' ), true );
        ?>
            <tr>
                <td data-label="Ref"><strong><?php echo esc_html( $b->ref ); ?></strong></td>
                <td data-label="Name">
                    <?php echo esc_html( $b->name ); ?>
                    <?php if ( $b->organisation ) : ?>
                        <br><small class="nms-muted"><?php echo esc_html( $b->organisation ); ?></small>
                    <?php endif; ?>
                </td>
                <td data-label="Space"><?php echo esc_html( $b->space ); ?></td>
                <td data-label="Date"><?php echo esc_html( date( 'D j M Y', strtotime( $b->booking_date ) ) ); ?></td>
                <td data-label="Time"><?php echo $is_daily ? 'All day' : esc_html( $b->start_time . ' – ' . $b->end_time ); ?></td>
                <td data-label="Guests"><?php echo esc_html( $b->attendees ); ?></td>
                <td data-label="Amount"><strong>&pound;<?php echo number_format( $b->amount, 2 ); ?></strong></td>
                <td data-label="Actions">
                    <div class="nms-action-btns">
                        <a href="?page=mathlin-booking&action=view&ref=<?php echo esc_attr( $b->ref ); ?>&amp;archive_return=<?php echo rawurlencode($archive_return); ?>" class="button button-small">View</a>
                        <?php if ( $archive_series_billing ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=mathlin-series&ref=' . rawurlencode( $b->series_id ) ) ); ?>" class="button button-small">Series invoices</a>
                        <?php else : ?>
                            <a href="?page=mathlin-booking&action=invoice&ref=<?php echo esc_attr( $b->ref ); ?>" class="button button-small">Invoice</a>
                        <?php endif; ?>
                        <details class="mbs-archive-actions"><summary>More actions</summary><button class="button button-small nms-btn-reopen" data-ref="<?php echo esc_attr( $b->ref ); ?>">Restore</button>
                        <button class="button button-small nms-btn-delete" data-ref="<?php echo esc_attr( $b->ref ); ?>">Delete</button></details>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <?php $archive_pager(); ?>
</div>
