# HR dashboard (MIS) — porting notes

## Ported
- One read-only screen of counts, cut to the viewer's branches (Super Admin / CEO: every branch): on the roll today,
  joined and left this month, on notice, attrition (exits ÷ average month-end headcount) and retention over a window,
  headcount / joined / left by month, on the roll by branch and by department.
- Attendance: people who punched today and people on approved leave today.
- Leave: with managers, waiting for HR, withdrawals waiting, on leave today, starting in the next 7 days, days used since
  the start of the organisation's year by leave type (approved leave with no category yet shown apart).
- Probation: ending within the window, reviews waiting, reviews past their date.
- Pending work desk by desk (only modules that are on, zero desks left out): leave, attendance corrections, probation
  reviews, profile updates, KYC & bank, letter requests, expense claims to audit / approve / pay, joiners being onboarded,
  new applications.
- Statutory dues within the window and missed — date, what, scheme, period; never an amount; only for logins that may
  open Statutory compliance.
- Never a name, an employee code or a pay figure; each part shows only while its module is on.

## Became a setting
- Months in the trend, attrition window, probation window, statutory window.

## Left out
- Pay figures and people cost (the reference showed them only for processed months and a narrow list of roles); office
  spend, application sources, asset register and animated layouts. Expense claims and applications are counted
  company-wide (they carry no branch).
