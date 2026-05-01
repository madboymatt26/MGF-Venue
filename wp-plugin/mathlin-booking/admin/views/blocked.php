<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap mbs-admin">
    <h1 class="wp-heading-inline">&#9884; Scout Bookings – Blocked Dates</h1>
    <hr class="wp-header-end">

    <p>Block dates to prevent bookings. You can block all spaces or specific ones. Blocked dates will show as unavailable on the public booking calendar.</p>

    <!-- Add new block -->
    <div class="nms-card">
        <div class="nms-card-header"><h2>🚫 Block Dates</h2></div>
        <div style="padding:1.5rem;">
            <div class="nms-block-form">
                <div class="nms-form-row" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end;">
                    <div>
                        <label for="nms-block-from"><strong>From Date</strong></label><br>
                        <input type="date" id="nms-block-from" class="regular-text">
                    </div>
                    <div>
                        <label for="nms-block-to"><strong>To Date</strong></label><br>
                        <input type="date" id="nms-block-to" class="regular-text">
                    </div>
                    <div>
                        <label for="nms-block-space"><strong>Space</strong></label><br>
                        <select id="nms-block-space">
                            <option value="">All Spaces</option>
                            <?php foreach ( $spaces as $name => $info ) : ?>
                                <option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex:1;min-width:200px;">
                        <label for="nms-block-reason"><strong>Reason</strong></label><br>
                        <input type="text" id="nms-block-reason" class="regular-text" style="width:100%;" placeholder="e.g. Building maintenance">
                    </div>
                    <div>
                        <button id="nms-add-block" class="button button-primary">Block Dates</button>
                    </div>
                </div>
                <span id="nms-block-msg" class="nms-settings-msg" style="display:block;margin-top:8px;"></span>
            </div>
        </div>
    </div>

    <!-- Existing blocks -->
    <div class="nms-card">
        <div class="nms-card-header"><h2>📋 Current Blocked Dates</h2></div>
        <div style="padding:1.5rem;">
            <?php if ( empty( $blocked ) ) : ?>
                <p class="nms-muted">No dates are currently blocked.</p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>From</th>
                            <th>To</th>
                            <th>Space</th>
                            <th>Reason</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $blocked as $b ) : ?>
                        <tr id="nms-block-row-<?php echo esc_attr( $b->id ); ?>">
                            <td><?php echo esc_html( date( 'D j M Y', strtotime( $b->date_from ) ) ); ?></td>
                            <td><?php echo esc_html( date( 'D j M Y', strtotime( $b->date_to ) ) ); ?></td>
                            <td><?php echo $b->space ? esc_html( $b->space ) : '<em>All spaces</em>'; ?></td>
                            <td><?php echo esc_html( $b->reason ?: '—' ); ?></td>
                            <td>
                                <button class="button button-small nms-delete-block" data-id="<?php echo esc_attr( $b->id ); ?>">Remove</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
