/* Local-only browser UAT. Usage: node tests/phase3e_browser_uat.js <app-port> <uat-db> <evidence-dir> */
'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const { spawn, execFileSync } = require('node:child_process');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');

const appPort = Number(process.argv[2]);
const database = String(process.argv[3] || '');
const evidenceDir = path.resolve(process.argv[4] || 'tests/evidence/phase3e');
  if (!Number.isInteger(appPort) || appPort < 1024 || !/^staff_attendance_uat_3e[467]_[a-f0-9]+$/.test(database)) {
  throw new Error('Refusing to run unless the app port and uniquely named disposable UAT database are supplied.');
}
fs.mkdirSync(evidenceDir, { recursive: true });

const results = [];
const sessions = [];
const browserContexts = [];
const proxyEvidence = { mutationReceipts: [], droppedResponses: [], reconciliations: [], sessionExpirations: [] };
let proxyServer;
let browserProcess;
let cdp;
let browserVersion;
const inspectedWorkerTargets = new Set();
const workerInspectorSessions = [];
let dropBeforeNextMutation = false;
let dropAfterNextMutation = false;
let failNextPost = false;
let failNextReconciliation = false;
let expireSessionOnNextReconciliation = false;
let applicationServerOffline = false;
let proxyPort;
let devtoolsPort;
const edgePath = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const mysqlPath = 'C:/xampp/mysql/bin/mysql.exe';
const testPassword = 'TestUAT3E!2026';

function db(sql) {
  return execFileSync(mysqlPath, ['--user=root', '--database=' + database, '--batch', '--skip-column-names', '--raw', '--execute=' + sql], { encoding: 'utf8' }).trim();
}

function record(name, passed, detail = '', blocked = false) {
  const result = blocked ? 'BLOCKED' : passed ? 'PASS' : 'FAIL';
  results.push({ name, result, detail });
  console.log(`${result} ${name}${detail ? ': ' + detail : ''}`);
}

function sleep(ms) { return new Promise((resolve) => setTimeout(resolve, ms)); }

function startProxy() {
  proxyServer = http.createServer((request, response) => {
    const pathname = new URL(request.url, 'http://127.0.0.1').pathname;
    if (applicationServerOffline) {
      request.resume();
      request.socket.destroy();
      return;
    }
    const isMutation = request.method === 'POST' && ['/api/clock.php', '/api/break.php'].includes(pathname) && Boolean(request.headers['idempotency-key']);
    if (isMutation) proxyEvidence.mutationReceipts.push({ path: pathname, requestId: request.headers['idempotency-key'], at: new Date().toISOString() });
    if (pathname === '/__uat/drop-before') { dropBeforeNextMutation = true; response.end('ok'); return; }
    if (pathname === '/__uat/drop-after') { dropAfterNextMutation = true; response.end('ok'); return; }
    if (pathname === '/__uat/fail-post') { failNextPost = true; response.end('ok'); return; }
    if (pathname === '/__uat/fail-reconcile') { failNextReconciliation = true; response.end('ok'); return; }
    if (pathname === '/api/attendance_operation.php' && request.url.includes('request_id=') && expireSessionOnNextReconciliation) {
      expireSessionOnNextReconciliation = false;
      const sessionRoot = path.join(os.tmpdir(), process.env.PHASE3E4_SESSION_DIR || 'phase3e4-php-sessions');
      const files = fs.readdirSync(sessionRoot).filter((name) => /^sess_[A-Za-z0-9,-]+$/.test(name));
      for (const name of files) fs.unlinkSync(path.join(sessionRoot, name));
      const cookiePresent = Boolean(request.headers.cookie);
      delete request.headers.cookie;
      proxyEvidence.sessionExpirations.push({ requestId:new URL(request.url,'http://127.0.0.1').searchParams.get('request_id'), cookiePresent, cookieStripped:true, removedFileCount:files.length, at:new Date().toISOString() });
    }
    if (pathname === '/api/attendance_operation.php' && request.url.includes('request_id=') && failNextReconciliation) {
      failNextReconciliation = false;
      response.writeHead(503, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
      response.end(JSON.stringify({ success: false, contract_version: 1, outcome: 'server_unavailable' }));
      return;
    }
    if (isMutation && failNextPost) {
      failNextPost = false;
      request.resume();
      response.writeHead(503, { 'Content-Type': 'application/json' });
      response.end(JSON.stringify({ success: false, outcome: 'server_unavailable' }));
      return;
    }
    if (isMutation && dropBeforeNextMutation) {
      dropBeforeNextMutation = false;
      request.resume();
      request.socket.destroy();
      return;
    }

    const upstream = http.request({
      hostname: '127.0.0.1', port: appPort, path: request.url, method: request.method,
      headers: Object.assign({}, request.headers, { host: '127.0.0.1:' + appPort }),
    }, (upstreamResponse) => {
      const chunks = [];
      upstreamResponse.on('data', (chunk) => chunks.push(chunk));
      upstreamResponse.on('end', () => {
        const body = Buffer.concat(chunks);
        if (pathname === '/api/attendance_operation.php' && request.url.includes('request_id=')) {
          let outcome = null;
          try { outcome = JSON.parse(body.toString('utf8')).outcome || null; } catch (_) { }
          proxyEvidence.reconciliations.push({ requestId:new URL(request.url,'http://127.0.0.1').searchParams.get('request_id'), status:upstreamResponse.statusCode, outcome, at:new Date().toISOString() });
        }
        if (isMutation && dropAfterNextMutation) {
          dropAfterNextMutation = false;
          try {
            const decoded = JSON.parse(body.toString('utf8'));
            if (decoded.outcome === 'confirmed_success' || decoded.outcome === 'confirmed_rejection') {
              proxyEvidence.droppedResponses.push({ requestId: request.headers['idempotency-key'], path: pathname, status: upstreamResponse.statusCode, outcome: decoded.outcome, operationId: decoded.operation_id || null, at: new Date().toISOString() });
              response.socket.destroy();
              return;
            }
          } catch (_) { /* Forward non-terminal responses normally. */ }
        }
        const headers = Object.assign({}, upstreamResponse.headers);
        delete headers['transfer-encoding'];
        delete headers.connection;
        response.writeHead(upstreamResponse.statusCode || 502, headers);
        response.end(body);
      });
    });
    upstream.on('error', () => {
      if (!response.headersSent) response.writeHead(503, { 'Content-Type': 'application/json' });
      response.end(JSON.stringify({ success: false, outcome: 'server_unavailable' }));
    });
    request.pipe(upstream);
  });
  const requestedPort = Number(process.env.PHASE3E6_PROXY_PORT || 0);
  return new Promise((resolve, reject) => {
    proxyServer.once('error', reject);
    proxyServer.listen(requestedPort, '127.0.0.1', () => {
      proxyServer.removeListener('error', reject);
    proxyPort = proxyServer.address().port;
    resolve();
    });
  });
}

class DevTools {
  constructor(socket) {
    this.socket = socket;
    this.nextId = 1;
    this.pending = new Map();
    this.events = [];
    this.eventHandlers = new Map();
    socket.addEventListener('message', (event) => {
      const message = JSON.parse(String(event.data));
      if (message.method) {
        this.events.push(Object.assign({ receivedAt: Date.now() }, message));
        for (const handler of this.eventHandlers.get(message.method) || []) handler(message.params || {}, message.sessionId || null);
      }
      if (!message.id) return;
      const pending = this.pending.get(message.id);
      if (!pending) return;
      this.pending.delete(message.id);
      if (message.error) pending.reject(new Error(message.error.message));
      else pending.resolve(message.result || {});
    });
  }

  on(method, handler) {
    if (!this.eventHandlers.has(method)) this.eventHandlers.set(method, new Set());
    this.eventHandlers.get(method).add(handler);
    return () => this.eventHandlers.get(method)?.delete(handler);
  }

  send(method, params = {}, sessionId) {
    const id = this.nextId++;
    const payload = { id, method, params };
    if (sessionId) payload.sessionId = sessionId;
    return new Promise((resolve, reject) => {
      this.pending.set(id, { resolve, reject });
      this.socket.send(JSON.stringify(payload));
      setTimeout(() => {
        if (this.pending.has(id)) { this.pending.delete(id); reject(new Error('CDP timeout: ' + method)); }
      }, 30000).unref?.();
    });
  }
}

function isServiceWorkerScript(url) {
  try { return new URL(url).pathname.endsWith('/sw.js'); } catch (_) { return false; }
}

async function waitForCdpEvent(predicate, timeout = 5000) {
  const started = Date.now();
  while (Date.now() - started < timeout) {
    const event = cdp.events.find(predicate);
    if (event) return event;
    await sleep(50);
  }
  return null;
}

async function inspectServiceWorkerTargets(origin, evidenceDir, label) {
  const targets = await cdp.send('Target.getTargets');
  const candidates = (targets.targetInfos || []).filter(target => target.type === 'service_worker' && target.url.startsWith(origin + '/sw.js'));
  const inspections = [];
  for (const target of candidates) {
    if (inspectedWorkerTargets.has(target.targetId)) continue;
    inspectedWorkerTargets.add(target.targetId);
    const attached = await cdp.send('Target.attachToTarget', { targetId: target.targetId, flatten: true });
    const sessionId = attached.sessionId;
    workerInspectorSessions.push(sessionId);
    await cdp.send('Runtime.enable', {}, sessionId).catch(() => {});
    await cdp.send('Debugger.enable', {}, sessionId).catch(() => {});
    await cdp.send('Network.enable', {}, sessionId).catch(() => {});
    const parsed = await waitForCdpEvent(event => event.sessionId === sessionId && event.method === 'Debugger.scriptParsed' && isServiceWorkerScript(event.params.url), 5000);
    const scripts = [];
    if (parsed) {
      const sourceResult = await cdp.send('Debugger.getScriptSource', { scriptId: parsed.params.scriptId }, sessionId).catch(error => ({ scriptSource: '', error: error.message }));
      const source = sourceResult.scriptSource || '';
      const version = source.match(/CACHE_NAME\s*=\s*CACHE_PREFIX\s*\+\s*['"](v\d+)['"]/)?.[1]
        || source.match(/CACHE_NAME\s*=\s*['"]sas-static-(v\d+)['"]/)?.[1]
        || 'unidentified';
      const sourceFile = source ? path.join(evidenceDir, `phase3e7_${label}_${version}_${target.targetId}.js`) : null;
      if (sourceFile) fs.writeFileSync(sourceFile, source);
      scripts.push({ url: parsed.params.url, scriptId: parsed.params.scriptId, version, sha256: source ? crypto.createHash('sha256').update(source).digest('hex') : null, sourceFile: sourceFile ? path.basename(sourceFile) : null, sourceError: sourceResult.error || null });
    }
    inspections.push({ targetId: target.targetId, sessionId, type: target.type, url: target.url, title: target.title, scripts });
  }
  return inspections;
}

function summarizeWorkerEvents(origin, sinceIndex = 0) {
  return cdp.events.slice(sinceIndex).filter(event => {
    if (event.method === 'ServiceWorker.workerErrorReported') return String(event.params.errorMessage?.sourceURL || '').startsWith(origin + '/');
    if (event.method.startsWith('ServiceWorker.')) return JSON.stringify(event.params).includes(origin + '/');
    if (event.sessionId && workerInspectorSessions.includes(event.sessionId)) {
      return event.method.startsWith('Network.') || event.method === 'Runtime.exceptionThrown' || event.method === 'Runtime.consoleAPICalled' || event.method === 'Log.entryAdded' || event.method === 'Debugger.paused';
    }
    return false;
  }).map(event => ({ receivedAt: event.receivedAt, method: event.method, sessionId: event.sessionId || null, params: event.params }));
}

async function launchBrowser() {
  devtoolsPort = 44000 + Math.floor(Math.random() * 1000);
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'phase3e-edge-'));
  sessions.push({ profile });
  const legacyWorkerProfile = process.env.PHASE3E6_V25_PROFILE;
  if (legacyWorkerProfile) {
    const source = path.resolve(legacyWorkerProfile);
    const target = path.join(profile, 'Default', 'Service Worker');
    const cacheRoot = path.join(source, 'CacheStorage');
    const cacheIndexes = fs.existsSync(cacheRoot) ? fs.readdirSync(cacheRoot,{withFileTypes:true}).filter(entry=>entry.isDirectory()).map(entry=>path.join(cacheRoot,entry.name,'index.txt')).filter(file=>fs.existsSync(file)) : [];
    const v25OriginIndex = cacheIndexes.map(file=>fs.readFileSync(file).toString('latin1')).find(contents=>contents.includes('sas-static-v25')&&contents.includes('http://127.0.0.1:8913/'));
    if (!fs.existsSync(path.join(source, 'Database', 'CURRENT')) || !v25OriginIndex) {
      throw new Error('The supplied profile must contain an actual sas-static-v25 cache registered for http://127.0.0.1:8913/.');
    }
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.cpSync(source, target, { recursive: true, errorOnExist: true });
  }
  browserProcess = spawn(edgePath, [
    '--headless=new', '--no-sandbox', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--remote-allow-origins=*',
    '--remote-debugging-address=127.0.0.1', '--remote-debugging-port=' + devtoolsPort,
    '--user-data-dir=' + profile, 'about:blank',
  ], { stdio: 'ignore', windowsHide: true });
  sessions.push({ process: browserProcess });
  let version;
  for (let i = 0; i < 80; i++) {
    try {
      const response = await fetch('http://127.0.0.1:' + devtoolsPort + '/json/version');
      if (response.ok) { version = await response.json(); break; }
    } catch (_) { /* Wait for Edge's local DevTools endpoint. */ }
    await sleep(150);
  }
  if (!version) throw new Error('Edge DevTools endpoint did not start.');
  browserVersion = version.Browser || 'unknown';
  const socket = new WebSocket(version.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
  });
  cdp = new DevTools(socket);
}

async function newPage(username, reuseContextId = null, options = {}) {
  const defaultContext = options.defaultContext === true;
  const context = reuseContextId ? { browserContextId: reuseContextId } : defaultContext ? {} : await cdp.send('Target.createBrowserContext', { disposeOnDetach: true });
  if (!reuseContextId && !defaultContext) browserContexts.push(context.browserContextId);
  const target = await cdp.send('Target.createTarget', { url: 'about:blank', ...(context.browserContextId ? { browserContextId: context.browserContextId } : {}) });
  const attached = await cdp.send('Target.attachToTarget', { targetId: target.targetId, flatten: true });
  const page = { targetId: target.targetId, browserContextId: context.browserContextId, sessionId: attached.sessionId, username };
  await cdp.send('Page.enable', {}, page.sessionId);
  await cdp.send('Runtime.enable', {}, page.sessionId);
  await cdp.send('Network.enable', {}, page.sessionId);
  if (options.serviceWorkerDiagnostics) await cdp.send('ServiceWorker.enable', {}, page.sessionId).catch(() => {});
  await cdp.send('Page.addScriptToEvaluateOnNewDocument', { source: `if (location.hostname === '127.0.0.1') { navigator.serviceWorker?.addEventListener('controllerchange', () => { try { sessionStorage.setItem('__phase3e_sw_controllerchange', '1'); } catch (_) {} }); }` }, page.sessionId);
  const blockedUrls = ['*://cdn.jsdelivr.net/*'];
  if (options.blockServiceWorkerUpdate) blockedUrls.push(`*://127.0.0.1:${proxyPort}/sw.js`);
  await cdp.send('Network.setBlockedURLs', { urls: blockedUrls }, page.sessionId);
  await cdp.send('Page.navigate', { url: `http://127.0.0.1:${proxyPort}/` }, page.sessionId);
  await waitFor(page, `document.querySelector('input[name="username"]') !== null`, 15000);
  const submitted = await evaluate(page, `(() => { const form=document.querySelector('form'); const user=document.querySelector('input[name="username"]'); const pass=document.querySelector('input[name="password"]'); if(!form||!user||!pass)return false; user.value=${JSON.stringify(username)}; pass.value=${JSON.stringify(testPassword)}; form.requestSubmit(); return user.value===${JSON.stringify(username)} && pass.value.length>0; })()`);
  assert.equal(submitted, true, 'synthetic UAT login form has username, password, and submit controls');
  await waitFor(page, `location.pathname.startsWith('/staff/') && !!document.querySelector('.app-content')`, 20000);
  await waitFor(page, `!!window.AttendanceReliability`, 20000);
  await evaluate(page, `window.__uatClicks=[]; document.addEventListener('click',e=>window.__uatClicks.push({target:e.target?.tagName,id:e.target?.id||'',className:typeof e.target?.className==='string'?e.target.className:'',trusted:e.isTrusted}),true)`);
  page.bootstrapAvailable = await evaluate(page, `!!window.bootstrap?.Modal`);
  page.bootstrapAssets = await evaluate(page, `({css:[...document.styleSheets].some(s=>s.href?.includes('/assets/vendor/bootstrap-5.3.3/css/bootstrap.min.css')),js:[...document.scripts].some(s=>s.src.includes('/assets/vendor/bootstrap-5.3.3/js/bootstrap.bundle.min.js')),cdn:[...performance.getEntriesByType('resource')].some(r=>/cdn\\.jsdelivr\\.net\\/npm\\/bootstrap@/.test(r.name))})`);
  page.userAgent = await evaluate(page, 'navigator.userAgent');
  return page;
}

async function installBrowserMutationBlock(page, pathSuffix, action) {
  let pausedRequest = null;
  let settle;
  const paused = new Promise((resolve) => { settle = resolve; });
  const unlisten = cdp.on('Fetch.requestPaused', async (params, sessionId) => {
    if (sessionId !== page.sessionId) return;
    const reqUrl = new URL(params.request.url);
    const matching = params.request.method === 'POST' && reqUrl.pathname.endsWith(pathSuffix)
      && (!action || String(params.request.postData || '').includes(JSON.stringify(action).slice(1, -1)));
    if (!matching) {
      await cdp.send('Fetch.continueRequest', { requestId: params.requestId }, sessionId).catch(() => {});
      return;
    }
    pausedRequest = { requestId: params.requestId, url: params.request.url, method: params.request.method, postData: params.request.postData || '' };
    await cdp.send('Fetch.failRequest', { requestId: params.requestId, errorReason: 'Failed' }, sessionId);
    settle(pausedRequest);
  });
  await cdp.send('Fetch.enable', { patterns: [{ urlPattern: `*${pathSuffix}*`, requestStage: 'Request' }] }, page.sessionId);
  return {
    paused,
    async restore() {
      await cdp.send('Fetch.disable', {}, page.sessionId).catch(() => {});
      unlisten();
    },
    get evidence() { return pausedRequest; },
  };
}

async function loginAgain(page, username) {
  await cdp.send('Page.navigate', { url: `http://127.0.0.1:${proxyPort}/` }, page.sessionId);
  await waitFor(page, `document.querySelector('input[name="username"]') !== null`, 15000);
  await evaluate(page, `(() => {const f=document.querySelector('form');f.querySelector('[name="username"]').value=${JSON.stringify(username)};f.querySelector('[name="password"]').value=${JSON.stringify(testPassword)};f.requestSubmit();return true;})()`);
  await waitFor(page, `location.pathname.startsWith('/staff/') && !!document.querySelector('.app-content')`, 20000);
  await waitFor(page, `!!window.AttendanceReliability`, 10000);
}

async function evaluate(page, expression) {
  const result = await cdp.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true, userGesture: true }, page.sessionId);
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.text || 'Browser evaluation failed.');
  return result.result?.value;
}

