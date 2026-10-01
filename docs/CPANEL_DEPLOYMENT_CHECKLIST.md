# cPanel Production Deployment Checklist

This checklist is for the internet-facing deployment of the Staff Attendance System. Use a temporary subdomain for staging before changing the live domain.

## Hosting requirements

- PHP 8.0 or later
- PHP extensions: `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`
- MySQL/MariaDB with permission to create the application database
- HTTPS certificate and forced HTTPS redirect
- cPanel Cron Jobs
- Writable `storage/` and `uploads/profiles/` directories
- Apache rules enabled (`.htaccess` support)

## cPanel values to obtain

- Domain and document root
- cPanel username and application path
- PHP version and selected extensions
- Database name, database user, password, host and port
- Server timezone (the application policy is `Asia/Kuala_Lumpur`)
- Cron PHP binary path
- Backup and restore method

## Application configuration

Set these as cPanel environment variables if the host supports them, or in the host's protected configuration mechanism:

```text
LASTO_APP_ENV=production
LASTO_BASE_PATH=/              # use the real subdirectory if not at the domain root
LASTO_DB_HOST=127.0.0.1
LASTO_DB_PORT=3306
LASTO_DB_NAME=<production database>
LASTO_DB_USER=<dedicated database user>
LASTO_DB_PASS=<long random password>
LASTO_PUBLIC_ORIGIN=https://<real-domain>
```

Do not use the MySQL `root` account. Do not place secrets in a committed file.

## Upload and protect

1. Upload the application files using File Manager or SFTP.
2. Keep `config`, `includes`, `database`, `cli`, `tests`, `docs`, `scratch`, and `storage` inaccessible over HTTP.
3. Keep `uploads/profiles` writable but prevent PHP execution there.
4. Keep backups outside the document root.
5. Confirm `.htaccess` rules are honored by requesting protected paths and expecting denial.

## Database

1. Create a blank production database and dedicated user.
2. Import `database/schema.sql` only into the blank database.
3. Import `database/seed.sql` only for the initial application policy/settings.
4. Do not import UAT accounts or `storage/uat-manifest.json`.
5. Create the first real admin account, change its password, and create security questions.

## Cron

Create a daily cPanel Cron Job at 00:05 Malaysia time. Use the host's PHP binary and absolute application path:

```text
05 00 * * * /usr/local/bin/php /home/CPANEL_USER/path-to-app/cli/daily_attendance.php >> /home/CPANEL_USER/logs/attendance-cron.log 2>&1
```

Replace both paths after checking cPanel's actual PHP binary and home directory. Run a manual catch-up test with `--from YYYY-MM-DD --to YYYY-MM-DD` and retain the exit log.

## Go-live checks

- HTTPS works and HTTP redirects to HTTPS.
- `php cli/preflight.php` has no failures.
- No active account uses `Admin@123` or `Staff@123`.
- No `uat_` users remain.
- UAT manifest is removed from `storage/`.
- Backup is copied outside the web root and restored into a separate empty database.
- Admin-only approval for leave and corrections is verified.
- English, Bahasa Melayu, and Chinese are checked on desktop and mobile.
- PDF/CSV reports download correctly.
- Login, logout, session expiry, password setup, and security-question recovery work.

