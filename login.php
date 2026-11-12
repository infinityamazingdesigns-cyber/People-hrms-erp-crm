<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/two_step.php';

const LOCK_TRIES = 5;
const LOCK_MINUTES = 15;

$err = null; $step = 'password';
$db = wn_installed() ? pdo() : null;
if ($db && tfa_pending()) $step = 'code';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) {
    check_csrf();
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $a = (string)($_POST['a'] ?? 'password');
    if ($a === 'cancel') { tfa_cancel(); redirect('login.php'); }
    if ($a === 'resend') { $r = tfa_resend($db); $step = tfa_pending() ? 'code' : 'password'; if (!$r['ok']) $err = $r['msg']; else flash('A new code is on its way.'); }
    elseif ($a === 'code') {
        if (ip_throttled($db, $ip)) $err = 'Too many attempts from this network. Try again in 15 minutes.';
        else {
            $r = tfa_check($db, (string)($_POST['code'] ?? ''));
            if ($r['ok']) { login_finish($db, $r['row'], 'code'); redirect('index.php'); }
            ip_fail($db, $ip); $err = $r['msg']; $step = tfa_pending() ? 'code' : 'password';
        }
    } else {
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
                    if ($newHash) { $db->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([$newHash, $u['id']]); $u['pass_hash'] = $newHash; }
                    $db->prepare('UPDATE users SET failed = 0, locked_until = NULL WHERE id = ?')->execute([$u['id']]);
                    try { [$need, $why] = tfa_decide($db, $u); }
                    catch (Throwable $e) { audit((int)$u['id'], 'tfa_error', mb_substr($e->getMessage(), 0, 200)); $need = 'error'; $why = ''; }
                    if ($need === 'error') $err = 'Sign-in could not be checked just now. Try again in a minute.';
                    elseif ($need === 'code') {
                        $r = tfa_start($db, $u, !empty($_POST['remember']));
                        if ($r['ok']) { $step = 'code'; }
                        else $err = $r['msg'];
                    } else {
                        if ($why === 'noaddr') audit((int)$u['id'], 'tfa_no_address');
                        login_finish($db, $u, $why);
                        redirect('index.php');
                    }
                }
            }
        }
    }
}
$p = $db ? tfa_pending() : null;
ui_head('Sign in');
?>
<?php if ($step === 'code' && $p): ?>
<form method="post" class="card narrow login">
  <h2><?= h(org('name')) ?></h2>
  <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
  <?= csrf_field() ?><input type="hidden" name="a" value="code">
  <p>We emailed a 6-digit code to <b><?= h($p['to']) ?></b>.</p>
  <label>Code <input name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus></label>
  <button class="btn">Sign in</button>
</form>
<form method="post" class="narrow row" style="margin:8px auto"><?= csrf_field() ?><button class="btn sm ghost" name="a" value="resend">Send a new code</button><button class="btn sm ghost" name="a" value="cancel">Use another login</button></form>
<?php else: ?>
<form method="post" class="card narrow login">
  <h2><?= h(org('name')) ?></h2>
  <?php if (isset($_GET['installed'])): ?><div class="flash ok">Installed. Sign in as the owner.</div><?php endif; ?>
  <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
  <?= csrf_field() ?><input type="hidden" name="a" value="password">
  <label>Email or username <input name="login" autocomplete="username" required autofocus></label>
  <label>Password <input name="password" type="password" autocomplete="current-password" required></label>
  <?php if ($db && module_on('email') && mail_settings()['tfa'] && (int)mail_settings()['trust_days'] > 0): ?><label class="tick"><input type="checkbox" name="remember" value="1"> Trust this browser for <?= (int)mail_settings()['trust_days'] ?> days</label><?php endif; ?>
  <button class="btn">Sign in</button>
</form>
<?php endif;
ui_foot();
