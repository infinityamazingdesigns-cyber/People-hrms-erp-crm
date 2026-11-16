# Payroll — porting notes

## Part 1 (this pull request)
- Rules by month (`pay_rule`, append-only; a version cannot start inside a confirmed month). Country rule pack
  supplies the statutory part: India (PF with ceiling, pension split, insurance / admin charges; state insurance with
  ceiling, rounded up; professional tax by state slabs entered by the Super Admin) and "no statutory rules".
- Pay profile per person (PF member, PF on full wages, state insurance by ceiling / yes / no, PT exempt, paid by) and
  monthly CTC with a from-month (`pay_ctc`, append-only). Off payroll with a reason.
- Structure: Basic % of gross with an optional floor, HRA % of Basic, fixed allowances, Special = rest. Gross worked
  back from CTC when CTC includes employer contributions (setting).
- Paid days from attendance + leave (paid leave types are a setting); part-month joiners / leavers; flags for missed
  punches, leave without a type, part month, no CTC, deductions above pay.
- Steps: inputs (other earnings, TDS, other deductions, hold with reason) → checked by someone else → confirmed by the
  confirm role (CEO by default) after the month ends, snapshot locked → released. Reopen with a reason; earlier
  snapshots kept (versions). Never one's own inputs. CSV export (audited, counts only).
- Pay data class only (Super Admin, CEO; widened person by person).

## Became settings / organisation data
- Every percentage, ceiling, slab, allowance, paid leave type and the confirming role.

## Not ported (company decisions in the reference build)
- One company's sheet method, CTC-from-take-home lists, standing incentives for named people, PT states of named
  people, named own-payslip lists, leave-bank sheet imports.

## Part 2
- Loans and salary advances: amount, monthly recovery from a month; each draft month deducts the recovery or what is
  left; recoveries written at confirmation per snapshot version; closed with a reason.
- Bank file (released months, pay + bank data): people paid by bank, not held, with a verified KYC bank account;
  generic CSV or account / IFSC layout; cells cleaned; who was left out and why; audited with counts.
- Payslips module (own view, PIN) — see modules/payslips/NOTES.md.

## Part 3 (next)
- Statutory returns (PF ECR, ESI), leave bank / encashment, LWF, bonus, arrears.
