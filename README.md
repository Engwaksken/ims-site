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

Viewing a `.ppt` or `.pptx` in Documents converts it to PDF with headless
LibreOffice and opens it in the browser's existing PDF viewer. Conversion runs
after the document permission check. Downloads return the original presentation.
Compressed presentations use the existing decompression flow.

Install LibreOffice Impress on the web server (Debian/Ubuntu:
`sudo apt-get install libreoffice-impress`) and enable PHP `proc_open`. Set
`LIBREOFFICE_BINARY` in `.env` to the executable path, for example
`/usr/bin/soffice` or `"C:/Program Files/LibreOffice/program/soffice.com"`.
Install the presentation's fonts on the server for consistent PDF layouts.

Each conversion uses a private temporary workspace and isolated LibreOffice
profile, cleaned up after the request. `POWERPOINT_PDF_TIMEOUT` defaults to 60
seconds (maximum 180); PDF previews are limited to 32 MB. Failed conversions
show the download fallback and log the cause in the server error log.

Run conversion regression checks with `php tests/powerpoint-pdf-test.php`.
With LibreOffice installed, run the real two-slide PPT/PPTX conversion check:
`php tests/powerpoint-pdf-integration-test.php /usr/bin/soffice` (use the Windows
executable path on Windows).
