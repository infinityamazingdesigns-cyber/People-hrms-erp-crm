<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
$u = module_require('users');
$db = pdo();

function temp_password(): string { return substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 12) . random_int(10, 99); }
function load_user(PDO $db, int $id): ?array {
    $s = $db->prepare('SELECT id, username, name, email, active, is_owner FROM users WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

$shown = null;   // a new password is shown once, never stored in plain text
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? '');
    if ($a === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $login = strtolower(trim((string)($_POST['username'] ?? '')));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if ($name === '' || !preg_match('/^[a-z0-9._@-]{3,80}$/', $login)) flash('Give a name and a username (letters, digits, . _ - @).', 'err');
        elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('The email is not valid.', 'err');
        else {
            try {
                $pw = temp_password();
                $db->prepare('INSERT INTO users (username, email, name, pass_hash, must_change, created_by) VALUES (?,?,?,?,1,?)')
                   ->execute([$login, $email, $name, password_hash($pw, pw_algo()), (int)$u['id']]);
                $nid = (int)$db->lastInsertId();
                $e = user_roles_save($u, ['id' => $nid, 'is_owner' => 0], (array)($_POST['roles'] ?? ['EMPLOYEE']));
                audit((int)$u['id'], 'user_create', '#' . $nid);
                $shown = [$login, $pw];
                if ($e) flash('Login made, but roles: ' . $e, 'err');
            } catch (PDOException $e) { flash('That username is already taken.', 'err'); }
        }
    } else {
        $t = load_user($db, (int)($_POST['id'] ?? 0));
        if (!$t) flash('Login not found.', 'err');
        elseif ($a === 'roles') {
            $e = user_roles_save($u, $t, (array)($_POST['roles'] ?? []));
            if (!$e) {
                $db->prepare('DELETE FROM user_branch WHERE user_id = ?')->execute([$t['id']]);
                foreach ((array)($_POST['branches'] ?? []) as $b) $db->prepare('INSERT IGNORE INTO user_branch VALUES (?,?)')->execute([$t['id'], (int)$b]);
                audit((int)$u['id'], 'user_branches', '#' . $t['id']);
            }
            flash($e ?? 'Saved.', $e ? 'err' : 'ok');
        } elseif ($a === 'active') {
            $on = ($_POST['on'] ?? '') === '1';
            if (!$on && ($g = owner_guard($u, $t, 'deactivate'))) flash($g, 'err');
            elseif ((int)$t['id'] === (int)$u['id']) flash('You cannot switch off your own login.', 'err');
            else { $db->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$on ? 1 : 0, $t['id']]); audit((int)$u['id'], 'user_' . ($on ? 'activate' : 'deactivate'), '#' . $t['id']); flash('Saved.'); }
        } elseif ($a === 'reset') {
            if ($g = owner_guard($u, $t, 'reset_password')) flash($g, 'err');
            else {
                $pw = temp_password();
                $db->prepare('UPDATE users SET pass_hash = ?, must_change = 1, failed = 0, locked_until = NULL WHERE id = ?')->execute([password_hash($pw, pw_algo()), $t['id']]);
                audit((int)$u['id'], 'user_reset_password', '#' . $t['id']);
                $shown = [$t['username'], $pw];
            }
        }
    }
    if (!$shown) redirect('users.php');
}

$roles = role_labels();
$brs = branches();
$list = $db->query('SELECT id, username, name, email, active, is_owner, last_login FROM users ORDER BY is_owner DESC, active DESC, name')->fetchAll();
$rmap = []; foreach ($db->query('SELECT user_id, role_code FROM user_role') as $r) $rmap[(int)$r['user_id']][] = $r['role_code'];
$bmap = []; foreach ($db->query('SELECT user_id, branch_id FROM user_branch') as $r) $bmap[(int)$r['user_id']][] = (int)$r['branch_id'];

ui_head('Users & access', $u, 'users');
if ($shown): ?><div class="flash ok">Password for <b><?= h($shown[0]) ?></b>: <code><?= h($shown[1]) ?></code> — shown once. They set their own at first sign-in.</div><?php endif; ?>
<form method="post" class="card"><h2>New login</h2><?= csrf_field() ?><input type="hidden" name="a" value="create">
  <div class="grid"><label>Full name <input name="name" required></label><label>Username <input name="username" required></label><label>Email <input name="email" type="email"></label></div>
  <div class="roles"><?php foreach ($roles as $rc => $rl): if (!can_assign($u, $rc)) continue; ?><label class="tick"><input type="checkbox" name="roles[]" value="<?= h($rc) ?>"<?= $rc === 'EMPLOYEE' ? ' checked' : '' ?>><?= h($rl) ?></label><?php endforeach; ?></div>
  <button class="btn">Create login</button></form>

<section class="card"><h2>Logins</h2>
<?php foreach ($list as $p): $mine = $rmap[(int)$p['id']] ?? []; $fid = 'u' . $p['id']; ?>
<details class="person<?= $p['active'] ? '' : ' off' ?>"><summary><b><?= h($p['name']) ?></b> <small><?= h($p['username']) ?></small>
  <?php if ($p['is_owner']): ?><span class="pill on">Owner · always Super Admin</span><?php endif; ?>
  <?php if (!$p['active']): ?><span class="pill off">Switched off</span><?php endif; ?>
  <span class="muted"><?= h(implode(', ', array_map(fn($r) => $roles[$r] ?? $r, $mine)) ?: 'No role') ?></span></summary>
  <form method="post" id="<?= $fid ?>"><?= csrf_field() ?><input type="hidden" name="a" value="roles"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
    <div class="roles"><?php foreach ($roles as $rc => $rl): $lock = $p['is_owner'] && $rc === 'SUPERADMIN'; ?><label class="tick"><input type="checkbox" name="roles[]" value="<?= h($rc) ?>"<?= in_array($rc, $mine, true) || $lock ? ' checked' : '' ?><?= $lock || !can_assign($u, $rc) ? ' disabled' : '' ?>><?= h($rl) ?></label><?php if ($lock || (!can_assign($u, $rc) && in_array($rc, $mine, true))): ?><input type="hidden" name="roles[]" value="<?= h($rc) ?>"><?php endif; ?><?php endforeach; ?></div>
    <p>Branches (none ticked = every branch):
    <?php foreach ($brs as $b): ?><label class="tick"><input type="checkbox" name="branches[]" value="<?= (int)$b['id'] ?>"<?= in_array((int)$b['id'], $bmap[(int)$p['id']] ?? [], true) ? ' checked' : '' ?>><?= h($b['name']) ?></label><?php endforeach; ?></p>
    <button class="btn sm">Save roles & branches</button></form>
  <div class="row">
    <?php if (!$p['is_owner'] && (int)$p['id'] !== (int)$u['id']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="active"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="on" value="<?= $p['active'] ? '0' : '1' ?>"><button class="btn sm ghost"><?= $p['active'] ? 'Switch off' : 'Switch on' ?></button></form><?php endif; ?>
    <?php if (!$p['is_owner'] || (int)$p['id'] === (int)$u['id']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="reset"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn sm ghost">New password</button></form><?php endif; ?>
    <span class="muted">Last sign-in: <?= h($p['last_login'] ?? 'never') ?></span>
  </div>
</details>
<?php endforeach; ?>
</section>
<?php ui_foot();
