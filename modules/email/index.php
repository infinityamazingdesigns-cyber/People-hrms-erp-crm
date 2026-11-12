<?php
// Email & 2-step — which mail service, test email, outbox, 2-step on / off and per login.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/two_step.php';
$u = module_require('email');
$db = pdo();
$admin = tfa_admin_can($u);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? '');
    try {
        if (!$admin) throw new RuntimeException('Only the Super Admin or IT Admin changes these.');
        switch ($a) {
            case 'settings':
                $new = mail_settings_check($_POST);
                if ($new['tfa'] && !mail_can_send() && mail_order_for($new, mail_ready()) === []) throw new RuntimeException('Set up a mail service first — 2-step needs it to send codes.');
                $st = module_states()['email'] ?? ['roles' => module_roles('email')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['email', 1, implode(',', $st['roles'] ?: module_roles('email')), json_encode($new), (int)$u['id']]);
                audit((int)$u['id'], 'module_settings', 'email tfa=' . ($new['tfa'] ? 'on' : 'off'));
                flash('Saved.'); break;
            case 'test':
                $to = strtolower(trim((string)($_POST['to'] ?? '')));
                if (!mail_valid($to)) throw new RuntimeException('Give an email address.');
                $text = "This is a test email from " . org('name') . ".\n\nIf you can read this, outgoing email works.";
                $res = mail_send_now(['to' => $to, 'subject' => org('name') . ' test email', 'text' => $text, 'html' => mail_html($text, 'Test email', org('name'), org('color_primary'))]);
                mail_log_sent($db, 'test', $to, org('name') . ' test email', $res, (int)$u['id']);
                flash('Sent through ' . MAIL_SERVICES[$res['service']] . ($res['errors'] ? ' (first try failed: ' . implode(' · ', $res['errors']) . ')' : '') . '.'); break;
            case 'retry': mail_retry($db, (int)$_POST['id'], (int)$u['id']); mail_drain($db, [(int)$_POST['id']]); flash('Tried again.'); break;
            case 'drain': $r = mail_drain($db); flash("Sent {$r['sent']}, failed {$r['failed']}, waiting {$r['waiting']}."); break;
            case 'login': tfa_login_set($db, $u, (int)$_POST['uid'], ($_POST['off'] ?? '') === '1', (string)($_POST['note'] ?? '')); flash('Saved.'); break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('index.php' . (isset($_POST['view']) ? '?view=' . urlencode((string)$_POST['view']) : ''));
}

$set = mail_settings(); $ready = mail_ready(); $order = mail_order();
$view = (string)($_GET['view'] ?? '');
ui_head('Email & 2-step', $u, 'email');
?>
<nav class="row"><a class="btn sm<?= $view === '' ? '' : ' ghost' ?>" href="index.php">Mail &amp; 2-step</a><a class="btn sm<?= $view === 'outbox' ? '' : ' ghost' ?>" href="?view=outbox">Outbox</a><a class="btn sm<?= $view === 'logins' ? '' : ' ghost' ?>" href="?view=logins">2-step by login</a></nav>

<?php if ($view === 'outbox'): $job = mail_job_running($db); ?>
<section class="card"><h2>Latest messages</h2>
  <p class="muted">Reminder job: <?= $job === null ? 'never ran' : ($job ? 'running' : 'not running') ?>. Bodies of sign-in codes are never kept.</p>
  <?php if ($admin): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="drain"><input type="hidden" name="view" value="outbox"><button class="btn sm ghost">Send what is waiting now</button></form><?php endif; ?>
  <table><thead><tr><th>When</th><th>Kind</th><th>To</th><th>Subject</th><th>State</th><th></th></tr></thead><tbody>
  <?php foreach (mail_recent($db) as $m): ?><tr><td><?= h(date('j M H:i', strtotime($m['created_at']))) ?></td><td><?= h(MAIL_KINDS[$m['kind']] ?? $m['kind']) ?></td><td><?= h(mail_mask($m['to_addr'])) ?></td><td><?= h($m['subject']) ?></td>
    <td><span class="pill <?= $m['status'] === 'sent' ? 'on' : ($m['status'] === 'failed' ? 'off' : 'port') ?>"><?= h($m['status']) ?></span><?= $m['error'] !== '' ? ' <small class="muted">' . h($m['error']) . '</small>' : '' ?></td>
    <td><?php if ($admin && $m['status'] === 'failed' && $m['kind'] !== 'code'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="retry"><input type="hidden" name="view" value="outbox"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn sm ghost">Send again</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></section>

<?php elseif ($view === 'logins'):
    $rows = $db->query('SELECT u.id, u.name, u.username, u.is_owner, COALESCE(t.off, 0) AS off FROM users u LEFT JOIN tfa_user t ON t.user_id = u.id WHERE u.active = 1 OR u.is_owner = 1 ORDER BY u.name')->fetchAll(); ?>
<section class="card"><h2>2-step by login</h2><table><thead><tr><th>Login</th><th>Codes go to</th><th>2-step</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $addr = tfa_address($db, (int)$r['id']); ?><tr><td><?= h($r['name'] . ' · ' . $r['username']) ?><?= $r['is_owner'] ? ' <small class="muted">owner</small>' : '' ?></td>
    <td><?= $addr !== '' ? h(mail_mask($addr)) : '<span class="pill port">No email — password alone</span>' ?></td><td><?= $r['off'] ? '<span class="pill off">Off for this login</span>' : '<span class="pill on">As set</span>' ?></td>
    <td><?php if ($admin && (int)$r['id'] !== (int)$u['id'] && (!$r['is_owner'] || is_owner($u))): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="login"><input type="hidden" name="view" value="logins"><input type="hidden" name="uid" value="<?= (int)$r['id'] ?>"><input type="hidden" name="off" value="<?= $r['off'] ? '0' : '1' ?>">
      <input name="note" placeholder="Reason" required><button class="btn sm ghost"><?= $r['off'] ? 'Switch back on' : 'Switch off for this login' ?></button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table>
  <p class="muted">Codes go to the login's email, else the linked employee's work email. Nobody changes this for their own login.</p></section>

<?php else: ?>
<section class="card"><h2>Mail services</h2><table><tbody>
  <?php foreach (MAIL_SERVICES as $k => $l): ?><tr><td><?= h($l) ?></td><td><?= $ready[$k] ? '<span class="pill on">Set up</span>' : '<span class="pill off">Not set up</span>' ?></td></tr><?php endforeach; ?>
  <tr><td>Sender</td><td><?= h(mail_from() ?: '—') ?> · <?= h(mail_from_name()) ?></td></tr></tbody></table>
  <?php if (is_stage()): ?><p class="note">This is a staging copy: nothing is sent from here.</p><?php endif; ?>
  <details><summary>How to set up</summary><p class="muted">Add to <code>config.php</code> (never in the database or GitHub):</p>
  <pre>'mail_from' => 'hr@example.com',
// SMTP (Zoho, Microsoft 365, your host …): use an app password
'mail_smtp_host' => 'smtp.example.com', 'mail_smtp_port' => 465, 'mail_smtp_secure' => 'ssl',
'mail_smtp_user' => 'hr@example.com', 'mail_smtp_pass' => '…',
// Google Workspace: service account with domain-wide delegation, scope gmail.send
'mail_google_sa' => '/home/…/private/google-sa.json',
// emergency: 'tfa_off' => true switches 2-step off</pre>
  <p class="muted">Run <code>php tools/mail_drain.php</code> every 5 minutes (cron) to send what is waiting and retry failures.</p></details></section>
<?php if ($admin): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2><div class="grid">
  <label>Use first <select name="primary"><?php foreach (MAIL_SERVICES as $k => $l): ?><option value="<?= $k ?>"<?= $set['primary'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
  <label>Trust a browser for (days, 0 = never) <input type="number" name="trust_days" value="<?= (int)$set['trust_days'] ?>" min="0" max="90"></label></div>
  <p><label class="tick"><input type="checkbox" name="fallback" value="1"<?= $set['fallback'] ? ' checked' : '' ?>> Try the other service when the first fails</label>
  <label class="tick"><input type="checkbox" name="tfa" value="1"<?= $set['tfa'] ? ' checked' : '' ?>> 2-step sign-in: email a code after the password</label></p>
  <p class="muted">Now sending through: <?= $order ? h(implode(' → ', array_map(fn($s) => MAIL_SERVICES[$s], $order))) : 'nothing (not set up)' ?>.</p><button class="btn sm">Save</button></form>
<form method="post" class="card row"><?= csrf_field() ?><input type="hidden" name="a" value="test"><input type="email" name="to" placeholder="Send a test email to" required value="<?= h((string)($u['email'] ?? '')) ?>"><button class="btn sm"<?= $order ? '' : ' disabled' ?>>Send test</button></form>
<?php endif; endif;
ui_foot();
