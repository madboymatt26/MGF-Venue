/* NM Scouts Booking – Public JS */
jQuery(function ($) {

    // ── Calendar ───────────────────────────────────────────────────────────────
    var calYear  = new Date().getFullYear();
    var calMonth = new Date().getMonth() + 1; // 1-based
    var calData  = {};

    var MONTHS = ['January','February','March','April','May','June',
                  'July','August','September','October','November','December'];

    function loadCalendar(year, month) {
        $.post(NMS.ajax_url, {
            action: 'mbs_get_calendar',
            nonce:  NMS.nonce,
            year:   year,
            month:  month
        }, function (res) {
            if (res.success) {
                calData = res.data;
                renderCalendar(year, month);
            }
        });
    }

    function renderCalendar(year, month) {
        $('#nms-cal-month-label').text(MONTHS[month - 1] + ' ' + year);

        var today    = new Date();
        today.setHours(0,0,0,0);
        var firstDay = new Date(year, month - 1, 1);
        var lastDay  = new Date(year, month, 0);
        var startDow = firstDay.getDay(); // 0=Sun
        startDow = startDow === 0 ? 6 : startDow - 1; // Mon=0

        var $grid = $('#nms-cal-days').empty();

        // Blank cells before first day
        for (var i = 0; i < startDow; i++) {
            $grid.append('<div class="nms-cal-day nms-other-month"></div>');
        }

        for (var d = 1; d <= lastDay.getDate(); d++) {
            var dateStr  = year + '-' + pad(month) + '-' + pad(d);
            var count    = calData[dateStr] || 0;
            var thisDate = new Date(year, month - 1, d);
            var classes  = ['nms-cal-day'];

            if (thisDate.getTime() === today.getTime()) classes.push('nms-today');
            if (thisDate < today) classes.push('nms-past');
            if (count > 0) classes.push('nms-has-bookings');
            if (count >= 3) classes.push('nms-fully-booked');

            // Grey out dates within the minimum notice period (too soon to book)
            var minDate = NMS.min_date ? new Date(NMS.min_date + 'T00:00:00') : today;
            var tooSoon = thisDate >= today && thisDate < minDate;
            if (tooSoon) classes.push('nms-beyond-limit');

            // Check if date is blocked (all spaces)
            var isBlocked = false;
            if (NMS.blocked_dates && NMS.blocked_dates[dateStr]) {
                var blocks = NMS.blocked_dates[dateStr];
                for (var bi = 0; bi < blocks.length; bi++) {
                    if (blocks[bi] === '__all__') { isBlocked = true; break; }
                }
            }
            if (isBlocked) classes.push('nms-blocked');

            var $cell = $('<div>')
                .addClass(classes.join(' '))
                .attr('data-date', dateStr);

            // Day number
            var $num = $('<span class="nms-cal-day-num">').text(d);
            $cell.append($num);

            // Show blocked label
            if (isBlocked) {
                $cell.append('<span class="nms-blocked-label">Unavailable</span>');
            }

            if (thisDate >= minDate && !isBlocked) {
                $cell.on('click', function () {
                    var ds = $(this).data('date');
                    $('.nms-cal-day').removeClass('nms-selected');
                    $(this).addClass('nms-selected');
                    showDayInfo(ds);
                });
            }
            $grid.append($cell);
        }
    }

    function showDayInfo(dateStr) {
        var $sidebar = $('#nms-cal-sidebar');
        var label    = formatDate(dateStr);
        $sidebar.html('<h4>' + label + '</h4><p class="nms-muted">Loading…</p>');

        // Check for blocked spaces on this date
        var blockedSpaces = [];
        if (NMS.blocked_dates && NMS.blocked_dates[dateStr]) {
            blockedSpaces = NMS.blocked_dates[dateStr];
        }

        $.post(NMS.ajax_url, {
            action: 'mbs_get_day',
            nonce:  NMS.nonce,
            date:   dateStr
        }, function (res) {
            if (!res.success) return;
            var bookings = res.data;
            var html = '<h4>' + label + '</h4>';

            // Show blocked notice
            if (blockedSpaces.length > 0) {
                var allBlocked = blockedSpaces.indexOf('__all__') !== -1;
                if (allBlocked) {
                    html += '<div class="nms-alert nms-alert-error" style="margin-bottom:1rem;padding:0.75rem 1rem;font-size:0.85rem;">🚫 <strong>Unavailable</strong> — All spaces are blocked on this date.</div>';
                } else {
                    html += '<div class="nms-alert nms-alert-error" style="margin-bottom:1rem;padding:0.75rem 1rem;font-size:0.85rem;">🚫 <strong>Partially unavailable</strong> — ' + escHtml(blockedSpaces.join(', ')) + ' blocked on this date.</div>';
                }
            }

            if (bookings.length === 0) {
                html += '<p class="nms-muted" style="margin-bottom:1rem">No bookings on this day.</p>';
            } else {
                bookings.forEach(function (b) {
                    var time = b.all_day ? 'All day' : (b.start_time + ' – ' + b.end_time);
                    html += '<div class="nms-day-booking">' +
                            '<div class="nms-day-booking-space">' + escHtml(b.space) + '</div>' +
                            '<div class="nms-day-booking-time">' + escHtml(time) + '</div>' +
                            '</div>';
                });
            }

            html += '<button class="nms-btn nms-btn-primary nms-btn-sm nms-prefill-date" data-date="' + dateStr + '">+ Book this date</button>';
            $sidebar.html(html);
        });
    }

    // Prefill date in form when clicking calendar button
    $(document).on('click', '.nms-prefill-date', function () {
        var date = $(this).data('date');
        $('#nms-date').val(date);
        var $form = $('#nms-form-section');
        if ($form.length) {
            $('html, body').animate({ scrollTop: $form.offset().top - 80 }, 400);
        }
    });

    $('#nms-cal-prev').on('click', function () {
        calMonth--;
        if (calMonth < 1) { calMonth = 12; calYear--; }
        loadCalendar(calYear, calMonth);
    });
    $('#nms-cal-next').on('click', function () {
        calMonth++;
        if (calMonth > 12) { calMonth = 1; calYear++; }
        loadCalendar(calYear, calMonth);
    });

    // Init calendar
    if ($('#nms-cal-days').length) {
        loadCalendar(calYear, calMonth);
    }

    // ── Apply minimum notice period to date picker ─────────────────────────────
    if (NMS.min_date) {
        $('#nms-date').attr('min', NMS.min_date);
        var days = NMS.min_notice_days;
        var hint = days === 0 ? 'Same-day bookings are allowed.' :
                   days === 1 ? 'Bookings must be made at least 1 day in advance.' :
                                'Bookings must be made at least ' + days + ' days in advance.';
        $('#nms-date-hint').text(hint);
    }

    // ── Cost preview ───────────────────────────────────────────────────────────
    function updateCost() {
        var space   = $('#nms-space').val();
        var start   = $('#nms-start').val();
        var end     = $('#nms-end').val();
        var kitchen = $('#nms-kitchen').val() === '1';
        var info    = NMS.spaces[space];

        var spaceCost  = 0;
        var spaceLabel = 'Space hire';

        if (info) {
            if (info.unit === 'day') {
                spaceCost  = info.rate;
                spaceLabel = space + ' (full day)';
            } else if (start && end) {
                var mins = timeToMins(end) - timeToMins(start);
                var hrs  = Math.ceil(Math.max(0, mins / 60));
                spaceCost  = hrs * info.rate;
                spaceLabel = space + (hrs > 0 ? ' (' + hrs + ' hr' + (hrs !== 1 ? 's' : '') + ' × £' + info.rate + ')' : '');
            }
        }

        var kitchenPrice = parseFloat(NMS.kitchen_price) || 10;
        var total = spaceCost + (kitchen ? kitchenPrice : 0);
        $('#nms-cost-space-label').text(spaceLabel);
        $('#nms-cost-space-val').text('£' + total2dp(spaceCost));
        $('#nms-cost-kitchen-row').toggle(kitchen);
        if (kitchen) {
            $('#nms-cost-kitchen-row').find('span').last().text('£' + total2dp(kitchenPrice));
        }
        $('#nms-cost-total').text('£' + total2dp(total));

        // Toggle time fields
        var isDay = info && info.unit === 'day';
        $('#nms-time-row').css({ opacity: isDay ? 0.4 : 1, pointerEvents: isDay ? 'none' : 'auto' });
        $('#nms-start, #nms-end').prop('required', !isDay);
    }

    $('#nms-space, #nms-start, #nms-end, #nms-kitchen').on('change', updateCost);

    // ── Booking form submit ────────────────────────────────────────────────────
    $('#nms-booking-form').on('submit', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn  = $('#nms-submit-btn');
        var $err  = $('#nms-error-msg');
        var $ok   = $('#nms-success-msg');

        $err.hide(); $ok.hide();

        // Client-side validation
        var valid = true;
        $form.find('[required]').each(function () {
            if (!$(this).val().trim()) {
                $(this).addClass('nms-field-error');
                valid = false;
            } else {
                $(this).removeClass('nms-field-error');
            }
        });
        if (!valid) {
            $err.text('Please fill in all required fields.').show();
            return;
        }

        $btn.prop('disabled', true).text('Submitting…');

        var data = $form.serializeArray();
        data.push({ name: 'action', value: 'mbs_submit_booking' });

        $.post(NMS.ajax_url, data, function (res) {
            $btn.prop('disabled', false).text('Submit Booking Request');
            if (res.success) {
                $ok.text(res.data.message).show();
                $form[0].reset();
                updateCost();
                $('html, body').animate({ scrollTop: $ok.offset().top - 80 }, 400);
            } else {
                $err.text(res.data.message || 'An error occurred. Please try again.').show();
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Submit Booking Request');
            $err.text('A network error occurred. Please try again.').show();
        });
    });

    // Clear error state on input
    $(document).on('input change', '.nms-field-error', function () {
        $(this).removeClass('nms-field-error');
    });

    // ── Helpers ────────────────────────────────────────────────────────────────
    function pad(n) { return String(n).padStart(2, '0'); }

    function timeToMins(t) {
        var parts = t.split(':');
        return parseInt(parts[0]) * 60 + parseInt(parts[1]);
    }

    function total2dp(n) { return Number(n).toFixed(2); }

    function formatDate(dateStr) {
        var d = new Date(dateStr + 'T00:00:00');
        return d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    }

    function escHtml(str) {
        return String(str || '')
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
});
