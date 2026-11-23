<?php
/**
 * Inbox — each login's own company mail (Zoho Mail, Gmail), Zoho Cliq and WhatsApp Business, read and answered inside
 * WorkNest HR. Off until the Super Admin switches the module on, and then each service is off until that login connects it.
 *
 * - Whose: every connection and every WhatsApp message belongs to the one login that connected it — ib_acct is keyed by
 *   login + service and ib_wa_msg carries the login. The page sets the login with ib_owner(); the webhook finds it by the
 *   WhatsApp number Meta names (metadata.phone_number_id), else by whose app secret signed the call.
 * - Secrets: client secrets, refresh / access tokens, the Meta app secret and the webhook verify token are sealed with
 *   seal() in ib_acct.box — never in a page, an answer, a URL, a log line or the repository. Only the last 4 show.
 * - Mail and Cliq are read live each time (nothing copied here). Mail bodies show in a sandboxed frame.
 * - WhatsApp: Meta posts to modules/inbox/wa_hook.php (signature checked); messages are kept in ib_wa_msg. Replies go
 *   through Meta's Cloud API only within 24 h of the customer's last message.
 * - A staging copy sends nothing: replies and posts are refused there.
 * - Nothing here deletes a message anywhere. ib_log is append-only and never holds a secret or message text.
 * Every outside call goes through ib_http() with an injectable transport, so tests never reach Zoho, Google or Meta.
 */
declare(strict_types=1);

const IB_SVCS = ['zoho' => 'Zoho Mail & Cliq', 'google' => 'Gmail', 'wa' => 'WhatsApp Business'];
const IB_ZOHO_DC = ['in' => 'India (zoho.in)', 'com' => 'US (zoho.com)', 'eu' => 'Europe (zoho.eu)', 'com.au' => 'Australia (zoho.com.au)',
                    'jp' => 'Japan (zoho.jp)', 'ca' => 'Canada (zohocloud.ca)', 'sa' => 'Saudi Arabia (zoho.sa)', 'uk' => 'UK (zoho.uk)'];
const IB_ZOHO_SCOPES = 'ZohoMail.accounts.READ,ZohoMail.folders.READ,ZohoMail.messages.ALL,ZohoCliq.Chats.READ,ZohoCliq.Messages.READ,ZohoCliq.Webhooks.CREATE';
const IB_GOOGLE_SCOPES = 'https://www.googleapis.com/auth/gmail.modify';
const IB_META_VER = 'v26.0';
const IB_LIST = 30;           // messages / chats a list shows
const IB_WA_WINDOW = 86400;   // Meta's customer-service window: free-form replies only within 24 h of the customer's last message

/* ---------- whose connections ---------- */

/** The login whose Inbox this request works on (set once by the page / webhook). */
function ib_owner(?int $set = null): int {
    static $o = 0;
    if ($set !== null) $o = max(0, $set);
    return $o;
}
function ib_me(): int {
    $o = ib_owner();
    if ($o <= 0) throw new RuntimeException('No login for the inbox');
    return $o;
}

/* ---------- storage (tables in db/migrations/0054_inbox.sql) ---------- */

function ib_install(PDO $db): void {}   // tables come from the migration

function ib_log(PDO $db, string $svc, string $act, ?int $by, string $note = ''): void {
    try {
        $db->prepare('INSERT INTO ib_log (uid, svc, act, by_user, note) VALUES (?,?,?,?,?)')->execute([ib_owner(), $svc, $act, $by, mb_substr($note, 0, 250)]);
    } catch (Throwable $e) {}
    audit($by, 'inbox_' . $act, $svc);   // never the address, number or text
}

