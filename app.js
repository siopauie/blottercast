// app.js — shared application logic

// Local "YYYY-MM-DD" for today — deliberately NOT `new Date().toISOString()`,
// since that gives the UTC date. In timezones ahead of UTC (e.g. the
// Philippines, UTC+8), during early-morning local hours the UTC date is
// still "yesterday", which would wrongly cap date pickers one day short
// and block today's own date from being selected.
function bcTodayLocalStr() {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

document.addEventListener('DOMContentLoaded', () => {
  // Active nav highlight
  const current = window.location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.nav-link').forEach(link => {
    const href = link.getAttribute('href');
    link.classList.toggle('active', href === current);
  });

  // Cap any date input marked data-no-future so the native calendar
  // picker itself can't select a day after today — belt-and-suspenders
  // alongside the matching bcIsFutureDate() check each form also runs
  // on submit (this covers the picker UI; that covers typed/pasted
  // values and dates set programmatically when editing a record). Today
  // itself stays fully selectable — only strictly-later dates are capped.
  const todayStr = bcTodayLocalStr();
  document.querySelectorAll('input[type="date"][data-no-future]').forEach(el => { el.max = todayStr; });
});

// Live character-stripping filters — remove disallowed characters the
// moment they land in a field (typed, pasted, or autofilled), rather
// than only catching them at submit time. Delegated on `input` at the
// document level so every current and future field marked with one of
// these data attributes is covered without a listener wired up per-page.
//   data-no-numbers → name fields: strip digits (0-9), keep letters/punctuation.
//   data-digits-only → contact number fields: strip everything except
//     digits, so letters AND special characters (+, -, (), spaces, etc.)
//     are blocked as you type. PH mobile numbers are entered as plain
//     digits here (e.g. "09171234567"), so there's nothing legitimate
//     for a contact field to keep besides 0-9.
function bcStripDisallowedChars(el, disallowedRe) {
  const cleaned = el.value.replace(disallowedRe, '');
  if (cleaned !== el.value) {
    const pos = el.selectionStart ? el.selectionStart - (el.value.length - cleaned.length) : cleaned.length;
    el.value = cleaned;
    if (el.setSelectionRange) el.setSelectionRange(pos, pos);
  }
}
document.addEventListener('input', (e) => {
  const el = e.target;
  if (!el.matches) return;
  if (el.matches('[data-no-numbers]')) bcStripDisallowedChars(el, /[0-9]/g);
  else if (el.matches('[data-digits-only]')) bcStripDisallowedChars(el, /[^0-9]/g);
});

// ── Auth guard: redirect to login if session is missing ────
// Also enforces role-based page access (permissions.js) and hides
// sidebar links the current role isn't permitted to use.
async function requireAuth() {
  try {
    const status = await BCApi.me();
    if (!status.authenticated) { window.location.href = 'login.html'; return null; }

    const role = status.user.role;
    if (typeof enforcePageAccess === 'function' && !enforcePageAccess(role)) {
      return null; // enforcePageAccess already redirected away
    }
    if (typeof applyNavPermissions === 'function') applyNavPermissions(role);
    if (typeof applyElementPermissionsLive === 'function') applyElementPermissionsLive(role);

    const nameEl = document.querySelector('[data-user-name]');
    const roleEl = document.querySelector('[data-user-role]');
    const avatarEl = document.querySelector('[data-user-avatar]');
    if (nameEl) nameEl.textContent = status.user.full_name;
    if (roleEl) roleEl.textContent = status.user.role;
    if (avatarEl) avatarEl.textContent = bcInitials(status.user.full_name);
    if (status.user.mustChangePassword) bcShowForcedPasswordChange();
    return status.user;
  } catch (e) {
    window.location.href = 'login.html';
    return null;
  }
}

// Initials shown in the sidebar avatar circle, e.g. "Juan Dela Cruz" -> "JD"
// (first letter of the first two words). Falls back to a single letter for
// one-word names, and "?" if the name is somehow empty.
function bcInitials(fullName) {
  const words = (fullName || '').trim().split(/\s+/).filter(Boolean);
  if (words.length === 0) return '?';
  if (words.length === 1) return words[0][0].toUpperCase();
  return (words[0][0] + words[1][0]).toUpperCase();
}

