# CRM — porting notes (part 1: customers and products)

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

## Became settings
- Roles that work on every customer (in their branches), on their own customers, that only read, and that merge /
  import / archive. Customer categories, units, digits of a local mobile number. The tax ID rule comes from the country.

## Not ported (next parts)
- Orders, dispatch, consignments, tracking page and customer messages (part 2); enquiry register; quotations;
  marketing; complaints; bills import; CRM-own rights grid and change-history hash chain.
