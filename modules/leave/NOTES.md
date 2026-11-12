# Leave — porting notes

## Ported (logic only)
- Apply for a full day, first / second half, a range (first day full or second half, last day full or first half), or a
  time permission inside the shift (late coming, early going, short leave out & back).
- No clash with the person's own waiting or approved leave on any day, even inside a longer application; every
  clashing date is named.
- Route reporting manager → HR; no manager login → HR. HR may decide while it is with the manager (manager skipped),
  also for withdrawal requests. Nobody decides their own; reject / keep need a note.
- Withdraw while waiting; approved leave needs a withdrawal request (manager → HR), the leave stays until HR agrees.
  A passed day with no attendance: only the Super Admin / HR Manager may withdraw it.
- HR applies for people in their branches, records leave approved outside, sets the final type; never their own.
- Month closed for payroll: no new leave or withdrawal touching it; waiting leave blocks the close.
- Balance per type: days a year pro-rated from joining (or confirmation), HR's changes (append-only, with reason),
  approved leave of that type; waiting leave shown apart.
- Attendance shows approved leave and waives late / early as the reference build did.
- "Work handed over to": active logins, name + department only.

## Became settings / organisation data
- Leave types (code, name, paid, days a year or no limit, pro-rated, after confirmation, birthday only, open to ask)
  are set by HR — only "Without pay" comes installed. No company's leave policy is in the code.
- How far back / ahead, longest application, counting weekly offs and holidays, showing balances, letting people
  choose the type. The leave year follows the organisation's year start.

## Not ported
- Named people / codes lists, one-off seeds and fixes; the payroll leave bank and its sheet import (payroll module);
  balance trial switches; browser notices to the cover person (notify module).

## Later
- Payroll: leave in date order across types, encashment, carry-forward, sandwich rules — in the payroll rule pack.
- Clash check from attendance correction requests on full-day leave.