/** What the page may show about a service: no secret, no token. */
function ib_conn(PDO $db, string $svc): array {
    ib_install($db);
    $s = $db->prepare('SELECT * FROM ib_acct WHERE uid = ? AND svc = ?'); $s->execute([ib_me(), $svc]);
    $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    $info = json_decode((string)($r['info'] ?? ''), true);
    return ['svc' => $svc, 'client_id' => (string)($r['client_id'] ?? ''), 'dc' => (string)($r['dc'] ?? ib_settings()['zoho_dc']),
        'hint' => (string)($r['hint'] ?? ''), 'has_box' => !empty($r['box']), 'connected' => (int)($r['connected'] ?? 0) === 1,
        'info' => is_array($info) ? $info : [], 'last_err' => (string)($r['last_err'] ?? '')];
}
/** The sealed part (server side only). */
function ib_secret(PDO $db, string $svc): array {
    ib_install($db);
    $s = $db->prepare('SELECT box FROM ib_acct WHERE uid = ? AND svc = ?'); $s->execute([ib_me(), $svc]);
    $b = $s->fetchColumn();
    return is_string($b) && $b !== '' ? (json_decode(unseal($b), true) ?: []) : [];
}
/** Save part of a service. $sec keys merge into the sealed box (blank = keep); $info merges into the shown info. */
function ib_put(PDO $db, string $svc, array $plain, array $sec = [], array $info = [], ?int $by = null): void {
    if (!isset(IB_SVCS[$svc])) throw new InvalidArgumentException('Unknown service');
    ib_install($db);
    $cur = ib_conn($db, $svc);
    $box = ib_secret($db, $svc);
    foreach ($sec as $k => $v) { if ($v === null) unset($box[$k]); elseif ((string)$v !== '') $box[$k] = (string)$v; }
    $inf = $cur['info'];
    foreach ($info as $k => $v) { if ($v === null) unset($inf[$k]); else $inf[$k] = $v; }
    $hint = (string)($box['refresh'] ?? $box['token'] ?? '');
    $hint = $hint !== '' ? substr($hint, -4) : '';
    $db->prepare('INSERT INTO ib_acct (uid, svc, client_id, dc, box, info, hint, connected, last_err, updated_by) VALUES (?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE client_id = VALUES(client_id), dc = VALUES(dc), box = VALUES(box), info = VALUES(info), hint = VALUES(hint),
        connected = VALUES(connected), last_err = VALUES(last_err), updated_by = VALUES(updated_by)')
       ->execute([ib_me(), $svc, (string)($plain['client_id'] ?? $cur['client_id']), (string)($plain['dc'] ?? $cur['dc']),
           $box ? seal(json_encode($box)) : null, json_encode($inf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $hint,
           (int)($plain['connected'] ?? $cur['connected']), mb_substr((string)($plain['last_err'] ?? $cur['last_err']), 0, 250), $by]);
}
function ib_err(PDO $db, string $svc, string $msg): void {
    try { $db->prepare('UPDATE ib_acct SET last_err = ? WHERE uid = ? AND svc = ?')->execute([mb_substr($msg, 0, 250), ib_me(), $svc]); } catch (Throwable $e) {}
}
/** Disconnect: tokens dropped (client id / secret kept so connecting again is one click). Nothing in the mailbox changes. */
function ib_disconnect(PDO $db, string $svc, int $by): void {
    $box = ib_secret($db, $svc);
    unset($box['refresh'], $box['token']);
    ib_install($db);
    $db->prepare('UPDATE ib_acct SET box = ?, hint = \'\', connected = 0, last_err = \'\', updated_by = ? WHERE uid = ? AND svc = ?')
       ->execute([$box ? seal(json_encode($box)) : null, $by, ib_me(), $svc]);
    ib_log($db, $svc, 'disconnect', $by);
}

/* ---------- outside calls ---------- */

/** One HTTP call. $body: array = form fields, string = raw (JSON). @return array{code:int, body:string, err:string} */
function ib_http(string $method, string $url, array $headers = [], array|string|null $body = null): array {
    if (!function_exists('curl_init')) return ['code' => 0, 'body' => '', 'err' => 'PHP curl is not available on the server'];
    $ch = curl_init($url);
    $o = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
          CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, CURLOPT_USERAGENT => 'WorkNest-HR/1.0', CURLOPT_FOLLOWLOCATION => false,
          CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers)];
    if ($body !== null) $o[CURLOPT_POSTFIELDS] = is_array($body) ? http_build_query($body) : $body;
    curl_setopt_array($ch, $o);
    $b = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    return ['code' => $code, 'body' => is_string($b) ? $b : '', 'err' => $err];
}
function ib_json(array $r, string $who): array {
    $j = json_decode((string)($r['body'] ?? ''), true);
    $code = (int)($r['code'] ?? 0);
    if (!is_array($j)) throw new RuntimeException($who . ' did not answer' . (($r['err'] ?? '') !== '' ? ': ' . $r['err'] : ' (HTTP ' . $code . ')'));
    if ($code >= 400 || isset($j['error'])) {
        $e = $j['error'] ?? $j['data']['errorCode'] ?? $j['status']['description'] ?? $j['message'] ?? ('HTTP ' . $code);
        if (is_array($e)) $e = $e['message'] ?? $e['error_user_msg'] ?? json_encode($e);
        throw new RuntimeException($who . ' said: ' . mb_substr((string)$e, 0, 160));
    }
    return $j;
}

/** This site's address, for OAuth redirects and the webhook. config.php 'site_url' wins. */
function ib_base(): string { return rtrim(wn_site_url(), '/'); }
function ib_redirect_uri(string $svc): string { return ib_base() . '/modules/inbox/index.php?cb=' . $svc; }
function ib_hook_url(): string { return ib_base() . '/modules/inbox/wa_hook.php'; }

/* ---------- OAuth (Zoho, Google) ---------- */

/** Key on the Connect link: tied to the session's CSRF token and the service, so the token itself never sits in a URL. */
function ib_connect_key(string $csrf, string $svc): string { return substr(hash_hmac('sha256', 'ib-connect|' . $svc, $csrf), 0, 32); }

function ib_zoho_host(string $dc, string $what): string {
    $root = $dc === 'ca' ? 'zohocloud.ca' : 'zoho.' . (isset(IB_ZOHO_DC[$dc]) ? $dc : 'com');
    return 'https://' . $what . '.' . $root;
}

/** The address the "Connect" button sends the person to. $state is stored in the session by the page. */
function ib_auth_url(array $c, string $svc, string $state): string {
    if ($svc === 'zoho') return ib_zoho_host($c['dc'], 'accounts') . '/oauth/v2/auth?' . http_build_query([
        'scope' => IB_ZOHO_SCOPES, 'client_id' => $c['client_id'], 'response_type' => 'code', 'access_type' => 'offline',
        'prompt' => 'consent', 'redirect_uri' => ib_redirect_uri('zoho'), 'state' => $state]);
    if ($svc === 'google') return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'scope' => IB_GOOGLE_SCOPES, 'client_id' => $c['client_id'], 'response_type' => 'code', 'access_type' => 'offline',
        'prompt' => 'consent', 'include_granted_scopes' => 'true', 'redirect_uri' => ib_redirect_uri('google'), 'state' => $state]);
    throw new InvalidArgumentException('No sign-in page for this service');
}
function ib_token_url(array $c, string $svc): string {
    return $svc === 'zoho' ? ib_zoho_host($c['dc'], 'accounts') . '/oauth/v2/token' : 'https://oauth2.googleapis.com/token';
}

