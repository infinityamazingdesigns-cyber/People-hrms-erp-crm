# Lite mode — porting notes

## Ported
- Small server-built pages for slow internet, for every signed-in login while the module is on (`module_require_self`,
  listed under "For me"): Home (greeting, today's punches with Check in / out, open tasks, requests still waiting),
  Punch, own Attendance by month (with "correct" on absent / missed-punch days), Leave (apply, own applications,
  withdraw a waiting one), Requests (ask to correct a day, own requests), Letters (issued letters; open / download on My
  letters). Each screen opens only when the full module would let the login in.
- One tiny inline stylesheet (light / dark from the device), the organisation's colour, no web fonts or frameworks.
  Script only on the punch page: one camera frame and a precise GPS fix, sent to the full punch screen's handler, so every
  punch rule is the same. Camera and location are allowed on the Lite punch page only (`.htaccess`).
- No rule of its own: leave through `lv_create()` / `lv_act()`, corrections through `atr_create()`, day lines through
  `att_days_for()`, the tracker through My day's `md_stage()`.
- Chosen at sign-in ("Use Lite", ticked by itself on the browser's Save-Data hint or an earlier choice); the home page
  then opens Lite; "Open the full site" (`index.php?full=1`) clears the choice.

## Left out
- Manager screens (one day for the team, probation check-ins), The Hook in text, password change on Lite, late coming /
  early going / short leave and work hand-over on the Lite leave form (all on the full site).
