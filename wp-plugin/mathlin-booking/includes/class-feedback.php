<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Post-Booking Feedback & Review module.
 *
 * One day after a booking concludes, emails the hirer asking for a Google
 * Review (public) or private feedback via a secure built-in form. Private
 * feedback is routed to a configurable distribution address.
 *
 * Architecture notes (this plugin uses CUSTOM TABLES, not CPTs):
 *   - Bookings live in wp_mathlin_bookings. Idempotency uses a `feedback_sent`
 *     flag COLUMN on that table (not post meta).
 *   - Scout/internal bookings carry `scout_use = 1` and are excluded.
 *   - The secure {feedback_link} reuses the existing `modification_token`
 *     column + hash_equals() verification (same pattern as MBS_Modification).
 */
class MBS_Feedback {

    /**
     * Wire up the cron event, the public form shortcode, and the AJAX
     * submission handlers (both logged-in and logged-out visitors).
     */
    public function init() {
        // WP-Cron: custom daily hook fired by WordPress once a day.
        add_action( 'mbs_daily_feedback', array( $this, 'send_feedback_requests' ) );
        if ( ! wp_next_scheduled( 'mbs_daily_feedback' ) ) {
            // Schedule for 10:00 local time tomorrow, then daily thereafter.
            wp_schedule_event( strtotime( 'tomorrow 10:00:00' ), 'daily', 'mbs_daily_feedback' );
        }

        // Frontend feedback form (rendered via [mathlin_feedback] shortcode).
        add_shortcode( 'mathlin_feedback', array( $this, 'shortcode_feedback' ) );

        // AJAX submission — wp_ajax_nopriv_* fires for logged-OUT visitors,
        // wp_ajax_* for logged-IN users. Hirers are usually logged out.
        add_action( 'wp_ajax_nopriv_mbs_submit_feedback', array( $this, 'ajax_submit_feedback' ) );
        add_action( 'wp_ajax_mbs_submit_feedback',        array( $this, 'ajax_submit_feedback' ) );
    }

    /**
     * Clear the scheduled cron on plugin deactivation.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( 'mbs_daily_feedback' );
    }

    // ── Settings ─────────────────────────────────────────────────────────────────

    /**
     * Read the admin-configured feedback settings (all option_-prefixed).
     */
    public static function get_settings() {
        return array(
            'enabled'            => (bool) get_option( 'mbs_feedback_enabled', 0 ),
            'review_url'         => get_option( 'mbs_feedback_review_url', '' ),
            'subject'            => get_option( 'mbs_feedback_subject', 'How was your time at {org_name}?' ),
            'body'               => get_option( 'mbs_feedback_body', self::default_body() ),
            'distribution_email' => get_option( 'mbs_feedback_distribution_email', '' ),
        );
    }

    /**
     * Default WYSIWYG body used until the admin customises it.
     */
    public static function default_body() {
        return "<p>Hi {hirer_name},</p>\n"
            . "<p>Thank you for choosing our venue for your booking on {booking_date}. We hope everything went smoothly!</p>\n"
            . "<p>If you have a moment, we'd be really grateful if you could leave us a quick Google review — it genuinely helps our small Scout Group.</p>\n"
            . "<p>{review_link}</p>\n"
            . "<p>Prefer to tell us privately, or have something we should fix? You can send us confidential feedback here instead:</p>\n"
            . "<p>{feedback_link}</p>\n"
            . "<p>Thanks again — we hope to welcome you back soon.</p>";
    }

    // ── Cron logic ───────────────────────────────────────────────────────────────

