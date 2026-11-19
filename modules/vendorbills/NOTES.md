# Vendor bills (HO) — porting notes

## Ported
- Branch bills paid from head office (rent, electricity, internet …): branch, kind, vendor, vendor's bill / account
  no., bill date, month, amount, due date, where to pay, note, bill copy (or the reason there is none). Draft or send.
- Route: checked → approved → paid with paid on, mode and a transaction reference or a payment slip (a slip can be
  added later). The checker or approver returns it with a note; the approver can reject with the reason. Nobody
  checks, approves or pays a bill they entered. Drafts, returned bills and bills still waiting to be checked can be
  withdrawn (kept).
- The same vendor bill number for the same branch and vendor is refused; a second bill of the same kind, vendor and
  month is flagged to the checker.
- Repeating bills: once per branch + kind + vendor with the bill day; each month a draft is made for the person who
  set it up (when the page is used, and by `php tools/vb_repeat.php` hourly). Approved bills due within N days are
  told to the pay roles once.
- Who sees: the person who entered a bill; step and view roles see bills of their branches (Super Admin / CEO every
  branch, others their ticked branches, none ticked = every branch). People enter for their own branch and ticked
  branches.
- All bills: filters, paid by branch / kind / month, still coming, CSV. Email to the next step and to the person who
  entered it when Email is on.
- Day book: the viewers' report lists bills paid / coming from head office for their branches (never the branch cash).
- Files by their bytes in the database, opened by whoever can see the bill (each look audited). Nothing is deleted;
  every step is a history line.

## Became settings
- Roles for seeing every bill, checking, approving and paying; days before the due date to warn; the kinds of bill.
- Branch staff can enter bills once Admin → Modules gives their role (e.g. Employee) the module.

## Not ported (later)
- Browser notices (the target has email only), a paid bill as a line on the branch's day sheet.
