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
    if (!activePopover) return;
    if (activePopover.el && activePopover.el.contains(e.target)) return;
    if (activePopover.trigger && (activePopover.trigger === e.target || activePopover.trigger.contains(e.target))) return;
    if (activePopover.wrap && (activePopover.wrap === e.target || activePopover.wrap.contains(e.target))) return;
    closeActivePopover();
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
    panel.style.position = 'fixed';
    panel.style.left = '0px';
    panel.style.top = '0px';
    const panelW = panel.offsetWidth || 284;
    const panelH = panel.offsetHeight || 300;
    let left = r.left;
    let top = r.bottom + 6;
    const viewportW = document.documentElement.clientWidth;
    const viewportH = window.innerHeight;
    if (left + panelW > viewportW - 8) {
      left = viewportW - panelW - 8;
    }
    if (left < 8) left = 8;
    if (top + panelH > viewportH - 8 && r.top - panelH - 6 > 0) {
      top = r.top - panelH - 6;
    }
    panel.style.left = Math.round(left) + 'px';
    panel.style.top = Math.round(top) + 'px';
    panel.style.zIndex = '99999';
  }

  function pad2(n) { return String(n).padStart(2, '0'); }

  // ============================================================
  // SELECT
  // ============================================================
  function enhanceSelect(select) {
    if (select.dataset.bcSelect || select.multiple || select.hidden || select.classList.contains('bc-dp-select') || select.closest('.bc-datepicker-panel')) return;
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
    const wrap = attachFieldIcon(input,
      '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></svg>'
    );

    let viewYear, viewMonth;
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
      prevBtn.type = 'button';
      prevBtn.className = 'bc-dp-nav';
      prevBtn.innerHTML = '&#8249;';
      prevBtn.setAttribute('aria-label', 'Previous month');
      const canPrev = !min || (viewYear > minYear || (viewYear === minYear && viewMonth > min.getMonth()));
      if (!canPrev) {
        prevBtn.disabled = true;
        prevBtn.style.opacity = '0.35';
        prevBtn.style.cursor = 'not-allowed';
      } else {
        prevBtn.onclick = (e) => {
          e.stopPropagation();
          viewMonth--;
          if (viewMonth < 0) { viewMonth = 11; viewYear--; }
          rebuild();
        };
      }

      const selectorsWrap = document.createElement('div');
      selectorsWrap.className = 'bc-dp-selectors';

      // Month dropdown selector
      const monthSelect = document.createElement('select');
      monthSelect.className = 'bc-dp-select bc-dp-month-select';
      monthSelect.setAttribute('aria-label', 'Select Month');
      MONTH_NAMES.forEach((name, idx) => {
        const opt = document.createElement('option');
        opt.value = String(idx);
        opt.textContent = name;
        if (idx === viewMonth) opt.selected = true;
        if (min && viewYear === minYear && idx < min.getMonth()) opt.disabled = true;
        if (max && viewYear === maxYear && idx > max.getMonth()) opt.disabled = true;
        monthSelect.appendChild(opt);
      });
      monthSelect.onchange = (e) => {
        e.stopPropagation();
        viewMonth = parseInt(e.target.value, 10);
        rebuild();
      };

      // Year dropdown selector
      const yearSelect = document.createElement('select');
      yearSelect.className = 'bc-dp-select bc-dp-year-select';
      yearSelect.setAttribute('aria-label', 'Select Year');

      const currentYear = new Date().getFullYear();
      const startYear = minYear !== null ? minYear : Math.min(1920, viewYear - 10);
      const endYear = maxYear !== null ? maxYear : Math.max(currentYear + 10, viewYear + 10);

      for (let y = endYear; y >= startYear; y--) {
        const opt = document.createElement('option');
        opt.value = String(y);
        opt.textContent = String(y);
        if (y === viewYear) opt.selected = true;
        yearSelect.appendChild(opt);
      }
      yearSelect.onchange = (e) => {
        e.stopPropagation();
        viewYear = parseInt(e.target.value, 10);
        rebuild();
      };

      selectorsWrap.append(monthSelect, yearSelect);

      const nextBtn = document.createElement('button');
      nextBtn.type = 'button';
      nextBtn.className = 'bc-dp-nav';
      nextBtn.innerHTML = '&#8250;';
      nextBtn.setAttribute('aria-label', 'Next month');
      const canNext = !max || (viewYear < maxYear || (viewYear === maxYear && viewMonth < max.getMonth()));
      if (!canNext) {
        nextBtn.disabled = true;
        nextBtn.style.opacity = '0.35';
        nextBtn.style.cursor = 'not-allowed';
      } else {
        nextBtn.onclick = (e) => {
          e.stopPropagation();
          viewMonth++;
          if (viewMonth > 11) { viewMonth = 0; viewYear++; }
          rebuild();
        };
      }

      header.append(prevBtn, selectorsWrap, nextBtn);
      panel.appendChild(header);

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
      viewYear = undefined;
      viewMonth = undefined;
      panelRef = build();
      document.body.appendChild(panelRef);
      positionPopover(panelRef, input);
      requestAnimationFrame(() => panelRef.classList.add('open'));
      activePopover = {
        el: panelRef, trigger: input, wrap,
        close() { panelRef.classList.remove('open'); setTimeout(() => panelRef.remove(), 120); }
      };
    }

    const handleTrigger = (e) => {
      e.preventDefault();
      e.stopPropagation();
      input.focus();
      if (activePopover && (activePopover.trigger === input || activePopover.wrap === wrap)) {
        closeActivePopover();
      } else {
        open();
      }
    };

    input.addEventListener('click', handleTrigger);
    if (wrap) {
      wrap.addEventListener('click', (e) => {
        if (e.target !== input) handleTrigger(e);
      });
    }

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

  function getTimeSettingIs12Hour() {
    if (typeof bcIs12Hour === 'function') return bcIs12Hour();
    const stored = localStorage.getItem('bc_time_format');
    return !stored || stored.toLowerCase().includes('12');
  }

  function getAssociatedDate(input) {
    const targetSel = input.getAttribute('data-date-target');
    if (targetSel) {
      const targetEl = document.querySelector(targetSel);
      if (targetEl && targetEl.value) return targetEl.value;
    }
    const form = input.closest('form');
    if (form) {
      const dateEl = form.querySelector('input[type="date"]');
      if (dateEl && dateEl.value) return dateEl.value;
    }
    const modal = input.closest('.modal-box') || input.closest('.modal-overlay');
    if (modal) {
      const dateEl = modal.querySelector('input[type="date"]');
      if (dateEl && dateEl.value) return dateEl.value;
    }
    return null;
  }

  function enhanceTimeInput(input) {
    if (input.dataset.bcTime || input.hidden) return;
    input.dataset.bcTime = '1';
    input.classList.add('bc-time-enhanced');
    const wrap = attachFieldIcon(input,
      '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg>'
    );

    let panelRef;

    function build() {
      const is12 = getTimeSettingIs12Hour();
      const rawVal = input.value;
      const parsed = parseTimeVal(rawVal);
      const now = new Date();
      const curH = now.getHours();
      const curM = now.getMinutes();

      let t = parsed ? { ...parsed } : { h: curH, m: Math.floor(curM / 5) * 5 };

      // Associated date check for future time prevention
      const assocDate = getAssociatedDate(input);
      let isToday = false;
      let isFutureDate = false;
      if (assocDate) {
        const todayStr = `${now.getFullYear()}-${pad2(now.getMonth() + 1)}-${pad2(now.getDate())}`;
        isToday = (assocDate === todayStr);
        const d = new Date(assocDate + 'T00:00:00');
        const tZero = new Date(); tZero.setHours(0, 0, 0, 0);
        isFutureDate = (d.getTime() > tZero.getTime());
      }
      if (!assocDate && (input.dataset.noFuture !== undefined || input.hasAttribute('data-no-future'))) {
        isToday = true;
      }

      const panel = document.createElement('div');
      panel.className = 'bc-timepicker-panel' + (is12 ? ' bc-tp-12h' : '');

      const cols = document.createElement('div');
      cols.className = 'bc-tp-cols';

      if (is12) {
        // 12-Hour Mode: Hours (1–12), Minutes (00–55), AM/PM
        let period = t.h >= 12 ? 'PM' : 'AM';
        let h12 = t.h % 12 === 0 ? 12 : t.h % 12;

        // 1. Hour Column (1–12)
        const hourCol = document.createElement('div');
        hourCol.className = 'bc-tp-col';
        for (let h = 1; h <= 12; h++) {
          const item = document.createElement('button');
          item.type = 'button';
          item.className = 'bc-tp-item' + (h === h12 ? ' bc-tp-item-active' : '');
          item.textContent = pad2(h);

          const h24 = (h % 12) + (period === 'PM' ? 12 : 0);
          let disabled = isFutureDate;
          if (isToday && h24 > curH) disabled = true;

          if (disabled) {
            item.disabled = true;
            item.classList.add('bc-tp-item-disabled');
            item.title = 'Cannot select a future time';
          } else {
            item.onclick = () => {
              const newH24 = (h % 12) + (period === 'PM' ? 12 : 0);
              input.value = fmtTimeVal(newH24, t.m);
              input.dispatchEvent(new Event('input', { bubbles: true }));
              input.dispatchEvent(new Event('change', { bubbles: true }));
              rebuild();
            };
          }
          hourCol.appendChild(item);
        }

        // 2. Minute Column
        const minCol = document.createElement('div');
        minCol.className = 'bc-tp-col';
        const minuteSet = new Set([0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55]);
        minuteSet.add(t.m);
        const curH24 = (h12 % 12) + (period === 'PM' ? 12 : 0);
        Array.from(minuteSet).sort((a, b) => a - b).forEach(m => {
          const item = document.createElement('button');
          item.type = 'button';
          item.className = 'bc-tp-item' + (m === t.m ? ' bc-tp-item-active' : '');
          item.textContent = pad2(m);

          let disabled = isFutureDate;
          if (isToday) {
            if (curH24 > curH) disabled = true;
            else if (curH24 === curH && m > curM) disabled = true;
          }

          if (disabled) {
            item.disabled = true;
            item.classList.add('bc-tp-item-disabled');
            item.title = 'Cannot select a future time';
          } else {
            item.onclick = () => {
              input.value = fmtTimeVal(curH24, m);
              input.dispatchEvent(new Event('input', { bubbles: true }));
              input.dispatchEvent(new Event('change', { bubbles: true }));
              rebuild();
            };
          }
          minCol.appendChild(item);
        });

        // 3. AM / PM Column
        const ampmCol = document.createElement('div');
        ampmCol.className = 'bc-tp-col bc-tp-col-ampm';
        ['AM', 'PM'].forEach(p => {
          const item = document.createElement('button');
          item.type = 'button';
          item.className = 'bc-tp-item' + (p === period ? ' bc-tp-item-active' : '');
          item.textContent = p;

          let disabled = isFutureDate;
          if (isToday && p === 'PM' && curH < 12) {
            disabled = true;
          }

          if (disabled) {
            item.disabled = true;
            item.classList.add('bc-tp-item-disabled');
            item.title = 'Cannot select a future time';
          } else {
            item.onclick = () => {
              let newH24 = (h12 % 12) + (p === 'PM' ? 12 : 0);
              if (isToday && newH24 > curH) newH24 = curH;
              input.value = fmtTimeVal(newH24, t.m);
              input.dispatchEvent(new Event('input', { bubbles: true }));
              input.dispatchEvent(new Event('change', { bubbles: true }));
              rebuild();
            };
          }
          ampmCol.appendChild(item);
        });

        cols.append(hourCol, minCol, ampmCol);
      } else {
        // 24-Hour Mode: Hours (00–23), Minutes (00–55), No AM/PM column
        const hourCol = document.createElement('div');
        hourCol.className = 'bc-tp-col';
        for (let h = 0; h < 24; h++) {
          const item = document.createElement('button');
          item.type = 'button';
          item.className = 'bc-tp-item' + (h === t.h ? ' bc-tp-item-active' : '');
          item.textContent = pad2(h);

          let disabled = isFutureDate;
          if (isToday && h > curH) disabled = true;

          if (disabled) {
            item.disabled = true;
            item.classList.add('bc-tp-item-disabled');
            item.title = 'Cannot select a future time';
          } else {
            item.onclick = () => {
              input.value = fmtTimeVal(h, t.m);
              input.dispatchEvent(new Event('input', { bubbles: true }));
              input.dispatchEvent(new Event('change', { bubbles: true }));
              rebuild();
            };
          }
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

          let disabled = isFutureDate;
          if (isToday) {
            if (t.h > curH) disabled = true;
            else if (t.h === curH && m > curM) disabled = true;
          }

          if (disabled) {
            item.disabled = true;
            item.classList.add('bc-tp-item-disabled');
            item.title = 'Cannot select a future time';
          } else {
            item.onclick = () => {
              input.value = fmtTimeVal(t.h, m);
              input.dispatchEvent(new Event('input', { bubbles: true }));
              input.dispatchEvent(new Event('change', { bubbles: true }));
              rebuild();
            };
          }
          minCol.appendChild(item);
        });

        cols.append(hourCol, minCol);
      }

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
      panelRef.querySelectorAll('.bc-tp-item-active').forEach(active => {
        if (active && active.scrollIntoView) active.scrollIntoView({ block: 'center' });
      });
    }

    function open() {
      closeActivePopover();
      panelRef = build();
      document.body.appendChild(panelRef);
      positionPopover(panelRef, input);
      requestAnimationFrame(() => {
        panelRef.classList.add('open');
        panelRef.querySelectorAll('.bc-tp-item-active').forEach(active => {
          if (active && active.scrollIntoView) active.scrollIntoView({ block: 'center' });
        });
      });
      activePopover = {
        el: panelRef, trigger: input, wrap,
        close() { panelRef.classList.remove('open'); setTimeout(() => panelRef.remove(), 120); }
      };
    }

    const handleTrigger = (e) => {
      e.preventDefault();
      e.stopPropagation();
      input.focus();
      if (activePopover && (activePopover.trigger === input || activePopover.wrap === wrap)) {
        closeActivePopover();
      } else {
        open();
      }
    };

    input.addEventListener('click', handleTrigger);
    if (wrap) {
      wrap.addEventListener('click', (e) => {
        if (e.target !== input) handleTrigger(e);
      });
    }

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
