// ===== DATA STORE =====
const DB = {
  get bookings() { return JSON.parse(localStorage.getItem('nm_bookings') || '[]'); },
  save(bookings) { localStorage.setItem('nm_bookings', JSON.stringify(bookings)); },
  add(booking) { const all = this.bookings; all.push(booking); this.save(all); },
  update(ref, changes) { this.save(this.bookings.map(b => b.ref === ref ? {...b,...changes} : b)); },
  delete(ref) { this.save(this.bookings.filter(b => b.ref !== ref)); }
};

const PRICES = {
  'Main Scout Hall': { rate: 25, unit: 'hr' },
  'Meeting Room':    { rate: 12, unit: 'hr' },
  'Outdoor Area':    { rate: 40, unit: 'day' }
};

function calcCost(space, startTime, endTime) {
  const p = PRICES[space];
  if (!p) return 0;
  if (p.unit === 'day') return p.rate;
  if (!startTime || !endTime) return 0;
  const [sh, sm] = startTime.split(':').map(Number);
  const [eh, em] = endTime.split(':').map(Number);
  const hours = Math.max(0, (eh * 60 + em - sh * 60 - sm) / 60);
  return Math.ceil(hours) * p.rate;
}

function genRef() { return 'NMS-' + Date.now().toString(36).toUpperCase().slice(-6); }

function fmtDate(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr + 'T00:00:00');
  return d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' });
}

function fmtCurrency(n) { return '\u00a3' + Number(n).toFixed(2); }

function timeToMins(t) { const [h, m] = t.split(':').map(Number); return h * 60 + m; }

function escHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function navigate(page) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
  const target = document.getElementById('page-' + page);
  if (target) target.classList.add('active');
  document.querySelectorAll('[data-page="' + page + '"]').forEach(b => b.classList.add('active'));
  window.scrollTo(0, 0);
  if (page === 'calendar') renderCalendar();
  if (page === 'admin') renderAdmin();
}

document.querySelectorAll('.nav-btn, .btn[data-page]').forEach(btn => {
  btn.addEventListener('click', () => navigate(btn.dataset.page));
});

function showToast(msg, type) {
  type = type || '';
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast' + (type ? ' ' + type : '');
  t.style.display = 'block';
  setTimeout(() => { t.style.display = 'none'; }, 3500);
}

let calYear, calMonth;

function initCalendar() {
  const now = new Date();
  calYear = now.getFullYear();
  calMonth = now.getMonth();
}

function renderCalendar() {
  const label = document.getElementById('cal-month-label');
  const grid = document.getElementById('cal-days');
  const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  label.textContent = months[calMonth] + ' ' + calYear;
  const bookings = DB.bookings;
  const today = new Date(); today.setHours(0,0,0,0);
  const firstDay = new Date(calYear, calMonth, 1);
  const lastDay = new Date(calYear, calMonth + 1, 0);
  let startDow = firstDay.getDay();
  startDow = startDow === 0 ? 6 : startDow - 1;
  grid.innerHTML = '';
  for (let i = 0; i < startDow; i++) {
    const blank = document.createElement('div');
    blank.className = 'cal-day other-month';
    grid.appendChild(blank);
  }
  for (let d = 1; d <= lastDay.getDate(); d++) {
    const dateStr = calYear + '-' + String(calMonth + 1).padStart(2,'0') + '-' + String(d).padStart(2,'0');
    const dayBookings = bookings.filter(b => b.date === dateStr && b.status !== 'cancelled');
    const cell = document.createElement('div');
    cell.className = 'cal-day';
    cell.textContent = d;
    const thisDate = new Date(calYear, calMonth, d);
    if (thisDate.getTime() === today.getTime()) cell.classList.add('today');
    if (dayBookings.length > 0) cell.classList.add('has-bookings');
    if (dayBookings.length >= 3) cell.classList.add('fully-booked');
    cell.addEventListener('click', ((ds, db, c) => () => showDayInfo(ds, db, c))(dateStr, dayBookings, cell));
    grid.appendChild(cell);
  }
}

