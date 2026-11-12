<?php
/**
 * Outgoing email — one mailer for the whole product.
 *
 * Services (set in config.php only — never in the database, a page or GitHub):
 *   google : Gmail API as `mail_from` through a Google Workspace service account with domain-wide delegation
 *            (scope gmail.send). config: 'mail_google_sa' => path to the JSON key.
 *   smtp   : any SMTP server (Zoho, Microsoft 365, the host's own …). config: mail_smtp_host, mail_smtp_port,
 *            mail_smtp_secure (ssl | tls), mail_smtp_user, mail_smtp_pass, mail_smtp_from (optional).
 *   config 'mail_from' (sender address), 'mail_from_name' (default: organisation name).
 * Settings (Email & 2-step page): which service first, try the other when it fails, 2-step on / off.
 * Every email goes through mail_queue() (outbox, retried with waits 5, 10, 20 … min, 6 tries) or mail_send_now()
 * (sign-in codes, logged without the body). A staging copy sends nothing. TLS certificates are always checked.
 */
declare(strict_types=1);

const MAIL_SERVICES = ['google' => 'Google Workspace (Gmail API)', 'smtp' => 'SMTP server'];
const MAIL_TRIES = 6;
const MAIL_KINDS = ['letter' => 'Letter', 'code' => 'Sign-in code', 'test' => 'Test', 'notice' => 'Notice'];
const MAIL_DEFAULTS = ['primary' => 'smtp', 'fallback' => true, 'tfa' => false, 'trust_days' => 30];

/* ---------------- pure ---------------- */

function mail_hdr(string $s): string { return trim(str_replace(["\r", "\n", "\0"], ' ', $s)); }

