/**
 * Shared front-end helpers
 */
(function () {
  function normalizeAccountInput(input) {
    if (!(input instanceof HTMLInputElement)) return;
    if (!['username', 'full_name', 'email', 'phone'].includes(input.name)) return;
    const start = input.selectionStart;
    const end = input.selectionEnd;
    const value = input.value;
    if (input.name === 'phone') {
      const phone = value.trim();
      const valid = /^[1-9][0-9]{8,9}$/.test(phone);
      input.setCustomValidity(phone === '' || valid ? '' : 'Enter 9 or 10 digits after +60, without a leading 0, spaces or hyphens.');
    }
    if (input.name === 'full_name') input.value = value.toUpperCase();
    if (input.name === 'username') input.value = value.toLowerCase().replace(/\s/g, '_');
    if (input.name === 'email') {
      input.value = value.toLowerCase().trim();
      const valid = /^[^\s@]+@(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+com$/.test(input.value);
      input.setCustomValidity(input.value === '' || valid ? '' : 'Enter a valid email ending in .com, for example username@example.com.');
    }
    if (start !== null && end !== null && value !== input.value) input.setSelectionRange(start, end);
  }
  document.addEventListener('input', (event) => {
    if (!event.isComposing) normalizeAccountInput(event.target);
  }, true);
  document.addEventListener('compositionend', (event) => normalizeAccountInput(event.target), true);
  document.addEventListener('change', (event) => normalizeAccountInput(event.target), true);
  document.addEventListener('submit', (event) => {
    event.target.querySelectorAll('input[name]').forEach(normalizeAccountInput);
    for (const input of event.target.querySelectorAll('input[name="email"], input[name="phone"]')) {
      if (!input.reportValidity()) {
        event.preventDefault();
        break;
      }
    }
  }, true);
  // Delegation keeps the control working after language/navigation swaps.
  function initPasswordToggles() {
    syncSecurityQuestions();
    document.querySelector('[data-setup-error]')?.focus();
    document.querySelectorAll('[data-password-toggle]').forEach((button) => { button.hidden = false; });
  }
  initPasswordToggles();
  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-password-toggle]');
    if (!button) return;
    const input = document.getElementById(button.getAttribute('aria-controls'));
    if (!input) return;
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    const label = button.getAttribute(show ? 'data-hide-label' : 'data-show-label');
    const icon = button.querySelector('i');
    if (icon) icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    button.setAttribute('aria-label', label);
    button.setAttribute('aria-pressed', String(show));
  });
  window.initPasswordToggles = initPasswordToggles;
  function syncSecurityQuestions() {
    const first = document.getElementById('question_1');
    const second = document.getElementById('question_2');
    if (!first || !second) return;
    for (const [select, other] of [[first, second], [second, first]]) {
      for (const option of select.options) {
        option.disabled = option.value !== '' && option.value === other.value;
      }
    }
  }
  document.addEventListener('change', (event) => {
    if (event.target.matches('#question_1, #question_2')) syncSecurityQuestions();
  });
  document.addEventListener('input', (event) => {
    if (!event.target.matches('[data-timeout-minutes]')) return;
    const display = event.target.form?.querySelector('[data-timeout-display]');
    if (!display) return;
    const minutes = Number(event.target.value);
    display.textContent = event.target.value && Number.isInteger(minutes) && minutes > 0
      ? Math.floor(minutes / 60) + (window.UNIT_H || 'h') + ' ' + (minutes % 60) + (window.UNIT_M || 'm') : '';
  });
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-password-form]')) return;
    const current = form.elements.namedItem('current_password');
    const next = form.elements.namedItem('new_password');
    const confirm = form.elements.namedItem('confirm_password');
    const alertBox = form.querySelector('[data-password-error]');
    [current, next, confirm].forEach((field) => field.classList.remove('is-invalid'));
    let field = null;
    let message = '';
    if (!current.value || !next.value || !confirm.value) {
      field = [current, next, confirm].find((item) => !item.value);
      message = form.dataset.requiredMessage;
    } else if (Array.from(next.value).length < 8) {
      field = next;
      message = form.dataset.shortMessage;
    } else if (next.value !== confirm.value) {
      field = confirm;
      message = form.dataset.mismatchMessage;
    }
    if (!field) return;
    event.preventDefault();
    field.classList.add('is-invalid');
    alertBox.textContent = message;
    alertBox.classList.remove('d-none');
    field.focus();
  });
  document.addEventListener('input', (event) => {
    const form = event.target.closest?.('[data-password-form]');
    if (!form) return;
    event.target.classList.remove('is-invalid');
    form.querySelector('[data-password-error]')?.classList.add('d-none');
  });
  const overlay = document.getElementById('loadingOverlay');
  let toastContainer = document.getElementById('toastContainer');

  function showLoading(show) {
    if (!overlay) return;
    overlay.classList.toggle('d-none', !show);
  }

  function showToast(message, type) {
    type = type || 'success';
    if (!toastContainer) {
      const fallback = document.createElement('div');
      fallback.id = 'toastContainer';
      fallback.className = 'toast-container position-fixed top-0 end-0 p-3';
      document.body.appendChild(fallback);
      toastContainer = fallback;
      return showToast(message, type);
    }
    const id = 't' + Date.now();
    const el = document.createElement('div');
    el.className =
      'toast align-items-center text-bg-' +
      (type === 'danger' ? 'danger' : type === 'warning' ? 'warning' : 'success') +
      ' border-0';
    el.id = id;
    el.setAttribute('role', 'alert');
    el.innerHTML =
      '<div class="d-flex"><div class="toast-body"></div>' +
      '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Dismiss notification"></button></div>';
    el.querySelector('.toast-body').textContent = message;
    toastContainer.appendChild(el);
    const toast = new bootstrap.Toast(el, { delay: 15000 });
    toast.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
  }

  async function apiPost(path, data) {
    showLoading(true);
    try {
      const res = await fetch((window.APP_BASE || '') + path, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': window.CSRF_TOKEN || '',
          Accept: 'application/json',
        },
        body: JSON.stringify(Object.assign({}, data || {}, { csrf_token: window.CSRF_TOKEN })),
      });
      const json = await res.json().catch(() => ({ success: false, message: 'Unexpected server response.' }));
      return json;
    } catch (e) {
      return { success: false, message: 'Network error. Please try again.' };
    } finally {
      showLoading(false);
    }
  }

  function confirmAction(message) {
    const modal = document.getElementById('appConfirmModal') || (() => {
      const el = document.createElement('div');
      el.id = 'appConfirmModal';
      el.className = 'modal fade';
      el.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Confirm action</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-accent" data-confirm-yes>Continue</button></div></div></div>';
      document.body.appendChild(el); return el;
    })();
    return new Promise((resolve) => {
      const instance = bootstrap.Modal.getOrCreateInstance(modal);
      const yes = modal.querySelector('[data-confirm-yes]');
      modal.querySelector('.modal-body').textContent = message;
      const finish = (ok) => { yes.onclick = null; modal.removeEventListener('hidden.bs.modal', onHidden); resolve(ok); };
      const onHidden = () => finish(false);
      yes.onclick = () => { modal.addEventListener('hidden.bs.modal', () => finish(true), {once:true}); instance.hide(); };
      modal.addEventListener('hidden.bs.modal', onHidden, {once:true});
      instance.show();
    });
  }

  function confirmDashboardAction(button, message) {
    const modal = document.getElementById('dashboardConfirmModal');
    if (!modal || !window.bootstrap?.Modal) return Promise.resolve(confirmAction(message));
    return new Promise((resolve) => {
      const confirmButton = modal.querySelector('[data-action-confirm]');
      const cancelButton = modal.querySelector('[data-action-cancel]');
      const instance = bootstrap.Modal.getOrCreateInstance(modal);
      let accepted = false;
      modal.querySelector('#dashboardConfirmTitle').textContent = button.dataset.confirmTitle || '';
      modal.querySelector('#dashboardConfirmMessage').textContent = message;
      confirmButton.textContent = button.dataset.confirmLabel || '';
      const onConfirm = () => {
        accepted = true;
        instance.hide();
      };
      const onHidden = () => {
        confirmButton.removeEventListener('click', onConfirm);
        modal.removeEventListener('hidden.bs.modal', onHidden);
        resolve(accepted);
      };
      confirmButton.addEventListener('click', onConfirm);
      modal.addEventListener('hidden.bs.modal', onHidden);
      modal.addEventListener('shown.bs.modal', () => cancelButton.focus(), { once: true });
      instance.show();
    });
  }

  function reviewCorrectionTime(value) {
    if (!value) return '—';
    const match = /^(\d{1,2}):(\d{2})/.exec(value);
    if (!match) return value;
    const hour = Number(match[1]);
    return String(hour % 12 || 12) + ':' + match[2] + (hour < 12 ? ' AM' : ' PM');
  }

  // Delegated so the confirmation also works after a soft page navigation.
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!form.matches('[data-correction-form]') || event.submitter?.matches('[data-confirm-correction]')) return;
    const modal = document.getElementById('correctionConfirmModal');
    if (!modal || !window.bootstrap?.Modal) return;
    event.preventDefault();

    const rows = modal.querySelector('[data-correction-review-changes]');
    rows.replaceChildren();
    let changeCount = 0;
    form.querySelectorAll('[data-original-value]').forEach((field) => {
      const before = field.dataset.originalValue || '';
      const after = field.value || '';
      if (before === after) return;
      const display = field.name === 'clock_in' || field.name === 'clock_out'
        ? reviewCorrectionTime
        : (value) => value || '—';
      const row = document.createElement('tr');
      const number = document.createElement('th');
      number.scope = 'row';
      number.textContent = String(changeCount + 1);
      row.appendChild(number);
      [field.dataset.reviewLabel || '', display(before), display(after)].forEach((value) => {
        const cell = document.createElement('td');
        cell.textContent = value;
        row.appendChild(cell);
      });
      rows.appendChild(row);
      changeCount++;
    });
    modal.querySelector('[data-correction-review-table]').hidden = changeCount === 0;
    modal.querySelector('[data-correction-no-changes]').hidden = changeCount !== 0;
    modal.querySelector('[data-confirm-correction]').disabled = changeCount === 0;
    modal.querySelector('[data-correction-review-reason]').textContent = form.elements.namedItem('reason').value.trim();
    modal.addEventListener('shown.bs.modal', () => {
      modal.querySelector('[data-correction-cancel]').focus();
    }, { once: true });
    bootstrap.Modal.getOrCreateInstance(modal).show();
  });

  function earthPulse(kind) {
    if (window.ChronosSpaceBg && typeof window.ChronosSpaceBg.pulse === 'function') {
      window.ChronosSpaceBg.pulse(kind);
    }
  }

  // Sidebar mobile toggle (once)
  const sidebar = document.getElementById('appSidebar');
  const toggle = document.getElementById('sidebarToggle');
  const backdrop = document.getElementById('sidebarBackdrop');
  function closeSidebar() {
    sidebar && sidebar.classList.remove('open');
    backdrop && backdrop.classList.remove('show');
  }
  if (toggle && sidebar && !toggle.dataset.bound) {
    toggle.dataset.bound = '1';
    toggle.addEventListener('click', () => {
      sidebar.classList.toggle('open');
      backdrop && backdrop.classList.toggle('show');
    });
  }
  if (backdrop && !backdrop.dataset.bound) {
    backdrop.dataset.bound = '1';
    backdrop.addEventListener('click', closeSidebar);
  }

  function bindPageActions() {
    document.querySelectorAll('.app-content [data-action]').forEach((btn) => {
      if (btn.dataset.actionBound === '1') return;
      btn.dataset.actionBound = '1';
      btn.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();

        const action = btn.getAttribute('data-action');
        const type = btn.getAttribute('data-type') || '';

        const confirmMsg = btn.getAttribute('data-confirm');
        if (['clock_in', 'clock_out', 'start_break', 'end_break'].includes(action)) {
          const message = confirmMsg || window.CHRONOS_I18N?.['confirm_' + action] || 'Confirm this action?';
          if (!await confirmDashboardAction(btn, message)) return;
          if (!btn.isConnected || btn.disabled) return;
        } else if (confirmMsg) {
          if (!await confirmAction(confirmMsg)) {
            return;
          }
        }

        btn.disabled = true;
        let result;
        if (action === 'clock_in' || action === 'clock_out') {
          result = await apiPost('/api/clock.php', { action });
        } else if (action === 'start_break') {
          result = await apiPost('/api/break.php', { action: 'start', type });
        } else if (action === 'end_break') {
          result = await apiPost('/api/break.php', { action: 'end' });
        }

        if (result && result.success) {
          if (action === 'clock_in') earthPulse('in');
          else if (action === 'clock_out') earthPulse('out');
          showToast(result.message || 'Done', result.exceeded ? 'warning' : 'success');
          setTimeout(() => {
            if (window.ChronosSpa && typeof window.ChronosSpa.reload === 'function') {
              window.ChronosSpa.reload();
            } else {
              window.location.reload();
            }
          }, 500);
        } else {
          showToast((result && result.message) || 'Action failed', 'danger');
          btn.disabled = false;
        }
      });
    });
  }

  // Replace native "localhost says" confirmations on ordinary forms/buttons.
  document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.confirm || form.dataset.confirmed === '1') return;
    event.preventDefault();
    event.stopPropagation();
    if (await confirmAction(form.dataset.confirm)) {
      form.dataset.confirmed = '1';
      try {
        form.requestSubmit(event.submitter);
      } finally {
        setTimeout(() => { delete form.dataset.confirmed; }, 100);
      }
    }
  });
  document.addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-confirm]');
    if (!button || button.dataset.confirmed === '1' || button.closest('[data-action]') || button.form?.dataset.confirm) return;
    event.preventDefault();
    event.stopPropagation();
    if (await confirmAction(button.dataset.confirm)) {
      button.dataset.confirmed = '1';
      try {
        if (button.form) {
          button.form.requestSubmit(button);
        } else {
          button.click();
        }
      } finally {
        setTimeout(() => { delete button.dataset.confirmed; }, 100);
      }
    }
  });

  function formatLiveMinutes(mins) {
    mins = Math.max(0, Math.floor(mins));
    const uh = window.UNIT_H || 'h';
    const um = window.UNIT_M || 'm';
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return h > 0 ? h + uh + ' ' + String(m).padStart(2, '0') + um : m + um;
  }

  function tickLiveTimers() {
    document.querySelectorAll('[data-live-start]').forEach((el) => {
      const start = el.getAttribute('data-live-start');
      if (!start) return;
      const startMs = Date.parse(start);
      if (Number.isNaN(startMs)) return;
      const base = parseInt(el.getAttribute('data-live-base') || '0', 10) || 0;
      const mins = Math.max(0, Math.floor((Date.now() - startMs) / 60000)) + base;
      el.textContent = formatLiveMinutes(mins);
    });

    document.querySelectorAll('[data-live-work]').forEach((el) => {
      const clockInMs = Date.parse(el.getAttribute('data-clock-in') || '');
      if (Number.isNaN(clockInMs)) return;
      const breakBase = parseInt(el.getAttribute('data-break-base') || '0', 10) || 0;
      let breaks = breakBase;
      const open = el.getAttribute('data-break-open');
      if (open) {
        const openMs = Date.parse(open);
        if (!Number.isNaN(openMs)) {
          breaks += Math.max(0, Math.floor((Date.now() - openMs) / 60000));
        }
      }
      const elapsed = Math.max(0, Math.floor((Date.now() - clockInMs) / 60000));
      const workBase = parseInt(el.getAttribute('data-work-base') || '0', 10) || 0;
      el.textContent = formatLiveMinutes(workBase + Math.max(0, elapsed - breaks));
    });
  }

  if (!window.__SAS_LIVE_TIMER__) {
    window.__SAS_LIVE_TIMER__ = true;
    tickLiveTimers();
    setInterval(tickLiveTimers, 1000);
  }

  // ——— Theme toggle (night / day) ———
  function applyTheme(theme, persist) {
    const t = theme === 'day' ? 'day' : 'night';
    document.documentElement.setAttribute('data-theme', t);
    if (persist !== false) {
      try {
        localStorage.setItem('chrono_theme', t);
      } catch (e) {}
    }
    const meta = document.getElementById('metaThemeColor');
    if (meta) meta.setAttribute('content', t === 'day' ? '#e8eef6' : '#06090f');
    if (window.ChronosSpaceBg && typeof window.ChronosSpaceBg.setTheme === 'function') {
      window.ChronosSpaceBg.setTheme(t);
    }
    syncThemeToggleUi(t);
  }

  function syncThemeToggleUi(theme) {
    document.querySelectorAll('#themeToggle').forEach((btn) => {
      const night = theme !== 'day';
      btn.classList.toggle('is-day', !night);
      const text = btn.querySelector('.theme-toggle__text');
      if (text) {
        text.textContent = night
          ? btn.getAttribute('data-label-night') || ''
          : btn.getAttribute('data-label-day') || '';
      }
    });
  }

  function initThemeToggle() {
    const initial =
      document.documentElement.getAttribute('data-theme') ||
      (function () {
        try {
          return localStorage.getItem('chrono_theme') || 'night';
        } catch (e) {
          return 'night';
        }
      })();
    applyTheme(initial, false);

    document.querySelectorAll('#themeToggle').forEach((btn) => {
      if (btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', () => {
        const cur = document.documentElement.getAttribute('data-theme') || 'night';
        applyTheme(cur === 'day' ? 'night' : 'day', true);
      });
    });
  }

  // ——— Admin live ticker ———
  let tickerRefreshFn = null;

  function initLiveTicker() {
    const root = document.getElementById('chronoTicker');
    if (!root) return;
    const track = document.getElementById('chronoTickerTrack');
    const feedUrl = root.getAttribute('data-feed');
    if (!track || !feedUrl) return;

    function escapeHtml(s) {
      return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    async function refresh() {
      try {
        const res = await fetch(feedUrl, {
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
        });
        const json = await res.json();
        if (!json || !json.success || !Array.isArray(json.items)) return;
        const kindClass = {
          on_break: 'is-break',
          clock_in: 'is-in',
          late: 'is-late',
          leave: 'is-leave',
          alert: 'is-alert',
          summary: 'is-summary',
          info: 'is-info',
          idle: 'is-idle',
        };
        const parts = json.items.map((it) => {
          const kind = it.kind || 'info';
          const cls = kindClass[kind] || 'is-info';
          return '<span class="topbar-ticker__item ' + cls + '">' + escapeHtml(it.text || '') + '</span>';
        });
        const sep = '<span class="topbar-ticker__sep">◆</span>';
        const singleHtml = parts.join(sep);
        track.innerHTML = singleHtml;
        track.classList.remove('is-scrolling', 'is-scrolling-fast');
        requestAnimationFrame(() => {
          const viewport = root.querySelector('.topbar-ticker__viewport') || track.parentElement;
          const viewportWidth = viewport ? viewport.clientWidth : 0;
          if (track.scrollWidth > viewportWidth + 8 && parts.length > 1) {
            track.innerHTML = singleHtml + sep + singleHtml;
            track.classList.add(parts.length > 8 ? 'is-scrolling-fast' : 'is-scrolling');
          }
        });
      } catch (e) {}
    }

    tickerRefreshFn = refresh;

    if (root.dataset.bound === '1') {
      refresh();
      return;
    }
    root.dataset.bound = '1';
    refresh();
    setInterval(refresh, 20000);
  }

  function refreshLiveTicker() {
    if (typeof tickerRefreshFn === 'function') tickerRefreshFn();
    else initLiveTicker();
  }

  // ——— Live sidebar badges (no full page refresh) ———
  let badgePrev = null;
  let badgeTimer = null;

  function i18n(key, replace) {
    const dict = window.CHRONOS_I18N || {};
    let text = dict[key] || key;
    if (replace) {
      Object.keys(replace).forEach((k) => {
        text = text.split(':' + k).join(String(replace[k]));
      });
    }
    return text;
  }

  function applyBadges(badges, opts) {
    opts = opts || {};
    const map = badges || {};
    document.querySelectorAll('.nav-badge[data-badge]').forEach((el) => {
      const key = el.getAttribute('data-badge');
      const n = parseInt(map[key], 10) || 0;
      const prev = badgePrev && typeof badgePrev[key] === 'number' ? badgePrev[key] : null;
      if (n > 0) {
        el.hidden = false;
        el.classList.add('is-on');
        el.textContent = n > 99 ? '99+' : String(n);
        el.setAttribute('aria-label', String(n));
        if (prev !== null && n > prev) {
          el.classList.remove('is-bump');
          void el.offsetWidth;
          el.classList.add('is-bump');
        }
      } else {
        el.hidden = true;
        el.classList.remove('is-on', 'is-bump');
        el.textContent = '';
      }
    });

    if (opts.notify && badgePrev) {
      const leaveNow = parseInt(map.leave, 10) || 0;
      const leaveWas = parseInt(badgePrev.leave, 10) || 0;
      if (leaveNow > leaveWas) {
        const isAdminLeave = !!document.querySelector('.sidebar-nav a[href*="/admin/leave"]');
        if (isAdminLeave) {
          const added = leaveNow - leaveWas;
          const msg =
            added === 1
              ? i18n('badge_new_leave')
              : i18n('badge_new_leave_n', { n: added });
          showToast(msg, 'warning');
        } else {
          showToast(i18n('badge_leave_decision'), 'success');
        }
      }
      const incNow = parseInt(map.incomplete, 10) || 0;
      const incWas = parseInt(badgePrev.incomplete, 10) || 0;
      if (incNow > incWas && document.querySelector('.sidebar-nav a[data-badge-key="incomplete"]')) {
        showToast(i18n('badge_incomplete'), 'warning');
      }
    }

    badgePrev = Object.assign({}, map);
  }

  async function fetchBadges(notify) {
    try {
      const res = await fetch((window.APP_BASE || '') + '/api/badges.php', {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      });
      const json = await res.json();
      if (!json || !json.success || !json.badges) return;
      applyBadges(json.badges, { notify: !!notify });
    } catch (e) {}
  }

  function initBadgePoller() {
    if (!document.querySelector('.app-wrapper')) return;
    if (window.__CHRONOS_BADGE_POLLER__) {
      fetchBadges(false);
      return;
    }
    window.__CHRONOS_BADGE_POLLER__ = true;
    // Seed from SSR badges already in DOM
    const seed = {};
    document.querySelectorAll('.nav-badge[data-badge]').forEach((el) => {
      const key = el.getAttribute('data-badge');
      const n = parseInt(el.textContent, 10);
      seed[key] = Number.isFinite(n) ? n : el.classList.contains('is-on') ? 1 : 0;
    });
    badgePrev = seed;
    fetchBadges(false);
    badgeTimer = setInterval(() => fetchBadges(true), 12000);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) fetchBadges(true);
    });
  }

  function printPage() {
    const ambient = document.getElementById('chronoAmbient');
    const overlay = document.getElementById('loadingOverlay');
    document.body.classList.add('is-printing');
    if (ambient) ambient.setAttribute('aria-hidden', 'true');
    if (overlay) overlay.classList.add('d-none');

    const cleanup = () => {
      document.body.classList.remove('is-printing');
      window.removeEventListener('afterprint', cleanup);
    };
    window.addEventListener('afterprint', cleanup);

    // Let CSS apply, then open system print dialog
    requestAnimationFrame(() => {
      setTimeout(() => {
        try {
          window.print();
        } catch (e) {
          showToast('Print unavailable in this browser.', 'danger');
          cleanup();
        }
        // Fallback if afterprint never fires (some browsers)
        setTimeout(cleanup, 1500);
      }, 60);
    });
  }

  // Delegated — works after SPA content swaps (inline onclick can be unreliable)
  if (!window.__CHRONOS_PRINT_BOUND__) {
    window.__CHRONOS_PRINT_BOUND__ = true;
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.js-print-page');
      if (!btn) return;
      e.preventDefault();
      e.stopPropagation();
      printPage();
    });
  }

  function initServiceWorker() {
    if ('serviceWorker' in navigator && (window.isSecureContext || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
      window.addEventListener('load', () => {
        const swUrl = (window.APP_BASE || '') + '/sw.js';
        const scope = (window.APP_BASE || '') + '/';
        navigator.serviceWorker.register(swUrl, { scope })
          .then((reg) => {
            reg.addEventListener('updatefound', () => {
              const installing = reg.installing;
              if (installing) {
                installing.addEventListener('statechange', () => {
                  if (installing.state === 'installed' && navigator.serviceWorker.controller) {
                    console.log('[PWA] New version ready.');
                  }
                });
              }
            });
          })
          .catch((err) => {
            console.warn('[PWA] Service worker registration bypassed:', err.message);
          });
      });
    }
  }

  bindPageActions();
  initThemeToggle();
  initLiveTicker();
  initBadgePoller();
  initServiceWorker();

  window.SAS = {
    showToast,
    apiPost,
    showLoading,
    confirmAction,
    bindPageActions,
    applyTheme,
    earthPulse,
    initThemeToggle,
    syncThemeToggleUi,
    refreshLiveTicker,
    refreshBadges: function () {
      return fetchBadges(false);
    },
    applyBadges,
    printPage,
  };
})();
