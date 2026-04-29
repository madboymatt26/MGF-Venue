<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<div class="nms-calendar-wrap">
    <h2 class="nms-section-title">Availability Calendar</h2>
    <p class="nms-section-sub">Click a date to see what's booked, or to start a new booking.</p>

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
