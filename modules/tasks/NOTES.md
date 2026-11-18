# Tasks — porting notes

## Ported
- Own tasks and tasks given to colleagues (several people = one copy each); title, notes, list, priority, due date and
  time; a login sees only what it created or was given; managers see the tasks of people below them on the org chart
  (read only); the lead roles see everyone's.
- Approval loop: a given task ticked by its person goes back to the giver — approve, or redo / correction with a note,
  any number of rounds; the person can take a submission back. A task from a colleague (not above on the reporting
  lines, not a lead role) can be declined with a reason and goes back to the giver; a manager's cannot.
- Reminders at the time, 15 min / 1 h / 1 day before, or the morning of the day; once or again till done (hourly ones
  wait out the night); a reminder more than 12 h late is dropped. They become notices on the page and, when set and
  Email is on, emails. `php tools/task_remind.php` (cron every minute) sends them when nobody has the page open.
- Repeats every day / working day / week / month (keeps the first day, cut to the month end) / year: the next copy is
  made once when one is finished or approved.
- Calendar file (.ics) per task with a 15-minute alarm. Nothing deleted (removed_at); task_log is append-only.

## Became settings
- Who tasks can be given to (anyone / people below), roles that see everyone's, the morning hour, quiet hours,
  working days, reminder emails.

## Not ported (later)
- Voice entry (browser speech) in English / Hindi / Gujarati, files and voice notes on tasks, asking for more time,
  bulk actions, the month calendar and history views, browser push.
