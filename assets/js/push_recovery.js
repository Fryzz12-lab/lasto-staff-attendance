/* Browser-side VAPID comparison and reversible subscription replacement. */
(function (root) {
  'use strict';

  function decodeKey(value) {
    const padded = value.replace(/-/g, '+').replace(/_/g, '/');
    const raw = root.atob(padded + '='.repeat((4 - padded.length % 4) % 4));
    return Uint8Array.from(raw, (char) => char.charCodeAt(0));
  }

  function keyStatus(subscription, currentKey) {
    const previous = subscription?.options?.applicationServerKey;
    if (!subscription || !currentKey || !previous) return 'unknown';
    try {
      const oldBytes = new Uint8Array(previous);
      const newBytes = decodeKey(currentKey);
      if (oldBytes.length !== newBytes.length) return 'stale';
      return oldBytes.every((byte, index) => byte === newBytes[index]) ? 'match' : 'stale';
    } catch (error) {
      return 'unknown';
    }
  }

  async function recover({ subscription, currentKey, preferences, subscribe, register, removeOld }) {
    if (keyStatus(subscription, currentKey) !== 'stale') {
      return { ok: false, reason: 'not_stale', subscription };
    }
    const oldEndpoint = subscription.endpoint;
    const oldKey = subscription.options.applicationServerKey;
    try {
      if (!await subscription.unsubscribe()) {
        return { ok: false, reason: 'browser_unsubscribe', subscription, retained: true };
      }
    } catch (error) {
      return { ok: false, reason: 'browser_unsubscribe', subscription, retained: true };
    }

    async function restore(reason) {
      if (replacement) {
        try {
          if (!await replacement.unsubscribe()) {
            return { ok: false, reason: 'replacement_unsubscribe_failed', cause: reason, subscription: replacement };
          }
        } catch (error) {
          return { ok: false, reason: 'replacement_unsubscribe_failed', cause: reason, subscription: replacement };
        }
      }
      let restored;
      try {
        restored = await subscribe(oldKey);
      } catch (error) {
        return { ok: false, reason: 'restore_failed', cause: reason, subscription: null };
      }
      try {
        const saved = await register(restored, preferences);
        if (!saved?.success) return { ok: false, reason: 'restore_server_failed', cause: reason, subscription: restored, restored: true, serverUnconfirmed: true };
      } catch (error) {
        return { ok: false, reason: 'restore_server_failed', cause: reason, subscription: restored, restored: true, serverUnconfirmed: true };
      }
      return { ok: false, reason, subscription: restored, restored: true };
    }

    let replacement;
    try {
      replacement = await subscribe(currentKey);
    } catch (error) {
      return restore('resubscribe_failed');
    }
    try {
      const saved = await register(replacement, preferences);
      if (!saved?.success) return restore('server_register_failed');
    } catch (error) {
      return restore('server_register_failed');
    }

    let cleanupPending = false;
    if (replacement.endpoint !== oldEndpoint) {
      try {
        const removed = await removeOld(oldEndpoint);
        cleanupPending = !removed?.success;
      } catch (error) {
        cleanupPending = true;
      }
    }
    return { ok: true, subscription: replacement, cleanupPending, oldEndpoint };
  }

  const api = { keyStatus, recover };
  root.SASPushRecovery = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
})(typeof window !== 'undefined' ? window : globalThis);
