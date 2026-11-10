<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

const LOCK_TRIES = 5;
const LOCK_MINUTES = 15;

$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $db = pdo();
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $login = strtolower(trim((string)($_POST['login'] ?? '')));
    $pass = (string)($_POST['password'] ?? '');
    if (ip_throttled($db, $ip)) $err = 'Too many attempts from this network. Try again in 15 minutes.';
    else {
        $s = $db->prepare('SELECT * FROM users WHERE username = ? OR (email <> "" AND email = ?) LIMIT 1');
        $s->execute([$login, $login]);
        $u = $s->fetch();
        if ($u && $u['locked_until'] && strtotime((string)$u['locked_until']) > time()) $err = 'This login is locked for a few minutes after wrong passwords.';
        elseif (!$u || (!$u['active'] && !$u['is_owner'])) { ip_fail($db, $ip); $err = 'Wrong sign-in or password.'; audit(null, 'login_fail', 'unknown'); }
        else {
            [$ok, $newHash] = pw_verify_upgrade($pass, (string)$u['pass_hash']);
            if (!$ok) {
                ip_fail($db, $ip);
                $f = (int)$u['failed'] + 1;
                $db->prepare('UPDATE users SET failed = ?, locked_until = ? WHERE id = ?')
                   ->execute([$f >= LOCK_TRIES ? 0 : $f, $f >= LOCK_TRIES ? date('Y-m-d H:i:s', time() + LOCK_MINUTES * 60) : null, $u['id']]);
                audit((int)$u['id'], 'login_fail', 'password');
                $err = 'Wrong sign-in or password.';
            } else {
                $hash = $newHash ?? (string)$u['pass_hash'];
                $db->prepare('UPDATE users SET failed = 0, locked_until = NULL, last_login = NOW()' . ($newHash ? ', pass_hash = ?' : '') . ' WHERE id = ?')
                   ->execute($newHash ? [$newHash, $u['id']] : [$u['id']]);
                start_session();
                session_regenerate_id(true);
                $_SESSION = ['uid' => (int)$u['id'], 'seen' => time(), 'pwv' => substr(hash('sha256', $hash), 0, 16)];
                audit((int)$u['id'], 'login', $u['is_owner'] ? 'owner' : '');
                redirect('index.php');
            }
        }
    }
}
ui_head('Sign in');
?>
<form method="post" class="card narrow login">
  <h2><?= h(org('name')) ?></h2>
  <?php if (isset($_GET['installed'])): ?><div class="flash ok">Installed. Sign in as the owner.</div><?php endif; ?>
  <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
  <?= csrf_field() ?>
  <label>Email or username <input name="login" autocomplete="username" required autofocus></label>
  <label>Password <input name="password" type="password" autocomplete="current-password" required></label>
  <button class="btn">Sign in</button>
</form>
<?php ui_foot();