/** Callback: code → refresh token (sealed), then who the account is. */
function ib_oauth_finish(PDO $db, string $svc, string $code, int $by, ?callable $t = null): void {
    $t ??= 'ib_http';
    $c = ib_conn($db, $svc); $S = ib_secret($db, $svc);
    if ($c['client_id'] === '' || ($S['secret'] ?? '') === '') throw new RuntimeException('Save the client ID and secret first');
    $j = ib_json($t('POST', ib_token_url($c, $svc), [], ['grant_type' => 'authorization_code', 'code' => $code,
        'client_id' => $c['client_id'], 'client_secret' => $S['secret'], 'redirect_uri' => ib_redirect_uri($svc)]),
        $svc === 'zoho' ? 'Zoho' : 'Google');
    $ref = (string)($j['refresh_token'] ?? '');
    if ($ref === '') throw new RuntimeException('No refresh token came back — remove the app\'s access in the account and connect again');
    ib_put($db, $svc, ['connected' => 1, 'last_err' => ''], ['refresh' => $ref], [], $by);
    $tok = (string)($j['access_token'] ?? '');
    try { $info = $svc === 'zoho' ? ib_zoho_whoami($c, $tok, $t) : ib_google_whoami($tok, $t); $err = ''; }
    catch (Throwable $e) { $info = []; $err = 'Connected, but the mailbox did not answer: ' . $e->getMessage(); }   // the link stands; the reason shows on Settings
    ib_put($db, $svc, ['last_err' => $err], [], $info + ['connected_on' => date('Y-m-d')], $by);
    ib_log($db, $svc, 'connect', $by, (string)($info['email'] ?? ''));
}

/** A fresh access token (kept for this request only). */
function ib_access(PDO $db, string $svc, ?callable $t = null): string {
    static $cache = [];
    $ck = ib_me() . ':' . $svc;   // one cache entry per login + service
    if (isset($cache[$ck]) && $cache[$ck][1] > time() + 60) return $cache[$ck][0];
    $c = ib_conn($db, $svc); $S = ib_secret($db, $svc);
    if (!$c['connected'] || ($S['refresh'] ?? '') === '') throw new RuntimeException(IB_SVCS[$svc] . ' is not connected yet');
    $j = ib_json(($t ?? 'ib_http')('POST', ib_token_url($c, $svc), [], ['grant_type' => 'refresh_token',
        'refresh_token' => $S['refresh'], 'client_id' => $c['client_id'], 'client_secret' => (string)($S['secret'] ?? '')]),
        $svc === 'zoho' ? 'Zoho' : 'Google');
    $tok = (string)($j['access_token'] ?? '');
    if ($tok === '') throw new RuntimeException('No access token came back');
    if ($t === null) $cache[$ck] = [$tok, time() + (int)($j['expires_in'] ?? 3600)];
    return $tok;
}

/* ---------- Zoho Mail ---------- */

