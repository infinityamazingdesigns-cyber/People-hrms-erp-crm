# Payslips — porting notes

## Ported (logic only)
- Each login linked to an employee sees only its own payslips, for released months, read from the locked snapshot;
  a held salary's slip stays hidden.
- 4-digit PIN (no repeated / running digits), password-hashed; asked again after 10 minutes and whenever the screen
  is left (locks at once, hides the slip); 5 wrong tries lock it for 15 minutes; the Super Admin resets a forgotten
  PIN (never their own; the owner's only by the owner); PIN history without the PIN.
- HR with pay data previews anyone's slip from the payroll month (never their own through that route); every look
  audited. Print / save as PDF from the browser.
- Who sees their own: everyone (default) or only people allowed per person on Admin → Modules.

## Not ported
- Named lists of people allowed to see their own slip, server-built PDF, earlier months imported from sheets.
