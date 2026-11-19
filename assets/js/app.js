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
  // Staff delete controls are replaced during soft navigation, so handle clicks
  // on the document. Bootstrap modals must sit outside .app-wrapper's stacking
  // context or the backdrop covers the dialog and blocks its buttons.
  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-delete-staff]');
    if (!button) return;
    const modalEl = document.getElementById('deleteStaffModal');
    const id = Number(button.dataset.staffId);
    if (!modalEl || !Number.isSafeInteger(id) || id <= 0 || !window.bootstrap?.Modal) return;

    document.getElementById('deleteStaffName').textContent = button.dataset.staffName || '';
    document.getElementById('deleteStaffForm').action = (window.APP_BASE || '') + '/admin/staff_form.php?id=' + id;
    document.getElementById('deleteStaffCsrf').value = button.dataset.csrf || '';

    const parent = modalEl.parentNode;
    document.body.appendChild(modalEl);
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modalEl.addEventListener('hidden.bs.modal', () => {
      modal.dispose();
      if (parent?.isConnected) parent.appendChild(modalEl);
      showLoading(false);
    }, { once: true });
    modal.show();
  });
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
  const loadingLabel = overlay?.querySelector('[data-loading-label]');
  const defaultLoadingLabel = loadingLabel?.textContent || 'Loading...';
  let toastContainer = document.getElementById('toastContainer');

  function showLoading(show, message) {
    if (!overlay) return;
    if (loadingLabel) loadingLabel.textContent = show && message ? message : defaultLoadingLabel;
    overlay.classList.toggle('d-none', !show);
  }

  function showToast(message, type, key) {
    type = type || 'success';
    if (!toastContainer) {
      const fallback = document.createElement('div');
      fallback.id = 'toastContainer';
      fallback.className = 'toast-container position-fixed top-0 end-0 p-3';
      document.body.appendChild(fallback);
      toastContainer = fallback;
      return showToast(message, type, key);
    }
    if (key) {
      toastContainer.querySelectorAll('[data-toast-key]').forEach((existing) => {
        if (existing.dataset.toastKey !== key) return;
        try { bootstrap.Toast.getInstance(existing)?.dispose(); } catch (e) { }
        existing.remove();
      });
    }
    // Remove duplicate messages and limit stack to max 2 items
    toastContainer.querySelectorAll('.toast').forEach((existing) => {
      if (existing.querySelector('.toast-body')?.textContent === message) {
        try { bootstrap.Toast.getInstance(existing)?.dispose(); } catch (e) { }
        existing.remove();
      }
    });
    const currentToasts = toastContainer.querySelectorAll('.toast');
    if (currentToasts.length >= 2) {
      currentToasts[0].remove();
    }
    const id = 't' + Date.now();
    const el = document.createElement('div');
    el.className =
      'toast align-items-center text-bg-' +
      (type === 'danger' ? 'danger' : type === 'warning' ? 'warning' : 'success') +
      ' border-0';
    el.id = id;
    if (key) el.dataset.toastKey = key;
    el.setAttribute('role', 'alert');
    el.innerHTML =
      '<div class="d-flex"><div class="toast-body"></div>' +
      '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    el.querySelector('.btn-close').setAttribute('aria-label', i18n('toast_dismiss'));
    el.querySelector('.toast-body').textContent = message;
    toastContainer.appendChild(el);
    const toast = new bootstrap.Toast(el, { delay: 4000 });
    toast.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
  }

  function sidebarTemplateText() {
    const template = document.getElementById('sidebar_welcome_template')?.textContent || '';
    const values = {
      name: document.getElementById('input_full_name')?.value || '',
      company: document.getElementById('sidebar_company_name')?.textContent || '',
      url: document.getElementById('sidebar_login_url')?.textContent || '',
      username: document.getElementById('input_username')?.value || '',
      password: document.getElementById('sidebar_temp_pw')?.value || '',
    };
    return template.replace(/:(name|company|url|username|password)\b/g, (_, key) => values[key]);
  }

  function updateSidebarPreview() {
    const preview = document.getElementById('sidebar_template_preview');
    if (preview) preview.textContent = sidebarTemplateText();
  }

  document.addEventListener('input', (event) => {
    if (event.target.matches('#sidebar_temp_pw, #input_username, #input_full_name')) updateSidebarPreview();
  });
  document.addEventListener('click', (event) => {
    if (!event.target.closest('#btn_regen_sidebar_pw')) return;
    const input = document.getElementById('sidebar_temp_pw');
    if (input) {
      input.value = 'Temp@' + Math.floor(1000 + Math.random() * 9000);
      updateSidebarPreview();
    }
  });

  async function copyCredsText(button, value) {
    if (!value) return;
    try {
      if (!navigator.clipboard?.writeText) throw new Error('Clipboard API unavailable');
      await navigator.clipboard.writeText(value);
    } catch (_) {
      const field = document.createElement('textarea');
      field.value = value;
      field.style.cssText = 'position:fixed;left:-9999px;top:0';
      document.body.appendChild(field);
      let copied = false;
      try {
        field.select();
        copied = document.execCommand('copy');
      } catch (_) {
        copied = false;
      } finally {
        field.remove();
      }
      if (!copied) {
        showToast('Could not copy. Please select and copy the text manually.', 'danger');
        return;
      }
    }
    showToast('Copied to clipboard.');
    const label = button?.querySelector('span');
    if (label) {
      const original = label.textContent;
      label.textContent = 'Copied!';
      setTimeout(() => { if (label.isConnected) label.textContent = original; }, 2000);
    }
  }
  window.copyCredsText = copyCredsText;
  window.copySidebarTemplate = (button) => copyCredsText(button, sidebarTemplateText());

  async function apiPost(path, data, useLoadingOverlay, includeHttpStatus) {
    if (useLoadingOverlay !== false) showLoading(true);
    try {
      const token = document.querySelector('meta[name="csrf-token"]')?.content || window.CSRF_TOKEN || '';
      const res = await fetch((window.APP_BASE || '') + path, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': token,
          Accept: 'application/json',
        },
        body: JSON.stringify(Object.assign({}, data || {}, { csrf_token: token })),
      });
      const json = await res.json().catch(() => ({ success: false, message: 'Unexpected server response.' }));
      if (includeHttpStatus) json.http_status = res.status;
      return json;
    } catch (e) {
      return { success: false, message: 'Network error. Please try again.' };
    } finally {
      if (useLoadingOverlay !== false) showLoading(false);
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
      let accepted = false;
      const onHidden = () => { yes.onclick = null; modal.removeEventListener('hidden.bs.modal', onHidden); resolve(accepted); };
      yes.onclick = () => { accepted = true; instance.hide(); };
      modal.addEventListener('hidden.bs.modal', onHidden, { once: true });
      instance.show();
    });
  }

  function alertAction(message) {
    const modal = document.getElementById('appAlertModal') || (() => {
      const el = document.createElement('div');
      el.id = 'appAlertModal';
      el.className = 'modal fade';
      el.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Notice</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"></div><div class="modal-footer"><button type="button" class="btn btn-accent" data-bs-dismiss="modal">OK</button></div></div></div>';
      document.body.appendChild(el);
      return el;
    })();
    modal.querySelector('.modal-body').textContent = message;
    bootstrap.Modal.getOrCreateInstance(modal).show();
  }

  function confirmDashboardAction(button, message) {
    const modal = document.getElementById('dashboardConfirmModal');
    if (!modal || !window.bootstrap?.Modal) return Promise.resolve(confirmAction(message));
    return new Promise((resolve) => {
      const originalParent = modal.parentNode;
      const trigger = document.activeElement;
      // Keep Bootstrap's modal and backdrop in the document root. The app shell
      // has transformed stacking contexts that can otherwise place the backdrop
      // above the modal buttons, making the entire screen appear unclickable.
      if (originalParent !== document.body) document.body.appendChild(modal);
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
      const onEscape = (event) => {
        if (event.key !== 'Escape') return;
        event.preventDefault();
        instance.hide();
      };
      const onHidden = () => {
        confirmButton.removeEventListener('click', onConfirm);
        modal.removeEventListener('keydown', onEscape);
        modal.removeEventListener('hidden.bs.modal', onHidden);
        if (originalParent?.isConnected) originalParent.appendChild(modal);
        if (trigger?.isConnected && !trigger.disabled) trigger.focus({ preventScroll: true });
        resolve(accepted);
      };
      confirmButton.addEventListener('click', onConfirm);
      modal.addEventListener('keydown', onEscape);
      modal.addEventListener('hidden.bs.modal', onHidden);
      modal.addEventListener('shown.bs.modal', () => cancelButton.focus(), { once: true });
      instance.show();
    });
  }

  function askAttendanceLocation() {
    const modal = document.createElement('div');
    modal.className = 'modal fade attendance-location-choice-modal';
    modal.tabIndex = -1;
    modal.setAttribute('aria-labelledby', 'attendanceLocationChoiceTitle');
    modal.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="attendanceLocationChoiceTitle">Share your location for attendance?</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">Your location is requested only when you Clock In, Clock Out, or start/end approved work-from-home time. Your location is not continuously tracked.</div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-location-choice="disabled">Continue Without Location</button><button type="button" class="btn btn-accent" data-location-choice="enabled">Enable Location</button></div></div></div>';
    document.body.appendChild(modal);
    return new Promise((resolve) => {
      const instance = bootstrap.Modal.getOrCreateInstance(modal);
      let choice = null;
      modal.querySelectorAll('[data-location-choice]').forEach((button) => {
        button.addEventListener('click', () => {
          choice = button.dataset.locationChoice;
          instance.hide();
        });
      });
      modal.addEventListener('hidden.bs.modal', () => {
        instance.dispose();
        modal.remove();
        resolve(choice);
      }, { once: true });
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
    const originalParent = modal.parentNode;
    if (originalParent !== document.body) document.body.appendChild(modal);
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
    modal.addEventListener('hidden.bs.modal', () => {
      if (originalParent?.isConnected) originalParent.appendChild(modal);
    }, { once: true });
    bootstrap.Modal.getOrCreateInstance(modal).show();
  });

  function earthPulse(kind) {
    if (window.ChronosSpaceBg && typeof window.ChronosSpaceBg.pulse === 'function') {
      window.ChronosSpaceBg.pulse(kind);
    }
  }

  // Sidebar mobile toggle & swipe gestures (once)
  const sidebar = document.getElementById('appSidebar');
  const toggle = document.getElementById('sidebarToggle');
  const mobileUserButton = document.getElementById('mobileUserBtn');
  const backdrop = document.getElementById('sidebarBackdrop');

  function openSidebar() {
    sidebar && sidebar.classList.add('open');
    backdrop && backdrop.classList.add('show');
    toggle && toggle.setAttribute('aria-expanded', 'true');
    mobileUserButton && mobileUserButton.setAttribute('aria-expanded', 'true');
  }

  function closeSidebar() {
    sidebar && sidebar.classList.remove('open');
    backdrop && backdrop.classList.remove('show');
    toggle && toggle.setAttribute('aria-expanded', 'false');
    mobileUserButton && mobileUserButton.setAttribute('aria-expanded', 'false');
  }

  function toggleSidebar() {
    if (sidebar && sidebar.classList.contains('open')) {
      closeSidebar();
    } else {
      openSidebar();
    }
  }

  // Handle taps on hamburger menu button AND mobile topbar profile picture/avatar
  document.addEventListener('click', (event) => {
    if (event.target.closest('#sidebarToggle, #mobileUserBtn, .sidebar-toggle, .topbar-mobile-avatar')) {
      event.preventDefault();
      toggleSidebar();
    }
  });

  if (backdrop && !backdrop.dataset.bound) {
    backdrop.dataset.bound = '1';
    backdrop.addEventListener('click', closeSidebar);
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && sidebar && sidebar.classList.contains('open')) {
      closeSidebar();
    }
  });

  // Mobile swipe gestures: swipe right from left edge to open, swipe left to close
  if (sidebar && !sidebar.dataset.swipeBound) {
    sidebar.dataset.swipeBound = '1';

    let touchStartX = 0;
    let touchStartY = 0;
    let touchStartTime = 0;
    let isTracking = false;
    let swipeDirection = null;
    let sidebarWasOpen = false;

    const isMobileView = () => window.matchMedia('(max-width: 991.98px)').matches;

    document.addEventListener('touchstart', (e) => {
      if (!isMobileView() || e.touches.length !== 1) return;
      if (e.target.closest('input, textarea, select, .modal')) return;

      sidebarWasOpen = sidebar.classList.contains('open');
      const touch = e.touches[0];
      touchStartX = touch.clientX;
      touchStartY = touch.clientY;
      touchStartTime = Date.now();
      swipeDirection = null;

      // When closed: only trigger if touch starts within 60px of the left edge
      if (!sidebarWasOpen && touchStartX > 60) {
        isTracking = false;
        return;
      }

      isTracking = true;
    }, { passive: true });

    document.addEventListener('touchmove', (e) => {
      if (!isTracking || !isMobileView() || e.touches.length !== 1) return;

      const touch = e.touches[0];
      const dx = touch.clientX - touchStartX;
      const dy = touch.clientY - touchStartY;

      if (!swipeDirection) {
        // If movement is mostly vertical, let native scrolling handle it
        if (Math.abs(dy) >= 8 && Math.abs(dy) > Math.abs(dx)) {
          swipeDirection = 'vertical';
          isTracking = false;
          return;
        }
        // If movement is clearly horizontal in the expected direction
        if (Math.abs(dx) >= 8 && Math.abs(dx) > Math.abs(dy)) {
          if ((!sidebarWasOpen && dx > 0) || (sidebarWasOpen && dx < 0)) {
            swipeDirection = 'horizontal';
          } else {
            isTracking = false;
            return;
          }
        }
      }

      if (swipeDirection === 'horizontal') {
        if (e.cancelable) e.preventDefault();

        if (!sidebarWasOpen && dx >= 50) {
          openSidebar();
          isTracking = false;
        } else if (sidebarWasOpen && dx <= -50) {
          closeSidebar();
          isTracking = false;
        }
      }
    }, { passive: false });

    const handleTouchEnd = (e) => {
      if (!isTracking || swipeDirection !== 'horizontal' || !isMobileView()) {
        isTracking = false;
        swipeDirection = null;
        return;
      }

      const touch = e.changedTouches[0];
      if (touch) {
        const dx = touch.clientX - touchStartX;
        const dy = touch.clientY - touchStartY;
        const dt = Date.now() - touchStartTime;

        const isFlick = dt < 350 && Math.abs(dx) > 30 && Math.abs(dx) > Math.abs(dy) * 1.2;
        const isSubstantialSwipe = Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy) * 1.2;

        if (isFlick || isSubstantialSwipe) {
          if (!sidebarWasOpen && dx > 0) {
            openSidebar();
          } else if (sidebarWasOpen && dx < 0) {
            closeSidebar();
          }
        }
      }

      isTracking = false;
      swipeDirection = null;
    };

    document.addEventListener('touchend', handleTouchEnd, { passive: true });
    document.addEventListener('touchcancel', () => {
      isTracking = false;
      swipeDirection = null;
    }, { passive: true });
  }

  function initAttendanceLocationCard() {
    const card = document.querySelector('[data-attendance-location-card]');
    if (!card || card.dataset.locationInitialized === '1') return;
    card.dataset.locationInitialized = '1';
    const toggle = card.querySelector('[data-attendance-location-toggle]');
    const state = card.querySelector('[data-attendance-location-state]');
    const browser = card.querySelector('[data-attendance-browser-permission]');
    const help = card.querySelector('[data-attendance-location-help]');
    let browserPermission = 'checking';

    const render = () => {
      const preference = card.dataset.preference;
      toggle.checked = preference === 'enabled';
      toggle.indeterminate = false;
      state.textContent = preference === 'undecided'
        ? 'Not decided yet — choose during your next Clock In or Clock Out, or enable it here.'
        : preference === 'disabled' ? 'Location will not be requested during attendance events.'
          : browserPermission === 'denied' ? 'Location sharing is on, but your browser is blocking access.'
            : 'Location will be requested during attendance events.';
      browser.textContent = ({ granted: 'Allowed', prompt: 'Not requested', denied: 'Blocked', checking: 'Checking...', unknown: 'Unknown' })[browserPermission] || 'Unknown';
      const badgeState = ({ granted: 'allowed', prompt: 'pending', denied: 'blocked' })[browserPermission] || 'unknown';
      browser.className = 'attendance-permission-badge is-' + badgeState;
      help.classList.toggle('is-blocked', browserPermission === 'denied');
    };

    render();
    toggle.addEventListener('change', async () => {
      const next = toggle.checked ? 'enabled' : 'disabled';
      toggle.disabled = true;
      const result = await apiPost('/api/clock.php', { action: 'location_preference', preference: next });
      toggle.disabled = false;
      if (!result.success) {
        render();
        showToast(result.message || 'Could not save Attendance Location. Please try again.', 'danger');
        return;
      }
      card.dataset.preference = next;
      document.querySelectorAll('[data-location-preference]').forEach((button) => { button.dataset.locationPreference = next; });
      render();
      showToast('Attendance Location ' + (next === 'enabled' ? 'enabled.' : 'disabled.'), 'success', 'attendance-location');
    });

    if (!navigator.geolocation || !window.isSecureContext || !navigator.permissions?.query) {
      browserPermission = 'unknown'; render(); return;
    }
    navigator.permissions.query({ name: 'geolocation' }).then((permission) => {
      if (!card.isConnected) return;
      const update = () => { browserPermission = permission.state; render(); };
      update();
      permission.addEventListener?.('change', update);
    }).catch(() => { browserPermission = 'unknown'; render(); });
  }

  let attendanceCapability = null;
  let activeAttendanceOperation = null;
  let attendanceOperationBusy = false;
  let attendanceStatusRegion = null;
  let attendanceRecoveryInitialized = false;
  let attendanceRecoveryPromise = null;
  let attendanceFrontendState = 'ready';
  const locationFinalizationAttempts = new Set();

  function attendanceStatus(message, state, retry) {
    const root = document.querySelector('.app-content');
    if (!root) return;
    if (!attendanceStatusRegion || !attendanceStatusRegion.isConnected) {
      attendanceStatusRegion = document.createElement('section');
      attendanceStatusRegion.className = 'alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3';
      attendanceStatusRegion.setAttribute('role', 'status');
      attendanceStatusRegion.setAttribute('aria-live', 'polite');
      attendanceStatusRegion.setAttribute('aria-atomic', 'true');
      attendanceStatusRegion.tabIndex = -1;
      attendanceStatusRegion.dataset.attendanceOperationStatus = '1';
      const text = document.createElement('span');
      text.dataset.attendanceStatusText = '1';
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn btn-sm btn-outline-primary';
      button.textContent = 'Check status / retry safely';
      button.hidden = true;
      button.addEventListener('click', () => {
        if (activeAttendanceOperation && !attendanceOperationBusy) runAttendanceOperation(activeAttendanceOperation, true);
      });
      const loginLink = document.createElement('a');
      loginLink.href = (window.APP_BASE || '') + '/';
      loginLink.className = 'btn btn-sm btn-outline-primary';
      loginLink.textContent = 'Go to login';
      loginLink.hidden = true;
      attendanceStatusRegion.append(text, button, loginLink);
      root.prepend(attendanceStatusRegion);
    }
    attendanceFrontendState = state;
    root.dataset.attendanceState = state;
    attendanceStatusRegion.dataset.state = state;
    attendanceStatusRegion.classList.toggle('alert-warning', ['connection_unavailable', 'retry_available', 'session_expired', 'administrator_review_required'].includes(state));
    attendanceStatusRegion.classList.toggle('alert-info', !['connection_unavailable', 'retry_available', 'session_expired', 'administrator_review_required'].includes(state));
    attendanceStatusRegion.querySelector('[data-attendance-status-text]').textContent = message;
    const retryButton = attendanceStatusRegion.querySelector('button');
    retryButton.hidden = !retry;
    retryButton.disabled = attendanceOperationBusy;
    attendanceStatusRegion.querySelector('a').hidden = state !== 'session_expired';
    attendanceStatusRegion.hidden = false;
    if (['connection_unavailable', 'retry_available', 'session_expired', 'administrator_review_required'].includes(state)) {
      attendanceStatusRegion.focus({ preventScroll: true });
    }
  }

  function setAttendanceActionsDisabled(disabled) {
    document.querySelectorAll('.app-content [data-action="clock_in"], .app-content [data-action="clock_out"], .app-content [data-action="start_break"], .app-content [data-action="end_break"]').forEach((button) => {
      button.disabled = disabled;
      if (disabled) button.setAttribute('aria-disabled', 'true');
      else button.removeAttribute('aria-disabled');
    });
  }

  async function finalizeAttendanceLocationOnce(pending, terminalResult) {
    const attemptKey = String(pending?.requestId || '');
    if (!terminalResult?.location_event_id || !attemptKey || !['clock_in', 'clock_out'].includes(pending.action)) return true;
    if (locationFinalizationAttempts.has(attemptKey)) return true;
    // This guard is page-local. Persisting it before geolocation resolves would
    // strand the optional location event if the tab reloads mid-finalization.
    locationFinalizationAttempts.add(attemptKey);
    pending.locationFinalizationAttempted = true;
    attendanceStatus('Attendance is confirmed. Updating optional location status…', 'confirmed_success', false);
    try {
      let location = { location_status: 'unavailable' };
      if (navigator.geolocation && window.isSecureContext) {
        location = await new Promise((resolve) => navigator.geolocation.getCurrentPosition(
          (position) => resolve({ location_status: 'captured', latitude: position.coords.latitude, longitude: position.coords.longitude, accuracy_metres: position.coords.accuracy }),
          (error) => resolve({ location_status: error.code === 1 ? 'permission_denied' : error.code === 3 ? 'timed_out' : 'unavailable' }),
          { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        ));
      } else if (!navigator.geolocation || !window.isSecureContext) location.location_status = 'unsupported';
      const saved = await apiPost('/api/clock.php', { action: 'location_result', event_id: terminalResult.location_event_id, ...location }, false, true);
      if (!saved.success) {
        locationFinalizationAttempts.delete(attemptKey);
        const authRequired = saved.http_status === 401 || saved.http_status === 403;
        const authMessage = saved.http_status === 401
          ? 'Attendance is confirmed, but the optional location update is unresolved. Your session expired; log in again to verify and complete it.'
          : 'Attendance is confirmed, but the optional location update is unresolved. Refresh or log in again to verify the security token and complete it.';
        attendanceStatus(authRequired
          ? authMessage
          : 'Attendance is confirmed, but the optional location update is unresolved. The original request is retained; retry will check its result before updating location.',
        authRequired ? 'session_expired' : 'connection_unavailable', !authRequired);
        showToast(authRequired ? 'Attendance was recorded. Refresh or log in again to complete the optional location update.' : 'Attendance was recorded. Location status remains unresolved; retry safely when connected.', 'warning', 'attendance-location-result');
        return false;
      }
      showToast('Attendance location status updated.', 'success', 'attendance-location-result');
      return true;
    } catch (_) {
      locationFinalizationAttempts.delete(attemptKey);
      attendanceStatus('Attendance is confirmed, but the optional location update is unresolved. The original request is retained; retry will check its result before updating location.', 'connection_unavailable', true);
      showToast('Attendance was recorded. Location could not be completed; this does not affect attendance.', 'warning', 'attendance-location-result');
      return false;
    }
  }

  async function checkAttendanceOperation(pending) {
    attendanceStatus('Checking server status for the pending attendance action…', 'checking_server_status', false);
    try {
      const reconciliation = await window.AttendanceReliability.reconcile(pending);
      if (String(window.SAS_USER_ID) !== String(pending.userId) || activeAttendanceOperation?.requestId !== pending.requestId) return;
      const data = reconciliation.data || {};
      if (reconciliation.httpStatus === 401) {
        attendanceStatus('Your session expired. Log in again to verify this action. The pending request is saved and will not be sent while you are signed out.', 'session_expired', false);
        setAttendanceActionsDisabled(true);
      } else if (data.request_id && data.request_id !== pending.requestId) {
        attendanceStatus('The server response did not match the pending request. Administrator review is required.', 'administrator_review_required', false);
        attendanceOperationBusy = false;
        setAttendanceActionsDisabled(true);
      } else if (data.outcome === 'confirmed_success' || data.outcome === 'confirmed_rejection') {
        const expectedOperation = ({ clock_in: 'clock_in', clock_out: 'clock_out', start_break: 'break_start', end_break: 'break_end' })[pending.action];
        if (data.operation !== expectedOperation) {
          attendanceStatus('The stored operation does not match the pending action. Administrator review is required.', 'administrator_review_required', false);
          attendanceOperationBusy = false;
          setAttendanceActionsDisabled(true);
          return;
        }
        const terminal = data.result || {};
        if (data.outcome === 'confirmed_success' && !await finalizeAttendanceLocationOnce(pending, terminal)) return;
        window.AttendanceReliability.clearPending(pending);
        activeAttendanceOperation = null;
        attendanceOperationBusy = false;
        if (data.outcome === 'confirmed_success') {
          attendanceStatus('Attendance action confirmed by the server.', 'confirmed_success', false);
          showToast(terminal.message || 'Attendance action confirmed.', 'success');
          if (pending.action === 'clock_in') earthPulse('in');
          if (pending.action === 'clock_out') earthPulse('out');
        } else {
          attendanceStatus(terminal.message || 'The server confirmed that this attendance action was not accepted.', 'confirmed_rejection', false);
          showToast(terminal.message || 'Attendance action was not accepted.', 'danger');
        }
        setTimeout(() => window.location.reload(), 700);
      } else if (data.outcome === 'unknown') {
        const observed = data.state?.derived?.classification;
        const stateText = observed ? ' Current server attendance state: ' + observed.replace(/_/g, ' ') + '.' : '';
        attendanceStatus('No result is recorded for this request ID.' + stateText + ' A state change from another device does not confirm this request. You can safely retry with the same request ID.', 'retry_available', true);
        attendanceOperationBusy = false;
        setAttendanceActionsDisabled(true);
      } else if (data.outcome === 'processing') {
        attendanceStatus('The server is still processing this attendance action. Checking again shortly…', 'checking_server_status', false);
        setTimeout(() => { if (activeAttendanceOperation?.requestId === pending.requestId) checkAttendanceOperation(pending); }, 2000);
      } else if (data.outcome === 'expired' || data.outcome === 'unavailable' || data.outcome === 'server_unavailable' || data.outcome === 'invalid_request') {
        attendanceStatus('The operation result cannot be verified automatically. Please contact an administrator before taking another attendance action.', 'administrator_review_required', false);
        attendanceOperationBusy = false;
        setAttendanceActionsDisabled(true);
      } else {
        attendanceStatus('The attendance service is temporarily unavailable. The action outcome is uncertain; check status again before retrying.', 'connection_unavailable', true);
        attendanceOperationBusy = false;
        setAttendanceActionsDisabled(true);
      }
    } catch (_) {
      attendanceStatus('The attendance service is temporarily unavailable. The action outcome is uncertain; check status again before retrying.', 'connection_unavailable', true);
      attendanceOperationBusy = false;
      setAttendanceActionsDisabled(true);
    }
  }

  async function runAttendanceOperation(pending, retry) {
    if (!pending || attendanceOperationBusy) return;
    attendanceOperationBusy = true;
    activeAttendanceOperation = pending;
    setAttendanceActionsDisabled(true);
    attendanceStatus(retry ? 'Checking the original request before retrying…' : 'Submitting attendance action securely…', retry ? 'checking_server_status' : 'submitting', false);

    if (retry) {
      try {
        const beforeRetry = await window.AttendanceReliability.reconcile(pending);
        if (activeAttendanceOperation?.requestId !== pending.requestId) return;
        const data = beforeRetry.data || {};
        if (beforeRetry.httpStatus === 401) {
          attendanceOperationBusy = false;
          attendanceStatus('Your session expired. Log in again to verify this action. The pending request is retained.', 'session_expired', false);
          return;
        }
        if (['confirmed_success', 'confirmed_rejection', 'processing', 'expired'].includes(data.outcome)) {
          attendanceOperationBusy = false;
          await checkAttendanceOperation(pending);
          return;
        }
        if (data.outcome !== 'unknown') throw new Error('Status check is unavailable.');
      } catch (_) {
        attendanceOperationBusy = false;
        attendanceStatus('Could not verify the original request. No retry was sent. Check status again when the connection returns.', 'connection_unavailable', true);
        return;
      }
    }

    try {
      const response = await window.AttendanceReliability.submit(pending);
      if (activeAttendanceOperation?.requestId !== pending.requestId) return;
      const data = response.data || {};
      if (response.httpStatus === 401 || response.httpStatus === 403) {
        attendanceOperationBusy = false;
        attendanceStatus('Your session or security check needs attention. Refresh or log in again to verify this action. The pending request is retained and will not be resent until you are authenticated.', 'session_expired', false);
        return;
      }
      if (response.httpStatus === 400) {
        window.AttendanceReliability.clearPending(pending);
        activeAttendanceOperation = null;
        attendanceOperationBusy = false;
        setAttendanceActionsDisabled(false);
        attendanceStatus(data.message || 'The attendance request was rejected before processing. No attendance change was made.', 'confirmed_rejection', false);
        showToast(data.message || 'The attendance request was rejected before processing.', 'danger');
        return;
      }
      if (data.outcome === 'confirmed_success' || data.outcome === 'confirmed_rejection') {
        attendanceOperationBusy = false;
        await checkAttendanceOperation(pending);
        return;
      }
      attendanceOperationBusy = false;
      await checkAttendanceOperation(pending);
    } catch (_) {
      if (activeAttendanceOperation?.requestId !== pending.requestId) return;
      attendanceOperationBusy = false;
      await checkAttendanceOperation(pending);
    }
  }

  function initializeAttendanceRecovery() {
    if (!window.AttendanceReliability || !document.querySelector('.app-content [data-action="clock_in"], .app-content [data-action="clock_out"], .app-content [data-action="start_break"], .app-content [data-action="end_break"]')) return Promise.resolve(null);
    if (attendanceRecoveryInitialized) return attendanceRecoveryPromise || Promise.resolve(attendanceCapability);
    attendanceRecoveryInitialized = true;
    document.querySelector('.app-content').dataset.attendanceState = attendanceFrontendState;
    const pending = window.AttendanceReliability.getPending(window.SAS_USER_ID);
    if (pending) {
      activeAttendanceOperation = pending;
      setAttendanceActionsDisabled(true);
    }
    attendanceRecoveryPromise = window.AttendanceReliability.checkCapability().then((capability) => {
      attendanceCapability = capability;
      if (!pending) return;
      if (!capability.ready) {
        attendanceStatus(capability.sessionExpired ? 'Your session expired. Log in again to verify the pending action.' : (capability.message || 'Safe attendance actions are unavailable. Contact an administrator.'), capability.sessionExpired ? 'session_expired' : 'administrator_review_required', false);
        return;
      }
      return checkAttendanceOperation(pending);
    });
    return attendanceRecoveryPromise;
  }

  function bindPageActions() {
    initAttendanceLocationCard();
    initPushNotificationManager();
    syncPushSubscription();
    syncPushLanguage();
    initializeAttendanceRecovery();
    document.querySelectorAll('.app-content [data-action]').forEach((btn) => {
      if (btn.dataset.actionBound === '1') return;
      btn.dataset.actionBound = '1';
      btn.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();

        const action = btn.getAttribute('data-action');
        const type = btn.getAttribute('data-type') || '';

        if (['clock_in', 'clock_out', 'start_break', 'end_break'].includes(action)) {
          if (attendanceRecoveryPromise) await attendanceRecoveryPromise;
          if (activeAttendanceOperation || attendanceOperationBusy) {
            showToast('An attendance action is still unresolved. Check its status before continuing.', 'warning');
            return;
          }
        }

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

        if (['clock_in', 'clock_out', 'start_break', 'end_break'].includes(action)) {
          if (!window.AttendanceReliability) {
            showToast('Safe attendance actions are unavailable in this browser. No action was submitted.', 'danger');
            return;
          }
          if (activeAttendanceOperation || attendanceOperationBusy) return;
          if (!attendanceCapability) attendanceCapability = await window.AttendanceReliability.checkCapability();
          if (!attendanceCapability.ready) {
            showToast(attendanceCapability.message || 'Safe attendance actions are unavailable. Contact an administrator.', 'danger');
            return;
          }
          if (action === 'clock_in' || action === 'clock_out') {
            if (btn.dataset.locationPreference === 'undecided') {
              const choice = await askAttendanceLocation();
              if (!choice) return;
              const savedChoice = await apiPost('/api/clock.php', { action: 'location_preference', preference: choice });
              if (!savedChoice.success) {
                showToast(savedChoice.message || 'Could not save your location choice. Please try again.', 'danger');
                return;
              }
              document.querySelectorAll('[data-location-preference]').forEach((button) => {
                button.dataset.locationPreference = choice;
              });
            }
          }
          try {
            activeAttendanceOperation = window.AttendanceReliability.createPending(window.SAS_USER_ID, action, type);
            runAttendanceOperation(activeAttendanceOperation, false);
          } catch (error) {
            showToast(error.message || 'Could not safely prepare the attendance action.', 'danger');
          }
          return;
        }

        btn.disabled = true;
        const result = await apiPost('/api/clock.php', { action });
        if (result?.success) showToast(result.message || 'Done', 'success');
        else { showToast(result?.message || 'Action failed', 'danger'); btn.disabled = false; }
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
    const clock = document.getElementById('chronoClock');
    const serverIso = clock?.getAttribute('data-server-now');
    const serverMs = serverIso ? Date.parse(serverIso) : NaN;
    const displayedNow = Number.isNaN(serverMs) ? new Date() : new Date(Date.now() + serverMs - (window.__SAS_CLOCK_BOOT__ || Date.now()));
    document.querySelectorAll('[data-live-server-time]').forEach((el) => {
      el.textContent = new Intl.DateTimeFormat('en-US', {
        timeZone: 'Asia/Kuala_Lumpur', hour: 'numeric', minute: '2-digit', hour12: true,
      }).format(displayedNow);
    });
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
    window.__SAS_CLOCK_BOOT__ = Date.now();
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
      } catch (e) { }
    }
    const meta = document.getElementById('metaThemeColor');
    if (meta) meta.setAttribute('content', t === 'day' ? '#e8eef6' : '#06090f');
    if (window.ChronosSpaceBg && typeof window.ChronosSpaceBg.setTheme === 'function') {
      window.ChronosSpaceBg.setTheme(t);
    }
    syncThemeToggleUi(t);
  }

  function syncThemeToggleUi(theme) {
    document.querySelectorAll('.theme-toggle').forEach((btn) => {
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
  }

  // Delegated click handler ensures mobile sidebar, topbar, and dynamic buttons always toggle
  document.addEventListener('click', (event) => {
    const btn = event.target.closest('.theme-toggle');
    if (!btn) return;
    const cur = document.documentElement.getAttribute('data-theme') || 'night';
    applyTheme(cur === 'day' ? 'night' : 'day', true);
  });

  // ——— 3D Globe toggle (on / off) ———
  function applyGlobe(state, persist) {
    const on = state !== 'off';
    const val = on ? 'on' : 'off';
    document.documentElement.setAttribute('data-globe', val);
    if (persist !== false) {
      try {
        localStorage.setItem('chrono_globe', val);
      } catch (e) { }
    }
    const host = document.getElementById('chronoAmbient');
    if (host) {
      if (on) {
        host.style.removeProperty('display');
        host.style.removeProperty('visibility');
      } else {
        host.style.setProperty('display', 'none', 'important');
        host.style.setProperty('visibility', 'hidden', 'important');
      }
    }
    if (window.ChronosSpaceBg && typeof window.ChronosSpaceBg.setEnabled === 'function') {
      window.ChronosSpaceBg.setEnabled(on);
    }
    syncGlobeToggleUi(val);
  }

  function syncGlobeToggleUi(state) {
    const on = state !== 'off';
    document.querySelectorAll('.globe-toggle').forEach((btn) => {
      btn.classList.toggle('is-off', !on);
      btn.classList.toggle('is-on', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      const text = btn.querySelector('.globe-toggle__text');
      if (text) {
        text.textContent = on
          ? btn.getAttribute('data-label-on') || ''
          : btn.getAttribute('data-label-off') || '';
      }
    });

    document.querySelectorAll('.globe-profile-toggle').forEach((input) => {
      input.checked = on;
    });
  }

  function initGlobeToggle() {
    const initial =
      document.documentElement.getAttribute('data-globe') ||
      (function () {
        try {
          return localStorage.getItem('chrono_globe') || 'on';
        } catch (e) {
          return 'on';
        }
      })();
    applyGlobe(initial, false);
  }

  // Delegated click & change handlers for globe toggle buttons & profile switches
  document.addEventListener('click', (event) => {
    const btn = event.target.closest('.globe-toggle');
    if (!btn) return;
    const cur = document.documentElement.getAttribute('data-globe') || 'on';
    applyGlobe(cur === 'off' ? 'on' : 'off', true);
  });

  document.addEventListener('change', (event) => {
    const input = event.target.closest('.globe-profile-toggle');
    if (!input) return;
    applyGlobe(input.checked ? 'on' : 'off', true);
  });

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
        const url = feedUrl + (feedUrl.includes('?') ? '&' : '?') + '_ts=' + Date.now();
        const res = await fetch(url, {
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
          cache: 'no-store',
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
      } catch (e) { }
    }

    tickerRefreshFn = refresh;

    if (root.dataset.bound === '1') {
      refresh();
      return;
    }
    root.dataset.bound = '1';
    refresh();
    setInterval(refresh, 20000);
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') refresh();
    });
  }

  function refreshLiveTicker() {
    if (typeof tickerRefreshFn === 'function') tickerRefreshFn();
    else initLiveTicker();
  }

  // ——— Live sidebar badges (no full page refresh) ———
  let badgePrev = null;
  let badgeTimer = null;
  let notificationAudioContext = null;
  let notificationAudioUnlocked = false;
  let recentMissingClockOutPushAt = 0;

  const handleSwMessage = (event) => {
    if (event.data?.type === 'sas-critical-push' && event.data.category === 'missing_clock_out') {
      recentMissingClockOutPushAt = Date.now();
      document.querySelectorAll('[data-toast-key="badge-incomplete"]').forEach((toast) => toast.remove());
    }
    if (event.data?.type === 'sas-navigate' && event.data.url) {
      try {
        const target = new URL(event.data.url, window.location.href);
        if (target.origin === window.location.origin && (target.protocol === 'http:' || target.protocol === 'https:')) {
          const path = target.pathname;
          const isBlocked = path === '/logout.php' ||
                            path.endsWith('/logout.php') ||
                            path.includes('/api/') ||
                            path.includes('/cli/') ||
                            path.includes('/config/') ||
                            path.includes('/storage/');
          if (!isBlocked && window.location.href !== target.href) {
            window.location.assign(target.href);
          }
        }
      } catch (e) { }
    }
  };

  if (typeof navigator !== 'undefined' && 'serviceWorker' in navigator) {
    if (navigator.serviceWorker.addEventListener) {
      navigator.serviceWorker.addEventListener('message', handleSwMessage);
    }
    navigator.serviceWorker.onmessage = handleSwMessage;
    if (typeof navigator.serviceWorker.startMessages === 'function') {
      try { navigator.serviceWorker.startMessages(); } catch (e) { }
    }
  }

  function unlockNotificationSound() {
    try {
      const AudioContext = window.AudioContext || window.webkitAudioContext;
      if (!AudioContext) return;
      notificationAudioContext ||= new AudioContext();
      if (notificationAudioContext.state === 'suspended') notificationAudioContext.resume();
      notificationAudioUnlocked = true;
    } catch (e) { }
  }

  function playNotificationSound() {
    if (!notificationAudioUnlocked || !notificationAudioContext) return;
    try {
      const ctx = notificationAudioContext;
      const now = ctx.currentTime;
      const gain = ctx.createGain();
      gain.gain.setValueAtTime(0.0001, now);
      gain.gain.exponentialRampToValueAtTime(0.12, now + 0.015);
      gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.42);
      gain.connect(ctx.destination);
      const oscillator = ctx.createOscillator();
      oscillator.type = 'sine';
      oscillator.frequency.setValueAtTime(660, now);
      oscillator.frequency.setValueAtTime(880, now + 0.13);
      oscillator.connect(gain);
      oscillator.start(now);
      oscillator.stop(now + 0.44);
    } catch (e) { }
  }

  function initNotificationSound() {
    if (window.__CHRONOS_NOTIFICATION_SOUND__) return;
    window.__CHRONOS_NOTIFICATION_SOUND__ = true;
    ['pointerdown', 'keydown', 'touchstart'].forEach((eventName) => {
      document.addEventListener(eventName, unlockNotificationSound, { once: true, passive: true });
    });
  }

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
        playNotificationSound();
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
        setTimeout(() => {
          if (Date.now() - recentMissingClockOutPushAt > 15000) {
            showToast(i18n('badge_incomplete'), 'warning', 'badge-incomplete');
          }
        }, 1000);
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
    } catch (e) { }
  }

  function initBadgePoller() {
    if (!document.querySelector('.app-wrapper')) return;
    initNotificationSound();
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

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
  }

  async function initPushNotificationManager() {
    const allowToggle = document.getElementById('allowAllNotifications');
    const statusHint = document.getElementById('notificationStatus');
    const testBtn = document.getElementById('btnTestPushNotification');
    const optionCheckboxes = document.querySelectorAll('.notification-option');

    if (!allowToggle && !statusHint && !testBtn && optionCheckboxes.length === 0) {
      return;
    }

    const setStatus = (key, text) => {
      if (!statusHint) return;
      const str = text || statusHint.getAttribute('data-' + key) || key;
      statusHint.textContent = str;
    };

    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
      if (allowToggle) allowToggle.disabled = true;
      setStatus('unsupported', i18n('push_ui_unsupported'));
      return;
    }

    let reg;
    try {
      reg = await navigator.serviceWorker.ready;
    } catch (e) {
      return;
    }

    let subscription = null;
    try { subscription = await reg.pushManager.getSubscription(); } catch (e) { console.warn('[WebPush] Subscription unavailable:', e); }
    let serverConfig = null;
    try {
      const endpointQuery = subscription?.endpoint ? '?endpoint=' + encodeURIComponent(subscription.endpoint) : '';
      const res = await fetch((window.APP_BASE || '') + '/api/push_subscription.php' + endpointQuery, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        cache: 'no-store',
      });
      serverConfig = await res.json();
      if (!res.ok || !serverConfig?.success) throw new Error(serverConfig?.message || 'Settings unavailable');
    } catch (e) { }

    const prefs = serverConfig?.preferences || {};
    const pushRecovery = window.SASPushRecovery;
    const subscribeWithKey = (key) => reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: typeof key === 'string' ? urlBase64ToUint8Array(key) : key,
    });
    const saveSubscription = (device, preferences) => apiPost('/api/push_subscription.php', {
      action: 'subscribe', subscription: device.toJSON(), preferences,
    });
    const removeEndpoint = (endpoint) => apiPost('/api/push_subscription.php', {
      action: 'unsubscribe', endpoint,
    });
    if (serverConfig) {
      try {
        const pendingEndpoint = sessionStorage.getItem('sas-push-cleanup-endpoint');
        if (pendingEndpoint) {
          const cleaned = await removeEndpoint(pendingEndpoint);
          if (cleaned?.success) sessionStorage.removeItem('sas-push-cleanup-endpoint');
        }
      } catch (error) { /* Keep the pending endpoint for a later page load. */ }
    }
    let recoveryResult = null;
    if (subscription && serverConfig?.subscribed && prefs.all !== false && Notification.permission === 'granted'
        && pushRecovery?.keyStatus(subscription, serverConfig.vapid_public_key) === 'stale') {
      const attemptKey = subscription.endpoint + ':' + serverConfig.vapid_public_key;
      window.__SAS_PUSH_RECOVERY_ATTEMPTS__ ||= new Set();
      let recentlyAttempted = false;
      try {
        const lastAttempt = JSON.parse(sessionStorage.getItem('sas-push-recovery-attempt') || 'null');
        recentlyAttempted = lastAttempt?.key === attemptKey && Date.now() - lastAttempt.at < 300000;
      } catch (error) { }
      if (!window.__SAS_PUSH_RECOVERY_ATTEMPTS__.has(attemptKey) && !recentlyAttempted) {
        window.__SAS_PUSH_RECOVERY_ATTEMPTS__.add(attemptKey);
        try { sessionStorage.setItem('sas-push-recovery-attempt', JSON.stringify({ key: attemptKey, at: Date.now() })); } catch (error) { }
        setStatus('off', i18n('push_recovery_progress'));
        recoveryResult = await pushRecovery.recover({
          subscription,
          currentKey: serverConfig.vapid_public_key,
          preferences: Object.assign({}, prefs),
          subscribe: subscribeWithKey,
          register: saveSubscription,
          removeOld: removeEndpoint,
        });
        subscription = recoveryResult.subscription;
        if (recoveryResult.ok) {
          serverConfig.subscribed = true;
          Object.assign(prefs, { all: true });
          try { sessionStorage.removeItem('sas-push-recovery-attempt'); } catch (error) { }
          if (recoveryResult.cleanupPending) {
            try { sessionStorage.setItem('sas-push-cleanup-endpoint', recoveryResult.oldEndpoint); } catch (error) { }
          }
        } else if (recoveryResult.serverUnconfirmed || recoveryResult.reason === 'replacement_unsubscribe_failed') {
          serverConfig.subscribed = false;
        }
      } else {
        recoveryResult = { ok: false, retained: true, subscription };
      }
    }

    const syncCategoryUi = (isEnabled) => {
      const container = document.querySelector('.notification-preferences');
      if (container) {
        container.style.transition = 'opacity 0.25s ease, filter 0.25s ease';
        container.style.opacity = isEnabled ? '1' : '0.45';
        container.style.pointerEvents = isEnabled ? 'auto' : 'none';
        container.style.filter = isEnabled ? 'none' : 'grayscale(0.8)';
      }
      optionCheckboxes.forEach((cb) => {
        cb.disabled = !isEnabled;
        if (!isEnabled) {
          cb.checked = false;
        } else {
          const key = cb.dataset.notificationKey;
          cb.checked = prefs[key] !== false;
        }
      });
      if (testBtn) {
        testBtn.disabled = !isEnabled;
        testBtn.style.opacity = isEnabled ? '1' : '0.5';
        testBtn.style.pointerEvents = isEnabled ? 'auto' : 'none';
      }
    };

    optionCheckboxes.forEach((cb) => {
      cb.addEventListener('change', async () => {
        if (!allowToggle?.checked) return;
        const key = cb.dataset.notificationKey;
        if (!key) return;
        const previous = prefs[key] !== false;
        const update = { [key]: cb.checked };
        const result = await apiPost('/api/push_subscription.php', { action: 'update_preferences', endpoint: subscription?.endpoint || '', preferences: update });
        if (result?.success) prefs[key] = result.preferences[key];
        else {
          cb.checked = previous;
          if (result?.code === 'device_not_registered') {
            serverConfig.subscribed = false;
            if (allowToggle) allowToggle.checked = false;
            syncCategoryUi(false);
            setStatus('off', i18n('push_ui_unregistered'));
          }
          showToast(result?.message || i18n('push_ui_preference_failed'), 'danger', 'push-preference');
        }
      });
    });

    if (Notification.permission === 'denied') {
      if (allowToggle) {
        allowToggle.checked = false;
        allowToggle.disabled = true;
      }
      setStatus('blocked', i18n('push_ui_blocked'));
      syncCategoryUi(false);
    } else if (!serverConfig) {
      if (allowToggle) { allowToggle.checked = false; allowToggle.disabled = true; }
      setStatus('off', i18n('push_ui_settings_unavailable'));
      syncCategoryUi(false);
    } else if (recoveryResult && !recoveryResult.ok) {
      if (allowToggle) allowToggle.checked = !!subscription && !!serverConfig.subscribed;
      syncCategoryUi(!!allowToggle?.checked);
      setStatus('off', i18n(recoveryResult.restored || recoveryResult.retained ? 'push_recovery_retained' : 'push_recovery_failed'));
    } else if (subscription && serverConfig.subscribed && prefs.all !== false) {
      if (allowToggle) allowToggle.checked = true;
      setStatus('enabled', recoveryResult?.cleanupPending ? i18n('push_recovery_cleanup') : i18n('push_ui_enabled'));
      syncCategoryUi(true);
    } else {
      if (allowToggle) allowToggle.checked = false;
      setStatus('off', i18n(subscription ? 'push_ui_unregistered' : 'push_ui_disabled'));
      syncCategoryUi(false);
    }

    if (allowToggle && !allowToggle.dataset.pushBound) {
      allowToggle.dataset.pushBound = '1';
      allowToggle.addEventListener('change', async () => {
        if (allowToggle.checked) {
          try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
              allowToggle.checked = false;
              syncCategoryUi(false);
              setStatus('blocked', i18n('push_ui_blocked'));
              showToast(i18n('push_ui_permission_denied'), 'warning', 'push-toggle');
              return;
            }

            const vapidKey = serverConfig?.vapid_public_key;
            if (!vapidKey) {
              throw new Error(i18n('push_ui_key_unavailable'));
            }

            let res;
            if (subscription && pushRecovery?.keyStatus(subscription, vapidKey) === 'stale') {
              const recovered = await pushRecovery.recover({
                subscription, currentKey: vapidKey, preferences: Object.assign({}, prefs),
                subscribe: subscribeWithKey, register: saveSubscription, removeOld: removeEndpoint,
              });
              subscription = recovered.subscription;
              if (!recovered.ok) {
                recoveryResult = recovered;
                if (recovered.serverUnconfirmed || recovered.reason === 'replacement_unsubscribe_failed') serverConfig.subscribed = false;
                throw new Error(i18n(recovered.restored || recovered.retained ? 'push_recovery_retained' : 'push_recovery_failed'));
              }
              recoveryResult = recovered;
              if (recovered.cleanupPending) {
                try { sessionStorage.setItem('sas-push-cleanup-endpoint', recovered.oldEndpoint); } catch (error) { }
              }
              res = { success: true, preferences: prefs };
            } else {
              subscription = await subscribeWithKey(vapidKey);
              res = await saveSubscription(subscription, Object.assign({}, prefs));
            }

            if (res?.success) {
              Object.assign(prefs, res.preferences || {}, { all: true });
              serverConfig.subscribed = true;
              syncCategoryUi(true);
              setStatus('enabled', recoveryResult?.cleanupPending ? i18n('push_recovery_cleanup') : i18n('push_ui_enabled'));
              showToast(i18n('push_ui_enabled_toast'), 'success', 'push-toggle');
            } else {
              throw new Error(res?.message || i18n('push_ui_enable_failed'));
            }
          } catch (err) {
            console.error('[WebPush] Subscribe error:', err);
            allowToggle.checked = !!subscription && !!serverConfig?.subscribed;
            syncCategoryUi(allowToggle.checked);
            const failure = recoveryResult
              ? i18n(recoveryResult.restored || recoveryResult.retained ? 'push_recovery_retained' : 'push_recovery_failed')
              : i18n('push_ui_enable_failed');
            setStatus('off', failure);
            showToast(failure, 'danger', 'push-toggle');
          }
        } else {
          try {
            if (subscription) {
              const endpoint = subscription.endpoint;
              const result = await apiPost('/api/push_subscription.php', {
                action: 'unsubscribe',
                endpoint: endpoint,
              });
              if (!result?.success) throw new Error(result?.message || i18n('push_ui_disable_failed'));
              serverConfig.subscribed = false;
              const removed = await subscription.unsubscribe();
              if (removed) subscription = null;
            }
            prefs.all = false;
            syncCategoryUi(false);
            setStatus('off', i18n('push_ui_disabled'));
            showToast(i18n('push_ui_disabled_toast'), 'info', 'push-toggle');
          } catch (err) {
            console.error('[WebPush] Unsubscribe error:', err);
            allowToggle.checked = !!serverConfig.subscribed;
            syncCategoryUi(allowToggle.checked);
            setStatus('off', i18n(serverConfig.subscribed ? 'push_ui_disable_failed' : 'push_ui_disabled'));
            showToast(err?.message || i18n('push_ui_disable_failed'), 'danger', 'push-toggle');
          }
        }
      });
    }

    if (testBtn && !testBtn.dataset.pushBound) {
      testBtn.dataset.pushBound = '1';
      testBtn.addEventListener('click', async (e) => {
        e.preventDefault();
        testBtn.disabled = true;
        const origHtml = testBtn.innerHTML;
        testBtn.textContent = i18n('push_ui_sending');
        try {
          if (!subscription && Notification.permission !== 'granted') {
            showToast(i18n('push_ui_test_enable_first'), 'warning');
            return;
          }
          const res = await apiPost('/api/push_subscription.php', { action: 'test' });
          if (res?.success) {
            showToast(i18n('push_ui_test_sent'), 'success');
          } else {
            showToast(res?.message || i18n('push_ui_test_failed'), 'warning');
          }
        } catch (err) {
          showToast(i18n('push_ui_test_failed'), 'danger');
        } finally {
          testBtn.disabled = false;
          testBtn.innerHTML = origHtml;
        }
      });
    }
  }

  let pushLanguageSyncQueue = Promise.resolve();
  function syncPushLanguage() {
    pushLanguageSyncQueue = pushLanguageSyncQueue.then(async () => {
      if (!('serviceWorker' in navigator) || !('Notification' in window) || Notification.permission !== 'granted') return;
      const langTag = (document.documentElement.lang || '').toLowerCase();
      const language = langTag.startsWith('zh') ? 'zh' : langTag.startsWith('ms') ? 'ms' : 'en';
      const registration = await navigator.serviceWorker.ready;
      const device = await registration.pushManager?.getSubscription();
      if (!device?.endpoint) return;
      const marker = device.endpoint + '|' + language;
      try { if (sessionStorage.getItem('sas-push-language') === marker) return; } catch (error) { }
      const token = document.querySelector('meta[name="csrf-token"]')?.content || window.CSRF_TOKEN || '';
      const response = await fetch((window.APP_BASE || '') + '/api/push_subscription.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ action: 'set_language', endpoint: device.endpoint, csrf_token: token }),
      });
      const result = await response.json();
      if (response.ok && result?.success) {
        try { sessionStorage.setItem('sas-push-language', marker); } catch (error) { }
      }
    }).catch((error) => { console.warn('[WebPush] Language sync unavailable:', error); });
    return pushLanguageSyncQueue;
  }

  let pushSyncQueue = Promise.resolve();
  function syncPushSubscription() {
    pushSyncQueue = pushSyncQueue.then(async () => {
      if (!window.SAS_USER_ID) return;
      if (!('serviceWorker' in navigator) || !('Notification' in window) || Notification.permission !== 'granted') return;
      const registration = await navigator.serviceWorker.ready;
      const sub = await registration.pushManager?.getSubscription();
      if (!sub?.endpoint) return;

      const marker = String(window.SAS_USER_ID) + '|' + sub.endpoint;
      try {
        if (sessionStorage.getItem('sas-push-synced') === marker) return;
      } catch (_) {}

      const rawKey = sub.getKey ? sub.getKey('p256dh') : null;
      const rawAuth = sub.getKey ? sub.getKey('auth') : null;
      const p256dh = rawKey ? btoa(String.fromCharCode.apply(null, new Uint8Array(rawKey))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '') : '';
      const auth = rawAuth ? btoa(String.fromCharCode.apply(null, new Uint8Array(rawAuth))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '') : '';

      const token = document.querySelector('meta[name="csrf-token"]')?.content || window.CSRF_TOKEN || '';
      const response = await fetch((window.APP_BASE || '') + '/api/push_subscription.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          action: 'sync',
          endpoint: sub.endpoint,
          p256dh: p256dh,
          auth: auth,
          csrf_token: token,
        }),
      });
      const result = await response.json();
      if (response.ok && result?.success) {
        try {
          sessionStorage.setItem('sas-push-synced', marker);
        } catch (_) {}
      }
    }).catch((error) => {
      console.warn('[WebPush] Subscription sync unavailable:', error);
    });
    return pushSyncQueue;
  }

  function initLogoutProtection() {
    if (window.__SAS_LOGOUT_BOUND__) return;
    window.__SAS_LOGOUT_BOUND__ = true;

    document.addEventListener('submit', async (event) => {
      const form = event.target.closest('form[data-logout-form]');
      if (!form) return;

      if (form.dataset.submitting === '1') {
        return;
      }

      event.preventDefault();
      form.dataset.submitting = '1';

      let endpoint = '';
      try {
        if ('serviceWorker' in navigator) {
          const timeoutPromise = new Promise((resolve) => setTimeout(() => resolve(null), 800));
          const subPromise = (async () => {
            const reg = await navigator.serviceWorker.ready;
            return await reg.pushManager?.getSubscription();
          })();
          const sub = await Promise.race([subPromise, timeoutPromise]);
          if (sub?.endpoint) {
            endpoint = sub.endpoint;
            try {
              await Promise.race([sub.unsubscribe(), new Promise((resolve) => setTimeout(resolve, 500))]);
            } catch (_) {}
          }
        }
      } catch (err) {
        console.warn('[Logout] Push cleanup error:', err);
      }

      try {
        sessionStorage.removeItem('sas-push-synced');
        sessionStorage.removeItem('sas-push-language');
        sessionStorage.removeItem('sas-push-cleanup-endpoint');
        sessionStorage.removeItem('sas-push-recovery-attempt');
      } catch (_) {}

      const field = form.querySelector('[data-push-endpoint-field]');
      if (field && endpoint) {
        field.value = endpoint;
      }

      form.submit();
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

  // Leave approval/rejection buttons open their corresponding review modal.
  // Delegation keeps this working after SPA page swaps and for mobile cards.
  if (!window.__CHRONOS_LEAVE_REVIEW_BOUND__) {
    window.__CHRONOS_LEAVE_REVIEW_BOUND__ = true;
    document.addEventListener('click', (event) => {
      const button = event.target.closest('[data-leave-modal]');
      if (!button || !window.bootstrap?.Modal) return;
      event.preventDefault();
      const kind = button.dataset.leaveModal === 'reject' ? 'reject' : 'approve';
      const prefix = kind === 'reject' ? 'reject' : 'approve';
      const set = (suffix, value) => {
        const el = document.getElementById(prefix + suffix);
        if (el) el.textContent = value || '';
      };
      const id = document.getElementById(prefix + 'LeaveId');
      if (id) id.value = button.dataset.id || '';
      set('StaffName', button.dataset.name);
      set('LeaveDate', button.dataset.date);
      set('LeaveType', button.dataset.type);
      set('LeaveReason', button.dataset.reason);
      const modal = document.getElementById(prefix + 'LeaveModal');
      if (modal) {
        const originalParent = modal.parentNode;
        if (originalParent !== document.body) document.body.appendChild(modal);
        const instance = bootstrap.Modal.getOrCreateInstance(modal);
        modal.addEventListener('hidden.bs.modal', () => {
          if (originalParent?.isConnected) originalParent.appendChild(modal);
        }, { once: true });
        instance.show();
      }
    });
  }

  bindPageActions();
  initThemeToggle();
  initGlobeToggle();
  initLiveTicker();
  initBadgePoller();
  initServiceWorker();
  initLogoutProtection();

  window.SAS = {
    showToast,
    alertAction,
    apiPost,
    showLoading,
    confirmAction,
    bindPageActions,
    applyTheme,
    earthPulse,
    initThemeToggle,
    syncThemeToggleUi,
    applyGlobe,
    initGlobeToggle,
    syncGlobeToggleUi,
    refreshLiveTicker,
    refreshBadges: function () {
      return fetchBadges(false);
    },
    applyBadges,
    printPage,
    syncPushSubscription,
  };
})();