function showDayInfo(dateStr, dayBookings, cell) {
  document.querySelectorAll('.cal-day.selected').forEach(c => c.classList.remove('selected'));
  cell.classList.add('selected');
  const sidebar = document.getElementById('cal-selected-info');
  const formatted = fmtDate(dateStr);
  if (dayBookings.length === 0) {
    sidebar.innerHTML = '<h4>' + formatted + '</h4><p style="color:var(--text-muted);margin-bottom:1rem">No bookings on this day.</p><button class="btn btn-primary btn-sm" onclick="prefillDate(\'' + dateStr + '\')">+ Book this date</button>';
  } else {
    let html = '<h4>' + formatted + '</h4>';
    dayBookings.forEach(b => {
      html += '<div class="day-booking-item"><div class="dbi-name">' + escHtml(b.name) + '</div><div class="dbi-space">' + escHtml(b.space) + '</div><div class="dbi-time">' + (b.space === 'Outdoor Area' ? 'All day' : (b.startTime + ' - ' + b.endTime)) + '</div></div>';
    });
    html += '<button class="btn btn-primary btn-sm" onclick="prefillDate(\'' + dateStr + '\')">+ Add booking</button>';
    sidebar.innerHTML = html;
  }
}

function prefillDate(dateStr) {
  navigate('book');
  setTimeout(() => { document.getElementById('f-date').value = dateStr; }, 50);
}

document.getElementById('cal-prev').addEventListener('click', () => { calMonth--; if (calMonth < 0) { calMonth = 11; calYear--; } renderCalendar(); });
document.getElementById('cal-next').addEventListener('click', () => { calMonth++; if (calMonth > 11) { calMonth = 0; calYear++; } renderCalendar(); });

const fSpace = document.getElementById('f-space');
const fStart = document.getElementById('f-start');
const fEnd   = document.getElementById('f-end');
const fKitchen = document.getElementById('f-kitchen');

function updateCostPreview() {
  const space = fSpace.value;
  const start = fStart.value;
  const end = fEnd.value;
  const kitchen = fKitchen.value === 'yes';
  const p = PRICES[space];
  let spaceCost = 0;
  let spaceLabel = 'Space hire';
  if (p) {
    spaceCost = calcCost(space, start, end);
    if (p.unit === 'hr') {
      const hrs = start && end ? Math.ceil(Math.max(0, (timeToMins(end) - timeToMins(start)) / 60)) : 0;
      spaceLabel = space + (hrs > 0 ? ' (' + hrs + ' hr' + (hrs !== 1 ? 's' : '') + ' x ' + fmtCurrency(p.rate) + ')' : '');
    } else {
      spaceLabel = space + ' (full day)';
    }
  }
  const total = spaceCost + (kitchen ? 10 : 0);
  document.getElementById('cost-space-label').textContent = spaceLabel;
  document.getElementById('cost-space-val').textContent = fmtCurrency(spaceCost);
  document.getElementById('cost-kitchen-row').style.display = kitchen ? 'flex' : 'none';
  document.getElementById('cost-total-val').textContent = fmtCurrency(total);
  const timeRow = document.getElementById('time-row');
  if (space === 'Outdoor Area') {
    timeRow.style.opacity = '0.4';
    timeRow.style.pointerEvents = 'none';
    fStart.removeAttribute('required');
    fEnd.removeAttribute('required');
  } else {
    timeRow.style.opacity = '1';
    timeRow.style.pointerEvents = 'auto';
    fStart.setAttribute('required', '');
    fEnd.setAttribute('required', '');
  }
}

[fSpace, fStart, fEnd, fKitchen].forEach(el => el.addEventListener('change', updateCostPreview));

