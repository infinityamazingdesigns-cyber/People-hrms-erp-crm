<?php
// Browser notifications (Web Push) — rules.
//  - The push sent to the browser's push service (Google, Mozilla, Apple, Microsoft) is EMPTY, signed with this site's
//    VAPID key: no name, date or text goes through it. The service worker (sw.js) then asks this site for the
//    login's new notices with the person's own sign-in and shows them; signed out on that device → a plain line.
//  - Endpoints only on the known push services (no requests to other addresses).
//  - Every notice is kept in user_notice (the person's inbox), so nothing is lost when a device is off.
//  - The VAPID key pair is in the database (private key sealed with seal()), made on first use.
//  - A staging copy sends nothing. Pushes go after the page answer.
// Any module tells a login with notify_user(); it does nothing while the module is off.
declare(strict_types=1);

const PUSH_HOSTS = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com', 'web.push.apple.com', 'notify.windows.com'];
const PUSH_TTL = 2 * 86400;
const PUSH_MAX_SUBS = 10;
$GLOBALS['PUSH_QUEUE'] = $GLOBALS['PUSH_QUEUE'] ?? [];

function push_b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function push_b64u_dec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

/** Is this a push-service address we send to? https only, known hosts and their sub-domains, no port / user. Pure. */
function push_endpoint_ok(string $ep): bool {
    $p = parse_url($ep);
    if (($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['port']) || strlen($ep) > 700) return false;
    $h = strtolower($p['host']);
    foreach (PUSH_HOSTS as $ok) if ($h === $ok || str_ends_with($h, '.' . $ok)) return true;
    return false;
}
/** DER ECDSA signature → raw r‖s (64 bytes) for JWT ES256. Pure. */
function push_der_to_raw(string $der): string {
    $o = 2; if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f;
    $int = function () use ($der, &$o): string { $len = ord($der[$o + 1]); $v = substr($der, $o + 2, $len); $o += 2 + $len; return str_pad(ltrim($v, "\0"), 32, "\0", STR_PAD_LEFT); };
    return $int() . $int();
}
/** A fresh key pair: ['pub' => base64url P-256 point, 'pem' => private key]. */
function push_make_key(): array {
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$k || !openssl_pkey_export($k, $pem)) throw new RuntimeException('Could not make the notification key');
    $d = openssl_pkey_get_details($k)['ec'];
    $pad = fn(string $b) => str_pad($b, 32, "\0", STR_PAD_LEFT);
    return ['pub' => push_b64u("\x04" . $pad($d['x']) . $pad($d['y'])), 'pem' => $pem];
}
/** Signed VAPID token for one push service origin. */
function push_jwt(array $vapid, string $aud, string $subject, ?int $now = null): string {
    $h = push_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $c = push_b64u(json_encode(['aud' => $aud, 'exp' => ($now ?? time()) + 12 * 3600, 'sub' => $subject]));
    if (!openssl_sign("$h.$c", $sig, $vapid['pem'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('Could not sign the notification');
    return "$h.$c." . push_b64u(push_der_to_raw($sig));
}
/** A site page address for a notice: relative, no scheme / // / .., at most 200 characters; '' otherwise. Pure. */
function push_url_clean(string $u): string {
    $u = trim($u);
    if (str_starts_with($u, '//')) return '';
    $u = ltrim($u, '/');
    return ($u !== '' && strlen($u) <= 200 && !preg_match('~^[a-z][a-z0-9+.-]*:|\.\.|//|\\\\~i', $u) && preg_match('~^[A-Za-z0-9_./?=&%#:-]+$~', $u)) ? $u : '';
}

// ---------- database ----------

function push_vapid(PDO $db): array {
    $r = $db->query('SELECT pub, pem_box FROM push_key WHERE id = 1')->fetch();
    if (!$r) { $k = push_make_key(); $db->prepare('INSERT IGNORE INTO push_key (id, pub, pem_box) VALUES (1, ?, ?)')->execute([$k['pub'], seal($k['pem'])]); $r = $db->query('SELECT pub, pem_box FROM push_key WHERE id = 1')->fetch(); }
    return ['pub' => (string)$r['pub'], 'pem' => unseal((string)$r['pem_box'])];
}
function push_pub(PDO $db): string { $r = $db->query('SELECT pub FROM push_key WHERE id = 1')->fetchColumn(); return $r ? (string)$r : push_vapid($db)['pub']; }

function push_subscribe(PDO $db, int $userId, array $sub, string $agent = ''): void {
    $ep = (string)($sub['endpoint'] ?? '');
    if (!push_endpoint_ok($ep)) throw new RuntimeException('This browser\'s notification service is not supported.');
    $keys = (array)($sub['keys'] ?? []);
    $db->prepare('INSERT INTO push_sub (user_id, endpoint, endpoint_hash, p256dh, auth, agent) VALUES (?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), agent = VALUES(agent), fails = 0')
       ->execute([$userId, $ep, hash('sha256', $ep), substr((string)($keys['p256dh'] ?? ''), 0, 200), substr((string)($keys['auth'] ?? ''), 0, 60), mb_substr($agent, 0, 120)]);
    $s = $db->prepare('SELECT id FROM push_sub WHERE user_id = ? ORDER BY id DESC'); $s->execute([$userId]);
    $old = array_slice(array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)), PUSH_MAX_SUBS);
    if ($old) $db->exec('DELETE FROM push_sub WHERE id IN (' . implode(',', $old) . ')');   // a device subscription, not a record: the newest few per login are kept
}
function push_unsubscribe(PDO $db, int $userId, string $endpoint): void { $db->prepare('DELETE FROM push_sub WHERE endpoint_hash = ? AND user_id = ?')->execute([hash('sha256', $endpoint), $userId]); }
function push_device_count(PDO $db, int $userId): int { $s = $db->prepare('SELECT COUNT(*) FROM push_sub WHERE user_id = ?'); $s->execute([$userId]); return (int)$s->fetchColumn(); }

/** Give a login a notice (kept in their inbox) and push it to their devices after the page answer. 0 while off. Never throws. */
function notify_user(PDO $db, int $userId, string $kind, string $title, string $body = '', string $url = ''): int {
    if ($userId <= 0 || !module_on('notify')) return 0;
    try {
        $db->prepare('INSERT INTO user_notice (user_id, kind, title, body, url) VALUES (?,?,?,?,?)')
           ->execute([$userId, mb_substr($kind, 0, 30), mb_substr($title, 0, 120), mb_substr($body, 0, 300), push_url_clean($url)]);
        $id = (int)$db->lastInsertId();
    } catch (Throwable $e) { error_log('notice: ' . $e->getMessage()); return 0; }
    if (!$GLOBALS['PUSH_QUEUE']) register_shutdown_function(function () use ($db) { if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); push_flush($db); });
    $GLOBALS['PUSH_QUEUE'][$userId] = true;
    return $id;
}

