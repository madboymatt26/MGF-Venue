<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<div class="nms-wrap" id="nms-booking-wrap">

    <!-- Calendar -->
    <?php include __DIR__ . '/calendar.php'; ?>

    <!-- Booking Form -->
    <div class="nms-form-wrap" id="nms-form-section">
        <h2 class="nms-section-title">Make a Booking</h2>
        <p class="nms-section-sub">Complete the form below. You'll receive a confirmation email and invoice once your booking is approved.</p>

        <?php if ( ! is_user_logged_in() ) :
            $portal_pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 's' => 'mathlin_portal', 'numberposts' => 1 ) );
            $portal_url = ! empty( $portal_pages ) ? get_permalink( $portal_pages[0]->ID ) : '';
            if ( $portal_url ) :
        ?>
        <div style="background:#f5f0ff;border:1px solid #e0d0f0;border-radius:8px;padding:12px 16px;margin-bottom:1.5rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
            <span style="font-size:0.9rem;color:#1a1a2e;">🔑 Have an account? <a href="<?php echo esc_url( $portal_url ); ?>" style="color:#7413DC;font-weight:600;">Log in</a> to pre-fill your details and track your bookings.</span>
        </div>
        <?php endif; endif; ?>

        <div id="nms-success-msg" class="nms-alert nms-alert-success" style="display:none"></div>
        <div id="nms-error-msg"   class="nms-alert nms-alert-error"   style="display:none"></div>

        <form id="nms-booking-form" class="nms-form" novalidate>
            <?php wp_nonce_field( 'mbs_public_nonce', 'nonce' ); ?>

            <?php
            // Pre-fill for logged-in hirers
            $hirer = MBS_Hirer_Portal::get_hirer_details();
            ?>

            <div class="nms-form-section">
                <h3>Your Details</h3>
                <?php if ( $hirer ) : ?>
                    <p style="font-size:0.85rem;color:#065f46;background:#d1fae5;padding:8px 12px;border-radius:6px;margin-bottom:1rem;">✅ Logged in as <?php echo esc_html( $hirer['name'] ); ?> — your details are pre-filled.</p>
                <?php endif; ?>
                <div class="nms-form-row">
                    <div class="nms-form-group">
                        <label for="nms-name">Full Name <span class="nms-req">*</span></label>
                        <input type="text" id="nms-name" name="name" placeholder="Jane Smith" required
                               value="<?php echo esc_attr( $hirer['name'] ?? '' ); ?>">
                    </div>
                    <div class="nms-form-group">
                        <label for="nms-org">Organisation / Group</label>
                        <input type="text" id="nms-org" name="organisation" placeholder="e.g. 1st Needham Market Scouts"
                               value="<?php echo esc_attr( $hirer['organisation'] ?? '' ); ?>">
                    </div>
                </div>
                <div class="nms-form-row">
                    <div class="nms-form-group">
                        <label for="nms-email">Email Address <span class="nms-req">*</span></label>
                        <input type="email" id="nms-email" name="email" placeholder="jane@example.com" required
                               value="<?php echo esc_attr( $hirer['email'] ?? '' ); ?>">
                    </div>
                    <div class="nms-form-group">
                        <label for="nms-phone">Phone Number <span class="nms-req">*</span></label>
                        <input type="tel" id="nms-phone" name="phone" placeholder="07700 900000" required
                               value="<?php echo esc_attr( $hirer['phone'] ?? '' ); ?>">
                    </div>
                </div>
                <div class="nms-form-group">
                    <label for="nms-address">Billing Address <span class="nms-req">*</span></label>
                    <textarea id="nms-address" name="address" rows="3" placeholder="123 High Street, Needham Market, IP6 8AA" required><?php echo esc_textarea( $hirer['address'] ?? '' ); ?></textarea>
                </div>
            </div>

            <div class="nms-form-section">
                <h3>Booking Details</h3>
                <div class="nms-form-row">
                    <div class="nms-form-group">
                        <label for="nms-space">Space Required <span class="nms-req">*</span></label>
                        <select id="nms-space" name="space" required>
                            <option value="">— Select a space —</option>
                            <?php
                            $spaces = MBS_Bookings::get_spaces();
                            foreach ( $spaces as $name => $info ) :
                                $hourly = isset( $info['rate_hourly'] ) ? $info['rate_hourly'] : ( $info['rate'] ?? 0 );
                                $daily  = isset( $info['rate_daily'] ) ? $info['rate_daily'] : 0;
                                $price_label = '£' . number_format( $hourly, 0 ) . '/hr or £' . number_format( $daily, 0 ) . '/day';
                                $cap_label   = ! empty( $info['capacity'] ) ? ', up to ' . $info['capacity'] . ' people' : '';
                            ?>
                            <option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name . ' (' . $price_label . $cap_label . ')' ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="nms-form-group">
                        <label for="nms-kitchen">Kitchen Add-on</label>
                        <select id="nms-kitchen" name="kitchen">
                            <option value="">No kitchen required</option>
                            <option value="1">Yes, include kitchen (£<?php echo number_format( MBS_Bookings::get_kitchen_price(), 0 ); ?>/session)</option>
                        </select>
                    </div>
                </div>
                <div class="nms-form-row">
                    <div class="nms-form-group">
                        <label for="nms-date">Start Date <span class="nms-req">*</span></label>
                        <input type="date" id="nms-date" name="booking_date" required
                               min="<?php
                                   $notice = (int) get_option( 'mbs_min_notice_days', 1 );
                                   echo esc_attr( date( 'Y-m-d', strtotime( "+{$notice} days" ) ) );
                               ?>">
                        <p class="nms-field-hint" id="nms-date-hint"></p>
                    </div>
                    <div class="nms-form-group">
                        <label for="nms-date-end">End Date</label>
                        <input type="date" id="nms-date-end" name="booking_date_end"
                               min="<?php echo esc_attr( date( 'Y-m-d', strtotime( "+{$notice} days" ) ) ); ?>">
                        <p class="nms-field-hint">Leave blank for a single day booking</p>
                    </div>
                </div>
                <div class="nms-form-row">
                    <div class="nms-form-group">
                        <label for="nms-attendees">Expected Attendees <span class="nms-req">*</span></label>
                        <input type="number" id="nms-attendees" name="attendees" min="1" max="100" placeholder="e.g. 30" required>
                    </div>
                    <div class="nms-form-group">
                        <label for="nms-allday">Booking Type</label>
                        <select id="nms-allday" name="all_day">
                            <option value="0">Specific hours</option>
                            <option value="1">Full day(s)</option>
                        </select>
                    </div>
                </div>
                <div class="nms-form-row" id="nms-time-row">
                    <div class="nms-form-group">
                        <label for="nms-start">Start Time <span class="nms-req">*</span></label>
                        <input type="time" id="nms-start" name="start_time">
                    </div>
                    <div class="nms-form-group">
                        <label for="nms-end">End Time <span class="nms-req">*</span></label>
                        <input type="time" id="nms-end" name="end_time">
                    </div>
                </div>
                <div class="nms-form-group">
                    <label for="nms-purpose">Purpose of Booking <span class="nms-req">*</span></label>
                    <input type="text" id="nms-purpose" name="purpose" placeholder="e.g. Birthday party, committee meeting, training day" required>
                </div>
                <div class="nms-form-group">
                    <label for="nms-notes">Additional Notes</label>
                    <textarea id="nms-notes" name="notes" rows="3" placeholder="Any special requirements, setup needs, etc."></textarea>
                </div>

                <!-- Recurring booking option -->
                <div class="nms-form-row">
                    <div class="nms-form-group">
                        <label for="nms-recurring">Repeat Weekly?</label>
                        <select id="nms-recurring" name="recurring">
                            <option value="0">No — single booking</option>
                            <option value="1">Yes — repeat weekly</option>
                        </select>
                    </div>
                    <div class="nms-form-group" id="nms-repeat-until-group" style="display:none">
                        <label for="nms-repeat-until">Repeat Until <span class="nms-req">*</span></label>
                        <input type="date" id="nms-repeat-until" name="repeat_until"
                               min="<?php echo esc_attr( date( 'Y-m-d', strtotime( '+7 days' ) ) ); ?>">
                        <p class="nms-field-hint">Booking will repeat every week until this date (max 52 weeks). Dates with conflicts will be skipped.</p>
                    </div>
                </div>

                <div class="nms-form-row">
                    <div class="nms-form-group">
                        <label for="nms-public">Event Visibility</label>
                        <select id="nms-public" name="is_public">
                            <option value="0">Private — only shows as "Booked" on calendar</option>
                            <option value="1">Public — show event name and details on calendar</option>
                        </select>
                        <p class="nms-field-hint">Public events display the event name and your contact details on the calendar.</p>
                    </div>
                </div>
            </div>

            <!-- Cost preview -->
            <?php MBS_Custom_Fields::render_form_fields(); ?>

            <div class="nms-form-section" id="nms-cost-section">
                <h3>Cost Estimate</h3>
                <div class="nms-cost-table">
                    <div class="nms-cost-row">
                        <span id="nms-cost-space-label">Space hire</span>
                        <span id="nms-cost-space-val">£0.00</span>
                    </div>
                    <div class="nms-cost-row" id="nms-cost-kitchen-row" style="display:none">
                        <span>Kitchen add-on</span>
                        <span>£10.00</span>
                    </div>
                    <div class="nms-cost-row nms-cost-total">
                        <span>Estimated Total</span>
                        <span id="nms-cost-total">£0.00</span>
                    </div>
                </div>
                <p class="nms-cost-note">* Final invoice issued upon confirmation by our booking team.</p>
            </div>

            <div class="nms-form-actions">
                <?php
                $terms_page_id = (int) get_option( 'mbs_terms_page_id', 0 );
                if ( $terms_page_id && get_post( $terms_page_id ) ) :
                    $terms_url = get_permalink( $terms_page_id );
                ?>
                <div class="nms-form-group" style="margin-bottom:1rem;text-align:center;">
                    <label style="display:inline-flex;align-items:center;gap:0.5rem;font-weight:500;cursor:pointer;">
                        <input type="checkbox" id="nms-terms" name="accept_terms" value="1" required style="width:18px;height:18px;">
                        I agree to the <a href="<?php echo esc_url( $terms_url ); ?>" target="_blank" style="color:#7413DC;">Terms &amp; Conditions</a> <span class="nms-req">*</span>
                    </label>
                </div>
                <?php endif; ?>

                <button type="submit" class="nms-btn nms-btn-primary nms-btn-lg" id="nms-submit-btn">
                    Submit Booking Request
                </button>
            </div>
        </form>
    </div>
</div>
