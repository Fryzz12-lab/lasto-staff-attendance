# Phase 3E.1 — Bootstrap Asset Reliability

## Dependency audit

Shared staff and administrator layouts are emitted by `includes/layout_header.php` and `includes/layout_footer.php`.

| Dependency | Version | Origin before change | Role |
| --- | --- | --- | --- |
| Bootstrap CSS | 5.3.3 | jsDelivr | Layout, forms, responsive utilities, modals |
| Bootstrap JS bundle | 5.3.3 | jsDelivr | Modal, toast, offcanvas; bundle includes Popper |
| Bootstrap Icons CSS | 1.11.3 | jsDelivr | Icons |
| Flatpickr CSS and JS | 4.6.13 | jsDelivr | Date/time filter controls |
| Three.js | 0.160.0 | jsDelivr | Ambient background effect |
| Google Fonts | remote CSS import | Google Fonts | Typography |
| html2pdf.js | 0.10.1 | jsDelivr, export-only | PDF report export |

The shared header loads Bootstrap CSS before the existing application stylesheet. The shared footer loads the Bootstrap bundle before Flatpickr and all app scripts. `assets/js/app.js` calls Bootstrap `Modal` and `Toast`; the staff dashboard confirmation uses `bootstrap.Modal.getOrCreateInstance()`. Other modal users include shared staff and administrator pages.

Bootstrap CSS and JS previously used the same 5.3.3 release. No Bootstrap distribution was present in the repository before this change. The `asset()` helper builds a URL from the application base path and adds the file modification time as `?v=...`.

## Package source and verification

- Package: `bootstrap@5.3.3`, MIT license.
- Source: [official npm registry package metadata](https://registry.npmjs.org/bootstrap/5.3.3) and its package tarball.
- Package tarball integrity from registry metadata: `sha512-8HLCdWgyoMguSO9o+aH+iuZ+aht+mzW0u3HIMzVu7Srrpv7EBBxTnrFlSCskwdY1+EOFQSm7uMJhNQHkdPcmjg==`.
- The downloaded tarball's SHA-512 was computed locally and matched the registry integrity value before extraction.
- Source files were copied unchanged from the package's `dist/` directory. The upstream `LICENSE` is included alongside them.

Files added:

- `assets/vendor/bootstrap-5.3.3/css/bootstrap.min.css`
- `assets/vendor/bootstrap-5.3.3/js/bootstrap.bundle.min.js`
- `assets/vendor/bootstrap-5.3.3/LICENSE`

SHA-256 checksums:

- CSS: `3C8F27E6009CCFD710A905E6DCF12D0EE3C6F2AC7DA05B0572D3E0D12E736FC8`
- JS bundle: `0833B2E9C3A26C258476C46266E6877FC75218625162E0460BE9A3A098A61C6C`
- License: `8C14611AE41AC6FD543C13349F22188EB12C69B3E59105C5ECA3925A8E4ECA3E`

## Cache and deployment

The shared layouts now use the local files through `asset()`, retaining subdirectory support and file modification cache-busting. The service worker's precache list includes both files, and its cache name advances from `sas-static-v24` to `sas-static-v25`; activation removes older `sas-static-*` caches.

Deploy the three added files with the changed `includes/layout_header.php`, `includes/layout_footer.php`, `sw.js`, and the service-worker cache test. Deploying templates without the assets will make the local URLs fail. Deploying the assets without the new service worker can leave clients using an older cache until the new worker installs and activates. A mixed CSS/JS version is avoided by pinning both files to 5.3.3 in the same vendor directory; do not replace only one file during later upgrades.

Rollback by restoring the prior layout references and service-worker cache version, then deploy that service worker so the app returns to the previous CDN behavior. The added vendor files can remain unused or be removed in a separate cleanup after rollback verification.

## Remaining external dependencies

This change removes Bootstrap CSS and JavaScript as critical CDN dependencies. Bootstrap Icons, Flatpickr, Three.js, Google Fonts, and export-only html2pdf.js remain external. Date filtering, icon appearance, typography, ambient decoration, and PDF export may still be degraded or unavailable when their external hosts cannot be reached. Attendance recording itself still requires the application server.

## Verification scope

The local browser smoke page is `tests/phase3e1_bootstrap_asset_test.html`; run it with the focused Edge/CDP runner `node tests/phase3e1_browser_asset_test.js` while the local PHP server is available at port 8913. The runner blocks jsDelivr and Google Fonts requests, exercises all four dashboard confirmation actions, cancels each dialog, checks focus, Escape dismissal, modal instance reuse, viewport fit and overflow, and captures screenshots in the system temporary directory under `phase3e1-bootstrap-screenshots/`. It uses mock readiness responses and verifies that no attendance mutation endpoint is called.

This is a focused layout/modal fixture using the application's CSS and JavaScript, not a complete authenticated staff dashboard session. Database-backed dashboard loading and end-to-end clock/break UAT remain for the separately authorized Phase 3E UAT. No attendance mutation or database change is part of this phase.
