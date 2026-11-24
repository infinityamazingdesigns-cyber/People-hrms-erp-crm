# Finance (books) — porting notes

Ported from the reference build's Finance steps 1–2 (`finance.php`, `lib/finance.php`, `lib/fin_ledger.php`), logic only.

## Ported
- Setup: tax registrations (number checked by the country rule pack's tax-ID rule, all of one legal entity; a
  registration may wait for its number), bank accounts (number sealed, last 4 shown; a collection account settles into
  another; one main), Finance team (head / manager / executive / auditor; nobody sets their own), voucher approval rule
  (limit + approver from a date; earlier rules kept).
- Books: standard groups seeded once, groups added under a group, ledgers with opening balance, ledgers made for bank
  accounts, restricted ledgers (seen by the restricted-ledger roles only, others see one total line).
- Vouchers: payment / receipt / contra / journal with their bank / cash rules; Dr = Cr > 0; insert-only entry lines
  (a draft saved again writes a new revision); numbers only at posting `PMT|RCT|CTR|JV/<year>/0001` per type and
  financial year; maker-checker (at or above the limit → the approver; below it a head / manager posts, an executive's
  voucher waits for their check; nobody decides their own); reversal with its own number, the original kept; books
  start and period lock (opening a locked period needs a reason).
- Reading: day book, ledger statement with running balance, trial balance (opening difference shown), overview,
  CSV of day book and trial balance, "Vouchers to approve" on the home page.

## Became settings
- Who changes the setup / lock (Super Admin always), who sees restricted ledgers, which role works as Accounts
  executive. Year start comes from Organisation settings. Currency grouping follows the organisation currency.
- India-only checks (GSTIN carrying the PAN, IFSC) became the rule pack's tax-ID rule and a generic routing code.

## Part 2 (steps 3–7, lib/fin_more.php)
- Ledger mapping (`fin_map`): which ledger each feed line goes to; "paid from" lines need a bank / cash ledger.
- Bring in (`fin_feed`, request_id `feed-<source>-<ref>`): one DRAFT voucher per source record, ever — payroll month
  totals (never a person; only the payroll-feed roles, Super Admin by default), expense claims paid, vendor bills paid,
  CRM orders dispatched (another currency left to a hand journal), purchase orders issued and fully received.
- Each source page shows where its record stands (`lib/fin_note.php` `fin_feed_note()`, only when Finance is allowed):
  claims, vendor bills, payroll month, CRM order, purchase order. Home: "Records to bring into the books".
- Opening balances sheet with the Dr / Cr difference, open until the lock reaches the books start.
- Profit and loss, balance sheet (profit not yet closed, difference = openings), management view (six months).
- Bank reconciliation: statement import (CSV / .xlsx, columns by title, same line never twice), sure matches, match by
  hand, unmatch / leave out with a reason, reconciliation statement, suggested drafts from earlier matched lines
  (rule based, nothing leaves the site; else the suspense ledger).

## Became settings
- `payroll_feed_roles` (who brings payroll totals in; Super Admin always kept).
- Sale feed on dispatch statuses `FIN_SALE_DONE`; matching window `FIN_REC_DAYS` (7).

## Not ported yet
- GST registers / return preparation (needs a rule-pack-neutral design: tax returns differ by country).
- Opening balances import from a trial-balance file; a 2B-style purchase-tax matching.