    /**
     * Daily cron callback (hooked to mbs_daily_feedback).
     *
     * Finds bookings whose effective END date was EXACTLY 1 day ago, that have
     * genuinely taken place, excludes Scout/internal use, and emails each hirer
     * once (guarded by the feedback_sent flag for idempotency).
     */
    public function send_feedback_requests() {
        $settings = self::get_settings();
        if ( ! $settings['enabled'] ) return;

        // Need at least one destination for the hirer to act on.
        if ( empty( $settings['review_url'] ) && empty( self::feedback_form_base_url() ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . MBS_TABLE;

        // "Exactly 1 day ago" = yesterday's date in site timezone.
        $yesterday = wp_date( 'Y-m-d', strtotime( '-1 day' ) );

        // Effective end date = booking_date_end when it's a real date, else booking_date.
        // Exclusion rule: scout_use = 0 (skip Scout / internal bookings).
        // Only chase bookings that actually happened (paid/confirmed/deposit_paid).
        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE (
                 CASE
                     WHEN booking_date_end IS NULL OR booking_date_end = '' OR booking_date_end = '0000-00-00'
                     THEN booking_date
                     ELSE booking_date_end
                 END
             ) = %s
             AND scout_use = 0
             AND status IN ('paid', 'confirmed', 'deposit_paid')
             AND feedback_sent = 0
             ORDER BY id ASC
             LIMIT 50",
            $yesterday
        ) );

        if ( empty( $bookings ) ) return;

        $sent = 0;
        foreach ( $bookings as $booking ) {
            // Mark as sent FIRST to guarantee idempotency even if the mailer is slow
            // or the cron overlaps — we never want to email a hirer twice.
            $updated = $wpdb->update(
                $table,
                array( 'feedback_sent' => 1 ),
                array( 'id' => $booking->id, 'feedback_sent' => 0 ),
                array( '%d' ),
                array( '%d', '%d' )
            );

            // If another run already claimed this row, $updated is 0 — skip.
            if ( ! $updated ) continue;

            self::send_request_email( $booking );
            MBS_Audit_Log::log( $booking->ref, 'feedback_sent', 'Post-booking feedback request emailed to hirer.' );
            $sent++;
        }

        if ( $sent > 0 ) {
            error_log( '[MGF Venue] Sent ' . $sent . ' post-booking feedback request(s).' );
        }
    }

    // ── Secure feedback link (reuses modification_token) ──────────────────────────

    /**
     * Build a secure feedback-form URL for a booking, generating a token if the
     * booking doesn't already have one (same column/pattern as modifications).
     */
    public static function get_feedback_url( $booking ) {
        $token = $booking->modification_token;
        if ( empty( $token ) ) {
            $token = wp_generate_password( 32, false );
            global $wpdb;
            $wpdb->update( $wpdb->prefix . MBS_TABLE, array( 'modification_token' => $token ), array( 'ref' => $booking->ref ) );
        }

        $base = self::feedback_form_base_url();
        if ( empty( $base ) ) $base = home_url();

        return add_query_arg(
            array( 'mbs_feedback' => '1', 'ref' => $booking->ref, 'token' => $token ),
            $base
        );
    }

    /**
     * Find the permalink of the page hosting the [mathlin_feedback] shortcode.
     * Returns '' if no such page exists yet.
     */
    private static function feedback_form_base_url() {
        $pages = get_posts( array(
            'post_type'   => 'page',
            'post_status' => 'publish',
            's'           => 'mathlin_feedback',
            'numberposts' => 1,
        ) );
        return ! empty( $pages ) ? get_permalink( $pages[0]->ID ) : '';
    }

