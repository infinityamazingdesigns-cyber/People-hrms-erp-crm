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

## Part 2
- Correction requests for this month and last month (until it is closed): in / out time, reason, note; one open
  request per day; route reporting manager (login linked to the manager's code) → HR; no manager login → HR.
- HR approvers may decide while it is with the manager ("manager skipped"); nobody decides their own; reject needs a
  note; the person can withdraw while waiting. Approved corrections fill the register day (marked "corrected");
  punches are never changed.
- Month close (Super Admin, HR Manager): blocked while requests wait; closed = no new requests; only Super Admin
  reopens with a reason.
- HR adds attendance directly (Super Admin, HR Manager), also in a closed month, never for their own code.
- Late / early against the branch shift with grace minutes (setting); never on weekly offs or holidays; counted in
  the register summary and the CSV.

## Later
- Per-person shifts and weekly offs (Shifts module); late-coming permissions and pay cuts (Payroll module);
  clash with leave (Leave module).

## Not ported
- Biometric machines (own module), place names from map services, Drive copies of selfies, auto check-out,
  km-from-base display, live attendance board.
