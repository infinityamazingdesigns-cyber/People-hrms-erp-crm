<?php
/**
 * Password hashing, sealing at rest, HTTP security headers, cookies, sign-in throttle.
 * No custom cryptography: password_hash (Argon2id when available) and AES-256-GCM via OpenSSL.
 */
declare(strict_types=1);

function pw_algo(): string|int|null { return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT; }

function pw_problem(string $plain): ?string {
    if (strlen($plain) < 10) return 'Use at least 10 characters.';
    if (!preg_match('/[A-Za-z]/', $plain) || !preg_match('/\d/', $plain)) return 'Use letters and numbers.';
    return null;
}

function pw_hash(string $plain): string {
    if ($p = pw_problem($plain)) throw new InvalidArgumentException($p);
    return password_hash($plain, pw_algo());
}

/** [bool ok, ?string newHash] — a fresh hash when the stored one uses an older algorithm. */
function pw_verify_upgrade(string $plain, string $hash): array {
    if (!password_verify($plain, $hash)) return [false, null];
    return [true, password_needs_rehash($hash, pw_algo()) ? password_hash($plain, pw_algo()) : null];
}

/* ---------- Sealing (ID numbers, bank details, integration secrets) ---------- */

function seal_key(): string {
    $k = (string)cfg('seal_key', '');
    if ($k === '') $k = 'wn-derived|' . (string)cfg('db_pass', '') . '|' . (string)cfg('db_name', '');
    return hash('sha256', $k, true);
}

function seal(string $plain): string {
    if ($plain === '') return '';
    $iv = random_bytes(12);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', seal_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'g1:' . base64_encode($iv . $tag . $ct);
}

function unseal(string $box): string {
    if ($box === '' || !str_starts_with($box, 'g1:')) return '';
    $raw = base64_decode(substr($box, 3), true);
    if ($raw === false || strlen($raw) < 29) return '';
    $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', seal_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $out === false ? '' : $out;
}

/** "XXXXXX1234" style mask — never show more than the last 4. */
function mask(string $v, int $keep = 4): string {
    $v = preg_replace('/\s+/', '', $v);
    if ($v === '') return '';
    return str_repeat('X', max(0, strlen($v) - $keep)) . substr($v, -$keep);
}

/* ---------- HTTPS, cookies, headers ---------- */

function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function is_stage(): bool { return cfg('stage', false) === true; }

function cookie_secure(): bool { return is_https() || !cfg('dev', false); }

const WN_CSP = "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; "
    . "font-src 'self' data:; img-src 'self' data: blob:; media-src 'self' blob: mediastream:; connect-src 'self'; "
    . "frame-src 'self' blob: data:; worker-src 'self' blob:; frame-ancestors 'self'; form-action 'self'; "
    . "base-uri 'self'; object-src 'none'";

function send_security_headers(): void {
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), display-capture=()');
    header('Content-Security-Policy: ' . WN_CSP);
    if (is_https()) header('Strict-Transport-Security: max-age=31536000');
}

/* ---------- Sign-in throttle: per address, per 15-minute window ---------- */

function throttle_window(): string {
    return date('Y-m-d H:') . str_pad((string)(intdiv((int)date('i'), 15) * 15), 2, '0', STR_PAD_LEFT) . ':00';
}

function ip_throttled(PDO $db, string $ip, int $max = 30): bool {
    $s = $db->prepare('SELECT attempts FROM login_throttle WHERE ip = ? AND window_start = ?');
    $s->execute([$ip, throttle_window()]);
    return (int)$s->fetchColumn() >= $max;
}

function ip_fail(PDO $db, string $ip): void {
    $db->prepare('INSERT INTO login_throttle (ip, window_start, attempts) VALUES (?,?,1) ON DUPLICATE KEY UPDATE attempts = attempts + 1')
       ->execute([$ip, throttle_window()]);
}
