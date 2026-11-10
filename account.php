<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $s = pdo()->prepare('SELECT pass_hash FROM users WHERE id = ?');
    $s->execute([$u['id']]);
    $cur = (string)$s->fetchColumn();
    $new = (string)($_POST['new'] ?? '');
    if (!password_verify((string)($_POST['current'] ?? ''), $cur)) flash('Your current password is not right.', 'err');
    elseif ($p = pw_problem($new)) flash($p, 'err');
    elseif ($new !== (string)($_POST['new2'] ?? '')) flash('The two new passwords differ.', 'err');
    else {
        $hash = pw_hash($new);
        pdo()->prepare('UPDATE users SET pass_hash = ?, must_change = 0 WHERE id = ?')->execute([$hash, $u['id']]);
        $_SESSION['pwv'] = substr(hash('sha256', $hash), 0, 16);   // keeps this browser signed in, ends the others
        audit((int)$u['id'], 'user_password_own');
        flash('Password changed.');
        redirect('index.php');
    }
    redirect('account.php' . (!empty($u['must_change']) ? '?first=1' : ''));
}
ui_head('Account', $u);
?>
<form method="post" class="card narrow">
  <h2><?= !empty($u['must_change']) ? 'Set your own password' : 'Change password' ?></h2>
  <?= csrf_field() ?>
  <label>Current password <input name="current" type="password" required autocomplete="current-password"></label>
  <label>New password <input name="new" type="password" required minlength="10" autocomplete="new-password"></label>
  <label>New password again <input name="new2" type="password" required minlength="10" autocomplete="new-password"></label>
  <button class="btn">Save</button>
</form>
<?php ui_foot();