async function doLogout() {
  if (!(await bcConfirm('Are you sure you want to log out?', { title: 'Log Out', okLabel: 'Log Out' }))) return;
  try { await BCApi.logout(); } catch (e) {}
  window.location.href = 'login.html';
}


// ── Field validation helpers ────────────────────────────────
// Small, dependency-free predicates used right before any create/update
// API call, so obviously-invalid input (digits in a name, a malformed
// contact number, a birth date in the future, etc.) never reaches the
// server. Deliberately kept as plain functions rather than a form
// framework — every page already collects its values into a `vals`
// object and checks required fields with a simple `if(...) { await bcAlert(...);
// return; }`, so each validator just slots into that same pattern.

// Letters (including accented ones like Ñ/ñ), spaces, and the
// punctuation that legitimately appears in Filipino names — hyphens
// (Dela Cruz-Santos), apostrophes (O'Brien, D'Souza), and periods
// (Jr., Ma.). No digits or other symbols.
const BC_NAME_RE = /^[A-Za-zÀ-ÖØ-öø-ÿ.'\- ]+$/;
function bcIsValidName(str) {
  return BC_NAME_RE.test((str || '').trim());
}

// Philippine mobile numbers: 09XXXXXXXXX (11 digits starting with 09).
// Paired with data-digits-only above, which strips letters/symbols as
// they're typed, so by the time this runs the only way to fail is
// wrong length or wrong prefix — not stray characters.
function bcIsValidContact(str) {
  const digits = (str || '').trim();
  return /^09\d{9}$/.test(digits);
}

function bcIsValidEmail(str) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test((str || '').trim());
}

// True if dateStr (the "YYYY-MM-DD" value an <input type="date"> gives
// you) falls after today, in local time — used to block birth dates,
// filing dates, etc. that shouldn't be set in the future.
function bcIsFutureDate(dateStr) {
  if (!dateStr) return false;
  const d = new Date(dateStr + 'T00:00:00');
  const today = new Date(); today.setHours(0, 0, 0, 0);
  return d.getTime() > today.getTime();
}

// True if dateStr is earlier than minDateStr (both "YYYY-MM-DD") — used
// for simple chronological ordering checks between two date fields on
// the same form (e.g. a settlement date shouldn't precede the
// confrontation date it followed).
function bcIsBeforeDate(dateStr, minDateStr) {
  if (!dateStr || !minDateStr) return false;
  return new Date(dateStr + 'T00:00:00').getTime() < new Date(minDateStr + 'T00:00:00').getTime();
}

// True if the combined date+time (a "YYYY-MM-DD" date input value plus an
// "HH:MM" time input value) falls after the current moment — used where a
// form has both a date AND a time field (e.g. Incident's Date/Time
// Reported), since data-no-future / bcIsFutureDate alone only cap the date
// part and would still let someone pick today's date with a time later
// than right now. If timeStr is empty, only the date is compared (same
// behavior as bcIsFutureDate).
function bcIsFutureDateTime(dateStr, timeStr) {
  if (!dateStr) return false;
  const dt = new Date(`${dateStr}T${timeStr || '00:00'}:00`);
  return dt.getTime() > Date.now();
}

