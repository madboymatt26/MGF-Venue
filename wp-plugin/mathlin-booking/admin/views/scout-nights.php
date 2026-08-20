<?php if ( ! defined( 'ABSPATH' ) ) exit;
$today = wp_date( 'Y-m-d' );
$status_label = static function ( $row, $summary ) use ( $today ) {
    if ( $row->status === 'pending' ) return 'Pending';
    if ( $row->status === 'cancelled' ) return 'Cancelled';
    if ( $row->status === 'cancelled_future' || ( $summary && (int) $summary->future_active_count === 0 && (int) $summary->future_cancelled_count > 0 ) ) return 'Cancelled future';
    if ( $summary && empty( $summary->next_date ) && ! empty( $summary->last_date ) && $summary->last_date < $today ) return 'Ended';
    return 'Active';
};
?>
<div class="wrap nms-admin-wrap mbs-scout-series-admin">
    <h1><?php echo MBS_Admin::brand_mark(); ?> Scout Nights</h1>
    <p>Manage internal Scout-use series. They block public availability, remain free of charge and do not send hirer emails.</p>

    <?php if ( $registration_error ) : ?>
        <div class="notice notice-error"><p>Some older Scout series could not be registered: <?php echo esc_html( $registration_error->get_error_message() ); ?></p></div>
    <?php endif; ?>

    <?php if ( ! empty( $external_series_redirect ) ) : ?>
        <div class="notice notice-info"><p>This is an external customer series. <a href="<?php echo esc_url( $external_series_redirect ); ?>">Manage it in Recurring Series</a>.</p></div>
    <?php elseif ( ! empty( $ref ) && ( ! $series || empty( $series->scout_use ) ) ) : ?>
        <div class="notice notice-error"><p>Scout series not found.</p></div>
    <?php elseif ( ! $series ) : ?>
        <details class="postbox mbs-scout-create" <?php echo empty( $series_rows ) ? 'open' : ''; ?>>
            <summary><strong>Create Scout series</strong><span>Add a weekly no-charge section booking</span></summary>
            <div class="mbs-scout-create__body">
                <div class="mbs-scout-form-grid">
                    <label>Space
                        <select id="scout-space">
                            <?php foreach ( $spaces as $name => $info ) : ?>
                                <option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Day of week
                        <select id="scout-day">
                            <option value="1">Monday</option><option value="2">Tuesday</option><option value="3">Wednesday</option>
                            <option value="4">Thursday</option><option value="5">Friday</option><option value="6">Saturday</option><option value="7">Sunday</option>
                        </select>
                    </label>
                    <label>Start time <input type="time" id="scout-start" value="18:30"></label>
                    <label>End time <input type="time" id="scout-end" value="20:00"></label>
                    <label>Section / purpose <input type="text" id="scout-purpose" value="Scouts" placeholder="e.g. Beavers, Cubs, Scouts"></label>
                    <label>Start date <input type="date" id="scout-date-from" value="<?php echo esc_attr( $today ); ?>"></label>
                    <label>End date <input type="date" id="scout-date-to" value="<?php echo esc_attr( wp_date( 'Y-m-d', strtotime( '+1 year' ) ) ); ?>"></label>
                </div>
                <div class="mbs-scout-form-actions">
                    <button id="nms-create-scout-recurring" class="button button-primary">Create Scout series</button>
                    <span id="nms-scout-msg" role="status"></span>
                </div>
            </div>
        </details>

        <form method="get" class="mbs-series-filters">
            <input type="hidden" name="page" value="mathlin-scout-nights">
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Section, reference or space">
            <select name="status">
                <option value="">All statuses</option>
                <?php foreach ( array( 'pending', 'confirmed', 'cancelled_future', 'cancelled' ) as $option ) : ?>
                    <option value="<?php echo esc_attr( $option ); ?>" <?php selected( $status, $option ); ?>><?php echo esc_html( ucfirst( str_replace( '_', ' ', $option ) ) ); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="scope">
                <option value="current" <?php selected( $scope, 'current' ); ?>>Current and upcoming</option>
                <option value="ended" <?php selected( $scope, 'ended' ); ?>>Ended</option>
                <option value="all" <?php selected( $scope, 'all' ); ?>>All dates</option>
            </select>
            <button class="button">Filter</button>
        </form>

        <div class="mbs-series-table-wrap">
            <table class="widefat striped mbs-series-table">
                <thead><tr><th>Series</th><th>Section</th><th>Schedule</th><th>Dates</th><th>Nights</th><th>Status</th></tr></thead>
                <tbody>
                <?php if ( ! $series_rows ) : ?><tr><td colspan="6">No Scout series match this filter.</td></tr><?php endif; ?>
                <?php foreach ( $series_rows as $row ) :
                    $row_summary = $occurrence_summaries[ $row->series_ref ] ?? null;
                    $label = $status_label( $row, $row_summary );
                ?>
                    <tr>
                        <td data-label="Series"><a href="<?php echo esc_url( admin_url( 'admin.php?page=mathlin-scout-nights&ref=' . rawurlencode( $row->series_ref ) ) ); ?>"><strong><?php echo esc_html( $row->series_ref ); ?></strong></a><br><small><?php echo esc_html( $row->space ); ?></small></td>
                        <td data-label="Section"><strong><?php echo esc_html( $row->purpose ?: $row->contact_name ); ?></strong><br><span class="mbs-series-badge mbs-series-badge--scout">Scout use · no charge</span></td>
                        <td data-label="Schedule">Weekly · <?php echo esc_html( wp_date( 'l', strtotime( $row->start_date ) ) ); ?><br><small><?php echo esc_html( $row->all_day ? 'All day' : substr( (string) $row->start_time, 0, 5 ) . '–' . substr( (string) $row->end_time, 0, 5 ) ); ?></small></td>
                        <td data-label="Dates"><?php echo esc_html( wp_date( 'j M Y', strtotime( $row->start_date ) ) ); ?><br><small>to <?php echo esc_html( wp_date( 'j M Y', strtotime( $row->repeat_until ) ) ); ?></small></td>
                        <td data-label="Nights"><?php echo $row_summary ? (int) $row_summary->future_active_count : 0; ?> upcoming<br><small><?php echo $row_summary ? (int) $row_summary->cancelled_count : 0; ?> cancelled<?php if ( $row_summary && $row_summary->next_date ) : ?> · next <?php echo esc_html( wp_date( 'j M', strtotime( $row_summary->next_date ) ) ); ?><?php endif; ?></small></td>
                        <td data-label="Status"><span class="mbs-series-status mbs-series-status--<?php echo esc_attr( sanitize_html_class( strtolower( str_replace( ' ', '-', $label ) ) ) ); ?>"><?php echo esc_html( $label ); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else :
        $detail_summary = $occurrence_summaries[ $series->series_ref ] ?? null;
        $detail_label = $status_label( $series, $detail_summary );
    ?>
        <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=mathlin-scout-nights' ) ); ?>">← All Scout series</a></p>

        <div class="mbs-series-detail-grid">
            <section class="postbox mbs-series-panel">
                <div class="mbs-series-panel__heading">
                    <div><h2><?php echo esc_html( $series->purpose ?: $series->contact_name ); ?></h2><p><?php echo esc_html( $series->series_ref ); ?></p></div>
                    <span class="mbs-series-status mbs-series-status--<?php echo esc_attr( sanitize_html_class( strtolower( str_replace( ' ', '-', $detail_label ) ) ) ); ?>"><?php echo esc_html( $detail_label ); ?></span>
                </div>
                <span class="mbs-series-badge mbs-series-badge--scout">Scout use · no charge</span>
                <dl class="mbs-series-facts">
                    <div><dt>Space</dt><dd><?php echo esc_html( $series->space ); ?></dd></div>
                    <div><dt>Schedule</dt><dd>Weekly · <?php echo esc_html( wp_date( 'l', strtotime( $series->start_date ) ) ); ?> · <?php echo esc_html( $series->all_day ? 'All day' : substr( (string) $series->start_time, 0, 5 ) . '–' . substr( (string) $series->end_time, 0, 5 ) ); ?></dd></div>
                    <div><dt>Date range</dt><dd><?php echo esc_html( wp_date( 'j M Y', strtotime( $series->start_date ) ) . '–' . wp_date( 'j M Y', strtotime( $series->repeat_until ) ) ); ?></dd></div>
                    <div><dt>Next night</dt><dd><?php echo $detail_summary && $detail_summary->next_date ? esc_html( wp_date( 'D j M Y', strtotime( $detail_summary->next_date ) ) ) : 'None'; ?></dd></div>
                </dl>
                <?php if ( ! empty( $series->metadata_incomplete ) ) : ?><div class="notice notice-warning inline"><p>This older series was reconstructed from its occurrence records. Original skipped-date history may be incomplete.</p></div><?php endif; ?>
            </section>

            <section class="postbox mbs-series-panel">
                <h2>Series summary</h2>
                <div class="mbs-series-metrics">
                    <div><strong><?php echo $detail_summary ? (int) $detail_summary->future_active_count : 0; ?></strong><span>Upcoming</span></div>
                    <div><strong><?php echo $detail_summary ? (int) $detail_summary->past_count : 0; ?></strong><span>Past</span></div>
                    <div><strong><?php echo $detail_summary ? (int) $detail_summary->cancelled_count : 0; ?></strong><span>Cancelled</span></div>
                    <div><strong><?php echo count( $exceptions ); ?></strong><span>Skipped</span></div>
                </div>
            </section>
        </div>

        <section class="postbox mbs-series-panel">
            <h2>Series actions</h2>
            <p>These actions affect future nights only unless explicitly labelled otherwise. Past nights remain in the record and no hirer emails are sent.</p>
            <div class="mbs-series-actions">
                <button class="button button-primary nms-btn-edit-series" data-series="<?php echo esc_attr( $series->series_ref ); ?>" data-space="<?php echo esc_attr( $series->space ); ?>" data-start="<?php echo esc_attr( substr( (string) $series->start_time, 0, 5 ) ); ?>" data-end="<?php echo esc_attr( substr( (string) $series->end_time, 0, 5 ) ); ?>" data-purpose="<?php echo esc_attr( $series->purpose ); ?>">Edit future nights</button>
                <?php if ( $detail_summary && (int) $detail_summary->future_active_count > 0 ) : ?><button class="button nms-btn-cancel-scout-series" data-series="<?php echo esc_attr( $series->series_ref ); ?>">Cancel future nights</button><?php endif; ?>
                <?php if ( $detail_summary && (int) $detail_summary->future_cancelled_count > 0 ) : ?><button class="button nms-btn-reopen-scout-series" data-series="<?php echo esc_attr( $series->series_ref ); ?>">Reopen cancelled future nights</button><?php endif; ?>
            </div>
            <?php if ( $can_delete ) : ?>
                <details class="mbs-series-danger-zone"><summary>Permanent deletion</summary><p>Use only to correct records entered in error. Cancellation is safer for normal changes.</p><div class="mbs-series-actions"><button class="button nms-btn-delete-scout-series" data-series="<?php echo esc_attr( $series->series_ref ); ?>" data-scope="future">Delete future records permanently</button><button class="button nms-btn-delete-scout-series mbs-button-danger" data-series="<?php echo esc_attr( $series->series_ref ); ?>" data-scope="all">Delete entire series permanently</button></div></details>
            <?php endif; ?>
        </section>

        <section class="postbox mbs-series-panel">
            <h2>Occurrences</h2>
            <div class="mbs-series-table-wrap">
                <table class="widefat striped mbs-series-table mbs-scout-occurrences">
                    <thead><tr><th>Date</th><th>Time</th><th>Space</th><th>Reference</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ( $occurrences as $occurrence ) :
                        $is_future = $occurrence->booking_date >= $today;
                        $is_cancelled = $occurrence->status === 'cancelled';
                    ?>
                        <tr class="<?php echo $is_cancelled ? 'is-cancelled' : ''; ?>">
                            <td data-label="Date"><?php echo esc_html( wp_date( 'D j M Y', strtotime( $occurrence->booking_date ) ) ); ?></td>
                            <td data-label="Time"><?php echo esc_html( $occurrence->all_day ? 'All day' : substr( (string) $occurrence->start_time, 0, 5 ) . '–' . substr( (string) $occurrence->end_time, 0, 5 ) ); ?></td>
                            <td data-label="Space"><?php echo esc_html( $occurrence->space ); ?></td>
                            <td data-label="Reference"><a href="<?php echo esc_url( admin_url( 'admin.php?page=mathlin-booking&action=view&ref=' . rawurlencode( $occurrence->ref ) ) ); ?>"><?php echo esc_html( $occurrence->ref ); ?></a></td>
                            <td data-label="Status"><?php echo esc_html( MBS_Bookings::status_label( $occurrence->status ) ); ?></td>
                            <td data-label="Actions">
                                <?php if ( $is_future && $is_cancelled ) : ?><button class="button button-small nms-btn-reopen-scout-occurrence" data-ref="<?php echo esc_attr( $occurrence->ref ); ?>">Reopen</button>
                                <?php elseif ( $is_future && ! $is_cancelled ) : ?><button class="button button-small nms-btn-cancel-scout-occurrence" data-ref="<?php echo esc_attr( $occurrence->ref ); ?>">Cancel this night</button>
                                <?php else : ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="mbs-series-detail-grid">
            <section class="postbox mbs-series-panel"><h2>Skipped dates and exceptions</h2><?php if ( ! $exceptions ) : ?><p>No recorded skipped dates.</p><?php else : ?><ul class="mbs-series-event-list"><?php foreach ( $exceptions as $exception ) : ?><li><strong><?php echo esc_html( wp_date( 'j M Y', strtotime( $exception['date'] ) ) ); ?></strong><br><span><?php echo esc_html( $exception['message'] ?? ucfirst( $exception['status'] ?? 'Skipped' ) ); ?></span></li><?php endforeach; ?></ul><?php endif; ?></section>
            <section class="postbox mbs-series-panel"><h2>Audit history</h2><?php if ( ! $audit ) : ?><p>No series audit events yet.</p><?php else : ?><ul class="mbs-series-event-list"><?php foreach ( $audit as $event ) : ?><li><strong><?php echo esc_html( MBS_Audit_Log::action_label( $event->action ) ); ?></strong> · <?php echo esc_html( wp_date( 'j M Y H:i', strtotime( $event->created_at ) ) ); ?><br><span><?php echo esc_html( $event->details ); ?></span></li><?php endforeach; ?></ul><?php endif; ?></section>
        </div>
    <?php endif; ?>