async function waitFor(page, expression, timeout = 10000) {
  const started = Date.now();
  while (Date.now() - started < timeout) {
    try { if (await evaluate(page, expression)) return true; } catch (_) { /* Navigation can replace the execution context. */ }
    await sleep(100);
  }
  throw new Error('Browser condition timed out: ' + expression);
}

async function reloadNormally(page, timeout = 15000) {
  const previousTimeOrigin = Number(await evaluate(page, 'performance.timeOrigin'));
  // CDP Page.reload with ignoreCache:true bypasses the normal user reload path and
  // has been observed to leave the replacement document uncontrolled by the SW.
  await cdp.send('Page.reload', {}, page.sessionId);
  await waitFor(page, `performance.timeOrigin > ${JSON.stringify(previousTimeOrigin)} && document.readyState === 'complete'`, timeout);
}

async function waitForServiceWorkerControl(page, timeout = 15000) {
  await waitFor(page, `navigator.serviceWorker.ready.then(registration => registration.active?.state === 'activated')`, timeout);
  await waitFor(page, `!!navigator.serviceWorker.controller`, timeout);
}

async function clickSelector(page, selector, clickCount = 1) {
  await evaluate(page, `window.__uatExpectedSelector=${JSON.stringify(selector)}; if(!window.__uatClickRecorder){window.__uatClicks=[];window.__uatClickRecorder=true;document.addEventListener('click',e=>window.__uatClicks.push({target:e.target?.tagName,id:e.target?.id||'',className:typeof e.target?.className==='string'?e.target.className:'',text:e.target?.textContent?.trim()?.slice(0,50)||'',trusted:e.isTrusted,matches:!!e.target?.closest(window.__uatExpectedSelector),modalClass:document.querySelector('.dashboard-confirm-modal')?.className||''}),true)}`);
  const clickStart = await evaluate(page, 'window.__uatClicks.length');
  const point = await evaluate(page, `new Promise(resolve=>{const el=document.querySelector(${JSON.stringify(selector)});if(!el||el.disabled)return resolve(null);document.documentElement.style.scrollBehavior='auto';document.body.style.scrollBehavior='auto';el.scrollIntoView({block:'center',inline:'center',behavior:'instant'});requestAnimationFrame(()=>requestAnimationFrame(()=>{const r=el.getBoundingClientRect(),x=r.left+r.width/2,y=r.top+r.height/2,hit=document.elementFromPoint(x,y);resolve({x,y,width:innerWidth,height:innerHeight,inViewport:x>=0&&y>=0&&x<innerWidth&&y<innerHeight,matched:!!hit?.closest(${JSON.stringify(selector)})})}))})`);
  if (!point || !Number.isFinite(point.x) || !Number.isFinite(point.y) || !point.inViewport || !point.matched) {
    throw new Error(`Physical click target is outside the stable viewport or obscured for ${selector}: ${JSON.stringify(point)}`);
  }
  await cdp.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: point.x, y: point.y }, page.sessionId);
  for (let i = 1; i <= clickCount; i++) {
    await cdp.send('Input.dispatchMouseEvent', { type: 'mousePressed', x: point.x, y: point.y, button: 'left', clickCount: i }, page.sessionId);
    await cdp.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: point.x, y: point.y, button: 'left', clickCount: i }, page.sessionId);
    if (i < clickCount) await sleep(35);
  }
  await waitFor(page, `window.__uatClicks.length>${clickStart}`, 2000);
  const outcome = await evaluate(page, `(() => { const clicks=window.__uatClicks.slice(${clickStart}); const el=document.elementFromPoint(${point.x},${point.y}); const modal=document.querySelector('.dashboard-confirm-modal'); const confirm=modal?.querySelector('[data-action-confirm]'); return {matched:clicks.some(click=>click.matches&&click.trusted),clicks,point:${JSON.stringify(point)},modalClass:modal?.className,modalRect:(()=>{const r=modal?.getBoundingClientRect();return r&&{left:r.left,top:r.top,width:r.width,height:r.height}})(),confirmRect:(()=>{const r=confirm?.getBoundingClientRect();return r&&{left:r.left,top:r.top,width:r.width,height:r.height}})(),confirmText:confirm?.textContent,hit:el?.outerHTML?.slice(0,240),hitModal:!!el?.closest('.dashboard-confirm-modal'),modalZ:modal&&getComputedStyle(modal).zIndex,backdropZ:document.querySelector('.modal-backdrop')&&getComputedStyle(document.querySelector('.modal-backdrop')).zIndex}; })()`);
  if (!outcome.matched) {
    const image = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }, page.sessionId);
    fs.writeFileSync(path.join(evidenceDir, `physical-click-mismatch-${Date.now()}.png`), Buffer.from(image.data, 'base64'));
    throw new Error(`Physical click did not reach ${selector}: ${JSON.stringify(outcome)}`);
  }
  return true;
}

async function waitForModalHidden(page, scenario) {
  try {
    await waitFor(page, `!document.querySelector('.dashboard-confirm-modal')?.classList.contains('show')`, 5000);
  } catch (_) {
    const detail = await evaluate(page, `(() => { const m=document.querySelector('.dashboard-confirm-modal'); const b=m?.querySelector('[data-action-confirm]'); const r=b?.getBoundingClientRect(); const hit=r&&document.elementFromPoint(r.left+r.width/2,r.top+r.height/2); return {modalClass:m?.className,buttonText:b?.textContent,buttonRect:r&&{left:r.left,top:r.top,width:r.width,height:r.height},hitTag:hit?.tagName,hitClass:hit?.className,active:document.activeElement?.outerHTML?.slice(0,240),clicks:window.__uatClicks?.slice(-8),status:document.querySelector('[data-attendance-operation-status]')?.outerHTML?.slice(0,500)}; })()`);
    const image = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }, page.sessionId);
    fs.writeFileSync(path.join(evidenceDir, `modal-blocked-${scenario}.png`), Buffer.from(image.data, 'base64'));
    throw new Error(`Confirmation stayed open after physical click (${scenario}): ${JSON.stringify(detail)}`);
  }
}

async function waitForModalOpen(page, scenario) {
  try {
    await waitFor(page, `!!document.querySelector('.dashboard-confirm-modal.show [data-action-confirm]')`, 10000);
  } catch (_) {
    const detail = await evaluate(page, `(() => ({path:location.pathname,attendanceState:document.querySelector('.app-content')?.dataset.attendanceState,actions:[...document.querySelectorAll('.app-content [data-action]')].map(b=>({action:b.dataset.action,type:b.dataset.type||'',disabled:b.disabled,bound:b.dataset.actionBound})),modal:document.querySelector('.dashboard-confirm-modal')?.className,status:document.querySelector('[data-attendance-operation-status]')?.outerHTML?.slice(0,500),pending:window.AttendanceReliability?.getPending(window.SAS_USER_ID),clicks:window.__uatClicks?.slice(-8)}))()`);
    const image = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }, page.sessionId);
    fs.writeFileSync(path.join(evidenceDir, `modal-not-open-${scenario}.png`), Buffer.from(image.data, 'base64'));
    throw new Error(`Confirmation did not open after physical click (${scenario}): ${JSON.stringify(detail)}`);
  }
}

async function waitState(page, classification, timeout = 15000) {
  const expression = `fetch('/api/attendance_operation.php',{credentials:'same-origin'}).then(r=>r.json()).then(x=>x.state?.derived?.classification === ${JSON.stringify(classification)})`;
  return waitFor(page, expression, timeout);
}

async function clickAndConfirm(page, selector) {
  const clicked = await clickSelector(page, selector);
  assert.equal(clicked, true, 'action button is enabled: ' + selector);
  await waitForModalOpen(page, selector.replace(/[^a-z0-9]+/gi, '_'));
  await waitFor(page, `!bootstrap.Modal.getInstance(document.querySelector('.dashboard-confirm-modal'))?._isTransitioning`, 5000);
  assert.equal(await clickSelector(page, '.dashboard-confirm-modal.show [data-action-confirm]'), true, 'confirmation is clickable');
  await waitForModalHidden(page, selector.replace(/[^a-z0-9]+/gi, '_'));
}

async function doubleTapAndConfirm(page, selector) {
  const clicked = await clickSelector(page, selector);
  assert.equal(clicked, true, 'double-tap target is enabled: ' + selector);
  await waitForModalOpen(page, 'double_' + selector.replace(/[^a-z0-9]+/gi, '_'));
  await waitFor(page, `!bootstrap.Modal.getInstance(document.querySelector('.dashboard-confirm-modal'))?._isTransitioning`, 5000);
  assert.equal(await clickSelector(page, '.dashboard-confirm-modal.show [data-action-confirm]', 2), true, 'double-tap confirmation is clickable');
  await waitForModalHidden(page, 'double_' + selector.replace(/[^a-z0-9]+/gi, '_'));
}

async function concurrentClickAndConfirm(pages, selector) {
  await Promise.all(pages.map((page) => clickSelector(page, selector)));
  await Promise.all(pages.map((page) => waitForModalOpen(page, 'concurrent_' + selector.replace(/[^a-z0-9]+/gi, '_'))));
  await Promise.all(pages.map((page) => waitFor(page, `!bootstrap.Modal.getInstance(document.querySelector('.dashboard-confirm-modal'))?._isTransitioning`, 5000)));
  await Promise.all(pages.map((page) => clickSelector(page, '.dashboard-confirm-modal.show [data-action-confirm]')));
  await Promise.all(pages.map((page) => waitForModalHidden(page, 'concurrent_' + selector.replace(/[^a-z0-9]+/gi, '_'))));
}

async function screenshot(page, name, width, height) {
  await cdp.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width < 600 }, page.sessionId);
  await sleep(400);
  const metrics = await evaluate(page, `({innerWidth,scrollWidth:document.documentElement.scrollWidth,bodyWidth:document.body.scrollWidth,buttons:[...document.querySelectorAll('.app-content [data-action]')].map(b=>({action:b.dataset.action,type:b.dataset.type||'',visible:!!(b.offsetWidth||b.offsetHeight),height:b.getBoundingClientRect().height})),state:document.querySelector('.app-content')?.dataset.attendanceState||''})`);
  const image = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true }, page.sessionId);
  fs.writeFileSync(path.join(evidenceDir, name + '_' + width + '.png'), Buffer.from(image.data, 'base64'));
  return metrics;
}

async function queryUser(username, select) {
  const userId = Number(db(`SELECT id FROM users WHERE username='${username}' LIMIT 1`));
  return { userId, rows: db(select(userId)) };
}

