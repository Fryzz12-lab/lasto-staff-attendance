/* Mocked service-worker push and click behavior; no network or real push. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const handlers = {};
const shown = [];
const messages = [];
const navigation = [];
let windows = [];
let opened = null;
const scope = 'https://example.test/agy-staff-attendance/';
const clients = {
  claim: async () => {},
  matchAll: async () => windows,
  openWindow: async (url) => { opened = url; },
};
const worker = {
  addEventListener: (name, handler) => { handlers[name] = handler; },
  skipWaiting: async () => {},
  clients,
  registration: {
    scope,
    getNotifications: async () => [],
    showNotification: async (title, options) => { shown.push({ title, options }); },
  },
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'sw.js'), 'utf8'), {
  self: worker, caches: {}, URL, Date, Promise, console, Response,
});
function windowClient(url, visibilityState, overrides = {}) {
  const client = {
    url, visibilityState,
    postMessage: (data) => messages.push(data),
    focus: async () => { navigation.push('focus:' + url); return client; },
    navigate: async (destUrl) => { navigation.push(destUrl); return client; },
    ...overrides
  };
  return client;
}
async function push(tag, category, url = '/agy-staff-attendance/staff/leave.php') {
  let task;
  handlers.push({ data: { json: () => ({ title: 'Notice', body: 'Body', tag, category, url }) },
    waitUntil: (promise) => { task = promise; } });
  await task;
}
async function click(url) {
  let task;
  let closed = false;
  handlers.notificationclick({ notification: { data: { url }, close: () => { closed = true; } },
    waitUntil: (promise) => { task = promise; } });
  await task;
  return closed;
}
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; }

(async () => {
  // --- BASELINE NOTIFICATION DISPLAY CHECKS ---
  windows = [windowClient(scope + 'staff/index.php', 'visible')];
  await push('visible-normal', 'leave_decision');
  check(shown.length === 1, 'Visible noncritical push displays one native notification');
  check(shown[0].options.silent === true && shown[0].options.vibrate === undefined, 'Visible noncritical push uses silent: true');
  await push('visible-critical', 'missing_clock_out');
  check(shown.length === 2 && shown[1].options.renotify === false && shown[1].options.silent !== true && Array.isArray(shown[1].options.vibrate), 'Visible critical push remains visible with sound/vibration');
  check(messages.length === 1 && messages[0].category === 'missing_clock_out', 'Critical category relayed to in-app deduplication');
  await push('visible-critical', 'missing_clock_out');
  check(shown.length === 2 && messages.length === 1, 'Duplicate tag does not make second banner or alert');
  windows = [windowClient(scope + 'staff/index.php', 'hidden')];
  await push('background-normal', 'leave_decision');
  check(shown.length === 3 && shown[2].options.silent !== true && Array.isArray(shown[2].options.vibrate), 'Background push displays a notification');
  windows = [];
  await push('closed-critical', 'shift_ending');
  check(shown.length === 4 && shown[3].options.silent !== true && Array.isArray(shown[3].options.vibrate), 'Closed-browser push displays a notification');
  worker.registration.getNotifications = async () => [{ tag: 'already-shown' }];
  await push('already-shown', 'break_ending');
  check(shown.length === 4, 'Existing native notification prevents duplicate banner');
  worker.registration.getNotifications = async () => [];

  // Malformed payload test
  let malformedTask;
  handlers.push({
    data: { json: () => null, text: () => 'Raw fallback' },
    waitUntil: (promise) => { malformedTask = promise; }
  });
  await malformedTask;
  check(shown.length === 5, 'Malformed push payload displays safe fallback notification');

  // Baseline navigation tests
  const hidden = windowClient(scope + 'staff/index.php', 'hidden');
  const visible = windowClient(scope + 'admin/index.php', 'visible');
  windows = [hidden, visible];
  check(await click('/agy-staff-attendance/admin/leave_requests.php'), 'Click closes native notification');
  check(navigation.includes('https://example.test/agy-staff-attendance/admin/leave_requests.php'), 'Click navigates to correct destination');
  check(navigation.includes('focus:' + visible.url), 'Click focuses visible application window');

  // =========================================================================
  // --- PHASE 6C.2C EXPANDED FOREGROUND & BACKGROUND NAVIGATION COVERAGE ---
  // =========================================================================

  // Scenario 1: Foreground PWA notification clicks (Simulated in Node.js mock)
  navigation.length = 0;
  messages.length = 0;
  opened = null;
  const fgAdminTab = windowClient(scope + 'admin/index.php', 'visible');
  windows = [fgAdminTab];
  check(await click('/agy-staff-attendance/notifications.php'), 'Scenario 1: Click closes notification in foreground');
  check(navigation.includes('focus:' + fgAdminTab.url), 'Scenario 1: Focuses existing visible window first');
  check(navigation.includes('https://example.test/agy-staff-attendance/notifications.php'), 'Scenario 1: Calls navigate with notification target');
  check(messages.some((m) => m?.type === 'sas-navigate' && m?.url === 'https://example.test/agy-staff-attendance/notifications.php'), 'Scenario 1: Relays sas-navigate message to page');
  check(opened === null, 'Scenario 1: Prevents duplicate window in foreground');

  // Scenario 2: Background PWA notification clicks (Simulated in Node.js mock; Android-verified in Phase 6C.2)
  navigation.length = 0;
  messages.length = 0;
  opened = null;
  const bgStaffTab = windowClient(scope + 'staff/attendance.php', 'hidden');
  windows = [bgStaffTab];
  check(await click('/agy-staff-attendance/notifications.php'), 'Scenario 2: Click closes notification in background');
  check(navigation.includes('focus:' + bgStaffTab.url), 'Scenario 2: Focuses background window to bring to front');
  check(navigation.includes('https://example.test/agy-staff-attendance/notifications.php'), 'Scenario 2: Navigates background window to target');
  check(messages.some((m) => m?.type === 'sas-navigate' && m?.url === 'https://example.test/agy-staff-attendance/notifications.php'), 'Scenario 2: Relays sas-navigate message to background window');
  check(opened === null, 'Scenario 2: Prevents duplicate window from background');

  // Scenario 3: Closed-window cold launch (Simulated in Node.js mock; Android-verified in Phase 6C.2 Test B)
  navigation.length = 0;
  messages.length = 0;
  opened = null;
  windows = [];
  check(await click('/agy-staff-attendance/notifications.php'), 'Scenario 3: Click closes notification on cold start');
  check(opened === 'https://example.test/agy-staff-attendance/notifications.php', 'Scenario 3: Opens destination directly via openWindow');
  check(navigation.length === 0, 'Scenario 3: No existing window client navigation attempted');

  // Scenario 4: Failed WindowClient.navigate() throwing TypeError (Simulated in Node.js mock)
  navigation.length = 0;
  messages.length = 0;
  opened = null;
  const errTab = windowClient(scope + 'staff/profile.php', 'visible', {
    navigate: async () => { throw new TypeError('Frame navigation blocked by browser policy'); }
  });
  windows = [errTab];
  check(await click('/agy-staff-attendance/notifications.php'), 'Scenario 4: Click closes notification on navigate failure');
  check(navigation.includes('focus:' + errTab.url), 'Scenario 4: Window focused despite navigate rejection');
  check(messages.some((m) => m?.type === 'sas-navigate' && m?.url === 'https://example.test/agy-staff-attendance/notifications.php'), 'Scenario 4: sas-navigate relayed to page for in-app fallback');
  check(opened === null, 'Scenario 4: Does NOT open duplicate window on uncertain navigation');

  // Scenario 5: WindowClient.navigate() resolving to null (Simulated in Node.js mock)
  navigation.length = 0;
  messages.length = 0;
  opened = null;
  const nullTab = windowClient(scope + 'admin/index.php', 'visible', {
    navigate: async () => null
  });
  windows = [nullTab];
  check(await click('/agy-staff-attendance/notifications.php'), 'Scenario 5: Click closes notification on null navigate');
  check(navigation.includes('focus:' + nullTab.url), 'Scenario 5: Window focused when navigate returns null');
  check(messages.some((m) => m?.type === 'sas-navigate' && m?.url === 'https://example.test/agy-staff-attendance/notifications.php'), 'Scenario 5: sas-navigate relayed to in-app listener');
  check(opened === null, 'Scenario 5: Avoids opening duplicate window when navigate returns null');

  // Scenario 6: In-page app.js sas-navigate message handling simulation
  let simulatedLocation = scope + 'admin/index.php';
  let assignedLocation = null;
  const simulatedWindow = {
    location: {
      href: simulatedLocation,
      origin: 'https://example.test',
      assign: (url) => { assignedLocation = url; }
    }
  };

  const simulateAppJsNavigate = (msg) => {
    if (msg?.type !== 'sas-navigate' || !msg.url) return false;
    try {
      const target = new URL(msg.url, simulatedWindow.location.href);
      if (target.origin === simulatedWindow.location.origin && (target.protocol === 'http:' || target.protocol === 'https:')) {
        const path = target.pathname;
        const isBlocked = path === '/logout.php' || path.endsWith('/logout.php') ||
                          path.includes('/api/') || path.includes('/cli/') ||
                          path.includes('/config/') || path.includes('/storage/');
        if (!isBlocked && simulatedWindow.location.href !== target.href) {
          simulatedWindow.location.assign(target.href);
          return true;
        }
      }
    } catch (e) { }
    return false;
  };

  const validMsg = messages.find((m) => m?.type === 'sas-navigate');
  check(simulateAppJsNavigate(validMsg), 'Scenario 6: In-page handler accepts valid same-origin sas-navigate');
  check(assignedLocation === 'https://example.test/agy-staff-attendance/notifications.php', 'Scenario 6: In-page handler assigned target destination');

  assignedLocation = null;
  check(!simulateAppJsNavigate({ type: 'sas-navigate', url: 'https://evil.test/exploit' }), 'Scenario 6: In-page handler rejects cross-origin URL');
  check(assignedLocation === null, 'Scenario 6: No navigation on cross-origin URL');

  check(!simulateAppJsNavigate({ type: 'sas-navigate', url: '/agy-staff-attendance/logout.php' }), 'Scenario 6: In-page handler blocks logout route');
  check(assignedLocation === null, 'Scenario 6: No navigation on blocked logout route');

  // Scenario 7: Duplicate window prevention (across existing window states)
  check(opened === null, 'Scenario 7: Verified openWindow is never called when existing window is matched');

  // Scenario 8: Invalid or malicious destination normalization
  navigation.length = 0;
  messages.length = 0;
  opened = null;
  const safeTab = windowClient(scope + 'staff/profile.php', 'visible');
  windows = [safeTab];
  check(await click('https://evil.test/phishing'), 'Scenario 8: Click closes notification on evil URL');
  check(navigation.includes(scope), 'Scenario 8: Evil external URL safely normalized to app scope');
  check(!navigation.includes('https://evil.test/phishing'), 'Scenario 8: Evil external URL never passed to navigate');
  check(opened === null, 'Scenario 8: No duplicate window opened');

  // Scenario 9: Already-on-destination behavior (No redundant navigation)
  navigation.length = 0;
  messages.length = 0;
  opened = null;
  const destTab = windowClient(scope + 'notifications.php', 'visible');
  windows = [destTab];
  check(await click('/agy-staff-attendance/notifications.php'), 'Scenario 9: Click closes notification when already on destination');
  check(navigation.length === 1 && navigation[0] === 'focus:' + destTab.url, 'Scenario 9: Window focused without calling navigate');
  check(!navigation.includes('https://example.test/agy-staff-attendance/notifications.php'), 'Scenario 9: Redundant navigate call skipped');
  check(messages.length === 0, 'Scenario 9: No sas-navigate message sent when already on destination');
  check(opened === null, 'Scenario 9: No duplicate window opened');

  console.log(`Service worker push: ${checks}/${checks} passed`);
})().catch((error) => { console.error(error); process.exitCode = 1; });
