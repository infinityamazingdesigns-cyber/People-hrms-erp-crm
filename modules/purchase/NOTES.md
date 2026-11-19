# Purchase & procurement — porting notes

## Part 1 (ported)
- **Vendors**: code `V00001…`, name check (company words ignored), tax ID by the country rule pack (one vendor per tax ID),
  "not registered for tax", region from the tax ID, credit days, small-business number, bank details **sealed** (`seal()`)
  and shown masked; full view only to buyers / approvers, each look audited. Send for approval → an approver who neither
  registered nor sent it approves or blocks (reason). A new tax ID or bank account on an approved vendor goes back to waiting.
- **Requisitions** (`PR/<branch>/<FY>/0001`): several lines, own branches only, stock item or typed name, last order rate and
  stock looked up. At or above the manager value — or with a line nobody can price — it waits for the requester's reporting
  manager (org chart `manager_code` → their login; none → any approver). Nobody decides their own; reject needs a note.
- **Comparisons**: quotes per vendor (rate, freight, delivery days, credit days); QCD score (price = cheapest landed ÷ landed,
  quality = 100 − 10 × rejection %, delivery = half on-time history, half fastest days ÷ days, payment = credit days ÷ longest;
  new vendors count a neutral value). Not the best, or a single quote → a reason. Saving vs highest / first quote.
- **Orders** (purchase / job work): from ticked requisition lines (a finished comparison brings vendor and rate) or empty;
  Draft → approver → CEO at or above the limit (needs every line on a comparison or a single-source reason) → Approved →
  Issued. Nobody approves their own; the CEO is never the approver of the first step; send back / reject need a note. The
  number is given only at issue; header (Quotations → Header & terms) and vendor are frozen then. Tax: unregistered vendor
  none, else the rule pack's split (branch region vs vendor region). Print / PDF with amount in words.

## Part 2 (ported)
- **Goods received** (`RCV/<branch>/<FY>/0001`) against issued order lines, never more than is still to come (row lock on the
  line), date not in the future nor before the order; cancelled with a reason only before its check (the quantity opens again).
- **Quality check** by a quality role and **never the person who received the goods**: accepted + rejected = received, a reason
  from the settings list for rejections, test certificate received / not / not needed. Purchase return no. (buyer / quality),
  debit note no. (accounts only).
- **Into stock**: a stock-store login takes checked, accepted goods into stock once (Goods in · Purchase receipt at the order
  rate, `stk_link` 'grn'); a line with no stock item gets one picked then. Reversing the stock entry frees it again.
- **Vendor scorecards** (12 months of checked receipts): on-time % (where the line had a date), rejection % by value, days to
  deliver; score = half on-time, half quality (100 − 10 × rejection %), grade A ≥ 85 / B ≥ 70 / C. It feeds the comparison score.
  Monthly reviews with a grade (below A needs a note).
- **KRA figures** for a month (login's branches): orders issued and value, orders at the limit with a comparison or
  single-source reason, requisition → order within the target working days (Sundays off), on-time deliveries, rejected by
  value, saving against the highest quote, late lines still to come, requisition lines waiting past the target.

## Became settings (neutral defaults)
- Receive / quality / debit-note roles, requisition → order target days (3), rejection reasons.
- Buyer / approver / read roles; CEO limit (25,000 placeholder, flagged to the Super Admin); manager limit (10,000);
  prefixes; QCD weights 50 / 20 / 20 / 10 and the new-vendor value 70; default order terms.

## Left out / next
- Pipeline board, writing KRA figures into the performance module, item master / drawings, month-end stock checklist.
- Zoho Sheet link, data pack import, emailing orders to vendors with a private link, vendor documents and GST portal reading.
