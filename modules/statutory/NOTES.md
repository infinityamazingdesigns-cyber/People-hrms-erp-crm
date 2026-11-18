# Statutory compliance — porting notes

## Ported
- Official links by scheme and state, sorted for Employees / Payments / Returns & registrations; "address to confirm"
  until someone ticks it; switched off, never deleted.
- Due-date rules: day of the month after the wage month (31 = last day, "months after" moves it further), only in the
  listed wage months, or one date; kind payment / return / both / task; who does it. Due list: overdue (120 days) and
  the next 30 days, from the first confirmed payroll month unless set.
- Spends pulled from payroll's locked months only (never worked out again): one line per wage month + scheme (+ state)
  with people, employee share, employer share and charges; held salaries left out. A line changed by hand needs a
  reason, keeps its figures and shows payroll's beside it; "Back to payroll's figures". Lines added by hand for months
  before payroll. Payments: date up to today, reference, amount (blank = total), mode; taken off with a reason. Status:
  to pay / due this week / overdue / paid / paid late / part paid / nothing due.
- Filings and tasks marked per period with a reference; taken back with a reason (row kept). Year view by month.
- Registrations and licences with numbers and renewal dates (flagged 60 days ahead).

## Became settings / rule pack
- Schemes, which payslip lines make each scheme, which go by state, and the national links / dues / registrations are
  in the country pack (`statutory`); India seeds EPFO, ESIC, income-tax and labour links, PF / ESI / TDS dues and the
  four national registration numbers. State links and dues are added by the organisation for its own states.
- Roles that manage and that record payments; schemes kept apart by state; the month to follow dues from.

## Not ported (one company's data in the reference build)
- Its state list and offices, city corporation links, state due dates and registrations, named contacts.

## Later
- Insurance cover (employees' compensation), incidents, contractors, employee duties (health check-ups, committees),
  month-close packet with files, reminders, an employee "my part" page, interest estimates on late payments.
