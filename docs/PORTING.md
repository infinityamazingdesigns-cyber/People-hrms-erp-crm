# Porting from the reference build

WorkNest HR's modules are ported from a private reference build that ran for one organisation, with the owner's
permission. The reference repository is never copied wholesale and **none of its data comes across**.

## What comes across
- Logic and rules: approval flows, calculations, validations, state machines, screens' structure.
- Database designs (re-written as migrations here, neutral names).
- Tests, rewritten with synthetic data.

## What never comes across
- Any record: people, codes, contacts, addresses, salaries, IDs, bank details, customers, vendors, prices.
- The organisation's name, logos, mascots, wording, domains, branch / city / state lists, holiday lists.
- One-off decisions about named people (who sees salary, fixed leaver lists, seeds, named exceptions).
- Keys, tokens, webhook secrets, config.

## How a module is ported
1. Read the reference files listed in `modules/<key>/README.md`.
2. Write the module under `modules/<key>/` (pages) and `lib/` or `modules/<key>/lib/` (rules), using `org()`,
   `branches()`, module settings and rule packs for anything organisation-specific.
3. Replace constants that held decisions (thresholds, rates, lists) with settings that have neutral defaults.
4. Add migrations, tests (`tests/cases/<ID>.php`), update the module README (what is ported, what became a setting)
   and `status` in `config/modules.php` (`port` → `built`).
5. Run `php tests/run.php` and `python3 tools/guard.py`; open a pull request.

## Recurring sync
A scheduled task reviews new work in the reference build, and ports **features only** (never data) into
WorkNest HR on a branch, opening a pull request for review. See `docs/ROADMAP.md` for order.

## Port order
Employee master → Org chart → My profile / KYC → Holidays → Attendance → Shifts → Leave → Onboarding / Probation →
Letters → Email & 2-step → Payroll (with the India rule pack) → Payslips → Claims → Assets → Tasks → Chat →
Announcement board → Recruitment / Careers → Visitors → PMS / Conduct / Training → CRM suite → Stock → Purchase →
the rest.
