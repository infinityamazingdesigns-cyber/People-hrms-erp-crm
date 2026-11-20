# Setup

## Requirements
PHP 8.1+ (pdo_mysql, openssl, mbstring, fileinfo), MySQL 5.7+ / MariaDB 10.4+, HTTPS. Apache with `.htaccess`
(shared hosting works) or nginx with equivalent rules (deny `config.php`, `lib/`, `db/`, `tools/`, `tests/`, `data/`, `docs/`).

## Install
1. Create an empty database and a user with full rights on it.
2. Upload the repository to the web folder (or deploy from GitHub — below).
3. Open `/install.php`: database details, organisation name, **owner** name, email and password.
   It writes `config.php` (with a random `seal_key`), creates the tables, the owner and their Super Admin role.
4. `install.php` then answers "Not found". Keep `config.php` out of Git; back it up — losing `seal_key` makes sealed
   ID / bank data unreadable.

## Scheduled jobs (cron)
Each job is CLI-only (`tools/` is blocked from the web), does nothing while its module is off, and is safe to run more
often than listed — every reminder is sent once per item.

| Job | How often | What it does |
|---|---|---|
| `php tools/mail_drain.php` | every 5 minutes | Sends queued email and retries failures (Email & 2-step). |
| `php tools/task_remind.php` | every minute | Task reminders that are due (Tasks). |
| `php tools/mv_alerts.php` | every 5 minutes | Staff out longer than the limit or at shift end (Staff movement). |
| `php tools/vb_repeat.php` | hourly | Monthly drafts of repeating vendor bills, bills falling due (Vendor bills). |
| `php tools/qc_alerts.php` | hourly | Overdue CAPA actions, cases with no update, cases nobody took (Complaints & CAPA). |
| `php tools/ct_alerts.php` | daily | Agreements and party documents ending soon (Third parties & contracts). |

Example crontab (`/path` = the site folder):
```
*/5 * * * * php /path/tools/mail_drain.php
* * * * *   php /path/tools/task_remind.php
*/5 * * * * php /path/tools/mv_alerts.php
7 * * * *   php /path/tools/vb_repeat.php
13 * * * *  php /path/tools/qc_alerts.php
20 6 * * *  php /path/tools/ct_alerts.php
```

## Staging copy
A second folder + database, `config.php` with `'stage' => true`: no email, WhatsApp or notifications leave it and
a Staging bar shows. Use it before switching new modules on in the live site.

## Deploy from GitHub
`.github/workflows/checks.yml` runs on every push and pull request. A deploy workflow (rsync over SSH) can be added
with repository secrets `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_KEY`, `DEPLOY_PATH`; it must exclude `config.php`,
`data/` and `uploads/`.

## Local development
```
mysql -e "CREATE DATABASE wn_dev CHARACTER SET utf8mb4"
php -S 127.0.0.1:8080        # then open http://127.0.0.1:8080/install.php and tick "Local test machine"
```
