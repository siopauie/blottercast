// ============================================================
// api.js — shared frontend client for the BlotterCast PHP/MySQL
// backend and the Python ML microservice.
// All pages load this after including the sidebar/app.js.
// ============================================================
const BC_API = '.'; // same-origin, relative to current folder: http://localhost/blottercast/api/...
// Direct Flask calls are no longer made from the browser — all ML requests
// go through api/ml_proxy.php so login + role permissions are enforced.

const BCApi = {
  async _fetch(url, opts = {}) {
    const res = await fetch(url, { credentials: 'include', ...opts });
    if (res.status === 401) {
      let msg = 'Not authenticated';
      try { msg = (await res.json()).error || msg; } catch (e) {}
      const isLoginPage = window.location.pathname.endsWith('login.html') || window.location.pathname === '/' || url.includes('action=login');
      if (!isLoginPage) {
        window.location.href = 'login.html';
      }
      throw new Error(msg);
    }
    if (!res.ok) {
      let msg = 'Request failed';
      try { msg = (await res.json()).error || msg; } catch (e) {}
      throw new Error(msg);
    }
    return res.status === 204 ? null : res.json();
  },

  // ---- auth ----
  login(username, password) {
    return this._fetch(`${BC_API}/api/auth.php?action=login`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password }),
    });
  },
  logout() { return this._fetch(`${BC_API}/api/auth.php?action=logout`); },
  me() { return this._fetch(`${BC_API}/api/auth.php?action=me`); },
  changePassword(currentPassword, newPassword) {
    return this._fetch(`${BC_API}/api/auth.php?action=change_password`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ currentPassword, newPassword }),
    });
  },

  // ---- records: incidents / blotter / settlements ----
  list(type, params = {}) {
    const qs = new URLSearchParams({ type, ...params }).toString();
    return this._fetch(`${BC_API}/api/records.php?${qs}`);
  },
  create(type, data) {
    return this._fetch(`${BC_API}/api/records.php?type=${type}`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data),
    });
  },
  update(type, id, data) {
    return this._fetch(`${BC_API}/api/records.php?type=${type}&id=${id}`, {
      method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data),
    });
  },
  remove(type, id) {
    return this._fetch(`${BC_API}/api/records.php?type=${type}&id=${id}`, { method: 'DELETE' });
  },
  // Preview-only look-ahead at the next Docket No. / Report No. (doesn't
  // reserve it — the real number is generated fresh again at save time).
  // Used to show what the number *will* be on the "New…" form before the
  // record actually exists, instead of leaving that field blank until
  // after saving.
  peekSeq(type) {
    return this._fetch(`${BC_API}/api/records.php?type=${type}&peek=1`);
  },

  // ---- analytics ----
  dashboard() { return this._fetch(`${BC_API}/api/analytics.php?action=dashboard`); },
  heatmap(params = {}) {
    const qs = new URLSearchParams(params).toString();
    return this._fetch(`${BC_API}/api/analytics.php?action=heatmap&${qs}`);
  },
  trends(year) {
    const qs = year ? `&year=${year}` : '';
    return this._fetch(`${BC_API}/api/analytics.php?action=trends${qs}`);
  },
  zones() { return this._fetch(`${BC_API}/api/analytics.php?action=zones`); },

  // ---- users & audit log ----
  users() { return this._fetch(`${BC_API}/api/users.php?action=list`); },
  createUser(data) {
    return this._fetch(`${BC_API}/api/users.php?action=create`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data),
    });
  },
  updateUser(id, data) {
    return this._fetch(`${BC_API}/api/users.php?action=update&id=${id}`, {
      method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data),
    });
  },
  toggleUserStatus(id) {
    return this._fetch(`${BC_API}/api/users.php?action=toggle_status&id=${id}`, { method: 'POST' });
  },
  deleteUser(id) {
    return this._fetch(`${BC_API}/api/users.php?action=delete&id=${id}`, { method: 'DELETE' });
  },
  auditLog(limit = 10) {
    return this._fetch(`${BC_API}/api/users.php?action=audit&limit=${limit}`);
  },
  async uploadSignature(userId, file) {
    const fd = new FormData();
    fd.append('signature', file);
    const res = await fetch(`${BC_API}/api/users.php?action=upload_signature&id=${userId}`, {
      method: 'POST', credentials: 'include', body: fd,
    });
    if (res.status === 401) { window.location.href = 'login.html'; throw new Error('Not authenticated'); }
    if (!res.ok) {
      let msg = 'Signature upload failed';
      try { msg = (await res.json()).error || msg; } catch (e) {}
      throw new Error(msg);
    }
    return res.json();
  },
  async importBlotterFile(file) {
    const fd = new FormData();
    fd.append('file', file);
    const res = await fetch(`${BC_API}/api/blotter_import.php`, {
      method: 'POST', credentials: 'include', body: fd,
    });
    if (res.status === 401) { window.location.href = 'login.html'; throw new Error('Not authenticated'); }
    if (!res.ok) {
      let msg = 'Import failed';
      try { msg = (await res.json()).error || msg; } catch (e) {}
      throw new Error(msg);
    }
    return res.json();
  },
  removeSignature(userId) {
    return this._fetch(`${BC_API}/api/users.php?action=remove_signature&id=${userId}`, { method: 'POST' });
  },
  captainSignature() { return this._fetch(`${BC_API}/api/users.php?action=captain_signature`); },
  notifList(limit = 20) { return this._fetch(`${BC_API}/api/notifications.php?action=list&limit=${limit}`); },
  notifUnreadCount() { return this._fetch(`${BC_API}/api/notifications.php?action=unread_count`); },
  notifMarkRead(id) { return this._fetch(`${BC_API}/api/notifications.php?action=mark_read&id=${id}`, { method: 'POST' }); },
  notifMarkAllRead() { return this._fetch(`${BC_API}/api/notifications.php?action=mark_all_read`, { method: 'POST' }); },

  // ---- census / clearance / indigency (record & monitor only) ----
  docList(type) { return this._fetch(`${BC_API}/api/documents.php?type=${type}`); },
  checkBlotterRecords(lastName, firstName, residentId) {
    return this._fetch(`${BC_API}/api/documents.php?type=blotter_check&lastName=${encodeURIComponent(lastName)}&firstName=${encodeURIComponent(firstName)}&residentId=${encodeURIComponent(residentId || '')}`);
  },
  // Preview-only look-ahead at the next O.R. No. for Clearance / Residency /
  // Non-Residency forms — same look-ahead pattern as peekSeq() above.
  peekOr() { return this._fetch(`${BC_API}/api/documents.php?type=or_peek`); },
  docCreate(type, data, isBulkImport = false) {
    const headers = { 'Content-Type': 'application/json' };
    if (isBulkImport) headers['X-Bulk-Import'] = '1';
    return this._fetch(`${BC_API}/api/documents.php?type=${type}`, {
      method: 'POST', headers, body: JSON.stringify(data),
    });
  },
  docUpdate(type, id, data) {
    return this._fetch(`${BC_API}/api/documents.php?type=${type}&id=${id}`, {
      method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data),
    });
  },
  docDelete(type, id) {
    return this._fetch(`${BC_API}/api/documents.php?type=${type}&id=${id}`, { method: 'DELETE' });
  },

  // ---- settings & backup ----
  settingsList() { return this._fetch(`${BC_API}/api/settings.php?action=list`); },
  letterheadInfo() { return this._fetch(`${BC_API}/api/settings.php?action=letterhead`); },
  getMlModel() { return this._fetch(`${BC_API}/api/settings.php?action=ml_model`); },
  autoBackupCheck() { return this._fetch(`${BC_API}/api/settings.php?action=auto_backup_check`); },
  setMlModel(task, model) {
    return this._fetch(`${BC_API}/api/settings.php?action=ml_model`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ task, model }),
    });
  },
  settingsSave(values) {
    return this._fetch(`${BC_API}/api/settings.php?action=save`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(values),
    });
  },
  runBackup() { return this._fetch(`${BC_API}/api/settings.php?action=backup`, { method: 'POST' }); },
  backupHistory() { return this._fetch(`${BC_API}/api/settings.php?action=backups`); },

  // ---- ML (Python service, routed through ml_proxy.php for auth/permission enforcement) ----
  async mlTrain(activeModels) {
    const res = await fetch(`${BC_API}/api/ml_proxy.php?action=train`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include',
      body: JSON.stringify({
        activeOccurrenceModel: activeModels?.occurrence,
        activeTypeModel: activeModels?.type,
        activeHotspotModel: activeModels?.hotspot,
      }),
    });
    if (res.status === 401) { window.location.href = 'login.html'; throw new Error('Not authenticated'); }
    if (!res.ok) {
      let msg = 'ML training failed';
      try { msg = (await res.json()).error || msg; } catch (e) {}
      throw new Error(msg);
    }
    return res.json();
  },
  async mlLatest() {
    const res = await fetch(`${BC_API}/api/ml_proxy.php?action=latest`, { credentials: 'include' });
    if (res.status === 404) return null;
    if (res.status === 401) { window.location.href = 'login.html'; return null; }
    if (!res.ok) throw new Error('ML service unavailable');
    return res.json();
  },
  async mlHealth() {
    try {
      const res = await fetch(`${BC_API}/api/ml_proxy.php?action=health`, { credentials: 'include', signal: AbortSignal.timeout(2500) });
      return res.ok;
    } catch (e) { return false; }
  },
};

const ZONES = ['Zone 1','Zone 2','Zone 3','Zone 4','Zone 5','Zone 6','Zone 7','Zone 8'];
const CATEGORIES = ['Physical Assault','Theft','Domestic Dispute','Vandalism','Trespassing','Drug-Related Activity','Public Disturbance','Other'];
