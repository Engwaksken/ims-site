# IMS Site

PHP information management system (programs, projects, KPIs, workplans, HR, hub operations, procurement).

## Setup

1. `composer install`
2. Copy `.env.example` to `.env` and fill in the database, `APP_KEY`, mail and API settings.
   Set `APP_ENV=production` and `APP_DEBUG=false` on the server. Never change `APP_KEY` once
   set — stored secrets are encrypted with it.
3. Import the database, then run every file in `database/migrations/` (idempotent).
4. Optional cron for carrying pending daily tasks to the next day:
   `5 0 * * * php /path/to/legacy/cron/carry-over-pending-tasks.php`

## Notes

- `.env`, database dumps, backups, uploads and logs are deliberately not in version control.
- Uploaded files are served only through `legacy/download-file.php` (login + per-file permission checks).
