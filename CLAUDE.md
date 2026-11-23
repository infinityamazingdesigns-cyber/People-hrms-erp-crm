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
- **Access preview** (`admin/view_as.php`, `lib/view_as.php`): only a Super Admin opens the site as one role, through a
  synthetic `preview.<code>` login (switched off, no employee code, no usable password; sign-in refuses it) — never a
  real person's login. The swap is in `current_user()` after the real checks; every request while previewing is
  read-only (`va_guard()`); sign-out ends the preview. Start / stop are audited. Tests VA01–VA10.

## Modules
- `config/modules.php` is the one catalog. A new module = one entry there + `php tools/gen_modules.php`.
- Every module page starts with `module_require('<key>')`, every API with `module_require('<key>', true)`.
  Access = module ON (Admin → Modules) **and** an allowed role or a per-person allow, **and** no per-person deny.
- Menus list only `module_menu($u)`. Never link to a module page from somewhere that skips the gate.
- Own-records pages (My letters …) use `module_require_self('<key>')`: every signed-in login while the module is on,
  no per-person deny; the page shows only the login's own records. They are declared as `self` in the catalog and
  listed under "For me".
- Public pages (no sign-in) call `module_public('<key>')` first, are throttled, send `noindex`, and show only what
  the page's rule allows: `modules/letters/respond.php` (answer link), `modules/letters/verify.php` (verification),
  `modules/careers/apply.php` (job board + application form; public details of open positions only, never pay),
  `modules/crm/track.php` (order tracking by private link; stage, items, consignments — never prices or internal remarks),
  `modules/candidates/portal.php` (applicant's private link: own stage, interviews, letters to their email, own details
  and CV — never pay, panel feedback or other candidates), `modules/biometric/iclock.php` (machine push, only for a
  registered serial; attendance lines only; the one page exempt from the HTTPS redirect) and `modules/biometric/punch.php`
  (punches by API key, kept only as a hash) and `modules/marketing/lead.php` (website-form leads by key, kept only as a
  hash; number / mail / name / campaign only, never message text) and `modules/inbox/wa_hook.php` (WhatsApp Business
  webhook: GET answers only a login's own verify token, POST only when signed with a connected login's app secret;
  throttled; filed under the login whose number Meta names).
- Every page under `modules/` calls one of the gates first (test GT01).
- **Ctrl + K palette** (`assets/cmdk.js`, every signed-in page): searches only the menu links drawn for that login, the
  account links and — behind Team chat's gate, never while previewing — `modules/talk/index.php?people=1`
  (`talk_people()`); a person opens a chat through Team chat's own POST. Nothing typed is sent. Tests CK01–CK06.
- **Module map** (`admin/module_map.php`, `lib/module_map.php`): Super Admin only, read-only — modules by group with
  needs / needed by, roles × modules and data classes, built by `mm_build()` from the catalog, saved module states and
  `config/rbac.php` each time (nothing to keep in step by hand). No person's data, no counts. Tests MM01–MM08.
- **Home page** (`index.php`, `lib/home.php`): each login's own day — today's punches, up to 10 urgent tasks
  (`hm_task_pick()`; the tick posts to Tasks with `back=home`, so Tasks' own done / approval rule runs) and **Waiting
  for you** (`hm_waiting()`: each count from that module's own waiting function, only when `module_allowed()`, HR desks
  also behind their HR rule). Own data only, never an amount. A new module with an approval queue adds one `$try()`
  line there. Tests HM01–HM07.
- **Inbox** (`modules/inbox/`, `lib/inbox.php`): each login's own mail / Cliq / WhatsApp — connections keyed by login +
  service, messages per login, nobody (Super Admin included) sees another login's. Secrets sealed, last 4 shown; mail
  read live and shown sandboxed; replies refused on a staging copy; WhatsApp replies only within 24 h. Every outside
  call through `ib_http()` (injectable). Tests IB01–IB18.
- **Sign-in pictures** (`admin/login_pictures.php`, `lib/login_slides.php`, public `login_slide.php`): up to 5 pictures
  behind organisation-settings rights, each re-drawn on the server (camera details dropped) and kept in the database;
  only pictures on the list are served; removing switches one off. Layout `org('login_layout')`: plain (default) / card
  right / centre / glass panel. Fields, CSRF, the code step and Lite are the same in every layout. Tests LS01–LS09.
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

## Email and sign-in
- Every email goes through `mail_queue()` / `mail_queue_send()` (outbox, retried) or `mail_send_now()` (sign-in codes,
  logged without the body) — never PHP `mail()` or a second client. Mail secrets live in `config.php` only.
- A staging copy (`'stage' => true`) sends nothing.
- 2-step sign-in: the session is signed in only by `login_finish()`, after the code when `tfa_decide()` asks for one.
  Only an HMAC of a code is kept; the trust cookie is signed over the login and its password hash.

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
