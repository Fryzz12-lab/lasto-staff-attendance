# Deployment handoff

## Local validation, 28 September 2026

- Existing visual design and responsive styles were preserved. CSS already includes phone, tablet, desktop and reduced-motion breakpoints; this is not a substitute for visual testing.
- 18 Malay/Chinese UAT accounts already exist, with 407 attendance rows and 436 break rows at inspection. Credentials, exact dates and examples are in the private `storage/uat-manifest.json` file; do not publish that file.
- Local Apache/MySQL were started for testing. The default URL is `http://localhost/agy-staff-attendance/`.
- Run `php tests/company_policy.php` for isolated policy, concurrency, recovery and page-render checks. This creates and drops only a uniquely named test database.
- Run `php tests/http_smoke.php` for real HTTP login, inactive-account, role, setup, CSRF and private-file checks. It uses the existing UAT accounts; dashboard GET requests may perform the application's normal attendance reconciliation.
- Latest run: **268 policy/integrity/concurrency checks passed**, **97 HTTP checks passed**, and **63 PHP files passed syntax checks**. No responsive browser screenshots or restore drill were performed in this continuation.
- `php cli/maintenance.php cleanup` created a fresh backup and found no remaining retired settings or targeted columns to remove. Historical fields are retained; unused-looking fields are not removed without dependency review.
- A fresh schema reset now also clears old security-question rows, preventing recovery answers from surviving a demo reinstall. Never use the schema reset on production data.

## Required go-live gates

The cPanel-specific upload, environment, database, protection, and cron steps are consolidated in `docs/CPANEL_DEPLOYMENT_CHECKLIST.md`. Database import and restore handling are in `docs/PRODUCTION_DATABASE_HANDOFF.md`.

1. Complete `docs/UAT_SCENARIOS.md` and record pass/fail, device, date and evidence. Verify PDF/CSV downloads and both admin/staff workflows yourself. Automated render tests do not prove visual usability.
2. Back up using `php cli/maintenance.php backup`. Copy the SQL to access-controlled storage outside the web root. Restore it into an empty, separate database and compare record counts before relying on it. The generated backup does not create or select the destination database.
3. Remove only generated UAT accounts using `php cli/seed_uat.php --remove`. If it refuses due to references from real staff records, review those references rather than deleting them blindly. Retire the original demo staff and change every remaining default password. Do not remove UAT until testing is finished.
4. Set `LASTO_APP_ENV=production`, a dedicated least-privilege database account using `LASTO_DB_USER`/`LASTO_DB_PASS`, the correct port, and `LASTO_PUBLIC_ORIGIN=https://your-real-host`. Configure these for both Apache and scheduled CLI jobs; an interactive terminal's environment is not automatically Apache's environment. Restart the affected services after configuration changes.
5. Serve HTTPS, verify secure/HttpOnly/SameSite session cookies, and configure durable server/proxy rate limiting for login and recovery. The current login has no built-in persistent brute-force throttle. The current Forgot Password flow uses security questions, not email-only recovery; assess this before exposing it publicly. Do not treat this build as Internet-ready until these controls are verified.
6. Check account setup, password changes, recovery, token expiry/reuse and session invalidation. Configure and actually test mail delivery if using email invitations; otherwise document admin identity verification and private link handoff. Never send shared UAT answers/passwords to real staff.
7. Schedule `C:\xampp\php\php.exe` with argument `C:\xampp\htdocs\agy-staff-attendance\cli\daily_attendance.php` daily at 00:05 Malaysia time. Capture task exit status/logs and test a missed-day catch-up using `--from YYYY-MM-DD --to YYYY-MM-DD`.
8. Confirm HTTP access is denied for `storage`, `config`, `includes`, `database`, `cli`, `tests`, `docs` and `scratch`. Apache must honor `.htaccess`; other web servers need equivalent rules. Backups and the UAT manifest contain sensitive material.
9. Run `php cli/preflight.php` under the deployment environment. Resolve every FAIL. Also complete its manual checks: a zero exit code is not a security audit or a readiness percentage.

## Small-screen acceptance

Use browser device emulation at 375, 768, 1024 and 1440 pixels, plus one real phone. Test day/night themes, long Malay/Chinese names, keyboard focus, navigation opening/closing, date filters, form errors, modal confirmation buttons and horizontally scrolling report tables. At 200% zoom, labels and primary actions must remain reachable. Do not redesign the UI to fix a single overflow: record the page, width and element, then apply a targeted adjustment.

## Current decision

Local functional tests can establish UAT readiness, not a defensible "90% production ready" score. Production environment, database credentials, HTTPS, default/UAT credentials, rate limiting, recovery policy, restore testing, scheduled operations and browser acceptance remain explicit deployment gates. Do not go live merely because tests pass.
