// ============================================================
// permissions.js — client-side mirror of api/permissions.php.
// This is for UI purposes ONLY (hiding nav links, redirecting
// away from pages a role can't use). It is NOT the security
// boundary — every API endpoint enforces the real rule
// server-side via require_permission() in api/permissions.php.
// Keep this matrix in sync with that file if either changes.
// ============================================================
const PERMISSIONS = {
  view_records:     { 'System Admin': true,  'Barangay Captain': true,  'Desk Officer': true,  'Data Encoder': true  },
  add_blotter:      { 'System Admin': true,  'Barangay Captain': true,  'Desk Officer': true,  'Data Encoder': true  },
  edit_records:     { 'System Admin': true,  'Barangay Captain': true,  'Desk Officer': true,  'Data Encoder': false },
  delete_records:   { 'System Admin': true,  'Barangay Captain': true,  'Desk Officer': false, 'Data Encoder': false },
  generate_reports: { 'System Admin': true,  'Barangay Captain': true,  'Desk Officer': true,  'Data Encoder': false },
  view_analytics:   { 'System Admin': true,  'Barangay Captain': true,  'Desk Officer': true,  'Data Encoder': false },
  manage_users:     { 'System Admin': true,  'Barangay Captain': true,  'Desk Officer': false, 'Data Encoder': false },
  retrain_ml:       { 'System Admin': true,  'Barangay Captain': true,  'Desk Officer': false, 'Data Encoder': false },
  import_data:      { 'System Admin': true,  'Barangay Captain': true,  'Desk Officer': false, 'Data Encoder': true  },
  system_settings:  { 'System Admin': true,  'Barangay Captain': false, 'Desk Officer': false, 'Data Encoder': false },
};

function roleCan(role, permission) {
  return !!(PERMISSIONS[permission] && PERMISSIONS[permission][role]);
}

// Pages gated by a single permission. Pages not listed here are
// accessible to every signed-in role (e.g. Dashboard, Blotter,
// Incident, Settlement, Census, Clearance, Indigency, Heat Map, Trends).
const PAGE_PERMISSION = {
  'heatmap.html':     'view_analytics',
  'trends.html':      'view_analytics',
  'predictions.html': 'view_analytics',
  'reports.html':      'generate_reports',
  'users.html':         'manage_users',
  'settings.html':      'system_settings',
};

// Hide sidebar nav links the current role isn't permitted to use, and
// hide a section header (e.g. "Analytics", "System") too whenever every
// link that belongs to it ends up hidden — otherwise a role like Data
// Encoder, who can't see any Analytics or System pages, is left staring
// at empty section labels with nothing underneath them.
function applyNavPermissions(role) {
  const nav = document.querySelector('nav');
  if (!nav) return;

  document.querySelectorAll('.nav-link[data-page]').forEach(link => {
    const href = link.getAttribute('href');
    const permission = PAGE_PERMISSION[href];
    if (permission && !roleCan(role, permission)) {
      link.style.display = 'none';
    }
  });

  // Sidebar markup is a flat list of <p class="nav-section-label"> headers
  // followed by their <a class="nav-link"> links, all as siblings inside
  // <nav>. Walk it once: whenever a label is followed by zero visible
  // links before the next label (or the end of the list), hide it too.
  const children = Array.from(nav.children);
  let currentLabel = null;
  let sawVisibleLink = true; // no label seen yet, nothing to hide
  const finalizeLabel = () => {
    if (currentLabel) currentLabel.style.display = sawVisibleLink ? '' : 'none';
  };
  children.forEach(el => {
    if (el.classList.contains('nav-section-label')) {
      finalizeLabel();
      currentLabel = el;
      sawVisibleLink = false;
    } else if (el.classList.contains('nav-link') && el.style.display !== 'none') {
      sawVisibleLink = true;
    }
  });
  finalizeLabel();
}

// Hide any element tagged data-perm="permission_key" that the current
// role can't use. Works for elements already in the DOM at call time;
// applyElementPermissionsLive() (below) also catches ones added later
// (table re-renders) so buttons rendered via template strings don't
// need special-casing.
let CURRENT_ROLE = null;
function applyElementPermissions(role, root = document) {
  root.querySelectorAll('[data-perm]').forEach(el => {
    const permission = el.getAttribute('data-perm');
    if (permission && !roleCan(role, permission)) {
      el.style.display = 'none';
    }
  });
}
function applyElementPermissionsLive(role) {
  CURRENT_ROLE = role;
  applyElementPermissions(role);
  if (window._bcPermObserver) return; // only attach once per page
  window._bcPermObserver = new MutationObserver((mutations) => {
    if (!CURRENT_ROLE) return;
    for (const m of mutations) {
      for (const node of m.addedNodes) {
        if (node.nodeType !== 1) continue;
        if (node.hasAttribute && node.hasAttribute('data-perm')) applyElementPermissions(CURRENT_ROLE, node.parentNode || document);
        if (node.querySelector && node.querySelector('[data-perm]')) applyElementPermissions(CURRENT_ROLE, node);
      }
    }
  });
  window._bcPermObserver.observe(document.body, { childList: true, subtree: true });
}

// Block direct navigation to a gated page the current role can't use.
// Returns true if access is allowed, false if the page redirected away.
function enforcePageAccess(role) {
  const current = window.location.pathname.split('/').pop() || 'index.html';
  const permission = PAGE_PERMISSION[current];
  if (permission && !roleCan(role, permission)) {
    window.location.href = 'dashboard.html';
    return false;
  }
  return true;
}
