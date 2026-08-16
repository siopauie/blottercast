// ============================================================
// custom-controls.js — themed dropdown, date & time pickers.
//
// Progressively enhances every native <select>, <input type="date">,
// and <input type="time"> on the page with a floating, on-brand popup
// (see the matching CSS block in styles.css, "Custom select dropdown"
// / "Custom date & time pickers"). Native checkboxes are themed with
// pure CSS and need no JS — see styles.css.
//
// The underlying native element is always kept as the single source
// of truth: it stays in the DOM, keeps its id/name, keeps receiving
// real 'change' events, and continues to work with any existing code
// that reads or sets `.value` — including code that runs *after* this
// script (e.g. populating a <select> later, or setting a date field
// while editing a record). New form fields added to the page later
// (modals, dynamically-built rows, etc.) are picked up automatically
// via a MutationObserver, so nothing needs to call an "init" function.
// ============================================================
(function () {
  'use strict';

  let activePopover = null;

  function closeActivePopover() {
    if (activePopover) { activePopover.close(); activePopover = null; }
  }

  document.addEventListener('mousedown', (e) => {
    if (activePopover && !activePopover.el.contains(e.target) && e.target !== activePopover.trigger && !activePopover.trigger.contains(e.target)) {
      closeActivePopover();
    }
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeActivePopover(); });
  window.addEventListener('resize', closeActivePopover);
  // Capture-phase so this also sees scrolling on any scrollable ancestor of
  // the trigger (not just window scroll) and closes the popover so it isn't
  // left floating in the wrong spot. But a popover's OWN internal scrolling —
  // e.g. the time picker auto-scrolling its hour list to the selected hour,
  // or the select dropdown scrolling a pre-selected option into view — must
  // NOT count as "the page scrolled". Without this check that self-scroll
  // was misread as an outside scroll and closed the popover the instant it
  // opened, before the person ever saw it.
  window.addEventListener('scroll', (e) => {
    if (activePopover && activePopover.el.contains(e.target)) return;
    closeActivePopover();
  }, true);

  function positionPopover(panel, anchor) {
    const r = anchor.getBoundingClientRect();
    panel.style.left = '0px';
    panel.style.top = '0px';
    const panelW = panel.offsetWidth || 240;
    const panelH = panel.offsetHeight || 260;
    let left = r.left + window.scrollX;
    let top = r.bottom + window.scrollY + 6;
    const viewportW = document.documentElement.clientWidth;
    if (left + panelW > window.scrollX + viewportW - 8) {
      left = window.scrollX + viewportW - panelW - 8;
    }
    if (left < window.scrollX + 8) left = window.scrollX + 8;
    if (r.bottom + panelH + 6 > window.innerHeight && r.top - panelH - 6 > 0) {
      top = r.top + window.scrollY - panelH - 6;
    }
    panel.style.left = left + 'px';
    panel.style.top = top + 'px';
  }

  function pad2(n) { return String(n).padStart(2, '0'); }

  // ============================================================
  // SELECT
  // ============================================================
  function enhanceSelect(select) {
    if (select.dataset.bcSelect || select.multiple || select.hidden) return;
    select.dataset.bcSelect = '1';

    const wrap = document.createElement('div');
    wrap.className = 'bc-select-wrap';
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    select.classList.add('bc-select-native');
    select.tabIndex = -1;

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'bc-select-trigger ' + select.className.replace('bc-select-native', '').trim();
    if (select.getAttribute('style')) trigger.setAttribute('style', select.getAttribute('style'));
    trigger.innerHTML =
      '<span class="bc-select-value"></span>' +
      '<svg class="bc-select-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
    wrap.appendChild(trigger);

    function syncTrigger() {
      const opt = select.options[select.selectedIndex];
      const valSpan = trigger.querySelector('.bc-select-value');
      const text = opt ? (opt.textContent || '').trim() : '';
      valSpan.textContent = text || 'Select…';
      valSpan.classList.toggle('bc-select-placeholder', !opt || opt.disabled || !text);
      trigger.disabled = select.disabled;
      trigger.classList.toggle('bc-select-disabled', select.disabled);
    }

    // Keep the trigger label in sync when something sets `select.value`
    // programmatically (e.g. loading a record into an edit form).
    const valueDesc = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
    try {
      Object.defineProperty(select, 'value', {
        configurable: true,
        get() { return valueDesc.get.call(select); },
        set(v) { valueDesc.set.call(select, v); syncTrigger(); }
      });
    } catch (err) { /* ignore if it can't be redefined */ }

    select.addEventListener('change', syncTrigger);

    // Keep the trigger + option list in sync when <option>s are added,
    // removed, or replaced after the fact (e.g. year/month pickers that
    // get populated once the modal that holds them is built).
    new MutationObserver(syncTrigger).observe(select, {
      childList: true, subtree: true, attributes: true, attributeFilter: ['selected']
    });

    function buildPanel() {
      const panel = document.createElement('div');
      panel.className = 'bc-select-panel';
      Array.from(select.options).forEach((opt, i) => {
        if (opt.hidden) return;
        const item = document.createElement('div');
        item.className = 'bc-select-option';
        if (opt.disabled) item.classList.add('bc-select-option-disabled');
        if (i === select.selectedIndex) item.classList.add('bc-select-option-active');
        item.textContent = opt.textContent;
        if (!opt.disabled) {
          item.addEventListener('click', () => {
            select.selectedIndex = i;
            syncTrigger();
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
            closeActivePopover();
            trigger.focus();
          });
        }
        panel.appendChild(item);
      });
      if (!panel.children.length) {
        const empty = document.createElement('div');
        empty.className = 'bc-select-option bc-select-option-disabled';
        empty.textContent = 'No options';
        panel.appendChild(empty);
      }
      return panel;
    }

    function open() {
      if (select.disabled) return;
      closeActivePopover();
      const panel = buildPanel();
      document.body.appendChild(panel);
      panel.style.minWidth = trigger.getBoundingClientRect().width + 'px';
      positionPopover(panel, trigger);
      requestAnimationFrame(() => panel.classList.add('open'));
      trigger.classList.add('bc-select-trigger-open');
      const activeOpt = panel.querySelector('.bc-select-option-active');
      if (activeOpt && activeOpt.scrollIntoView) activeOpt.scrollIntoView({ block: 'nearest' });
      activePopover = {
        el: panel,
        trigger,
        close() {
          panel.classList.remove('open');
          trigger.classList.remove('bc-select-trigger-open');
          setTimeout(() => panel.remove(), 120);
        }
      };
    }

    trigger.addEventListener('click', () => {
      if (activePopover && activePopover.trigger === trigger) { closeActivePopover(); return; }
      open();
    });
    trigger.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
    });

    syncTrigger();
  }

  // ============================================================
  // DATE
  // ============================================================
  const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

  function parseDateVal(v) {
    if (!v) return null;
    const parts = v.split('-').map(Number);
    if (!parts[0]) return null;
    return new Date(parts[0], parts[1] - 1, parts[2]);
  }
  function fmtDateVal(y, m, d) { return `${y}-${pad2(m + 1)}-${pad2(d)}`; }

  function attachFieldIcon(input, svgInner) {
    input.readOnly = true;

    const wrap = document.createElement('div');
    wrap.className = 'bc-field-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    const icon = document.createElement('span');
    icon.className = 'bc-field-icon';
    icon.innerHTML = svgInner;
    wrap.appendChild(icon);
    return wrap;
  }

  function enhanceDateInput(input) {
    if (input.dataset.bcDate || input.hidden) return;
    input.dataset.bcDate = '1';
    input.classList.add('bc-date-enhanced');
    attachFieldIcon(input,
      '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></svg>'
    );

    let viewYear, viewMonth;
    // 'days' shows the usual month grid; 'years' shows a 12-year picker so
    // a date far from today (a date of birth, say) can be reached in a
    // couple of clicks instead of clicking "previous month" hundreds of
    // times. Clicking the header label toggles into 'years'; picking a
    // year returns to 'days' on that year, same month.
    let viewMode = 'days';
    let yearRangeStart;
    let panelRef;

    function build() {
      const selected = parseDateVal(input.value);
      const base = selected || new Date();
      if (viewYear === undefined) { viewYear = base.getFullYear(); viewMonth = base.getMonth(); }

      const min = input.min ? parseDateVal(input.min) : null;
      const max = input.max ? parseDateVal(input.max) : null;
      const minYear = min ? min.getFullYear() : null;
      const maxYear = max ? max.getFullYear() : null;

      const panel = document.createElement('div');
      panel.className = 'bc-datepicker-panel';

      const header = document.createElement('div');
      header.className = 'bc-dp-header';
      const prevBtn = document.createElement('button');
      prevBtn.type = 'button'; prevBtn.className = 'bc-dp-nav';
      const nextBtn = document.createElement('button');
      nextBtn.type = 'button'; nextBtn.className = 'bc-dp-nav';
      const label = document.createElement('button');
      label.type = 'button';
      label.className = 'bc-dp-label bc-dp-label-btn';

      if (viewMode === 'years') {
        if (yearRangeStart === undefined) yearRangeStart = viewYear - (viewYear % 12);
        prevBtn.innerHTML = '&#8249;'; prevBtn.setAttribute('aria-label', 'Previous years');
        nextBtn.innerHTML = '&#8250;'; nextBtn.setAttribute('aria-label', 'Next years');
        prevBtn.onclick = () => { yearRangeStart -= 12; rebuild(); };
        nextBtn.onclick = () => { yearRangeStart += 12; rebuild(); };
        label.textContent = `${yearRangeStart}\u2013${yearRangeStart + 11}`;
        label.setAttribute('aria-label', 'Back to month view');
        label.onclick = () => { viewMode = 'days'; rebuild(); };
      } else {
        prevBtn.innerHTML = '&#8249;'; prevBtn.setAttribute('aria-label', 'Previous month');
        nextBtn.innerHTML = '&#8250;'; nextBtn.setAttribute('aria-label', 'Next month');
        prevBtn.onclick = () => { viewMonth--; if (viewMonth < 0) { viewMonth = 11; viewYear--; } rebuild(); };
        nextBtn.onclick = () => { viewMonth++; if (viewMonth > 11) { viewMonth = 0; viewYear++; } rebuild(); };
        label.textContent = `${MONTH_NAMES[viewMonth]} ${viewYear}`;
        label.setAttribute('aria-label', 'Choose year');
        label.onclick = () => { viewMode = 'years'; yearRangeStart = viewYear - (viewYear % 12); rebuild(); };
      }
      header.append(prevBtn, label, nextBtn);
      panel.appendChild(header);

      if (viewMode === 'years') {
        const yearGrid = document.createElement('div');
        yearGrid.className = 'bc-dp-year-grid';
        const thisYear = new Date().getFullYear();
        for (let i = 0; i < 12; i++) {
          const y = yearRangeStart + i;
          const cell = document.createElement('button');
          cell.type = 'button';
          cell.className = 'bc-dp-year-item';
          cell.textContent = String(y);
          if (y === thisYear) cell.classList.add('bc-dp-year-item-current');
          if (y === viewYear) cell.classList.add('bc-dp-year-item-selected');
          const isDisabled = (minYear !== null && y < minYear) || (maxYear !== null && y > maxYear);
          if (isDisabled) {
            cell.disabled = true;
            cell.classList.add('bc-dp-year-item-disabled');
          } else {
            cell.addEventListener('click', () => { viewYear = y; viewMode = 'days'; rebuild(); });
          }
          yearGrid.appendChild(cell);
        }
        panel.appendChild(yearGrid);
        return panel;
      }

      const grid = document.createElement('div');
      grid.className = 'bc-dp-grid';
      ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'].forEach(w => {
        const wd = document.createElement('div'); wd.className = 'bc-dp-weekday'; wd.textContent = w; grid.appendChild(wd);
      });
      const firstDay = new Date(viewYear, viewMonth, 1).getDay();
      const daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();
      const today = new Date(); today.setHours(0, 0, 0, 0);
      for (let i = 0; i < firstDay; i++) grid.appendChild(document.createElement('div'));
      for (let day = 1; day <= daysInMonth; day++) {
        const cellDate = new Date(viewYear, viewMonth, day);
        const cell = document.createElement('button');
        cell.type = 'button';
        cell.className = 'bc-dp-day';
        cell.textContent = String(day);
        if (selected && cellDate.getTime() === new Date(selected.getFullYear(), selected.getMonth(), selected.getDate()).getTime()) {
          cell.classList.add('bc-dp-day-selected');
        }
        if (cellDate.getTime() === today.getTime()) cell.classList.add('bc-dp-day-today');
        const isDisabled = (min && cellDate < min) || (max && cellDate > max);
        if (isDisabled) {
          cell.disabled = true;
          cell.classList.add('bc-dp-day-disabled');
        } else {
          cell.addEventListener('click', () => {
            input.value = fmtDateVal(viewYear, viewMonth, day);
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            closeActivePopover();
          });
        }
        grid.appendChild(cell);
      }
      panel.appendChild(grid);

      const footer = document.createElement('div');
      footer.className = 'bc-dp-footer';
      const todayBtn = document.createElement('button');
      todayBtn.type = 'button'; todayBtn.className = 'bc-dp-today-btn'; todayBtn.textContent = 'Today';
      todayBtn.onclick = () => {
        const t = new Date(); t.setHours(0, 0, 0, 0);
        if ((max && t > max) || (min && t < min)) return;
        input.value = fmtDateVal(t.getFullYear(), t.getMonth(), t.getDate());
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        closeActivePopover();
      };
      footer.appendChild(todayBtn);
      if (!input.required && input.value) {
        const clearBtn = document.createElement('button');
        clearBtn.type = 'button'; clearBtn.className = 'bc-dp-clear-btn'; clearBtn.textContent = 'Clear';
        clearBtn.onclick = () => {
          input.value = '';
          input.dispatchEvent(new Event('input', { bubbles: true }));
          input.dispatchEvent(new Event('change', { bubbles: true }));
          closeActivePopover();
        };
        footer.appendChild(clearBtn);
      }
      panel.appendChild(footer);
      return panel;
    }

    function rebuild() {
      const fresh = build();
      panelRef.replaceWith(fresh);
      panelRef = fresh;
      positionPopover(panelRef, input);
      panelRef.classList.add('open');
      // Rebuilding swaps in a brand-new panel element (old one is detached).
      // If this picker is still the open popover, point activePopover at
      // the new element too -- otherwise the very next click inside the
      // panel is mistaken for an outside click and closes it immediately,
      // which is what made navigating more than one step (e.g. hopping
      // through years) impossible.
      if (activePopover && activePopover.trigger === input) activePopover.el = panelRef;
    }

    function open() {
      closeActivePopover();
      viewYear = undefined; viewMonth = undefined;
      viewMode = 'days'; yearRangeStart = undefined;
      panelRef = build();
      document.body.appendChild(panelRef);
      positionPopover(panelRef, input);
      requestAnimationFrame(() => panelRef.classList.add('open'));
      activePopover = {
        el: panelRef, trigger: input,
        close() { panelRef.classList.remove('open'); setTimeout(() => panelRef.remove(), 120); }
      };
    }

    input.addEventListener('mousedown', (e) => {
      e.preventDefault();
      input.focus();
      if (activePopover && activePopover.trigger === input) closeActivePopover();
      else open();
    });
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
      if (e.key === 'Escape') closeActivePopover();
    });
  }

  // ============================================================
  // TIME
  // ============================================================
  function parseTimeVal(v) {
    if (!v) return null;
    const parts = v.split(':').map(Number);
    if (isNaN(parts[0])) return null;
    return { h: parts[0], m: parts[1] || 0 };
  }
  function fmtTimeVal(h, m) { return `${pad2(h)}:${pad2(m)}`; }

  function enhanceTimeInput(input) {
    if (input.dataset.bcTime || input.hidden) return;
    input.dataset.bcTime = '1';
    input.classList.add('bc-time-enhanced');
    attachFieldIcon(input,
      '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg>'
    );

    let panelRef;

    function build() {
      const t = parseTimeVal(input.value) || { h: new Date().getHours(), m: 0 };
      const panel = document.createElement('div');
      panel.className = 'bc-timepicker-panel';

      const cols = document.createElement('div');
      cols.className = 'bc-tp-cols';

      const hourCol = document.createElement('div');
      hourCol.className = 'bc-tp-col';
      for (let h = 0; h < 24; h++) {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'bc-tp-item' + (h === t.h ? ' bc-tp-item-active' : '');
        item.textContent = pad2(h);
        item.onclick = () => {
          input.value = fmtTimeVal(h, t.m);
          input.dispatchEvent(new Event('input', { bubbles: true }));
          input.dispatchEvent(new Event('change', { bubbles: true }));
          rebuild();
        };
        hourCol.appendChild(item);
      }

      const minCol = document.createElement('div');
      minCol.className = 'bc-tp-col';
      const minuteSet = new Set([0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55]);
      minuteSet.add(t.m);
      Array.from(minuteSet).sort((a, b) => a - b).forEach(m => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'bc-tp-item' + (m === t.m ? ' bc-tp-item-active' : '');
        item.textContent = pad2(m);
        item.onclick = () => {
          input.value = fmtTimeVal(t.h, m);
          input.dispatchEvent(new Event('input', { bubbles: true }));
          input.dispatchEvent(new Event('change', { bubbles: true }));
          rebuild();
        };
        minCol.appendChild(item);
      });

      cols.append(hourCol, minCol);
      panel.appendChild(cols);

      const footer = document.createElement('div');
      footer.className = 'bc-dp-footer';
      const nowBtn = document.createElement('button');
      nowBtn.type = 'button'; nowBtn.className = 'bc-dp-today-btn'; nowBtn.textContent = 'Now';
      nowBtn.onclick = () => {
        const n = new Date();
        input.value = fmtTimeVal(n.getHours(), n.getMinutes());
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        closeActivePopover();
      };
      const doneBtn = document.createElement('button');
      doneBtn.type = 'button'; doneBtn.className = 'bc-dp-clear-btn'; doneBtn.textContent = 'Done';
      doneBtn.onclick = () => closeActivePopover();
      footer.append(nowBtn, doneBtn);
      panel.appendChild(footer);
      return panel;
    }

    function rebuild() {
      const fresh = build();
      panelRef.replaceWith(fresh);
      panelRef = fresh;
      positionPopover(panelRef, input);
      panelRef.classList.add('open');
      if (activePopover && activePopover.trigger === input) activePopover.el = panelRef;
      const active = panelRef.querySelector('.bc-tp-item-active');
      if (active && active.scrollIntoView) active.scrollIntoView({ block: 'center' });
    }

    function open() {
      closeActivePopover();
      panelRef = build();
      document.body.appendChild(panelRef);
      positionPopover(panelRef, input);
      requestAnimationFrame(() => {
        panelRef.classList.add('open');
        const active = panelRef.querySelector('.bc-tp-item-active');
        if (active && active.scrollIntoView) active.scrollIntoView({ block: 'center' });
      });
      activePopover = {
        el: panelRef, trigger: input,
        close() { panelRef.classList.remove('open'); setTimeout(() => panelRef.remove(), 120); }
      };
    }

    input.addEventListener('mousedown', (e) => {
      e.preventDefault();
      input.focus();
      if (activePopover && activePopover.trigger === input) closeActivePopover();
      else open();
    });
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
      if (e.key === 'Escape') closeActivePopover();
    });
  }

  // ============================================================
  // Auto-discovery: enhance everything now, and anything added later.
  // ============================================================
  function enhanceAll(root) {
    root.querySelectorAll('select').forEach(enhanceSelect);
    root.querySelectorAll('input[type="date"]').forEach(enhanceDateInput);
    root.querySelectorAll('input[type="time"]').forEach(enhanceTimeInput);
  }

  document.addEventListener('DOMContentLoaded', () => {
    enhanceAll(document);
    const mo = new MutationObserver((mutations) => {
      for (const m of mutations) {
        m.addedNodes.forEach((node) => {
          if (node.nodeType !== 1) return;
          if (node.matches) {
            if (node.matches('select')) { enhanceSelect(node); return; }
            if (node.matches('input[type="date"]')) { enhanceDateInput(node); return; }
            if (node.matches('input[type="time"]')) { enhanceTimeInput(node); return; }
          }
          if (node.querySelectorAll) enhanceAll(node);
        });
      }
    });
    mo.observe(document.body, { childList: true, subtree: true });
  });
})();
