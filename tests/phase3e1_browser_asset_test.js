/* Focused local asset/browser check; does not authenticate or submit attendance. */
'use strict';

const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const edgePath = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const pageUrl = process.argv[2] || 'http://127.0.0.1:8913/tests/phase3e1_bootstrap_asset_test.html';
const evidenceDir = path.resolve(process.argv[3] || path.join(os.tmpdir(), 'phase3e1-bootstrap-screenshots'));
const widths = [320, 375, 390, 768, 1280];
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function main() {
  fs.mkdirSync(evidenceDir, { recursive: true });
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'phase3e1-edge-'));
  const port = 46000 + Math.floor(Math.random() * 1000);
  const child = spawn(edgePath, [
    '--headless=new', '--no-sandbox', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--disable-background-networking', '--remote-allow-origins=*', '--remote-debugging-address=127.0.0.1',
    '--remote-debugging-port=' + port, '--user-data-dir=' + profile, 'about:blank',
  ], { stdio: 'ignore', windowsHide: true });
  let socket;
  const pending = new Map();
  let nextId = 1;
  const exceptions = [];
  const requestedUrls = [];
  const call = (method, params = {}) => {
    const id = nextId++;
    socket.send(JSON.stringify({ id, method, params }));
    return new Promise((resolve, reject) => {
      pending.set(id, { resolve, reject });
      setTimeout(() => {
        if (pending.has(id)) { pending.delete(id); reject(new Error('CDP timeout: ' + method)); }
      }, 15000).unref?.();
    });
  };
  try {
    let version;
    for (let i = 0; i < 80; i++) {
      try {
        const response = await fetch('http://127.0.0.1:' + port + '/json/version');
        if (response.ok) { version = await response.json(); break; }
      } catch (_) { /* Wait for the local browser endpoint. */ }
      await sleep(150);
    }
    assert.ok(version, 'Edge DevTools endpoint starts');
    const targetResponse = await fetch('http://127.0.0.1:' + port + '/json/new?about:blank', { method: 'PUT' });
    const target = await targetResponse.json();
    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
      socket.addEventListener('open', resolve, { once: true });
      socket.addEventListener('error', reject, { once: true });
    });
    socket.addEventListener('message', (event) => {
      const message = JSON.parse(String(event.data));
      if (message.method === 'Runtime.exceptionThrown') exceptions.push(message.params.exceptionDetails.text);
      if (message.method === 'Network.requestWillBeSent') requestedUrls.push(message.params.request.url);
      if (!message.id) return;
      const waiter = pending.get(message.id);
      if (!waiter) return;
      pending.delete(message.id);
      if (message.error) waiter.reject(new Error(message.error.message));
      else waiter.resolve(message.result || {});
    });

    await call('Page.enable');
    await call('Runtime.enable');
    await call('Network.enable');
    await call('Network.setBlockedURLs', { urls: ['*://cdn.jsdelivr.net/*', '*://fonts.googleapis.com/*', '*://fonts.gstatic.com/*'] });

    for (const width of widths) {
      await call('Emulation.setDeviceMetricsOverride', {
        width, height: 1000, deviceScaleFactor: 1, mobile: width <= 768,
      });
      await call('Page.navigate', { url: pageUrl });
      let state;
      for (let i = 0; i < 50; i++) {
        await sleep(100);
        const evaluated = await call('Runtime.evaluate', {
          expression: `({title:document.title,result:document.querySelector('#testResult')?.textContent||'',width:innerWidth,scrollWidth:document.documentElement.scrollWidth})`,
          returnByValue: true,
        });
        state = evaluated.result.value;
        if (/^(PASS|FAIL) Phase 3E\.1/.test(state.title)) break;
      }
      assert.equal(state.width, width, `viewport is ${width}px`);
      assert.match(state.title, /^PASS Phase 3E\.1/, `${width}px focused browser checks pass: ${state.result}`);
      assert.ok(state.scrollWidth <= width, `${width}px has no horizontal overflow`);
      const image = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
      const screenshot = path.join(evidenceDir, `bootstrap-smoke-${width}px.png`);
      fs.writeFileSync(screenshot, Buffer.from(image.data, 'base64'));
      console.log(`PASS ${width}px — ${state.result}`);
      console.log(`Screenshot: ${screenshot}`);
    }

    assert.ok(requestedUrls.some((url) => url.includes('/assets/vendor/bootstrap-5.3.3/css/bootstrap.min.css')), 'local Bootstrap CSS requested');
    assert.ok(requestedUrls.some((url) => url.includes('/assets/vendor/bootstrap-5.3.3/js/bootstrap.bundle.min.js')), 'local Bootstrap JS requested');
    assert.ok(!requestedUrls.some((url) => /cdn\.jsdelivr\.net\/npm\/bootstrap/.test(url)), 'Bootstrap CDN is not requested');
    assert.equal(exceptions.length, 0, 'no uncaught browser exceptions');
    console.log('PASS local Bootstrap CSS/JS requested with Bootstrap CDN blocked; no uncaught browser exceptions');
  } finally {
    socket?.close();
    spawn('taskkill.exe', ['/PID', String(child.pid), '/T', '/F'], { stdio: 'ignore', windowsHide: true });
    child.kill();
    await sleep(250);
    try { fs.rmSync(profile, { recursive: true, force: true }); }
    catch (_) { console.log(`Temporary Edge profile could not be removed while the browser process exits: ${profile}`); }
  }
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
