<?php if ( ! defined( 'ABSPATH' ) ) exit;
$future_sessions = array_filter( $occurrences, function( $b ) {
    return $b->booking_date > wp_date( 'Y-m-d' ) && in_array( $b->status, array( 'pending', 'confirmed', 'paid', 'deposit_paid' ), true );
} );
$request_history = MBS_Series_Request::history( $s->series_ref );
?>
<?php if ( $future_sessions && empty( $s->scout_use ) ) : ?>
<details style="margin-top:1rem;">
    <summary>Request a change or cancellation</summary>
    <p>Requests are reviewed by the venue. Your bookings and invoices remain unchanged until we confirm the outcome. Availability, pricing and cancellation terms still apply. For a session today, please contact the venue directly.</p>
    <form class="nms-series-request-form">
        <input type="hidden" name="series_ref" value="<?php echo esc_attr( $s->series_ref ); ?>">
        <p><label>Request type<br><select name="kind" required><option value="modify">Change booking(s)</option><option value="cancel">Cancel booking(s)</option></select></label></p>
        <p><label>Which sessions?<br><select name="scope" required><option value="one">One session</option><option value="selected">Selected sessions</option><option value="from_date">All sessions from a date</option></select></label></p>
        <p class="nms-series-one"><label>Session<br><select name="refs[]" required>
            <?php foreach ( $future_sessions as $b ) : ?>
            <option value="<?php echo esc_attr( $b->ref ); ?>"><?php echo esc_html( wp_date( 'D j M Y', strtotime( $b->booking_date ) ) . ' · ' . substr( $b->start_time, 0, 5 ) . '–' . substr( $b->end_time, 0, 5 ) ); ?></option>
            <?php endforeach; ?>
        </select></label></p>
        <fieldset class="nms-series-selected" hidden disabled><legend>Select sessions</legend>
            <?php foreach ( $future_sessions as $b ) : ?>
            <label style="display:block;"><input type="checkbox" name="refs[]" value="<?php echo esc_attr( $b->ref ); ?>"> <?php echo esc_html( wp_date( 'D j M Y', strtotime( $b->booking_date ) ) . ' · ' . substr( $b->start_time, 0, 5 ) . '–' . substr( $b->end_time, 0, 5 ) ); ?></label>
            <?php endforeach; ?>
        </fieldset>
        <p class="nms-series-from" hidden><label>From date (inclusive)<br><input type="date" name="from_date" min="<?php echo esc_attr( wp_date( 'Y-m-d', strtotime( '+1 day' ) ) ); ?>" required disabled></label><br>Includes currently booked sessions on or after this date, not future extensions to the series.</p>
        <p><label>Requested changes or cancellation reason<br><textarea name="notes" rows="4" minlength="3" maxlength="4000" required style="width:100%;"></textarea></label><br>For changes, include the new dates, times or other details you would like.</p>
        <p><label><input type="checkbox" required> I understand this is a request, not an immediate change or cancellation.</label></p>
        <button type="submit" class="nms-btn">Submit request for review</button>
        <p class="nms-series-request-result" role="status" aria-live="polite"></p>
        <noscript>Please enable JavaScript to submit a request, or contact the venue.</noscript>
    </form>
</details>
<?php endif; ?>
<?php if ( $request_history ) : ?>
<details style="margin-top:1rem;"><summary>Request history</summary>
    <?php foreach ( $request_history as $request ) : ?>
    <div style="border-top:1px solid #ddd;margin-top:0.75rem;padding-top:0.75rem;">
        <strong><?php echo esc_html( '#' . $request->id . ' · ' . ucfirst( $request->status ) . ' · ' . $request->created_at ); ?></strong>
        <p style="white-space:pre-line;"><?php echo esc_html( MBS_Series_Request::summary( $request ) ); ?></p>
        <p style="white-space:pre-line;"><?php echo esc_html( $request->notes ); ?></p>
        <?php if ( $request->admin_response ) : ?><p style="white-space:pre-line;">Venue response: <?php echo esc_html( $request->admin_response ); ?></p><?php endif; ?>
    </div>
    <?php endforeach; ?>
</details>
<?php endif; ?>
