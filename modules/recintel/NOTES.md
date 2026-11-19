# Recruitment intelligence — porting notes

## Ported
- **Contacts** of the hiring desk: institutes / placement officers, recruitment consultants, labour contractors — organisation,
  person, phone (first number kept, others moved to "other numbers"), email, website, city, state, type, courses /
  expertise, status, terms / fees, remarks. One per kind + organisation + phone (else email, else person); switched off with
  a reason, never deleted; every change logged by field name.
- **Import** of a contact list (CSV / .xlsx, first sheet): the title row is found, columns by their titles, rows repeating in
  the file joined, unreadable numbers / emails kept in the remarks; **Check writes nothing**; an existing contact only gets
  its blanks filled; the file is never kept; each import recorded (file, its SHA-256, who shared it, counts).
- **Companies**: our organisation, clients, outside companies (watch for poaching), other names, one per name (company words
  ignored); switched off only with nothing open on them.
- **Recruitment projects** for our organisation or a client; **vacancies** with a job code `JOB-<id>` to put in posts and
  messages (search by it); an outside company's opening is **"Observed only"** — never a project or a requisition; our
  own vacancy may point at a Recruitment requisition (one open job code each). Pay figures only for logins with pay data.
- **Hiring trends**: outside companies' observed openings in the window (90 days), watched companies first.

## Became settings
- Roles that keep the records; trends window.
- The reference build's sheet layouts, its agency's name and its find-and-replace repair are one organisation's file —
  replaced by import by column titles.

## Left out
- WhatsApp CV intake and the Drive hand-over (needs a messaging connection per organisation), erasure requests (the
  Recruitment pipeline's own erasure covers candidates).
