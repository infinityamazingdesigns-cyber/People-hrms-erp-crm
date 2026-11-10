# Biometric machines

**Key:** `biometric` · **Group:** time · **Status:** Porting from the reference build

ADMS push from machines of any make, vendor cloud APIs, key-based punch API.

## Access
- Switched on by the Super Admin on **Admin → Modules**; needs Attendance (`attendance`).
- Default roles when first switched on: Super Admin. The Super Admin can change this and add per-person exceptions.
- Every page starts with `module_require('biometric')`; every API with `module_require('biometric', true)`.

## Reference build (port source)
- `bio_machines.php`
- `lib/bio_devices.php`
- `api/iclock.php`
- `api/bio_punch.php`
- `lib/etime.php`

## Porting rules
1. Take the logic, never the data: no names, employee codes, phone numbers, emails, addresses, amounts, GSTIN / PAN / ID numbers, bank details or company wording.
2. Every organisation value (company name, branches, states, shifts, holidays, rates, thresholds) comes from `org()`, `branches()` or the module's own settings — never a constant.
3. Rules that were decided for one organisation (named people, one-off seeds, fixed lists) become settings with neutral defaults.
4. Country-specific statutory rules go into a rule pack (`docs/RULE_PACKS.md`), not into module code.
5. Tests use synthetic data only (`TEST –` names); add them under `tests/cases/`.
6. `python3 tools/guard.py` must pass before the commit.
7. Record what was ported and what became a setting in `modules/biometric/NOTES.md` (this README is regenerated).