    /**
     * Verify a (ref, token) pair using constant-time comparison.
     */
    public static function verify_token( $ref, $token ) {
        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking || empty( $booking->modification_token ) || empty( $token ) ) return false;
        return hash_equals( $booking->modification_token, $token );
    }

    // ── Email to the hirer ────────────────────────────────────────────────────────

    /**
     * Compose and send the feedback-request email to the hirer.
     */
    public static function send_request_email( $booking ) {
        $settings    = self::get_settings();
        $org         = MBS_Email_Templates::get_org_settings();
        $admin_email = MBS_Bookings::get_admin_email();

        // Replace template tags in the admin-authored subject + body.
        $replacements = self::placeholders( $booking, $settings );
        $subject = str_replace( array_keys( $replacements ), array_values( $replacements ), $settings['subject'] );
        $body_inner = str_replace( array_keys( $replacements ), array_values( $replacements ), $settings['body'] );

        $body  = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:0 auto;">';
        $body .= '<div style="background:#7413DC;padding:24px 32px;border-radius:8px 8px 0 0;text-align:center;">';
        $body .= MBS_Email_Templates::get_logo_html();
        $body .= '<h1 style="color:#fff;margin:8px 0 0;font-size:20px;">' . esc_html( $org['name'] ) . '</h1>';
        $body .= '<p style="color:rgba(255,255,255,0.9);margin:4px 0 0;">We\'d love your feedback</p>';
        $body .= '</div>';
        $body .= '<div style="background:#fff;padding:32px;border:1px solid #e0d0f0;border-top:none;border-radius:0 0 8px 8px;">';
        // Admin body is WYSIWYG HTML — sanitise with wp_kses_post (link buttons already injected).
        $body .= wp_kses_post( $body_inner );
        $body .= '</div>';
        $body .= '<div style="text-align:center;padding:16px;color:#999;font-size:12px;">' . esc_html( $org['name'] ) . ' &bull; ' . esc_html( $org['address'] ) . ' &bull; Charity No. ' . esc_html( $org['charity_number'] ) . '</div>';
        $body .= '</body></html>';

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $org['name'] . ' <' . get_option( 'admin_email', $admin_email ) . '>',
            'Reply-To: ' . $admin_email,
        );

        MBS_Email_Queue::send( $booking->email, $subject, $body, $headers );
    }

    /**
     * Build the placeholder → value map for the feedback email.
     * {review_link} and {feedback_link} are rendered as styled button anchors.
     */
    private static function placeholders( $booking, $settings ) {
        $org = MBS_Email_Templates::get_org_settings();

        // Public Google review button
        $review_link = '';
        if ( ! empty( $settings['review_url'] ) ) {
            $review_link = '<a href="' . esc_url( $settings['review_url'] ) . '" style="display:inline-block;background:#fbbc04;color:#1a1a2e;padding:14px 32px;border-radius:6px;text-decoration:none;font-weight:bold;font-size:16px;">⭐ Leave a Google Review</a>';
        }

        // Private secure feedback-form button
        $feedback_link = '';
        $feedback_url  = self::get_feedback_url( $booking );
        if ( $feedback_url ) {
            $feedback_link = '<a href="' . esc_url( $feedback_url ) . '" style="display:inline-block;background:#7413DC;color:#fff;padding:14px 32px;border-radius:6px;text-decoration:none;font-weight:bold;font-size:16px;">💬 Send Private Feedback</a>';
        }

        $date_str = wp_date( 'l j F Y', strtotime( $booking->booking_date ) );

        return array(
            '{hirer_name}'   => esc_html( $booking->name ),
            '{booking_date}' => esc_html( $date_str ),
            '{space}'        => esc_html( $booking->space ),
            '{ref}'          => esc_html( $booking->ref ),
            '{org_name}'     => esc_html( $org['name'] ),
            '{review_link}'  => $review_link,
            '{feedback_link}' => $feedback_link,
        );
    }

    // ── Frontend form (shortcode) ───────────────────────────────────────────────

    /**
     * [mathlin_feedback] — renders the private feedback form when reached via a
     * valid {feedback_link} (?mbs_feedback=1&ref=…&token=…). Otherwise shows a
     * gentle "invalid link" notice.
     */
    public function shortcode_feedback( $atts ) {
        $ref   = isset( $_GET['ref'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['ref'] ) ) ) : '';
        $token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

        ob_start();

        if ( ! $ref || ! $token || ! self::verify_token( $ref, $token ) ) {
            echo '<div class="nms-feedback-wrap" style="max-width:600px;margin:0 auto;padding:24px;border:1px solid #e0d0f0;border-radius:8px;font-family:Arial,sans-serif;">';
            echo '<h2 style="color:#e74c3c;margin-top:0;">Link not valid</h2>';
            echo '<p>This feedback link is invalid or has expired. If you\'d still like to get in touch, please email us at <a href="mailto:' . esc_attr( MBS_Bookings::get_admin_email() ) . '">' . esc_html( MBS_Bookings::get_admin_email() ) . '</a>.</p>';
            echo '</div>';
            return ob_get_clean();
        }

        $booking  = MBS_Bookings::get( $ref );
        $date_str = wp_date( 'l j F Y', strtotime( $booking->booking_date ) );
        $nonce    = wp_create_nonce( 'mbs_public_nonce' );
        $ajax_url = admin_url( 'admin-ajax.php' );
        ?>
        <div class="nms-feedback-wrap" style="max-width:600px;margin:0 auto;padding:24px;border:1px solid #e0d0f0;border-radius:8px;font-family:Arial,sans-serif;color:#1a1a2e;">
            <h2 style="color:#7413DC;margin-top:0;">Share your feedback</h2>
            <p style="color:#555;">Booking <strong><?php echo esc_html( $ref ); ?></strong> &bull; <?php echo esc_html( $booking->space ); ?> &bull; <?php echo esc_html( $date_str ); ?></p>

            <form id="mbs-feedback-form" style="margin-top:16px;">
                <input type="hidden" name="ref" value="<?php echo esc_attr( $ref ); ?>">
                <input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">

                <label style="display:block;font-weight:600;margin:12px 0 6px;">Overall, how was your experience?</label>
                <select name="rating" style="padding:8px;border:1px solid #ccc;border-radius:6px;width:100%;max-width:260px;">
                    <option value="">— Select (optional) —</option>
                    <option value="5">⭐⭐⭐⭐⭐ Excellent</option>
                    <option value="4">⭐⭐⭐⭐ Good</option>
                    <option value="3">⭐⭐⭐ Okay</option>
                    <option value="2">⭐⭐ Poor</option>
                    <option value="1">⭐ Very poor</option>
                </select>

                <label style="display:block;font-weight:600;margin:16px 0 6px;">Your comments</label>
                <textarea name="comments" rows="6" required placeholder="Tell us what went well, or anything we could improve…" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;box-sizing:border-box;"></textarea>

                <div style="margin-top:16px;">
                    <button type="submit" style="background:#7413DC;color:#fff;padding:12px 28px;border:none;border-radius:6px;font-weight:bold;font-size:15px;cursor:pointer;">Send Feedback</button>
                </div>
                <p id="mbs-feedback-msg" style="margin-top:12px;font-size:14px;"></p>
            </form>
        </div>

        <script>
        (function () {
            var form = document.getElementById('mbs-feedback-form');
            if (!form) return;
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var msg = document.getElementById('mbs-feedback-msg');
                var btn = form.querySelector('button[type="submit"]');
                var comments = form.querySelector('[name="comments"]').value.trim();
                if (!comments) {
                    msg.style.color = '#e74c3c';
                    msg.textContent = 'Please enter a comment before sending.';
                    return;
                }
                btn.disabled = true; btn.textContent = 'Sending…';

                var data = new FormData();
                data.append('action', 'mbs_submit_feedback');
                data.append('nonce', '<?php echo esc_js( $nonce ); ?>');
                data.append('ref', form.querySelector('[name="ref"]').value);
                data.append('token', form.querySelector('[name="token"]').value);
                data.append('rating', form.querySelector('[name="rating"]').value);
                data.append('comments', comments);

                fetch('<?php echo esc_url_raw( $ajax_url ); ?>', { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.success) {
                            form.innerHTML = '<h3 style="color:#2ecc71;">Thank you!</h3><p>' + (res.data && res.data.message ? res.data.message : 'Your feedback has been received.') + '</p>';
                        } else {
                            msg.style.color = '#e74c3c';
                            msg.textContent = (res && res.data && res.data.message) ? res.data.message : 'Something went wrong. Please try again.';
                            btn.disabled = false; btn.textContent = 'Send Feedback';
                        }
                    })
                    .catch(function () {
                        msg.style.color = '#e74c3c';
                        msg.textContent = 'Network error — please try again.';
                        btn.disabled = false; btn.textContent = 'Send Feedback';
                    });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Manually send a feedback request for a specific booking (admin override).
     *
     * Unlike the cron, this ignores the date window and the feedback_sent flag,
     * trusting the admin's explicit command — mirroring the "Send Access Details"
     * manual button. Still marks feedback_sent = 1 and audit-logs the action.
     */
    public static function resend( $booking ) {
        self::send_request_email( $booking );

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . MBS_TABLE,
            array( 'feedback_sent' => 1 ),
            array( 'ref' => $booking->ref )
        );

        MBS_Audit_Log::log( $booking->ref, 'feedback_sent', 'Feedback request manually sent to hirer by admin.' );
    }

    // ── AJAX: receive feedback submission ─────────────────────────────────────────

    /**
     * Handle a feedback form submission: verify the token, bundle the comments
     * with the booking details, and email the distribution address.
     */
    public function ajax_submit_feedback() {
        check_ajax_referer( 'mbs_public_nonce', 'nonce' );

        $ref   = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $token = sanitize_text_field( $_POST['token'] ?? '' );

        if ( ! self::verify_token( $ref, $token ) ) {
            wp_send_json_error( array( 'message' => 'Invalid or expired feedback link.' ) );
        }

        $booking = MBS_Bookings::get( $ref );
        if ( ! $booking ) {
            wp_send_json_error( array( 'message' => 'Booking not found.' ) );
        }

        $comments = sanitize_textarea_field( $_POST['comments'] ?? '' );
        if ( empty( $comments ) ) {
            wp_send_json_error( array( 'message' => 'Please enter a comment before sending.' ) );
        }

        $rating = absint( $_POST['rating'] ?? 0 );
        if ( $rating < 1 || $rating > 5 ) $rating = 0;

        self::send_feedback_to_distribution( $booking, $rating, $comments );

        MBS_Audit_Log::log(
            $booking->ref,
            'feedback_received',
            'Hirer submitted feedback' . ( $rating ? ' (rating: ' . $rating . '/5)' : '' ) . '.',
            0
        );

        wp_send_json_success( array( 'message' => 'Thanks for taking the time to share your feedback with us.' ) );
    }

    /**
     * Email the bundled feedback to the configured distribution address
     * (falling back to the admin email if none is set).
     */
    private static function send_feedback_to_distribution( $booking, $rating, $comments ) {
        $settings    = self::get_settings();
        $org         = MBS_Email_Templates::get_org_settings();
        $admin_email = MBS_Bookings::get_admin_email();

        $to = is_email( $settings['distribution_email'] ) ? $settings['distribution_email'] : $admin_email;

        $stars   = $rating ? str_repeat( '⭐', $rating ) . ' (' . $rating . '/5)' : 'Not provided';
        $subject = '[Feedback] ' . $booking->ref . ' – ' . $booking->name . ( $rating ? ' (' . $rating . '/5)' : '' );

        $body  = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1a1a2e;max-width:600px;margin:0 auto;">';
        $body .= '<div style="background:#7413DC;padding:24px 32px;border-radius:8px 8px 0 0;text-align:center;">';
        $body .= MBS_Email_Templates::get_logo_html();
        $body .= '<h1 style="color:#fff;margin:8px 0 0;font-size:20px;">' . esc_html( $org['name'] ) . '</h1>';
        $body .= '<p style="color:rgba(255,255,255,0.9);margin:4px 0 0;">New Customer Feedback</p>';
        $body .= '</div>';
        $body .= '<div style="background:#fff;padding:32px;border:1px solid #e0d0f0;border-top:none;border-radius:0 0 8px 8px;">';
        $body .= '<h2 style="color:#7413DC;">Feedback received</h2>';

        $body .= '<table style="width:100%;border-collapse:collapse;margin:16px 0;">';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;width:35%;border-bottom:1px solid #e0d0f0;">Reference</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->ref ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Hirer</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->name ) . ( $booking->organisation ? ' (' . esc_html( $booking->organisation ) . ')' : '' ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Email</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;"><a href="mailto:' . esc_attr( $booking->email ) . '">' . esc_html( $booking->email ) . '</a></td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Space</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $booking->space ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Booking date</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( wp_date( 'l j F Y', strtotime( $booking->booking_date ) ) ) . '</td></tr>';
        $body .= '<tr><td style="padding:8px 12px;background:#f5f0ff;font-weight:600;border-bottom:1px solid #e0d0f0;">Rating</td><td style="padding:8px 12px;border-bottom:1px solid #e0d0f0;">' . esc_html( $stars ) . '</td></tr>';
        $body .= '</table>';

        $body .= '<h3 style="color:#7413DC;">Comments</h3>';
        $body .= '<div style="background:#f5f0ff;border:1px solid #e0d0f0;border-radius:6px;padding:16px;">' . nl2br( esc_html( $comments ) ) . '</div>';

        $body .= '<p style="margin-top:16px;"><a href="' . esc_url( admin_url( 'admin.php?page=mathlin-booking&action=view&ref=' . $booking->ref ) ) . '" style="background:#7413DC;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;">View Booking</a></p>';
        $body .= '</div></body></html>';

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $org['name'] . ' <' . get_option( 'admin_email', $admin_email ) . '>',
            // Reply-To the hirer so staff can respond to them directly.
            'Reply-To: ' . $booking->name . ' <' . $booking->email . '>',
        );

        MBS_Email_Queue::send( $to, $subject, $body, $headers );
    }
}
