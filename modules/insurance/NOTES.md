# Group insurance — porting notes

## Ported
- From one hard-coded mediclaim policy to **any number of policies** (group health, personal accident, term life,
  employees' compensation, other): insurer, number, period, sum insured, basis, helpline, claims / cashless emails, the
  policy copy (PDF / picture by its bytes, 15 MB, database only, opened only through the module page, sandboxed, audited).
  A renewal is a new policy; the old one is switched off with a reason, never removed. Renewal due within the setting's days.
- **Cover line per person per policy**: covered, card number (as on the card), sum insured, covered from, insurer's own
  employee ID, nominee (name, relation from the list, past date of birth, phone). A card means covered; not covered clears
  the card. Every change to `ins_log` with field names only; audit lines carry field names, never values.
- **Insurer's member list** pasted as text: ID, card, name, date of birth, relation, sum; the employee code suggested
  (same code / code one or two letters off with the same name / single name match), HR ticks each line before saving.
- **People on the roll not covered** under a policy.
- **Requests for the policy copy** from My insurance (one open per policy): HR shares (needs the copy on file, or a note
  saying how it was sent) or closes (a note); the person is emailed when email is on; a shared copy then opens for them.
- Keepers (settings roles) act within their branch scope; nobody sets their own cover or answers their own request.
- **My insurance** (`mine.php`, "For me"): the login's own cover only, by its linked code.

## Became settings
- Roles that keep the records (Super Admin, HR Manager by default); renewal reminder days (45).
- The reference build's policy (insurer, number, contacts) is data — not ported; HR enters each policy.

## Left out
- Showing the cover inside the employee profile page; claims tracking; premium and invoice records (see Statutory).
