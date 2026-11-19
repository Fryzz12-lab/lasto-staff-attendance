'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto').webcrypto;
const values = new Map();
const requests = [];
let migrationAvailable = true;
global.window = {
  APP_BASE: '/staff-app',
  CSRF_TOKEN: 'csrf-test-only',
  crypto,
  sessionStorage: {
    getItem: (key) => values.has(key) ? values.get(key) : null,
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
  },
  document: { querySelector: () => null },
  fetch: async (url, options) => {
    requests.push({ url, options });
    return migrationAvailable
      ? { status: 200, json: async () => ({ contract_version: 1, outcome: 'ready', idempotency_supported: true }) }
      : { status: 503, json: async () => ({ contract_version: 1, outcome: 'unavailable', idempotency_supported: false }) };
  },
};

require('../assets/js/attendance_reliability.js');
const reliability = global.window.AttendanceReliability;
let checks = 0;
function check(value, message) {
  assert.ok(value, message);
  checks++;
}

(async () => {
  const pending = reliability.createPending(42, 'start_break', 'lunch');
  check(/^[0-9a-f-]{36}$/i.test(pending.requestId), 'Request ID is a cryptographically generated UUID');
  check(reliability.getPending(42).requestId === pending.requestId, 'Pending request is restored for the same authenticated user and tab context');
  check(reliability.getPending(99) === null, 'Pending request is not exposed to a different authenticated user');
  const otherUserPending = reliability.createPending(99, 'clock_in', '');
  check(reliability.getPending(42).requestId === pending.requestId && reliability.getPending(99).requestId === otherUserPending.requestId, 'Account switching preserves separate pending contexts without cross-account reuse');
  check(reliability.createPending(42, 'start_break', 'lunch').requestId === pending.requestId, 'Same logical action reuses its request ID');
  assert.throws(() => reliability.createPending(42, 'clock_out', ''), /unresolved/);
  checks++;
  const persisted = JSON.stringify(pending);
  check(!/csrf|password|email|full_name|employee_id/i.test(persisted), 'Persisted context contains no credentials or employee personal information');

  const capability = await reliability.checkCapability();
  check(capability.ready === true, 'Keyed operations enable only after the capability endpoint confirms support');
  const submitted = await reliability.submit(pending);
  check(submitted.httpStatus === 200, 'Mutation result is returned with its HTTP status');
  check(requests[0].options.method === 'GET', 'Capability request is read-only');
  const post = requests[2];
  check(post.options.headers['Idempotency-Key'] === pending.requestId, 'Mutation sends the persisted request ID in the header');
  check(JSON.parse(post.options.body).csrf_token === 'csrf-test-only', 'CSRF remains in the request body and outside persisted context');
  check(JSON.parse(post.options.body).type === 'lunch', 'Semantic break payload is preserved for retries');

  reliability.clearPending(pending);
  check(reliability.getPending(42) === null, 'Terminal operation cleanup removes matching pending context');
  migrationAvailable = false;
  check((await reliability.checkCapability()).ready === false, 'Missing migration disables the keyed feature');
  const blocked = await reliability.submit(otherUserPending);
  check(blocked.data.outcome === 'feature_unavailable' && requests.at(-1).options.method === 'GET', 'Unavailable migration never falls back to an unkeyed mutation');
  console.log(`PASS: ${checks} attendance frontend reliability checks.`);
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
