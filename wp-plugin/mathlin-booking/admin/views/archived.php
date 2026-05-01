<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap mbs-admin">
    <h1 class="wp-heading-inline">&#9884; Scout Bookings – Archived</h1>
    <hr class="wp-header-end">

    <p>These bookings have been archived. They are kept for record-keeping but no longer appear in the main booking list.</p>

    <!-- Search -->
    <div class="nms-filters">
        <form method="get" action="">
            <input type="hidden" name="page" value="mathlin-archived">
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search archived bookings…" class="nms-search-input">
            <button type="submit" class="button">Search</button>
            <?php if ( $search ) : ?>
                <a href="?page=mathlin-archived" class="button">Clear</a>
            <?php endif; ?>
        </form>
    </div>

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
            $spaces   = MBS_Bookings::get_spaces();
            $is_daily = isset( $spaces[ $b->space ] ) && $spaces[ $b->space ]['unit'] === 'day';
        ?>
            <tr>
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
                    <div class="nms-action-btns">
                        <a href="?page=mathlin-booking&action=view&ref=<?php echo esc_attr( $b->ref ); ?>" class="button button-small">View</a>
                        <a href="?page=mathlin-booking&action=invoice&ref=<?php echo esc_attr( $b->ref ); ?>" class="button button-small">Invoice</a>
                        <button class="button button-small nms-btn-reopen" data-ref="<?php echo esc_attr( $b->ref ); ?>">Restore</button>
                        <button class="button button-small nms-btn-delete" data-ref="<?php echo esc_attr( $b->ref ); ?>">Delete</button>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
