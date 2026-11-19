# Aptitude test — porting notes

## Ported
- **Question bank** kept by HR: section (reasoning, numbers, role knowledge, safety & conduct, leadership & judgement),
  job families (or every family), level range 1–4, 2–6 options, "keep the order" for options like "all of the above".
  A question is never edited (papers keep it as given) — taken out with a reason, brought back, or a new one added.
  Coverage table: what each family × level can draw per section against the paper's count.
- **Who may take it** (setting): after every induction academy level (default; no levels for them = nothing to wait for),
  any linked login, or only people HR invites. HR sets a person's job family and level (else the defaults), invites, and
  allows **one re-test with a reason** — never for their own code.
- **Paper**: drawn per section by the level's blueprint — own level first, then the nearest, questions asked before only
  after new ones one level further; options shuffled; one open paper per person (a double tap gives the same paper);
  the deadline is the server's (+ 2 minutes' grace); the clock on the page sends the paper at zero; leaving the screen is
  counted, never blocked; an expired paper closes with the answers saved.
- **Scoring on the server**; the right answers never reach the browser; **the result is never shown to the person**.
  HR (and the "see results" roles) see results within their branch scope, never their own: score, %, band, sections,
  the answer sheet as given, an HR note; a paper can be voided with a reason. HR is emailed each result when email is on.
- Nothing is deleted; `apt_log` is append-only.

## Became settings
- Job families and level names (neutral defaults), default family / level, minutes and section counts per level,
  bands, fewest questions a paper may have, roles that run the test and roles that only see results.

## Left out
- The reference's built-in bank of 214 questions (written around one organisation's work) and its three-language
  wording; file import of questions; the printable result card.
