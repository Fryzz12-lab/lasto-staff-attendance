/**
 * Soft page navigation — swap shell + content, keep Three.js background alive
 */
(function () {
  let navigationController = null;

  function sameOrigin(url) {
    try {
      const u = new URL(url, window.location.href);
      return u.origin === window.location.origin;
    } catch (e) {
      return false;
    }
  }

  function shouldSpaNavigate(anchor) {
    if (!anchor || anchor.target === '_blank') return false;
    if (anchor.hasAttribute('download')) return false;
    if (anchor.dataset.spa === '0' || anchor.classList.contains('no-spa')) return false;
    const href = anchor.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript:')) return false;
    if (!sameOrigin(href)) return false;
    const u = new URL(href, window.location.href);
    if (u.pathname.indexOf('/logout.php') !== -1) return false;
    if (anchor.closest('form')) return false;
    // Logged-in app or auth shell
    if (!document.querySelector('.app-wrapper') && !document.querySelector('.auth-shell')) return false;
    return true;
  }

  function replaceHtml(cur, next) {
    if (cur && next) cur.innerHTML = next.innerHTML;
  }

  function replaceOuter(cur, next) {
    if (cur && next) cur.outerHTML = next.outerHTML;
  }

  function syncI18nFromDoc(doc) {
    const html = doc.documentElement;
    if (html) {
      const lang = html.getAttribute('lang');
      if (lang) document.documentElement.setAttribute('lang', lang);
      const uh = html.getAttribute('data-unit-h');
      const um = html.getAttribute('data-unit-m');
      if (uh) window.UNIT_H = uh;
      if (um) window.UNIT_M = um;
      document.documentElement.setAttribute('data-unit-h', uh || '');
      document.documentElement.setAttribute('data-unit-m', um || '');
    }
    // Pull updated toast strings from next page footer script if present
    const scripts = doc.querySelectorAll('script');
    for (let i = 0; i < scripts.length; i++) {
      const t = scripts[i].textContent || '';
      if (t.indexOf('CHRONOS_I18N') === -1) continue;
      const m = t.match(/CHRONOS_I18N\s*=\s*(\{[\s\S]*?\});/);
      if (m) {
        try {
          // eslint-disable-next-line no-new-func
          window.CHRONOS_I18N = new Function('return (' + m[1] + ')')();
        } catch (e) {}
      }
      break;
    }
  }

  /** Refresh chrome that sits outside .app-content (lang, sidebar, topbar…) */
  function swapShell(doc) {
    syncI18nFromDoc(doc);

    // Keep page-specific body classes in sync during soft navigation. Without
    // this, the admin/dashboard background can remain active on staff pages.
    if (doc.body) {
      document.body.className = doc.body.className;
    }

    // Keep the destination page's active section along with its sidebar labels.
    replaceHtml(document.querySelector('.sidebar-nav'), doc.querySelector('.sidebar-nav'));
    replaceHtml(document.querySelector('.brand-sub'), doc.querySelector('.brand-sub'));
    replaceHtml(document.querySelector('.user-role'), doc.querySelector('.user-role'));

    // Topbar title
    const nextTitle = doc.querySelector('.topbar-title');
    const curTitle = document.querySelector('.topbar-title');
    if (nextTitle && curTitle) {
      curTitle.textContent = nextTitle.textContent;
      curTitle.className = nextTitle.className;
    }

    // System online status label
    const nextStatus = doc.querySelector('.chrono-status');
    const curStatus = document.querySelector('.chrono-status');
    if (nextStatus && curStatus) {
      const title = nextStatus.getAttribute('title');
      const label = (nextStatus.textContent || '').trim();
      curStatus.innerHTML = '';
      const newDot = document.createElement('span');
      newDot.className = 'chrono-status__dot';
      curStatus.appendChild(newDot);
      curStatus.appendChild(document.createTextNode(' ' + label));
      if (title) curStatus.setAttribute('title', title);
    }

    const nextTheme = doc.querySelector('#themeToggle');
    const curTheme = document.querySelector('#themeToggle');
    if (nextTheme && curTheme) {
      ['title', 'aria-label', 'data-label-night', 'data-label-day'].forEach((attr) => {
        const v = nextTheme.getAttribute(attr);
        if (v != null) curTheme.setAttribute(attr, v);
      });
      if (window.SAS && typeof window.SAS.syncThemeToggleUi === 'function') {
        window.SAS.syncThemeToggleUi(document.documentElement.getAttribute('data-theme') || 'night');
      }
    }

    // Language switcher (re-bind Bootstrap via fresh markup)
    const curLang = document.querySelector('.lang-switcher');
    const nextLang = doc.querySelector('.lang-switcher');
    replaceOuter(curLang, nextLang);

    // Logout button
    const curLogout = document.querySelector('.topbar-actions a[href*="logout"]');
    const nextLogout = doc.querySelector('.topbar-actions a[href*="logout"]');
    if (curLogout && nextLogout) curLogout.innerHTML = nextLogout.innerHTML;

    // Ticker label (admin)
    const curTickLabel = document.querySelector('.topbar-ticker__label');
    const nextTickLabel = doc.querySelector('.topbar-ticker__label');
    if (curTickLabel && nextTickLabel) curTickLabel.textContent = nextTickLabel.textContent;

    // Footer
    const curFooter = document.querySelector('.app-footer');
    const nextFooter = doc.querySelector('.app-footer');
    if (curFooter && nextFooter) curFooter.innerHTML = nextFooter.innerHTML;

    // Auth top lang switcher
    const curAuthTop = document.querySelector('.auth-top');
    const nextAuthTop = doc.querySelector('.auth-top');
    if (curAuthTop && nextAuthTop) {
      replaceOuter(curAuthTop.querySelector('.lang-switcher'), nextAuthTop.querySelector('.lang-switcher'));
      const curAuthTheme = curAuthTop.querySelector('#themeToggle');
      const nextAuthTheme = nextAuthTop.querySelector('#themeToggle');
      if (curAuthTheme && nextAuthTheme) {
        ['title', 'aria-label', 'data-label-night', 'data-label-day'].forEach((attr) => {
          const v = nextAuthTheme.getAttribute(attr);
          if (v != null) curAuthTheme.setAttribute(attr, v);
        });
      }
    }

    // Loading overlay text
    const curLoad = document.querySelector('#loadingOverlay .mt-2');
    const nextLoad = doc.querySelector('#loadingOverlay .mt-2');
    if (curLoad && nextLoad) curLoad.textContent = nextLoad.textContent;
  }

  function refreshUiAfterSwap() {
    // Page-local inline scripts are not re-executed when .app-content is
    // swapped. Keep the staff form's required username field functional after
    // soft navigation as well as after a full refresh.
    const fullName = document.getElementById('input_full_name');
    const username = document.getElementById('input_username');
    if (fullName && username) {
      const suggest = () => {
        if (username.dataset.userEdited === '1') return;
        const tokens = fullName.value.trim().toLowerCase()
        .replace(/\b(a\/l|a\/p|s\/o|d\/o|bin|binti|bte|bt|anak|ak)\b/g, ' ')
        .replace(/[^a-z0-9\s]/g, ' ').trim().split(/\s+/).filter(Boolean);
        if (tokens.length) username.value = tokens.length === 1 ? tokens[0]
          : (tokens[0][0] + '_' + tokens[1] + (tokens.length > 2 ? '_' + tokens[tokens.length - 1] : ''));
        else username.value = '';
        username.dispatchEvent(new Event('input', { bubbles: true }));
      };
      username.addEventListener('input', (event) => { if (event.isTrusted) username.dataset.userEdited = '1'; }, { once: false });
      fullName.addEventListener('input', suggest);
      if (!username.value.trim()) suggest();
    }
    if (window.initPasswordToggles) window.initPasswordToggles();
    if (window.ChronosUI && typeof window.ChronosUI.refreshContent === 'function') {
      window.ChronosUI.refreshContent({ instant: true });
    }
    if (window.SAS && typeof window.SAS.bindPageActions === 'function') {
      window.SAS.bindPageActions();
    }
    if (window.SAS && typeof window.SAS.initNotificationControls === 'function') {
      window.SAS.initNotificationControls();
    }
    if (window.ChronosDateFilter && typeof window.ChronosDateFilter.init === 'function') {
      window.ChronosDateFilter.init(document);
    }
    // Re-bind theme toggle if node was replaced
    if (window.SAS && typeof window.SAS.initThemeToggle === 'function') {
      window.SAS.initThemeToggle();
    }
    // Refresh admin ticker copy in new language
    if (window.SAS && typeof window.SAS.refreshLiveTicker === 'function') {
      window.SAS.refreshLiveTicker();
    }
    if (window.SAS && typeof window.SAS.refreshBadges === 'function') {
      window.SAS.refreshBadges();
    }
  }

  function cleanLangFromHistoryUrl(url) {
    try {
      const u = new URL(url, window.location.href);
      // Keep lang in history so back/forward & share work; session already set by server
      return u.toString();
    } catch (e) {
      return url;
    }
  }

  function coreAssetsChanged(doc) {
    const selectors = [
      'link[href*="/assets/css/app.css"]',
      'script[src*="/assets/js/app.js"]',
      'script[src*="/assets/js/date-filter.js"]',
      'script[src*="/assets/js/spa-nav.js"]',
    ];
    return selectors.some((selector) => {
      const current = document.querySelector(selector);
      const next = doc.querySelector(selector);
      if (!current || !next) return false;
      const attribute = current.tagName === 'LINK' ? 'href' : 'src';
      return current.getAttribute(attribute) !== next.getAttribute(attribute);
    });
  }

  async function softNavigate(url, push) {
    // A newer filter selection supersedes an in-flight response.
    if (navigationController) navigationController.abort();
    const controller = new AbortController();
    navigationController = controller;
    document.body.classList.add('is-spa-nav');
    const currentContent = document.querySelector('.app-content');
    if (currentContent) currentContent.setAttribute('aria-busy', 'true');
    const progressBar = document.getElementById('chronoProgressBar');
    if (progressBar) {
      progressBar.classList.remove('is-done');
      progressBar.classList.add('is-active');
    }

    try {
      const res = await fetch(url, {
        signal: controller.signal,
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          Accept: 'text/html',
        },
        credentials: 'same-origin',
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const html = await res.text();
      if (controller.signal.aborted) return;
      const doc = new DOMParser().parseFromString(html, 'text/html');

      // Swapping new markup while old JavaScript/CSS remains loaded can break controls.
      if (coreAssetsChanged(doc)) {
        window.location.href = url;
        return;
      }

      const nextMain = doc.querySelector('.app-content');
      const curMain = document.querySelector('.app-content');
      const nextAuth = doc.querySelector('.auth-shell');
      const curAuth = document.querySelector('.auth-shell');

      if (curMain && nextMain) {
        // Keep ambient canvas untouched — swap content without full-screen veil
        curMain.innerHTML = nextMain.innerHTML;
        swapShell(doc);
      } else if (curAuth && nextAuth) {
        const nextInner = nextAuth.cloneNode(true);
        curAuth.innerHTML = nextInner.innerHTML;
        swapShell(doc);
      } else {
        window.location.href = url;
        return;
      }

      if (doc.title) document.title = doc.title;

      const nextCsrf = doc.querySelector('meta[name="csrf-token"]');
      if (nextCsrf) {
        window.CSRF_TOKEN = nextCsrf.getAttribute('content') || window.CSRF_TOKEN;
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.setAttribute('content', window.CSRF_TOKEN);
      }

      if (push !== false) {
        history.pushState({ spa: 1 }, '', cleanLangFromHistoryUrl(url));
      }

      const sidebar = document.getElementById('appSidebar');
      const backdrop = document.getElementById('sidebarBackdrop');
      sidebar && sidebar.classList.remove('open');
      backdrop && backdrop.classList.remove('show');

      window.scrollTo(0, 0);
      refreshUiAfterSwap();
    } catch (e) {
      if (!controller.signal.aborted) window.location.href = url;
    } finally {
      if (navigationController === controller) {
        navigationController = null;
        document.body.classList.remove('is-spa-nav');
        const content = document.querySelector('.app-content');
        if (content) content.removeAttribute('aria-busy');
        const progressBar = document.getElementById('chronoProgressBar');
        if (progressBar) {
          progressBar.classList.remove('is-active');
          progressBar.classList.add('is-done');
          setTimeout(() => progressBar.classList.remove('is-done'), 400);
        }
      }
    }
  }

  function onClick(e) {
    if (e.defaultPrevented) return;
    if (e.button !== 0) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest('a');
    if (!shouldSpaNavigate(a)) return;
    e.preventDefault();
    const url = a.href;
    // Language switches use a full reload so the server-rendered selector stays in sync.
    const isLang = a.hasAttribute('data-spa-lang');
    // Language changes update the session and the shell selector on the server.
    // Reload normally so the visible control cannot retain the previous label.
    if (isLang) {
      window.location.assign(url);
      return;
    }
    if (!isLang && url === window.location.href) return;
    softNavigate(url, true);
  }

  document.addEventListener('click', onClick);

  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'get') return;
    if (!form.closest('.app-content')) return;
    if (form.dataset.spa === '0') return;
    e.preventDefault();
    const action = form.getAttribute('action') || window.location.href;
    const url = new URL(action, window.location.href);
    const data = new FormData(form);
    // Unchecked controls are absent from FormData; clear their old URL values too.
    Array.from(form.elements).forEach((control) => {
      if (control.name) url.searchParams.delete(control.name);
    });
    data.forEach((value, key) => {
      if (value !== '') url.searchParams.set(key, String(value));
      else url.searchParams.delete(key);
    });
    softNavigate(url.toString(), true);
  });

  window.addEventListener('popstate', () => {
    softNavigate(window.location.href, false);
  });

  window.ChronosSpa = {
    navigate: softNavigate,
    reload: function () {
      softNavigate(window.location.href, false);
    },
  };
})();
