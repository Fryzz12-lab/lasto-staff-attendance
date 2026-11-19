/* Durable, tab-scoped attendance operation context and HTTP contract. */
(function (root) {
  'use strict';

  const pendingKey = (userId) => 'sas.attendance.pending.v1.' + String(userId);
  const contextKey = 'sas.attendance.context.v1';
  const endpoint = (root.APP_BASE || '') + '/api/attendance_operation.php';

  function secureId() {
    if (root.crypto?.randomUUID) return root.crypto.randomUUID();
    if (!root.crypto?.getRandomValues) throw new Error('Secure request IDs are unavailable in this browser.');
    const bytes = new Uint8Array(16);
    root.crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
  }

  function getContext() {
    let value = root.sessionStorage.getItem(contextKey);
    if (!value) {
      value = secureId();
      root.sessionStorage.setItem(contextKey, value);
    }
    return value;
  }

  function readStored(userId) {
    try {
      const value = root.sessionStorage.getItem(pendingKey(userId));
      if (!value) return null;
      const parsed = JSON.parse(value);
      if (!parsed || parsed.version !== 1 || typeof parsed.requestId !== 'string' || typeof parsed.action !== 'string') return null;
      return parsed;
    } catch (_) {
      return null;
    }
  }

  function getPending(userId) {
    const pending = readStored(userId);
    if (!pending || String(pending.userId) !== String(userId) || pending.contextId !== getContext()) return null;
    return pending;
  }

  function createPending(userId, action, type) {
    const existing = getPending(userId);
    if (existing) {
      if (existing.action !== action || String(existing.type || '') !== String(type || '')) {
        throw new Error('Another attendance action is still unresolved. Check its status first.');
      }
      return existing;
    }
    const pending = {
      version: 1,
      userId: String(userId),
      contextId: getContext(),
      requestId: secureId(),
      action,
      type: action === 'start_break' ? String(type || '') : '',
      createdAt: new Date().toISOString(),
      locationFinalizationAttempted: false,
    };
    root.sessionStorage.setItem(pendingKey(userId), JSON.stringify(pending));
    return pending;
  }

  function savePending(pending) {
    root.sessionStorage.setItem(pendingKey(pending.userId), JSON.stringify(pending));
  }

  function clearPending(pending) {
    const current = readStored(pending.userId);
    if (current && current.requestId === pending.requestId && String(current.userId) === String(pending.userId)) {
      root.sessionStorage.removeItem(pendingKey(pending.userId));
    }
  }

  function bodyFor(pending) {
    switch (pending.action) {
      case 'clock_in':
      case 'clock_out':
        return { path: '/api/clock.php', body: { action: pending.action } };
      case 'start_break':
        return { path: '/api/break.php', body: { action: 'start', type: pending.type } };
      case 'end_break':
        return { path: '/api/break.php', body: { action: 'end' } };
      default:
        throw new Error('Unsupported attendance action.');
    }
  }

  async function readJson(response) {
    let data;
    try { data = await response.json(); } catch (_) { data = null; }
    return { httpStatus: response.status, data: data || {} };
  }

  async function checkCapability() {
    try {
      const response = await root.fetch(endpoint, { method: 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const result = await readJson(response);
      return result.data.contract_version === 1 && result.data.idempotency_supported === true && result.data.outcome === 'ready'
        ? { ready: true, state: result.data.state }
        : { ready: false, sessionExpired: result.httpStatus === 401 || result.httpStatus === 403, message: result.data.outcome === 'unavailable' ? 'Safe attendance retries are not enabled yet. Please contact your administrator.' : 'Attendance service status is unavailable.' };
    } catch (_) {
      return { ready: false, unavailable: true, message: 'Could not check attendance service status. No action was submitted.' };
    }
  }

  async function reconcile(pending) {
    const url = endpoint + '?request_id=' + encodeURIComponent(pending.requestId);
    const response = await root.fetch(url, { method: 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const result = await readJson(response);
    return { httpStatus: result.httpStatus, data: result.data };
  }

  async function submit(pending) {
    const capability = await checkCapability();
    if (!capability.ready) {
      return { httpStatus: 503, data: { contract_version: 1, outcome: 'feature_unavailable', message: capability.message || 'Safe attendance actions are unavailable.' } };
    }
    const request = bodyFor(pending);
    const token = root.document.querySelector('meta[name="csrf-token"]')?.content || root.CSRF_TOKEN || '';
    const response = await root.fetch((root.APP_BASE || '') + request.path, {
      method: 'POST', credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': token,
        'Idempotency-Key': pending.requestId,
        Accept: 'application/json',
      },
      body: JSON.stringify(Object.assign({}, request.body, { csrf_token: token })),
    });
    return readJson(response);
  }

  root.AttendanceReliability = { getPending, createPending, savePending, clearPending, checkCapability, reconcile, submit };
})(window);
