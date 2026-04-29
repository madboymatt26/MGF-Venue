<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<div class="nms-wrap" id="nms-booking-wrap">

    <!-- Calendar -->
    <?php include __DIR__ . '/calendar.php'; ?>

    <!-- Booking Form -->
    <div class="nms-form-wrap" id="nms-form-section">
        <h2 class="nms-section-title">Make a Booking</h2>
        <p class="nms-section-sub">Complete the form below. You'll receive a confirmation email and invoice once your booking is approved.</p>

        <div id="nms-success-msg" class="nms-alert nms-alert-success" style="display:none"></div>
        <div id="nms-error-msg"   class="nms-alert nms-alert-error"   style="display:none"></div>

        <form id="nms-booking-form" class="nms-form" novalidate>
            <?php wp_nonce_field( 'mbs_public_nonce', 'nonce' ); ?>

            <div class="nms-form-section">
                <h3>Your Details</h3>
                <div class="nms-form-row">
                    <div class="nms-form-group">
                        <label for="nms-name">Full Name <span class="nms-req">*</span></label>
                        <input type="text" id="nms-name" name="name" placeholder="Jane Smith" required>
                    </div>
                    <div class="nms-form-group">
                        <label for="nms-org">Organisation / Group</label>
                        <input type="text" id="nms-org" name="organisation" placeholder="e.g. 1st Needham Market Scouts">
                    </div>
                </div>
                <div class="nms-form-row">
                    <div class="nms-form-group">
                        <label for="nms-email">Email Address <span class="nms-req">*</span></label>
                        <input type="email" id="nms-email" name="email" placeholder="jane@example.com" required>
                    </div>
                    <div class="nms-form-group">
                        <label for="nms-phone">Phone Number <span class="nms-req">*</span></label>
                        <input type="tel" id="nms-phone" name="phone" placeholder="07700 900000" required>
                    </div>
                </div>
                <div class="nms-form-group">
                    <label for="nms-address">Billing Address <span class="nms-req">*</span></label>
                    <textarea id="nms-address" name="address" rows="3" placeholder="123 High Street, Needham Market, IP6 8AA" required></textarea>
                </div>
            </div>

            <div class="nms-form-section">
                <h3>Booking Details</h3>
                <div class="nms-form-row">
                    <div class="nms-form-group">
                        <label for="nms-space">Space Required <span class="nms-req">*</span></label>
                        <select id="nms-space" name="space" required>
                            <option value="">— Select a space —</option>
                            <option value="Main Scout Hall">Main Scout Hall (£25/hr, up to 80 people)</option>
                            <option value="Meeting Room">Meeting Room (£12/hr, up to 20 people)</option>
                            <option value="Outdoor Area">Outdoor Area (£40/day)</option>
                        </select>
                    </div>
                    <div class="nms-form-group">
                        <label for="nms-kitchen">Kitchen Add-on</label>
                        <select id="nms-kitchen" name="kitchen">
                            <option value="">No kitchen required</option>
                            <option value="1">Yes, include kitchen (£10/session)</option>
                        </select>
                    </div>
                </div>
                <div class="nms-form-row">
                    <div class="nms-form-group">
                        <label for="nms-date">Date <span class="nms-req">*</span></label>
                        <input type="date" id="nms-date" name="booking_date" required
                               min="<?php
                                   $notice = (int) get_option( 'mbs_min_notice_days', 1 );
                                   echo esc_attr( date( 'Y-m-d', strtotime( "+{$notice} days" ) ) );
                               ?>">
                        <p class="nms-field-hint" id="nms-date-hint"></p>
                    </div>
                    <div class="nms-form-group">
                        <label for="nms-attendees">Expected Attendees <span class="nms-req">*</span></label>
                        <input type="number" id="nms-attendees" name="attendees" min="1" max="100" placeholder="e.g. 30" required>
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
            </div>

            <!-- Cost preview -->
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
                <button type="submit" class="nms-btn nms-btn-primary nms-btn-lg" id="nms-submit-btn">
                    Submit Booking Request
                </button>
            </div>
        </form>
    </div>
</div>
