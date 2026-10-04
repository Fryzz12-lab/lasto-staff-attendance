# cPanel Production Deployment Checklist

Target for this install:

- Public URL: `https://attendance.lastotechnology.my/`
- Document root: `/home/lgsubsc1/attendance.lastotechnology.my`
- Do not import into any other database on this cPanel account.

## Hosting requirements

- PHP 8.0 or later (MultiPHP: select 8.1+ if available)
- PHP extensions: `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`
- A **new empty** MySQL/MariaDB database and a dedicated user (not `root`)
- AutoSSL / HTTPS on this subdomain
- Cron Jobs
- Writable `storage/` and `uploads/profiles/`
- Apache `.htaccess` honoured

## Configuration on the server

Do **not** rely on cPanel “environment variables” alone. Cron does not read `.htaccess` `SetEnv`. After upload, copy `config/local.php.example` to `config/local.php` **on the server** and fill the real database name, user, and password from MySQL Databases.

```text
LASTO_APP_ENV=production
LASTO_BASE_PATH=                 (empty: site is at domain root)
LASTO_DB_HOST=localhost
LASTO_DB_PORT=3306
LASTO_DB_NAME=<cPanel database, often prefixed lgsubsc1_>
LASTO_DB_USER=<cPanel database user>
LASTO_DB_PASS=<long random password>
LASTO_PUBLIC_ORIGIN=https://attendance.lastotechnology.my
```

Do not commit `config/local.php`. Do not use the MySQL `root` account.

## Upload and protect

1. Upload only the paths listed in the deployment notes (see `deploy-exclude.txt`).
2. Confirm HTTP 403 for `/config/`, `/includes/`, `/database/`, `/cli/`, `/storage/`, `/docs/`, `/tests/`.
3. Confirm `/uploads/profiles/` cannot execute PHP.
4. Keep SQL backups **outside** this document root (for example `/home/lgsubsc1/backups/attendance/`).

## Database

1. Create a blank database in cPanel MySQL Databases. Do not reuse WordPress or other sites’ databases.
2. With that database selected in phpMyAdmin, import `database/cpanel_schema.sql`, then `database/cpanel_seed.sql`.
3. Keep both import files on your computer; they do not belong in the public document root. Check that the selected database has zero tables before importing. The cPanel schema now fails if tables already exist.
4. Do **not** import `database/schema.sql` (it issues `CREATE DATABASE staff_attendance`) or `database/seed.sql` (demo accounts).
5. On the server, run `php cli/create_admin.php --username=... --name='...' --email=...` and enter the password when prompted. Do not put a password in the command line. Use a private terminal, since input masking depends on the terminal's `stty` support.
6. Sign in and set security questions.

## Cron

```text
05 00 * * * /usr/local/bin/php /home/lgsubsc1/attendance.lastotechnology.my/cli/daily_attendance.php >> /home/lgsubsc1/logs/attendance-cron.log 2>&1
```

Confirm the PHP binary path in cPanel. `config/local.php` must exist so Cron uses the same database. Test once with `--from YYYY-MM-DD --to YYYY-MM-DD`.

## Go-live checks

- HTTPS works; HTTP redirects to HTTPS for this host.
- `php cli/preflight.php` has no FAIL.
- No `Admin@123` / `Staff@123` / `uat_` accounts.
- Login, logout, clock in/out, leave, corrections, PDF/CSV, and mobile layout checked.
- Login rate limiting is configured at the server/proxy (the app has no persistent brute-force lock).