// ── Forced password change (Security > Password Expiry (days)) ─────
// Built and injected on demand rather than living in every page's HTML,
// since it only needs to exist for the rare case a login comes back
// flagged mustChangePassword. Deliberately has no close/backdrop-dismiss
// path — Password Expiry means the account genuinely can't proceed with
// the old password, so this stays up until a valid change succeeds.
function bcShowForcedPasswordChange() {
  if (document.getElementById('bcForcedPwModal')) return; // already showing
  const overlay = document.createElement('div');
  overlay.id = 'bcForcedPwModal';
  overlay.className = 'modal-overlay open';
  overlay.setAttribute('data-no-dismiss', '');
  overlay.style.zIndex = '9999';
  overlay.innerHTML = `
    <div class="modal-box max-w-md">
      <h2 class="font-display text-xl text-forest-800 mb-1">Password Update Required</h2>
      <p class="text-sm text-forest-500 mb-4">Your password has expired per this system's Security policy. Please set a new one to continue.</p>
      <div class="space-y-3">
        <div><label class="form-label">Current Password</label><input type="password" id="bcPw_current" class="form-input" autocomplete="current-password"/></div>
        <div><label class="form-label">New Password</label><input type="password" id="bcPw_new" class="form-input" autocomplete="new-password"/></div>
        <div><label class="form-label">Confirm New Password</label><input type="password" id="bcPw_confirm" class="form-input" autocomplete="new-password"/></div>
        <div id="bcPw_error" class="text-red-600 text-xs hidden"></div>
        <div class="flex justify-end pt-2">
          <button id="bcPw_submit" class="btn-primary">Update Password</button>
        </div>
      </div>
    </div>`;
  document.body.appendChild(overlay);
  document.body.style.overflow = 'hidden';

  document.getElementById('bcPw_submit').onclick = async () => {
    const errEl = document.getElementById('bcPw_error');
    errEl.classList.add('hidden');
    const current = document.getElementById('bcPw_current').value;
    const next = document.getElementById('bcPw_new').value;
    const confirm = document.getElementById('bcPw_confirm').value;
    if (!current || !next || !confirm) {
      errEl.textContent = 'Please fill in all three fields.'; errEl.classList.remove('hidden'); return;
    }
    if (next !== confirm) {
      errEl.textContent = 'New password and confirmation do not match.'; errEl.classList.remove('hidden'); return;
    }
    try {
      await BCApi.changePassword(current, next);
      document.body.removeChild(overlay);
      document.body.style.overflow = '';
      showToast('Password updated. You\'re all set!');
    } catch (err) {
      errEl.textContent = err.message; errEl.classList.remove('hidden');
    }
  };
}

// ── Smart pagination ────────────────────────────────────────
// Renders Prev / page numbers / Next into `container`. With only a
// handful of pages every number shows; once there are more, it
// collapses everything except the first page, last page, and a small
// window around the current page into "…" — so a table with 28+ pages
// doesn't force the pagination bar to stretch across (or wrap under)
// the whole table. First/last/current-neighbors are always one click
// away either way.
// onPageChange(page) is called with the 1-based page number clicked.
function bcRenderPagination(container, currentPage, totalPages, onPageChange) {
  if (!container) return;
  container.innerHTML = '';
  totalPages = Math.max(1, totalPages);
  currentPage = Math.min(Math.max(1, currentPage), totalPages);

  const addBtn = (label, opts = {}) => {
    const b = document.createElement('button');
    b.textContent = label;
    b.type = 'button';
    b.className = 'pagination-btn' + (opts.active ? ' active' : '') + (opts.ellipsis ? ' pagination-ellipsis' : '');
    b.disabled = !!opts.disabled || !!opts.ellipsis;
    if (!b.disabled && opts.onClick) b.onclick = opts.onClick;
    container.appendChild(b);
  };

  addBtn('‹ Prev', { disabled: currentPage === 1, onClick: () => onPageChange(currentPage - 1) });

  // Always show page 1, the last page, and a window around the current
  // page; everything in between collapses to a single "…".
  const keep = new Set([1, totalPages, currentPage - 1, currentPage, currentPage + 1]);
  const pages = [...keep].filter(p => p >= 1 && p <= totalPages).sort((a, b) => a - b);

  let last = 0;
  for (const p of pages) {
    if (last && p - last > 1) addBtn('…', { ellipsis: true });
    addBtn(String(p), { active: p === currentPage, onClick: () => onPageChange(p) });
    last = p;
  }

  addBtn('Next ›', { disabled: currentPage === totalPages, onClick: () => onPageChange(currentPage + 1) });
}

// ── Modal helpers ──────────────────────────────────────────
function openModal(id) {
  const el = document.getElementById(id);
  if (el) { el.classList.add('open'); document.body.style.overflow = 'hidden'; }
}
function closeModal(id) {
  const el = document.getElementById(id);
  if (el) { el.classList.remove('open'); document.body.style.overflow = ''; }
}
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-overlay') && !e.target.hasAttribute('data-no-dismiss')) {
    e.target.classList.remove('open');
    document.body.style.overflow = '';
  }
});

// ── Toast ──────────────────────────────────────────────────
function showToast(msg, type = 'success') {
  let t = document.getElementById('globalToast');
  if (!t) {
    t = document.createElement('div');
    t.id = 'globalToast';
    t.className = 'toast';
    document.body.appendChild(t);
  }
  const iconName = type === 'error' ? 'x' : 'check';
  t.className = 'toast' + (type === 'error' ? ' error' : '');
  t.innerHTML = `<span data-icon="${iconName}" data-icon-size="16"></span><span>${msg}</span>`;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2800);
}

