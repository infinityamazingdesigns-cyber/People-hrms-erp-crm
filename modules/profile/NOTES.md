# My profile — porting notes

## Ported (logic only)
- Each person edits only their personal details; employment details are read-only from the employee master
  (a wrong one is reported in a note to HR).
- One open change per person: sending again replaces it, Withdraw takes it back; nothing changes until HR verifies.
- HR desk (Super Admin, HR Manager, HR Operations) accepts field by field; unticked fields are declined; sending
  everything back needs a note; nobody decides their own; branch scope applies.
- Accepted date of birth and gender follow to the employee master; every accepted / declined field goes to the
  employee history.
- Checks: dates not in the future, sensible age, choices from lists, anniversary / spouse only when married,
  emergency contact not the person's own number, personal email not on the work domain.

## Became settings / generic
- Work mail domain → taken from Admin → Organisation (HR support email), not fixed domains.
- Phone numbers: international (7–15 digits, optional +), not one country's 10-digit format.
- Postal codes: letters and digits (3–10), not one country's 6-digit PIN.

## Not ported
- Notifications to the person (come with the Browser notifications module).
- Date-of-joining change requests with the letter attached (comes with Letter desk / KYC).
- PIN-to-city lookup (country data; later as an optional rule pack).

## Later
- KYC & bank as its own module (next), profile photo.
