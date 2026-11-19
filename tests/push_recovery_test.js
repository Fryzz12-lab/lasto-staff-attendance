/* Run: node tests/push_recovery_test.js; all browser/server operations are mocked. */
const assert = require('node:assert/strict');
const { keyStatus, recover } = require('../assets/js/push_recovery.js');

const oldKey = Uint8Array.from([1, 2, 3]);
const currentKey = Buffer.from([4, 5, 6]).toString('base64url');
function device(endpoint, key, unsubscribe = async () => true) {
  return { endpoint, options: { applicationServerKey: key }, unsubscribe, toJSON: () => ({ endpoint }) };
}
const oldDevice = () => device('old', oldKey);
const newDevice = () => device('new', Uint8Array.from([4, 5, 6]));
const preferences = { all: true, break_ending: false, leave_decision: true };
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; }

(async () => {
  check(keyStatus(newDevice(), currentKey) === 'match', 'Current VAPID key matches');
  check(keyStatus(oldDevice(), currentKey) === 'stale', 'Outdated VAPID key detected');
  check(keyStatus(device('unknown', undefined), currentKey) === 'unknown', 'Unsupported key comparison is non-destructive');

  let registeredPreferences;
  let removed = 0;
  const success = await recover({ subscription: oldDevice(), currentKey, preferences,
    subscribe: async () => newDevice(),
    register: async (subscription, prefs) => { registeredPreferences = prefs; return { success: true }; },
    removeOld: async () => { removed++; return { success: true }; },
  });
  check(success.ok && success.subscription.endpoint === 'new', 'Successful replacement');
  check(success.oldEndpoint === 'old', 'Recovery records old endpoint for deferred cleanup');
  check(removed === 1, 'Old server endpoint removed only after new registration');
  check(registeredPreferences.break_ending === false && registeredPreferences.leave_decision === true, 'Device categories copied');

  let attempted = false;
  const cannotUnsubscribe = await recover({ subscription: device('old', oldKey, async () => false), currentKey, preferences,
    subscribe: async () => { attempted = true; return newDevice(); },
    register: async () => ({ success: true }), removeOld: async () => ({ success: true }),
  });
  check(cannotUnsubscribe.reason === 'browser_unsubscribe' && cannotUnsubscribe.retained, 'Browser unsubscribe failure retains old browser subscription');
  check(!attempted, 'No replacement attempted after browser unsubscribe failure');

  const cleanupFailure = await recover({ subscription: oldDevice(), currentKey, preferences,
    subscribe: async () => newDevice(), register: async () => ({ success: true }),
    removeOld: async () => ({ success: false }),
  });
  check(cleanupFailure.ok && cleanupFailure.cleanupPending, 'Server unsubscribe failure retains working replacement');
  check(cleanupFailure.oldEndpoint === 'old', 'Failed cleanup can be retried later');

  let subscribeCalls = 0;
  const creationFailure = await recover({ subscription: oldDevice(), currentKey, preferences,
    subscribe: async () => { subscribeCalls++; if (subscribeCalls === 1) throw new Error('new key failed'); return oldDevice(); },
    register: async () => ({ success: true }), removeOld: async () => ({ success: true }),
  });
  check(!creationFailure.ok && creationFailure.restored && creationFailure.subscription.endpoint === 'old', 'New subscription failure restores old browser subscription');
  check(subscribeCalls === 2, 'Restore attempts old VAPID key once');

  let replacementRemoved = false;
  const registrationFailure = await recover({ subscription: oldDevice(), currentKey, preferences,
    subscribe: async (key) => typeof key === 'string'
      ? device('new', Uint8Array.from([4, 5, 6]), async () => { replacementRemoved = true; return true; })
      : oldDevice(),
    register: async (subscription) => ({ success: subscription.endpoint === 'old' }),
    removeOld: async () => ({ success: true }),
  });
  check(registrationFailure.restored && replacementRemoved, 'Failed server registration removes replacement then restores old');

  const unconfirmedRestore = await recover({ subscription: oldDevice(), currentKey, preferences,
    subscribe: async (key) => typeof key === 'string' ? newDevice() : oldDevice(),
    register: async () => ({ success: false }), removeOld: async () => ({ success: true }),
  });
  check(unconfirmedRestore.restored && unconfirmedRestore.serverUnconfirmed, 'Restored browser subscription reports unconfirmed server registration');

  const restoreFailure = await recover({ subscription: oldDevice(), currentKey, preferences,
    subscribe: async () => { throw new Error('unavailable'); },
    register: async () => ({ success: true }), removeOld: async () => ({ success: true }),
  });
  check(restoreFailure.reason === 'restore_failed' && restoreFailure.subscription === null, 'Unrecoverable browser failure is explicit');
  console.log(`Push recovery: ${checks}/${checks} passed`);
})().catch((error) => { console.error(error); process.exitCode = 1; });
