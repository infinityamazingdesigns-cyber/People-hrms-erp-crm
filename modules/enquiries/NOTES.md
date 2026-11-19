# Enquiry register — porting notes

## Ported
- One record per enquiry in the database (the reference build kept them in a browser app's store): reference
  ENQ/<year>/0001, enquiry date, customer, company, phone, mail, category and product, lead source, stage, quotation
  number / date / value before tax / tax %, next follow-up, owner, branch, notes.
- Stages New → Contacted → Quotation sent → Negotiation → Won / Lost. From "Quotation sent" on a quotation number and
  value are needed; Lost needs a reason from the list; open enquiries need a follow-up date (default tomorrow, three
  days after a quotation); closed ones keep none. Every stage move, quotation and hand-over writes a note; quick notes.
- Register with filters (stage, follow-up overdue / today, source, category, owner, search) sorted by follow-up;
  Follow-ups due; quick move to the next stage from the list; CSV.
- A new enquiry from a phone (local part) or mail that already has an open enquiry is refused with its reference unless
  "a different requirement" is ticked; on import it becomes a note on the open one.
- Linked to the CRM customer with the same phone or mail; "Make a CRM customer"; Won → "Make the order" in CRM orders.
- Reports by lead source, category, product, person or stage: enquiries, open, won, lost, win rate (won ÷ won + lost),
  quoted value, won value.
- Import from a file or pasted sheet rows (the reference template's column order, or titles): status words map to
  stages, names not on the lists are kept in a note; checking writes nothing.
- Removed with a reason by a manager, never deleted.

## Became settings
- Roles that see every enquiry, managers (their branches, give out, import, remove), people who work their own.
- Categories with their products, lead sources in groups, reasons lost, default tax %, follow-up days, reference prefix,
  digits of a local phone number. The reference build's product list was one company's catalogue — the default is neutral.

## Not ported (later)
- Lead intake from WhatsApp / ad platforms / website forms (Marketing module), quotation documents (Quotations module),
  access requests inside the app.
