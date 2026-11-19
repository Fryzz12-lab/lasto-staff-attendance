# Phase 3B durable idempotency foundation

The Phase 3B migration adds `attendance_idempotency_operations`. It is a
foundation only; the clock and break APIs do not use it yet.

## Preconditions

- MariaDB 10.4 or a compatible InnoDB release.
- The selected database contains `users`, `attendance`, and `break_records`.
- Their primary and referenced keys are `INT UNSIGNED`, as in the current
  schema.
- The target database has been explicitly verified and backed up.

The migration does not select a database and uses `CREATE TABLE IF NOT EXISTS`,
so rerunning it does not drop existing operation records. An existing table
with an incompatible definition must be investigated rather than silently
overwritten.

## Forward migration and deployment order

1. Verify the database name and the three parent table definitions.
2. Take and verify a database backup.
3. Apply `database/migration_phase3b_idempotency.sql` using a connection that
   explicitly selects the intended database.
4. Run `SHOW CREATE TABLE attendance_idempotency_operations` and inspect the
   three foreign keys and `uk_idem_user_request` index.
5. Keep clock and break API traffic on the existing code until a later Phase
   3C release has been separately authorized and verified.

This foundation can be deployed without changing application behavior because
the existing APIs do not load or write the new table.

## Transaction contract

`includes/idempotency_service.php` never starts, commits, or rolls back a PDO
transaction. A future API caller must acquire the existing employee lock,
reserve the operation, perform the attendance mutation and audit write, persist
the terminal response, and commit all of them together.

If the transaction rolls back, the reservation and mutation disappear together.
If it commits and the HTTP response is lost, the terminal response remains
available for replay.

## Retention and expiry

The default retention is seven days. Expired terminal records become `expired`
tombstones during cleanup; their request keys remain reserved and cannot be
silently reused. `processing` rows are never cleaned automatically. A future
retention/archive policy must preserve tombstones before any physical deletion
is considered.

## Rollback

`database/rollback_phase3b_idempotency.sql` only prints a warning. It does not
drop the table automatically because doing so would destroy replay and conflict
history. Any manual drop requires a backup, stopped application traffic, and
explicit approval.
