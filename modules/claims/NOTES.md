# Expense claims — porting notes

## Ported
- One claim, many lines: money spent and money received for the work (an advance, cash from the office). What is paid
  = allowed spending − money received; below 0 the person gives money back ("Mark settled").
- Bills per line by their own bytes (PDF / JPEG / PNG / WebP, 10 MB, camera on phones), kept in the database, opened
  only by the person and reviewers in scope (sandboxed, each look by a reviewer audited). Every expense needs its bill or
  "No bill" with a reason.
- Drafts stay with the person; send → HR audit (not allow single lines with a reason, return with a note) → approval
  (approve, send back to HR, reject — notes needed) → payment (date up to today, mode, reference). Returned claims are
  corrected and sent again (round 2 …); withdrawn while with HR or returned. Nobody decides their own claim.
- Possible duplicates flagged, never blocked: the same bill on another claim, the same date and amount on the person's
  other claims — shown when sending and to HR on each line.
- Lines may be tagged "for" a branch. Report by category, month and step (report roles). Waiting counts per step.
- Nothing deleted: lines left out of a form are switched off, bills stay, the history is append-only.

## Became settings
- Expense categories, kinds of money received, payment modes, how claims are paid, whether a bill is needed, oldest
  date allowed, largest line, and the roles for audit / approval / payment / report.

## Not ported (one company's choices in the reference build)
- Its office expense register and Google sheet copy, moving rows from that register, branch cash day-book payments,
  named categories and people.

## Later
- Notices to the next step, paying claims with salary through payroll, a branch day book, payment slip uploads.
