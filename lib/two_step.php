<?php
/**
 * 2-step sign-in: after the right password, an untrusted browser gets a 6-digit code by email.
 *
 * Rules (ported from the reference build, organisation data removed):
 *  - In force only when the Email module is on, its 2-step setting is on, a mail service works, and config.php has no
 *    emergency 'tfa_off'. Codes go to the login's email, else the linked employee's work email.
 *  - Code: 10 minutes, 5 tries, only the newest works, at most 5 codes per login per 15 minutes; only an HMAC is kept.
 *    The password step must be finished within 15 minutes. The session is signed in only after the code.
 *  - "Trust this browser" (setting, default 30 days): a cookie signed over the login and its password hash, so a
 *    password change ends it.
 *  - Per login: 2-step can be switched off (password alone) by the Super Admin / IT Admin with a reason, never for
 *    their own login; every change in tfa_user_log (append-only). The owner's own choice only by the owner.
 *  - A login with no email signs in with the password alone (audit `tfa_no_address`); mail down → sign-in refused with
 *    the reason (trusted browsers still work).
 */
declare(strict_types=1);
require_once __DIR__ . '/mail.php';

const TFA_TTL = 600;
const TFA_TRIES = 5;
const TFA_SENDS = 5;
const TFA_PENDING_TTL = 900;
const TFA_ADMIN_ROLES = ['SUPERADMIN', 'ITADMIN'];
const TFA_WHY = ['emergency' => 'Off by the emergency switch in config.php', 'off' => '2-step is switched off', 'nomail' => 'No mail service works yet',
    'login' => '2-step is switched off for this login', 'trusted' => 'This browser is trusted', 'noaddr' => 'No email for this login — password alone', 'code' => 'A code is emailed'];

/* ---------------- pure ---------------- */

function tfa_code_hash(int $cid, int $uid, string $code, string $key): string { return hash_hmac('sha256', $cid . '|' . $uid . '|' . $code, $key); }

function tfa_trust_sign(int $uid, string $passHash, int $time, string $rand, string $key): string {
    $v = $uid . '.' . $time . '.' . $rand;
    return $v . '.' . hash_hmac('sha256', $v . '|' . hash('sha256', 'pw|' . $passHash), $key);
}

function tfa_trust_ok(string $cookie, int $uid, string $passHash, string $key, int $days, ?int $now = null): bool {
    if ($days <= 0 || !preg_match('/^(\d+)\.(\d+)\.([0-9a-f]{16})\.([0-9a-f]{64})$/', $cookie, $m)) return false;
    if ((int)$m[1] !== $uid || (int)$m[2] < ($now ?? time()) - $days * 86400) return false;
    return hash_equals(tfa_trust_sign($uid, $passHash, (int)$m[2], $m[3], $key), $cookie);
}

/** The decision with its reason: ['code' | 'no' | 'noaddr', why]. Pure over its inputs. */
function tfa_decide_from(bool $emergency, bool $on, bool $canSend, bool $loginOff, bool $trusted, bool $hasAddr): array {
    if ($emergency) return ['no', 'emergency'];
    if (!$on) return ['no', 'off'];
    if (!$canSend) return ['no', 'nomail'];
    if ($loginOff) return ['no', 'login'];
    if ($trusted) return ['no', 'trusted'];
    return $hasAddr ? ['code', 'code'] : ['noaddr', 'noaddr'];
}

function tfa_mail(string $name, string $org, string $code, string $ip): array {
    $first = trim(explode(' ', trim($name))[0] ?? '') ?: 'there';
    $text = "Hello $first,\n\nYour $org sign-in code is:\n\n$code\n\nIt works for 10 minutes. Type it on the sign-in page to finish signing in."
          . "\n\nIf you did not just sign in, someone may have your password: change it now or ask your administrator to reset it."
          . ($ip !== '' ? "\n\nSign-in from network address $ip." : '');
    return ['subject' => "$org sign-in code: $code", 'text' => $text, 'html' => mail_html($text, 'Your sign-in code', $org, org('color_primary')), 'auto' => true];
}

/* ---------------- data ---------------- */

function tfa_key(): string {
    $k = (string)cfg('tfa_key', '');
    return hash('sha256', 'wn-tfa|' . ($k !== '' ? $k : bin2hex(seal_key())));
}
function tfa_cookie_name(int $uid): string { return 'wntfa' . $uid; }
function tfa_admin_can(array $u): bool { return has_role($u, ...TFA_ADMIN_ROLES); }

