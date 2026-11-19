# Third parties & contracts — porting notes

## Ported
- Party register: legal / trade name, entity type, category, purpose, address, branch, owner, onboarding date, status,
  risk, due-diligence state. Tax ID and registration no. (labels are settings) sealed at rest (`seal()`), shown masked;
  a full look is a POST for the sensitive right only, shown once and audited, never logged with the value. Duplicate
  check by a keyed hash (same number refused) and by the name without "Pvt / Ltd" and punctuation (asked).
- Contacts (signatory marked), switched off, never deleted.
- Agreements: types (NDA, MSA, service, contractor, consultancy, SOW, purchase contract, work order, DPA, IP,
  confidentiality, amendment, extension, renewal, termination), reference `<prefix>/<type>/<year>/001` by the organisation's
  year, scope, owner, dates, notice period; value (whole minor units) and terms only for the commercial right.
- Every PDF is a new version with its SHA-256, kept in the database, never replaced or deleted; a new version sets aside a
  pending or given approval and returns the agreement to Draft. Approval is bound to the exact file (version + SHA-256
  checked when deciding) and never decided by the person who asked or uploaded it; sending back needs a note; the
  requester may withdraw. An approver records a copy signed outside (date), which executes it. An executed agreement takes
  no versions and is not cancelled — amendments / extensions / renewals / terminations are new agreements linked to it.
- Compliance documents (PDF / JPEG / PNG by bytes, last 4 of the number only, validity dates); a new copy of the same
  kind replaces the old (kept); verified or rejected only by someone other than the uploader; Expired wins over Verified.
- Files open sandboxed for the view right, every look audited. Ending soon list; reminders at the setting's day bands,
  once per item per band, to the owner and uploader (`php tools/ct_alerts.php`, daily). Change log per party and
  agreement (ids, field names, states — never numbers or amounts). Audit family `ct_` chained.

## Became a setting
- Who views, keeps the register, approves, sees full numbers, sees values; reminder days; reference prefix; the names of
  the two numbers (country-neutral instead of fixed tax-ID formats).

## Left out
- E-signing through a provider (the reference's Leegality bridge, webhook and import of documents already there) — needs
  a per-organisation account; country-specific tax-ID check digits; key re-seal tool.
