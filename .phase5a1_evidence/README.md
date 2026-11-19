# Phase 5A.1 browser UX audit evidence

Audit date: 2026-10-31 (local environment Asia/Kuala_Lumpur)

## Scope and method

Read-only application inspection and authenticated browser review using synthetic accounts/records in uniquely named disposable databases. Screenshots and machine-readable results in this directory are retained as evidence. Existing `.phase5a_evidence/` from Phase 5A was preserved. No application, test, schema, or normal-database files were intentionally changed in this phase.

Authenticated route coverage includes 8 staff routes and 18 administrator routes, with shared notifications checked under both roles, at 390px and 1280px. Staff dashboard and administrator daily-attendance pages were additionally measured at 320, 375, 390, 430, 768, 1024, 1280, and 1440px. Synthetic state captures cover working, breaks, completed, WFH and location preference/permission variants. See `staff-route-audit.json`, `admin-state-audit.json`, `eight-width-measurements.json`, `additional-captures.json`, `interaction-checks.json`, and screenshots.

## Verified observations

- Administrator daily-attendance table content exceeds its containing area at 320–768px (e.g. 427px table in 364px container at 390px); the document itself does not overflow because the container scrolls horizontally. At small widths headers and row contents visibly collide, wrap into fragments, and actions clip. Malay and Chinese captures exhibit the same pressure.
- Staff dashboard document width exceeds viewport at 1024px by 71px in the tested asset-fallback environment; the right-side Logout control is clipped. CDN typography was unavailable, so retest with production-equivalent assets before implementation.
- Staff leave required date/type/reason controls have no associated label in the rendered DOM. Staff correction `clock_out_date` selector likewise has no associated label. The empty leave form showed native required-field validation; no form was submitted.
- Mobile sidebar opens with drawer/backdrop but toggle has no `aria-expanded` value. Clock-in confirmation modal has accessible title/description references and fit the tested viewport.
- Synthetic expired session redirected to login without a visible explanatory expiry message.
- With the PHP server deliberately stopped, the service worker served its “Connection Lost” page and stated that attendance changes were not saved. Browser offline emulation alone did not prove this behavior; the server-unreachable run did.
- Local Bootstrap CSS/JS and logo loaded. Network-restricted CDN requests for icon/font/date-picker/three.js assets failed; captures therefore use fallback rendering. This is an environment/dependency resilience observation, not proof that those assets fail in normal deployment.

## State coverage limitations

Working, break types, completed, WFH, location preference enabled/disabled and permission-denied states were observed. The incomplete fixture used the wrong day and rendered Ready to Start; incomplete state is NOT VERIFIED. Review-required was present in admin reporting but not verified as a staff-facing state. Location capture pending/unavailable response is NOT VERIFIED. Keyed pending mutation, dropped-response reconciliation and session-expiry UAT were NOT VERIFIED because the existing Phase 3E harness failed before producing any proxy mutation receipt. See `phase3e_ux/uat_results.json` and `phase3e_network/uat_results.json`; both report zero receipts/reconciliations. First run failed on expected `not_clocked_in` vs actual `today_not_started`; network-only run timed out waiting for `retry_available`.

## Isolation incident disclosure

An early unauthenticated staff-page request was issued while the PHP server process had not inherited the intended disposable DB settings. The request redirected to login and did not submit a form or perform attendance mutation. The server was stopped, its configuration corrected, and subsequent browser work used explicitly configured disposable databases. This is disclosed rather than treated as isolated retroactively.

## Recommendation

Prioritize the administrator mobile daily-attendance presentation (mobile cards or a responsive row layout preserving employee, status/review, times and actions), followed by form-label associations and staff dashboard breakpoint verification. Keep attendance APIs, business logic, recovery/idempotency behavior, and existing visual identity out of the first UI-only increments. No changes are implemented by this audit.

## Verification limits

No automated accessibility scanner, screen-reader evaluation, full keyboard-only audit, formal contrast measurement, production-equivalent CDN check, PDF generation, or complete Phase 3E recovery UAT passed in this session. Treat this as a browser evidence audit, not accessibility certification or release UAT.
