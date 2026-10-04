# Attendance adjustment ledger deployment

1. Back up the production database.
2. Upload the application files from `fix/attendance-adjustments`.
3. In cPanel phpMyAdmin, select the attendance database and run `database/migration_attendance_adjustments.sql` once.
4. Confirm the new tables exist: `offday_adjustment_reviews`, `leave_replacement_reviews`, and `staff_balance_ledger`.
5. Open **Admin → Off-day Adjustments** and verify the page loads.
6. Test one staff account on a scheduled off day: clock in, start/end a break, clock out, then confirm a pending review appears.
7. Do not import `database/schema.sql` into the production database; it recreates the database. Use the migration only.

The migration does not create balances for historical leave or attendance automatically. Admin decisions create ledger entries going forward.
