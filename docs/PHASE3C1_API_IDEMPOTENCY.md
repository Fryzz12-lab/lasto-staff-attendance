# Phase 3C.1 clock and break API idempotency

The clock and break APIs accept the optional `Idempotency-Key` request header
for clock-in, clock-out, break-start, and break-end. Existing clients that omit
the header continue through the existing service path.

## Transaction and response behavior

For keyed requests, the API adapter owns one PDO transaction. It locks the
authenticated employee row, reserves or looks up the key, calls the existing
attendance service inside that transaction, stores the terminal response, and
commits. The nested `with_employee_lock()` detects the caller-owned transaction
and does not commit it. Attendance, audit, location, break, and idempotency
records therefore commit or roll back together.

Keyed first responses add `contract_version`, `outcome`, `request_id`,
`operation_id`, `replayed`, attendance/break IDs, `server_timestamp`, and the
read-only attendance state. Replays preserve the stored result and set
`replayed` to true. Same-key operation or payload conflicts return HTTP 409.
Confirmed business rejections preserve the existing HTTP 200 convention and
are replayable. Unexpected exceptions roll back and return a generic HTTP 500;
they do not leave a committed reservation. Invalid CSRF and expired sessions
are rejected before reservation.

## Migration and deployment order

1. Back up the intended database and verify the `users`, `attendance`, and
   `break_records` key definitions.
2. Apply `database/migration_phase3b_idempotency.sql` to that explicitly
   selected database. The application does not auto-migrate.
3. Verify the table, three foreign keys, and unique `(user_id, request_id)` key.
4. Deploy the API code. Unkeyed clients remain compatible; keyed clients can be
   enabled later by sending a validated key for each logical operation.
5. Monitor confirmed results, replays, key conflicts, lock timeouts, and generic
   server failures before enabling keys in a client release.

The integration was tested only against a disposable local database. This is
not production readiness evidence.

## Failure, rollback, and monitoring

If the idempotency table is unavailable, a keyed request returns a sanitized
server error and its attendance transaction rolls back. Unkeyed requests do
not access the new table and retain their existing path.

To roll back the application code, first stop clients from sending keys, then
restore the prior API files. Keep the table and its records so committed
responses and request tombstones are not lost. The rollback SQL intentionally
does not drop the table; a destructive drop requires a verified backup and
separate approval.

Monitor API status codes and database deadlock/lock-timeout rates. Key conflicts
can indicate client key reuse with changed actions. Replays are expected after
lost responses. Do not log CSRF values or full request payloads.

## Phase 3D client transition

A later client release should generate one cryptographically random key per
logical action, persist it while the outcome is unresolved, and reuse it only
for retries of the same operation and semantic payload. It should clear the
key after a terminal response and reconcile through the operation identifiers
and included server state. This phase does not alter the frontend.
