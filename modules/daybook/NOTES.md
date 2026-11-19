# Branch day book — porting notes

## Ported
- One daily cash sheet per branch, filled in with each person's own login: bills (bill / challan no., party, city,
  amount, freight), dispatch (picked from the branch's bills, direct or by transporter + LR, local transport and
  freight with how they were paid; one dispatch per bill), money received (bill optional; cash / UPI / bank / cheque;
  into a person's own account = reference + screenshot, "with employee" until a viewer settles it) and petty cash
  (category, purpose, bill no., receipt or why there is none).
- Opening = the previous day's closing (cash counted on a closed day, else worked out); a viewer sets the first
  opening or changes one, with a reason. Closing = opening + cash in − cash out.
- Close the day with the cash counted (a note when it differs); blocked while a screenshot / receipt is missing or
  the opening is not set. Closed days are locked; viewers Check or Reopen (reason).
- People enter for their own branch (linked employee record) and ticked branches, today and a few days back, and see
  only their own entries plus the day's totals. Viewers see their branches: entries, branch-wise and person-wise
  totals, cash differences, money still with employees, bills not dispatched, freight by transporter, the change
  register (who, when, before → after), CSV.
- Nothing is deleted: removals keep the row with why; every change is a line in the register. Receipts and screenshots
  by their bytes in the database only, opened only by whoever can see the entry. Party and transporter names are kept
  as suggestions.

## Became settings
- Roles that see every entry (Super Admin / CEO every branch, others their ticked branches, none = every branch), days
  back a person may fill in, the petty cash categories.

## Not ported (later)
- Lines paid by staff for a branch (expense claims) and by head office (vendor bills), branch cash paying back a claim,
  reading the office expense register, syncing to a sheet or accounting software.