// ── Custom alert/confirm dialogs — drop-in async replacements for the
// native window.alert() / window.confirm(), styled to match the rest of
// the app instead of the browser's own popup. Built once, lazily, and
// reused for every call (same pattern as showToast above).
//   await bcAlert('message');
//   await bcAlert('message', { title: 'Heads up', okLabel: 'Got it' });
//   if (await bcConfirm('message')) { ... }
//   if (await bcConfirm('Delete this?', { danger: true, okLabel: 'Delete' })) { ... }
let _bcDialogEl = null;
let _bcDialogResolve = null;

function _bcEnsureDialog() {
  if (_bcDialogEl) return _bcDialogEl;
  const el = document.createElement('div');
  el.id = 'bcDialogOverlay';
  el.className = 'modal-overlay';
  el.setAttribute('data-no-dismiss', ''); // clicking the backdrop shouldn't silently dismiss it
  el.innerHTML = `
    <div class="bc-dialog-box">
      <div class="bc-dialog-header">
        <span id="bcDialogIcon" class="bc-dialog-icon" data-icon="info" data-icon-size="18"></span>
        <h3 id="bcDialogTitle" class="bc-dialog-title"></h3>
      </div>
      <p id="bcDialogMessage" class="bc-dialog-message"></p>
      <div class="bc-dialog-actions">
        <button id="bcDialogCancelBtn" type="button" class="btn-secondary"></button>
        <button id="bcDialogOkBtn" type="button" class="btn-primary"></button>
      </div>
    </div>`;
  document.body.appendChild(el);
  _bcDialogEl = el;
  document.getElementById('bcDialogOkBtn').addEventListener('click', () => _bcDialogFinish(true));
  document.getElementById('bcDialogCancelBtn').addEventListener('click', () => _bcDialogFinish(false));
  el.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') _bcDialogFinish(false);
    // Enter is intentionally NOT force-bound to "confirm" here — the
    // focused button (see _bcOpenDialog, which focuses Cancel by default
    // for danger actions) already handles Enter/Space natively, so a
    // destructive dialog can't be confirmed by an accidental Enter press.
  });
  return el;
}

function _bcDialogFinish(result) {
  if (!_bcDialogEl || !_bcDialogEl.classList.contains('open')) return;
  _bcDialogEl.classList.remove('open');
  document.body.style.overflow = '';
  const resolve = _bcDialogResolve;
  _bcDialogResolve = null;
  if (resolve) resolve(result);
}

function _bcOpenDialog({ title, message, isConfirm, okLabel, cancelLabel, danger }) {
  const el = _bcEnsureDialog();
  document.getElementById('bcDialogTitle').textContent = title;
  document.getElementById('bcDialogMessage').textContent = message;
  const cancelBtn = document.getElementById('bcDialogCancelBtn');
  const okBtn = document.getElementById('bcDialogOkBtn');
  cancelBtn.style.display = isConfirm ? '' : 'none';
  cancelBtn.textContent = cancelLabel || 'Cancel';
  okBtn.textContent = okLabel || (isConfirm ? 'Confirm' : 'OK');
  okBtn.className = danger ? 'btn-danger' : 'btn-primary';

  // Swap the icon (info for plain alerts, warning for danger confirms) —
  // reset the icon-library's "already rendered" flag first so it actually
  // redraws instead of keeping whatever icon was shown last time.
  const icon = document.getElementById('bcDialogIcon');
  icon.dataset.icon = danger ? 'warning' : 'info';
  icon.innerHTML = '';
  delete icon.dataset.iconRendered;
  icon.className = 'bc-dialog-icon' + (danger ? ' danger' : '');
  if (typeof renderIcons === 'function') renderIcons(el);

  el.classList.add('open');
  document.body.style.overflow = 'hidden';
  // Default focus sits on whichever button is the "safe" choice: Cancel
  // for destructive (danger) confirms, OK otherwise — so a stray Enter
  // press never accidentally triggers a delete.
  setTimeout(() => (danger && isConfirm ? cancelBtn : okBtn).focus(), 50);
  return new Promise(resolve => { _bcDialogResolve = resolve; });
}

/** Drop-in async replacement for window.alert(). Always resolves (no return value needed). */
function bcAlert(message, opts = {}) {
  return _bcOpenDialog({ title: opts.title || 'Notice', message, isConfirm: false, okLabel: opts.okLabel });
}

