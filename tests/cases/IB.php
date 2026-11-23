<?php
/* Inbox (lib/inbox.php, modules/inbox/) — outside calls only through a fake transport; no database here. */
require_once WN_ROOT . '/lib/inbox.php';
$lib = file_get_contents(WN_ROOT . '/lib/inbox.php');
$page = file_get_contents(WN_ROOT . '/modules/inbox/index.php');
$hook = file_get_contents(WN_ROOT . '/modules/inbox/wa_hook.php');
$mig = file_get_contents(WN_ROOT . '/db/migrations/0054_inbox.sql');
t('IB01 page behind the module gate, works on its own login first', str_contains($page, "module_require('inbox')") && strpos($page, 'ib_owner($uid);') < strpos($page, "\$_SERVER['REQUEST_METHOD']"));
t('IB02 hook is public through module_public, throttled, signature checked before reading', str_contains($hook, "module_public('inbox')") && str_contains($hook, 'ip_throttled(') && str_contains($hook, 'HTTP_X_HUB_SIGNATURE_256') && str_contains($lib, 'if ($signer <= 0) return 401;'));
t('IB03 one row per login + service; messages per login', str_contains($mig, 'PRIMARY KEY (uid, svc)') && str_contains($mig, 'UNIQUE KEY uq_mid (uid, mid)') && str_contains($lib, 'SELECT * FROM ib_acct WHERE uid = ? AND svc = ?') && str_contains($lib, 'WHERE uid = ? AND peer = ?'));
t('IB04 secrets sealed, never printed; verify token shown once then last 4', str_contains($lib, 'seal(json_encode($box))') && !preg_match('/echo[^;]*(secret|refresh|token)\]/', $page) && str_contains($page, "substr((string)\$WS['verify'], -4)"));
t('IB05 log and audit never carry text, numbers or addresses; nothing deleted', !preg_match('/ib_log\([^)]*\$text/', $lib) && str_contains($lib, "audit(\$by, 'inbox_' . \$act, \$svc);") && !preg_match('/\bDELETE\s+FROM\b|\bDROP\b|\bTRUNCATE\b/i', $lib) && audit_is_sensitive('inbox_connect'));
t('IB06 Connect is a link with a derived key, never the session token', str_contains($page, 'index.php?connect=zoho&amp;k=') && ib_connect_key('tok', 'zoho') !== ib_connect_key('tok', 'google') && !str_contains(ib_connect_key('tok', 'zoho'), 'tok'));
t('IB07 OAuth state checked and sign-in addresses right', str_contains($page, "hash_equals((string)(\$st[1] ?? ''), (string)(\$_GET['state'] ?? ''))")
    && str_starts_with(ib_auth_url(['dc' => 'eu', 'client_id' => 'TST.CLIENT01'], 'zoho', 's'), 'https://accounts.zoho.eu/oauth/v2/auth?')
    && str_starts_with(ib_auth_url(['dc' => 'com', 'client_id' => 'TST.CLIENT01'], 'google', 's'), 'https://accounts.google.com/o/oauth2/v2/auth?'));
t('IB08 Zoho hosts by region, unknown region falls back to zoho.com', ib_zoho_host('ca', 'mail') === 'https://mail.zohocloud.ca' && ib_zoho_host('x', 'cliq') === 'https://cliq.zoho.com');
t('IB09 webhook signature', ib_wa_sig_ok('{"a":1}', 'sha256=' . hash_hmac('sha256', '{"a":1}', 'tstsecret'), 'tstsecret') && !ib_wa_sig_ok('{"a":1}', 'sha256=' . hash_hmac('sha256', '{"a":2}', 'tstsecret'), 'tstsecret') && !ib_wa_sig_ok('x', 'sha256=1', ''));
$loc = '202' . '555' . '0100';   // a reserved fictional number, joined so no real-looking number sits in the file
t('IB10 numbers: country code only from the setting, never assumed', ib_wa_num('202 555 0100', '') === $loc && ib_wa_num('202-555-0100', '44') === '44' . $loc && ib_wa_num('+1 ' . $loc, '91') === '1' . $loc && ib_wa_num('12', '') === '');
t('IB11 message text, never the media', ib_wa_text(['type' => 'text', 'text' => ['body' => 'hi']]) === 'hi' && ib_wa_text(['type' => 'image', 'image' => ['caption' => 'TEST']]) === '[image] TEST' && ib_wa_text(['type' => 'contacts']) === '[contact]');
$now = 1_800_000_000;
t('IB12 24-hour reply window', ib_wa_open([['dir' => 'in', 'ts' => $now - 3600]], $now) && !ib_wa_open([['dir' => 'in', 'ts' => $now - 90000]], $now) && !ib_wa_open([['dir' => 'out', 'ts' => $now]], $now));
$d = ib_mail_doc('<p onclick="x()">Hi</p><script>alert(1)</script><a href="javascript:alert(2)">x</a><iframe src="//e"></iframe>');
t('IB13 mail bodies fenced and shown in a sandbox', !str_contains($d, '<script') && !str_contains($d, 'onclick') && !str_contains($d, 'javascript:') && !str_contains($d, '<iframe') && str_contains($page, 'sandbox="allow-popups allow-popups-to-escape-sandbox"'));
$raw = ib_b64url_dec(ib_gmail_raw('a@example.com', "b@example.com\r\nBcc: x@example.com", 'Hello', 'Thanks', '<m1@example.com>'));
t('IB14 Gmail reply threaded, no header injection', str_contains($raw, 'In-Reply-To: <m1@example.com>') && !str_contains($raw, "\nBcc:") && ib_re('Re: X') === 'Re: X' && ib_re('X') === 'Re: X' && ib_addr('TEST <t@example.com>') === 't@example.com');
t('IB15 a staging copy sends nothing', substr_count($lib, 'ib_no_stage();') === 4 && str_contains($lib, 'if (is_stage()) throw'));
$ok = true; try { ib_settings_check(['wa_cc' => '12345', 'zoho_dc' => 'com']); $ok = false; } catch (InvalidArgumentException $e) {}
t('IB16 settings: neutral defaults, checked', IB_DEFAULTS === ['wa_cc' => '', 'zoho_dc' => 'com'] && $ok && ib_settings_check(['wa_cc' => '+44', 'zoho_dc' => 'eu']) === ['wa_cc' => '44', 'zoho_dc' => 'eu']);
ib_owner(0); $thrown = false; try { ib_me(); } catch (Throwable $e) { $thrown = true; }
t('IB17 no login, no inbox', $thrown);
t('IB18 Embedded Signup widens the page policy for Settings only', str_contains($page, "if (\$V === 'set' && \$W['client_id'] !== ''") && str_contains($page, 'WN_CSP));'));
