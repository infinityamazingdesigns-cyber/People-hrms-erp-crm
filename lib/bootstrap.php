<?php
/**
 * WorkNest HR — loaded first by every page and API.
 * Config, database, session, current user, CSRF, escaping, audit. Organisation-specific values come from the
 * database (Admin → Organisation), never from code.
 */
declare(strict_types=1);

const WN_ROOT = __DIR__ . '/..';
const WN_VERSION = '0.1.0';

$cfgFile = WN_ROOT . '/config.php';
$GLOBALS['WN_CFG'] = is_file($cfgFile) ? (require $cfgFile) : null;

function cfg(string $k, $d = null) { return $GLOBALS['WN_CFG'][$k] ?? $d; }
function wn_installed(): bool { return is_array($GLOBALS['WN_CFG']); }

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/org.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/modules.php';
require_once __DIR__ . '/migrate.php';
require_once __DIR__ . '/ui.php';

if (PHP_SAPI !== 'cli') {
    send_security_headers();
    if (!wn_installed() && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'install.php') {
        header('Location: ' . wn_base() . 'install.php'); exit;
    }
}

function pdo(): PDO {
    static $p = null;
    if ($p instanceof PDO) return $p;
    if (isset($GLOBALS['WN_TEST_PDO'])) return $p = $GLOBALS['WN_TEST_PDO'];
    $p = new PDO('mysql:host=' . cfg('db_host', 'localhost') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4',
        (string)cfg('db_user'), (string)cfg('db_pass'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return $p;
}

/** Relative path from the running script back to the site root ("" or "../"). */
function wn_base(): string {
    $f = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: '';
    $root = realpath(WN_ROOT) ?: '';
    if ($f === '' || $root === '' || !str_starts_with($f, $root)) return '';
    $rel = trim(str_replace(DIRECTORY_SEPARATOR, '/', substr(dirname($f), strlen($root))), '/');
    return $rel === '' ? '' : str_repeat('../', substr_count($rel, '/') + 1);
}

/* ---------- Session ---------- */

const SESSION_IDLE = 8 * 3600;

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') return;
    $dir = WN_ROOT . '/data/sessions';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
    ini_set('session.use_strict_mode', '1');
    session_name('wnhr');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => cookie_secure()]);
    session_start();
}

/** The signed-in login, or null. $touch = false for background checks that must not count as activity. */
function current_user(bool $touch = true): ?array {
    if (isset($GLOBALS['WN_TEST_USER'])) return $GLOBALS['WN_TEST_USER'];
    start_session();
    if (empty($_SESSION['uid'])) return null;
    if (time() - (int)($_SESSION['seen'] ?? 0) > SESSION_IDLE) { $_SESSION = []; session_destroy(); return null; }
    if ($touch) $_SESSION['seen'] = time();
    static $u = false;
    if ($u === false) {
        $s = pdo()->prepare('SELECT id, username, email, name, emp_code, active, must_change, is_owner, pass_hash FROM users WHERE id = ?');
        $s->execute([(int)$_SESSION['uid']]);
        $u = $s->fetch() ?: null;
        if ($u && !$u['active'] && !$u['is_owner']) $u = null;
        if ($u && ($_SESSION['pwv'] ?? '') !== substr(hash('sha256', (string)$u['pass_hash']), 0, 16)) $u = null;   // password changed elsewhere
        if (!$u) { $_SESSION = []; session_destroy(); return null; }
        unset($u['pass_hash']);
        $u['roles'] = user_roles((int)$u['id'], (bool)$u['is_owner']);
        $u['branches'] = user_branch_ids((int)$u['id']);
    }
    if ($u && !empty($u['must_change']) && !in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), ['account.php', 'logout.php'], true)) {
        header('Location: ' . wn_base() . 'account.php?first=1'); exit;
    }
    if (!headers_sent()) header('Cache-Control: no-store, private');
    return $u;
}

function require_login(bool $api = false): array {
    $u = current_user();
    if ($u) return $u;
    if ($api) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['error' => 'signed_out']); exit; }
    header('Location: ' . wn_base() . 'login.php'); exit;
}

/* ---------- CSRF, escaping ---------- */

function csrf_token(): string {
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return (string)$_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }
function check_csrf(): void {
    $t = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '');
    if ($t === '' || !hash_equals(csrf_token(), $t)) { http_response_code(403); exit('Invalid request token – reload the page.'); }
}
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function flash(?string $msg = null, string $kind = 'ok'): ?array {
    start_session();
    if ($msg !== null) { $_SESSION['flash'] = [$kind, $msg]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}

function redirect(string $to): never { header('Location: ' . $to, true, 303); exit; }
