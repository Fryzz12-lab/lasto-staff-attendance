/**
 * Chronos Pulse — UI interactions (content reveal, clocks, KPI)
 * Space background stays alive via ChronosSpaceBg singleton + spa-nav
 */
(function () {
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let chronometerBound = false;

  function initAmbient() {
    if (window.ChronosSpaceBg && typeof window.ChronosSpaceBg.init === 'function') {
      window.ChronosSpaceBg.init();
    }
  }

  function initChronometer() {
    if (chronometerBound) return;
    const el = document.getElementById('chronoClock');
    if (!el) return;
    chronometerBound = true;
    const serverIso = el.getAttribute('data-server-now');
    const serverMs = serverIso ? Date.parse(serverIso) : Date.now();
    const bootClient = Date.now();
    const drift = serverMs - bootClient;

    function pad(n) {
      return String(n).padStart(2, '0');
    }

    function tick() {
      const d = new Date(Date.now() + drift);
      try {
        const parts = new Intl.DateTimeFormat('en-GB', {
          timeZone: 'Asia/Kuala_Lumpur',
          hour: '2-digit',
          minute: '2-digit',
          second: '2-digit',
          hour12: false,
        }).formatToParts(d);
        const get = (type) => (parts.find((p) => p.type === type) || {}).value || '00';
        el.querySelector('[data-h]').textContent = get('hour');
        el.querySelector('[data-m]').textContent = get('minute');
        el.querySelector('[data-s]').textContent = get('second');
      } catch (e) {
        el.querySelector('[data-h]').textContent = pad(d.getHours());
        el.querySelector('[data-m]').textContent = pad(d.getMinutes());
        el.querySelector('[data-s]').textContent = pad(d.getSeconds());
      }
      el.classList.toggle('chrono-clock--tick');
    }

    tick();
    setInterval(tick, 1000);
  }

  function initCountUp() {
    const nodes = document.querySelectorAll('.app-content [data-count-to]');
    if (!nodes.length) return;

    const animate = (el) => {
      if (el.dataset.counted === '1') return;
      el.dataset.counted = '1';
      const target = parseFloat(el.getAttribute('data-count-to') || '0');
      const duration = reduceMotion ? 0 : 700;
      if (duration === 0) {
        el.textContent = String(Math.round(target));
        return;
      }
      const start = performance.now();
      function step(now) {
        const p = Math.min(1, (now - start) / duration);
        const eased = 1 - Math.pow(1 - p, 3);
        el.textContent = String(Math.round(target * eased));
        if (p < 1) requestAnimationFrame(step);
      }
      requestAnimationFrame(step);
    };

    nodes.forEach(animate);
  }

  function initPulseRings() {
    document.querySelectorAll('.app-content [data-pulse-ring]').forEach((el) => {
      const value = parseFloat(el.getAttribute('data-value') || '0');
      const max = Math.max(1, parseFloat(el.getAttribute('data-max') || '1'));
      const pct = Math.max(0, Math.min(1, value / max));
      const circle = el.querySelector('.pulse-ring__meter');
      if (!circle) return;
      const len = 2 * Math.PI * 42;
      circle.style.strokeDasharray = String(len);
      circle.style.strokeDashoffset = String(len);
      requestAnimationFrame(() => {
        circle.style.transition = reduceMotion ? 'none' : 'stroke-dashoffset 1.1s cubic-bezier(.22,1,.36,1)';
        circle.style.strokeDashoffset = String(len * (1 - pct));
      });
    });
  }

  function initReveal(opts) {
    opts = opts || {};
    const instant = !!opts.instant || reduceMotion;

    // Soft SPA swaps: show content immediately (no opacity flash)
    if (instant) {
      document.querySelectorAll('.app-content .chrono-reveal').forEach((el) => {
        el.classList.remove('chrono-reveal');
        el.style.removeProperty('--reveal-delay');
      });
      document.body.classList.add('chrono-ready');
      return;
    }

    document.body.classList.remove('chrono-ready');
    const items = document.querySelectorAll(
      '.app-content > .chrono-command, .app-content > .row, .app-content > .card-soft, .app-content > .hero-action, .app-content > .d-flex, .app-content .stat-card, .app-content .chrono-ring, .app-content .hero-action, .app-content .card-soft'
    );
    const seen = new Set();
    let i = 0;
    items.forEach((el) => {
      if (seen.has(el)) return;
      seen.add(el);
      el.classList.add('chrono-reveal');
      el.style.setProperty('--reveal-delay', Math.min(i * 35, 240) + 'ms');
      i += 1;
    });
    requestAnimationFrame(() => document.body.classList.add('chrono-ready'));
  }

  function initCardEnergy() {
    document.querySelectorAll('.app-content .card-soft, .app-content .chrono-ring, .app-content .hero-action, .app-content .stat-card').forEach((card) => {
      card.classList.add('energy-card');
      card.addEventListener('pointermove', (e) => {
        const r = card.getBoundingClientRect();
        card.style.setProperty('--ex', ((e.clientX - r.left) / r.width) * 100 + '%');
        card.style.setProperty('--ey', ((e.clientY - r.top) / r.height) * 100 + '%');
      });
    });
  }

  function refreshContent(opts) {
    opts = opts || {};
    initCountUp();
    initPulseRings();
    initReveal(opts);
    initCardEnergy();
  }

  document.addEventListener('DOMContentLoaded', () => {
    initAmbient();
    initChronometer();
    refreshContent();
  });

  window.ChronosUI = { refreshContent };
})();
