# Recruitment pipeline — porting notes

## Ported
- Requisitions: position, department, branch, openings, type, new or replacement, hire-by, job description, and what
  the position needs (qualification level and discipline, experience range, key skills, titles that fit, location,
  notice, pay range for pay-data roles). Raised by the raise roles, approved by an approver who did not raise it
  (or opened at once when approval is switched off); hold, fill, close, reopen with a note. References PREFIX-YYYY-0001.
  A position becomes Filled when as many people joined as it has openings.
- Candidates: one record per person — a second application with the same mobile (last 10 digits) or email lands on
  the existing record and only fills its blanks. CV files by their own bytes (PDF, Word, ODT, RTF, text; 5 MB) in the
  database; text of .docx / .txt CVs is read for the match; CV text can also be pasted. Consent recorded. Switched
  off with a reason, or erased on the person's request (details, CV files and panel comments blanked; history kept).
- Applications on a position or in the talent pool, through New → Screening → Shortlisted → Interview → Offer made →
  Offer accepted → Joined, plus On hold / Not taken forward / Withdrew (a reason needed; reopening needs one too).
  Board by stage per position, sort by match, HR rating 1–5.
- Interviews: rounds, date and time, in person / video (https link) / phone, panel of logins (emailed when Email is on),
  done / did not come / cancelled. Panel members give rating, verdict and comments on My interviews — they see work
  details only, never contacts or pay.
- Offers: pay (pay-data roles only), joining date, answer-by; accepted / declined / withdrawn moves the application.
  An accepted offer starts an Onboarding checklist once.
- ATS match (0–100, half stars) from the reference's seven checks — key skills 30, experience 25, qualification 15,
  designation fit 15, location 5, salary 5, notice 5 — weights per position; a requirement the application does not
  show counts as 0 (switchable); the CV text is read when the form is blank. A screening aid only; it never moves
  anyone. Log (rc_log) append-only with ids, fields and stages, never values.

## Became settings
- Roles that run the pipeline, approve and raise requisitions; whether approval is needed; reference prefix.

## Not ported (later)
- Custom ATS criteria (keyword sets, field conditions, HR rating criteria), PDF CV text reading (comes with the
  careers page), job-board / sheet / form intake, MPRF printouts, hiring reports.
