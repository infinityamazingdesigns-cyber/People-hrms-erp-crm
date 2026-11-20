<?php
/**
 * View as · access preview.
 *
 * A Super Admin opens the site as one role to check what that role gets. The view uses a synthetic preview login
 * per role ("preview.<code>", "TEST – Preview …", switched off, no employee code, no usable password) — never a real
 * person's login, so nobody's chats, notices, profile or KYC are opened. The swap happens in current_user() only
 * after the real login passed every check, and the real login must still be a Super Admin on every request.
 * Read-only: every POST / PUT / PATCH / DELETE is refused except starting / stopping on admin/view_as.php.
 * Start and stop are audited. Sign-out just ends the preview.
 */
declare(strict_types=1);

const VA_PREFIX = 'preview.';
const VA_PAGE = 'admin/view_as.php';

/** Is this username one of the synthetic preview logins? Pure. */
function va_is_preview(string $username): bool { return str_starts_with(strtolower($username), VA_PREFIX); }

/** Username of the preview login for a role. Pure. */
function va_username(string $code): string { return VA_PREFIX . strtolower(preg_replace('/[^A-Za-z0-9_]/', '', $code)); }

/** May this request run while previewing? Pure: reads only, except the preview page itself. */
function va_method_ok(string $method, string $script): bool {
    $m = strtoupper($method);
    if (in_array($m, ['GET', 'HEAD', 'OPTIONS'], true)) return true;
    return str_ends_with(str_replace('\\', '/', $script), '/' . VA_PAGE) || $script === VA_PAGE;
}

/** Make (or refresh) the preview login for a role; it holds exactly that role and no branch. */
function va_login(PDO $db, string $code): int {
    $labels = role_labels();
    if (!isset($labels[$code])) throw new InvalidArgumentException('Unknown role.');
    $un = va_username($code);
    $s = $db->prepare('SELECT id FROM users WHERE username = ?'); $s->execute([$un]);
    $id = (int)$s->fetchColumn();
    if (!$id) {
        $db->prepare('INSERT INTO users (username, email, name, pass_hash, active, must_change, emp_code) VALUES (?,?,?,?,0,0,NULL)')
           ->execute([$un, '', 'TEST – Preview ' . $labels[$code], '!preview-no-password']);
        $id = (int)$db->lastInsertId();
    } else {
        $db->prepare('UPDATE users SET active = 0, must_change = 0, emp_code = NULL, pass_hash = ? WHERE id = ?')->execute(['!preview-no-password', $id]);
    }
    $db->prepare('DELETE FROM user_role WHERE user_id = ?')->execute([$id]);
    $db->prepare('INSERT INTO user_role (user_id, role_code) VALUES (?, ?)')->execute([$id, $code]);
    $db->prepare('DELETE FROM user_branch WHERE user_id = ?')->execute([$id]);
    return $id;
}

/** Turn the real login into the preview login when a preview is running. Called by current_user(). */
function va_apply(array $real): array {
    $code = (string)($_SESSION['view_as'] ?? '');
    if ($code === '') return $real;
    if (!is_superadmin($real) || !isset(role_labels()[$code])) { unset($_SESSION['view_as']); return $real; }
    $s = pdo()->prepare('SELECT id, username, email, name, emp_code, is_owner FROM users WHERE username = ?');
    $s->execute([va_username($code)]);
    $p = $s->fetch();
    if (!$p) { unset($_SESSION['view_as']); return $real; }
    $p['active'] = 1; $p['must_change'] = 0; $p['is_owner'] = 0; $p['emp_code'] = null;
    $p['roles'] = user_roles((int)$p['id']);
    $p['branches'] = [];
    $p['preview'] = ['role' => $code, 'by' => (int)$real['id'], 'by_name' => (string)$real['name']];
    return $p;
}

/** Refuse a write while previewing (called by current_user() after the swap). */
function va_guard(array $u): void {
    if (empty($u['preview']) || PHP_SAPI === 'cli') return;
    if (va_method_ok((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), (string)($_SERVER['SCRIPT_NAME'] ?? ''))) return;
    http_response_code(403);
    $json = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json') || str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json');
    if ($json) { header('Content-Type: application/json'); echo json_encode(['error' => 'preview', 'message' => 'Access preview is read-only.']); }
    else echo 'Access preview is read-only. <a href="' . h(wn_base() . VA_PAGE) . '">Back to my view</a>';
    exit;
}

function va_start(PDO $db, array $real, string $code): void {
    if (!is_superadmin($real)) throw new RuntimeException('Only a Super Admin can preview.');
    va_login($db, $code);
    $_SESSION['view_as'] = $code;
    audit((int)$real['id'], 'view_as_start', $code);
}

function va_stop(): void {
    start_session();
    $code = (string)($_SESSION['view_as'] ?? '');
    unset($_SESSION['view_as']);
    if ($code !== '' && !empty($_SESSION['uid'])) audit((int)$_SESSION['uid'], 'view_as_stop', $code);
}

/** The yellow bar on every page while previewing. */
function va_banner(array $u): string {
    if (empty($u['preview'])) return '';
    $b = wn_base();
    return '<div class="va-bar">Viewing as <b>' . h(role_labels()[$u['preview']['role']] ?? $u['preview']['role']) . '</b> · read-only preview'
         . ' <a href="' . h($b . VA_PAGE) . '">Change role</a>'
         . ' <form method="post" action="' . h($b . VA_PAGE) . '"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="a" value="stop"><button>Back to my view</button></form></div>';
}

/** What a role gets: menu modules by group, from the same functions the live menu uses (fresh login, no person allows). */
function va_role_menu(string $code): array {
    return module_menu(['id' => 0, 'username' => va_username($code), 'name' => '', 'roles' => [$code], 'branches' => [], 'is_owner' => 0]);
}
