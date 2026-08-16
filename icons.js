// ============================================================
// icons.js — a small, self-contained line-icon set for BlotterCast.
// Inline SVG (no external CDN, no emoji), single stroke weight,
// rounded caps, 20x20 viewBox. Use icon('name') to get an <svg> string,
// or add data-icon="name" to any element and call renderIcons() once
// the DOM is ready (done automatically on DOMContentLoaded).
// ============================================================
const ICONS = {
  // Navigation
  dashboard: '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
  blotter: '<path d="M6 2h9l4 4v16H6z"/><path d="M15 2v4h4"/><path d="M9 11h7M9 15h7M9 7h3"/>',
  incident: '<path d="M12 2 2 20h20L12 2z"/><path d="M12 9v5"/><circle cx="12" cy="17" r="0.5" fill="currentColor"/>',
  settlement: '<path d="M8 12l3 3 6-7"/><circle cx="12" cy="12" r="9"/>',
  census: '<circle cx="8" cy="8" r="3"/><path d="M2 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><circle cx="17" cy="9" r="2.5"/><path d="M14.5 20c.3-2.6 2.3-4.5 4.9-4.7"/>',
  clearance: '<path d="M7 2h8l4 4v16H7z"/><path d="M15 2v4h4"/><path d="M9 12l2 2 4-4"/>',
  indigency: '<path d="M6 2h9l4 4v16H6z"/><path d="M15 2v4h4"/><path d="M9 12h6M9 16h6"/><circle cx="9.5" cy="9" r="1"/>',
  heatmap: '<path d="M3 20l5-11 4 6 3-5 6 10z"/><path d="M3 20h18"/>',
  trends: '<path d="M3 17l5-6 4 3 7-9"/><path d="M14 5h5v5"/><path d="M3 21h18"/>',
  predictions: '<rect x="4" y="8" width="16" height="12" rx="2"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/><circle cx="9" cy="14" r="1" fill="currentColor" stroke="none"/><circle cx="15" cy="14" r="1" fill="currentColor" stroke="none"/>',
  users: '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5"/><circle cx="17" cy="9" r="2.3"/><path d="M15 13.8c2.6.5 4.5 2.7 4.8 5.4"/>',
  user: '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4.4 3.6-8 8-8s8 3.6 8 8"/>',
  key: '<circle cx="8" cy="15" r="4.5"/><path d="M11.2 11.8 20 3"/><path d="M16 7l3 3"/><path d="M13.3 10 16 12.7"/>',
  reports: '<path d="M6 2h9l4 4v16H6z"/><path d="M15 2v4h4"/><path d="M9 11h7M9 15h4"/>',
  settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6V21a2 2 0 1 1-4 0v-.2a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.6-1H3a2 2 0 1 1 0-4h.2a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.6V3a2 2 0 1 1 4 0v.2a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9c.3.6.9 1 1.6 1H21a2 2 0 1 1 0 4h-.2a1.7 1.7 0 0 0-1.6 1z"/>',

  // Seal / brand fallback (used only if logo.png fails to load)
  seal: '<circle cx="12" cy="12" r="9.5"/><path d="M9 8v5a3 3 0 0 0 6 0V8"/><path d="M9 8h6"/><path d="M12 16v3"/>',

  // Actions
  plus: '<path d="M12 5v14M5 12h14"/>',
  view: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
  viewOff: '<path d="M3 3l18 18"/><path d="M10.6 5.2A10.4 10.4 0 0 1 12 5c6.5 0 10 7 10 7a17.9 17.9 0 0 1-3.2 4.2M6.6 6.6C4 8.3 2 12 2 12s3.5 7 10 7a10 10 0 0 0 4.2-.9"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
  edit: '<path d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3z"/><path d="M13.5 6.5l3 3"/>',
  trash: '<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13"/><path d="M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
  bell: '<path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M9.5 19a2.5 2.5 0 0 0 5 0"/>',
  lock: '<rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>',
  save: '<path d="M5 4h11l3 3v13H5z"/><path d="M8 4v5h7V4"/><path d="M8 14h8v6H8z"/>',
  archive: '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8"/><path d="M10 12h4"/>',
  download: '<path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M4 19h16"/>',
  refresh: '<path d="M4 4v5h5"/><path d="M20 20v-5h-5"/><path d="M5.5 15A8 8 0 0 0 20 12"/><path d="M18.5 9A8 8 0 0 0 4 12"/>',
  printer: '<path d="M6 9V3h12v6"/><rect x="4" y="9" width="16" height="8" rx="1.5"/><path d="M6 15h12v6H6z"/>',
  folder: '<path d="M3 6a1 1 0 0 1 1-1h5l2 2h9a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/>',
  play: '<path d="M6 4l14 8-14 8z"/>',
  spinner: '<path d="M12 3v3"/><path d="M12 18v3"/><path d="M4.2 4.2l2.1 2.1"/><path d="M17.7 17.7l2.1 2.1"/><path d="M3 12h3"/><path d="M18 12h3"/><path d="M4.2 19.8l2.1-2.1"/><path d="M17.7 6.3l2.1-2.1"/>',
  check: '<path d="M4 12l6 6L20 6"/>',
  x: '<path d="M5 5l14 14M19 5L5 19"/>',
  logout: '<path d="M9 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
  building: '<path d="M4 21V7l8-4 8 4v14"/><path d="M9 21v-6h6v6"/><path d="M9 10h.01M15 10h.01M9 14h.01M15 14h.01"/>',
  info: '<circle cx="12" cy="12" r="9.5"/><path d="M12 11v6"/><path d="M12 7.5v.01"/>',
  warning: '<path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4"/><path d="M12 17v.01"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
  shield: '<path d="M12 3l7 3v6c0 4.5-3 8-7 9-4-1-7-4.5-7-9V6z"/>',
  database: '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
};

function iconSvg(name, size = 20) {
  const paths = ICONS[name];
  if (!paths) return '';
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="bc-icon">${paths}</svg>`;
}

function renderIcons(root = document) {
  root.querySelectorAll('[data-icon]').forEach(el => {
    const name = el.getAttribute('data-icon');
    const size = el.getAttribute('data-icon-size') || 20;
    if (!el.dataset.iconRendered) {
      el.innerHTML = iconSvg(name, size);
      el.dataset.iconRendered = '1';
    }
  });
}

document.addEventListener('DOMContentLoaded', () => renderIcons());

// Auto-render icons injected later (table re-renders, modals, toasts, etc.)
// so pages never need to remember to call renderIcons() manually.
const _bcIconObserver = new MutationObserver((mutations) => {
  for (const m of mutations) {
    for (const node of m.addedNodes) {
      if (node.nodeType !== 1) continue; // element nodes only
      if (node.hasAttribute && node.hasAttribute('data-icon')) renderIcons(node.parentNode || document);
      if (node.querySelector && node.querySelector('[data-icon]')) renderIcons(node);
    }
  }
});
document.addEventListener('DOMContentLoaded', () => {
  _bcIconObserver.observe(document.body, { childList: true, subtree: true });
});
