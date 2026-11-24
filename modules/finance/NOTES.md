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

## Not ported yet
- Steps 3–7: feeds from other modules as drafts, openings import, GST registers / returns preparation, bank
  statement reconciliation, suggested drafts, P&L / balance sheet / management view.
