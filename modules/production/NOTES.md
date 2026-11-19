# Production workers — porting notes

## Ported
- Workers have their own register, never the office employee master: own codes (prefix + 4 digits, never reused), name,
  name in local script, father's / husband's name, gender, mobile, department, branch, joining date, card / token no.,
  skill, paid by; duplicate card no., mobile, or the same name at the same branch refused (unless "a different person");
  switched off with a reason, never deleted.
- Hours grid per month: one cell per worker per day (9.5 / 9:30 / "9 h 30"; blank = not worked; no day still to come),
  only changed cells saved. Piece work by type, a team's pieces split evenly; lines taken off with a reason.
- Hourly rate history per worker and piece-rate history per type: each day is paid at the rate in force that day.
  Overtime = hours above the standard day × the OT multiplier. Money in whole minor units, hours in hundredths — no floats.
- Advances ledger (opening, given with its payment reference, recovered, waived); recovery never above the outstanding.
- Separate monthly pay run (the staff payroll is untouched): other earnings, advance recovery, three typed deductions
  with organisation-named labels, other deductions; red flags (no rate on a worked day, pieces with no rate, recovery
  above the outstanding, net below zero) block Confirm; confirming only once the month has ended, by a login that sees
  every branch, snapshots the month, locks its hours / pieces / inputs / rates and posts the recoveries to the ledger
  once; Reopen needs a reason and switches those recoveries off (kept). CSV of the month.
- Entry roles (production heads) see hours and pieces only — never a rate, wage or advance — in their ticked branches
  (none ticked = nothing). Change log; audit family `prd_` chained.

## Became a setting
- Entry / money / view roles; standard day; OT multiplier (1 = same as the hourly rate); first month of production pay;
  worker code prefix; the three deduction labels.

## Left out
- The reference's piece-rate types and rates (an organisation's own sheet), worker ID documents / photos / bank
  details, the printed worker form, issued items (quarters, uniform, safety gear), statutory deductions worked out by
  rule (typed per worker here, as in the reference trial), the interim Excel workbook.
