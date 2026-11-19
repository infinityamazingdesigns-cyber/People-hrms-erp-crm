# Induction academy — porting notes

## Ported
- The reference build's academy was one organisation's own content in a page of its own; here **HR builds the levels**:
  title, summary, reading (plain text, paragraphs), an https video link, for everyone or one department, own pass mark.
- A person (login linked to an employee record) takes the levels that apply to them **in order**: a level opens once every
  earlier one is passed. A level without a quiz is finished by "I have read this".
- **Quiz** of 2–6 options per question, graded on the server; the right answers never reach the browser; unanswered =
  wrong; pass at the mark; a retry can wait (setting). Every attempt is kept; a question people answered is never
  changed — switched off and a new one added.
- **Progress** per person on the roll (within the login's scope) and on each onboarding joiner's page.
- The learner's page is `mine.php` ("For me"); levels and questions are switched off with a reason, never deleted.

## Became settings
- Roles that keep the academy, pass mark (70 %), wait before a retry (0 minutes).

## Left out
- The reference's 3D campus and its own lesson content (organisation-specific); certificates.
