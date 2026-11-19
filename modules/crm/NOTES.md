# CRM — porting notes

## Ported
- Customers with any number of contact persons, mobiles (country code kept, WhatsApp yes / no, label), emails,
  addresses (the first is the delivery and billing default) and notes. Codes C00001 …; owner = the login that adds it
  (people who work on every customer can give it to someone else).
- Tax ID checked by the country rule pack (`tax_id` in rulepacks/<CC>/pack.php; India: GSTIN pattern + check character).
  A tax ID belongs to one customer only. No rule = kept as typed.
- Duplicate check across the whole organisation (lists stay branch-wise): same tax ID, mobile (compared on its local
  part) or email; a similar name (Pvt / Ltd / punctuation ignored) at the same city or postcode, or a near-identical
  name with no place. Saving something that looks like an existing customer needs "It is a different customer"; two
  records with the same mobile / email only by an admin. "Not the same" is remembered.
- Merge: the kept record gets contacts, numbers, emails, addresses, notes and its blanks filled; the other is archived
  and points at it (opening it opens the kept one). Archive / bring back with a reason. Nothing is deleted; removed
  contacts, numbers and addresses are switched off.
- History of every change, field by field in screen words.
- Import of a customer file (CSV / .xlsx, columns by their titles, several numbers in a cell, the same customer twice
  in the file merged): Check writes nothing and shows New / Already there / Possible duplicate / Not read; Save adds
  new ones and fills only what existing ones lack; possible duplicates only when ticked. The file is never kept.
- Products: code (P00001 … when blank), name, category, unit, tax code (HSN / SAC), tax %, rate; switched off with a
  reason; product import by code, else name.
- Customer list CSV (formula-safe cells, audited).

## Ported in part 2: orders and dispatch
- Orders on a customer: where it came from + reference, received time, PO, contact, salesperson (own name unless the
  login works on every customer), advance, payment due, delivery address (default the customer's), expected dispatch,
  several products (list rate and tax by default, discount), value before and with tax. Numbers SO/<branch>/<year>/0001
  per branch and year (year by the organisation's first month). A repeat submit returns the same order.
- Moves by hand: Draft → Submitted → In progress → Ready for dispatch; cancel with a reason; Dispatched → Delivered →
  Closed. Dispatch states follow the consignments. Changes only while Draft / Submitted, with a version check.
- Readiness blockers (ours or the customer's) with who follows up; Ready for dispatch needs none open (setting).
- Dispatch: give to a person with a dispatch role in the branch → they accept → packing → consignments (courier /
  transport, number, dates, vehicle, e-way bill or permit, packages, weight, quantities — never more than is left,
  checked under a row lock). Tracking updates by hand; all consignments delivered → order Delivered.
- Customer stages (received → preparing → packing → handed to transport → on the way → delivered), never stepping back,
  shown on the public tracking page (private link, only its SHA-256 kept, expires, stopped by staff, throttled, noindex,
  no-store; customer-safe fields only) and emailed at each stage when the Email module is on (a link of its own each
  time). "Send on WhatsApp" opens WhatsApp with the text and a new link — no WhatsApp service is called.
- Same-day dispatch score: orders punched on a day (optional cut-off) whose first consignment left that day; customer
  holds left out; next-day count and median minutes. Order CSV.
- Couriers & transport kept by the admin, none seeded, no address filled in; {ref} in the tracking page.

## Became settings
- Roles that work on every customer (in their branches), on their own customers, that only read, and that merge /
  import / archive. Customer categories, units, digits of a local mobile number. The tax ID rule comes from the country.
- Dispatch roles, order number prefix, link days, same-day cut-off, order sources, blockers on our / the customer's side,
  ready-needs-no-blockers, email the customer by default, PO and quantities on the tracking page.

## Not ported (next parts)
- WhatsApp / carrier API sending, staff time tracking, dispatch photo proofs; enquiry register; quotations;
  marketing; complaints; bills import; CRM-own rights grid and change-history hash chain.
