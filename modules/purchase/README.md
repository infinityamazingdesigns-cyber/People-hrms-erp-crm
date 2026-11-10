# Purchase & procurement

**Key:** `purchase` · **Group:** ops · **Status:** Porting from the reference build

Requisitions, vendor master, comparisons, PO approval, goods received + QC, vendor scorecards.

## Access
- Switched on by the Super Admin on **Admin → Modules**; needs nothing.
- Default roles when first switched on: Super Admin, Buyer, CEO / Director. The Super Admin can change this and add per-person exceptions.
- Every page starts with `module_require('purchase')`; every API with `module_require('purchase', true)`.

## Reference build (port source)
- `purchase.php`
- `lib/purchase.php`
- `lib/purchase_docs.php`
- `lib/purchase_pipeline.php`

## Porting rules
1. Take the logic, never the data: no names, employee codes, phone numbers, emails, addresses, amounts, GSTIN / PAN / ID numbers, bank details or company wording.
2. Every organisation value (company name, branches, states, shifts, holidays, rates, thresholds) comes from `org()`, `branches()` or the module's own settings — never a constant.
3. Rules that were decided for one organisation (named people, one-off seeds, fixed lists) become settings with neutral defaults.
4. Country-specific statutory rules go into a rule pack (`docs/RULE_PACKS.md`), not into module code.
5. Tests use synthetic data only (`TEST –` names); add them under `tests/cases/`.
6. `python3 tools/guard.py` must pass before the commit.
