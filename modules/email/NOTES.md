# Email & 2-step — porting notes

## Ported (logic only)
- One mailer: Google Workspace (Gmail API, service account with domain-wide delegation) or any SMTP server; use first,
  try the other on failure. TLS certificates always checked; plain SMTP only to this machine.
- Outbox with retries (5, 10, 20 … minutes, 6 tries), claim guard so two runs never send the same row, "Send again",
  cron `tools/mail_drain.php`, job running / not running shown on the page only.
- 2-step sign-in: 6-digit code by email after the right password; 10 minutes, 5 tries, newest only, 5 codes / 15 min;
  HMAC only; trust this browser (signed over the login and its password hash; a password change ends it).
- Off by itself while no mail service works; a login without an email signs in with the password alone (audited);
  emergency `'tfa_off' => true` in config.php. Per-login switch off with a reason (append-only log), never one's own;
  the owner's only by the owner.
- Letters: an issued letter can be emailed to the person, with a fresh answer link when it needs one.

## Changed from the reference build
- "Zoho" becomes any SMTP server; addresses come from the login's email, else the employee's work email (no
  company-domain lists). The sender name is the organisation's.

## Not ported
- Company sender addresses, mailbox domain rules, official-mailbox table, Lite sign-in, attachments by file path.

## Later
- Attach a PDF once the product makes PDFs on the server; notices by email for other modules.
