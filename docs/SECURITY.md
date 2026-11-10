# Security

- **Passwords:** Argon2id (bcrypt fallback), upgraded on sign-in; 10+ characters with letters and digits; first
  sign-in forces a new password; 5 wrong tries lock the login for 15 minutes; 30 failures per network per 15 minutes
  block that network. A password change signs out every other browser.
- **Sessions:** private session folder, HttpOnly + SameSite + Secure cookies, 8-hour idle sign-out, `no-store` pages.
- **Headers:** strict Content-Security-Policy (own scripts only), no framing by others, nosniff, HSTS on HTTPS,
  camera / location only on pages that need them.
- **CSRF** token on every form and state-changing API.
- **Owner lock:** see `CLAUDE.md` → The owner.
- **Least privilege:** module gate + roles + branch scope + data classes; Employee-only logins deny by default.
- **Sealing:** ID numbers, bank details and integration secrets AES-256-GCM with `seal_key` (config.php only).
  Shown masked; full view only to listed roles and audited.
- **Audit:** sensitive families on a SHA-256 hash chain; `Admin → Audit log` shows whether the chain is intact.
- **Repository:** `tools/guard.py` refuses organisation data, IDs, secrets, real phone numbers and email addresses.
- **Staging:** `'stage' => true` stops every outgoing message.

Report a problem to the owner of the installation; never post it in a public issue.
