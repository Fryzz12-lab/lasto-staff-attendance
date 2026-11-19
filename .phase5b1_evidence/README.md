# Phase 5B.1 responsive layout and form accessibility verification

Date: 2026-10-31. Scope: shared shell responsiveness, specified staff form labels, and session-expiry feedback. No deployment or Phase 5B.2 work.

## Isolation and method

The PHP browser server and test fixtures used `staff_attendance_phase5b1_disposable_f9c776a3` on local MariaDB at `127.0.0.1:3306`. The selected database identity and data directory were checked before fixture insertions; the PHP server returned the expected database name and empty base path from an in-process config probe before authenticated browser interaction. The only browser fixture records were synthetic admin/staff users and one synthetic leave request. No attendance action, attendance calculation, approval, correction, or external notification was initiated.

The existing Phase 5A.1 captures remain in `.phase5a1_evidence/` and `.phase5a_evidence/`. New browser evidence is retained here.

## Responsive measurements

Staff dashboard document width before (Phase 5A.1 capture) and after (fresh authenticated browser, fallback font environment):

| Viewport | Before document width | After document width |
|---:|---:|---:|
| 320 | 320 | 320 |
| 375 | 375 | 375 |
| 390 | 390 | 390 |
| 430 | 430 | 430 |
| 768 | 768 | 768 |
| 1024 | 1095 | 1024 |
| 1280 | 1280 | 1280 |
| 1440 | 1440 | 1440 |

At 1024px, the previous Logout control ended at x=1095. After the shell change, the staff action controls occupy a second topbar row and Logout ends at x=1002 inside the 1024px viewport. The page has no document-level horizontal overflow at any tested width. See `../phase5a1_evidence/eight-width-measurements.json`, `browser-results.json`, and `staff_1024_after.png`.

Authenticated admin daily-attendance layouts were checked at 390, 1024, and 1280px. Document width matched the viewport in each check; no administrator page-specific CSS or attendance table markup was changed. The separate mobile table presentation issue remains for Phase 5B.2.

The mobile sidebar opened, closed by backdrop, and closed by Escape. Its toggle `aria-expanded` changed false → true → false; SPA navigation also resets both menu triggers. Staff light and dark theme toggles worked. English, Malay, and Chinese dashboard routes loaded with document language `en-GB`, `ms-MY`, and `zh-CN` respectively. At 1024px, the notification dropdown opened, `aria-expanded` changed false → true, the menu became visible, and the document remained 1024px wide. See `notification-shell-check.json`.

## Forms and session feedback

- Staff leave date, type, and reason controls each have a matching unique ID and explicit associated label. Empty form validation remains invalid with the native required-field message. A valid request was submitted against the synthetic database and returned the existing success message.
- The staff correction clock-out date select now has an explicit associated “Date” label. Its existing required validation still rejects the incomplete correction form.
- Session expiry was exercised by setting only the synthetic browser session's `last_activity` to an expired time. English, Malay, and Chinese expiry text was verified; the fresh session preserves the selected locale. The first combined browser pass recorded a false English assertion due to navigation timing; a dedicated repeat confirmed English rendering, retained in `session-expiry-debug.json` and `session_expired_after.png`.
- Explicit logout still ends the authenticated session and shows its existing logged-out message. The shared logout helper now clears the old session ID before a replacement flash session is started, allowing intended one-time feedback to persist.

No attendance operation or reconciliation state was modified or cleared by the expiry handling. API response status/payload behavior was not changed.

## Assets and accessibility limits

The locally vendored Bootstrap CSS and JS both returned HTTP 200; a fully loaded staff page exposed `window.bootstrap` as an object. The notification dropdown runtime check also exercised Bootstrap JS. Restricted network access returned 504 for Google Fonts, Bootstrap Icons CDN, and Flatpickr CSS. Native date inputs remained available. Use production-network assets for final visual validation; fallback typography/icons are not a production rendering claim.

Browser DOM checks verified native `labels` associations and menu expanded state. There is no checked-in axe/Lighthouse/pa11y runner, and this audit did not include a screen-reader session, formal contrast calculation, or full keyboard-only evaluation. No WCAG conformance claim is made.

## Regression checks

- `tests/login_rate_limiting_test.php`: 21 passed, 0 failed.
- `tests/logout_csrf_push_lifecycle_test.php`: 18 passed, 0 failed.
- `tests/attendance_reliability_test.js`: 16 passed.
- `tests/push_recovery_test.js`: 16 passed.
- PHP syntax checks: passed for auth, layout header/footer, staff leave/corrections, login and logout entry points.
- Node syntax checks: passed for `assets/js/app.js` and `assets/js/spa-nav.js`.
- Focused `git diff --check` for touched source files: no whitespace errors.

The PHP CLI tests emitted `Cannot modify header information` warnings where their existing CLI tests print output before exercising header-setting login behavior; all assertions passed. With a task-local session save path, there were no session-file permission warnings.

## Source changes in this phase

- `assets/css/app.css`: staff topbar second row only at 992–1199.98px.
- `assets/js/app.js`, `assets/js/spa-nav.js`, `includes/layout_header.php`: accessible expanded state for both mobile menu triggers.
- `staff/leave.php`, `staff/corrections.php`: explicit label associations only; names, values, validation rules, and hooks remain intact.
- `includes/auth.php`: preserve safe locale for expiry feedback; clear destroyed session ID before a replacement session is started.

Existing unrelated working-tree edits were preserved.
