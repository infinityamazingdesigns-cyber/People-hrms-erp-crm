# Inbox — porting notes

## Ported
- Each login's own **company mail** — Zoho Mail and Gmail through OAuth (client ID + secret saved per login, Connect is a
  plain link with a key derived from the session token, OAuth `state` checked from the session). Mail is read live each
  time (nothing copied), bodies are cleaned and shown in a sandboxed frame; replies are threaded.
- **Zoho Cliq**: chats, messages and posting, with the same Zoho connection.
- **WhatsApp Business** (Meta Cloud API, coexistence with the Business app): Embedded Signup (code → token, subscribe the
  app, ask for contacts and history) or a system-user token by hand. Meta posts to the public `modules/inbox/wa_hook.php`:
  GET answers the challenge only for a verify token a login made; POST must be signed with a connected login's app
  secret; each body once; messages, the app's echoes, history and statuses land per login. Replies only within 24 h of
  the customer's last message.
- **Whose:** every connection and every message belongs to the login that connected it (one row per login + service;
  the webhook files a change under the login whose number Meta names, else the signer). Nobody sees another login's mail
  or chats — Super Admin included.
- Secrets (client secrets, refresh / access tokens, app secret, verify token) sealed with `seal()`; only the last 4 shown;
  the verify token is shown once. `ib_log` and the audit line carry the service and action only.
- A staging copy sends nothing (replies, Cliq posts and WhatsApp sends are refused).
- Nothing deletes a message anywhere.

## Became settings (neutral defaults)
- Country calling code for WhatsApp numbers that come without one: blank (numbers kept as they come). The reference build
  always added one country's code.
- Default Zoho region: zoho.com (the reference build used one region).
- Who may use it: Admin → Modules; Super Admin by default; the module is off until switched on.

## Not ported
- The old one-row-per-service table and its migration (WorkNest starts per login).
- The home-page mail / chat card.
