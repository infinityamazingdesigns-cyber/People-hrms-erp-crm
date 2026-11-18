<?php
// My interviews — for anyone on an interview panel: the candidate's work details (never contacts or pay) and the
// feedback form. Only interviews where this login is on the panel.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/recruitment.php';
$u = module_require_self('recruitment');
$db = pdo(); $uid = (int)$u['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try { rc_feedback($db, $u, (int)($_POST['id'] ?? 0), $_POST); flash('Thank you — feedback saved.'); } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('panel.php');
}
$list = rc_my_interviews($db, $uid);
$mine = $db->prepare('SELECT * FROM rc_feedback WHERE interview_id = ? AND user_id = ?');
ui_head('My interviews', $u, 'recruitment'); ?>
<div class="card"><h2>My interviews</h2><p class="muted">Interviews you are on the panel for, from 30 days ago.</p>
<?php if (!$list): ?><p class="muted">None.</p><?php endif; ?></div>
<?php foreach ($list as $i): $mine->execute([(int)$i['id'], $uid]); $f = $mine->fetch() ?: ['rating' => '', 'verdict' => '', 'comments' => '']; ?>
<div class="card"><h3><?= h($i['name']) ?> — <?= h($i['req_title'] ?: 'Talent pool') ?>, round <?= (int)$i['round'] ?></h3>
<p><?= h(date('D j M Y, H:i', strtotime((string)$i['at']))) ?> · <?= h(RC_MODES[$i['mode']] ?? '') ?><?= $i['place'] !== '' ? ' · ' . h($i['place']) : '' ?> · <?= h($i['status']) ?></p>
<p class="muted"><?= h(implode(' · ', array_filter([$i['cur_title'], $i['total_exp'] !== null ? (float)$i['total_exp'] . ' yrs' : '', $i['qualification']]))) ?><?= $i['skills'] !== '' ? '<br>Skills: ' . h($i['skills']) : '' ?></p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
<div class="row" style="flex-wrap:wrap"><select name="rating" required><option value="">Rating</option><?php for ($n = 1; $n <= 5; $n++): ?><option value="<?= $n ?>"<?= (int)$f['rating'] === $n ? ' selected' : '' ?>><?= str_repeat('★', $n) ?></option><?php endfor; ?></select>
<select name="verdict" required><option value="">Your call</option><?php foreach (RC_VERDICTS as $k => $l): ?><option value="<?= $k ?>"<?= $f['verdict'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
<textarea name="comments" rows="3" placeholder="What you saw: strengths, gaps"><?= h((string)$f['comments']) ?></textarea>
<button class="btn sm"><?= $f['rating'] !== '' ? 'Update feedback' : 'Give feedback' ?></button></form></div>
<?php endforeach;
ui_foot();
