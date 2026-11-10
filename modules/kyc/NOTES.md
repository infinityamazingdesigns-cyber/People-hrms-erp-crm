# KYC & bank — porting notes

## Ported (logic only)
- Values sealed at rest (AES-256-GCM, `seal_key`), masked hints for every list; full values only on the HR verify
  screen, each look audited; the person sees their own social security number in full.
- National ID: only the last 4 kept; a full number is check-digit verified first where the country has one.
- One open change per person; changing tax ID / national ID / bank needs the matching document.
- HR verifies field by field; nobody verifies their own; a change to an already verified bank account is verified
  only by the HR Manager or Super Admin; branch scope.
- Documents checked by their bytes (PDF / JPEG / PNG / WebP, 5 MB), kept in the database, opened only by the person
  and the HR desk (sandboxed, audited). History lines are masked.

## Became settings / generic
- ID formats are a country pack chosen by Admin → Organisation → Country: India (tax ID, national ID with check
  digit, social security, health insurance, bank code), UK, US, and open formats for everyone else.
- Generic names: tax ID, national ID, social security, health insurance, bank code.

## Not ported
- Bulk upload of KYC from a spreadsheet + document ZIP; importing from an outside workbook.
- HR entering details a person already shared, "ask to update" campaigns, payroll gap checks.
- The reference organisation's own membership-ID format.

## Later
- HR entry for someone + person confirms; bulk upload; payroll checks once Payroll is ported.
