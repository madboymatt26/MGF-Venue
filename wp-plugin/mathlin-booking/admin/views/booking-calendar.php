<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<section class="nms-card nms-booking-calendar" data-ref="<?php echo esc_attr( $booking->ref ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'mbs_gcal_booking' ) ); ?>" aria-label="Google Calendar linkage" style="margin-bottom:1.5rem;">
    <div class="nms-card-header"><h2>Google Calendar</h2></div>
    <div style="padding:1rem 1.5rem;">
        <p class="nms-gcal-label" role="status" aria-live="polite">Checking calendar linkage…</p>
        <p class="nms-gcal-connection nms-muted"></p>
        <ul class="nms-gcal-items"></ul>
        <ul class="nms-gcal-events"></ul>
        <p class="nms-gcal-checked nms-muted"></p>
        <p class="nms-gcal-last nms-muted"></p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button type="button" class="button nms-gcal-check">Check status</button>
            <button type="button" class="button button-primary nms-gcal-sync" disabled>Sync now</button>
            <a class="button" href="<?php echo esc_url( self::page() ); ?>">Calendar Sync / review</a>
        </div>
        <p class="nms-muted">Sync now applies only to this booking. Protected estate entries and uncertain matches require review. No booking emails are sent.</p>
        <noscript><p>Enable JavaScript to check linkage here, or open Calendar Sync.</p></noscript>
    </div>
</section>
