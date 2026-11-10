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
