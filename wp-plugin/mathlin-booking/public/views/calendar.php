<?php if ( ! defined( 'ABSPATH' ) ) exit;
$is_readonly = ! empty( $GLOBALS['mbs_calendar_mode'] ) && $GLOBALS['mbs_calendar_mode'] === 'readonly';
?>

<div class="nms-calendar-wrap" data-mode="<?php echo $is_readonly ? 'readonly' : 'booking'; ?>">
    <h2 class="nms-section-title"><?php echo $is_readonly ? 'What\'s On' : 'Availability Calendar'; ?></h2>
    <p class="nms-section-sub"><?php echo $is_readonly ? 'Click a date to see scheduled events.' : 'Click a date to see what\'s booked, or to start a new booking.'; ?></p>

    <?php if ( $is_readonly && ! ( class_exists( 'MBS_Campsite_Policy' ) && MBS_Campsite_Policy::enabled() ) ) :
        $feed_url = rest_url( 'mathlin/v1/bookings/ical' );
        $subscribe_url = preg_replace( '#^https?://#i', 'webcal://', $feed_url );
    ?>
    <section class="nms-calendar-subscribe" aria-label="Subscribe to this calendar">
        <p><a class="nms-btn nms-btn-primary" href="<?php echo esc_url( $subscribe_url, array( 'webcal' ) ); ?>">Subscribe to calendar</a></p>
        <details><summary>Google, Outlook or another calendar?</summary>
            <p>See public event names and reserved times for private bookings. This is a read-only subscription, with no sign-in needed.</p>
            <label>Subscription URL <input class="nms-subscription-url" type="url" readonly value="<?php echo esc_url( $feed_url ); ?>" aria-label="Calendar subscription URL"></label>
            <button type="button" class="nms-copy-calendar nms-btn nms-btn-secondary">Copy subscription URL</button>
            <span class="nms-copy-calendar-status" role="status"></span>
            <ul><li><strong>Apple Calendar:</strong> use Subscribe to calendar above, or Add Subscription Calendar with this URL.</li>
                <li><strong>Google Calendar:</strong> on a computer browser, choose Other calendars &gt; From URL, then paste the URL. It will then appear in the mobile app.</li>
                <li><strong>Outlook:</strong> choose Add calendar &gt; Subscribe from web, then paste the URL.</li></ul>
            <p>Updates and cancellations appear when your calendar app refreshes, which can take more than 24 hours. Check this page for the latest availability.</p>
            <p><a href="<?php echo esc_url( add_query_arg( 'download', '1', $feed_url ) ); ?>">Download .ics snapshot</a>, a one-off copy that will not receive updates.</p>
        </details>
    </section>
    <?php endif; ?>

    <div class="nms-calendar-layout">
        <div class="nms-calendar-main">
            <div class="nms-cal-header">
                <button class="nms-cal-nav" id="nms-cal-prev" aria-label="Previous month">&#8249;</button>
                <h3 id="nms-cal-month-label">Loading…</h3>
                <button class="nms-cal-nav" id="nms-cal-next" aria-label="Next month">&#8250;</button>
            </div>
            <div class="nms-cal-grid nms-cal-day-names">
                <div>Mon</div><div>Tue</div><div>Wed</div>
                <div>Thu</div><div>Fri</div><div>Sat</div><div>Sun</div>
            </div>
            <div class="nms-cal-grid nms-cal-days" id="nms-cal-days"></div>
        </div>

        <div class="nms-calendar-sidebar" id="nms-cal-sidebar">
            <h4>Select a date</h4>
            <p class="nms-muted">Click a date to see availability.</p>
        </div>
    </div>

    <div class="nms-legend">
        <span class="nms-legend-item"><span class="nms-dot nms-dot-free"></span> Available</span>
        <span class="nms-legend-item"><span class="nms-dot nms-dot-partial"></span> Partially booked</span>
        <span class="nms-legend-item"><span class="nms-dot nms-dot-full"></span> Fully booked</span>
        <span class="nms-legend-item"><span class="nms-dot nms-dot-today"></span> Today</span>
    </div>
</div>
