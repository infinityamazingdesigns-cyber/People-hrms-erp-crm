# WorkNest HR

People, HRMS, ERP and CRM for any organisation — one product, set up per organisation by its Super Admin.
PHP 8 + MySQL / MariaDB, no build step: runs on ordinary shared hosting.

## What's in it
- **Administration (ready):** organisation profile (name, logo, colours, country, time zone, currency, year start),
  branches with their spellings, shifts and weekly offs, departments, designations, **modules on / off with role access
  and per-person exceptions**, users & roles with branch scope, tamper-evident audit log, in-app project guide.
- **56 modules** in 13 groups — people, hiring, onboarding, time & attendance, pay & expenses, performance, assets,
  communication, front desk, sales & CRM, operations & supply, insights. See [docs/MODULES.md](docs/MODULES.md).
- **Owner lock:** the person who installs it is the permanent owner — always an active Super Admin, never removable.

## Quick start
1. Upload the files; create an empty MySQL database.
2. Open `https://your-site/install.php`, fill in the database, organisation name and **owner** details.
3. Sign in as the owner → **Project guide** → follow the setup checklist.

Details: [docs/SETUP.md](docs/SETUP.md) · Guide: [docs/PROJECT_GUIDE.md](docs/PROJECT_GUIDE.md) ·
Porting status: [docs/ROADMAP.md](docs/ROADMAP.md) · Security: [docs/SECURITY.md](docs/SECURITY.md)

## Developing
```
php tests/run.php          # core tests
python3 tools/guard.py     # must say "clean" before every commit
php tools/gen_modules.php  # refresh module folders / specs from config/modules.php
```
House rules for contributors (people and Claude): [CLAUDE.md](CLAUDE.md).
