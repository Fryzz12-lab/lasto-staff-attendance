# Production Database Handoff

## Fresh installation

Use a blank database created in cPanel. Import in this order:

1. `database/cpanel_schema.sql`
2. `database/cpanel_seed.sql`

Select the newly created, empty attendance database in phpMyAdmin before either import. `schema.sql` is for local XAMPP development and drops application tables. Never run it on the hosting account.

## Existing installation

Apply only the migration files required by the current database version, one at a time, after taking a backup. Review each migration before running it. The migration files are in `database/`.

For an existing installation that has not received attendance location, import `migration_attendance_location.sql` first, then `migration_attendance_location_preference.sql`. If the location table and preference column already exist, import only the preference migration if its `attendance_location_preference_migrated` setting is absent. Do not import either migration into a fresh installation using `cpanel_schema.sql`; that schema already contains both changes. The deployment artifact builder copies both migration files beside the ZIP for phpMyAdmin import; they are not included in the public upload ZIP.

The preference migration keeps existing `1` values enabled. Earlier `0` values cannot reliably distinguish an explicit choice from the old default, so it changes them to NULL (undecided) once. Staff then choose at their next Clock In or Clock Out. Later explicit Off choices remain `0`.

## Before go-live

- Create a database backup with `php cli/maintenance.php backup`.
- Copy the generated SQL outside the web root.
- Restore the backup into a separate empty database.
- Compare users, attendance, breaks, leave requests, corrections, and audit-log counts.
- Remove UAT accounts with `php cli/seed_uat.php --remove` only after browser UAT is complete.
- Verify no `uat_` username remains and delete `storage/uat-manifest.json`.

## Initial administrator

The first administrator must use a unique password and complete the password setup flow. Do not distribute the seeded demo credentials. Keep at least one second trusted admin account or document the admin-assisted recovery procedure.