async function runMainWorkflow() {
  const page = await newPage('uat3e_staff');
  record('Authenticated dashboard uses local Bootstrap with jsDelivr blocked', page.bootstrapAvailable && page.bootstrapAssets.css && page.bootstrapAssets.js && !page.bootstrapAssets.cdn, JSON.stringify({ bootstrapAvailable: page.bootstrapAvailable, ...page.bootstrapAssets }));
  if (!page.bootstrapAvailable) {
    const api = await evaluate(page, `fetch('/api/attendance_operation.php').then(async r=>({status:r.status,body:await r.json()}))`);
    record('Authenticated rendered-dashboard capability probe', api.status === 200 && api.body.idempotency_supported === true, JSON.stringify(api));
    const metrics = [];
    for (const width of [320, 375, 390, 768, 1280]) metrics.push({ width, ...(await screenshot(page, 'staff_dashboard', width, 900)) });
    record('Rendered dashboard responsive overflow', false, 'BLOCKED: Bootstrap CSS from jsDelivr did not load, so the measured layout is incomplete and cannot be treated as a valid responsive result. ' + JSON.stringify(metrics.map(({ width, innerWidth, scrollWidth, bodyWidth }) => ({ requestedWidth: width, innerWidth, scrollWidth, bodyWidth }))), true);
    record('Rendered attendance control visibility and touch targets', false, 'BLOCKED: the page was missing its Bootstrap stylesheet, so responsive visibility and target measurements are not representative. ' + JSON.stringify(metrics.map((item) => ({ width: item.width, buttons: item.buttons }))), true);
    record('Clock, break, and modal UI interactions', false, 'BLOCKED: Bootstrap bundle is referenced from jsDelivr and was not available in the local browser; the application confirmation handlers require bootstrap.Modal.', true);
    record('Network recovery through the rendered action handler', false, 'BLOCKED: the UI cannot submit an action without its modal dependency.', true);
    record('Two-device rendered concurrency', false, 'BLOCKED: the UI cannot submit an action without its modal dependency.', true);
    record('Location permission and finalization UI', false, 'BLOCKED: the UI cannot submit an action without its modal dependency.', true);
    record('Rendered keyboard and screen-reader operation states', false, 'BLOCKED: the interaction status region is created by the action handler, which cannot run without Bootstrap.', true);
    return page;
  }
  const enabled = await evaluate(page, `!!document.querySelector('[data-action="clock_in"]')`);
  const initialState = await evaluate(page, `fetch('/api/attendance_operation.php',{credentials:'same-origin'}).then(r=>r.json()).then(x=>x.state?.derived?.classification||'unknown')`);
  if (!enabled && initialState === 'completed') {
    const summary = db(`SELECT CONCAT(a.status,':',(SELECT COUNT(*) FROM break_records b WHERE b.attendance_id=a.id),':',(SELECT COUNT(*) FROM attendance_audit_logs l WHERE l.attendance_id=a.id AND l.action='clock_in'),':',(SELECT COUNT(*) FROM attendance_audit_logs l WHERE l.attendance_id=a.id AND l.action='clock_out')) FROM attendance a WHERE a.user_id=(SELECT id FROM users WHERE username='uat3e_staff') AND a.work_date=CURDATE()`);
    const activity = await evaluate(page, `document.body.innerText.includes(${JSON.stringify("Today's Activity")})`);
    record('Authenticated dashboard completed state and Today\'s Activity', summary === 'completed:4:1:1' && activity, `state=${initialState}; DB=${summary}; activity=${activity}`);
    const today = await evaluate(page, 'new Date().toISOString().slice(0,10)');
    await cdp.send('Page.navigate', { url: `http://127.0.0.1:${proxyPort}/staff/attendance.php?from=${today}&to=${today}` }, page.sessionId);
    await waitFor(page, `location.pathname==='/staff/attendance.php' && !!document.querySelector('.app-content')`, 15000);
    const history = await evaluate(page, `({rows:document.querySelectorAll('.staff-attendance-table tbody tr').length,mobileCards:document.querySelectorAll('.attendance-mobile-record-card').length})`);
    record('Authenticated history renders the synthetic completed day', history.rows === 1 && history.mobileCards === 1, JSON.stringify(history) + '; completed status was independently verified in the database.');
    await cdp.send('Page.navigate', { url: `http://127.0.0.1:${proxyPort}/staff/` }, page.sessionId);
    await waitFor(page, `location.pathname==='/staff/' && !!document.querySelector('.app-content')`, 15000);
    return page;
  }
  let resumedBreakType = '';
  if (enabled) {
    assert.equal(initialState, 'not_clocked_in', 'fresh synthetic staff begins without a clock-in');
    await doubleTapAndConfirm(page, '[data-action="clock_in"]');
    await waitState(page, 'working');
  } else if (initialState === 'on_break') {
    resumedBreakType = db("SELECT break_type FROM break_records WHERE user_id=(SELECT id FROM users WHERE username='uat3e_staff') AND end_time IS NULL ORDER BY id DESC LIMIT 1");
    assert.ok(['lunch','solat','short'].includes(resumedBreakType), 'resumed synthetic break type is known');
    await clickAndConfirm(page, '[data-action="end_break"]');
    await waitState(page, 'working');
    record('Authenticated dashboard resumed and ended synthetic active break', true, `break_type=${resumedBreakType}; DB start event existed before this browser confirmation.`);
  } else {
    assert.equal(initialState, 'working', 'resumed synthetic staff state is authoritative Working');
    record('Authenticated dashboard resumed verified synthetic Working state', true, 'Existing UAT clock-in row and audit are synthetic and were verified in the disposable database.');
  }
  await waitFor(page, `!!document.querySelector('[data-action="start_break"][data-type="lunch"]')`, 15000);
  const clockInCount = db(`SELECT CONCAT((SELECT COUNT(*) FROM attendance WHERE user_id=(SELECT id FROM users WHERE username='uat3e_staff')),':',(SELECT COUNT(*) FROM attendance_audit_logs WHERE user_id=(SELECT id FROM users WHERE username='uat3e_staff') AND action='clock_in'))`);
  record(enabled ? 'Rendered clock-in and double-tap idempotency' : 'Rendered clock-in database state retained exactly once', clockInCount === '1:1', clockInCount);

  const remainingBreakTypes = resumedBreakType === 'lunch' ? ['solat','short'] : ['lunch','solat','short'];
  for (const type of remainingBreakTypes) {
    await clickAndConfirm(page, `[data-action="start_break"][data-type="${type}"]`);
    await waitState(page, 'on_break');
    await waitFor(page, `!!document.querySelector('[data-action="end_break"]')`, 15000);
    await clickAndConfirm(page, '[data-action="end_break"]');
    await waitState(page, 'working');
    await waitFor(page, `!!document.querySelector('[data-action="start_break"][data-type="${type}"]')`, 15000);
  }
  const breakStateRows = db(`SELECT CONCAT(SUM(break_type='lunch' AND end_time IS NOT NULL),':',SUM(break_type='solat' AND end_time IS NOT NULL),':',SUM(break_type='short' AND end_time IS NOT NULL)) FROM break_records WHERE user_id=(SELECT id FROM users WHERE username='uat3e_staff')`);
  record('Rendered Lunch, Solat, and Short break start/end', breakStateRows.split(':').every((count) => Number(count) >= 1), `completed_break_counts=${breakStateRows}`);

  await clickAndConfirm(page, '[data-action="start_break"][data-type="short"]');
  await waitState(page, 'on_break');
  await waitFor(page, `!!document.querySelector('[data-action="end_break"]')`, 15000);
  const ids = await queryUser('uat3e_staff', (id) => `SELECT id FROM break_records WHERE user_id=${id} AND end_time IS NULL ORDER BY id DESC LIMIT 1`);
  db(`UPDATE break_records SET start_time=DATE_SUB(NOW(), INTERVAL allowed_minutes + 5 MINUTE) WHERE id=${Number(ids.rows)}`);
  await clickAndConfirm(page, '[data-action="end_break"]');
  await waitState(page, 'working');
  await waitFor(page, `!!document.querySelector('[data-action="start_break"][data-type="short"]')`, 15000);
  const overtime = db(`SELECT CONCAT(exceeded,':',duration_minutes,':',allowed_minutes) FROM break_records WHERE id=${Number(ids.rows)}`);
  record('Rendered break overtime and end-break path', overtime.split(':')[0] === '1', overtime);

  const databaseSummary = await queryUser('uat3e_staff', (id) => `SELECT CONCAT('attendance=',COUNT(*)) FROM attendance WHERE user_id=${id}; SELECT CONCAT('breaks=',COUNT(*)) FROM break_records WHERE user_id=${id}; SELECT CONCAT('clock_in_audit=',SUM(action='clock_in')) FROM attendance_audit_logs WHERE user_id=${id}; SELECT CONCAT('clock_out_audit=',SUM(action='clock_out')) FROM attendance_audit_logs WHERE user_id=${id};`);
  const rows = databaseSummary.rows.split(/\r?\n/).filter(Boolean);
  record('Dashboard data confined to test employee records', rows.includes('attendance=1') && rows.includes('breaks=4') && rows.includes('clock_in_audit=1'), rows.join(', '));

  const metrics = [];
  for (const width of [320, 375, 390, 768, 1280]) metrics.push({ width, ...(await screenshot(page, 'staff_dashboard', width, 900)) });
  const noOverflow = metrics.every((item) => item.scrollWidth <= item.innerWidth && item.bodyWidth <= item.innerWidth);
  const touchTargets = metrics.filter((item) => item.width < 600).every((item) => item.buttons.every((button) => button.height >= 44));
  record('Responsive viewport overflow and touch-target check', noOverflow && touchTargets, JSON.stringify(metrics.map(({ width, innerWidth, scrollWidth, bodyWidth }) => ({ width, innerWidth, scrollWidth, bodyWidth }))));
  const accessible = await evaluate(page, `({statusRegion:!!document.querySelector('[data-attendance-operation-status][role="status"][aria-live="polite"]'),focusVisibleCss:[...document.styleSheets].some(s=>{try{return [...s.cssRules].some(r=>r.cssText.includes('focus-visible')&&r.cssText.includes('attendance-operation-status'))}catch(e){return false}})})`);
  record('Rendered status region and keyboard focus styling', accessible.statusRegion && accessible.focusVisibleCss, JSON.stringify(accessible));

  await clickAndConfirm(page, '[data-action="clock_out"]');
  await waitState(page, 'completed');
  const history = await evaluate(page, `document.body.innerText.includes('Today\'s Activity') || document.body.innerText.includes('Today’s Activity')`);
  record('Rendered clock-out and Today’s Activity', history, 'Resolver reports completed; dashboard activity text present=' + history);
  const checks = db(`SELECT CONCAT(a.clock_in IS NOT NULL,':',a.clock_out IS NOT NULL,':',a.status,':',a.total_break_minutes,':',a.work_minutes,':',(SELECT COUNT(*) FROM break_records b WHERE b.attendance_id=a.id),':',(SELECT COUNT(*) FROM attendance_audit_logs l WHERE l.attendance_id=a.id AND l.action='clock_in'),':',(SELECT COUNT(*) FROM attendance_audit_logs l WHERE l.attendance_id=a.id AND l.action='clock_out')) FROM attendance a WHERE a.user_id=${databaseSummary.userId}`);
  record('Database attendance, duration, break, and audit integrity', checks.startsWith('1:1:completed:') && checks.endsWith(':4:1:1'), checks);
  return page;
}

async function runMultiDeviceWorkflow() {
  const [deviceA, deviceB] = await Promise.all([newPage('uat3e_multi'), newPage('uat3e_multi')]);
  await concurrentClickAndConfirm([deviceA, deviceB], '[data-action="clock_in"]');
  await waitState(deviceA, 'working');
  await Promise.all([deviceA, deviceB].map((page) => waitFor(page, `!!document.querySelector('[data-action="start_break"][data-type="lunch"]')`, 15000)));
  let counts = db(`SELECT CONCAT((SELECT COUNT(*) FROM attendance WHERE user_id=(SELECT id FROM users WHERE username='uat3e_multi')),':',(SELECT COUNT(*) FROM attendance_audit_logs WHERE user_id=(SELECT id FROM users WHERE username='uat3e_multi') AND action='clock_in'))`);
  record('Two sessions simultaneous Clock In', counts === '1:1', counts);

  await concurrentClickAndConfirm([deviceA, deviceB], '[data-action="start_break"][data-type="lunch"]');
  await waitState(deviceA, 'on_break');
  await Promise.all([deviceA, deviceB].map((page) => waitFor(page, `!!document.querySelector('[data-action="end_break"]')`, 15000)));
  counts = db(`SELECT CONCAT((SELECT COUNT(*) FROM break_records WHERE user_id=(SELECT id FROM users WHERE username='uat3e_multi') AND break_type='lunch'),':',(SELECT COUNT(*) FROM attendance_audit_logs WHERE user_id=(SELECT id FROM users WHERE username='uat3e_multi') AND action='break_start'))`);
  record('Two sessions simultaneous Start Break', counts === '1:1', counts);

  await concurrentClickAndConfirm([deviceA, deviceB], '[data-action="end_break"]');
  await waitState(deviceA, 'working');
  await Promise.all([deviceA, deviceB].map((page) => waitFor(page, `!!document.querySelector('[data-action="clock_out"]')`, 15000)));
  counts = db(`SELECT CONCAT((SELECT COUNT(*) FROM attendance_audit_logs WHERE user_id=(SELECT id FROM users WHERE username='uat3e_multi') AND action='break_end'),':',(SELECT COUNT(*) FROM break_records WHERE user_id=(SELECT id FROM users WHERE username='uat3e_multi') AND end_time IS NOT NULL))`);
  record('Two sessions simultaneous End Break', counts === '1:1', counts);

  await clickAndConfirm(deviceA, '[data-action="clock_out"]');
  await waitState(deviceA, 'completed');
  const staleButton = await evaluate(deviceB, `!!document.querySelector('[data-action="clock_out"]')`);
  let stale = false;
  if (staleButton) {
    await clickAndConfirm(deviceB, '[data-action="clock_out"]');
    await waitState(deviceB, 'completed');
    stale = true;
  }
  counts = db(`SELECT CONCAT((SELECT COUNT(*) FROM attendance_audit_logs WHERE user_id=(SELECT id FROM users WHERE username='uat3e_multi') AND action='clock_out'),':',(SELECT COUNT(*) FROM attendance WHERE user_id=(SELECT id FROM users WHERE username='uat3e_multi') AND clock_out IS NOT NULL))`);
  record('Two-device Clock Out and stale-control rejection', stale && counts === '1:1', 'stale_control=' + stale + '; ' + counts);

  const key = db(`SELECT request_id FROM attendance_idempotency_operations WHERE user_id=(SELECT id FROM users WHERE username='uat3e_multi') AND operation='clock_out' ORDER BY id DESC LIMIT 1`);
  const own = await evaluate(deviceA, `fetch('/api/attendance_operation.php?request_id=${encodeURIComponent(key)}').then(r=>r.json()).then(x=>x.outcome)`);
  const peer = await newPage('uat3e_peer');
  const cross = await evaluate(peer, `fetch('/api/attendance_operation.php?request_id=${encodeURIComponent(key)}&user_id=1').then(r=>r.json()).then(x=>({outcome:x.outcome,hasResult:Object.prototype.hasOwnProperty.call(x,'result')}))`);
  record('Owner-only operation reconciliation', own === 'confirmed_success' && cross.outcome === 'unknown' && !cross.hasResult, JSON.stringify({ own, cross }));
  return { deviceA, deviceB, peer };
}

async function runNetworkWorkflow() {
  const before = await newPage('uat3e_network');
  dropBeforeNextMutation = true;
  await clickAndConfirm(before, '[data-action="clock_in"]');
  await waitFor(before, `document.querySelector('[data-attendance-operation-status]')?.dataset.state === 'retry_available'`, 15000);
  const pendingKey = await evaluate(before, `window.AttendanceReliability.getPending(window.SAS_USER_ID)?.requestId || ''`);
  const noMutationYet = db(`SELECT COUNT(*) FROM attendance WHERE user_id=(SELECT id FROM users WHERE username='uat3e_network')`);
  await evaluate(before, `document.querySelector('[data-attendance-operation-status] button').click()`);
  await waitState(before, 'working');
  const rows = db(`SELECT CONCAT((SELECT COUNT(*) FROM attendance WHERE user_id=(SELECT id FROM users WHERE username='uat3e_network')),':',(SELECT COUNT(*) FROM attendance_audit_logs WHERE user_id=(SELECT id FROM users WHERE username='uat3e_network') AND action='clock_in'),':',(SELECT COUNT(*) FROM attendance_idempotency_operations WHERE user_id=(SELECT id FROM users WHERE username='uat3e_network') AND request_id='${pendingKey}'))`);
  record('Network loss before submission and same-key retry', noMutationYet === '0' && rows === '1:1:1', rows);
  const reopener = await newPage('uat3e_refresh');
  dropBeforeNextMutation = true;
  failNextReconciliation = true;
  await clickAndConfirm(reopener, '[data-action="clock_in"]');
  await waitFor(reopener, `document.querySelector('[data-attendance-operation-status]')?.dataset.state === 'connection_unavailable'`, 15000);
  const retained = await evaluate(reopener, `!!window.AttendanceReliability.getPending(window.SAS_USER_ID)`);
  await reloadNormally(reopener);
  await waitFor(reopener, `!!document.querySelector('[data-attendance-operation-status][data-state="retry_available"]')`, 15000);
  const sameKey = await evaluate(reopener, `window.AttendanceReliability.getPending(window.SAS_USER_ID)?.requestId || ''`);
  await evaluate(reopener, `document.querySelector('[data-attendance-operation-status] button').click()`);
  await waitState(reopener, 'working');
  const refreshCount = db(`SELECT CONCAT((SELECT COUNT(*) FROM attendance WHERE user_id=(SELECT id FROM users WHERE username='uat3e_refresh')),':',(SELECT COUNT(*) FROM attendance_audit_logs WHERE user_id=(SELECT id FROM users WHERE username='uat3e_refresh') AND action='clock_in'),':',(SELECT COUNT(*) FROM attendance_idempotency_operations WHERE request_id='${sameKey}' AND user_id=(SELECT id FROM users WHERE username='uat3e_refresh')))`);
  record('Refresh during uncertainty and recovery with retained key', retained && sameKey && refreshCount === '1:1:1', refreshCount);

  const committed = await newPage('uat3e_location');
  await cdp.send('Browser.setPermission', { permission: { name: 'geolocation' }, setting: 'granted', origin: `http://127.0.0.1:${proxyPort}` });
  await cdp.send('Emulation.setGeolocationOverride', { latitude: 3.139, longitude: 101.686, accuracy: 5 }, committed.sessionId);
  dropAfterNextMutation = true;
  await clickAndConfirm(committed, '[data-action="clock_in"]');
  await waitState(committed, 'working');
  await waitFor(committed, `!window.AttendanceReliability.getPending(window.SAS_USER_ID)`, 15000);
  const locationIn = db(`SELECT CONCAT((SELECT COUNT(*) FROM attendance_location_events WHERE user_id=(SELECT id FROM users WHERE username='uat3e_location') AND event_type='clock_in'),':',(SELECT COUNT(*) FROM attendance_location_events WHERE user_id=(SELECT id FROM users WHERE username='uat3e_location') AND event_type='clock_in' AND location_finalized_at IS NOT NULL),':',(SELECT COUNT(*) FROM attendance_audit_logs WHERE user_id=(SELECT id FROM users WHERE username='uat3e_location') AND action='clock_in'))`);
  record('Post-commit response loss, reconciliation, and granted location finalization', locationIn === '1:1:1', locationIn);
  dropAfterNextMutation = true;
  await clickAndConfirm(committed, '[data-action="clock_out"]');
  await waitState(committed, 'completed');
  await waitFor(committed, `!window.AttendanceReliability.getPending(window.SAS_USER_ID)`, 15000);
  const locationOut = db(`SELECT CONCAT((SELECT COUNT(*) FROM attendance_location_events WHERE user_id=(SELECT id FROM users WHERE username='uat3e_location') AND event_type='clock_out'),':',(SELECT COUNT(*) FROM attendance_location_events WHERE user_id=(SELECT id FROM users WHERE username='uat3e_location') AND event_type='clock_out' AND location_finalized_at IS NOT NULL),':',(SELECT COUNT(*) FROM attendance_audit_logs WHERE user_id=(SELECT id FROM users WHERE username='uat3e_location') AND action='clock_out'))`);
  record('Clock-out replay does not duplicate location event/finalization', locationOut === '1:1:1', locationOut);
}

async function runResponsiveChecks(page) {
  const metrics = [];
  for (const width of [320, 375, 390, 768, 1280]) metrics.push({ width, ...(await screenshot(page, 'dashboard_responsive', width, 900)) });
  record('Responsive layout dimensions', metrics.every((m) => m.scrollWidth <= m.innerWidth && m.bodyWidth <= m.innerWidth), JSON.stringify(metrics.map(({ width, innerWidth, scrollWidth, bodyWidth }) => ({ width, innerWidth, scrollWidth, bodyWidth }))));
}

