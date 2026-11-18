# Announcement board — porting notes

## Ported
- Announcements (pin / unpin, up to 6 files) and picture posts (photos only, up to 24), files checked by their own
  bytes, 15 MB, kept in the database. Authors remove their own; moderators remove anyone's. Removed posts are kept
  (`removed_at`) and their files stop opening. Every signed-in login with the module reads the board.
- Celebrations: birthdays (employee master date of birth, else the approved profile) and, when switched on, wedding
  anniversaries from approved profiles — name, designation, department, branch and day-month only; never codes,
  years or ages. Leavers are left out.
- On leave today: approved leave only, name, branch and part of the day — never the reason, type or notes.
- The viewer's own holidays for the next 60 days from the Holidays module (their branch's calendar, overrides kept).
- Daily challenges for everyone, branches, departments or chosen people; "Mark done" is the person's own record,
  with photos not needed / optional / needed (up to 3). Photos are seen only by their owner and the people who set
  challenges; employees never see other people's codes in "who it is for". Challenges are taken off, never deleted.

## Became settings
- Roles that post / set challenges and roles that moderate; birthdays, anniversaries, on-leave list on or off; days
  ahead for celebrations; how long posts stay on the board.

## Not ported (later)
- Company events calendar, festival artwork and greetings, games (word of the day, puzzles), the viewer's own month
  attendance summary, profile pictures beside authors, the Lite (no-script) version.