document.getElementById('booking-form').addEventListener('submit', function(e) {
  e.preventDefault();
  if (!validateForm()) return;
  const space = fSpace.value;
  const kitchen = fKitchen.value === 'yes';
  const spaceCost = calcCost(space, fStart.value, fEnd.value);
  const total = spaceCost + (kitchen ? 10 : 0);
  const booking = {
    ref: genRef(),
    name: document.getElementById('f-name').value.trim(),
    org: document.getElementById('f-org').value.trim(),
    email: document.getElementById('f-email').value.trim(),
    phone: document.getElementById('f-phone').value.trim(),
    address: document.getElementById('f-address').value.trim(),
    space: space, kitchen: kitchen,
    date: document.getElementById('f-date').value,
    startTime: fStart.value, endTime: fEnd.value,
    attendees: document.getElementById('f-attendees').value,
    purpose: document.getElementById('f-purpose').value.trim(),
    notes: document.getElementById('f-notes').value.trim(),
    amount: total, status: 'pending', createdAt: new Date().toISOString()
  };
  DB.add(booking);
  this.reset();
  updateCostPreview();
  showToast('Booking submitted! Reference: ' + booking.ref, 'success');
  setTimeout(() => { navigate('admin'); openBookingModal(booking.ref); }, 1200);
});

function validateForm() {
  let valid = true;
  ['f-name','f-email','f-phone','f-address','f-space','f-date','f-attendees','f-purpose'].forEach(id => {
    const el = document.getElementById(id);
    if (!el.value.trim()) { el.classList.add('error'); valid = false; } else el.classList.remove('error');
  });
  if (fSpace.value !== 'Outdoor Area') {
    if (!fStart.value) { fStart.classList.add('error'); valid = false; } else fStart.classList.remove('error');
    if (!fEnd.value) { fEnd.classList.add('error'); valid = false; } else fEnd.classList.remove('error');
    if (fStart.value && fEnd.value && timeToMins(fEnd.value) <= timeToMins(fStart.value)) {
      fEnd.classList.add('error'); showToast('End time must be after start time', 'error'); valid = false;
    }
  }
  if (!valid) showToast('Please fill in all required fields', 'error');
  return valid;
}

document.querySelectorAll('#booking-form input, #booking-form select, #booking-form textarea').forEach(el => {
  el.addEventListener('input', () => el.classList.remove('error'));
});

function renderAdmin() { const bookings = DB.bookings; renderStats(bookings); renderTable(bookings); }

function renderStats(bookings) {
  const total = bookings.length;
  const pending = bookings.filter(b => b.status === 'pending').length;
  const confirmed = bookings.filter(b => b.status === 'confirmed').length;
  const revenue = bookings.filter(b => b.status === 'confirmed').reduce((s, b) => s + b.amount, 0);
  document.getElementById('admin-stats').innerHTML =
    statCard(total, 'Total Bookings') + statCard(pending, 'Pending') +
    statCard(confirmed, 'Confirmed') + statCard(fmtCurrency(revenue), 'Confirmed Revenue');
}

function statCard(val, label) {
  return '<div class="stat-card"><div class="stat-val">' + val + '</div><div class="stat-label">' + label + '</div></div>';
}

