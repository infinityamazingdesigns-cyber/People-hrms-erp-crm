# Attendance — porting notes

## Part 1 (this PR)
- Phone punch: live camera selfie (JPEG checked by its bytes, kept in the database) + fresh GPS fix within the required
  precision (cached / old fixes refused); In / Out alternate, a daily limit.
- Office geofence from each branch's location and radius; outside every office a one-line remark (optional).
- Flexible hours: before the day-change hour a punch belongs to the previous working day ("01:20 (+1)").
- Camera and location allowed on the punch page only (`.htaccess` + page header).
- Day status: Present / Half day / Short (hours minus breaks), Checked in, Missed punch, Weekly off (branch), Holiday
  (holiday calendar), Absent; days before joining / after the last day left out.
- My attendance by month; register with summary per person and day by day for one person; CSV; selfies opened only
  by the person or someone whose view includes them.
- Who sees whom: own; roles in "see everyone" within branches; managers their whole reporting tree (setting).
- Settings: precision, punches a day, full / half-day hours, day-change hour, remarks, who sees everyone.

## Became settings / generic
- Time zone from Admin → Organisation; precision, hours and visibility are module settings, not constants.
- Who sees everyone is a role list (the reference build fixed it to two named categories).

## Part 2 (next)
- Correction requests (manager → HR, HR may skip the manager), month close for payroll, late / early rules with
  grace and permissions, per-person shifts and weekly offs (Shifts module).

## Not ported
- Biometric machines (own module), place names from map services, Drive copies of selfies, auto check-out,
  km-from-base display, live attendance board.
