# Full & final — porting notes

## Ported
- Settlement for a leaver: pay for the days up to the last working day, month by month (a month can be marked
  "full month as agreed"), other earnings and deductions, what was already paid, balance, amount in words.
- Leave adjustment: counted leave taken beyond the pro-rated share of the year is cut at monthly gross ÷ calendar days
  of the leaving month (unrounded days, shown to 2 decimals). Nobody on probation at the last working day gets the cut.
  HR may type the days instead; the worked-out figure stays beside them.
- Tax by state each month on what is payable for it (the leaving month carries the cut and extras), from the country
  pack's deductions other than provident fund and state insurance — e.g. professional tax in India.
- Prefilled from the records (HR checks every field): employee master (name, designation, department, branch, joining,
  last day, confirmation → probation), payroll profile and CTC → monthly gross, approved leave of the counted types,
  months after the last locked payroll month.
- Steps Draft → Checked → Approved → Paid (payment reference); sent back to draft with a reason; only a draft changes;
  switched off with a reason, never deleted; one live settlement per person; log append-only.
- Documents (print / save as PDF, DRAFT stamp until approved): settlement statement, acknowledgement. Bank line (CSV)
  after approval from the verified KYC bank account.

## Became settings / organisation data
- Leave types counted and days a year (default: every paid type with a yearly allowance, from the Leave module).
- Year start = Admin → Organisation. Paying unused leave (off by default) and its limit. Roles that prepare / approve.
- Acknowledgement wording. Company name, address and logo from the organisation settings.

## Not ported (one company's choices in the reference build)
- Named leavers, fixed company details and signatories, a fixed 16-day year, one bank's file layout.

## Access
- Amounts only for logins with pay data; nobody prepares, checks, approves or prints their own; the checker and approver
  are never the preparer. Audit lines (`fnf_` chained) carry ids, never amounts.

## Later
- Carrying the adjusted leave days onto the payroll line of the leaving month; separation agreement; gratuity.
