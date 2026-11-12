# Letter desk — porting notes

## Ported (logic only)
- Letters made from templates with {{fields}}; values from the employee record fill themselves, the rest are typed.
  A letter with an empty field cannot be issued.
- Signatory per template role, kept by valid-from date; the letter keeps the name it was issued with.
- Approval when the template asks for it: a login with the signer role approves; never the maker, never a letter
  about oneself; sending back needs a note; changing an approved letter needs approval again.
- Issue freezes the text (SHA-256), gives a reference CODE/year/0001 and a 10-character verification code.
- Letters needing an answer get a private link (SHA-256 kept, 60 days, new link stops the old one); the person types
  their name to accept / decline / acknowledge (time and address kept). Signed-in people answer on My letters.
  Offers past "accept by" lapse.
- Public verify page: type, reference, name, designation, date only.
- My letters (every login linked to an employee): own issued letters, ask HR for a letter; issuing closes the request.
- Nothing deleted: letters withdrawn with a reason, templates switched off, every step in letter_log.

## Changed from the reference build
- The letter is made and frozen on the server (no browser-built PDF upload); print / save as PDF from the letter page.
- Templates are the organisation's own; a neutral starter set is installed (offer, appointment, confirmation,
  employment and experience certificates, relieving letter).

## Became settings / organisation data
- Templates, signatories, which letters employees see / may ask for, the letterhead (organisation name, legal name,
  logo, address).

## Not ported
- One company's letter wording, annexure salary tables, signature images, QR code in the footer, joining documents
  through the answer link, the full & final "clearance" before relieving letters, increment holds from conduct,
  onboarding auto-ticks.

## Later
- Email the letter (Email & 2-step module); clearance check once Full & final is ported.