/** Where this login's codes go: the login's email, else the linked employee's work email. */
function tfa_address(PDO $db, int $uid): string {
    $s = $db->prepare('SELECT u.email, e.work_email FROM users u LEFT JOIN employee e ON e.code = u.emp_code WHERE u.id = ?'); $s->execute([$uid]);
    $r = $s->fetch() ?: [];
    foreach (['email', 'work_email'] as $k) if (mail_valid(strtolower(trim((string)($r[$k] ?? ''))))) return strtolower(trim((string)$r[$k]));
    return '';
}

function tfa_login_off(PDO $db, int $uid): bool {
    $s = $db->prepare('SELECT off FROM tfa_user WHERE user_id = ?'); $s->execute([$uid]);
    return (int)$s->fetchColumn() === 1;
}

function tfa_trusted(array $row): bool {
    return tfa_trust_ok((string)($_COOKIE[tfa_cookie_name((int)$row['id'])] ?? ''), (int)$row['id'], (string)$row['pass_hash'], tfa_key(), (int)mail_settings()['trust_days']);
}

function tfa_trust_set(array $row): void {
    $d = (int)mail_settings()['trust_days'];
    if ($d <= 0 || headers_sent()) return;
    setcookie(tfa_cookie_name((int)$row['id']), tfa_trust_sign((int)$row['id'], (string)$row['pass_hash'], time(), bin2hex(random_bytes(8)), tfa_key()),
        ['expires' => time() + $d * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => cookie_secure()]);
}

function tfa_decide(PDO $db, array $row): array {
    $em = (bool)cfg('tfa_off', false);
    $on = module_on('email') && mail_settings()['tfa'];
    if ($em || !$on) return tfa_decide_from($em, $on, false, false, false, false);
    return tfa_decide_from(false, true, mail_can_send(), tfa_login_off($db, (int)$row['id']), tfa_trusted($row), tfa_address($db, (int)$row['id']) !== '');
}

/** Make and email a code; keep the pending sign-in in the session (never as the signed-in uid). */
function tfa_start(PDO $db, array $row, bool $remember, ?callable $send = null): array {
    $uid = (int)$row['id'];
    $to = tfa_address($db, $uid);
    if ($to === '') return ['ok' => false, 'msg' => 'No email is on file for this login. Ask your administrator.'];
    $s = $db->prepare('SELECT COUNT(*) FROM tfa_code WHERE user_id = ? AND created_at > NOW() - INTERVAL 15 MINUTE'); $s->execute([$uid]);
    if ((int)$s->fetchColumn() >= TFA_SENDS) return ['ok' => false, 'msg' => 'Too many codes asked for. Wait 15 minutes and sign in again.'];
    $db->prepare('UPDATE tfa_code SET used = 1 WHERE user_id = ? AND used = 0')->execute([$uid]);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $db->prepare('INSERT INTO tfa_code (user_id, code_hash, sent_to, expires_at, ip) VALUES (?, ?, ?, NOW() + INTERVAL ' . TFA_TTL . ' SECOND, ?)')->execute([$uid, str_repeat('0', 64), $to, mb_substr($ip, 0, 45)]);
    $cid = (int)$db->lastInsertId();
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $db->prepare('UPDATE tfa_code SET code_hash = ? WHERE id = ?')->execute([tfa_code_hash($cid, $uid, $code, tfa_key()), $cid]);
    $m = tfa_mail((string)$row['name'], org('name'), $code, $ip) + ['to' => $to];
    try {
        $res = $send ? $send($m) : mail_send_now($m);
        mail_log_sent($db, 'code', $to, org('name') . ' sign-in code', $res, $uid);
    } catch (Throwable $e) {
        $db->prepare('UPDATE tfa_code SET used = 1 WHERE id = ?')->execute([$cid]);
        audit($uid, 'tfa_send_failed', mb_substr($e->getMessage(), 0, 200));
        return ['ok' => false, 'msg' => 'Your sign-in code could not be emailed just now. Try again in a minute; if it keeps failing, tell your administrator.'];
    }
    start_session();
    $_SESSION['tfa'] = ['uid' => $uid, 'cid' => $cid, 'at' => time(), 'to' => mail_mask($to), 'remember' => $remember];
    audit($uid, 'tfa_code_sent', mail_mask($to));
    return ['ok' => true, 'msg' => '', 'to' => mail_mask($to)];
}

function tfa_pending(): ?array {
    start_session();
    $p = $_SESSION['tfa'] ?? null;
    if (!is_array($p) || empty($p['uid'])) return null;
    if (time() - (int)$p['at'] > TFA_PENDING_TTL) { unset($_SESSION['tfa']); return null; }
    return $p;
}
function tfa_cancel(): void { start_session(); unset($_SESSION['tfa']); }

