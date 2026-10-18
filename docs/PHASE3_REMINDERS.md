# Phase 3 scheduled reminders

Run every five minutes from cPanel Cron:

```text
*/5 * * * * /usr/local/bin/php /home/ACCOUNT/public_html/cli/notification_reminders.php >> /home/ACCOUNT/logs/notification-reminders.log 2>&1
```

The notification event key prevents duplicates. In-app records are created before Web Push is attempted.
