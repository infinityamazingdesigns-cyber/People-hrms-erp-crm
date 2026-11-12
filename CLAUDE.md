# House rules — WorkNest HR

A product for **any** organisation. Nothing in this repository may belong to one organisation.

## Never in the repository
- No organisation's data: names of people, employee codes, phone numbers, emails, addresses, salaries or any amount
  tied to a person, tax / national / bank / GST numbers, customer or vendor records, policies written for one company.
- No secrets: `config.php`, `.env`, keys, tokens, passwords, exports, backups.
- `python3 tools/guard.py` must print `clean` before every commit; CI refuses otherwise. Keep a local `.guard-deny`
  (one word per line, never committed) with names and places from any reference build you port from.

## The owner
- `users.is_owner = 1` is the installer. Always holds SUPERADMIN (`user_roles()` adds it), always active, never
  deleted. `owner_guard()` decides every action on the owner's login; every new screen that changes a login calls it.
  Only the owner changes the owner's own password. Tests O01–O04.

## Modules
- `config/modules.php` is the one catalog. A new module = one entry there + `php tools/gen_modules.php`.
- Every module page starts with `module_require('<key>')`, every API with `module_require('<key>', true)`.
  Access = module ON (Admin → Modules) **and** an allowed role or a per-person allow, **and** no per-person deny.
- Menus list only `module_menu($u)`. Never link to a module page from somewhere that skips the gate.
- Own-records pages (My letters …) use `module_require_self('<key>')`: every signed-in login while the module is on,
  no per-person deny; the page shows only the login's own records. They are declared as `self` in the catalog and
  listed under "For me".
- Public pages (no sign-in) call `module_public('<key>')` first, are throttled, send `noindex`, and show only what
  the page's rule allows: `modules/letters/respond.php` (answer link), `modules/letters/verify.php` (verification).
- `needs` keeps dependencies honest: switching on pulls them in; switching off is refused while a dependant is on.

## Organisation values come from settings
- Company name, logo, colours, country, time zone, currency, year start → `org()`.
- Branches (with other spellings) → `branches()`, `branch_label()`. Never type a branch, state or city in code.
- Country rules (social security, provident fund, tax slabs, professional tax, leave law) → rule packs
  (`docs/RULE_PACKS.md`), chosen by `org('country')` and the branch's `state`.
- A rule decided for one company in the reference build becomes a setting with a neutral default.

## Data protection
- Deny by default. Employee-only logins (`self_only()`) see only their own records.
- Data classes (`config/rbac.php` → `data`): pay and bank only for the listed roles, widened person by person by the
  Super Admin. ID numbers and bank details are sealed at rest (`seal()`), shown masked (`mask()`).
- Nothing important is hard-deleted: switch off with a reason; logs are append-only.
- Audit lines carry ids, field names and counts — never values of ID numbers, bank details or amounts.
- Sensitive audit families are hash-chained (`AUDIT_CHAINED_PREFIXES`); add a prefix when a new family lands.

## Code
- PHP 8, `declare(strict_types=1)`, PDO prepared statements only, every table `DEFAULT CHARSET=utf8mb4`.
- Schema changes are new files in `db/migrations/` (never edit an applied one). No DDL inside a transaction.
- Forms carry `csrf_field()`; handlers call `check_csrf()`. Output through `h()`.
- Pure rule functions + thin pages, so rules are testable without a database. Tests go in `tests/cases/<ID>.php`.
- Synthetic data only in tests (`TEST –` names, example.com addresses).
- `install.php` never deletes itself (hosting malware scanners remove self-deleting PHP).

## Porting from the reference build
See `docs/PORTING.md`. Logic only, never data; one module per pull request; spec in `modules/<key>/NOTES.md`
updated with what was ported and what became a setting.