function renderTable(bookings) {
  const search = document.getElementById('admin-search').value.toLowerCase();
  const filter = document.getElementById('admin-filter').value;
  let filtered = bookings.filter(b => {
    const matchStatus = filter === 'all' || b.status === filter;
    const matchSearch = !search || b.ref.toLowerCase().includes(search) || b.name.toLowerCase().includes(search) || b.space.toLowerCase().includes(search) || (b.org && b.org.toLowerCase().includes(search));
    return matchStatus && matchSearch;
  });
  filtered.sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt));
  const tbody = document.getElementById('bookings-tbody');
  const noBookings = document.getElementById('no-bookings');
  if (filtered.length === 0) { tbody.innerHTML = ''; noBookings.style.display = 'block'; return; }
  noBookings.style.display = 'none';
  tbody.innerHTML = filtered.map(b =>
    '<tr>' +
    '<td><strong>' + escHtml(b.ref) + '</strong></td>' +
    '<td>' + escHtml(b.name) + (b.org ? '<br><small style="color:var(--text-muted)">' + escHtml(b.org) + '</small>' : '') + '</td>' +
    '<td>' + escHtml(b.space) + '</td>' +
    '<td>' + fmtDate(b.date) + '</td>' +
    '<td>' + (b.space === 'Outdoor Area' ? 'All day' : (b.startTime + ' - ' + b.endTime)) + '</td>' +
    '<td>' + b.attendees + '</td>' +
    '<td><strong>' + fmtCurrency(b.amount) + '</strong></td>' +
    '<td><span class="status-badge status-' + b.status + '">' + b.status + '</span></td>' +
    '<td><div class="action-btns">' +
      '<button class="btn btn-sm btn-info" onclick="openBookingModal(\'' + b.ref + '\')">View</button>' +
      '<button class="btn btn-sm btn-primary" onclick="showInvoice(\'' + b.ref + '\')">Invoice</button>' +
      (b.status === 'pending' ? '<button class="btn btn-sm btn-success" onclick="updateStatus(\'' + b.ref + '\',\'confirmed\')">Confirm</button>' : '') +
      (b.status !== 'cancelled' ? '<button class="btn btn-sm btn-danger" onclick="updateStatus(\'' + b.ref + '\',\'cancelled\')">Cancel</button>' : '') +
    '</div></td></tr>'
  ).join('');
}

document.getElementById('admin-search').addEventListener('input', renderAdmin);
document.getElementById('admin-filter').addEventListener('change', renderAdmin);

function updateStatus(ref, status) {
  DB.update(ref, { status: status });
  renderAdmin();
  showToast('Booking ' + ref + ' marked as ' + status, status === 'confirmed' ? 'success' : '');
}

function openBookingModal(ref) {
  const b = DB.bookings.find(x => x.ref === ref);
  if (!b) return;
  document.getElementById('modal-title').textContent = 'Booking ' + b.ref;
  document.getElementById('modal-body').innerHTML =
    '<div class="detail-grid">' +
    '<div class="detail-item"><label>Reference</label><span>' + escHtml(b.ref) + '</span></div>' +
    '<div class="detail-item"><label>Status</label><span class="status-badge status-' + b.status + '">' + b.status + '</span></div>' +
    '<div class="detail-item"><label>Name</label><span>' + escHtml(b.name) + '</span></div>' +
    '<div class="detail-item"><label>Organisation</label><span>' + escHtml(b.org || '-') + '</span></div>' +
    '<div class="detail-item"><label>Email</label><span>' + escHtml(b.email) + '</span></div>' +
    '<div class="detail-item"><label>Phone</label><span>' + escHtml(b.phone) + '</span></div>' +
    '<div class="detail-item"><label>Space</label><span>' + escHtml(b.space) + '</span></div>' +
    '<div class="detail-item"><label>Date</label><span>' + fmtDate(b.date) + '</span></div>' +
    '<div class="detail-item"><label>Time</label><span>' + (b.space === 'Outdoor Area' ? 'All day' : b.startTime + ' - ' + b.endTime) + '</span></div>' +
    '<div class="detail-item"><label>Attendees</label><span>' + b.attendees + '</span></div>' +
    '<div class="detail-item"><label>Kitchen</label><span>' + (b.kitchen ? 'Yes' : 'No') + '</span></div>' +
    '<div class="detail-item"><label>Total Amount</label><span><strong>' + fmtCurrency(b.amount) + '</strong></span></div>' +
    '</div>' +
    '<div class="detail-item" style="margin-bottom:0.75rem"><label>Purpose</label><span>' + escHtml(b.purpose) + '</span></div>' +
    (b.notes ? '<div class="detail-item"><label>Notes</label><span>' + escHtml(b.notes) + '</span></div>' : '') +
    (b.address ? '<div class="detail-item" style="margin-top:0.75rem"><label>Billing Address</label><span>' + escHtml(b.address).replace(/\n/g,'<br>') + '</span></div>' : '');
  const footer = document.getElementById('modal-footer');
  footer.innerHTML = '';
  if (b.status === 'pending') {
    const confirmBtn = document.createElement('button');
    confirmBtn.className = 'btn btn-success';
    confirmBtn.textContent = 'Confirm Booking';
    confirmBtn.onclick = () => { updateStatus(ref, 'confirmed'); closeModal(); };
    footer.appendChild(confirmBtn);
  }
  if (b.status !== 'cancelled') {
    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'btn btn-danger';
    cancelBtn.textContent = 'Cancel Booking';
    cancelBtn.onclick = () => { if (confirm('Cancel this booking?')) { updateStatus(ref, 'cancelled'); closeModal(); } };
    footer.appendChild(cancelBtn);
  }
  const invBtn = document.createElement('button');
  invBtn.className = 'btn btn-primary';
  invBtn.textContent = 'View Invoice';
  invBtn.onclick = () => { closeModal(); showInvoice(ref); };
  footer.appendChild(invBtn);
  const delBtn = document.createElement('button');
  delBtn.className = 'btn btn-outline';
  delBtn.style.borderColor = 'var(--danger)';
  delBtn.style.color = 'var(--danger)';
  delBtn.textContent = 'Delete';
  delBtn.onclick = () => {
    if (confirm('Permanently delete booking ' + ref + '? This cannot be undone.')) {
      DB.delete(ref); closeModal(); renderAdmin(); showToast('Booking deleted');
    }
  };
  footer.appendChild(delBtn);
  document.getElementById('modal-overlay').style.display = 'flex';
}