/** Drop-in async replacement for window.confirm() — resolves to true (OK/Confirm) or false (Cancel/Esc). */
function bcConfirm(message, opts = {}) {
  return _bcOpenDialog({
    title: opts.title || 'Please Confirm', message, isConfirm: true,
    okLabel: opts.okLabel, cancelLabel: opts.cancelLabel, danger: opts.danger,
  });
}

// ── Sidebar shared HTML builder (call once per page) ───────
function buildSidebar(activePage) {
  const pages = [
    { href:'dashboard.html',  icon:'📊', label:'Dashboard',          group:'main' },
    { href:'blotter.html',    icon:'📋', label:'Blotter Records',     group:'main' },
    { href:'incident.html',   icon:'🚨', label:'Incident Reports',    group:'main' },
    { href:'settlement.html', icon:'🤝', label:'Settlement Monitor',  group:'main' },
    { href:'heatmap.html',    icon:'🗺', label:'Heat Map',            group:'analytics' },
    { href:'trends.html',     icon:'📈', label:'Trends',              group:'analytics' },
    { href:'predictions.html',icon:'🤖', label:'Predictions',         group:'analytics' },
    { href:'users.html',      icon:'👥', label:'Users & Roles',       group:'system' },
    { href:'reports.html',    icon:'📄', label:'Reports',             group:'system' },
    { href:'settings.html',   icon:'⚙', label:'Settings',            group:'system' },
  ];
  const groupLabels = { main:'Main Menu', analytics:'Analytics', system:'System' };
  let lastGroup = null, html = '';
  pages.forEach(p => {
    if (p.group !== lastGroup) {
      html += `<p class="text-forest-400 text-xs font-semibold uppercase tracking-widest px-3 py-2 ${lastGroup ? 'mt-4':''}">
                 ${groupLabels[p.group]}</p>`;
      lastGroup = p.group;
    }
    html += `<a href="${p.href}" class="nav-link${p.href === activePage ? ' active':''}">
               <span class="nav-icon">${p.icon}</span> ${p.label}</a>`;
  });
  return html;
}

// ── Shared export-filter modal (year/month picker before an .xlsx download) ──
// Call openExportFilter(exportUrl, title) from any page; injects a small modal
// into the DOM on first use so pages don't need to duplicate the markup.
let _exportFilterUrl = '';
function _ensureExportFilterModal() {
  if (document.getElementById('bcExportFilterModal')) return;
  const el = document.createElement('div');
  el.innerHTML = `
    <div class="modal-overlay" id="bcExportFilterModal">
      <div class="modal-box" style="width:420px">
        <div class="flex items-center justify-between mb-5">
          <h2 class="font-display text-lg text-forest-800" id="bcExportFilterTitle">Export to Excel</h2>
          <button onclick="closeModal('bcExportFilterModal')" class="modal-close-btn"><span data-icon="x" data-icon-size="18"></span></button>
        </div>
        <div class="space-y-4">
          <div>
            <label class="form-label">Period</label>
            <select id="bcExportPeriod" class="form-input" onchange="_updateExportFilterFields()">
              <option value="all">All Records</option>
              <option value="year">Specific Year</option>
              <option value="month">Specific Month</option>
            </select>
          </div>
          <div id="bcExportYearWrap" class="hidden">
            <label class="form-label">Year</label>
            <select id="bcExportYear" class="form-input"></select>
          </div>
          <div id="bcExportMonthWrap" class="hidden">
            <label class="form-label">Month</label>
            <select id="bcExportMonth" class="form-input">
              <option value="1">January</option><option value="2">February</option><option value="3">March</option>
              <option value="4">April</option><option value="5">May</option><option value="6">June</option>
              <option value="7">July</option><option value="8">August</option><option value="9">September</option>
              <option value="10">October</option><option value="11">November</option><option value="12">December</option>
            </select>
          </div>
        </div>
        <div class="flex justify-end gap-3 pt-5">
          <button type="button" onclick="closeModal('bcExportFilterModal')" class="btn-secondary">Cancel</button>
          <button type="button" onclick="_confirmExportFilter()" class="btn-primary flex items-center gap-2">
            <span data-icon="download" data-icon-size="16"></span> Download
          </button>
        </div>
      </div>
    </div>`;
  document.body.appendChild(el.firstElementChild);
  const yearSel = document.getElementById('bcExportYear');
  const thisYear = new Date().getFullYear();
  for (let y = thisYear; y >= thisYear - 5; y--) {
    const opt = document.createElement('option');
    opt.value = y; opt.textContent = y;
    yearSel.appendChild(opt);
  }
}
function _updateExportFilterFields() {
  const period = document.getElementById('bcExportPeriod').value;
  document.getElementById('bcExportYearWrap').classList.toggle('hidden', period === 'all');
  document.getElementById('bcExportMonthWrap').classList.toggle('hidden', period !== 'month');
}
function openExportFilter(exportUrl, title) {
  _ensureExportFilterModal();
  _exportFilterUrl = exportUrl;
  document.getElementById('bcExportFilterTitle').textContent = title || 'Export to Excel';
  document.getElementById('bcExportPeriod').value = 'all';
  _updateExportFilterFields();
  openModal('bcExportFilterModal');
}
function _confirmExportFilter() {
  const period = document.getElementById('bcExportPeriod').value;
  let url = _exportFilterUrl;
  if (period === 'year') {
    url += (url.includes('?') ? '&' : '?') + 'year=' + document.getElementById('bcExportYear').value;
  } else if (period === 'month') {
    url += (url.includes('?') ? '&' : '?') + 'year=' + document.getElementById('bcExportYear').value
         + '&month=' + document.getElementById('bcExportMonth').value;
  }
  window.location.href = url;
  closeModal('bcExportFilterModal');
}

