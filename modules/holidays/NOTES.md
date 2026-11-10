# Holiday calendar — porting notes

## Ported (logic only)
- One calendar, the only source every module reads (`hol_for()`, `hol_days()`); never a date list in code.
- Holidays by branch (none ticked = every branch), optional holidays, ranges entered once and stored day by day.
- Same holiday on the same day is one row: adding it for another branch only ticks the branch.
- Never deleted: switched off with a reason; edits keep who and when.
- Employees see their own branch's calendar; HR sees all, filtered by branch.
- A person can follow another branch's holidays (until a date or further notice) — holidays only, never shift,
  weekly off or statutory state.
- Import from a file: Check writes nothing; dates already there with the same holiday only get branches ticked.
- Copy a year forward (HR then fixes festivals that move).

## Became settings / organisation data
- Every holiday list is entered by HR (or imported); no seeded lists for any branch or country.
- Year view follows Admin → Organisation → year start month.

## Not ported
- Seeded holiday lists and one-off corrections for named branches; reading holidays out of an encrypted vault.

## Later
- Announcement board / attendance / payroll will read `hol_days()` once they are ported.