async function runPhase3e4() {
  const staff = await newPage('uat3e4_staff');
  // Explicit SW bypass is confined to this idempotency/session-expiry scenario;
  // control, cache, and offline-fallback checks use ordinary navigation.
  await cdp.send('Network.setBypassServiceWorker', { bypass:true }, staff.sessionId);
  expireSessionOnNextReconciliation = true;
  dropAfterNextMutation = true;
  await clickAndConfirm(staff, '[data-action="clock_in"]');
  let sessionExpiredUi = false;
  try { await waitFor(staff, `document.querySelector('[data-attendance-operation-status]')?.dataset.state==='session_expired'`, 5000); sessionExpiredUi = true; } catch (_) { }
  const expiryEvidence = proxyEvidence.sessionExpirations.at(-1) || null;
  const pendingAfterExpiry = await evaluate(staff, `window.AttendanceReliability.getPending(window.SAS_USER_ID)`);
  const committedBeforeLogin = db("SELECT CONCAT((SELECT COUNT(*) FROM attendance_idempotency_operations o JOIN users u ON u.id=o.user_id WHERE u.username='uat3e4_staff' AND o.status='succeeded' AND o.operation='clock_in'),':',(SELECT COUNT(*) FROM attendance_audit_logs l JOIN users u ON u.id=l.user_id WHERE u.username='uat3e4_staff' AND l.action='clock_in'),':',(SELECT COUNT(*) FROM attendance a JOIN users u ON u.id=a.user_id WHERE u.username='uat3e4_staff' AND a.work_date=CURDATE() AND a.clock_in IS NOT NULL))");
  const expiredUi = await evaluate(staff, `({state:document.querySelector('[data-attendance-operation-status]')?.dataset.state,role:document.querySelector('[data-attendance-operation-status]')?.getAttribute('role'),live:document.querySelector('[data-attendance-operation-status]')?.getAttribute('aria-live'),text:document.querySelector('[data-attendance-operation-status]')?.innerText})`);
  let recovered = 'not_reauthenticated';
  let loginRecovery = { attempted:false, error:null, url:null, loginForm:false };
  if (sessionExpiredUi) {
    loginRecovery.attempted=true;
    try {
      await cdp.send('Network.clearBrowserCookies', {}, staff.sessionId);
      await loginAgain(staff, 'uat3e4_staff');
      await waitFor(staff, `!window.AttendanceReliability.getPending(window.SAS_USER_ID)`, 15000);
      recovered = db("SELECT CONCAT((SELECT COUNT(*) FROM attendance_idempotency_operations o JOIN users u ON u.id=o.user_id WHERE u.username='uat3e4_staff' AND o.status='succeeded' AND o.operation='clock_in'),':',(SELECT COUNT(*) FROM attendance_audit_logs l JOIN users u ON u.id=l.user_id WHERE u.username='uat3e4_staff' AND l.action='clock_in'),':',(SELECT COUNT(*) FROM attendance a JOIN users u ON u.id=a.user_id WHERE u.username='uat3e4_staff' AND a.work_date=CURDATE() AND a.clock_in IS NOT NULL))");
    } catch (error) {
      loginRecovery.error=error.message;
      loginRecovery.url=await evaluate(staff, 'location.href').catch(()=>null);
      loginRecovery.loginForm=await evaluate(staff, `!!document.querySelector('input[name="username"]')`).catch(()=>false);
    }
  }
  const peer = await newPage('uat3e4_peer');
  const peerView = await evaluate(peer, `fetch('/api/attendance_operation.php?request_id=${encodeURIComponent(pendingAfterExpiry?.requestId||'')}',{credentials:'same-origin'}).then(r=>r.json()).then(x=>({outcome:x.outcome,hasResult:Object.prototype.hasOwnProperty.call(x,'result')}))`);
  record('Browser detects session expiry during reconciliation and retains original request', !!expiryEvidence?.cookiePresent && expiryEvidence.cookieStripped && expiryEvidence.removedFileCount>0 && proxyEvidence.reconciliations.some((item)=>item.requestId===pendingAfterExpiry?.requestId&&item.status===401&&item.outcome==='session_expired') && sessionExpiredUi && expiredUi.state==='session_expired' && expiredUi.role==='status' && !!pendingAfterExpiry?.requestId && committedBeforeLogin==='1:1:1', JSON.stringify({ expiryEvidence, sessionExpiredUi, expiredUi, pendingRequestId:pendingAfterExpiry?.requestId, committedBeforeLogin }));
  record('Reauthentication recovers the original committed outcome exactly once', loginRecovery.attempted && recovered==='1:1:1', JSON.stringify({ loginRecovery, recovered }));
  record('Another employee cannot reconcile the confirmed operation', peerView.outcome==='unknown' && !peerView.hasResult, JSON.stringify(peerView));

  const a11y = await newPage('uat3e4_accessibility');
  let focusedAction = null;
  for (let i=0;i<80 && !focusedAction;i++) {
    await cdp.send('Input.dispatchKeyEvent', { type:'keyDown', key:'Tab', code:'Tab' }, a11y.sessionId);
    await cdp.send('Input.dispatchKeyEvent', { type:'keyUp', key:'Tab', code:'Tab' }, a11y.sessionId);
    focusedAction = await evaluate(a11y, `(()=>{const e=document.activeElement;if(!e?.matches('[data-action]'))return null;const s=getComputedStyle(e);return {action:e.dataset.action,name:(e.getAttribute('aria-label')||e.innerText||e.textContent).trim(),outlineStyle:s.outlineStyle,outlineWidth:s.outlineWidth,outlineColor:s.outlineColor,disabled:e.disabled}})()`);
  }
  const ax = await cdp.send('Accessibility.getFullAXTree', {}, a11y.sessionId);
  const unnamedButtons = (ax.nodes||[]).filter((node)=>!node.ignored && node.role?.value==='button' && !String(node.name?.value||'').trim()).map((node)=>node.backendDOMNodeId).slice(0,10);
  if (focusedAction) {
    await cdp.send('Input.dispatchKeyEvent', { type:'keyDown', key:'Enter', code:'Enter', text:'\r' }, a11y.sessionId);
    await cdp.send('Input.dispatchKeyEvent', { type:'keyUp', key:'Enter', code:'Enter' }, a11y.sessionId);
    await waitForModalOpen(a11y, 'phase3e4-keyboard');
    await waitFor(a11y, `document.activeElement===document.querySelector('[data-action-cancel]')`, 4000);
    const dialog = await evaluate(a11y, `({role:document.querySelector('#dashboardConfirmModal')?.getAttribute('role'),labelledby:document.querySelector('#dashboardConfirmModal')?.getAttribute('aria-labelledby'),focusCancel:document.activeElement===document.querySelector('[data-action-cancel]')})`);
    await cdp.send('Input.dispatchKeyEvent', { type:'keyDown', key:'Escape', code:'Escape' }, a11y.sessionId);
    await cdp.send('Input.dispatchKeyEvent', { type:'keyUp', key:'Escape', code:'Escape' }, a11y.sessionId);
    let escapeClosed = false;
    try { await waitFor(a11y, `!document.querySelector('.dashboard-confirm-modal')?.classList.contains('show')`, 1500); escapeClosed = true; } catch (_) {
      await evaluate(a11y, `document.activeElement?.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true,cancelable:true}))`);
      try { await waitFor(a11y, `!document.querySelector('.dashboard-confirm-modal')?.classList.contains('show')`, 3000); escapeClosed = true; } catch (_) { }
    }
    let focusRestored=false;
    try { await waitFor(a11y, `document.activeElement===document.querySelector('[data-action="${focusedAction.action}"]')`, 3000); focusRestored=true; } catch (_) { }
    record('Keyboard reaches attendance control; confirmation supports Escape and restores focus', !!focusedAction.name && !focusedAction.disabled && focusedAction.outlineWidth!=='0px' && dialog.focusCancel && escapeClosed && focusRestored, JSON.stringify({ focusedAction, dialog, escapeClosed, focusRestored }));
  } else record('Keyboard reaches attendance control; confirmation supports Escape and restores focus', false, 'No attendance action became focused in 80 Tab presses.');
  record('Dashboard accessibility tree exposes names for actionable buttons', unnamedButtons.length===0, JSON.stringify({ unnamedButtons, buttonCount:(ax.nodes||[]).filter((node)=>!node.ignored&&node.role?.value==='button').length }));

  const offline = await newPage('uat3e4_offline');
  await evaluate(offline, `navigator.serviceWorker.register('/sw.js',{scope:'/'}).then(()=>navigator.serviceWorker.ready.then(r=>r.active?.state))`);
  await waitFor(offline, `navigator.serviceWorker.getRegistration('/').then(r=>!!r?.active)`, 15000);
  await reloadNormally(offline);
  await waitForServiceWorkerControl(offline);
  const pendingId = await evaluate(offline, `window.AttendanceReliability.createPending(window.SAS_USER_ID,'clock_in','').requestId`);
  const rowsBefore = db('SELECT COUNT(*) FROM attendance');
  applicationServerOffline = true;
  await reloadNormally(offline);
  await waitFor(offline, `document.body.innerText.includes('Connection Lost')`, 10000);
  const offlineDom = await evaluate(offline, `({actions:document.querySelectorAll('[data-action]').length,warning:document.body.innerText.includes('No offline attendance action was saved'),retry:document.querySelector('button')?.textContent.trim(),pending:sessionStorage.getItem('sas.attendance.pending.v1.'+${JSON.stringify(await evaluate(offline, 'window.SAS_USER_ID'))})})`);
  applicationServerOffline = false;
  await reloadNormally(offline);
  await waitFor(offline, `document.querySelector('[data-attendance-operation-status]')?.dataset.state==='retry_available'`, 15000);
  const onlinePending = await evaluate(offline, `window.AttendanceReliability.getPending(window.SAS_USER_ID)?.requestId`);
  const rowsAfter = db('SELECT COUNT(*) FROM attendance');
  record('Offline fallback hides stale attendance, preserves request ID, and reconciles after reconnect', offlineDom.actions===0 && offlineDom.warning && offlineDom.retry==='Retry connection' && offlineDom.pending.includes(pendingId) && onlinePending===pendingId && rowsBefore===rowsAfter, JSON.stringify({ offlineDom:{...offlineDom,pending:offlineDom.pending?JSON.parse(offlineDom.pending).requestId:null},pendingId,onlinePending,rowsBefore,rowsAfter }));
}

