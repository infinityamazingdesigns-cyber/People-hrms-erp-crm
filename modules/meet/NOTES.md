# Video meetings — porting notes

## Ported
- Group video meetings for every login with the module: camera, microphone and screen go browser to browser
  (WebRTC); the site keeps the meeting record and passes connection messages (handed over once, then deleted). Each
  open meeting page asks `api.php` what changed — no WebSocket server, works on shared PHP hosting.
- Members only: nobody reads a meeting's chat, transcript or minutes unless they were in it.
- Start (title, agenda, invite / ring colleagues, open join, minutes on / off), join by code or link, pre-join camera /
  microphone check, waiting room (host / co-host admits or turns away; turned away = waits again), lock, remove (never
  back), co-host, mute one / everyone, lower hand, reactions, chat, screen share, live transcript (each browser writes
  its own speaker's words; nothing sent while muted). A page that stops checking has left; with no host / co-host in
  the room the person in longest becomes co-host.
- Ringing: a ring shows as a card on any open page of the site (`assets/meet-ring.js`, not on the meeting page); the
  check never keeps an idle sign-in alive.
- Minutes by rules (summary, decisions, action items with due words, open questions, attendees) when the meeting
  ends or on request; download as text; emailed to attendees when Email is on and the host left it on. Invitations by
  email when Email is on.
- Camera / microphone / screen allowed on the meeting page only (`.htaccess` WN_MEET_PAGE + the page's own header).
- Fixed on the way: the check that lets a waiting person in also carried the host's first connection message, which
  was dropped — so the video never connected for admitted people. That message is now used.

## Became settings
- Most people in one meeting, ring length, STUN addresses (blank = same network only), transcript languages.

## Not ported (later)
- Team games / quiz / points, AI-written minutes (sending meeting text to an outside service), TURN with credentials,
  browser push notices.