// ── Notification bell (real, system-generated alerts) ──────
// Only does anything on pages that actually have #notifPanel in the DOM
// (currently the Dashboard); harmless no-op calls elsewhere.
const NOTIF_SEVERITY_ICON = { critical: 'warning', warning: 'clock', info: 'bell' };
const NOTIF_SEVERITY_COLOR = { critical: '#dc2626', warning: '#d97706', info: '#23703c' };

function timeAgo(dateStr) {
  const seconds = Math.floor((Date.now() - new Date(dateStr.replace(' ', 'T'))) / 1000);
  if (seconds < 60) return 'just now';
  const mins = Math.floor(seconds / 60);
  if (mins < 60) return `${mins}m ago`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `${hours}h ago`;
  const days = Math.floor(hours / 24);
  return `${days}d ago`;
}

async function refreshNotifBadge() {
  const badge = document.getElementById('notifBadge');
  if (!badge) return;
  try {
    const res = await BCApi.notifUnreadCount();
    badge.classList.toggle('hidden', res.count === 0);
  } catch (e) { /* not fatal — badge just stays as-is */ }
}

async function toggleNotifPanel() {
  const panel = document.getElementById('notifPanel');
  if (!panel) return;
  const opening = panel.classList.contains('hidden');
  panel.classList.toggle('hidden');
  if (!opening) return;

  const list = document.getElementById('notifList');
  list.innerHTML = '<div class="px-4 py-6 text-center text-forest-400 text-sm">Loading…</div>';
  try {
    const items = await BCApi.notifList(20);
    if (items.length === 0) {
      list.innerHTML = '<div class="px-4 py-8 text-center text-forest-400 text-sm">No notifications yet.</div>';
    } else {
      list.innerHTML = items.map(n => `
        <a href="${n.link || '#'}" onclick="markNotifRead(${n.id})"
           class="flex gap-3 px-4 py-3 border-b border-forest-50 hover:bg-forest-50 transition-colors ${n.is_read == 0 ? 'bg-forest-50/60' : ''}">
          <span style="color:${NOTIF_SEVERITY_COLOR[n.severity] || '#23703c'}" data-icon="${NOTIF_SEVERITY_ICON[n.severity] || 'bell'}" data-icon-size="16" class="flex-shrink-0 mt-0.5"></span>
          <span class="flex-1 min-w-0">
            <span class="block text-sm font-semibold text-forest-800 truncate">${n.title}</span>
            <span class="block text-xs text-forest-500 mt-0.5">${n.body}</span>
            <span class="block text-xs text-forest-400 mt-1">${timeAgo(n.created_at)}</span>
          </span>
          ${n.is_read == 0 ? '<span class="w-2 h-2 rounded-full bg-forest-500 flex-shrink-0 mt-1.5"></span>' : ''}
        </a>`).join('');
    }
  } catch (e) {
    list.innerHTML = '<div class="px-4 py-6 text-center text-red-500 text-sm">Could not load notifications.</div>';
  }
  refreshNotifBadge();
}

