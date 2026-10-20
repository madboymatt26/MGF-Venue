/* Staff entry: all output is text, and only a server-frozen review token can be committed. */
(function () {
    'use strict';
    const form = document.getElementById('mbs-entry-form'); if (!form) return;
    const review = document.getElementById('mbs-entry-review'), message = document.getElementById('mbs-entry-message');
    const save = document.getElementById('entry-save'), confirm = document.getElementById('entry-confirm');
    let token = null, busy = false;
    function state() {
        const f = form.elements;
        document.getElementById('entry-times').hidden = f.all_day.checked;
        document.getElementById('entry-repeat-fields').hidden = !f.recurrence_pattern.value;
        document.getElementById('entry-selected-row').hidden = f.recurrence_pattern.value !== 'selected';
        const estate = f.entry_type.value === 'estate';
        const row = document.getElementById('entry-coordinator-row'); if (row) row.hidden = !estate;
        if (!estate && f.coordinator_contact) f.coordinator_contact.checked = false;
        const coordinator = estate && f.coordinator_contact && f.coordinator_contact.checked;
        f.name.required = f.email.required = !coordinator;
        f.name.disabled = f.email.disabled = !!coordinator;
        f.notify_hirer.disabled = !!coordinator; if (coordinator) f.notify_hirer.checked = false;
        f.repeat_until.required = !!f.recurrence_pattern.value;
        f.start_time.required = f.end_time.required = !f.all_day.checked;
    }
    function invalidate() { token = null; review.hidden = true; confirm.checked = false; save.disabled = true; state(); }
    form.addEventListener('input', invalidate); form.addEventListener('change', invalidate); state();
    function payload() {
        const d = {custom_fields:{}};
        for (const [k,v] of new FormData(form)) {
            const m = k.match(/^custom_fields\[([^\]]+)\]$/);
            if (m) d.custom_fields[m[1]] = v; else d[k] = v;
        }
        for (const el of form.querySelectorAll('input[type=checkbox]')) if (el.name && !el.name.startsWith('custom_fields[')) d[el.name] = el.checked;
        return d;
    }
    async function request(action, body) {
        const response = await fetch(MBS_Entry.url + action, {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':MBS_Entry.nonce},body:JSON.stringify(body)});
        const result = await response.json(); if (!response.ok) throw new Error(result.message || 'Request failed. Check bookings before retrying.'); return result;
    }
    function line(parent, text) { const p = document.createElement('p'); p.textContent = text; parent.appendChild(p); }
    form.addEventListener('submit', async function (event) {
        event.preventDefault(); if (busy) return; busy = true; invalidate(); message.textContent = 'Checking all dates…';
        const button = document.getElementById('entry-preview'); button.disabled = true;
        try {
            const snapshot = JSON.stringify(payload()); const result = await request('preview', JSON.parse(snapshot));
            if (JSON.stringify(payload()) !== snapshot) throw new Error('Details changed while checking. Review again.');
            token = result.token; const p = result.plan, d = p.data;
            const summary = document.getElementById('entry-summary'); summary.replaceChildren();
            line(summary, d.purpose + ' — ' + d.space + ' — ' + d.attendees + ' people');
            line(summary, 'Contact: ' + d.name + ' <' + d.email + '>');
            line(summary, d.all_day ? 'All day' : 'Times: ' + d.start_time + '–' + d.end_time);
            line(summary, p.schedule.length + ' booking(s); status: ' + d.status + '; estimated total £' + Number(p.estimated_total).toFixed(2) + ' (not a payment request).');
            line(summary, d.notify_hirer ? 'EMAIL WILL BE SENT to ' + d.email + ' using normal booking/invoice delivery.' : 'No customer email will be sent.');
            line(summary, 'All checked dates are available now. They will be checked again when saved. Google sync is not enabled by this action.');
            const dates = document.getElementById('entry-dates'); dates.replaceChildren();
            for (const date of p.schedule) { const tr = document.createElement('tr'); for (const key of ['from','to']) { const td = document.createElement('td'); td.textContent = date[key]; tr.appendChild(td); } dates.appendChild(tr); }
            review.hidden = false; message.textContent = 'Review ready. Nothing has been created.';
        } catch (e) { message.textContent = e.message; } finally { busy = false; button.disabled = false; }
    });
    confirm.addEventListener('change', () => { save.disabled = !token || !confirm.checked || busy; });
    save.addEventListener('click', async function () {
        if (!token || !confirm.checked || busy) return; busy = true; save.disabled = true;
        form.querySelectorAll('input,select,textarea,button').forEach(el => { el.disabled = true; });
        message.textContent = 'Saving reviewed booking…';
        try {
            const result = await request('commit', {token,confirm:true}); token = null; review.hidden = true;
            const target = document.getElementById('entry-result'); target.replaceChildren();
            const series = result.series, ref = series ? series.series_id : result.booking.ref;
            line(target, 'Saved ' + ref + '. Notification requested: ' + (result.notified_hirer ? 'yes (check delivery history)' : 'no') + '.');
            if (series && series.skipped && series.skipped.length) line(target, 'Some dates changed during saving and were NOT booked: ' + JSON.stringify(series.skipped) + '. Review the series before taking further action.');
            const link = document.createElement('a'); link.className = 'button button-primary'; link.textContent = 'View saved ' + (series ? 'series' : 'booking');
            link.href = 'admin.php?page=' + (series ? 'mathlin-series&ref=' : 'mathlin-booking&action=view&ref=') + encodeURIComponent(ref); target.appendChild(link);
            message.textContent = 'Saved. Do not resubmit this reservation.';
        } catch (e) { message.textContent = e.message + '\nCheck All Bookings/Recurring Series before retrying. Reload this page to start a new review.'; }
        finally { busy = false; }
    });
}());
