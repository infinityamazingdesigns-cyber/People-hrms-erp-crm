# Staff movement — porting notes

## Ported
- A gate record only: who went out during working hours, when, why (optional reason from an editable list, or other
  text), where, vehicle, material taken; when they came back. No approval; nothing reaches attendance or pay.
- Out / back in on server time. One open entry per person (unique key), so double taps and two desks at once cannot
  double-record. The same form sent twice saves once.
- Several people out together: one entry each, saved all or nothing; each comes back on their own, or "Whole group
  back" for those who took no material. Material taken out must be answered on return (all / part / not back; a note
  when not all back).
- Time corrections with a reason; the original is kept and shown.
- Late: red after the limit (minutes, per branch if set), flagged when the person's shift end (Shifts: own rule, else
  the branch) passes while out, or when still out from an earlier day. Each flag alerts each alert login of that branch
  once (email when Email is on). Raised when the gate page opens and by `php tools/mv_alerts.php` (cron, every 5 min).
- Picker: people on the roll in the login's branches — code, name, department, branch — optionally only some
  departments. Reception / Production Head logins with no branch ticked see no branch.
- Log with filters, summary by person and reason (times, time out, late returns), CSV.

## Became settings
- Roles for recording / log and reports / reasons and corrections / late alerts, the late limit and per-branch limits,
  departments on the picker, alert email on / off, the reason list (renamed or switched off, never deleted).

## Not ported (later)
- WhatsApp alerts, the person's attendance photo on the gate screen, production-head contact list apart from logins.
