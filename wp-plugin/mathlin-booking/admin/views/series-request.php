<?php if ( ! defined( 'ABSPATH' ) ) exit;
$data = json_decode( $r->requested_data, true ) ?: array();
$series_ref = $data['series_ref'] ?? '';
$current_series = MBS_Series::get( $series_ref );
$series_url = add_query_arg( array( 'page' => 'mathlin-series', 'ref' => $series_ref ), admin_url( 'admin.php' ) );
?>
<div class="nms-card" style="padding:16px;margin-bottom:16px;">
    <h3>Series <?php echo $r->request_type === 'series_cancel' ? 'cancellation' : 'change'; ?> request #<?php echo (int) $r->id; ?></h3>
    <p><?php echo esc_html( $r->name . ' (' . $r->email . ') · Submitted ' . $r->created_at ); ?></p>
    <p style="white-space:pre-line;"><?php echo esc_html( MBS_Series_Request::summary( $r ) ); ?></p>
    <p><strong>Requested details:</strong><br><span style="white-space:pre-line;"><?php echo esc_html( $r->notes ); ?></span></p>
    <?php if ( ! $current_series || (int) $current_series->version !== (int) ( $data['series_version'] ?? -1 ) ) : ?>
    <p><strong>The series has changed since submission. Recheck the current schedule and all requested dates.</strong></p>
    <?php endif; ?>
    <p>This is a request only. Review availability, pricing, cancellation terms, invoices and credits before applying changes. Do not use the individual-booking approval workflow. Marking handled only records your outcome and emails it to the hirer; it does not change bookings, issue invoices or refund payments.</p>
    <p><a class="button" href="<?php echo esc_url( $series_url ); ?>">Review series and invoices</a></p>
    <?php if ( $r->request_type === 'series_cancel' && $current_series ) : ?>
    <p>Approve cancellation applies only the listed sessions, reconciles their invoice lines and sends the usual series notification. Cash refunds and any cancellation charges require separate review.</p>
    <p><button class="button button-primary nms-approve-series-cancellation" data-id="<?php echo (int) $r->id; ?>" data-version="<?php echo (int) $current_series->version; ?>">Approve listed cancellations</button></p>
    <?php endif; ?>
    <p><button class="button button-primary nms-handle-series-request" data-id="<?php echo (int) $r->id; ?>">Changes applied — record outcome</button>
    <button class="button nms-reject-request" data-id="<?php echo (int) $r->id; ?>" data-type="<?php echo esc_attr( $r->request_type ); ?>">Reject request</button></p>
</div>
