# Stock & inventory — porting notes (part 1)

## Ported
- One item list: code (STK-00001 … when blank), name, type (finished, semi-finished, raw material, bought-out, traded,
  consumable, packing), group, unit (fixed once it has entries), tax code, cost per unit, the CRM product it is (once).
  Switched off with a reason only when no branch holds stock; never deleted.
- Every movement is a numbered document (GRN / ISS / OPN / STN / ADJ / REV per branch and year) posted through one
  function: insert-only ledger lines and the running balance per branch + item changed in the same transaction under
  row locks taken in one order. A double tap returns the same document. Never below zero unless the setting allows it.
  Dates not after today and after "stock closed up to".
- Goods in (purchase, customer return, back from job work, other — reason needed), goods out (sale needs the invoice /
  challan no.; issue, sample, job work, scrap and other), opening stock only for items with no entry at that branch.
- Transfers leave the sender at once and wait in transit; the receiving branch takes in all, part (the rest stays in
  transit) or closes it short with a reason.
- Corrections are reversals (own document, opposite quantities, today's date, reason; the original stays, marked
  reversed; a reversal is never reversed) or stock counts (counted vs books; only differences posted; both kept).
- Stock now (state: below minimum incl. in transit, below zero, nil, in stock; value at cost), entries list, one entry,
  item page with levels per branch (minimum, reorder quantity, rack / bin) and its ledger with the stock after each line,
  period statement (opening, purchase, transfer in, other in, sale, issued, transfer out, other out, adjusted, closing;
  CSV), reorder list (buy or make).

## Became settings
- Roles that see stock, keep the store, do counts and reversals; negative stock allowed; stock closed up to; units.

## Not ported (next parts)
- Bills of materials and production, CRM dispatch → goods out, Purchase receipts → goods in, monthly Excel stock sheet
  import.
