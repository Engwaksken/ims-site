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

## PowerPoint previews

The Documents module previews `.pptx` files locally using PHP's ZIP, DOM/XML and
Fileinfo extensions. Slides appear in presentation order with text, tables,
embedded PNG/JPEG/GIF/WebP images and slide navigation. This is a simplified
content preview; exact layouts, charts, animations and unsupported image formats
require downloading the original presentation. Legacy `.ppt` and `.odp` remain
download-only. Compressed documents use the existing decompression flow.

Run the preview regression checks with `php tests/pptx-preview-test.php`.