function closeModal() { document.getElementById('modal-overlay').style.display = 'none'; }
document.getElementById('modal-close').addEventListener('click', closeModal);
document.getElementById('modal-overlay').addEventListener('click', function(e) { if (e.target === this) closeModal(); });

function showInvoice(ref) {
  const b = DB.bookings.find(x => x.ref === ref);
  if (!b) return;
  const invNum = 'INV-' + b.ref;
  const issueDate = new Date(b.createdAt).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
  const dueDate = new Date(new Date(b.createdAt).getTime() + 14 * 86400000).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
  const p = PRICES[b.space];
  let qtyLabel = '1 day';
  if (p && p.unit === 'hr' && b.startTime && b.endTime) {
    const qty = Math.ceil(Math.max(0, (timeToMins(b.endTime) - timeToMins(b.startTime)) / 60));
    qtyLabel = qty + ' hour' + (qty !== 1 ? 's' : '');
  }
  const spaceSubtotal = b.amount - (b.kitchen ? 10 : 0);
  let lineItems = '<tr><td>' + escHtml(b.space) + ' hire - ' + fmtDate(b.date) + '<br><small style="color:var(--text-muted)">' + (b.space === 'Outdoor Area' ? 'Full day' : b.startTime + ' - ' + b.endTime) + ' | ' + escHtml(b.purpose) + '</small></td><td>' + qtyLabel + '</td><td class="text-right">' + fmtCurrency(p ? p.rate : 0) + '</td><td class="text-right">' + fmtCurrency(spaceSubtotal) + '</td></tr>';
  if (b.kitchen) {
    lineItems += '<tr><td>Kitchen facilities add-on</td><td>1 session</td><td class="text-right">' + fmtCurrency(10) + '</td><td class="text-right">' + fmtCurrency(10) + '</td></tr>';
  }
  document.getElementById('invoice-body').innerHTML =
    '<div class="invoice-wrap">' +
    '<div class="invoice-header">' +
    '<div class="invoice-org"><div style="font-size:2rem;margin-bottom:0.5rem">&#9884;</div><h2>Needham Market Scout Group</h2><p>Scout Hall, Needham Market, Suffolk<br>bookings@needhammarketscouts.org.uk<br>Registered Charity No. 123456</p></div>' +
    '<div class="invoice-meta"><div class="inv-num">' + invNum + '</div><p>Issue Date: ' + issueDate + '</p><p>Due Date: ' + dueDate + '</p><p style="margin-top:0.5rem"><span class="status-badge status-' + b.status + '">' + b.status + '</span></p></div>' +
    '</div>' +
    '<div class="invoice-parties">' +
    '<div class="invoice-party"><h4>From</h4><p><strong>Needham Market Scout Group</strong><br>Scout Hall<br>Needham Market<br>Suffolk, IP6 8AA</p></div>' +
    '<div class="invoice-party"><h4>Bill To</h4><p><strong>' + escHtml(b.name) + '</strong>' + (b.org ? '<br>' + escHtml(b.org) : '') + '<br>' + escHtml(b.address).replace(/\n/g,'<br>') + '<br>' + escHtml(b.email) + '<br>' + escHtml(b.phone) + '</p></div>' +
    '</div>' +
    '<table class="invoice-table"><thead><tr><th>Description</th><th>Qty</th><th class="text-right">Unit Price</th><th class="text-right">Amount</th></tr></thead><tbody>' + lineItems + '</tbody></table>' +
    '<div class="invoice-totals"><div class="inv-total-row"><span>Subtotal</span><span>' + fmtCurrency(b.amount) + '</span></div><div class="inv-total-row"><span>VAT (0% - Charity exempt)</span><span>' + fmtCurrency(0) + '</span></div><div class="inv-total-row grand"><span>Total Due</span><span>' + fmtCurrency(b.amount) + '</span></div></div>' +
    '<div class="invoice-notes"><h5>Payment Details</h5><p>Please make payment within 14 days of invoice date.<br>Bank Transfer: Sort Code 12-34-56 | Account No. 12345678 | Ref: ' + invNum + '<br>Cheques payable to: <em>Needham Market Scout Group</em></p></div>' +
    '</div>';
  document.getElementById('invoice-overlay').style.display = 'flex';
}

