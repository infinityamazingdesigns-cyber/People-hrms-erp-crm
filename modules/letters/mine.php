<?php
// My letters — the login's own issued letters (by its linked employee code), answers, and requests to HR.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/letters.php';
$u = module_require_self('letters');
$db = pdo();
$code = (string)($u['emp_code'] ?? '');
let_housekeeping($db);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        if (($_POST['a'] ?? '') === 'ask') { let_request($db, $u, (int)$_POST['template_id'], (string)($_POST['purpose'] ?? '')); flash('Asked. HR will issue it.'); }
        if (($_POST['a'] ?? '') === 'answer') {
            $l = let_get($db, (int)$_POST['id']);
            if (!$l || !let_own($u, $l)) throw new RuntimeException('Not your letter.');
            flash(let_answer($db, $l, (string)$_POST['answer'], (string)$_POST['typed'], (string)($_SERVER['REMOTE_ADDR'] ?? ''), (int)$u['id']) . '.');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('mine.php');
}
ui_head('My letters', $u, 'letters:self');
if ($code === ''): ?><section class="card"><p>Your login is not linked to an employee record yet. Ask HR.</p></section><?php ui_foot(); exit; endif;
$mine = let_mine($db, $code);
$req = $db->prepare('SELECT r.*, t.name AS tname FROM letter_request r JOIN letter_template t ON t.id = r.template_id WHERE r.emp_code = ? ORDER BY r.id DESC LIMIT 20'); $req->execute([$code]); ?>
<section class="card"><h2>Your letters</h2><?php if (!$mine): ?><p class="muted">None yet.</p><?php endif; ?>
<?php foreach ($mine as $l): $state = let_answer_state($l, date('Y-m-d')); ?>
  <div class="mod"><b><?= h($l['tname']) ?></b> · <?= h($l['ref']) ?> · <?= h(date('j M Y', strtotime($l['issued_at']))) ?>
  <?= $l['response'] ? ' <span class="pill on">' . h(LET_ANSWER[$l['resp']][$l['response']]) . '</span>' : '' ?> <a href="print.php?id=<?= (int)$l['id'] ?>" target="_blank" rel="noopener">Open</a>
  <?php if ($state === 'ok'): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="answer"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
    <input name="typed" placeholder="Type your full name" required><?php foreach (LET_ANSWER[$l['resp']] as $k => $v): ?><button class="btn sm<?= $k === 'no' ? ' ghost' : '' ?>" name="answer" value="<?= $k ?>"><?= h($v === 'Accepted' ? 'Accept' : ($v === 'Declined' ? 'Decline' : 'Acknowledge')) ?></button><?php endforeach; ?></form><?php endif; ?></div>
<?php endforeach; ?></section>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="ask"><h2>Ask HR for a letter</h2><div class="grid">
  <label>Letter <select name="template_id" required><?php foreach (let_templates($db) as $t): if (!$t['for_employees']) continue; ?><option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?></select></label>
  <label>For (purpose) <input name="purpose" maxlength="300" placeholder="e.g. bank loan, visa"></label></div><button class="btn sm">Ask</button></form>
<?php $rows = $req->fetchAll(); if ($rows): ?><section class="card"><h2>Your requests</h2><table><tbody><?php foreach ($rows as $r): ?><tr><td><?= h($r['tname']) ?></td><td><?= h($r['purpose']) ?></td>
  <td><span class="pill <?= $r['status'] === 'done' ? 'on' : ($r['status'] === 'closed' ? 'off' : 'port') ?>"><?= h(['open' => 'With HR', 'done' => 'Issued', 'closed' => 'Closed'][$r['status']]) ?></span> <?= h($r['note']) ?></td></tr><?php endforeach; ?></tbody></table></section><?php endif;
ui_foot();
