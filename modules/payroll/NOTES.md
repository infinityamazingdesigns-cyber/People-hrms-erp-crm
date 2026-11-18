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

## Part 3
- Returns from a confirmed / released month's snapshot only (never worked out again). Which returns exist comes from
  the country pack (`returns`): India = PF ECR text (11 fields, `#~#`, UAN = KYC social ID, 12 digits) and the ESI
  monthly sheet (IP = KYC health ID, 10 digits; reason 2 + last working day for leavers in the month, 1 for zero days).
  Everyone gets "Deductions by state" (people, professional tax, labour welfare fund). People without the number are
  listed (from KYC hints, nothing unsealed) and left out; numbers are unsealed only for the download. Each download is
  logged in `pay_return_log` and audited with counts only.
- The pack now reports its wage bases per line (`wages`: PF, pension, ESI) so returns use the exact figures; lines locked
  before this fall back to Basic up to the usual ceiling.
- Labour welfare fund per state on Payroll → Rules ("employee employer months", e.g. "6 18 6,12"), employee share
  deducted or paid by the employer (setting). Never part of working CTC back to gross.
- Arrears: a locked month (last 12) worked out again from today's attendance, leave and rules, never saved; earnings that
  differ are offered on the draft month — Pay / Recover in this month, once per person per snapshot version, worked out
  again on the server (never from the form), not on one's own pay. Paid arrears add to gross, recovered ones are a
  deduction; statutory is not worked out on them (flagged). Taken off with a reason (`pay_arrear`, never deleted).

## Later
- Leave bank / encashment, statutory bonus, gratuity provision, TDS projection.
