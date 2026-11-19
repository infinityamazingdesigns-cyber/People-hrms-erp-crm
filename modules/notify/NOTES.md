# Browser notifications — porting notes

## Ported
- Web Push per device: each person turns notifications on / off on Notifications (under "For me"), sends a test.
- The push to the browser's push service is empty and VAPID-signed; the service worker (`sw.js`, site root) fetches the
  text from `modules/notify/api.php?pending` with the person's own sign-in. Signed out on that device → a plain line.
  The worker intercepts no page and caches nothing but its own counter; a click opens only addresses of this site.
- Endpoints only on the known push services (Google, Mozilla, Apple, Microsoft); a browser that drops a subscription is
  forgotten (404 / 410); the newest few devices per login are kept.
- VAPID key pair made on first use, private key sealed (`seal()`) in `push_key`.
- Every notice is kept in the person's inbox (`user_notice`, mark all read); a staging copy pushes nothing; pushes go
  after the page answer.
- `notify_user()` is the one way any module tells a login (does nothing while the module is off). Wired now: task
  notices (Tasks), meeting rings (Video meetings), vendor bill steps, staff movement late alerts.

## Became settings
- Nothing: the module switch decides; each person decides per device.

## Not ported (later)
- The account-menu on / off item, the app-icon badge with unread chat, wiring the remaining modules' notices
  (leave cover, attendance requests, letters, claims …) to `notify_user()`.