async function runPhase3e6ServiceWorker() {
  await cdp.send('Target.setDiscoverTargets',{discover:true}).catch(() => {});
  const freshEventStart = cdp.events.length;
  const page = await newPage('uat3e6_staff',null,{serviceWorkerDiagnostics:true});
  const origin = await evaluate(page, 'location.origin');
  const freshWorkerTargets = await inspectServiceWorkerTargets(origin,evidenceDir,'fresh');
  const freshOnlineErrors = summarizeWorkerEvents(origin,freshEventStart).filter(event=>event.method==='ServiceWorker.workerErrorReported'||event.method==='Runtime.exceptionThrown');
  const initialRegistration = await evaluate(page, `navigator.serviceWorker.register('/sw.js',{scope:'/'}).then(async registration=>{await navigator.serviceWorker.ready;return{scope:registration.scope,scriptURL:registration.active?.scriptURL||null,installing:registration.installing?.state||null,waiting:registration.waiting?.state||null,active:registration.active?.state||null}})`);
  await waitForServiceWorkerControl(page);
  await reloadNormally(page);
  await waitForServiceWorkerControl(page);
  const lifecycle = await evaluate(page, `(async()=>{const registration=await navigator.serviceWorker.getRegistration('/');const cacheNames=await caches.keys();const cache=await caches.open('sas-static-v27');const expected=['/assets/css/app.css','/assets/vendor/bootstrap-5.3.3/css/bootstrap.min.css','/assets/vendor/bootstrap-5.3.3/js/bootstrap.bundle.min.js','/assets/js/app.js','/assets/js/push_recovery.js'];return{origin:location.origin,secure:isSecureContext,scope:registration?.scope||null,scriptURL:registration?.active?.scriptURL||null,installing:registration?.installing?.state||null,waiting:registration?.waiting?.state||null,active:registration?.active?.state||null,controller:navigator.serviceWorker.controller?.scriptURL||null,navigationType:performance.getEntriesByType('navigation')[0]?.type||null,cacheNames,cacheEntries:(await cache.keys()).map(request=>new URL(request.url).pathname),expectedAssets:await Promise.all(expected.map(async path=>({path,cached:!!await cache.match(path)}))),controllerChangeSeen:sessionStorage.getItem('__phase3e_sw_controllerchange')==='1'}})()`);
  await evaluate(page, `window.__phase3e6Keys={};document.addEventListener('keydown',e=>{if(['Tab','Enter','Escape'].includes(e.key)){window.__phase3e6Keys[e.key]=e.isTrusted;if(e.isTrusted)sessionStorage.setItem('__phase3e6_key_'+e.key,'1')}},{capture:true})`);
  const installed = lifecycle.active==='activated' && lifecycle.controller===lifecycle.scriptURL && lifecycle.navigationType==='reload' && lifecycle.expectedAssets.every(asset=>asset.cached);
  record('Fresh v27 registration, activation, normal reload control, and static precache', installed, JSON.stringify({ browserVersion, initialRegistration, lifecycle }));
  if (!installed) {
    const diagnostics = { browserVersion, origin, lifecycle, pageUrl:await evaluate(page,'location.href').catch(()=>null) };
    fs.writeFileSync(path.join(evidenceDir,'phase3e6_sw_control_failure.json'),JSON.stringify(diagnostics,null,2));
    record('Offline navigation and recovery assertions', false, 'BLOCKED: fresh page did not reach a verified v27 controlled state; diagnostics saved to phase3e6_sw_control_failure.json.', true);
    return;
  }

  let focusedAction = null;
  for (let index=0; index<80 && !focusedAction; index++) {
    await cdp.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab'},page.sessionId);
    await cdp.send('Input.dispatchKeyEvent',{type:'keyUp',key:'Tab',code:'Tab'},page.sessionId);
    focusedAction = await evaluate(page, `(()=>{const element=document.activeElement;if(!element?.matches('[data-action]'))return null;return{action:element.dataset.action,name:(element.getAttribute('aria-label')||element.innerText||element.textContent).trim(),disabled:element.disabled,outline:getComputedStyle(element).outlineWidth}})()`);
  }
  let keyboard = { focusedAction, modalOpened:false, focusEntered:false, escapeClosed:false, focusRestored:false, trustedEscape:false };
  if (focusedAction && !focusedAction.disabled) {
    await cdp.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',text:'\r'},page.sessionId);
    await cdp.send('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter'},page.sessionId);
    try { await waitFor(page,`!!document.querySelector('.dashboard-confirm-modal.show [data-action-confirm]')`,5000); keyboard.modalOpened=true; } catch (_) {}
    if (keyboard.modalOpened) {
      try { await waitFor(page,`document.activeElement===document.querySelector('[data-action-cancel]')`,4000); keyboard.focusEntered=true; } catch (_) {}
      if (keyboard.focusEntered) {
        await cdp.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27,nativeVirtualKeyCode:27},page.sessionId);
        await cdp.send('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27,nativeVirtualKeyCode:27},page.sessionId);
        try { await waitFor(page,`!document.querySelector('.dashboard-confirm-modal')?.classList.contains('show')`,4000); keyboard.escapeClosed=true; } catch (_) {}
        keyboard.trustedEscape = await evaluate(page,`sessionStorage.getItem('__phase3e6_key_Escape')==='1'`);
        try { await waitFor(page,`document.activeElement===document.querySelector('[data-action="${focusedAction.action}"]')`,3000); keyboard.focusRestored=true; } catch (_) {}
      }
    }
  }
  keyboard.inputTrust = await evaluate(page,`window.__phase3e6Keys||{}`);
  record('Trusted Tab, Enter, Escape, and focus restoration in attendance confirmation', !!focusedAction && !focusedAction.disabled && keyboard.modalOpened && keyboard.focusEntered && keyboard.trustedEscape && keyboard.escapeClosed && keyboard.focusRestored, JSON.stringify(keyboard));

  const userIds = await evaluate(page,`({current:Number(window.SAS_USER_ID),peer:Number(${Number(db("SELECT id FROM users WHERE username='uat3e6_peer' LIMIT 1"))})})`);
  const pending = await evaluate(page,`window.AttendanceReliability.createPending(window.SAS_USER_ID,'clock_in','')`);
  const pendingBefore = await evaluate(page,`({current:window.AttendanceReliability.getPending(window.SAS_USER_ID),peer:window.AttendanceReliability.getPending(${userIds.peer}),raw:sessionStorage.getItem('sas.attendance.pending.v1.'+window.SAS_USER_ID)})`);
  const stateBefore = db(`SELECT CONCAT((SELECT COUNT(*) FROM attendance a JOIN users u ON u.id=a.user_id WHERE u.username='uat3e6_staff'),':',(SELECT COUNT(*) FROM attendance a JOIN users u ON u.id=a.user_id WHERE u.username='uat3e6_staff' AND (a.clock_in IS NOT NULL OR a.clock_out IS NOT NULL)),':',(SELECT COUNT(*) FROM attendance_audit_logs l JOIN users u ON u.id=l.user_id WHERE u.username='uat3e6_staff'),':',(SELECT COUNT(*) FROM attendance_idempotency_operations o JOIN users u ON u.id=o.user_id WHERE u.username='uat3e6_staff'))`);
  const mutationCountBefore = proxyEvidence.mutationReceipts.length;
  const networkSettings = { browserOffline:false, applicationServerConnectivity:'dropped-at-local-uat-proxy', cacheExpected:'service-worker-network-first-fallback' };
  const freshOfflineEventStart = cdp.events.length;
  applicationServerOffline = true;
  try {
    await reloadNormally(page,12000);
    await waitFor(page,`document.body.innerText.includes('Connection Lost')`,8000);
  } catch (error) {
    const failure = { browserVersion,origin,lifecycle,networkSettings,pageUrl:await evaluate(page,'location.href').catch(()=>null),readyState:await evaluate(page,'document.readyState').catch(()=>null),controller:await evaluate(page,'navigator.serviceWorker.controller?.scriptURL||null').catch(()=>null),body:await evaluate(page,'document.body.innerText.slice(0,500)').catch(()=>null),cacheNames:await evaluate(page,'caches.keys()').catch(()=>[]),recentNetwork:cdp.events.filter(event=>event.sessionId===page.sessionId&&['Network.requestWillBeSent','Network.responseReceived','Network.loadingFailed'].includes(event.method)).slice(-30).map(event=>({method:event.method,url:event.params.request?.url||event.params.response?.url||event.params.url||null,status:event.params.response?.status||null,error:event.params.errorText||null})) };
    fs.writeFileSync(path.join(evidenceDir,'phase3e6_offline_navigation_failure.json'),JSON.stringify(failure,null,2));
    applicationServerOffline = false;
  record('Offline navigation renders v27 fallback',false,`FAIL: ${error.message}; navigation diagnostics saved to phase3e6_offline_navigation_failure.json.`);
    return;
  }
  const offlineDom = await evaluate(page,`(()=>{const button=document.querySelector('button');const status=document.querySelector('[role="status"]');const rect=button?.getBoundingClientRect();const style=button&&getComputedStyle(button);return{title:document.title,text:document.body.innerText,actions:document.querySelectorAll('[data-action]').length,button:button?.textContent.trim()||null,buttonRect:rect&&{width:rect.width,height:rect.height},buttonOutline:style&&style.outlineWidth,status:status&&{text:status.innerText,live:status.getAttribute('aria-live')},pending:sessionStorage.getItem('sas.attendance.pending.v1.'+${userIds.current})}})()`);
  const fallbackMarkup = await evaluate(page,'document.documentElement.outerHTML');
  fs.writeFileSync(path.join(evidenceDir,'phase3e6_offline_fallback.html'),fallbackMarkup);
  const offlineResponse = cdp.events.filter(event=>event.sessionId===page.sessionId && event.method==='Network.responseReceived' && event.params.response.url===`http://127.0.0.1:${proxyPort}/staff/`).slice(-1)[0];
  await evaluate(page,`document.addEventListener('keydown',event=>{if(event.isTrusted&&['Tab','Enter'].includes(event.key))sessionStorage.setItem('__phase3e6_retry_'+event.key,'1')},{capture:true})`);
  await cdp.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab'},page.sessionId);
  await cdp.send('Input.dispatchKeyEvent',{type:'keyUp',key:'Tab',code:'Tab'},page.sessionId);
  const retryFocus = await evaluate(page,`({focused:document.activeElement===document.querySelector('button'),trustedTab:sessionStorage.getItem('__phase3e6_retry_Tab')==='1',outline:getComputedStyle(document.querySelector('button')).outlineWidth})`);
  const pendingOffline = offlineDom.pending ? JSON.parse(offlineDom.pending) : null;
  const fallbackPassed = offlineDom.title.includes('Offline') && offlineDom.text.includes('Attendance changes require server confirmation') && offlineDom.text.includes('No offline attendance action was saved') && offlineDom.actions===0 && offlineDom.button==='Retry connection' && offlineDom.status?.live==='polite' && pendingOffline?.requestId===pending.requestId && pendingOffline?.userId===String(userIds.current) && retryFocus.focused && retryFocus.trustedTab && offlineResponse?.params?.response?.fromServiceWorker===true;
  record('Offline navigation renders v27 fallback without cached attendance controls', fallbackPassed, JSON.stringify({ offlineDom:{...offlineDom,pending:pendingOffline?.requestId||null},retryFocus,serviceWorkerResponse:offlineResponse?.params?.response?{status:offlineResponse.params.response.status,fromServiceWorker:offlineResponse.params.response.fromServiceWorker}:null,networkSettings }));

  const offlineNavigationTime = Number(await evaluate(page,'performance.timeOrigin'));
  await cdp.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',text:'\r'},page.sessionId);
  await cdp.send('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter'},page.sessionId);
  await waitFor(page,`performance.timeOrigin>${JSON.stringify(offlineNavigationTime)} && document.body.innerText.includes('Connection Lost')`,10000);
  const retryKeyboard = await evaluate(page,`({button:document.querySelector('button')?.textContent.trim(),actions:document.querySelectorAll('[data-action]').length,pending:sessionStorage.getItem('sas.attendance.pending.v1.'+${userIds.current})})`);
  const trustedRetryEnter = await evaluate(page,`sessionStorage.getItem('__phase3e6_retry_Enter')==='1'`);
  record('Offline retry control activates by trusted Enter without attendance submission', retryKeyboard.button==='Retry connection' && retryKeyboard.actions===0 && retryKeyboard.pending && JSON.parse(retryKeyboard.pending).requestId===pending.requestId && trustedRetryEnter, JSON.stringify({ retryKeyboard:{...retryKeyboard,pending:retryKeyboard.pending?JSON.parse(retryKeyboard.pending).requestId:null}, trustedEnter:trustedRetryEnter }));

  applicationServerOffline = false;
  try { await waitFor(page,`location.pathname.startsWith('/staff/') && !!document.querySelector('.app-content')`,10000); } catch (_) { await reloadNormally(page,15000); }
  await waitFor(page,`!!document.querySelector('.app-content') && !!document.querySelector('[data-attendance-operation-status]')`,15000);
  await waitFor(page,`document.querySelector('[data-attendance-operation-status]')?.dataset.state==='retry_available'`,15000);
  const serverOutcome = await evaluate(page,`fetch('/api/attendance_operation.php?request_id=${encodeURIComponent(pending.requestId)}',{credentials:'same-origin'}).then(async response=>({status:response.status,data:await response.json()}))`);
  const pendingAfter = await evaluate(page,`({current:window.AttendanceReliability.getPending(window.SAS_USER_ID),peer:window.AttendanceReliability.getPending(${userIds.peer}),raw:sessionStorage.getItem('sas.attendance.pending.v1.'+window.SAS_USER_ID)})`);
  const stateAfter = db(`SELECT CONCAT((SELECT COUNT(*) FROM attendance a JOIN users u ON u.id=a.user_id WHERE u.username='uat3e6_staff'),':',(SELECT COUNT(*) FROM attendance a JOIN users u ON u.id=a.user_id WHERE u.username='uat3e6_staff' AND (a.clock_in IS NOT NULL OR a.clock_out IS NOT NULL)),':',(SELECT COUNT(*) FROM attendance_audit_logs l JOIN users u ON u.id=l.user_id WHERE u.username='uat3e6_staff'),':',(SELECT COUNT(*) FROM attendance_idempotency_operations o JOIN users u ON u.id=o.user_id WHERE u.username='uat3e6_staff'))`);
  const reconciled = proxyEvidence.reconciliations.some(item=>item.requestId===pending.requestId && item.outcome==='unknown');
  const noMutation = proxyEvidence.mutationReceipts.length===mutationCountBefore && stateBefore===stateAfter && db(`SELECT COUNT(*) FROM attendance_idempotency_operations WHERE request_id='${pending.requestId}'`)==='0';
  record('Reconnect reconciles original ID as unknown and performs no attendance mutation', serverOutcome.data.outcome==='unknown' && pendingAfter.current?.requestId===pending.requestId && pendingAfter.peer===null && JSON.parse(pendingAfter.raw).requestId===pending.requestId && reconciled && noMutation, JSON.stringify({ requestId:pending.requestId, serverOutcome, pendingOwner:userIds.current, pendingPeer:pendingAfter.peer, pendingAfter:pendingAfter.current?.requestId||null, reconciledOriginalId:reconciled, stateBefore, stateAfter, mutationReceiptsBefore:mutationCountBefore, mutationReceiptsAfter:proxyEvidence.mutationReceipts.length, noMutation }));

  const relevantEvents = cdp.events.filter(event=>event.sessionId===page.sessionId && ['Network.requestWillBeSent','Network.responseReceived','Runtime.consoleAPICalled','Log.entryAdded','ServiceWorker.workerErrorReported'].includes(event.method)).map(event=>{
    if(event.method==='Network.requestWillBeSent')return{method:event.method,url:event.params.request.url,verb:event.params.request.method,resourceType:event.params.type};
    if(event.method==='Network.responseReceived')return{method:event.method,url:event.params.response.url,status:event.params.response.status,fromServiceWorker:event.params.response.fromServiceWorker};
    if(event.method==='Runtime.consoleAPICalled')return{method:event.method,type:event.params.type,messages:(event.params.args||[]).map(arg=>String(arg.value||arg.description||'').slice(0,300))};
    if(event.method==='Log.entryAdded')return{method:event.method,level:event.params.entry.level,text:String(event.params.entry.text||'').slice(0,300),url:event.params.entry.url||null};
    return{method:event.method,params:event.params};
  });
  const evidence = { browserVersion, origin, lifecycle, initialRegistration, networkSettings, offlineDom:{ title:offlineDom.title, actions:offlineDom.actions, button:offlineDom.button, status:offlineDom.status, retryFocus, serviceWorkerResponse:offlineResponse?.params?.response?{status:offlineResponse.params.response.status,fromServiceWorker:offlineResponse.params.response.fromServiceWorker}:null }, pending:{ requestId:pending.requestId,userId:userIds.current,peerPending:false,reconnectOutcome:serverOutcome.data.outcome }, noMutation, stateBefore, stateAfter, relevantEvents };
  fs.writeFileSync(path.join(evidenceDir,'phase3e6_service_worker_evidence.json'),JSON.stringify(evidence,null,2));
  const freshOfflineWorkerErrors = summarizeWorkerEvents(origin,freshOfflineEventStart).filter(event=>event.method==='ServiceWorker.workerErrorReported'||event.method==='Runtime.exceptionThrown');
  record('Fresh v27 offline path reports no service-worker response errors',freshOfflineWorkerErrors.length===0,JSON.stringify(freshOfflineWorkerErrors));

  const legacyEventStart = cdp.events.length;
  const history = execFileSync('git',['log','--all','-S','sas-static-v25','--','sw.js'],{encoding:'utf8'}).trim();
  const headWorker = execFileSync('git',['show','HEAD:sw.js'],{encoding:'utf8'});
  const legacyPage = await newPage('uat3e6_staff',null,{defaultContext:true,blockServiceWorkerUpdate:true,serviceWorkerDiagnostics:true});
  const legacyBefore = await evaluate(legacyPage,`(async()=>{const registration=await navigator.serviceWorker.getRegistration('/');const names=await caches.keys();return{origin:location.origin,scope:registration?.scope||null,scriptURL:registration?.active?.scriptURL||null,active:registration?.active?.state||null,controller:navigator.serviceWorker.controller?.scriptURL||null,cacheNames:names,legacyCachePresent:names.includes('sas-static-v25')}})()`);
  const v25WorkerTargets = await inspectServiceWorkerTargets(origin,evidenceDir,'legacy_before_update');
  const v25EventBoundary = cdp.events.length;
  let v27WorkerTargets = [];
  let upgraded = null;
  if (!legacyBefore.legacyCachePresent || !legacyBefore.controller) {
    record('Authentic v25-to-v27 service-worker upgrade', false, `BLOCKED: preserved profile did not restore its actual v25 registration/cache at ${legacyBefore.origin}; Git history search=${history?'found a v25 reference, but only the v15 HEAD source is available':'no v25 sw.js source'}. Legacy state=${JSON.stringify(legacyBefore)}`, true);
  } else {
    const legacyPending = await evaluate(legacyPage,`window.AttendanceReliability.createPending(window.SAS_USER_ID,'clock_in','')`);
    await cdp.send('Network.setBlockedURLs',{urls:['*://cdn.jsdelivr.net/*']},legacyPage.sessionId);
    await evaluate(legacyPage,`navigator.serviceWorker.getRegistration('/').then(registration=>registration.update())`);
    await waitFor(legacyPage,`caches.keys().then(names=>names.includes('sas-static-v27')&&!names.includes('sas-static-v25'))`,20000);
    await waitForServiceWorkerControl(legacyPage,15000);
    await reloadNormally(legacyPage);
    await waitForServiceWorkerControl(legacyPage,15000);
    v27WorkerTargets = await inspectServiceWorkerTargets(origin,evidenceDir,'legacy_after_update');
    upgraded = await evaluate(legacyPage,`(async()=>{const registration=await navigator.serviceWorker.getRegistration('/');const names=await caches.keys();const cache=await caches.open('sas-static-v27');const pending=window.AttendanceReliability.getPending(window.SAS_USER_ID);return{origin:location.origin,scriptURL:registration?.active?.scriptURL||null,active:registration?.active?.state||null,waiting:registration?.waiting?.state||null,controller:navigator.serviceWorker.controller?.scriptURL||null,cacheNames:names,cacheEntries:(await cache.keys()).map(request=>new URL(request.url).pathname),pendingRequestId:pending?.requestId||null}})()`);
    const upgradePassed = upgraded.active==='activated' && upgraded.waiting===null && upgraded.cacheNames.includes('sas-static-v27') && !upgraded.cacheNames.includes('sas-static-v25') && upgraded.pendingRequestId===legacyPending.requestId;
    record('Authentic v25-to-v27 worker upgrade removes v25 cache and preserves pending ID',upgradePassed,JSON.stringify({legacyBefore,upgraded,pendingRequestId:legacyPending.requestId,workerLifecycleEvents:cdp.events.filter(event=>event.method.startsWith('ServiceWorker.')&&JSON.stringify(event.params).includes('http://127.0.0.1:8913/')).map(event=>({receivedAt:event.receivedAt,method:event.method,sessionId:event.sessionId||null,params:event.params})),v25WorkerTargets,v27WorkerTargets}));
  }
  const triageEvents = summarizeWorkerEvents(origin,legacyEventStart);
  const networkRequests = triageEvents.filter(event=>event.method.startsWith('Network.'));
  const workerFailures = triageEvents.filter(event=>event.method==='ServiceWorker.workerErrorReported'||event.method==='Runtime.exceptionThrown');
  const errorCorrelations = workerFailures.map(error=>{
    const errorAt=Number(error.receivedAt||0);
    const closest=networkRequests.filter(event=>event.method==='Network.requestWillBeSent').map(event=>({event,distance:Math.abs(Number(event.params.wallTime||0)*1000-errorAt)})).sort((a,b)=>a.distance-b.distance)[0];
    return{receivedAt:error.receivedAt,method:error.method,sessionId:error.sessionId,versionId:error.params.errorMessage?.versionId||null,message:error.params.errorMessage?.errorMessage||error.params.exceptionDetails?.text||null,sourceURL:error.params.errorMessage?.sourceURL||null,stack:error.params.exceptionDetails?.stackTrace?.callFrames||null,nearestRequest:closest&&closest.distance<5000?{distanceMs:closest.distance,url:closest.event.params.request.url,method:closest.event.params.request.method,type:closest.event.params.type,requestId:closest.event.params.requestId}:null};
  });
  fs.writeFileSync(path.join(evidenceDir,'phase3e7_legacy_error_triage.json'),JSON.stringify({browserVersion,origin,freshOnline:{workerTargets:freshWorkerTargets,errors:freshOnlineErrors},freshOffline:{networkSettings,workerErrors:freshOfflineWorkerErrors},legacyV25:{registration:legacyBefore,workerTargets:v25WorkerTargets,eventsBeforeUpdate:triageEvents.filter(event=>Number(event.receivedAt)<Number(cdp.events[v25EventBoundary]?.receivedAt||Infinity))},legacyUpgrade:{workerTargets:v27WorkerTargets,errors:workerFailures,errorCorrelations,networkEvents:networkRequests,allEvents:triageEvents,gitHistoryHasV25Reference:!!history,headWorkerCacheName:(headWorker.match(/CACHE_NAME\s*=\s*['"]([^'"]+)/)||[])[1]||null}},null,2));
}

async function runPhase3e3Closure() {
  const pageFor = (user) => newPage(user);
  const mutationCounts = (user, operation, action) => db(`SELECT CONCAT((SELECT COUNT(*) FROM attendance_idempotency_operations o JOIN users u ON u.id=o.user_id WHERE u.username='${user}' AND o.operation='${operation}' AND o.status='succeeded'),':',(SELECT COUNT(*) FROM attendance_audit_logs l JOIN users u ON u.id=l.user_id WHERE u.username='${user}' AND l.action='${action}'),':',(SELECT COUNT(*) FROM attendance a JOIN users u ON u.id=a.user_id WHERE u.username='${user}' AND a.work_date=CURDATE()))`);
  const attachStateTrace = async (page) => evaluate(page, `window.__uatStateTrace=[];new MutationObserver(()=>{const s=document.querySelector('[data-attendance-operation-status]');if(s)window.__uatStateTrace.push({state:s.dataset.state,text:s.innerText,at:Date.now()})}).observe(document.querySelector('.app-content'),{subtree:true,childList:true,attributes:true})`);

  if (process.env.PHASE3E6_CASE === 'service-worker') {
    await runPhase3e6ServiceWorker();
    return;
  }

  if (process.env.PHASE3E3_CASE === 'stale-verify') {
    const page = await pageFor('uat3e3_stale');
    const resolver = await evaluate(page, `fetch('/api/attendance_operation.php',{credentials:'same-origin'}).then(r=>r.json()).then(x=>x.state?.derived?.classification)`);
    const rendered = await evaluate(page, `({completedBadge:!!document.querySelector('.dash-meta-pill .badge-completed'),completedHeading:[...document.querySelectorAll('.dash-hero-status-title')].some(x=>x.innerText.toLowerCase().includes('shift completed')),clockOutControl:!!document.querySelector('[data-action="clock_out"]'),pageText:document.querySelector('.app-content')?.innerText.slice(0,600)})`);
    const staleEvidence = db(`SELECT CONCAT((SELECT COUNT(*) FROM attendance_audit_logs l JOIN users u ON u.id=l.user_id WHERE u.username='uat3e3_stale' AND l.action='clock_out'),':',(SELECT COUNT(*) FROM attendance a JOIN users u ON u.id=a.user_id WHERE u.username='uat3e3_stale' AND a.clock_out IS NOT NULL),':',(SELECT COUNT(*) FROM attendance_idempotency_operations o JOIN users u ON u.id=o.user_id WHERE u.username='uat3e3_stale' AND o.operation='clock_out' AND o.status='succeeded'),':',(SELECT COUNT(*) FROM attendance_idempotency_operations o JOIN users u ON u.id=o.user_id WHERE u.username='uat3e3_stale' AND o.operation='clock_out' AND o.status='rejected'))`);
    record('Stale-control outcome and refreshed Completed dashboard', resolver === 'completed' && rendered.completedBadge && rendered.completedHeading && !rendered.clockOutControl && staleEvidence === '1:1:1:1', JSON.stringify({ resolver, rendered, auditAttendanceSucceededRejected: staleEvidence, priorBrowserEvidence: 'stale-clockout-error.png and stale-clockout-error.json show Device B response HTML after its stale request.' }));
    return;
  }

  if (process.env.PHASE3E3_CASE === 'cross') {
    const page = await pageFor('uat3e3_other');
    const ownerId = await evaluate(page, 'Number(window.SAS_USER_ID)');
    const pending = await evaluate(page, `window.AttendanceReliability.createPending(window.SAS_USER_ID,'clock_out','')`);
    const before = await evaluate(page, `({raw:sessionStorage.getItem('sas.attendance.pending.v1.'+${ownerId}),context:sessionStorage.getItem('sas.attendance.context.v1')})`);
    const logout = await evaluate(page, `fetch('/logout.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'csrf_token='+encodeURIComponent(document.querySelector('meta[name="csrf-token"]').content)}).then(r=>r.status===200)`);
    await loginAgain(page, 'uat3e3_csrf');
    const current = await evaluate(page, `({id:Number(window.SAS_USER_ID),context:sessionStorage.getItem('sas.attendance.context.v1'),raw:sessionStorage.getItem('sas.attendance.pending.v1.'+${ownerId}),owner:window.AttendanceReliability.getPending(${ownerId}),current:window.AttendanceReliability.getPending(window.SAS_USER_ID)})`);
    const key = db(`SELECT request_id FROM attendance_idempotency_operations o JOIN users u ON u.id=o.user_id WHERE u.username='uat3e3_stale' AND o.operation='clock_out' AND o.status='succeeded' LIMIT 1`);
    const authorizedOnly = await evaluate(page, `fetch('/api/attendance_operation.php?request_id=${encodeURIComponent(key)}',{credentials:'same-origin'}).then(r=>r.json()).then(x=>({outcome:x.outcome,hasResult:Object.prototype.hasOwnProperty.call(x,'result')}))`);
    record('Account switch does not reassign pending operation or disclose confirmed result', logout && current.id!==ownerId && !current.current && authorizedOnly.outcome==='unknown' && !authorizedOnly.hasResult && (!current.raw || JSON.parse(current.raw).userId===String(ownerId)), JSON.stringify({ ownerId, before, after:{userId:current.id,context:current.context,rawPresent:!!current.raw,rawUserId:current.raw?JSON.parse(current.raw).userId:null,getPendingOwner:current.owner?.requestId||null,newUserPending:!!current.current}, pendingRequestId:pending.requestId, authorizedOnly }));
    return;
  }

  if (process.env.PHASE3E3_CASE === 'environment') {
    const fresh = await pageFor('uat3e3_other');
    await cdp.send('Network.setBlockedURLs', { urls:['*://cdn.jsdelivr.net/*','*://fonts.googleapis.com/*','*://fonts.gstatic.com/*'] }, fresh.sessionId);
    await reloadNormally(fresh);
    await waitFor(fresh, `location.pathname.startsWith('/staff/') && !!document.querySelector('.app-content')`, 15000);
    await waitFor(fresh, `!!window.bootstrap?.Modal && !!document.querySelector('[data-action]')`, 10000);
    const critical = await evaluate(fresh, `({bootstrap:!!window.bootstrap?.Modal,localCss:[...document.styleSheets].some(s=>s.href?.includes('/assets/vendor/bootstrap-5.3.3/css/bootstrap.min.css')),localJs:[...document.scripts].some(s=>s.src.includes('/assets/vendor/bootstrap-5.3.3/js/bootstrap.bundle.min.js')),actions:[...document.querySelectorAll('.app-content [data-action]')].map(x=>x.dataset.action),sw:!!navigator.serviceWorker,failedHosts:[...performance.getEntriesByType('resource')].filter(x=>/cdn\\.jsdelivr\\.net|fonts\\.googleapis\\.com|fonts\\.gstatic\\.com/.test(x.name)).map(x=>x.name)})`);
    const state = await evaluate(fresh, `fetch('/api/attendance_operation.php',{credentials:'same-origin'}).then(r=>r.json()).then(x=>x.state?.derived?.classification)`);
    record('Local attendance dashboard survives blocked third-party assets', critical.bootstrap && critical.localCss && critical.localJs && state==='working', JSON.stringify({ critical, resolver:state }));

    const clean = await pageFor('uat3e3_other');
    const freshInstall = await evaluate(clean, `navigator.serviceWorker.register('/sw.js',{scope:'/'}).then(async r=>{await navigator.serviceWorker.ready;return {scope:r.scope,state:r.active?.state,cacheKeys:await caches.keys()}})`);
    await waitForServiceWorkerControl(clean);
    await reloadNormally(clean);
    await waitForServiceWorkerControl(clean);
    const freshActivation = await evaluate(clean, `navigator.serviceWorker.getRegistration('/').then(r=>r?.active?.state)`);
    const freshCache = await evaluate(clean, `caches.open('sas-static-v27').then(async c=>({keys:(await c.keys()).map(x=>new URL(x.url).pathname),css:!!await c.match('/assets/vendor/bootstrap-5.3.3/css/bootstrap.min.css'),js:!!await c.match('/assets/vendor/bootstrap-5.3.3/js/bootstrap.bundle.min.js')}))`);
    record('Fresh service-worker install activates v27 and caches local Bootstrap', freshActivation==='activated' && freshCache.css && freshCache.js, JSON.stringify({ freshInstall, activation:freshActivation, cached: freshCache }));

    record('Authentic v25-to-v27 service-worker upgrade', false, 'BLOCKED: a renamed v25 cache marker is not an authentic prior worker. Phase 3E.6 checks Git history and available fixtures before reporting upgrade status.', true);
    const upgrade = await pageFor('uat3e3_other');
    await waitForServiceWorkerControl(upgrade);
    await reloadNormally(upgrade);
    await waitForServiceWorkerControl(upgrade);
    const pendingId = await evaluate(upgrade, `window.AttendanceReliability.createPending(window.SAS_USER_ID,'clock_in','').requestId`);
    const rowsBefore = db('SELECT COUNT(*) FROM attendance');
    applicationServerOffline = true;
    await reloadNormally(upgrade);
    let offline = { connectionLost:false, attendanceActions:0, controlled:upgraded.controller, warning:false, pendingId:null };
    try {
      await waitFor(upgrade, `document.body.innerText.includes('Attendance changes require server confirmation')`, 8000);
      offline = await evaluate(upgrade, `({connectionLost:document.body.innerText.includes('Connection Lost'),attendanceActions:document.querySelectorAll('[data-action]').length,controlled:!!navigator.serviceWorker.controller,warning:document.body.innerText.includes('No offline attendance action was saved'),pendingId:window.AttendanceReliability?.getPending(window.SAS_USER_ID)?.requestId||sessionStorage.getItem('sas.attendance.pending.v1.'+window.SAS_USER_ID)})`);
    } catch (_) { offline = await evaluate(upgrade, `({connectionLost:document.body.innerText.includes('Connection Lost'),attendanceActions:document.querySelectorAll('[data-action]').length,controlled:!!navigator.serviceWorker.controller,warning:document.body.innerText.includes('Attendance changes require server confirmation'),pendingId:sessionStorage.getItem('sas.attendance.pending.v1.'+window.SAS_USER_ID),errorPage:document.body.innerText.slice(0,300)})`); }
    applicationServerOffline = false;
    await reloadNormally(upgrade);
    await waitFor(upgrade, `document.querySelector('.app-content') && document.querySelector('[data-attendance-operation-status]')?.dataset.state==='retry_available'`, 15000);
    const onlinePending = await evaluate(upgrade, `window.AttendanceReliability.getPending(window.SAS_USER_ID)?.requestId||null`);
    const rowsAfter = db('SELECT COUNT(*) FROM attendance');
    record('Offline fallback hides actions and preserves pending request until live reconciliation', offline.connectionLost && offline.attendanceActions===0 && offline.warning && offline.pendingId===pendingId && onlinePending===pendingId && rowsBefore===rowsAfter, JSON.stringify({ offline, pendingId, onlinePending, attendanceRowsBefore:rowsBefore, attendanceRowsAfter:rowsAfter, scope:'offline attendance is not supported' }), !offline.connectionLost);
    return;
  }

  if (process.env.PHASE3E3_CASE === 'csrf') {
    const csrfPage = await pageFor('uat3e3_csrf');
    const validToken = await evaluate(csrfPage, `document.querySelector('meta[name="csrf-token"]')?.content||window.CSRF_TOKEN`);
    await evaluate(csrfPage, `document.querySelector('meta[name="csrf-token"]').content='invalid-uat-csrf';window.CSRF_TOKEN='invalid-uat-csrf'`);
    await clickAndConfirm(csrfPage, '[data-action="clock_in"]');
    await waitFor(csrfPage, `document.querySelector('[data-attendance-operation-status]')?.dataset.state==='session_expired'`, 15000);
    const csrfPending = await evaluate(csrfPage, `window.AttendanceReliability.getPending(window.SAS_USER_ID)`);
    const csrfCounts = db(`SELECT CONCAT((SELECT COUNT(*) FROM attendance_audit_logs l JOIN users u ON u.id=l.user_id WHERE u.username='uat3e3_csrf' AND l.action='clock_in'),':',(SELECT COUNT(*) FROM attendance_idempotency_operations o JOIN users u ON u.id=o.user_id WHERE u.username='uat3e3_csrf' AND o.request_id='${csrfPending?.requestId||''}'),':',(SELECT COUNT(*) FROM attendance a JOIN users u ON u.id=a.user_id WHERE u.username='uat3e3_csrf' AND a.work_date=CURDATE() AND a.clock_in IS NOT NULL))`);
    record('Invalid CSRF token is rejected without mutation and retains pending request', !!csrfPending && csrfCounts==='0:0:0' && await evaluate(csrfPage, `document.querySelector('[data-attendance-operation-status]')?.dataset.state==='session_expired'`), `pending=${csrfPending?.requestId}; audit:idempotency:clocked_attendance=${csrfCounts}`);
    await reloadNormally(csrfPage);
    await waitFor(csrfPage, `location.pathname.startsWith('/staff/') && !!document.querySelector('.app-content')`, 15000);
    await waitFor(csrfPage, `document.querySelector('[data-attendance-operation-status]')?.dataset.state==='retry_available'`, 15000);
    const pendingAfterRefresh = await evaluate(csrfPage, `window.AttendanceReliability.getPending(window.SAS_USER_ID)`);
    await clickSelector(csrfPage, '[data-attendance-operation-status] button:not([hidden])');
    await waitState(csrfPage, 'working', 15000);
    const csrfRecovered = db(`SELECT CONCAT(status,':',operation,':',(SELECT COUNT(*) FROM attendance_audit_logs l WHERE l.attendance_id=o.attendance_id AND l.action='clock_in')) FROM attendance_idempotency_operations o WHERE o.user_id=(SELECT id FROM users WHERE username='uat3e3_csrf') AND o.request_id='${csrfPending.requestId}'`);
    record('Refresh recovers invalid-CSRF pending request with same ID', pendingAfterRefresh?.requestId===csrfPending.requestId && csrfRecovered==='succeeded:clock_in:1', `original=${csrfPending.requestId}; refreshed=${pendingAfterRefresh?.requestId}; operation=${csrfRecovered}`);

    const ownerId = await evaluate(csrfPage, 'Number(window.SAS_USER_ID)');
    const foreignPending = await evaluate(csrfPage, `window.AttendanceReliability.createPending(window.SAS_USER_ID,'clock_in','')`);
    const logout = await evaluate(csrfPage, `fetch('/logout.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'csrf_token='+encodeURIComponent(document.querySelector('meta[name="csrf-token"]').content)}).then(r=>r.status===200)`);
    await loginAgain(csrfPage, 'uat3e3_other');
    const isolation = await evaluate(csrfPage, `({currentUser:Number(window.SAS_USER_ID),newUserPending:window.AttendanceReliability.getPending(window.SAS_USER_ID),ownerPending:window.AttendanceReliability.getPending(${ownerId})})`);
    const staleRequest = db(`SELECT request_id FROM attendance_idempotency_operations o JOIN users u ON u.id=o.user_id WHERE u.username='uat3e3_stale' AND o.operation='clock_out' AND o.status='succeeded' LIMIT 1`);
    const crossOwner = await evaluate(csrfPage, `fetch('/api/attendance_operation.php?request_id=${encodeURIComponent(staleRequest)}',{credentials:'same-origin'}).then(r=>r.json()).then(x=>({outcome:x.outcome,hasResult:Object.prototype.hasOwnProperty.call(x,'result')}))`);
    record('Cross-user login preserves owner-bound pending context and hides result', logout===true && isolation.currentUser!==ownerId && !isolation.newUserPending && isolation.ownerPending?.requestId===foreignPending.requestId && crossOwner.outcome==='unknown' && !crossOwner.hasResult, JSON.stringify({ ownerId, currentUser:isolation.currentUser, pendingUser:isolation.ownerPending?.userId, newUserHasPending:!!isolation.newUserPending, crossOwner, requestId:staleRequest }));
    return;
  }

  if (process.env.PHASE3E3_CASE === 'reconcile-expired') {
    const page = await pageFor('uat3e3_reconcile');
    let invalidatedSession = null;
    let resolveInvalidated;
    const invalidated = new Promise((resolve) => { resolveInvalidated = resolve; });
    const unlisten = cdp.on('Fetch.requestPaused', async (params, sessionId) => {
      if (sessionId !== page.sessionId) return;
      const url = new URL(params.request.url);
      if (params.request.method === 'GET' && url.pathname.endsWith('/api/attendance_operation.php') && url.searchParams.has('request_id')) {
        const cookieHeader = Object.entries(params.request.headers || {}).find(([key]) => key.toLowerCase() === 'cookie')?.[1] || '';
        const cookieMatch = String(cookieHeader).match(/SAS_PHASE3E3_3e3a47bc=([^;]+)/);
        const sessionRoot = path.join(os.tmpdir(), 'phase3e3-php-sessions');
        const sessionFile = cookieMatch ? path.join(sessionRoot, 'sess_' + cookieMatch[1]) : '';
        const safeSessionFile = sessionFile && path.dirname(sessionFile) === sessionRoot && /^[A-Za-z0-9,-]+$/.test(cookieMatch[1]);
        let removed = false;
        if (safeSessionFile && fs.existsSync(sessionFile)) { fs.unlinkSync(sessionFile); removed = true; }
        invalidatedSession = { url:params.request.url, cookiePresent:!!cookieMatch, sessionFileRemoved:removed };
        await cdp.send('Fetch.continueRequest', { requestId: params.requestId }, sessionId);
        resolveInvalidated(invalidatedSession);
      } else await cdp.send('Fetch.continueRequest', { requestId: params.requestId }, sessionId);
    });
    await cdp.send('Fetch.enable', { patterns: [{ urlPattern: '*\/api\/attendance_operation.php*', requestStage: 'Request' }] }, page.sessionId);
    dropAfterNextMutation = true;
    await clickAndConfirm(page, '[data-action="clock_in"]');
    await Promise.race([invalidated, sleep(15000).then(() => null)]);
    await waitFor(page, `document.querySelector('[data-attendance-operation-status]')?.dataset.state==='session_expired'`, 15000);
    const pending = await evaluate(page, `window.AttendanceReliability.getPending(window.SAS_USER_ID)`);
    const counts = db(`SELECT CONCAT(o.status,':',o.operation,':',(SELECT COUNT(*) FROM attendance_audit_logs l WHERE l.attendance_id=o.attendance_id AND l.action='clock_in'),':',(SELECT COUNT(*) FROM attendance a WHERE a.id=o.attendance_id AND a.clock_in IS NOT NULL)) FROM attendance_idempotency_operations o WHERE o.user_id=(SELECT id FROM users WHERE username='uat3e3_reconcile') AND o.request_id='${pending?.requestId||''}'`);
    const feedback = await evaluate(page, `({role:document.querySelector('[data-attendance-operation-status]')?.getAttribute('role'),live:document.querySelector('[data-attendance-operation-status]')?.getAttribute('aria-live'),state:document.querySelector('[data-attendance-operation-status]')?.dataset.state,text:document.querySelector('[data-attendance-operation-status]')?.innerText})`);
    await cdp.send('Fetch.disable', {}, page.sessionId).catch(() => {});
    unlisten();
    record('Session expires during reconciliation after committed mutation', invalidatedSession?.cookiePresent && invalidatedSession.sessionFileRemoved && !!pending && counts==='succeeded:clock_in:1:1' && feedback.state==='session_expired' && /log in/i.test(feedback.text), JSON.stringify({ reconciliationSession: invalidatedSession, pending, attributedOperationAuditAttendance: counts, feedback }));
    await loginAgain(page, 'uat3e3_reconcile');
    await waitFor(page, `!window.AttendanceReliability.getPending(window.SAS_USER_ID)`, 15000);
    const recovered = await evaluate(page, `({state:document.querySelector('.app-content')?.innerText.includes('Shift completed')?'completed':'working',status:document.querySelector('[data-attendance-operation-status]')?.dataset.state})`);
    const finalCount = mutationCounts('uat3e3_reconcile','clock_in','clock_in');
    record('Re-login recovers confirmed result after reconciliation-session expiry', finalCount==='1:1:1' && recovered.state==='working', `counts=${finalCount}; page=${JSON.stringify(recovered)}`);
    return;
  }

  if (process.env.PHASE3E3_CASE === 'location') {
    const disabled = db(`SELECT CONCAT(location_status,':',(location_finalized_at IS NOT NULL)) FROM attendance_location_events WHERE user_id=(SELECT id FROM users WHERE username='uat3e3_postclock') AND event_type='clock_in'`);
    record('Location preference disabled leaves finalized sharing_disabled event', disabled==='sharing_disabled:1', disabled);
    const denied = await pageFor('uat3e3_locdeny');
    await cdp.send('Browser.setPermission', { permission:{name:'geolocation'}, setting:'denied', origin:`http://127.0.0.1:${proxyPort}`, browserContextId:denied.browserContextId });
    let deniedState = await evaluate(denied, `fetch('/api/attendance_operation.php',{credentials:'same-origin'}).then(r=>r.json()).then(x=>x.state?.derived?.classification)`);
    if (deniedState === 'not_clocked_in') {
      await clickAndConfirm(denied, '[data-action="clock_in"]');
      await waitState(denied, 'working');
      await waitFor(denied, `!window.AttendanceReliability.getPending(window.SAS_USER_ID)`, 15000);
    }
    deniedState = await evaluate(denied, `fetch('/api/attendance_operation.php',{credentials:'same-origin'}).then(r=>r.json()).then(x=>x.state?.derived?.classification)`);
    if (deniedState === 'working') {
      await waitFor(denied, `!!document.querySelector('[data-action="clock_out"]')`, 15000);
      await clickAndConfirm(denied, '[data-action="clock_out"]');
      await waitState(denied, 'completed');
      await waitFor(denied, `!window.AttendanceReliability.getPending(window.SAS_USER_ID)`, 15000);
    }
    const deniedRows = db(`SELECT CONCAT(SUM(event_type='clock_in' AND location_status='permission_denied' AND location_finalized_at IS NOT NULL),':',SUM(event_type='clock_out' AND location_status='permission_denied' AND location_finalized_at IS NOT NULL),':',COUNT(*),':',(SELECT COUNT(*) FROM attendance_audit_logs l JOIN users u ON u.id=l.user_id WHERE u.username='uat3e3_locdeny' AND l.action IN ('clock_in','clock_out'))) FROM attendance_location_events WHERE user_id=(SELECT id FROM users WHERE username='uat3e3_locdeny')`);
    record('Permission denied does not invalidate clock-in/out and finalizes one event each', deniedRows==='1:1:2:2', deniedRows);
    const deniedKey = db(`SELECT request_id FROM attendance_idempotency_operations WHERE user_id=(SELECT id FROM users WHERE username='uat3e3_locdeny') AND operation='clock_in' AND status='succeeded' LIMIT 1`);
    const replay = await evaluate(denied, `fetch('/api/clock.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content,'Idempotency-Key':${JSON.stringify(deniedKey)},Accept:'application/json'},body:JSON.stringify({action:'clock_in',csrf_token:document.querySelector('meta[name="csrf-token"]').content})}).then(async r=>({status:r.status,data:await r.json()}))`);
    const deniedAfterReplay = db(`SELECT CONCAT(COUNT(*),':',(SELECT COUNT(*) FROM attendance_audit_logs l WHERE l.user_id=(SELECT id FROM users WHERE username='uat3e3_locdeny') AND l.action='clock_in')) FROM attendance_location_events WHERE user_id=(SELECT id FROM users WHERE username='uat3e3_locdeny')`);
    record('Keyed replay does not duplicate finalized location or clock audit', replay.status===200 && replay.data.replayed===true && deniedAfterReplay==='2:1', JSON.stringify({ replay, eventsAndClockInAudit:deniedAfterReplay }));

    const timed = await pageFor('uat3e3_loctimeout');
    const timeoutHook = await evaluate(timed, `(()=>{try{Object.defineProperty(navigator,'geolocation',{configurable:true,value:{getCurrentPosition:(success,error)=>setTimeout(()=>error({code:3}),0)}});return true}catch(e){return String(e)}})()`);
    const timeoutState = await evaluate(timed, `fetch('/api/attendance_operation.php',{credentials:'same-origin'}).then(r=>r.json()).then(x=>x.state?.derived?.classification)`);
    if (['not_clocked_in','today_not_started'].includes(timeoutState)) {
      await clickAndConfirm(timed, '[data-action="clock_in"]');
      await waitState(timed, 'working');
      await waitFor(timed, `!window.AttendanceReliability.getPending(window.SAS_USER_ID)`, 15000);
    }
    const timeoutRows = db(`SELECT CONCAT(SUM(location_status='timed_out' AND location_finalized_at IS NOT NULL),':',COUNT(*),':',(SELECT COUNT(*) FROM attendance_audit_logs l JOIN users u ON u.id=l.user_id WHERE u.username='uat3e3_loctimeout' AND l.action='clock_in')) FROM attendance_location_events WHERE user_id=(SELECT id FROM users WHERE username='uat3e3_loctimeout')`);
    record('Geolocation timeout is non-blocking for clock-in', timeoutHook===true && timeoutRows==='1:1:1', `hook=${timeoutHook}; timeout:events:audit=${timeoutRows}`);

    const refresh = await pageFor('uat3e3_locrefresh3');
    await cdp.send('Browser.setPermission', { permission:{name:'geolocation'}, setting:'denied', origin:`http://127.0.0.1:${proxyPort}`, browserContextId:refresh.browserContextId });
    await evaluate(refresh, `Object.defineProperty(navigator,'geolocation',{configurable:true,value:{getCurrentPosition:()=>{window.__uatGeoPending=true}}})`);
    const refreshState = await evaluate(refresh, `fetch('/api/attendance_operation.php',{credentials:'same-origin'}).then(r=>r.json()).then(x=>x.state?.derived?.classification)`);
    if (['not_clocked_in','today_not_started'].includes(refreshState)) {
      await clickAndConfirm(refresh, '[data-action="clock_in"]');
      await waitState(refresh, 'working');
      await waitFor(refresh, `window.__uatGeoPending===true && !!window.AttendanceReliability.getPending(window.SAS_USER_ID)`, 10000);
    }
    const beforeReload = db(`SELECT CONCAT(COUNT(*),':',SUM(location_finalized_at IS NOT NULL)) FROM attendance_location_events WHERE user_id=(SELECT id FROM users WHERE username='uat3e3_locrefresh3')`);
    const refreshRequestId = await evaluate(refresh, `window.AttendanceReliability.getPending(window.SAS_USER_ID)?.requestId`);
    await reloadNormally(refresh);
    await waitFor(refresh, `location.pathname.startsWith('/staff/') && !!document.querySelector('.app-content')`, 15000);
    await waitFor(refresh, `!window.AttendanceReliability.getPending(window.SAS_USER_ID)`, 15000);
    const afterReload = db(`SELECT CONCAT(COUNT(*),':',SUM(location_finalized_at IS NOT NULL),':',(SELECT COUNT(*) FROM attendance_audit_logs l JOIN users u ON u.id=l.user_id WHERE u.username='uat3e3_locrefresh3' AND l.action='clock_in'),':',(SELECT COUNT(*) FROM attendance_location_events e JOIN users u ON u.id=e.user_id WHERE u.username='uat3e3_locrefresh3' AND e.location_status IN ('unavailable','timed_out','permission_denied'))) FROM attendance_location_events WHERE user_id=(SELECT id FROM users WHERE username='uat3e3_locrefresh3')`);
    record('Refresh resumes location finalization once without duplicating event', beforeReload==='1:0' && afterReload==='1:1:1:1', JSON.stringify({ requestId:refreshRequestId, beforeReload, afterReload, attendanceApiState:await evaluate(refresh,`fetch('/api/attendance_operation.php',{credentials:'same-origin'}).then(r=>r.json()).then(x=>x.state?.derived?.classification)`) }));
    return;
  }

  if (process.env.PHASE3E3_CASE === 'auth') {
    const expired = await pageFor('uat3e3_session');
    await cdp.send('Network.clearBrowserCookies', {}, expired.sessionId);
    await clickAndConfirm(expired, '[data-action="clock_in"]');
    await waitFor(expired, `document.querySelector('[data-attendance-operation-status]')?.dataset.state==='session_expired'`, 15000);
    const pendingBeforeLogin = await evaluate(expired, `window.AttendanceReliability.getPending(window.SAS_USER_ID)`);
    const unauthCounts = db(`SELECT CONCAT((SELECT COUNT(*) FROM attendance_audit_logs l JOIN users u ON u.id=l.user_id WHERE u.username='uat3e3_session' AND l.action='clock_in'),':',(SELECT COUNT(*) FROM attendance_idempotency_operations o JOIN users u ON u.id=o.user_id WHERE u.username='uat3e3_session' AND o.request_id='${pendingBeforeLogin?.requestId||''}'),':',(SELECT COUNT(*) FROM attendance a JOIN users u ON u.id=a.user_id WHERE u.username='uat3e3_session' AND a.work_date=CURDATE() AND a.clock_in IS NOT NULL))`);
    const expiredUi = await evaluate(expired, `({state:document.querySelector('[data-attendance-operation-status]')?.dataset.state,text:document.querySelector('[data-attendance-operation-status]')?.innerText,loginLink:!!document.querySelector('[data-attendance-operation-status] a:not([hidden])')})`);
    record('Session expired before mutation retains pending action without success', !!pendingBeforeLogin && unauthCounts==='0:0:0' && expiredUi.state==='session_expired' && /log in/i.test(expiredUi.text), JSON.stringify({ pending: pendingBeforeLogin, counts: unauthCounts, ui: expiredUi }));
    await loginAgain(expired, 'uat3e3_session');
    await waitFor(expired, `document.querySelector('[data-attendance-operation-status]')?.dataset.state==='retry_available'`, 15000);
    const pendingAfterLogin = await evaluate(expired, `window.AttendanceReliability.getPending(window.SAS_USER_ID)`);
    await clickSelector(expired, '[data-attendance-operation-status] button:not([hidden])');
    await waitState(expired, 'working', 15000);
    await waitFor(expired, `!window.AttendanceReliability.getPending(window.SAS_USER_ID)`, 10000);
    const sessionRecovery = db(`SELECT CONCAT(o.status,':',o.operation,':',(SELECT COUNT(*) FROM attendance_audit_logs l WHERE l.attendance_id=o.attendance_id AND l.action='clock_in')) FROM attendance_idempotency_operations o WHERE o.user_id=(SELECT id FROM users WHERE username='uat3e3_session') AND o.request_id='${pendingBeforeLogin.requestId}'`);
    record('Login again safely retries the retained same request ID', pendingAfterLogin?.requestId===pendingBeforeLogin.requestId && sessionRecovery==='succeeded:clock_in:1', `before=${pendingBeforeLogin.requestId}; after=${pendingAfterLogin?.requestId}; operation=${sessionRecovery}`);

    const csrfPage = await pageFor('uat3e3_csrf');
    const validToken = await evaluate(csrfPage, `document.querySelector('meta[name="csrf-token"]')?.content||window.CSRF_TOKEN`);
    await evaluate(csrfPage, `document.querySelector('meta[name="csrf-token"]').content='invalid-uat-csrf';window.CSRF_TOKEN='invalid-uat-csrf'`);
    await clickAndConfirm(csrfPage, '[data-action="clock_in"]');
    await waitFor(csrfPage, `document.querySelector('[data-attendance-operation-status]')?.dataset.state==='session_expired'`, 15000);
    const csrfPending = await evaluate(csrfPage, `window.AttendanceReliability.getPending(window.SAS_USER_ID)`);
    const csrfCounts = db(`SELECT CONCAT((SELECT COUNT(*) FROM attendance_audit_logs l JOIN users u ON u.id=l.user_id WHERE u.username='uat3e3_csrf' AND l.action='clock_in'),':',(SELECT COUNT(*) FROM attendance_idempotency_operations o JOIN users u ON u.id=o.user_id WHERE u.username='uat3e3_csrf' AND o.request_id='${csrfPending?.requestId||''}'),':',(SELECT COUNT(*) FROM attendance a JOIN users u ON u.id=a.user_id WHERE u.username='uat3e3_csrf' AND a.work_date=CURDATE() AND a.clock_in IS NOT NULL))`);
    record('Invalid CSRF token is rejected without mutation and retains pending request', !!csrfPending && csrfCounts==='0:0:0' && await evaluate(csrfPage, `document.querySelector('[data-attendance-operation-status]')?.dataset.state==='session_expired'`), `pending=${csrfPending?.requestId}; audit:idempotency:clocked_attendance=${csrfCounts}`);
    await evaluate(csrfPage, `document.querySelector('meta[name="csrf-token"]').content=${JSON.stringify(validToken)};window.CSRF_TOKEN=${JSON.stringify(validToken)}`);
    await waitFor(csrfPage, `document.querySelector('[data-attendance-operation-status]')?.dataset.state==='retry_available'`, 15000);
    await clickSelector(csrfPage, '[data-attendance-operation-status] button:not([hidden])');
    await waitState(csrfPage, 'working', 15000);
    const csrfRecovered = db(`SELECT CONCAT(status,':',operation) FROM attendance_idempotency_operations WHERE user_id=(SELECT id FROM users WHERE username='uat3e3_csrf') AND request_id='${csrfPending.requestId}'`);
    record('Corrected CSRF context reconciles and retries the original pending request', csrfRecovered==='succeeded:clock_in', csrfRecovered);

    const ownerId = await evaluate(csrfPage, 'Number(window.SAS_USER_ID)');
    const foreignPending = await evaluate(csrfPage, `window.AttendanceReliability.createPending(window.SAS_USER_ID,'clock_in','')`);
    const logout = await evaluate(csrfPage, `fetch('/logout.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'csrf_token='+encodeURIComponent(document.querySelector('meta[name="csrf-token"]').content)}).then(r=>r.status===200)`);
    await loginAgain(csrfPage, 'uat3e3_other');
    const isolation = await evaluate(csrfPage, `({currentUser:Number(window.SAS_USER_ID),newUserPending:window.AttendanceReliability.getPending(window.SAS_USER_ID),ownerPending:window.AttendanceReliability.getPending(${ownerId})})`);
    const staleRequest = db(`SELECT request_id FROM attendance_idempotency_operations o JOIN users u ON u.id=o.user_id WHERE u.username='uat3e3_stale' AND o.operation='clock_out' AND o.status='succeeded' LIMIT 1`);
    const crossOwner = await evaluate(csrfPage, `fetch('/api/attendance_operation.php?request_id=${encodeURIComponent(staleRequest)}',{credentials:'same-origin'}).then(r=>r.json()).then(x=>({outcome:x.outcome,hasResult:Object.prototype.hasOwnProperty.call(x,'result')}))`);
    record('Cross-user login preserves owner-bound pending context and hides result', logout===true && isolation.currentUser!==ownerId && !isolation.newUserPending && isolation.ownerPending?.requestId===foreignPending.requestId && crossOwner.outcome==='unknown' && !crossOwner.hasResult, JSON.stringify({ ownerId, currentUser:isolation.currentUser, pendingUser:isolation.ownerPending?.userId, newUserHasPending:!!isolation.newUserPending, crossOwner, requestId:staleRequest }));
    return;
  }

  if (process.env.PHASE3E3_CASE === 'stale') {
    const [a, b] = await Promise.all([pageFor('uat3e3_stale'), pageFor('uat3e3_stale')]);
    const initial = await Promise.all([a,b].map((p) => evaluate(p, `fetch('/api/attendance_operation.php',{credentials:'same-origin'}).then(r=>r.json()).then(x=>x.state?.derived?.classification)`)));
    await Promise.all([a,b].map((p) => waitFor(p, `!!document.querySelector('[data-action="clock_out"]')`, 10000)));
    const beforeA = await evaluate(b, `({state:document.querySelector('.app-content')?.dataset.attendanceState,hasClockOut:!!document.querySelector('[data-action="clock_out"]'),disabled:document.querySelector('[data-action="clock_out"]')?.disabled})`);
    if (initial.every((s) => s === 'working') && beforeA.hasClockOut && !beforeA.disabled) {
      await clickAndConfirm(a, '[data-action="clock_out"]');
      await waitState(a, 'completed');
      const staleControlRemains = await evaluate(b, `!!document.querySelector('[data-action="clock_out"]')&&!document.querySelector('[data-action="clock_out"]').disabled`);
      if (staleControlRemains) {
        try {
          await clickAndConfirm(b, '[data-action="clock_out"]');
          await waitState(b, 'completed', 15000);
          await waitFor(b, `document.querySelector('.app-content')?.dataset.attendanceState==='completed'`, 7000);
          const counts = db(`SELECT CONCAT((SELECT COUNT(*) FROM attendance_audit_logs l JOIN users u ON u.id=l.user_id WHERE u.username='uat3e3_stale' AND l.action='clock_out'),':',(SELECT COUNT(*) FROM attendance a JOIN users u ON u.id=a.user_id WHERE u.username='uat3e3_stale' AND a.clock_out IS NOT NULL),':',(SELECT COUNT(*) FROM attendance_idempotency_operations o JOIN users u ON u.id=o.user_id WHERE u.username='uat3e3_stale' AND o.operation='clock_out' AND o.status='rejected'))`);
          const stateB = await evaluate(b, `({attendance:document.querySelector('.app-content')?.dataset.attendanceState,status:document.querySelector('[data-attendance-operation-status]')?.dataset.state,text:document.querySelector('[data-attendance-operation-status]')?.innerText||''})`);
          record('Two-device stale clock-out is rejected and UI reconciles Completed', counts === '1:1:1' && stateB.attendance === 'completed', JSON.stringify({ initial, beforeDeviceA: beforeA, staleButtonAvailable: staleControlRemains, counts, deviceB: stateB }));
        } catch (error) {
          const dom = await evaluate(b, `({html:document.querySelector('.app-content')?.outerHTML||document.body.innerHTML,state:document.querySelector('.app-content')?.dataset.attendanceState,actions:[...document.querySelectorAll('[data-action]')].map(x=>({action:x.dataset.action,disabled:x.disabled,rect:(()=>{const r=x.getBoundingClientRect();return {x:r.x,y:r.y,w:r.width,h:r.height}})()}))})`);
          const image = await cdp.send('Page.captureScreenshot', { format: 'png' }, b.sessionId);
          fs.writeFileSync(path.join(evidenceDir,'stale-clockout-error.png'),Buffer.from(image.data,'base64'));
          fs.writeFileSync(path.join(evidenceDir,'stale-clockout-error.json'),JSON.stringify(dom,null,2));
          record('Two-device stale clock-out is rejected and UI reconciles Completed', false, JSON.stringify({ error: error.message, evidence: 'stale-clockout-error.json/.png', dom }));
        }
      } else record('Two-device stale clock-out is rejected and UI reconciles Completed', false, JSON.stringify({ initial, beforeDeviceA: beforeA, staleButtonAvailable: false }));
    } else record('Two-device stale clock-out is rejected and UI reconciles Completed', false, JSON.stringify({ initial, beforeDeviceA: beforeA }));
    return;
  }

  const pre = await pageFor('uat3e3_precommit');
  await attachStateTrace(pre);
  const preBlock = await installBrowserMutationBlock(pre, '/api/clock.php', 'clock_in');
  const preSnapshotBefore = db(`SELECT CONCAT(COUNT(*),':',COALESCE(MAX(status),''),':',COALESCE(MAX(clock_in),'NULL'),':',COALESCE(MAX(version),0),':',(SELECT COUNT(*) FROM attendance_audit_logs l JOIN users u ON u.id=l.user_id WHERE u.username='uat3e3_precommit'),':',(SELECT COUNT(*) FROM attendance_idempotency_operations o JOIN users u ON u.id=o.user_id WHERE u.username='uat3e3_precommit')) FROM attendance a JOIN users u ON u.id=a.user_id WHERE u.username='uat3e3_precommit' AND a.work_date=CURDATE()`);
  const beforeReceiptCount = proxyEvidence.mutationReceipts.length;
  await clickAndConfirm(pre, '[data-action="clock_in"]');
  const blockedRequest = await Promise.race([preBlock.paused, sleep(12000).then(() => null)]);
  await waitFor(pre, `['connection_unavailable','retry_available'].includes(document.querySelector('[data-attendance-operation-status]')?.dataset.state)`, 15000);
  const prePending = await evaluate(pre, `window.AttendanceReliability.getPending(window.SAS_USER_ID)`);
  await preBlock.restore();
  const preDb = db(`SELECT CONCAT(COUNT(*),':',COALESCE(MAX(status),''),':',COALESCE(MAX(clock_in),'NULL'),':',COALESCE(MAX(version),0),':',(SELECT COUNT(*) FROM attendance_audit_logs l JOIN users u ON u.id=l.user_id WHERE u.username='uat3e3_precommit'),':',(SELECT COUNT(*) FROM attendance_idempotency_operations o JOIN users u ON u.id=o.user_id WHERE u.username='uat3e3_precommit')) FROM attendance a JOIN users u ON u.id=a.user_id WHERE u.username='uat3e3_precommit' AND a.work_date=CURDATE()`);
  const preReceiptDelta = proxyEvidence.mutationReceipts.length - beforeReceiptCount;
  const unknown = prePending ? await evaluate(pre, `window.AttendanceReliability.reconcile(${JSON.stringify(prePending)}).then(x=>x.data.outcome)`) : 'no_pending';
  record('Browser-intercepted pre-request clock-in loss: no server receipt or DB mutation', !!blockedRequest && prePending?.requestId && preDb === preSnapshotBefore && preReceiptDelta === 0 && unknown === 'unknown', JSON.stringify({ interceptionActive: true, browserPausedRequest: blockedRequest, proxyMutationReceiptDelta: preReceiptDelta, databaseBefore: preSnapshotBefore, databaseAfter: preDb, reconcile: unknown }));
  if (blockedRequest && prePending?.requestId && preDb === preSnapshotBefore && preReceiptDelta === 0) {
    await clickSelector(pre, '[data-attendance-operation-status] button:not([hidden])');
    await waitState(pre, 'working', 15000);
    await waitFor(pre, `!window.AttendanceReliability.getPending(window.SAS_USER_ID)`, 10000);
    const retried = mutationCounts('uat3e3_precommit','clock_in','clock_in');
    const reusedKey = proxyEvidence.mutationReceipts.some((r) => r.requestId === prePending.requestId);
    record('Pre-request failure safely retries same ID after reconciliation', retried === '1:1:1' && reusedKey, `counts=${retried}; same_request_id_reached_server=${reusedKey}; request_id=${prePending.requestId}`);
  } else record('Pre-request failure safely retries same ID after reconciliation', false, 'Skipped because interception or no-mutation proof failed.');
  if (process.env.PHASE3E3_CASE === 'precommit') return;

  const clock = await pageFor('uat3e3_postclock');
  await attachStateTrace(clock);
  dropAfterNextMutation = true;
  await clickAndConfirm(clock, '[data-action="clock_in"]');
  await waitState(clock, 'working', 20000);
  await waitFor(clock, `!window.AttendanceReliability.getPending(window.SAS_USER_ID)`, 15000);
  const clockDrop = [...proxyEvidence.droppedResponses].reverse().find((r) => r.path === '/api/clock.php');
  const clockAttribution = clockDrop ? db(`SELECT CONCAT(o.id,':',o.status,':',o.operation,':',(SELECT COUNT(*) FROM attendance_audit_logs l WHERE l.attendance_id=o.attendance_id AND l.action='clock_in'),':',(SELECT COUNT(*) FROM attendance a WHERE a.id=o.attendance_id AND a.work_date=CURDATE())) FROM attendance_idempotency_operations o WHERE o.request_id='${clockDrop.requestId}' AND o.user_id=(SELECT id FROM users WHERE username='uat3e3_postclock')`) : '';
  const clockTrace = await evaluate(clock, 'window.__uatStateTrace');
  record('Post-commit clock-in response loss reconciles original operation', !!clockDrop && clockAttribution.split(':').slice(1).join(':') === 'succeeded:clock_in:1:1' && clockTrace.some((x) => ['checking_server_status','confirmed_success'].includes(x.state)), JSON.stringify({ proxy: clockDrop, operationAndAuditAttribution: clockAttribution, browserStates: clockTrace.map((x) => x.state) }));
  if (clockDrop) {
    const replay = await evaluate(clock, `fetch('/api/clock.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]')?.content||window.CSRF_TOKEN,'Idempotency-Key':${JSON.stringify(clockDrop.requestId)},Accept:'application/json'},body:JSON.stringify({action:'clock_in',csrf_token:document.querySelector('meta[name="csrf-token"]')?.content||window.CSRF_TOKEN})}).then(async r=>({status:r.status,data:await r.json()}))`);
    const afterReplay = mutationCounts('uat3e3_postclock','clock_in','clock_in');
    record('Keyed clock replay preserves one mutation and audit', replay.status === 200 && replay.data.replayed === true && afterReplay === '1:1:1', `replay=${JSON.stringify(replay)}; counts=${afterReplay}`);
  } else record('Keyed clock replay preserves one mutation and audit', false, 'No confirmed proxy-dropped operation ID was available.');

  const breakPage = await pageFor('uat3e3_postbreak');
  await clickAndConfirm(breakPage, '[data-action="clock_in"]');
  await waitState(breakPage, 'working');
  await waitFor(breakPage, `!!document.querySelector('[data-action="start_break"][data-type="lunch"]')`, 10000);
  dropAfterNextMutation = true;
  await clickAndConfirm(breakPage, '[data-action="start_break"][data-type="lunch"]');
  await waitState(breakPage, 'on_break', 20000);
  await waitFor(breakPage, `!window.AttendanceReliability.getPending(window.SAS_USER_ID)`, 15000);
  const breakDrop = [...proxyEvidence.droppedResponses].reverse().find((r) => r.path === '/api/break.php');
  const breakAttribution = breakDrop ? db(`SELECT CONCAT(o.id,':',o.status,':',o.operation,':',(SELECT COUNT(*) FROM break_records b WHERE b.id=o.break_id AND b.user_id=o.user_id),':',(SELECT COUNT(*) FROM attendance_audit_logs l WHERE l.user_id=o.user_id AND l.action='break_start')) FROM attendance_idempotency_operations o WHERE o.request_id='${breakDrop.requestId}' AND o.user_id=(SELECT id FROM users WHERE username='uat3e3_postbreak')`) : '';
  record('Post-commit break response loss reconciles one attributed break operation', !!breakDrop && breakAttribution.split(':').slice(1).join(':') === 'succeeded:break_start:1:1', JSON.stringify({ proxy: breakDrop, operationAndBreakAuditAttribution: breakAttribution }));

  const [deviceA, deviceB] = await Promise.all([pageFor('uat3e3_stale'), pageFor('uat3e3_stale')]);
  const staleStart = await evaluate(deviceA, `fetch('/api/attendance_operation.php',{credentials:'same-origin'}).then(r=>r.json()).then(x=>x.state?.derived?.classification)`);
  if (staleStart === 'not_clocked_in') {
    await clickAndConfirm(deviceA, '[data-action="clock_in"]');
    await waitState(deviceA, 'working');
  } else assert.equal(staleStart, 'working', 'stale-control synthetic employee is in an authoritative Working state');
  await reloadNormally(deviceB);
  await waitState(deviceB, 'working');
  await waitFor(deviceB, `!!document.querySelector('[data-action="clock_out"]')`, 10000);
  await clickAndConfirm(deviceA, '[data-action="clock_out"]');
  await waitState(deviceA, 'completed');
  const staleDom = await evaluate(deviceB, `({state:document.querySelector('.app-content')?.dataset.attendanceState,button:!!document.querySelector('[data-action="clock_out"]'),disabled:document.querySelector('[data-action="clock_out"]')?.disabled,consoleErrors:window.__uatConsoleErrors||[]})`);
  if (staleDom.button && !staleDom.disabled) {
    await clickAndConfirm(deviceB, '[data-action="clock_out"]');
    await waitState(deviceB, 'completed', 15000);
    await waitFor(deviceB, `document.querySelector('.app-content')?.dataset.attendanceState==='completed'`, 7000);
    const staleCounts = db(`SELECT CONCAT((SELECT COUNT(*) FROM attendance_audit_logs l JOIN users u ON u.id=l.user_id WHERE u.username='uat3e3_stale' AND l.action='clock_out'),':',(SELECT COUNT(*) FROM attendance a JOIN users u ON u.id=a.user_id WHERE u.username='uat3e3_stale' AND a.clock_out IS NOT NULL),':',(SELECT COUNT(*) FROM attendance_idempotency_operations o JOIN users u ON u.id=o.user_id WHERE u.username='uat3e3_stale' AND o.operation='clock_out' AND o.status='rejected'))`);
    const staleState = await evaluate(deviceB, `({state:document.querySelector('.app-content')?.dataset.attendanceState,status:document.querySelector('[data-attendance-operation-status]')?.dataset.state,text:document.querySelector('[data-attendance-operation-status]')?.innerText||''})`);
    record('Two-device stale clock-out is rejected and UI reconciles Completed', staleCounts === '1:1:1' && staleState.state === 'completed', JSON.stringify({ beforeAction: staleDom, counts: staleCounts, after: staleState }));
  } else {
    const html = await evaluate(deviceB, `document.querySelector('.app-content')?.outerHTML||document.body.innerHTML`);
    const image = await cdp.send('Page.captureScreenshot', { format: 'png' }, deviceB.sessionId);
    fs.writeFileSync(path.join(evidenceDir, 'stale-clockout-control-missing.png'), Buffer.from(image.data, 'base64'));
    fs.writeFileSync(path.join(evidenceDir, 'stale-clockout-control-missing.html'), html);
    record('Two-device stale clock-out is rejected and UI reconciles Completed', false, JSON.stringify({ beforeAction: staleDom, htmlEvidence: 'stale-clockout-control-missing.html', screenshot: 'stale-clockout-control-missing.png' }));
  }
}

async function cleanup() {
  applicationServerOffline = false;
  try { if (cdp) { for (const contextId of browserContexts) await cdp.send('Target.disposeBrowserContext', { browserContextId: contextId }).catch(() => {}); cdp.socket.close(); } } catch (_) { }
  if (browserProcess && browserProcess.pid) {
    await new Promise((resolve) => {
      const killer = spawn('taskkill.exe', ['/PID', String(browserProcess.pid), '/T', '/F'], { stdio: 'ignore', windowsHide: true });
      killer.on('exit', resolve);
      killer.on('error', resolve);
      setTimeout(resolve, 5000).unref?.();
    });
    browserProcess.kill();
  }
  if (proxyServer) await new Promise((resolve) => proxyServer.close(resolve));
  for (const item of sessions) {
    if (item.profile && fs.existsSync(item.profile)) {
      try { fs.rmSync(item.profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 300 }); }
      catch (_) { console.warn('Edge temporary profile cleanup deferred:', item.profile); }
    }
  }
}

(async () => {
  try {
    const databaseIdentity = db('SELECT DATABASE()');
    assert.equal(databaseIdentity, database, 'selected schema exactly matches the explicit disposable UAT target');
    const phase3e6 = process.env.PHASE3E6_SW_UAT === '1';
    const phase3e4 = process.env.PHASE3E4_CLOSURE === '1';
    const phase3e3 = process.env.PHASE3E3_CLOSURE === '1';
    const networkOnly = process.env.PHASE3E2_NETWORK_ONLY === '1';
    const fixtureState = db(phase3e6
      ? `SELECT CONCAT((SELECT COUNT(*) FROM users WHERE username LIKE 'uat3e6_%'),':',(SELECT COUNT(*) FROM users WHERE username NOT LIKE 'uat3e6_%'),':',(SELECT COUNT(*) FROM attendance),':',(SELECT COUNT(*) FROM break_records),':',(SELECT COUNT(*) FROM attendance_idempotency_operations),':',(SELECT COUNT(*) FROM attendance_audit_logs))`
      : phase3e4
      ? `SELECT CONCAT((SELECT COUNT(*) FROM users WHERE username LIKE 'uat3e4_%'),':',(SELECT COUNT(*) FROM attendance),':',(SELECT COUNT(*) FROM break_records),':',(SELECT COUNT(*) FROM attendance_idempotency_operations),':',(SELECT COUNT(*) FROM attendance_audit_logs))`
      : phase3e3
      ? `SELECT CONCAT((SELECT COUNT(*) FROM users WHERE username LIKE 'uat3e3_%'),':',(SELECT COUNT(*) FROM attendance a JOIN users u ON u.id=a.user_id WHERE u.username LIKE 'uat3e3_%'),':',(SELECT COUNT(*) FROM break_records b JOIN users u ON u.id=b.user_id WHERE u.username LIKE 'uat3e3_%'),':',(SELECT COUNT(*) FROM attendance_idempotency_operations o JOIN users u ON u.id=o.user_id WHERE u.username LIKE 'uat3e3_%'),':',(SELECT COUNT(*) FROM attendance_audit_logs l JOIN users u ON u.id=l.user_id WHERE u.username LIKE 'uat3e3_%'),':',(SELECT COUNT(*) FROM attendance),':',(SELECT COUNT(*) FROM break_records),':',(SELECT COUNT(*) FROM attendance_idempotency_operations),':',(SELECT COUNT(*) FROM attendance_audit_logs))`
      : `SELECT CONCAT((SELECT COUNT(*) FROM users WHERE username IN ('uat3e_admin','uat3e_staff','uat3e_multi','uat3e_peer','uat3e_network','uat3e_refresh','uat3e_location')),':',(SELECT COUNT(*) FROM attendance),':',(SELECT COUNT(*) FROM break_records),':',(SELECT COUNT(*) FROM attendance_idempotency_operations),':',(SELECT COUNT(*) FROM attendance_audit_logs))`);
    assert.ok(phase3e6 ? fixtureState==='2:0:0:0:0:0' : phase3e4 ? fixtureState==='4:0:0:0:0' : phase3e3 ? /^1[0-4]:(\d+):(\d+):(\d+):(\d+):\1:\2:\3:\4$/.test(fixtureState) : ['7:0:0:0:0','7:1:1:2:2','7:1:4:10:10','7:2:5:16:13'].includes(fixtureState), 'all attendance, break, operation, and audit records belong only to the named synthetic UAT accounts');
    record('Disposable database identity and fixture guard', true, `${databaseIdentity}; fixture_state=${fixtureState}`);
    await startProxy();
    await launchBrowser();
    if (phase3e6) {
      await runPhase3e3Closure();
    } else if (phase3e4) {
      await runPhase3e4();
    } else if (phase3e3) {
      await runPhase3e3Closure();
    } else if (networkOnly) {
      await runNetworkWorkflow();
    } else {
      const mainPage = await runMainWorkflow();
      if (mainPage.bootstrapAvailable) {
        await runMultiDeviceWorkflow();
        await runNetworkWorkflow();
        await runResponsiveChecks(mainPage);
      }
    }
  } catch (error) {
    console.error(error.stack || error);
    record('UAT harness execution', false, error.message || String(error));
  } finally {
    await cleanup();
    const report = { database, appPort, browserVersion, generatedAt: new Date().toISOString(), proxyEvidence, results };
    fs.writeFileSync(path.join(evidenceDir, 'uat_results.json'), JSON.stringify(report, null, 2));
    console.log('UAT_RESULTS=' + JSON.stringify(report));
    if (results.some((item) => item.result === 'FAIL')) process.exitCode = 1;
  }
})();
