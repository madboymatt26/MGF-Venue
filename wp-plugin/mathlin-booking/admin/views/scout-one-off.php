<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<h2>One-off Scout bookings</h2>
<p>Individual Scout-use reservations, including courses and meetings. Open a reference to view or edit the existing booking. No bookings, payments or emails are changed by this list.</p>
<form method="get" class="mbs-series-filters">
    <input type="hidden" name="page" value="mathlin-scout-nights">
    <input type="hidden" name="tab" value="one-off">
    <label class="screen-reader-text" for="scout-one-off-search">Search one-off Scout bookings</label>
    <input id="scout-one-off-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Name, organisation, reference or purpose">
    <label class="screen-reader-text" for="scout-one-off-status">Status</label>
    <select id="scout-one-off-status" name="status">
        <option value="">All statuses</option>
        <?php foreach ( array( 'pending', 'confirmed', 'deposit_paid', 'paid', 'cancelled', 'archived' ) as $option ) : ?>
            <option value="<?php echo esc_attr( $option ); ?>" <?php selected( $status, $option ); ?>><?php echo esc_html( MBS_Bookings::status_label( $option ) ); ?></option>
        <?php endforeach; ?>
    </select>
    <label class="screen-reader-text" for="scout-one-off-scope">Date range</label>
    <select id="scout-one-off-scope" name="scope">
        <option value="current" <?php selected( $scope, 'current' ); ?>>Current and upcoming</option>
        <option value="ended" <?php selected( $scope, 'ended' ); ?>>Past bookings</option>
        <option value="all" <?php selected( $scope, 'all' ); ?>>All dates</option>
    </select>
    <button class="button">Filter</button>
</form>
<div class="mbs-series-table-wrap">
    <table class="widefat striped mbs-series-table">
        <thead><tr><th>Reference</th><th>Booking / contact</th><th>Date</th><th>Time</th><th>Space</th><th>Status</th></tr></thead>
        <tbody>
        <?php if ( ! $one_off_bookings ) : ?><tr><td colspan="6">No one-off Scout bookings match this filter.</td></tr><?php endif; ?>
        <?php foreach ( $one_off_bookings as $row ) : ?>
            <tr>
                <td data-label="Reference"><a href="<?php echo esc_url( admin_url( 'admin.php?page=mathlin-booking&action=view&ref=' . rawurlencode( $row->ref ) ) ); ?>"><strong><?php echo esc_html( $row->ref ); ?></strong></a></td>
                <td data-label="Booking / contact"><strong><?php echo esc_html( $row->purpose ?: $row->name ); ?></strong><br><?php echo esc_html( $row->name ); ?><?php if ( $row->organisation ) : ?><br><small><?php echo esc_html( $row->organisation ); ?></small><?php endif; ?><br><span class="mbs-series-badge mbs-series-badge--scout">Scout use · no charge</span></td>
                <td data-label="Date"><?php echo esc_html( wp_date( 'D j M Y', strtotime( $row->booking_date ) ) ); ?><?php if ( ! empty( $row->booking_date_end ) && $row->booking_date_end !== $row->booking_date ) : ?><br><small>to <?php echo esc_html( wp_date( 'D j M Y', strtotime( $row->booking_date_end ) ) ); ?></small><?php endif; ?></td>
                <td data-label="Time"><?php echo esc_html( $row->all_day ? 'All day' : substr( (string) $row->start_time, 0, 5 ) . '–' . substr( (string) $row->end_time, 0, 5 ) ); ?></td>
                <td data-label="Space"><?php echo esc_html( $row->space ); ?><?php if ( $row->kitchen ) : ?><br><small>Kitchen included</small><?php endif; ?></td>
                <td data-label="Status"><?php echo esc_html( MBS_Bookings::status_label( $row->status ) ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php if ( $one_off_page > 1 || $one_off_has_next ) :
    $page_args = array( 'page' => 'mathlin-scout-nights', 'tab' => 'one-off', 'scope' => $scope, 'status' => $status, 's' => $search );
?>
    <nav class="tablenav" aria-label="One-off Scout bookings pages">
        <?php if ( $one_off_page > 1 ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $page_args, array( 'paged' => $one_off_page - 1 ) ), admin_url( 'admin.php' ) ) ); ?>">Previous</a><?php endif; ?>
        <span>Page <?php echo (int) $one_off_page; ?></span>
        <?php if ( $one_off_has_next ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $page_args, array( 'paged' => $one_off_page + 1 ) ), admin_url( 'admin.php' ) ) ); ?>">Next</a><?php endif; ?>
    </nav>
<?php endif; ?>