/** Check a typed code. On success the users row is returned and the pending step cleared. */
function tfa_check(PDO $db, string $code): array {
    $p = tfa_pending();
    if (!$p) return ['ok' => false, 'msg' => 'The code step has expired. Sign in again.', 'row' => null];
    $code = preg_replace('/\D/', '', $code);
    $s = $db->prepare('SELECT *, expires_at > NOW() AS live FROM tfa_code WHERE id = ? AND user_id = ?'); $s->execute([(int)$p['cid'], (int)$p['uid']]);
    $c = $s->fetch();
    if (!$c || (int)$c['used'] === 1 || !(int)$c['live'] || (int)$c['tries'] >= TFA_TRIES) return ['ok' => false, 'msg' => 'This code no longer works. Send a new code.', 'row' => null];
    $db->prepare('UPDATE tfa_code SET tries = tries + 1 WHERE id = ?')->execute([(int)$c['id']]);
    if (strlen($code) !== 6 || !hash_equals((string)$c['code_hash'], tfa_code_hash((int)$c['id'], (int)$p['uid'], $code, tfa_key()))) {
        usleep(300000);
        $left = TFA_TRIES - (int)$c['tries'] - 1;
        audit((int)$p['uid'], 'tfa_wrong_code');
        return ['ok' => false, 'msg' => $left > 0 ? "That code is not right. $left " . ($left === 1 ? 'try' : 'tries') . ' left.' : 'That code is not right. Send a new code.', 'row' => null];
    }
    $db->prepare('UPDATE tfa_code SET used = 1 WHERE id = ?')->execute([(int)$c['id']]);
    $u = $db->prepare('SELECT * FROM users WHERE id = ?'); $u->execute([(int)$p['uid']]);
    $row = $u->fetch();
    tfa_cancel();
    if (!$row || (!$row['active'] && !$row['is_owner'])) return ['ok' => false, 'msg' => 'This login is switched off.', 'row' => null];
    if (!empty($p['remember'])) tfa_trust_set($row);
    return ['ok' => true, 'msg' => '', 'row' => $row];
}

function tfa_resend(PDO $db): array {
    $p = tfa_pending();
    if (!$p) return ['ok' => false, 'msg' => 'The code step has expired. Sign in again.'];
    $s = $db->prepare('SELECT * FROM users WHERE id = ?'); $s->execute([(int)$p['uid']]);
    $row = $s->fetch();
    if (!$row) { tfa_cancel(); return ['ok' => false, 'msg' => 'Sign in again.']; }
    return tfa_start($db, $row, !empty($p['remember']));
}

/** Switch 2-step off / on for one login (reason needed), never one's own; the owner's only by the owner. */
function tfa_login_set(PDO $db, array $actor, int $uid, bool $off, string $note): void {
    if (!tfa_admin_can($actor)) throw new RuntimeException('Only the Super Admin or IT Admin does this.');
    if ($uid === (int)$actor['id']) throw new RuntimeException('Not for your own login.');
    $s = $db->prepare('SELECT * FROM users WHERE id = ?'); $s->execute([$uid]); $t = $s->fetch();
    if (!$t) throw new RuntimeException('Login not found.');
    if ($e = owner_guard($actor, $t, 'edit')) throw new RuntimeException($e);
    $note = trim(mb_substr(strip_tags($note), 0, 300));
    if ($note === '') throw new InvalidArgumentException('Give a reason.');
    $db->prepare('INSERT INTO tfa_user (user_id, off) VALUES (?,?) ON DUPLICATE KEY UPDATE off = VALUES(off)')->execute([$uid, $off ? 1 : 0]);
    $db->prepare('INSERT INTO tfa_user_log (user_id, off, note, by_user) VALUES (?,?,?,?)')->execute([$uid, $off ? 1 : 0, $note, (int)$actor['id']]);
    audit((int)$actor['id'], $off ? 'tfa_login_off' : 'tfa_login_on', '#' . $uid);
}

/** Sign the browser in (after the password, and the code when needed). */
function login_finish(PDO $db, array $row, string $how): void {
    start_session();
    session_regenerate_id(true);
    $_SESSION = ['uid' => (int)$row['id'], 'seen' => time(), 'pwv' => substr(hash('sha256', (string)$row['pass_hash']), 0, 16)];
    $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$row['id']]);
    audit((int)$row['id'], 'login', trim(($row['is_owner'] ? 'owner ' : '') . '2-step: ' . $how));
}