function mail_valid(string $e): bool {
    $e = trim($e);
    return $e !== '' && strlen($e) <= 160 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/[\r\n,;<>"]/', $e);
}

/** "a•••@example.com" */
function mail_mask(string $e): string {
    $at = strrpos($e, '@');
    if ($at === false) return '';
    return mb_substr($e, 0, 1) . str_repeat('•', max(1, min(6, $at - 1))) . substr($e, $at);
}

function mail_cc_list($cc): array {
    $l = is_array($cc) ? $cc : preg_split('/[,;\s]+/', (string)$cc);
    return array_values(array_unique(array_filter(array_map(fn($x) => strtolower(trim((string)$x)), $l), 'mail_valid')));
}

/** Services in the order to try. $settings from the page, $ready from config. Pure. */
function mail_order_for(array $settings, array $ready): array {
    $p = $settings['primary'] === 'google' ? ['google', 'smtp'] : ['smtp', 'google'];
    if (!$settings['fallback']) $p = [$p[0]];
    return array_values(array_filter($p, fn($s) => !empty($ready[$s])));
}

/** Wait before the next try: 5, 10, 20, 40, 80, 160 minutes. Pure. */
function mail_backoff(int $tries): int { return 300 * (2 ** max(0, min(5, $tries - 1))); }

/** RFC 5322 message: plain text, optional HTML alternative. $m = [to, subject, text, html?, cc?, reply_to?, auto?]. Pure. */
function mail_mime(string $from, string $fromName, array $m, ?string $seed = null): string {
    $enc = fn(string $h) => preg_match('/[^\x20-\x7E]/', $h) ? '=?UTF-8?B?' . base64_encode($h) . '?=' : $h;
    $seed ??= bin2hex(random_bytes(8));
    $to = mail_hdr((string)$m['to']);
    if (!mail_valid($to)) throw new InvalidArgumentException('Not an email address: ' . $to);
    $dom = substr($from, (int)strrpos($from, '@') + 1);
    $h = 'From: ' . $enc(str_replace('"', '', mail_hdr($fromName))) . ' <' . mail_hdr($from) . ">\r\n"
       . 'To: <' . $to . ">\r\n"
       . (($cc = mail_cc_list($m['cc'] ?? [])) ? 'Cc: ' . implode(', ', array_map(fn($x) => '<' . $x . '>', $cc)) . "\r\n" : '')
       . (!empty($m['reply_to']) && mail_valid((string)$m['reply_to']) ? 'Reply-To: <' . mail_hdr((string)$m['reply_to']) . ">\r\n" : '')
       . 'Subject: ' . $enc(mail_hdr((string)$m['subject'])) . "\r\n"
       . 'Date: ' . date('r') . "\r\n" . 'Message-ID: <' . $seed . '.' . time() . '@' . $dom . ">\r\n"
       . (!empty($m['auto']) ? "Auto-Submitted: auto-generated\r\n" : '') . "MIME-Version: 1.0\r\n";
    $b64 = fn(string $s) => chunk_split(base64_encode($s));
    $text = "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64((string)$m['text']);
    if (empty($m['html'])) return $h . $text;
    $b = 'alt' . $seed;
    return $h . "Content-Type: multipart/alternative; boundary=\"$b\"\r\n\r\n--$b\r\n" . $text
         . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64((string)$m['html']) . "--$b--\r\n";
}

/** A plain branded HTML version of a text message (paragraphs, https links clickable). Pure. */
/** $extra = trusted HTML added under the text (an issued letter's frozen body). */
function mail_html(string $text, string $heading, string $orgName, string $color = '#13265C', string $extra = ''): string {
    $esc = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $paras = '';
    foreach (preg_split("/\n{2,}/", trim($text)) as $p)
        $paras .= '<p style="margin:0 0 14px">' . preg_replace('~(https://[^\s<]+)~', '<a href="$1">$1</a>', nl2br($esc($p), false)) . '</p>';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#13265C';
    return '<!doctype html><html><body style="margin:0;background:#f3f5f9;font-family:Arial,Helvetica,sans-serif;color:#1d2433">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:10px;border-top:5px solid ' . $color . '">'
         . '<tr><td style="padding:22px 26px 6px;font-size:13px;letter-spacing:.06em;color:' . $color . ';font-weight:bold">' . $esc(mb_strtoupper($orgName)) . '</td></tr>'
         . ($heading !== '' ? '<tr><td style="padding:4px 26px 8px;font-size:20px;font-weight:bold">' . $esc($heading) . '</td></tr>' : '')
         . '<tr><td style="padding:8px 26px 18px;font-size:15px;line-height:1.55">' . $paras . ($extra !== '' ? '<hr>' . $extra : '') . '</td></tr></table></td></tr></table></body></html>';
}

/* ---------------- config and settings ---------------- */

function mail_from(): string { $f = strtolower(trim((string)cfg('mail_from', ''))); return mail_valid($f) ? $f : ''; }
function mail_from_name(): string { return mail_hdr((string)(cfg('mail_from_name', '') ?: org('name'))) ?: 'WorkNest HR'; }

function mail_google_sa(): ?array {
    $p = (string)cfg('mail_google_sa', '');
    if ($p === '' || !is_file($p)) return null;
    $j = json_decode((string)file_get_contents($p), true);
    return is_array($j) && !empty($j['client_email']) && !empty($j['private_key']) ? $j : null;
}

function mail_smtp_opts(): ?array {
    $h = trim((string)cfg('mail_smtp_host', ''));
    if ($h === '' || (string)cfg('mail_smtp_user', '') === '' || (string)cfg('mail_smtp_pass', '') === '') return null;
    return ['host' => $h, 'port' => (int)cfg('mail_smtp_port', 465), 'secure' => in_array(cfg('mail_smtp_secure', 'ssl'), ['ssl', 'tls', 'none'], true) ? cfg('mail_smtp_secure', 'ssl') : 'ssl',
            'user' => (string)cfg('mail_smtp_user'), 'pass' => (string)cfg('mail_smtp_pass'), 'from' => strtolower(trim((string)cfg('mail_smtp_from', '')))];
}

function mail_ready(): array { return ['google' => mail_google_sa() !== null && mail_from() !== '', 'smtp' => mail_smtp_opts() !== null && (mail_from() !== '' || mail_valid((string)(mail_smtp_opts()['from'] ?? '')))]; }

function mail_settings(): array {
    $s = module_states()['email']['settings'] ?? [];
    return array_replace(MAIL_DEFAULTS, array_intersect_key($s, MAIL_DEFAULTS));
}

/** Pure check of the settings form. */
function mail_settings_check(array $in): array {
    $o = ['primary' => ($in['primary'] ?? '') === 'google' ? 'google' : 'smtp', 'fallback' => !empty($in['fallback']), 'tfa' => !empty($in['tfa'])];
    $d = filter_var($in['trust_days'] ?? 30, FILTER_VALIDATE_INT);
    $o['trust_days'] = $d === false ? 30 : max(0, min(90, $d));
    return $o;
}

function mail_order(): array {
    if (is_stage() || !module_on('email')) return [];   // a staging copy sends nothing
    return mail_order_for(mail_settings(), mail_ready());
}
function mail_can_send(): bool { return mail_order() !== []; }

/* ---------------- sending ---------------- */

function mail_http_post(string $url, array $headers, string $body): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = $raw === false ? curl_error($ch) : ''; curl_close($ch);
    if ($raw === false) throw new RuntimeException('Could not reach Google: ' . $err);
    return [$code, (string)$raw];
}

