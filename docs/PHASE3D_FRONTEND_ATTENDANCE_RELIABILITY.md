# Phase 3D: Frontend attendance reliability

## Deployment prerequisites

1. Back up the target database and verify the selected database name.
2. Apply `database/migration_phase3b_idempotency.sql` to the intended database using the approved migration process. The application does not apply this migration automatically.
3. Deploy `api/attendance_operation.php`, the Phase 3C.1 keyed API adapter, and the frontend assets together.
4. Verify the authenticated `GET /api/attendance_operation.php` response reports `contract_version: 1`, `outcome: ready`, and `idempotency_supported: true` before enabling employee actions.

The staff dashboard probes the endpoint before submitting an attendance action. If the database migration or endpoint is unavailable, the dashboard blocks the action with an explanatory message. It never falls back to an unkeyed mutation. Existing server API callers that omit `Idempotency-Key` retain their prior compatibility behavior.

## Request and recovery behavior

The browser stores one pending request per authenticated user in `sessionStorage`, scoped to a random tab context. It stores only the action, break type when relevant, request ID, timestamps, and location-finalization flag. CSRF values and employee profile data are not persisted. A retry uses the original request ID and occurs only after the authenticated reconciliation endpoint reports that request as unknown.

The reconciliation endpoint derives the employee from the authenticated session. It returns the employee's read-only `resolve_attendance_state()` result and, when supplied, looks up the operation using `(session user ID, request ID)`. It does not accept an employee ID and never infers that a pending request succeeded merely because attendance state changed. Terminal operation results come from the durable idempotency row. A missing table reports `unavailable`; expired requests require administrator review.

## Location

Attendance success and optional location finalization have separate feedback. Location collection remains optional and uses the existing attendance location endpoint and privacy preference. The browser records that location finalization was attempted before sending it; restoring a pending operation will not submit it twice. A location failure does not change the confirmed attendance result.

## Rollback

If frontend behavior must be rolled back, restore the prior frontend assets and layout script reference. Keep the Phase 3B table and keyed API support in place while any clients may have pending requests. Do not drop the table or reverse the migration during rollback; retained operation records are needed to reconcile requests already submitted. Unkeyed API compatibility remains available for authorized legacy clients.

## Local verification

- `tests/attendance_api_idempotency_test.php` exercises keyed mutations, authenticated reconciliation, cross-user non-disclosure, the missing-migration capability response, and historical-row preservation against a disposable database.
- `tests/attendance_reliability_test.js` checks browser storage scoping, key reuse, capability gating, header and CSRF separation, semantic payload retention, and terminal cleanup.
- `tests/attendance_reliability_browser_test.html` runs the request module in a local browser with Web Crypto and session storage.
- Existing API, attendance calculation, location, break, correction, and stale-break regression suites remain applicable. Full UI end-to-end coverage requires a test browser session, test account, and installed browser automation harness; the local browser fixture verifies the request/recovery module rather than every rendered dashboard control.
