<?php
/**
 * One-time installer: database connection, organisation name, the permanent owner.
 * Answers "Not found" once config.php exists — that is the guard. Never make this file delete itself
 * (hosting malware scanners remove self-deleting PHP files).
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

if (wn_installed()) { http_response_code(404); exit('Not found'); }

$err = null;
$v = fn(string $k, string $d = '') => trim((string)($_POST[$k] ?? $d));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower($v('owner_email'));
    $pass = (string)($_POST['owner_pass'] ?? '');
    if ($v('org_name') === '' || $v('owner_name') === '') $err = 'Fill in the organisation and owner names.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'The owner email is not valid.';
    elseif ($p = pw_problem($pass)) $err = 'Owner password: ' . $p;
    elseif ($pass !== (string)($_POST['owner_pass2'] ?? '')) $err = 'The two passwords differ.';
    else {
        try {
            $cfg = ['db_host' => $v('db_host', 'localhost'), 'db_name' => $v('db_name'), 'db_user' => $v('db_user'),
                    'db_pass' => (string)($_POST['db_pass'] ?? ''), 'owner_email' => $email,
                    'seal_key' => bin2hex(random_bytes(32)), 'dev' => !empty($_POST['dev']), 'stage' => false];
            $db = new PDO('mysql:host=' . $cfg['db_host'] . ';dbname=' . $cfg['db_name'] . ';charset=utf8mb4', $cfg['db_user'], $cfg['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $GLOBALS['WN_CFG'] = $cfg;
            $GLOBALS['WN_TEST_PDO'] = $db;
            migrate($db);
            if ((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) throw new RuntimeException('This database already has logins — use a new database.');
            $db->prepare('INSERT INTO users (username, email, name, pass_hash, active, must_change, is_owner) VALUES (?,?,?,?,1,0,1)')
               ->execute([$email, $email, $v('owner_name'), pw_hash($pass)]);
            $uid = (int)$db->lastInsertId();
            $db->prepare('INSERT INTO user_role (user_id, role_code, given_by) VALUES (?,?,?)')->execute([$uid, 'SUPERADMIN', $uid]);
            $db->prepare('INSERT INTO org_setting (k, v, updated_by) VALUES (?,?,?)')->execute(['name', $v('org_name'), $uid]);
            $body = "<?php\n// Written by install.php. Never commit this file.\nreturn " . var_export($cfg, true) . ";\n";
            if (@file_put_contents(__DIR__ . '/config.php', $body, LOCK_EX) === false)
                throw new RuntimeException('Could not write config.php — check folder permissions.');
            @chmod(__DIR__ . '/config.php', 0600);
            audit($uid, 'owner_created', 'install');
            header('Location: login.php?installed=1'); exit;
        } catch (Throwable $e) { $err = $e->getMessage(); }
    }
}
ui_head('Install');
?>
<form method="post" class="card narrow" autocomplete="off">
  <h2>Set up WorkNest HR</h2>
  <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
  <fieldset><legend>Database (MySQL / MariaDB, empty database)</legend>
    <label>Host <input name="db_host" value="<?= h($v('db_host', 'localhost')) ?>" required></label>
    <label>Database <input name="db_name" value="<?= h($v('db_name')) ?>" required></label>
    <label>User <input name="db_user" value="<?= h($v('db_user')) ?>" required></label>
    <label>Password <input name="db_pass" type="password"></label>
  </fieldset>
  <fieldset><legend>Organisation</legend>
    <label>Display name <input name="org_name" value="<?= h($v('org_name')) ?>" required maxlength="80"></label>
  </fieldset>
  <fieldset><legend>Owner — permanent Super Admin</legend>
    <label>Full name <input name="owner_name" value="<?= h($v('owner_name')) ?>" required></label>
    <label>Email (sign-in) <input name="owner_email" type="email" value="<?= h($v('owner_email')) ?>" required></label>
    <label>Password <input name="owner_pass" type="password" required minlength="10"></label>
    <label>Password again <input name="owner_pass2" type="password" required minlength="10"></label>
  </fieldset>
  <label class="tick"><input type="checkbox" name="dev" value="1"> Local test machine over plain http</label>
  <button class="btn">Install</button>
</form>
<?php ui_foot();
