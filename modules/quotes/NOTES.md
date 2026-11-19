# Quotations — porting notes

## Ported
- A quotation on a CRM customer, started from the customer or from an open enquiry ("Enquiries waiting for one").
  Number QT/<branch>/<year>/0001 per branch and year (year by the organisation's first month).
- Lines from the product list (list rate and tax % unless typed, discount) plus other charges (freight, packing …)
  taxed at the highest goods rate. Bill-to / deliver-to from the customer's addresses, contact, payment terms (picked
  from the header's list), delivery, note, valid till (header's days).
- Sales tax from the country rule pack (`sales_tax` in rulepacks/<CC>/pack.php). India: place of supply = the delivery
  address's state, else the state inside the customer's GSTIN, else the billing state, or picked by hand; same state as
  the branch → CGST + SGST, else IGST. Each line's tax is split, never added twice. No pack → one "Tax" line.
- Draft → Mark sent (locked; the header as it was is kept with the quotation) → Won / Lost (reason). Revise keeps the
  number with -R1, -R2 …; the sent one stays as Revised. Validity over is shown. Won → a draft CRM order with the same
  lines (once), other charges noted on it.
- The enquiry moves with it: sent → Quotation sent with the number, value and a follow-up; won → Won; lost → Lost with
  the reason (mapped to the register's list).
- Print / save as PDF (A4): header, customer, lines, tax parts, round off, total in words (India: lakh / crore; others:
  million), payment, bank details, terms, introduction page when ticked; a draft or revised one carries a stamp.
- Header & terms per company and branch (a branch fills only what differs), every save logged with field names.
- Archive / bring back with a reason; one that became an order stays. History per quotation.

## Became settings
- Number prefix, reasons lost, days to the enquiry's follow-up after sending, whether the company's tax ID is needed
  before sending. Header values (name, tagline, tax ID, other registrations, address, contacts, bank text, payment terms
  list, validity, rounding, introduction, terms) — none written in the code.

## Not ported (later)
- Tax rules by product code with dates (HSN rules), special treatments (SEZ, export under LUT, composition), product
  pictures on the printed page, invoice numbers from accounting software, CRM-own rights grid.
