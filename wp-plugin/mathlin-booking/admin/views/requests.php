<?php if ( ! defined( 'ABSPATH' ) ) exit;
$pending  = MBS_Modification::get_pending();
$all      = MBS_Modification::get_all_requests( 100 );
$resolved = array_filter( $all, function( $r ) { return $r->status !== 'pending'; } );
?>
<div class="wrap mbs-admin">
    <h1>&#9884; Scout Bookings – Change Requests
        <?php if ( count( $pending ) > 0 ) : ?>
            <span class="nms-status nms-status-pending" style="font-size:0.8rem;vertical-align:middle;margin-left:8px;"><?php echo count( $pending ); ?> pending</span>
        <?php endif; ?>
    </h1>

    <!-- Pending requests -->
    <div class="nms-card">
        <div class="nms-card-header"><h2>⏳ Pending Requests</h2></div>
        <div style="padding:1rem 1.5rem;">
            <?php if ( empty( $pending ) ) : ?>
                <p class="nms-muted">No pending requests. 🎉</p>
            <?php else : ?>
                <?php foreach ( $pending as $r ) :
                    $changes = json_decode( $r->requested_data, true ) ?: array();
                    $is_cancel = $r->request_type === 'cancel';
                ?>
                <div style="border:1px solid <?php echo $is_cancel ? '#fca5a5' : '#e0d0f0'; ?>;border-radius:8px;padding:16px;margin-bottom:16px;background:<?php echo $is_cancel ? '#fef2f2' : '#fdfcff'; ?>;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
                        <div>
                            <strong style="font-size:1.1rem;color:<?php echo $is_cancel ? '#991b1b' : '#7413DC'; ?>;">
                                <?php echo $is_cancel ? '❌ Cancellation Request' : '✏️ Modification Request'; ?>
                            </strong>
                            <br><span class="nms-muted">Submitted <?php echo esc_html( date( 'j M Y H:i', strtotime( $r->created_at ) ) ); ?></span>
                        </div>
                        <a href="?page=mathlin-booking&action=view&ref=<?php echo esc_attr( $r->booking_ref ); ?>" class="button button-small">View Booking</a>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:12px 0;font-size:0.875rem;">
                        <div><strong>Booking:</strong> <?php echo esc_html( $r->booking_ref ); ?></div>
                        <div><strong>Booker:</strong> <?php echo esc_html( $r->name ); ?> (<?php echo esc_html( $r->email ); ?>)</div>
                        <div><strong>Space:</strong> <?php echo esc_html( $r->space ); ?></div>
                        <div><strong>Date:</strong> <?php echo esc_html( date( 'j M Y', strtotime( $r->booking_date ) ) ); ?></div>
                        <div><strong>Current Amount:</strong> &pound;<?php echo number_format( $r->amount, 2 ); ?></div>
                        <div><strong>Booking Status:</strong> <?php echo esc_html( ucfirst( $r->booking_status ) ); ?></div>
                    </div>

                    <?php if ( ! $is_cancel && ! empty( $changes ) ) : ?>
                    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:10px 14px;margin:8px 0;font-size:0.85rem;">
                        <strong>Requested changes:</strong>
                        <ul style="margin:4px 0 0;padding-left:20px;">
                            <?php foreach ( $changes as $key => $val ) : ?>
                                <?php if ( $val ) : ?>
                                <li><strong><?php echo esc_html( ucfirst( str_replace( '_', ' ', $key ) ) ); ?>:</strong> <?php echo esc_html( $val ); ?></li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <?php if ( $r->notes ) : ?>
                    <div style="font-size:0.85rem;color:#6b7280;margin:8px 0;"><strong>Notes:</strong> <?php echo esc_html( $r->notes ); ?></div>
                    <?php endif; ?>

                    <div style="display:flex;gap:8px;margin-top:12px;align-items:center;">
                        <button class="button button-primary nms-approve-request" data-id="<?php echo esc_attr( $r->id ); ?>">
                            ✓ Approve<?php echo $is_cancel ? ' Cancellation' : ' Changes'; ?>
                        </button>
                        <button class="button nms-reject-request" data-id="<?php echo esc_attr( $r->id ); ?>" data-type="<?php echo esc_attr( $r->request_type ); ?>">
                            ✗ Reject
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Resolved requests -->
    <?php if ( ! empty( $resolved ) ) : ?>
    <div class="nms-card">
        <div class="nms-card-header"><h2>📋 Recent Resolved Requests</h2></div>
        <div style="padding:1rem 1.5rem;">
            <table class="wp-list-table widefat fixed striped" style="font-size:0.85rem;">
                <thead>
                    <tr><th>Booking</th><th>Type</th><th>Booker</th><th>Submitted</th><th>Result</th><th>Resolved</th></tr>
                </thead>
                <tbody>
                <?php foreach ( $resolved as $r ) : ?>
                    <tr>
                        <td><a href="?page=mathlin-booking&action=view&ref=<?php echo esc_attr( $r->booking_ref ); ?>"><?php echo esc_html( $r->booking_ref ); ?></a></td>
                        <td><?php echo $r->request_type === 'cancel' ? '❌ Cancel' : '✏️ Modify'; ?></td>
                        <td><?php echo esc_html( $r->name ); ?></td>
                        <td><?php echo esc_html( date( 'j M Y', strtotime( $r->created_at ) ) ); ?></td>
                        <td>
                            <span class="nms-status nms-status-<?php echo $r->status === 'approved' ? 'confirmed' : 'cancelled'; ?>">
                                <?php echo esc_html( ucfirst( $r->status ) ); ?>
                            </span>
                        </td>
                        <td><?php echo $r->resolved_at ? esc_html( date( 'j M Y', strtotime( $r->resolved_at ) ) ) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
