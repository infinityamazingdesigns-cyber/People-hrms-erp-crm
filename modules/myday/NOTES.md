# My day — porting notes

## Ported
- An own-records page for every signed-in login while the module is on (`module_require_self`, listed under "For me").
  Own data only — the session's login and its linked employee code; the page reads no request parameter, writes nothing.
- Greeting and today's punches (first in, last out, count) with Check in / Check out to the punch screen when allowed;
  a note when the login is not linked to an employee record.
- One tracker of every own request — attendance corrections, leave (and withdrawal requests), expense claims, letter
  requests, profile updates, asset issues — each with its steps and where it stands (open, done, stopped); open ones
  oldest first, then those closed in the last 30 days, newest first; each links to its own screen.
- Letters issued to the person, open tasks given to them (late / due today), unread notices, latest announcements, quick
  actions to the screens they may open and their "For me" pages.
- Never a money figure: claims show their number and title only.

## Left out
- Own month summary, leave balance and payslip months (the reference opened them per person on a trial list; here the
  person's own payslips / leave screens already show them under their own rules); the motion kit; Signalman answers from
  own records.
