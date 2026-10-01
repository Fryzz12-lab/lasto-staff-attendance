/**
 * Date filter shortcuts + DD/MM/YYYY date inputs (Flatpickr).
 * Event delegation / re-init safe for SPA content swaps.
 */
(function () {
  const pendingFilters = new WeakMap();

  function showFilterLoading(form) {
    const reportWrapper = document.getElementById('reportContentWrapper');
    if (reportWrapper) {
      reportWrapper.classList.add('is-loading');
    }
    const progressBar = document.getElementById('chronoProgressBar');
    if (progressBar) {
      progressBar.classList.remove('is-done');
      progressBar.classList.add('is-active');
    }
    if (form) {
      form.classList.add('is-filtering');
    }
  }

  function submitFilter(form, triggeredBy) {
    clearTimeout(pendingFilters.get(form));
    if (!form.isConnected || !form.checkValidity()) return;
    const from = form.querySelector('[name="from"]');
    const to = form.querySelector('[name="to"]');
    if (from && to) {
      const fVal = (from.value || '').trim();
      const tVal = (to.value || '').trim();
      // Block if date range is inverted (from > to)
      if (fVal && tVal && fVal > tVal) return;
      // If user is currently typing/editing one of the date fields, wait for both
      if (triggeredBy && (triggeredBy.name === 'from' || triggeredBy.name === 'to')) {
        if ((fVal && !tVal) || (!fVal && tVal)) return;
      }
    }
    showFilterLoading(form);
    if (typeof form.requestSubmit === 'function') form.requestSubmit();
    else form.submit();
  }

  function initAutoFilters(scope) {
    scope.querySelectorAll('.app-content form[method="get"]').forEach((form) => {
      if (form.dataset.autoFilter === '0') return;
      form.dataset.autoFilter = '1';
      const hint = form.querySelector('[data-auto-filter-hint]');
      if (hint) hint.hidden = false;
      form.querySelectorAll('button:not([type]), button[type="submit"], input[type="submit"]').forEach((button) => {
        if (button.closest('.search-bar-combo') || button.dataset.keepVisible === '1') return;
        button.hidden = true;
        const wrapper = button.parentElement;
        if (wrapper !== form && wrapper.children.length === 1 && /\bcol-/.test(wrapper.className)) {
          wrapper.hidden = true;
        }
      });
    });
  }

  function pad(n) {
    return String(n).padStart(2, '0');
  }

  function formatYmd(d) {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }

  function parseToday(el) {
    const raw = (el.getAttribute('data-today') || '').trim();
    if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
      const parts = raw.split('-').map(Number);
      return new Date(parts[0], parts[1] - 1, parts[2]);
    }
    const now = new Date();
    return new Date(now.getFullYear(), now.getMonth(), now.getDate());
  }

  function rangeForPreset(key, today, mode) {
    const y = today.getFullYear();
    const m = today.getMonth();
    const d = today.getDate();
    const t = new Date(y, m, d);

    if (key === 'today') {
      const s = formatYmd(t);
      return { from: s, to: s, date: s };
    }
    if (key === 'this_week') {
      const dayOfWeek = t.getDay(); // 0 is Sunday, 1 is Monday...
      const diffToMonday = (dayOfWeek === 0 ? -6 : 1) - dayOfWeek;
      const monday = new Date(y, m, d + diffToMonday);
      const sunday = new Date(monday.getFullYear(), monday.getMonth(), monday.getDate() + 6);
      return { from: formatYmd(monday), to: formatYmd(sunday) };
    }
    if (key === 'next_week') {
      const dayOfWeek = t.getDay();
      const diffToNextMonday = (dayOfWeek === 0 ? 1 : 8 - dayOfWeek);
      const monday = new Date(y, m, d + diffToNextMonday);
      const sunday = new Date(monday.getFullYear(), monday.getMonth(), monday.getDate() + 6);
      return { from: formatYmd(monday), to: formatYmd(sunday) };
    }
    if (key === 'next_month') {
      const first = new Date(y, m + 1, 1);
      const last = new Date(y, m + 2, 0);
      return { from: formatYmd(first), to: formatYmd(last) };
    }
    if (key === 'past30') {
      return { from: formatYmd(new Date(y, m, d - 29)), to: formatYmd(t) };
    }
    if (key === 'yesterday') {
      const yest = new Date(y, m, d - 1);
      const s = formatYmd(yest);
      return { from: s, to: s, date: s };
    }
    if (key === 'day_before') {
      const day = new Date(y, m, d - 2);
      const s = formatYmd(day);
      return { from: s, to: s, date: s };
    }
    if (key === 'last7') {
      return { from: formatYmd(new Date(y, m, d - 6)), to: formatYmd(t) };
    }
    if (key === 'last30') {
      return { from: formatYmd(new Date(y, m, d - 29)), to: formatYmd(t) };
    }
    if (key === 'this_month') {
      const first = new Date(y, m, 1);
      const last = new Date(y, m + 1, 0);
      return { from: formatYmd(first), to: formatYmd(last) };
    }
    if (key === 'last_month') {
      const first = new Date(y, m - 1, 1);
      const last = new Date(y, m, 0);
      return { from: formatYmd(first), to: formatYmd(last) };
    }
    return null;
  }

  function findNamedInput(form, name) {
    if (!form || !name) return null;
    return form.querySelector('input[name="' + name + '"]');
  }

  function setInputValue(input, isoDate) {
    if (!input) return;
    if (input._flatpickr) {
      // Presets update both endpoints before submitting once.
      input._flatpickr.setDate(isoDate, false, 'Y-m-d');
      return;
    }
    input.value = isoDate;
  }

  function applyPreset(wrap, btn) {
    const form = wrap.closest('form');
    if (!form) return;

    const mode = wrap.getAttribute('data-date-presets') || 'range';
    const key = btn.getAttribute('data-preset');
    const range = rangeForPreset(key, parseToday(wrap), mode);
    if (!range) return;

    if (mode === 'single') {
      const input = findNamedInput(form, wrap.getAttribute('data-date-name') || 'date');
      setInputValue(input, range.date);
    } else {
      const from = findNamedInput(form, wrap.getAttribute('data-from-name') || 'from');
      const to = findNamedInput(form, wrap.getAttribute('data-to-name') || 'to');
      setInputValue(from, range.from);
      setInputValue(to, range.to);
    }

    wrap.querySelectorAll('.date-preset').forEach((el) => {
      el.classList.toggle('is-active', el === btn);
      el.setAttribute('aria-pressed', String(el === btn));
    });

    submitFilter(form);
  }

  function syncPresets(form) {
    form.querySelectorAll('[data-date-presets]').forEach((wrap) => {
      let matched = false;
      wrap.querySelectorAll('.date-preset').forEach((button) => {
        const range = rangeForPreset(button.dataset.preset, parseToday(wrap), wrap.dataset.datePresets || 'range');
        const single = wrap.dataset.datePresets === 'single';
        const matches = range && (single
          ? findNamedInput(form, wrap.dataset.dateName || 'date')?.value === range.date
          : findNamedInput(form, wrap.dataset.fromName || 'from')?.value === range.from
            && findNamedInput(form, wrap.dataset.toName || 'to')?.value === range.to);
        const active = !matched && !!matches;
        if (active) matched = true;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', String(active));
      });
    });
  }

  function initDateInputs(root) {
    const scope = root || document;
    initAutoFilters(scope);
    if (typeof flatpickr !== 'function') return;
    scope.querySelectorAll('input[type="date"]').forEach((el) => {
      if (el._flatpickr) return;
      if (el.dataset.fpSkip === '1') return;

      const min = el.getAttribute('min') || undefined;
      const max = el.getAttribute('max') || undefined;
      const classes = (el.className || 'form-control').trim();

      flatpickr(el, {
        dateFormat: 'Y-m-d',
        altInput: true,
        altFormat: 'd/m/Y',
        allowInput: true,
        disableMobile: true,
        minDate: min,
        maxDate: max,
        monthSelectorType: 'static',
        onReady: function (_dates, _str, instance) {
          if (!instance.altInput) return;
          instance.altInput.className = classes + ' flatpickr-alt';
          if (el.id) {
            instance.altInput.id = el.id + '-display';
            document.querySelectorAll('label[for]').forEach((label) => {
              if (label.htmlFor === el.id) label.htmlFor = instance.altInput.id;
            });
          }
          instance.altInput.setAttribute('placeholder', 'DD/MM/YYYY');
          instance.altInput.setAttribute('inputmode', 'numeric');
          instance.altInput.setAttribute('autocomplete', 'off');
          if (el.required) instance.altInput.required = true;
          if (el.disabled) instance.altInput.disabled = true;
        },
      });
    });

    scope.querySelectorAll('input[type="time"][data-time-picker]').forEach((el) => {
      if (el._flatpickr || el.dataset.fpSkip === '1') return;
      const classes = (el.className || 'form-control').trim();

      flatpickr(el, {
        dateFormat: 'H:i',
        altInput: true,
        altFormat: 'h:i K',
        enableTime: true,
        noCalendar: true,
        time_24hr: false,
        minuteIncrement: 1,
        allowInput: true,
        disableMobile: true,
        onReady: function (_dates, _str, instance) {
          if (!instance.altInput) return;
          instance.altInput.className = classes + ' flatpickr-alt';
          if (el.id) {
            instance.altInput.id = el.id + '-display';
            document.querySelectorAll('label[for]').forEach((label) => {
              if (label.htmlFor === el.id) label.htmlFor = instance.altInput.id;
            });
          }
          instance.altInput.setAttribute('placeholder', 'hh:mm AM/PM');
          instance.altInput.setAttribute('autocomplete', 'off');
          if (el.required) instance.altInput.required = true;
          if (el.disabled) instance.altInput.disabled = true;
        },
      });
    });
  }

  if (!window.__CHRONOS_DATE_FILTER__) {
    window.__CHRONOS_DATE_FILTER__ = true;
    document.addEventListener('change', (event) => {
      const input = event.target;
      const form = input.form;
      if (!form || form.dataset.autoFilter !== '1' || !input.name || input.disabled) return;
      syncPresets(form);
      clearTimeout(pendingFilters.get(form));
      showFilterLoading(form);
      pendingFilters.set(form, setTimeout(() => submitFilter(form, input), 120));
    });
    document.addEventListener('submit', (event) => {
      clearTimeout(pendingFilters.get(event.target));
      showFilterLoading(event.target);
    });
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.date-preset');
      if (!btn) return;
      const wrap = btn.closest('[data-date-presets]');
      if (!wrap) return;
      e.preventDefault();
      applyPreset(wrap, btn);
    });
  }

  window.ChronosDateFilter = {
    init: initDateInputs,
    setInputValue: setInputValue,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initDateInputs(document);
    });
  } else {
    initDateInputs(document);
  }
})();
