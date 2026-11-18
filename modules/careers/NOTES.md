# Careers page — porting notes

## Ported
- Public job board (`apply.php`, no sign-in, noindex): open requisitions ticked "on the job board", with title,
  department, place, type, experience, qualification, skills, openings and (by setting) the job description —
  never pay, notes, approvals or weights. Search in the page; a per-position link (`?p=<id>`).
- Application form in four parts (opening, CV, about you, work): name, mobile, email, date of birth (minimum age, exact
  to the day), gender, city, relocation, qualification, experience, title, employer, notice, skills, a note, consent.
  Everything is checked in the page first so a CV never has to be attached twice; the server checks again.
- "Fill the form from my CV": PDF (pdftotext or the pure-PHP reader) and Word read on this server only; values go only
  into empty boxes, marked to be checked. Places come from the branches and a settings list.
- Spam checks: hidden field, a signed form time with a minimum, hourly limits per hashed address for sends and CV
  reads. A repeat within 15 minutes returns the first reference (APP-YYYY-NNNN).
- Hand-over into the recruitment pipeline: one candidate per person (same mobile / email → blanks filled), an
  application on the position or the talent pool, source Website, the CV stored, the consent wording kept with each
  application. HR sees the page's applications and chooses which positions are listed.
- Employer ratings typed by HR (source, rating, reviews, https link), shown only when switched on.

## Became settings
- Heading, introduction, contact, consent and thank-you wording, minimum age, whether a CV / date of birth is needed,
  "no opening fits" option, job descriptions shown, cities for the CV reader, ratings, spam limits.

## Not ported (later)
- Form editor for extra questions, Hindi / Gujarati wording, the Google Form / Sheet bridge, Google Places ratings
  lookup, the candidate portal (its own module).
