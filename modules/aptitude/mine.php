<?php
// My aptitude test — start, take and send the paper. The deadline is the server's; no score, band or right answer is
// ever shown here (HR sees the result).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/aptitude.php';
$u = module_require_self('aptitude');
$db = pdo();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        if (($_POST['a'] ?? '') === 'start') apt_start($db, $u);
        if (($_POST['a'] ?? '') === 'submit') { apt_submit($db, $u, (int)$_POST['id'], (array)($_POST['ans'] ?? []), (int)($_POST['leaves'] ?? 0)); flash('Submitted. Thank you.'); }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('mine.php');
}
$st = apt_state($db, $u); $S = apt_settings();
ui_head('My aptitude test', $u, 'aptitude');
if ($st['state'] === 'nocode'): ?><section class="card"><p>Your login is not linked to an employee record yet — ask HR.</p></section>
<?php elseif ($st['state'] === 'locked'): ?><section class="card"><h2>Aptitude test</h2><p><?= $S['unlock'] === 'invite' ? 'HR will invite you when it is your turn.' : 'The test opens once you have passed every level of the induction academy.' ?></p><?php if ($S['unlock'] === 'induction' && module_on('induction')): ?><p><a href="../induction/mine.php">Go to my induction</a></p><?php endif; ?></section>
<?php elseif ($st['state'] === 'done'): ?><section class="card"><h2>Aptitude test</h2><p>You have taken the test. Thank you — HR has your paper.</p></section>
<?php elseif ($st['state'] === 'ready'): $min = (int)($S['minutes'][$st['level']] ?? 30); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="start"><h2>Aptitude test</h2><p>Up to <?= array_sum($S['blueprint'][$st['level']] ?? []) ?> questions · <?= $min ?> minutes. The clock starts when you press Start and does not stop. Answer what you can; unanswered questions count as wrong. Your answers go to HR.</p><button class="btn">Start</button></form>
<?php else: $a = $st['open']; $items = apt_items_public(json_decode((string)$a['items'], true) ?: []); $left = max(0, strtotime((string)$a['deadline']) - time()); ?>
<form method="post" class="card" id="aptForm"><?= csrf_field() ?><input type="hidden" name="a" value="submit"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="leaves" id="aptLeaves" value="0">
<p class="row" style="position:sticky;top:0;background:inherit"><b>Time left: <span id="aptT" data-left="<?= $left ?>"><?= gmdate('i:s', $left) ?></span></b> · <?= count($items) ?> questions</p>
<?php foreach ($items as $x): ?><fieldset style="border:0;padding:6px 0"><legend><b><?= $x['n'] + 1 ?>. <?= h($x['q']) ?></b> <span class="muted"><?= h(APT_SECTIONS[$x['s']] ?? '') ?></span></legend><?php foreach ($x['opts'] as $k => $o): ?><label style="display:block"><input type="radio" name="ans[<?= $x['n'] ?>]" value="<?= $k ?>"> <?= h($o) ?></label><?php endforeach; ?></fieldset><?php endforeach; ?>
<button class="btn">Submit</button></form>
<script>(function(){var t=document.getElementById('aptT'),l=+t.dataset.left,f=document.getElementById('aptForm'),lv=document.getElementById('aptLeaves'),sent=false;
setInterval(function(){l=Math.max(0,l-1);t.textContent=String(Math.floor(l/60)).padStart(2,'0')+':'+String(l%60).padStart(2,'0');if(l===0&&!sent){sent=true;f.submit();}},1000);
document.addEventListener('visibilitychange',function(){if(document.hidden)lv.value=+lv.value+1;});f.addEventListener('submit',function(){sent=true;});})();</script>
<?php endif;
ui_foot();