document.getElementById('invoice-close').addEventListener('click', () => { document.getElementById('invoice-overlay').style.display = 'none'; });
document.getElementById('invoice-close-btn').addEventListener('click', () => { document.getElementById('invoice-overlay').style.display = 'none'; });
document.getElementById('invoice-overlay').addEventListener('click', function(e) { if (e.target === this) this.style.display = 'none'; });
document.getElementById('invoice-print').addEventListener('click', () => window.print());

function seedDemoData() {
  if (DB.bookings.length > 0) return;
  const today = new Date();
  const fmt = d => d.toISOString().split('T')[0];
  const d1 = new Date(today); d1.setDate(today.getDate() + 3);
  const d2 = new Date(today); d2.setDate(today.getDate() + 7);
  const d3 = new Date(today); d3.setDate(today.getDate() - 2);
  DB.add({ ref:'NMS-DEMO1', name:'Sarah Johnson', org:'1st Needham Market Beavers', email:'sarah@example.com', phone:'07700 900001', address:'12 High Street\nNeedham Market\nIP6 8AA', space:'Main Scout Hall', kitchen:false, date:fmt(d1), startTime:'18:00', endTime:'21:00', attendees:45, purpose:'Beaver Colony meeting', notes:'', amount:75, status:'confirmed', createdAt:new Date(today.getTime()-86400000*2).toISOString() });
  DB.add({ ref:'NMS-DEMO2', name:'Tom Williams', org:'Needham Market WI', email:'tom@example.com', phone:'07700 900002', address:'5 Church Lane\nNeedham Market\nIP6 8BB', space:'Meeting Room', kitchen:false, date:fmt(d2), startTime:'10:00', endTime:'12:00', attendees:15, purpose:'Committee meeting', notes:'Please set up chairs in a circle', amount:24, status:'pending', createdAt:new Date(today.getTime()-86400000).toISOString() });
  DB.add({ ref:'NMS-DEMO3', name:'Emma Clarke', org:'', email:'emma@example.com', phone:'07700 900003', address:'8 Mill Road\nNeedham Market\nIP6 8CC', space:'Outdoor Area', kitchen:true, date:fmt(d3), startTime:'', endTime:'', attendees:30, purpose:'Birthday party', notes:'Will need tables outside', amount:50, status:'confirmed', createdAt:new Date(today.getTime()-86400000*5).toISOString() });
}

initCalendar();
seedDemoData();
navigate('home');