function mail_b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

/** Access token to send as $user (domain-wide delegation, scope gmail.send). */
function mail_google_token(string $user, ?callable $post = null, ?array $sa = null): string {
    static $cache = [];
    if (isset($cache[$user]) && $cache[$user][1] > time() + 60) return $cache[$user][0];
    $sa ??= mail_google_sa();
    if (!$sa) throw new RuntimeException('Google Workspace is not set up (service-account key missing).');
    $now = time();
    $jwt = mail_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . mail_b64url(json_encode([
        'iss' => $sa['client_email'], 'sub' => $user, 'scope' => 'https://www.googleapis.com/auth/gmail.send',
        'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
    if (!openssl_sign($jwt, $sig, (string)$sa['private_key'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('The Google key could not sign.');
    [$code, $raw] = ($post ?? 'mail_http_post')('https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt . '.' . mail_b64url($sig)]));
    $j = json_decode($raw, true);
    if ($code !== 200 || empty($j['access_token'])) throw new RuntimeException('Google refused the mailbox ' . $user . ' (' . $code . '): ' . mb_substr((string)($j['error_description'] ?? $j['error'] ?? $raw), 0, 160));
    $cache[$user] = [$j['access_token'], $now + (int)($j['expires_in'] ?? 3000)];
    return $j['access_token'];
}

function mail_google_send(string $from, string $raw, ?callable $post = null, ?array $sa = null): string {
    $post ??= 'mail_http_post';
    $tok = mail_google_token($from, $post, $sa);
    [$code, $res] = $post('https://gmail.googleapis.com/gmail/v1/users/' . rawurlencode($from) . '/messages/send',
        ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'], json_encode(['raw' => mail_b64url($raw)]));
    $j = json_decode($res, true);
    if ($code !== 200 || empty($j['id'])) throw new RuntimeException('Gmail refused (' . $code . '): ' . mb_substr((string)($j['error']['message'] ?? $res), 0, 160));
    return (string)$j['id'];
}

/** SMTP with AUTH LOGIN. Certificates are checked; plain connections only to this machine. */
function mail_smtp_send(string $from, string $to, string $raw, array $o, array $cc = []): string {
    $host = (string)$o['host']; $port = (int)$o['port']; $sec = (string)($o['secure'] ?? 'ssl'); $timeout = (int)($o['timeout'] ?? 20);
    if ($sec === 'none' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) throw new RuntimeException('SMTP without encryption is not allowed.');
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
    $fp = @stream_socket_client(($sec === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException('Could not reach ' . $host . ':' . $port . ' (' . $errstr . ').');
    stream_set_timeout($fp, $timeout);
    $read = function () use ($fp): array {
        $t = '';
        while (($line = fgets($fp, 1024)) !== false) { $t .= $line; if (strlen($line) < 4 || $line[3] === ' ') break; }
        if ($t === '') throw new RuntimeException('The mail server closed the connection.');
        return [(int)substr($t, 0, 3), trim($t)];
    };
    $say = function (?string $line, array $want, string $what) use ($fp, $read): string {
        if ($line !== null) fwrite($fp, $line . "\r\n");
        [$c, $t] = $read();
        if (!in_array($c, $want, true)) throw new RuntimeException("Mail server refused $what: " . mb_substr(preg_replace('/\s+/', ' ', $t), 0, 160));
        return $t;
    };
    try {
        $say(null, [220], 'the connection');
        $me = preg_replace('/[^A-Za-z0-9.-]/', '', (string)($_SERVER['SERVER_NAME'] ?? '')) ?: 'worknest.local';
        $say('EHLO ' . $me, [250], 'the greeting');
        if ($sec === 'tls') {
            $say('STARTTLS', [220], 'STARTTLS');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0)))
                throw new RuntimeException('Could not start TLS with ' . $host . '.');
            $say('EHLO ' . $me, [250], 'the greeting');
        }
        $say('AUTH LOGIN', [334], 'sign-in');
        $say(base64_encode((string)$o['user']), [334], 'the user name');
        $say(base64_encode((string)$o['pass']), [235], 'the password (check it is an app password)');
        $say('MAIL FROM:<' . mail_hdr($from) . '>', [250], 'the sender ' . $from);
        foreach (array_merge([$to], mail_cc_list($cc)) as $r) $say('RCPT TO:<' . mail_hdr($r) . '>', [250, 251], 'the address ' . $r);
        $say('DATA', [354], 'the message');
        fwrite($fp, rtrim(preg_replace('/^\./m', '..', preg_replace("/\r?\n/", "\r\n", $raw)), "\r\n") . "\r\n.\r\n");
        $ok = $say(null, [250], 'the message');
        @fwrite($fp, "QUIT\r\n");
        return mb_substr(preg_replace('/^250[ -]/', '', $ok), 0, 120);
    } finally { @fclose($fp); }
}

/**
 * Send now, trying the services in order. $senders (tests): ['google' => fn($from, $raw), 'smtp' => fn($from, $to, $raw)].
 * @return array{service:string, id:string, errors:list<string>}
 */
function mail_send_now(array $m, ?array $senders = null, ?array $order = null): array {
    $order ??= $senders ? array_keys($senders) : mail_order();
    if (!$order) throw new RuntimeException('Email is not set up yet.');
    $errors = [];
    foreach ($order as $svc) {
        try {
            if ($svc === 'google') {
                $from = mail_from();
                $raw = mail_mime($from, mail_from_name(), $m + ['reply_to' => $from]);
                $id = isset($senders['google']) ? ($senders['google'])($from, $raw) : mail_google_send($from, $raw);
            } else {
                $o = mail_smtp_opts() ?? [];
                $from = mail_valid((string)($o['from'] ?? '')) ? (string)$o['from'] : mail_from();
                if ($from === '') throw new RuntimeException('No sender address (mail_from) in config.php.');
                $raw = mail_mime($from, mail_from_name(), $m + ['reply_to' => $from]);
                $id = isset($senders['smtp']) ? ($senders['smtp'])($from, (string)$m['to'], $raw) : mail_smtp_send($from, (string)$m['to'], $raw, $o, mail_cc_list($m['cc'] ?? []));
            }
            return ['service' => $svc, 'id' => (string)$id, 'errors' => $errors];
        } catch (Throwable $e) { $errors[] = (MAIL_SERVICES[$svc] ?? $svc) . ': ' . $e->getMessage(); }
    }
    throw new RuntimeException(implode(' · ', $errors));
}

/** Queue a message (sent by mail_drain after the page's own work is saved). */
function mail_queue(PDO $db, array $m, string $kind = 'notice', string $ref = '', ?int $by = null): int {
    if (!mail_valid((string)($m['to'] ?? ''))) throw new InvalidArgumentException('Not an email address.');
    if (!isset(MAIL_KINDS[$kind])) $kind = 'notice';
    $db->prepare('INSERT INTO mail_out (kind, ref, to_addr, cc_addr, subject, body_text, body_html, next_try_at, created_by) VALUES (?,?,?,?,?,?,?,NOW(),?)')
       ->execute([$kind, mb_substr($ref, 0, 60), strtolower(trim((string)$m['to'])), implode(', ', mail_cc_list($m['cc'] ?? [])), mb_substr(mail_hdr((string)$m['subject']), 0, 250),
                  (string)$m['text'], isset($m['html']) ? (string)$m['html'] : null, $by]);
    return (int)$db->lastInsertId();
}

/** Log a message sent at once without keeping its body (sign-in codes). */
function mail_log_sent(PDO $db, string $kind, string $to, string $subject, array $res, ?int $by = null): void {
    $db->prepare("INSERT INTO mail_out (kind, to_addr, subject, status, tries, service, provider_id, error, created_by, sent_at) VALUES (?,?,?,'sent',1,?,?,?,?,NOW())")
       ->execute([$kind, strtolower($to), mb_substr($subject, 0, 250), $res['service'], mb_substr($res['id'], 0, 160), mb_substr(implode(' · ', $res['errors']), 0, 500), $by]);
}

/** Send what is due (or the given ids). A claim guards each row for 10 minutes. */
function mail_drain(PDO $db, ?array $ids = null, ?array $senders = null, int $max = 25): array {
    $db->exec("UPDATE mail_out SET status = 'queued' WHERE status = 'sending' AND next_try_at < NOW()");
    if ($ids !== null) {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return ['sent' => 0, 'failed' => 0, 'waiting' => 0];
        $q = $db->prepare("SELECT id FROM mail_out WHERE status = 'queued' AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')'); $q->execute($ids);
    } else {
        $q = $db->query("SELECT id FROM mail_out WHERE status = 'queued' AND (next_try_at IS NULL OR next_try_at <= NOW()) ORDER BY id LIMIT " . max(1, min(200, $max)));
    }
    $sent = 0; $failed = 0;
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $c = $db->prepare("UPDATE mail_out SET status = 'sending', tries = tries + 1, next_try_at = NOW() + INTERVAL 10 MINUTE WHERE id = ? AND status = 'queued'");
        $c->execute([$id]);
        if ($c->rowCount() !== 1) continue;
        $s = $db->prepare('SELECT * FROM mail_out WHERE id = ?'); $s->execute([$id]); $r = $s->fetch();
        try {
            $res = mail_send_now(['to' => $r['to_addr'], 'cc' => (string)$r['cc_addr'], 'subject' => $r['subject'], 'text' => (string)$r['body_text'], 'html' => $r['body_html']], $senders);
            $db->prepare("UPDATE mail_out SET status = 'sent', service = ?, provider_id = ?, error = ?, sent_at = NOW(), next_try_at = NULL WHERE id = ?")
               ->execute([$res['service'], mb_substr($res['id'], 0, 160), mb_substr(implode(' · ', $res['errors']), 0, 500), $id]);
            $sent++;
        } catch (Throwable $e) {
            $final = (int)$r['tries'] >= MAIL_TRIES;
            $db->prepare('UPDATE mail_out SET status = ?, error = ?, next_try_at = ' . ($final ? 'NULL' : 'NOW() + INTERVAL ? SECOND') . ' WHERE id = ?')
               ->execute($final ? ['failed', mb_substr($e->getMessage(), 0, 500), $id] : ['queued', mb_substr($e->getMessage(), 0, 500), mail_backoff((int)$r['tries']), $id]);
            if ($final) $failed++;
        }
    }
    return ['sent' => $sent, 'failed' => $failed, 'waiting' => (int)$db->query("SELECT COUNT(*) FROM mail_out WHERE status IN ('queued','sending')")->fetchColumn()];
}

/** Queue and try once right after the page answer. */
function mail_queue_send(PDO $db, array $m, string $kind, string $ref, ?int $by): int {
    $id = mail_queue($db, $m, $kind, $ref, $by);
    register_shutdown_function(function () use ($db, $id) { if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); try { mail_drain($db, [$id]); } catch (Throwable $e) {} });
    return $id;
}

function mail_retry(PDO $db, int $id, int $by): void {
    $s = $db->prepare("UPDATE mail_out SET status = 'queued', tries = 0, next_try_at = NOW(), error = '' WHERE id = ? AND status = 'failed' AND kind <> 'code'");
    $s->execute([$id]);
    if ($s->rowCount() !== 1) throw new RuntimeException('That message cannot be sent again.');
    audit($by, 'mail_retry', 'mail #' . $id);
}

function mail_recent(PDO $db, int $limit = 40): array {
    return $db->query('SELECT id, kind, ref, to_addr, subject, status, tries, service, error, created_at, sent_at FROM mail_out ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)))->fetchAll();
}

function kv_get(PDO $db, string $k): ?string { $s = $db->prepare('SELECT v FROM app_kv WHERE k = ?'); $s->execute([$k]); $v = $s->fetchColumn(); return $v === false ? null : (string)$v; }
function kv_set(PDO $db, string $k, string $v): void { $db->prepare('INSERT INTO app_kv (k, v) VALUES (?,?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]); }

/** Is the cron job running (beat within 15 min)? null = never ran. */
function mail_job_running(PDO $db): ?bool {
    $v = json_decode((string)kv_get($db, 'mail_job_last'), true);
    return is_array($v) && isset($v['at']) ? time() - (int)$v['at'] < 900 : null;
}
