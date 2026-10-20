<?php if ( ! defined( 'ABSPATH' ) ) exit;
$camp = MBS_Campsite_Policy::enabled(); $paid = ! MBS_Payment_Features::free_hire();
?>
<div class="wrap mbs-admin">
    <h1><?php echo MBS_Admin::brand_mark(); ?>Add booking</h1>
    <p>Create a reservation on behalf of a booker or the estate. Review every date before saving. No email is sent unless explicitly selected. Google sync settings are not changed.</p>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=mathlin-booking' ) ); ?>">← All Bookings</a>
    <style>
        .mbs-entry-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;max-width:1200px;margin-top:20px}
        .mbs-entry-grid .nms-card{margin:0;padding:20px}.mbs-entry-grid label{display:block;font-weight:600;margin:12px 0 5px}
        .mbs-entry-grid input:not([type=checkbox]),.mbs-entry-grid select,.mbs-entry-grid textarea{width:100%;max-width:none}
        .mbs-entry-grid label:has(input[type=checkbox]){font-weight:400}.mbs-entry-help{color:#50575e}.mbs-entry-actions{margin-top:20px;max-width:1200px}
        #mbs-entry-review{max-width:1160px;margin-top:20px;padding:20px;background:#fff;border-left:4px solid #7413dc}
        #mbs-entry-review table{width:100%;border-collapse:collapse}#mbs-entry-review td,#mbs-entry-review th{padding:8px;text-align:left;border-bottom:1px solid #ddd}
        [hidden]{display:none!important}#mbs-entry-message{white-space:pre-wrap}
    </style>
    <form id="mbs-entry-form">
        <div class="mbs-entry-grid">
            <section class="nms-card"><h2>Booker and reservation</h2>
                <label for="entry-type">Reservation type</label><select id="entry-type" name="entry_type"><option value="hire"><?php echo $camp ? 'Scouting campsite booking' : 'External hire'; ?></option>
                    <?php if ( $camp ) : ?><option value="estate">Estate reservation</option><?php endif; ?>
                    <?php if ( get_option( 'mbs_scout_nights_enabled', 1 ) ) : ?><option value="scout">Internal Scout use — no charge</option><?php endif; ?>
                </select>
                <label for="entry-purpose">Booking title / purpose *</label><input id="entry-purpose" name="purpose" required maxlength="200">
                <label for="entry-organisation">Organisation / group</label><input id="entry-organisation" name="organisation" maxlength="200">
                <?php if ( $camp ) : ?><label id="entry-coordinator-row" hidden><input type="checkbox" name="coordinator_contact"> Use the booking coordinator as contact (estate reservation only; no customer email)</label><?php endif; ?>
                <label for="entry-name">Contact name</label><input id="entry-name" name="name" maxlength="200">
                <label for="entry-email">Contact email</label><input id="entry-email" name="email" type="email" maxlength="200">
                <label for="entry-phone">Phone</label><input id="entry-phone" name="phone" type="tel" maxlength="80">
                <?php if ( $paid ) : ?><label for="entry-address">Billing address</label><textarea id="entry-address" name="address" rows="3"></textarea><?php endif; ?>
                <label for="entry-notes">Notes</label><textarea id="entry-notes" name="notes" rows="3"></textarea>
                <?php MBS_Custom_Fields::render_form_fields(); ?>
            </section>
            <section class="nms-card"><h2>Dates and space</h2>
                <label for="entry-space">Space *</label><select id="entry-space" name="space" required><option value="">Select a space</option><?php foreach ( MBS_Bookings::get_spaces() as $name => $info ) : ?><option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name ); ?> — up to <?php echo (int) $info['capacity']; ?></option><?php endforeach; ?></select>
                <?php if ( class_exists( 'MBS_Space_Selection' ) ) MBS_Space_Selection::selector( 'entry-space' ); ?>
                <label for="entry-attendees">Expected attendance *</label><input id="entry-attendees" name="attendees" type="number" min="1" required>
                <label for="entry-date">Start / arrival date *</label><input id="entry-date" name="booking_date" type="date" min="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" required>
                <label for="entry-end-date">End / departure date</label><input id="entry-end-date" name="booking_date_end" type="date"><p class="mbs-entry-help">Inclusive last occupied date. Leave blank for a single day. Recurring sessions must be single-day bookings.</p>
                <?php if ( $camp ) : ?><label for="entry-span">Multi-day use</label><select id="entry-span" name="booking_span"><option value="continuous">Continuous stay — arrival to departure</option><option value="daily">Same time slot each occupied day</option></select><?php endif; ?>
                <label><input name="all_day" type="checkbox"> All day (dates inclusive)</label>
                <div id="entry-times"><label for="entry-start">Start / arrival time *</label><input id="entry-start" name="start_time" type="time"><label for="entry-end">End / departure time *</label><input id="entry-end" name="end_time" type="time"></div>
                <label for="entry-repeat">Repeat</label><select id="entry-repeat" name="recurrence_pattern"><option value="">No — one booking / stay</option><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="fortnightly">Every two weeks</option><option value="monthly_date">Monthly — same date</option><option value="monthly_weekday">Monthly — same weekday and week</option><option value="selected">Choose dates</option></select>
                <div id="entry-repeat-fields" hidden><label for="entry-until">Repeat until (inclusive)</label><input id="entry-until" name="repeat_until" type="date"><label id="entry-selected-row" hidden>Selected dates (YYYY-MM-DD, comma separated)<textarea name="recurrence_dates" rows="3"></textarea></label></div>
                <?php if ( $camp ) : ?><label><input type="checkbox" name="campsite_exception"> Agreed estate/coordinator scheduling exception (notice, weekday arrival or custom session times only)</label><label for="entry-exception">Exception explanation</label><textarea id="entry-exception" name="exception_reason" rows="2"></textarea><p class="mbs-entry-help">Exceptions never bypass blocked dates or occupied related areas.</p><?php endif; ?>
                <?php if ( $paid ) : ?><label for="entry-tier">Pricing tier</label><select id="entry-tier" name="pricing_tier"><?php foreach ( MBS_Bookings::get_pricing_tiers() as $key => $tier ) : ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $tier['label'] ); ?></option><?php endforeach; ?></select><?php endif; ?>
                <?php if ( get_option( 'mbs_kitchen_enabled', 1 ) && ! $camp ) : ?><label><input type="checkbox" name="kitchen"> Include kitchen</label><?php endif; ?>
                <label for="entry-status">Initial status</label><select id="entry-status" name="status"><option value="pending">Pending — approval required</option><option value="confirmed">Confirmed — approve now</option></select>
                <p class="mbs-entry-help"><?php if ( $paid ) : ?>Paid recurring hires must start Pending: configure billing and approve in Recurring Series. Confirming a paid one-off uses normal invoice issuance. <?php else : ?>No hire charge or payment is required. <?php endif; ?>This form does not accept terms for the booker.</p>
                <label><input type="checkbox" name="notify_hirer"> Send the normal booking email to the contact (and invoice where applicable)</label>
            </section>
        </div>
        <div class="mbs-entry-actions"><button class="button button-primary" id="entry-preview">Check dates and review</button></div>
    </form>
    <p id="mbs-entry-message" role="status" aria-live="polite"></p>
    <section id="mbs-entry-review" hidden><h2>Review before saving</h2><div id="entry-summary"></div><div style="max-height:320px;overflow:auto"><table><thead><tr><th>Start</th><th>End (inclusive)</th></tr></thead><tbody id="entry-dates"></tbody></table></div><p><label><input id="entry-confirm" type="checkbox"> I have checked the contact, dates, price, status and email choice.</label></p><button id="entry-save" class="button button-primary" disabled>Create reviewed booking</button></section>
    <div id="entry-result"></div>
</div>