async function markNotifRead(id) {
  try { await BCApi.notifMarkRead(id); refreshNotifBadge(); } catch (e) {}
}

async function markAllNotifsRead() {
  try {
    await BCApi.notifMarkAllRead();
    const panel = document.getElementById('notifPanel');
    if (panel && !panel.classList.contains('hidden')) {
      panel.classList.add('hidden');
      await toggleNotifPanel();
    }
    refreshNotifBadge();
  } catch (e) {}
}

document.addEventListener('click', (e) => {
  const panel = document.getElementById('notifPanel');
  if (!panel || panel.classList.contains('hidden')) return;
  if (!e.target.closest('#notifPanel') && !e.target.closest('[onclick="toggleNotifPanel()"]')) {
    panel.classList.add('hidden');
  }
});

// ── Resident search-picker (replaces the old <select> dropdown) ────
// Shared by Clearance, Certificate of Residency, and Certificate of
// Indigency — a text input that filters as you type and shows name,
// age, address, and household number in each suggestion, instead of
// a plain dropdown of names. Call bcInitResidentPicker() once per page
// after residentOptions has been loaded.
const _bcResidentPickers = {}; // keyed by input id, holds { options, hiddenId, onPick }

function bcInitResidentPicker(inputId, hiddenId, listId, options, onPick) {
  _bcResidentPickers[inputId] = { options, hiddenId, listId, onPick };
  const input = document.getElementById(inputId);
  if (!input || input.dataset.bcPickerBound) return;
  input.dataset.bcPickerBound = '1';
  input.addEventListener('input', () => _bcFilterResidents(inputId));
  input.addEventListener('focus', () => _bcFilterResidents(inputId));
  document.addEventListener('click', (e) => {
    if (!e.target.closest('#' + listId) && e.target !== input) {
      const list = document.getElementById(listId);
      if (list) list.classList.add('hidden');
    }
  });
}

function bcResidentPickerSetOptions(inputId, options) {
  if (_bcResidentPickers[inputId]) _bcResidentPickers[inputId].options = options;
}

function _bcFilterResidents(inputId) {
  const picker = _bcResidentPickers[inputId];
  if (!picker) return;
  const input = document.getElementById(inputId);
  const list = document.getElementById(picker.listId);
  const q = input.value.trim().toLowerCase();

  const matches = q === ''
    ? picker.options.slice(0, 20)
    : picker.options.filter(r => `${r.lastName} ${r.firstName} ${r.middleName}`.toLowerCase().includes(q)).slice(0, 20);

  if (matches.length === 0) {
    list.innerHTML = `<div class="px-3 py-3 text-sm text-forest-400">${q ? 'No matching residents.' : 'No residents recorded yet.'}</div>`;
  } else {
    list.innerHTML = matches.map(r => `
      <button type="button" class="w-full text-left px-3 py-2 hover:bg-forest-50 border-b border-forest-50 last:border-0"
              onclick="bcResidentPickerChoose('${inputId}', ${r.id})">
        <div class="text-sm font-semibold text-forest-800">${r.lastName}, ${r.firstName} ${r.middleName || ''}</div>
        <div class="text-xs text-forest-500">${r.age ?? '—'} yrs old &middot; ${r.address || '—'} &middot; Household ${r.householdNo || '—'}</div>
      </button>`).join('');
  }
  list.classList.remove('hidden');
}

function bcResidentPickerChoose(inputId, residentId) {
  const picker = _bcResidentPickers[inputId];
  if (!picker) return;
  const r = picker.options.find(x => x.id === residentId);
  if (!r) return;
  document.getElementById(inputId).value = `${r.lastName}, ${r.firstName} ${r.middleName || ''}`.trim();
  document.getElementById(picker.hiddenId).value = String(residentId);
  document.getElementById(picker.listId).classList.add('hidden');
  picker.onPick(r);
}

function bcResidentPickerClear(inputId) {
  const picker = _bcResidentPickers[inputId];
  if (!picker) return;
  document.getElementById(inputId).value = '';
  document.getElementById(picker.hiddenId).value = '';
  const list = document.getElementById(picker.listId);
  if (list) list.classList.add('hidden');
  picker.onPick(null);
}