</div>

<?php if ( $series && ! empty( $series->scout_use ) ) : ?>
<div id="nms-edit-series-modal" class="mbs-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="nms-edit-series-title">
    <div class="mbs-modal__backdrop"></div>
    <div class="mbs-modal__content mbs-scout-series-modal">
        <div class="mbs-modal__header"><h2 class="mbs-modal__title" id="nms-edit-series-title">Edit Scout series <span id="nms-edit-series-id"></span></h2><button type="button" class="mbs-modal__close nms-edit-series-close" aria-label="Close">&times;</button></div>
        <div class="mbs-modal__body">
            <input type="hidden" id="nms-edit-series-series">
            <p>Changes apply to future active nights only. Past and individually cancelled nights are preserved; conflicting dates are left unchanged.</p>
            <div class="mbs-scout-form-grid">
                <label>Space <select id="nms-edit-series-space"><?php foreach ( $spaces as $name => $info ) : ?><option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name ); ?></option><?php endforeach; ?></select></label>
                <label>Start time <input type="time" id="nms-edit-series-start"></label>
                <label>End time <input type="time" id="nms-edit-series-end"></label>
                <label>Section / purpose <input type="text" id="nms-edit-series-purpose"></label>
            </div>
            <div class="mbs-scout-form-actions"><button class="button button-primary" id="nms-edit-series-save">Save future changes</button><span id="nms-edit-series-msg" role="status"></span></div>
            <hr>
            <h3>Extend series</h3>
            <p>Add weekly nights after the last existing occurrence. Conflicting or blocked dates are recorded as skipped.</p>
            <div class="mbs-scout-extend"><label>Extend until <input type="date" id="nms-edit-series-extend-until" min="<?php echo esc_attr( wp_date( 'Y-m-d', strtotime( $series->repeat_until . ' +1 day' ) ) ); ?>"></label><button class="button" id="nms-edit-series-extend">Extend</button></div>
            <span id="nms-extend-series-msg" role="status"></span>
        </div>
        <div class="mbs-modal__footer"><button type="button" class="button nms-edit-series-close">Close</button></div>
    </div>
</div>
<?php endif; ?>
