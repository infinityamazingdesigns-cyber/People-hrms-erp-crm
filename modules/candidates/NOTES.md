# Candidate portal — porting notes

## Ported
- HR (settings roles) gives one **open application** a private link with the candidate's email — **no password, no
  login**. Only the SHA-256 of the link is kept; it is shown once; **New link** stops the old one. One live access per
  email and per application; an email of an active login is refused. HR shares the link themselves (copy, mail app,
  WhatsApp) — the site sends nothing.
- The applicant sees **only their own application**: stage in candidate wording on a step line, HR's message, interviews
  (date, time, mode, place or https link, panel names) with **I will attend / Ask to reschedule** (a note needed; only
  upcoming, scheduled ones; a changed time asks again), **issued letters addressed to their email** — opened and answered
  with the Letter desk's own rule (`let_answer()`: typed name, time, internet address), **their details** (every version
  kept; HR fills only the blanks of the candidate record) and **CVs** (by their bytes, every version kept, read into the
  ATS text when it has none).
- Access **ends by itself** when the application closes (joined / not taken forward / withdrew) or the candidate is
  switched off; HR can end it with a reason; the link then stops for good. The HR login that gave access is emailed
  when the candidate answers (when email is on).
- Public page `portal.php`: throttled by address, noindex, no-store, no referrer. Never pay, panel feedback or anyone else.
- Nothing is deleted; `cand_log` is append-only.

## Became settings
- Roles that give access (Super Admin, HR Manager, HR Recruitment); link days (60).

## Left out
- KYC & bank through the joining form for a candidate (needs the onboarding link); emailing the link from the site.