function ib_zoho_whoami(array $c, string $tok, callable $t): array {
    $j = ib_json($t('GET', ib_zoho_host($c['dc'], 'mail') . '/api/accounts', ['Authorization: Zoho-oauthtoken ' . $tok]), 'Zoho Mail');
    $a = $j['data'][0] ?? [];
    $acc = (string)($a['accountId'] ?? '');
    $inbox = '';
    if ($acc !== '') {
        try {
            $f = ib_json($t('GET', ib_zoho_host($c['dc'], 'mail') . '/api/accounts/' . rawurlencode($acc) . '/folders',
                ['Authorization: Zoho-oauthtoken ' . $tok]), 'Zoho Mail');
            foreach ($f['data'] ?? [] as $x) if (strcasecmp((string)($x['folderType'] ?? $x['folderName'] ?? ''), 'Inbox') === 0) { $inbox = (string)$x['folderId']; break; }
        } catch (Throwable $e) {}
    }
    return ['account' => $acc, 'email' => (string)($a['primaryEmailAddress'] ?? $a['mailboxAddress'] ?? ''), 'folder' => $inbox];
}
function ib_zoho_get(PDO $db, string $path, array $q = [], ?callable $t = null): array {
    $c = ib_conn($db, 'zoho');
    $url = ib_zoho_host($c['dc'], 'mail') . '/api/accounts/' . rawurlencode((string)($c['info']['account'] ?? '')) . $path
         . ($q ? '?' . http_build_query($q) : '');
    return ib_json(($t ?? 'ib_http')('GET', $url, ['Authorization: Zoho-oauthtoken ' . ib_access($db, 'zoho', $t)]), 'Zoho Mail');
}
/** @return list<array{id:string, folder:string, from:string, subject:string, summary:string, at:int, unread:bool}> */
function ib_zoho_list(PDO $db, ?callable $t = null): array {
    $c = ib_conn($db, 'zoho');
    $q = ['limit' => IB_LIST, 'start' => 1, 'sortorder' => 'false'];
    if (($c['info']['folder'] ?? '') !== '') $q['folderId'] = $c['info']['folder'];
    $o = [];
    foreach (ib_zoho_get($db, '/messages/view', $q, $t)['data'] ?? [] as $m) $o[] = [
        'id' => (string)($m['messageId'] ?? ''), 'folder' => (string)($m['folderId'] ?? ''),
        'from' => ib_clean((string)($m['sender'] ?? $m['fromAddress'] ?? '')), 'addr' => ib_clean((string)($m['fromAddress'] ?? '')),
        'subject' => ib_clean((string)($m['subject'] ?? '')), 'summary' => ib_clean((string)($m['summary'] ?? '')),
        'at' => (int)floor(((float)($m['receivedTime'] ?? 0)) / 1000), 'unread' => (string)($m['status'] ?? '') === '0'];
    return $o;
}
function ib_zoho_read(PDO $db, string $folder, string $id, ?callable $t = null): array {
    $p = '/folders/' . rawurlencode($folder) . '/messages/' . rawurlencode($id);
    $j = ib_zoho_get($db, $p . '/content', [], $t);
    $h = [];
    try { $h = ib_zoho_get($db, $p . '/details', [], $t)['data'] ?? []; } catch (Throwable $e) {}
    return ['id' => $id, 'folder' => $folder, 'html' => (string)($j['data']['content'] ?? ''),
        'subject' => ib_clean((string)($h['subject'] ?? '')), 'from' => ib_clean((string)($h['fromAddress'] ?? $h['sender'] ?? '')),
        'to' => ib_clean((string)($h['toAddress'] ?? '')), 'at' => (int)floor(((float)($h['receivedTime'] ?? 0)) / 1000)];
}
function ib_zoho_reply(PDO $db, string $id, string $to, string $subject, string $text, int $by, ?callable $t = null): void {
    ib_no_stage();
    $c = ib_conn($db, 'zoho');
    $url = ib_zoho_host($c['dc'], 'mail') . '/api/accounts/' . rawurlencode((string)($c['info']['account'] ?? '')) . '/messages/' . rawurlencode($id);
    ib_json(($t ?? 'ib_http')('POST', $url, ['Authorization: Zoho-oauthtoken ' . ib_access($db, 'zoho', $t), 'Content-Type: application/json'],
        json_encode(['fromAddress' => (string)($c['info']['email'] ?? ''), 'toAddress' => $to, 'subject' => ib_re($subject),
            'content' => nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')), 'action' => 'reply', 'mailFormat' => 'html'])), 'Zoho Mail');
    ib_log($db, 'zoho', 'mail_reply', $by);
}

/* ---------- Zoho Cliq ---------- */

function ib_cliq_call(PDO $db, string $method, string $path, ?array $json = null, ?callable $t = null): array {
    $c = ib_conn($db, 'zoho');
    $h = ['Authorization: Zoho-oauthtoken ' . ib_access($db, 'zoho', $t)];
    if ($json !== null) $h[] = 'Content-Type: application/json';
    $r = ($t ?? 'ib_http')($method, ib_zoho_host($c['dc'], 'cliq') . '/api/v2' . $path, $h, $json !== null ? json_encode($json) : null);
    if ((int)($r['code'] ?? 0) === 204) return [];
    return ib_json($r, 'Zoho Cliq');
}
function ib_cliq_chats(PDO $db, ?callable $t = null): array {
    $o = [];
    foreach (ib_cliq_call($db, 'GET', '/chats?limit=' . IB_LIST, null, $t)['chats'] ?? [] as $x) {
        if (!empty($x['removed'])) continue;
        $lm = $x['last_message_info'] ?? [];
        $o[] = ['id' => (string)($x['chat_id'] ?? ''), 'name' => ib_clean((string)($x['name'] ?? '')),
            'last' => ib_clean((string)($lm['text'] ?? $lm['content']['text'] ?? '')),
            'at' => (int)floor(((float)($x['last_modified_time'] ?? 0)) / 1000)];
    }
    return $o;
}
function ib_cliq_messages(PDO $db, string $chat, ?callable $t = null): array {
    $o = [];
    foreach (ib_cliq_call($db, 'GET', '/chats/' . rawurlencode($chat) . '/messages?limit=' . IB_LIST, null, $t)['data'] ?? [] as $m) {
        $txt = $m['content']['text'] ?? ($m['content']['file']['name'] ?? '');
        $o[] = ['who' => ib_clean((string)($m['sender']['name'] ?? '')), 'text' => ib_clean((string)$txt),
            'type' => (string)($m['type'] ?? 'text'), 'at' => (int)floor(((float)($m['time'] ?? 0)) / 1000)];
    }
    usort($o, fn($a, $b) => $a['at'] <=> $b['at']);
    return $o;
}
function ib_cliq_post(PDO $db, string $chat, string $text, int $by, ?callable $t = null): void {
    ib_no_stage();
    ib_cliq_call($db, 'POST', '/chats/' . rawurlencode($chat) . '/message', ['text' => $text, 'sync_message' => true], $t);
    ib_log($db, 'zoho', 'cliq_post', $by);
}

/* ---------- Gmail ---------- */

function ib_google_whoami(string $tok, callable $t): array {
    $j = ib_json($t('GET', 'https://gmail.googleapis.com/gmail/v1/users/me/profile', ['Authorization: Bearer ' . $tok]), 'Gmail');
    return ['email' => (string)($j['emailAddress'] ?? '')];
}
function ib_gmail_get(PDO $db, string $path, ?callable $t = null): array {
    return ib_json(($t ?? 'ib_http')('GET', 'https://gmail.googleapis.com/gmail/v1/users/me' . $path,
        ['Authorization: Bearer ' . ib_access($db, 'google', $t)]), 'Gmail');
}
function ib_hdr(array $headers, string $name): string {
    foreach ($headers as $h) if (strcasecmp((string)($h['name'] ?? ''), $name) === 0) return (string)($h['value'] ?? '');
    return '';
}
function ib_gmail_list(PDO $db, ?callable $t = null): array {
    $o = [];
    foreach (ib_gmail_get($db, '/messages?labelIds=INBOX&maxResults=20', $t)['messages'] ?? [] as $m) {
        $id = (string)($m['id'] ?? '');
        if ($id === '') continue;
        $d = ib_gmail_get($db, '/messages/' . rawurlencode($id) . '?format=metadata&metadataHeaders=From&metadataHeaders=Subject', $t);
        $H = $d['payload']['headers'] ?? [];
        $o[] = ['id' => $id, 'folder' => (string)($d['threadId'] ?? ''), 'from' => ib_clean(ib_hdr($H, 'From')), 'addr' => ib_clean(ib_hdr($H, 'From')),
            'subject' => ib_clean(ib_hdr($H, 'Subject')), 'summary' => ib_clean(html_entity_decode((string)($d['snippet'] ?? ''), ENT_QUOTES, 'UTF-8')),
            'at' => (int)floor(((float)($d['internalDate'] ?? 0)) / 1000), 'unread' => in_array('UNREAD', $d['labelIds'] ?? [], true)];
    }
    return $o;
}
function ib_b64url_dec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
/** The best body of a Gmail payload: HTML first, else plain text (escaped). */
function ib_gmail_body(array $p): string {
    $html = ''; $text = '';
    $walk = function (array $p) use (&$walk, &$html, &$text) {
        $mt = (string)($p['mimeType'] ?? ''); $data = (string)($p['body']['data'] ?? '');
        if ($data !== '' && $mt === 'text/html' && $html === '') $html = ib_b64url_dec($data);
        if ($data !== '' && $mt === 'text/plain' && $text === '') $text = ib_b64url_dec($data);
        foreach ($p['parts'] ?? [] as $q) if (is_array($q)) $walk($q);
    };
    $walk($p);
    return $html !== '' ? $html : nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
}
function ib_gmail_read(PDO $db, string $id, ?callable $t = null): array {
    $d = ib_gmail_get($db, '/messages/' . rawurlencode($id) . '?format=full', $t);
    $H = $d['payload']['headers'] ?? [];
    return ['id' => $id, 'folder' => (string)($d['threadId'] ?? ''), 'html' => ib_gmail_body($d['payload'] ?? []),
        'subject' => ib_clean(ib_hdr($H, 'Subject')), 'from' => ib_clean(ib_hdr($H, 'Reply-To') ?: ib_hdr($H, 'From')),
        'to' => ib_clean(ib_hdr($H, 'To')), 'msgid' => ib_hdr($H, 'Message-ID'), 'at' => (int)floor(((float)($d['internalDate'] ?? 0)) / 1000)];
}
/** RFC 2822 reply, threaded by In-Reply-To / References. Pure. */
function ib_gmail_raw(string $from, string $to, string $subject, string $text, string $msgid): string {
    $h = ['From: ' . $from, 'To: ' . ib_hdr_safe($to), 'Subject: =?UTF-8?B?' . base64_encode(ib_re($subject)) . '?=',
          'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64'];
    if ($msgid !== '') { $h[] = 'In-Reply-To: ' . ib_hdr_safe($msgid); $h[] = 'References: ' . ib_hdr_safe($msgid); }
    $raw = implode("\r\n", $h) . "\r\n\r\n" . chunk_split(base64_encode($text));
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}
function ib_gmail_reply(PDO $db, string $id, string $text, int $by, ?callable $t = null): void {
    ib_no_stage();
    $m = ib_gmail_read($db, $id, $t);
    $c = ib_conn($db, 'google');
    ib_json(($t ?? 'ib_http')('POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
        ['Authorization: Bearer ' . ib_access($db, 'google', $t), 'Content-Type: application/json'],
        json_encode(['raw' => ib_gmail_raw((string)($c['info']['email'] ?? ''), $m['from'], $m['subject'], $text, (string)$m['msgid']),
            'threadId' => $m['folder']])), 'Gmail');
    ib_log($db, 'google', 'mail_reply', $by);
}

/* ---------- WhatsApp Business (Cloud API, coexistence with the Business app) ---------- */

function ib_graph(string $path): string { return 'https://graph.facebook.com/' . IB_META_VER . '/' . ltrim($path, '/'); }

/** Embedded Signup finished in the page: code → business token, subscribe the app to the WABA, ask Meta to share the
 *  app's contacts and the last 180 days of chats. */
function ib_wa_finish(PDO $db, string $code, string $waba, string $phone, int $by, ?callable $t = null): array {
    $t ??= 'ib_http';
    $c = ib_conn($db, 'wa'); $S = ib_secret($db, 'wa');
    if ($c['client_id'] === '' || ($S['app_secret'] ?? '') === '') throw new RuntimeException('Save the Meta app ID and app secret first');
    if (!preg_match('/^\d{5,30}$/', $waba) || !preg_match('/^\d{5,30}$/', $phone)) throw new RuntimeException('Meta did not send the business account and number');
    $j = ib_json($t('GET', ib_graph('oauth/access_token') . '?' . http_build_query(['client_id' => $c['client_id'],
        'client_secret' => $S['app_secret'], 'code' => $code]), []), 'Meta');
    $tok = (string)($j['access_token'] ?? '');
    if ($tok === '') throw new RuntimeException('Meta gave no token');
    return ib_wa_link($db, $tok, $waba, $phone, $by, $t);
}
/** Shared by Embedded Signup and a token pasted by hand. */
function ib_wa_link(PDO $db, string $tok, string $waba, string $phone, int $by, ?callable $t = null): array {
    $t ??= 'ib_http';
    $auth = ['Authorization: Bearer ' . $tok];
    ib_json($t('POST', ib_graph($waba . '/subscribed_apps'), $auth, []), 'Meta');
    $num = [];
    try { $num = ib_json($t('GET', ib_graph($phone) . '?fields=display_phone_number,verified_name,is_on_biz_app,platform_type', $auth), 'Meta'); } catch (Throwable $e) {}
    ib_put($db, 'wa', ['connected' => 1, 'last_err' => ''], ['token' => $tok], ['waba' => $waba, 'phone_id' => $phone,
        'number' => (string)($num['display_phone_number'] ?? ''), 'name' => (string)($num['verified_name'] ?? ''),
        'on_app' => !empty($num['is_on_biz_app']), 'connected_on' => date('Y-m-d')], $by);
    $sync = [];
    foreach (['smb_app_state_sync', 'history'] as $kind) {
        try {
            ib_json($t('POST', ib_graph($phone . '/smb_app_data'), array_merge($auth, ['Content-Type: application/json']),
                json_encode(['messaging_product' => 'whatsapp', 'sync_type' => $kind])), 'Meta');
            $sync[$kind] = 'asked';
        } catch (Throwable $e) { $sync[$kind] = $e->getMessage(); }
    }
    ib_put($db, 'wa', [], [], ['sync' => $sync], $by);
    ib_log($db, 'wa', 'connect', $by, (string)($num['display_phone_number'] ?? ''));
    return $sync;
}

/** Digits only; a local number (no country code) gets the code set on the module's settings, if any. */
function ib_wa_num(string $s, ?string $cc = null): string {
    $d = preg_replace('/\D/', '', $s) ?? '';
    $cc ??= ib_settings()['wa_cc'];
    $cc = preg_replace('/\D/', '', (string)$cc) ?? '';
    if ($cc !== '' && strlen($d) === 10) $d = $cc . $d;
    return strlen($d) >= 8 && strlen($d) <= 15 ? $d : '';
}
/** What a WhatsApp message object says, as text for the inbox (never the media itself). Pure. */
function ib_wa_text(array $m): string {
    $type = (string)($m['type'] ?? 'text');
    return match ($type) {
        'text' => (string)($m['text']['body'] ?? ''),
        'image', 'video', 'document', 'audio', 'sticker' => '[' . $type . ']' . (($m[$type]['caption'] ?? $m[$type]['filename'] ?? '') !== '' ? ' ' . ($m[$type]['caption'] ?? $m[$type]['filename']) : ''),
        'button' => (string)($m['button']['text'] ?? ''),
        'interactive' => (string)($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? '[reply]'),
        'location' => '[location] ' . trim((string)($m['location']['name'] ?? '') . ' ' . (string)($m['location']['address'] ?? '')),
        'contacts' => '[contact]',
        'reaction' => '[reaction ' . (string)($m['reaction']['emoji'] ?? '') . ']',
        'template' => '[template]',
        default => '[' . $type . ']',
    };
}

function ib_wa_store(PDO $db, array $m, string $peer, string $dir, string $src, string $name = ''): bool {
    $mid = (string)($m['id'] ?? '');
    $peer = ib_wa_num($peer);
    if ($mid === '' || $peer === '') return false;
    $s = $db->prepare('INSERT IGNORE INTO ib_wa_msg (uid, mid, peer, name, dir, src, type, body, ts, status) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $s->execute([ib_me(), mb_substr($mid, 0, 190), $peer, mb_substr($name, 0, 120), $dir, $src, mb_substr((string)($m['type'] ?? 'text'), 0, 20),
        mb_substr(ib_wa_text($m), 0, 4000), (int)($m['timestamp'] ?? time()), $dir === 'out' ? 'sent' : '']);
    if ($s->rowCount() === 0) return false;
    if ($name !== '') $db->prepare('UPDATE ib_wa_msg SET name = ? WHERE uid = ? AND peer = ? AND name = \'\'')->execute([mb_substr($name, 0, 120), ib_me(), $peer]);
    return true;
}

/** A webhook body (already signature-checked). Returns counts. Messages, statuses, the Business app's echoes and
 *  history; account / state-sync events are only counted. */
function ib_wa_ingest(PDO $db, array $p): array {
    ib_install($db);
    $sigOwner = ib_owner();
    $n = ['in' => 0, 'echo' => 0, 'history' => 0, 'status' => 0, 'other' => 0];
    foreach ($p['entry'] ?? [] as $e) foreach ($e['changes'] ?? [] as $ch) {
        $f = (string)($ch['field'] ?? ''); $v = $ch['value'] ?? [];
        if (!is_array($v)) continue;
        // the number Meta names decides whose Inbox this goes to; else the login whose app secret signed the call
        $pid = (string)($v['metadata']['phone_number_id'] ?? '');
        $own = $pid !== '' ? ib_wa_owner_of_phone($db, $pid) : 0;
        ib_owner($own > 0 ? $own : $sigOwner);
        if (ib_owner() <= 0) { $n['other']++; continue; }
        $names = [];
        foreach ($v['contacts'] ?? [] as $k) $names[ib_wa_num((string)($k['wa_id'] ?? ''))] = (string)($k['profile']['name'] ?? '');
        if ($f === 'messages') {
            foreach ($v['messages'] ?? [] as $m) {
                $from = (string)($m['from'] ?? '');
                if (ib_wa_store($db, $m, $from, 'in', 'live', $names[ib_wa_num($from)] ?? '')) $n['in']++;
            }
            foreach ($v['statuses'] ?? [] as $st) {
                $up = $db->prepare('UPDATE ib_wa_msg SET status = ? WHERE uid = ? AND mid = ?');
                $up->execute([mb_substr((string)($st['status'] ?? ''), 0, 12), ib_me(), (string)($st['id'] ?? '')]);
                $n['status'] += $up->rowCount();
            }
        } elseif ($f === 'smb_message_echoes') {
            foreach ($v['message_echoes'] ?? [] as $m) if (ib_wa_store($db, $m, (string)($m['to'] ?? ''), 'out', 'app')) $n['echo']++;
        } elseif ($f === 'history') {
            foreach ($v['history'] ?? [] as $h) foreach ($h['threads'] ?? [] as $th) {
                $peer = (string)($th['context']['wa_id'] ?? $th['id'] ?? '');
                $nm = (string)($th['context']['username'] ?? '');
                foreach ($th['messages'] ?? [] as $m) {
                    $out = ib_wa_num((string)($m['from'] ?? '')) !== ib_wa_num($peer);
                    if (ib_wa_store($db, $m, $peer, $out ? 'out' : 'in', 'history', $out ? '' : $nm)) $n['history']++;
                }
            }
        } else $n['other']++;
    }
    return $n;
}

/** Webhook GET check: echo the challenge only for our verify token. */
/** Every login's WhatsApp connection: uid => [sealed secrets opened, shown info]. Server side only. */
function ib_wa_accounts(PDO $db): array {
    ib_install($db);
    $o = [];
    foreach ($db->query("SELECT uid, box, info FROM ib_acct WHERE svc = 'wa' AND box IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $box = json_decode(unseal((string)$r['box']), true);
        $info = json_decode((string)($r['info'] ?? ''), true);
        $o[(int)$r['uid']] = [is_array($box) ? $box : [], is_array($info) ? $info : []];
    }
    return $o;
}
/** Whose WhatsApp number is this (Meta's phone_number_id)? 0 = nobody's. */
function ib_wa_owner_of_phone(PDO $db, string $pid): int {
    foreach (ib_wa_accounts($db) as $uid => [, $info]) if ((string)($info['phone_id'] ?? '') === $pid) return (int)$uid;
    return 0;
}
function ib_wa_verify(PDO $db, array $q): ?string {
    $tok = (string)($q['hub_verify_token'] ?? $q['hub.verify_token'] ?? '');
    if (($q['hub_mode'] ?? $q['hub.mode'] ?? '') !== 'subscribe' || $tok === '') return null;
    $hit = false;
    foreach (ib_wa_accounts($db) as [$S]) if (($S['verify'] ?? '') !== '' && hash_equals((string)$S['verify'], $tok)) { $hit = true; break; }
    if (!$hit) return null;
    $ch = (string)($q['hub_challenge'] ?? $q['hub.challenge'] ?? '');
    return preg_match('/^[A-Za-z0-9_-]{1,200}$/', $ch) ? $ch : null;
}
/** X-Hub-Signature-256 = sha256= HMAC of the raw body with the app secret. */
function ib_wa_sig_ok(string $raw, string $sig, string $secret): bool {
    if ($secret === '' || !str_starts_with($sig, 'sha256=')) return false;
    return hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $sig);
}
/** The whole webhook POST. Returns the HTTP code to answer. */
function ib_wa_hook(PDO $db, string $raw, string $sig): int {
    ib_install($db);
    $signer = 0;   // the login whose app secret signed the call
    foreach (ib_wa_accounts($db) as $uid => [$S]) if (ib_wa_sig_ok($raw, $sig, (string)($S['app_secret'] ?? ''))) { $signer = (int)$uid; break; }
    if ($signer <= 0) return 401;
    ib_owner($signer);
    $h = hash('sha256', $raw);
    $s = $db->prepare('INSERT IGNORE INTO ib_wa_hook (hash) VALUES (?)'); $s->execute([$h]);
    if ($s->rowCount() === 0) return 200;
    $p = json_decode($raw, true);
    if (is_array($p)) { try { ib_wa_ingest($db, $p); } catch (Throwable $e) { ib_err($db, 'wa', 'Webhook: ' . $e->getMessage()); } }
    return 200;
}

/** Chats: one line per number, newest first. */
function ib_wa_chats(PDO $db): array {
    ib_install($db);
    $r = $db->query("SELECT m.peer, MAX(m.ts) last, SUM(m.dir = 'in' AND m.seen = 0) unread,
        MAX(CASE WHEN m.name <> '' THEN m.name ELSE '' END) name,
        SUBSTRING_INDEX(GROUP_CONCAT(m.body ORDER BY m.ts DESC SEPARATOR '\x1f'), '\x1f', 1) body
        FROM ib_wa_msg m WHERE m.uid = " . ib_me() . " GROUP BY m.peer ORDER BY last DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($x) => ['peer' => (string)$x['peer'], 'name' => (string)$x['name'], 'last' => (int)$x['last'],
        'unread' => (int)$x['unread'], 'body' => mb_substr((string)$x['body'], 0, 90)], $r);
}
function ib_wa_thread(PDO $db, string $peer): array {
    ib_install($db);
    $peer = ib_wa_num($peer);
    $s = $db->prepare('SELECT * FROM (SELECT id, dir, src, type, body, ts, status FROM ib_wa_msg WHERE uid = ? AND peer = ? ORDER BY ts DESC, id DESC LIMIT 200) x ORDER BY ts, id');
    $s->execute([ib_me(), $peer]);
    $db->prepare("UPDATE ib_wa_msg SET seen = 1 WHERE uid = ? AND peer = ? AND dir = 'in' AND seen = 0")->execute([ib_me(), $peer]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}
/** Can a free-form reply go now? Meta allows it within 24 h of the customer's last message. Pure on the thread. */
function ib_wa_open(array $thread, ?int $now = null): bool {
    $now ??= time();
    $last = 0;
    foreach ($thread as $m) if (($m['dir'] ?? '') === 'in') $last = max($last, (int)$m['ts']);
    return $last > 0 && $now - $last < IB_WA_WINDOW;
}
function ib_wa_send(PDO $db, string $peer, string $text, int $by, ?callable $t = null): void {
    ib_no_stage();
    $peer = ib_wa_num($peer);
    $text = trim($text);
    if ($peer === '' || $text === '') throw new RuntimeException('Type a message');
    if (mb_strlen($text) > 4096) throw new RuntimeException('Too long for one WhatsApp message');
    if (!ib_wa_open(ib_wa_thread($db, $peer))) throw new RuntimeException('More than 24 hours since this customer last wrote — Meta allows only a template now, so reply from the WhatsApp Business app');
    $c = ib_conn($db, 'wa'); $S = ib_secret($db, 'wa');
    if (!$c['connected'] || ($S['token'] ?? '') === '' || ($c['info']['phone_id'] ?? '') === '') throw new RuntimeException('WhatsApp is not connected yet');
    $j = ib_json(($t ?? 'ib_http')('POST', ib_graph($c['info']['phone_id'] . '/messages'),
        ['Authorization: Bearer ' . $S['token'], 'Content-Type: application/json'],
        json_encode(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $peer, 'type' => 'text',
            'text' => ['body' => $text, 'preview_url' => false]])), 'WhatsApp');
    $mid = (string)($j['messages'][0]['id'] ?? ('local-' . bin2hex(random_bytes(8))));
    ib_wa_store($db, ['id' => $mid, 'type' => 'text', 'text' => ['body' => $text], 'timestamp' => time()], $peer, 'out', 'site');
    $db->prepare('UPDATE ib_wa_msg SET by_user = ? WHERE uid = ? AND mid = ?')->execute([$by, ib_me(), $mid]);
    ib_log($db, 'wa', 'wa_reply', $by);
}
/** Live check for the open page: a fingerprint that moves when a message arrives or changes. */
function ib_wa_fp(PDO $db): string {
    ib_install($db);
    $s = $db->prepare("SELECT COUNT(*) n, COALESCE(MAX(id),0) m, SUM(status <> '') s FROM ib_wa_msg WHERE uid = ?"); $s->execute([ib_me()]);
    $r = $s->fetch(PDO::FETCH_NUM) ?: [0, 0, 0];
    return implode('.', array_map('intval', $r));
}

/* ---------- small helpers ---------- */

function ib_clean(string $s): string {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    return trim(mb_substr($s, 0, 500));
}
function ib_hdr_safe(string $s): string { return str_replace(["\r", "\n"], '', $s); }
function ib_re(string $s): string { $s = trim($s); return preg_match('/^re:/i', $s) ? $s : 'Re: ' . $s; }
/** An address out of "Name <a@b.c>". */
function ib_addr(string $s): string {
    if (preg_match('/<([^>]+@[^>]+)>/', $s, $m)) return trim($m[1]);
    return filter_var(trim($s), FILTER_VALIDATE_EMAIL) ? trim($s) : '';
}
/** A mail body for the sandboxed frame: scripts, forms, frames and outside loads removed. The frame has no
 *  allow-scripts and the page's CSP blocks outside pictures, so this is a second fence. */
function ib_mail_doc(string $html): string {
    $html = preg_replace('#<(script|style|iframe|object|embed|form|link|meta|base)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
    $html = preg_replace('#<(script|iframe|object|embed|form|link|meta|base)\b[^>]*/?>#is', '', $html) ?? '';
    $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
    $html = preg_replace('/(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2/i', '$1=$2#$2', $html) ?? '';
    return '<!doctype html><meta charset="utf-8"><base target="_blank"><style>body{font:14px/1.55 system-ui,sans-serif;color:#101A33;margin:12px;word-wrap:break-word}img{max-width:100%;height:auto}table{max-width:100%}</style>' . $html;
}
/** New verify token for the WhatsApp webhook. */
function ib_new_token(): string { return 'wnwa_' . bin2hex(random_bytes(16)); }

/** A staging copy sends nothing (house rule). */
function ib_no_stage(): void { if (is_stage()) throw new RuntimeException('This is a staging copy — nothing is sent from here.'); }

const IB_DEFAULTS = ['wa_cc' => '', 'zoho_dc' => 'com'];
function ib_settings(): array {
    $s = module_states()['inbox']['settings'] ?? [];
    $o = IB_DEFAULTS;
    foreach ($o as $k => $_) if (isset($s[$k]) && is_scalar($s[$k])) $o[$k] = (string)$s[$k];
    return $o;
}
/** Pure: clean the Super Admin's settings. */
function ib_settings_check(array $in): array {
    $cc = preg_replace('/\D/', '', (string)($in['wa_cc'] ?? '')) ?? '';
    if ($cc !== '' && strlen($cc) > 4) throw new InvalidArgumentException('A country calling code has 1 to 4 digits.');
    $dc = (string)($in['zoho_dc'] ?? 'com');
    if (!isset(IB_ZOHO_DC[$dc])) throw new InvalidArgumentException('Pick a Zoho region.');
    return ['wa_cc' => $cc, 'zoho_dc' => $dc];
}
