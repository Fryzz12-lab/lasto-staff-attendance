/* Run: node tests/service_worker_cache_test.js */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '..', 'sw.js'), 'utf8');
assert.match(source, /CACHE_NAME\s*=\s*CACHE_PREFIX\s*\+\s*'v27'/, 'service-worker cache version advances');
assert.match(source, /\.\/assets\/vendor\/bootstrap-5\.3\.3\/css\/bootstrap\.min\.css/, 'Bootstrap CSS is precached');
assert.match(source, /\.\/assets\/vendor\/bootstrap-5\.3\.3\/js\/bootstrap\.bundle\.min\.js/, 'Bootstrap JS bundle is precached');
const handlers = {};
const names = new Set(['sas-static-v15', 'sas-static-v16', 'other-app-cache', 'sas-runtime-v1']);
const deleted = [];
const opened = [];
let dynamicCacheMatches = 0;
const caches = {
  keys: async () => [...names],
  delete: async (key) => { deleted.push(key); names.delete(key); return true; },
  open: async (key) => { opened.push(key); names.add(key); return { addAll: async () => {}, match: async (request) => { if (request.url.includes('/assets/uncached.js')) return null; dynamicCacheMatches++; return new Response('<button data-action="clock_in">Clock In</button>'); }, put: async () => {} }; },
};
const worker = {
  addEventListener: (name, handler) => { handlers[name] = handler; },
  skipWaiting: async () => {},
  clients: { claim: async () => {} },
};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '..', 'sw.js'), 'utf8'), {
  self: worker, caches, URL, Promise, console, fetch: async () => { throw new Error('offline'); }, Response,
});
(async () => {
  let checks = 0;
  const verify = (condition, label) => { assert.ok(condition, label); checks++; };
  let install;
  handlers.install({ waitUntil: (promise) => { install = promise; } });
  await install;
  verify(opened.includes('sas-static-v27'), 'Install opens the new AGY cache');
  verify(names.has('sas-static-v16'), 'Install does not delete prior cache');
  let activation;
  handlers.activate({ waitUntil: (promise) => { activation = promise; } });
  await activation;
  verify(deleted.includes('sas-static-v15') && deleted.includes('sas-static-v16'), 'Old AGY caches deleted');
  verify(names.has('sas-static-v27'), 'Current AGY cache retained');
  verify(names.has('other-app-cache') && names.has('sas-runtime-v1'), 'Unrelated caches preserved');
  verify(!deleted.includes('other-app-cache') && !deleted.includes('sas-runtime-v1'), 'Unrelated cache deletion never requested');
  let offlineNavigation;
  handlers.fetch({
    request: { method: 'GET', mode: 'navigate', destination: 'document', headers: new Headers({ Accept: 'text/html' }), url: 'https://example.test/staff/' },
    respondWith: (promise) => { offlineNavigation = promise; },
  });
  const offlinePage = await offlineNavigation;
  const offlineHtml = await offlinePage.text();
  verify(offlineHtml.includes('Attendance changes require server confirmation'), 'Offline navigation explains attendance cannot be confirmed or saved offline');
  verify(offlineHtml.includes('Retry connection') && offlineHtml.includes("addEventListener('online'"), 'Offline page retries on user action and a connectivity-change signal');
  verify(!offlineHtml.includes('data-action="clock_in"') && !offlineHtml.includes('data-action="clock_out"'), 'Offline fallback has no cached attendance controls');
  verify(dynamicCacheMatches === 0, 'Network failure does not reuse cached authenticated dashboard HTML');
  verify(!offlinePage.headers.has('Content-Length'), 'Offline fallback does not retain cached response length');
  let uncachedStaticAsset;
  handlers.fetch({
    request: { method: 'GET', mode: 'no-cors', destination: 'script', headers: new Headers({ Accept: '*/*' }), url: 'https://example.test/assets/uncached.js' },
    respondWith: (promise) => { uncachedStaticAsset = promise; },
  });
  const offlineStaticResponse = await uncachedStaticAsset;
  verify(offlineStaticResponse instanceof Response && offlineStaticResponse.status === 504, 'Uncached static asset with failed network returns a valid offline Response');
  console.log(`Service worker cache: ${checks}/${checks} passed`);
})().catch((error) => { console.error(error); process.exitCode = 1; });