/** Send the queued pushes. $send(endpoint, headers) → HTTP status (tests). @return array<int,int> subscription id => status */
function push_flush(PDO $db, ?callable $send = null): array {
    $users = array_keys($GLOBALS['PUSH_QUEUE']); $GLOBALS['PUSH_QUEUE'] = [];
    if (!$users || is_stage()) return [];
    $out = [];
    try {
        $vapid = push_vapid($db); $subject = 'https://' . (parse_url(wn_site_url(), PHP_URL_HOST) ?: 'localhost');
        $s = $db->prepare('SELECT * FROM push_sub WHERE user_id IN (' . implode(',', array_fill(0, count($users), '?')) . ')'); $s->execute($users);
        $jwt = [];
        foreach ($s->fetchAll() as $sub) {
            if (!push_endpoint_ok($sub['endpoint'])) continue;
            $aud = 'https://' . parse_url($sub['endpoint'], PHP_URL_HOST);
            $jwt[$aud] ??= push_jwt($vapid, $aud, $subject);
            $code = ($send ?? 'push_http')($sub['endpoint'], ['TTL: ' . PUSH_TTL, 'Urgency: high', 'Content-Length: 0', 'Authorization: vapid t=' . $jwt[$aud] . ', k=' . $vapid['pub']]);
            $out[(int)$sub['id']] = (int)$code;
            if ($code === 404 || $code === 410) $db->prepare('DELETE FROM push_sub WHERE id = ?')->execute([$sub['id']]);      // the browser dropped it
            elseif ($code >= 200 && $code < 300) $db->prepare('UPDATE push_sub SET last_ok = NOW(), fails = 0 WHERE id = ?')->execute([$sub['id']]);
            else { $db->prepare('UPDATE push_sub SET fails = fails + 1 WHERE id = ?')->execute([$sub['id']]); $db->exec('DELETE FROM push_sub WHERE fails >= 20'); }
        }
    } catch (Throwable $e) { error_log('push: ' . $e->getMessage()); }
    return $out;
}
function push_http(string $endpoint, array $headers): int {
    $c = curl_init($endpoint);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
    curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    return $code;
}

/** For the service worker: notices newer than $since (≤ 5, last 2 days; a new device only the last 15 minutes). */
function push_pending(PDO $db, int $userId, int $since): array {
    $q = $db->prepare('SELECT id, title, body, url FROM user_notice WHERE user_id = ? AND id > ? AND created_at >= DATE_SUB(NOW(), INTERVAL ' . ($since > 0 ? '2 DAY' : '15 MINUTE') . ') ORDER BY id DESC LIMIT 5');
    $q->execute([$userId, $since]);
    $items = array_reverse($q->fetchAll());
    $l = $db->prepare('SELECT id, title, body, url FROM user_notice WHERE user_id = ? ORDER BY id DESC LIMIT 1'); $l->execute([$userId]);
    $fix = fn($r) => $r ? ['id' => (int)$r['id'], 'title' => $r['title'], 'body' => $r['body'], 'url' => $r['url']] : null;
    return ['items' => array_map($fix, $items), 'latest' => $fix($l->fetch() ?: null)];
}
function notice_list(PDO $db, int $userId, int $limit = 100): array { $s = $db->prepare('SELECT * FROM user_notice WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit))); $s->execute([$userId]); return $s->fetchAll(); }
function notice_unread(PDO $db, int $userId): int { try { $s = $db->prepare('SELECT COUNT(*) FROM user_notice WHERE user_id = ? AND read_at IS NULL'); $s->execute([$userId]); return (int)$s->fetchColumn(); } catch (Throwable $e) { return 0; } }
function notice_read_all(PDO $db, int $userId): void { $db->prepare('UPDATE user_notice SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL')->execute([$userId]); }
