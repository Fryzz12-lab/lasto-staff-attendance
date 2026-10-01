# Staff Attendance & Workforce Management System

Production-oriented internal system for a retail/store company. Built with native PHP 8+, MySQL, Bootstrap 5, and vanilla JavaScript.

## Features

- Role-based access: **Admin/Manager** and **Staff**
- Clock In / Clock Out with automatic Late detection
- Break management: Lunch, Solat (×3), Short Break (×3)
- Distinguishes **shift duration** vs **actual working time** (excludes breaks)
- Work schedules & per-staff weekly off days (configurable, not hard-coded)
- Attendance correction workflow with audit trail
- Optional Day Off / Leave requests
- Admin dashboards, reports, CSV export, settings
- UI language switcher: **English / 中文 / Bahasa Melayu** (page content primarily English)
- Timezone: `Asia/Kuala_Lumpur`

## Requirements

- XAMPP (Apache + MySQL/MariaDB + PHP 8+)
- Browser (desktop / tablet / mobile)

## Setup (XAMPP)

1. Place this folder at:
   ```
   C:\xampp\htdocs\agy-staff-attendance
   ```
   (Already created if you followed the project setup.)

2. Start **Apache** and **MySQL** in the XAMPP Control Panel.

3. Open phpMyAdmin: http://localhost/phpmyadmin

4. Import database for a **new installation only** (`schema.sql` drops existing application tables; never import it over live data):
   - Import `database/schema.sql`
   - Then import `database/seed.sql`

   Or via MySQL CLI:
   ```bash
   mysql -u root < database/schema.sql
   mysql -u root < database/seed.sql
   ```

5. Check `config/config.php`:
   - Set `LASTO_DB_USER`, `LASTO_DB_PASS`, and `LASTO_DB_PORT` in the server environment (default local port: **3306**).
   - `LASTO_BASE_PATH` defaults to `/agy-staff-attendance`.

6. Ensure `uploads/profiles` is writable by Apache.

7. Open:
   ```
   http://localhost/agy-staff-attendance/
   ```

## Demo Accounts (change before production!)

| Role  | Username | Password   |
|-------|----------|------------|
| Admin | `admin`  | `Admin@123` |
| Staff | `user`   | `Staff@123` |

### Staff accounts and password recovery

For an existing database, apply `database/migration_account_recovery.sql` once before using the new pages. Fresh installations already have these tables in `database/schema.sql`.

An admin creates staff with a full name, username, and email in Staff Management. Staff set their own password through a single-use invitation link (48-hour expiry). When email delivery is unavailable, the admin sees the link once and can hand it to the staff member after checking their identity. A hand-delivered invitation does **not** verify the email address.

Staff can edit their job position, phone, and password in My Profile. Username, full name, employee ID, joining date, and recovery email are managed by Admin. Email changes invalidate outstanding recovery links and require a new verification link. Staff can change a password while logged in by entering their current password. The current Forgot Password page uses two security answers; admin-assisted one-time reset links are also available. Security answers are weaker than verified recovery links: protect login and recovery with server-side rate limiting before public deployment.

Emails already stored before the migration start unverified. Admins can send a verification link from Staff Management after mail delivery is configured. The seeded admin email is a placeholder and must be replaced with a real address before it can be verified.

Automatic email delivery is disabled until a transport is chosen. To use PHP's configured mail transport, set the environment variables `LASTO_MAIL_TRANSPORT=php_mail`, `LASTO_MAIL_FROM` to a real company mailbox, and `LASTO_PUBLIC_ORIGIN` to the public HTTPS origin (for example, `https://attendance.example.com`). Configure and test PHP `mail()`/sendmail separately. Set the public origin for hand-delivered links too if staff open them on another device; the default `http://localhost` only works on the server computer. Without working delivery, email links cannot be delivered automatically; the security-question page and admin-assisted handoff are separate recovery paths. WhatsApp delivery is not yet integrated.


## Default Company Rules (fixed policy; individual weekly off days)

- Working hours: **10:00 AM – 8:00 PM**
- Late after scheduled start
- 6 working days / week, 1 weekly off day (per staff)
- Lunch: 60 minutes
- Solat: 20 minutes × 3
- Short Break: 10 minutes × 3
- Target working time: **7 hours 30 minutes** (net of breaks)

## Project Structure

```
config/          Application & DB config
includes/        Auth, helpers, i18n, layout, attendance logic
admin/           Admin pages
staff/           Staff pages
api/             AJAX endpoints (clock / break)
assets/          CSS, JS, language files
database/        schema.sql + seed.sql
uploads/         Profile photos
index.php        Login
logout.php       Logout
```

## Security Notes

- Passwords use `password_hash()` / `password_verify()`
- PDO prepared statements everywhere
- CSRF tokens on state-changing requests
- Session regeneration on login
- Role checks on every protected page
- Set `APP_ENV` to `production` in `config/config.php` before go-live
- Change all demo passwords

## Testing Checklist

- [ ] Admin / Staff login & logout
- [ ] Clock In on time / late / duplicate
- [ ] Start & end Lunch, Solat ×3, Short ×3
- [ ] Overlapping break blocked
- [ ] Clock Out / duplicate Clock Out blocked
- [ ] Off day handling
- [ ] Correction request approve/reject
- [ ] Staff profile edit & password change
- [ ] Admin staff / schedule / settings
- [ ] Reports + CSV export
- [ ] Unauthorized URL access blocked
- [ ] Mobile layout usable

## License

Internal company / internship project use.

## Repeatable UAT dataset

Run `php cli/seed_uat.php` after importing the schema and base seed. It creates only accounts listed in `storage/uat-manifest.json`, four weeks of varied activity, and a full UAT set using Malay and Chinese names. It never clears real staff records. Use `php cli/seed_uat.php --reset` to replace only the previously generated UAT accounts. Use `php cli/seed_uat.php --remove` before deployment to remove the UAT accounts and their records while preserving other staff. Each run creates a backup first. The old `database/update_today_records.php` script is retired because it generated inconsistent totals.

### UAT scenarios

1. Read the current UAT password and account list from `storage/uat-manifest.json`. Sign in as `uat_admin`; check dashboard, Daily Attendance, Reports, Breaks, Leave Requests, and Corrections. Confirm Malay and Chinese names appear.
2. Sign in as `uat_nurul`; use the date picker to inspect completed, late, absent, day-off, and WFH records.
3. Submit a leave request as staff, approve one and reject another as admin, then confirm both histories.
4. Submit attendance corrections; approve one and reject one as admin. Verify the audit trail and detail page.
5. Try duplicate clock-in, duplicate clock-out, and overlapping breaks. Each must be refused with a clear message.
6. Check widths around 375px, 768px, and desktop. Navigation, tables, forms, and action buttons should remain usable through the existing responsive breakpoints.

The UAT scenario details are also available in `docs/UAT_SCENARIOS.md`. Do not deploy with accounts whose usernames begin with `uat_`.

## Validation and deployment

- `php tests/company_policy.php`: isolated database regression tests (requires database create/drop permission).
- `php tests/http_smoke.php`: local Apache login, role, setup, CSRF and private-file checks using the existing UAT manifest. Run before manually changing UAT passwords or account states.
- `php cli/maintenance.php cleanup`: back up the database, then remove only the explicitly retired columns and settings. Historical attendance remains intact.
- `php cli/preflight.php`: read-only deployment checks; exit code 1 means a blocking check failed. Development and UAT installations are expected to fail production checks.
- Follow `docs/DEPLOYMENT.md` for the remaining go-live gates. Passing automated checks alone does not certify deployment readiness.
