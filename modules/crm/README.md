# CRM & orders

**Key:** `crm` · **Group:** sales · **Status:** Porting from the reference build

Customers, products, orders, dispatch, tracking page, customer messages, same-day KPI.

## Access
- Switched on by the Super Admin on **Admin → Modules**; needs nothing.
- Default roles when first switched on: Super Admin, CEO / Director, Sales. The Super Admin can change this and add per-person exceptions.
- Every page starts with `module_require('crm')`; every API with `module_require('crm', true)`.

## Reference build (port source)
- `crm/`

## Porting rules
1. Take the logic, never the data: no names, employee codes, phone numbers, emails, addresses, amounts, GSTIN / PAN / ID numbers, bank details or company wording.
2. Every organisation value (company name, branches, states, shifts, holidays, rates, thresholds) comes from `org()`, `branches()` or the module's own settings — never a constant.
3. Rules that were decided for one organisation (named people, one-off seeds, fixed lists) become settings with neutral defaults.
4. Country-specific statutory rules go into a rule pack (`docs/RULE_PACKS.md`), not into module code.
5. Tests use synthetic data only (`TEST –` names); add them under `tests/cases/`.
6. `python3 tools/guard.py` must pass before the commit.
7. Record what was ported and what became a setting in `modules/crm/NOTES.md` (this README is regenerated).
