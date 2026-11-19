<?php
// My induction academy — the login's own path: levels in order (done / open / locked), reading and video, the quiz
// (graded on the server; the right answers never reach this page), results.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/induction.php';
$u = module_require_self('induction');
$db = pdo(); $code = strtoupper((string)($u['emp_code'] ?? '')); $lid = (int)($_GET['l'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf(); $lid = (int)($_POST['l'] ?? 0);
    try {
        if (($_POST['a'] ?? '') === 'quiz') { $g = ind_submit($db, $u, $lid, (array)($_POST['ans'] ?? []));
            flash(($g['passed'] ? 'Passed — ' : 'Not passed yet — ') . $g['score'] . ' of ' . $g['total'] . ' right (' . $g['pct'] . '%).', $g['passed'] ? 'ok' : 'err'); }
        if (($_POST['a'] ?? '') === 'read') { ind_mark_read($db, $u, $lid); flash('Level finished.'); }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('mine.php' . ($lid ? '?l=' . $lid : ''));
}
ui_head('My induction academy', $u, 'induction');
if ($code === ''): ?><section class="card"><p>Your login is not linked to an employee record yet — ask HR.</p></section><?php ui_foot(); exit; endif;
$path = ind_path($db, $code); $S = ind_settings(); ?>
<section class="card"><h2>Your induction</h2><p><b><?= $path['done'] ?></b> of <?= $path['of'] ?> level(s) passed<?= $path['of'] && $path['done'] === $path['of'] ? ' — well done, you have finished.' : '' ?></p>
<?php if (!$path['levels']): ?><p class="muted">HR has not set up any levels for you yet.</p><?php endif; ?>
<ol><?php foreach ($path['levels'] as $l): $st = $path['state'][(int)$l['id']]; ?><li><?= $st === 'locked' ? h($l['title']) : '<a href="?l=' . (int)$l['id'] . '">' . h($l['title']) . '</a>' ?> <span class="pill<?= $st === 'done' ? ' on' : '' ?>"><?= ['done' => 'Passed', 'open' => 'Open', 'locked' => 'Locked'][$st] ?></span></li><?php endforeach; ?></ol></section>
<?php if ($lid && isset($path['state'][$lid]) && $path['state'][$lid] !== 'locked'): $l = ind_level_get($db, $lid); $qs = ind_quiz_public(ind_questions($db, $lid)); $st = $path['state'][$lid];
  $wait = ind_wait_left(ind_last_fail($db, $code, $lid), (int)$S['retry_minutes'], time()); ?>
<section class="card"><h2><?= h($l['title']) ?></h2><?= $l['summary'] !== '' ? '<p class="muted">' . h($l['summary']) . '</p>' : '' ?>
<?php foreach (preg_split('/\n\s*\n/', trim((string)$l['body'])) ?: [] as $para): if (trim($para) === '') continue; ?><p><?= nl2br(h(trim($para))) ?></p><?php endforeach; ?>
<?php if ($l['video_url'] !== ''): ?><p><a class="btn sm ghost" href="<?= h($l['video_url']) ?>" target="_blank" rel="noopener">Watch the video</a></p><?php endif; ?></section>
<?php if ($st === 'done'): ?><section class="card"><p>You passed this level on <?= h(date('j M Y', strtotime($path['passed'][$lid]))) ?>.</p></section>
<?php elseif (!$qs): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="read"><input type="hidden" name="l" value="<?= $lid ?>"><button class="btn">I have read this</button></form>
<?php elseif ($wait > 0): ?><section class="card"><p>You can try the quiz again in <?= $wait ?> minute(s).</p></section>
<?php else: ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="quiz"><input type="hidden" name="l" value="<?= $lid ?>"><h3>Quiz · pass mark <?= (int)($l['pass_pct'] ?? $S['pass_pct']) ?>%</h3>
<?php foreach ($qs as $i => $q): ?><fieldset style="border:0;padding:6px 0"><legend><b><?= $i + 1 ?>. <?= h($q['q']) ?></b></legend><?php foreach ($q['opts'] as $k => $o): ?><label style="display:block"><input type="radio" name="ans[<?= (int)$q['id'] ?>]" value="<?= $k ?>" required> <?= h($o) ?></label><?php endforeach; ?></fieldset><?php endforeach; ?>
<button class="btn">Submit</button></form><?php endif; endif; ?>
<?php ui_foot();
